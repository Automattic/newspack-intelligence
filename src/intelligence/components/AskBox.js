import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { Spinner, TextControl } from '@wordpress/components';
import { Icon, check } from '@wordpress/icons';
import { Core } from '@newspack-nodes/runtime';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';
import { usePersistedFlag } from '@newspack-nodes/shared/hooks/usePersistedState';
import {
	commandArgs,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import Citation from './Citation';
import ItemRow, { ItemRows } from './ItemRow';
import Refusal from './Refusal';
import { Unread } from './Answer';
import { pageFormat } from './format';
import { daysSince } from './lensDraft';
import { word } from './words';
import { useEditing } from '../layout/PageContext';

/** How often an ask still owed its answer is read again, on the Router's tick. */
const POLL_MS = 3000;

/** How long a source shown from its marker stays washed, in milliseconds. */
const WASH_MS = 2000;

/** The states an ask reads while it is still owed its outcome. */
const OWED = [ 'asked', 'planned', 'read' ];

/**
 * One search an ask ran, as the reader reads it: its words, the entity it
 * was about, its lens, its kinds, its roles and its days.
 *
 * @param {Object} search A search, as the plan named it.
 * @return {string} The search.
 */
function searchText( search ) {
	return [
		search.words &&
			sprintf(
				/* translators: %s: the words searched. */
				__( '“%s”', 'newspack-intelligence' ),
				search.words
			),
		search.entity &&
			sprintf(
				/* translators: %s: the entity searched. */
				__( 'about %s', 'newspack-intelligence' ),
				search.entity
			),
		search.lens &&
			sprintf(
				/* translators: %s: the team view searched through. */
				__( 'team view %s', 'newspack-intelligence' ),
				search.lens
			),
		search.kinds &&
			search.kinds
				.map( ( kind ) => word( 'kindWords', kind ) )
				.join( ', ' ),
		search.roles &&
			sprintf(
				/* translators: %s: the roles searched. */
				__( 'about %s', 'newspack-intelligence' ),
				search.roles
					.map( ( role ) => word( 'roleWords', role ) )
					.join( ', ' )
			),
		search.days &&
			sprintf(
				/* translators: %d: how many days were searched. */
				_n(
					'the last %d day',
					'the last %d days',
					search.days,
					'newspack-intelligence'
				),
				search.days
			),
	]
		.filter( Boolean )
		.join( ', ' );
}

/**
 * A plan's search as the filters Search opens on: its words, its lens, its
 * kinds and roles, and its days as the day they start from. The entity it
 * names is a name, not a key, so it is left to the reader.
 *
 * @param {Object} search A search, as the plan named it.
 * @return {Object<string, string>} The filters it names.
 */
function searchFilters( search ) {
	return Object.fromEntries(
		[
			[ 'q', search.words ],
			[ 'kind', search.kinds?.join( ',' ) ],
			[ 'role', search.roles?.join( ',' ) ],
			[ 'lens', search.lens ],
			[ 'since', search.days && daysSince( search.days, Date.now() ) ],
		].filter( ( [ , value ] ) => value )
	);
}

/**
 * The steps an ask owed its outcome has taken, each ticked as it ends, and
 * the one under way beside a spinner.
 *
 * @param {Object} props
 * @param {Object} props.answer What `ask_result` answered.
 * @return {import('react').ReactElement} The line.
 */
function Stages( { answer } ) {
	const done = doneSteps( answer );
	const active = {
		asked: __( 'Planning the search', 'newspack-intelligence' ),
		planned: __( 'Reading the items', 'newspack-intelligence' ),
		read: __( 'Writing the answer', 'newspack-intelligence' ),
	}[ answer.state ];
	return (
		<ol className="newspack-intelligence__stages">
			{ done.map( ( text ) => (
				<li
					key={ text }
					className="newspack-intelligence__stage is-done"
				>
					<Icon icon={ check } size={ 16 } />
					{ text }
				</li>
			) ) }
			<li className="newspack-intelligence__stage is-active">
				<Spinner />
				{ active }
			</li>
		</ol>
	);
}

/**
 * The steps an answer says have ended, in order: the plan, then the read.
 *
 * @param {Object} answer What `ask_result` answered.
 * @return {string[]} Each step's done form.
 */
function doneSteps( answer ) {
	const planned = sprintf(
		/* translators: %d: how many searches the ask planned. */
		_n(
			'Planned %d search',
			'Planned %d searches',
			answer.searches.length,
			'newspack-intelligence'
		),
		answer.searches.length
	);
	const read = sprintf(
		/* translators: %d: how many items the ask read. */
		_n(
			'Read %d item',
			'Read %d items',
			answer.found,
			'newspack-intelligence'
		),
		answer.found
	);
	return (
		{ planned: [ planned ], read: [ planned, read ] }[ answer.state ] ?? []
	);
}

/**
 * What the ask searched and read, and how many sources its answer cites.
 *
 * @param {Object} props
 * @param {Object} props.answer What `ask_result` answered.
 * @return {import('react').ReactElement} The line.
 */
function Searched( { answer } ) {
	const searches = pageFormat( Intl.ListFormat, {
		type: 'conjunction',
	} ).format( answer.searches.map( searchText ) );
	return (
		<p className="newspack-nodes-status is-muted">
			{ sprintf(
				/* translators: 1: each search the ask ran, 2: the items it read, 3: the sources its answer cites. */
				_n(
					'Searched %1$s. Read %2$d item; this answer cites %3$d.',
					'Searched %1$s. Read %2$d items; this answer cites %3$d.',
					answer.found,
					'newspack-intelligence'
				),
				searches,
				answer.found,
				answer.items.length
			) }
		</p>
	);
}

/**
 * An answered ask: its sentences, each followed by a marker per source it
 * cites that the list holds, how many sentences were left out or hidden,
 * the sources, numbered, and what it searched; or which kind of nothing it
 * is. A marker shows its source in the list, washed for `WASH_MS`.
 *
 * @param {Object}                    props
 * @param {Object}                    props.answer       What `ask_result` answered.
 * @param {string}                    props.question     The question asked.
 * @param {(filters: Object) => void} props.onSearchFor  Opens Search on filters.
 * @param {(key: string) => void}     props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}    [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                   [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The answer.
 */
function Answered( {
	answer,
	question,
	onSearchFor,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const listRef = useRef( null );
	const show = ( n ) => {
		const row = listRef.current?.children[ n - 1 ];
		row?.scrollIntoView?.( { block: 'nearest' } );
		row?.classList.add( 'is-washed' );
		setTimeout( () => row?.classList.remove( 'is-washed' ), WASH_MS );
	};
	const counted = [
		0 < answer.dropped &&
			sprintf(
				/* translators: %d: how many sentences were left out. */
				_n(
					'%d sentence was left out because it cited nothing.',
					'%d sentences were left out because they cited nothing.',
					answer.dropped,
					'newspack-intelligence'
				),
				answer.dropped
			),
		0 < answer.hidden &&
			sprintf(
				/* translators: %d: how many sentences are hidden. */
				_n(
					'%d sentence is hidden because you can no longer read its sources.',
					'%d sentences are hidden because you can no longer read their sources.',
					answer.hidden,
					'newspack-intelligence'
				),
				answer.hidden
			),
	].filter( Boolean );
	if ( 0 === answer.found ) {
		return (
			<p className="newspack-nodes-empty-state is-quiet">
				{ __(
					'Nothing you can read matches this question. Try other words, or search instead.',
					'newspack-intelligence'
				) }{ ' ' }
				<button
					type="button"
					className="button"
					onClick={ () => onSearchFor( { q: question } ) }
				>
					{ __( 'Search for these words', 'newspack-intelligence' ) }
				</button>
			</p>
		);
	}
	return (
		<>
			{ 0 === answer.sentences.length && 0 === answer.hidden && (
				<p className="newspack-nodes-empty-state is-quiet">
					{ sprintf(
						/* translators: %d: how many items the ask read. */
						_n(
							'Read %d item, but none of them answers the question.',
							'Read %d items, but none of them answers the question.',
							answer.found,
							'newspack-intelligence'
						),
						answer.found
					) }{ ' ' }
					<button
						type="button"
						className="button"
						onClick={ () =>
							onSearchFor( searchFilters( answer.searches[ 0 ] ) )
						}
					>
						{ __( 'Show the items', 'newspack-intelligence' ) }
					</button>
				</p>
			) }
			{ 0 < answer.sentences.length && (
				<div className="newspack-intelligence__answer">
					{ answer.sentences.map( ( sentence, at ) => (
						<span key={ at }>
							<span>{ sentence.text }</span>
							{ sentence.cites
								.filter(
									( n ) => 1 <= n && n <= answer.items.length
								)
								.map( ( n ) => (
									<Citation
										key={ n }
										n={ n }
										item={ answer.items[ n - 1 ] }
										onShow={ show }
									/>
								) ) }{ ' ' }
						</span>
					) ) }
				</div>
			) }
			{ counted.map( ( text ) => (
				<p key={ text } className="newspack-nodes-status is-muted">
					{ text }
				</p>
			) ) }
			<Searched answer={ answer } />
			{ 0 < answer.items.length && (
				<section
					className="newspack-intelligence__sources"
					aria-label={ __( 'Sources', 'newspack-intelligence' ) }
				>
					<h3>{ __( 'Sources', 'newspack-intelligence' ) }</h3>
					<ItemRows items={ answer.items }>
						<ol
							ref={ listRef }
							className="newspack-intelligence__items"
						>
							{ answer.items.map( ( item ) => (
								<ItemRow
									as="li"
									key={ item.item_key }
									item={ item }
									onOpenEntity={ onOpenEntity }
									onSave={ onSave }
									canTune={ canTune }
								/>
							) ) }
						</ol>
					</ItemRows>
				</section>
			) }
		</>
	);
}

/**
 * What a failed, withheld or abandoned ask says, as one sentence.
 *
 * @param {Object}  answer What `ask_result` answered.
 * @param {boolean} gaveUp Whether the page stopped waiting for it.
 * @return {?string} The sentence, or null for an ask that has not failed.
 */
function failure( answer, gaveUp ) {
	if ( gaveUp && OWED.includes( answer.state ) ) {
		return __(
			'The answer took too long and was stopped. Ask again.',
			'newspack-intelligence'
		);
	}
	return [ 'failed', 'withheld' ].includes( answer.state )
		? sprintf(
				/* translators: %s: why there is no answer. */
				__( 'No answer: %s.', 'newspack-intelligence' ),
				answer.reason
		  )
		: null;
}

/**
 * The ask's state: refused, unreadable, owed with its steps, given up on,
 * failed, withheld or answered.
 *
 * @param {Object}                                                                   props
 * @param {{answer: ?Object, error: ?string, loading: boolean, refresh: () => void}} props.read         What `useIntelRead` returned for `ask_result`.
 * @param {{question: string, gaveUp: boolean}}                                      props.asked        The ask the page holds.
 * @param {() => void}                                                               props.onAskAgain   Asks the same question again.
 * @param {(filters: Object) => void}                                                props.onSearchFor  Opens Search on filters.
 * @param {(key: string) => void}                                                    props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                                                   [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                                                  [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactNode} The state.
 */
function AskState( {
	read,
	asked,
	onAskAgain,
	onSearchFor,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const { answer, error, loading } = read;
	if ( error ) {
		return <Unread error={ error } refresh={ read.refresh } />;
	}
	if ( loading ) {
		return <Stages answer={ { state: 'asked', searches: [], found: 0 } } />;
	}
	if ( false === answer.available ) {
		return (
			<p className="newspack-nodes-banner is-warning">
				{ __(
					'The intelligence graph could not be opened, so the answer was not read.',
					'newspack-intelligence'
				) }
			</p>
		);
	}
	const failed = failure( answer, asked.gaveUp );
	if ( failed ) {
		return (
			<p className="newspack-nodes-banner is-warning">
				{ failed }{ ' ' }
				{ ! asked.gaveUp && (
					<button
						type="button"
						className="button"
						onClick={ onAskAgain }
					>
						{ __( 'Ask again', 'newspack-intelligence' ) }
					</button>
				) }
			</p>
		);
	}
	if ( OWED.includes( answer.state ) ) {
		return <Stages answer={ answer } />;
	}
	return (
		<>
			<Answered
				answer={ answer }
				question={ asked.question }
				onSearchFor={ onSearchFor }
				onOpenEntity={ onOpenEntity }
				onSave={ onSave }
				canTune={ canTune }
			/>
			<p className="newspack-nodes-status is-muted">
				{ __(
					'Answers use only the items listed. Open a source before acting on it.',
					'newspack-intelligence'
				) }
			</p>
		</>
	);
}

/**
 * Announces each step an ask ends, politely, and its failure assertively,
 * once each as the answer reaches it.
 *
 * @param {?Object} answer What `ask_result` answered, or null.
 * @param {boolean} gaveUp Whether the page stopped waiting for it.
 */
function useAnnounced( answer, gaveUp ) {
	const failed = answer ? failure( answer, gaveUp ) : null;
	const step = answer ? doneSteps( answer ).at( -1 ) : undefined;
	useEffect( () => {
		if ( step ) {
			speak( step, 'polite' );
		}
	}, [ step ] );
	useEffect( () => {
		if ( failed ) {
			speak( failed, 'assertive' );
		}
	}, [ failed ] );
}

/**
 * The `ask` block's component, on Search and on any team view or profile
 * whose layout holds the block, a plain part of the page: a question
 * field under its visible label, which sends `ask` with the question alone
 * and holds the key it answers. The answer is read through `ask_result`,
 * again on the Router's tick while it is owed and the graph opens, so the
 * page holds the one key it asked and nothing pairs a reply with its
 * request. The page holds the ask, `{ key, at, gaveUp, question }`, `at`
 * the substrate clock's second it landed, so a switch between views keeps it
 * until **Clear**; the box stops asking once the ask has waited the `wait_s`
 * the server answers. The block's examples show, each as "Try: %s", until
 * the reader's first ask in this browser. While its layout is
 * edited it asks nothing and marks no first ask, its **Ask** inert.
 *
 * @param {Object}                                                        props
 * @param {string}                                                        props.label        The field's visible label.
 * @param {string}                                                        props.placeholder  The field's placeholder.
 * @param {string[]}                                                      props.examples     The questions offered before a first ask.
 * @param {?{key: string, at: number, gaveUp: boolean, question: string}} props.asked        The ask the page holds, or null.
 * @param {(asked: ?Object) => void}                                      props.onAsked      Holds a new one.
 * @param {(filters: Object) => void}                                     props.onSearchFor  Opens Search on filters.
 * @param {(key: string) => void}                                         props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                                        [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                                       [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The box.
 */
export default function AskBox( {
	label,
	placeholder,
	examples,
	asked,
	onAsked,
	onSearchFor,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const [ question, setQuestion ] = useState( () => asked?.question ?? '' );
	const fieldRef = useRef( null );
	const sentRef = useRef( '' );
	const [ askedOnce, , markAsked ] = usePersistedFlag(
		`newspack-intelligence:asked-once:${
			/** @type {{NewspackNodesData: {userId: string}}} */ (
				/** @type {unknown} */ ( window )
			).NewspackNodesData.userId
		}`,
		false
	);
	const ask = useIntelWrite( 'ask', {
		scope: 'ask-box:ask',
		// A new ask changes no answer read before it.
		dirties: [],
		refresh: ( landed ) =>
			onAsked( {
				key: landed.ask_key,
				at: Core.now(),
				gaveUp: false,
				question: sentRef.current,
			} ),
	} );
	const read = useIntelRead(
		'ask-box:result',
		'ask_result',
		null === asked ? null : commandArgs( { key: asked.key } )
	);
	const answer = read.answer;
	useAnnounced( null === asked ? null : answer, Boolean( asked?.gaveUp ) );
	useRouterTick( {
		name: 'ask-box:poll',
		onTick: () => {
			if ( Core.now() - asked.at >= answer.wait_s ) {
				onAsked( { ...asked, gaveUp: true } );
			} else {
				read.refresh();
			}
		},
		intervalMs: POLL_MS,
		enabled:
			null !== asked &&
			! asked.gaveUp &&
			OWED.includes( answer?.state ) &&
			false !== answer.available,
	} );
	const editing = useEditing();
	const send = ( text ) => {
		if ( editing ) {
			return;
		}
		sentRef.current = text;
		if ( ! askedOnce ) {
			markAsked();
		}
		onAsked( null );
		ask.run( { question: text } );
	};
	const blank = '' === question.trim();

	return (
		<section aria-label={ __( 'Ask', 'newspack-intelligence' ) }>
			<form
				className="newspack-intelligence__ask"
				onSubmit={ ( event ) => {
					event.preventDefault();
					if ( ! blank ) {
						send( question );
					}
				} }
			>
				<TextControl
					ref={ fieldRef }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ label }
					placeholder={ placeholder }
					value={ question }
					onChange={ setQuestion }
				/>
				<button
					type="submit"
					className="button button-primary"
					disabled={ blank || ask.pending }
					aria-disabled={ editing || undefined }
				>
					{ __( 'Ask', 'newspack-intelligence' ) }
				</button>
			</form>
			{ ! askedOnce && 0 < examples.length && (
				<p className="newspack-intelligence__examples">
					{ examples.map( ( example ) => (
						<button
							key={ example }
							type="button"
							className="button-link"
							onClick={ () => setQuestion( example ) }
						>
							{ sprintf(
								/* translators: %s: an example question. */
								__( 'Try: %s', 'newspack-intelligence' ),
								example
							) }
						</button>
					) ) }
				</p>
			) }
			<Refusal error={ ask.refusal } />
			{ null !== asked && (
				<>
					<AskState
						read={ read }
						asked={ asked }
						onAskAgain={ () => send( asked.question ) }
						onSearchFor={ onSearchFor }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
					<p className="newspack-intelligence__actions">
						<button
							type="button"
							className="button"
							onClick={ () => {
								setQuestion( '' );
								onAsked( null );
							} }
						>
							{ __( 'Clear', 'newspack-intelligence' ) }
						</button>
						<button
							type="button"
							className="button"
							onClick={ () => {
								setQuestion( '' );
								fieldRef.current?.focus();
							} }
						>
							{ __( 'Ask another', 'newspack-intelligence' ) }
						</button>
					</p>
				</>
			) }
		</section>
	);
}

import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Card, CardBody, TextControl } from '@wordpress/components';
import { Core } from '@newspack-nodes/runtime';
import useRouterTick from '@newspack-nodes/shared/hooks/useRouterTick';
import {
	commandArgs,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import ItemRow from './ItemRow';
import Refusal from './Refusal';
import { Unread } from './Answer';
import { word } from './words';

/** How often an ask still owed its answer is read again, on the Router's tick. */
const POLL_MS = 3000;

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
 * The searches an ask ran, under its answer; nothing for none.
 *
 * @param {Object}   props
 * @param {Object[]} props.searches The searches.
 * @return {?import('react').ReactElement} The line.
 */
function Searched( { searches } ) {
	return 0 < searches.length ? (
		<p className="newspack-nodes-status is-muted">
			{ sprintf(
				/* translators: %s: each search the ask ran. */
				__( 'Searched: %s', 'newspack-intelligence' ),
				searches.map( searchText ).join( '; ' )
			) }
		</p>
	) : null;
}

/**
 * How long an ask may wait, as the reader reads it.
 *
 * @param {number} seconds The wait.
 * @return {string} The wait.
 */
function waitText( seconds ) {
	const minutes = Math.round( seconds / 60 );
	return 60 <= seconds
		? sprintf(
				/* translators: %d: minutes. */
				_n(
					'%d minute',
					'%d minutes',
					minutes,
					'newspack-intelligence'
				),
				minutes
		  )
		: sprintf(
				/* translators: %d: seconds. */
				_n(
					'%d second',
					'%d seconds',
					seconds,
					'newspack-intelligence'
				),
				seconds
		  );
}

/**
 * An answered ask: its sentences, each followed by the numbers of the items
 * it cites, then those items in that numbering; or why none is shown.
 *
 * @param {Object}                 props
 * @param {Object}                 props.answer       What `ask_result` answered.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The answer.
 */
function Answered( { answer, onOpenEntity, onSave, canTune } ) {
	if ( 0 === answer.found ) {
		return (
			<p className="newspack-nodes-empty-state is-quiet">
				{ __(
					'Nothing you can read matched this question.',
					'newspack-intelligence'
				) }
			</p>
		);
	}
	if ( 0 === answer.sentences.length ) {
		return (
			<p className="newspack-nodes-empty-state is-quiet">
				{ sprintf(
					/* translators: %d: how many items the answer was written from. */
					_n(
						'The answer cited none of the %d item it read, so nothing is shown.',
						'The answer cited none of the %d items it read, so nothing is shown.',
						answer.found,
						'newspack-intelligence'
					),
					answer.found
				) }
			</p>
		);
	}
	return (
		<>
			<div className="newspack-intelligence__answer">
				{ answer.sentences.map( ( sentence, at ) => (
					<p key={ at }>
						<span>{ sentence.text }</span>{ ' ' }
						<span className="newspack-nodes-status is-muted">
							{ sentence.cites
								.map( ( n ) => `[${ n }]` )
								.join( '' ) }
						</span>
					</p>
				) ) }
			</div>
			{ 0 < answer.dropped && (
				<p className="newspack-nodes-status is-muted">
					{ sprintf(
						/* translators: %d: how many sentences were left out. */
						_n(
							'%d sentence was left out for citing nothing it was given.',
							'%d sentences were left out for citing nothing they were given.',
							answer.dropped,
							'newspack-intelligence'
						),
						answer.dropped
					) }
				</p>
			) }
			<ol className="newspack-intelligence__items">
				{ answer.items.map( ( item ) => (
					<ItemRow
						key={ item.item_key }
						item={ item }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
				) ) }
			</ol>
		</>
	);
}

/**
 * The ask's state: refused, unreadable, owed, given up on, failed,
 * withheld or answered, the searches it ran beneath.
 *
 * @param {Object}                                                                   props
 * @param {{answer: ?Object, error: ?string, loading: boolean, refresh: () => void}} props.read         What `useIntelRead` returned for `ask_result`.
 * @param {boolean}                                                                  props.gaveUp       Whether the page stopped waiting for it.
 * @param {(key: string) => void}                                                    props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                                                   [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                                                  [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactNode} The state.
 */
function AskState( { read, gaveUp, onOpenEntity, onSave, canTune } ) {
	const { answer, error, loading } = read;
	if ( error ) {
		return <Unread error={ error } refresh={ read.refresh } />;
	}
	if ( loading ) {
		return (
			<p className="newspack-nodes-status is-muted" role="status">
				{ __( 'Working on it…', 'newspack-intelligence' ) }
			</p>
		);
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
	if ( 'asked' === answer.state ) {
		return gaveUp ? (
			<p className="newspack-nodes-banner is-warning">
				{ sprintf(
					/* translators: %s: how long an ask may wait. */
					__(
						'No answer came back within %s; ask again.',
						'newspack-intelligence'
					),
					waitText( answer.wait_s )
				) }
			</p>
		) : (
			<p className="newspack-nodes-status is-muted" role="status">
				{ __( 'Working on it…', 'newspack-intelligence' ) }
			</p>
		);
	}
	return (
		<>
			{ 'answered' === answer.state ? (
				<Answered
					answer={ answer }
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
					canTune={ canTune }
				/>
			) : (
				<p className="newspack-nodes-banner is-warning">
					{ sprintf(
						/* translators: %s: why there is no answer. */
						__(
							'No answer: %s. Ask again.',
							'newspack-intelligence'
						),
						answer.reason
					) }
				</p>
			) }
			<Searched searches={ answer.searches } />
		</>
	);
}

/**
 * The question field heading the Briefing and Search. A question goes out as
 * `ask`, which answers its key; the answer is read through `ask_result`,
 * again on the Router's tick while it is still owed and the graph opens, so
 * the page holds the one key it asked and nothing pairs a reply with its
 * request. The page holds the ask, `{ key, at, gaveUp }`, `at` the substrate
 * clock's second it landed, so a switch between views keeps it; the box
 * stops asking once the ask has waited the `wait_s` the server answers.
 *
 * @param {Object}                                      props
 * @param {{name: string, heading: string}}             props.operator     Who runs the deployment, whom the box offers to ask about by name.
 * @param {?{key: string, at: number, gaveUp: boolean}} props.asked        The ask the page holds, or null.
 * @param {(asked: ?Object) => void}                    props.onAsked      Holds a new one.
 * @param {(key: string) => void}                       props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                      [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                     [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The box.
 */
export default function AskBox( {
	operator,
	asked,
	onAsked,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const [ question, setQuestion ] = useState( '' );
	const ask = useIntelWrite( 'ask', {
		scope: 'ask-box:ask',
		// A new ask changes no answer read before it.
		dirties: [],
		refresh: ( landed ) =>
			onAsked( { key: landed.ask_key, at: Core.now(), gaveUp: false } ),
	} );
	const read = useIntelRead(
		'ask-box:result',
		'ask_result',
		null === asked ? null : commandArgs( { key: asked.key } )
	);
	const answer = read.answer;
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
			'asked' === answer?.state &&
			false !== answer.available,
	} );
	const blank = '' === question.trim();

	return (
		<Card as="section" aria-label={ __( 'Ask', 'newspack-intelligence' ) }>
			<CardBody>
				<form
					className="newspack-intelligence__ask"
					onSubmit={ ( event ) => {
						event.preventDefault();
						if ( ! blank ) {
							onAsked( null );
							ask.run( { question } );
						}
					} }
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						hideLabelFromVision
						label={ __( 'Question', 'newspack-intelligence' ) }
						placeholder={ sprintf(
							/* translators: %s: who runs the deployment. */
							__(
								'Ask about clients, the industry or %s…',
								'newspack-intelligence'
							),
							operator.name
						) }
						value={ question }
						onChange={ setQuestion }
					/>
					<button
						type="submit"
						className="button button-primary"
						disabled={ blank || ask.pending }
					>
						{ __( 'Ask', 'newspack-intelligence' ) }
					</button>
				</form>
				<Refusal error={ ask.refusal } />
				{ null !== asked && (
					<AskState
						read={ read }
						gaveUp={ asked.gaveUp }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
				) }
			</CardBody>
		</Card>
	);
}

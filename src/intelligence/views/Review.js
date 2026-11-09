import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Snackbar, TextControl, ToggleControl } from '@wordpress/components';
import { Icon, check, closeSmall } from '@wordpress/icons';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { isTypingTarget } from '@newspack-nodes/shared/utils/isTypingTarget';
import { usePersistedFlag } from '@newspack-nodes/shared/hooks/usePersistedState';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import Answer from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import { SaveButton } from '../components/ItemRow';
import Refusal from '../components/Refusal';
import { metaLine } from '../components/format';

/**
 * A proposed link in words: what the entity is to the item, how it was
 * found and how sure the finding was, in the words the page localizes. A
 * role or method the words leave out reads as given.
 *
 * @param {Object} link       The proposed link.
 * @param {Object} vocabulary The localized vocabulary.
 * @return {string} The sentence.
 */
function howFound( link, vocabulary ) {
	return sprintf(
		/* translators: 1: what the entity is to the item, 2: how it was found, 3: confidence, 0 to 100. */
		__( '%1$s, found by %2$s (%3$d%%)', 'newspack-intelligence' ),
		vocabulary.linkRoleWords?.[ link.role ] ?? link.role,
		vocabulary.methodWords?.[ link.method ] ?? link.method,
		Math.round( Number( link.confidence ) * 100 )
	);
}

/** A row the review keys move between, by its attribute. */
const ROW = '[data-review-row]';

/** The keys that press a row's control, each the `aria-keyshortcuts` of one. */
const JUDGMENT_KEYS = [ 'y', 'n', 'm' ];

/** Where this browser keeps whether the single-key shortcuts are off. */
const KEYS_OFF_KEY = 'newspack-intelligence:review:single-keys-off';

/**
 * One queue's rows as a list the review keys work in: `j` and `k` focus the
 * next and the previous row, `y`, `n` and `m` press the focused row's
 * control whose `aria-keyshortcuts` names that key, and `z` undoes the most
 * recent judgment still pending. A key typed in a field, or with a modifier,
 * is left alone, and every key is while the keys are off. A judgment takes
 * its row out, so once the list renders without it focus moves to the row
 * in its place, else the last. The list is named for its queue and
 * described by the legend naming its keys.
 *
 * @param {Object}                    props
 * @param {string}                    props.label       The queue's name.
 * @param {boolean}                   props.keys        Whether it takes the keys.
 * @param {string}                    props.describedBy The legend's id.
 * @param {() => void}                props.onUndo      Undoes the latest judgment.
 * @param {import('react').ReactNode} props.children    Its rows.
 * @return {import('react').ReactElement} The list.
 */
function ReviewList( { label, keys, describedBy, onUndo, children } ) {
	const list = useRef( null );
	const judged = useRef( null );
	useLayoutEffect( () => {
		if ( null === judged.current ) {
			return;
		}
		const left = list.current.querySelectorAll( ROW );
		( left[ judged.current ] ?? left[ left.length - 1 ] )?.focus();
		judged.current = null;
	} );

	const onKeyDown = ( event ) => {
		const { key, target } = event;
		if (
			! keys ||
			event.altKey ||
			event.ctrlKey ||
			event.metaKey ||
			isTypingTarget( target )
		) {
			return;
		}
		if ( 'z' === key ) {
			event.preventDefault();
			onUndo();
			return;
		}
		const rows = [ ...list.current.querySelectorAll( ROW ) ];
		const at = rows.findIndex( ( row ) => row.contains( target ) );
		if ( 'j' === key || 'k' === key ) {
			event.preventDefault();
			const step = 'j' === key ? 1 : -1;
			rows[
				Math.min( Math.max( at + step, 0 ), rows.length - 1 )
			]?.focus();
			return;
		}
		const control =
			JUDGMENT_KEYS.includes( key ) &&
			rows[ at ]?.querySelector( `[aria-keyshortcuts="${ key }"]` );
		if ( ! control ) {
			return;
		}
		event.preventDefault();
		judged.current = 'm' === key ? null : at;
		control.click();
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex, jsx-a11y/no-noninteractive-element-interactions -- the review keys move between its rows.
		<ul
			ref={ list }
			className="newspack-intelligence__items"
			tabIndex={ 0 }
			aria-label={ label }
			aria-describedby={ keys ? describedBy : undefined }
			onKeyDown={ onKeyDown }
		>
			{ children }
		</ul>
	);
}

/**
 * The review keys' legend, visible, and the switch turning them off, which
 * this browser remembers.
 *
 * @param {Object}     props
 * @param {string}     props.id       The legend's id, which each list cites.
 * @param {boolean}    props.keys     Whether the keys are on.
 * @param {() => void} props.onToggle Turns them off, or back on.
 * @return {import('react').ReactElement} The legend and the switch.
 */
function KeysLegend( { id, keys, onToggle } ) {
	return (
		<div className="newspack-intelligence__keys">
			{ keys && (
				<p id={ id } className="newspack-nodes-status is-muted">
					{ __(
						'Keys: J and K to move, Y yes, N no, M same as…, Z undo.',
						'newspack-intelligence'
					) }
				</p>
			) }
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Turn off single-key shortcuts',
					'newspack-intelligence'
				) }
				checked={ ! keys }
				onChange={ onToggle }
			/>
		</div>
	);
}

/**
 * The most recent judgment still pending, as a snackbar offering its Undo,
 * until it is sent, undone or dismissed. Its message is not spoken here:
 * `useDeferredWrite` announced it as it was deferred.
 *
 * @param {Object}                         props
 * @param {?{id: number, message: string}} props.latest The pending write.
 * @param {() => void}                     props.onUndo Undoes it.
 * @return {?import('react').ReactElement} The snackbar.
 */
function UndoSnackbar( { latest, onUndo } ) {
	const [ dismissed, setDismissed ] = useState( null );
	if ( ! latest || latest.id === dismissed ) {
		return null;
	}
	return (
		<div className="newspack-intelligence__undo">
			<Snackbar
				explicitDismiss
				spokenMessage=""
				actions={ [
					{
						label: __( 'Undo', 'newspack-intelligence' ),
						onClick: onUndo,
					},
				] }
				onRemove={ () => setDismissed( latest.id ) }
			>
				{ latest.message }
			</Snackbar>
		</div>
	);
}

/**
 * The sentence a proposal was found in, quoted; nothing without one.
 *
 * @param {Object} props
 * @param {string} props.text The sentence.
 * @return {?import('react').ReactElement} The quote.
 */
function Evidence( { text } ) {
	return text ? (
		<blockquote className="newspack-intelligence__evidence">
			{ text }
		</blockquote>
	) : null;
}

/**
 * One proposal as a single control: the entity's chip, then for a curator
 * the affirming and rejecting glyphs, joined into one pill. As a `row` the
 * review keys move to it.
 *
 * A curator's `judge` names the affirming glyph for the entity (`yes`),
 * affirms (`onYes`) and rejects (`onNo`).
 *
 * @param {Object}                                                                       props
 * @param {string}                                                                       props.entityKey     The entity's key.
 * @param {?string}                                                                      props.name          Its name.
 * @param {string}                                                                       [props.description] What the proposal rests on.
 * @param {(key: string) => void}                                                        props.onOpen        Opens the entity.
 * @param {?{yes: (label: string) => string, onYes: () => void, onNo: () => void}|false} props.judge         A curator's judgment, or none.
 * @param {boolean}                                                                      [props.row]         Whether it is a review row.
 * @param {string}                                                                       [props.reviewKey]   A row's deferred-write key.
 * @return {import('react').ReactElement} The pill.
 */
function Judgment( {
	entityKey,
	name,
	description,
	onOpen,
	judge,
	row,
	reviewKey,
} ) {
	const label = name || entityKey;
	return (
		<span
			className="newspack-intelligence__judgment"
			{ ...( row && {
				tabIndex: -1,
				'data-review-row': '',
				'data-review-key': reviewKey,
			} ) }
		>
			<EntityChip
				entityKey={ entityKey }
				name={ name }
				description={ description }
				onOpen={ onOpen }
			/>
			{ judge && (
				<>
					<button
						type="button"
						className="button button-small"
						aria-label={ judge.yes( label ) }
						aria-keyshortcuts="y"
						onClick={ judge.onYes }
					>
						<Icon icon={ check } size={ 18 } />
					</button>
					<button
						type="button"
						className="button button-small"
						aria-keyshortcuts="n"
						aria-label={ sprintf(
							/* translators: %s: the entity's name. */
							__( 'Reject %s', 'newspack-intelligence' ),
							label
						) }
						onClick={ judge.onNo }
					>
						<Icon icon={ closeSmall } size={ 18 } />
					</button>
				</>
			) }
		</span>
	);
}

/**
 * The control merging one proposed entity into another the curator names,
 * closed until asked for, its field focused as it opens.
 *
 * @param {Object}                 props
 * @param {string}                 props.label   The entity's name.
 * @param {(into: string) => void} props.onMerge Sends the merge.
 * @return {import('react').ReactElement} The control.
 */
function Merge( { label, onMerge } ) {
	const [ open, setOpen ] = useState( false );
	const [ into, setInto ] = useState( '' );
	const field = useRef( null );
	useEffect( () => {
		if ( open ) {
			field.current?.focus();
		}
	}, [ open ] );
	return (
		<>
			<button
				type="button"
				className="button button-small"
				aria-keyshortcuts="m"
				aria-expanded={ open }
				aria-label={ sprintf(
					/* translators: %s: the entity's name. */
					__( 'Merge %s…', 'newspack-intelligence' ),
					label
				) }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Merge…', 'newspack-intelligence' ) }
			</button>
			{ open && (
				<form
					className="newspack-intelligence__merge"
					{ ...submitProps( () => onMerge( into ) ) }
				>
					<TextControl
						ref={ field }
						__next40pxDefaultSize
						label={ __( 'Merge into', 'newspack-intelligence' ) }
						placeholder="organization:the-valley-times"
						value={ into }
						onChange={ setInto }
					/>
					<button type="submit" className="button">
						{ __( 'Merge', 'newspack-intelligence' ) }
					</button>
				</form>
			) }
		</>
	);
}

/**
 * The proposed links as one row per item, in the order the queue answers
 * them, each row holding the links proposed for that item.
 *
 * @param {Array<Object>} links The queue's links.
 * @return {Array<Array<Object>>} The links, grouped by item.
 */
function byItem( links ) {
	const rows = new Map();
	for ( const link of links ) {
		const row = rows.get( link.item_key ) ?? [];
		row.push( link );
		rows.set( link.item_key, row );
	}
	return [ ...rows.values() ];
}

/**
 * A proposed link's key: its item and entity.
 *
 * @param {Object} link The link.
 * @return {string} The key.
 */
const linkKey = ( link ) =>
	JSON.stringify( [ link.item_key, link.entity_key ] );

/**
 * A proposed entity's key.
 *
 * @param {Object} entity The entity.
 * @return {string} The key.
 */
const entityKey = ( entity ) => entity.entity_key;

/**
 * A suggested pair of themes' key: its two themes.
 *
 * @param {Object} pair The pair.
 * @return {string} The key.
 */
const pairKey = ( pair ) =>
	JSON.stringify( pair.themes.map( ( side ) => side.theme ) );

/**
 * A read with every row whose key `hidden` holds taken out.
 *
 * @param {{answer: ?Object}}       read   What `useHeld` returned as its read.
 * @param {string}                  rows   The answer's row list.
 * @param {(row: Object) => string} keyOf  A row's key.
 * @param {Set<string>}             hidden The keys a deferred write hides.
 * @return {Object} The read without those rows.
 */
function without( read, rows, keyOf, hidden ) {
	const listed = read.answer?.[ rows ];
	return listed
		? {
				...read,
				answer: {
					...read.answer,
					[ rows ]: listed.filter(
						( row ) => ! hidden.has( keyOf( row ) )
					),
				},
		  }
		: read;
}

/**
 * One suggested pair of themes: each theme, how many stories each holds,
 * how many entities their stories share and the words their names share,
 * and for a curator a merge of either theme into the other.
 *
 * @param {Object}                                      props
 * @param {Object}                                      props.pair    The pair, as `theme_suggestions` answers it.
 * @param {?(from: string, into: string) => void|false} props.onMerge Sends a merge, or none for a reader.
 * @return {import('react').ReactElement} The row.
 */
function ThemePair( { pair, onMerge } ) {
	const [ first, second ] = pair.themes;
	const stories = ( side ) =>
		sprintf(
			/* translators: %d: how many stories carry the theme. */
			_n(
				'%d story',
				'%d stories',
				side.stories,
				'newspack-intelligence'
			),
			side.stories
		);
	return (
		<li
			className="newspack-intelligence__item"
			tabIndex={ -1 }
			data-review-key={ pairKey( pair ) }
		>
			<div className="newspack-intelligence__item-head">
				<strong>{ first.theme }</strong>
				<strong>{ second.theme }</strong>
			</div>
			<span className="newspack-nodes-status is-muted">
				{ sprintf(
					/* translators: 1: the first theme's stories, 2: the second theme's stories, 3: the entities their stories share, 4: the words their names share. */
					__(
						'%1$s and %2$s, %3$s in common, sharing %4$s',
						'newspack-intelligence'
					),
					stories( first ),
					stories( second ),
					sprintf(
						/* translators: %d: how many entities both themes' stories are about. */
						_n(
							'%d entity',
							'%d entities',
							pair.shared,
							'newspack-intelligence'
						),
						pair.shared
					),
					pair.words.join( ', ' )
				) }
			</span>
			{ onMerge && (
				<div className="newspack-intelligence__judgments">
					{ [
						[ second, first ],
						[ first, second ],
					].map( ( [ from, into ] ) => (
						<button
							key={ into.theme }
							type="button"
							className="button button-small"
							aria-label={ sprintf(
								/* translators: 1: the theme merged away, 2: the theme it merges into. */
								__(
									'Merge %1$s into %2$s',
									'newspack-intelligence'
								),
								from.theme,
								into.theme
							) }
							onClick={ () => onMerge( from.theme, into.theme ) }
						>
							{ sprintf(
								/* translators: %s: the theme the other merges into. */
								__( 'Merge into %s', 'newspack-intelligence' ),
								into.theme
							) }
						</button>
					) ) }
				</div>
			) }
		</li>
	);
}

/**
 * Review: the proposed links and the proposed entities, each row with the
 * items citing it and the sentence each proposal was found in, and for a
 * curator the judgments each takes — confirm or reject a link; accept,
 * reject or merge an entity. A link row is one item with a pill per entity
 * proposed for it. A judgment takes its row out of the queue at once, so the
 * next can follow, and waits `UNDO_MS` before it is sent, a snackbar
 * offering its Undo meanwhile; a merge of two entities is sent at once,
 * because a merge cannot be taken back. One that lands re-reads its queue, and a
 * refused one shows the refusal and puts its row back. In either list `j`
 * and `k` move between the judgments, `y` confirms or accepts, `n` rejects,
 * `m` opens a merge and `z` undoes the latest judgment still pending, unless
 * the reader turned the keys off. Below them, the pairs of themes
 * `theme_suggestions` reads, each offering a curator a merge of either theme
 * into the other, deferred as a judgment is. A link row's item saves to a
 * folder as any item does.
 *
 * @param {Object}                 props
 * @param {boolean}                props.canTune      Whether to offer judgments.
 * @param {Object}                 props.vocabulary   The localized vocabulary.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Review( {
	canTune,
	vocabulary,
	onOpenEntity,
	onSave,
} ) {
	const deferred = useDeferredWrite();
	const [ keysOff, , toggleKeys ] = usePersistedFlag( KEYS_OFF_KEY, false );
	const keysId = useId();
	const view = useRef( null );
	const restored = useRef( null );
	useLayoutEffect( () => {
		const key = restored.current;
		if ( null === key ) {
			return;
		}
		restored.current = null;
		[ ...view.current.querySelectorAll( '[data-review-key]' ) ]
			.find( ( row ) => key === row.dataset.reviewKey )
			?.focus();
	} );
	const undo = () => {
		restored.current = deferred.undo();
	};

	const links = useIntelRead( 'review:links', 'review', [ 'links' ] );
	const entities = useIntelRead( 'review:entities', 'review', [
		'entities',
	] );

	const themes = useIntelRead( 'review:themes', 'theme_suggestions', [] );

	const judgedLinks = useHeld( links, 'links', linkKey );
	const judgedEntities = useHeld( entities, 'entities', entityKey );
	const judgedThemes = useHeld( themes, 'suggestions', pairKey );

	const relink = {
		refresh: links.refresh,
		onRefused: judgedLinks.refused,
	};
	const rejudge = {
		refresh: entities.refresh,
		onRefused: judgedEntities.refused,
	};
	const confirmLink = useIntelWrite( 'confirm_link', relink );
	const rejectLink = useIntelWrite( 'reject_link', relink );
	const acceptEntity = useIntelWrite( 'accept_entity', rejudge );
	const rejectEntity = useIntelWrite( 'reject_entity', rejudge );
	const mergeEntities = useIntelWrite( 'merge_entities', rejudge );
	const mergeTheme = useIntelWrite( 'merge_theme', {
		refresh: themes.refresh,
		onRefused: judgedThemes.refused,
	} );
	const keyProps = {
		keys: ! keysOff,
		describedBy: keysId,
		onUndo: undo,
	};
	const judgments = [
		confirmLink,
		rejectLink,
		acceptEntity,
		rejectEntity,
		mergeEntities,
		mergeTheme,
	];

	return (
		<div ref={ view } className="newspack-intelligence__view">
			{ judgments.map( ( write, i ) => (
				<Refusal key={ i } error={ write.refusal } />
			) ) }
			<KeysLegend
				id={ keysId }
				keys={ ! keysOff }
				onToggle={ toggleKeys }
			/>
			<Answer
				title={ __( 'Proposed links', 'newspack-intelligence' ) }
				read={ without(
					judgedLinks.read,
					'links',
					linkKey,
					deferred.hidden
				) }
				rows="links"
				empty={ __(
					'No proposed link awaits review.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ReviewList
						label={ __(
							'Proposed links',
							'newspack-intelligence'
						) }
						{ ...keyProps }
					>
						{ byItem( answer.links ).map( ( row ) => (
							<li
								key={ row[ 0 ].item_key }
								className="newspack-intelligence__item"
							>
								<div className="newspack-intelligence__item-head">
									<strong>
										<Cite
											url={ row[ 0 ].url }
											title={ row[ 0 ].title }
										/>
									</strong>
									<SaveButton
										item={ row[ 0 ] }
										onSave={ onSave }
									/>
								</div>
								<div className="newspack-intelligence__judgments">
									{ row.map( ( link ) => {
										const pair = {
											item: link.item_key,
											entity: link.entity_key,
										};
										const key = linkKey( link );
										const judge = ( write, said ) =>
											deferred.defer(
												key,
												() =>
													judgedLinks.hold(
														key,
														null,
														() => write.run( pair )
													),
												sprintf(
													said,
													link.name || link.entity_key
												)
											);
										return (
											<Judgment
												key={ link.entity_key }
												row
												reviewKey={ key }
												entityKey={ link.entity_key }
												name={ link.name }
												description={ howFound(
													link,
													vocabulary
												) }
												onOpen={ onOpenEntity }
												judge={
													canTune && {
														yes: ( label ) =>
															sprintf(
																/* translators: %s: the entity's name. */
																__(
																	'Confirm %s',
																	'newspack-intelligence'
																),
																label
															),
														onYes: () =>
															judge(
																confirmLink,
																/* translators: %s: the entity's name. */
																__(
																	'Confirmed %s.',
																	'newspack-intelligence'
																)
															),
														onNo: () =>
															judge(
																rejectLink,
																/* translators: %s: the entity's name. */
																__(
																	'Rejected %s.',
																	'newspack-intelligence'
																)
															),
													}
												}
											/>
										);
									} ) }
								</div>
								{ [
									...new Set(
										row.map( ( link ) => link.evidence )
									),
								].map( ( text ) => (
									<Evidence key={ text } text={ text } />
								) ) }
							</li>
						) ) }
					</ReviewList>
				) }
			</Answer>
			<Answer
				title={ __( 'Proposed entities', 'newspack-intelligence' ) }
				read={ without(
					judgedEntities.read,
					'entities',
					entityKey,
					deferred.hidden
				) }
				rows="entities"
				empty={ __(
					'No proposed entity awaits review.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ReviewList
						label={ __(
							'Proposed entities',
							'newspack-intelligence'
						) }
						{ ...keyProps }
					>
						{ answer.entities.map( ( entity ) => {
							const key = entity.entity_key;
							const hold = ( write, args ) =>
								judgedEntities.hold( key, null, () =>
									write.run( args )
								);
							const judge = ( write, said ) =>
								deferred.defer(
									key,
									() => hold( write, { key } ),
									sprintf( said, entity.name || key )
								);
							return (
								<li
									key={ key }
									className="newspack-intelligence__item"
									tabIndex={ -1 }
									data-review-row=""
									data-review-key={ key }
								>
									<div className="newspack-intelligence__item-head">
										<Judgment
											entityKey={ key }
											name={ entity.name }
											onOpen={ onOpenEntity }
											judge={
												canTune && {
													yes: ( label ) =>
														sprintf(
															/* translators: %s: the entity's name. */
															__(
																'Accept %s',
																'newspack-intelligence'
															),
															label
														),
													onYes: () =>
														judge(
															acceptEntity,
															/* translators: %s: the entity's name. */
															__(
																'Accepted %s.',
																'newspack-intelligence'
															)
														),
													onNo: () =>
														judge(
															rejectEntity,
															/* translators: %s: the entity's name. */
															__(
																'Rejected %s.',
																'newspack-intelligence'
															)
														),
												}
											}
										/>
										<span className="newspack-nodes-status is-muted">
											{ metaLine( entity.type, key ) }
										</span>
										{ canTune && (
											<Merge
												label={ entity.name || key }
												onMerge={ ( into ) =>
													hold( mergeEntities, {
														from: key,
														into,
													} )
												}
											/>
										) }
									</div>
									<ul className="newspack-intelligence__facts">
										{ entity.items.map( ( item ) => (
											<li key={ item.item_key }>
												<Cite
													url={ item.url }
													title={ item.title }
												/>{ ' ' }
												<SaveButton
													item={ item }
													onSave={ onSave }
												/>
												<Evidence
													text={ item.evidence }
												/>
											</li>
										) ) }
									</ul>
								</li>
							);
						} ) }
					</ReviewList>
				) }
			</Answer>
			<Answer
				title={ __( 'Themes', 'newspack-intelligence' ) }
				read={ without(
					judgedThemes.read,
					'suggestions',
					pairKey,
					deferred.hidden
				) }
				rows="suggestions"
				empty={ __(
					'No two themes look alike.',
					'newspack-intelligence'
				) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__items">
						{ answer.suggestions.map( ( pair ) => (
							<ThemePair
								key={ pairKey( pair ) }
								pair={ pair }
								onMerge={
									canTune &&
									( ( from, into ) =>
										deferred.defer(
											pairKey( pair ),
											() =>
												judgedThemes.hold(
													pairKey( pair ),
													null,
													() =>
														mergeTheme.run( {
															from,
															into,
														} )
												),
											sprintf(
												/* translators: 1: the theme merged away, 2: the theme it merges into. */
												__(
													'Merged %1$s into %2$s.',
													'newspack-intelligence'
												),
												from,
												into
											)
										) )
								}
							/>
						) ) }
					</ul>
				) }
			</Answer>
			<UndoSnackbar latest={ deferred.latest } onUndo={ undo } />
		</div>
	);
}

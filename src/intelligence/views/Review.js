import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl, VisuallyHidden } from '@wordpress/components';
import { Icon, check, closeSmall } from '@wordpress/icons';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { isTypingTarget } from '@newspack-nodes/shared/utils/isTypingTarget';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
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

/**
 * One queue's rows as a list the review keys work in: `j` and `k` focus the
 * next and the previous row, and `y`, `n` and `m` press the focused row's
 * control whose `aria-keyshortcuts` names that key. A key typed in a field,
 * or with a modifier, is left alone. A judgment takes its row out, so once
 * the list renders without it focus moves to the row in its place, else the
 * last. The list is named for its queue and described, for a screen reader,
 * by the keys it takes.
 *
 * @param {Object}                    props
 * @param {string}                    props.label    The queue's name.
 * @param {import('react').ReactNode} props.children Its rows.
 * @return {import('react').ReactElement} The list.
 */
function ReviewList( { label, children } ) {
	const list = useRef( null );
	const judged = useRef( null );
	const keysId = useId();
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
			event.altKey ||
			event.ctrlKey ||
			event.metaKey ||
			isTypingTarget( target )
		) {
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
		<>
			<VisuallyHidden id={ keysId }>
				{ __(
					'Press j and k to move between proposals, y to confirm or accept, n to reject and m to merge.',
					'newspack-intelligence'
				) }
			</VisuallyHidden>
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex, jsx-a11y/no-noninteractive-element-interactions -- the review keys move between its rows. */ }
			<ul
				ref={ list }
				className="newspack-intelligence__items"
				tabIndex={ 0 }
				aria-label={ label }
				aria-describedby={ keysId }
				onKeyDown={ onKeyDown }
			>
				{ children }
			</ul>
		</>
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
 * @return {import('react').ReactElement} The pill.
 */
function Judgment( { entityKey, name, description, onOpen, judge, row } ) {
	const label = name || entityKey;
	return (
		<span
			className="newspack-intelligence__judgment"
			{ ...( row && { tabIndex: -1, 'data-review-row': '' } ) }
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
		<li className="newspack-intelligence__item">
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
 * proposed for it. A judgment takes its row out of the queue as it is sent,
 * so the next can follow at once; one that lands re-reads its queue, and a
 * refused one shows the refusal and puts its row back. In either list `j`
 * and `k` move between the judgments, `y` confirms or accepts, `n` rejects
 * and `m` opens a merge. Below them, the pairs of themes `theme_suggestions`
 * reads, each offering a curator a merge of either theme into the other,
 * held as a judgment is.
 *
 * @param {Object}                props
 * @param {boolean}               props.canTune      Whether to offer judgments.
 * @param {Object}                props.vocabulary   The localized vocabulary.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {import('react').ReactElement} The view.
 */
export default function Review( { canTune, vocabulary, onOpenEntity } ) {
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
	const judgments = [
		confirmLink,
		rejectLink,
		acceptEntity,
		rejectEntity,
		mergeEntities,
		mergeTheme,
	];

	return (
		<div className="newspack-intelligence__view">
			{ judgments.map( ( write, i ) => (
				<Refusal key={ i } error={ write.refusal } />
			) ) }
			<Answer
				title={ __( 'Proposed links', 'newspack-intelligence' ) }
				read={ judgedLinks.read }
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
					>
						{ byItem( answer.links ).map( ( row ) => (
							<li
								key={ row[ 0 ].item_key }
								className="newspack-intelligence__item"
							>
								<strong>
									<Cite
										url={ row[ 0 ].url }
										title={ row[ 0 ].title }
									/>
								</strong>
								<div className="newspack-intelligence__judgments">
									{ row.map( ( link ) => {
										const pair = {
											item: link.item_key,
											entity: link.entity_key,
										};
										const judge = ( write ) =>
											judgedLinks.hold(
												linkKey( link ),
												null,
												() => write.run( pair )
											);
										return (
											<Judgment
												key={ link.entity_key }
												row
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
																confirmLink
															),
														onNo: () =>
															judge( rejectLink ),
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
				read={ judgedEntities.read }
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
					>
						{ answer.entities.map( ( entity ) => {
							const key = entity.entity_key;
							const judge = ( write, args ) =>
								judgedEntities.hold( key, null, () =>
									write.run( args )
								);
							return (
								<li
									key={ key }
									className="newspack-intelligence__item"
									tabIndex={ -1 }
									data-review-row=""
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
														judge( acceptEntity, {
															key,
														} ),
													onNo: () =>
														judge( rejectEntity, {
															key,
														} ),
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
													judge( mergeEntities, {
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
				read={ judgedThemes.read }
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
										judgedThemes.hold(
											pairKey( pair ),
											null,
											() =>
												mergeTheme.run( { from, into } )
										) )
								}
							/>
						) ) }
					</ul>
				) }
			</Answer>
		</div>
	);
}

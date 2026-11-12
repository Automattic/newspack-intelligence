import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useLayoutEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import { Icon, check, closeSmall } from '@wordpress/icons';
import DataViewsList from '@newspack-nodes/shared/components/DataViewsList';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import TabBar, {
	tabDomId,
	useTabPanelProps,
} from '@newspack-nodes/shared/tabs/TabBar';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import {
	ENTITY_READS,
	JUDGED_READS,
	THEME_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { REVIEW_PAGE, useQueuePage } from './useQueuePage';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { AnswerBody } from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import EntityPicker from '../components/EntityPicker';
import ListKeys, { LEAVES } from '../components/ListKeys';
import Refusal from '../components/Refusal';
import { Header } from '../components/Section';
import Skeleton from '../components/Skeleton';
import UndoSnackbar from '../components/UndoSnackbar';
import {
	argsOf,
	entityKey,
	entityName,
	evidenceText,
	itemKey,
	pageFormat,
} from '../components/format';
import { word } from '../components/words';

/** The queues' tab bar's scope on the page, apart from the page's own. */
const SCOPE = 'review';

/** How many placeholder rows stand in for a queue still owed. */
const SKELETON_ROWS = 5;

/** A row the review keys move between, by its attribute. */
const ROW = '[data-review-row]';

/**
 * The review keys' legend, in words.
 *
 * @return {string} The legend.
 */
const legendText = () =>
	__(
		'Keys: J and K to move, Y yes, N no, M same as…, Z undo.',
		'newspack-intelligence'
	);

/**
 * The empty queue's sentence, which every queue shares.
 *
 * @return {string} The sentence.
 */
const emptyText = () =>
	__(
		'Nothing to review. New suggestions arrive as items are read.',
		'newspack-intelligence'
	);

/**
 * Why a link was proposed, in the words the page localizes: what the entity
 * is to the item, how it was found and how sure the finding was, an exact
 * match at full confidence, else the percentage.
 *
 * @param {Object} link The proposed link.
 * @return {string} The sentence.
 */
function howFound( link ) {
	const percent = Math.round( Number( link.confidence ) * 100 );
	return sprintf(
		/* translators: 1: what the entity is to the item, 2: how it was found, 3: how sure, as "exact match" or "61% match". */
		__( '%1$s by %2$s, %3$s.', 'newspack-intelligence' ),
		word( 'linkRoleWords', link.role ),
		word( 'methodWords', link.method ),
		100 === percent
			? __( 'exact match', 'newspack-intelligence' )
			: sprintf(
					/* translators: %d: confidence, 0 to 99. */
					__( '%d%% match', 'newspack-intelligence' ),
					percent
			  )
	);
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
 * Two themes' key, the same whichever is named first.
 *
 * @param {string[]} themes The two themes.
 * @return {string} The key.
 */
const themesKey = ( themes ) => JSON.stringify( [ ...themes ].sort() );

/**
 * A suggested pair of themes' key.
 *
 * @param {Object} pair The pair.
 * @return {string} The key.
 */
const pairKey = ( pair ) =>
	themesKey( pair.themes.map( ( side ) => side.theme ) );

/**
 * The key of the row each queue's write judged, read from the tokens its
 * refusal echoes; null for tokens naming none, as a Router's NOT_AVAILABLE.
 */
const JUDGED_BY = {
	links: ( { item, entity } ) =>
		item ? linkKey( { item_key: item, entity_key: entity } ) : null,
	entities: ( { key } ) => key ?? null,
	themes: ( { from, into, theme, other } ) => {
		if ( from ) {
			return themesKey( [ from, into ] );
		}
		return theme ? themesKey( [ theme, other ] ) : null;
	},
};

/**
 * Every item the match queue lists, in the order it answers them, counted
 * in one pass: each row the item's key, URL and title, its links, and
 * `more`, how many its `item_links` holds beyond those listed.
 *
 * @param {Array<Object>} listed The links the queue lists.
 * @return {Array<{item_key: string, url: string, title: string, links: Array<Object>, more: number}>} The rows.
 */
function byItem( listed ) {
	const rows = new Map();
	for ( const link of listed ) {
		const row = rows.get( link.item_key ) ?? {
			item_key: link.item_key,
			url: link.url,
			title: link.title,
			links: [],
			more: link.item_links,
		};
		row.more -= 1;
		row.links.push( link );
		rows.set( link.item_key, row );
	}
	return [ ...rows.values() ];
}

/**
 * The key of the row that takes the place of those leaving: the first after
 * the last of them that stays, else the last that stays, else null.
 *
 * @param {string[]}    keys    The rows shown, by key, in order.
 * @param {Set<string>} leaving The keys leaving.
 * @return {?string} The key in their place.
 */
function beside( keys, leaving ) {
	const last = keys.map( ( key ) => leaving.has( key ) ).lastIndexOf( true );
	const staying = ( key ) => ! leaving.has( key );
	return (
		keys.slice( last + 1 ).find( staying ) ??
		keys.filter( staying ).at( -1 ) ??
		null
	);
}

/** The keys each queue's answer lists, as its rows are keyed. */
const LISTED = {
	links: ( answer ) => answer.links.map( linkKey ),
	entities: ( answer ) => answer.entities.map( entityKey ),
	themes: ( answer ) => answer.suggestions.map( pairKey ),
};

/**
 * Forgets each judged row of a queue whose write went once the keys its
 * queue still lists leave it out, so a row listed again later reads as owed
 * a judgment.
 *
 * @param {string}                                                   queue  The queue, a key of `LISTED`.
 * @param {?Set<string>}                                             listed The keys it lists, null before it answers.
 * @param {(drops: (key: string, entry: Object) => boolean) => void} forget Forgets the judgments `drops` names.
 */
function useForgotten( queue, listed, forget ) {
	useEffect( () => {
		if ( listed ) {
			forget(
				( key, entry ) =>
					queue === entry.queue &&
					[ 'sent', 'landed' ].includes( entry.state ) &&
					! listed.has( key )
			);
		}
	}, [ queue, listed, forget ] );
}

/**
 * A judgment a row shows: its verdict in words and, while the undo window
 * holds its write as the latest, the Undo that takes it back.
 *
 * @typedef {{words: string, undo: ?{message: string, onUndo: () => void}}} Verdict
 */

/**
 * A review row's frame, keyed for focus: while its judgment is owed, a row
 * the review keys move to; once judged, greyed, out of the keys' reach and
 * showing its verdict and, while it can still be undone, Undo, until a read
 * no longer lists it.
 *
 * @param {Object}                    props
 * @param {string}                    props.className The row's class.
 * @param {string}                    props.rowKey    The row's key.
 * @param {?Verdict}                  props.verdict   Its judgment, or null.
 * @param {import('react').ReactNode} props.children  Its content.
 * @return {import('react').ReactElement} The row.
 */
function ReviewRow( { className, rowKey, verdict, children } ) {
	return (
		<div
			className={
				verdict
					? `${ className } newspack-nodes-status is-muted`
					: className
			}
			{ ...( verdict ? {} : { tabIndex: -1, 'data-review-row': '' } ) }
			data-review-key={ rowKey }
		>
			{ children }
			{ verdict && (
				<p>
					{ verdict.words }{ ' ' }
					{ verdict.undo && (
						<button
							type="button"
							className="button button-small"
							aria-label={ sprintf(
								/* translators: %s: the judgment, as its snackbar says it. */
								__( 'Undo: %s', 'newspack-intelligence' ),
								verdict.undo.message
							) }
							onClick={ verdict.undo.onUndo }
						>
							{ __( 'Undo', 'newspack-intelligence' ) }
						</button>
					) }
				</p>
			) }
		</div>
	);
}

/**
 * One queue's list as a group named for its queue and, while the keys are
 * on, described by the legend naming them.
 *
 * @param {Object}                    props
 * @param {string}                    props.label         The queue's name.
 * @param {string}                    [props.describedBy] The legend's id, while the keys are on.
 * @param {import('react').ReactNode} props.children      Its list.
 * @return {import('react').ReactElement} The group.
 */
function ReviewList( { label, describedBy, children } ) {
	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-tabindex -- the review keys move between its rows.
		<div
			role="group"
			className="newspack-intelligence__review-list"
			tabIndex={ 0 }
			aria-label={ label }
			aria-describedby={ describedBy }
		>
			{ children }
		</div>
	);
}

/**
 * The sentence a proposal was found in, as plain text quoted, the entity's
 * name marked; nothing for evidence `evidenceText()` answers null for.
 *
 * @param {Object} props
 * @param {string} props.text  The evidence.
 * @param {string} props.name  The entity's name.
 * @param {string} props.title The item's title, shown above it.
 * @return {?import('react').ReactElement} The quote.
 */
function Evidence( { text, name, title } ) {
	const quoted = evidenceText( text, name, title );
	return quoted ? (
		<p className="newspack-intelligence__evidence">
			<q>
				{ quoted.before }
				{ quoted.name && <mark>{ quoted.name }</mark> }
				{ quoted.after }
			</q>
		</p>
	) : null;
}

/**
 * A curator's answer to one row: a check that affirms and a cross that
 * rejects, joined in one pill, each named for a screen reader by what it
 * does to the entity and pressed by its key, after any control `children`
 * add.
 *
 * @param {Object}                    props
 * @param {string}                    props.label      The entity's name.
 * @param {string}                    props.yesLabel   What the check does, for a screen reader.
 * @param {() => void}                props.onYes      Affirms.
 * @param {() => void}                props.onNo       Rejects.
 * @param {import('react').ReactNode} [props.children] Controls ahead of the two.
 * @return {import('react').ReactElement} The answers.
 */
function Answers( { label, yesLabel, onYes, onNo, children } ) {
	return (
		<div className="newspack-intelligence__judgments">
			{ children }
			<span className="newspack-intelligence__judgment">
				<button
					type="button"
					className="button button-small"
					aria-keyshortcuts="y"
					aria-label={ yesLabel }
					{ ...LEAVES }
					onClick={ onYes }
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
					{ ...LEAVES }
					onClick={ onNo }
				>
					<Icon icon={ closeSmall } size={ 18 } />
				</button>
			</span>
		</div>
	);
}

/**
 * One proposed link as a review row: whether the item is about the entity,
 * why it was proposed in muted text, the sentence it was found in, a
 * curator's answers until it is judged, then its verdict, and a refusal of
 * the last answer under them.
 *
 * @param {Object}                                       props
 * @param {Object}                                       props.link    The link.
 * @param {(key: string) => void}                        props.onOpen  Opens the entity.
 * @param {?{onYes: () => void, onNo: () => void}|false} props.judge   A curator's answers, or none.
 * @param {?Verdict}                                     props.verdict Its judgment, or null.
 * @param {?string}                                      props.refusal The refusal of its last answer.
 * @return {import('react').ReactElement} The row.
 */
function LinkRow( { link, onOpen, judge, verdict, refusal } ) {
	const label = entityName( link );
	return (
		<ReviewRow
			className="newspack-intelligence__proposal"
			rowKey={ linkKey( link ) }
			verdict={ verdict }
		>
			<p>
				{ createInterpolateElement(
					/* translators: <entity /> is the entity's name. */
					__( 'Is this about <entity />?', 'newspack-intelligence' ),
					{
						entity: (
							<EntityChip
								entityKey={ link.entity_key }
								name={ link.name }
								onOpen={ onOpen }
							/>
						),
					}
				) }
			</p>
			<p className="newspack-nodes-status is-muted">
				{ howFound( link ) }
			</p>
			<Evidence
				text={ link.evidence }
				name={ label }
				title={ link.title }
			/>
			{ judge && ! verdict && (
				<Answers
					label={ label }
					yesLabel={ sprintf(
						/* translators: %s: the entity's name. */
						__( 'Confirm %s', 'newspack-intelligence' ),
						label
					) }
					onYes={ judge.onYes }
					onNo={ judge.onNo }
				/>
			) }
			<Refusal error={ refusal } />
		</ReviewRow>
	);
}

/**
 * One item of the match queue: its title, citing it, then a review row for
 * each entity proposed for it, and in muted text how many more wait beyond
 * them.
 *
 * @param {Object}                                                          props
 * @param {Object}                                                          props.item      The item, holding its `links`.
 * @param {(key: string) => void}                                           props.onOpen    Opens an entity.
 * @param {((link: Object) => {onYes: () => void, onNo: () => void})|false} props.judgeOf   A link's answers, or none.
 * @param {(key: string) => ?Verdict}                                       props.verdictOf A row's judgment.
 * @param {(key: string) => ?string}                                        props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The item.
 */
function LinkItem( { item, onOpen, judgeOf, verdictOf, refusalOf } ) {
	return (
		<div className="newspack-intelligence__item">
			<div className="newspack-intelligence__item-head">
				<strong>
					<Cite url={ item.url } title={ item.title } />
				</strong>
			</div>
			{ item.links.map( ( link ) => (
				<LinkRow
					key={ link.entity_key }
					link={ link }
					onOpen={ onOpen }
					judge={ judgeOf && judgeOf( link ) }
					verdict={ verdictOf( linkKey( link ) ) }
					refusal={ refusalOf( linkKey( link ) ) }
				/>
			) ) }
			{ 0 !== item.more && (
				<p className="newspack-nodes-status is-muted">
					{ sprintf(
						/* translators: %d: how many more matches the item holds than the queue shows. */
						_n(
							'%d more match waits beyond these.',
							'%d more matches wait beyond these.',
							item.more,
							'newspack-intelligence'
						),
						item.more
					) }
				</p>
			) }
		</div>
	);
}

/**
 * One proposed entity as a review row: its name and type, the items citing
 * it with the sentence each was found in, and a curator's answers, Same
 * as… among them, until it is judged, then its verdict.
 *
 * @param {Object}                                                                                props
 * @param {Object}                                                                                props.item      The entity.
 * @param {(key: string) => void}                                                                 props.onOpen    Opens it.
 * @param {((entity: Object) => {onYes: () => void, onNo: () => void, onSame: () => void})|false} props.judgeOf   Its answers, or none.
 * @param {(key: string) => ?Verdict}                                                             props.verdictOf A row's judgment.
 * @param {(key: string) => ?string}                                                              props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The row.
 */
function EntityRow( { item: entity, onOpen, judgeOf, verdictOf, refusalOf } ) {
	const key = entity.entity_key;
	const label = entity.name || key;
	const verdict = verdictOf( key );
	const judge = ! verdict && judgeOf && judgeOf( entity );
	return (
		<ReviewRow
			className="newspack-intelligence__item"
			rowKey={ key }
			verdict={ verdict }
		>
			<p>
				{ createInterpolateElement(
					sprintf(
						/* translators: <entity /> is the entity's name; %s: its type. */
						__(
							'New name: <entity />, a %s',
							'newspack-intelligence'
						),
						word( 'typeWords', entity.type )
					),
					{
						entity: (
							<EntityChip
								entityKey={ key }
								name={ entity.name }
								onOpen={ onOpen }
							/>
						),
					}
				) }
			</p>
			<ul className="newspack-intelligence__facts">
				{ entity.items.map( ( item ) => (
					<li key={ item.item_key }>
						<Cite url={ item.url } title={ item.title } />
						<Evidence
							text={ item.evidence }
							name={ label }
							title={ item.title }
						/>
					</li>
				) ) }
			</ul>
			{ judge && (
				<Answers
					label={ label }
					yesLabel={ sprintf(
						/* translators: %s: the entity's name. */
						__( 'Accept %s', 'newspack-intelligence' ),
						label
					) }
					onYes={ judge.onYes }
					onNo={ judge.onNo }
				>
					<button
						type="button"
						className="button"
						aria-keyshortcuts="m"
						aria-label={ sprintf(
							/* translators: %s: the entity's name. */
							__( '%s is the same as…', 'newspack-intelligence' ),
							label
						) }
						onClick={ judge.onSame }
					>
						{ __( 'Same as…', 'newspack-intelligence' ) }
					</button>
				</Answers>
			) }
			<Refusal error={ refusalOf( key ) } />
		</ReviewRow>
	);
}

/**
 * A theme's story count in words.
 *
 * @param {{stories: number}} side One theme of a pair.
 * @return {string} The count.
 */
const storiesOf = ( side ) =>
	sprintf(
		/* translators: %d: how many stories carry the theme. */
		_n( '%d story', '%d stories', side.stories, 'newspack-intelligence' ),
		side.stories
	);

/**
 * One suggested pair of themes as a review row: both themes on one line,
 * then each one's stories, how many entities their stories share and the
 * words both names hold, and for a curator Keep for either theme, which
 * merges the other into it, and Not the same, until it is judged, then its
 * verdict.
 *
 * @param {Object}                                                                                        props
 * @param {Object}                                                                                        props.item      The pair, as `theme_suggestions` answers it.
 * @param {((pair: Object) => {onKeep: (from: string, into: string) => void, onApart: () => void})|false} props.judgeOf   Its answers, or none.
 * @param {(key: string) => ?Verdict}                                                                     props.verdictOf A row's judgment.
 * @param {(key: string) => ?string}                                                                      props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The row.
 */
function ThemePair( { item: pair, judgeOf, verdictOf, refusalOf } ) {
	const [ first, second ] = pair.themes;
	const verdict = verdictOf( pairKey( pair ) );
	const judge = ! verdict && judgeOf && judgeOf( pair );
	const words = pageFormat( Intl.ListFormat, {
		type: 'conjunction',
	} ).format(
		pair.words.map( ( one ) =>
			/* translators: %s: a word both themes' names hold, quoted. */
			sprintf( __( '"%s"', 'newspack-intelligence' ), one )
		)
	);
	return (
		<ReviewRow
			className="newspack-intelligence__item"
			rowKey={ pairKey( pair ) }
			verdict={ verdict }
		>
			<p className="newspack-intelligence__pair">
				<strong>{ first.theme }</strong> ↔{ ' ' }
				<strong>{ second.theme }</strong>
			</p>
			<p className="newspack-nodes-status is-muted">
				{ sprintf(
					/* translators: 1: the first theme's stories, 2: the second theme's stories, 3: the entities their stories share, 4: the words their names share, quoted and listed. */
					__(
						'%1$s and %2$s; %3$s in common; both say %4$s',
						'newspack-intelligence'
					),
					storiesOf( first ),
					storiesOf( second ),
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
					words
				) }
			</p>
			{ judge && (
				<div className="newspack-intelligence__judgments">
					{ [
						[ second, first ],
						[ first, second ],
					].map( ( [ from, into ] ) => (
						<button
							key={ into.theme }
							type="button"
							className="button"
							{ ...LEAVES }
							onClick={ () =>
								judge.onKeep( from.theme, into.theme )
							}
						>
							{ sprintf(
								/* translators: %s: the theme kept, the other merging into it. */
								__( 'Keep "%s"', 'newspack-intelligence' ),
								into.theme
							) }
						</button>
					) ) }
					<button
						type="button"
						className="button"
						{ ...LEAVES }
						onClick={ judge.onApart }
					>
						{ __( 'Not the same', 'newspack-intelligence' ) }
					</button>
				</div>
			) }
			<Refusal error={ refusalOf( pairKey( pair ) ) } />
		</ReviewRow>
	);
}

/**
 * The merge "Same as…" confirms: an `EntityPicker` offering every entity
 * but the one merging, the consequence, and Merge and Cancel. Merge waits
 * for an offered entity picked and for the last send's answer; a refusal
 * shows here. The field takes focus as the dialog opens.
 *
 * @param {Object}                                      props
 * @param {string}                                      props.name    The merging entity's name.
 * @param {string}                                      props.from    Its key.
 * @param {boolean}                                     props.pending Whether a merge is owed its answer.
 * @param {?string}                                     props.refusal The refusal of the merge sent.
 * @param {(into: {key: string, name: string}) => void} props.onMerge Sends the merge.
 * @param {() => void}                                  props.onClose Closes the dialog.
 * @return {import('react').ReactElement} The dialog.
 */
function MergeDialog( { name, from, pending, refusal, onMerge, onClose } ) {
	const [ into, setInto ] = useState( { key: '', name: null } );
	const field = useRef( null );
	useEffect( () => field.current?.querySelector( 'input' )?.focus(), [] );
	const ready = Boolean( into.name ) && ! pending;

	return (
		<Modal onClose={ onClose }>
			<ModalHeader
				title={ sprintf(
					/* translators: %s: the entity's name. */
					__( 'Merge %s into…', 'newspack-intelligence' ),
					name
				) }
				onClose={ onClose }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<div ref={ field }>
				<EntityPicker
					label={ __( 'Merge into', 'newspack-intelligence' ) }
					value={ into.key }
					exclude={ from }
					onChange={ ( key, picked ) =>
						setInto( { key, name: picked } )
					}
				/>
			</div>
			<p>
				{ __(
					'Its names and items move to the record you pick. This cannot be undone.',
					'newspack-intelligence'
				) }
			</p>
			<Refusal error={ refusal } />
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className={ primaryButtonClass( ! ready ) }
					disabled={ ! ready }
					onClick={ () => onMerge( into ) }
				>
					{ __( 'Merge', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

/**
 * Review: under the shared header, which says what the queues are for,
 * three queues behind tabs, each counted by the `total` its read
 * answers — the proposed links, one row per item asking of each entity
 * proposed for it whether the item is about it; the proposed entities,
 * each with the items citing it; and the pairs of themes
 * `theme_suggestions` reads. The match and name queues page by cursor
 * (`useQueuePage()`). Each queue is a `DataViewsList` in the list layout.
 * Each proposal says why it was proposed and quotes the sentence it was
 * found in. A curator's judgment leaves its row in place, greyed and out
 * of the review keys' reach, showing its verdict, with Undo on the row
 * while the judgment waits `UNDO_MS` to be sent, as the snackbar offers
 * it; the row stays until its write has gone and no page its queue still
 * reaches lists it, and a refused one returns to its answers with the
 * refusal under it, focused, every refusal off the page shown listed above
 * the queues. A page or a queue shown in another's place sends every
 * judgment still waiting at once, so an undo only ever takes back rows on
 * the page shown. On the match and name queues a curator also
 * checks rows into a run, which Yes or No in the bulk toolbar judges
 * whole, every row's write deferred under one Undo; a match row's run
 * judges every entity it asks about that is not yet judged. Same as…
 * merges an entity into one the curator picks, confirmed in a dialog
 * because a merge cannot be taken back, and sent on Merge. Not the same
 * keeps a pair of themes apart for every reader after the undo window, so
 * the suggestions never pair them again. A later
 * page whose read answers no rows and names no next page gives way to the
 * one before it, and one that could not be read still offers Previous. On
 * the match and name queues the review keys work from anywhere on the
 * page, unless the reader turned them off or the screen is narrow.
 *
 * @param {Object}                props
 * @param {boolean}               props.canTune      Whether to offer judgments.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {import('react').ReactElement} The view.
 */
export default function Review( { canTune, onOpenEntity } ) {
	const deferred = useDeferredWrite();
	const [ open, setOpen ] = useState( 'links' );
	const panel = useTabPanelProps( SCOPE, open, true );
	const [ merging, setMerging ] = useState( null );
	// The merge owed its answer; Merge waits for it, so there is one.
	const sentMerge = useRef( null );
	const [ notice, setNotice ] = useState( null );
	const [ checked, setChecked ] = useState( [] );
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
	const links = useQueuePage( 'review:links', 'links', LISTED.links );
	const entities = useQueuePage(
		'review:entities',
		'entities',
		LISTED.entities
	);
	const themes = useIntelRead( 'review:themes', 'theme_suggestions', [] );
	const themesListed = useMemo(
		() => themes.answer && new Set( LISTED.themes( themes.answer ) ),
		[ themes.answer ]
	);
	const paged = { links, entities }[ open ];
	const page = paged?.page ?? 1;
	const turn = paged?.turn;
	// A page or a queue shown in another's place closes the undo window.
	const { flush } = deferred;
	useEffect( flush, [ flush, open, links.page, entities.page ] );
	const land = useCallback(
		( to ) => {
			if ( turn( to ) ) {
				setChecked( [] );
			}
		},
		[ turn ]
	);

	// Each judged row by key: its queue, verdict, label, state and refusal.
	const [ judged, setJudged ] = useState( () => new Map() );
	const mark = ( queue, key, words, label ) =>
		setJudged( ( was ) =>
			new Map( was ).set( key, { queue, words, label, state: 'waiting' } )
		);
	const settle = ( key, state ) =>
		setJudged( ( was ) =>
			was.has( key )
				? new Map( was ).set( key, { ...was.get( key ), state } )
				: was
		);
	const went = ( key ) => settle( key, 'sent' );
	const landedIn = ( queue ) => ( answer, tokens ) =>
		settle( JUDGED_BY[ queue ]( argsOf( tokens ) ), 'landed' );
	const forget = useCallback(
		( drops ) =>
			setJudged( ( was ) => {
				const kept = [ ...was ].filter(
					( [ key, entry ] ) => ! drops( key, entry )
				);
				return kept.length === was.size ? was : new Map( kept );
			} ),
		[]
	);
	/**
	 * Answers a refusal: its row, or with no row named, every row of the
	 * queue sent and not yet landed, takes it, or is forgotten when it
	 * carries no words to show.
	 *
	 * @param {string}  queue The queue.
	 * @param {?string} key   The row refused, null when the reply names none.
	 * @param {?string} error The refusal.
	 */
	const refuse = ( queue, key, error ) => {
		setJudged( ( was ) => {
			const owed = ( one ) =>
				queue === was.get( one ).queue &&
				'sent' === was.get( one ).state;
			const named =
				null === key ? [ ...was.keys() ].filter( owed ) : [ key ];
			const next = new Map( was );
			for ( const one of named ) {
				if ( error ) {
					next.set( one, {
						queue,
						...was.get( one ),
						state: 'refused',
						error,
					} );
				} else {
					next.delete( one );
				}
			}
			return next;
		} );
		restored.current = key;
	};
	const undoWrite = deferred.undo;
	const undo = useCallback( () => {
		const undone = undoWrite();
		if ( undone ) {
			restored.current = undone[ 0 ];
			forget( ( key ) => undone.includes( key ) );
			speak( __( 'Undone.', 'newspack-intelligence' ), 'polite' );
		}
	}, [ undoWrite, forget ] );
	const verdictOf = ( key ) => {
		const entry = judged.get( key );
		if ( ! entry || 'refused' === entry.state ) {
			return null;
		}
		const latest = deferred.latest;
		return {
			words: entry.words,
			undo:
				deferred.hidden.has( key ) && latest?.keys.includes( key )
					? { message: latest.message, onUndo: undo }
					: null,
		};
	};
	useForgotten( 'links', links.reached, forget );
	useForgotten( 'entities', entities.reached, forget );
	useForgotten( 'themes', themesListed, forget );

	const refusedIn = ( queue ) => ( tokens, error ) =>
		refuse( queue, JUDGED_BY[ queue ]( argsOf( tokens ) ), error );
	const relinked = {
		dirties: ENTITY_READS,
		refresh: landedIn( 'links' ),
		onRefused: refusedIn( 'links' ),
	};
	const rejudged = {
		dirties: JUDGED_READS,
		refresh: landedIn( 'entities' ),
		onRefused: refusedIn( 'entities' ),
	};
	const writes = {
		confirm_link: useIntelWrite( 'confirm_link', relinked ),
		reject_link: useIntelWrite( 'reject_link', relinked ),
		accept_entity: useIntelWrite( 'accept_entity', rejudged ),
		reject_entity: useIntelWrite( 'reject_entity', rejudged ),
		merge_theme: useIntelWrite( 'merge_theme', {
			dirties: THEME_READS,
			refresh: landedIn( 'themes' ),
			onRefused: refusedIn( 'themes' ),
		} ),
		keep_themes_apart: useIntelWrite( 'keep_themes_apart', {
			dirties: [ 'theme_suggestions:' ],
			refresh: landedIn( 'themes' ),
			onRefused: refusedIn( 'themes' ),
		} ),
	};

	const entityRows = entities.answer?.entities ?? [];
	const entityKeys = entityRows
		.map( entityKey )
		.filter( ( key ) => ! verdictOf( key ) );
	const mergeEntities = useIntelWrite( 'merge_entities', {
		dirties: JUDGED_READS,
		onRefused: ( tokens ) =>
			refuse( 'entities', argsOf( tokens ).from ?? null, null ),
		refresh: ( answer, tokens ) => {
			settle( argsOf( tokens ).from, 'landed' );
			const merged = sentMerge.current;
			restored.current = merged.after;
			setNotice( {
				id: `merged:${ merged.key }`,
				message: sprintf(
					/* translators: 1: the entity merged away, 2: the entity it merges into. */
					__( 'Merged %1$s into %2$s.', 'newspack-intelligence' ),
					merged.name,
					merged.into.name
				),
			} );
			setMerging( ( was ) => ( was?.sent ? null : was ) );
		},
	} );

	/** The words naming a queue's row, as the answer shown lists it. */
	const labelOf = {
		links: ( key ) => {
			const link = ( links.answer?.links ?? [] ).find(
				( one ) => key === linkKey( one )
			);
			return link
				? sprintf(
						/* translators: 1: the entity's name, 2: the item's title. */
						__( '%1$s in %2$s', 'newspack-intelligence' ),
						entityName( link ),
						link.title
				  )
				: key;
		},
		entities: ( key ) => {
			const entity = entityRows.find(
				( one ) => key === entityKey( one )
			);
			return entity ? entityName( entity ) : key;
		},
		themes: ( key ) => {
			const pair = themes.answer?.suggestions.find(
				( one ) => key === pairKey( one )
			);
			return pair
				? sprintf(
						/* translators: 1, 2: the two themes. */
						__( '%1$s and %2$s', 'newspack-intelligence' ),
						pair.themes[ 0 ].theme,
						pair.themes[ 1 ].theme
				  )
				: key;
		},
	};

	/**
	 * Records each row's verdict and defers their writes under one message
	 * and one Undo; a row's own refusal goes once it is judged again, a send
	 * that throws is its row's refusal, and a write, once sent, keeps its
	 * verdict showing until a read no longer lists the row.
	 *
	 * @param {string}                                                     queue   The rows' queue.
	 * @param {Array<{key: string, run: () => string[], verdict: string}>} run     The rows' writes.
	 * @param {string}                                                     message What the snackbar says.
	 */
	const judgeAll = ( queue, run, message ) => {
		run.forEach( ( one ) =>
			mark( queue, one.key, one.verdict, labelOf[ queue ]( one.key ) )
		);
		deferred.deferGroup(
			run.map( ( one ) => ( {
				key: one.key,
				send: () => {
					try {
						one.run();
						went( one.key );
					} catch ( error ) {
						refuse( queue, one.key, error.message );
					}
				},
			} ) ),
			message
		);
	};
	const judge = ( queue ) => ( key, run, message, verdict ) =>
		judgeAll( queue, [ { key, run, verdict } ], message );
	const refusalOf = ( key ) => {
		const entry = judged.get( key );
		return 'refused' === entry?.state ? entry.error : null;
	};

	/**
	 * A bulk action judging a checked run: every row's write deferred under
	 * one message and one Undo, the run let go, any refusal showing let go,
	 * and focus moved to the row taking the run's place; a row it holds no
	 * write for, every match of it judged, is not eligible.
	 *
	 * @param {string}                                                                      queue    The rows' queue.
	 * @param {string}                                                                      id       The action's id.
	 * @param {string}                                                                      label    Its words.
	 * @param {import('react').ReactElement}                                                icon     Its glyph, which a narrow toolbar shows.
	 * @param {string[]}                                                                    keys     The rows still owed a judgment, by key, in order.
	 * @param {(row: Object) => Array<{key: string, run: () => string[], verdict: string}>} writesOf A checked row's writes, its judged rows left out.
	 * @param {(count: number) => string}                                                   said     What the snackbar says of so many writes.
	 * @return {Object} The DataViews action.
	 */
	const runAction = ( queue, id, label, icon, keys, writesOf, said ) => ( {
		id,
		label,
		icon,
		supportsBulk: true,
		isEligible: ( row ) => 0 < writesOf( row ).length,
		callback: ( rows ) => {
			const run = rows.flatMap( writesOf );
			if ( 0 === run.length ) {
				return;
			}
			setChecked( [] );
			restored.current = beside(
				keys,
				new Set( run.map( ( one ) => one.key ) )
			);
			judgeAll( queue, run, said( run.length ) );
		},
	} );

	const sendLink = ( verb, link ) => () =>
		writes[ verb ].run( {
			item: link.item_key,
			entity: link.entity_key,
		} );
	const VERDICTS = {
		confirm_link: () => __( 'Confirmed.', 'newspack-intelligence' ),
		reject_link: () => __( 'Rejected.', 'newspack-intelligence' ),
		accept_entity: () => __( 'Accepted.', 'newspack-intelligence' ),
		reject_entity: () => __( 'Rejected.', 'newspack-intelligence' ),
	};
	const linkWrites = ( verb ) => ( row ) =>
		row.links
			.filter( ( link ) => ! verdictOf( linkKey( link ) ) )
			.map( ( link ) => ( {
				key: linkKey( link ),
				run: sendLink( verb, link ),
				verdict: VERDICTS[ verb ](),
			} ) );
	const sendEntity = ( verb, entity ) => () =>
		writes[ verb ].run( { key: entity.entity_key } );
	const entityWrites = ( verb ) => ( entity ) =>
		verdictOf( entity.entity_key )
			? []
			: [
					{
						key: entity.entity_key,
						run: sendEntity( verb, entity ),
						verdict: VERDICTS[ verb ](),
					},
			  ];
	const linkRows = byItem( links.answer?.links ?? [] );
	const linkKeys = linkRows
		.flatMap( ( row ) => row.links.map( linkKey ) )
		.filter( ( key ) => ! verdictOf( key ) );
	const selectLabel = ( name ) =>
		sprintf(
			/* translators: %s: the item's title, or the entity's name. */
			__( 'Select %s', 'newspack-intelligence' ),
			name
		);

	const queues = [
		{
			id: 'links',
			name: __( 'Suggested matches', 'newspack-intelligence' ),
			count: links.answer ? links.answer.total : null,
			read: links.answer
				? { ...links, answer: { ...links.answer, items: linkRows } }
				: links,
			paged: true,
			rows: 'items',
			items: linkRows,
			getItemId: itemKey,
			Row: LinkItem,
			selectLabel: ( row ) => selectLabel( row.title ),
			judgeOf:
				canTune &&
				( ( link ) => {
					const key = linkKey( link );
					const name = entityName( link );
					return {
						onYes: () =>
							judge( 'links' )(
								key,
								sendLink( 'confirm_link', link ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Confirmed %s.',
										'newspack-intelligence'
									),
									name
								),
								VERDICTS.confirm_link()
							),
						onNo: () =>
							judge( 'links' )(
								key,
								sendLink( 'reject_link', link ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Rejected %s.',
										'newspack-intelligence'
									),
									name
								),
								VERDICTS.reject_link()
							),
					};
				} ),
			actions: canTune
				? [
						runAction(
							'links',
							'confirm',
							__( 'Yes, it is', 'newspack-intelligence' ),
							check,
							linkKeys,
							linkWrites( 'confirm_link' ),
							( count ) =>
								sprintf(
									/* translators: %d: how many matches. */
									_n(
										'Confirmed %d match.',
										'Confirmed %d matches.',
										count,
										'newspack-intelligence'
									),
									count
								)
						),
						runAction(
							'links',
							'reject',
							__( 'No', 'newspack-intelligence' ),
							closeSmall,
							linkKeys,
							linkWrites( 'reject_link' ),
							( count ) =>
								sprintf(
									/* translators: %d: how many matches. */
									_n(
										'Rejected %d match.',
										'Rejected %d matches.',
										count,
										'newspack-intelligence'
									),
									count
								)
						),
				  ]
				: [],
		},
		{
			id: 'entities',
			name: __( 'Suggested names', 'newspack-intelligence' ),
			count: entities.answer ? entities.answer.total : null,
			read: entities,
			paged: true,
			rows: 'entities',
			items: entityRows,
			getItemId: entityKey,
			Row: EntityRow,
			selectLabel: ( entity ) => selectLabel( entityName( entity ) ),
			judgeOf:
				canTune &&
				( ( entity ) => {
					const key = entity.entity_key;
					const name = entityName( entity );
					return {
						onYes: () =>
							judge( 'entities' )(
								key,
								sendEntity( 'accept_entity', entity ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Accepted %s.',
										'newspack-intelligence'
									),
									name
								),
								VERDICTS.accept_entity()
							),
						onNo: () =>
							judge( 'entities' )(
								key,
								sendEntity( 'reject_entity', entity ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Rejected %s.',
										'newspack-intelligence'
									),
									name
								),
								VERDICTS.reject_entity()
							),
						onSame: () =>
							setMerging( {
								key,
								name,
								into: null,
								sent: false,
							} ),
					};
				} ),
			actions: canTune
				? [
						runAction(
							'entities',
							'confirm',
							__( 'Add it', 'newspack-intelligence' ),
							check,
							entityKeys,
							entityWrites( 'accept_entity' ),
							( count ) =>
								sprintf(
									/* translators: %d: how many names. */
									_n(
										'Accepted %d name.',
										'Accepted %d names.',
										count,
										'newspack-intelligence'
									),
									count
								)
						),
						runAction(
							'entities',
							'reject',
							__( 'No', 'newspack-intelligence' ),
							closeSmall,
							entityKeys,
							entityWrites( 'reject_entity' ),
							( count ) =>
								sprintf(
									/* translators: %d: how many names. */
									_n(
										'Rejected %d name.',
										'Rejected %d names.',
										count,
										'newspack-intelligence'
									),
									count
								)
						),
				  ]
				: [],
		},
		{
			id: 'themes',
			name: __( 'Similar themes', 'newspack-intelligence' ),
			count: themes.answer ? themes.answer.total : null,
			read: themes,
			paged: false,
			rows: 'suggestions',
			items: themes.answer?.suggestions ?? [],
			getItemId: pairKey,
			Row: ThemePair,
			judgeOf:
				canTune &&
				( ( pair ) => {
					const key = pairKey( pair );
					return {
						onKeep: ( from, into ) =>
							judge( 'themes' )(
								key,
								() => writes.merge_theme.run( { from, into } ),
								sprintf(
									/* translators: 1: the theme merged away, 2: the theme it merges into. */
									__(
										'Merged %1$s into %2$s.',
										'newspack-intelligence'
									),
									from,
									into
								),
								sprintf(
									/* translators: %s: the theme kept. */
									__( 'Kept "%s".', 'newspack-intelligence' ),
									into
								)
							),
						onApart: () =>
							judge( 'themes' )(
								key,
								() =>
									writes.keep_themes_apart.run( {
										theme: pair.themes[ 0 ].theme,
										other: pair.themes[ 1 ].theme,
									} ),
								sprintf(
									/* translators: 1: one theme, 2: the theme it is kept apart from. */
									__(
										'Kept %1$s and %2$s apart.',
										'newspack-intelligence'
									),
									pair.themes[ 0 ].theme,
									pair.themes[ 1 ].theme
								),
								__( 'Not the same.', 'newspack-intelligence' )
							),
					};
				} ),
			actions: [],
		},
	];
	const shown = queues.find( ( queue ) => open === queue.id );
	const onPage = new Set(
		'links' === open
			? linkRows.flatMap( ( row ) => row.links.map( linkKey ) )
			: shown.items.map( shown.getItemId )
	);
	const elsewhere = [ ...judged ].filter(
		( [ key, entry ] ) => 'refused' === entry.state && ! onPage.has( key )
	);
	const totalItems = shown.paged ? shown.count ?? 0 : shown.items.length;
	const pages = shown.read.answer?.next
		? Math.max( page + 1, Math.ceil( totalItems / REVIEW_PAGE ) )
		: page;
	const emptied =
		shown.paged &&
		1 < page &&
		! paged.paging &&
		Boolean( shown.read.answer ) &&
		0 === shown.items.length &&
		! shown.read.answer.next;
	useEffect( () => {
		if ( emptied ) {
			land( page - 1 );
		}
	}, [ emptied, page, land ] );

	return (
		<div ref={ view } className="newspack-intelligence__view">
			<Header
				title={ __( 'Review', 'newspack-intelligence' ) }
				description={ __(
					'The extractor suggests which client or company each item is about. Confirm the right ones and reject the rest.',
					'newspack-intelligence'
				) }
			/>
			<Refusal
				error={
					0 < elsewhere.length && (
						<ul>
							{ elsewhere.map( ( [ key, entry ] ) => (
								<li key={ key }>
									{ sprintf(
										/* translators: 1: the row refused, 2: the refusal. */
										__(
											'%1$s: %2$s',
											'newspack-intelligence'
										),
										entry.label ?? key,
										entry.error
									) }
								</li>
							) ) }
						</ul>
					)
				}
			/>
			<TabBar
				scope={ SCOPE }
				label={ __( 'Review queues', 'newspack-intelligence' ) }
				tabs={ queues.map( ( queue ) => ( {
					id: queue.id,
					label:
						null === queue.count
							? queue.name
							: sprintf(
									/* translators: 1: the queue's name, 2: how many rows it holds. */
									__( '%1$s %2$d', 'newspack-intelligence' ),
									queue.name,
									queue.count
							  ),
				} ) ) }
				activeId={ open }
				onPick={ ( id ) => {
					setOpen( id );
					setChecked( [] );
				} }
			/>
			<div { ...panel } className="newspack-intelligence__queue">
				<ListKeys
					rows={ ROW }
					onUndo={ undo }
					legend={ shown.paged ? legendText() : null }
					page
					heading={ () =>
						view.current.ownerDocument.getElementById(
							tabDomId( SCOPE, open )
						)
					}
				>
					{ ( describedBy ) => (
						<>
							<AnswerBody
								read={ shown.read }
								rows={ shown.rows }
								empty={ emptyText() }
								skeleton={
									<Skeleton count={ SKELETON_ROWS } />
								}
							>
								{ () => (
									<ReviewList
										label={ shown.name }
										describedBy={ describedBy }
									>
										<DataViewsList
											key={ shown.id }
											items={ shown.items }
											getItemId={ shown.getItemId }
											view={ {
												type: 'list',
												page,
												perPage: Math.max(
													shown.items.length,
													1
												),
											} }
											onChangeView={ ( moved ) => {
												if ( moved.page !== page ) {
													land( moved.page );
												}
											} }
											isLoading={ paged?.paging }
											empty={ emptyText() }
											paginationInfo={ {
												totalItems,
												totalPages: pages,
											} }
											render={ shown.Row }
											rowProps={ {
												onOpen: onOpenEntity,
												judgeOf: shown.judgeOf,
												verdictOf,
												refusalOf,
											} }
											actions={ shown.actions }
											selection={ checked }
											onChangeSelection={ setChecked }
											selectLabel={
												0 < shown.actions.length
													? shown.selectLabel
													: undefined
											}
										/>
									</ReviewList>
								) }
							</AnswerBody>
							{ shown.read.error && 1 < page && (
								<div className="newspack-nodes-dataviews__pager">
									<button
										type="button"
										className="button"
										onClick={ () => land( page - 1 ) }
									>
										{ __(
											'Previous',
											'newspack-intelligence'
										) }
									</button>
								</div>
							) }
						</>
					) }
				</ListKeys>
			</div>
			{ merging && (
				<MergeDialog
					name={ merging.name }
					from={ merging.key }
					pending={ mergeEntities.pending }
					refusal={
						merging.sent && ! mergeEntities.pending
							? mergeEntities.refusal
							: null
					}
					onMerge={ ( into ) => {
						sentMerge.current = {
							...merging,
							into,
							after: beside(
								entityKeys,
								new Set( [ merging.key ] )
							),
						};
						setMerging( { ...merging, into, sent: true } );
						mark(
							'entities',
							merging.key,
							sprintf(
								/* translators: %s: the entity it merges into. */
								__(
									'Merged into %s.',
									'newspack-intelligence'
								),
								into.name
							),
							merging.name
						);
						went( merging.key );
						mergeEntities.run( {
							from: merging.key,
							into: into.key,
						} );
					} }
					onClose={ () => {
						if ( merging.sent ) {
							restored.current = sentMerge.current.after;
						}
						setMerging( null );
					} }
				/>
			) }
			<UndoSnackbar
				latest={ deferred.latest ?? notice }
				onUndo={ deferred.latest ? undo : undefined }
			/>
		</div>
	);
}

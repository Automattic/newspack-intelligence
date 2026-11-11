import {
	createInterpolateElement,
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { ComboboxControl } from '@wordpress/components';
import { check, closeSmall } from '@wordpress/icons';
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
import { useHeld } from '../hooks/useHeld';
import { usePageRead } from '../hooks/usePageRead';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { AnswerBody, Unread } from '../components/Answer';
import Cite, { EntityChip } from '../components/Cite';
import { useEntityNames } from '../components/EntityPicker';
import ListKeys, { LEAVES } from '../components/ListKeys';
import Refusal from '../components/Refusal';
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
	themes: ( { from, into } ) => ( from ? themesKey( [ from, into ] ) : null ),
};

/**
 * A read with every row whose key `hidden` holds taken out.
 *
 * @param {{answer: ?Object}}       read   What `useHeld` returned as its read.
 * @param {string}                  rows   The answer's row list.
 * @param {(row: Object) => string} keyOf  A row's key.
 * @param {Set<string>}             hidden The keys to leave out.
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
 * How many rows a queue holds less those its reader has judged that the read
 * still lists, or null while the queue is owed.
 *
 * @param {{answer: ?Object}} read  The queue as read.
 * @param {{answer: ?Object}} shown The queue as shown.
 * @param {string}            rows  The answer's row list.
 * @return {?number} The count.
 */
function waiting( read, shown, rows ) {
	const listed = read.answer?.[ rows ];
	return listed
		? read.answer.total - ( listed.length - shown.answer[ rows ].length )
		: null;
}

/**
 * The proposed links as one row per item, in the order the queue answers
 * them, each row the item's key, URL and title and the links proposed for it.
 *
 * @param {Array<Object>} links The queue's links.
 * @return {Array<{item_key: string, url: string, title: string, links: Array<Object>}>} The rows.
 */
function byItem( links ) {
	const rows = new Map();
	for ( const link of links ) {
		const row = rows.get( link.item_key ) ?? {
			item_key: link.item_key,
			url: link.url,
			title: link.title,
			links: [],
		};
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
 * A curator's answer to one row: No, then the affirming answer as the
 * primary button, each named for a screen reader by what it does to the
 * entity and pressed by its key, after any control `children` add.
 *
 * @param {Object}                    props
 * @param {string}                    props.label      The entity's name.
 * @param {string}                    props.yes        The affirming answer's words.
 * @param {string}                    props.yesLabel   What it does, for a screen reader.
 * @param {() => void}                props.onYes      Affirms.
 * @param {() => void}                props.onNo       Rejects.
 * @param {import('react').ReactNode} [props.children] Controls ahead of the two.
 * @return {import('react').ReactElement} The answers.
 */
function Answers( { label, yes, yesLabel, onYes, onNo, children } ) {
	return (
		<div className="newspack-intelligence__judgments">
			{ children }
			<button
				type="button"
				className="button"
				aria-keyshortcuts="n"
				aria-label={ sprintf(
					/* translators: %s: the entity's name. */
					__( 'Reject %s', 'newspack-intelligence' ),
					label
				) }
				{ ...LEAVES }
				onClick={ onNo }
			>
				{ __( 'No', 'newspack-intelligence' ) }
			</button>
			<button
				type="button"
				className={ primaryButtonClass() }
				aria-keyshortcuts="y"
				aria-label={ yesLabel }
				{ ...LEAVES }
				onClick={ onYes }
			>
				{ yes }
			</button>
		</div>
	);
}

/**
 * One proposed link as a review row: whether the item is about the entity,
 * why it was proposed in muted text, the sentence it was found in, a
 * curator's answers, and a refusal of the last answer under them.
 *
 * @param {Object}                                       props
 * @param {Object}                                       props.link    The link.
 * @param {(key: string) => void}                        props.onOpen  Opens the entity.
 * @param {?{onYes: () => void, onNo: () => void}|false} props.judge   A curator's answers, or none.
 * @param {?string}                                      props.refusal The refusal of its last answer.
 * @return {import('react').ReactElement} The row.
 */
function LinkRow( { link, onOpen, judge, refusal } ) {
	const label = entityName( link );
	return (
		<div
			className="newspack-intelligence__proposal"
			tabIndex={ -1 }
			data-review-row=""
			data-review-key={ linkKey( link ) }
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
			{ judge && (
				<Answers
					label={ label }
					yes={ __( 'Yes, it is', 'newspack-intelligence' ) }
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
		</div>
	);
}

/**
 * One item of the match queue: its title, citing it, then a review row for
 * each entity proposed for it.
 *
 * @param {Object}                                                          props
 * @param {Object}                                                          props.item      The item, holding its `links`.
 * @param {(key: string) => void}                                           props.onOpen    Opens an entity.
 * @param {((link: Object) => {onYes: () => void, onNo: () => void})|false} props.judgeOf   A link's answers, or none.
 * @param {(key: string) => ?string}                                        props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The item.
 */
function LinkItem( { item, onOpen, judgeOf, refusalOf } ) {
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
					refusal={ refusalOf( linkKey( link ) ) }
				/>
			) ) }
		</div>
	);
}

/**
 * One proposed entity as a review row: its name and type, the items citing
 * it with the sentence each was found in, and a curator's answers, Same
 * as… among them.
 *
 * @param {Object}                                                                                props
 * @param {Object}                                                                                props.item      The entity.
 * @param {(key: string) => void}                                                                 props.onOpen    Opens it.
 * @param {((entity: Object) => {onYes: () => void, onNo: () => void, onSame: () => void})|false} props.judgeOf   Its answers, or none.
 * @param {(key: string) => ?string}                                                              props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The row.
 */
function EntityRow( { item: entity, onOpen, judgeOf, refusalOf } ) {
	const key = entity.entity_key;
	const label = entity.name || key;
	const judge = judgeOf && judgeOf( entity );
	return (
		<div
			className="newspack-intelligence__item"
			tabIndex={ -1 }
			data-review-row=""
			data-review-key={ key }
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
					yes={ __( 'Add it', 'newspack-intelligence' ) }
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
		</div>
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
 * merges the other into it, and Not the same.
 *
 * @param {Object}                                                                                        props
 * @param {Object}                                                                                        props.item      The pair, as `theme_suggestions` answers it.
 * @param {((pair: Object) => {onKeep: (from: string, into: string) => void, onApart: () => void})|false} props.judgeOf   Its answers, or none.
 * @param {(key: string) => ?string}                                                                      props.refusalOf A row's refusal.
 * @return {import('react').ReactElement} The row.
 */
function ThemePair( { item: pair, judgeOf, refusalOf } ) {
	const [ first, second ] = pair.themes;
	const judge = judgeOf && judgeOf( pair );
	const words = pageFormat( Intl.ListFormat, {
		type: 'conjunction',
	} ).format(
		pair.words.map( ( one ) =>
			/* translators: %s: a word both themes' names hold, quoted. */
			sprintf( __( '"%s"', 'newspack-intelligence' ), one )
		)
	);
	return (
		<div
			className="newspack-intelligence__item"
			tabIndex={ -1 }
			data-review-row=""
			data-review-key={ pairKey( pair ) }
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
		</div>
	);
}

/**
 * The merge "Same as…" confirms: a name picker reading `names`, offering
 * every entity but the one merging, the consequence, and Merge and Cancel.
 * Merge waits for a pick and for the last send's answer; a refusal shows
 * here. The field takes focus as the dialog opens.
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
	const [ text, setText ] = useState( '' );
	const [ into, setInto ] = useState( null );
	const field = useRef( null );
	const names = useEntityNames( text );
	useEffect( () => field.current?.querySelector( 'input' )?.focus(), [] );

	const offered = names.suggestions.map( ( suggestion ) => {
		const key = names.keyOf( suggestion.value );
		return {
			value: key,
			label:
				key === suggestion.value
					? `${ suggestion.label } (${ key })`
					: suggestion.value,
		};
	} );
	const options = [
		...( into && ! offered.some( ( one ) => into.key === one.value )
			? [ { value: into.key, label: into.name } ]
			: [] ),
		...offered,
	].filter( ( one ) => from !== one.value );
	const ready = Boolean( into ) && ! pending;

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
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Merge into', 'newspack-intelligence' ) }
					value={ into?.key ?? null }
					options={ options }
					onFilterValueChange={ setText }
					onChange={ ( key ) =>
						setInto(
							key
								? {
										key,
										name: options.find(
											( one ) => key === one.value
										).label,
								  }
								: null
						)
					}
				/>
			</div>
			<Unread error={ names.error } refresh={ names.refresh } />
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
 * Review: three queues behind tabs, each counted by the total its read
 * answers less what the reader has judged — the proposed links, one row per
 * item asking of each entity proposed for it whether the item is about it;
 * the proposed entities, each with the items citing it; and the pairs of
 * themes `theme_suggestions` reads. Each queue is a `DataViewsList` in the list
 * layout. Each proposal says why it was proposed and quotes the sentence it
 * was found in. A curator's judgment takes its row out at once, so the next
 * can follow, and waits `UNDO_MS` before it is sent, a snackbar offering its
 * Undo meanwhile; a refused one returns its row with the refusal under it,
 * focused. On the match and name queues a curator also checks rows into a
 * run, which Yes or No in the bulk toolbar judges whole, every row's write
 * deferred under one Undo; a match row's run judges every entity it asks
 * about. Same as… merges an entity into one the curator picks, confirmed in
 * a dialog because a merge cannot be taken back, and sent on Merge. Not the
 * same hides a pair of themes for this reader and records nothing. On the
 * match and name queues the review keys work from anywhere on the page,
 * unless the reader turned them off or the screen is narrow.
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
	const [ apart, setApart ] = useState( () => new Set() );
	const [ refused, setRefused ] = useState( () => new Map() );
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
	const undoWrite = deferred.undo;
	const undo = useCallback( () => {
		restored.current = undoWrite();
	}, [ undoWrite ] );

	const links = usePageRead( 'review' );
	const entities = useIntelRead( 'review:entities', 'review', [
		'entities',
	] );
	const themes = useIntelRead( 'review:themes', 'theme_suggestions', [] );

	const judgedLinks = useHeld( links, 'links', linkKey );
	const judgedEntities = useHeld( entities, 'entities', entityKey );
	const judgedThemes = useHeld( themes, 'suggestions', pairKey );

	const refusedIn = ( queue, held ) => ( tokens, error ) => {
		held.refused( tokens );
		const key = JUDGED_BY[ queue ]( argsOf( tokens ) );
		setRefused( ( was ) => new Map( was ).set( key, error ) );
		restored.current = key;
	};
	const relinked = {
		dirties: ENTITY_READS,
		onRefused: refusedIn( 'links', judgedLinks ),
	};
	const rejudged = {
		dirties: JUDGED_READS,
		onRefused: refusedIn( 'entities', judgedEntities ),
	};
	const writes = {
		confirm_link: useIntelWrite( 'confirm_link', relinked ),
		reject_link: useIntelWrite( 'reject_link', relinked ),
		accept_entity: useIntelWrite( 'accept_entity', rejudged ),
		reject_entity: useIntelWrite( 'reject_entity', rejudged ),
		merge_theme: useIntelWrite( 'merge_theme', {
			dirties: THEME_READS,
			onRefused: refusedIn( 'themes', judgedThemes ),
		} ),
	};
	const shownLinks = without(
		judgedLinks.read,
		'links',
		linkKey,
		deferred.hidden
	);
	const shownEntities = without(
		judgedEntities.read,
		'entities',
		entityKey,
		deferred.hidden
	);
	const shownThemes = without(
		judgedThemes.read,
		'suggestions',
		pairKey,
		new Set( [ ...deferred.hidden, ...apart ] )
	);

	const mergeEntities = useIntelWrite( 'merge_entities', {
		dirties: JUDGED_READS,
		onRefused: judgedEntities.refused,
		refresh: () => {
			const merged = sentMerge.current;
			restored.current = beside(
				( shownEntities.answer?.entities ?? [] ).map( entityKey ),
				new Set( [ merged.key ] )
			);
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

	/**
	 * Defers one row's judgment, its row hidden at once and any refusal
	 * showing let go.
	 *
	 * @param {string}                                                   key     The row's key.
	 * @param {(key: string, row: ?Object, run: () => string[]) => void} hold    The queue's `useHeld` hold.
	 * @param {() => string[]}                                           run     Sends the write.
	 * @param {string}                                                   message What the snackbar says.
	 */
	const judge = ( key, hold, run, message ) => {
		setRefused( new Map() );
		deferred.defer( key, () => hold( key, null, run ), message );
	};
	const refusalOf = ( key ) => refused.get( key ) ?? null;

	/**
	 * A bulk action judging a checked run: every row's write deferred under
	 * one message and one Undo, the run let go, any refusal showing let go,
	 * and focus moved to the row taking the run's place.
	 *
	 * @param {string}                                                                                                                     id       The action's id.
	 * @param {string}                                                                                                                     label    Its words.
	 * @param {import('react').ReactElement}                                                                                               icon     Its glyph, which a narrow toolbar shows.
	 * @param {string[]}                                                                                                                   keys     The rows shown, by key, in order.
	 * @param {(row: Object) => Array<{key: string, hold: (key: string, row: ?Object, run: () => string[]) => void, run: () => string[]}>} writesOf A checked row's writes.
	 * @param {(count: number) => string}                                                                                                  said     What the snackbar says of so many writes.
	 * @return {Object} The DataViews action.
	 */
	const runAction = ( id, label, icon, keys, writesOf, said ) => ( {
		id,
		label,
		icon,
		supportsBulk: true,
		callback: ( rows ) => {
			const run = rows.flatMap( writesOf );
			setRefused( new Map() );
			setChecked( [] );
			restored.current = beside(
				keys,
				new Set( run.map( ( one ) => one.key ) )
			);
			deferred.deferGroup(
				run.map( ( one ) => ( {
					key: one.key,
					send: () => one.hold( one.key, null, one.run ),
				} ) ),
				said( run.length )
			);
		},
	} );

	const sendLink = ( verb, link ) => () =>
		writes[ verb ].run( {
			item: link.item_key,
			entity: link.entity_key,
		} );
	const linkWrites = ( verb ) => ( row ) =>
		row.links.map( ( link ) => ( {
			key: linkKey( link ),
			hold: judgedLinks.hold,
			run: sendLink( verb, link ),
		} ) );
	const sendEntity = ( verb, entity ) => () =>
		writes[ verb ].run( { key: entity.entity_key } );
	const entityWrites = ( verb ) => ( entity ) => [
		{
			key: entity.entity_key,
			hold: judgedEntities.hold,
			run: sendEntity( verb, entity ),
		},
	];
	const linkRows = byItem( shownLinks.answer?.links ?? [] );
	const linkKeys = linkRows.flatMap( ( row ) => row.links.map( linkKey ) );
	const entityRows = shownEntities.answer?.entities ?? [];
	const entityKeys = entityRows.map( entityKey );
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
			count: waiting( links, shownLinks, 'links' ),
			read: shownLinks,
			rows: 'links',
			keyed: true,
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
							judge(
								key,
								judgedLinks.hold,
								sendLink( 'confirm_link', link ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Confirmed %s.',
										'newspack-intelligence'
									),
									name
								)
							),
						onNo: () =>
							judge(
								key,
								judgedLinks.hold,
								sendLink( 'reject_link', link ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Rejected %s.',
										'newspack-intelligence'
									),
									name
								)
							),
					};
				} ),
			actions: canTune
				? [
						runAction(
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
			count: waiting( entities, shownEntities, 'entities' ),
			read: shownEntities,
			rows: 'entities',
			keyed: true,
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
							judge(
								key,
								judgedEntities.hold,
								sendEntity( 'accept_entity', entity ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Accepted %s.',
										'newspack-intelligence'
									),
									name
								)
							),
						onNo: () =>
							judge(
								key,
								judgedEntities.hold,
								sendEntity( 'reject_entity', entity ),
								sprintf(
									/* translators: %s: the entity's name. */
									__(
										'Rejected %s.',
										'newspack-intelligence'
									),
									name
								)
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
			count: waiting( themes, shownThemes, 'suggestions' ),
			read: shownThemes,
			rows: 'suggestions',
			keyed: false,
			items: shownThemes.answer?.suggestions ?? [],
			getItemId: pairKey,
			Row: ThemePair,
			judgeOf:
				canTune &&
				( ( pair ) => {
					const key = pairKey( pair );
					return {
						onKeep: ( from, into ) =>
							judge(
								key,
								judgedThemes.hold,
								() => writes.merge_theme.run( { from, into } ),
								sprintf(
									/* translators: 1: the theme merged away, 2: the theme it merges into. */
									__(
										'Merged %1$s into %2$s.',
										'newspack-intelligence'
									),
									from,
									into
								)
							),
						onApart: () =>
							setApart( ( was ) => new Set( was ).add( key ) ),
					};
				} ),
			actions: [],
		},
	];
	const shown = queues.find( ( queue ) => open === queue.id );

	return (
		<div ref={ view } className="newspack-intelligence__view">
			<p className="newspack-intelligence__intro">
				{ __(
					'The extractor suggests which client or company each item is about. Confirm the right ones and reject the rest.',
					'newspack-intelligence'
				) }
			</p>
			{ refused.has( null ) && <Refusal error={ refused.get( null ) } /> }
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
					legend={ shown.keyed ? legendText() : null }
					page
					heading={ () =>
						view.current.ownerDocument.getElementById(
							tabDomId( SCOPE, open )
						)
					}
				>
					{ ( describedBy ) => (
						<AnswerBody
							read={ shown.read }
							rows={ shown.rows }
							empty={ emptyText() }
							skeleton={ <Skeleton count={ SKELETON_ROWS } /> }
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
											page: 1,
											perPage: Math.max(
												shown.items.length,
												1
											),
										} }
										onChangeView={ () => {} }
										empty={ emptyText() }
										paginationInfo={ {
											totalItems: shown.items.length,
											totalPages: 1,
										} }
										render={ shown.Row }
										rowProps={ {
											onOpen: onOpenEntity,
											judgeOf: shown.judgeOf,
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
						sentMerge.current = { ...merging, into };
						setMerging( { ...merging, into, sent: true } );
						judgedEntities.hold( merging.key, null, () =>
							mergeEntities.run( {
								from: merging.key,
								into: into.key,
							} )
						);
					} }
					onClose={ () => {
						if ( merging.sent ) {
							restored.current = merging.key;
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

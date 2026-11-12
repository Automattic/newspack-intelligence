import {
	Button,
	DropdownMenu,
	RadioControl,
	SnackbarList,
} from '@wordpress/components';
import {
	createContext,
	useContext,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { archive, moreHorizontal } from '@wordpress/icons';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { useHeld } from '../hooks/useHeld';
import {
	ENTITY_READS,
	ITEM_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { AnswerBody } from './Answer';
import Cite, { EntityChip } from './Cite';
import EntityPicker from './EntityPicker';
import HowRead from './HowRead';
import Refusal from './Refusal';
import Skeleton from './Skeleton';
import UndoSnackbar from './UndoSnackbar';
import {
	argsOf,
	dayLabel,
	entityName,
	itemKey,
	reasonText,
	safeHref,
	utcDay,
} from './format';
import { word } from './words';

/** The most subjects an item's row leads with as pills. */
const LEAD_PILLS = 3;

/** How long a snackbar saying what a menu action did stands. */
const SAID_MS = 4000;

/** The link roles naming an entity an item is about, as the server spells them. */
const NAMING_ROLES = [ 'subject', 'mention' ];

/** The least score, of the extraction's 0 to 10, that reads as high. */
const HIGH_SCORE = 7;

/** The least score that reads as medium; below it reads as low. */
const MEDIUM_SCORE = 4;

/** The writes a list's rows share, which `ItemRows` holds. */
const ListWrites = createContext( null );

/**
 * A chip's key in its list's undo window: its item and its entity.
 *
 * @param {string} item   The item's key.
 * @param {string} entity The entity's key.
 * @return {string} The key.
 */
const chipKey = ( item, entity ) => JSON.stringify( [ item, entity ] );

/**
 * An exclusion's key in its list's undo window, apart from every chip's.
 *
 * @param {string} item The item's key.
 * @return {string} The key.
 */
const exclusionKey = ( item ) => JSON.stringify( [ item ] );

/**
 * A held chip's key.
 *
 * @param {{key: string}} chip The chip.
 * @return {string} Its key.
 */
const keyOfChip = ( chip ) => chip.key;

/**
 * The refusal a write's last reply gave the item, read off the item its
 * tokens echo.
 *
 * @param {{refusal: ?string, answeredArgs: ?string[]}} write The write.
 * @param {Object}                                      item  The item.
 * @return {?string} The refusal, or null.
 */
const refusalFor = ( write, item ) =>
	write.refusal && item.item_key === argsOf( write.answeredArgs ).item
		? write.refusal
		: null;

/**
 * The rows of one list of items, sharing one `reject_link` write and one
 * `exclude_item` write, whose refusals echo the item and the entity they
 * judged, and one undo window: `deferred`, the list's own when the caller
 * holds one (as a list taking `z` does), else a window of its own with its
 * snackbar. A chip a curator removes hides at once and its rejection waits
 * behind Undo, then stays out (`useHeld`) until a read no longer lists the
 * entity; an excluded row reads "Excluded." the same way until a read no
 * longer lists the item. Undo, or a refusal, puts either back; a refusal
 * echoing no tokens shows above the rows.
 *
 * @param {Object}                    props
 * @param {Object[]}                  props.items      The items the rows show.
 * @param {Object}                    [props.deferred] The list's undo window (`useDeferredWrite`).
 * @param {import('react').ReactNode} props.children   The rows.
 * @return {import('react').ReactElement} The rows.
 */
export function ItemRows( { items, deferred, children } ) {
	return deferred ? (
		<RowWrites items={ items } deferred={ deferred }>
			{ children }
		</RowWrites>
	) : (
		<OwnUndo items={ items }>{ children }</OwnUndo>
	);
}

/**
 * Rows holding an undo window of their own, and its snackbar.
 *
 * @param {Object}                    props
 * @param {Object[]}                  props.items    The items.
 * @param {import('react').ReactNode} props.children The rows.
 * @return {import('react').ReactElement} The rows.
 */
function OwnUndo( { items, children } ) {
	const deferred = useDeferredWrite();
	return (
		<>
			<RowWrites items={ items } deferred={ deferred }>
				{ children }
			</RowWrites>
			<UndoSnackbar latest={ deferred.latest } onUndo={ deferred.undo } />
		</>
	);
}

/**
 * The list's writes, lent to each row through `ListWrites`.
 *
 * @param {Object}                    props
 * @param {Object[]}                  props.items    The items.
 * @param {Object}                    props.deferred The undo window.
 * @param {import('react').ReactNode} props.children The rows.
 * @return {import('react').ReactElement} The rows.
 */
function RowWrites( { items, deferred, children } ) {
	const scope = useId();
	const chipRead = useMemo(
		() => ( {
			answer: {
				chips: items.flatMap( ( item ) =>
					( item.entities ?? [] ).map( ( entity ) => ( {
						key: chipKey( item.item_key, entity.entity_key ),
					} ) )
				),
			},
			error: null,
			loading: false,
		} ),
		[ items ]
	);
	const itemRead = useMemo(
		() => ( { answer: { items }, error: null, loading: false } ),
		[ items ]
	);
	const heldChips = useHeld( chipRead, 'chips', keyOfChip );
	const kept = useHeld( itemRead, 'items', itemKey );
	const reject = useIntelWrite( 'reject_link', {
		scope: `reject-link:${ scope }`,
		dirties: ENTITY_READS,
		onRefused: heldChips.refused,
	} );
	const exclude = useIntelWrite( 'exclude_item', {
		scope: `exclude-item:${ scope }`,
		dirties: ITEM_READS,
		onRefused: kept.refused,
	} );
	const shownChips = new Set( heldChips.read.answer.chips.map( keyOfChip ) );
	const shownItems = new Set( kept.read.answer.items.map( itemKey ) );
	const bare = [ reject, exclude ].find(
		( write ) => write.refusal && 0 === write.answeredArgs?.length
	);
	const writes = {
		entitiesOf: ( item ) =>
			( item.entities ?? [] ).filter( ( entity ) => {
				const key = chipKey( item.item_key, entity.entity_key );
				return shownChips.has( key ) && ! deferred.hidden.has( key );
			} ),
		removeChip: ( item, entity ) => {
			const key = chipKey( item.item_key, entity.entity_key );
			deferred.defer(
				key,
				() =>
					heldChips.hold( key, null, () =>
						reject.run( {
							item: item.item_key,
							entity: entity.entity_key,
						} )
					),
				sprintf(
					/* translators: %s: the entity taken off the item. */
					__( 'Removed %s from this item.', 'newspack-intelligence' ),
					entityName( entity )
				)
			);
		},
		excluded: ( item ) =>
			deferred.hidden.has( exclusionKey( item.item_key ) ) ||
			! shownItems.has( item.item_key ),
		exclude: ( item ) =>
			deferred.defer(
				exclusionKey( item.item_key ),
				() =>
					kept.hold( item.item_key, null, () =>
						exclude.run( { item: item.item_key } )
					),
				sprintf(
					/* translators: %s: the item's title. */
					__(
						'Excluded “%s” from every view.',
						'newspack-intelligence'
					),
					item.title
				)
			),
		refusalOf: ( item ) =>
			refusalFor( exclude, item ) ?? refusalFor( reject, item ),
		menuId: ( item ) => `${ scope }more-${ item.item_key }`,
	};
	return (
		<ListWrites.Provider value={ writes }>
			<Refusal error={ bare?.refusal ?? null } />
			{ children }
		</ListWrites.Provider>
	);
}

/**
 * The writes of the list a row renders in, which `ItemRows` holds.
 *
 * @return {Object} The writes.
 * @throws {Error} Outside `ItemRows`.
 */
export function useListWrites() {
	const writes = useContext( ListWrites );
	if ( null === writes ) {
		throw new Error(
			'ItemRow renders inside ItemRows, which holds its list’s writes'
		);
	}
	return writes;
}

/**
 * The control saving an item to a folder, for a caller who may curate;
 * nothing for a reader.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item     The item.
 * @param {(item: Object) => void} [props.onSave] Saves the item; absent for a reader.
 * @return {?import('react').ReactElement} The button.
 */
export function SaveButton( { item, onSave } ) {
	return onSave ? (
		<Button
			size="small"
			icon={ archive }
			className="newspack-intelligence__save"
			label={ __( 'Save to folder', 'newspack-intelligence' ) }
			onClick={ () => onSave( item ) }
		/>
	) : null;
}

/**
 * One item as `search` and `timeline` answer it: its title citing its URL,
 * its meta line (`Meta`), its summary, a toggle showing its excerpt
 * (`Excerpt`), its entities as pills (`Pills`); for a caller who may curate,
 * Save (`SaveButton`), which moves into the row's menu (`MoreActions`) when
 * the page is narrow, and for one holding tune the text action excluding it
 * from every view; then any control the view adds, and the menu, whose
 * "How this was read" opens the drawer (`HowRead`) under the row, focus
 * returning to the menu's toggle when it closes. Why the item ranks sits
 * behind a disclosure (`WhyRanks`). An exclusion reads "Excluded." in the
 * row's place, behind its list's Undo until it is sent; a refused one puts
 * the row back with the refusal. For a reader holding tune, each chip holds
 * an × rejecting that link, and the menu offers "Is this about…"
 * (`AboutDialog`), linking the item to an entity picked by name. The row
 * renders inside `ItemRows`, which holds those writes for its whole list.
 *
 * @param {Object}                    props
 * @param {Object}                    props.item         The item.
 * @param {(key: string) => void}     props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}    [props.onSave]     Saves the item; absent for a reader.
 * @param {boolean}                   [props.canTune]    Whether the reader holds tune, which offers the exclusion.
 * @param {import('react').ReactNode} [props.children]   The view's own controls for the item.
 * @return {import('react').ReactElement} The row.
 */
export default function ItemRow( {
	item,
	onOpenEntity,
	onSave,
	canTune,
	children,
} ) {
	const writes = useListWrites();
	const narrow = useNarrow();
	const { menu, drawer } = useRowMenu( item, {
		onOpenEntity,
		onSave: narrow ? onSave : undefined,
		canTune,
	} );
	if ( writes.excluded( item ) ) {
		return (
			<li className="newspack-intelligence__item">
				<p className="newspack-nodes-status is-muted" role="status">
					{ __( 'Excluded.', 'newspack-intelligence' ) }
				</p>
			</li>
		);
	}
	const pills = pillsOf( writes.entitiesOf( item ) );
	return (
		<li className="newspack-intelligence__item">
			<Refusal error={ writes.refusalOf( item ) } />
			<div className="newspack-intelligence__item-head">
				<strong className="newspack-intelligence__item-title">
					<Cite url={ item.url } title={ item.title } />
				</strong>
				{ ! narrow && <SaveButton item={ item } onSave={ onSave } /> }
				{ canTune && (
					<button
						type="button"
						className="button-link newspack-intelligence__exclude"
						onClick={ () => writes.exclude( item ) }
					>
						{ __(
							'Exclude from every view',
							'newspack-intelligence'
						) }
					</button>
				) }
				{ children }
				{ menu }
			</div>
			<Meta item={ item } />
			{ item.summary && <p>{ item.summary }</p> }
			<Excerpt item={ item } />
			<WhyRanks item={ item } />
			<Pills
				{ ...pills }
				onOpenEntity={ onOpenEntity }
				onRemove={ removerOf( writes, item, canTune ) }
			/>
			{ drawer }
		</li>
	);
}

/**
 * A row's "More actions" menu (`MoreActions`), and the drawer laying out
 * how the item was read (`HowRead`) while its entry holds it open, focus
 * returning to the menu's toggle when it closes. The menu leads with the
 * subject its first pill shows, and offers "Is this about…" for a reader
 * holding tune.
 *
 * @param {Object}                 item                 The item.
 * @param {Object}                 actions              What the menu offers.
 * @param {(key: string) => void}  actions.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [actions.onSave]     Saves the item, when Save sits in the menu.
 * @param {() => void}             [actions.onExclude]  Excludes the item, when the exclusion sits in the menu.
 * @param {boolean}                [actions.canTune]    Whether the reader holds tune.
 * @return {{menu: import('react').ReactElement, drawer: ?import('react').ReactElement}} The menu, and the drawer while open.
 */
export function useRowMenu(
	item,
	{ onOpenEntity, onSave, onExclude, canTune }
) {
	const writes = useListWrites();
	const [ reading, setReading ] = useState( false );
	const toggleId = writes.menuId( item );
	return {
		menu: (
			<MoreActions
				item={ item }
				subject={ pillsOf( writes.entitiesOf( item ) ).lead[ 0 ] }
				onOpenEntity={ onOpenEntity }
				onSave={ onSave }
				onExclude={ onExclude }
				onHowRead={ () => setReading( true ) }
				canLink={ canTune }
				toggleId={ toggleId }
			/>
		),
		drawer: reading ? (
			<HowRead
				item={ item }
				onClose={ () => {
					setReading( false );
					document.getElementById( toggleId ).focus();
				} }
			/>
		) : null,
	};
}

/**
 * What a pill's × does for a reader holding tune: takes its entity off the
 * item, behind its list's Undo, and hands focus to the row's menu toggle;
 * none for any other reader.
 *
 * @param {Object}  writes  The list's writes (`useListWrites()`).
 * @param {Object}  item    The item.
 * @param {boolean} canTune Whether the reader holds tune.
 * @return {((entity: Object) => void)|undefined} The remover.
 */
export const removerOf = ( writes, item, canTune ) =>
	canTune
		? ( entity ) => {
				writes.removeChip( item, entity );
				document.getElementById( writes.menuId( item ) ).focus();
		  }
		: undefined;

/**
 * A row's meta line as cells, joined without a mark: the source's initial as its
 * icon and its word, the item's UTC day (`dayLabel()`), how many other copies
 * of its story the reader may read, a toggle opening them below the line
 * (`Copies`), its kind's word as a badge, and its kind check
 * (`kindCheckText()`); a part the item lacks is left out.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The meta line.
 */
function Meta( { item } ) {
	const today = todayUtc();
	const copies = useCopies( item, today );
	return (
		<>
			<p className="newspack-nodes-status is-muted newspack-intelligence__meta">
				<ItemSource item={ item } />
				<ItemDay item={ item } today={ today } />
				{ copies.toggle }
				<ItemKind item={ item } />
				{ item.kind_check && (
					<span>{ kindCheckText( item.kind_check, today ) }</span>
				) }
			</p>
			{ copies.list }
		</>
	);
}

/**
 * How many other copies of an item's story the reader may read, as a toggle
 * opening them (`Copies`); neither for an item with none.
 *
 * @param {Object} item  The item.
 * @param {string} today Today's UTC day, Y-m-d.
 * @return {{toggle: ?import('react').ReactElement, list: ?import('react').ReactElement}} The toggle, and the copies while open.
 */
export function useCopies( item, today ) {
	const [ open, setOpen ] = useState( false );
	const copiesId = useId();
	if ( ! ( 0 < item.copies ) ) {
		return { toggle: null, list: null };
	}
	return {
		toggle: (
			<Button
				variant="link"
				className="newspack-intelligence__more"
				aria-expanded={ open }
				aria-controls={ copiesId }
				onClick={ () => setOpen( ! open ) }
			>
				{ sprintf(
					/* translators: %d: the story's other copies the reader may read. */
					_n(
						'and %d other copy',
						'and %d other copies',
						item.copies,
						'newspack-intelligence'
					),
					item.copies
				) }
			</Button>
		),
		list: open ? (
			<Copies item={ item } id={ copiesId } today={ today } />
		) : null,
	};
}

/**
 * Today's UTC day, Y-m-d, on the page's clock.
 *
 * @return {string} The day.
 */
export const todayUtc = () => utcDay( Math.floor( Core.now() ) );

/**
 * An item's source: its word, after its initial as its icon.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The source.
 */
export function ItemSource( { item } ) {
	const source = word( 'sourceWords', item.source );
	return (
		<span className="newspack-intelligence__source">
			<span
				className="newspack-nodes-status-badge is-pill is-neutral newspack-intelligence__source-icon"
				aria-hidden="true"
			>
				{ source.charAt( 0 ) }
			</span>
			{ source }
		</span>
	);
}

/**
 * An item's UTC day (`dayLabel()`), named against `today`; none for an item
 * with no time.
 *
 * @param {Object} props
 * @param {Object} props.item    The item.
 * @param {string} [props.today] Today's UTC day, Y-m-d; the page's own unless named.
 * @return {?import('react').ReactElement} The day.
 */
export function ItemDay( { item, today = todayUtc() } ) {
	const day = utcDay( item.item_ts );
	return day ? (
		<time dateTime={ day }>{ dayLabel( day, today ) }</time>
	) : null;
}

/**
 * An item's kind's word as a badge; none for an item with no kind.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {?import('react').ReactElement} The badge.
 */
export function ItemKind( { item } ) {
	return item.kind ? (
		<span className="newspack-nodes-status-badge is-neutral">
			{ word( 'kindWords', item.kind ) }
		</span>
	) : null;
}

/**
 * The other copies of an item's story the reader may read (`copies`), each
 * its title linking where it is safe, its source's word and its UTC day.
 *
 * @param {Object} props
 * @param {Object} props.item  The item.
 * @param {string} props.id    The list's id, which its toggle controls.
 * @param {string} props.today Today's UTC day, Y-m-d.
 * @return {import('react').ReactElement} The copies.
 */
function Copies( { item, id, today } ) {
	const read = useIntelRead( `copies:${ useId() }`, 'copies', [
		item.item_key,
	] );
	return (
		<div id={ id }>
			<AnswerBody
				read={ read }
				rows="copies"
				empty={ __(
					'None of its other copies can be read now.',
					'newspack-intelligence'
				) }
				skeleton={ <Skeleton shape="line" count={ item.copies } /> }
			>
				{ ( { copies } ) => (
					<ul
						aria-label={ __(
							'Other copies',
							'newspack-intelligence'
						) }
					>
						{ copies.map( ( copy ) => (
							<li key={ copy.item_key }>
								<Cite url={ copy.url } title={ copy.title } />{ ' ' }
								<span className="newspack-nodes-status is-muted">
									{ sprintf(
										/* translators: 1: the copy's source, 2: its day, such as "14 Oct". */
										__(
											'%1$s, %2$s',
											'newspack-intelligence'
										),
										word( 'sourceWords', copy.source ),
										dayLabel( copy.day, today )
									) }
								</span>
							</li>
						) ) }
					</ul>
				) }
			</AnswerBody>
		</div>
	);
}

/**
 * Copies a link, answering whether it was copied: a page with no clipboard,
 * or one that refuses it, answers that it was not.
 *
 * @param {string} href The link.
 * @return {Promise<string>} What to say.
 */
const copyLink = ( href ) =>
	new Promise( ( resolve ) =>
		resolve( window.navigator.clipboard.writeText( href ) )
	).then(
		() => __( 'Link copied.', 'newspack-intelligence' ),
		() => __( 'The link could not be copied.', 'newspack-intelligence' )
	);

/**
 * The row's "More actions" menu: "Open profile" for the subject its first
 * pill shows, "Copy link" for a URL it would link, "Save to folder" when
 * given a save, "Exclude from every view" when given an exclusion, "Is this
 * about…" for a curator, opening `AboutDialog`, and
 * "How this was read". What a copy or a link did stands as a polite
 * snackbar for SAID_MS.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item         The item.
 * @param {?Object}                [props.subject]    The entity its first pill shows.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves the item, when Save sits in the menu.
 * @param {() => void}             [props.onExclude]  Excludes the item, when the exclusion sits in the menu.
 * @param {() => void}             props.onHowRead    Opens how the item was read.
 * @param {boolean}                [props.canLink]    Whether the reader may link the item to an entity.
 * @param {string}                 props.toggleId     The menu toggle's id.
 * @return {import('react').ReactElement} The menu.
 */
function MoreActions( {
	item,
	subject,
	onOpenEntity,
	onSave,
	onExclude,
	onHowRead,
	canLink,
	toggleId,
} ) {
	const [ said, setSaid ] = useState( null );
	const [ asking, setAsking ] = useState( false );
	const notices = useRef( 0 );
	useEffect( () => {
		if ( ! said ) {
			return undefined;
		}
		const timer = setTimeout( () => setSaid( null ), SAID_MS );
		return () => clearTimeout( timer );
	}, [ said ] );
	const say = ( content ) => {
		notices.current += 1;
		setSaid( { id: notices.current, content } );
	};
	const closeAsking = () => {
		setAsking( false );
		document.getElementById( toggleId ).focus();
	};
	const href = safeHref( item.url );
	const controls = [
		subject && {
			title: __( 'Open profile', 'newspack-intelligence' ),
			onClick: () => onOpenEntity( subject.entity_key ),
		},
		href && {
			title: __( 'Copy link', 'newspack-intelligence' ),
			onClick: () => copyLink( href ).then( say ),
		},
		onSave && {
			title: __( 'Save to folder', 'newspack-intelligence' ),
			onClick: () => onSave( item ),
		},
		onExclude && {
			title: __( 'Exclude from every view', 'newspack-intelligence' ),
			onClick: onExclude,
		},
		canLink && {
			title: __( 'Is this about…', 'newspack-intelligence' ),
			onClick: () => setAsking( true ),
		},
		{
			title: __( 'How this was read', 'newspack-intelligence' ),
			onClick: onHowRead,
		},
	].filter( Boolean );
	return (
		<>
			<DropdownMenu
				icon={ moreHorizontal }
				label={ __( 'More actions', 'newspack-intelligence' ) }
				toggleProps={ { size: 'small', id: toggleId } }
				controls={ controls }
			/>
			{ asking && (
				<AboutDialog
					item={ item }
					onLinked={ ( name ) => {
						say(
							sprintf(
								/* translators: %s: the entity the item was linked to. */
								__(
									'Linked %s to this item. It shows here once the item is read again.',
									'newspack-intelligence'
								),
								name
							)
						);
						closeAsking();
					} }
					onClose={ closeAsking }
				/>
			) }
			{ said && (
				<div className="newspack-intelligence__undo">
					<SnackbarList
						notices={ [ said ] }
						onRemove={ () => setSaid( null ) }
					/>
				</div>
			) }
		</>
	);
}

/**
 * "Is this about…": a name picker over `names` (`EntityPicker`) and the role
 * the item names the entity in, the subject unless the curator says it is
 * mentioned, worded as the vocabulary words link roles. "Link it" sends
 * `add_link` once an entity `names` offered is picked; a landed link
 * hands `onLinked` the entity's name, and a refused one stays in the dialog
 * as its alert.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item     The item.
 * @param {(name: string) => void} props.onLinked Runs once the link lands.
 * @param {() => void}             props.onClose  Closes the dialog.
 * @return {import('react').ReactElement} The dialog.
 */
function AboutDialog( { item, onLinked, onClose } ) {
	const [ picked, setPicked ] = useState( { key: '', name: null } );
	const [ role, setRole ] = useState( 'subject' );
	const field = useRef( null );
	useEffect( () => field.current?.querySelector( 'input' )?.focus(), [] );
	const { key, name } = picked;
	const link = useIntelWrite( 'add_link', {
		scope: `add-link:${ useId().replace( /[^a-z0-9]/gi, '' ) }`,
		dirties: ENTITY_READS,
		refresh: () => onLinked( name ),
	} );
	const ready = Boolean( name ) && ! link.pending;
	const send = () =>
		ready && link.run( { item: item.item_key, entity: key, role } );
	return (
		<Modal onClose={ onClose } onSubmit={ send }>
			<ModalHeader
				title={ __( 'Is this about…', 'newspack-intelligence' ) }
				onClose={ onClose }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<div ref={ field }>
				<EntityPicker
					label={ __( 'Client or company', 'newspack-intelligence' ) }
					value={ key }
					onChange={ ( next, named ) =>
						setPicked( { key: next, name: named } )
					}
				/>
			</div>
			<RadioControl
				label={ __( 'Named as', 'newspack-intelligence' ) }
				selected={ role }
				options={ NAMING_ROLES.map( ( value ) => ( {
					value,
					label: word( 'linkRoleWords', value ),
				} ) ) }
				onChange={ setRole }
			/>
			<Refusal error={ link.refusal } />
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button
					type="submit"
					className={ primaryButtonClass( ! ready ) }
					disabled={ ! ready }
				>
					{ __( 'Link it', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

/**
 * An item's kind check as its meta line says it: the kind checked, whether
 * it passed or failed, and its UTC day (`dayLabel()`).
 *
 * @param {{kind: string, verdict: string, ts: number}} check The item's kind check.
 * @param {string}                                      today Today's UTC day, Y-m-d.
 * @return {string} The text.
 */
export function kindCheckText( check, today ) {
	const format =
		'passed' === check.verdict
			? /* translators: 1: item kind, 2: UTC day. */
			  __(
					'%1$s passed its kind check on %2$s',
					'newspack-intelligence'
			  )
			: /* translators: 1: item kind, 2: UTC day. */
			  __(
					'%1$s failed its kind check on %2$s',
					'newspack-intelligence'
			  );
	return sprintf(
		format,
		word( 'kindWords', check.kind ),
		dayLabel( utcDay( check.ts ), today )
	);
}

/**
 * The entities an item's row shows as pills (`chips()`): its first
 * LEAD_PILLS subjects as `lead`, and every other entity it names as `rest`;
 * none for an entity linked by a role that does not name it.
 *
 * @param {Object[]} entities The item's entities, in the answer's order.
 * @return {{lead: Object[], rest: Object[]}} The pills.
 */
function pillsOf( entities ) {
	const named = chips( entities ).filter( ( entity ) =>
		NAMING_ROLES.includes( entity.role )
	);
	const lead = named
		.filter( ( entity ) => 'subject' === entity.role )
		.slice( 0, LEAD_PILLS );
	return {
		lead,
		rest: named.filter( ( entity ) => ! lead.includes( entity ) ),
	};
}

/**
 * The open clients among an item's entities, chipped as its pills are
 * (`chips()`), in the answer's order.
 *
 * @param {Object[]} entities The item's entities, in the answer's order.
 * @return {Object[]} Its open clients.
 */
export const clientsOf = ( entities ) =>
	chips( entities ).filter( ( entity ) => entity.client );

/**
 * An item's pills (`EntityChip`, named by `pillName()`): the lead, then the
 * rest behind a "+N more" toggle in the same row; none for none. Given
 * `onRemove`, each pill holds an × removing its entity from the item.
 *
 * @param {Object}                   props
 * @param {Object[]}                 props.lead         The pills shown at first.
 * @param {Object[]}                 props.rest         The pills behind the toggle.
 * @param {(key: string) => void}    props.onOpenEntity Opens an entity.
 * @param {(entity: Object) => void} [props.onRemove]   Removes an entity; absent for a reader.
 * @return {?import('react').ReactElement} The pills.
 */
export function Pills( { lead, rest, onOpenEntity, onRemove } ) {
	const [ open, setOpen ] = useState( false );
	if ( 0 === lead.length + rest.length ) {
		return null;
	}
	return (
		<ul className="newspack-intelligence__chips">
			{ ( open ? [ ...lead, ...rest ] : lead ).map( ( entity ) => (
				<li key={ entity.entity_key }>
					<EntityChip
						entityKey={ entity.entity_key }
						name={ pillName( entity ) }
						onOpen={ onOpenEntity }
						onRemove={ onRemove && ( () => onRemove( entity ) ) }
						removeLabel={ sprintf(
							/* translators: %s: an entity the item names. */
							__(
								'Remove %s from this item',
								'newspack-intelligence'
							),
							entityName( entity )
						) }
					/>
				</li>
			) ) }
			{ 0 < rest.length && (
				<li>
					<Button
						variant="link"
						className="newspack-intelligence__more"
						aria-expanded={ open }
						onClick={ () => setOpen( ! open ) }
					>
						{ open
							? __( 'Show fewer', 'newspack-intelligence' )
							: sprintf(
									/* translators: %d: entities the item names, hidden. */
									__( '+%d more', 'newspack-intelligence' ),
									rest.length
							  ) }
					</Button>
				</li>
			) }
		</ul>
	);
}

/**
 * An entity's name as its pill shows it: the name, or its key where the
 * graph holds no name, as "%s, mentioned" for a mention.
 *
 * @param {Object} entity The entity.
 * @return {string} The name.
 */
function pillName( entity ) {
	const name = entityName( entity );
	return 'mention' === entity.role
		? sprintf(
				/* translators: %s: an entity the item mentions. */
				__( '%s, mentioned', 'newspack-intelligence' ),
				name
		  )
		: name;
}

/**
 * The body's opening behind a toggle, collapsed at first; none when it is
 * empty or says nothing its summary or title does not (`repeats()`).
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {?import('react').ReactElement} The toggle and the excerpt it shows.
 */
function Excerpt( { item } ) {
	const [ open, setOpen ] = useState( false );
	if ( ! item.excerpt || repeats( item ) ) {
		return null;
	}
	return (
		<>
			<Button
				variant="link"
				className="newspack-intelligence__more"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ open
					? __( 'Show less', 'newspack-intelligence' )
					: __( 'Show more', 'newspack-intelligence' ) }
			</Button>
			{ open && <p>{ item.excerpt }</p> }
		</>
	);
}

/**
 * Each level a score reads at, by the noun it qualifies: importance leads
 * the line, so its words are capitalized, and relevance follows.
 *
 * @return {{importance: Object<string, string>, relevance: Object<string, string>}} The words.
 */
const levelWords = () => ( {
	importance: {
		high: __( 'High importance', 'newspack-intelligence' ),
		medium: __( 'Medium importance', 'newspack-intelligence' ),
		low: __( 'Low importance', 'newspack-intelligence' ),
	},
	relevance: {
		high: __( 'high relevance', 'newspack-intelligence' ),
		medium: __( 'medium relevance', 'newspack-intelligence' ),
		low: __( 'low relevance', 'newspack-intelligence' ),
	},
} );

/**
 * The level a score reads at.
 *
 * @param {number} score A score from 0 to 10.
 * @return {'high'|'medium'|'low'} The level.
 */
function levelOf( score ) {
	if ( HIGH_SCORE <= score ) {
		return 'high';
	}
	return MEDIUM_SCORE <= score ? 'medium' : 'low';
}

/**
 * An item's importance in words, "High importance"; null for an item a read
 * answers without a score, as one never extracted.
 *
 * @param {Object} item The item.
 * @return {?string} The words.
 */
export const importanceText = ( item ) =>
	'number' === typeof item.importance
		? levelWords().importance[ levelOf( item.importance ) ]
		: null;

/**
 * Why an item ranks, behind a "Why this ranks" toggle, collapsed at first:
 * its importance and relevance in words, never as numbers, then the
 * extraction's reason (`reasonText()`); none for an item a read answers
 * without both scores, as one never extracted.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {?import('react').ReactElement} The toggle and what it shows.
 */
function WhyRanks( { item } ) {
	const [ open, setOpen ] = useState( false );
	const shownId = useId();
	if (
		'number' !== typeof item.importance ||
		'number' !== typeof item.relevance
	) {
		return null;
	}
	return (
		<>
			<Button
				variant="link"
				className="newspack-intelligence__more"
				aria-expanded={ open }
				aria-controls={ shownId }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Why this ranks', 'newspack-intelligence' ) }
			</Button>
			{ open && (
				<p
					id={ shownId }
					className="newspack-nodes-status is-muted newspack-intelligence__why"
				>
					{ sprintf(
						/* translators: 1: how important the item is, such as "High importance", 2: how relevant, such as "low relevance". */
						__( '%1$s, %2$s.', 'newspack-intelligence' ),
						importanceText( item ),
						levelWords().relevance[ levelOf( item.relevance ) ]
					) }{ ' ' }
					{ reasonText( item.rank_reason ) }
				</p>
			) }
		</>
	);
}

/**
 * Whether an item's summary or title already holds its whole excerpt, case
 * aside.
 *
 * @param {Object} item The item.
 * @return {boolean} Whether the excerpt adds nothing.
 */
function repeats( item ) {
	const excerpt = item.excerpt.toLowerCase();
	return [ item.summary, item.title ].some( ( shown ) =>
		( shown ?? '' ).toLowerCase().includes( excerpt )
	);
}

/**
 * An item's entities as chips, in the answer's order, a proposal left out
 * when another entity of its name is not `proposed`, and only the first of
 * several proposals of one name kept; two entities that are not proposed
 * keep a chip each, whatever their names.
 *
 * @param {Object[]} entities The item's entities, in the answer's order.
 * @return {Object[]} The entities to chip.
 */
function chips( entities ) {
	const shown = new Set(
		entities
			.filter( ( entity ) => 'proposed' !== entity.state )
			.map( entityName )
	);
	return entities.filter( ( entity ) => {
		if ( 'proposed' !== entity.state ) {
			return true;
		}
		if ( shown.has( entityName( entity ) ) ) {
			return false;
		}
		shown.add( entityName( entity ) );
		return true;
	} );
}

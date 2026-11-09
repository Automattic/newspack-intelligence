import { Button, DropdownMenu, SnackbarList } from '@wordpress/components';
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { archive, moreHorizontal } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { ITEM_READS, useIntelWrite } from '../hooks/useIntelCommand';
import Cite, { EntityChip } from './Cite';
import Pager from './Pager';
import Refusal from './Refusal';
import { dayLabel, safeHref, utcDay } from './format';
import { word } from './words';

/** The most subjects an item's row leads with as pills. */
const LEAD_PILLS = 3;

/** How long the snackbar saying whether a link was copied stands. */
const COPIED_MS = 4000;

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
 * from every view; then any control the view adds, and the menu. An
 * exclusion puts the row behind its Undo (`Excluding`) until it is sent; a
 * refused one puts the row back with the refusal.
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
	const narrow = useNarrow();
	const pills = pillsOf( item.entities ?? [] );
	const [ excluding, setExcluding ] = useState( false );
	const [ refusal, setRefusal ] = useState( null );
	if ( excluding ) {
		return (
			<Excluding
				item={ item }
				onUndo={ () => setExcluding( false ) }
				onRefused={ ( why ) => {
					setRefusal( why );
					setExcluding( false );
				} }
			/>
		);
	}
	return (
		<li className="newspack-intelligence__item">
			<Refusal error={ refusal } />
			<div className="newspack-intelligence__item-head">
				<strong className="newspack-intelligence__item-title">
					<Cite url={ item.url } title={ item.title } />
				</strong>
				{ ! narrow && <SaveButton item={ item } onSave={ onSave } /> }
				{ canTune && (
					<button
						type="button"
						className="button-link newspack-intelligence__exclude"
						onClick={ () => {
							setRefusal( null );
							setExcluding( true );
						} }
					>
						{ __(
							'Exclude from every view',
							'newspack-intelligence'
						) }
					</button>
				) }
				{ children }
				<MoreActions
					item={ item }
					subject={ pills.lead[ 0 ] }
					onOpenEntity={ onOpenEntity }
					onSave={ narrow ? onSave : undefined }
				/>
			</div>
			<Meta item={ item } />
			{ item.summary && <p>{ item.summary }</p> }
			<Excerpt item={ item } />
			<Pills { ...pills } onOpenEntity={ onOpenEntity } />
		</li>
	);
}

/**
 * A row's meta line as cells, joined without a mark: the source's initial as its
 * icon and its word, the item's UTC day (`dayLabel()`), its kind's word as a badge,
 * and its kind check (`kindCheckText()`); a part the item lacks is left out.
 *
 * @param {Object} props
 * @param {Object} props.item The item.
 * @return {import('react').ReactElement} The meta line.
 */
function Meta( { item } ) {
	const today = utcDay( Math.floor( Core.now() ) );
	const day = utcDay( item.item_ts );
	const source = word( 'sourceWords', item.source );
	return (
		<p className="newspack-nodes-status is-muted newspack-intelligence__meta">
			<span className="newspack-intelligence__source">
				<span
					className="newspack-nodes-status-badge is-pill is-neutral newspack-intelligence__source-icon"
					aria-hidden="true"
				>
					{ source.charAt( 0 ) }
				</span>
				{ source }
			</span>
			{ day && <time dateTime={ day }>{ dayLabel( day, today ) }</time> }
			{ item.kind && (
				<span className="newspack-nodes-status-badge is-neutral">
					{ word( 'kindWords', item.kind ) }
				</span>
			) }
			{ item.kind_check && (
				<span>{ kindCheckText( item.kind_check, today ) }</span>
			) }
		</p>
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
 * pill shows, "Copy link" for a URL it would link, whose outcome stands as a
 * polite snackbar for COPIED_MS, and "Save to folder" when given a save;
 * none when it holds none of them.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item         The item.
 * @param {?Object}                [props.subject]    The entity its first pill shows.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves the item, when Save sits in the menu.
 * @return {?import('react').ReactElement} The menu.
 */
function MoreActions( { item, subject, onOpenEntity, onSave } ) {
	const [ copied, setCopied ] = useState( null );
	const copies = useRef( 0 );
	useEffect( () => {
		if ( ! copied ) {
			return undefined;
		}
		const timer = setTimeout( () => setCopied( null ), COPIED_MS );
		return () => clearTimeout( timer );
	}, [ copied ] );
	const href = safeHref( item.url );
	const controls = [
		subject && {
			title: __( 'Open profile', 'newspack-intelligence' ),
			onClick: () => onOpenEntity( subject.entity_key ),
		},
		href && {
			title: __( 'Copy link', 'newspack-intelligence' ),
			onClick: () =>
				copyLink( href ).then( ( content ) => {
					copies.current += 1;
					setCopied( { id: copies.current, content } );
				} ),
		},
		onSave && {
			title: __( 'Save to folder', 'newspack-intelligence' ),
			onClick: () => onSave( item ),
		},
	].filter( Boolean );
	if ( 0 === controls.length ) {
		return null;
	}
	return (
		<>
			<DropdownMenu
				icon={ moreHorizontal }
				label={ __( 'More actions', 'newspack-intelligence' ) }
				toggleProps={ { size: 'small' } }
				controls={ controls }
			/>
			{ copied && (
				<div className="newspack-intelligence__undo">
					<SnackbarList
						notices={ [ copied ] }
						onRemove={ () => setCopied( null ) }
					/>
				</div>
			) }
		</>
	);
}

/**
 * A row excluded from every view: "Excluded." and its Undo while
 * `useDeferredWrite` holds the `exclude_item` write, then "Excluded." alone
 * once it is sent. Each mounts its own write under a scope of its own, since
 * two rows may be excluded at once.
 *
 * @param {Object}                props
 * @param {Object}                props.item      The item.
 * @param {() => void}            props.onUndo    Puts the row back.
 * @param {(why: string) => void} props.onRefused Puts the row back with the refusal.
 * @return {import('react').ReactElement} The notice.
 */
function Excluding( { item, onUndo, onRefused } ) {
	const scope = `exclude-item:${ useId().replace( /[^a-z0-9]/gi, '' ) }`;
	const exclude = useIntelWrite( 'exclude_item', {
		scope,
		dirties: ITEM_READS,
	} );
	const deferred = useDeferredWrite();
	const asked = useRef( false );
	useEffect( () => {
		if ( asked.current ) {
			return;
		}
		asked.current = true;
		deferred.defer(
			item.item_key,
			() => exclude.run( { item: item.item_key } ),
			sprintf(
				/* translators: %s: the item's title. */
				__( 'Excluded “%s” from every view.', 'newspack-intelligence' ),
				item.title
			)
		);
	}, [ deferred, exclude, item ] );
	useEffect( () => {
		if ( exclude.refusal ) {
			onRefused( exclude.refusal );
		}
	}, [ exclude.refusal, onRefused ] );
	return (
		<li className="newspack-intelligence__item">
			<p className="newspack-nodes-status is-muted" role="status">
				<span>{ __( 'Excluded.', 'newspack-intelligence' ) }</span>{ ' ' }
				{ deferred.latest && (
					<button
						type="button"
						className="button button-small"
						onClick={ () => {
							deferred.undo();
							onUndo();
						} }
					>
						{ __( 'Undo', 'newspack-intelligence' ) }
					</button>
				) }
			</p>
		</li>
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
function kindCheckText( check, today ) {
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

/** The link roles naming an entity an item is about, as the server spells them. */
const NAMING_ROLES = [ 'subject', 'mention' ];

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
 * An item's pills (`EntityChip`, named by `pillName()`): the lead, then the
 * rest behind a "+N more" toggle in the same row; none for none.
 *
 * @param {Object}                props
 * @param {Object[]}              props.lead         The pills shown at first.
 * @param {Object[]}              props.rest         The pills behind the toggle.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {?import('react').ReactElement} The pills.
 */
function Pills( { lead, rest, onOpenEntity } ) {
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
	const name = entity.name || entity.entity_key;
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
	const label = ( entity ) => entity.name || entity.entity_key;
	const shown = new Set(
		entities
			.filter( ( entity ) => 'proposed' !== entity.state )
			.map( label )
	);
	return entities.filter( ( entity ) => {
		if ( 'proposed' !== entity.state ) {
			return true;
		}
		if ( shown.has( label( entity ) ) ) {
			return false;
		}
		shown.add( label( entity ) );
		return true;
	} );
}

/**
 * Each order `search` answers in: what a page in it says, the order the
 * switch offers instead, and the switch's label.
 *
 * @return {Object<string, {shown: string, other: string, label: string}>} The orders.
 */
const sorts = () => ( {
	importance: {
		shown: __( 'Ranked by importance', 'newspack-intelligence' ),
		other: 'time',
		label: __( 'Show newest first', 'newspack-intelligence' ),
	},
	time: {
		shown: __( 'Newest first', 'newspack-intelligence' ),
		other: 'importance',
		label: __( 'Rank by importance', 'newspack-intelligence' ),
	},
} );

/**
 * One page of the items a read answered, each an `ItemRow`, then the pager
 * over the page starts its view holds; given `onSort`, the order the answer
 * says it is in, and the switch to the other.
 *
 * @param {Object}                                          props
 * @param {{items: Object[], total: number, sort: ?string}} props.answer       The read's answer.
 * @param {number[]}                                        props.starts       Each page's first row, the current last.
 * @param {(starts: number[]) => void}                      props.onStarts     Replaces the stack.
 * @param {(key: string) => void}                           props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                          [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                         [props.canTune]    Whether the reader holds tune, which offers each row's exclusion.
 * @param {(sort: string) => void}                          [props.onSort]     Asks for the other order; absent for a page that does not sort.
 * @return {import('react').ReactElement} The page.
 */
export function ItemPage( {
	answer,
	starts,
	onStarts,
	onOpenEntity,
	onSave,
	canTune,
	onSort,
} ) {
	const order = onSort && sorts()[ answer.sort ];
	return (
		<>
			{ order && (
				<p className="newspack-nodes-status is-muted">
					<span>{ order.shown }</span>{ ' ' }
					<button
						type="button"
						className="button button-small"
						onClick={ () => onSort( order.other ) }
					>
						{ order.label }
					</button>
				</p>
			) }
			<ul className="newspack-intelligence__items">
				{ answer.items.map( ( item ) => (
					<ItemRow
						key={ item.item_key }
						item={ item }
						onOpenEntity={ onOpenEntity }
						onSave={ onSave }
						canTune={ canTune }
					/>
				) ) }
			</ul>
			<Pager
				starts={ starts }
				onStarts={ onStarts }
				shown={ answer.items.length }
				total={ answer.total }
			/>
		</>
	);
}

import { Button } from '@wordpress/components';
import { useEffect, useId, useRef, useState } from '@wordpress/element';
import { archive } from '@wordpress/icons';
import { __, sprintf } from '@wordpress/i18n';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { useIntelWrite } from '../hooks/useIntelCommand';
import Cite, { EntityChip } from './Cite';
import Pager from './Pager';
import Refusal from './Refusal';
import { metaLine, utcDay } from './format';

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
 * its source, kind, day and kind check (`kindCheckText()`), its summary, a toggle showing its excerpt (`Excerpt`),
 * its entities as chips (`Chips`), and, for a caller who may curate, the
 * control saving it to a folder and, for one holding tune, the text action
 * excluding it from every view, then any control the view adds. An exclusion
 * puts the row behind its Undo (`Excluding`) until it is sent; a refused one
 * puts the row back with the refusal.
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
				<strong>
					<Cite url={ item.url } title={ item.title } />
				</strong>
				<SaveButton item={ item } onSave={ onSave } />
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
			</div>
			<p className="newspack-nodes-status is-muted">
				{ metaLine(
					item.source,
					item.kind,
					utcDay( item.item_ts ),
					kindCheckText( item.kind_check )
				) }
			</p>
			{ item.summary && <p>{ item.summary }</p> }
			<Excerpt item={ item } />
			<Chips
				entities={ item.entities ?? [] }
				onOpenEntity={ onOpenEntity }
			/>
		</li>
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
	const exclude = useIntelWrite( 'exclude_item', { scope } );
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
 * it passed or failed, and its UTC day; '' for an item never checked.
 *
 * @param {?{kind: string, verdict: string, ts: number}} check The item's kind check.
 * @return {string} The text.
 */
function kindCheckText( check ) {
	if ( ! check ) {
		return '';
	}
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
	return sprintf( format, check.kind, utcDay( check.ts ) );
}

/** The link roles naming an entity an item is about, as the server spells them. */
const NAMING_ROLES = [ 'subject', 'mention' ];

/**
 * Whether an entity leads an item's chips: an open client, or a subject.
 *
 * @param {{client: boolean, role: string}} entity The entity.
 * @return {boolean} Whether it leads.
 */
const leads = ( entity ) => entity.client || 'subject' === entity.role;

/**
 * An item's entities as chips (`chips()`): each it is about as a client or a
 * subject, then its mentions behind a "+N more" toggle; none for an entity
 * linked by a role that does not name it.
 *
 * @param {Object}                props
 * @param {Object[]}              props.entities     The item's entities, in the answer's order.
 * @param {(key: string) => void} props.onOpenEntity Opens an entity.
 * @return {?import('react').ReactElement} The chips.
 */
function Chips( { entities, onOpenEntity } ) {
	const [ open, setOpen ] = useState( false );
	const named = chips( entities ).filter( ( entity ) =>
		NAMING_ROLES.includes( entity.role )
	);
	if ( 0 === named.length ) {
		return null;
	}
	const lead = named.filter( leads );
	const rest = named.filter( ( entity ) => ! leads( entity ) );
	return (
		<div className="newspack-intelligence__actions">
			<ul className="newspack-intelligence__chips">
				{ ( open ? [ ...lead, ...rest ] : lead ).map( ( entity ) => (
					<li key={ entity.entity_key }>
						<EntityChip
							entityKey={ entity.entity_key }
							name={ entity.name }
							onOpen={ onOpenEntity }
						/>
					</li>
				) ) }
			</ul>
			{ 0 < rest.length && (
				<Button
					variant="link"
					className="newspack-intelligence__more"
					aria-expanded={ open }
					onClick={ () => setOpen( ! open ) }
				>
					{ open
						? __( 'Show fewer', 'newspack-intelligence' )
						: sprintf(
								/* translators: %d: entities the item mentions, hidden. */
								__( '+%d more', 'newspack-intelligence' ),
								rest.length
						  ) }
				</Button>
			) }
		</div>
	);
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

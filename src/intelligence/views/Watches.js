import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ComboboxControl,
	DropdownMenu,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { moreHorizontal } from '@wordpress/icons';
import DataViewsList from '@newspack-nodes/shared/components/DataViewsList';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import {
	WATCH_READS,
	commandArgs,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { usePageRead } from '../hooks/usePageRead';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import Answer, { AnswerBody, Unread } from '../components/Answer';
import { useEntityNames } from '../components/EntityPicker';
import ItemList from '../components/ItemList';
import ItemRow from '../components/ItemRow';
import ListKeys, { LEAVES } from '../components/ListKeys';
import Refusal from '../components/Refusal';
import UndoSnackbar from '../components/UndoSnackbar';
import { argsOf, itemKey } from '../components/format';

/** No watch open, its matches on their first page. */
export const CLOSED_WATCH = { key: null, page: 1 };

/** The matches one page reads, which `watch_matches` is asked for by name. */
const MATCH_PAGE = 50;

/** The three things a watch may follow, in the order the form offers them. */
const KINDS = [ 'lens', 'entity', 'collection' ];

/** The three ways a watch delivers its matches, in the order rows name them. */
const DELIVERIES = [ 'inbox', 'digest', 'instant' ];

/** Each match row's focusable element inside the list layout. */
const MATCH_ROWS = '.dataviews-view-list__item';

/**
 * What each target kind is called: as a watch follows it, and as the field
 * and the column naming the one it follows.
 *
 * @return {Object<string, {watched: string, field: string}>} The words, by kind.
 */
const kindWords = () => ( {
	lens: {
		watched: __( 'A team view', 'newspack-intelligence' ),
		field: __( 'Team view', 'newspack-intelligence' ),
	},
	entity: {
		watched: __( 'A client or company', 'newspack-intelligence' ),
		field: __( 'Client or company', 'newspack-intelligence' ),
	},
	collection: {
		watched: __( 'A folder', 'newspack-intelligence' ),
		field: __( 'Folder', 'newspack-intelligence' ),
	},
} );

/**
 * What each delivery checkbox does, and how a row names it: first in a row,
 * then after another.
 *
 * @return {Object<string, {box: string, first: string, after: string}>} The words, by delivery.
 */
const deliveryWords = () => ( {
	inbox: {
		box: __( 'List new items here', 'newspack-intelligence' ),
		first: __( 'Here', 'newspack-intelligence' ),
		after: __( 'here', 'newspack-intelligence' ),
	},
	digest: {
		box: __( 'Email me a daily digest', 'newspack-intelligence' ),
		first: __( 'Daily email', 'newspack-intelligence' ),
		after: __( 'daily email', 'newspack-intelligence' ),
	},
	instant: {
		box: __( 'Email me each new item', 'newspack-intelligence' ),
		first: __( 'Each new item', 'newspack-intelligence' ),
		after: __( 'each new item', 'newspack-intelligence' ),
	},
} );

/**
 * How a listed watch delivers, its deliveries in order: "Here, daily email".
 *
 * @param {Object} watch The listed watch.
 * @return {string} The words.
 */
function deliveryText( watch ) {
	const words = deliveryWords();
	return DELIVERIES.filter( ( one ) => watch[ one ] )
		.map( ( one, at ) => words[ one ][ 0 === at ? 'first' : 'after' ] )
		.join( ', ' );
}

/**
 * What a listed watch's inbox holds: that it is off, its new count, or
 * nothing while a save of it awaits a read.
 *
 * @param {Object} watch The listed watch.
 * @return {?(string|number)} The words, or the count.
 */
function newText( watch ) {
	if ( ! watch.inbox ) {
		return __( 'Not listed here; email only', 'newspack-intelligence' );
	}
	return watch.unseen;
}

/**
 * A listed watch as the form's draft: its key, name and target kind, the
 * target's key under that kind and '' under the others, and its deliveries.
 *
 * @param {Object} watch A watch as `watches` answers it.
 * @return {Object} The draft.
 */
function watchDraft( watch ) {
	return {
		key: watch.watch_key,
		name: watch.name,
		kind: watch.target.kind,
		...Object.fromEntries(
			KINDS.map( ( kind ) => [
				kind,
				kind === watch.target.kind ? watch.target.key : '',
			] )
		),
		...Object.fromEntries(
			DELIVERIES.map( ( one ) => [ one, watch[ one ] ] )
		),
	};
}

/**
 * The draft of a watch not yet saved, following `target`, delivering to the
 * page alone.
 *
 * @param {{kind: string, key: string}} target What it follows.
 * @param {string}                      name   Its name.
 * @return {Object} The draft.
 */
export function newWatch( target, name ) {
	return watchDraft( {
		watch_key: '',
		name,
		target,
		digest: false,
		instant: false,
		inbox: true,
	} );
}

/**
 * A draft as the watch it saves, shaped as `watches` answers one; its new
 * count is unknown until a read answers it.
 *
 * @param {Object} draft The form's draft.
 * @return {Object} The watch.
 */
const draftWatch = ( draft ) => ( {
	watch_key: draft.key,
	name: draft.name,
	target: { kind: draft.kind, key: draft[ draft.kind ] },
	...Object.fromEntries( DELIVERIES.map( ( one ) => [ one, draft[ one ] ] ) ),
	unseen: null,
} );

/**
 * A draft as the options `save_watch` takes: its key when it edits one, its
 * name, the one target its kind names, and every delivery as true or false.
 *
 * @param {Object} draft The form's draft.
 * @return {Object<string, string>} The options.
 */
const watchArgs = ( draft ) => ( {
	key: draft.key,
	name: draft.name,
	[ draft.kind ]: draft[ draft.kind ],
	...Object.fromEntries(
		[ 'digest', 'instant', 'inbox' ].map( ( one ) => [
			one,
			String( draft[ one ] ),
		] )
	),
} );

/**
 * A listed watch's key, '' for one still saving.
 *
 * @param {Object} watch The watch.
 * @return {string} Its key.
 */
const watchKey = ( watch ) => watch.watch_key;

/**
 * Whether a listed watch reflects a held one: the same watch, by the key a
 * save of one carries, any listed watch for a new one, holding the name,
 * target kind and deliveries the save sent. The target's key is not
 * compared, because the server stores an entity as its merge survivor; the
 * saved fields are, because the re-read a landed edit runs still answers
 * the watch as it was.
 *
 * @param {Object} listed The listed watch.
 * @param {Object} held   The watch held.
 * @return {boolean} Whether the listed watch reflects it.
 */
const sameWatch = ( listed, held ) =>
	( '' === held.watch_key || listed.watch_key === held.watch_key ) &&
	listed.name === held.name &&
	listed.target.kind === held.target.kind &&
	DELIVERIES.every( ( one ) => listed[ one ] === held[ one ] );

/**
 * Watches: the caller's own watches as a table, a list under 600 px, each
 * with what it follows, how it delivers and how many of its items are new;
 * the one opened, with its new matches; the form saving a new watch or the
 * one opened; and the dialog stopping it, for every reader. A save shows its
 * watch and a stop takes it out from the moment each is sent, until a read
 * of `watches` reflects it; a refusal puts it back. A save says so once it
 * lands, naming the watch its reply echoes. The page holds the watch
 * opened, its page and the form's draft, so a team view's "Watch this" opens
 * the form here, and the view returns as it was left.
 *
 * @param {Object}                                         props
 * @param {{key: ?string, page: number}}                   props.opened       The watch opened and its page.
 * @param {(opened: {key: ?string, page: number}) => void} props.onOpen       Opens a watch, or a page of it.
 * @param {?Object}                                        props.draft        The form's draft, or null when closed.
 * @param {(draft: ?Object) => void}                       props.onDraft      Replaces the draft; null closes the form.
 * @param {(key: string) => void}                          props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                         [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                        [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The view.
 */
export default function Watches( {
	opened,
	onOpen,
	draft,
	onDraft,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const [ stopping, setStopping ] = useState( null );
	// The event the last landed save wrote, which its snackbar is keyed by.
	const [ saved, setSaved ] = useState( null );
	const list = usePageRead( 'watches' );
	const watches = useHeld( list, 'watches', watchKey, sameWatch );
	const save = useIntelWrite( 'save_watch', {
		dirties: WATCH_READS,
		refresh: ( { event_id: event } ) => {
			setSaved( event );
			onDraft( null );
		},
		onRefused: watches.refused,
	} );
	const remove = useIntelWrite( 'remove_watch', {
		dirties: WATCH_READS,
		onRefused: watches.refused,
	} );
	const watch = watches.read.answer?.watches.find(
		( one ) => one.watch_key === opened.key
	);
	const startNew = () => onDraft( newWatch( { kind: 'lens', key: '' }, '' ) );

	return (
		<div className="newspack-intelligence__view">
			<div className="newspack-intelligence__create">
				<Button variant="primary" onClick={ startNew }>
					{ __( 'New watch', 'newspack-intelligence' ) }
				</Button>
			</div>
			<Refusal error={ remove.refusal } />
			<Answer
				title={ __( 'Watches', 'newspack-intelligence' ) }
				read={ watches.read }
				rows="watches"
				empty={
					<>
						{ __(
							'You are not watching anything yet. Watch a client, a team view or a folder to collect its new items here.',
							'newspack-intelligence'
						) }{ ' ' }
						<Button variant="secondary" onClick={ startNew }>
							{ __( 'New watch', 'newspack-intelligence' ) }
						</Button>
					</>
				}
			>
				{ ( answer ) => (
					<WatchList
						watches={ answer.watches }
						opened={ opened.key }
						onOpen={ ( key ) => onOpen( { key, page: 1 } ) }
					/>
				) }
			</Answer>
			{ watch && (
				<Opened
					key={ watch.watch_key }
					watch={ watch }
					page={ opened.page }
					onPage={ ( page ) =>
						onOpen( { key: watch.watch_key, page } )
					}
					onSeen={ list.refresh }
					onEdit={ () => onDraft( watchDraft( watch ) ) }
					onStop={ () => setStopping( watch ) }
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
					canTune={ canTune }
				/>
			) }
			{ draft && (
				<WatchForm
					draft={ draft }
					onDraft={ onDraft }
					refusal={ save.refusal }
					onSave={ () => {
						watches.hold( draft.key, draftWatch( draft ), () =>
							save.run( watchArgs( draft ) )
						);
					} }
					onCancel={ () => onDraft( null ) }
				/>
			) }
			{ stopping && (
				<StopDialog
					name={ stopping.name }
					onStop={ () => {
						setStopping( null );
						onOpen( CLOSED_WATCH );
						watches.hold( stopping.watch_key, null, () =>
							remove.run( { key: stopping.watch_key } )
						);
					} }
					onClose={ () => setStopping( null ) }
				/>
			) }
			<UndoSnackbar
				latest={
					saved && ! save.refusal
						? {
								id: saved,
								message: sprintf(
									/* translators: %s: the watch's name. */
									__( '%s saved.', 'newspack-intelligence' ),
									argsOf( save.answeredArgs ).name
								),
						  }
						: null
				}
			/>
		</div>
	);
}

/**
 * The watches as a table naming what each watches, how it delivers and how
 * many items are new, or under 600 px as a list whose rows say how many and
 * open their watch when picked.
 *
 * @param {Object}                props
 * @param {Object[]}              props.watches The watches, any still saving among them.
 * @param {?string}               props.opened  The watch opened.
 * @param {(key: string) => void} props.onOpen  Opens one.
 * @return {import('react').ReactElement} The list.
 */
function WatchList( { watches, opened, onOpen } ) {
	const narrow = useNarrow();
	const kinds = kindWords();
	const layout = narrow ? 'list' : 'table';
	const fields = narrow
		? []
		: [
				{
					id: 'watching',
					label: __( 'Watching', 'newspack-intelligence' ),
					render: ( { item } ) => kinds[ item.target.kind ].field,
				},
				{
					id: 'delivery',
					label: __( 'Delivery', 'newspack-intelligence' ),
					render: ( { item } ) => deliveryText( item ),
				},
				{
					id: 'new',
					label: __( 'New', 'newspack-intelligence' ),
					render: ( { item } ) => (
						<span className="newspack-intelligence__count">
							{ newText( item ) }
						</span>
					),
				},
		  ].map( ( field ) => ( {
				...field,
				enableSorting: false,
				enableHiding: false,
		  } ) );
	return (
		<DataViewsList
			items={ watches }
			getItemId={ ( one ) => one.watch_key || `saving:${ one.name }` }
			view={ {
				type: layout,
				// Its columns stay put, so a header offers no menu.
				layout: { enableMoving: false },
				page: 1,
				perPage: Math.max( watches.length, 1 ),
				fields: fields.map( ( field ) => field.id ),
			} }
			onChangeView={ () => {} }
			layout={ layout }
			empty={ null }
			paginationInfo={ { totalItems: watches.length, totalPages: 1 } }
			render={ narrow ? NarrowWatch : WatchName }
			rowProps={ { opened, onOpen } }
			label={ __( 'Name', 'newspack-intelligence' ) }
			fields={ fields }
			selection={ opened ? [ opened ] : [] }
			onChangeSelection={ ( [ key ] ) => key && onOpen( key ) }
		/>
	);
}

/**
 * A watch's name in its table row: the button opening it, or, while a save
 * of it awaits a read, its name as saving.
 *
 * @param {Object}                props
 * @param {Object}                props.item   The watch.
 * @param {?string}               props.opened The watch opened.
 * @param {(key: string) => void} props.onOpen Opens it.
 * @return {import('react').ReactElement} The name.
 */
function WatchName( { item, opened, onOpen } ) {
	if ( ! item.watch_key ) {
		return (
			<>
				<span>{ item.name }</span>{ ' ' }
				<span className="newspack-nodes-status is-muted">
					{ __( 'Saving…', 'newspack-intelligence' ) }
				</span>
			</>
		);
	}
	return (
		<Button
			variant="link"
			aria-current={ opened === item.watch_key || undefined }
			onClick={ () => onOpen( item.watch_key ) }
		>
			{ item.name }
		</Button>
	);
}

/**
 * A watch in the narrow list: its name and how many of its items are new,
 * the row itself opening it.
 *
 * @param {Object} props
 * @param {Object} props.item The watch.
 * @return {import('react').ReactElement} The row.
 */
function NarrowWatch( { item } ) {
	const count = newText( item );
	return (
		<span className="newspack-intelligence__watch-row">
			<span>{ item.name }</span>
			<span className="newspack-intelligence__count">
				{ 'number' === typeof count
					? sprintf(
							/* translators: %d: the watch's new items. */
							__( '%d new', 'newspack-intelligence' ),
							count
					  )
					: count }
			</span>
		</span>
	);
}

/**
 * The watch opened: its new matches, newest first, a page at a time, each
 * with **Mark seen**, and **Mark %d as seen** over the page shown; **Edit**,
 * and "Stop watching…" under its ⋯ menu. A mark hides its rows at once and
 * sends after the undo window, which **Undo** or `z` cancels; once sent its
 * rows stay hidden until a read no longer lists them, a refusal puts them
 * back, and a mark that lands returns to the first page. `j` and `k` walk
 * the matches while the list has focus. A watch whose inbox is off says so.
 *
 * @param {Object}                 props
 * @param {Object}                 props.watch        The listed watch opened.
 * @param {number}                 props.page         The page of matches shown.
 * @param {(page: number) => void} props.onPage       Shows another page.
 * @param {() => void}             props.onSeen       Runs once a mark lands.
 * @param {() => void}             props.onEdit       Opens the form on it.
 * @param {() => void}             props.onStop       Asks to stop watching it.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @return {import('react').ReactElement} The matches.
 */
function Opened( {
	watch,
	page,
	onPage,
	onSeen,
	onEdit,
	onStop,
	onOpenEntity,
	onSave,
	canTune,
} ) {
	const key = watch.watch_key;
	const offset = ( page - 1 ) * MATCH_PAGE;
	const read = useIntelRead(
		'watch:matches',
		'watch_matches',
		commandArgs( {
			key,
			limit: MATCH_PAGE,
			...( 0 < offset ? { offset } : {} ),
		} )
	);
	const matches = useHeld( read, 'items', itemKey );
	const markSeen = useIntelWrite( 'mark_seen', {
		dirties: WATCH_READS,
		refresh: () => {
			// A mark shortens the new list, so a later page would skip some.
			if ( 1 < page ) {
				onPage( 1 );
			}
			onSeen();
		},
		onRefused: matches.refused,
	} );
	const deferred = useDeferredWrite();
	// Each pending mark's key => the matches it marks.
	const marking = useRef( new Map() );
	const hidden = new Set(
		[ ...deferred.hidden ].flatMap( ( id ) => marking.current.get( id ) )
	);
	const shown = ( matches.read.answer?.items ?? [] ).filter(
		( item ) => ! hidden.has( item.item_key )
	);
	const mark = ( items ) => {
		const keys = items.map( itemKey );
		const id = `seen:${ keys.join( ' ' ) }`;
		marking.current.set( id, keys );
		deferred.defer(
			id,
			() => {
				const tokens = markSeen.run( { key, item: keys } );
				// The rows hold on one send, so its refusal puts them all back.
				keys.forEach( ( one ) =>
					matches.hold( one, null, () => tokens )
				);
			},
			sprintf(
				/* translators: %d: the matches marked seen. */
				_n(
					'Marked %d as seen.',
					'Marked %d as seen.',
					keys.length,
					'newspack-intelligence'
				),
				keys.length
			)
		);
	};
	const total = matches.read.answer?.total ?? 0;
	const heading = useRef( null );
	return (
		<Card as="section" aria-label={ watch.name }>
			<CardHeader>
				<h3 ref={ heading } tabIndex={ -1 }>
					{ watch.name }
				</h3>
				<div className="newspack-intelligence__actions">
					{ 0 < shown.length && (
						<Button
							variant="secondary"
							onClick={ () => mark( shown ) }
						>
							{ sprintf(
								/* translators: %d: the matches shown. */
								__(
									'Mark %d as seen',
									'newspack-intelligence'
								),
								shown.length
							) }
						</Button>
					) }
					<Button variant="secondary" onClick={ onEdit }>
						{ __( 'Edit', 'newspack-intelligence' ) }
					</Button>
					<DropdownMenu
						icon={ moreHorizontal }
						label={ sprintf(
							/* translators: %s: the watch's name. */
							__(
								'More actions for %s',
								'newspack-intelligence'
							),
							watch.name
						) }
						toggleProps={ { size: 'small' } }
						controls={ [
							{
								title: __(
									'Stop watching…',
									'newspack-intelligence'
								),
								onClick: onStop,
							},
						] }
					/>
				</div>
			</CardHeader>
			<CardBody>
				<Refusal error={ markSeen.refusal } />
				{ watch.inbox && 0 < total && (
					<p className="newspack-nodes-status is-muted">
						{ sprintf(
							/* translators: %d: the watch's new matches. */
							_n(
								'%d new item since you last marked them seen.',
								'%d new items since you last marked them seen.',
								total,
								'newspack-intelligence'
							),
							total
						) }
					</p>
				) }
				<AnswerBody
					read={ matches.read }
					rows="items"
					empty={
						watch.inbox
							? __(
									'Nothing new. Items appear here as they arrive.',
									'newspack-intelligence'
							  )
							: __(
									'Not listed here; email only',
									'newspack-intelligence'
							  )
					}
				>
					{ () => (
						<ListKeys
							rows={ MATCH_ROWS }
							onUndo={ deferred.undo }
							heading={ () => heading.current }
						>
							<ItemList
								items={ shown }
								deferred={ deferred }
								view={ {
									type: 'list',
									page,
									perPage: MATCH_PAGE,
								} }
								onChangeView={ ( next ) => onPage( next.page ) }
								empty={ __(
									'Nothing new. Items appear here as they arrive.',
									'newspack-intelligence'
								) }
								paginationInfo={ {
									totalItems: total,
									totalPages: Math.ceil( total / MATCH_PAGE ),
								} }
								render={ MatchRow }
								rowProps={ {
									onSeen: ( item ) => mark( [ item ] ),
									onOpenEntity,
									onSave,
									canTune,
								} }
							/>
						</ListKeys>
					) }
				</AnswerBody>
				<UndoSnackbar
					latest={ deferred.latest }
					onUndo={ deferred.undo }
				/>
			</CardBody>
		</Card>
	);
}

/**
 * One match: its item row with **Mark seen**.
 *
 * @param {Object}                 props
 * @param {Object}                 props.item         The match.
 * @param {(item: Object) => void} props.onSeen       Marks it seen.
 * @param {(key: string) => void}  props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void} [props.onSave]     Saves it; absent for a reader.
 * @param {boolean}                [props.canTune]    Whether the reader holds tune.
 * @return {import('react').ReactElement} The row.
 */
function MatchRow( { item, onSeen, onOpenEntity, onSave, canTune } ) {
	return (
		<ItemRow
			item={ item }
			onOpenEntity={ onOpenEntity }
			onSave={ onSave }
			canTune={ canTune }
		>
			<Button
				variant="secondary"
				size="small"
				{ ...LEAVES }
				onClick={ () => onSeen( item ) }
			>
				{ __( 'Mark seen', 'newspack-intelligence' ) }
			</Button>
		</ItemRow>
	);
}

/**
 * The form a watch is saved with, a new one or one being edited, in a
 * dialog whose Name takes focus as it opens: its name, the kind it follows
 * and that one target, picked by name
 * from the team views, the folders or the clients and companies `names`
 * offers, and its three deliveries.
 *
 * @param {Object}                  props
 * @param {Object}                  props.draft    The draft shown.
 * @param {(draft: Object) => void} props.onDraft  Replaces the draft.
 * @param {?string}                 props.refusal  The last save's refusal.
 * @param {() => void}              props.onSave   Sends the draft.
 * @param {() => void}              props.onCancel Closes the form unsent.
 * @return {import('react').ReactElement} The form.
 */
function WatchForm( { draft, onDraft, refusal, onSave, onCancel } ) {
	const nameField = useRef( null );
	useEffect( () => nameField.current.focus(), [] );
	const kinds = kindWords();
	const deliveries = deliveryWords();
	const set = ( name ) => ( value ) =>
		onDraft( { ...draft, [ name ]: value } );
	const title = draft.key
		? sprintf(
				/* translators: %s: the watch's name. */
				__( 'Edit %s', 'newspack-intelligence' ),
				draft.name
		  )
		: __( 'New watch', 'newspack-intelligence' );
	return (
		<Modal onClose={ onCancel }>
			<ModalHeader
				title={ title }
				onClose={ onCancel }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<form
				className="newspack-intelligence__edit"
				{ ...submitProps( onSave ) }
			>
				<TextControl
					ref={ nameField }
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Name', 'newspack-intelligence' ) }
					value={ draft.name }
					onChange={ set( 'name' ) }
				/>
				<RadioControl
					label={ __( 'Watching', 'newspack-intelligence' ) }
					selected={ draft.kind }
					options={ KINDS.map( ( kind ) => ( {
						label: kinds[ kind ].watched,
						value: kind,
					} ) ) }
					onChange={ set( 'kind' ) }
				/>
				<TargetField
					key={ draft.kind }
					kind={ draft.kind }
					label={ kinds[ draft.kind ].field }
					value={ draft[ draft.kind ] }
					onChange={ set( draft.kind ) }
				/>
				<fieldset className="newspack-intelligence__choices">
					<legend>
						{ __( 'Delivery', 'newspack-intelligence' ) }
					</legend>
					{ DELIVERIES.map( ( one ) => (
						<CheckboxControl
							__nextHasNoMarginBottom
							key={ one }
							label={ deliveries[ one ].box }
							checked={ draft[ one ] }
							onChange={ set( one ) }
						/>
					) ) }
				</fieldset>
				<Refusal error={ refusal } />
				<div className="newspack-nodes-modal__actions">
					<button
						type="button"
						className="button"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'newspack-intelligence' ) }
					</button>
					<button
						type="submit"
						className={ primaryButtonClass( false ) }
					>
						{ __( 'Save watch', 'newspack-intelligence' ) }
					</button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * The one target a watch follows, picked by name: a team view or a folder
 * from the lists `lenses` and `collections` answer, or a client or company
 * from what `names` offers for the text typed. A key that every list leaves
 * out, as an edited watch's entity before a name is typed, stands as itself.
 *
 * @param {Object}                props
 * @param {string}                props.kind     `lens`, `entity` or `collection`.
 * @param {string}                props.label    The field's label.
 * @param {string}                props.value    The target's key.
 * @param {(key: string) => void} props.onChange Takes the key picked.
 * @return {import('react').ReactElement} The field.
 */
function TargetField( { kind, label, value, onChange } ) {
	const [ text, setText ] = useState( '' );
	const lenses = usePageRead( 'lenses' );
	const collections = usePageRead( 'folders' );
	const names = useEntityNames( 'entity' === kind ? text : '' );
	const listed = { lens: lenses, collection: collections, entity: names }[
		kind
	];

	const offered = {
		lens: ( lenses.answer?.lenses ?? [] ).map( ( one ) => ( {
			value: one.lens_key,
			label: one.name,
		} ) ),
		collection: ( collections.answer?.collections ?? [] ).map(
			( one ) => ( { value: one.collection_key, label: one.name } )
		),
		entity: names.suggestions.map( ( suggestion ) => {
			const key = names.keyOf( suggestion.value );
			return {
				value: key,
				label:
					key === suggestion.value
						? suggestion.label
						: suggestion.value,
			};
		} ),
	}[ kind ];
	const options = [
		...( value && ! offered.some( ( one ) => value === one.value )
			? [ { value, label: value } ]
			: [] ),
		...offered,
	];
	return (
		<>
			<ComboboxControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label }
				value={ value || null }
				options={ options }
				onFilterValueChange={ setText }
				onChange={ ( key ) => onChange( key ?? '' ) }
			/>
			<Unread error={ listed.error } refresh={ listed.refresh } />
		</>
	);
}

/**
 * The dialog asking before a watch stops: what it costs, and **Stop
 * watching**, which alone sends.
 *
 * @param {Object}     props
 * @param {string}     props.name    The watch's name.
 * @param {() => void} props.onStop  Stops it.
 * @param {() => void} props.onClose Closes the dialog unsent.
 * @return {import('react').ReactElement} The dialog.
 */
function StopDialog( { name, onStop, onClose } ) {
	return (
		<Modal onClose={ onClose }>
			<ModalHeader
				title={ sprintf(
					/* translators: %s: the watch's name. */
					__( 'Stop watching %s?', 'newspack-intelligence' ),
					name
				) }
				onClose={ onClose }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<p>
				{ __(
					'Its list of new items goes with it.',
					'newspack-intelligence'
				) }
			</p>
			<div className="newspack-nodes-modal__actions">
				<button type="button" className="button" onClick={ onClose }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className={ primaryButtonClass( false, 'is-danger' ) }
					onClick={ onStop }
				>
					{ __( 'Stop watching', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

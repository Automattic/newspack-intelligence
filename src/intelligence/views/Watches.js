import { __, sprintf } from '@wordpress/i18n';
import {
	CheckboxControl,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import {
	commandArgs,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import ItemRow from '../components/ItemRow';
import Pager from '../components/Pager';
import Refusal from '../components/Refusal';
import { metaLine } from '../components/format';

/** No watch open, its matches on their first page. */
export const CLOSED_WATCH = { key: null, starts: [ 0 ] };

/** The three things a watch may follow, in the order the form offers them. */
const KINDS = [ 'lens', 'entity', 'collection' ];

/** The three ways a watch delivers its matches. */
const DELIVERIES = [ 'digest', 'instant', 'inbox' ];

/**
 * What each target kind is called.
 *
 * @return {Object<string, string>} The words, by kind.
 */
const kindWords = () => ( {
	lens: __( 'Lens', 'newspack-intelligence' ),
	entity: __( 'Entity', 'newspack-intelligence' ),
	collection: __( 'Collection', 'newspack-intelligence' ),
} );

/**
 * What each delivery checkbox does.
 *
 * @return {Object<string, string>} The labels, by delivery.
 */
const deliveryWords = () => ( {
	digest: __( 'Email me a daily digest', 'newspack-intelligence' ),
	instant: __( 'Email me each new match', 'newspack-intelligence' ),
	inbox: __( 'List new matches on this page', 'newspack-intelligence' ),
} );

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
 * dashboard alone.
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
 * A draft as the watch it saves, shaped as `watches` answers one; its unseen
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
		DELIVERIES.map( ( one ) => [ one, String( draft[ one ] ) ] )
	),
} );

/**
 * A listed watch's key.
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
 * What a listed watch's inbox holds: that it is off, its unseen count, or
 * nothing while a save of it awaits a read.
 *
 * @param {Object} watch The listed watch.
 * @return {?string} The words.
 */
function inboxText( watch ) {
	if ( ! watch.inbox ) {
		return __( 'Inbox off', 'newspack-intelligence' );
	}
	if ( null === watch.unseen ) {
		return null;
	}
	return sprintf(
		/* translators: %d: matches not yet seen. */
		__( '%d unseen', 'newspack-intelligence' ),
		watch.unseen
	);
}

/**
 * A listed item's key.
 *
 * @param {Object} item The item.
 * @return {string} Its key.
 */
const itemKey = ( item ) => item.item_key;

/**
 * A select's options over listed rows, by name, led by the choice of none.
 *
 * @param {?Array<Object>} rows  The rows, or none while owed.
 * @param {string}         field The field holding each row's key.
 * @return {{label: string, value: string}[]} The options.
 */
const optionsOf = ( rows, field ) => [
	{ label: __( 'Not set', 'newspack-intelligence' ), value: '' },
	...( rows ?? [] ).map( ( row ) => ( {
		label: row.name,
		value: row[ field ],
	} ) ),
];

/**
 * The form a watch is saved with, a new one or the one opened: its name,
 * the kind it follows and that one target — a lens or a collection picked
 * by name, an entity by key — and its three deliveries.
 *
 * @param {Object}                  props
 * @param {Object}                  props.draft    The draft shown.
 * @param {(draft: Object) => void} props.onDraft  Replaces the draft.
 * @param {() => void}              props.onSave   Sends the draft.
 * @param {() => void}              props.onCancel Closes the form unsent.
 * @return {import('react').ReactElement} The form.
 */
function WatchForm( { draft, onDraft, onSave, onCancel } ) {
	const lenses = useIntelRead( 'watch:lenses', 'lenses', [] );
	const collections = useIntelRead( 'watch:collections', 'collections', [] );
	const field = ( name ) => ( {
		value: draft[ name ],
		onChange: ( value ) => onDraft( { ...draft, [ name ]: value } ),
		__next40pxDefaultSize: true,
	} );
	const kinds = kindWords();
	const deliveries = deliveryWords();
	const legend = __( 'Save watch', 'newspack-intelligence' );
	return (
		<form
			className="newspack-intelligence__filters"
			aria-label={ legend }
			{ ...submitProps( onSave ) }
		>
			<TextControl
				label={ __( 'Name', 'newspack-intelligence' ) }
				{ ...field( 'name' ) }
			/>
			<SelectControl
				label={ __( 'Follows', 'newspack-intelligence' ) }
				options={ KINDS.map( ( kind ) => ( {
					label: kinds[ kind ],
					value: kind,
				} ) ) }
				{ ...field( 'kind' ) }
			/>
			{ 'lens' === draft.kind && (
				<SelectControl
					label={ kinds.lens }
					options={ optionsOf( lenses.answer?.lenses, 'lens_key' ) }
					{ ...field( 'lens' ) }
				/>
			) }
			{ 'entity' === draft.kind && (
				<TextControl
					label={ kinds.entity }
					placeholder="organization:acme-gazette"
					{ ...field( 'entity' ) }
				/>
			) }
			{ 'collection' === draft.kind && (
				<SelectControl
					label={ kinds.collection }
					options={ optionsOf(
						collections.answer?.collections,
						'collection_key'
					) }
					{ ...field( 'collection' ) }
				/>
			) }
			<fieldset className="newspack-intelligence__choices">
				<legend>{ __( 'Delivery', 'newspack-intelligence' ) }</legend>
				{ DELIVERIES.map( ( one ) => (
					<CheckboxControl
						key={ one }
						label={ deliveries[ one ] }
						checked={ draft[ one ] }
						onChange={ ( on ) =>
							onDraft( { ...draft, [ one ]: on } )
						}
					/>
				) ) }
			</fieldset>
			<Refusal error={ lenses.error } />
			<Refusal error={ collections.error } />
			<div className="newspack-intelligence__actions">
				<button type="submit" className="button button-primary">
					{ legend }
				</button>
				<button type="button" className="button" onClick={ onCancel }>
					{ __( 'Cancel', 'newspack-intelligence' ) }
				</button>
			</div>
		</form>
	);
}

/**
 * The watch opened: its unseen matches, newest first, a page at a time,
 * each with the control marking it seen, and one marking the page's matches
 * seen, naming each. A match marked seen leaves the list from the moment it
 * is sent, until a read no longer lists it, and a refusal puts it back; a
 * mark that lands returns to the first page. A watch whose inbox is off says
 * so in place of its matches.
 *
 * @param {Object}                     props
 * @param {Object}                     props.watch        The listed watch opened.
 * @param {number[]}                   props.starts       Each page's first row.
 * @param {(starts: number[]) => void} props.onStarts     Replaces the page starts.
 * @param {() => void}                 props.onSeen       Runs once a mark lands.
 * @param {(key: string) => void}      props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}     [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The matches.
 */
function Opened( { watch, starts, onStarts, onSeen, onOpenEntity, onSave } ) {
	const key = watch.watch_key;
	const offset = starts[ starts.length - 1 ];
	const read = useIntelRead(
		'watch:matches',
		'watch_matches',
		commandArgs( 0 < offset ? { key, offset } : { key } )
	);
	const matches = useHeld( read, 'items', itemKey );
	const markSeen = useIntelWrite( 'mark_seen', {
		refresh: () => {
			// A mark shortens the unseen list, so a later page would skip some.
			if ( 0 < offset ) {
				onStarts( [ 0 ] );
			} else {
				read.refresh();
			}
			onSeen();
		},
		onRefused: matches.refused,
	} );
	const markShown = ( items ) => {
		const tokens = markSeen.run( { key, item: items.map( itemKey ) } );
		// Each row holds on the one send, so its refusal puts them all back.
		items.forEach( ( item ) =>
			matches.hold( item.item_key, null, () => tokens )
		);
	};
	return (
		<>
			<Refusal error={ markSeen.refusal } />
			<Answer
				title={ watch.name }
				level={ 3 }
				read={ matches.read }
				rows="items"
				empty={
					watch.inbox
						? __( 'No unseen match.', 'newspack-intelligence' )
						: __(
								'The inbox is off for this watch, so its matches are not listed here.',
								'newspack-intelligence'
						  )
				}
			>
				{ ( answer ) => (
					<>
						<div className="newspack-intelligence__actions">
							<button
								type="button"
								className="button"
								onClick={ () => markShown( answer.items ) }
							>
								{ __(
									'Mark these seen',
									'newspack-intelligence'
								) }
							</button>
						</div>
						<ul className="newspack-intelligence__items">
							{ answer.items.map( ( item ) => (
								<ItemRow
									key={ item.item_key }
									item={ item }
									onOpenEntity={ onOpenEntity }
									onSave={ onSave }
								>
									<button
										type="button"
										className="button button-small"
										onClick={ () =>
											matches.hold(
												item.item_key,
												null,
												() =>
													markSeen.run( {
														key,
														item: item.item_key,
													} )
											)
										}
									>
										{ __(
											'Mark seen',
											'newspack-intelligence'
										) }
									</button>
								</ItemRow>
							) ) }
						</ul>
						<Pager
							starts={ starts }
							onStarts={ onStarts }
							shown={ answer.items.length }
							total={ answer.total }
						/>
					</>
				) }
			</Answer>
		</>
	);
}

/**
 * Watches: the caller's own watches, each with what it follows and its
 * unseen count; the one opened, with its unseen matches; and the form saving
 * a new watch or the one opened, and the control removing it, for every
 * reader. A save shows its watch and a removal takes it out from the moment
 * each is sent, until a read of `watches` reflects it; a refusal puts it
 * back. The page holds the watch opened, its page and the form's draft, so
 * a lens's "Watch this" opens the form here, and the view returns as it was
 * left.
 *
 * @param {Object}                                             props
 * @param {{key: ?string, starts: number[]}}                   props.opened       The watch opened and its page starts.
 * @param {(opened: {key: ?string, starts: number[]}) => void} props.onOpen       Opens a watch, or a page of it.
 * @param {?Object}                                            props.draft        The form's draft, or null when closed.
 * @param {(draft: ?Object) => void}                           props.onDraft      Replaces the draft; null closes the form.
 * @param {(key: string) => void}                              props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                             [props.onSave]     Saves an item; absent for a reader.
 * @return {import('react').ReactElement} The view.
 */
export default function Watches( {
	opened,
	onOpen,
	draft,
	onDraft,
	onOpenEntity,
	onSave,
} ) {
	const list = useIntelRead( 'watch:list', 'watches', [] );
	const watches = useHeld( list, 'watches', watchKey, sameWatch );
	const save = useIntelWrite( 'save_watch', {
		refresh: () => {
			onDraft( null );
			list.refresh();
		},
		onRefused: watches.refused,
	} );
	const remove = useIntelWrite( 'remove_watch', {
		refresh: () => {
			onOpen( CLOSED_WATCH );
			list.refresh();
		},
		onRefused: watches.refused,
	} );
	const watch = watches.read.answer?.watches.find(
		( one ) => one.watch_key === opened.key
	);
	const kinds = kindWords();

	return (
		<div className="newspack-intelligence__view">
			{ draft ? (
				<WatchForm
					draft={ draft }
					onDraft={ onDraft }
					onSave={ () =>
						watches.hold( draft.key, draftWatch( draft ), () =>
							save.run( watchArgs( draft ) )
						)
					}
					onCancel={ () => onDraft( null ) }
				/>
			) : (
				<div className="newspack-intelligence__create">
					<button
						type="button"
						className="button button-primary"
						onClick={ () =>
							onDraft( newWatch( { kind: 'lens', key: '' }, '' ) )
						}
					>
						{ __( 'New watch', 'newspack-intelligence' ) }
					</button>
					{ watch && (
						<>
							<button
								type="button"
								className="button"
								onClick={ () => onDraft( watchDraft( watch ) ) }
							>
								{ sprintf(
									/* translators: %s: the watch's name. */
									__( 'Edit %s', 'newspack-intelligence' ),
									watch.name
								) }
							</button>
							<button
								type="button"
								className="button"
								onClick={ () =>
									watches.hold( watch.watch_key, null, () =>
										remove.run( { key: watch.watch_key } )
									)
								}
							>
								{ sprintf(
									/* translators: %s: the watch's name. */
									__( 'Remove %s', 'newspack-intelligence' ),
									watch.name
								) }
							</button>
						</>
					) }
				</div>
			) }
			<Refusal error={ save.refusal } />
			<Refusal error={ remove.refusal } />
			<Answer
				title={ __( 'Watches', 'newspack-intelligence' ) }
				read={ watches.read }
				rows="watches"
				empty={ __( 'No watch yet.', 'newspack-intelligence' ) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.watches.map( ( one ) =>
							one.watch_key ? (
								<li key={ one.watch_key }>
									<button
										type="button"
										className={
											opened.key === one.watch_key
												? 'button button-small is-active'
												: 'button button-small'
										}
										onClick={ () =>
											onOpen( {
												key: one.watch_key,
												starts: [ 0 ],
											} )
										}
									>
										{ one.name }
									</button>{ ' ' }
									<span className="newspack-nodes-status is-muted">
										{ metaLine(
											kinds[ one.target.kind ],
											one.target.key,
											inboxText( one )
										) }
									</span>
								</li>
							) : (
								<li key={ one.name }>
									<span>{ one.name }</span>{ ' ' }
									<span className="newspack-nodes-status is-muted">
										{ __(
											'Saving…',
											'newspack-intelligence'
										) }
									</span>
								</li>
							)
						) }
					</ul>
				) }
			</Answer>
			{ watch && (
				<Opened
					key={ watch.watch_key }
					watch={ watch }
					starts={ opened.starts }
					onStarts={ ( starts ) =>
						onOpen( { key: watch.watch_key, starts } )
					}
					onSeen={ list.refresh }
					onOpenEntity={ onOpenEntity }
					onSave={ onSave }
				/>
			) }
		</div>
	);
}

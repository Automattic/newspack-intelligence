import {
	useCallback,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { speak } from '@wordpress/a11y';
import {
	Card,
	CardBody,
	CardHeader,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { Core } from '@newspack-nodes/runtime';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import {
	FOLDER_READS,
	WATCH_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { usePageRead } from '../hooks/usePageRead';
import { useDeferredWrite } from '../hooks/useDeferredWrite';
import { AnswerBody } from './Answer';
import FolderCard from './FolderCard';
import Refusal from './Refusal';
import Skeleton from './Skeleton';
import UndoSnackbar from './UndoSnackbar';
import { collectionKey, dayLabel, utcDay } from './format';
import { words } from './words';

/** The filter showing every item a folder holds. */
const ALL = 'all';

/** The filter showing the items a folder holds that carry no mark. */
const UNUSED = 'unused';

/** The most items a folder holds before its count warns. */
const CROWDED = 20;

/** The skeleton columns a wide board stands in while it loads. */
const SKELETON_COLUMNS = 3;

/**
 * A write held on the board from the moment it is deferred until a read
 * reflects it: a move, a mark or a removal of one item, `tokens` the JSON of
 * what it sent, null until it is sent.
 *
 * @typedef {Object} BoardWrite
 * @property {string}                 id     Its key in the undo window.
 * @property {'move'|'mark'|'remove'} kind   What it does.
 * @property {Object}                 item   The item, as its card showed it.
 * @property {string}                 from   The folder it is in.
 * @property {string}                 [into] The folder a move takes it into.
 * @property {string}                 [mark] The place a mark names.
 * @property {string}                 [day]  The day a mark names, Y-m-d.
 * @property {string}                 snack  The snackbar's words while it can be undone.
 * @property {?string}                tokens What it sent, as JSON, or null.
 */

/**
 * Whether a listed folder is one created by name.
 *
 * @param {Object} listed  The listed folder.
 * @param {Object} created The folder created.
 * @return {boolean} Whether they share a name.
 */
const sameName = ( listed, created ) => listed.name === created.name;

/**
 * Whether a saved item shows under a filter.
 *
 * @param {string} show The filter: ALL, UNUSED or a place.
 * @return {(item: Object) => boolean} The test.
 */
const showing = ( show ) => ( item ) => {
	if ( ALL === show ) {
		return true;
	}
	if ( UNUSED === show ) {
		return 0 === item.marks.length;
	}
	return item.marks.some( ( one ) => one.mark === show );
};

/**
 * The filters a board shows its cards under: every item, those not yet
 * used, and those used in each place, in the vocabulary's words.
 *
 * @return {Array<{label: string, value: string}>} The options.
 */
export function showOptions() {
	return [
		{ label: __( 'All', 'newspack-intelligence' ), value: ALL },
		{ label: __( 'Not yet used', 'newspack-intelligence' ), value: UNUSED },
		...Object.entries( words( 'markWords' ) ).map(
			( [ value, place ] ) => ( {
				label: sprintf(
					/* translators: %s: where an item was used. */
					__( 'Used in %s', 'newspack-intelligence' ),
					place
				),
				value,
			} )
		),
	];
}

/**
 * A saved item's marks with one made, replacing the day of the same place.
 *
 * @param {Array<{mark: string, day: string}>} marks The marks it carries.
 * @param {string}                             mark  The place.
 * @param {string}                             day   Its day.
 * @return {Array<{mark: string, day: string}>} The marks.
 */
const withMark = ( marks, mark, day ) => [
	...marks.filter( ( one ) => one.mark !== mark ),
	{ mark, day },
];

/**
 * A folder's items with the board's writes shown: each item a move or a
 * removal has taken stands where the last of them put it, the items moved in
 * first, and each carries the marks made on it.
 *
 * @param {string}       key    The folder.
 * @param {Object[]}     listed The items its read lists.
 * @param {BoardWrite[]} writes The writes held.
 * @return {Object[]} The items it shows.
 */
function shown( key, listed, writes ) {
	const placed = new Map();
	for ( const write of writes ) {
		if ( 'mark' !== write.kind ) {
			placed.set( write.item.item_key, write );
		}
	}
	const arrived = [ ...placed.values() ]
		.filter( ( write ) => 'move' === write.kind && write.into === key )
		.map( ( write ) => write.item );
	const stayed = listed.filter( ( item ) => ! placed.has( item.item_key ) );
	return [ ...arrived, ...stayed ].map( ( item ) =>
		writes
			.filter(
				( write ) =>
					'mark' === write.kind &&
					write.item.item_key === item.item_key
			)
			.reduce(
				( one, write ) => ( {
					...one,
					marks: withMark( one.marks, write.mark, write.day ),
				} ),
				item
			)
	);
}

/**
 * Whether the folders' reads reflect a sent write.
 *
 * @param {BoardWrite}            write  The write.
 * @param {Map<string, Object[]>} listed Each folder's items, as last read.
 * @return {boolean} Whether a read shows it.
 */
function reflected( write, listed ) {
	const has = ( key ) =>
		listed
			.get( key )
			?.find( ( item ) => item.item_key === write.item.item_key );
	if ( 'move' === write.kind ) {
		return Boolean( has( write.into ) ) && ! has( write.from );
	}
	if ( 'remove' === write.kind ) {
		return listed.has( write.from ) && ! has( write.from );
	}
	return Boolean(
		has( write.from )?.marks.some(
			( one ) => one.mark === write.mark && one.day === write.day
		)
	);
}

/**
 * The control creating a folder: **New folder**, which opens "Folder name"
 * and **Create folder** in its place.
 *
 * @param {Object}                 props
 * @param {(name: string) => void} props.onCreate Sends the name.
 * @return {import('react').ReactElement} The control.
 */
function NewFolder( { onCreate } ) {
	const [ open, setOpen ] = useState( false );
	const [ name, setName ] = useState( '' );
	if ( ! open ) {
		return (
			<button
				type="button"
				className="button"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'New folder', 'newspack-intelligence' ) }
			</button>
		);
	}
	return (
		<form
			className="newspack-intelligence__create"
			{ ...submitProps( () => {
				onCreate( name );
				setName( '' );
				setOpen( false );
			} ) }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Folder name', 'newspack-intelligence' ) }
				value={ name }
				onChange={ setName }
			/>
			<button type="submit" className="button button-primary">
				{ __( 'Create folder', 'newspack-intelligence' ) }
			</button>
			<button
				type="button"
				className="button"
				onClick={ () => setOpen( false ) }
			>
				{ __( 'Cancel', 'newspack-intelligence' ) }
			</button>
		</form>
	);
}

/**
 * A folder's count: its items, or "20+" in Warning text past twenty.
 *
 * @param {Object} props
 * @param {number} props.count The items it holds.
 * @return {import('react').ReactElement} The count.
 */
function FolderCount( { count } ) {
	const crowded = CROWDED < count;
	return (
		<span
			className={ `newspack-nodes-status ${
				crowded ? 'is-warning' : 'is-muted'
			} newspack-intelligence__folder-count` }
		>
			{ crowded ? `${ CROWDED }+` : count }
		</span>
	);
}

/**
 * One folder as a column: its name over its count, then its items' cards
 * under the filter, as its read lists them with the board's writes shown.
 * A card drags onto it whatever it holds.
 *
 * @param {Object} props
 * @param {Object} props.folder The listed folder.
 * @param {string} props.show   The filter.
 * @param {Object} props.board  What the board lends each column.
 * @return {import('react').ReactElement} The column.
 */
function Column( { folder, show, board } ) {
	const headingId = useId();
	const key = folder.collection_key;
	const read = useIntelRead( `collections:${ key }`, 'collections', [ key ] );
	const { onListed } = board;
	useEffect( () => {
		if ( read.answer?.items ) {
			onListed( key, read.answer.items );
		}
	}, [ read.answer, key, onListed ] );
	const items = read.answer?.items
		? shown( key, read.answer.items, board.writes )
		: null;
	return (
		<Card
			as="section"
			size="small"
			aria-labelledby={ headingId }
			className="newspack-intelligence__folder-column"
			onDragEnter={ () => board.over( folder ) }
			onDragOver={ board.allowDrop }
			onDrop={ ( event ) => board.drop( event, key ) }
		>
			<CardHeader>
				<h3 id={ headingId }>{ folder.name }</h3>
				<FolderCount count={ items ? items.length : folder.items } />
			</CardHeader>
			<CardBody>
				<AnswerBody read={ read } skeleton={ <Skeleton count={ 3 } /> }>
					{ () => {
						if ( 0 === items.length ) {
							return (
								<p className="newspack-nodes-empty-state is-quiet">
									{ __(
										'Drag items here, or use Save on any item.',
										'newspack-intelligence'
									) }
								</p>
							);
						}
						const cards = items.filter( showing( show ) );
						if ( 0 === cards.length ) {
							return (
								<p className="newspack-nodes-empty-state is-quiet">
									{ UNUSED === show
										? __(
												'Every item here has been used.',
												'newspack-intelligence'
										  )
										: __(
												'No item here carries this mark.',
												'newspack-intelligence'
										  ) }
								</p>
							);
						}
						return (
							<div className="newspack-intelligence__folder-cards">
								{ cards.map( ( item ) => (
									<FolderCard
										key={ item.item_key }
										{ ...board.cardProps( item, key ) }
									/>
								) ) }
							</div>
						);
					} }
				</AnswerBody>
			</CardBody>
		</Card>
	);
}

/**
 * A folder created and not yet listed, saving.
 *
 * @param {Object} props
 * @param {string} props.name Its name.
 * @return {import('react').ReactElement} The column.
 */
function SavingColumn( { name } ) {
	const headingId = useId();
	return (
		<Card
			as="section"
			size="small"
			aria-labelledby={ headingId }
			className="newspack-intelligence__folder-column"
		>
			<CardHeader>
				<h3 id={ headingId }>{ name }</h3>
			</CardHeader>
			<CardBody>
				<p className="newspack-nodes-status is-muted">
					{ __( 'Saving…', 'newspack-intelligence' ) }
				</p>
			</CardBody>
		</Card>
	);
}

/**
 * Columns of three skeleton cards while the folders load: three on a wide
 * board, one when narrow.
 *
 * @param {Object}  props
 * @param {boolean} props.narrow Whether the board is narrow.
 * @return {import('react').ReactElement} The columns.
 */
function SkeletonBoard( { narrow } ) {
	return (
		<div className="newspack-intelligence__folder-board">
			{ Array.from(
				{ length: narrow ? 1 : SKELETON_COLUMNS },
				( _, n ) => (
					<Card
						key={ n }
						size="small"
						className="newspack-intelligence__folder-column"
					>
						<CardBody>
							<Skeleton count={ 3 } />
						</CardBody>
					</Card>
				)
			) }
		</div>
	);
}

/**
 * The board's writes: each move, mark and removal held on the board from
 * the moment it is made, deferred with Undo (`useDeferredWrite`), sent after
 * the window and held until a read reflects it; a refusal returns the card
 * with the refusal on it.
 *
 * @param {(key: string) => string} nameOf A folder's name by its key.
 * @return {Object} The writes held, the controls, and the snackbar's props.
 */
function useBoardWrites( nameOf ) {
	const deferred = useDeferredWrite();
	const [ writes, setWrites ] = useState( [] );
	const [ refused, setRefused ] = useState( [] );
	const [ focus, setFocus ] = useState( null );
	const held = useRef( writes );
	held.current = writes;
	const listed = useRef( new Map() );
	const sequence = useRef( 0 );

	const refusedBy = ( kind ) => ( tokens, error ) => {
		const sent = JSON.stringify( tokens );
		const gone = held.current.filter(
			( write ) =>
				write.kind === kind &&
				null !== write.tokens &&
				( 0 === tokens.length || write.tokens === sent )
		);
		setWrites( ( was ) => was.filter( ( one ) => ! gone.includes( one ) ) );
		const keys = gone.map( ( write ) => write.item.item_key );
		setRefused( ( was ) => [
			...was.filter( ( [ key ] ) => ! keys.includes( key ) ),
			...keys.map( ( key ) => [ key, error ] ),
		] );
	};
	const writers = {
		move: useIntelWrite( 'move_item', {
			dirties: [ ...FOLDER_READS, ...WATCH_READS ],
			onRefused: refusedBy( 'move' ),
		} ),
		mark: useIntelWrite( 'mark_used', {
			dirties: FOLDER_READS,
			onRefused: refusedBy( 'mark' ),
		} ),
		remove: useIntelWrite( 'uncollect', {
			dirties: [ ...FOLDER_READS, ...WATCH_READS ],
			onRefused: refusedBy( 'remove' ),
		} ),
	};
	const runs = useRef( writers );
	runs.current = writers;

	const add = ( write, announced, args ) => {
		sequence.current += 1;
		const one = { ...write, id: `${ sequence.current }`, tokens: null };
		setRefused( ( was ) =>
			was.filter( ( [ key ] ) => key !== write.item.item_key )
		);
		setWrites( ( was ) => [ ...was, one ] );
		deferred.defer(
			one.id,
			() => {
				const tokens = JSON.stringify(
					runs.current[ write.kind ].run( args )
				);
				setWrites( ( was ) =>
					was.map( ( each ) =>
						each.id === one.id ? { ...each, tokens } : each
					)
				);
			},
			announced
		);
	};

	const onListed = useCallback( ( key, items ) => {
		listed.current.set( key, items );
		setWrites( ( was ) => {
			const kept = was.filter(
				( write ) =>
					null === write.tokens ||
					! reflected( write, listed.current )
			);
			return kept.length === was.length ? was : kept;
		} );
	}, [] );

	const move = ( item, from, into ) => {
		if ( from === into ) {
			return;
		}
		setFocus( { key: into, item_key: item.item_key } );
		add(
			{
				kind: 'move',
				item,
				from,
				into,
				snack: sprintf(
					/* translators: %s: the folder it moved into. */
					__( 'Moved to %s.', 'newspack-intelligence' ),
					nameOf( into )
				),
			},
			sprintf(
				/* translators: 1: the item's title, 2: the folder it left, 3: the folder it entered. */
				__( 'Moved %1$s from %2$s to %3$s.', 'newspack-intelligence' ),
				item.title || item.item_key,
				nameOf( from ),
				nameOf( into )
			),
			{ collection: from, item: item.item_key, into }
		);
	};

	const mark = ( item, from, place, day ) => {
		const said = sprintf(
			/* translators: 1: where it was used, 2: the day, as "5 Nov". */
			__( 'Marked as used in %1$s on %2$s.', 'newspack-intelligence' ),
			words( 'markWords' )[ place ] ?? place,
			dayLabel( day, utcDay( Math.floor( Core.now() ) ) )
		);
		add(
			{ kind: 'mark', item, from, mark: place, day, snack: said },
			said,
			{ collection: from, item: item.item_key, mark: place, day }
		);
	};

	const remove = ( item, from ) => {
		const said = sprintf(
			/* translators: %s: the folder it left. */
			__( 'Removed from %s.', 'newspack-intelligence' ),
			nameOf( from )
		);
		add( { kind: 'remove', item, from, snack: said }, said, {
			collection: from,
			item: item.item_key,
		} );
	};

	const undo = () => {
		const id = deferred.undo();
		setWrites( ( was ) => was.filter( ( one ) => one.id !== id ) );
	};

	const pending =
		deferred.latest &&
		writes.find( ( one ) => one.id === deferred.latest.key );
	const refusalOf = ( key ) =>
		refused.find( ( [ item ] ) => item === key )?.[ 1 ] ?? null;
	return {
		writes,
		focus,
		onListed,
		move,
		mark,
		remove,
		refusalOf,
		snackbar: {
			latest: pending
				? { id: deferred.latest.id, message: pending.snack }
				: null,
			onUndo: undo,
		},
	};
}

/**
 * The Folders board: a column per folder, every one or those `folders`
 * names, each of its saved items a card, the filter "Show" opening on
 * `show`. For a curator a card drags into another column, announced in the
 * live region, and its menu moves it in one action, marks it used or
 * removes it; each is deferred with Undo, and a move lands focus on the card
 * in its new column. A folder over twenty items warns and still takes
 * items. When narrow the columns are a select showing one at a time.
 * **New folder** creates one, shown saving until a read lists it.
 *
 * @param {Object}   props
 * @param {string}   props.show    The filter it opens on: "all", "unused" or a place.
 * @param {string[]} props.folders The folders shown, every one when empty.
 * @param {boolean}  props.canTune Whether to offer the writes.
 * @return {import('react').ReactElement} The board.
 */
export default function FolderBoard( { show: opensOn, folders, canTune } ) {
	const headingId = useId();
	const narrow = useNarrow();
	const [ show, setShow ] = useState( opensOn );
	useEffect( () => setShow( opensOn ), [ opensOn ] );
	const [ chosen, setChosen ] = useState( '' );
	const list = usePageRead( 'folders' );
	const listed = useHeld( list, 'collections', collectionKey, sameName );
	const create = useIntelWrite( 'create_collection', {
		dirties: FOLDER_READS,
		onRefused: listed.refused,
	} );
	const every = listed.read.answer?.collections ?? [];
	const nameOf = ( key ) =>
		every.find( ( one ) => one.collection_key === key )?.name ?? key;
	const writes = useBoardWrites( nameOf );
	const dragged = useRef( null );
	const overFolder = useRef( null );

	const onCreate = ( name ) =>
		listed.hold(
			name,
			{ collection_key: null, name, created_ts: null, items: 0 },
			() => create.run( { name } )
		);
	const board = {
		writes: writes.writes,
		onListed: writes.onListed,
		over: ( folder ) => {
			if (
				dragged.current &&
				overFolder.current !== folder.collection_key
			) {
				overFolder.current = folder.collection_key;
				speak(
					sprintf(
						/* translators: %s: the folder a dragged card is over. */
						__( 'Over %s.', 'newspack-intelligence' ),
						folder.name
					),
					'polite'
				);
			}
		},
		allowDrop: ( event ) => {
			if ( dragged.current ) {
				event.preventDefault();
			}
		},
		drop: ( event, into ) => {
			const was = dragged.current;
			dragged.current = null;
			if ( was ) {
				event.preventDefault();
				writes.move( was.item, was.from, into );
			}
		},
		cardProps: ( item, from ) => ( {
			item,
			others: every.filter(
				( one ) => one.collection_key && one.collection_key !== from
			),
			canTune,
			focused:
				writes.focus?.key === from &&
				writes.focus?.item_key === item.item_key,
			refusal: writes.refusalOf( item.item_key ),
			onPickUp: ( event ) => {
				dragged.current = { item, from };
				overFolder.current = from;
				event.dataTransfer?.setData( 'text/plain', item.item_key );
				if ( event.dataTransfer ) {
					event.dataTransfer.effectAllowed = 'move';
				}
				speak(
					sprintf(
						/* translators: %s: the title of the card picked up. */
						__( 'Picked up %s.', 'newspack-intelligence' ),
						item.title || item.item_key
					),
					'polite'
				);
			},
			onPutDown: () => {
				dragged.current = null;
				overFolder.current = null;
			},
			onMove: ( into ) => writes.move( item, from, into ),
			onMark: ( place, day ) => writes.mark( item, from, place, day ),
			onRemove: () => writes.remove( item, from ),
		} ),
	};

	const column = ( folder ) =>
		folder.collection_key ? (
			<Column
				key={ folder.collection_key }
				folder={ folder }
				show={ show }
				board={ board }
			/>
		) : (
			<SavingColumn key={ folder.name } name={ folder.name } />
		);

	return (
		<section
			className="newspack-intelligence__folders"
			aria-labelledby={ headingId }
		>
			<div className="newspack-intelligence__section-head">
				<h2 id={ headingId }>
					{ __( 'Folders', 'newspack-intelligence' ) }
				</h2>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __( 'Show', 'newspack-intelligence' ) }
					value={ show }
					onChange={ setShow }
					options={ showOptions() }
				/>
			</div>
			<Refusal error={ create.refusal } />
			<AnswerBody
				read={ listed.read }
				rows="collections"
				skeleton={ <SkeletonBoard narrow={ narrow } /> }
				empty={
					<>
						{ __(
							'No folders yet. Create one, then use Save on any item to file it here.',
							'newspack-intelligence'
						) }{ ' ' }
						{ canTune && <NewFolder onCreate={ onCreate } /> }
					</>
				}
			>
				{ () => {
					const shownFolders = every.filter(
						( one ) =>
							0 === folders.length ||
							folders.includes( one.collection_key )
					);
					const keyed = shownFolders.filter( collectionKey );
					const open =
						keyed.find(
							( one ) => one.collection_key === chosen
						) ?? keyed[ 0 ];
					return narrow ? (
						<>
							<SelectControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								label={ __(
									'Folder',
									'newspack-intelligence'
								) }
								value={ open?.collection_key ?? '' }
								onChange={ setChosen }
								options={ keyed.map( ( one ) => ( {
									label: sprintf(
										/* translators: 1: a folder's name, 2: the items it holds. */
										__(
											'%1$s (%2$d)',
											'newspack-intelligence'
										),
										one.name,
										one.items
									),
									value: one.collection_key,
								} ) ) }
							/>
							{ open && column( open ) }
							{ canTune && <NewFolder onCreate={ onCreate } /> }
						</>
					) : (
						<div className="newspack-intelligence__folder-board">
							{ shownFolders.map( column ) }
							{ canTune && (
								<Card
									size="small"
									className="newspack-intelligence__folder-column is-new"
								>
									<CardBody>
										<NewFolder onCreate={ onCreate } />
									</CardBody>
								</Card>
							) }
						</div>
					);
				} }
			</AnswerBody>
			<UndoSnackbar { ...writes.snackbar } />
		</section>
	);
}

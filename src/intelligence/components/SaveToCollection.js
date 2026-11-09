import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import {
	FOLDER_READS,
	WATCH_READS,
	useIntelRead,
	useIntelWrite,
} from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import { AnswerBody } from './Answer';
import Refusal from './Refusal';

/** The option naming the collection a save sends. */
const COLLECTION = '--collection=';

/**
 * A listed folder's key.
 *
 * @param {{collection_key: string}} folder The folder.
 * @return {string} Its key.
 */
const collectionKey = ( folder ) => folder.collection_key;

/**
 * Whether a listed folder is the one held.
 *
 * @param {{collection_key: string}} listed The listed folder.
 * @param {{collection_key: string}} held   The folder held.
 * @return {boolean} Whether they share a key.
 */
const sameKey = ( listed, held ) =>
	listed.collection_key === held.collection_key;

/**
 * The folders as select options: those the reader saved or moved into
 * lately first, as the read orders them, under their own group, then every
 * other; one list alone while the reader has saved into none.
 *
 * @param {Object[]} collections The folders, as `collections` lists them.
 * @return {import('react').ReactNode} The options.
 */
function folderOptions( collections ) {
	const option = ( c ) => (
		<option key={ c.collection_key } value={ c.collection_key }>
			{ c.name }
		</option>
	);
	const recent = collections.filter( ( c ) => null !== c.recent_ts );
	if ( 0 === recent.length ) {
		return collections.map( option );
	}
	return (
		<>
			<optgroup label={ __( 'Recent', 'newspack-intelligence' ) }>
				{ recent.map( option ) }
			</optgroup>
			<optgroup
				label={ __( 'Every other folder', 'newspack-intelligence' ) }
			>
				{ collections
					.filter( ( c ) => null === c.recent_ts )
					.map( option ) }
			</optgroup>
		</>
	);
}

/**
 * The dialog every result saves an item to a folder through: it offers the
 * reader's recent folders first, the latest chosen, sends `collect` with the
 * one chosen, and says where the item went or why it did not. **New folder**
 * opens a name field in place, and **Create and save** sends
 * `create_collection`, then `collect` into the folder once the create lands,
 * and returns to the select with the new folder chosen and the field cleared,
 * the folder held in the list (`useHeld`) until a read lists it; with no
 * folder at all the dialog opens on that field. Its close button
 * holds focus until the list loads, then the folder select or the name field.
 *
 * @param {Object}     props
 * @param {Object}     props.item    The item saved.
 * @param {() => void} props.onClose Dismisses the dialog.
 * @return {import('react').ReactElement} The dialog.
 */
export default function SaveToCollection( { item, onClose } ) {
	const list = useIntelRead( 'save:collections', 'collections', [] );
	const folders = useHeld( list, 'collections', collectionKey, sameKey );
	const [ chosen, setChosen ] = useState( '' );
	const [ asked, setAsked ] = useState( false );
	const [ name, setName ] = useState( '' );
	const collect = useIntelWrite( 'collect', {
		dirties: [ ...FOLDER_READS, ...WATCH_READS ],
	} );
	const save = ( key ) =>
		collect.run( { collection: key, item: item.item_key } );
	const create = useIntelWrite( 'create_collection', {
		scope: 'save:create_collection',
		dirties: FOLDER_READS,
		refresh: ( { collection_key: key } ) => {
			setChosen( key );
			setAsked( false );
			setName( '' );
			folders.hold(
				key,
				{
					collection_key: key,
					name,
					created_ts: null,
					items: 1,
					recent_ts: null,
				},
				() => save( key )
			);
		},
	} );
	// The collection the last landed save named, read off its echoed option.
	const saved =
		! collect.refusal &&
		collect.answeredArgs
			?.find( ( token ) => token.startsWith( COLLECTION ) )
			?.slice( COLLECTION.length );
	const collections = folders.read.answer?.collections ?? [];
	const closeRef = useRef( null );
	const selectRef = useRef( null );
	const nameRef = useRef( null );
	const loaded = 0 < collections.length;
	const none = !! list.answer && false !== list.answer.available && ! loaded;
	const naming = asked || none;

	useEffect( () => {
		let focused = closeRef;
		if ( naming ) {
			focused = nameRef;
		} else if ( loaded ) {
			focused = selectRef;
		}
		focused.current?.focus();
	}, [ loaded, naming ] );
	const target = chosen || collections[ 0 ]?.collection_key || '';
	const nameOf = ( key ) =>
		collections.find( ( c ) => c.collection_key === key )?.name ?? key;

	return (
		<Modal
			onClose={ onClose }
			onSubmit={ () =>
				naming ? create.run( { name } ) : save( target )
			}
		>
			<ModalHeader
				title={ __( 'Save to folder', 'newspack-intelligence' ) }
				onClose={ onClose }
				closeRef={ closeRef }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<p>{ item.title || item.item_key }</p>
			{ naming ? (
				<>
					{ none && (
						<p>
							{ __(
								'Make your first folder to save this item.',
								'newspack-intelligence'
							) }
						</p>
					) }
					<TextControl
						ref={ nameRef }
						__next40pxDefaultSize
						label={ __( 'Folder name', 'newspack-intelligence' ) }
						value={ name }
						onChange={ setName }
					/>
					<div className="newspack-nodes-modal__actions">
						<button
							type="submit"
							className="button button-primary"
							disabled={ create.pending }
						>
							{ __( 'Create and save', 'newspack-intelligence' ) }
						</button>
					</div>
				</>
			) : (
				<AnswerBody read={ folders.read }>
					{ () => (
						<>
							<SelectControl
								ref={ selectRef }
								__next40pxDefaultSize
								label={ __(
									'Folder',
									'newspack-intelligence'
								) }
								value={ target }
								onChange={ setChosen }
							>
								{ folderOptions( collections ) }
							</SelectControl>
							<div className="newspack-nodes-modal__actions">
								<button
									type="button"
									className="button"
									onClick={ () => setAsked( true ) }
								>
									{ __(
										'New folder',
										'newspack-intelligence'
									) }
								</button>
								<button
									type="submit"
									className="button button-primary"
								>
									{ __( 'Save', 'newspack-intelligence' ) }
								</button>
							</div>
						</>
					) }
				</AnswerBody>
			) }
			<Refusal error={ create.refusal } />
			<Refusal error={ collect.refusal } />
			{ saved && (
				<p className="newspack-nodes-status is-success" role="status">
					{ sprintf(
						/* translators: %s: the folder's name. */
						__( 'Saved to %s.', 'newspack-intelligence' ),
						nameOf( saved )
					) }
				</p>
			) }
		</Modal>
	);
}

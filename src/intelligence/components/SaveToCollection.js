import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import Modal from '@newspack-nodes/shared/components/Modal';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { AnswerBody } from './Answer';
import Refusal from './Refusal';

/** The option naming the collection a save sends. */
const COLLECTION = '--collection=';

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
 * one chosen, and says where the item went or why it did not. Its close
 * button holds focus until the list loads, and the folder select from then
 * on.
 *
 * @param {Object}     props
 * @param {Object}     props.item    The item saved.
 * @param {() => void} props.onClose Dismisses the dialog.
 * @return {import('react').ReactElement} The dialog.
 */
export default function SaveToCollection( { item, onClose } ) {
	const list = useIntelRead( 'save:collections', 'collections', [] );
	const [ chosen, setChosen ] = useState( '' );
	const collect = useIntelWrite( 'collect' );
	// The collection the last landed save named, read off its echoed option.
	const saved =
		! collect.refusal &&
		collect.answeredArgs
			?.find( ( token ) => token.startsWith( COLLECTION ) )
			?.slice( COLLECTION.length );
	const collections = list.answer?.collections ?? [];
	const closeRef = useRef( null );
	const selectRef = useRef( null );
	const loaded = 0 < collections.length;

	useEffect( () => {
		( loaded ? selectRef : closeRef ).current?.focus();
	}, [ loaded ] );
	const target = chosen || collections[ 0 ]?.collection_key || '';
	const nameOf = ( key ) =>
		collections.find( ( c ) => c.collection_key === key )?.name ?? key;

	return (
		<Modal
			ariaLabel={ __( 'Save to folder', 'newspack-intelligence' ) }
			onClose={ onClose }
			onSubmit={ () =>
				collect.run( { collection: target, item: item.item_key } )
			}
		>
			<header className="newspack-nodes-modal__header">
				<span className="newspack-nodes-modal__title">
					{ __( 'Save to folder', 'newspack-intelligence' ) }
				</span>
				<button
					type="button"
					className="newspack-nodes-modal__close"
					ref={ closeRef }
					aria-label={ __( 'Close', 'newspack-intelligence' ) }
					onClick={ onClose }
				>
					{ '×' }
				</button>
			</header>
			<p>{ item.title || item.item_key }</p>
			<AnswerBody
				read={ list }
				rows="collections"
				empty={ __(
					'No folder yet. Create one under Collections first.',
					'newspack-intelligence'
				) }
			>
				{ () => (
					<>
						<SelectControl
							ref={ selectRef }
							__next40pxDefaultSize
							label={ __( 'Folder', 'newspack-intelligence' ) }
							value={ target }
							onChange={ setChosen }
						>
							{ folderOptions( collections ) }
						</SelectControl>
						<div className="newspack-nodes-modal__actions">
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

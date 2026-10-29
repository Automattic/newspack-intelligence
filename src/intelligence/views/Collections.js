import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
import { useHeld } from '../hooks/useHeld';
import Answer from '../components/Answer';
import Cite from '../components/Cite';
import Refusal from '../components/Refusal';
import { metaLine, utcDay } from '../components/format';

/**
 * The form a curator creates a collection with.
 *
 * @param {Object}                 props
 * @param {(name: string) => void} props.onCreate Sends the name.
 * @return {import('react').ReactElement} The form.
 */
function CreateForm( { onCreate } ) {
	const [ name, setName ] = useState( '' );
	return (
		<form
			className="newspack-intelligence__create"
			{ ...submitProps( () => onCreate( name ) ) }
		>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Name', 'newspack-intelligence' ) }
				value={ name }
				onChange={ setName }
			/>
			<button type="submit" className="button button-primary">
				{ __( 'Create collection', 'newspack-intelligence' ) }
			</button>
		</form>
	);
}

/**
 * One collection's saved items, each citing its URL.
 *
 * @param {Object} props
 * @param {Object} props.collection The listed collection opened.
 * @return {import('react').ReactElement} The items.
 */
function Opened( { collection } ) {
	const read = useIntelRead( 'collections:one', 'collections', [
		collection.collection_key,
	] );
	return (
		<Answer
			title={ collection.name }
			level={ 3 }
			read={ read }
			rows="items"
			empty={ __(
				'This collection holds no item you can read.',
				'newspack-intelligence'
			) }
		>
			{ ( answer ) => (
				<ul className="newspack-intelligence__facts">
					{ answer.items.map( ( item ) => (
						<li key={ item.item_key }>
							<Cite url={ item.url } title={ item.title } />{ ' ' }
							<span className="newspack-nodes-status is-muted">
								{ item.actor }
							</span>
						</li>
					) ) }
				</ul>
			) }
		</Answer>
	);
}

/**
 * A listed collection's key.
 *
 * @param {Object} collection The collection.
 * @return {string} Its key.
 */
const collectionKey = ( collection ) => collection.collection_key;

/**
 * Whether a listed collection is one created by name.
 *
 * @param {Object} listed  The listed collection.
 * @param {Object} created The collection created.
 * @return {boolean} Whether they share a name.
 */
const sameName = ( listed, created ) => listed.name === created.name;

/**
 * Collections: every collection with its count; the one opened, with its
 * saved items; and for a curator, the form creating one. A collection
 * created shows, saving and unopened, from the moment it is sent until a
 * read lists it by name, since the projector records it seconds after it
 * lands; a refusal takes it out. One that lands lists again. The page holds
 * the one opened, so it outlives a visit to another view.
 *
 * @param {Object}                       props
 * @param {boolean}                      props.canTune Whether to offer the create form.
 * @param {?Object}                      props.opened  The listed collection opened, or null.
 * @param {(collection: Object) => void} props.onOpen  Opens a listed collection.
 * @return {import('react').ReactElement} The view.
 */
export default function Collections( { canTune, opened, onOpen } ) {
	const list = useIntelRead( 'collections:list', 'collections', [] );
	const listed = useHeld( list, 'collections', collectionKey, sameName );
	const create = useIntelWrite( 'create_collection', {
		refresh: list.refresh,
		onRefused: listed.refused,
	} );

	return (
		<div className="newspack-intelligence__view">
			{ canTune && (
				<CreateForm
					onCreate={ ( name ) =>
						listed.hold(
							name,
							{
								collection_key: null,
								name,
								created_ts: null,
								items: 0,
							},
							() => create.run( { name } )
						)
					}
				/>
			) }
			<Refusal error={ create.refusal } />
			<Answer
				title={ __( 'Collections', 'newspack-intelligence' ) }
				read={ listed.read }
				rows="collections"
				empty={ __( 'No collection yet.', 'newspack-intelligence' ) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.collections.map( ( collection ) =>
							collection.collection_key ? (
								<li key={ collection.collection_key }>
									<button
										type="button"
										className={
											opened?.collection_key ===
											collection.collection_key
												? 'button button-small is-active'
												: 'button button-small'
										}
										onClick={ () => onOpen( collection ) }
									>
										{ collection.name }
									</button>{ ' ' }
									<span className="newspack-nodes-status is-muted">
										{ metaLine(
											sprintf(
												/* translators: %d: items saved. */
												_n(
													'%d item',
													'%d items',
													collection.items,
													'newspack-intelligence'
												),
												collection.items
											),
											utcDay( collection.created_ts )
										) }
									</span>
								</li>
							) : (
								<li key={ collection.name }>
									<span>{ collection.name }</span>{ ' ' }
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
			{ opened && <Opened collection={ opened } /> }
		</div>
	);
}

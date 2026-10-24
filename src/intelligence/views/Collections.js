import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { useIntelRead, useIntelWrite } from '../hooks/useIntelCommand';
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
 * Collections: every collection with its count; the one opened, with its
 * saved items; and for a curator, the form creating one, which lists again
 * once it lands. The page holds the one opened, so it outlives a visit to
 * another view.
 *
 * @param {Object}                       props
 * @param {boolean}                      props.canTune Whether to offer the create form.
 * @param {?Object}                      props.opened  The listed collection opened, or null.
 * @param {(collection: Object) => void} props.onOpen  Opens a listed collection.
 * @return {import('react').ReactElement} The view.
 */
export default function Collections( { canTune, opened, onOpen } ) {
	const list = useIntelRead( 'collections:list', 'collections', [] );
	const create = useIntelWrite( 'create_collection', {
		refresh: list.refresh,
	} );

	return (
		<div className="newspack-intelligence__view">
			{ canTune && (
				<CreateForm onCreate={ ( name ) => create.run( { name } ) } />
			) }
			<Refusal error={ create.refusal } />
			<Answer
				title={ __( 'Collections', 'newspack-intelligence' ) }
				read={ list }
				rows="collections"
				empty={ __( 'No collection yet.', 'newspack-intelligence' ) }
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__facts">
						{ answer.collections.map( ( collection ) => (
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
						) ) }
					</ul>
				) }
			</Answer>
			{ opened && <Opened collection={ opened } /> }
		</div>
	);
}

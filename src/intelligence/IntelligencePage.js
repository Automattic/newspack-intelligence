import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DebugOverlay from '@newspack-nodes/debug-overlay';
import Search, { NEW_SEARCH } from './views/Search';
import Entity from './views/Entity';
import Review from './views/Review';
import Collections from './views/Collections';
import SaveToCollection from './components/SaveToCollection';
import './styles/intelligence.scss';

/**
 * The Intelligence page: Search, Entity, Review and Collections, switched in
 * place. Each view reads its own verbs; the page holds which view shows, the
 * submitted search, the open entity and collection, and the item being saved,
 * so a view returns as it was left. Curation renders only
 * for `canTune`, which hides controls and enforces nothing: the server's role
 * gate refuses a write the caller may not make.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune    Whether the caller may curate.
 * @param {Object}  props.vocabulary The ontology's closed lists.
 * @return {import('react').ReactElement} The page.
 */
export default function IntelligencePage( { canTune, vocabulary } ) {
	const [ view, setView ] = useState( 'search' );
	const [ entityKey, setEntityKey ] = useState( null );
	const [ saving, setSaving ] = useState( null );
	const [ search, setSearch ] = useState( NEW_SEARCH );
	const [ collection, setCollection ] = useState( null );

	const openEntity = ( key ) => {
		setEntityKey( key );
		setView( 'entity' );
	};
	const onSave = canTune ? setSaving : undefined;

	const tabs = [
		[ 'search', __( 'Search', 'newspack-intelligence' ) ],
		...( entityKey
			? [
					[
						'entity',
						sprintf(
							/* translators: %s: the open entity's key. */
							__( 'Entity: %s', 'newspack-intelligence' ),
							entityKey
						),
					],
			  ]
			: [] ),
		[ 'review', __( 'Review', 'newspack-intelligence' ) ],
		[ 'collections', __( 'Collections', 'newspack-intelligence' ) ],
	];

	return (
		<div className="newspack-intelligence newspack-nodes-theme newspack-nodes-skin-root newspack-nodes-ui">
			<h1>{ __( 'Intelligence', 'newspack-intelligence' ) }</h1>
			<div
				className="nodes-tab-host__tabbar"
				role="tablist"
				aria-label={ __( 'Views', 'newspack-intelligence' ) }
			>
				{ tabs.map( ( [ id, label ] ) => (
					<button
						key={ id }
						type="button"
						role="tab"
						className={ `nodes-tab-host__tab${
							view === id ? ' is-active' : ''
						}` }
						aria-selected={ view === id }
						onClick={ () => setView( id ) }
					>
						{ label }
					</button>
				) ) }
			</div>
			{ 'search' === view && (
				<Search
					query={ search }
					onQuery={ setSearch }
					vocabulary={ vocabulary }
					onOpenEntity={ openEntity }
					onSave={ onSave }
				/>
			) }
			{ 'entity' === view && (
				<Entity
					key={ entityKey }
					entityKey={ entityKey }
					canTune={ canTune }
					vocabulary={ vocabulary }
					onOpenEntity={ openEntity }
					onSave={ onSave }
				/>
			) }
			{ 'review' === view && (
				<Review canTune={ canTune } onOpenEntity={ openEntity } />
			) }
			{ 'collections' === view && (
				<Collections
					canTune={ canTune }
					opened={ collection }
					onOpen={ setCollection }
				/>
			) }
			{ saving && (
				<SaveToCollection
					item={ saving }
					onClose={ () => setSaving( null ) }
				/>
			) }
			<DebugOverlay storageKey="newspack-nodes:debug:intelligence" />
		</div>
	);
}

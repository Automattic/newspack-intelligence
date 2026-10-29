import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DashboardShell from '@newspack-nodes/shared/components/DashboardShell';
import TabBar, {
	panelDomId,
	tabDomId,
} from '@newspack-nodes/shared/tabs/TabBar';
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
 * The shared `DashboardShell` supplies the skin, the positioned page surface,
 * the header naming this page and the debug overlay; the page scrolls in the
 * shell, since no view owns a scroller of its own.
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
		{ id: 'search', label: __( 'Search', 'newspack-intelligence' ) },
		...( entityKey
			? [
					{
						id: 'entity',
						label: sprintf(
							/* translators: %s: the open entity's key. */
							__( 'Entity: %s', 'newspack-intelligence' ),
							entityKey
						),
					},
			  ]
			: [] ),
		{ id: 'review', label: __( 'Review', 'newspack-intelligence' ) },
		{
			id: 'collections',
			label: __( 'Collections', 'newspack-intelligence' ),
		},
	];

	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:intelligence"
			subtitle={ __( 'Intelligence', 'newspack-intelligence' ) }
			overflowY="auto"
		>
			{ () => (
				<div className="newspack-intelligence">
					<TabBar
						scope="intelligence"
						label={ __( 'Views', 'newspack-intelligence' ) }
						tabs={ tabs }
						activeId={ view }
						onPick={ setView }
					/>
					<div
						id={ panelDomId( 'intelligence' ) }
						role="tabpanel"
						aria-labelledby={ tabDomId( 'intelligence', view ) }
					>
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
							<Review
								canTune={ canTune }
								vocabulary={ vocabulary }
								onOpenEntity={ openEntity }
							/>
						) }
						{ 'collections' === view && (
							<Collections
								canTune={ canTune }
								opened={ collection }
								onOpen={ setCollection }
							/>
						) }
					</div>
					{ saving && (
						<SaveToCollection
							item={ saving }
							onClose={ () => setSaving( null ) }
						/>
					) }
				</div>
			) }
		</DashboardShell>
	);
}

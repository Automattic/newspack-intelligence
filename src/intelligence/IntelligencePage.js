import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DashboardShell from '@newspack-nodes/shared/components/DashboardShell';
import { getQueryParam } from '@newspack-nodes/shared/utils/queryParams';
import TabBar, {
	panelDomId,
	tabDomId,
} from '@newspack-nodes/shared/tabs/TabBar';
import Search, { newSearch } from './views/Search';
import Entity from './views/Entity';
import Review from './views/Review';
import Collections from './views/Collections';
import Lens, { CLOSED_LENS } from './views/Lens';
import Watches, { CLOSED_WATCH, newWatch } from './views/Watches';
import SaveToCollection from './components/SaveToCollection';
import './styles/intelligence.scss';

/**
 * The watch a link opens the page on, as the watcher's email names it
 * (`&watch=<key>`), or null.
 *
 * @return {?string} The watch's key.
 */
const linkedWatch = () => getQueryParam( 'watch' ) || null;

/**
 * The Intelligence page: Search, Entity, Lenses, Review, Collections and
 * Watches, switched in place. Each view reads its own verbs; the page holds
 * which view shows, the submitted search, the open entity, lens, collection
 * and watch, the watch form's draft and the item being saved, so a view
 * returns as it was left, and a lens's "Watch this" opens the form on it.
 * Curation renders only for `canTune`, which hides controls and enforces
 * nothing: the server's role gate refuses a write the caller may not make.
 * Watches are every reader's own, so they are not gated on it. A link naming
 * a watch opens the page on Watches with that watch open; a key the reader's
 * watches do not hold opens nothing, leaving the list.
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
	const [ view, setView ] = useState( () =>
		linkedWatch() ? 'watches' : 'search'
	);
	const [ entityKey, setEntityKey ] = useState( null );
	const [ saving, setSaving ] = useState( null );
	const [ search, setSearch ] = useState( () =>
		newSearch( vocabulary.lensLists )
	);
	const [ collection, setCollection ] = useState( null );
	const [ lens, setLens ] = useState( CLOSED_LENS );
	const [ watch, setWatch ] = useState( () => ( {
		...CLOSED_WATCH,
		key: linkedWatch(),
	} ) );
	const [ watchDraft, setWatchDraft ] = useState( null );

	const openEntity = ( key ) => {
		setEntityKey( key );
		setView( 'entity' );
	};
	const onSave = canTune ? setSaving : undefined;
	const openWatchForm = ( target, name ) => {
		setWatchDraft( newWatch( target, name ) );
		setView( 'watches' );
	};

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
		{ id: 'lenses', label: __( 'Lenses', 'newspack-intelligence' ) },
		{ id: 'review', label: __( 'Review', 'newspack-intelligence' ) },
		{
			id: 'collections',
			label: __( 'Collections', 'newspack-intelligence' ),
		},
		{ id: 'watches', label: __( 'Watches', 'newspack-intelligence' ) },
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
						{ 'lenses' === view && (
							<Lens
								canTune={ canTune }
								vocabulary={ vocabulary }
								opened={ lens }
								onOpen={ setLens }
								onOpenEntity={ openEntity }
								onWatch={ openWatchForm }
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
						{ 'watches' === view && (
							<Watches
								opened={ watch }
								onOpen={ setWatch }
								draft={ watchDraft }
								onDraft={ setWatchDraft }
								onOpenEntity={ openEntity }
								onSave={ onSave }
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

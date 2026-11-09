import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DashboardShell from '@newspack-nodes/shared/components/DashboardShell';
import { getQueryParam } from '@newspack-nodes/shared/utils/queryParams';
import { useQueryParamState } from '@newspack-nodes/shared/hooks/useQueryParamState';
import TabBar, { useTabPanelProps } from '@newspack-nodes/shared/tabs/TabBar';
import Briefing from './views/Briefing';
import Search, { newSearch } from './views/Search';
import Entity from './views/Entity';
import Review from './views/Review';
import Collections from './views/Collections';
import Lens, { CLOSED_LENS } from './views/Lens';
import Watches, { CLOSED_WATCH, newWatch } from './views/Watches';
import SaveToCollection from './components/SaveToCollection';
import { CanManage } from './components/Answer';
import HealthStrip from './components/HealthStrip';
import { useLastLook } from './hooks/useLastLook';
import './styles/intelligence.scss';

/**
 * The watch a link opens the page on, as the watcher's email names it
 * (`&watch=<key>`), or null.
 *
 * @return {?string} The watch's key.
 */
const linkedWatch = () => getQueryParam( 'watch' ) || null;

/**
 * The view a bar without `?view=` opens: Watches for a linked watch, else
 * the Briefing.
 *
 * @return {string} The view's tab id.
 */
const defaultView = () => ( linkedWatch() ? 'watches' : 'briefing' );

/**
 * The Intelligence page: Briefing, the view it opens on, then Search,
 * Entity, Team views, Review, Folders and Watches, switched in place. The
 * Briefing opens an entity, a lens, a watch, the watch form or Search. Each
 * view reads its own commands; the page holds
 * which view shows, the submitted search, the open entity, lens, collection
 * and watch, the watch form's draft and the item being saved, so a view
 * returns as it was left, and a lens's "Watch this" opens the form on it.
 * The Entity tab reads the name the open entity's record answered, else its
 * key. The page holds the reader's last look, fixed for the browser session,
 * so a return to the Briefing reads changes since the same instant. A line
 * of the Briefing's summary opens Search on its filters.
 * Curation renders only for `canTune`, which hides controls and enforces
 * nothing: the server's role gate refuses a write the caller may not make.
 * Watches are every reader's own, so they are not gated on it. A link naming
 * a watch opens the page on Watches with that watch open; a key the reader's
 * watches do not hold opens nothing, leaving the list. The health strip sits
 * above the tabs on every view, offering the operator fixes for `canManage`,
 * which the page also provides to every answer through `CanManage`. Each
 * switch of view pushes `?view=<tab id>`, so Back and Forward step through
 * the views and a link opens on one; a view the page does not offer opens
 * the default.
 *
 * The shared `DashboardShell` supplies the skin, the positioned page surface,
 * the header naming this page and the debug overlay; the page scrolls in the
 * shell, since the views have no scroller of their own.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune    Whether the caller may curate.
 * @param {boolean} props.canManage  Whether the caller is an operator.
 * @param {string}  props.userId     The reader, whose last look the page holds for the session.
 * @param {Object}  props.operator   Who runs the deployment, `{ name, heading }`, whom the copy names.
 * @param {Object}  props.vocabulary The ontology's closed lists.
 * @return {import('react').ReactElement} The page.
 */
export default function IntelligencePage( {
	canTune,
	canManage,
	userId,
	operator,
	vocabulary,
} ) {
	const [ entityKey, setEntityKey ] = useState( null );
	const [ named, setNamed ] = useState( null );
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
	const [ asked, setAsked ] = useState( null );
	const lastLook = useLastLook( userId );

	const tabs = [
		{ id: 'briefing', label: __( 'Briefing', 'newspack-intelligence' ) },
		{ id: 'search', label: __( 'Search', 'newspack-intelligence' ) },
		...( entityKey
			? [
					{
						id: 'entity',
						label: sprintf(
							/* translators: %s: the open entity's name, else its key. */
							__( 'Entity: %s', 'newspack-intelligence' ),
							( entityKey === named?.key && named.name ) ||
								entityKey
						),
					},
			  ]
			: [] ),
		{ id: 'lenses', label: __( 'Team views', 'newspack-intelligence' ) },
		{ id: 'review', label: __( 'Review', 'newspack-intelligence' ) },
		{
			id: 'collections',
			label: __( 'Folders', 'newspack-intelligence' ),
		},
		{ id: 'watches', label: __( 'Watches', 'newspack-intelligence' ) },
	];
	const [ view, setView ] = useQueryParamState(
		'view',
		( raw ) =>
			tabs.some( ( tab ) => tab.id === raw ) ? raw : defaultView(),
		( id ) => ( defaultView() === id ? null : id ),
		{ push: true }
	);
	const panelProps = useTabPanelProps( 'intelligence', view, true );

	const openEntity = ( key ) => {
		setEntityKey( key );
		setView( 'entity' );
	};
	const onSave = canTune ? setSaving : undefined;
	const openWatchForm = ( target, name ) => {
		setWatchDraft( newWatch( target, name ) );
		setView( 'watches' );
	};

	const openLens = ( key ) => {
		setLens( { ...CLOSED_LENS, key } );
		setView( 'lenses' );
	};
	const openWatch = ( key ) => {
		setWatch( { ...CLOSED_WATCH, key } );
		setView( 'watches' );
	};
	const searchFor = ( filters ) => {
		const fresh = newSearch( vocabulary.lensLists );
		setSearch( { ...fresh, filters: { ...fresh.filters, ...filters } } );
		setView( 'search' );
	};

	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:intelligence"
			title={ __( 'Intelligence', 'newspack-intelligence' ) }
			overflowY="auto"
		>
			{ () => (
				<CanManage.Provider value={ canManage }>
					<div className="newspack-intelligence">
						<HealthStrip
							canTune={ canTune }
							canManage={ canManage }
						/>
						<TabBar
							scope="intelligence"
							label={ __( 'Views', 'newspack-intelligence' ) }
							tabs={ tabs }
							activeId={ view }
							onPick={ setView }
						/>
						<div { ...panelProps }>
							{ 'briefing' === view && (
								<Briefing
									since={ lastLook.since }
									kept={ lastLook.kept }
									noteVisible={ lastLook.noteVisible }
									operator={ operator }
									onSearchFor={ searchFor }
									onOpenEntity={ openEntity }
									onOpenLens={ openLens }
									onOpenWatch={ openWatch }
									onNewWatch={ () =>
										openWatchForm(
											{ kind: 'lens', key: '' },
											''
										)
									}
									onSearch={ () => setView( 'search' ) }
									onSave={ onSave }
									canTune={ canTune }
									asked={ asked }
									onAsked={ setAsked }
								/>
							) }
							{ 'search' === view && (
								<Search
									operator={ operator }
									query={ search }
									onQuery={ setSearch }
									vocabulary={ vocabulary }
									onOpenEntity={ openEntity }
									onSave={ onSave }
									canTune={ canTune }
									asked={ asked }
									onAsked={ setAsked }
								/>
							) }
							{ 'entity' === view && (
								<Entity
									key={ entityKey }
									entityKey={ entityKey }
									canTune={ canTune }
									vocabulary={ vocabulary }
									operator={ operator }
									onOpenEntity={ openEntity }
									onNamed={ setNamed }
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
									onOpenEntity={ openEntity }
									onSave={ onSave }
								/>
							) }
							{ 'collections' === view && (
								<Collections
									canTune={ canTune }
									opened={ collection }
									onOpen={ setCollection }
									vocabulary={ vocabulary }
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
									canTune={ canTune }
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
				</CanManage.Provider>
			) }
		</DashboardShell>
	);
}

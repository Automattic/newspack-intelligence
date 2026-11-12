import {
	useCallback,
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import DashboardShell from '@newspack-nodes/shared/components/DashboardShell';
import { getQueryParam } from '@newspack-nodes/shared/utils/queryParams';
import { useQueryParamState } from '@newspack-nodes/shared/hooks/useQueryParamState';
import TabBar, { useTabPanelProps } from '@newspack-nodes/shared/tabs/TabBar';
import CompositionEditSlot from '@newspack-nodes/shared/composition/CompositionEditSlot';
import Briefing from './views/Briefing';
import Search from './views/Search';
import { namedView, newView } from './views/searchView';
import Entity from './views/Entity';
import Review from './views/Review';
import Collections from './views/Collections';
import Lens, { CLOSED_LENS } from './views/Lens';
import Watches, { CLOSED_WATCH, newWatch } from './views/Watches';
import SaveToCollection from './components/SaveToCollection';
import { CanManage } from './components/Answer';
import HealthStrip from './components/HealthStrip';
import { useLastLook } from './hooks/useLastLook';
import { PageReads, usePageReads } from './hooks/usePageRead';
import UndoSnackbar from './components/UndoSnackbar';
import { editTarget, layoutContext, leaveEdit } from './layout/editMode';
import { Confirm } from '@newspack-nodes/shared/components/Modal';
import './styles/intelligence.scss';

/** The edit slot, which hands every prop past `context` to the editor. */
const EditSlot = /** @type {import('react').ComponentType<Object>} */ (
	CompositionEditSlot
);

/**
 * The watch a link opens the page on, as the watcher's email names it
 * (`&watch=<key>`), or null.
 *
 * @return {?string} The watch's key.
 */
const linkedWatch = () => getQueryParam( 'watch' ) || null;

/** Every view the bar offers, by its tab id, in order. */
const VIEWS = [
	'briefing',
	'search',
	'lenses',
	'review',
	'collections',
	'watches',
];

/**
 * The view a bar without `?view=` opens: Watches for a linked watch, else
 * the Briefing.
 *
 * @return {string} The view's tab id.
 */
const defaultView = () => ( linkedWatch() ? 'watches' : 'briefing' );

/**
 * The Intelligence page: Briefing, the view it opens on, then Search,
 * Team views, Review, Folders and Watches, switched in place. The
 * Briefing opens an entity, a lens, a watch, the watch form or Search. Each
 * view reads its own commands; the page holds
 * which view shows, Search's DataViews view, the open entity, lens and
 * watch, the watch form's draft and the item being saved, so a view
 * returns as it was left, and a lens's "Watch this" opens the form on it.
 * A profile is not a tab: it stands in the panel of the view that opened it,
 * which stays as it was beneath it, until "Back to %s" returns to that view
 * and focuses the chip that opened it, or a tab is picked. The page holds
 * the reader's last look, fixed for the browser session,
 * so a return to the Briefing reads changes since the same instant. A line
 * of the Briefing's summary opens Search on its filters.
 * Curation renders only for `canTune`, which hides controls and enforces
 * nothing: the server's role gate refuses a write the caller may not make.
 * Watches are every reader's own, so they are not gated on it. A link naming
 * a watch opens the page on Watches with that watch open; a key the reader's
 * watches do not hold opens nothing, leaving the list. Collection's status
 * stands in the shell's header and its fault under the tabs on every view,
 * offering the operator its details and fixes for `canManage`, which the
 * page also provides to every answer through `CanManage`. A curator's
 * Review tab counts the items whose suggested matches await review while
 * another view shows, and the Watches tab every reader's new matches
 * across their watches. Each switch of view pushes `?view=<tab id>`, so
 * Back and Forward step through the views and a link opens on one; a view
 * the page does not offer opens the default.
 *
 * The Briefing is its page's layout, from the page data's `compositions`,
 * with **Edit layout** in its own header's ⋯ menu for a curator; a team view
 * and a profile each render their page's layout below their own header,
 * whose ⋯ menu offers a curator **Edit layout**. A curator's load naming
 * `edit` puts the layout editor in the panel and keeps the tab bar; the
 * layout it hands back, or a restored default, replaces the page's without a
 * reload. A tab picked or Back and Forward end an edit; while the editor
 * holds a change not yet saved, they ask "Discard your changes to this
 * layout?" first, the editor standing until answered: **Discard changes**
 * ends the edit and goes, and **Keep editing** stays, returning to the edit's
 * view. Back and Forward otherwise go as a tab picked goes, closing a
 * profile.
 *
 * The shared `DashboardShell` supplies the skin, the positioned page surface,
 * the header naming this page and the debug overlay; the page scrolls in the
 * shell, since the views have no scroller of their own.
 *
 * @param {Object}  props
 * @param {boolean} props.canTune      Whether the caller may curate.
 * @param {boolean} props.canManage    Whether the caller is an operator.
 * @param {string}  props.userId       The reader, whose last look the page holds for the session.
 * @param {Object}  props.vocabulary   The ontology's closed lists.
 * @param {Object}  props.compositions Context => the layout its page reads, or its refusal.
 * @param {Object}  props.askDefaults  The ask block's declared defaults, Search's Ask box copy.
 * @return {import('react').ReactElement} The page.
 */
export default function IntelligencePage( {
	canTune,
	canManage,
	userId,
	vocabulary,
	compositions,
	askDefaults,
} ) {
	const [ editing, setEditing ] = useState( () =>
		canTune ? editTarget( window.location.search ) : null
	);
	const [ dirty, setDirty ] = useState( false );
	const [ layouts, setLayouts ] = useState( compositions );
	const [ laidOut, setLaidOut ] = useState( null );
	const [ profile, setProfile ] = useState( () =>
		editing?.entity_key ? { key: editing.entity_key, opener: null } : null
	);
	const refocus = useRef( null );
	const root = useRef( null );
	useLayoutEffect( () => {
		if ( ! profile && refocus.current?.isConnected ) {
			refocus.current.focus();
		}
		refocus.current = null;
	}, [ profile ] );
	const [ saving, setSaving ] = useState( null );
	const [ search, setSearch ] = useState( newView );
	const [ lens, setLens ] = useState( () =>
		editing?.lens_key
			? { ...CLOSED_LENS, key: editing.lens_key }
			: CLOSED_LENS
	);
	const [ watch, setWatch ] = useState( () => ( {
		...CLOSED_WATCH,
		key: linkedWatch(),
	} ) );
	const [ watchDraft, setWatchDraft ] = useState( null );
	const [ asked, setAsked ] = useState( null );
	const lastLook = useLastLook( userId );
	const [ view, setView ] = useQueryParamState(
		'view',
		( raw ) => ( VIEWS.includes( raw ) ? raw : defaultView() ),
		( id ) => ( defaultView() === id ? null : id ),
		{ push: true }
	);
	const reads = usePageReads( { review: canTune && 'review' !== view } );
	const matches = canTune ? reads.review.answer?.total : undefined;
	const unseen = reads.watches.answer?.watches.reduce(
		( sum, one ) => sum + one.unseen,
		0
	);

	const labels = {
		briefing: __( 'Briefing', 'newspack-intelligence' ),
		search: __( 'Search', 'newspack-intelligence' ),
		lenses: __( 'Team views', 'newspack-intelligence' ),
		review:
			undefined === matches
				? __( 'Review', 'newspack-intelligence' )
				: sprintf(
						/* translators: %d: how many items have suggested matches awaiting review. */
						__( 'Review %d', 'newspack-intelligence' ),
						matches
				  ),
		collections: __( 'Folders', 'newspack-intelligence' ),
		watches: unseen
			? sprintf(
					/* translators: %d: the reader's new matches across their watches. */
					__( 'Watches %d', 'newspack-intelligence' ),
					unseen
			  )
			: __( 'Watches', 'newspack-intelligence' ),
	};
	const tabs = VIEWS.map( ( id ) => ( { id, label: labels[ id ] } ) );
	const [ editView ] = useState( view );
	const [ leaving, setLeaving ] = useState(
		/** @type {?{view: string, popped: boolean}} */ ( null )
	);
	// The view the page last went to itself; any other is a Back or Forward.
	const went = useRef( view );
	const panelProps = useTabPanelProps( 'intelligence', view, true );
	const viewName =
		{
			review: __( 'Review', 'newspack-intelligence' ),
			watches: __( 'Watches', 'newspack-intelligence' ),
		}[ view ] ?? labels[ view ];

	// A profile replaces the panel of the view that opened it.
	const show = ( id ) => {
		went.current = id;
		setProfile( null );
		setView( id );
	};
	const endEdit = useCallback( () => {
		setEditing( null );
		leaveEdit();
	}, [] );
	const go = ( id ) => {
		if ( editing && dirty ) {
			setLeaving( { view: id, popped: false } );
			return;
		}
		if ( editing ) {
			endEdit();
		}
		show( id );
	};
	useEffect( () => {
		if ( view === went.current ) {
			return;
		}
		if ( editing && dirty ) {
			setLeaving( { view, popped: true } );
			return;
		}
		if ( editing ) {
			endEdit();
		}
		went.current = view;
		setProfile( null );
	}, [ view, editing, dirty, endEdit ] );
	const relaid = ( answer ) =>
		setLayouts( ( was ) => ( { ...was, [ answer.context ]: answer } ) );
	const discard = () => {
		const { view: to, popped } = leaving;
		setLeaving( null );
		endEdit();
		if ( popped ) {
			went.current = to;
			setProfile( null );
		} else {
			show( to );
		}
	};
	const keepEditing = () => {
		const { popped } = leaving;
		setLeaving( null );
		if ( popped ) {
			went.current = editView;
			setView( editView );
		}
	};
	const openEntity = ( key ) =>
		setProfile( ( open ) => ( {
			key,
			opener: open
				? open.opener
				: root.current.ownerDocument.activeElement,
		} ) );
	const closeProfile = () => {
		refocus.current = profile.opener;
		setProfile( null );
	};
	const onSave = canTune ? setSaving : undefined;
	const openWatchForm = ( target, name ) => {
		setWatchDraft( newWatch( target, name ) );
		go( 'watches' );
	};

	const openLens = ( key ) => {
		setLens( { ...CLOSED_LENS, key } );
		go( 'lenses' );
	};
	const openWatch = ( key ) => {
		setWatch( { ...CLOSED_WATCH, key } );
		go( 'watches' );
	};
	const editSlot = editing && (
		<EditSlot
			context={ layoutContext( editing.page ) }
			target={ editing }
			since={ lastLook.since }
			reads={ reads }
			onDirty={ setDirty }
			onDone={ ( answer, verb ) => {
				if ( answer ) {
					relaid( answer );
				}
				if ( 'save' === verb ) {
					setLaidOut( {
						id: answer.saved_ts,
						message: __( 'Layout saved.', 'newspack-intelligence' ),
					} );
				}
				endEdit();
			} }
		/>
	);
	const searchFor = ( filters ) => {
		setSearch( { ...namedView( filters ), type: search.type } );
		go( 'search' );
	};

	return (
		<DashboardShell
			storageKey="newspack-nodes:debug:intelligence"
			subtitle={ __( 'Intelligence', 'newspack-intelligence' ) }
			overflowY="auto"
			fullBleed
		>
			{ ( slot ) => (
				<CanManage.Provider value={ canManage }>
					<PageReads.Provider value={ reads }>
						<div className="newspack-intelligence" ref={ root }>
							<TabBar
								scope="intelligence"
								label={ __( 'Views', 'newspack-intelligence' ) }
								tabs={ tabs }
								activeId={ view }
								onPick={ go }
							/>
							<div className="newspack-intelligence__page newspack-nodes-page-inset">
								<HealthStrip
									canManage={ canManage }
									slot={ slot }
								/>
								<div { ...panelProps }>
									{ editSlot }
									{ profile && ! editSlot && (
										<Entity
											onRestored={ relaid }
											key={ profile.key }
											entityKey={ profile.key }
											canTune={ canTune }
											vocabulary={ vocabulary }
											layout={
												layouts[
													layoutContext( 'entity' )
												]
											}
											back={ {
												label: viewName,
												onBack: closeProfile,
											} }
											onWatch={ openWatchForm }
											actions={ {
												onSearchFor: searchFor,
												onOpenEntity: openEntity,
												onOpenLens: openLens,
												onOpenWatch: openWatch,
												onSave,
												canTune,
											} }
										/>
									) }
									<div
										hidden={ Boolean(
											profile || editSlot
										) }
									>
										{ 'briefing' === view && ! editSlot && (
											<Briefing
												onRestored={ relaid }
												layout={
													layouts[
														layoutContext(
															'briefing'
														)
													]
												}
												since={ lastLook.since }
												kept={ lastLook.kept }
												noteVisible={
													lastLook.noteVisible
												}
												canTune={ canTune }
												actions={ {
													onSearchFor: searchFor,
													onOpenEntity: openEntity,
													onOpenLens: openLens,
													onOpenWatch: openWatch,
													onNewWatch: () =>
														openWatchForm(
															{
																kind: 'lens',
																key: '',
															},
															''
														),
													onSearch: () =>
														go( 'search' ),
													onSave,
													canTune,
												} }
											/>
										) }
										{ 'search' === view && (
											<Search
												ask={ askDefaults }
												view={ search }
												onChangeView={ setSearch }
												vocabulary={ vocabulary }
												onOpenEntity={ openEntity }
												onSave={ onSave }
												canTune={ canTune }
												asked={ asked }
												onAsked={ setAsked }
											/>
										) }
										{ 'lenses' === view && ! editSlot && (
											<Lens
												onRestored={ relaid }
												canTune={ canTune }
												vocabulary={ vocabulary }
												opened={ lens }
												onOpen={ setLens }
												onWatch={ openWatchForm }
												layout={
													layouts[
														layoutContext( 'lens' )
													]
												}
												actions={ {
													onSearchFor: searchFor,
													onOpenEntity: openEntity,
													onOpenLens: openLens,
													onOpenWatch: openWatch,
													onSave,
													canTune,
												} }
											/>
										) }
										{ 'review' === view && (
											<Review
												canTune={ canTune }
												onOpenEntity={ openEntity }
											/>
										) }
										{ 'collections' === view && (
											<Collections canTune={ canTune } />
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
								</div>
								<UndoSnackbar latest={ laidOut } />
								{ leaving && (
									<Confirm
										title={ __(
											'Discard your changes to this layout?',
											'newspack-intelligence'
										) }
										yes={ __(
											'Discard changes',
											'newspack-intelligence'
										) }
										no={ __(
											'Keep editing',
											'newspack-intelligence'
										) }
										onYes={ discard }
										onNo={ keepEditing }
									/>
								) }
								{ saving && (
									<SaveToCollection
										item={ saving }
										onClose={ () => setSaving( null ) }
									/>
								) }
							</div>
						</div>
					</PageReads.Provider>
				</CanManage.Provider>
			) }
		</DashboardShell>
	);
}

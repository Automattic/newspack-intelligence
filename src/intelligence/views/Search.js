import { Button, SelectControl } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import { useContext, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { useIntelRead } from '../hooks/useIntelCommand';
import { usePageRead } from '../hooks/usePageRead';
import { CanManage } from '../components/Answer';
import Unread from '@newspack-nodes/shared/components/Unread';
import AskBox from '../components/AskBox';
import EntityPicker from '../components/EntityPicker';
import ItemList from '../components/ItemList';
import Section, {
	SectionHeader,
} from '@newspack-nodes/shared/components/Section';
import Invitation from '@newspack-nodes/shared/components/Invitation';
import Sheet from '../components/Sheet';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import { daysSince, lensDraft, listOf } from '../components/lensDraft';
import { pageFormat } from '../components/format';
import { word, words } from '../components/words';
import { namedView, viewToArgs } from './searchView';

/** The list filters picked from a closed list, by option, each by the map wording it. */
const PICKED = { kind: 'kindWords', role: 'roleWords', source: 'sourceWords' };

/** The list filters DataViews shows beside the words, never behind "Add filter". */
const PRIMARY = [ 'lens', 'kind' ];

/** The skeleton rows that stand in for a first page still owed. */
const SKELETON_ROWS = 5;

/**
 * What each list filter is called, by option.
 *
 * @return {Object<string, string>} The labels.
 */
const listLabels = () => ( {
	kind: __( 'Kind of news', 'newspack-intelligence' ),
	topic: __( 'Topics', 'newspack-intelligence' ),
	role: __( 'About', 'newspack-intelligence' ),
	source: __( 'Sources', 'newspack-intelligence' ),
} );

/**
 * A list filter's comma-separated values as shown: each picked one by its
 * word, a typed one as given.
 *
 * @param {string} option The filter.
 * @param {string} text   Its values, comma-separated.
 * @return {string} The values shown.
 */
const shownList = ( option, text ) =>
	PICKED[ option ]
		? listOf( text )
				.map( ( one ) => word( PICKED[ option ], one ) )
				.join( ', ' )
		: text;

/**
 * The value a view's filter on `field` holds, or undefined.
 *
 * @param {Object} view  The view.
 * @param {string} field The filter's field.
 * @return {*} The value.
 */
const filterValue = ( view, field ) =>
	view.filters.find( ( one ) => one.field === field )?.value;

/**
 * Whether a filter holds a value that narrows the search.
 *
 * @param {{value: *}} filter The filter.
 * @return {boolean} Whether it does.
 */
const narrows = ( { value } ) =>
	undefined !== value && '' !== value && 0 !== [].concat( value ).length;

/**
 * The view on its first page with `field`'s filter an `is` of `value`, or
 * without it for a blank one.
 *
 * @param {Object} view  The view.
 * @param {string} field The filter's field.
 * @param {string} value The value.
 * @return {Object} The view.
 */
const withIs = ( view, field, value ) => ( {
	...view,
	page: 1,
	filters: [
		...view.filters.filter( ( one ) => one.field !== field ),
		...( value ? [ { field, operator: 'is', value } ] : [] ),
	],
} );

/**
 * The fields DataViews filters by: the team view, each list, a closed one
 * offering its words and a free one typed, and the days.
 *
 * @param {Array<{option: string, values: ?string[]}>} lists  The lens's list filters.
 * @param {Object[]}                                   lenses The team views.
 * @return {Object[]} The fields.
 */
function filterFields( lists, lenses ) {
	const labels = listLabels();
	const elements = ( option, values ) => {
		const shown = words( PICKED[ option ] );
		return ( values ?? Object.keys( shown ) ).map( ( value ) => ( {
			value,
			label: shown[ value ],
		} ) );
	};
	return [
		{
			id: 'lens',
			label: __( 'Team view', 'newspack-intelligence' ),
			elements: lenses.map( ( lens ) => ( {
				value: lens.lens_key,
				label: lens.name,
			} ) ),
			filterBy: { operators: [ 'is' ] },
		},
		...lists.map( ( { option, values } ) =>
			PICKED[ option ]
				? {
						id: option,
						label: labels[ option ],
						elements: elements( option, values ),
						filterBy: { operators: [ 'isAny' ] },
				  }
				: {
						id: option,
						label: labels[ option ],
						type: 'text',
						filterBy: { operators: [ 'is' ] },
				  }
		),
		{
			id: 'day',
			label: __( 'Dates', 'newspack-intelligence' ),
			type: 'date',
			filterBy: { operators: [ 'between', 'afterInc', 'beforeInc' ] },
		},
	].map( ( field ) => ( {
		...field,
		filterBy: {
			...field.filterBy,
			isPrimary: PRIMARY.includes( field.id ),
		},
		enableHiding: false,
		enableSorting: false,
	} ) );
}

/**
 * Every filter a lens searches with, read-only: each list, its entity, its
 * rolling days and its tiers, a filter it leaves unset reading as any; and,
 * when it names tiers, that editing it as one's own search drops them.
 *
 * @param {Object}        props
 * @param {Object}        props.lens  The lens picked.
 * @param {Array<Object>} props.lists The lens's list filters.
 * @return {import('react').ReactElement} The filters.
 */
function LensRuns( { lens, lists } ) {
	const text = lensDraft( lens, lists );
	const labels = listLabels();
	const days = Number( text.days );
	const rows = [
		...lists.map( ( { option } ) => [
			labels[ option ],
			shownList( option, text[ option ] ),
		] ),
		[ __( 'Client or company', 'newspack-intelligence' ), text.entity ],
		[
			__( 'Look back', 'newspack-intelligence' ),
			'' === text.days
				? ''
				: sprintf(
						/* translators: %d: how many days the team view reads. */
						_n(
							'%d day',
							'%d days',
							days,
							'newspack-intelligence'
						),
						days
				  ),
		],
		[
			__( 'Include items that are', 'newspack-intelligence' ),
			listOf( text.tier )
				.map( ( tier ) => word( 'tierWords', tier ) )
				.join( ', ' ),
		],
	];
	return (
		<div
			className="newspack-intelligence__runs"
			role="group"
			aria-label={ sprintf(
				/* translators: %s: the lens's name. */
				__( '%s searches with', 'newspack-intelligence' ),
				lens.name
			) }
		>
			<dl>
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label }>
						<dt className="newspack-nodes-status is-muted">
							{ label }
						</dt>
						<dd>
							{ value || __( 'Any', 'newspack-intelligence' ) }
						</dd>
					</div>
				) ) }
			</dl>
			{ '' !== text.tier && (
				<p className="newspack-nodes-status is-muted">
					{ __(
						'A search reads every tier you may read, so editing drops this team view’s tiers.',
						'newspack-intelligence'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * The client or company, picked by name: a name picked searches its key at
 * once, and typed text alone searches nothing. The box shows the view's
 * entity, so a filter removed elsewhere empties it.
 *
 * @param {Object}                 props
 * @param {Object}                 props.view         The view.
 * @param {(view: Object) => void} props.onChangeView Moves the view.
 * @return {import('react').ReactElement} The field.
 */
function EntityFilter( { view, onChangeView } ) {
	return (
		<EntityPicker
			label={ __( 'Client or company', 'newspack-intelligence' ) }
			value={ filterValue( view, 'entity' ) ?? '' }
			onChange={ ( key ) =>
				onChangeView( withIs( view, 'entity', key ) )
			}
		/>
	);
}

/**
 * How many items the answer counts, in the order it read them, and the
 * order to read them in.
 *
 * @param {Object}                 props
 * @param {Object}                 props.answer       The `search` answer.
 * @param {Object}                 props.view         The view.
 * @param {(view: Object) => void} props.onChangeView Moves the view.
 * @return {import('react').ReactElement} The count and the order.
 */
function Count( { answer, view, onChangeView } ) {
	const total = pageFormat( Intl.NumberFormat ).format( answer.total );
	return (
		<div className="newspack-intelligence__count-row">
			<p className="newspack-intelligence__count">
				{ 'time' === answer.sort
					? sprintf(
							/* translators: %s: how many items match. */
							_n(
								'%s item, newest first',
								'%s items, newest first',
								answer.total,
								'newspack-intelligence'
							),
							total
					  )
					: sprintf(
							/* translators: %s: how many items match. */
							_n(
								'%s item, most important first',
								'%s items, most important first',
								answer.total,
								'newspack-intelligence'
							),
							total
					  ) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				labelPosition="side"
				label={ __( 'Sort', 'newspack-intelligence' ) }
				value={ view.sort?.field ?? answer.sort }
				options={ [
					{
						label: __( 'Importance', 'newspack-intelligence' ),
						value: 'importance',
					},
					{
						label: __( 'Newest', 'newspack-intelligence' ),
						value: 'time',
					},
				] }
				onChange={ ( field ) =>
					onChangeView( {
						...view,
						page: 1,
						sort: { field, direction: 'desc' },
					} )
				}
			/>
		</div>
	);
}

/**
 * Search: the Ask box, then "Find items", the readable items matching the
 * view a page at a time, through DataViews. Its words are DataViews'
 * search; Team view and Kind of news stand beside them and every other list
 * and the days wait behind "Add filter", with "Reset" clearing them; the
 * client or company is picked by name beside the filters; and when the page
 * is narrow every filter sits in a sheet that "Filters (%d)" opens. The
 * count reads the server's total in the order it read, which Sort switches.
 * A team view picked shows every filter it searches with, read-only, and
 * "Edit as my own search" copies them into the view, its days as the day
 * they start from and without its tiers, and drops the team view. While a
 * page is owed, the answer the read remembers for it stands in, dimmed, else
 * placeholder rows; a refused read shows none. The page holds the view, so it
 * outlives a visit to another view.
 *
 * It stands under the shared header, its Ask box a plain part of the page
 * under it and "Find items" its first section. The Ask box is the `ask`
 * block's component at the block's declared defaults, which the page data
 * carries, whatever any layout holds; it
 * plans its own searches and never reads the view, and its "Search for
 * these words" and "Show the items" open these results on the filters they
 * name.
 *
 * @param {Object}                                                   props
 * @param {{label: string, placeholder: string, examples: string[]}} props.ask          The ask block's declared defaults, its Ask box's copy.
 * @param {Object}                                                   props.view         The DataViews view searched.
 * @param {(view: Object) => void}                                   props.onChangeView Replaces the view.
 * @param {Object}                                                   props.vocabulary   The ontology's closed lists.
 * @param {(key: string) => void}                                    props.onOpenEntity Opens an entity.
 * @param {(item: Object) => void}                                   [props.onSave]     Saves an item; absent for a reader.
 * @param {boolean}                                                  [props.canTune]    Whether the reader holds tune, which offers each item's exclusion.
 * @param {?Object}                                                  props.asked        The ask the page holds, which its AskBox shows.
 * @param {(asked: ?Object) => void}                                 props.onAsked      Holds a new one.
 * @return {import('react').ReactElement} The view.
 */
export default function Search( {
	ask,
	view,
	onChangeView,
	vocabulary,
	onOpenEntity,
	onSave,
	canTune,
	asked,
	onAsked,
} ) {
	const operator = useContext( CanManage );
	const lists = vocabulary.lensLists;
	const narrow = useNarrow();
	const [ sheet, setSheet ] = useState( false );
	const read = useIntelRead( 'search:items', 'search', viewToArgs( view ) );
	const lenses = usePageRead( 'lenses' );
	const answer = read.error ? null : read.answer;
	const found = true === answer?.available;
	const lens = lenses.answer?.lenses.find(
		( one ) => one.lens_key === filterValue( view, 'lens' )
	);
	const editAsOwn = () => {
		const text = lensDraft( lens, lists );
		onChangeView( {
			...namedView( {
				q: view.search,
				...Object.fromEntries(
					lists.map( ( { option } ) => [ option, text[ option ] ] )
				),
				entity: text.entity,
				since:
					'' === text.days
						? ''
						: daysSince( Number( text.days ), Date.now() ),
			} ),
			type: view.type,
			...( view.sort ? { sort: view.sort } : {} ),
		} );
	};
	const removeFilters = () =>
		onChangeView( { ...view, search: '', filters: [], page: 1 } );
	const entity = <EntityFilter view={ view } onChangeView={ onChangeView } />;
	const filters = (
		<>
			<DataViews.Filters />
			{ entity }
		</>
	);

	return (
		<div className="newspack-intelligence__view">
			<SectionHeader
				title={ __( 'Search', 'newspack-intelligence' ) }
				description={ __(
					'Ask a question the graph answers, or find items by their words and filters.',
					'newspack-intelligence'
				) }
			/>
			<AskBox
				{ ...ask }
				asked={ asked }
				onAsked={ onAsked }
				onSearchFor={ ( named ) =>
					onChangeView( { ...namedView( named ), type: view.type } )
				}
				onOpenEntity={ onOpenEntity }
				onSave={ onSave }
				canTune={ canTune }
			/>
			<Section heading={ __( 'Find items', 'newspack-intelligence' ) }>
				<ItemList
					items={ found ? answer.items : [] }
					view={ view }
					onChangeView={ onChangeView }
					layout={ [ 'list', 'table' ] }
					isLoading={ read.loading }
					empty={
						found && (
							<Invitation
								text={ __(
									'Nothing matches these filters.',
									'newspack-intelligence'
								) }
								label={ __(
									'Remove filters',
									'newspack-intelligence'
								) }
								onClick={ removeFilters }
							/>
						)
					}
					paginationInfo={ {
						totalItems: found ? answer.total : 0,
						totalPages: found
							? Math.max(
									1,
									Math.ceil( answer.total / view.perPage )
							  )
							: 1,
					} }
					fields={ filterFields(
						lists,
						lenses.answer?.lenses ?? []
					) }
					rowProps={ { onOpenEntity, onSave, canTune } }
					header={
						<>
							<div className="newspack-intelligence__find-bar">
								<DataViews.Search
									label={ __(
										'Search words',
										'newspack-intelligence'
									) }
								/>
								{ narrow && (
									<Button
										variant="secondary"
										onClick={ () => setSheet( true ) }
									>
										{ sprintf(
											/* translators: %d: how many filters narrow the search. */
											__(
												'Filters (%d)',
												'newspack-intelligence'
											),
											view.filters.filter( narrows )
												.length
										) }
									</Button>
								) }
								{ ! narrow && <DataViews.Filters /> }
								<DataViews.LayoutSwitcher />
							</div>
							{ ! narrow && entity }
							{ narrow && sheet && (
								<Sheet
									title={ __(
										'Filters',
										'newspack-intelligence'
									) }
									closeLabel={ __(
										'Close',
										'newspack-intelligence'
									) }
									onClose={ () => setSheet( false ) }
								>
									{ filters }
									<Button
										variant="primary"
										onClick={ () => setSheet( false ) }
									>
										{ sprintf(
											/* translators: %d: how many items match. */
											_n(
												'Show %d item',
												'Show %d items',
												answer?.total ?? 0,
												'newspack-intelligence'
											),
											answer?.total ?? 0
										) }
									</Button>
								</Sheet>
							) }
							{ lens && (
								<>
									<LensRuns lens={ lens } lists={ lists } />
									<Button
										variant="secondary"
										onClick={ editAsOwn }
									>
										{ __(
											'Edit as my own search',
											'newspack-intelligence'
										) }
									</Button>
								</>
							) }
							<Unread
								operator={ operator }
								error={ lenses.error }
								refresh={ lenses.refresh }
							/>
							<Unread
								operator={ operator }
								error={ read.error }
								refresh={ read.refresh }
							/>
							{ false === answer?.available && (
								<p className="newspack-nodes-banner is-warning">
									{ __(
										'The intelligence store did not open, so this was not read. An administrator can check the status above.',
										'newspack-intelligence'
									) }
								</p>
							) }
							{ found && (
								<Count
									answer={ answer }
									view={ view }
									onChangeView={ onChangeView }
								/>
							) }
							{ ! answer && read.loading && (
								<Skeleton count={ SKELETON_ROWS } />
							) }
						</>
					}
				/>
			</Section>
		</div>
	);
}

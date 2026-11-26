/**
 * The `graph` block: every entity the items a reader may read are about, on
 * the shared force graph, each a circle holding how many items are about it,
 * and a line for each one and what its Related list holds, sharing at least
 * the slider's count of items. The slider filters what it holds, and so does
 * a checkbox for each type, organizations alone at first, which lays the
 * graph out afresh.
 *
 * A press on the canvas selects an entity and lights what it reaches, and a
 * double press or Enter opens its profile. The list beside it, each entity a
 * chip, selects without a pointer. While an entity is selected an inspector
 * stands in the list's place, read from the answer the block holds.
 */

import {
	CheckboxControl,
	RangeControl,
	SearchControl,
} from '@wordpress/components';
import {
	memo,
	useCallback,
	useEffect,
	useId,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import Section from '@newspack-nodes/shared/components/Section';
import { ForceGraph, lightGraph } from '@newspack-nodes/shared/forceGraph';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import { AnswerBody } from '../components/Answer';
import CappedList from '../components/CappedList';
import { EntityChip } from '../components/Cite';
import {
	aboutLine,
	labelOf,
	ofTypes,
	toEntityGraph,
} from '../components/entityGraph';
import { pageFormat } from '../components/format';
import { words } from '../components/words';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions } from '../layout/PageContext';

/** The fewest and the most items the slider asks a pair to share. */
const LEAST_SHARED = 1;
const MOST_SHARED = 50;

/** The types shown until another's checkbox is checked. */
const CHECKED = [ 'organization' ];

/** The entities listed before the rest are asked for. */
const LISTED = 30;

/**
 * @typedef {Object} GraphAttributes
 * @property {number} shared The fewest items a line stands for at first.
 */

/** @typedef {import('../components/entityGraph').GraphAnswer} GraphAnswer */
/** @typedef {import('../components/entityGraph').GraphEntity} GraphEntity */
/** @typedef {import('../components/entityGraph').EntityGraph} EntityGraph */

/**
 * The slider over how many items a pair must share.
 *
 * @param {Object}                  props
 * @param {number}                  props.value    The count.
 * @param {(value: number) => void} props.onChange Takes another.
 * @return {import('react').ReactElement} The slider.
 */
function SharedControl( { value, onChange } ) {
	return (
		<RangeControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Items shared, at least', 'newspack-intelligence' ) }
			min={ LEAST_SHARED }
			max={ MOST_SHARED }
			value={ value }
			onChange={ onChange }
		/>
	);
}

/**
 * A checkbox for each type an entity may be.
 *
 * @param {Object}                    props
 * @param {string[]}                  props.value    The types checked.
 * @param {(types: string[]) => void} props.onChange Takes another set.
 * @return {import('react').ReactElement[]} The checkboxes.
 */
function TypesControl( { value, onChange } ) {
	const typeWords = words( 'typeWords' );
	const types = Object.keys( typeWords );
	return types.map( ( type ) => (
		<CheckboxControl
			key={ type }
			__nextHasNoMarginBottom
			label={ typeWords[ type ] }
			checked={ value.includes( type ) }
			onChange={ ( on ) =>
				onChange(
					types.filter( ( one ) =>
						one === type ? on : value.includes( one )
					)
				)
			}
		/>
	) );
}

/**
 * The canvas.
 *
 * @param {Object}                 props
 * @param {EntityGraph}            props.graph    The graph it draws.
 * @param {?string}                props.selected The selected entity's key.
 * @param {(key: ?string) => void} props.onSelect Selects an entity, or none.
 * @return {import('react').ReactElement} The canvas.
 */
function Canvas( { graph, selected, onSelect } ) {
	const { onOpenEntity } = useIntelActions();
	// An entity picked from the list that the graph does not draw lights none.
	const drawnPick = graph.nodes.some( ( { id } ) => id === selected )
		? selected
		: null;
	// ForceGraph reads a new array as new data.
	const lit = useMemo(
		() => lightGraph( graph, drawnPick ),
		[ graph, drawnPick ]
	);

	const openPressed = ( e ) => {
		const key = e.target.closest( '[data-id]' )?.dataset.id;
		if ( key ) {
			onOpenEntity( key );
		}
	};
	const openSelected = ( e ) => {
		if ( 'Enter' === e.key && null !== selected ) {
			onOpenEntity( selected );
		}
	};

	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- keys and presses reach it from the canvas inside.
		<div
			className="newspack-intelligence__graph-canvas"
			onDoubleClick={ openPressed }
			onKeyDown={ openSelected }
		>
			<ForceGraph
				nodes={ lit.nodes }
				links={ lit.links }
				selectedId={ drawnPick }
				onSelect={ onSelect }
				onDeselect={ () => onSelect( null ) }
				ariaLabel={ __( 'Entity graph', 'newspack-intelligence' ) }
			/>
		</div>
	);
}

/**
 * What holds the canvas's place while the answer is owed.
 *
 * @return {import('react').ReactElement} The skeleton.
 */
function CanvasSkeleton() {
	return (
		<div
			role="status"
			aria-label={ __( 'Loading', 'newspack-intelligence' ) }
			className="newspack-intelligence__graph"
		>
			<div className="newspack-nodes-skeleton" aria-hidden="true" />
		</div>
	);
}

/**
 * @typedef {Object} EntityListProps
 * @property {GraphEntity[]}          entities The entities.
 * @property {(key: string) => void}  onSelect Selects an entity.
 * @property {string}                 text     The text searched for.
 * @property {(text: string) => void} onText   Takes another.
 * @property {boolean}                all      Whether the rest were asked for.
 * @property {() => void}             onAll    Takes the ask for them.
 * @property {{current: ?string}}     returnTo The entity the inspector closed on, whose chip takes the focus once.
 */

/**
 * Every entity the canvas draws, those whose name holds the text searched
 * for, as chips, each selecting its entity. Enter in a search selects the
 * first. A move of the slider leaves every chip alone. Back from an
 * inspector closed from inside it, the list hands the focus to the chip of
 * the entity it showed, else to the search where the list does not show it.
 *
 * @type {import('react').NamedExoticComponent<EntityListProps>}
 */
const EntityList = memo( function EntityList( {
	entities,
	onSelect,
	text,
	onText,
	all,
	onAll,
	returnTo,
} ) {
	const column = useRef( /** @type {?HTMLDivElement} */ ( null ) );
	const search = useRef( /** @type {?HTMLInputElement} */ ( null ) );
	useEffect( () => {
		const key = returnTo.current;
		returnTo.current = null;
		if ( null === key ) {
			return;
		}
		const row = [ ...column.current.querySelectorAll( 'li' ) ].find(
			( one ) => key === one.dataset.key
		);
		( row?.querySelector( 'button' ) ?? search.current ).focus();
	}, [ returnTo ] );
	const wanted = text.trim().toLowerCase();
	const found = entities.filter( ( entity ) =>
		labelOf( entity ).toLowerCase().includes( wanted )
	);
	return (
		<div className="newspack-intelligence__graph-list" ref={ column }>
			<SearchControl
				ref={ search }
				label={ __( 'Find a name', 'newspack-intelligence' ) }
				placeholder={ __( 'Find a name…', 'newspack-intelligence' ) }
				value={ text }
				onChange={ onText }
				onKeyDown={ ( e ) => {
					if ( 'Enter' === e.key && wanted && 0 < found.length ) {
						onSelect( found[ 0 ].key );
					}
				} }
			/>
			{ 0 === found.length ? (
				<p className="newspack-nodes-empty-state is-quiet">
					{ wanted
						? __( 'No name here matches.', 'newspack-intelligence' )
						: __( 'No names to list.', 'newspack-intelligence' ) }
				</p>
			) : (
				<CappedList
					rows={ found }
					limit={ LISTED }
					all={ all }
					onAll={ onAll }
					className="newspack-intelligence__chips"
				>
					{ ( { key, name } ) => (
						<li key={ key } data-key={ key }>
							<EntityChip
								entityKey={ key }
								name={ name }
								onOpen={ onSelect }
							/>
						</li>
					) }
				</CappedList>
			) }
		</div>
	);
} );

/**
 * The entities a drawn line joins an entity to, in the order `related`
 * ranks them: the items they share over the items about either, then the
 * items they share, then by key.
 *
 * @param {EntityGraph}   graph    The graph drawn.
 * @param {GraphEntity[]} entities The entities it draws.
 * @param {string}        key      The entity's key.
 * @return {Array<{entity: GraphEntity, shared: number}>} Each, with the items it shares.
 */
function relatedOf( graph, entities, key ) {
	const byKey = new Map( entities.map( ( one ) => [ one.key, one ] ) );
	const items = byKey.get( key ).items;
	const score = ( { entity, shared } ) =>
		shared / ( items + entity.items - shared );
	return graph.links
		.filter( ( { source, target } ) => key === source || key === target )
		.map( ( { source, target, weight } ) => ( {
			entity: byKey.get( key === source ? target : source ),
			shared: weight,
		} ) )
		.sort(
			( one, two ) =>
				score( two ) - score( one ) ||
				two.shared - one.shared ||
				Number( one.entity.key > two.entity.key ) -
					Number( one.entity.key < two.entity.key )
		);
}

/**
 * @typedef {Object} InspectorProps
 * @property {EntityGraph}           graph    The graph drawn.
 * @property {GraphEntity[]}         entities The entities it draws.
 * @property {string}                selected The selected entity's key.
 * @property {(key: string) => void} onSelect Selects an entity.
 * @property {() => void}            onClose  Clears the selection from inside.
 */

/**
 * The selected entity in the list's place: its name heading the column, its
 * type and open roles, how many items are about it, Open profile, and each
 * entity a drawn line joins it to, selecting that one. Close or Escape
 * clears the selection. A pick that took the focus with it hands the focus
 * to the heading.
 *
 * @param {InspectorProps} props
 * @return {import('react').ReactElement} The inspector.
 */
function Inspector( { graph, entities, selected, onSelect, onClose } ) {
	const { onOpenEntity } = useIntelActions();
	const heading = useRef( /** @type {?HTMLHeadingElement} */ ( null ) );
	useEffect( () => {
		const { activeElement, body } = heading.current.ownerDocument;
		if ( ! activeElement || body === activeElement ) {
			heading.current.focus();
		}
	}, [ selected ] );
	const entity = entities.find( ( { key } ) => key === selected );
	const related = relatedOf( graph, entities, selected );
	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- Escape bubbles up from the controls inside; the column takes no input itself.
		<div
			className="newspack-intelligence__graph-list"
			onKeyDown={ ( e ) => 'Escape' === e.key && onClose() }
		>
			<Section
				ref={ heading }
				heading={ labelOf( entity ) }
				description={ aboutLine( entity ) }
				actions={
					<button
						type="button"
						className="button button-small"
						onClick={ onClose }
					>
						{ __( 'Close', 'newspack-intelligence' ) }
					</button>
				}
			>
				<p className="newspack-intelligence__counts">
					{ sprintf(
						/* translators: %s: how many items are about the entity. */
						_n(
							'%s item about it',
							'%s items about it',
							entity.items,
							'newspack-intelligence'
						),
						pageFormat( Intl.NumberFormat ).format( entity.items )
					) }
				</p>
				<p>
					<button
						type="button"
						className={ primaryButtonClass() }
						onClick={ () => onOpenEntity( selected ) }
					>
						{ __( 'Open profile', 'newspack-intelligence' ) }
					</button>
				</p>
				<h3>{ __( 'Related', 'newspack-intelligence' ) }</h3>
				{ 0 === related.length ? (
					<p className="newspack-nodes-empty-state is-quiet">
						{ __(
							'No related entity is drawn.',
							'newspack-intelligence'
						) }
					</p>
				) : (
					<CappedList
						key={ selected }
						rows={ related }
						limit={ LISTED }
					>
						{ ( { entity: other, shared } ) => (
							<li
								key={ other.key }
								className="newspack-intelligence__actions"
							>
								<EntityChip
									entityKey={ other.key }
									name={ other.name }
									onOpen={ onSelect }
								/>
								<span className="newspack-intelligence__count">
									{ sprintf(
										/* translators: %d: items both entities are about. */
										__(
											'%d shared',
											'newspack-intelligence'
										),
										shared
									) }
								</span>
							</li>
						) }
					</CappedList>
				) }
			</Section>
		</div>
	);
}

/**
 * @param {Object}          props
 * @param {GraphAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { shared } = attributes;
	const [ minShared, setMinShared ] = useState( shared );
	useEffect( () => setMinShared( shared ), [ shared ] );
	const [ types, setTypes ] = useState( CHECKED );
	const [ picked, setPicked ] = useState( /** @type {?string} */ ( null ) );
	const [ text, setText ] = useState( '' );
	const [ all, setAll ] = useState( false );
	const showAll = useCallback( () => setAll( true ), [] );
	const read = useIntelRead( `block:graph:${ useId() }`, 'graph', [] );
	const { answer } = read;
	const shown = useMemo(
		() => ( answer ? ofTypes( answer, types ) : null ),
		[ answer, types ]
	);
	const graph = useMemo(
		() => ( shown ? toEntityGraph( shown, { minShared } ) : null ),
		[ shown, minShared ]
	);
	const held = shown?.entities.some( ( { key } ) => key === picked );
	const selected = held ? picked : null;
	const returnTo = useRef( /** @type {?string} */ ( null ) );
	const close = () => {
		returnTo.current = selected;
		setPicked( null );
	};
	const pickTypes = ( next ) => {
		setTypes( next );
		const type = answer?.entities.find(
			( { key } ) => key === picked
		)?.type;
		if ( type && ! next.includes( type ) ) {
			setPicked( null );
		}
	};
	return (
		<div>
			<div className="newspack-intelligence__controls">
				<SharedControl value={ minShared } onChange={ setMinShared } />
				<TypesControl value={ types } onChange={ pickTypes } />
			</div>
			<AnswerBody
				read={ read }
				rows="entities"
				skeleton={ <CanvasSkeleton /> }
				empty={ __(
					'No collected item names anyone yet.',
					'newspack-intelligence'
				) }
			>
				{ () => (
					<div className="newspack-intelligence__graph">
						<Canvas
							key={ types.join( ' ' ) }
							graph={ graph }
							selected={ selected }
							onSelect={ setPicked }
						/>
						{ null === selected ? (
							<EntityList
								entities={ shown.entities }
								onSelect={ setPicked }
								text={ text }
								onText={ setText }
								all={ all }
								onAll={ showAll }
								returnTo={ returnTo }
							/>
						) : (
							<Inspector
								graph={ graph }
								entities={ shown.entities }
								selected={ selected }
								onSelect={ setPicked }
								onClose={ close }
							/>
						) }
					</div>
				) }
			</AnswerBody>
		</div>
	);
}

/**
 * @param {Object}                                   props
 * @param {GraphAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<GraphAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<SharedControl
			value={ attributes.shared }
			onChange={ ( shared ) => setAttributes( { shared } ) }
		/>
	);
}

export default { View, Controls };

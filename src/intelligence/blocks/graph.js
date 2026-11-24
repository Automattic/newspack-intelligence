/**
 * The `graph` block: every entity the items a reader may read name, on the
 * shared force graph, a line for each pair sharing at least the slider's
 * count of items and a hull for each role. Moving the slider filters what it
 * holds; Topics reads again, topic links among the rest, and draws a graph of
 * its own.
 *
 * A press on the canvas selects an entity and lights what it reaches, and a
 * double press or Enter opens its profile. The list beside it, each entity a
 * chip, does the same without a pointer: a pick selects, and a second pick
 * of the one selected opens it.
 */

import {
	RangeControl,
	SearchControl,
	ToggleControl,
} from '@wordpress/components';
import {
	memo,
	useCallback,
	useEffect,
	useId,
	useMemo,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { formatCommandArgs } from '@newspack-nodes/runtime';
import { ForceGraph } from '@newspack-nodes/shared/forceGraph';
import { AnswerBody } from '../components/Answer';
import CappedList from '../components/CappedList';
import { EntityChip } from '../components/Cite';
import { labelOf, lightGraph, toEntityGraph } from '../components/entityGraph';
import { pageFormat } from '../components/format';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions } from '../layout/PageContext';

/** The fewest and the most items the slider asks a pair to share. */
const LEAST_SHARED = 1;
const MOST_SHARED = 50;

/** The entities listed before the rest are asked for. */
const LISTED = 30;

/**
 * @typedef {Object} GraphAttributes
 * @property {number} shared The fewest items a line stands for at first.
 */

/** @typedef {import('../components/entityGraph').GraphAnswer} GraphAnswer */
/** @typedef {import('../components/entityGraph').GraphEntity} GraphEntity */

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
 * The canvas. The page scrolls beneath it, so it takes a wheel only while it
 * has focus, as a press gives it; a pinch, which arrives as a wheel with a
 * modifier, always reaches it.
 *
 * @param {Object}                 props
 * @param {GraphAnswer}            props.answer    What `graph` answered.
 * @param {number}                 props.minShared The fewest items a line stands for.
 * @param {?string}                props.selected  The selected entity's key.
 * @param {(key: ?string) => void} props.onSelect  Selects an entity, or none.
 * @return {import('react').ReactElement} The canvas.
 */
function Canvas( { answer, minShared, selected, onSelect } ) {
	const { onOpenEntity } = useIntelActions();
	const graph = useMemo(
		() => toEntityGraph( answer, { minShared } ),
		[ answer, minShared ]
	);
	// ForceGraph reads a new array as new data.
	const lit = useMemo(
		() => lightGraph( graph, selected ),
		[ graph, selected ]
	);

	const holdWheel = ( e ) => {
		const focused = e.currentTarget.contains(
			e.currentTarget.ownerDocument.activeElement
		);
		if ( ! ( focused || e.ctrlKey || e.metaKey ) ) {
			e.stopPropagation();
		}
	};
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
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- keys, wheels and presses reach it from the canvas inside.
		<div
			className="newspack-intelligence__graph-canvas"
			onWheelCapture={ holdWheel }
			onDoubleClick={ openPressed }
			onKeyDown={ openSelected }
		>
			<ForceGraph
				nodes={ lit.nodes }
				links={ lit.links }
				groups={ lit.groups }
				selectedId={ selected }
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
 * @property {?string}                selected The selected entity's key.
 * @property {(key: string) => void}  onSelect Selects an entity.
 * @property {string}                 text     The text searched for.
 * @property {(text: string) => void} onText   Takes another.
 * @property {boolean}                all      Whether the rest were asked for.
 * @property {() => void}             onAll    Takes the ask for them.
 */

/**
 * Every entity the canvas draws, those whose name holds the text searched
 * for, as chips, the selected one marked and shown however far down it
 * stands. Enter in a search selects the first. A move of the slider leaves
 * every chip alone.
 *
 * @type {import('react').NamedExoticComponent<EntityListProps>}
 */
const EntityList = memo( function EntityList( {
	entities,
	selected,
	onSelect,
	text,
	onText,
	all,
	onAll,
} ) {
	const { onOpenEntity } = useIntelActions();
	const wanted = text.trim().toLowerCase();
	const found = entities.filter( ( entity ) =>
		labelOf( entity ).toLowerCase().includes( wanted )
	);
	const pick = ( key ) =>
		key === selected ? onOpenEntity( key ) : onSelect( key );
	return (
		<div className="newspack-intelligence__graph-list">
			<SearchControl
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
					{ __( 'No name here matches.', 'newspack-intelligence' ) }
				</p>
			) : (
				<CappedList
					rows={ found }
					limit={ LISTED }
					keep={ ( { key } ) => key === selected }
					all={ all }
					onAll={ onAll }
					className="newspack-intelligence__chips"
				>
					{ ( { key, name } ) => (
						<li key={ key }>
							<EntityChip
								entityKey={ key }
								name={ name }
								onOpen={ pick }
								selected={ key === selected }
								description={
									key === selected
										? __(
												'Selected. Press again to open its profile.',
												'newspack-intelligence'
										  )
										: undefined
								}
							/>
						</li>
					) }
				</CappedList>
			) }
		</div>
	);
} );

/**
 * @param {Object}          props
 * @param {GraphAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const { shared } = attributes;
	const [ minShared, setMinShared ] = useState( shared );
	useEffect( () => setMinShared( shared ), [ shared ] );
	const [ topics, setTopics ] = useState( false );
	const [ picked, setPicked ] = useState( /** @type {?string} */ ( null ) );
	const [ text, setText ] = useState( '' );
	const [ all, setAll ] = useState( false );
	const showAll = useCallback( () => setAll( true ), [] );
	const read = useIntelRead(
		`block:graph:${ useId() }`,
		'graph',
		formatCommandArgs( [], topics ? { topics: true } : {} )
	);
	const held = read.answer?.entities.some( ( { key } ) => key === picked );
	const selected = held ? picked : null;
	return (
		<div>
			<div className="newspack-intelligence__controls">
				<SharedControl value={ minShared } onChange={ setMinShared } />
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Topics', 'newspack-intelligence' ) }
					checked={ topics }
					onChange={ setTopics }
				/>
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
				{ ( answer ) => (
					<>
						{ answer.truncated && (
							<p className="newspack-nodes-status is-muted">
								{ sprintf(
									/* translators: %s: how many pairs of entities the answer holds. */
									__(
										'Only the %s pairs sharing the most are read.',
										'newspack-intelligence'
									),
									pageFormat( Intl.NumberFormat ).format(
										answer.pairs.length
									)
								) }
							</p>
						) }
						<div className="newspack-intelligence__graph">
							<Canvas
								key={ topics ? 'topics' : 'names' }
								answer={ answer }
								minShared={ minShared }
								selected={ selected }
								onSelect={ setPicked }
							/>
							<EntityList
								entities={ answer.entities }
								selected={ selected }
								onSelect={ setPicked }
								text={ text }
								onText={ setText }
								all={ all }
								onAll={ showAll }
							/>
						</div>
					</>
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

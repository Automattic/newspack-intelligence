/**
 * The `graph` block: every entity the items a reader may read are about, on
 * the shared force graph, and a line for each one and what its Related list
 * holds, sharing at least the slider's count of items. The slider filters
 * what it holds, and so does a checkbox for each type, which lays the graph
 * out afresh.
 *
 * A press on the canvas selects an entity and lights what it reaches, and a
 * double press or Enter opens its profile. The list beside it, each entity a
 * chip, does the same without a pointer: a pick selects, and a second pick
 * of the one selected opens it.
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
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ForceGraph } from '@newspack-nodes/shared/forceGraph';
import { AnswerBody } from '../components/Answer';
import CappedList from '../components/CappedList';
import { EntityChip } from '../components/Cite';
import {
	labelOf,
	lightGraph,
	ofTypes,
	toEntityGraph,
} from '../components/entityGraph';
import { words } from '../components/words';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions } from '../layout/PageContext';

/** The fewest and the most items the slider asks a pair to share. */
const LEAST_SHARED = 1;
const MOST_SHARED = 50;

/** The types left out until their checkbox is checked. */
const UNCHECKED = [ 'product', 'topic' ];

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
 * @param {GraphAnswer}            props.answer    The part of the answer it draws.
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
					{ wanted
						? __( 'No name here matches.', 'newspack-intelligence' )
						: __( 'No names to list.', 'newspack-intelligence' ) }
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
	const [ types, setTypes ] = useState( () =>
		Object.keys( words( 'typeWords' ) ).filter(
			( type ) => ! UNCHECKED.includes( type )
		)
	);
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
	const held = shown?.entities.some( ( { key } ) => key === picked );
	const selected = held ? picked : null;
	return (
		<div>
			<div className="newspack-intelligence__controls">
				<SharedControl value={ minShared } onChange={ setMinShared } />
				<TypesControl value={ types } onChange={ setTypes } />
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
							answer={ shown }
							minShared={ minShared }
							selected={ selected }
							onSelect={ setPicked }
						/>
						<EntityList
							entities={ shown.entities }
							selected={ selected }
							onSelect={ setPicked }
							text={ text }
							onText={ setText }
							all={ all }
							onAll={ showAll }
						/>
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

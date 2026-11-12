import { useMemo, useRef, useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import { Unread } from './Answer';

/** The fewest letters, marks or digits a prefix `names` is asked about holds. */
export const MIN_PREFIX = 2;

/**
 * How many letters, marks or digits a text holds as `Entity_Index::norm()`
 * reads it: punctuation and spaces aside, and a leading "the" dropped.
 *
 * @param {string} text Any text.
 * @return {number} The count `names` holds to MIN_PREFIX.
 */
const significant = ( text ) =>
	text
		.toLowerCase()
		.replace( /[^\p{L}\p{M}\p{N}]+/gu, ' ' )
		.trim()
		.replace( /^the /, '' )
		.replace( / /g, '' ).length;

/**
 * The entities `names` answers for the text an entity box holds, each as an
 * option keyed by its entity, labelled by its name, and a name two of them
 * share labelled with each key beside it. Only an answer to this text is
 * offered, a remembered one until the fresh one lands, and none while it is
 * owed. A text holding fewer than MIN_PREFIX letters, marks or digits asks
 * nothing.
 *
 * @param {string} text The text the box holds.
 * @return {{options: Array<{value: string, label: string, name: string}>, error: ?string, refresh: () => void}}
 *   The options, the same array while the answer is; the refusal of the
 *   latest ask; and `refresh()`, which asks it again.
 */
export function useEntityNames( text ) {
	const prefix = text.trim();
	const names = useIntelRead(
		'search:names',
		'names',
		significant( prefix ) < MIN_PREFIX ? null : commandArgs( { prefix } )
	);
	const entities = names.answer?.entities;
	const options = useMemo( () => optionsOf( entities ?? [] ), [ entities ] );
	return { options, error: names.error, refresh: names.refresh };
}

/**
 * An answer's options, a name two entities share labelled with each key.
 *
 * @param {Array<{entity_key: string, name: string}>} entities The answer.
 * @return {Array<{value: string, label: string, name: string}>} The options.
 */
function optionsOf( entities ) {
	const counts = new Map();
	entities.forEach( ( { name } ) =>
		counts.set( name, ( counts.get( name ) ?? 0 ) + 1 )
	);
	return entities.map( ( { entity_key: key, name } ) => ( {
		value: key,
		label: counts.get( name ) > 1 ? `${ name } (${ key })` : name,
		name,
	} ) );
}

/** Joins the typed text to a key; uncased, so it lowercases nothing near it. */
const JOIN = '\uE000';

/**
 * An entity box: the stock ComboboxControl picking one of the options
 * `useEntityNames()` offers for the text typed, with the refusal of its
 * latest ask beneath it. Every option the read answers is listed. A click,
 * or the arrow keys and Enter, hands the owner the key picked and its name
 * at once; typed text alone picks nothing, and Reset hands on '' and null.
 * Escape in a box whose list is closed goes to `onEscape`. The box shows the
 * name of what it last picked while the owner holds that key, and any other
 * key as itself.
 *
 * @param {Object}                               props
 * @param {string}                               props.label      The field's label.
 * @param {string}                               props.value      The key the owner holds, '' for none.
 * @param {(key: string, name: ?string) => void} props.onChange   Takes each pick.
 * @param {string}                               [props.exclude]  A key never offered.
 * @param {() => void}                           [props.onEscape] Takes Escape while the list is closed.
 * @return {import('react').ReactElement} The field and its refusal.
 */
export default function EntityPicker( {
	label,
	value,
	onChange,
	exclude = '',
	onEscape,
} ) {
	const [ text, setText ] = useState( '' );
	const [ held, setHeld ] = useState( null );
	const field = useRef( null );
	const names = useEntityNames( text );
	const options = useMemo( () => {
		const current = held?.value === value ? held : { value, shown: value };
		// The held one keeps its name, which the closed box shows.
		const listed = names.options
			.filter( ( one ) => exclude !== one.value && value !== one.value )
			.map( ( one ) => ( {
				...one,
				shown: one.label,
				// Leading with the text, the stock filter lists every one.
				label: `${ text }${ JOIN }${ one.value }`,
			} ) );
		return value
			? [ { ...current, label: current.shown }, ...listed ]
			: listed;
	}, [ names.options, exclude, value, held, text ] );

	return (
		<>
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions -- the combobox inside takes the keys; this only listens. */ }
			<div
				ref={ field }
				onKeyDownCapture={ ( event ) => {
					const composing =
						event.nativeEvent.isComposing || 229 === event.keyCode;
					const input = field.current.querySelector( 'input' );
					if (
						'Escape' === event.key &&
						! composing &&
						'false' === input.getAttribute( 'aria-expanded' )
					) {
						onEscape?.();
					}
				} }
			>
				<ComboboxControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ label }
					value={ value || null }
					options={ options }
					onFilterValueChange={ setText }
					__experimentalRenderItem={ ( { item } ) => item.shown }
					onChange={ ( key ) => {
						const option = options.find(
							( one ) => key === one.value
						);
						setText( '' );
						// Stock Enter re-picks a dropped highlight.
						if ( key && ! option ) {
							return;
						}
						setHeld( option ?? null );
						onChange( key ?? '', option?.name ?? null );
					} }
					placeholder={ __(
						'Start typing a client or company name',
						'newspack-intelligence'
					) }
				/>
			</div>
			<Unread error={ names.error } refresh={ names.refresh } />
		</>
	);
}

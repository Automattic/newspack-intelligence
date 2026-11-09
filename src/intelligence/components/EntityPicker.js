import { useEffect, useId, useState } from '@wordpress/element';
import { BaseControl } from '@wordpress/components';
import CommitInput from '@newspack-nodes/shared/components/CommitInput';
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
 * The entities `names` answers for the text an entity box holds. Each is
 * offered under the name the reader types toward, its key as its label, and
 * a name two of them share under each key. The last answer stands until a
 * new one lands. A text holding fewer than MIN_PREFIX letters, marks or
 * digits asks nothing and clears it, and a text naming an offered entity,
 * by its name or its key, asks nothing.
 *
 * @param {string} text The text the box holds.
 * @return {{suggestions: Array<{value: string, label: string}>, keyOf: (text: string) => string, error: ?string, refresh: () => void}}
 *   The suggestions; `keyOf()`, the key an offered name stands for, any
 *   other text as given; the refusal of the latest ask; and `refresh()`,
 *   which asks it again.
 */
export function useEntityNames( text ) {
	const [ entities, setEntities ] = useState( [] );
	const prefix = text.trim();
	const short = significant( prefix ) < MIN_PREFIX;
	const shared = ( name ) =>
		entities.filter( ( one ) => one.name === name ).length > 1;
	const suggestions = entities.map( ( { entity_key: key, name } ) =>
		shared( name )
			? { value: key, label: name }
			: { value: name, label: key }
	);
	const keys = Object.fromEntries(
		entities.map( ( { entity_key: key, name } ) => [
			shared( name ) ? key : name,
			key,
		] )
	);
	const named =
		undefined !== keys[ prefix ] ||
		Object.values( keys ).includes( prefix );
	const names = useIntelRead(
		'search:names',
		'names',
		short || named ? null : commandArgs( { prefix } )
	);
	const answered = names.answer?.entities;
	useEffect( () => {
		if ( answered ) {
			setEntities( answered );
		}
	}, [ answered ] );
	useEffect( () => {
		if ( short ) {
			setEntities( [] );
		}
	}, [ short ] );
	return {
		suggestions,
		keyOf: ( typed ) => keys[ typed.trim() ] ?? typed,
		error: names.error,
		refresh: names.refresh,
	};
}

/**
 * An entity box: the shared `CommitInput` holding the text typed, every edit
 * handed on as typed, suggesting what `useEntityNames()` offers, with the
 * refusal of its latest ask beneath it. The text stays as given, so a name
 * that leads another can be typed past; its owner reads the key a name
 * stands for through `keyOf()` when it runs the search.
 *
 * @param {Object}                            props
 * @param {string}                            props.label    The field's label.
 * @param {string}                            props.value    The text the box holds.
 * @param {(value: string) => void}           props.onChange Takes each edit.
 * @param {ReturnType<typeof useEntityNames>} props.names    What `names` offers for `value`.
 * @return {import('react').ReactElement} The field, its suggestions and its refusal.
 */
export default function EntityPicker( { label, value, onChange, names } ) {
	const id = useId();
	return (
		<BaseControl id={ id } label={ label }>
			<CommitInput
				id={ id }
				className="components-text-control__input is-next-40px-default-size"
				value={ value }
				onChange={ onChange }
				suggestions={ names.suggestions }
				placeholder="organization:acme-gazette"
			/>
			<Unread error={ names.error } refresh={ names.refresh } />
		</BaseControl>
	);
}

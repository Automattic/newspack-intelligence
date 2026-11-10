/**
 * A layout control picking saved team views by name, holding their keys.
 */

import { FormTokenField } from '@wordpress/components';
import { useIntelRead } from '../hooks/useIntelCommand';

/**
 * @param {Object}                   props
 * @param {string}                   props.scope    Names its `lenses` read.
 * @param {string}                   props.label    The field's label.
 * @param {string[]}                 props.value    The keys picked.
 * @param {number}                   [props.max]    The most it holds.
 * @param {(keys: string[]) => void} props.onChange Takes the keys picked.
 * @return {import('react').ReactElement} The field.
 */
export default function LensTokens( { scope, label, value, max, onChange } ) {
	const lenses = useIntelRead( scope, 'lenses', [] ).answer?.lenses ?? [];
	const nameOf = Object.fromEntries(
		lenses.map( ( lens ) => [ lens.lens_key, lens.name ] )
	);
	const keyOf = Object.fromEntries(
		lenses.map( ( lens ) => [ lens.name, lens.lens_key ] )
	);
	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ label }
			value={ value.map( ( key ) => nameOf[ key ] ?? key ) }
			suggestions={ lenses.map( ( lens ) => lens.name ) }
			maxLength={ max }
			__experimentalValidateInput={ ( name ) => name in keyOf }
			__experimentalExpandOnFocus
			onChange={ ( names ) =>
				onChange(
					names
						.map( ( one ) =>
							'string' === typeof one ? one : one.value
						)
						.map( ( name ) => keyOf[ name ] ?? name )
				)
			}
		/>
	);
}

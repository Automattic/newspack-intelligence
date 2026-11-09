import { FormTokenField } from '@wordpress/components';
import { listOf } from './lensDraft';
import { words } from './words';

/**
 * A field holding several values of one closed list, each picked as a token
 * shown by its word: the list's values when it is given, else every key its
 * word map words; any other token is refused. The values travel as the
 * comma-separated text the lens and search forms hold.
 *
 * @param {Object}                  props
 * @param {string}                  props.label    The field's label.
 * @param {string}                  props.map      The vocabulary map wording the values.
 * @param {?string[]}               props.values   The list, or null for every key the map words.
 * @param {string}                  props.value    The values picked, comma-separated.
 * @param {(value: string) => void} props.onChange Takes the values picked, comma-separated.
 * @return {import('react').ReactElement} The field.
 */
export default function ListPicker( { label, map, values, value, onChange } ) {
	const shown = words( map );
	const offered = values ?? Object.keys( shown );
	const allowed = ( token ) => offered.includes( token );
	return (
		<FormTokenField
			label={ label }
			value={ listOf( value ) }
			suggestions={ offered }
			displayTransform={ ( token ) => shown[ token ] ?? token }
			__experimentalValidateInput={ allowed }
			__experimentalExpandOnFocus
			tokenizeOnBlur
			// A token typed with a comma skips the field's own validation.
			onChange={ ( picked ) =>
				onChange( picked.filter( allowed ).join( ', ' ) )
			}
			__next40pxDefaultSize
		/>
	);
}

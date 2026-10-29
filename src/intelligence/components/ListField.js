import { __, sprintf } from '@wordpress/i18n';
import { TextControl } from '@wordpress/components';

/**
 * A field holding several values, entered separated by commas, with the
 * values a closed list allows named beneath it.
 *
 * @param {{choices?: ?string[]} & import('react').ComponentProps<typeof TextControl>} props The values a closed list allows, null for free text, and the field's own props.
 * @return {import('react').ReactElement} The field.
 */
export default function ListField( { choices, ...field } ) {
	return (
		<TextControl
			__next40pxDefaultSize
			help={
				choices
					? sprintf(
							/* translators: %s: the values allowed, comma-separated. */
							__(
								'Separate values with commas, from: %s',
								'newspack-intelligence'
							),
							choices.join( ', ' )
					  )
					: __(
							'Separate values with commas.',
							'newspack-intelligence'
					  )
			}
			{ ...field }
		/>
	);
}

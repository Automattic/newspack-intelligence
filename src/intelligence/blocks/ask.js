/**
 * The `ask` block: the Ask box under the block's own label, placeholder and
 * examples, holding the ask the page holds.
 */

import { __ } from '@wordpress/i18n';
import { FormTokenField, TextControl } from '@wordpress/components';
import AskBox from '../components/AskBox';
import { useIntelActions } from '../layout/PageContext';

/** The most examples a block offers, as its row bounds them. */
const MAX_EXAMPLES = 4;

/**
 * @typedef {Object} AskAttributes
 * @property {string}   label       The field's label.
 * @property {string}   placeholder Its placeholder.
 * @property {string[]} examples    The questions offered before a first ask.
 */

/**
 * @param {Object}        props
 * @param {AskAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The box.
 */
function View( { attributes } ) {
	const { asked, onAsked, onSearchFor, onOpenEntity, onSave, canTune } =
		useIntelActions();
	return (
		<AskBox
			{ ...attributes }
			asked={ asked }
			onAsked={ onAsked }
			onSearchFor={ onSearchFor }
			onOpenEntity={ onOpenEntity }
			onSave={ onSave }
			canTune={ canTune }
		/>
	);
}

/**
 * @param {Object}                                 props
 * @param {AskAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<AskAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Label', 'newspack-intelligence' ) }
				value={ attributes.label }
				onChange={ ( label ) => setAttributes( { label } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Placeholder', 'newspack-intelligence' ) }
				value={ attributes.placeholder }
				onChange={ ( placeholder ) => setAttributes( { placeholder } ) }
			/>
			<FormTokenField
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Example questions', 'newspack-intelligence' ) }
				value={ attributes.examples }
				maxLength={ MAX_EXAMPLES }
				onChange={ ( examples ) =>
					setAttributes( {
						examples: examples.map( ( one ) =>
							'string' === typeof one ? one : one.value
						),
					} )
				}
			/>
		</>
	);
}

export default { View, Controls };

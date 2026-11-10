/**
 * The `health` block: collection's details panel drawn inline, its summary
 * rows or every source too, with the fixes for an operator alone, each
 * inert while its layout is edited.
 */

import { useContext } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { SelectControl } from '@wordpress/components';
import { CanManage } from '../components/Answer';
import { HealthBlock } from '../components/HealthStrip';

/**
 * @param {Object}                          props
 * @param {{detail: ('summary'|'sources')}} props.attributes Its attributes.
 * @return {import('react').ReactElement} The panel.
 */
function View( { attributes: { detail } } ) {
	return (
		<HealthBlock operator={ useContext( CanManage ) } detail={ detail } />
	);
}

/**
 * @param {Object}                                          props
 * @param {{detail: ('summary'|'sources')}}                 props.attributes    Its attributes.
 * @param {(next: {detail: ('summary'|'sources')}) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The control.
 */
function Controls( { attributes: { detail }, setAttributes } ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __( 'Show', 'newspack-intelligence' ) }
			value={ detail }
			options={ [
				{
					label: __( 'A summary', 'newspack-intelligence' ),
					value: 'summary',
				},
				{
					label: __( 'Every source', 'newspack-intelligence' ),
					value: 'sources',
				},
			] }
			onChange={ ( value ) =>
				setAttributes( {
					detail: /** @type {'summary'|'sources'} */ ( value ),
				} )
			}
		/>
	);
}

export default { View, Controls };

/**
 * The `folder-board` block: the Folders board on a composed page, the
 * folders it names or every one, opening on its `show`; its writes are a
 * curator's alone, and none while the layout is edited.
 */

import { FormTokenField, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import FolderBoard, { showOptions } from '../components/FolderBoard';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelActions } from '../layout/PageContext';

/**
 * @typedef {Object} FolderBoardAttributes
 * @property {string}   show    "all", "unused" or a place.
 * @property {string[]} folders The folders shown, every one when empty.
 */

/**
 * @param {Object}                props
 * @param {FolderBoardAttributes} props.attributes Its attributes.
 * @param {boolean}               props.editing    Whether its layout is being edited.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes: { show, folders }, editing } ) {
	const { canTune } = useIntelActions();
	return (
		<FolderBoard
			show={ show }
			folders={ folders }
			canTune={ canTune && ! editing }
		/>
	);
}

/**
 * A field picking folders by name, holding their keys.
 *
 * @param {Object}                   props
 * @param {string[]}                 props.value    The keys picked.
 * @param {(keys: string[]) => void} props.onChange Takes the keys picked.
 * @return {import('react').ReactElement} The field.
 */
function FolderTokens( { value, onChange } ) {
	const listed =
		useIntelRead( 'layout:folder-board', 'collections', [] ).answer
			?.collections ?? [];
	const nameOf = Object.fromEntries(
		listed.map( ( one ) => [ one.collection_key, one.name ] )
	);
	const keyOf = Object.fromEntries(
		listed.map( ( one ) => [ one.name, one.collection_key ] )
	);
	return (
		<FormTokenField
			__next40pxDefaultSize
			__nextHasNoMarginBottom
			label={ __(
				'Folders (none shows every one)',
				'newspack-intelligence'
			) }
			value={ value.map( ( key ) => nameOf[ key ] ?? key ) }
			suggestions={ listed.map( ( one ) => one.name ) }
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

/**
 * @param {Object}                                         props
 * @param {FolderBoardAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<FolderBoardAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Opens on', 'newspack-intelligence' ) }
				value={ attributes.show }
				options={ showOptions() }
				onChange={ ( show ) => setAttributes( { show } ) }
			/>
			<FolderTokens
				value={ attributes.folders }
				onChange={ ( folders ) => setAttributes( { folders } ) }
			/>
		</>
	);
}

export default { View, Controls };

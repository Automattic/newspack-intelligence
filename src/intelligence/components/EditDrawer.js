import { useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl } from '@wordpress/components';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { roleText } from './format';

/**
 * The options a closed list offers a select.
 *
 * @param {string[]} values The list.
 * @return {{label: string, value: string}[]} The options.
 */
const optionsOf = ( values ) =>
	values.map( ( value ) => ( { label: value, value } ) );

/**
 * One curation form: a legend naming it, the fields it collects and the
 * button sending them. Enter in a field sends too.
 *
 * @param {Object}                    props
 * @param {string}                    props.legend   Names the form and its button.
 * @param {() => void}                props.onSubmit Sends the form.
 * @param {import('react').ReactNode} props.children The fields.
 * @return {import('react').ReactElement} The form.
 */
function EditForm( { legend, onSubmit, children } ) {
	return (
		<form
			className="newspack-intelligence__edit"
			role="group"
			aria-label={ legend }
			{ ...submitProps( onSubmit ) }
		>
			<strong>{ legend }</strong>
			{ children }
			<button type="submit" className="button">
				{ legend }
			</button>
		</form>
	);
}

/**
 * A form adding a value of one kind to the entity: a name or an identifier.
 *
 * @param {Object}                                props
 * @param {string}                                props.legend   Names the form.
 * @param {string[]}                              props.kinds    The kinds it offers.
 * @param {(kind: string, value: string) => void} props.onSubmit Sends the pair.
 * @return {import('react').ReactElement} The form.
 */
function KindValueForm( { legend, kinds, onSubmit } ) {
	const [ kind, setKind ] = useState( kinds[ 0 ] );
	const [ value, setValue ] = useState( '' );
	return (
		<EditForm legend={ legend } onSubmit={ () => onSubmit( kind, value ) }>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Kind', 'newspack-intelligence' ) }
				options={ optionsOf( kinds ) }
				value={ kind }
				onChange={ setKind }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Value', 'newspack-intelligence' ) }
				value={ value }
				onChange={ setValue }
			/>
		</EditForm>
	);
}

/**
 * The form opening a role on the entity from now.
 *
 * @param {Object}                 props
 * @param {string[]}               props.roles    The roles it offers.
 * @param {(role: string) => void} props.onSubmit Sends the role.
 * @return {import('react').ReactElement} The form.
 */
function RoleForm( { roles, onSubmit } ) {
	const [ role, setRole ] = useState( roles[ 0 ] );
	return (
		<EditForm
			legend={ __( 'Assert role', 'newspack-intelligence' ) }
			onSubmit={ () => onSubmit( role ) }
		>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Role', 'newspack-intelligence' ) }
				options={ optionsOf( roles ) }
				value={ role }
				onChange={ setRole }
			/>
		</EditForm>
	);
}

/**
 * An entity's curation behind an "Edit" toggle, closed until opened: each
 * role it holds open with the control ending it, then the forms adding a
 * name or an identifier and asserting a role. Each edit goes through the
 * write the view hands it, which re-reads the record once it lands.
 *
 * @param {Object}                                     props
 * @param {string}                                     props.entityKey  The entity.
 * @param {string}                                     props.name       What its heading shows.
 * @param {Object[]}                                   props.openRoles  The role rows it holds open.
 * @param {Object}                                     props.vocabulary The ontology's closed lists.
 * @param {Object<string, {run: (args: Object) => *}>} props.writes     `addName`, `addIdentifier`, `assertRole` and `endRole`.
 * @return {import('react').ReactElement} The toggle and the drawer it opens.
 */
export default function EditDrawer( {
	entityKey,
	name,
	openRoles,
	vocabulary,
	writes,
} ) {
	const [ open, setOpen ] = useState( false );
	const drawerId = useId();
	const { addName, addIdentifier, assertRole, endRole } = writes;
	return (
		<>
			<button
				type="button"
				className="button"
				aria-expanded={ open }
				aria-controls={ drawerId }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Edit', 'newspack-intelligence' ) }
			</button>
			{ open && (
				<div
					id={ drawerId }
					role="region"
					className="newspack-intelligence__drawer"
					aria-label={ sprintf(
						/* translators: %s: the entity's name. */
						__( 'Edit %s', 'newspack-intelligence' ),
						name
					) }
				>
					{ 0 < openRoles.length && (
						<ul className="newspack-intelligence__facts">
							{ openRoles.map( ( role ) => (
								<li
									key={ `${ role.role } ${ role.from_ts }` }
									className="newspack-intelligence__actions"
								>
									{ roleText( role ) }
									<button
										type="button"
										className="button button-small"
										onClick={ () =>
											endRole.run( {
												key: entityKey,
												role: role.role,
											} )
										}
									>
										{ sprintf(
											/* translators: %s: the role ended. */
											__(
												'End %s',
												'newspack-intelligence'
											),
											role.role
										) }
									</button>
								</li>
							) ) }
						</ul>
					) }
					<div className="newspack-intelligence__edits">
						<KindValueForm
							legend={ __( 'Add name', 'newspack-intelligence' ) }
							kinds={ vocabulary.nameKinds }
							onSubmit={ ( kind, value ) =>
								addName.run( { key: entityKey, kind, value } )
							}
						/>
						<KindValueForm
							legend={ __(
								'Add identifier',
								'newspack-intelligence'
							) }
							kinds={ vocabulary.identifierKinds }
							onSubmit={ ( kind, value ) =>
								addIdentifier.run( {
									key: entityKey,
									kind,
									value,
								} )
							}
						/>
						<RoleForm
							roles={ vocabulary.roles }
							onSubmit={ ( role ) =>
								assertRole.run( { key: entityKey, role } )
							}
						/>
					</div>
				</div>
			) }
		</>
	);
}

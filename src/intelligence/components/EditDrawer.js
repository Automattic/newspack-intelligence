import { useId, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import { submitProps } from '@newspack-nodes/shared/utils/submitProps';
import { CLIENT } from '../hooks/useEndClient';
import { entityName, roleText } from './format';
import Sheet from './Sheet';
import { word } from './words';

/**
 * Each Add form lays its fields out in one card with no header of its own.
 *
 * @type {import('@wordpress/dataviews/wp').Form['layout']}
 */
const CARD = { type: 'card', withHeader: false };

/**
 * A closed list as a field's elements, each shown by its word.
 *
 * @param {string[]} values The list.
 * @param {string}   words  The vocabulary map wording it.
 * @return {{label: string, value: string}[]} The elements.
 */
const elementsOf = ( values, words ) =>
	values.map( ( value ) => ( { label: word( words, value ), value } ) );

/**
 * A kind picked from a closed list, by a select or, for a long list, a
 * combobox.
 *
 * @param {string}   label  The field's label.
 * @param {string[]} values The list.
 * @param {string}   words  The vocabulary map wording it.
 * @return {Object} The field.
 */
const kindField = ( label, values, words ) => ( {
	id: 'kind',
	label,
	type: 'text',
	Edit: 'adaptiveSelect',
	elements: elementsOf( values, words ),
	isValid: { required: true, elements: true },
} );

/** The value a name or an identifier adds. */
const VALUE = {
	id: 'value',
	label: __( 'Value', 'newspack-intelligence' ),
	type: 'text',
	isValid: { required: true },
};

/**
 * One Add form: its fields as a DataForm card, sent by its button, or by
 * Enter in a field, only once every field is valid; a send clears the form.
 *
 * @param {Object}                 props
 * @param {string}                 props.legend   Names the form and its button.
 * @param {Object[]}               props.fields   The DataForm fields.
 * @param {Object}                 props.blank    The data it starts from.
 * @param {(data: Object) => void} props.onSubmit Sends the data.
 * @return {import('react').ReactElement} The form.
 */
function AddForm( { legend, fields, blank, onSubmit } ) {
	const [ data, setData ] = useState( blank );
	/** @type {import('@wordpress/dataviews/wp').Form} */
	const form = useMemo(
		() => ( { layout: CARD, fields: fields.map( ( field ) => field.id ) } ),
		[ fields ]
	);
	const { validity, isValid } = useFormValidity( data, fields, form );
	const filled = fields.every(
		( field ) => '' !== ( data[ field.id ] ?? '' )
	);
	const send = () => {
		if ( isValid && filled ) {
			onSubmit( data );
			setData( blank );
		}
	};
	return (
		<form
			className="newspack-intelligence__edit"
			role="group"
			aria-label={ legend }
			{ ...submitProps( send ) }
		>
			<DataForm
				data={ data }
				fields={ fields }
				form={ form }
				validity={ validity }
				onChange={ ( edits ) => setData( { ...data, ...edits } ) }
			/>
			<Button
				type="submit"
				variant="secondary"
				accessibleWhenDisabled
				disabled={ ! ( isValid && filled ) }
			>
				{ legend }
			</Button>
		</form>
	);
}

/**
 * One section of the drawer: what the entity holds, then the form adding
 * to it.
 *
 * @param {Object}                    props
 * @param {string}                    props.heading  Names the section.
 * @param {import('react').ReactNode} props.children The values and the form.
 * @return {import('react').ReactElement} The section.
 */
function Part( { heading, children } ) {
	const id = useId();
	return (
		<section
			className="newspack-intelligence__drawer-part"
			aria-labelledby={ id }
		>
			<h3 id={ id }>{ heading }</h3>
			{ children }
		</section>
	);
}

/**
 * Values the entity holds, each by its kind's word.
 *
 * @param {Object}                               props
 * @param {Array<{kind: string, value: string}>} props.values The values.
 * @param {string}                               props.words  The vocabulary map wording their kinds.
 * @return {?import('react').ReactElement} The list.
 */
function Values( { values, words } ) {
	if ( 0 === values.length ) {
		return null;
	}
	return (
		<dl className="newspack-intelligence__values">
			{ values.map( ( one ) => (
				<div key={ `${ one.kind } ${ one.value }` }>
					<dt>{ word( words, one.kind ) }</dt>
					<dd>{ one.value }</dd>
				</div>
			) ) }
		</dl>
	);
}

/**
 * The drawer's contents: each section listing what the entity holds above
 * the form adding to it, then the earlier records merged into it.
 *
 * @param {Object}                                     props
 * @param {Object}                                     props.entity      The record.
 * @param {Object}                                     props.vocabulary  The ontology's closed lists.
 * @param {Object<string, {run: (args: Object) => *}>} props.writes      `addName`, `addIdentifier`, `assertRole` and `endRole`.
 * @param {() => void}                                 props.onEndClient Asks whether to end the client role.
 * @return {import('react').ReactElement} The sections.
 */
function Sections( { entity, vocabulary, writes, onEndClient } ) {
	const key = entity.entity_key;
	const { addName, addIdentifier, assertRole, endRole } = writes;
	const open = entity.roles.filter( ( role ) => 0 === role.to_ts );
	const nameFields = useMemo(
		() => [
			kindField(
				__( 'Kind', 'newspack-intelligence' ),
				vocabulary.nameKinds,
				'nameKindWords'
			),
			VALUE,
		],
		[ vocabulary.nameKinds ]
	);
	const idFields = useMemo(
		() => [
			kindField(
				__( 'Kind', 'newspack-intelligence' ),
				vocabulary.identifierKinds,
				'identifierWords'
			),
			VALUE,
		],
		[ vocabulary.identifierKinds ]
	);
	const roleFields = useMemo(
		() => [
			{
				...kindField(
					__( 'Role', 'newspack-intelligence' ),
					vocabulary.roles,
					'roleWords'
				),
				id: 'role',
			},
		],
		[ vocabulary.roles ]
	);
	return (
		<>
			<Part heading={ __( 'Names', 'newspack-intelligence' ) }>
				<Values values={ entity.names } words="nameKindWords" />
				<AddForm
					legend={ __( 'Add a name', 'newspack-intelligence' ) }
					fields={ nameFields }
					blank={ { kind: '', value: '' } }
					onSubmit={ ( { kind, value } ) =>
						addName.run( { key, kind, value } )
					}
				/>
			</Part>
			<Part heading={ __( 'Identifiers', 'newspack-intelligence' ) }>
				<Values values={ entity.identifiers } words="identifierWords" />
				<AddForm
					legend={ __(
						'Add an identifier',
						'newspack-intelligence'
					) }
					fields={ idFields }
					blank={ { kind: '', value: '' } }
					onSubmit={ ( { kind, value } ) =>
						addIdentifier.run( { key, kind, value } )
					}
				/>
			</Part>
			<Part heading={ __( 'Roles', 'newspack-intelligence' ) }>
				{ 0 < open.length && (
					<ul className="newspack-intelligence__facts">
						{ open.map( ( role ) => (
							<li
								key={ `${ role.role } ${ role.from_ts }` }
								className="newspack-intelligence__actions"
							>
								{ roleText( role ) }
								<Button
									variant="secondary"
									size="small"
									onClick={
										CLIENT === role.role
											? onEndClient
											: () =>
													endRole.run( {
														key,
														role: role.role,
													} )
									}
								>
									{ CLIENT === role.role
										? sprintf(
												/* translators: %s: the role ended, which asks first. */
												__(
													'End %s…',
													'newspack-intelligence'
												),
												word( 'roleWords', role.role )
										  )
										: sprintf(
												/* translators: %s: the role ended. */
												__(
													'End %s',
													'newspack-intelligence'
												),
												word( 'roleWords', role.role )
										  ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				<AddForm
					legend={ __( 'Add a role', 'newspack-intelligence' ) }
					fields={ roleFields }
					blank={ { role: '' } }
					onSubmit={ ( { role } ) => assertRole.run( { key, role } ) }
				/>
			</Part>
			{ 0 < entity.merged_from.length && (
				<details>
					<summary>
						{ sprintf(
							/* translators: %d: earlier records merged into this one. */
							_n(
								'Merged from: %d earlier record',
								'Merged from: %d earlier records',
								entity.merged_from.length,
								'newspack-intelligence'
							),
							entity.merged_from.length
						) }
					</summary>
					<ul>
						{ entity.merged_from.map( ( merged ) => (
							<li key={ merged }>{ merged }</li>
						) ) }
					</ul>
				</details>
			) }
		</>
	);
}

/**
 * An entity's curation behind **Edit**, which opens it in a sheet beside the
 * page, or rising from its foot when narrow, named "Edit %s". Its names,
 * identifiers and open roles each stand above the form adding one, and each
 * edit goes through the write the view hands it, but the client role, whose
 * end asks first through `onEndClient`.
 *
 * @param {Object}                                     props
 * @param {Object}                                     props.entity      The record.
 * @param {Object}                                     props.vocabulary  The ontology's closed lists.
 * @param {Object<string, {run: (args: Object) => *}>} props.writes      `addName`, `addIdentifier`, `assertRole` and `endRole`.
 * @param {() => void}                                 props.onEndClient Asks whether to end the client role.
 * @return {import('react').ReactElement} **Edit** and the drawer it opens.
 */
export default function EditDrawer( {
	entity,
	vocabulary,
	writes,
	onEndClient,
} ) {
	const [ open, setOpen ] = useState( false );
	const name = entityName( entity );
	return (
		<>
			<Button
				variant="secondary"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ __( 'Edit', 'newspack-intelligence' ) }
			</Button>
			{ open && (
				<Sheet
					title={ sprintf(
						/* translators: %s: the entity's name. */
						__( 'Edit %s', 'newspack-intelligence' ),
						name
					) }
					closeLabel={ __( 'Close', 'newspack-intelligence' ) }
					onClose={ () => setOpen( false ) }
				>
					<Sections
						entity={ entity }
						vocabulary={ vocabulary }
						writes={ writes }
						onEndClient={ onEndClient }
					/>
				</Sheet>
			) }
		</>
	);
}

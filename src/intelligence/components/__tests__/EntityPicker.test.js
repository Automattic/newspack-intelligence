/* eslint-env jest */
/**
 * EntityPicker is the stock ComboboxControl picking one entity from what
 * `names` answers for the text typed. Every entity the read answers shows
 * in the visible list, those whose names do not spell the text included,
 * and a click, or the arrow keys and Enter, hands the owner the key picked
 * and its name at once. Typed text alone picks nothing. Reset hands on ''.
 * Two entities sharing a name are offered with their keys beside it, and
 * the box shows what the owner holds: the name it picked, or a key it was
 * handed.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { pageData } from '../../views/__tests__/fixtures';
import EntityPicker, { useEntityNames } from '../EntityPicker';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

let wire;

// jsdom lays nothing out, so it has no scrollIntoView for the picker.
const { scrollIntoView } = window.Element.prototype;

beforeAll( () => {
	window.Element.prototype.scrollIntoView = jest.fn();
} );

afterAll( () => {
	window.Element.prototype.scrollIntoView = scrollIntoView;
} );

/** Two entities `Quill` leads and two sharing the name `Quill Gazette`. */
const QUILL = [
	{ entity_key: 'organization:quill-press', name: 'Quill Press' },
	{ entity_key: 'organization:quill-review', name: 'Quill Review' },
];
const GAZETTES = [
	{ entity_key: 'organization:quill-gazette-east', name: 'Quill Gazette' },
	{ entity_key: 'organization:quill-gazette-west', name: 'Quill Gazette' },
];
/** An entity found by an alias its name does not spell. */
const ALIASED = [
	{ entity_key: 'organization:northern-quill', name: 'The Northern Quill' },
];
/** A name ending in a capital sigma, which lowercases by its position. */
const STREET = [ { entity_key: 'organization:odos', name: 'ΟΔΟΣ Media' } ];

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.names = ( args ) => ( {
		entities:
			{
				'--prefix=Quill': QUILL,
				'--prefix=Quill Gaz': GAZETTES,
				'--prefix=TNQ': ALIASED,
				'--prefix=ΟΔΟΣ': STREET,
			}[ args[ 0 ] ] ?? [],
		available: true,
	} );
} );

/**
 * The picker under an owner holding its key, as every caller holds it.
 *
 * @param {Object}                               props
 * @param {string}                               [props.start]  The key held first.
 * @param {(key: string, name: ?string) => void} props.onChange Hears each change.
 * @param {Object}                               [props.rest]   The picker's other props.
 * @return {import('react').ReactElement} The picker.
 */
function Owner( { start = '', onChange, ...rest } ) {
	const [ key, setKey ] = useState( start );
	const [ , setRenders ] = useState( 0 );
	return (
		<>
			<EntityPicker
				label="Client or company"
				value={ key }
				onChange={ ( next, name ) => {
					setKey( next );
					onChange( next, name );
				} }
				{ ...rest }
			/>
			<button
				type="button"
				onClick={ () => setRenders( ( n ) => n + 1 ) }
			>
				Render again
			</button>
		</>
	);
}

const box = () => screen.getByRole( 'combobox', { name: 'Client or company' } );

/**
 * Type into the box as a reader does: focused first, then the text.
 *
 * @param {string} text What is typed.
 */
const type = ( text ) => {
	act( () => box().focus() );
	fireEvent.change( box(), { target: { value: text } } );
};

const key = ( name ) => fireEvent.keyDown( box(), { key: name, code: name } );

const optionNames = () =>
	screen.queryAllByRole( 'option' ).map( ( one ) => one.textContent );

const highlighted = ( name ) =>
	expect( box() ).toHaveAttribute(
		'aria-activedescendant',
		screen.getByRole( 'option', { name } ).id
	);

describe( 'EntityPicker', () => {
	it( 'stands beside another picker on one page, each reading its own names', async () => {
		render(
			<>
				<EntityPicker
					label="Client or company"
					value=""
					onChange={ () => {} }
				/>
				<EntityPicker
					label="Linked entity 6620"
					value=""
					onChange={ () => {} }
				/>
			</>
		);
		const other = screen.getByRole( 'combobox', {
			name: 'Linked entity 6620',
		} );
		act( () => other.focus() );
		fireEvent.change( other, { target: { value: 'Quill' } } );
		expect(
			await screen.findByRole( 'option', { name: 'Quill Press' } )
		).toBeInTheDocument();
		act( () => box().focus() );
		fireEvent.keyDown( box(), { key: 'ArrowDown', code: 'ArrowDown' } );
		expect( box() ).toHaveAttribute( 'aria-expanded', 'true' );
		const firstList = document.getElementById(
			box().getAttribute( 'aria-owns' ) ??
				box().getAttribute( 'aria-controls' )
		);
		expect( firstList ).not.toBeNull();
		expect( within( firstList ).queryAllByRole( 'option' ) ).toEqual( [] );
	} );

	it( 'shows every entity the read answers as a visible option, one whose name does not spell the text too', async () => {
		render( <Owner onChange={ jest.fn() } /> );
		type( 'TNQ' );

		expect(
			await screen.findByRole( 'option', { name: 'The Northern Quill' } )
		).toBeVisible();
		expect( box() ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( box() ).toHaveAttribute(
			'placeholder',
			'Start typing a client or company name'
		);
	} );

	it( 'lists a name typed ending in a capital sigma', async () => {
		render( <Owner onChange={ jest.fn() } /> );
		type( 'ΟΔΟΣ' );

		expect(
			await screen.findByRole( 'option', { name: 'ΟΔΟΣ Media' } )
		).toBeVisible();
	} );

	it( 'hands on the option the arrow keys reach when Enter picks it, and shows its name', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );

		key( 'ArrowDown' );
		highlighted( 'Quill Review' );
		key( 'Enter' );

		expect( onChange ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenCalledWith(
			'organization:quill-review',
			'Quill Review'
		);
		expect( box() ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( box() ).toHaveValue( 'Quill Review' );
	} );

	it( 'highlights the first option as the list opens, so Enter picks it', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );

		highlighted( 'Quill Press' );
		key( 'Enter' );

		expect( onChange ).toHaveBeenCalledWith(
			'organization:quill-press',
			'Quill Press'
		);
	} );

	it( 'keeps the highlight where the arrow keys left it across a render', async () => {
		render( <Owner onChange={ jest.fn() } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );
		key( 'ArrowDown' );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Render again' } )
		);

		expect( box() ).toHaveAttribute(
			'aria-activedescendant',
			screen.getByRole( 'option', { name: 'Quill Review' } ).id
		);
	} );

	it( 'shows the fresh answer once it lands over a remembered one', async () => {
		let land;
		let quills = 0;
		wire.replies.names = ( args ) => {
			if ( '--prefix=Quill' !== args[ 0 ] ) {
				return { entities: [], available: true };
			}
			quills++;
			if ( 1 === quills ) {
				return { entities: QUILL, available: true };
			}
			return new Promise( ( resolve ) => {
				land = resolve;
			} );
		};
		render( <Owner onChange={ jest.fn() } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );
		fireEvent.change( box(), { target: { value: 'Quill R' } } );
		await waitFor( () =>
			expect( wire.sent( 'names' ) ).toContainEqual( [
				'--prefix=Quill R',
			] )
		);
		fireEvent.change( box(), { target: { value: 'Quill' } } );
		await screen.findByRole( 'option', { name: 'Quill Review' } );
		await waitFor( () => expect( land ).toBeDefined() );

		await act( async () =>
			land( {
				entities: [
					{
						entity_key: 'organization:quill-annex',
						name: 'Quill Annex',
					},
					QUILL[ 1 ],
				],
				available: true,
			} )
		);

		expect( optionNames() ).toEqual( [ 'Quill Annex', 'Quill Review' ] );
	} );

	it( 'offers nothing for new text until its answer lands, so an immediate Enter picks nothing', async () => {
		wire.replies.names = ( args ) =>
			'--prefix=Acme' === args[ 0 ]
				? {
						entities: [
							{ entity_key: 'organization:acme', name: 'Acme' },
						],
						available: true,
				  }
				: undefined;
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Acme' );
		await screen.findByRole( 'option', { name: 'Acme' } );

		fireEvent.change( box(), { target: { value: 'Acme Corp' } } );
		expect( optionNames() ).toEqual( [] );
		key( 'Enter' );

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'drops the text an Enter that picked nothing cleared, and asks for it anew when typed again', async () => {
		wire.replies.names = ( args ) => ( {
			entities:
				'--prefix=Acme' === args[ 0 ]
					? [ { entity_key: 'organization:acme', name: 'Acme' } ]
					: [],
			available: true,
		} );
		render( <Owner onChange={ jest.fn() } /> );
		type( 'Acme' );
		await screen.findByRole( 'option', { name: 'Acme' } );
		const corp = () =>
			wire
				.sent( 'names' )
				.filter( ( one ) => '--prefix=Acme Corp' === one[ 0 ] );
		fireEvent.change( box(), { target: { value: 'Acme Corp' } } );
		key( 'Enter' );
		await act( async () => {} );
		expect( corp() ).toHaveLength( 0 );

		fireEvent.change( box(), { target: { value: 'Acme Corp' } } );

		await waitFor( () => expect( corp() ).toHaveLength( 1 ) );
	} );

	it( 'hands on the option clicked', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Quill' );

		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Quill Press' } )
		);

		expect( onChange ).toHaveBeenCalledTimes( 1 );
		expect( onChange ).toHaveBeenCalledWith(
			'organization:quill-press',
			'Quill Press'
		);
	} );

	it( 'asks again for text typed after a pick, as for any new text', async () => {
		render( <Owner onChange={ jest.fn() } /> );
		type( 'Quill' );
		fireEvent.click(
			await screen.findByRole( 'option', { name: 'Quill Press' } )
		);

		fireEvent.change( box(), { target: { value: 'Quill' } } );

		await waitFor( () =>
			expect(
				wire
					.sent( 'names' )
					.filter( ( one ) => '--prefix=Quill' === one[ 0 ] )
			).toHaveLength( 2 )
		);
	} );

	it( 'picks nothing on Enter for text no entity answers', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'organization:quill-annex' );
		await waitFor( () =>
			expect( wire.sent( 'names' ) ).toContainEqual( [
				'--prefix=organization:quill-annex',
			] )
		);
		await act( async () => {} );

		key( 'Enter' );

		expect( onChange ).not.toHaveBeenCalled();
		expect( optionNames() ).toEqual( [] );
	} );

	it( 'hands on nothing when Enter re-picks an option highlighted before the list emptied', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );

		fireEvent.change( box(), { target: { value: 'Quillz' } } );
		await waitFor( () => expect( optionNames() ).toEqual( [] ) );
		key( 'Enter' );

		expect( onChange ).not.toHaveBeenCalled();
	} );

	it( 'offers two entities sharing a name with their keys, and hands on the one picked by its name', async () => {
		const onChange = jest.fn();
		render( <Owner onChange={ onChange } /> );
		type( 'Quill Gaz' );

		fireEvent.click(
			await screen.findByRole( 'option', {
				name: 'Quill Gazette (organization:quill-gazette-west)',
			} )
		);

		expect( onChange ).toHaveBeenCalledWith(
			'organization:quill-gazette-west',
			'Quill Gazette'
		);
	} );

	it( 'never offers the key it excludes', async () => {
		render(
			<Owner exclude="organization:quill-press" onChange={ jest.fn() } />
		);
		type( 'Quill' );

		await screen.findByRole( 'option', { name: 'Quill Review' } );
		expect( optionNames() ).toEqual( [ 'Quill Review' ] );
	} );

	it( 'hands on an empty key from its Reset button', () => {
		const onChange = jest.fn();
		render(
			<Owner start="organization:quill-annex" onChange={ onChange } />
		);

		fireEvent.click( screen.getByRole( 'button', { name: 'Reset' } ) );

		expect( onChange ).toHaveBeenCalledWith( '', null );
	} );

	it( 'shows a key its owner is handed from elsewhere', () => {
		const { rerender } = render(
			<EntityPicker
				label="Client or company"
				value=""
				onChange={ jest.fn() }
			/>
		);
		rerender(
			<EntityPicker
				label="Client or company"
				value="organization:quill-annex"
				onChange={ jest.fn() }
			/>
		);

		expect( box() ).toHaveValue( 'organization:quill-annex' );
	} );

	it( 'hands Escape on when the list is closed, and closes the list first when it is open', async () => {
		const onEscape = jest.fn();
		render( <Owner onEscape={ onEscape } onChange={ jest.fn() } /> );
		type( 'Quill' );
		await screen.findByRole( 'option', { name: 'Quill Review' } );

		key( 'Escape' );
		expect( screen.queryByRole( 'listbox' ) ).toBeNull();
		expect( onEscape ).not.toHaveBeenCalled();

		key( 'Escape' );
		expect( onEscape ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'hands Escape on from the Reset button too, the list being closed', () => {
		const onEscape = jest.fn();
		render(
			<Owner
				start="organization:quill-annex"
				onEscape={ onEscape }
				onChange={ jest.fn() }
			/>
		);
		const reset = screen.getByRole( 'button', { name: 'Reset' } );
		act( () => reset.focus() );

		fireEvent.keyDown( reset, { key: 'Escape', code: 'Escape' } );

		expect( onEscape ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves an Escape that ends a composition alone', () => {
		const onEscape = jest.fn();
		render( <Owner onEscape={ onEscape } onChange={ jest.fn() } /> );

		fireEvent.keyDown( box(), {
			key: 'Escape',
			code: 'Escape',
			isComposing: true,
		} );
		fireEvent.keyDown( box(), {
			key: 'Escape',
			code: 'Escape',
			keyCode: 229,
		} );

		expect( onEscape ).not.toHaveBeenCalled();
	} );

	it( 'shows the refusal of the names read beneath the box', async () => {
		wire.replies.names = new Error( 'names: the projection is rebuilding' );
		render( <Owner onChange={ jest.fn() } /> );
		type( 'Quill' );

		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'useEntityNames', () => {
	let names;
	function Probe( { text } ) {
		names = useEntityNames( text );
		return null;
	}

	it( 'offers each entity keyed, labelled by its name, and a shared name with its key', async () => {
		render( <Probe text="Quill Gaz" /> );
		await waitFor( () => expect( names.options ).toHaveLength( 2 ) );

		expect( names.options ).toEqual( [
			{
				value: 'organization:quill-gazette-east',
				label: 'Quill Gazette (organization:quill-gazette-east)',
				name: 'Quill Gazette',
			},
			{
				value: 'organization:quill-gazette-west',
				label: 'Quill Gazette (organization:quill-gazette-west)',
				name: 'Quill Gazette',
			},
		] );
	} );
} );

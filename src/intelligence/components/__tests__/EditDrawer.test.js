/* eslint-env jest */
/**
 * The Edit drawer: **Edit** opens a sheet named for the entity, its heading
 * focused and **Edit** marked expanded; Escape hands focus back to **Edit**.
 * Each section lists what the entity holds above the form adding to it, and
 * a form sends nothing until it is valid.
 */

import { fireEvent, render, screen, within } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { pageData, VOCABULARY } from '../../views/__tests__/fixtures';
import EditDrawer from '../EditDrawer';

const KEY = 'organization:harbor-ledger';

const ENTITY = {
	entity_key: KEY,
	name: 'Harbor Ledger',
	merged_from: [ 'organization:harbor-ledger-co', 'organization:the-ledger' ],
	names: [ { kind: 'alias', value: 'The Ledger' } ],
	identifiers: [ { kind: 'domain', value: 'harborledger.example' } ],
	roles: [
		{ role: 'client', from_ts: Date.UTC( 2020, 4, 12 ) / 1000, to_ts: 0 },
		{ role: 'competitor', from_ts: 1700000000, to_ts: 0 },
		{ role: 'former_client', from_ts: 1600000000, to_ts: 1650000000 },
	],
};

let viewport;
let writes;
let onEndClient;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	viewport = installViewport( false );
	writes = {
		addName: { run: jest.fn() },
		addIdentifier: { run: jest.fn() },
		assertRole: { run: jest.fn() },
		endRole: { run: jest.fn() },
	};
	onEndClient = jest.fn();
} );

afterEach( () => {
	viewport.restore();
} );

const renderDrawer = () =>
	render(
		<EditDrawer
			entity={ ENTITY }
			vocabulary={ VOCABULARY }
			writes={ writes }
			onEndClient={ onEndClient }
		/>
	);

/**
 * Open the drawer from **Edit**.
 *
 * @return {HTMLElement} The drawer.
 */
function open() {
	const edit = screen.getByRole( 'button', { name: 'Edit' } );
	edit.focus();
	fireEvent.click( edit );
	return screen.getByRole( 'dialog', { name: 'Edit Harbor Ledger' } );
}

/**
 * One of the drawer's sections.
 *
 * @param {string} name Its heading.
 * @return {HTMLElement} The section.
 */
const section = ( name ) => screen.getByRole( 'region', { name } );

describe( 'EditDrawer', () => {
	it( 'opens a sheet named for the entity, its heading focused, Edit marked expanded', () => {
		renderDrawer();
		const edit = screen.getByRole( 'button', { name: 'Edit' } );
		expect( edit ).toHaveAttribute( 'aria-expanded', 'false' );

		const drawer = open();

		expect( drawer ).toHaveClass(
			'newspack-intelligence__sheet',
			'is-side'
		);
		expect(
			screen.getByRole( 'heading', { name: 'Edit Harbor Ledger' } )
		).toHaveFocus();
		expect( edit ).toHaveAttribute( 'aria-expanded', 'true' );
	} );

	it( 'hands focus back to Edit on Escape', () => {
		renderDrawer();
		open();

		fireEvent.keyDown( document, { key: 'Escape' } );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		const edit = screen.getByRole( 'button', { name: 'Edit' } );
		expect( edit ).toHaveFocus();
		expect( edit ).toHaveAttribute( 'aria-expanded', 'false' );
	} );

	it( 'rises from the bottom when the page is narrow', () => {
		viewport.restore();
		viewport = installViewport( true );
		renderDrawer();

		expect( open() ).toHaveClass( 'is-bottom' );
	} );

	it( 'lists the names above the form adding one', () => {
		renderDrawer();
		open();
		const names = section( 'Names' );
		const current = within( names ).getByText( 'The Ledger' );
		const form = within( names ).getByRole( 'group', {
			name: 'Add a name',
		} );

		expect(
			within( names ).getByText( 'Goes by', { selector: 'dt' } )
		).toBeInTheDocument();
		expect(
			current.compareDocumentPosition( form ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'adds a name of the kind chosen, once its value is given', () => {
		renderDrawer();
		open();
		const form = within( section( 'Names' ) ).getByRole( 'group', {
			name: 'Add a name',
		} );
		const add = within( form ).getByRole( 'button', {
			name: 'Add a name',
		} );

		fireEvent.click( add );
		expect( writes.addName.run ).not.toHaveBeenCalled();

		fireEvent.change( within( form ).getByLabelText( /Kind/ ), {
			target: { value: 'acronym' },
		} );
		fireEvent.change( within( form ).getByLabelText( /Value/ ), {
			target: { value: 'HL' },
		} );
		fireEvent.click( add );

		expect( writes.addName.run ).toHaveBeenCalledWith( {
			key: KEY,
			kind: 'acronym',
			value: 'HL',
		} );
	} );

	it( 'lists the identifiers above the form adding one, and adds one', () => {
		renderDrawer();
		open();
		const ids = section( 'Identifiers' );
		expect(
			within( ids ).getByText( 'harborledger.example' )
		).toBeInTheDocument();
		const form = within( ids ).getByRole( 'group', {
			name: 'Add an identifier',
		} );

		fireEvent.change( within( form ).getByLabelText( /Kind/ ), {
			target: { value: 'github_org' },
		} );
		fireEvent.change( within( form ).getByLabelText( /Value/ ), {
			target: { value: 'harbor-ledger' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add an identifier' } )
		);

		expect( writes.addIdentifier.run ).toHaveBeenCalledWith( {
			key: KEY,
			kind: 'github_org',
			value: 'harbor-ledger',
		} );
	} );

	it( 'lists the open roles, ending the client through its dialog and another role at once', () => {
		renderDrawer();
		open();
		const roles = section( 'Roles' );
		expect(
			within( roles ).queryByText( /Lapsed member/, { selector: 'li' } )
		).toBeNull();

		fireEvent.click(
			within( roles ).getByRole( 'button', {
				name: 'End Paying member…',
			} )
		);
		expect( onEndClient ).toHaveBeenCalledTimes( 1 );
		expect( writes.endRole.run ).not.toHaveBeenCalled();

		fireEvent.click(
			within( roles ).getByRole( 'button', { name: 'End Rival' } )
		);
		expect( writes.endRole.run ).toHaveBeenCalledWith( {
			key: KEY,
			role: 'competitor',
		} );
	} );

	it( 'asserts the role chosen', () => {
		renderDrawer();
		open();
		const form = within( section( 'Roles' ) ).getByRole( 'group', {
			name: 'Add a role',
		} );

		fireEvent.change( within( form ).getByLabelText( /Role/ ), {
			target: { value: 'former_client' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Add a role' } )
		);

		expect( writes.assertRole.run ).toHaveBeenCalledWith( {
			key: KEY,
			role: 'former_client',
		} );
	} );

	it( 'says how many earlier records it merged, listing them when opened', () => {
		renderDrawer();
		open();
		const merged = screen
			.getByText( 'Merged from: 2 earlier records' )
			.closest( 'details' );

		expect( merged ).not.toHaveAttribute( 'open' );
		expect(
			within( merged ).getByText( 'organization:the-ledger' )
		).toBeInTheDocument();
	} );
} );

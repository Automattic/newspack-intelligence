/* eslint-env jest */
/**
 * The `item-list` block reads `search` under the page it stands on: on a
 * team view's page each list it names is narrowed to the view's own list
 * of that name, and an intersection left empty reads nothing and says its
 * `empty` sentence; on a profile it reads the entity; its tiers are sent for
 * the server to narrow within the reader's. It draws the stories grouped by
 * theme, or the items in the layout it names.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { GROUPS, ITEM, LENSES, pageData } from '../../views/__tests__/fixtures';
import { IntelPageContext } from '../../layout/PageContext';
import itemList from '../item-list';

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

const [ SUPPORT ] = LENSES.lenses;

/**
 * A team view's page whose view names `kinds`.
 *
 * @param {string[]} kinds The view's kinds.
 * @return {Object} The page.
 */
const deskOf = ( kinds ) => ( {
	page: 'lens',
	lens_key: 'prairie-desk',
	lens: {
		...SUPPORT,
		lens_key: 'prairie-desk',
		filters: { ...SUPPORT.filters, kinds },
	},
	entity_key: null,
	since: null,
	editing: false,
} );

const ATTRIBUTES = {
	heading: 'Stories on the desk',
	kinds: [],
	topics: [],
	roles: [],
	sources: [],
	lens: '',
	group: '',
	sort: 'time',
	layout: 'list',
	limit: 7,
	tiers: [],
	empty: 'Nothing on this desk.',
};

let wire;
let onOpenEntity;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	wire.replies.search = {
		items: [ ITEM ],
		total: 1,
		sort: 'time',
		available: true,
	};
	onOpenEntity = jest.fn();
} );

/**
 * Render the block on a page.
 *
 * @param {Object} attributes Its attributes, over the defaults above.
 * @param {Object} page       The page.
 * @return {Object} The render.
 */
const block = ( attributes, page ) =>
	render(
		<IntelPageContext.Provider
			value={ { ...page, actions: { onOpenEntity, canTune: false } } }
		>
			<itemList.View attributes={ { ...ATTRIBUTES, ...attributes } } />
		</IntelPageContext.Provider>
	);

describe( 'item-list', () => {
	it( 'sends only the kinds the team view also names', async () => {
		block( { kinds: [ 'bug' ] }, deskOf( [ 'award', 'bug' ] ) );
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=prairie-desk', '--kind=bug', '--sort=time', '--limit=7' ],
		] );
	} );

	it( 'reads nothing and says its empty sentence when the view names none of its kinds', async () => {
		block( { kinds: [ 'bug' ] }, deskOf( [ 'award' ] ) );
		expect(
			screen.getByText( 'Nothing on this desk.' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Stories on the desk' } )
		).toBeInTheDocument();
		await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		expect( wire.sent( 'search' ) ).toEqual( [] );
	} );

	it( 'sends its own list where the view names none, and its tiers for the server to narrow', async () => {
		block( { roles: [ 'client' ], tiers: [ 'restricted' ] }, deskOf( [] ) );
		await waitFor( () =>
			expect( wire.sent( 'search' ) ).toEqual( [
				[
					'--lens=prairie-desk',
					'--role=client',
					'--tier=restricted',
					'--sort=time',
					'--limit=7',
				],
			] )
		);
	} );

	it( 'groups the stories by theme in the order the server ranks them', async () => {
		wire.replies.search = GROUPS;
		block( { group: 'theme', limit: 12 }, deskOf( [] ) );
		expect(
			await screen.findByRole( 'heading', {
				name: 'site blocked from sending email',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=prairie-desk', '--group=theme', '--limit=12' ],
		] );
	} );

	it( 'reads a profile’s entity and opens an entity its rows name', async () => {
		block(
			{},
			{
				page: 'entity',
				lens_key: null,
				entity_key: 'organization:prairie-signal',
				since: null,
				editing: false,
			}
		);
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:acme-quillsheet'
		);
		expect( wire.sent( 'search' ) ).toEqual( [
			[
				'--entity=organization:prairie-signal',
				'--sort=time',
				'--limit=7',
			],
		] );
	} );

	it( 'says its empty sentence for an answer holding nothing', async () => {
		wire.replies.search = {
			items: [],
			total: 0,
			sort: 'time',
			available: true,
		};
		block( {}, deskOf( [] ) );
		expect(
			await screen.findByText( 'Nothing on this desk.' )
		).toBeInTheDocument();
	} );

	it( 'stands skeleton rows in while owed, under its heading', () => {
		wire.replies.search = owed;
		block( {}, deskOf( [] ) );
		expect(
			screen.getByRole( 'status', { name: 'Loading' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Stories on the desk' } )
		).toBeInTheDocument();
	} );

	it( 'shows the shared read error in its place', async () => {
		wire.replies.search = () => new Error( 'no lens: prairie-desk' );
		block( {}, deskOf( [] ) );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
	} );

	it( "names its heading in a profile's jump links, and nothing without one", () => {
		expect( itemList.headings( ATTRIBUTES ) ).toEqual( [
			'Stories on the desk',
		] );
		expect( itemList.headings( { ...ATTRIBUTES, heading: '' } ) ).toEqual(
			[]
		);
	} );

	it( 'writes each setting from its controls, a list by its words', () => {
		const setAttributes = jest.fn();
		render(
			<itemList.Controls
				attributes={ { ...ATTRIBUTES, kinds: [ 'award' ] } }
				setAttributes={ setAttributes }
			/>
		);
		const pick = ( label, said ) => {
			const field = screen.getByLabelText( label );
			fireEvent.change( field, { target: { value: said } } );
			fireEvent.keyDown( field, { key: 'Enter' } );
		};

		pick( 'Kinds of news', 'Going live' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			kinds: [ 'award', 'launch' ],
		} );
		pick( 'Kinds of news', 'Heron sightings' );
		expect( setAttributes ).toHaveBeenCalledTimes( 1 );
		pick( 'About', 'Lead' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			roles: [ 'prospect' ],
		} );
		pick( 'Sources', 'Team blog' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			sources: [ 'p2' ],
		} );
		pick( 'Include items that are', 'Staff' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			tiers: [ 'internal' ],
		} );

		const choose = ( label, value ) =>
			fireEvent.change( screen.getByLabelText( label ), {
				target: { value },
			} );
		choose( 'Heading', 'On the prairie desk' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			heading: 'On the prairie desk',
		} );
		choose( 'Show', 'theme' );
		expect( setAttributes ).toHaveBeenLastCalledWith( { group: 'theme' } );
		choose( 'Order', 'importance' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			sort: 'importance',
		} );
		choose( 'Layout', 'table' );
		expect( setAttributes ).toHaveBeenLastCalledWith( { layout: 'table' } );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Items shown' } ),
			{ target: { value: '23' } }
		);
		expect( setAttributes ).toHaveBeenLastCalledWith( { limit: 23 } );
		choose( 'When empty, say', 'The desk is quiet.' );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			empty: 'The desk is quiet.',
		} );
	} );
} );

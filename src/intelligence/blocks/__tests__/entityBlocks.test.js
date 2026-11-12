/* eslint-env jest */
/**
 * A profile's blocks, each read on its own page: the pulse line of the
 * page's subject; the facts, its counts only when not zero, its claims
 * without Save and its details with the ID under a disclosure for a curator;
 * the related entities up to the block's limit; and the activity, a page at
 * a time, with what the entity published under a disclosure.
 */

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, pageData } from '../../views/__tests__/fixtures';
import { IntelPageContext } from '../../layout/PageContext';
import { useIntelRead } from '../../hooks/useIntelCommand';
import { aboutEntity, EntityRecord, useEntityRecord } from '../record';
import pulse from '../pulse';
import entityFacts from '../entity-facts';
import related from '../related';
import timeline from '../timeline';

const KEY = 'organization:harbor-ledger';

/** 10 Nov 2026, UTC. */
const TODAY = Date.UTC( 2026, 10, 10 ) / 1000;

/** The client's profile record. */
const RECORD = {
	entity_key: KEY,
	type: 'organization',
	name: 'Harbor Ledger',
	state: 'accepted',
	merged_from: [ 'organization:harbor-ledger-co' ],
	names: [ { kind: 'alias', value: 'The Ledger' } ],
	identifiers: [ { kind: 'domain', value: 'harborledger.example' } ],
	roles: [
		{ role: 'client', from_ts: Date.UTC( 2020, 4, 12 ) / 1000, to_ts: 0 },
	],
	topics: [],
	counts: { items: 31, proposed_links: 4 },
	published: { count: 39 },
	status: {
		client_since: Date.UTC( 2020, 4, 12 ) / 1000,
		kinds: {
			churned: 0,
			churn_signal: 0,
			feature_request: 3,
			complaint: 1,
		},
		last_item_ts: Date.UTC( 2026, 10, 6 ) / 1000,
		churn_open: false,
		churn_items: [],
		churn_note: null,
	},
	available: true,
};

/**
 * An entity sharing items with the client.
 *
 * @param {number} n Its place.
 * @return {Object} The row.
 */
const other = ( n ) => ( {
	entity_key: `organization:prairie-signal-${ n }`,
	name: `Prairie Signal ${ n }`,
	type: 'organization',
	shared_items: 10 - n,
	items: [],
} );

let wire;
let actions;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	jest.spyOn( Core, 'now' ).mockReturnValue( TODAY );
	wire = intelWire();
	wire.replies.entity = RECORD;
	wire.replies.claims = {
		claims: [
			{
				subject: KEY,
				subject_name: 'Harbor Ledger',
				predicate: 'uses_product',
				object: 'product:example-newsletters',
				object_name: 'Example Newsletters',
				confidence: 0.9,
				item_key: 'feed:harbor-ledger-41',
				url: 'https://harborledger.example/41',
				title: 'Harbor Ledger moves its newsletters',
			},
		],
		available: true,
	};
	actions = {
		onOpenEntity: jest.fn(),
		onSave: jest.fn(),
		canTune: false,
	};
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Render one block's View on a page.
 *
 * @param {Object} block      The block's `{ View }`.
 * @param {Object} attributes Its attributes.
 * @param {Object} [page]     The page, over a profile of the client.
 * @return {Object} What `render` returned.
 */
/**
 * A profile's one `entity` read, provided as the Entity view provides it.
 *
 * @param {Object}                    props
 * @param {?string}                   props.entityKey The profile's entity.
 * @param {import('react').ReactNode} props.children  Its blocks.
 * @return {import('react').ReactElement} The profile.
 */
function Profile( { entityKey, children } ) {
	const record = useIntelRead(
		'entity:record',
		'entity',
		entityKey ? [ entityKey ] : null
	);
	return (
		<EntityRecord.Provider value={ aboutEntity( entityKey, record ) }>
			{ children }
		</EntityRecord.Provider>
	);
}

const renderBlock = ( block, attributes, page = {} ) => {
	const shown = {
		page: 'entity',
		lens_key: null,
		entity_key: KEY,
		since: null,
		actions,
		...page,
	};
	return renderOnPage(
		<IntelPageContext.Provider value={ shown }>
			<Profile entityKey={ shown.entity_key }>
				<block.View attributes={ attributes } />
			</Profile>
		</IntelPageContext.Provider>
	);
};

describe( 'pulse', () => {
	const SIGNALS = {
		signals: [],
		trend: [ { bucket: '2026-11-02', count: 4, in_window: true } ],
		partial: { bucket: '2026-11-09', count: 2 },
		judged: true,
		bucket: 'week',
		baseline_buckets: 8,
		available: true,
	};

	it( 'reads the profile’s entity', async () => {
		wire.replies.signals = SIGNALS;
		renderBlock( pulse, { lens: '' } );
		expect(
			await screen.findByText( 'No unusual change last week.' )
		).toBeInTheDocument();
		expect( wire.sent( 'signals' ) ).toEqual( [ [ `--entity=${ KEY }` ] ] );
	} );

	it( 'reads a team view’s lens on its page', async () => {
		wire.replies.signals = SIGNALS;
		renderBlock(
			pulse,
			{ lens: '' },
			{ page: 'lens', lens_key: 'desk-watch', entity_key: null }
		);
		await screen.findByText( 'No unusual change last week.' );
		expect( wire.sent( 'signals' ) ).toEqual( [ [ '--lens=desk-watch' ] ] );
	} );

	it( 'reads the lens it names on the Briefing, and nothing without one', async () => {
		wire.replies.signals = SIGNALS;
		const named = renderBlock(
			pulse,
			{ lens: 'desk-q4' },
			{ page: 'briefing', entity_key: null }
		);
		await screen.findByText( 'No unusual change last week.' );
		expect( wire.sent( 'signals' ) ).toEqual( [ [ '--lens=desk-q4' ] ] );
		named.unmount();

		const { container } = renderBlock(
			pulse,
			{ lens: '' },
			{ page: 'briefing', entity_key: null }
		);
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'entity-facts', () => {
	it( 'counts what is not zero, and the day of the last item', async () => {
		renderBlock( entityFacts, { show: [ 'counts' ], limit: 10 } );
		expect(
			await screen.findByText( '3 feature requests' )
		).toBeInTheDocument();
		expect( screen.getByText( '1 complaint' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Last item 6 Nov' ) ).toBeInTheDocument();
		expect( screen.queryByText( /churn/ ) ).toBeNull();
		expect( screen.queryByRole( 'heading' ) ).toBeNull();
		expect( wire.sent( 'claims' ) ).toEqual( [] );
	} );

	it( 'renders no counts when it shows claims alone', async () => {
		renderBlock( entityFacts, { show: [ 'claims' ], limit: 10 } );
		expect(
			await screen.findByRole( 'heading', { name: 'Facts' } )
		).toBeInTheDocument();
		await screen.findByText( 'runs' );
		expect( screen.queryByText( '3 feature requests' ) ).toBeNull();
		expect(
			screen.queryByRole( 'heading', { name: 'Details' } )
		).toBeNull();
	} );

	it( 'reads each claim in words, cites its item and offers no Save', async () => {
		renderBlock( entityFacts, { show: [ 'claims' ], limit: 10 } );
		const facts = (
			await screen.findByRole( 'heading', { name: 'Facts' } )
		).closest( 'section' );
		await within( facts ).findByText( 'runs' );
		expect(
			within( facts ).getByRole( 'button', {
				name: 'Example Newsletters',
			} )
		).toBeInTheDocument();
		expect(
			within( facts ).getByRole( 'link', {
				name: 'Harbor Ledger moves its newsletters',
			} )
		).toBeInTheDocument();
		expect(
			within( facts ).queryByRole( 'button', { name: /Save/ } )
		).toBeNull();
		expect( wire.sent( 'claims' ) ).toEqual( [ [ KEY, '--limit=10' ] ] );
	} );

	it( 'asks for no more claims than its limit', async () => {
		renderBlock( entityFacts, { show: [ 'claims' ], limit: 3 } );
		await screen.findByText( 'runs' );
		expect( wire.sent( 'claims' ) ).toEqual( [ [ KEY, '--limit=3' ] ] );
	} );

	it( 'lists the details, and the ID under a disclosure for a curator alone', async () => {
		const reader = renderBlock( entityFacts, {
			show: [ 'details' ],
			limit: 10,
		} );
		const shown = (
			await screen.findByRole( 'heading', { name: 'Details' } )
		).closest( 'section' );
		expect(
			within( shown ).getByText( 'Goes by: The Ledger' )
		).toBeInTheDocument();
		expect(
			within( shown ).getByText( 'Web address: harborledger.example' )
		).toBeInTheDocument();
		expect( within( shown ).queryByText( 'Show the ID' ) ).toBeNull();
		expect( within( shown ).queryByText( new RegExp( KEY ) ) ).toBeNull();
		reader.unmount();

		actions.canTune = true;
		renderBlock( entityFacts, { show: [ 'details' ], limit: 10 } );
		const curated = (
			await screen.findByRole( 'heading', { name: 'Details' } )
		).closest( 'section' );
		const id = within( curated )
			.getByText( 'Show the ID' )
			.closest( 'details' );
		expect( id ).not.toHaveAttribute( 'open' );
		expect( within( id ).getByText( `ID: ${ KEY }` ) ).toBeInTheDocument();
		expect(
			within( id ).getByText(
				'Merged from organization:harbor-ledger-co'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'related', () => {
	beforeEach( () => {
		wire.replies.related = {
			entities: [ 1, 2, 3, 4, 5 ].map( other ),
			available: true,
		};
	} );

	it( 'shows its limit of rows and "Show all %d" for the rest', async () => {
		renderBlock( related, { heading: 'Shares coverage', limit: 3 } );
		const section = (
			await screen.findByRole( 'heading', { name: 'Shares coverage' } )
		).closest( 'section' );
		await within( section ).findByRole( 'button', {
			name: 'Prairie Signal 1',
		} );
		expect( within( section ).getAllByRole( 'listitem' ) ).toHaveLength(
			3
		);
		expect( within( section ).getByText( '9 shared' ) ).toBeInTheDocument();
		fireEvent.click(
			within( section ).getByRole( 'button', { name: 'Show all 5' } )
		);
		expect( within( section ).getAllByRole( 'listitem' ) ).toHaveLength(
			5
		);
		expect(
			within( section ).queryByRole( 'button', { name: /Show all/ } )
		).toBeNull();
	} );

	it( 'opens a related entity', async () => {
		renderBlock( related, { heading: 'Related', limit: 5 } );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Prairie Signal 2' } )
		);
		expect( actions.onOpenEntity ).toHaveBeenCalledWith(
			'organization:prairie-signal-2'
		);
	} );

	it( 'names the entity when nothing shares its items', async () => {
		wire.replies.related = { entities: [], available: true };
		renderBlock( related, { heading: 'Related', limit: 5 } );
		expect(
			await screen.findByText(
				'No other client or company shares items with Harbor Ledger yet.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'timeline', () => {
	const item = ( n ) => ( {
		...ITEM,
		item_key: `feed:harbor-ledger-${ n }`,
		title: `Harbor Ledger story ${ n }`,
		url: `https://harborledger.example/${ n }`,
	} );

	beforeEach( () => {
		wire.replies.timeline = ( args ) =>
			args.includes( '--published' )
				? { items: [ item( 90 ) ], total: 39, available: true }
				: {
						items: [ item( 1 ), item( 2 ) ],
						total: 14,
						available: true,
				  };
	} );

	it( 'heads the activity, pages it by its limit and keeps what it published under a disclosure', async () => {
		renderBlock( timeline, {
			heading: 'Lately',
			published: 'disclosure',
			layout: 'activity',
			limit: 7,
		} );
		expect(
			await screen.findByRole( 'heading', { name: 'Lately' } )
		).toBeInTheDocument();
		await screen.findByRole( 'link', { name: 'Harbor Ledger story 1' } );
		expect( wire.sent( 'timeline' ) ).toEqual( [ [ KEY, '--limit=7' ] ] );
		expect( screen.getByText( 'Page 1 of 2' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		await waitFor( () =>
			expect( wire.sent( 'timeline' ) ).toContainEqual( [
				KEY,
				'--limit=7',
				'--offset=7',
			] )
		);

		const toggle = await screen.findByRole( 'button', {
			name: 'Published by Harbor Ledger (39)',
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'link', { name: 'Harbor Ledger story 90' } )
		).toBeNull();
		fireEvent.click( toggle );
		expect(
			await screen.findByRole( 'link', {
				name: 'Harbor Ledger story 90',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toContainEqual( [
			KEY,
			'--limit=7',
			'--published',
		] );
	} );

	it( 'renders no "Published by" when it shows none', async () => {
		renderBlock( timeline, {
			heading: 'Activity',
			published: 'none',
			layout: 'list',
			limit: 20,
		} );
		await screen.findByRole( 'link', { name: 'Harbor Ledger story 1' } );
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toEqual( [ [ KEY ] ] )
		);
		expect( screen.queryByText( /Published by/ ) ).toBeNull();
	} );

	it( 'shows only what the entity published when it says so', async () => {
		renderBlock( timeline, {
			heading: 'Its own stories',
			published: 'only',
			layout: 'activity',
			limit: 20,
		} );
		expect(
			await screen.findByRole( 'link', {
				name: 'Harbor Ledger story 90',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'timeline' ) ).toEqual( [
			[ KEY, '--limit=20', '--published' ],
		] );
	} );

	it( 'names the entity when nothing is about it', async () => {
		wire.replies.timeline = { items: [], total: 0, available: true };
		renderBlock( timeline, {
			heading: 'Activity',
			published: 'none',
			layout: 'activity',
			limit: 20,
		} );
		expect(
			await screen.findByText( 'Nothing about Harbor Ledger yet.' )
		).toBeInTheDocument();
	} );
} );

describe( 'a profile that names no entity yet', () => {
	it.each( [
		[ 'pulse', pulse, { lens: '' } ],
		[ 'related', related, { heading: 'Shares coverage', limit: 3 } ],
		[
			'timeline',
			timeline,
			{
				heading: 'On the wire',
				published: 'none',
				layout: 'list',
				limit: 4,
			},
		],
		[ 'entity-facts', entityFacts, { show: [ 'claims' ], limit: 4 } ],
	] )(
		'%s reads nothing, sending no "null"',
		async ( _, block, attributes ) => {
			const view = renderBlock( block, attributes, { entity_key: null } );
			await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
			expect( wire.sent( 'signals' ) ).toEqual( [] );
			expect( wire.sent( 'related' ) ).toEqual( [] );
			expect( wire.sent( 'timeline' ) ).toEqual( [] );
			expect( wire.sent( 'entity' ) ).toEqual( [] );
			view.unmount();
		}
	);
} );

describe( 'entity-facts with nothing to count', () => {
	it( 'renders no counts for an entity holding no item, its details still shown', async () => {
		wire.replies.entity = {
			...RECORD,
			roles: [],
			status: null,
			counts: { items: 0, proposed_links: 0 },
		};
		renderBlock( entityFacts, { show: [ 'counts', 'details' ], limit: 4 } );
		expect(
			await screen.findByRole( 'heading', { name: 'Details' } )
		).toBeInTheDocument();
		expect(
			document.querySelector( '.newspack-intelligence__counts' )
		).toBe( null );
	} );
} );

describe( 'Controls', () => {
	it( "write the pulse's team view by its name", async () => {
		wire.replies.lenses = {
			lenses: [
				{ lens_key: 'prairie-desk', name: 'Prairie desk' },
				{ lens_key: 'harbor-desk', name: 'Harbor desk' },
			],
			available: true,
		};
		const setAttributes = jest.fn();
		const controls = ( lens ) => (
			<pulse.Controls
				attributes={ { lens } }
				setAttributes={ setAttributes }
			/>
		);
		const { rerender } = renderOnPage( controls( 'prairie-desk' ) );
		expect( await screen.findByText( 'Prairie desk' ) ).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove item' } )
		);
		expect( setAttributes ).toHaveBeenLastCalledWith( { lens: '' } );
		rerender( controls( '' ) );
		const field = screen.getByLabelText( 'Team view (on the Briefing)' );
		fireEvent.change( field, { target: { value: 'Harbor desk' } } );
		fireEvent.keyDown( field, { key: 'Enter' } );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			lens: 'harbor-desk',
		} );
	} );

	it( 'write the parts the facts show, in their order, and how many', () => {
		const setAttributes = jest.fn();
		renderOnPage(
			<entityFacts.Controls
				attributes={ { show: [ 'details' ], limit: 4 } }
				setAttributes={ setAttributes }
			/>
		);
		fireEvent.click( screen.getByLabelText( 'Counts' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			show: [ 'counts', 'details' ],
		} );
		fireEvent.click( screen.getByLabelText( 'Details' ) );
		expect( setAttributes ).toHaveBeenLastCalledWith( { show: [] } );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Facts shown' } ),
			{ target: { value: '17' } }
		);
		expect( setAttributes ).toHaveBeenLastCalledWith( { limit: 17 } );
	} );

	it( "write related's heading and rows", () => {
		const setAttributes = jest.fn();
		renderOnPage(
			<related.Controls
				attributes={ { heading: 'Neighbors', limit: 5 } }
				setAttributes={ setAttributes }
			/>
		);
		fireEvent.change( screen.getByLabelText( 'Heading' ), {
			target: { value: 'Shares items with' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			heading: 'Shares items with',
		} );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Rows shown' } ),
			{ target: { value: '13' } }
		);
		expect( setAttributes ).toHaveBeenLastCalledWith( { limit: 13 } );
	} );

	it( "write the timeline's heading, what it published, layout and page", () => {
		const setAttributes = jest.fn();
		renderOnPage(
			<timeline.Controls
				attributes={ {
					heading: 'Lately',
					published: 'disclosure',
					layout: 'activity',
					limit: 9,
				} }
				setAttributes={ setAttributes }
			/>
		);
		fireEvent.change( screen.getByLabelText( 'Heading' ), {
			target: { value: 'On the record' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			heading: 'On the record',
		} );
		const published = screen.getByLabelText( 'What it published' );
		expect(
			[ ...published.options ].map( ( one ) => one.textContent )
		).toEqual( [ 'Under a disclosure', 'Left out', 'Shown alone' ] );
		fireEvent.change( published, { target: { value: 'only' } } );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			published: 'only',
		} );
		fireEvent.change( screen.getByLabelText( 'Layout' ), {
			target: { value: 'list' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( { layout: 'list' } );
		fireEvent.change(
			screen.getByRole( 'spinbutton', { name: 'Items a page' } ),
			{ target: { value: '21' } }
		);
		expect( setAttributes ).toHaveBeenLastCalledWith( { limit: 21 } );
	} );
} );

describe( 'useEntityRecord', () => {
	/** A block reading the profile's record. */
	function Named() {
		return <p>{ useEntityRecord().answer?.name ?? 'owed' }</p>;
	}

	it( 'refuses a record no profile provides', () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		expect( () => render( <Named /> ) ).toThrow(
			'useEntityRecord(): no profile provides its record'
		);
		console.error.mockRestore();
	} );
} );

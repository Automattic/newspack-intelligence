/* eslint-env jest */
/**
 * The `trend-chart` block reads `trends` through the page's subject, the
 * team view or the profile's entity, naming the axis, the span and the
 * style its attributes set, the view's own axis and look back read from
 * `lenses`; it keeps its heading while owed, empty and refused, and reads
 * nothing for a page that names no subject yet.
 */

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { LENSES, pageData } from '../../views/__tests__/fixtures';
import { IntelPageContext } from '../../layout/PageContext';
import trendChart from '../trend-chart';

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

const [ SUPPORT ] = LENSES.lenses;

const DESK = {
	page: 'lens',
	lens_key: SUPPORT.lens_key,
	entity_key: null,
	since: null,
};

const ATTRIBUTES = {
	heading: 'Weeks of %s',
	by: '',
	bucket: '',
	style: 'stack',
};

const ROWS = [
	{ bucket: '2026-10-26', value: 'complaint', count: 3, items: [] },
	{ bucket: '2026-10-19', value: 'praise', count: 2, items: [] },
];

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	window.localStorage.clear();
	wire = intelWire();
	wire.replies.trends = { rows: ROWS, by: 'kind', bucket: 'week' };
	wire.replies.lenses = LENSES;
} );

/**
 * Render the block on a page.
 *
 * @param {Object} attributes Its attributes, over the defaults above.
 * @param {Object} page       The page, over the support desk's.
 * @return {Object} The render.
 */
const block = ( attributes = {}, page = {} ) =>
	render(
		<IntelPageContext.Provider value={ { ...DESK, ...page } }>
			<trendChart.View attributes={ { ...ATTRIBUTES, ...attributes } } />
		</IntelPageContext.Provider>
	);

describe( 'trend-chart', () => {
	it( 'asks trends by the axis it names and draws lines for the lines style', async () => {
		block( { by: 'kind', style: 'lines' } );
		const toggle = await screen.findByRole( 'button', {
			name: 'Stack Weeks of news type',
		} );
		expect( toggle ).toHaveAttribute( 'aria-pressed', 'false' );
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk', '--by=kind' ],
		] );
	} );

	it( 'reads the team view’s own axis and span when it names none, stacked', async () => {
		block();
		expect(
			await screen.findByRole( 'button', {
				name: 'Stack Weeks of news type',
			} )
		).toHaveAttribute( 'aria-pressed', 'true' );
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
	} );

	it( 'reads a profile’s entity, naming the span it sets', async () => {
		block(
			{ bucket: 'month' },
			{
				page: 'entity',
				lens_key: null,
				entity_key: 'organization:harbor-ledger',
			}
		);
		await screen.findByRole( 'heading', { name: 'Weeks of news type' } );
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--entity=organization:harbor-ledger', '--bucket=month' ],
		] );
	} );

	it( 'keeps its heading over a 180 px skeleton while owed', async () => {
		wire.replies.trends = owed;
		block( { by: 'theme' } );
		expect(
			screen.getByRole( 'heading', { name: 'Weeks of thread' } )
		).toBeInTheDocument();
		const skeleton = screen.getByRole( 'status', { name: 'Loading' } );
		expect( skeleton.firstChild ).toHaveStyle( { height: '180px' } );
		await waitFor( () =>
			expect( wire.sent( 'trends' ) ).toHaveLength( 1 )
		);
	} );

	it( 'says nothing is in the view over the days it looks back', async () => {
		wire.replies.trends = { rows: [], by: 'kind', bucket: 'week' };
		block();
		expect(
			await screen.findByText(
				'Nothing in this view in the last 21 days.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Weeks of news type' } )
		).toBeInTheDocument();
	} );

	it( 'says a view looking back over everything holds nothing yet', async () => {
		wire.replies.trends = { rows: [], by: 'kind', bucket: 'week' };
		wire.replies.lenses = {
			lenses: [
				{ ...SUPPORT, filters: { ...SUPPORT.filters, days: null } },
			],
			available: true,
		};
		block();
		expect(
			await screen.findByText( 'Nothing in this view yet.' )
		).toBeInTheDocument();
	} );

	it( 'reads nothing on a page that names no subject yet, sending no "null"', async () => {
		const lens = block( {}, { lens_key: null } );
		const profile = block(
			{},
			{ page: 'entity', lens_key: null, entity_key: null }
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 50 ) );
		expect( wire.sent( 'trends' ) ).toEqual( [] );
		lens.unmount();
		profile.unmount();
	} );

	it( 'stands twice on one page under one axis and span, both drawn from one read', async () => {
		render(
			<IntelPageContext.Provider value={ DESK }>
				<trendChart.View attributes={ { ...ATTRIBUTES, by: 'kind' } } />
				<trendChart.View
					attributes={ { ...ATTRIBUTES, by: 'kind', style: 'lines' } }
				/>
			</IntelPageContext.Provider>
		);
		expect(
			await screen.findByRole( 'button', {
				name: 'Stack Weeks of news type',
				pressed: false,
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Stack Weeks of news type',
				pressed: true,
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk', '--by=kind' ],
		] );
	} );

	it( 'shows the shared read error in its place, under its heading', async () => {
		wire.replies.trends = () => new Error( 'by must be one of: kind' );
		block( { by: 'topic' } );
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Weeks of subject area' } )
		).toBeInTheDocument();
	} );

	it( 'writes its heading, axis, span and style from its controls', () => {
		const setAttributes = jest.fn();
		render(
			<trendChart.Controls
				attributes={ ATTRIBUTES }
				setAttributes={ setAttributes }
			/>
		);
		const optionsOf = ( label ) =>
			[ ...screen.getByLabelText( label ).options ].map(
				( one ) => one.textContent
			);
		expect( optionsOf( 'Chart by' ) ).toEqual( [
			'As the view sets it',
			'News type',
			'Subject area',
			'Thread',
		] );
		expect( optionsOf( 'Group by' ) ).toEqual( [
			'As the view sets it',
			'Daily',
			'Weekly',
			'Monthly',
		] );

		fireEvent.change( screen.getByLabelText( 'Heading' ), {
			target: { value: 'Months of %s' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( {
			heading: 'Months of %s',
		} );
		fireEvent.change( screen.getByLabelText( 'Chart by' ), {
			target: { value: 'theme' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( { by: 'theme' } );
		fireEvent.change( screen.getByLabelText( 'Group by' ), {
			target: { value: 'month' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( { bucket: 'month' } );
		fireEvent.change( screen.getByLabelText( 'Draw as' ), {
			target: { value: 'lines' },
		} );
		expect( setAttributes ).toHaveBeenLastCalledWith( { style: 'lines' } );
	} );
} );

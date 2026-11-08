/* eslint-env jest */
/**
 * A signal's value names its axis and kind in the page's own language, then
 * the value it counts, when it counts one. A trend table shows each row's
 * bucket, value, count and cited items, its bucket a date that does not
 * wrap and its Value cell the class that keeps its column as wide as its
 * longest word. A trend chart draws the largest values over the latest
 * buckets as series of the shared time chart, and hands a screen reader
 * every row as a table. A signals answer reads "Steady" beside its
 * sparkline when nothing signals, and otherwise calls out each signal there
 * before listing them.
 */

import { render, screen, within } from '@testing-library/react';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import TrendTable, {
	SignalTrend,
	TrendChart,
	signalValue,
} from '../TrendTable';

const THEMES = [
	{
		bucket: '2026-09-21',
		value: 'site blocked from sending email',
		count: 12,
		clients: [
			{
				entity_key: 'organization:harbor-ledger',
				name: 'Harbor Ledger',
			},
			{
				entity_key: 'organization:acme-quillsheet',
				name: 'Acme Quillsheet',
			},
		],
		items: [
			{
				item_key: 'linear:SUP-120',
				url: 'https://linear.example/SUP-120',
				title: 'Acme mail bounces at the sender',
			},
		],
	},
	{
		bucket: '2026-09-14',
		value: 'client offboarding',
		count: 1,
		clients: [
			{
				entity_key: 'organization:kestrel-bayou',
				name: 'Kestrel Bayou',
			},
		],
		items: [
			{
				item_key: 'linear:SUP-64',
				url: 'https://linear.example/SUP-64',
				title: 'Kestrel Bayou asks to export its archive',
			},
		],
	},
];

afterEach( () => resetLocaleData( undefined, 'newspack-intelligence' ) );

describe( 'signalValue', () => {
	it( 'translates the axis and the kind', () => {
		setLocaleData(
			{
				'': { domain: 'newspack-intelligence' },
				publisher: [ 'éditeur' ],
				drop: [ 'baisse' ],
				about: [ 'sujet' ],
				burst: [ 'rafale' ],
			},
			'newspack-intelligence'
		);
		expect(
			signalValue( { axis: 'publisher', signal: 'drop', value: null } )
		).toBe( 'éditeur baisse' );
		expect(
			signalValue( {
				axis: 'about',
				signal: 'burst',
				value: 'partnership',
			} )
		).toBe( 'sujet rafale: partnership' );
	} );

	it( 'names an axis or a kind it does not know as the server spelled it', () => {
		expect(
			signalValue( { axis: 'harbor', signal: 'swell', value: null } )
		).toBe( 'harbor swell' );
	} );
} );

describe( 'TrendTable', () => {
	const rowOf = ( bucket ) =>
		screen.getByRole( 'row', { name: new RegExp( bucket ) } );

	it( 'marks the Value cell of a trend row and a signal row', () => {
		const valueCell = ( bucket ) =>
			within( rowOf( bucket ) ).getAllByRole( 'cell' )[ 1 ];
		const { unmount } = render(
			<TrendTable
				rows={ [
					{
						bucket: '2026-10-05',
						value: 'incident',
						count: 4,
						items: [],
					},
				] }
			/>
		);
		expect( valueCell( '2026-10-05' ) ).toHaveClass(
			'newspack-intelligence__value'
		);
		unmount();

		render(
			<TrendTable
				rows={ [
					{
						axis: 'about',
						signal: 'spike',
						bucket: '2026-10-12',
						value: 'release',
						count: 7,
						baseline: 1.5,
						items: [],
					},
				] }
				value={ signalValue }
			/>
		);
		expect( valueCell( '2026-10-12' ) ).toHaveTextContent(
			'about spike: release'
		);
		expect( valueCell( '2026-10-12' ) ).toHaveClass(
			'newspack-intelligence__value'
		);
		expect(
			within( rowOf( '2026-10-12' ) ).getAllByRole( 'cell' )[ 0 ]
		).not.toHaveClass( 'newspack-intelligence__value' );
	} );

	it( 'shows a row’s items under Items and none of its clients', () => {
		render( <TrendTable rows={ THEMES } /> );
		expect(
			screen
				.getAllByRole( 'columnheader' )
				.map( ( header ) => header.textContent )
		).toEqual( [ 'Bucket', 'Value', 'Count', 'Items' ] );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		const cells = within( rowOf( '2026-09-14' ) ).getAllByRole( 'cell' );
		expect( cells[ 1 ] ).toHaveTextContent( 'client offboarding' );
		expect(
			within( cells[ 3 ] ).getByRole( 'link', {
				name: 'Kestrel Bayou asks to export its archive',
			} )
		).toBeInTheDocument();
	} );
} );

describe( 'TrendTable dates', () => {
	it( 'marks every bucket cell as a date that does not wrap', () => {
		render( <TrendTable rows={ THEMES } /> );
		for ( const bucket of [ '2026-09-21', '2026-09-14' ] ) {
			expect( screen.getByText( bucket ) ).toHaveClass(
				'newspack-intelligence__date'
			);
		}
	} );
} );

describe( 'TrendChart', () => {
	/**
	 * Trend rows, newest bucket first, as `trends` answers them: nine weekly
	 * buckets, so the oldest falls outside the latest eight, and six values,
	 * so the smallest falls outside the top five.
	 */
	const WEEKS = [
		'2026-10-26',
		'2026-10-19',
		'2026-10-12',
		'2026-10-05',
		'2026-09-28',
		'2026-09-21',
		'2026-09-14',
		'2026-09-07',
		'2026-08-31',
	];
	const ROWS = [
		...WEEKS.slice( 0, 8 ).map( ( bucket ) => ( {
			bucket,
			value: 'complaint',
			count: 3,
			items: [],
		} ) ),
		{ bucket: WEEKS[ 0 ], value: 'praise', count: 6, items: [] },
		{ bucket: WEEKS[ 3 ], value: 'praise', count: 2, items: [] },
		{ bucket: WEEKS[ 1 ], value: 'incident', count: 5, items: [] },
		{ bucket: WEEKS[ 2 ], value: 'bug', count: 4, items: [] },
		{ bucket: WEEKS[ 4 ], value: 'launch', count: 3, items: [] },
		{ bucket: WEEKS[ 5 ], value: 'hire', count: 1, items: [] },
		{ bucket: WEEKS[ 8 ], value: 'release', count: 40, items: [] },
	];

	const legend = ( container ) =>
		Array.from(
			container.querySelectorAll( '.newspack-nodes-chart-legend button' )
		).map( ( row ) => row.textContent );

	/**
	 * Every number the drawn SVG carries in a path or an attribute.
	 *
	 * @param {Element} container The rendered chart.
	 * @return {string} The SVG's markup.
	 */
	const markup = ( container ) =>
		Array.from( container.querySelectorAll( 'svg' ) )
			.map( ( svg ) => svg.outerHTML )
			.join( '' );

	it( 'draws the five largest values over the latest eight buckets as series of one shared time chart', () => {
		const { container } = render( <TrendChart rows={ ROWS } /> );
		expect(
			container.querySelectorAll( '.newspack-nodes-chart' )
		).toHaveLength( 1 );
		expect( legend( container ) ).toEqual( [
			'complaint',
			'praise',
			'incident',
			'bug',
			'launch',
		] );
		expect(
			screen.getByRole( 'heading', {
				name: 'From 2026-09-07 to 2026-10-26',
			} )
		).toBeInTheDocument();
		expect( markup( container ) ).toMatch( /<path / );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'hands a screen reader every row as a table', () => {
		render( <TrendChart rows={ ROWS } /> );
		const table = screen.getByRole( 'table' );
		expect( within( table ).getAllByRole( 'row' ) ).toHaveLength(
			ROWS.length + 1
		);
		expect(
			within( table ).getByRole( 'row', { name: /2026-08-31/ } )
		).toHaveTextContent( 'release' );
		expect( within( table ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'draws counts that are all zero without a NaN', () => {
		const { container } = render(
			<TrendChart
				rows={ WEEKS.slice( 0, 3 ).map( ( bucket ) => ( {
					bucket,
					value: 'complaint',
					count: 0,
					items: [],
				} ) ) }
			/>
		);
		expect( legend( container ) ).toEqual( [ 'complaint' ] );
		expect( markup( container ) ).toMatch( /<path / );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'draws one bucket without a NaN', () => {
		const { container } = render(
			<TrendChart
				rows={ [
					{
						bucket: WEEKS[ 0 ],
						value: 'complaint',
						count: 7,
						items: [],
					},
				] }
			/>
		);
		expect( legend( container ) ).toEqual( [ 'complaint' ] );
		expect( markup( container ) ).toMatch( /<path / );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'draws nothing for no rows', () => {
		const { container } = render( <TrendChart rows={ [] } /> );
		expect( container.querySelector( '.newspack-nodes-chart' ) ).toBeNull();
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'SignalTrend', () => {
	const trend = [
		{ bucket: '2026-10-12', count: 9 },
		{ bucket: '2026-10-05', count: 2 },
	];
	const spike = {
		axis: 'about',
		signal: 'spike',
		bucket: '2026-10-12',
		by: 'kind',
		value: 'complaint',
		count: 9,
		baseline: 2,
		items: [],
	};

	it( 'reads no spike as steady beside the sparkline, with no table', () => {
		render( <SignalTrend answer={ { signals: [], trend } } /> );
		const line = screen.getByText( 'Steady' ).closest( 'p' );
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 2, 9; the latest, from 2026-10-12, counts 9/
		);
		expect( screen.queryByRole( 'table' ) ).toBeNull();
		expect( screen.queryByText( /No signal/ ) ).toBeNull();
	} );

	it( 'calls out each signal beside the sparkline, then lists them', () => {
		render( <SignalTrend answer={ { signals: [ spike ], trend } } /> );
		const line = screen
			.getByText( 'about spike: complaint' )
			.closest( 'p' );
		expect( within( line ).getByRole( 'img' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Steady' ) ).toBeNull();
		expect(
			screen.getByRole( 'row', { name: /2026-10-12/ } )
		).toHaveTextContent( '9 against a baseline of 2' );
	} );

	it( 'names one bucket in the singular and draws no line for none', () => {
		const { container, rerender } = render(
			<SignalTrend
				answer={ {
					signals: [],
					trend: [ { bucket: '2026-10-19', count: 7 } ],
				} }
			/>
		);
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			'Stories in the last bucket, from 2026-10-19: 7'
		);
		rerender( <SignalTrend answer={ { signals: [], trend: [] } } /> );
		expect( container.querySelector( 'svg' ) ).toBeNull();
		expect( screen.getByText( 'Steady' ) ).toBeInTheDocument();
	} );

	it( 'shows each row’s value as the caller names it', () => {
		render(
			<SignalTrend
				answer={ { signals: [ spike ], trend } }
				value={ signalValue }
			/>
		);
		expect(
			screen.getByRole( 'row', { name: /2026-10-12/ } )
		).toHaveTextContent( 'about spike: complaint' );
	} );
} );

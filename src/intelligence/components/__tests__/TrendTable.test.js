/* eslint-env jest */
/**
 * A signal says itself in the vocabulary's words, a spike naming what
 * jumped and any other the value it counts, when it counts one. A trend
 * table shows each row's bucket, value, count and cited items, a kind by its word, its bucket a date that does not
 * wrap and its Value cell the class that keeps its column as wide as its
 * longest word. A trend chart draws the largest values over the latest
 * buckets as series of the shared time chart, and hands a screen reader
 * every row as a table. A signals answer that lists no signal says whether
 * nothing unusual changed or there is too little history to judge, never
 * "Steady", and otherwise calls out each signal beside its sparkline before
 * listing them; the sparkline breaks at a bucket the read never reached
 * and draws the usual range behind the line.
 */

import { render, screen, within, fireEvent } from '@testing-library/react';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import { chartColor } from '@newspack-nodes/shared/hooks/useTimeChart';
import TrendTable, {
	Signals,
	TrendChart,
	rankColor,
	signalValue,
} from '../TrendTable';
import { pageData } from '../../views/__tests__/fixtures';

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

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
	it( 'says a signal in the vocabulary’s words, a spike naming what jumped', () => {
		expect(
			signalValue( { axis: 'publisher', signal: 'drop', value: null } )
		).toBe( 'Posted little' );
		expect(
			signalValue( {
				axis: 'publisher',
				signal: 'burst',
				value: 'partnership',
			} )
		).toBe( 'Posted a lot: Teaming up' );
		expect(
			signalValue( { axis: 'about', signal: 'spike', value: 'release' } )
		).toBe( 'Version out climbed' );
		expect(
			signalValue( {
				axis: 'about',
				signal: 'spike',
				value: 'fees up $& more',
			} )
		).toBe( 'fees up $& more climbed' );
	} );

	it( 'translates the joining words around a counted value', () => {
		setLocaleData(
			{
				'': { domain: 'newspack-intelligence' },
				'%1$s: %2$s': [ '%1$s — %2$s' ],
			},
			'newspack-intelligence'
		);
		expect(
			signalValue( {
				axis: 'publisher',
				signal: 'burst',
				value: 'partnership',
			} )
		).toBe( 'Posted a lot — Teaming up' );
	} );

	it( 'refuses a signal the vocabulary does not word', () => {
		expect( () =>
			signalValue( { axis: 'harbor', signal: 'swell', value: null } )
		).toThrow( 'no signalWords word for "swell"' );
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
						usual: { mean: 1.5, low: 0, high: 3.9 },
						items: [],
					},
				] }
				value={ signalValue }
			/>
		);
		expect( valueCell( '2026-10-12' ) ).toHaveTextContent(
			'Version out climbed'
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
		).toEqual( [ 'When', 'What', 'Items', 'Examples' ] );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		const cells = within( rowOf( '2026-09-14' ) ).getAllByRole( 'cell' );
		expect( cells[ 1 ] ).toHaveTextContent( 'client offboarding' );
		expect(
			within( cells[ 3 ] ).getByRole( 'link', {
				name: 'Kestrel Bayou asks to export its archive',
			} )
		).toBeInTheDocument();
	} );

	it( 'saves each cited item to a folder when given a save handler', () => {
		const onSave = jest.fn();
		render( <TrendTable rows={ THEMES } onSave={ onSave } /> );
		const cells = within( rowOf( '2026-09-14' ) ).getAllByRole( 'cell' );
		fireEvent.click(
			within( cells[ 3 ] ).getAllByRole( 'button', {
				name: 'Save to folder',
			} )[ 0 ]
		);
		expect( onSave ).toHaveBeenCalledWith(
			expect.objectContaining( {
				title: 'Kestrel Bayou asks to export its archive',
			} )
		);
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
	/** Wednesday 4 November 2026, 15:00 UTC: the week of 2 November runs. */
	const NOW = Date.UTC( 2026, 10, 4, 15 ) / 1000;

	/**
	 * Trend rows, newest bucket first, as `trends` answers them: the week
	 * still running, then nine complete weeks, so the oldest falls outside the
	 * latest eight, and six values, so the smallest falls outside the top five.
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
		{ bucket: '2026-11-02', value: 'complaint', count: 4, items: [] },
		{ bucket: '2026-11-02', value: 'praise', count: 7, items: [] },
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
	const ANSWER = { rows: ROWS, by: 'kind', bucket: 'week' };
	const HEADING = 'Eight weeks of %s';

	beforeEach( () => {
		jest.spyOn( Core, 'now' ).mockReturnValue( NOW );
		window.localStorage.clear();
	} );

	afterEach( () => jest.restoreAllMocks() );

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

	const chart = ( props ) =>
		render(
			<TrendChart
				answer={ ANSWER }
				heading={ HEADING }
				style="stack"
				{ ...props }
			/>
		);

	it( 'draws the five largest values over the eight complete buckets as series of one shared time chart', () => {
		const { container } = chart();
		expect(
			container.querySelectorAll( '.newspack-nodes-chart' )
		).toHaveLength( 1 );
		expect( legend( container ) ).toEqual( [
			'Grumble',
			'Kudos',
			'Outage',
			'Defect',
			'Going live',
		] );
		expect( markup( container ) ).toMatch( /<path / );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'titles the chart by its heading, naming the axis it counted in lower case', () => {
		chart();
		expect(
			screen.getByRole( 'heading', { name: 'Eight weeks of news type' } )
		).toBeInTheDocument();
	} );

	it( 'ticks its weeks by day and short month, level', () => {
		const { container } = chart();
		const ticks = [
			...container.querySelectorAll( 'svg .tick text' ),
		].filter( ( text ) => /[A-Za-z]/.test( text.textContent ) );
		expect( ticks.length ).toBeGreaterThan( 0 );
		for ( const text of ticks ) {
			expect( text.textContent ).toMatch( /^\d{1,2} [A-Z][a-z]{2}$/ );
			expect( text.getAttribute( 'transform' ) ).toBeNull();
		}
	} );

	it( 'draws the running week apart as one hatched bar of its total, labelled this week', () => {
		const { container } = chart();
		const bar = container.querySelector( '.newspack-nodes-chart__partial' );
		expect( bar ).not.toBeNull();
		expect( bar.querySelector( 'title' ) ).toHaveTextContent(
			'this week: 11'
		);
		expect(
			container.querySelector( '.newspack-nodes-chart__partial-label' )
		).toHaveTextContent( 'this week' );
	} );

	it( 'names the running day and month by their own span', () => {
		const { container, rerender } = chart( {
			answer: {
				rows: [
					{ bucket: '2026-11-04', value: 'bug', count: 2, items: [] },
					{ bucket: '2026-11-03', value: 'bug', count: 5, items: [] },
				],
				by: 'kind',
				bucket: 'day',
			},
		} );
		expect(
			container.querySelector( '.newspack-nodes-chart__partial title' )
		).toHaveTextContent( 'today: 2' );
		rerender(
			<TrendChart
				answer={ {
					rows: [
						{
							bucket: '2026-11-01',
							value: 'bug',
							count: 9,
							items: [],
						},
						{
							bucket: '2026-10-01',
							value: 'bug',
							count: 5,
							items: [],
						},
					],
					by: 'kind',
					bucket: 'month',
				} }
				heading={ HEADING }
				style="stack"
			/>
		);
		expect(
			container.querySelector( '.newspack-nodes-chart__partial title' )
		).toHaveTextContent( 'this month: 9' );
	} );

	it( 'draws lines for the lines style, so no total bar stands beside them', () => {
		const { container } = chart( { style: 'lines' } );
		expect(
			screen.getByRole( 'button', {
				name: 'Stack Eight weeks of news type',
			} )
		).toHaveAttribute( 'aria-pressed', 'false' );
		expect(
			container.querySelector( '.newspack-nodes-chart__partial' )
		).toBeNull();
	} );

	it( 'names each theme in sentence case', () => {
		const { container } = chart( {
			answer: {
				rows: [
					{
						bucket: '2026-10-26',
						value: 'client offboarding',
						count: 3,
						items: [],
					},
				],
				by: 'theme',
				bucket: 'week',
			},
		} );
		expect( legend( container ) ).toEqual( [ 'Client offboarding' ] );
		expect(
			screen.getByRole( 'heading', { name: 'Eight weeks of thread' } )
		).toBeInTheDocument();
	} );

	it( 'hands a screen reader every row as a table', () => {
		chart();
		const table = screen.getByRole( 'table' );
		expect( within( table ).getAllByRole( 'row' ) ).toHaveLength(
			ROWS.length + 1
		);
		expect(
			within( table ).getByRole( 'row', { name: /2026-08-31/ } )
		).toHaveTextContent( 'Version out' );
		expect( within( table ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'draws counts that are all zero without a NaN', () => {
		const { container } = chart( {
			answer: {
				...ANSWER,
				rows: WEEKS.slice( 0, 3 ).map( ( bucket ) => ( {
					bucket,
					value: 'complaint',
					count: 0,
					items: [],
				} ) ),
			},
		} );
		expect( legend( container ) ).toEqual( [ 'Grumble' ] );
		expect( markup( container ) ).toMatch( /<path / );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'draws one bucket without a NaN', () => {
		const { container } = chart( {
			answer: {
				...ANSWER,
				rows: [
					{
						bucket: WEEKS[ 0 ],
						value: 'complaint',
						count: 7,
						items: [],
					},
				],
			},
		} );
		expect( legend( container ) ).toEqual( [ 'Grumble' ] );
		expect( markup( container ) ).not.toMatch( /NaN/ );
	} );

	it( 'draws nothing for no rows', () => {
		const { container } = chart( { answer: { ...ANSWER, rows: [] } } );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'rankColor', () => {
	const colors = ( count ) =>
		Array.from( { length: count }, ( _, n ) =>
			rankColor( count )( `value ${ n }`, n )
		);

	it( 'takes palette slots 1, 2 and 4 for three series, so no two are the accent', () => {
		expect( colors( 3 ) ).toEqual( [
			chartColor( 0 ),
			chartColor( 1 ),
			chartColor( 3 ),
		] );
		expect( colors( 2 ) ).toEqual( [ chartColor( 0 ), chartColor( 1 ) ] );
	} );

	it( 'takes the slots in rank for four series or more', () => {
		expect( colors( 4 ) ).toEqual( [ 0, 1, 2, 3 ].map( chartColor ) );
	} );
} );

describe( 'Signals', () => {
	const trend = [
		{ bucket: '2026-10-12', count: 9, in_window: true },
		{ bucket: '2026-10-05', count: 2, in_window: true },
	];
	const judged = {
		trend,
		partial: { bucket: '2026-10-19', count: 4 },
		judged: true,
		bucket: 'week',
		baseline_buckets: 8,
	};
	const tooNew = { ...judged, judged: false };
	const spike = {
		axis: 'about',
		signal: 'spike',
		bucket: '2026-10-12',
		by: 'kind',
		value: 'complaint',
		count: 7,
		usual: { mean: 2, low: 0.5, high: 3.5 },
		trend: [
			{ bucket: '2026-10-12', count: 7, in_window: true },
			{ bucket: '2026-10-05', count: 1, in_window: true },
		],
		items: [],
	};

	const band = ( container ) =>
		container.querySelector( '.newspack-nodes-sparkline__band' );

	it( 'draws no sparkline of zeros for a view that counted nothing', () => {
		const { container } = render(
			<Signals
				answer={ {
					...tooNew,
					signals: [],
					trend: trend.map( ( point ) => ( { ...point, count: 0 } ) ),
					partial: { bucket: '2026-10-19', count: 0 },
				} }
			/>
		);
		expect(
			screen.getByText(
				'Too little history to judge. A change is measured against the 8 weeks before.'
			)
		).toBeInTheDocument();
		expect( screen.queryByRole( 'img' ) ).toBeNull();
		expect( band( container ) ).toBeNull();
	} );

	it( "hands its table the save handler, so each signal's items save to a folder", () => {
		const onSave = jest.fn();
		const cited = {
			...spike,
			items: [
				{
					item_key: 'linear:SUP-311',
					url: 'https://tracker.example/SUP-311',
					title: 'Heron Ledger asks for a refund',
				},
			],
		};
		render(
			<Signals
				answer={ { ...judged, signals: [ cited ] } }
				onSave={ onSave }
			/>
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Save to folder' } )
		);
		expect( onSave ).toHaveBeenCalledWith( cited.items[ 0 ] );
	} );

	it( 'shows each row’s value as the caller names it, counted against its usual mean', () => {
		render(
			<Signals
				answer={ { ...judged, signals: [ spike ] } }
				value={ signalValue }
			/>
		);
		expect(
			screen.getByRole( 'row', { name: /2026-10-12/ } )
		).toHaveTextContent( 'Grumble climbed7, usually about 2' );
	} );

	it( 'reads a judged answer with no spike as no unusual change over its totals, with no band and no table', () => {
		const { container } = render(
			<Signals answer={ { ...judged, signals: [] } } />
		);
		const line = screen
			.getByText( 'No unusual change last week.' )
			.closest( 'p' );
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 2, 9; the latest, from 2026-10-12, counts 9/
		);
		expect( band( container ) ).toBeNull();
		expect( screen.queryByRole( 'table' ) ).toBeNull();
		expect( screen.queryByText( /Steady|Too little history/ ) ).toBeNull();
	} );

	it.each( [
		[ 'day', 'No unusual change yesterday.' ],
		[ 'week', 'No unusual change last week.' ],
		[ 'month', 'No unusual change last month.' ],
	] )( 'names a quiet %s by its bucket', ( bucket, words ) => {
		render( <Signals answer={ { ...judged, bucket, signals: [] } } /> );
		expect( screen.getByText( words ) ).toBeInTheDocument();
	} );

	it.each( [
		[
			'day',
			3,
			'Too little history to judge. A change is measured against the 3 days before.',
		],
		[
			'week',
			1,
			'Too little history to judge. A change is measured against the 1 week before.',
		],
		[
			'month',
			5,
			'Too little history to judge. A change is measured against the 5 months before.',
		],
	] )(
		'reads a %s answer too new to judge against its %d buckets, never as steady',
		( bucket, count, words ) => {
			render(
				<Signals
					answer={ {
						...tooNew,
						bucket,
						baseline_buckets: count,
						signals: [],
					} }
				/>
			);
			expect( screen.getByText( words ) ).toBeInTheDocument();
			expect(
				screen.queryByText( /Steady|No unusual change/ )
			).toBeNull();
		}
	);

	it( 'draws a signal’s own series with its usual range, not the totals', () => {
		const { container } = render(
			<Signals answer={ { ...judged, signals: [ spike ] } } />
		);
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 1, 7; the latest, from 2026-10-12, counts 7/
		);
		expect( band( container ) ).not.toBeNull();
	} );

	it( 'draws a drop’s series with its own usual range, as every signal takes one', () => {
		const drop = {
			...spike,
			axis: 'publisher',
			signal: 'drop',
			by: null,
			value: null,
			usual: { mean: 6.25, low: 3.5, high: 9 },
		};
		const { container } = render(
			<Signals answer={ { ...judged, signals: [ drop ] } } />
		);
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 1, 7;/
		);
		expect( band( container ) ).not.toBeNull();
		expect(
			screen.getByText( 'Posted little: 7 last week, usually about 6.' )
		).toBeInTheDocument();
	} );

	it( 'names the signal whose series the line draws, the first of several', () => {
		const second = {
			...spike,
			value: 'praise',
			count: 4,
			trend: [
				{ bucket: '2026-10-12', count: 4, in_window: true },
				{ bucket: '2026-10-05', count: 0, in_window: true },
			],
		};
		const { rerender } = render(
			<Signals answer={ { ...judged, signals: [ spike, second ] } } />
		);
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			'The line draws Grumble. Items in each of the last 2 periods, oldest first: 1, 7; the latest, from 2026-10-12, counts 7'
		);
		expect(
			screen.getByText( 'Grumble: 7 last week, usually about 2.' )
		).toBeInTheDocument();
		expect( screen.queryByText( /^Praise:/ ) ).toBeNull();
		rerender( <Signals answer={ { ...judged, signals: [ second ] } } /> );
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			'Items in each of the last 2 periods, oldest first: 0, 4; the latest, from 2026-10-12, counts 4'
		);
	} );

	it( 'breaks the line at a bucket the read never reached', () => {
		const { container } = render(
			<Signals
				answer={ {
					...tooNew,
					signals: [],
					trend: [
						{ bucket: '2026-10-12', count: 9, in_window: true },
						{ bucket: '2026-10-05', count: 6, in_window: true },
						{ bucket: '2026-09-28', count: 0, in_window: false },
						{ bucket: '2026-09-21', count: 3, in_window: true },
					],
				} }
			/>
		);
		const path = container.querySelector( 'svg path' ).getAttribute( 'd' );
		expect( path.match( /M/g ) ).toHaveLength( 2 );
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 3, not read, 6, 9;/
		);
	} );

	it( 'reads a latest bucket the read never reached as not read, never as 0', () => {
		render(
			<Signals
				answer={ {
					...tooNew,
					signals: [],
					trend: [
						{ bucket: '2026-10-12', count: 0, in_window: false },
						{ bucket: '2026-10-05', count: 6, in_window: true },
					],
				} }
			/>
		);
		const name = screen.getByRole( 'img' ).getAttribute( 'aria-label' );
		expect( name ).toBe(
			'Items in each of the last 2 periods, oldest first: 6, not read; the latest, from 2026-10-12, was not read'
		);
		expect( name ).not.toMatch( /counts 0/ );
	} );

	it( 'says the signal in words on its pulse line, the bucket still running apart', () => {
		render( <Signals answer={ { ...judged, signals: [ spike ] } } /> );
		const line = screen
			.getByText( 'Grumble: 7 last week, usually about 2.' )
			.closest( 'p' );
		expect( within( line ).getByRole( 'img' ) ).toBeInTheDocument();
		expect(
			within( line ).getByText( '4 so far this week' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( /Steady|No unusual change|Too little/ )
		).toBeNull();
	} );

	it( 'names one bucket in the singular, and draws no line for a lone unread one or for none', () => {
		const one = ( point ) => ( {
			...tooNew,
			signals: [],
			trend: [ point ],
		} );
		const { container, rerender } = render(
			<Signals
				answer={ one( {
					bucket: '2026-10-19',
					count: 7,
					in_window: true,
				} ) }
			/>
		);
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			'Items in the last period, from 2026-10-19: 7'
		);
		rerender(
			<Signals
				answer={ one( {
					bucket: '2026-10-19',
					count: 0,
					in_window: false,
				} ) }
			/>
		);
		expect( container.querySelector( 'svg' ) ).toBeNull();
		rerender(
			<Signals answer={ { ...tooNew, signals: [], trend: [] } } />
		);
		expect( container.querySelector( 'svg' ) ).toBeNull();
		expect( screen.getByText( /Too little history/ ) ).toBeInTheDocument();
	} );
} );

/* eslint-env jest */
/**
 * A pulse line says what changed in words, then draws it: a signal reads as
 * its subject, its count over the last bucket and what is usual, with the
 * usual range as the sparkline's band; a quiet answer says there was no
 * unusual change, or that there is too little history to judge. A bucket the
 * read never reached is a gap, never a zero, and the bucket still running is
 * its own phrase, never a point on the line. The line's accessible name
 * says which signal it draws only when there are several.
 */

import { render, screen, within } from '@testing-library/react';
import PulseLine, { spanWords } from '../PulseLine';
import { pageData } from '../../views/__tests__/fixtures';

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

const TREND = [
	{ bucket: '2026-11-02', count: 6, in_window: true },
	{ bucket: '2026-10-26', count: 1, in_window: true },
	{ bucket: '2026-10-19', count: 0, in_window: false },
	{ bucket: '2026-10-12', count: 2, in_window: true },
];

const SIGNAL = {
	axis: 'about',
	signal: 'spike',
	bucket: '2026-11-02',
	by: 'theme',
	value: 'offboarding',
	count: 6,
	usual: { mean: 0.4, low: 0.2, high: 3 },
	trend: TREND,
	items: [],
};

const band = ( container ) =>
	container.querySelector( '.newspack-nodes-sparkline__band' );

describe( 'PulseLine', () => {
	it( 'says a change in words, then the bucket still running, apart', () => {
		const { container } = render(
			<PulseLine
				signal={ SIGNAL }
				subject="Offboarding"
				trend={ TREND }
				partial={ { bucket: '2026-11-09', count: 11 } }
				judged={ true }
				words={ spanWords( 'week', 8 ) }
			/>
		);
		const line = container.querySelector( '.newspack-intelligence__pulse' );
		expect(
			within( line ).getByText(
				'Offboarding: 6 last week, usually under 1.'
			)
		).toBeInTheDocument();
		expect(
			within( line ).getByText( '11 so far this week' )
		).toBeInTheDocument();
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			/^Items in each of the last 4 periods, oldest first: 2, not read, 1, 6;/
		);
		expect( band( container ) ).not.toBeNull();
	} );

	it( 'draws the signal’s own usual range as the band, low to high', () => {
		const { container } = render(
			<PulseLine
				signal={ { ...SIGNAL, usual: { mean: 2, low: 1, high: 5 } } }
				subject="Offboarding"
				trend={ TREND }
				partial={ null }
				judged={ true }
				words={ spanWords( 'week', 8 ) }
			/>
		);
		const svg = container.querySelector( 'svg' );
		const rect = band( container );
		const height = Number( svg.getAttribute( 'height' ) );
		expect( svg.getAttribute( 'width' ) ).toBe( '96' );
		expect( height ).toBe( 24 );
		const top = Number( rect.getAttribute( 'y' ) );
		const bottom = top + Number( rect.getAttribute( 'height' ) );
		// The peak is the band's high, 5, against the line's highest, 6.
		expect( top ).toBeGreaterThan( 3 );
		expect( bottom ).toBeLessThan( height - 3 );
	} );

	it( 'reads a too-new answer as too little history, with no band', () => {
		const { container } = render(
			<PulseLine
				signal={ null }
				subject=""
				trend={ TREND }
				partial={ { bucket: '2026-11-09', count: 3 } }
				judged={ false }
				words={ spanWords( 'week', 8 ) }
			/>
		);
		expect(
			screen.getByText(
				'Too little history to judge. A change is measured against the 8 weeks before.'
			)
		).toBeInTheDocument();
		expect( screen.getByText( '3 so far this week' ) ).toBeInTheDocument();
		expect( band( container ) ).toBeNull();
		expect( screen.getByRole( 'img' ) ).toHaveAccessibleName(
			/^Items in each of the last 4 periods/
		);
	} );

	it( 'reads a judged answer with no signal as no unusual change', () => {
		render(
			<PulseLine
				signal={ null }
				subject=""
				trend={ TREND }
				partial={ null }
				judged={ true }
				words={ spanWords( 'month', 8 ) }
			/>
		);
		expect(
			screen.getByText( 'No unusual change last month.' )
		).toBeInTheDocument();
		expect( screen.queryByText( /so far/ ) ).toBeNull();
	} );

	it( 'hands the sparkline a gap for each bucket outside the window', () => {
		const { container } = render(
			<PulseLine
				signal={ null }
				subject=""
				trend={ [
					{ bucket: '2026-11-02', count: 9, in_window: true },
					{ bucket: '2026-10-26', count: 4, in_window: true },
					{ bucket: '2026-10-19', count: 0, in_window: false },
					{ bucket: '2026-10-12', count: 3, in_window: true },
					{ bucket: '2026-10-05', count: 5, in_window: true },
				] }
				partial={ null }
				judged={ false }
				words={ spanWords( 'week', 8 ) }
			/>
		);
		const path = container.querySelector( 'svg path' ).getAttribute( 'd' );
		expect( path.match( /M/g ) ).toHaveLength( 2 );
	} );

	it.each( [
		[
			'day',
			'Offboarding: 6 yesterday, usually under 1.',
			'2 so far today',
		],
		[
			'month',
			'Offboarding: 6 last month, usually under 1.',
			'2 so far this month',
		],
	] )( 'words a %s by its span', ( bucket, said, soFar ) => {
		render(
			<PulseLine
				signal={ SIGNAL }
				subject="Offboarding"
				trend={ TREND }
				partial={ { bucket: '2026-11-08', count: 2 } }
				judged={ true }
				words={ spanWords( bucket, 8 ) }
			/>
		);
		expect( screen.getByText( said ) ).toBeInTheDocument();
		expect( screen.getByText( soFar ) ).toBeInTheDocument();
	} );
} );

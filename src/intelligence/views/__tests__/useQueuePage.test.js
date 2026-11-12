/* eslint-env jest */
/**
 * A review queue's page turns after the cursor the page before it answered,
 * holds the page it shows while the next is owed, and turns back to the
 * cursor a page came from.
 */

import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { Profiler } from 'react';
import { useState } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { renderOnPage } from '../../__tests__/pageReads';
import { pageData } from './fixtures';
import { useQueuePage } from '../useQueuePage';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
} );

/**
 * The keys a test page lists, each row's own.
 *
 * @param {{links: Array<{key: string}>}} answer A page's answer.
 * @return {string[]} The keys.
 */
const keysOf = ( answer ) => answer.links.map( ( one ) => one.key );

/**
 * The queue page a test turns: its page, total, whether it is paging, its
 * freshness and the keys its reachable pages list.
 *
 * @return {import('react').ReactElement} The page.
 */
function Queue() {
	const read = useQueuePage( 'probe:links', 'links', keysOf );
	const [ skipped, setSkipped ] = useState( null );
	return (
		<section aria-label="queue">
			{ `page ${ read.page } total ${ read.answer?.total } ${
				read.paging ? 'paging' : 'still'
			} next ${ read.answer?.next } skipped ${ skipped } stale ${
				read.stale
			} updated ${ null === read.updatedAt ? 'never' : 'once' } reached ${
				read.reached ? [ ...read.reached ].join( ',' ) : 'none'
			}` }
			<button
				type="button"
				onClick={ () => setSkipped( read.turn( read.page + 2 ) ) }
			>
				skip
			</button>
			<button type="button" onClick={ () => read.turn( 2 ) }>
				next
			</button>
			<button type="button" onClick={ () => read.turn( 1 ) }>
				back
			</button>
			<button type="button" onClick={ read.refresh }>
				again
			</button>
		</section>
	);
}

const region = () => screen.getByRole( 'region', { name: 'queue' } );

describe( 'useQueuePage', () => {
	it( 'turns to the page after the cursor its page answered, standing on that page while the next is owed, a refresh asking no other page, and back to the cursor a page came from', async () => {
		let answer;
		const later = new Promise( ( resolve ) => ( answer = resolve ) );
		wire.replies.review = ( tokens ) =>
			tokens.includes( '--after=after-harbor-17' )
				? later
				: {
						links: [ { key: 'k-harbor' } ],
						total: 41,
						next: 'after-harbor-17',
						available: true,
				  };
		renderOnPage( <Queue /> );
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 1 total 41 still' )
		);
		expect( wire.sent( 'review' ) ).toEqual( [
			[ 'links', '--limit=50' ],
		] );
		fireEvent.click( screen.getByRole( 'button', { name: 'next' } ) );
		await waitFor( () =>
			expect( wire.sent( 'review' ) ).toEqual( [
				[ 'links', '--limit=50' ],
				[ 'links', '--limit=50', '--after=after-harbor-17' ],
			] )
		);
		expect( region() ).toHaveTextContent(
			'page 1 total 41 paging next after-harbor-17'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'skip' } ) );
		expect( region() ).toHaveTextContent( 'skipped false' );
		fireEvent.click( screen.getByRole( 'button', { name: 'again' } ) );
		await act( async () => {} );
		expect( wire.sent( 'review' ) ).toEqual( [
			[ 'links', '--limit=50' ],
			[ 'links', '--limit=50', '--after=after-harbor-17' ],
		] );
		await act( async () =>
			answer( { links: [], total: 38, next: null, available: true } )
		);
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 2 total 38 still' )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'back' } ) );
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 1 total 41' )
		);
		expect( wire.sent( 'review' ) ).toHaveLength( 3 );
		expect( wire.sent( 'review' )[ 2 ] ).toEqual( [
			'links',
			'--limit=50',
		] );
	} );

	it( 'holds the page it shows with that page’s freshness while the next is owed', async () => {
		wire.replies.review = {
			links: [ { key: 'k-harbor' } ],
			total: 41,
			next: 'after-harbor-17',
			available: true,
		};
		const first = renderOnPage( <Queue /> );
		await waitFor( () =>
			expect( region() ).toHaveTextContent(
				'page 1 total 41 still next after-harbor-17'
			)
		);
		first.unmount();
		wire.replies.review = () => new Promise( () => {} );
		renderOnPage( <Queue /> );
		expect( region() ).toHaveTextContent( 'stale true updated once' );
		fireEvent.click( screen.getByRole( 'button', { name: 'next' } ) );
		await waitFor( () =>
			expect( wire.sent( 'review' ) ).toContainEqual( [
				'links',
				'--limit=50',
				'--after=after-harbor-17',
			] )
		);
		expect( region() ).toHaveTextContent(
			'page 1 total 41 paging next after-harbor-17'
		);
		expect( region() ).toHaveTextContent( 'stale true updated once' );
	} );

	it( 'commits a landing page once', async () => {
		let answer;
		const later = new Promise( ( resolve ) => ( answer = resolve ) );
		wire.replies.review = ( tokens ) =>
			tokens.includes( '--after=after-harbor-17' )
				? later
				: {
						links: [ { key: 'k-harbor' } ],
						total: 41,
						next: 'after-harbor-17',
						available: true,
				  };
		let commits = 0;
		renderOnPage(
			<Profiler id="queue" onRender={ () => ( commits += 1 ) }>
				<Queue />
			</Profiler>
		);
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 1 total 41 still' )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'next' } ) );
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 1 total 41 paging' )
		);
		await act( async () => {} );
		commits = 0;
		await act( async () =>
			answer( {
				links: [ { key: 'k-moor' } ],
				total: 38,
				next: null,
				available: true,
			} )
		);
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 2 total 38 still' )
		);
		await act( async () => {} );
		expect( commits ).toBe( 1 );
	} );

	it( 'answers the keys listed on every page its cursors still reach', async () => {
		let renewed = false;
		wire.replies.review = ( tokens ) => {
			if ( tokens.includes( '--after=after-fen' ) ) {
				return {
					links: [ { key: 'k-moor' } ],
					total: 3,
					next: null,
					available: true,
				};
			}
			return renewed
				? {
						links: [ { key: 'k-harbor' } ],
						total: 2,
						next: 'after-fen-renewed',
						available: true,
				  }
				: {
						links: [ { key: 'k-harbor' }, { key: 'k-fen' } ],
						total: 3,
						next: 'after-fen',
						available: true,
				  };
		};
		renderOnPage( <Queue /> );
		expect( region() ).toHaveTextContent( 'reached none' );
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'reached k-harbor,k-fen' )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'next' } ) );
		await waitFor( () =>
			expect( region() ).toHaveTextContent(
				'page 2 total 3 still next null skipped null stale false updated never reached k-harbor,k-fen,k-moor'
			)
		);
		renewed = true;
		fireEvent.click( screen.getByRole( 'button', { name: 'back' } ) );
		await waitFor( () =>
			expect( region() ).toHaveTextContent( 'page 1 total 2' )
		);
		expect( region() ).toHaveTextContent( 'reached k-harbor' );
		expect( region() ).not.toHaveTextContent( 'k-moor' );
	} );
} );

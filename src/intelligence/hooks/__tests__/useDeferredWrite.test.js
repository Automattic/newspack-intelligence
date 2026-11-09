/* eslint-env jest */
/**
 * useDeferredWrite holds each write for the undo window: its key hides at
 * once, its message is announced, and its send waits UNDO_MS. Undo cancels the
 * most recent write still pending; leaving the page or unmounting sends every
 * pending write at once, each exactly once.
 */

import { renderHook, act, waitFor } from '@testing-library/react';
import { speak } from '@wordpress/a11y';
import { Core, hasSession, reservedNames } from '@newspack-nodes/runtime';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS, useDeferredWrite } from '../useDeferredWrite';
import { useIntelWrite } from '../useIntelCommand';
import { pageData } from '../../views/__tests__/fixtures';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const PRAIRIE = 'organization:prairie-signal';
const COASTLINE = 'organization:coastline-weekly';

beforeEach( () => {
	jest.useFakeTimers();
	speak.mockClear();
	Core.reset();
} );

afterEach( () => {
	jest.useRealTimers();
	// A test's pagehide leaves the transport sending as a page being left.
	window.dispatchEvent( new Event( 'pageshow' ) );
} );

describe( 'useDeferredWrite', () => {
	it( 'sends a deferred write once, after the undo window', () => {
		const send = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer( PRAIRIE, send, 'Rejected Prairie Signal.' )
		);
		expect( result.current.hidden ).toEqual( new Set( [ PRAIRIE ] ) );
		act( () => jest.advanceTimersByTime( 4999 ) );
		expect( send ).not.toHaveBeenCalled();
		act( () => jest.advanceTimersByTime( 1 ) );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( result.current.hidden ).toEqual( new Set() );
		act( () => jest.advanceTimersByTime( UNDO_MS * 3 ) );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'announces each deferred write politely', () => {
		const { result } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer(
				PRAIRIE,
				jest.fn(),
				'Rejected Prairie Signal.'
			)
		);
		expect( speak ).toHaveBeenCalledWith(
			'Rejected Prairie Signal.',
			'polite'
		);
	} );

	it( 'names the most recent pending write as the one an undo takes', () => {
		const { result } = renderHook( () => useDeferredWrite() );
		expect( result.current.latest ).toBeNull();
		act( () =>
			result.current.defer(
				PRAIRIE,
				jest.fn(),
				'Rejected Prairie Signal.'
			)
		);
		act( () =>
			result.current.defer(
				COASTLINE,
				jest.fn(),
				'Confirmed Coastline Weekly.'
			)
		);
		expect( result.current.latest ).toEqual(
			expect.objectContaining( {
				key: COASTLINE,
				message: 'Confirmed Coastline Weekly.',
			} )
		);
	} );

	it( 'undoes the most recent pending write only', () => {
		const first = jest.fn();
		const second = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer( PRAIRIE, first, 'Rejected Prairie Signal.' )
		);
		act( () =>
			result.current.defer(
				COASTLINE,
				second,
				'Confirmed Coastline Weekly.'
			)
		);
		let undone;
		act( () => {
			undone = result.current.undo();
		} );
		expect( undone ).toBe( COASTLINE );
		expect( result.current.hidden ).toEqual( new Set( [ PRAIRIE ] ) );
		expect( result.current.latest ).toEqual(
			expect.objectContaining( { key: PRAIRIE } )
		);
		act( () => jest.advanceTimersByTime( UNDO_MS ) );
		expect( first ).toHaveBeenCalledTimes( 1 );
		expect( second ).not.toHaveBeenCalled();
	} );

	it( 'answers null to an undo with nothing pending', () => {
		const { result } = renderHook( () => useDeferredWrite() );
		let undone;
		act( () => {
			undone = result.current.undo();
		} );
		expect( undone ).toBeNull();
	} );

	it( 'sends every pending write exactly once on pagehide', () => {
		const first = jest.fn();
		const second = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () => {
			result.current.defer( PRAIRIE, first, 'Rejected Prairie Signal.' );
			result.current.defer(
				COASTLINE,
				second,
				'Confirmed Coastline Weekly.'
			);
		} );
		act( () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		expect( first ).toHaveBeenCalledTimes( 1 );
		expect( second ).toHaveBeenCalledTimes( 1 );
		expect( result.current.hidden ).toEqual( new Set() );
		expect( result.current.latest ).toBeNull();
		act( () => jest.advanceTimersByTime( UNDO_MS * 2 ) );
		expect( first ).toHaveBeenCalledTimes( 1 );
		expect( second ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends every pending write exactly once on unmount', () => {
		const send = jest.fn();
		const { result, unmount } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer( PRAIRIE, send, 'Rejected Prairie Signal.' )
		);
		unmount();
		expect( send ).toHaveBeenCalledTimes( 1 );
		jest.advanceTimersByTime( UNDO_MS * 2 );
		window.dispatchEvent( new Event( 'pagehide' ) );
		expect( send ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends every pending write when one send throws, then raises it', () => {
		const refused = new Error( 'collect refused: quarry-notes' );
		const first = jest.fn( () => {
			throw refused;
		} );
		const second = jest.fn();
		const third = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () => {
			result.current.defer( PRAIRIE, first, 'Rejected Prairie Signal.' );
			result.current.defer(
				COASTLINE,
				second,
				'Confirmed Coastline Weekly.'
			);
			result.current.defer(
				'organization:fernwood-gazette',
				third,
				'Rejected Fernwood Gazette.'
			);
		} );
		let raised = null;
		act( () => {
			try {
				result.current.flush();
			} catch ( error ) {
				raised = error;
			}
		} );
		expect( raised ).toBe( refused );
		expect( first ).toHaveBeenCalledTimes( 1 );
		expect( second ).toHaveBeenCalledTimes( 1 );
		expect( third ).toHaveBeenCalledTimes( 1 );
		expect( result.current.hidden ).toEqual( new Set() );
		act( () => jest.advanceTimersByTime( UNDO_MS * 2 ) );
		expect( second ).toHaveBeenCalledTimes( 1 );
		expect( third ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'raises every failing send together, after the last', () => {
		const first = new Error( 'collect refused: quarry-notes' );
		const third = new Error( 'reject refused: fernwood-gazette' );
		const second = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () => {
			result.current.defer(
				PRAIRIE,
				() => {
					throw first;
				},
				'Rejected Prairie Signal.'
			);
			result.current.defer(
				COASTLINE,
				second,
				'Confirmed Coastline Weekly.'
			);
			result.current.defer(
				'organization:fernwood-gazette',
				() => {
					throw third;
				},
				'Rejected Fernwood Gazette.'
			);
		} );
		let raised = null;
		act( () => {
			try {
				result.current.flush();
			} catch ( error ) {
				raised = error;
			}
		} );
		expect( raised ).toBeInstanceOf( AggregateError );
		expect( raised.errors ).toEqual( [ first, third ] );
		expect( raised.message ).toBe(
			'2 failures: collect refused: quarry-notes | reject refused: fernwood-gazette'
		);
		expect( second ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'runs no Router tick of its own when a flush sends', () => {
		const router = holdRouterCadence();
		const fireCb = jest.spyOn( router, 'fireCb' );
		expect( Core.node( reservedNames.ROUTER ) ).toBe( router );
		const send = jest.fn();
		const { result } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer( PRAIRIE, send, 'Rejected Prairie Signal.' )
		);
		act( () => result.current.flush() );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( fireCb ).not.toHaveBeenCalled();
	} );
} );

/**
 * The unmount flush sends through the write's own graph whichever hook a
 * component calls first: the writes' Fetchers send what they hold queued as
 * they are removed, and the flush runs before any of them is. Leaving the page
 * removes nothing, so the pagehide flush posts the writes itself.
 */
describe( 'useDeferredWrite — on the wire', () => {
	let wire;

	beforeEach( () => {
		jest.useRealTimers();
		holdRouterCadence();
		window.NewspackNodesData = pageData();
		wire = intelWire();
	} );

	afterEach( () => {
		delete document.visibilityState;
	} );

	it( 'sends a pending write on unmount though its write hook is called first', async () => {
		const { result, unmount } = renderHook( () => ( {
			reject: useIntelWrite( 'reject_link' ),
			deferred: useDeferredWrite(),
		} ) );
		await waitFor( () => expect( hasSession() ).toBe( true ) );
		act( () =>
			result.current.deferred.defer(
				PRAIRIE,
				() => result.current.reject.run( { item: 'kestrel-4410' } ),
				'Rejected Prairie Signal.'
			)
		);

		unmount();
		await act( async () => {} );

		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[ '--item=kestrel-4410' ],
		] );
	} );
	// @longform The page reads visible, as it still does when `pagehide` fires,
	// so the keepalive can come only from the pagehide itself; the stopped
	// Timer is a background tab's, which a tick would never fire.
	it( 'posts a pending write with keepalive on pagehide, its Timer stopped', async () => {
		const { result } = renderHook( () => ( {
			reject: useIntelWrite( 'reject_link' ),
			deferred: useDeferredWrite(),
		} ) );
		await waitFor( () => expect( hasSession() ).toBe( true ) );
		Object.defineProperty( document, 'visibilityState', {
			configurable: true,
			get: () => 'visible',
		} );
		Core.node( 'intelligence:reject_link:timer' ).stopTimer();
		act( () =>
			result.current.deferred.defer(
				PRAIRIE,
				() => result.current.reject.run( { item: 'kakariki-5530' } ),
				'Rejected Prairie Signal.'
			)
		);

		act( () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );

		const posts = global.fetch.mock.calls.filter( ( [ url ] ) =>
			String( url ).endsWith( '/command' )
		);
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[ '--item=kakariki-5530' ],
		] );
		expect( posts.at( -1 )[ 1 ].keepalive ).toBe( true );
	} );
} );

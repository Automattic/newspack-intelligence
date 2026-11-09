/* eslint-env jest */
/**
 * useDeferredWrite holds each write for the undo window: its key hides at
 * once, its message is announced, and its send waits UNDO_MS. Undo cancels the
 * most recent write still pending; leaving the page or unmounting sends every
 * pending write at once, each exactly once.
 */

import { renderHook, act } from '@testing-library/react';
import { speak } from '@wordpress/a11y';
import { Core, reservedNames } from '@newspack-nodes/runtime';
import { holdRouterCadence } from '@newspack-nodes/shared/test-utils/holdRouterCadence';
import { UNDO_MS, useDeferredWrite } from '../useDeferredWrite';

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
		const router = holdRouterCadence();
		const fireCb = jest.spyOn( router, 'fireCb' );
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
		expect( fireCb ).toHaveBeenCalledTimes( 1 );
		expect( result.current.hidden ).toEqual( new Set() );
		act( () => jest.advanceTimersByTime( UNDO_MS * 2 ) );
		expect( second ).toHaveBeenCalledTimes( 1 );
		expect( third ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'raises every failing send together, after the tick', () => {
		const router = holdRouterCadence();
		const fireCb = jest.spyOn( router, 'fireCb' );
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
		expect( fireCb ).toHaveBeenCalledTimes( 1 );
	} );

	it( "keeps the send failures when the Router's tick throws too", () => {
		const router = holdRouterCadence();
		const ticked = new Error( 'tick failed: _http torn down' );
		jest.spyOn( router, 'fireCb' ).mockImplementationOnce( () => {
			throw ticked;
		} );
		const sent = new Error( 'collect refused: quarry-notes' );
		const { result } = renderHook( () => useDeferredWrite() );
		act( () =>
			result.current.defer(
				PRAIRIE,
				() => {
					throw sent;
				},
				'Rejected Prairie Signal.'
			)
		);
		let raised = null;
		act( () => {
			try {
				result.current.flush();
			} catch ( error ) {
				raised = error;
			}
		} );
		expect( raised ).toBeInstanceOf( AggregateError );
		expect( raised.errors ).toEqual( [ sent, ticked ] );
	} );

	it( "runs the Router's tick at once when a flush sends, so a parked ask leaves", () => {
		const router = holdRouterCadence();
		const fireCb = jest.spyOn( router, 'fireCb' );
		expect( Core.node( reservedNames.ROUTER ) ).toBe( router );
		const { result } = renderHook( () => useDeferredWrite() );
		act( () => result.current.flush() );
		expect( fireCb ).not.toHaveBeenCalled();
		const send = jest.fn( () => expect( fireCb ).not.toHaveBeenCalled() );
		act( () =>
			result.current.defer( PRAIRIE, send, 'Rejected Prairie Signal.' )
		);
		act( () => result.current.flush() );
		expect( send ).toHaveBeenCalledTimes( 1 );
		expect( fireCb ).toHaveBeenCalledTimes( 1 );
	} );
} );

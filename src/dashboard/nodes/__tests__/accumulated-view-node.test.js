/**
 * accumulated:view tests — the thin view node that owns the accumulated slice
 * ({ accumulated, done, total, digest }). It parses an `accumulated` reply and
 * setStates it for <AccumulatedPanel/>; it never touches the counts or top slices.
 *
 * The awaited `generate` / `collect` verbs are minted from their own nodes, so
 * their acks are addressed there and never reach this one.
 */

import {
	VALUE,
	TYPE,
	ID,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	newMessage,
	Core,
} from '@newspack-nodes/runtime';
import { AccumulatedViewNode } from '../accumulated-view-node';

beforeEach( () => Core.reset() );

function makeView() {
	const node = new AccumulatedViewNode();
	node.name = 'accumulated:view';
	return node;
}

function accReply( payload, id = '' ) {
	const m = newMessage();
	m[ TYPE ] = TM_COMMAND | TM_RESPONSE;
	m[ ID ] = id;
	m[ VALUE ] = { name: 'accumulated', payload };
	return m;
}

const SLICE = { accumulated: 12, done: 2, total: 3, digest: '# Digest' };

describe( 'accumulated:view — slice publish', () => {
	test( 'starts with an empty accumulated slice', () => {
		const v = makeView();
		expect( v.setStateCache.view ).toEqual( {
			accumulated: 0,
			done: 0,
			total: 0,
			digest: '',
		} );
	} );

	test( 'parses an accumulated reply into the slice and publishes it', () => {
		const v = makeView();
		v.fill( accReply( JSON.stringify( SLICE ) ) );
		expect( v.setStateCache.view ).toEqual( SLICE );
	} );

	test( 'a later reply replaces the published slice', () => {
		const v = makeView();
		v.fill( accReply( JSON.stringify( SLICE ) ) );
		v.fill(
			accReply(
				JSON.stringify( {
					accumulated: 8,
					done: 3,
					total: 3,
					digest: '# New',
				} )
			)
		);
		expect( v.setStateCache.view.accumulated ).toBe( 8 );
		expect( v.setStateCache.view.digest ).toBe( '# New' );
	} );

	test( 'surfaces a TM_ERROR reply as an error in the slice', () => {
		const v = makeView();
		const m = accReply( 'acc read failed' );
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE | TM_ERROR;
		v.fill( m );
		expect( v.setStateCache.view.error ).toMatch( /acc read failed/ );
	} );

	test( 'ignores an unparseable payload (keeps the prior slice)', () => {
		const v = makeView();
		v.fill( accReply( JSON.stringify( SLICE ) ) );
		v.fill( accReply( 'not json' ) );
		expect( v.setStateCache.view ).toEqual( SLICE );
	} );
} );

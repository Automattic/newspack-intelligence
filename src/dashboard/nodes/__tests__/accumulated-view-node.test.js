/**
 * accumulated:view tests — the thin view node that owns the accumulated slice
 * ({ accumulated, done, total, digest }). It parses an `accumulated` reply and
 * setStates it for <AccumulatedPanel/>; it never touches the counts or top slices.
 *
 * Unlike its sibling slice views, this one also owns a PendingReplies registry for
 * the awaited `generate`/`collect` action verbs: a reply matching a stashed
 * message[ID] settles that Promise FIRST and returns; teardown rejects any
 * in-flight awaited reply so a graph reinit can't strand a caller.
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
import { PendingReplies } from '@newspack-nodes/shared/pendingReplies';
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

describe( 'accumulated:view — awaited action verbs', () => {
	test( 'exposes a PendingReplies registry', () => {
		const v = makeView();
		expect( v.replies ).toBeInstanceOf( PendingReplies );
	} );

	test( 'settles a pending Promise on a reply matching message[ID]', async () => {
		const v = makeView();
		const promise = new Promise( ( resolve, reject ) => {
			v.replies.add( 'op-1', resolve, reject );
		} );
		v.fill( accReply( JSON.stringify( { collecting: 3 } ), 'op-1' ) );
		await expect( promise ).resolves.toBe(
			JSON.stringify( { collecting: 3 } )
		);
		expect( v.replies.has( 'op-1' ) ).toBe( false );
	} );

	test( 'rejects a pending Promise on a TM_ERROR reply matching message[ID]', async () => {
		const v = makeView();
		const promise = new Promise( ( resolve, reject ) => {
			v.replies.add( 'op-2', resolve, reject );
		} );
		const m = accReply( 'no live worker', 'op-2' );
		m[ TYPE ] = TM_COMMAND | TM_RESPONSE | TM_ERROR;
		v.fill( m );
		await expect( promise ).rejects.toThrow( /no live worker/i );
	} );

	test( 'a reply whose ID matches no pending entry still publishes the slice', () => {
		const v = makeView();
		v.replies.add(
			'stashed',
			() => {},
			() => {}
		);
		v.fill( accReply( JSON.stringify( SLICE ), 'unrelated' ) );
		expect( v.replies.has( 'stashed' ) ).toBe( true );
		expect( v.setStateCache.view ).toEqual( SLICE );
	} );

	test( 'removeNode rejects in-flight awaited replies (no stranded Promise)', async () => {
		const v = makeView();
		const promise = new Promise( ( resolve, reject ) => {
			v.replies.add( 'gen-1', resolve, reject );
		} );
		v.removeNode();
		await expect( promise ).rejects.toThrow( /torn down/i );
		expect( v.replies.size ).toBe( 0 );
	} );
} );

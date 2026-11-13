/* eslint-env jest */
/**
 * useIntelRead and useIntelWrite are the substrate's command hooks bound to
 * the Intelligence page: every send passes the `intelligence` group's Tap,
 * and goes to the `intelligence` CI unless a write names another.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { useIntelRead, useIntelWrite } from '../useIntelCommand';
import { pageData } from '../../views/__tests__/fixtures';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
} );

function Reader() {
	const { answer } = useIntelRead( 'probe:entity', 'entity', [
		'organization:acme-quillsheet',
	] );
	return <p>{ answer ? `answer: ${ answer.name }` : 'loading' }</p>;
}

function Writer( { ci, ...opts } ) {
	const { run } = useIntelWrite( 'accept_entity', { ci, ...opts } );
	return (
		<button
			type="button"
			className="button"
			onClick={ () => run( { key: 'organization:acme-quillsheet' } ) }
		>
			accept
		</button>
	);
}

describe( 'useIntelRead', () => {
	it( 'reads through the intelligence group from the intelligence CI', async () => {
		wire.replies.entity = { name: 'Acme Quillsheet', available: true };
		render( <Reader /> );
		expect(
			await screen.findByText( 'answer: Acme Quillsheet' )
		).toBeInTheDocument();
		expect( Core.node( 'probe:entity:fetch' ).target ).toBe(
			'intelligence:shell/_http/intelligence'
		);
	} );
} );

describe( 'useIntelWrite', () => {
	it( 'writes through the intelligence group to the intelligence CI', async () => {
		wire.replies.accept_entity = { ok: true };
		render( <Writer /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () =>
			expect( wire.sentTo( 'accept_entity' ) ).toEqual( [
				'intelligence',
			] )
		);
		expect( Core.node( 'intelligence:accept_entity:fetch' ).target ).toBe(
			'intelligence:shell/_http/intelligence'
		);
	} );

	it( 'writes to the CI it names, still through the intelligence group', async () => {
		wire.replies.accept_entity = { ok: true };
		render( <Writer ci="workers" /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () =>
			expect( wire.sentTo( 'accept_entity' ) ).toEqual( [ 'workers' ] )
		);
		expect( Core.node( 'workers:accept_entity:fetch' ).target ).toBe(
			'intelligence:shell/_http/workers'
		);
	} );

	it( 'keeps the intelligence group whatever group its options name', async () => {
		wire.replies.accept_entity = { ok: true };
		render( <Writer group="kea-7813" /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () =>
			expect( wire.sent( 'accept_entity' ) ).toHaveLength( 1 )
		);
		expect( Core.node( 'intelligence:accept_entity:fetch' ).target ).toBe(
			'intelligence:shell/_http/intelligence'
		);
	} );

	it( 'refuses an option the write does not take, naming it', () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		expect( () => render( <Writer dirtys={ [ 'entity:' ] } /> ) ).toThrow(
			/dirtys/
		);
	} );
} );

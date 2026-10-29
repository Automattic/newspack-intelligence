/* eslint-env jest */
/**
 * useIntelRead sends its verb whenever its arguments change and shows only
 * the answer to the question it is asking now; useIntelWrite sends once per
 * run and hands each reply to its caller.
 */

import {
	render,
	screen,
	fireEvent,
	act,
	waitFor,
} from '@testing-library/react';
import {
	Core,
	newMessage,
	TYPE,
	TO,
	VALUE,
	TM_ERROR,
} from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { commandArgs, useIntelRead, useIntelWrite } from '../useIntelCommand';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
} );

function Reader( { args } ) {
	const { answer, error, loading } = useIntelRead(
		'probe:entity',
		'entity',
		args
	);
	if ( error ) {
		return <p>refused: { error }</p>;
	}
	if ( loading ) {
		return <p>loading</p>;
	}
	return <p>{ answer ? `answer: ${ answer.name }` : 'nothing asked' }</p>;
}

function Writer( { refresh, onRefused, onSent = () => {} } ) {
	const { run, refusal } = useIntelWrite( 'accept_entity', {
		refresh,
		onRefused,
	} );
	return (
		<>
			<button
				type="button"
				className="button"
				onClick={ () =>
					onSent( run( { key: 'organization:acme-gazette' } ) )
				}
			>
				accept
			</button>
			{ refusal && <p>refusal: { refusal }</p> }
		</>
	);
}

describe( 'useIntelRead', () => {
	it( 'sends its verb with its arguments and shows the answer', async () => {
		wire.replies.entity = ( [ key ] ) => ( {
			name: `named ${ key }`,
			available: true,
		} );
		render( <Reader args={ [ 'organization:acme-gazette' ] } /> );
		expect(
			await screen.findByText( 'answer: named organization:acme-gazette' )
		).toBeInTheDocument();
		expect( wire.sent( 'entity' ) ).toEqual( [
			[ 'organization:acme-gazette' ],
		] );
	} );

	it( 'asks again when its arguments change, showing the new answer', async () => {
		wire.replies.entity = ( [ key ] ) => ( { name: key, available: true } );
		const { rerender } = render(
			<Reader args={ [ 'organization:acme-gazette' ] } />
		);
		await screen.findByText( 'answer: organization:acme-gazette' );
		rerender( <Reader args={ [ 'person:jo-reporter' ] } /> );
		expect(
			await screen.findByText( 'answer: person:jo-reporter' )
		).toBeInTheDocument();
	} );

	it( 'shows a refusal as the refusal', async () => {
		wire.replies.entity = () =>
			new Error( 'no entity: organization:nowhere' );
		render( <Reader args={ [ 'organization:nowhere' ] } /> );
		expect(
			await screen.findByText(
				/refused: no entity: organization:nowhere/
			)
		).toBeInTheDocument();
	} );

	it( 'shows a refusal that echoes no arguments rather than loading forever', async () => {
		render( <Reader args={ [ 'organization:acme-gazette' ] } /> );
		await waitFor( () =>
			expect( wire.sent( 'entity' ) ).toHaveLength( 1 )
		);
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'probe:entity:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		expect(
			await screen.findByText( /refused: NOT_AVAILABLE/ )
		).toBeInTheDocument();
	} );
} );

describe( 'useIntelWrite', () => {
	it( 'sends its verb once per run and refreshes once it lands', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-41' };
		const refresh = jest.fn();
		render( <Writer refresh={ refresh } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalledTimes( 1 ) );
		expect( wire.sent( 'accept_entity' ) ).toEqual( [
			[ '--key=organization:acme-gazette' ],
		] );
	} );

	it( 'holds a refusal until a later send lands', async () => {
		let refuse = true;
		wire.replies.accept_entity = () =>
			refuse
				? new Error( 'no entity: organization:acme-gazette' )
				: { ok: true, event_id: 'ev-42' };
		const refresh = jest.fn();
		render( <Writer refresh={ refresh } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		expect(
			await screen.findByText(
				'refusal: no entity: organization:acme-gazette'
			)
		).toBeInTheDocument();
		expect( refresh ).not.toHaveBeenCalled();
		refuse = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalled() );
		expect( screen.queryByText( /refusal:/ ) ).toBeNull();
	} );

	it( 'answers each run with the tokens it sent, and a refusal with the tokens refused', async () => {
		wire.replies.accept_entity = () =>
			new Error( 'no entity: organization:acme-gazette' );
		const onSent = jest.fn();
		const onRefused = jest.fn();
		render( <Writer onSent={ onSent } onRefused={ onRefused } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		expect( onSent ).toHaveBeenCalledWith( [
			'--key=organization:acme-gazette',
		] );
		await waitFor( () =>
			expect( onRefused ).toHaveBeenCalledWith( [
				'--key=organization:acme-gazette',
			] )
		);
	} );
} );

describe( 'commandArgs', () => {
	it( 'names each value given, repeating a list once per member', () => {
		expect(
			commandArgs( {
				key: 'support-desk',
				kind: [ 'complaint', 'praise' ],
				days: 21,
				offset: 0,
			} )
		).toEqual( [
			'--key=support-desk',
			'--kind=complaint',
			'--kind=praise',
			'--days=21',
			'--offset=0',
		] );
	} );

	it( 'leaves out a blank value and an empty list', () => {
		expect(
			commandArgs( {
				q: '',
				topic: [],
				source: [ 'linear', '' ],
			} )
		).toEqual( [ '--source=linear' ] );
	} );
} );

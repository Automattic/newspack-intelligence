/* eslint-env jest */
/**
 * useIntelRead sends its command whenever its arguments change and shows only
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
import { pageData } from '../../views/__tests__/fixtures';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
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

function Writer( { refresh, onRefused, onSent = () => {}, ci } ) {
	const { run, refusal, pending } = useIntelWrite( 'accept_entity', {
		ci,
		refresh,
		onRefused,
	} );
	return (
		<>
			{ pending && <p>pending</p> }
			<button
				type="button"
				className="button"
				onClick={ () =>
					onSent( run( { key: 'organization:acme-quillsheet' } ) )
				}
			>
				accept
			</button>
			{ refusal && <p>refusal: { refusal }</p> }
		</>
	);
}

function Dirtier( { command, dirties, refresh } ) {
	const { run } = useIntelWrite( command, {
		scope: `probe:${ command }`,
		dirties,
		refresh,
	} );
	return (
		<button
			type="button"
			className="button"
			onClick={ () => run( { item: 'harbor-ledger:memo' } ) }
		>
			{ command }
		</button>
	);
}

describe( 'useIntelRead', () => {
	it( 'sends its verb with its arguments and shows the answer', async () => {
		wire.replies.entity = ( [ key ] ) => ( {
			name: `named ${ key }`,
			available: true,
		} );
		render( <Reader args={ [ 'organization:acme-quillsheet' ] } /> );
		expect(
			await screen.findByText(
				'answer: named organization:acme-quillsheet'
			)
		).toBeInTheDocument();
		expect( wire.sent( 'entity' ) ).toEqual( [
			[ 'organization:acme-quillsheet' ],
		] );
	} );

	it( 'asks again when its arguments change, showing the new answer', async () => {
		wire.replies.entity = ( [ key ] ) => ( { name: key, available: true } );
		const { rerender } = render(
			<Reader args={ [ 'organization:acme-quillsheet' ] } />
		);
		await screen.findByText( 'answer: organization:acme-quillsheet' );
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
		render( <Reader args={ [ 'organization:acme-quillsheet' ] } /> );
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

/**
 * A read whose rows say which items it holds, and whether it is stale.
 *
 * @param {Object}   props
 * @param {string}   props.scope   The read's scope.
 * @param {string}   props.command The read verb.
 * @param {string[]} props.args    Its tokens.
 * @return {import('react').ReactElement} The read's state.
 */
function Lister( { scope, command, args } ) {
	const { answer, error, loading, stale } = useIntelRead(
		scope,
		command,
		args
	);
	// Every field it is handed renders, so a refusal beside rows would show.
	return (
		<>
			{ error && <p>refused: { error }</p> }
			{ loading && <p>loading</p> }
			{ answer && (
				<>
					<p>{ stale ? 'stale' : 'fresh' }</p>
					<ul>
						{ answer.items.map( ( title ) => (
							<li key={ title }>{ title }</li>
						) ) }
					</ul>
				</>
			) }
		</>
	);
}

/** A reply the suite holds open until it answers it. */
function held() {
	let answer;
	const reply = new Promise( ( resolve ) => ( answer = resolve ) );
	return { reply: () => reply, answer: ( value ) => answer( value ) };
}

describe( 'useIntelRead from memory', () => {
	it( 'a cached read paints at once and revalidates', async () => {
		wire.replies.lighthouse = { items: [ 'Tide table for Harbor Ledger' ] };
		const first = render(
			<Lister
				scope="probe:tide"
				command="lighthouse"
				args={ [ 'tide' ] }
			/>
		);
		await screen.findByText( 'fresh' );
		first.unmount();

		const later = held();
		wire.replies.lighthouse = later.reply;
		render(
			<Lister
				scope="probe:tide"
				command="lighthouse"
				args={ [ 'tide' ] }
			/>
		);
		expect( screen.getByText( 'stale' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Tide table for Harbor Ledger' )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'lighthouse' ) ).toHaveLength( 2 )
		);
		await act( async () =>
			later.answer( { items: [ 'Spring tide for Harbor Ledger' ] } )
		);
		expect( await screen.findByText( 'fresh' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Spring tide for Harbor Ledger' )
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Tide table for Harbor Ledger' )
		).toBeNull();
	} );

	it( 'the 65th answer evicts the least recently used', async () => {
		wire.replies.buoy = ( [ n ] ) => ( { items: [ `buoy ${ n }` ] } );
		const view = render(
			<Lister scope="probe:buoy" command="buoy" args={ [ '1' ] } />
		);
		await screen.findByText( 'buoy 1' );
		for ( let n = 2; n <= 65; n++ ) {
			view.rerender(
				<Lister
					scope="probe:buoy"
					command="buoy"
					args={ [ `${ n }` ] }
				/>
			);
			await screen.findByText( `buoy ${ n }` );
		}
		view.unmount();

		wire.replies.buoy = held().reply;
		const second = render(
			<Lister scope="probe:buoy" command="buoy" args={ [ '2' ] } />
		);
		expect( screen.getByText( 'stale' ) ).toBeInTheDocument();
		second.unmount();
		render( <Lister scope="probe:buoy" command="buoy" args={ [ '1' ] } /> );
		expect( screen.getByText( 'loading' ) ).toBeInTheDocument();
	} );

	it( 'nothing the cache holds reaches storage', async () => {
		const stored = jest.spyOn( window.Storage.prototype, 'setItem' );
		wire.replies.ledger = { items: [ 'Harbor Ledger restricted memo' ] };
		wire.replies.collect = { ok: true };
		const read = render(
			<Lister scope="probe:ledger" command="ledger" args={ [ 'memo' ] } />
		);
		await screen.findByText( 'Harbor Ledger restricted memo' );
		read.unmount();
		render(
			<Lister scope="probe:ledger" command="ledger" args={ [ 'memo' ] } />
		);
		const refresh = jest.fn();
		render(
			<Dirtier
				command="collect"
				dirties={ [ 'ledger:' ] }
				refresh={ refresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'collect' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalledTimes( 1 ) );
		expect( stored ).not.toHaveBeenCalled();
		stored.mockRestore();
	} );

	it( 'a landed write drops the prefixes it dirties', async () => {
		wire.replies.collections = { items: [ 'Harbor Ledger folder' ] };
		wire.replies.briefing = { items: [ 'Harbor Ledger brief' ] };
		wire.replies.collect = { ok: true, event_id: 'ev-77' };
		const folders = render(
			<Lister scope="probe:folders" command="collections" args={ [] } />
		);
		await screen.findByText( 'Harbor Ledger folder' );
		folders.unmount();
		const brief = render(
			<Lister
				scope="probe:brief"
				command="briefing"
				args={ [ 'signals' ] }
			/>
		);
		await screen.findByText( 'Harbor Ledger brief' );
		brief.unmount();

		const refresh = jest.fn();
		const writer = render(
			<Dirtier
				command="collect"
				dirties={ [ 'collections:' ] }
				refresh={ refresh }
			/>
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'collect' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalledTimes( 1 ) );
		writer.unmount();

		wire.replies.collections = held().reply;
		wire.replies.briefing = held().reply;
		const again = render(
			<Lister scope="probe:folders" command="collections" args={ [] } />
		);
		expect( screen.getByText( 'loading' ) ).toBeInTheDocument();
		again.unmount();
		render(
			<Lister
				scope="probe:brief"
				command="briefing"
				args={ [ 'signals' ] }
			/>
		);
		expect( screen.getByText( 'stale' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Harbor Ledger brief' ) ).toBeInTheDocument();
	} );

	it( 'a landed write re-reads a mounted read it dirties', async () => {
		let folder = 'Harbor Ledger folder';
		wire.replies.collections = () => ( { items: [ folder ] } );
		wire.replies.collect = { ok: true, event_id: 'ev-78' };
		render(
			<Lister scope="probe:shelf" command="collections" args={ [] } />
		);
		await screen.findByText( 'Harbor Ledger folder' );
		folder = 'Harbor Ledger folder, two items';
		render( <Dirtier command="collect" dirties={ [ 'collections:' ] } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'collect' } ) );
		expect(
			await screen.findByText( 'Harbor Ledger folder, two items' )
		).toBeInTheDocument();
		expect( wire.sent( 'collections' ) ).toHaveLength( 2 );
	} );

	it( 'a revoked grant never shows a stale restricted item', async () => {
		const restricted = 'Harbor Ledger restricted board memo';
		const open = 'Harbor Ledger open release note';
		wire.replies.tiers = { items: [ open, restricted ] };
		const first = render(
			<Lister scope="probe:tiers" command="tiers" args={ [ 'week' ] } />
		);
		await screen.findByText( restricted );
		first.unmount();

		// The grant is gone: the revalidation answers without the item.
		wire.replies.tiers = { items: [ open ] };
		const narrowed = render(
			<Lister scope="probe:tiers" command="tiers" args={ [ 'week' ] } />
		);
		await screen.findByText( 'fresh' );
		expect( screen.queryByText( restricted ) ).toBeNull();
		narrowed.unmount();

		// Seed the restricted answer again, then refuse its revalidation.
		wire.replies.tiers = { items: [ open, restricted ] };
		const seeded = render(
			<Lister scope="probe:tiers" command="tiers" args={ [ 'month' ] } />
		);
		await screen.findByText( restricted );
		seeded.unmount();
		wire.replies.tiers = () => new Error( 'not permitted: restricted' );
		const refused = render(
			<Lister scope="probe:tiers" command="tiers" args={ [ 'month' ] } />
		);
		expect(
			await screen.findByText( 'refused: not permitted: restricted' )
		).toBeInTheDocument();
		expect( screen.queryByText( restricted ) ).toBeNull();
		expect( screen.queryByText( open ) ).toBeNull();
		refused.unmount();

		// The refusal evicted the entry: nothing paints from memory.
		wire.replies.tiers = held().reply;
		render(
			<Lister scope="probe:tiers" command="tiers" args={ [ 'month' ] } />
		);
		expect( screen.getByText( 'loading' ) ).toBeInTheDocument();
		expect( screen.queryByText( restricted ) ).toBeNull();
	} );
} );

describe( 'useIntelRead across scopes', () => {
	it( 'paints a question one scope answered under another, marked stale', async () => {
		wire.replies.mooring = { items: [ 'Harbor Ledger berth 7' ] };
		const first = render(
			<Lister
				scope="probe:head"
				command="mooring"
				args={ [ 'harbor-ledger' ] }
			/>
		);
		await screen.findByText( 'fresh' );
		first.unmount();

		wire.replies.mooring = held().reply;
		render(
			<Lister
				scope="probe:block"
				command="mooring"
				args={ [ 'harbor-ledger' ] }
			/>
		);
		expect( screen.getByText( 'stale' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Harbor Ledger berth 7' )
		).toBeInTheDocument();
	} );
} );

describe( 'useIntelWrite', () => {
	it( 'sends its command once per run and refreshes with its answer once it lands', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-41' };
		const refresh = jest.fn();
		render( <Writer refresh={ refresh } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalledTimes( 1 ) );
		expect( refresh ).toHaveBeenCalledWith( {
			ok: true,
			event_id: 'ev-41',
		} );
		expect( wire.sent( 'accept_entity' ) ).toEqual( [
			[ '--key=organization:acme-quillsheet' ],
		] );
	} );

	it( 'holds a refusal until a later send lands', async () => {
		let refuse = true;
		wire.replies.accept_entity = () =>
			refuse
				? new Error( 'no entity: organization:acme-quillsheet' )
				: { ok: true, event_id: 'ev-42' };
		const refresh = jest.fn();
		render( <Writer refresh={ refresh } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		expect(
			await screen.findByText(
				'refusal: no entity: organization:acme-quillsheet'
			)
		).toBeInTheDocument();
		expect( refresh ).not.toHaveBeenCalled();
		refuse = false;
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalled() );
		expect( screen.queryByText( /refusal:/ ) ).toBeNull();
	} );

	it( 'answers each run with the tokens it sent, and a refusal with the tokens refused and its own error', async () => {
		wire.replies.accept_entity = () =>
			new Error( 'no entity: organization:acme-quillsheet' );
		const onSent = jest.fn();
		const onRefused = jest.fn();
		render( <Writer onSent={ onSent } onRefused={ onRefused } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		expect( onSent ).toHaveBeenCalledWith( [
			'--key=organization:acme-quillsheet',
		] );
		await waitFor( () =>
			expect( onRefused ).toHaveBeenCalledWith(
				[ '--key=organization:acme-quillsheet' ],
				'no entity: organization:acme-quillsheet'
			)
		);
	} );
} );

describe( 'useIntelWrite on another CI', () => {
	it( 'addresses the CI it names, and is pending until the reply lands', async () => {
		let answer;
		wire.replies.accept_entity = () =>
			new Promise( ( resolve ) => ( answer = resolve ) );
		const refresh = jest.fn();
		render( <Writer ci="workers" refresh={ refresh } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'accept' } ) );
		expect( screen.getByText( 'pending' ) ).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sentTo( 'accept_entity' ) ).toEqual( [ 'workers' ] )
		);
		await act( async () => answer( { ok: true } ) );
		await waitFor( () => expect( refresh ).toHaveBeenCalledTimes( 1 ) );
		expect( screen.queryByText( 'pending' ) ).toBeNull();
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

	it( 'leaves out a value not given, never sending it as "null"', () => {
		expect(
			commandArgs( {
				lens: null,
				entity: undefined,
				by: 'theme',
			} )
		).toEqual( [ '--by=theme' ] );
	} );
} );

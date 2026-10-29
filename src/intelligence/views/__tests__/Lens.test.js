/* eslint-env jest */
/**
 * Lenses lists every lens, opens one onto its items a page at a time beside
 * its trend rows, and offers a curator the form saving a lens, every filter
 * named and each list repeated, and the control removing the one opened.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, LENSES, VOCABULARY } from './fixtures';
import Lens, { CLOSED_LENS } from '../Lens';

const TRENDS = {
	rows: [
		{
			bucket: '2026-09-21',
			value: 'complaint',
			count: 4,
			items: [
				{
					item_key: 'linear:SUP-88',
					url: 'https://linear.example/SUP-88',
					title: 'Paywall login loops for Acme',
				},
				{
					item_key: 'feed:untitled-4',
					url: 'javascript:alert(1)',
					title: '',
				},
			],
		},
	],
	available: true,
};

const SIGNALS = {
	signals: [
		{
			bucket: '2026-09-28',
			by: 'kind',
			value: 'praise',
			count: 11,
			baseline: 1.6666,
			items: [
				{
					item_key: 'linear:SUP-91',
					url: 'https://linear.example/SUP-91',
					title: 'Acme thanks support for the fix',
				},
				{
					item_key: 'feed:untitled-6',
					url: 'data:text/html,hi',
					title: '',
				},
			],
		},
	],
	available: true,
};

let wire;

function Harness( props ) {
	const [ opened, setOpened ] = useState( CLOSED_LENS );
	return (
		<Lens
			vocabulary={ VOCABULARY }
			opened={ opened }
			onOpen={ setOpened }
			onOpenEntity={ jest.fn() }
			onWatch={ jest.fn() }
			{ ...props }
		/>
	);
}

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	wire = intelWire();
	wire.replies.lenses = LENSES;
	wire.replies.search = { items: [ ITEM ], total: 1, available: true };
	wire.replies.trends = TRENDS;
	wire.replies.signals = SIGNALS;
} );

const openSupport = async () =>
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Support desk' } )
	);

describe( 'Lens', () => {
	it( 'lists every lens with its description', async () => {
		render( <Harness canTune={ false } /> );
		expect(
			await screen.findByRole( 'button', { name: 'Support desk' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Developer shipping' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'What clients complain about' )
		).toBeInTheDocument();
		expect( wire.sent( 'lenses' ) ).toEqual( [ [] ] );
	} );

	it( 'says there is no lens yet', async () => {
		wire.replies.lenses = { lenses: [], available: true };
		render( <Harness canTune={ false } /> );
		expect( await screen.findByText( 'No lens yet.' ) ).toBeInTheDocument();
	} );

	it( 'opens a lens onto its items and its trend rows, each citing its items', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		const items = await screen.findByRole( 'region', {
			name: 'Support desk',
		} );
		expect(
			await within( items ).findByRole( 'link', {
				name: 'Acme Gazette wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://pressgazette.example/acme' );
		const trends = screen.getByRole( 'region', { name: 'Trends' } );
		const row = await within( trends ).findByRole( 'row', {
			name: /2026-09-21/,
		} );
		expect( within( row ).getByText( 'complaint' ) ).toBeInTheDocument();
		expect( within( row ).getByText( '4' ) ).toBeInTheDocument();
		expect(
			within( row ).getByRole( 'link', {
				name: 'Paywall login loops for Acme',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-88' );
		expect(
			within( row ).getByText( 'javascript:alert(1)' ).closest( 'a' )
		).toBeNull();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
	} );

	it( 'shows the lens’s signals, each count against its baseline', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const row = await within( signals ).findByRole( 'row', {
			name: /2026-09-28/,
		} );
		expect( within( row ).getByText( 'praise' ) ).toBeInTheDocument();
		expect(
			within( row ).getByText( '11 against a baseline of 1.7' )
		).toBeInTheDocument();
		expect(
			within( row ).getByRole( 'link', {
				name: 'Acme thanks support for the fix',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-91' );
		expect(
			within( row ).getByText( 'data:text/html,hi' ).closest( 'a' )
		).toBeNull();
		expect(
			signals.compareDocumentPosition(
				screen.getByRole( 'region', { name: 'Support desk' } )
			) & window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( wire.sent( 'signals' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
	} );

	it( 'says a lens shows no signal', async () => {
		wire.replies.signals = { signals: [], available: true };
		render( <Harness canTune={ false } /> );
		await openSupport();
		expect(
			await screen.findByText( 'No signal is seen through this lens.' )
		).toBeInTheDocument();
	} );

	it( 'offers every reader a watch on the lens opened', async () => {
		const onWatch = jest.fn();
		render( <Harness canTune={ false } onWatch={ onWatch } /> );
		await screen.findByRole( 'button', { name: 'Support desk' } );
		expect(
			screen.queryByRole( 'button', { name: 'Watch this' } )
		).toBeNull();
		await openSupport();
		await screen.findByText( '11 against a baseline of 1.7' );
		await screen.findByText( 'Paywall login loops for Acme' );
		await screen.findByText( 'Acme Gazette wins regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Watch this' } ) );
		expect( onWatch ).toHaveBeenCalledWith(
			{ kind: 'lens', key: 'support-desk' },
			'Support desk'
		);
	} );

	it( 'says a lens sees no item and no trend', async () => {
		wire.replies.search = { items: [], total: 0, available: true };
		wire.replies.trends = { rows: [], available: true };
		render( <Harness canTune={ false } /> );
		await openSupport();
		expect(
			await screen.findByText(
				'No readable item is seen through this lens.'
			)
		).toBeInTheDocument();
		expect(
			await screen.findByText( 'No trend is seen through this lens.' )
		).toBeInTheDocument();
	} );

	it( 'pages the lens items forward and back', async () => {
		wire.replies.search = ( args ) => ( {
			items: [ 'a', 'b', 'c' ].map( ( n ) => ( {
				...ITEM,
				item_key: `feed:${ n }`,
				title: `${ n } at ${ args.join( ' ' ) }`,
			} ) ),
			total: 70,
			available: true,
		} );
		render( <Harness canTune={ false } /> );
		await openSupport();
		await screen.findByText( 'a at --lens=support-desk' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText( 'a at --lens=support-desk --offset=3' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect(
			await screen.findByText( 'a at --lens=support-desk' )
		).toBeInTheDocument();
	} );

	it( 'shows a refused trend read as the refusal', async () => {
		wire.replies.trends = () =>
			new Error( 'by must be one of: kind, topic' );
		render( <Harness canTune={ false } /> );
		await openSupport();
		expect(
			await screen.findByText( 'by must be one of: kind, topic' )
		).toBeInTheDocument();
	} );

	it( 'offers a reader no curation', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		await screen.findByRole( 'region', { name: 'Trends' } );
		expect(
			screen.queryByRole( 'button', { name: 'New lens' } )
		).toBeNull();
		expect( screen.queryByRole( 'button', { name: /^Edit / } ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: /^Remove / } )
		).toBeNull();
	} );

	it( 'saves a new lens with every field named and each list repeated', async () => {
		wire.replies.save_lens = { ok: true, event_id: 'ev-l1' };
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Support desk' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New lens' } ) );
		const form = screen.getByRole( 'form', { name: 'Save lens' } );
		const type = ( label, value ) =>
			fireEvent.change( within( form ).getByLabelText( label ), {
				target: { value },
			} );
		type( 'Key', 'board-briefing' );
		type( 'Name', 'Board briefing' );
		type( 'Description', 'What the board reads monthly' );
		type( 'Kinds', 'funding, launch' );
		type( 'Topics', 'topic:local-news,topic:ai' );
		type( 'Roles', 'funder' );
		type( 'Sources', 'feed, github' );
		type( 'Entity', 'organization:acme-gazette' );
		type( 'Days', '45' );
		fireEvent.click( within( form ).getByLabelText( 'restricted' ) );
		fireEvent.click( within( form ).getByLabelText( 'public' ) );
		type( 'Count by', 'topic' );
		type( 'Bucket', 'month' );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save lens' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_lens' ) ).toEqual( [
				[
					'--key=board-briefing',
					'--name=Board briefing',
					'--description=What the board reads monthly',
					'--kind=funding',
					'--kind=launch',
					'--topic=topic:local-news',
					'--topic=topic:ai',
					'--role=funder',
					'--source=feed',
					'--source=github',
					'--tier=public',
					'--tier=restricted',
					'--entity=organization:acme-gazette',
					'--days=45',
					'--by=topic',
					'--bucket=month',
				],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 2 )
		);
		expect(
			screen.queryByRole( 'form', { name: 'Save lens' } )
		).toBeNull();
	} );

	it( 'edits the lens opened from its own filters, leaving out what it unsets', async () => {
		wire.replies.save_lens = { ok: true, event_id: 'ev-l2' };
		render( <Harness canTune={ true } /> );
		await openSupport();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit Support desk' } )
		);
		const form = screen.getByRole( 'form', { name: 'Save lens' } );
		expect( within( form ).getByLabelText( 'Kinds' ) ).toHaveValue(
			'complaint, praise'
		);
		expect( within( form ).getByLabelText( 'Days' ) ).toHaveValue( 21 );
		expect( within( form ).getByLabelText( 'Count by' ) ).toHaveValue(
			'kind'
		);
		expect( within( form ).getByLabelText( 'internal' ) ).toBeChecked();
		expect( within( form ).getByLabelText( 'public' ) ).not.toBeChecked();
		fireEvent.change( within( form ).getByLabelText( 'Roles' ), {
			target: { value: 'client, former_client' },
		} );
		fireEvent.click( within( form ).getByLabelText( 'internal' ) );
		fireEvent.change( within( form ).getByLabelText( 'Bucket' ), {
			target: { value: '' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save lens' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_lens' ) ).toEqual( [
				[
					'--key=support-desk',
					'--name=Support desk',
					'--description=What clients complain about',
					'--kind=complaint',
					'--kind=praise',
					'--role=client',
					'--role=former_client',
					'--entity=organization:acme-gazette',
					'--days=21',
					'--by=kind',
				],
			] )
		);
	} );

	it( 'shows a saved lens before a read lists it', async () => {
		wire.replies.save_lens = { ok: true, event_id: 'ev-l4' };
		render( <Harness canTune={ true } /> );
		await openSupport();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit Support desk' } )
		);
		const form = screen.getByRole( 'form', { name: 'Save lens' } );
		fireEvent.change( within( form ).getByLabelText( 'Name' ), {
			target: { value: 'Support triage' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save lens' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 2 )
		);
		expect(
			screen.getByRole( 'button', { name: 'Support triage' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Support desk' } )
		).toBeNull();
		expect(
			screen.getByRole( 'region', { name: 'Support triage' } )
		).toBeInTheDocument();
	} );

	it( 'puts a lens back when its save is refused', async () => {
		wire.replies.save_lens = () => new Error( 'no entity: topic:nowhere' );
		render( <Harness canTune={ true } /> );
		await openSupport();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit Support desk' } )
		);
		const form = screen.getByRole( 'form', { name: 'Save lens' } );
		fireEvent.change( within( form ).getByLabelText( 'Name' ), {
			target: { value: 'Support triage' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save lens' } )
		);
		expect(
			await screen.findByText( 'no entity: topic:nowhere' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Support desk' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Support triage' } )
		).toBeNull();
	} );

	it( 'cancels an edit without sending it', async () => {
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Support desk' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New lens' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect(
			screen.queryByRole( 'form', { name: 'Save lens' } )
		).toBeNull();
		expect( wire.sent( 'save_lens' ) ).toEqual( [] );
	} );

	it( 'shows a refused save as the refusal and keeps the form', async () => {
		wire.replies.save_lens = () =>
			new Error( 'kind must be one of: award, launch, funding' );
		render( <Harness canTune={ true } /> );
		await screen.findByRole( 'button', { name: 'Support desk' } );
		fireEvent.click( screen.getByRole( 'button', { name: 'New lens' } ) );
		const form = screen.getByRole( 'form', { name: 'Save lens' } );
		fireEvent.change( within( form ).getByLabelText( 'Key' ), {
			target: { value: 'odd-kinds' },
		} );
		fireEvent.click(
			within( form ).getByRole( 'button', { name: 'Save lens' } )
		);
		expect(
			await screen.findByText(
				'kind must be one of: award, launch, funding'
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'form', { name: 'Save lens' } )
		).toBeInTheDocument();
	} );

	it( 'removes the lens opened, closes it and lists again', async () => {
		wire.replies.remove_lens = { ok: true, event_id: 'ev-l3' };
		render( <Harness canTune={ true } /> );
		await openSupport();
		await screen.findByRole( 'region', { name: 'Trends' } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Support desk' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'remove_lens' ) ).toEqual( [
				[ '--key=support-desk' ],
			] )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'region', { name: 'Trends' } )
			).toBeNull()
		);
		expect( wire.sent( 'lenses' ) ).toHaveLength( 2 );
		expect(
			screen.queryByRole( 'button', { name: 'Support desk' } )
		).toBeNull();
	} );

	it( 'puts a lens back when its removal is refused', async () => {
		wire.replies.remove_lens = () => new Error( 'no lens: support-desk' );
		render( <Harness canTune={ true } /> );
		await openSupport();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove Support desk' } )
		);
		expect(
			await screen.findByText( 'no lens: support-desk' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'region', { name: 'Trends' } )
		).toBeInTheDocument();
	} );
} );

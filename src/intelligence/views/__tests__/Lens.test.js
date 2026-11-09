/* eslint-env jest */
/**
 * Lenses lists every lens, opens one onto its signals, its trends as one
 * compact chart, and its ranked theme groups a page of groups at a time, and
 * offers a curator the form saving a lens, every filter named and each list
 * repeated, and the control removing the one opened.
 */

import {
	cleanup,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { intelWire } from '../../__tests__/intelWire';
import { GROUPS, LENSES, VOCABULARY } from './fixtures';
import Lens, { CLOSED_LENS } from '../Lens';

/**
 * The line bars standing in for a card's prose while its read is owed.
 *
 * @param {HTMLElement} card The card.
 * @return {number} How many.
 */
const owedLines = ( card ) =>
	card.querySelectorAll(
		'[role=status] .newspack-intelligence__skeleton-bar.is-line'
	).length;

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

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

const TREND = [
	{ bucket: '2026-09-28', count: 11, in_window: true },
	{ bucket: '2026-09-21', count: 4, in_window: true },
	{ bucket: '2026-09-14', count: 0, in_window: true },
	{ bucket: '2026-09-07', count: 2, in_window: true },
	{ bucket: '2026-08-31', count: 1, in_window: true },
	{ bucket: '2026-08-24', count: 0, in_window: true },
	{ bucket: '2026-08-17', count: 3, in_window: true },
	{ bucket: '2026-08-10', count: 1, in_window: true },
];

const SIGNALS = {
	trend: TREND,
	partial: { bucket: '2026-10-05', count: 6 },
	judged: true,
	bucket: 'week',
	baseline_buckets: 8,
	signals: [
		{
			axis: 'about',
			signal: 'spike',
			bucket: '2026-09-28',
			by: 'kind',
			value: 'praise',
			count: 11,
			usual: { mean: 1.6666, low: 0, high: 4.2 },
			trend: TREND,
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
	wire.replies.search = GROUPS;
	wire.replies.trends = TRENDS;
	wire.replies.signals = SIGNALS;
} );

const openSupport = async () =>
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Support desk' } )
	);

describe( 'Lens', () => {
	it( 'stands a line in for its signals while they are owed', async () => {
		wire.replies.signals = owed;
		render( <Harness canTune={ false } /> );
		await openSupport();
		await waitFor( () =>
			expect(
				owedLines( screen.getByRole( 'region', { name: 'Signals' } ) )
			).toBe( 1 )
		);
	} );

	it( 'lists every lens with its description', async () => {
		render( <Harness canTune={ false } /> );
		const open = await screen.findByRole( 'button', {
			name: 'Support desk',
		} );
		expect( open.closest( 'li' ) ).toHaveClass(
			'newspack-intelligence__actions'
		);
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

	it( 'opens a lens onto its trend chart above its groups', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		const items = await screen.findByRole( 'region', {
			name: 'Support desk',
		} );
		expect(
			await within( items ).findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect(
			within( items )
				.getAllByRole( 'heading', { level: 4 } )
				.map( ( heading ) => heading.textContent )
		).toEqual( [ 'site blocked from sending email', 'bot registrations' ] );
		const trends = screen.getByRole( 'region', { name: 'Trends' } );
		await within( trends ).findByRole( 'heading', {
			name: 'From 2026-09-21 to 2026-09-21',
		} );
		expect(
			trends.querySelectorAll( '.newspack-nodes-chart' )
		).toHaveLength( 1 );
		expect(
			within( trends ).getByRole( 'button', { name: 'complaint' } )
		).toBeInTheDocument();
		expect( within( trends ).getAllByRole( 'table' ) ).toHaveLength( 1 );
		expect(
			within( trends ).getByRole( 'row', { name: /2026-09-21/ } )
		).toHaveTextContent( 'complaint' );
		expect( within( trends ).queryByRole( 'link' ) ).toBeNull();
		expect(
			trends.compareDocumentPosition( items ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=support-desk', '--group=theme' ],
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

	it( 'calls out the lens’s spike beside its sparkline', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const line = (
			await within( signals ).findByText( 'about spike: praise' )
		).closest( 'p' );
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			/oldest first: 1, 3, 0, 1, 2, 0, 4, 11; the latest, from 2026-09-28, counts 11/
		);
	} );

	it( 'reads a quiet lens too new to judge as such beside its sparkline', async () => {
		wire.replies.signals = {
			signals: [],
			trend: TREND,
			partial: { bucket: '2026-10-05', count: 6 },
			judged: false,
			bucket: 'week',
			baseline_buckets: 8,
			available: true,
		};
		render( <Harness canTune={ false } /> );
		await openSupport();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const line = (
			await within( signals ).findByText(
				'Too little history to judge. A change is measured against the 8 weeks before.'
			)
		).closest( 'p' );
		expect( within( signals ).queryByText( /Steady/ ) ).toBeNull();
		expect( within( line ).getByRole( 'img' ) ).toHaveAccessibleName(
			/counts 11$/
		);
		expect( within( signals ).queryByRole( 'table' ) ).toBeNull();
		expect( screen.queryByText( /No signal/ ) ).toBeNull();
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
		await screen.findByText( 'From 2026-09-21 to 2026-09-21' );
		await screen.findByText( 'Acme Quillsheet wins regional prize' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Watch this' } ) );
		expect( onWatch ).toHaveBeenCalledWith(
			{ kind: 'lens', key: 'support-desk' },
			'Support desk'
		);
	} );

	it( 'says a lens sees no item and no trend', async () => {
		wire.replies.search = { groups: [], total: 0, available: true };
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

	it( 'pages the lens groups forward and back', async () => {
		wire.replies.search = ( args ) => ( {
			...GROUPS,
			groups: GROUPS.groups.slice( 0, 3 ).map( ( group, n ) => ( {
				...group,
				count: 2,
				theme: `${ 'abc'[ n ] } at ${ args.join( ' ' ) }`,
			} ) ),
			total: 70,
		} );
		render( <Harness canTune={ false } /> );
		await openSupport();
		await screen.findByText( 'a at --lens=support-desk --group=theme' );
		expect( screen.getByText( '3 of 70 groups' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect(
			await screen.findByText(
				'a at --lens=support-desk --group=theme --offset=3'
			)
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
		expect(
			await screen.findByText( 'a at --lens=support-desk --group=theme' )
		).toBeInTheDocument();
	} );

	it( 'says a refused trend read was not read, its words kept from a reader', async () => {
		wire.replies.trends = () =>
			new Error( 'by must be one of: kind, topic' );
		render( <Harness canTune={ false } /> );
		await openSupport();
		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'by must be one of: kind, topic' )
		).toBeNull();
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
		type( 'Entity', 'organization:acme-quillsheet' );
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
					'--entity=organization:acme-quillsheet',
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
					'--entity=organization:acme-quillsheet',
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

	it( 'ranks the groups in the answer’s order under a fixed line, asking no sort', async () => {
		render( <Harness canTune={ false } /> );
		await openSupport();
		expect(
			await screen.findByText(
				'Ranked by severity, importance, clients affected, then recency'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Show newest first' } )
		).toBeNull();
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=support-desk', '--group=theme' ],
		] );
	} );

	it( "saves a signal's cited item to a folder", async () => {
		const onSave = jest.fn();
		render( <Harness canTune={ false } onSave={ onSave } /> );
		await openSupport();
		const signals = await screen.findByRole( 'region', {
			name: 'Signals',
		} );
		const cited = await within( signals ).findByRole( 'link', {
			name: 'Acme thanks support for the fix',
		} );
		fireEvent.click(
			within( cited.closest( 'li' ) ).getByRole( 'button', {
				name: 'Save to folder',
			} )
		);
		expect( onSave ).toHaveBeenCalledWith(
			SIGNALS.signals[ 0 ].items[ 0 ]
		);
	} );

	it( "offers a curator the exclusion on a group's items, and a reader none", async () => {
		const opened = async ( canTune ) => {
			render( <Harness canTune={ canTune } onSave={ jest.fn() } /> );
			await openSupport();
			const toggle = await screen.findByRole( 'button', {
				name: 'Show items: bot registrations',
			} );
			fireEvent.click( toggle );
			return toggle.closest( 'li' );
		};
		expect(
			within( await opened( true ) ).getByRole( 'button', {
				name: 'Exclude from every view',
			} )
		).toBeInTheDocument();
		cleanup();
		expect(
			within( await opened( false ) ).queryByRole( 'button', {
				name: 'Exclude from every view',
			} )
		).toBeNull();
	} );

	it( 'expands a group onto its items, saving one, and collapses it', async () => {
		const onSave = jest.fn();
		render( <Harness canTune={ false } onSave={ onSave } /> );
		await openSupport();
		const toggle = await screen.findByRole( 'button', {
			name: 'Show items: bot registrations',
		} );
		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		const group = toggle.closest( 'li' );
		fireEvent.click(
			within( group ).getByRole( 'button', {
				name: 'Save to folder',
			} )
		);
		expect( onSave ).toHaveBeenCalledWith( GROUPS.groups[ 1 ].items[ 0 ] );
		fireEvent.click( toggle );
		expect(
			screen.queryByRole( 'link', {
				name: 'Harbor Ledger floods with fake readers',
			} )
		).toBeNull();
	} );

	it( 'draws a lens counting by theme as a series per theme, leaving its clients to the groups', async () => {
		const [ support, ...rest ] = LENSES.lenses;
		wire.replies.lenses = {
			...LENSES,
			lenses: [
				{ ...support, filters: { ...support.filters, by: 'theme' } },
				...rest,
			],
		};
		wire.replies.trends = {
			rows: [
				{
					bucket: '2026-09-21',
					value: 'bot registrations',
					count: 17,
					clients: [
						{
							entity_key: 'organization:harbor-ledger',
							name: 'Harbor Ledger',
						},
					],
					items: TRENDS.rows[ 0 ].items,
				},
			],
			available: true,
		};
		render( <Harness canTune={ false } /> );
		await openSupport();
		const trends = screen.getByRole( 'region', { name: 'Trends' } );
		await within( trends ).findByRole( 'row', { name: /2026-09-21/ } );
		expect(
			within( trends ).getByRole( 'button', {
				name: 'bot registrations',
			} )
		).toBeInTheDocument();
		expect(
			within( trends ).queryByRole( 'button', { name: 'Harbor Ledger' } )
		).toBeNull();
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
	} );
} );

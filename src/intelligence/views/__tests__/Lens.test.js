/* eslint-env jest */
/**
 * Team views: a rail listing every view with what is new in it this week,
 * a select when narrow; the selected view's fixed header with Watch, and
 * for a curator Edit and a menu holding Remove view… and Edit layout; then
 * the team view layout, its blocks reading the selected view. One form,
 * in a dialog, saves a new view or edits one, keyed by its name and never
 * showing the key; removing one asks first.
 */

import {
	act,
	fireEvent,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { useState } from '@wordpress/element';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { COMPOSITIONS, GROUPS, LENSES, pageData, VOCABULARY } from './fixtures';
import Lens, { CLOSED_LENS } from '../Lens';

/** Each address Edit layout would load, in place of the navigation jsdom refuses. */
const mockLoads = [];
jest.mock( '@newspack-nodes/shared/composition/page', () => {
	const actual = jest.requireActual(
		'@newspack-nodes/shared/composition/page'
	);
	return {
		...actual,
		editLayoutControl: ( target, pages ) => ( {
			...actual.editLayoutControl( target, pages ),
			onClick: () =>
				mockLoads.push(
					actual.editSearch(
						globalThis.location.search,
						target,
						pages
					)
				),
		} ),
	};
} );

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

/** The default team view layout, as `compositions get` answers it. */
const LAYOUT = COMPOSITIONS[ 'newspack-intelligence/lens' ];

const TREND = [ 6, 1, 0, 2, 1, 0, 3, 1 ].map( ( count, n ) => ( {
	bucket: `2026-09-${ String( 28 - n * 7 ).padStart( 2, '0' ) }`,
	count,
	in_window: 0 < 28 - n * 7,
} ) );

const SIGNALS = {
	signals: [],
	trend: TREND,
	partial: { bucket: '2026-10-05', count: 2 },
	judged: true,
	bucket: 'week',
	baseline_buckets: 8,
	available: true,
};

const TRENDS = {
	rows: [
		{ bucket: '2026-09-28', value: 'complaint', count: 4, items: [] },
		{ bucket: '2026-09-21', value: 'praise', count: 2, items: [] },
	],
	by: 'kind',
	bucket: 'week',
	available: true,
};

let wire;
let props;

function Harness( over ) {
	const [ opened, setOpened ] = useState( CLOSED_LENS );
	return (
		<Lens { ...props } opened={ opened } onOpen={ setOpened } { ...over } />
	);
}

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	window.localStorage.clear();
	wire = intelWire();
	wire.replies.lenses = LENSES;
	wire.replies.signals = SIGNALS;
	wire.replies.trends = TRENDS;
	wire.replies.search = GROUPS;
	props = {
		canTune: false,
		vocabulary: VOCABULARY,
		layout: LAYOUT,
		onWatch: jest.fn(),
		actions: { onOpenEntity: jest.fn(), canTune: false },
	};
} );

/**
 * The rail's button naming a view.
 *
 * @param {string} name The view's name.
 * @return {Promise<HTMLElement>} The button.
 */
const railButton = ( name ) =>
	screen.findByRole( 'button', { name: new RegExp( `^${ name }` ) } );

/**
 * Open the ⋯ menu of the selected view's header.
 *
 * @return {Promise<HTMLElement>} The menu.
 */
const openMenu = async () => {
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'Team view actions' } )
	);
	return screen.findByRole( 'menu' );
};

/**
 * The shared header a heading stands in.
 *
 * @param {string} name The heading.
 * @return {HTMLElement} The header.
 */
const headerOf = ( name ) =>
	screen
		.getByRole( 'heading', { level: 2, name } )
		.closest( '.newspack-nodes-composed-section-head' );

describe( 'Lens', () => {
	it( 'stands under the shared header, New view at its end for a curator, and heads the open view the same way', async () => {
		props.canTune = true;
		renderOnPage( <Harness /> );
		await screen.findByRole( 'heading', { name: 'Support desk' } );
		const page = headerOf( 'Team views' );
		expect( page ).toHaveTextContent(
			'Saved views for each team, with what moved this week.'
		);
		expect(
			within( page ).getByRole( 'button', { name: 'New view' } )
		).toBeInTheDocument();
		const open = headerOf( 'Support desk' );
		expect( open ).toHaveTextContent( 'What clients complain about' );
		expect(
			within( open ).getByRole( 'button', { name: 'Team view actions' } )
		).toBeInTheDocument();
	} );

	it( 'lists every view in the rail with what is new this week, the first open', async () => {
		renderOnPage( <Harness /> );
		const rail = await screen.findByRole( 'navigation', {
			name: 'Team views',
		} );
		const support = await within( rail ).findByRole( 'button', {
			name: 'Support desk 29 new',
		} );
		expect( support ).toHaveAttribute( 'aria-current', 'true' );
		expect(
			within( rail ).getByRole( 'button', {
				name: 'Developer shipping 4 new',
			} )
		).not.toHaveAttribute( 'aria-current' );
		expect(
			screen.getByRole( 'heading', { level: 2, name: 'Support desk' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'What clients complain about' )
		).toBeInTheDocument();
		expect( wire.sent( 'lenses' ) ).toEqual( [ [] ] );
	} );

	it( 'renders the pulse line, the chart and the theme groups under the fixed header, in that order', async () => {
		renderOnPage( <Harness /> );
		const header = await screen.findByRole( 'heading', {
			level: 2,
			name: 'Support desk',
		} );
		const pulse = await screen.findByText( 'No unusual change last week.' );
		const chart = await screen.findByRole( 'heading', {
			name: 'Last 8 weeks, by news type',
		} );
		const groups = await screen.findByRole( 'heading', {
			name: 'Stories, ranked by severity, importance and clients',
		} );
		await screen.findByRole( 'heading', {
			name: 'site blocked from sending email',
		} );
		const follows = ( a, b ) =>
			a.compareDocumentPosition( b ) &
			window.Node.DOCUMENT_POSITION_FOLLOWING;
		expect( follows( header, pulse ) ).toBeTruthy();
		expect( follows( pulse, chart ) ).toBeTruthy();
		expect( follows( chart, groups ) ).toBeTruthy();
		expect( wire.sent( 'signals' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
		expect( wire.sent( 'trends' ) ).toEqual( [
			[ '--lens=support-desk' ],
		] );
		expect( wire.sent( 'search' ) ).toEqual( [
			[ '--lens=support-desk', '--group=theme', '--limit=10' ],
		] );
	} );

	it( 'opens the view the rail names', async () => {
		renderOnPage( <Harness /> );
		fireEvent.click( await railButton( 'Developer shipping' ) );
		expect(
			await screen.findByRole( 'heading', {
				level: 2,
				name: 'Developer shipping',
			} )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( wire.sent( 'trends' ) ).toContainEqual( [
				'--lens=dev-shipping',
			] )
		);
	} );

	it( 'says a view too new to judge holds nothing over its days, with no line of zeros and no band', async () => {
		wire.replies.lenses = {
			...LENSES,
			lenses: [
				{
					...LENSES.lenses[ 0 ],
					filters: { ...LENSES.lenses[ 0 ].filters, days: 30 },
				},
			],
		};
		wire.replies.signals = {
			...SIGNALS,
			judged: false,
			trend: TREND.map( ( point ) => ( { ...point, count: 0 } ) ),
			partial: { bucket: '2026-10-05', count: 0 },
		};
		wire.replies.trends = { ...TRENDS, rows: [] };
		wire.replies.search = { groups: [], total: 0, available: true };
		const { container } = renderOnPage( <Harness /> );
		expect(
			await screen.findByText(
				'Nothing in this view in the last 30 days.'
			)
		).toBeInTheDocument();
		const tooNew = await screen.findByText(
			'Too little history to judge. A change is measured against the 8 weeks before.'
		);
		expect(
			within( tooNew.closest( 'p' ) ).queryByRole( 'img' )
		).toBeNull();
		expect(
			container.querySelector( '.newspack-nodes-sparkline__band' )
		).toBeNull();
	} );

	it( 'offers every reader a watch on the view open', async () => {
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Watch' } )
		);
		expect( props.onWatch ).toHaveBeenCalledWith(
			{ kind: 'lens', key: 'support-desk' },
			'Support desk'
		);
	} );

	it( 'offers a reader no curation', async () => {
		renderOnPage( <Harness /> );
		await railButton( 'Support desk' );
		expect(
			screen.queryByRole( 'button', { name: 'New view' } )
		).toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Edit' } ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Team view actions' } )
		).toBeNull();
	} );

	it( 'holds Remove view… and Edit layout in a curator’s menu', async () => {
		props.canTune = true;
		renderOnPage( <Harness /> );
		const menu = await openMenu();
		expect(
			within( menu )
				.getAllByRole( 'menuitem' )
				.map( ( one ) => one.textContent )
		).toEqual( [ 'Remove view…', 'Edit layout' ] );
	} );

	it( 'loads the layout editor on the team view open from its Edit layout', async () => {
		props.canTune = true;
		mockLoads.length = 0;
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&view=lenses'
		);
		renderOnPage( <Harness /> );
		fireEvent.click( await railButton( 'Developer shipping' ) );
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Edit layout' } )
		);
		expect( mockLoads ).toEqual( [
			'?page=newspack-intelligence&view=lenses&edit=lens&lens=dev-shipping',
		] );
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'saves a new view from one dialog, its key derived from the name and never shown', async () => {
		props.canTune = true;
		wire.replies.save_lens = { ok: true, event_id: 'ev-l1' };
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'New view' } )
		);
		const dialog = screen.getByRole( 'dialog', { name: 'New team view' } );
		expect( within( dialog ).queryByLabelText( /key/i ) ).toBeNull();
		fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
			target: { value: 'Desk Watch' },
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'What it is for' ), {
			target: { value: 'What the desk files' },
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'Look back' ), {
			target: { value: '60' },
		} );
		fireEvent.click( within( dialog ).getByLabelText( 'Few' ) );
		fireEvent.change( within( dialog ).getByLabelText( 'Chart by' ), {
			target: { value: 'topic' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_lens' ) ).toEqual( [
				[
					'--key=Desk Watch',
					'--name=Desk Watch',
					'--description=What the desk files',
					'--tier=restricted',
					'--days=60',
					'--by=topic',
				],
			] )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'New team view' } )
			).toBeNull()
		);
	} );

	it( 'stacks the edit dialog\u2019s fields as the other forms stack theirs', async () => {
		props.canTune = true;
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Edit Support desk',
		} );
		expect( dialog ).toHaveClass( 'newspack-intelligence__edit' );
		expect(
			await within( dialog ).findByRole( 'option', { name: '21 days' } )
		).toBeInTheDocument();
	} );

	it( 'edits the view open in the same dialog, from its own filters', async () => {
		props.canTune = true;
		wire.replies.save_lens = { ok: true, event_id: 'ev-l2' };
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Edit Support desk',
		} );
		expect( within( dialog ).getByLabelText( 'Look back' ) ).toHaveValue(
			'21'
		);
		expect( within( dialog ).getByLabelText( 'Staff' ) ).toBeChecked();
		expect(
			within( dialog ).getByRole( 'option', { name: '21 days' } )
		).toBeInTheDocument();
		fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
			target: { value: 'Prairie support' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'save_lens' )[ 0 ].slice( 0, 2 ) ).toEqual( [
				'--key=support-desk',
				'--name=Prairie support',
			] )
		);
	} );

	it( 'gives a saved view back to the read once it lists the view as the server stored it', async () => {
		props.canTune = true;
		const [ support, shipping ] = LENSES.lenses;
		let saved = false;
		wire.replies.save_lens = () => {
			saved = true;
			return { ok: true, event_id: 'ev-l4', lens_key: 'support-desk' };
		};
		wire.replies.lenses = () => ( {
			lenses: saved
				? [
						{
							...support,
							name: 'Prairie support',
							filters: {
								...support.filters,
								topics: [ 'topic:tides' ],
							},
							week_items: 17,
						},
						shipping,
				  ]
				: LENSES.lenses,
			available: true,
		} );
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Edit Support desk',
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
			target: { value: 'Prairie support' },
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'Topics' ), {
			target: { value: 'topic:tides, topic:tides' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);

		expect(
			await screen.findByRole( 'button', {
				name: 'Prairie support 17 new',
			} )
		).toBeInTheDocument();
	} );

	it( 'sends a value its list repeats once, and gives way to the view it already stored', async () => {
		props.canTune = true;
		const [ support, shipping ] = LENSES.lenses;
		const tided = {
			...support,
			filters: { ...support.filters, topics: [ 'topic:tides' ] },
		};
		let saved = false;
		wire.replies.save_lens = () => {
			saved = true;
			return { ok: true, event_id: 'ev-l5', lens_key: 'support-desk' };
		};
		wire.replies.lenses = () => ( {
			lenses: [ { ...tided, week_items: saved ? 18 : 29 }, shipping ],
			available: true,
		} );
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Edit Support desk',
		} );
		fireEvent.change( within( dialog ).getByLabelText( 'Topics' ), {
			target: { value: 'topic:tides, topic:tides' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);

		await waitFor( () =>
			expect(
				wire
					.sent( 'save_lens' )[ 0 ]
					.filter( ( token ) => token.startsWith( '--topic=' ) )
			).toEqual( [ '--topic=topic:tides' ] )
		);
		expect(
			await screen.findByRole( 'button', {
				name: 'Support desk 18 new',
			} )
		).toBeInTheDocument();
	} );

	it( 'keeps the dialog and shows a refused save', async () => {
		props.canTune = true;
		wire.replies.save_lens = () =>
			new Error( 'kind must be one of: award, launch' );
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'New view' } )
		);
		const dialog = screen.getByRole( 'dialog', { name: 'New team view' } );
		fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
			target: { value: 'Odd kinds' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);
		expect(
			await screen.findByText( 'kind must be one of: award, launch' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'dialog', { name: 'New team view' } )
		).toBeInTheDocument();
	} );

	it( 'opens the dialog again with no refusal its last save drew', async () => {
		props.canTune = true;
		wire.replies.save_lens = () =>
			new Error( 'kind must be one of: award, launch' );
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'New view' } )
		);
		let dialog = screen.getByRole( 'dialog', { name: 'New team view' } );
		fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
			target: { value: 'Odd kinds' },
		} );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save view' } )
		);
		await screen.findByText( 'kind must be one of: award, launch' );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'New view' } ) );
		dialog = screen.getByRole( 'dialog', { name: 'New team view' } );
		expect(
			within( dialog ).queryByText( 'kind must be one of: award, launch' )
		).toBeNull();
	} );

	it( 'cancels the dialog without sending', async () => {
		props.canTune = true;
		renderOnPage( <Harness /> );
		fireEvent.click(
			await screen.findByRole( 'button', { name: 'New view' } )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect(
			screen.queryByRole( 'dialog', { name: 'New team view' } )
		).toBeNull();
		expect( wire.sent( 'save_lens' ) ).toEqual( [] );
		await screen.findByText( 'No unusual change last week.' );
	} );

	it( 'asks before removing the view open, then removes it', async () => {
		props.canTune = true;
		wire.replies.remove_lens = { ok: true, event_id: 'ev-l3' };
		renderOnPage( <Harness /> );
		const menu = await openMenu();
		fireEvent.click(
			within( menu ).getByRole( 'menuitem', { name: 'Remove view…' } )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Remove the Support desk view? Watches that follow it will stop.',
		} );
		expect( wire.sent( 'remove_lens' ) ).toEqual( [] );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Remove view' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'remove_lens' ) ).toEqual( [
				[ '--key=support-desk' ],
			] )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'button', { name: /^Support desk/ } )
			).toBeNull()
		);
	} );

	it( 'shows the read error in the layout’s place when the layout does not read', async () => {
		renderOnPage(
			<Harness
				layout={ { context: LAYOUT.context, error: 'no block' } }
			/>
		);
		expect(
			await screen.findByText( 'This could not be read just now.' )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Try again' } )
		).toBeNull();
	} );

	describe.each( [
		[ 'at 390 px', true ],
		[ 'at 1,440 px', false ],
	] )( '%s', ( _, narrow ) => {
		let viewport;
		beforeEach( () => {
			viewport = installViewport( narrow );
		} );
		afterEach( () => viewport.restore() );

		it( 'says there is no view yet, offering a curator a new one', async () => {
			props.canTune = true;
			wire.replies.lenses = { lenses: [], available: true };
			renderOnPage( <Harness /> );
			expect(
				await screen.findByText(
					/^No team views yet\. A view saves a set of filters and charts what moves inside it\./
				)
			).toBeInTheDocument();
			expect(
				screen.getAllByRole( 'button', { name: 'New view' } ).length
			).toBeGreaterThan( 0 );
		} );

		it( 'returns focus to the header’s New view once a view made from the empty state lands', async () => {
			props.canTune = true;
			wire.replies.lenses = { lenses: [], available: true };
			wire.replies.save_lens = { ok: true, event_id: 'ev-l7' };
			renderOnPage( <Harness /> );
			const empty = (
				await screen.findByText( /^No team views yet\./ )
			).closest( '.newspack-nodes-empty-state' );
			const opener = within( empty ).getByRole( 'button', {
				name: 'New view',
			} );
			act( () => opener.focus() );
			fireEvent.click( opener );
			const dialog = screen.getByRole( 'dialog', {
				name: 'New team view',
			} );
			fireEvent.change( within( dialog ).getByLabelText( 'Name' ), {
				target: { value: 'Osprey desk' },
			} );
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Save view' } )
			);
			await waitFor( () =>
				expect( screen.queryByRole( 'dialog' ) ).toBeNull()
			);
			expect( document.activeElement ).toBe(
				within( headerOf( 'Team views' ) ).getByRole( 'button', {
					name: 'New view',
				} )
			);
		} );

		it( 'stands a skeleton in while the views are owed', () => {
			wire.replies.lenses = owed;
			renderOnPage( <Harness /> );
			expect(
				screen.getByRole( 'status', { name: 'Loading' } )
			).toBeInTheDocument();
		} );

		it( 'shows the read error when the views are refused', async () => {
			wire.replies.lenses = () => new Error( 'lenses refused' );
			renderOnPage( <Harness /> );
			expect(
				await screen.findByText(
					'This could not be read just now. Try again.'
				)
			).toBeInTheDocument();
		} );
	} );

	describe( 'at 390 px', () => {
		let viewport;
		beforeEach( () => {
			viewport = installViewport( true );
		} );
		afterEach( () => viewport.restore() );

		it( 'picks the view from a select in the rail’s place', async () => {
			renderOnPage( <Harness /> );
			const select = await screen.findByLabelText( 'Team view' );
			expect(
				within( select ).getByRole( 'option', {
					name: 'Support desk, 29 new',
				} )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'navigation', { name: 'Team views' } )
			).toBeNull();
			fireEvent.change( select, { target: { value: 'dev-shipping' } } );
			expect(
				await screen.findByRole( 'heading', {
					level: 2,
					name: 'Developer shipping',
				} )
			).toBeInTheDocument();
		} );
	} );
} );

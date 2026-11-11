/* eslint-env jest */
/**
 * Briefing draws the page data's Briefing layout on one surface, each block
 * by its view, and reads the week once for its `since` and `week` blocks,
 * through the categories its `week` names. A block whose read fails shows
 * the read error in its own place while the rest render; a layout that no
 * longer reads shows the shared read error. A curator finds **Edit layout**
 * in the Briefing's ⋯ menu; a reader finds no menu.
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fireEvent, screen, within } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { Core } from '@newspack-nodes/runtime';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { BRIEFING_DEFAULTS, ITEM, pageData } from './fixtures';
import { sinceLabel } from '../../components/format';
import Briefing from '../Briefing';

/**
 * The shipped default layout's tree, as `compositions get` answers it.
 *
 * @param {Object} [over] Block name => attributes laid over its defaults.
 * @return {Object} The layout.
 */
const defaultLayout = ( over = {} ) => ( {
	context: 'newspack-intelligence/briefing',
	source: 'default',
	blocks: [
		...readFileSync(
			path.resolve( __dirname, '../../../../layouts/briefing.html' ),
			'utf8'
		).matchAll( /<!-- wp:([a-z-]+\/[a-z-]+) \/-->/g ),
	].map( ( [ , name ] ) => ( {
		name,
		attributes: { ...BRIEFING_DEFAULTS[ name ], ...over[ name ] },
		inner: [],
	} ) ),
	saved_ts: null,
	actor: null,
} );

/** A reply that never comes, so its read stays owed. */
const owed = () => new Promise( () => {} );

/** A last look the page holds. */
const LAST_LOOK = 1791999000;

/**
 * The lede's heading for that last look, named from now.
 *
 * @return {string} The heading.
 */
const sinceHeading = () => `Since ${ sinceLabel( LAST_LOOK, Core.now() ) }`;

const CHANGES = {
	order: 'importance',
	items: [ { ...ITEM, categories: [], otherwise: true } ],
	groups: [
		{ role: 'otherwise', key: null, name: null, items: [ ITEM.item_key ] },
	],
	categories: [],
	otherwise: 1,
	total: 61,
	summary: {
		since: LAST_LOOK,
		items: 23,
		clients: 9,
		kinds: [ { kind: 'award', items: 2, clients: 2 } ],
	},
	available: true,
};

const SIGNALS = { clients: [], lenses: [], judged: true, available: true };

const WATCHES = { watches: [], available: true };

let wire;
let props;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	const answers = { changes: CHANGES, signals: SIGNALS };
	wire.replies.briefing = ( [ what ] ) => answers[ what ];
	wire.replies.watches = WATCHES;
	wire.replies.lenses = { lenses: [], available: true };
	props = {
		layout: defaultLayout(),
		since: LAST_LOOK,
		kept: true,
		noteVisible: jest.fn(),
		canTune: false,
		actions: {
			onOpenEntity: jest.fn(),
			onOpenLens: jest.fn(),
			onOpenWatch: jest.fn(),
			onNewWatch: jest.fn(),
			onSearchFor: jest.fn(),
			onSearch: jest.fn(),
			canTune: false,
			asked: null,
			onAsked: jest.fn(),
		},
	};
} );

afterEach( () => jest.restoreAllMocks() );

const renderBriefing = () => renderOnPage( <Briefing { ...props } /> );

describe( 'Briefing', () => {
	it( 'draws the default layout in order, naming no operator', async () => {
		const { container } = renderBriefing();
		await screen.findByRole( 'region', {
			name: 'This week, most important first',
		} );
		const order = [ ...container.querySelectorAll( 'section' ) ].map(
			( section ) =>
				section.getAttribute( 'aria-label' ) ??
				section.querySelector( 'h2' )?.textContent
		);
		expect( order ).toEqual( [
			'Ask',
			sinceHeading(),
			'Needs attention',
			'Your watches',
			'This week, most important first',
		] );
		const walker = document.createTreeWalker(
			container,
			window.NodeFilter.SHOW_TEXT
		);
		while ( walker.nextNode() ) {
			expect( walker.currentNode.textContent ).not.toContain(
				'Newspack'
			);
		}
		expect(
			container.querySelectorAll( '.newspack-intelligence__surface' )
		).toHaveLength( 1 );
	} );

	it( "reads the week once, through its week's categories", async () => {
		props.layout = defaultLayout( {
			'newspack-intelligence/week': {
				categories: [ 'desk-awards', 'partner-desk' ],
			},
		} );
		renderBriefing();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect(
			wire.sent( 'briefing' ).filter( ( [ what ] ) => 'changes' === what )
		).toEqual( [
			[
				'changes',
				`--since=${ LAST_LOOK }`,
				'--lenses=desk-awards,partner-desk',
			],
		] );
	} );

	it( 'shows a failed block’s read error in its place while the rest render', async () => {
		wire.replies.briefing = ( [ what ] ) =>
			'signals' === what ? new Error( 'signals refused' ) : CHANGES;
		renderBriefing();
		const attention = await screen.findByRole( 'region', {
			name: 'Needs attention',
		} );
		expect(
			await within( attention ).findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toBeInTheDocument();
	} );

	it( 'stands two lede lines, three pulse rows and ten item rows in while owed', async () => {
		wire.replies.briefing = owed;
		wire.replies.watches = owed;
		renderBriefing();
		const region = ( name ) => screen.getByRole( 'region', { name } );
		await screen.findAllByRole( 'status', { name: 'Loading' } );
		const bars = ( name ) =>
			region( name ).querySelectorAll(
				'.newspack-intelligence__skeleton-bar.is-line'
			).length;
		expect( bars( sinceHeading() ) ).toBe( 2 );
		expect( bars( 'Needs attention' ) ).toBe( 3 );
		expect(
			region( 'This week, most important first' ).querySelectorAll(
				'.newspack-intelligence__item'
			)
		).toHaveLength( 10 );
	} );

	it( 'shows the shared read error for a layout that no longer reads', async () => {
		props.layout = {
			context: 'newspack-intelligence/briefing',
			error: 'this layout could not be read',
		};
		renderBriefing();
		expect(
			await screen.findByText(
				'Intelligence could not be read just now.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Try again' } )
		).toBeNull();
		expect( wire.sent( 'briefing' ) ).toEqual( [] );
	} );

	it( 'offers a curator Edit layout in its ⋯ menu, and a reader no menu', async () => {
		const { unmount } = renderBriefing();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		expect(
			screen.queryByRole( 'button', { name: 'Briefing actions' } )
		).toBeNull();
		unmount();
		props.canTune = true;
		renderBriefing();
		await screen.findByRole( 'link', {
			name: 'Acme Quillsheet wins regional prize',
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Briefing actions' } )
		);
		expect(
			await screen.findByRole( 'menuitem', { name: 'Edit layout' } )
		).toBeInTheDocument();
	} );

	it( 'tells the page it is seen while mounted, and not once unmounted', () => {
		const { unmount } = renderBriefing();
		expect( props.noteVisible ).toHaveBeenLastCalledWith( true );
		unmount();
		expect( props.noteVisible ).toHaveBeenLastCalledWith( false );
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

		it( 'says what it holds when the week is empty', async () => {
			wire.replies.briefing = ( [ what ] ) =>
				'signals' === what
					? SIGNALS
					: {
							...CHANGES,
							items: [],
							groups: [],
							otherwise: 0,
							total: 0,
							summary: {
								...CHANGES.summary,
								items: 0,
								kinds: [],
							},
					  };
			renderBriefing();
			expect(
				await screen.findByText( 'Nothing arrived this week.' )
			).toBeInTheDocument();
			expect(
				screen.getByText( /^Nothing new since / )
			).toBeInTheDocument();
			expect(
				screen.getByText(
					'No client or team view moved off its usual range last week.'
				)
			).toBeInTheDocument();
		} );

		it( 'stands skeletons in while it loads', async () => {
			wire.replies.briefing = owed;
			wire.replies.watches = owed;
			renderBriefing();
			expect(
				await screen.findAllByRole( 'status', { name: 'Loading' } )
			).toHaveLength( 4 );
		} );

		it( 'shows the read error in the failed block alone', async () => {
			wire.replies.watches = new Error( 'watches refused' );
			renderBriefing();
			const watches = await screen.findByRole( 'region', {
				name: 'Your watches',
			} );
			expect(
				await within( watches ).findByText(
					'Intelligence could not be read just now. Try again.'
				)
			).toBeInTheDocument();
			expect(
				await screen.findByRole( 'link', {
					name: 'Acme Quillsheet wins regional prize',
				} )
			).toBeInTheDocument();
		} );
	} );
} );

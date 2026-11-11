/* eslint-env jest */
/**
 * useLastLook fixes the Briefing's baseline for the browser session: it
 * copies the reader's last look into the session once, and writes a new
 * last look when the reader leaves after the Briefing has been seen for ten
 * seconds, counting only time the document is visible. The Briefing reads
 * the baseline it holds, so a tab switch and a reload ask the same instant.
 */

import { act, screen } from '@testing-library/react';
import { renderOnPage } from '../../__tests__/pageReads';
import { useEffect, useState } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import Briefing from '../../views/Briefing';
import { MIN_VISIBLE_MS, useLastLook } from '../useLastLook';
import { COMPOSITIONS, pageData } from '../../views/__tests__/fixtures';

const READER = '6143';
const LAST_LOOK = 'newspack-intelligence:last-look:6143';
const BASELINE = 'newspack-intelligence:baseline:6143';
const SEEN_MS = 'newspack-intelligence:seen-ms:6143';

/** A last look a reader left before this session, 2025-11-04 13:51 UTC. */
const SEEDED = 1762264260;

/** The wall clock the tests read while counting, in milliseconds. */
const WALL_MS = 1762300000777;

let monotonic;
let look;

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.sessionStorage.clear();
	monotonic = 51000;
	jest.spyOn( window.performance, 'now' ).mockImplementation(
		() => monotonic
	);
} );

afterEach( () => jest.restoreAllMocks() );

/**
 * Let the monotonic clock run.
 *
 * @param {number} ms How long.
 */
const pass = ( ms ) => {
	monotonic += ms;
};

/**
 * Hide or show the document, as the browser does on a tab switch.
 *
 * @param {'visible'|'hidden'} state What it becomes.
 */
const setVisibility = ( state ) => {
	jest.spyOn( document, 'visibilityState', 'get' ).mockReturnValue( state );
	act( () => {
		document.dispatchEvent( new Event( 'visibilitychange' ) );
	} );
};

const pagehide = () =>
	act( () => {
		window.dispatchEvent( new Event( 'pagehide' ) );
	} );

/**
 * The page's half: holds the hook, and notes the Briefing seen while shown.
 *
 * @param {Object}  props
 * @param {boolean} props.shown  Whether the Briefing shows.
 * @param {string}  props.reader The reader.
 * @return {null} Nothing.
 */
function Holder( { shown, reader = READER } ) {
	look = useLastLook( reader );
	const { noteVisible } = look;
	useEffect( () => {
		if ( ! shown ) {
			return undefined;
		}
		noteVisible( true );
		return () => noteVisible( false );
	}, [ shown, noteVisible ] );
	return null;
}

describe( 'useLastLook', () => {
	it( 'copies the last look into the session as the baseline', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		renderOnPage( <Holder shown={ false } /> );
		expect( look.since ).toBe( SEEDED );
		expect( look.kept ).toBe( true );
		expect( window.sessionStorage.getItem( BASELINE ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'keeps the session’s baseline over a newer last look', () => {
		window.sessionStorage.setItem( BASELINE, String( SEEDED ) );
		window.localStorage.setItem( LAST_LOOK, '1762299999' );
		renderOnPage( <Holder shown={ false } /> );
		expect( look.since ).toBe( SEEDED );
	} );

	it( 'keeps a first visit first for the whole session', () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( WALL_MS );
		const first = renderOnPage( <Holder shown /> );
		expect( look.since ).toBeNull();
		pass( MIN_VISIBLE_MS );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe( '1762300000' );
		first.unmount();
		renderOnPage( <Holder shown /> );
		expect( look.since ).toBeNull();
		expect( look.kept ).toBe( true );
	} );

	it( 'pagehide after ten seconds visible writes the mark', () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( WALL_MS );
		renderOnPage( <Holder shown /> );
		pass( MIN_VISIBLE_MS );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe( '1762300000' );
	} );

	it( 'pagehide after three seconds writes nothing', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		renderOnPage( <Holder shown /> );
		pass( 3000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'writes the mark when the document is hidden', () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( WALL_MS );
		renderOnPage( <Holder shown /> );
		pass( 12000 );
		setVisibility( 'hidden' );
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe( '1762300000' );
	} );

	it( 'counts only time the document is visible, across a return from the back-forward cache', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		renderOnPage( <Holder shown /> );
		pass( 6000 );
		setVisibility( 'hidden' );
		pass( 60000 );
		setVisibility( 'visible' );
		pass( 3000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
		act( () => {
			window.dispatchEvent( new Event( 'pageshow' ) );
		} );
		pass( 1000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).not.toBe(
			String( SEEDED )
		);
	} );

	it( 'counts nothing for a Briefing opened in a hidden tab', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		jest.spyOn( document, 'visibilityState', 'get' ).mockReturnValue(
			'hidden'
		);
		renderOnPage( <Holder shown /> );
		pass( 20000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'counts only time the Briefing shows', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		const page = renderOnPage( <Holder shown={ false } /> );
		pass( 30000 );
		page.rerender( <Holder shown /> );
		pass( 4000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'marks when the Briefing was last seen, not when the page hides', () => {
		const wall = jest.spyOn( Date, 'now' ).mockReturnValue( WALL_MS );
		const page = renderOnPage( <Holder shown /> );
		pass( 11000 );
		page.rerender( <Holder shown={ false } /> );
		wall.mockReturnValue( WALL_MS + 3600000 );
		pass( 3600000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe( '1762300000' );
	} );

	it( 'reads and writes only the reader’s own last look', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		renderOnPage( <Holder shown reader="9999" /> );
		expect( look.since ).toBeNull();
		pass( MIN_VISIBLE_MS );
		pagehide();
		expect(
			window.localStorage.getItem(
				'newspack-intelligence:last-look:9999'
			)
		).toMatch( /^\d+$/ );
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'counts time seen on an earlier page of the session', () => {
		jest.spyOn( Date, 'now' ).mockReturnValue( WALL_MS );
		window.sessionStorage.setItem( SEEN_MS, '6000' );
		renderOnPage( <Holder shown /> );
		pass( 5000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe( '1762300000' );
		expect( window.sessionStorage.getItem( SEEN_MS ) ).toBe( '11000' );
	} );

	it( 'writes nothing for six seconds alone', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		renderOnPage( <Holder shown /> );
		pass( 6000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
		expect( window.sessionStorage.getItem( SEEN_MS ) ).toBe( '6000' );
	} );

	it( 'writes nothing for a page that never showed the Briefing', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		window.sessionStorage.setItem( SEEN_MS, '13000' );
		renderOnPage( <Holder shown={ false } /> );
		pass( 5000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).toBe(
			String( SEEDED )
		);
	} );

	it( 'keeps no baseline when the session will not take one', () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		const write = window.Storage.prototype.setItem;
		jest.spyOn( window.Storage.prototype, 'setItem' ).mockImplementation(
			function ( key, value ) {
				if ( this === window.sessionStorage ) {
					throw new Error( 'session quota full' );
				}
				return write.call( this, key, value );
			}
		);
		renderOnPage( <Holder shown={ false } /> );
		expect( look.since ).toBeNull();
		expect( look.kept ).toBe( false );
	} );

	it( 'answers no baseline it can keep when both storages throw', () => {
		jest.spyOn( window.Storage.prototype, 'getItem' ).mockImplementation(
			() => {
				throw new Error( 'site data blocked' );
			}
		);
		jest.spyOn( window.Storage.prototype, 'setItem' ).mockImplementation(
			() => {
				throw new Error( 'site data blocked' );
			}
		);
		renderOnPage( <Holder shown /> );
		expect( look.since ).toBeNull();
		expect( look.kept ).toBe( false );
		pass( MIN_VISIBLE_MS );
		expect( pagehide ).not.toThrow();
	} );
} );

describe( 'the Briefing on the last look', () => {
	let wire;

	const handlers = {
		onSearchFor: () => {},
		onOpenEntity: () => {},
		onOpenLens: () => {},
		onOpenWatch: () => {},
		onNewWatch: () => {},
		onSearch: () => {},
		asked: null,
		onAsked: () => {},
	};

	const CHANGES = {
		order: 'importance',
		items: [],
		groups: [],
		categories: [],
		otherwise: 0,
		total: 0,
		summary: { since: SEEDED, items: 4, clients: 2, kinds: [] },
		available: true,
	};

	/**
	 * Answer the Briefing's three reads on a wire.
	 *
	 * @param {Object} on The wire.
	 */
	const answer = ( on ) => {
		on.replies.briefing = ( [ what ] ) =>
			'changes' === what
				? CHANGES
				: { clients: [], lenses: [], judged: true, available: true };
		on.replies.watches = { watches: [], available: true };
	};

	/**
	 * The page: the hook held above the tabs, the Briefing on its tab.
	 *
	 * @param {Object}  props
	 * @param {boolean} props.shown Whether the Briefing's tab is open.
	 * @return {import('react').ReactElement} The page.
	 */
	function Page( { shown } ) {
		const { since, kept, noteVisible } = useLastLook( READER );
		const [ asked, onAsked ] = useState( null );
		return shown ? (
			<Briefing
				layout={ COMPOSITIONS[ 'newspack-intelligence/briefing' ] }
				since={ since }
				kept={ kept }
				noteVisible={ noteVisible }
				canTune={ false }
				actions={ { ...handlers, asked, onAsked } }
			/>
		) : (
			<p>Search</p>
		);
	}

	beforeEach( () => {
		window.NewspackNodesData = pageData();
		wire = intelWire();
		answer( wire );
	} );

	it( 'a remount reads the first baseline', async () => {
		window.localStorage.setItem( LAST_LOOK, String( SEEDED ) );
		const page = renderOnPage( <Page shown /> );
		await screen.findByText( '4 new items.' );
		pass( 15000 );
		pagehide();
		expect( window.localStorage.getItem( LAST_LOOK ) ).not.toBe(
			String( SEEDED )
		);
		page.rerender( <Page shown={ false } /> );
		page.rerender( <Page shown /> );
		await screen.findByText( '4 new items.' );
		const beforeReload = wire.sent( 'briefing' );
		page.unmount();
		Core.reset();
		wire = intelWire();
		answer( wire );
		renderOnPage( <Page shown /> );
		await screen.findByText( '4 new items.' );
		const changes = [ ...beforeReload, ...wire.sent( 'briefing' ) ].filter(
			( [ what ] ) => 'changes' === what
		);
		expect( changes.length ).toBeGreaterThan( 0 );
		changes.forEach( ( asked ) =>
			expect( asked ).toEqual( [ 'changes', `--since=${ SEEDED }` ] )
		);
	} );

	it( 'storage that throws still renders the Briefing', async () => {
		jest.spyOn( window.Storage.prototype, 'getItem' ).mockImplementation(
			() => {
				throw new Error( 'site data blocked' );
			}
		);
		jest.spyOn( window.Storage.prototype, 'setItem' ).mockImplementation(
			() => {
				throw new Error( 'site data blocked' );
			}
		);
		renderOnPage( <Page shown /> );
		await screen.findByRole( 'region', {
			name: 'This week, most important first',
		} );
		await screen.findByText( 'Watch a client, a team view or a folder', {
			exact: false,
		} );
		expect( screen.queryByText( '4 new items.' ) ).not.toBeInTheDocument();
		expect( wire.sent( 'briefing' ) ).toContainEqual( [ 'changes' ] );
	} );
} );

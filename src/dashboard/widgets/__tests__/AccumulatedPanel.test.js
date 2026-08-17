/* eslint-env jest */
/**
 * AccumulatedPanel — the total-items KPI + collection progress + digest/newsletter
 * card. Reads ONLY the accumulated:view slice ({ accumulated, done, total, digest })
 * via useNodeState. Collect and Regenerate are the panel's OWN one-shots, so they
 * go over a fake command wire here rather than arriving as promise props — which
 * is also what makes the wiring itself covered. Copy / Create-draft act on the
 * shown digest via the `createDraft` / `markdownToContent` seams.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	act,
} from '@testing-library/react';
import {
	Core,
	VALUE,
	FROM,
	ID,
	forgetSession,
	__setAuthFetch,
} from '@newspack-nodes/runtime';
import { installFakeCommandWire } from '@newspack-nodes/shared/test-utils/fakeCommandWire';
import { views } from '../../nodes/register';

const AccumulatedViewNode = views.AccumulatedView;
import { AccumulatedPanel } from '../AccumulatedPanel';

// What the server answers, by verb; a test sets the one it exercises.
let replyByVerb;
let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	replyByVerb = {};
	wire = installFakeCommandWire( ( m ) => {
		const answer = replyByVerb[ m[ VALUE ]?.name ];
		return 'function' === typeof answer ? answer() : answer;
	} );
} );

// Every command of a given verb that actually went on the wire.
const sent = ( verb ) =>
	wire.batches.flat().filter( ( m ) => verb === m[ VALUE ]?.name );

function mountSlice( slice ) {
	const node = new AccumulatedViewNode();
	node.name = 'accumulated:view';
	node.setState( 'view', slice );
	return node;
}

// Real-clock sleep, captured before any faking: the reply rides the router's
// setInterval, which stays real, so waiting for it needs the real clock too.
const realSetTimeout = setTimeout;
const sleep = ( ms ) => new Promise( ( r ) => realSetTimeout( r, ms ) );

const DIGEST = '# Sprint digest\n\n- Big news shipped';
const COMPLETE = { accumulated: 3, done: 3, total: 3, digest: DIGEST };

// Render with sane action defaults; tests override the bits they exercise.
function renderPanel( slice, props = {} ) {
	mountSlice( slice );
	return render(
		<AccumulatedPanel
			createDraft={ jest.fn( () => Promise.resolve( { id: 1 } ) ) }
			markdownToContent={ ( md ) => `BLOCKS:${ md }` }
			{ ...props }
		/>
	);
}

describe( 'AccumulatedPanel — render', () => {
	it( 'shows the Total items KPI from the slice', () => {
		const { container } = renderPanel( COMPLETE );
		expect( screen.getByText( /total items/i ) ).toBeInTheDocument();
		expect(
			container.querySelector( '.eai-insights__stat-num' ).textContent
		).toBe( '3' );
	} );

	it( 'shows the REAL rendered digest in the preview', () => {
		renderPanel( COMPLETE );
		expect(
			screen.getByTestId( 'eai-insights-preview' ).textContent
		).toContain( 'Sprint digest' );
	} );

	it( 'shows collection progress as X/total', () => {
		renderPanel( { ...COMPLETE, done: 2, total: 3 } );
		expect( screen.getByText( /collected 2\/3/i ) ).toBeInTheDocument();
	} );

	it( 'surfaces a slice error as a notice', () => {
		renderPanel( {
			accumulated: 0,
			done: 0,
			total: 0,
			digest: '',
			error: 'acc read failed',
		} );
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			/acc read failed/
		);
	} );
} );

describe( 'AccumulatedPanel — canonical button classes', () => {
	it( 'uses stock .button classes, not eai-insights__btn', () => {
		const { container } = renderPanel( COMPLETE );
		expect( container.querySelector( '.eai-insights__btn' ) ).toBeNull();

		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toHaveClass( 'button', 'button-primary' );
		expect(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		).toHaveClass( 'button', 'button-primary' );

		const copy = screen.getByRole( 'button', { name: /copy markdown/i } );
		expect( copy ).toHaveClass( 'button' );
		expect( copy ).not.toHaveClass( 'button-primary' );

		const draft = screen.getByRole( 'button', {
			name: /create draft post/i,
		} );
		expect( draft ).toHaveClass( 'button' );
		expect( draft ).not.toHaveClass( 'button-primary' );
	} );
} );

describe( 'AccumulatedPanel — Collect gating', () => {
	it( 'offers Collect even with nothing collected yet (empty pipeline)', () => {
		renderPanel( { accumulated: 0, done: 0, total: 0, digest: '' } );
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeEnabled();
	} );

	it( 'disables Collect mid-collection (1/3)', () => {
		renderPanel( { ...COMPLETE, done: 1, total: 3 } );
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeDisabled();
	} );

	it( 'enables Collect when collection is complete (3/3)', () => {
		renderPanel( COMPLETE );
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeEnabled();
	} );

	it( 'shows 0/total immediately on click even when the prior cycle was complete', async () => {
		replyByVerb.collect = JSON.stringify( { collecting: 3, workers: 1 } );
		renderPanel( COMPLETE );
		expect( screen.getByText( /collected 3\/3/i ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collected 0\/3/i )
		).toBeInTheDocument();
	} );

	it( 'acknowledges a successful Collect and locks the button until the cycle completes', async () => {
		replyByVerb.collect = JSON.stringify( { collecting: 3, workers: 2 } );
		renderPanel( COMPLETE );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collecting from 2/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /collecting/i } )
		).toBeDisabled();
	} );

	it( 'surfaces a no-worker error when Collect finds nothing live', async () => {
		replyByVerb.collect = JSON.stringify( {
			error: 'No live newspack-intelligence worker',
		} );
		renderPanel( COMPLETE );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect( await screen.findByText( /no live/i ) ).toBeInTheDocument();
	} );

	it( 'surfaces an unexpected Collect response as an error', async () => {
		replyByVerb.collect = 'not json';
		renderPanel( COMPLETE );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText(
				/collection returned an unexpected response/i
			)
		).toBeInTheDocument();
	} );

	// A REFUSAL is a reply too: the verb answered, and it answered no.
	it( 'surfaces a refused Collect verb as an error', async () => {
		replyByVerb.collect = () => new Error( 'collect blew up' );
		renderPanel( COMPLETE );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collect blew up/i )
		).toBeInTheDocument();
	} );

	it( 'releases the Collect lock after the long safety timeout when progress never changes', async () => {
		replyByVerb.collect = JSON.stringify( { collecting: 3, workers: 1 } );
		// Fake the safety timeout ONLY. setInterval stays real so the graph
		// keeps ticking and the ack actually arrives; faking it would stop the
		// graph and pass this test for the wrong reason.
		jest.useFakeTimers( {
			doNotFake: [ 'setInterval', 'requestAnimationFrame' ],
		} );
		try {
			renderPanel( COMPLETE );
			fireEvent.click(
				screen.getByRole( 'button', { name: /^collect$/i } )
			);
			await act( async () => sleep( 2500 ) );
			expect(
				screen.getByText( /collecting from 1/i )
			).toBeInTheDocument();
			await act( async () => {
				jest.advanceTimersByTime( 180000 );
			} );
			expect(
				screen.getByRole( 'button', { name: /^collect$/i } )
			).toBeEnabled();
		} finally {
			jest.useRealTimers();
		}
	}, 20000 );

	it( 'auto-dismisses the "Collecting from N…" note so it does not linger forever', async () => {
		replyByVerb.collect = JSON.stringify( { collecting: 3, workers: 1 } );
		// Fake the dismissal ONLY; setInterval stays real so the ack arrives.
		jest.useFakeTimers( {
			doNotFake: [ 'setInterval', 'requestAnimationFrame' ],
		} );
		try {
			renderPanel( COMPLETE );
			fireEvent.click(
				screen.getByRole( 'button', { name: /^collect$/i } )
			);
			await act( async () => sleep( 2500 ) );
			expect(
				screen.getByText( /collecting from 1/i )
			).toBeInTheDocument();
			act( () => jest.advanceTimersByTime( 10000 ) );
			expect(
				screen.queryByText( /collecting from 1/i )
			).not.toBeInTheDocument();
		} finally {
			jest.useRealTimers();
		}
	}, 20000 );
} );

describe( 'AccumulatedPanel — Regenerate', () => {
	it( 'disables Regenerate until every source has reported done', () => {
		renderPanel( { ...COMPLETE, done: 1, total: 3 } );
		expect(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		).toBeDisabled();
	} );

	it( 'enables Regenerate once collection is complete', () => {
		renderPanel( COMPLETE );
		expect(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		).not.toBeDisabled();
	} );

	it( 'asks the worker to regenerate and acknowledges; the shown digest stays', async () => {
		replyByVerb.generate = JSON.stringify( {
			regenerating: true,
			workers: 1,
		} );
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		await waitFor( () =>
			expect( screen.getByText( /regenerating/i ) ).toBeInTheDocument()
		);
		expect(
			screen.getByTestId( 'eai-insights-preview' ).textContent
		).toContain( 'Sprint digest' );
	} );

	it( 'surfaces an error (and keeps the digest) when Regenerate finds no live worker', async () => {
		replyByVerb.generate = JSON.stringify( {
			error: 'No live newspack-intelligence worker',
		} );
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
				/no live/i
			)
		);
		expect(
			screen.getByTestId( 'eai-insights-preview' ).textContent
		).toContain( 'Sprint digest' );
	} );

	it( 'surfaces an unexpected Regenerate response as an error', async () => {
		replyByVerb.generate = 'not json';
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		expect(
			await screen.findByText(
				/regeneration returned an unexpected response/i
			)
		).toBeInTheDocument();
	} );

	// A REFUSAL is a reply too: the verb answered, and it answered no.
	it( 'surfaces a refused Regenerate verb as an error', async () => {
		replyByVerb.generate = () => new Error( 'generate blew up' );
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		expect(
			await screen.findByText( /generate blew up/i )
		).toBeInTheDocument();
	} );
} );

describe( 'AccumulatedPanel — Copy + Create draft', () => {
	it( 'copies the REAL digest markdown to the clipboard', async () => {
		const writeText = jest.fn( () => Promise.resolve() );
		Object.assign( window.navigator, { clipboard: { writeText } } );
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /copy markdown/i } )
		);
		expect( writeText ).toHaveBeenCalledTimes( 1 );
		expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain( 'Sprint digest' );
		expect( await screen.findByText( /copied/i ) ).toBeInTheDocument();
	} );

	it( 'does not crash or show "Copied" when the clipboard API is unavailable', async () => {
		const original = window.navigator.clipboard;
		Object.assign( window.navigator, { clipboard: undefined } );
		renderPanel( COMPLETE );
		expect( () =>
			fireEvent.click(
				screen.getByRole( 'button', { name: /copy markdown/i } )
			)
		).not.toThrow();
		expect( screen.queryByText( /copied/i ) ).not.toBeInTheDocument();
		Object.assign( window.navigator, { clipboard: original } );
	} );

	it( 'creates a draft from the markdownToContent seam and shows an Edit draft link', async () => {
		const createDraft = jest.fn( () => Promise.resolve( { id: 42 } ) );
		const markdownToContent = jest.fn( ( md ) => `BLOCKS:${ md }` );
		renderPanel( COMPLETE, { createDraft, markdownToContent } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		await waitFor( () => expect( createDraft ).toHaveBeenCalledTimes( 1 ) );
		expect( markdownToContent ).toHaveBeenCalledWith( DIGEST );
		expect( createDraft.mock.calls[ 0 ][ 0 ].content ).toBe(
			`BLOCKS:${ DIGEST }`
		);
		const link = await screen.findByRole( 'link', { name: /edit draft/i } );
		expect( link.getAttribute( 'href' ) ).toContain( 'post=42' );
	} );

	it( 'shows an inline error when creating a draft post fails', async () => {
		const createDraft = jest.fn( () =>
			Promise.reject( new Error( 'rest blew up' ) )
		);
		renderPanel( COMPLETE, { createDraft } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		expect(
			await screen.findByText( /rest blew up/i )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: /edit draft/i } )
		).not.toBeInTheDocument();
	} );

	it( 'shows an error (not a dead link) when the draft reply has no id', async () => {
		const createDraft = jest.fn( () => Promise.resolve( {} ) );
		renderPanel( COMPLETE, { createDraft } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		await waitFor( () => expect( createDraft ).toHaveBeenCalledTimes( 1 ) );
		expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: /edit draft/i } )
		).not.toBeInTheDocument();
	} );

	it( 'disables Copy + Create draft when there is no digest yet', () => {
		renderPanel( { accumulated: 3, done: 3, total: 3, digest: '' } );
		expect(
			screen.getByRole( 'button', { name: /copy markdown/i } )
		).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: /create draft post/i } )
		).toBeDisabled();
	} );
} );

// These moved here with the verbs: the panel holds them now, so the wire-level
// facts about them are asserted where the click that mints them lives.
describe( 'AccumulatedPanel — the action verbs on the wire', () => {
	it( 'mints each verb from its OWN node, addressed rather than correlated', async () => {
		replyByVerb.generate = JSON.stringify( { regenerating: true } );
		renderPanel( COMPLETE );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		await waitFor( () => expect( sent( 'generate' ).length ).toBe( 1 ), {
			timeout: 6000,
		} );
		const [ msg ] = sent( 'generate' );
		expect( msg[ FROM ] ).toBe( 'insights:generate:in' );
		// Addressed, not correlated: TO=FROM is the return path (ADR-7).
		expect( msg[ ID ] ).toBe( '' );
		// Token-array command contract: argv, never a joined string.
		expect( msg[ VALUE ] ).toMatchObject( {
			name: 'generate',
			arguments: [],
		} );
	}, 20000 );

	// A click before /auth resolves would mint UNSIGNED and be refused, and the
	// panel is clickable the moment it renders.
	it( 'signs a click that beats the session', async () => {
		forgetSession();
		let landAuth;
		const inFlight = new Promise( ( resolve ) => {
			landAuth = resolve;
		} );
		__setAuthFetch( () =>
			inFlight.then( () => ( {
				handle: 'ffff6666ffff6666ffff6666ffff6666',
				key: 'key-insights-late-auth',
				expires_in: 3600,
				now: 1771000000,
			} ) )
		);
		replyByVerb.generate = JSON.stringify( { regenerating: true } );
		renderPanel( COMPLETE );
		act( () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: /regenerate digest/i } )
			);
			landAuth();
		} );
		await waitFor( () => expect( sent( 'generate' ).length ).toBe( 1 ), {
			timeout: 8000,
		} );
		expect( sent( 'generate' )[ 0 ][ VALUE ].auth ).toBeDefined();
	}, 20000 );
} );

/* eslint-env jest */
/**
 * AccumulatedPanel — the total-items KPI + collection progress + digest/newsletter
 * card. Reads ONLY the accumulated:view slice ({ accumulated, done, total, digest })
 * via useNodeState. The Collect / Regenerate action verbs come in as the `collect`
 * / `generate` props (the hook's awaited verbs); Copy / Create-draft act on the
 * shown digest via the `createDraft` / `markdownToContent` seams.
 */

import {
	render,
	screen,
	fireEvent,
	waitFor,
	act,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { AccumulatedViewNode } from '../../nodes/accumulated-view-node';
import { AccumulatedPanel } from '../AccumulatedPanel';

beforeEach( () => Core.reset() );

function mountSlice( slice ) {
	const node = new AccumulatedViewNode();
	node.name = 'accumulated:view';
	node.setState( 'view', slice );
	return node;
}

const DIGEST = '# Sprint digest\n\n- Big news shipped';
const COMPLETE = { accumulated: 3, done: 3, total: 3, digest: DIGEST };

// Render with sane action defaults; tests override the bits they exercise.
function renderPanel( slice, props = {} ) {
	mountSlice( slice );
	const generate = props.generate || jest.fn( () => Promise.resolve( '{}' ) );
	const collect = props.collect || jest.fn( () => Promise.resolve( '{}' ) );
	return render(
		<AccumulatedPanel
			generate={ generate }
			collect={ collect }
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
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( { collecting: 3, workers: 1 } )
				)
			),
		} );
		expect( screen.getByText( /collected 3\/3/i ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collected 0\/3/i )
		).toBeInTheDocument();
	} );

	it( 'acknowledges a successful Collect and locks the button until the cycle completes', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( { collecting: 3, workers: 2 } )
				)
			),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collecting from 2/i )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: /collecting/i } )
		).toBeDisabled();
	} );

	it( 'surfaces a no-worker error when Collect finds nothing live', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( {
						error: 'No live newspack-intelligence worker',
					} )
				)
			),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect( await screen.findByText( /no live/i ) ).toBeInTheDocument();
	} );

	it( 'surfaces an unexpected Collect response as an error', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () => Promise.resolve( 'not json' ) ),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText(
				/collection returned an unexpected response/i
			)
		).toBeInTheDocument();
	} );

	it( 'surfaces a rejected Collect verb as an error', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.reject( new Error( 'collect blew up' ) )
			),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collect blew up/i )
		).toBeInTheDocument();
	} );

	it( 'releases the Collect lock after the long safety timeout when progress never changes', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( { collecting: 3, workers: 1 } )
				)
			),
		} );
		jest.useFakeTimers();
		try {
			fireEvent.click(
				screen.getByRole( 'button', { name: /^collect$/i } )
			);
			await act( async () => {} );
			await act( async () => {
				jest.advanceTimersByTime( 180000 );
			} );
			await waitFor( () =>
				expect(
					screen.getByRole( 'button', { name: /^collect$/i } )
				).toBeEnabled()
			);
		} finally {
			jest.useRealTimers();
		}
	} );

	it( 'auto-dismisses the "Collecting from N…" note so it does not linger forever', async () => {
		renderPanel( COMPLETE, {
			collect: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( { collecting: 3, workers: 1 } )
				)
			),
		} );
		jest.useFakeTimers();
		try {
			fireEvent.click(
				screen.getByRole( 'button', { name: /^collect$/i } )
			);
			await act( async () => {} );
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
	} );
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
		renderPanel( COMPLETE, {
			generate: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( { regenerating: true, workers: 1 } )
				)
			),
		} );
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
		renderPanel( COMPLETE, {
			generate: jest.fn( () =>
				Promise.resolve(
					JSON.stringify( {
						error: 'No live newspack-intelligence worker',
					} )
				)
			),
		} );
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
		renderPanel( COMPLETE, {
			generate: jest.fn( () => Promise.resolve( 'not json' ) ),
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		expect(
			await screen.findByText(
				/regeneration returned an unexpected response/i
			)
		).toBeInTheDocument();
	} );

	it( 'surfaces a rejected Regenerate verb as an error', async () => {
		renderPanel( COMPLETE, {
			generate: jest.fn( () =>
				Promise.reject( new Error( 'generate blew up' ) )
			),
		} );
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

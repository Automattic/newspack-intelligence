/* eslint-env jest */
/**
 * PublisherInsights — the thin view over the `insights:view` model. It mounts
 * the graph (useInsightsGraph) and reads the model via useNodeState. Here we
 * inject a fake CommandClient whose `insights` reply carries a known model (now
 * including the REAL rendered `digest`) and whose `generate` reply carries a
 * fresh digest, so the mounted graph fills the view and the component renders the
 * live analytics dashboard plus the digest-driven newsletter actions.
 *
 * The "Create draft post" action is exercised through the injected `createDraft`
 * prop seam (never the real network); "Generate digest" goes through the real
 * graph against the fake client.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import {
	newMessage,
	TO,
	FROM,
	ID,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	TM_ERROR,
	Core,
} from '@newspack-nodes/runtime';

jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => true,
} ) );

import PublisherInsights from '../PublisherInsights';

// Distinct from the table titles, so a preview/copy/draft assertion can't be
// satisfied by the top-items table.
const DIGEST =
	'# Sprint digest\n\n- **Big news** shipped\n- A hot thread heated up';

const model = {
	sources: { releases: 2, community: 1 },
	top: {
		releases: [ { title: 'Big release', score: 9.5 } ],
		community: [ { title: 'Hot thread', score: 4 } ],
	},
	accumulated: 3,
	digest: DIGEST,
	// Collection complete (3/3) so the gated Generate button is enabled by default.
	done: 3,
	total: 3,
};

// A fake CommandClient whose reply payload is keyed by verb (the CI reply pivot):
// the poll's `insights` and the awaited `generate` get their own payloads.
function clientFor( payloadByVerb, replyType = TM_COMMAND | TM_RESPONSE ) {
	return {
		buildMessage( { to, verb, args = '' } ) {
			const m = newMessage();
			m[ TYPE ] = TM_COMMAND;
			m[ VALUE ] = { name: verb, arguments: args, to };
			return m;
		},
		postBatch( messages ) {
			return Promise.resolve(
				messages.map( ( m ) => {
					const reply = newMessage();
					reply[ TYPE ] = replyType;
					reply[ TO ] = m[ FROM ];
					reply[ ID ] = m[ ID ];
					reply[ VALUE ] = {
						name: m[ VALUE ]?.name,
						payload: payloadByVerb[ m[ VALUE ]?.name ] ?? null,
					};
					return reply;
				} )
			);
		},
	};
}

function clientReturning( jsonModel ) {
	return clientFor( { insights: jsonModel } );
}

function clientFailing( errorText ) {
	return clientFor(
		{ insights: errorText },
		TM_COMMAND | TM_RESPONSE | TM_ERROR
	);
}

// Render the populated dashboard and wait until the live data has arrived.
async function renderPopulated( props = {} ) {
	render(
		<PublisherInsights
			refreshMs={ 4000 }
			commandClient={ clientReturning( JSON.stringify( model ) ) }
			{ ...props }
		/>
	);
	await waitFor( () =>
		expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
	);
}

beforeEach( () => Core.reset() );

describe( 'PublisherInsights', () => {
	it( 'renders a per-source top table for each source, plus the accumulated count', async () => {
		await renderPopulated();
		// Each source gets its own ranked table + heading; items land under their source.
		expect( screen.getByText( 'Big release' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Hot thread' ) ).toBeInTheDocument();
		expect(
			screen.getByText( /Accumulated items: 3/ )
		).toBeInTheDocument();
		expect( screen.getAllByRole( 'table' ) ).toHaveLength( 2 );
		expect(
			screen.getByRole( 'heading', { name: 'releases' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'community' } )
		).toBeInTheDocument();
	} );

	it( 'shows KPI stat cards: total items, top score, and source count', async () => {
		const { container } = render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning( JSON.stringify( model ) ) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		expect( screen.getByText( /total items/i ) ).toBeInTheDocument();
		expect( screen.getByText( /top score/i ) ).toBeInTheDocument();
		expect( screen.getByText( /^sources$/i ) ).toBeInTheDocument();
		const nums = [
			...container.querySelectorAll( '.eai-insights__stat' ),
		].map(
			( card ) =>
				card.querySelector( '.eai-insights__stat-num' ).textContent
		);
		expect( nums ).toEqual( [ '3', '9.5', '2' ] );
	} );

	it( 'renders each top item with a rank and a score bar sized by score', async () => {
		const { container } = render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning( JSON.stringify( model ) ) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		// Each source's table ranks from #1 (the fixture has one item per source).
		expect( screen.getAllByText( '#1' ) ).toHaveLength( 2 );
		const bars = container.querySelectorAll( '.eai-insights__score-bar' );
		expect( bars.length ).toBe( 2 );
		// Bars are relative to the global top score (9.5), so the top item is full-width.
		expect( bars[ 0 ].style.width ).toBe( '100%' );
	} );

	it( 'renders each source as a proportion bar with an inline width style', async () => {
		const { container } = render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning( JSON.stringify( model ) ) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		const bars = container.querySelectorAll( '.eai-insights__bar-fill' );
		expect( bars.length ).toBe( 2 );
		expect( bars[ 0 ].style.width ).toContain( '66.6' );
	} );

	it( 'shows the REAL rendered digest (from the poll model) in the preview', async () => {
		await renderPopulated();
		const preview = await screen.findByTestId( 'eai-insights-preview' );
		// The digest:log markdown, NOT a rebuilt list of item titles.
		expect( preview.textContent ).toContain( 'Sprint digest' );
		expect( preview.textContent ).toContain( 'Big news' );
	} );

	it( 'regenerates the digest via the `generate` verb when "Generate digest" is clicked', async () => {
		await renderPopulated( {
			commandClient: clientFor( {
				insights: JSON.stringify( model ),
				generate: JSON.stringify( { digest: '## Regenerated brief' } ),
			} ),
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /generate digest/i } )
		);
		await waitFor( () =>
			expect(
				screen.getByTestId( 'eai-insights-preview' ).textContent
			).toContain( 'Regenerated brief' )
		);
	} );

	it( 'keeps the shown digest (and notifies) when a Generate returns empty — no wipe', async () => {
		await renderPopulated( {
			commandClient: clientFor( {
				insights: JSON.stringify( model ),
				generate: JSON.stringify( { digest: '' } ),
			} ),
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: /generate digest/i } )
		);
		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toBeInTheDocument()
		);
		// The previously-shown durable digest is NOT wiped by an empty recompose.
		expect(
			screen.getByTestId( 'eai-insights-preview' ).textContent
		).toContain( 'Sprint digest' );
		// And the actions stay usable.
		expect(
			screen.getByRole( 'button', { name: /copy markdown/i } )
		).not.toBeDisabled();
	} );

	it( 'copies the REAL digest markdown to the clipboard when "Copy markdown" is clicked', async () => {
		const writeText = jest.fn( () => Promise.resolve() );
		Object.assign( window.navigator, { clipboard: { writeText } } );
		await renderPopulated();
		fireEvent.click(
			screen.getByRole( 'button', { name: /copy markdown/i } )
		);
		expect( writeText ).toHaveBeenCalledTimes( 1 );
		expect( writeText.mock.calls[ 0 ][ 0 ] ).toContain( 'Sprint digest' );
		expect( await screen.findByText( /copied/i ) ).toBeInTheDocument();
	} );

	it( 'creates a draft post as native blocks and shows an "Edit draft" link on success', async () => {
		const createDraft = jest.fn( () => Promise.resolve( { id: 42 } ) );
		await renderPopulated( { createDraft } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		await waitFor( () => expect( createDraft ).toHaveBeenCalledTimes( 1 ) );
		const arg = createDraft.mock.calls[ 0 ][ 0 ];
		expect( arg.title.length ).toBeGreaterThan( 0 );
		// Block-delimited markup from the digest markdown, not a rebuilt item list.
		expect( arg.content ).toContain( '<!-- wp:' );
		expect( arg.content ).toContain( '<strong>Big news</strong>' );
		const link = await screen.findByRole( 'link', { name: /edit draft/i } );
		expect( link.getAttribute( 'href' ) ).toContain( 'post=42' );
		expect( link.getAttribute( 'href' ) ).toContain( 'action=edit' );
	} );

	it( 'shows an inline error notice when creating a draft post fails', async () => {
		const createDraft = jest.fn( () =>
			Promise.reject( new Error( 'rest blew up' ) )
		);
		await renderPopulated( { createDraft } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		await waitFor( () => expect( createDraft ).toHaveBeenCalledTimes( 1 ) );
		expect(
			await screen.findByText( /rest blew up/i )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: /edit draft/i } )
		).not.toBeInTheDocument();
	} );

	it( 'does not crash or show "Copied" when the clipboard API is unavailable', async () => {
		const original = window.navigator.clipboard;
		Object.assign( window.navigator, { clipboard: undefined } );
		await renderPopulated();
		expect( () =>
			fireEvent.click(
				screen.getByRole( 'button', { name: /copy markdown/i } )
			)
		).not.toThrow();
		expect( screen.queryByText( /copied/i ) ).not.toBeInTheDocument();
		Object.assign( window.navigator, { clipboard: original } );
	} );

	it( 'shows an error (not a dead post=undefined link) when the draft reply has no id', async () => {
		const createDraft = jest.fn( () => Promise.resolve( {} ) );
		await renderPopulated( { createDraft } );
		fireEvent.click(
			screen.getByRole( 'button', { name: /create draft post/i } )
		);
		await waitFor( () => expect( createDraft ).toHaveBeenCalledTimes( 1 ) );
		expect( await screen.findByRole( 'alert' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: /edit draft/i } )
		).not.toBeInTheDocument();
	} );

	it( 'shows an empty state (no table) until the pipeline has items', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning(
					JSON.stringify( {
						sources: {},
						top: [],
						accumulated: 0,
						digest: '',
					} )
				) }
			/>
		);
		await waitFor( () =>
			expect(
				screen.getByText( /no scored items yet/i )
			).toBeInTheDocument()
		);
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );

	it( 'still offers Collect in the empty state so a fresh pipeline can be driven from the UI', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning(
					JSON.stringify( {
						sources: {},
						top: [],
						accumulated: 0,
						digest: '',
					} )
				) }
			/>
		);
		await waitFor( () =>
			expect(
				screen.getByText( /no scored items yet/i )
			).toBeInTheDocument()
		);
		// Collect drives collection — it MUST be reachable when there is nothing
		// yet (that is exactly when you need it). The downstream actions
		// (Generate/Copy/Create) only appear once there is data to act on.
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeEnabled();
		expect(
			screen.queryByRole( 'button', { name: /generate digest/i } )
		).not.toBeInTheDocument();
	} );

	it( 'surfaces a failed poll as an error notice', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientFailing( 'snapshot read failed' ) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByRole( 'alert' ) ).toBeInTheDocument()
		);
		expect( screen.getByRole( 'alert' ).textContent ).toMatch(
			/snapshot read failed/
		);
	} );

	it( 'shows collection progress as X/total', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning(
					JSON.stringify( { ...model, done: 2, total: 3 } )
				) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( /collected 2\/3/i ) ).toBeInTheDocument()
		);
	} );

	it( 'disables Generate until every source has reported done', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning(
					JSON.stringify( { ...model, done: 1, total: 3 } )
				) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		expect(
			screen.getByRole( 'button', { name: /generate digest/i } )
		).toBeDisabled();
	} );

	it( 'enables Generate once collection is complete (done >= total)', async () => {
		await renderPopulated(); // model is 3/3
		expect(
			screen.getByRole( 'button', { name: /generate digest/i } )
		).not.toBeDisabled();
	} );

	it( 'surfaces a no-worker error when Collect finds nothing live', async () => {
		await renderPopulated( {
			commandClient: clientFor( {
				insights: JSON.stringify( model ),
				collect: JSON.stringify( {
					error: 'No live newspack-ai-newsletter worker',
				} ),
			} ),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect( await screen.findByText( /no live/i ) ).toBeInTheDocument();
	} );

	it( 'gates Collect: disabled mid-collection (1/3)', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientReturning(
					JSON.stringify( { ...model, done: 1, total: 3 } )
				) }
			/>
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeDisabled();
	} );

	it( 'enables Collect when collection is complete (3/3)', async () => {
		await renderPopulated(); // model is 3/3
		expect(
			screen.getByRole( 'button', { name: /^collect$/i } )
		).toBeEnabled();
	} );

	it( 'shows 0/total immediately on click even when the prior cycle was complete', async () => {
		await renderPopulated( {
			commandClient: clientFor( {
				insights: JSON.stringify( model ),
				collect: JSON.stringify( { collecting: 3, workers: 1 } ),
			} ),
		} );
		expect( screen.getByText( /collected 3\/3/i ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collected 0\/3/i )
		).toBeInTheDocument();
	} );

	it( 'acknowledges a successful Collect and locks the button until the cycle completes', async () => {
		await renderPopulated( {
			commandClient: clientFor( {
				insights: JSON.stringify( model ),
				collect: JSON.stringify( { collecting: 3, workers: 2 } ),
			} ),
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect(
			await screen.findByText( /collecting from 2/i )
		).toBeInTheDocument();
		// Stays locked after the (fast) verb returns — no double-fire while in flight.
		expect(
			screen.getByRole( 'button', { name: /collecting/i } )
		).toBeDisabled();
	} );

	it( 'surfaces a Collect error in the empty state', async () => {
		render(
			<PublisherInsights
				refreshMs={ 4000 }
				commandClient={ clientFor( {
					insights: JSON.stringify( {
						sources: {},
						top: [],
						accumulated: 0,
						digest: '',
					} ),
					collect: JSON.stringify( {
						error: 'No live newspack-ai-newsletter worker',
					} ),
				} ) }
			/>
		);
		await waitFor( () =>
			expect(
				screen.getByText( /no scored items yet/i )
			).toBeInTheDocument()
		);
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect( await screen.findByText( /no live/i ) ).toBeInTheDocument();
	} );
} );

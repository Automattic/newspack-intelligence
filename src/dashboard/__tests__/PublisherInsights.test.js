/* eslint-env jest */
/**
 * PublisherInsights — the orchestrator that mounts the GENUINE node graph
 * (useInsightsGraph: Timer → Tee → three Fetchers, ONE batched POST per tick) and
 * renders the three per-slice widgets (SourceCounts / TopTable / AccumulatedPanel),
 * each reading ITS OWN view node via useNodeState. No god view node, no god
 * `insights` command. Here we inject a fake CommandClient whose three slice replies
 * carry a known model so the graph fills the three views and the dashboard renders.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { act } from 'react';
import {
	newMessage,
	ID,
	TO,
	FROM,
	VALUE,
	TYPE,
	TM_COMMAND,
	TM_RESPONSE,
	Core,
} from '@newspack-nodes/runtime';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@newspack-nodes/shared/hooks/usePageVisibility', () => ( {
	__esModule: true,
	default: () => true,
} ) );

import PublisherInsights from '../PublisherInsights';

const ROUTER = '_router';
const DIGEST = '# Sprint digest\n\n- Big news shipped';

// A fake CommandClient: postBatch echoes a per-verb reply pivoted via FROM.
function makeClient( payloadByVerb ) {
	return {
		postBatch( messages ) {
			return Promise.resolve(
				messages.map( ( m ) => {
					const reply = newMessage();
					reply[ TYPE ] = TM_COMMAND | TM_RESPONSE;
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

const populated = {
	counts: JSON.stringify( { sources: { github: 2, linear: 1 } } ),
	top: JSON.stringify( {
		top: {
			github: [ { title: 'Big release', score: 9.5 } ],
			linear: [ { title: 'Hot thread', score: 4 } ],
		},
	} ),
	accumulated: JSON.stringify( {
		accumulated: 3,
		done: 3,
		total: 3,
		digest: DIGEST,
	} ),
};

beforeEach( () => {
	Core.reset();
	apiFetch.mockReset();
} );

// Render the dashboard, tick the router to fill the views, await replies.
async function renderAndTick( client, props = {} ) {
	const utils = render(
		<PublisherInsights commandClient={ client } { ...props } />
	);
	await act( async () => {
		Core.node( ROUTER ).fireCb();
	} );
	return utils;
}

describe( 'PublisherInsights — render', () => {
	it( 'renders the heading', async () => {
		await act( async () => {
			render(
				<PublisherInsights commandClient={ makeClient( populated ) } />
			);
		} );
		expect(
			screen.getByRole( 'heading', { name: 'Publisher Insights' } )
		).toBeInTheDocument();
	} );

	it( 'fills all three slice widgets from their own view nodes after a tick', async () => {
		const { container } = await renderAndTick( makeClient( populated ) );
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		// counts → SourceCounts proportion bars.
		const barNames = [
			...container.querySelectorAll( '.eai-insights__source-name' ),
		].map( ( el ) => el.textContent );
		expect( barNames ).toEqual( [ 'github', 'linear' ] );
		// top → TopTable per-source ranked tables.
		expect( screen.getByText( 'Hot thread' ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'github' } )
		).toBeInTheDocument();
		// accumulated → AccumulatedPanel digest preview.
		expect(
			screen.getByTestId( 'eai-insights-preview' ).textContent
		).toContain( 'Sprint digest' );
	} );

	it( 'lays the widgets out in the two-column grid', async () => {
		const { container } = await renderAndTick( makeClient( populated ) );
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		const layout = container.querySelector( '.eai-insights__layout' );
		expect( layout ).toBeInTheDocument();
		const columns = layout.querySelectorAll(
			':scope > .eai-insights__side'
		);
		expect( columns ).toHaveLength( 2 );
		// Left column: digest over counts; the tall Top table gets the right.
		expect(
			columns[ 0 ].querySelector( '.eai-insights__draft' )
		).toBeInTheDocument();
		expect(
			columns[ 0 ].querySelector( '.eai-insights__sources' )
		).toBeInTheDocument();
		expect(
			columns[ 1 ].querySelector( '.eai-insights__top' )
		).toBeInTheDocument();
	} );

	it( 'shows per-slice empty states from an empty server reply', async () => {
		await renderAndTick(
			makeClient( {
				counts: JSON.stringify( { sources: {} } ),
				top: JSON.stringify( { top: {} } ),
				accumulated: JSON.stringify( {
					accumulated: 0,
					done: 0,
					total: 0,
					digest: '',
				} ),
			} )
		);
		await waitFor( () =>
			expect(
				screen.getByText( /no scored items yet/i )
			).toBeInTheDocument()
		);
		expect( screen.queryByRole( 'table' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'PublisherInsights — actions flow through the real graph', () => {
	it( 'asks the worker to regenerate via the graph and acknowledges', async () => {
		await renderAndTick(
			makeClient( {
				...populated,
				generate: JSON.stringify( { regenerating: true, workers: 1 } ),
			} )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: /regenerate digest/i } )
		);
		await waitFor( () =>
			expect( screen.getByText( /regenerating/i ) ).toBeInTheDocument()
		);
	} );

	it( 'drives Collect via the graph and surfaces a no-worker error', async () => {
		await renderAndTick(
			makeClient( {
				...populated,
				collect: JSON.stringify( {
					error: 'No live newspack-intelligence worker',
				} ),
			} )
		);
		await waitFor( () =>
			expect( screen.getByText( 'Big release' ) ).toBeInTheDocument()
		);
		fireEvent.click( screen.getByRole( 'button', { name: /^collect$/i } ) );
		expect( await screen.findByText( /no live/i ) ).toBeInTheDocument();
	} );
} );

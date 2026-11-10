/* eslint-env jest */
/**
 * The `health` block draws the strip's details panel inline: its summary
 * rows, or with `detail` "sources" every source too, with the fixes for an
 * operator alone.
 */

import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { HEALTHY, okSource } from '../../__tests__/healthFixture';
import { pageData } from '../../views/__tests__/fixtures';
import { CanManage } from '../../components/Answer';
import { BLOCK_VIEWS } from '..';

const { View, Controls } = BLOCK_VIEWS[ 'newspack-intelligence/health' ];

/** An idle source a reader reads without its credential. */
const IDLE = { ...okSource( 'feed' ), state: 'idle' };

let wire;
let health;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
	health = JSON.parse( JSON.stringify( HEALTHY ) );
	wire.replies.health = ( [ what ] ) => health[ what ];
} );

/**
 * The block, for a reader or an operator.
 *
 * @param {string}  detail    Its `detail` attribute.
 * @param {boolean} canManage Whether the reader is an operator.
 */
const renderBlock = ( detail, canManage ) =>
	render(
		<CanManage.Provider value={ canManage }>
			<View attributes={ { detail } } />
		</CanManage.Provider>
	);

const panel = () => screen.getByRole( 'region', { name: 'Collection' } );

/**
 * The panel's row labels.
 *
 * @return {string[]} Each row's label.
 */
const labels = () =>
	within( panel() )
		.getAllByRole( 'listitem' )
		.map( ( item ) => item.querySelector( 'strong' )?.textContent );

describe( 'the health block', () => {
	it( 'lists each source inline with detail "sources", a reader seeing no fix', async () => {
		health.sources.sources[ 1 ] = IDLE;
		health.backlog = {
			reason: 'no_live_entity',
			held: 12,
			since: null,
			dropped: null,
		};
		renderBlock( 'sources', false );
		await waitFor( () =>
			expect( labels() ).toEqual(
				expect.arrayContaining( [ 'Code host', 'Web feed', 'news' ] )
			)
		);
		expect(
			within( panel() ).getByText(
				'This source has no credential to fetch with.'
			)
		).toBeInTheDocument();
		expect(
			within( panel() ).queryByRole( 'button', {
				name: 'Seed the ontology',
			} )
		).toBeNull();
		expect( within( panel() ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'leaves the sources out of its summary', async () => {
		renderBlock( 'summary', false );
		await waitFor( () =>
			expect( labels() ).toEqual( [
				'Gathering',
				'Pairing items',
				'Digests',
			] )
		);
	} );

	it( 'offers an operator the fixes', async () => {
		health.backlog = {
			reason: 'no_live_entity',
			held: 12,
			since: null,
			dropped: null,
		};
		renderBlock( 'summary', true );
		expect(
			await screen.findByRole( 'button', { name: 'Seed the ontology' } )
		).toBeInTheDocument();
	} );

	it( 'sets what it shows from its control', () => {
		const setAttributes = jest.fn();
		render(
			<Controls
				attributes={ { detail: 'summary' } }
				setAttributes={ setAttributes }
			/>
		);
		const show = screen.getByLabelText( 'Show' );
		expect( [ ...show.options ].map( ( one ) => one.textContent ) ).toEqual(
			[ 'A summary', 'Every source' ]
		);
		fireEvent.change( show, { target: { value: 'sources' } } );
		expect( setAttributes ).toHaveBeenCalledWith( { detail: 'sources' } );
	} );
} );

/* eslint-env jest */
/**
 * HowRead lays out how one item was read, from the `trace` read, as one
 * line in plain words a step, in the order the pipeline took them, each
 * with who took it and when: its record, each link with its method and
 * state, its extraction or the stand-in written in its place, its kind
 * check and its cluster. No key, actor string or stored value reaches the
 * screen; every stored key reads through the vocabulary.
 */

import { render, screen, within } from '@testing-library/react';
import { Component } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { CanManage } from '../Answer';
import HowRead from '../HowRead';
import { pageData } from '../../views/__tests__/fixtures';

const ITEM = {
	item_key: 'linear:harbor-renewal-418',
	title: 'Harbor Ledger asks for a renewal quote',
	url: 'https://tracker.example/harbor-renewal-418',
};

/** 2026-10-13 12:10 UTC, when the item was retrieved. */
const RETRIEVED = Date.UTC( 2026, 9, 13, 12, 10 ) / 1000;

/** Noon UTC on 2026-11-20, long enough after for each step to read by date. */
const NOW = Date.UTC( 2026, 10, 20, 12 ) / 1000;

/**
 * The trace of an item, as `Graph_Trace::trace()` answers it.
 *
 * @param {Object[]} steps Its steps.
 * @return {Object} The answer.
 */
const traced = ( steps ) => ( {
	item_key: ITEM.item_key,
	title: ITEM.title,
	url: ITEM.url,
	steps,
	available: true,
} );

const RECORDED = {
	step: 'recorded',
	at: RETRIEVED,
	actor: 'machine:source:linear@9.4.1',
	source: 'linear',
	tier: 'internal',
};

const RECORD = [
	RECORDED,
	{
		step: 'linked',
		at: RETRIEVED + 40,
		actor: 'machine:resolve@9.4.1',
		entity_key: 'organization:harbor-ledger',
		name: 'Harbor Ledger',
		role: 'subject',
		method: 'identifier',
		confidence: 1,
		state: 'confirmed',
		judged: {
			state: 'confirmed',
			actor: 'user:41',
			at: RETRIEVED + 7200,
		},
	},
	{
		step: 'linked',
		at: RETRIEVED + 95,
		actor: 'machine:resolve@9.4.1',
		entity_key: 'organization:prairie-signal',
		name: 'Prairie Signal',
		role: 'mention',
		method: 'ner_fuzzy',
		confidence: 0.6,
		state: 'proposed',
		judged: null,
	},
	{
		step: 'linked',
		at: RETRIEVED + 96,
		actor: 'machine:resolve@9.4.1',
		entity_key: 'organization:kestrel-wire',
		name: 'Kestrel Wire',
		role: 'mention',
		method: 'alias',
		confidence: 1,
		state: 'rejected',
		judged: {
			state: 'rejected',
			actor: 'agent:41:triage-bot',
			at: RETRIEVED + 9000,
		},
	},
	{
		step: 'linked',
		at: RETRIEVED + 97,
		actor: 'machine:resolve@9.4.1',
		entity_key: 'organization:gull-press',
		name: 'Gull Press',
		role: 'publisher',
		method: 'domain_stem',
		confidence: 0.9,
		state: 'asserted',
		judged: null,
	},
	{
		step: 'extracted',
		at: RETRIEVED + 310,
		actor: 'machine:extract@9.4.1',
		kind: 'churn_signal',
		theme: 'renewal at risk',
		rank_reason: 'A client is weighing a cheaper host before its renewal.',
	},
	{
		step: 'checked',
		at: RETRIEVED + 330,
		actor: 'machine:extract@9.4.1',
		question: 'fits_kind',
		kind: 'churn_signal',
		verdict: 'passed',
	},
	{
		step: 'clustered',
		at: RETRIEVED + 900,
		actor: 'machine:dedup@9.4.1',
		canonical: true,
		copies: 2,
	},
];

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	jest.spyOn( Core, 'now' ).mockReturnValue( NOW );
	wire = intelWire();
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Renders the drawer over a trace answering `steps`, and waits for its
 * steps.
 *
 * @param {Object[]} steps The trace's steps.
 * @return {Promise<HTMLElement[]>} Each step's line.
 */
const renderSteps = async ( steps ) => {
	wire.replies.trace = traced( steps );
	render( <HowRead item={ ITEM } onClose={ jest.fn() } /> );
	await screen.findByRole( 'list' );
	return within( screen.getByRole( 'list' ) ).getAllByRole( 'listitem' );
};

/**
 * What a step's line says, and who took it and when, apart.
 *
 * @param {HTMLElement} step A step's line.
 * @return {{said: string, by: string}} Its parts.
 */
const partsOf = ( step ) => ( {
	said: step.querySelector( '.newspack-intelligence__step-said' ).textContent,
	by: step.querySelector( '.newspack-intelligence__step-by' ).textContent,
} );

describe( 'HowRead', () => {
	it( 'reads an item whose key holds a slash, two drawers apart', async () => {
		const github = {
			...ITEM,
			item_key: 'github:example/plugin#912',
			title: 'Plugin release 9.12',
		};
		wire.replies.trace = ( [ key ] ) => ( {
			...traced( [ RECORDED ] ),
			title: key,
		} );
		render(
			<>
				<HowRead item={ github } onClose={ jest.fn() } />
				<HowRead item={ ITEM } onClose={ jest.fn() } />
			</>
		);
		expect( await screen.findAllByRole( 'list' ) ).toHaveLength( 2 );
		expect( wire.sent( 'trace' ) ).toEqual(
			expect.arrayContaining( [
				[ 'github:example/plugin#912' ],
				[ ITEM.item_key ],
			] )
		);
	} );

	it( 'asks how the item was read', async () => {
		await renderSteps( [ RECORDED ] );
		expect( wire.sent( 'trace' ) ).toEqual( [ [ ITEM.item_key ] ] );
	} );

	it( 'lays each step out in order, in plain words, with who took it and when', async () => {
		const steps = await renderSteps( RECORD );
		expect( steps.map( partsOf ) ).toEqual( [
			{
				said: 'Recorded from Tracker; marked Staff',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Linked to Harbor Ledger by its handle, confirmed by a curator',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Suggested a link to Prairie Signal by a lookalike, not yet reviewed',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Linked to Kestrel Wire by another name, rejected by an agent',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Linked to Gull Press by its web address',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Read as Wobbling, in the theme “Renewal at risk”. A client is weighing a cheaper host before its renewal.',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'Checked whether it fits Wobbling: yes',
				by: 'By the pipeline, 13 Oct',
			},
			{
				said: 'The main copy, merged with 2 others',
				by: 'By the pipeline, 13 Oct',
			},
		] );
	} );

	it( 'shows no key, actor or stored value on screen', async () => {
		await renderSteps( RECORD );
		const shown = document.body.textContent;
		expect( shown ).not.toMatch( /[a-z]+:[a-z0-9]/i );
		expect( shown ).not.toMatch( /_/ );
		for ( const stored of [
			'linear',
			'internal',
			'identifier',
			'ner_fuzzy',
			'churn_signal',
			'fits_kind',
			'passed',
			'machine',
			'dedup',
			'@9.4.1',
		] ) {
			expect( shown ).not.toContain( stored );
		}
	} );

	it( 'reads a gated item as gated', async () => {
		const steps = await renderSteps( [
			RECORDED,
			{
				step: 'gated',
				at: RETRIEVED + 20,
				actor: 'machine:extract-gated@9.4.1',
				kind: 'other',
				theme: null,
				rank_reason: '',
			},
		] );
		expect( partsOf( steps[ 1 ] ).said ).toBe(
			'Gated: about its own publisher’s journalism, so not extracted'
		);
	} );

	it( 'reads a heuristic stand-in as a fallback the model never read', async () => {
		const steps = await renderSteps( [
			RECORDED,
			{
				step: 'heuristic',
				at: RETRIEVED + 20,
				actor: 'machine:extract-heuristic@9.4.1',
				kind: 'other',
				theme: null,
				rank_reason: '',
			},
		] );
		expect( partsOf( steps[ 1 ] ).said ).toBe(
			'Not read by the model: a fallback summary'
		);
	} );

	it( 'reads an extraction with no theme and no reason', async () => {
		const steps = await renderSteps( [
			RECORDED,
			{
				step: 'extracted',
				at: RETRIEVED + 310,
				actor: 'machine:extract@9.4.1',
				kind: 'award',
				theme: null,
				rank_reason: '',
			},
		] );
		expect( partsOf( steps[ 1 ] ).said ).toBe(
			'Read as Prize. No reason recorded.'
		);
	} );

	it( 'reads a failed kind check as no', async () => {
		const steps = await renderSteps( [
			RECORDED,
			{
				step: 'checked',
				at: RETRIEVED + 330,
				actor: 'machine:extract@9.4.1',
				question: 'fits_kind',
				kind: 'launch',
				verdict: 'failed',
			},
		] );
		expect( partsOf( steps[ 1 ] ).said ).toBe(
			'Checked whether it fits Going live: no'
		);
	} );

	it.each( [
		[ true, 1, 'The main copy, merged with 1 other' ],
		[ true, 0, 'The main copy of its story' ],
		[ false, 3, 'A copy, merged with 3 others' ],
		[ false, 0, 'A copy of another item' ],
	] )(
		'reads a cluster place, main %s with %d readable copies, as "%s"',
		async ( canonical, copies, said ) => {
			const steps = await renderSteps( [
				RECORDED,
				{
					step: 'clustered',
					at: RETRIEVED + 900,
					actor: 'machine:dedup@9.4.1',
					canonical,
					copies,
				},
			] );
			expect( partsOf( steps[ 1 ] ).said ).toBe( said );
		}
	);

	it( 'names a curator and an agent who took a step', async () => {
		const steps = await renderSteps( [
			{ ...RECORDED, actor: 'user:41' },
			{ ...RECORDED, actor: 'agent:41:triage-bot' },
		] );
		expect( steps.map( ( step ) => partsOf( step ).by ) ).toEqual( [
			'By a curator, 13 Oct',
			'By an agent, 13 Oct',
		] );
	} );

	it( 'stands placeholder lines in while the record is owed', () => {
		wire.replies.trace = () => new Promise( () => {} );
		render( <HowRead item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			screen.getByRole( 'status', { name: 'Loading' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'list' ) ).toBeNull();
	} );

	it( 'says when the store would not open', async () => {
		wire.replies.trace = { ...traced( [] ), available: false };
		render( <HowRead item={ ITEM } onClose={ jest.fn() } /> );
		expect(
			await screen.findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
	} );

	it( 'says a refused read could not be read, its words for an operator', async () => {
		wire.replies.trace = new Error( `no item: ${ ITEM.item_key }` );
		render(
			<CanManage.Provider value={ true }>
				<HowRead item={ ITEM } onClose={ jest.fn() } />
			</CanManage.Provider>
		);
		expect(
			await screen.findByText(
				'This could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText( `no item: ${ ITEM.item_key }` )
		).toBeInTheDocument();
	} );

	it( 'names the item it reads in its heading region', async () => {
		await renderSteps( [ RECORDED ] );
		expect(
			screen.getByRole( 'region', { name: 'How this was read' } )
		).toHaveTextContent( ITEM.title );
	} );

	it.each( [
		[ 'a step', { ...RECORDED, step: 'archived' }, 'the step "archived"' ],
		[
			'a link state',
			{ ...RECORD[ 2 ], state: 'disputed' },
			'the link state "disputed"',
		],
		[
			'a check',
			{ ...RECORD[ 6 ], question: 'about_publisher' },
			'the check "about_publisher"',
		],
		[
			'an actor',
			{ ...RECORDED, actor: 'robot:7' },
			'the actor "robot:7"',
		],
	] )( 'fails loud on %s it has no words for', async ( _, step, named ) => {
		// React reports the throw it hands the boundary below.
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		wire.replies.trace = traced( [ step ] );
		render(
			<Caught>
				<HowRead item={ ITEM } onClose={ jest.fn() } />
			</Caught>
		);
		expect(
			await screen.findByText( `no words for ${ named }` )
		).toBeInTheDocument();
	} );
} );

/** Shows the message of what its children threw, in place of them. */
class Caught extends Component {
	state = { error: null };

	static getDerivedStateFromError( error ) {
		return { error };
	}

	render() {
		return this.state.error ? (
			<p>{ this.state.error.message }</p>
		) : (
			this.props.children
		);
	}
}

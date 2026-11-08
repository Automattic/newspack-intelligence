/* eslint-env jest */
/**
 * AskBox heads the Briefing and Search: it sends `ask` with the question,
 * reads the caller's ask back through `ask_result` on the Router's tick
 * while it is owed its answer, and shows the answer's sentences, each with
 * its citations, above the items they cite, or why there is none.
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useState } from '@wordpress/element';
import { Core } from '@newspack-nodes/runtime';
import { steppedClock } from '@newspack-nodes/shared/test-utils/steppedClock';
import { intelWire } from '../../__tests__/intelWire';
import AskBox from '../AskBox';

const KEY = '5d1e9a7c3b2f4086';

const PLACEHOLDER = 'Ask about clients, the industry or Newspack…';

const WAITING = {
	ask_key: KEY,
	state: 'asked',
	reason: '',
	found: 0,
	dropped: 0,
	sentences: [],
	items: [],
	searches: [],
	wait_s: 900,
	available: true,
};

const ITEMS = [
	{
		item_key: 'github:quillsheet-launch',
		title: 'Acme Quillsheet launches a newsletter tool',
		url: 'https://quillsheet.example/launch',
		source: 'github',
		tier: 'internal',
		item_ts: 1792497600,
		summary: 'Quillsheet shipped a newsletter tool.',
		excerpt: '',
		kind: 'launch',
		entities: [],
	},
	{
		item_key: 'feed:inkfold-award',
		title: 'Inkfold Review wins a regional prize',
		url: 'https://inkfold.example/award',
		source: 'feed',
		tier: 'public',
		item_ts: 1792411200,
		summary: 'Inkfold took first place.',
		excerpt: '',
		kind: 'award',
		entities: [],
	},
];

const ANSWERED = {
	...WAITING,
	state: 'answered',
	found: 6,
	dropped: 1,
	sentences: [
		{ text: 'Quillsheet launched a newsletter tool.', cites: [ 1 ] },
		{ text: 'Both newsrooms had a strong month.', cites: [ 1, 2 ] },
	],
	items: ITEMS,
	searches: [
		{ kinds: [ 'launch', 'award' ], roles: [ 'client' ], days: 30 },
		{ words: 'quillsheet', entity: 'Acme Quillsheet' },
	],
};

/**
 * The box as a view mounts it, the ask held above it as the page holds it.
 *
 * @return {import('react').ReactElement} The box.
 */
function Held() {
	const [ asked, setAsked ] = useState( null );
	return (
		<AskBox
			asked={ asked }
			onAsked={ setAsked }
			onOpenEntity={ jest.fn() }
		/>
	);
}

let wire;
let clock;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = { restUrl: '/wp-json/', nonce: 'NONCE' };
	clock = steppedClock();
	wire = intelWire();
	wire.replies.ask = { ask_key: KEY };
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Type a question and send it.
 *
 * @param {string} question The question.
 */
function ask( question ) {
	fireEvent.change( screen.getByPlaceholderText( PLACEHOLDER ), {
		target: { value: question },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );
}

describe( 'AskBox', () => {
	it( 'sends the question, waits while it is owed, then shows the cited answer', async () => {
		let answered = false;
		wire.replies.ask_result = () => ( answered ? ANSWERED : WAITING );
		render( <Held /> );

		ask( 'What did clients launch this month?' );

		await waitFor( () =>
			expect( wire.sent( 'ask' ) ).toEqual( [
				[ '--question=What did clients launch this month?' ],
			] )
		);
		await waitFor( () =>
			expect( wire.sent( 'ask_result' ) ).toEqual( [
				[ `--key=${ KEY }` ],
			] )
		);
		expect(
			await screen.findByText( 'Working on it…' )
		).toBeInTheDocument();

		answered = true;
		await clock.tick( 5 );

		expect(
			await screen.findByText( 'Quillsheet launched a newsletter tool.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Both newsrooms had a strong month.' )
				.parentElement
		).toHaveTextContent( 'Both newsrooms had a strong month. [1][2]' );
		expect(
			screen.getAllByRole( 'link' ).map( ( link ) => link.textContent )
		).toEqual( [
			'Acme Quillsheet launches a newsletter tool',
			'Inkfold Review wins a regional prize',
		] );
		expect(
			screen.getByText(
				'1 sentence was left out for citing nothing it was given.'
			)
		).toBeInTheDocument();

		expect(
			screen.getByText(
				'Searched: kinds launch, award, roles client, the last 30 days; “quillsheet”, about Acme Quillsheet'
			)
		).toBeInTheDocument();

		const polls = wire.sent( 'ask_result' ).length;
		await clock.tick( 5 );
		await clock.tick( 5 );
		expect( wire.sent( 'ask_result' ) ).toHaveLength( polls );
	} );

	it( 'says the graph could not be opened, and stops asking', async () => {
		wire.replies.ask_result = { ...WAITING, available: false };
		render( <Held /> );

		ask( 'Who won?' );

		expect(
			await screen.findByText(
				'The intelligence graph could not be opened, so the answer was not read.'
			)
		).toBeInTheDocument();
		const polls = wire.sent( 'ask_result' ).length;
		await clock.tick( 5 );
		await clock.tick( 5 );
		expect( wire.sent( 'ask_result' ) ).toHaveLength( polls );
	} );

	it( 'stops asking once the ask has waited as long as the server allows', async () => {
		wire.replies.ask_result = { ...WAITING, wait_s: 120 };
		render( <Held /> );

		ask( 'Who won?' );

		expect(
			await screen.findByText( 'Working on it…' )
		).toBeInTheDocument();
		await clock.tick( 5 );
		expect( screen.getByText( 'Working on it…' ) ).toBeInTheDocument();
		await clock.tick( 120 );
		expect(
			await screen.findByText(
				'No answer came back within 2 minutes; ask again.'
			)
		).toBeInTheDocument();
		const polls = wire.sent( 'ask_result' ).length;
		await clock.tick( 5 );
		await clock.tick( 5 );
		expect( wire.sent( 'ask_result' ) ).toHaveLength( polls );
	} );

	it( 'says an answer is withheld once its items cannot be read', async () => {
		wire.replies.ask_result = {
			...WAITING,
			state: 'withheld',
			reason: 'the answer was written from items you can no longer read',
		};
		render( <Held /> );

		ask( 'Who churned?' );

		expect(
			await screen.findByText(
				'No answer: the answer was written from items you can no longer read. Ask again.'
			)
		).toBeInTheDocument();
	} );

	it( 'says why an ask failed', async () => {
		wire.replies.ask_result = {
			...WAITING,
			state: 'failed',
			reason: 'the model call failed',
		};
		render( <Held /> );

		ask( 'Who churned?' );

		expect(
			await screen.findByText(
				'No answer: the model call failed. Ask again.'
			)
		).toBeInTheDocument();
	} );

	it( 'says when nothing the reader may read matched', async () => {
		wire.replies.ask_result = { ...WAITING, state: 'answered' };
		render( <Held /> );

		ask( 'Any lighthouse news?' );

		expect(
			await screen.findByText(
				'Nothing you can read matched this question.'
			)
		).toBeInTheDocument();
	} );

	it( 'says when the answer cited none of the items it read', async () => {
		wire.replies.ask_result = {
			...WAITING,
			state: 'answered',
			found: 4,
			dropped: 3,
		};
		render( <Held /> );

		ask( 'Any news?' );

		expect(
			await screen.findByText(
				'The answer cited none of the 4 items it read, so nothing is shown.'
			)
		).toBeInTheDocument();
	} );

	it( 'sends nothing for a blank question', () => {
		render( <Held /> );

		ask( '   ' );

		expect( screen.getByRole( 'button', { name: 'Ask' } ) ).toBeDisabled();
		expect( wire.sent( 'ask' ) ).toEqual( [] );
	} );

	it( 'shows a refused ask', async () => {
		wire.replies.ask = new Error( 'question runs past 1000 bytes' );
		render( <Held /> );

		ask( 'A long question' );

		expect(
			await screen.findByText( /question runs past 1000 bytes/ )
		).toBeInTheDocument();
		expect( wire.sent( 'ask_result' ) ).toEqual( [] );
	} );
} );

/* eslint-env jest */
/**
 * AskBox is the `ask` block's component, heading the Briefing and Search: it
 * sends `ask` with the question alone, reads the caller's ask back through
 * `ask_result` on the Router's tick while it is owed its answer, shows each
 * step the ask has taken, and shows the answer's sentences, each marker
 * resolving to a listed source, above those sources, what it searched, and
 * a fixed footer; or which of its empty cases it is.
 */

import {
	act,
	render,
	screen,
	fireEvent,
	waitFor,
	within,
} from '@testing-library/react';
import { useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { Core } from '@newspack-nodes/runtime';
import { steppedClock } from '@newspack-nodes/shared/test-utils/steppedClock';
import { intelWire } from '../../__tests__/intelWire';
import AskBox from '../AskBox';
import { daysSince } from '../lensDraft';
import { pageData } from '../../views/__tests__/fixtures';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

const KEY = '5d1e9a7c3b2f4086';

/** An `ask` block's attributes, each distinct from its default. */
const ATTRIBUTES = {
	label: 'Put it to the desk',
	placeholder: 'Ask the desk…',
	examples: [ 'Who launched?', 'Which members wobbled?' ],
};

const WAITING = {
	ask_key: KEY,
	state: 'asked',
	reason: '',
	found: 0,
	dropped: 0,
	hidden: 0,
	sentences: [],
	items: [],
	searches: [],
	wait_s: 900,
	available: true,
};

const SEARCHES = [
	{ words: 'sponsor', days: 30 },
	{ kinds: [ 'launch' ], roles: [ 'client' ], days: 14 },
];

/**
 * One cited item, as `search` answers it.
 *
 * @param {string} key   Its key.
 * @param {string} title Its title.
 * @param {string} url   Its URL.
 * @return {Object} The item.
 */
const item = ( key, title, url ) => ( {
	item_key: key,
	title,
	url,
	source: 'github',
	tier: 'internal',
	item_ts: 1792497600,
	summary: `${ title }, in brief.`,
	excerpt: '',
	kind: 'launch',
	entities: [],
} );

const ITEMS = [
	item(
		'github:quillsheet-launch',
		'Acme Quillsheet launches a newsletter tool',
		'https://quillsheet.example/launch'
	),
	item(
		'feed:inkfold-award',
		'Inkfold Review wins a regional prize',
		'https://inkfold.example/award'
	),
	item(
		'linear:ledger-digest',
		'Harbor Ledger: weekly digest request',
		'https://tracker.example/LED-4'
	),
	item(
		'slack:signal-signup',
		'Signup form fails on mobile',
		'javascript:alert(1)'
	),
];

const ANSWERED = {
	...WAITING,
	state: 'answered',
	found: 23,
	sentences: [
		{ text: 'Quillsheet launched a newsletter tool.', cites: [ 1 ] },
		{ text: 'Two newsrooms had a strong month.', cites: [ 2, 3 ] },
		{ text: 'A signup form broke.', cites: [ 4, 9 ] },
	],
	items: ITEMS,
	searches: [ SEARCHES[ 0 ] ],
};

/**
 * The box as a view mounts it, the ask held above it as the page holds it.
 *
 * @param {Object}   props
 * @param {boolean}  [props.canTune]     Whether the reader holds tune.
 * @param {Function} [props.onSearchFor] Opens Search.
 * @return {import('react').ReactElement} The box.
 */
function Held( { canTune = false, onSearchFor = jest.fn() } ) {
	const [ asked, setAsked ] = useState( null );
	return (
		<AskBox
			{ ...ATTRIBUTES }
			asked={ asked }
			onAsked={ setAsked }
			onSearchFor={ onSearchFor }
			onOpenEntity={ jest.fn() }
			onSave={ jest.fn() }
			canTune={ canTune }
		/>
	);
}

let wire;
let clock;

beforeEach( () => {
	Core.reset();
	window.localStorage.clear();
	window.NewspackNodesData = { ...pageData(), userId: '41' };
	clock = steppedClock();
	wire = intelWire();
	wire.replies.ask = { ask_key: KEY };
	speak.mockClear();
} );

afterEach( () => {
	jest.restoreAllMocks();
	jest.useRealTimers();
} );

/**
 * Type a question and send it.
 *
 * @param {string} question The question.
 */
function ask( question ) {
	fireEvent.change( screen.getByLabelText( ATTRIBUTES.label ), {
		target: { value: question },
	} );
	fireEvent.click( screen.getByRole( 'button', { name: 'Ask' } ) );
}

/**
 * The stage line's steps, each its text and whether it is done.
 *
 * @return {string[]} Each step, `✓ <text>` when done.
 */
const stages = () =>
	[ ...document.querySelectorAll( '.newspack-intelligence__stage' ) ].map(
		( step ) =>
			`${ step.classList.contains( 'is-done' ) ? '✓ ' : '' }${
				step.textContent
			}`
	);

describe( 'AskBox', () => {
	it( "shows the block's label, placeholder and examples, and asks nothing until sent", () => {
		render( <Held /> );

		const field = screen.getByLabelText( 'Put it to the desk' );
		expect( field ).toHaveAttribute( 'placeholder', 'Ask the desk…' );
		expect( screen.getByText( 'Put it to the desk' ) ).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Try: Who launched?' } )
		).toBeInTheDocument();

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Try: Which members wobbled?',
			} )
		);

		expect( field ).toHaveValue( 'Which members wobbled?' );
		expect( wire.sent( 'ask' ) ).toEqual( [] );
		expect( wire.sent( 'ask_result' ) ).toEqual( [] );
	} );

	it( "shows the Try prompts only before the reader's first ask", async () => {
		wire.replies.ask_result = ANSWERED;
		const { unmount } = render( <Held /> );

		ask( 'Who launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );
		expect( screen.queryByText( /^Try:/ ) ).toBeNull();
		unmount();

		render( <Held /> );
		expect( screen.queryByText( /^Try:/ ) ).toBeNull();
	} );

	it( 'ticks each step the ask takes, announcing each politely', async () => {
		let state = WAITING;
		wire.replies.ask_result = () => state;
		render( <Held /> );

		ask( 'Which members asked about sponsors?' );

		await waitFor( () =>
			expect( wire.sent( 'ask' ) ).toEqual( [
				[ '--question=Which members asked about sponsors?' ],
			] )
		);
		await waitFor( () =>
			expect( stages() ).toEqual( [ 'Planning the search' ] )
		);

		state = { ...WAITING, state: 'planned', searches: SEARCHES };
		await clock.tick( 5 );
		await waitFor( () =>
			expect( stages() ).toEqual( [
				'✓ Planned 2 searches',
				'Reading the items',
			] )
		);
		expect( speak ).toHaveBeenCalledWith( 'Planned 2 searches', 'polite' );

		state = { ...WAITING, state: 'read', searches: SEARCHES, found: 23 };
		await clock.tick( 5 );
		await waitFor( () =>
			expect( stages() ).toEqual( [
				'✓ Planned 2 searches',
				'✓ Read 23 items',
				'Writing the answer',
			] )
		);
		expect( speak ).toHaveBeenCalledWith( 'Read 23 items', 'polite' );

		state = ANSWERED;
		await clock.tick( 5 );
		expect(
			await screen.findByText( 'Quillsheet launched a newsletter tool.' )
		).toBeInTheDocument();
		expect( stages() ).toEqual( [] );

		const polls = wire.sent( 'ask_result' ).length;
		await clock.tick( 5 );
		await clock.tick( 5 );
		expect( wire.sent( 'ask_result' ) ).toHaveLength( polls );
	} );

	it( 'renders each marker from its sentence, every one resolving to a listed source', async () => {
		wire.replies.ask_result = ANSWERED;
		render( <Held /> );

		ask( 'What launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );

		const sources = within(
			screen.getByRole( 'region', { name: 'Sources' } )
		).getAllByRole( 'listitem' );
		expect( sources ).toHaveLength( 4 );
		const markers = screen.getAllByRole( 'button', { name: /^Source / } );
		expect( markers.map( ( one ) => one.textContent ) ).toEqual( [
			'[1]',
			'[2]',
			'[3]',
			'[4]',
		] );
		markers.forEach( ( marker, at ) =>
			expect( sources[ at ] ).toHaveTextContent(
				marker
					.getAttribute( 'aria-label' )
					.replace( /^Source \d+: /, '' )
			)
		);
		expect(
			screen.queryByRole( 'button', { name: /^Source 9/ } )
		).toBeNull();
		expect(
			screen.getByText( 'A signup form broke.' ).parentElement
		).not.toHaveTextContent( '[9]' );
	} );

	it( 'says what it searched, what it read and how much the answer cites, under a fixed footer', async () => {
		wire.replies.ask_result = {
			...ANSWERED,
			searches: [ { words: 'sponsor', days: 30 } ],
		};
		render( <Held /> );

		ask( 'Who asked about sponsors?' );

		expect(
			await screen.findByText(
				'Searched “sponsor”, the last 30 days. Read 23 items; this answer cites 4.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Answers use only the items listed. Open a source before acting on it.'
			)
		).toBeInTheDocument();
	} );

	it( 'shows a source in the list on a marker click, washed for two seconds', async () => {
		wire.replies.ask_result = ANSWERED;
		render( <Held /> );
		ask( 'What launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );
		const row = within(
			screen.getByRole( 'region', { name: 'Sources' } )
		).getAllByRole( 'listitem' )[ 2 ];
		const scrolled = jest.fn();
		row.scrollIntoView = scrolled;
		jest.useFakeTimers( { doNotFake: [ 'Date', 'queueMicrotask' ] } );

		fireEvent.click( screen.getByRole( 'button', { name: /^Source 3/ } ) );

		expect( scrolled ).toHaveBeenCalled();
		expect( row ).toHaveClass( 'is-washed' );
		act( () => jest.advanceTimersByTime( 1999 ) );
		expect( row ).toHaveClass( 'is-washed' );
		act( () => jest.advanceTimersByTime( 1 ) );
		expect( row ).not.toHaveClass( 'is-washed' );
	} );

	it( 'counts sentences left out and sentences hidden, and gives a hidden one no marker', async () => {
		wire.replies.ask_result = {
			...ANSWERED,
			dropped: 2,
			hidden: 3,
			sentences: [ ANSWERED.sentences[ 0 ] ],
			items: [ ITEMS[ 0 ] ],
		};
		render( <Held /> );

		ask( 'What launched?' );

		expect(
			await screen.findByText(
				'2 sentences were left out because they cited nothing.'
			)
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'3 sentences are hidden because you can no longer read their sources.'
			)
		).toBeInTheDocument();
		expect(
			screen.getAllByRole( 'button', { name: /^Source / } )
		).toHaveLength( 1 );
	} );

	it( 'says nothing readable matched, and offers to search the words', async () => {
		const onSearchFor = jest.fn();
		wire.replies.ask_result = { ...WAITING, state: 'answered' };
		render( <Held onSearchFor={ onSearchFor } /> );

		ask( 'lighthouse grants' );

		expect(
			await screen.findByText(
				'Nothing you can read matches this question. Try other words, or search instead.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Search for these words' } )
		);
		expect( onSearchFor ).toHaveBeenCalledWith( {
			q: 'lighthouse grants',
		} );
	} );

	it( "says the items read answered nothing, and opens Search on the plan's first search", async () => {
		const onSearchFor = jest.fn();
		wire.replies.ask_result = {
			...WAITING,
			state: 'answered',
			found: 17,
			searches: [
				{
					words: 'digest',
					kinds: [ 'launch', 'award' ],
					roles: [ 'client' ],
					lens: 'desk-week',
					days: 12,
				},
				{ words: 'other' },
			],
		};
		render( <Held onSearchFor={ onSearchFor } /> );

		ask( 'Any digests?' );

		expect(
			await screen.findByText(
				'Read 17 items, but none of them answers the question.'
			)
		).toBeInTheDocument();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show the items' } )
		);
		expect( onSearchFor ).toHaveBeenCalledWith( {
			q: 'digest',
			kind: 'launch,award',
			role: 'client',
			lens: 'desk-week',
			since: daysSince( 12, Date.now() ),
		} );
	} );

	it( 'says why an ask failed, assertively, and asks the same question again', async () => {
		wire.replies.ask_result = {
			...WAITING,
			state: 'failed',
			reason: 'the model call failed',
		};
		render( <Held /> );

		ask( 'Who churned?' );

		expect(
			await screen.findByText( 'No answer: the model call failed.' )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledWith(
			'No answer: the model call failed.',
			'assertive'
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Ask again' } ) );
		await waitFor( () =>
			expect( wire.sent( 'ask' ) ).toEqual( [
				[ '--question=Who churned?' ],
				[ '--question=Who churned?' ],
			] )
		);
	} );

	it( 'withholds an answer as the server does, showing no sentence or source', async () => {
		wire.replies.ask_result = {
			...WAITING,
			state: 'withheld',
			reason: 'the answer was written from items you can no longer read',
			sentences: [ { text: 'A restricted claim.', cites: [ 1 ] } ],
			items: [ ITEMS[ 0 ] ],
		};
		render( <Held /> );

		ask( 'Who churned?' );

		expect(
			await screen.findByText(
				'No answer: the answer was written from items you can no longer read.'
			)
		).toBeInTheDocument();
		expect( screen.queryByText( 'A restricted claim.' ) ).toBeNull();
		expect(
			screen.queryByRole( 'region', { name: 'Sources' } )
		).toBeNull();
	} );

	it( 'stops asking once the ask has waited as long as the server allows', async () => {
		wire.replies.ask_result = { ...WAITING, wait_s: 120 };
		render( <Held /> );

		ask( 'Who won?' );

		await waitFor( () =>
			expect( stages() ).toEqual( [ 'Planning the search' ] )
		);
		await clock.tick( 5 );
		await clock.tick( 120 );
		expect(
			await screen.findByText(
				'The answer took too long and was stopped. Ask again.'
			)
		).toBeInTheDocument();
		const polls = wire.sent( 'ask_result' ).length;
		await clock.tick( 5 );
		await clock.tick( 5 );
		expect( wire.sent( 'ask_result' ) ).toHaveLength( polls );
	} );

	it( 'clears the answer and the field, or empties the field for another', async () => {
		wire.replies.ask_result = ANSWERED;
		render( <Held /> );
		ask( 'What launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );
		const field = screen.getByLabelText( ATTRIBUTES.label );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Ask another' } )
		);
		expect( field ).toHaveValue( '' );
		expect( field ).toHaveFocus();
		expect(
			screen.getByText( 'Quillsheet launched a newsletter tool.' )
		).toBeInTheDocument();

		fireEvent.change( field, { target: { value: 'Half typed' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Clear' } ) );
		expect( field ).toHaveValue( '' );
		expect(
			screen.queryByText( 'Quillsheet launched a newsletter tool.' )
		).toBeNull();
	} );

	it( "offers a curator the exclusion on an answer's sources, and a reader none", async () => {
		wire.replies.ask_result = ANSWERED;
		const { unmount } = render( <Held canTune={ true } /> );
		ask( 'What launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );
		expect(
			screen.getAllByRole( 'button', { name: 'Exclude from every view' } )
		).toHaveLength( 4 );
		unmount();

		render( <Held /> );
		ask( 'What launched?' );
		await screen.findByText( 'Quillsheet launched a newsletter tool.' );
		expect(
			screen.queryByRole( 'button', { name: 'Exclude from every view' } )
		).toBeNull();
	} );

	it( 'renders markup in a sentence as text, and links only an http or https source', async () => {
		wire.replies.ask_result = {
			...ANSWERED,
			sentences: [
				{ text: '<img src=x onerror=alert(1)>Payload.', cites: [ 4 ] },
			],
		};
		const { container } = render( <Held /> );

		ask( 'What broke?' );

		expect(
			await screen.findByText( '<img src=x onerror=alert(1)>Payload.' )
		).toBeInTheDocument();
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect(
			screen
				.getAllByRole( 'link' )
				.map( ( link ) => link.getAttribute( 'href' ) )
				.every( ( href ) => /^https?:\/\//.test( href ) )
		).toBe( true );
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

	it( 'says a refused answer read was not read, its words kept from a reader', async () => {
		wire.replies.ask_result = () =>
			new Error( 'ask_result refused for Harbor Ledger' );
		render( <Held /> );

		ask( 'Who won?' );

		expect(
			await screen.findByText(
				'Intelligence could not be read just now. Try again.'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'ask_result refused for Harbor Ledger' )
		).toBeNull();
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

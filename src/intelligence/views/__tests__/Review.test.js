/* eslint-env jest */
/**
 * Review is three queues behind tabs — suggested matches, suggested names and
 * similar themes — each counted by the total its read answers. A match row is
 * one item asking, per entity proposed for it, whether the item is about that
 * entity, with the reason and the sentence it was found in. A curator's
 * judgment hides its row at once and sends after the undo window; a merge of
 * two entities is confirmed in a dialog and sent on Merge. The review keys
 * listen on the whole page; each screen state renders at 390 and 1,440 px.
 */

import {
	render,
	screen,
	fireEvent,
	act,
	waitFor,
	within,
} from '@testing-library/react';
import { renderOnPage as onPage } from '../../__tests__/pageReads';
import {
	Core,
	newMessage,
	TYPE,
	TO,
	VALUE,
	TM_ERROR,
} from '@newspack-nodes/runtime';
import { speak } from '@wordpress/a11y';
import { installViewport } from '@newspack-nodes/shared/test-utils/viewportWidth';
import { intelWire } from '../../__tests__/intelWire';
import { UNDO_MS } from '../../hooks/useDeferredWrite';
import { useIntelRead } from '../../hooks/useIntelCommand';
import Review from '../Review';
import { pageData } from './fixtures';

jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );

// The last props each list took, so a test moves its view as DataViews may.
const mockList = { props: null, renders: 0 };
jest.mock( '@newspack-nodes/shared/components/DataViewsList', () => {
	const { default: List } = jest.requireActual(
		'@newspack-nodes/shared/components/DataViewsList'
	);
	return {
		__esModule: true,
		default: ( props ) => {
			mockList.props = props;
			mockList.renders += 1;
			return require( 'react' ).createElement( List, props );
		},
	};
} );

const LINK = {
	item_key: 'github:example/plugin#912',
	entity_key: 'organization:acme-quillsheet',
	name: 'Acme Quillsheet',
	type: 'organization',
	role: 'mention',
	method: 'ner_fuzzy',
	confidence: 0.61,
	evidence:
		'Readers of **Acme Quillsheet** flagged the [paywall](https://github.example/912) bug twice.',
	title: 'Paywall bug reported by Acme',
	url: 'https://github.example/example/plugin/912',
	item_links: 2,
};

const SIBLING = {
	...LINK,
	entity_key: 'organization:valley-ledger',
	name: 'Valley Ledger',
	role: 'subject',
	method: 'name',
	confidence: 1,
	evidence: 'Valley Ledger editors reproduced it.',
};

const PROPOSED = {
	entity_key: 'organization:valley-times',
	type: 'organization',
	name: 'Valley Times',
	items: [
		{
			item_key: 'feed:valley-17',
			url: 'https://valley.example/17',
			title: 'Valley Times joins the network',
			evidence: 'This week _Valley Times_ signed on.',
		},
	],
};

const PAIR = {
	themes: [
		{ theme: 'kiosk offboarding', stories: 4 },
		{ theme: 'kiosk offboarding from platform', stories: 2 },
	],
	words: [ 'kiosk', 'offboarding' ],
	shared: 3,
};

const TOTALS = { links: 41, entities: 7, themes: 12 };

const LEGEND = 'Keys: J and K to move, Y yes, N no, M same as…, Z undo.';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	window.localStorage.clear();
	wire = intelWire();
	wire.replies.review = ( [ what ] ) =>
		'links' === what
			? { links: [ LINK, SIBLING ], total: TOTALS.links, available: true }
			: {
					entities: [ PROPOSED ],
					total: TOTALS.entities,
					available: true,
			  };
	wire.replies.theme_suggestions = {
		suggestions: [ PAIR ],
		total: TOTALS.themes,
		available: true,
	};
} );

/**
 * Render `ui` on a page, which asks no review count while Review is open.
 *
 * @param {import('react').ReactElement} ui What to render.
 * @return {import('@testing-library/react').RenderResult} The render.
 */
const renderOnPage = ( ui ) => onPage( ui );

function renderReview( props = {} ) {
	return renderOnPage(
		<Review canTune={ false } onOpenEntity={ jest.fn() } { ...props } />
	);
}

const tab = ( name ) =>
	screen.getByRole( 'tab', { name: new RegExp( `^${ name }` ) } );

/**
 * One queue's panel, its tab picked first when another is open.
 *
 * @param {string} name The queue's name.
 * @return {HTMLElement} The panel.
 */
const queue = ( name ) => {
	const opener = tab( name );
	if ( 'true' !== opener.getAttribute( 'aria-selected' ) ) {
		fireEvent.click( opener );
	}
	return screen.getByRole( 'tabpanel', {
		name: new RegExp( `^${ name }` ),
	} );
};

// Only the undo window's own timer is faked; the wire runs as it does.
const fakeUndoClock = () =>
	jest.useFakeTimers( {
		doNotFake: [
			'Date',
			'hrtime',
			'nextTick',
			'performance',
			'queueMicrotask',
			'requestAnimationFrame',
			'cancelAnimationFrame',
			'requestIdleCallback',
			'cancelIdleCallback',
			'setImmediate',
			'clearImmediate',
			'setInterval',
			'clearInterval',
		],
	} );
const elapse = ( ms ) =>
	act( async () => {
		jest.advanceTimersByTime( ms );
	} );
const settle = () => act( async () => {} );

afterEach( () => {
	jest.useRealTimers();
} );

/**
 * A read whose rendering says whether its answer is owed, remembered or
 * read, so a test sees what the cache holds for it.
 *
 * @param {Object}   props
 * @param {string}   props.scope   The read's scope.
 * @param {string}   props.command The read verb.
 * @param {string[]} props.args    Its tokens.
 * @return {import('react').ReactElement} Its state.
 */
function Probe( { scope, command, args } ) {
	const { loading, stale } = useIntelRead( scope, command, args );
	if ( loading ) {
		return <p>{ `${ command } owed` }</p>;
	}
	return <p>{ `${ command } ${ stale ? 'remembered' : 'read' }` }</p>;
}

/** A reply that never comes, so a read stays owed. */
const never = () => new Promise( () => {} );

const healthProbe = () => (
	<Probe scope="probe:health" command="health" args={ [ 'sources' ] } />
);

/** Seed a remembered health slice, as the strip leaves one behind. */
const rememberHealth = async () => {
	wire.replies.health = { sources: [], dead_clients: [] };
	const seeded = render( healthProbe() );
	await screen.findByText( 'health read' );
	seeded.unmount();
};

/**
 * A judgment row: the element the review keys move to.
 *
 * @param {HTMLElement} element An element inside the row.
 * @return {?HTMLElement} The row.
 */
const rowOf = ( element ) => element.closest( '[data-review-row]' );

/**
 * Hold that the row naming an entity stands judged: greyed, out of the
 * review keys' reach, its answers gone and its verdict in words.
 *
 * @param {string}      name        The entity's name, as its chip reads.
 * @param {string}      words       The verdict.
 * @param {HTMLElement} [container] Where the row stands.
 * @return {HTMLElement} The row.
 */
function judgedAs( name, words, container = document.body ) {
	const row = within( container )
		.getByRole( 'button', { name } )
		.closest( '[data-review-key]' );
	expect( row ).toHaveClass( 'is-muted' );
	expect( row ).not.toHaveAttribute( 'data-review-row' );
	expect( row ).toHaveTextContent( words );
	expect(
		within( row ).queryAllByRole( 'button', {
			name: /^(Confirm|Reject|Accept) /,
		} )
	).toEqual( [] );
	return row;
}

describe( 'Review — queues', () => {
	it( 'says what the queues are for under the shared header and counts each tab by its total', async () => {
		renderReview();
		expect(
			screen
				.getByRole( 'heading', { level: 2, name: 'Review' } )
				.closest( '.newspack-intelligence__section-head' )
		).toHaveTextContent(
			'The extractor suggests which client or company each item is about. Confirm the right ones and reject the rest.'
		);
		expect(
			await screen.findByRole( 'tab', { name: 'Suggested matches 41' } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Suggested names 7' } )
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'tab', { name: 'Similar themes 12' } )
		).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toEqual(
			expect.arrayContaining( [
				[ 'links', '--limit=50' ],
				[ 'entities', '--limit=50' ],
			] )
		);
		expect( wire.sent( 'theme_suggestions' ) ).toEqual( [ [] ] );
	} );

	it( 'shows the open queue alone', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		expect(
			screen.queryByRole( 'button', { name: 'Valley Times' } )
		).toBeNull();
		expect(
			await within( queue( 'Suggested names' ) ).findByRole( 'button', {
				name: 'Valley Times',
			} )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeNull();
	} );

	it( 'counts each tab by the total its read answers, judged rows still in it until a read drops them', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Reject Acme Quillsheet',
			} )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Reject Valley Ledger' } )
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 41' } )
		).toBeInTheDocument();
		judgedAs( 'Acme Quillsheet', 'Rejected.' );
		judgedAs( 'Valley Ledger', 'Rejected.' );
	} );

	it( 'says each queue is empty, or that the graph is unavailable', async () => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [], total: 0, available: true }
				: { entities: [], total: 0, available: false };
		renderReview();
		expect(
			await within( queue( 'Suggested matches' ) ).findByText(
				'Nothing to review. New suggestions arrive as items are read.'
			)
		).toBeInTheDocument();
		expect(
			await within( queue( 'Suggested names' ) ).findByText(
				'The intelligence store did not open, so this was not read. An administrator can check the status above.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'Review — rows', () => {
	it( 'asks of each proposed entity whether the item is about it, with the reason muted', async () => {
		renderReview();
		const links = queue( 'Suggested matches' );
		expect(
			await within( links ).findByRole( 'link', {
				name: 'Paywall bug reported by Acme',
			} )
		).toHaveAttribute( 'href', LINK.url );
		expect(
			within( links ).getAllByRole( 'link', {
				name: 'Paywall bug reported by Acme',
			} )
		).toHaveLength( 1 );
		const acme = rowOf(
			within( links ).getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		expect( acme ).toHaveTextContent( /^Is this about Acme Quillsheet\?/ );
		const reason = within( acme ).getByText(
			'Named in passing by a lookalike, 61% match.'
		);
		expect( reason ).toHaveClass( 'is-muted' );
		expect(
			within(
				rowOf(
					within( links ).getByRole( 'button', {
						name: 'Valley Ledger',
					} )
				)
			).getByText( 'Headlined by its exact name, exact match.' )
		).toHaveClass( 'is-muted' );
	} );

	it( 'says how many of an item’s matches wait beyond those the queue lists, judged ones still listed', async () => {
		wire.replies.reject_link = never;
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [
							{ ...LINK, item_links: 7 },
							{ ...SIBLING, item_links: 7 },
						],
						total: TOTALS.links,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		expect(
			await within( links ).findByText(
				'5 more matches wait beyond these.'
			)
		).toHaveClass( 'is-muted' );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: `Reject ${ LINK.name }`,
			} )
		);
		expect(
			within( links ).getByText( '5 more matches wait beyond these.' )
		).toBeInTheDocument();
	} );

	it( 'keeps a fully judged item, its judged links and how many more wait, until a read drops it', async () => {
		wire.replies.reject_link = never;
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [
							{ ...LINK, item_links: 7 },
							{ ...SIBLING, item_links: 7 },
						],
						total: TOTALS.links,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		for ( const link of [ LINK, SIBLING ] ) {
			fireEvent.click(
				await within( links ).findByRole( 'button', {
					name: `Reject ${ link.name }`,
				} )
			);
		}
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 41' } )
		).toBeInTheDocument();
		expect(
			within( links ).getByRole( 'link', { name: LINK.title } )
		).toBeInTheDocument();
		expect(
			within( links ).getByText( '5 more matches wait beyond these.' )
		).toBeInTheDocument();
	} );

	it( 'says nothing waits beyond an item whose matches are all listed', async () => {
		wire.replies.review = ( [ what ] ) => ( {
			[ what ]:
				'links' === what
					? [
							{ ...LINK, item_links: 2 },
							{ ...SIBLING, item_links: 2 },
					  ]
					: [],
			total: 1,
			available: true,
		} );
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		expect( screen.queryByText( /more match/ ) ).toBeNull();
	} );

	it( "names a proposed entity with its type's word, never its key", async () => {
		renderReview();
		const entities = queue( 'Suggested names' );
		const row = rowOf(
			await within( entities ).findByRole( 'button', {
				name: 'Valley Times',
			} )
		);
		expect( row ).toHaveTextContent( /^New name: Valley Times, a Outfit/ );
		expect(
			within( entities ).queryByText( /organization:valley-times/ )
		).toBeNull();
		expect(
			within( entities ).getByRole( 'link', {
				name: 'Valley Times joins the network',
			} )
		).toHaveAttribute( 'href', 'https://valley.example/17' );
	} );

	it( 'opens a row entity', async () => {
		const onOpenEntity = jest.fn();
		renderReview( { onOpenEntity } );
		fireEvent.click(
			await within( queue( 'Suggested names' ) ).findByRole( 'button', {
				name: 'Valley Times',
			} )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:valley-times'
		);
	} );

	it( 'offers no Save, even to a curator who can save', async () => {
		renderReview( { canTune: true, onSave: jest.fn() } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		expect(
			screen.queryByRole( 'button', { name: 'Save to folder' } )
		).toBeNull();
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: 'Valley Times',
		} );
		expect(
			screen.queryByRole( 'button', { name: 'Save to folder' } )
		).toBeNull();
	} );

	it( 'offers a reader no judgment', async () => {
		renderReview();
		for ( const [ name, entity ] of [
			[ 'Suggested matches', 'Acme Quillsheet' ],
			[ 'Suggested names', 'Valley Times' ],
		] ) {
			await within( queue( name ) ).findByRole( 'button', {
				name: entity,
			} );
			expect(
				screen.queryAllByRole( 'button', {
					name: /^(Confirm|Reject|Accept|Merge|Keep|Not the same)|same as…$/,
				} )
			).toEqual( [] );
		}
		await within( queue( 'Similar themes' ) ).findByText(
			'kiosk offboarding'
		);
		expect(
			within( queue( 'Similar themes' ) ).queryAllByRole( 'button', {
				name: /^(Keep|Not the same)/,
			} )
		).toEqual( [] );
	} );
} );

describe( 'Review — evidence', () => {
	it( 'quotes the sentence naming the entity as plain text, the name marked', async () => {
		renderReview();
		const links = queue( 'Suggested matches' );
		const row = rowOf(
			await within( links ).findByRole( 'button', {
				name: 'Acme Quillsheet',
			} )
		);
		const quote = row.querySelector( '.newspack-intelligence__evidence' );
		expect( quote ).toHaveTextContent(
			'Readers of Acme Quillsheet flagged the paywall bug twice.'
		);
		expect( quote.querySelector( 'mark' ) ).toHaveTextContent(
			/^Acme Quillsheet$/
		);
		expect( within( quote ).queryByRole( 'link' ) ).toBeNull();
		const cited = await within( queue( 'Suggested names' ) ).findByText(
			'Valley Times',
			{ selector: 'mark' }
		);
		expect( cited.parentElement ).toHaveTextContent(
			'This week Valley Times signed on.'
		);
	} );

	it.each( [
		[ 'is not spelled in any sentence', '' ],
		[ 'is the title', 'Paywall bug reported by Acme' ],
	] )( 'quotes nothing for evidence that %s', async ( _, evidence ) => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ { ...LINK, evidence, item_links: 1 } ],
						total: 1,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		renderReview();
		const links = queue( 'Suggested matches' );
		await within( links ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		expect(
			links.querySelectorAll( '.newspack-intelligence__evidence' )
		).toHaveLength( 0 );
	} );
} );

describe( 'Review — judgments', () => {
	const linksRead = () =>
		wire
			.sent( 'review' )
			.filter(
				( [ w, size ] ) => 'links' === w && '--limit=50' === size
			);

	it( 'answers with a check and a cross joined in one pill, each key on its glyph', async () => {
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const yes = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		const no = within( links ).getByRole( 'button', {
			name: 'Reject Acme Quillsheet',
		} );
		for ( const glyph of [ yes, no ] ) {
			expect( glyph ).toHaveTextContent( /^$/ );
			expect( glyph.querySelector( 'svg' ) ).not.toBeNull();
			expect( glyph ).toHaveClass( 'button-small' );
			expect( glyph.parentElement ).toHaveClass(
				'newspack-intelligence__judgment'
			);
		}
		expect( yes.nextElementSibling ).toBe( no );
		expect( yes ).toHaveAttribute( 'aria-keyshortcuts', 'y' );
		expect( no ).toHaveAttribute( 'aria-keyshortcuts', 'n' );
		const entities = queue( 'Suggested names' );
		const add = await within( entities ).findByRole( 'button', {
			name: 'Accept Valley Times',
		} );
		expect( add ).toHaveTextContent( /^$/ );
		expect( add.querySelector( 'svg' ) ).not.toBeNull();
		expect(
			within( entities ).getByRole( 'button', {
				name: 'Valley Times is the same as…',
			} )
		).toHaveAttribute( 'aria-keyshortcuts', 'm' );
	} );

	it( 'confirms and rejects a link by its item and entity, then re-reads', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		fireEvent.click(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
	} );

	it( 'keeps a judged link in place, greyed with its verdict and Undo on the row while it waits, then without Undo while the re-read still lists it', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		const row = judgedAs( 'Acme Quillsheet', 'Confirmed.', links );
		expect(
			within( row ).getByRole( 'button', {
				name: 'Undo: Confirmed Acme Quillsheet.',
			} )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		expect(
			within(
				judgedAs( 'Acme Quillsheet', 'Confirmed.', links )
			).queryByRole( 'button', { name: /^Undo/ } )
		).toBeNull();
		expect(
			rowOf(
				within( links ).getByRole( 'button', { name: 'Valley Ledger' } )
			)
		).not.toBeNull();
	} );

	it( 'takes a judged link out once a read no longer lists it', async () => {
		let confirmed = false;
		wire.replies.confirm_link = () => {
			confirmed = true;
			return { ok: true, event_id: 'ev-gone' };
		};
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			'links' === tokens[ 0 ] && confirmed
				? { links: [ SIBLING ], total: 40, available: true }
				: answer( tokens );
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect(
				within( links ).queryByRole( 'button', {
					name: 'Acme Quillsheet',
				} )
			).toBeNull()
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 40' } )
		).toBeInTheDocument();
	} );

	it( 'shows an undone judgment’s row owed a judgment once its write would have gone', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		fakeUndoClock();
		fireEvent.click(
			await within( links ).findByRole( 'button', {
				name: 'Reject Acme Quillsheet',
			} )
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		expect(
			within( links ).getByRole( 'button', {
				name: 'Reject Acme Quillsheet',
			} )
		).toBeInTheDocument();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ SIBLING.item_key }`,
				`--entity=${ SIBLING.entity_key }`,
			],
		] );
	} );

	it( 'takes z after a judgment’s write went as nothing to undo, the row still judged', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const reject = await within( links ).findByRole( 'button', {
			name: 'Reject Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( reject );
		await elapse( UNDO_MS );
		fireEvent.keyDown( document.body, { key: 'z' } );
		await settle();
		judgedAs( 'Acme Quillsheet', 'Rejected.', links );
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
	} );

	it( 'undoes a judgment from its row, its answers back', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		fakeUndoClock();
		fireEvent.click(
			await within( links ).findByRole( 'button', {
				name: 'Reject Acme Quillsheet',
			} )
		);
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Undo: Rejected Acme Quillsheet.',
			} )
		);
		expect(
			within( links ).getByRole( 'button', {
				name: 'Reject Acme Quillsheet',
			} )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'moves focus to the row in the place of one judged by a click', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const no = await within( links ).findByRole( 'button', {
			name: 'Reject Acme Quillsheet',
		} );
		no.focus();
		await act( async () => fireEvent.click( no ) );
		expect( document.activeElement ).toBe(
			rowOf(
				within( links ).getByRole( 'button', { name: 'Valley Ledger' } )
			)
		);
	} );

	it( 'moves focus to the queue’s tab once its last row is judged', async () => {
		wire.replies.reject_entity = never;
		renderReview( { canTune: true } );
		const names = queue( 'Suggested names' );
		const no = await within( names ).findByRole( 'button', {
			name: 'Reject Valley Times',
		} );
		no.focus();
		await act( async () => fireEvent.click( no ) );
		expect( document.activeElement ).toBe( tab( 'Suggested names' ) );
	} );

	it( 'takes the next judgment while the last one waits', async () => {
		wire.replies.confirm_link = never;
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
		judgedAs( 'Acme Quillsheet', 'Confirmed.', links );
		judgedAs( 'Valley Ledger', 'Rejected.', links );
	} );

	it( 'returns a refused judgment to its row with the refusal under it, focused', async () => {
		wire.replies.confirm_link = () =>
			new Error( 'no link: github:example/plugin#912' );
		renderReview( { canTune: true } );
		const yes = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: 'Confirm Acme Quillsheet' }
		);
		fakeUndoClock();
		fireEvent.click( yes );
		await elapse( UNDO_MS );
		const refusal = await screen.findByText(
			/no link: github:example\/plugin/
		);
		const row = rowOf(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		expect( row ).toContainElement( refusal );
		expect( refusal.closest( '[role="alert"]' ) ).not.toBeNull();
		expect( document.activeElement ).toBe( row );
	} );

	it( 'returns a refused name to its row with the refusal under it, focused', async () => {
		wire.replies.accept_entity = () =>
			new Error( 'no entity: organization:valley-times' );
		renderReview( { canTune: true } );
		const add = await within( queue( 'Suggested names' ) ).findByRole(
			'button',
			{ name: 'Accept Valley Times' }
		);
		fakeUndoClock();
		fireEvent.click( add );
		await elapse( UNDO_MS );
		const refusal = await screen.findByText(
			'no entity: organization:valley-times'
		);
		const row = rowOf(
			within( queue( 'Suggested names' ) ).getByRole( 'button', {
				name: 'Accept Valley Times',
			} )
		);
		expect( row ).toContainElement( refusal );
		expect( document.activeElement ).toBe( row );
	} );

	it( 'brings every judged link back on a refusal that does not name its send', async () => {
		wire.replies.confirm_link = never;
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 )
		);
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'intelligence:confirm_link:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		expect(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		).toBeInTheDocument();
		expect(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		).toBeInTheDocument();
		const alerts = screen.getAllByRole( 'alert' );
		expect( alerts ).toHaveLength( 2 );
		alerts.forEach( ( alert ) =>
			expect( alert ).toHaveTextContent( 'NOT_AVAILABLE' )
		);
	} );

	it( 'keeps a row whose write landed judged on a refusal that does not name its send', async () => {
		let landed = false;
		wire.replies.confirm_link = () => {
			landed = true;
			return { ok: true, event_id: 'ev-landed' };
		};
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( 2000 );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS - 2000 );
		await waitFor( () => expect( landed ).toBe( true ) );
		await settle();
		await settle();
		await elapse( 2000 );
		jest.useRealTimers();
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 )
		);
		expect( wire.sent( 'confirm_link' ) ).toHaveLength( 1 );
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'intelligence:reject_link:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		const back = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: 'Reject Valley Ledger' }
		);
		expect( rowOf( back ) ).toHaveTextContent( 'NOT_AVAILABLE' );
		judgedAs(
			'Acme Quillsheet',
			'Confirmed.',
			queue( 'Suggested matches' )
		);
	} );

	it( 'keeps a row still in its undo window judged on a refusal that does not name its send', async () => {
		wire.replies.confirm_link = never;
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const confirm = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( confirm );
		await elapse( UNDO_MS );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'intelligence:confirm_link:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		expect(
			within( links ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		).toBeInTheDocument();
		judgedAs( 'Valley Ledger', 'Rejected.', links );
	} );

	it( 'forgets a judgment its read dropped while it waited, on the first read after it is sent, so its row returns answerable', async () => {
		const COASTLINE = {
			...PROPOSED,
			entity_key: 'organization:coastline-weekly',
			name: 'Coastline Weekly',
		};
		let listed = [ LINK, SIBLING ];
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: listed, total: listed.length, available: true }
				: {
						entities: [ PROPOSED, COASTLINE ],
						total: 2,
						available: true,
				  };
		wire.replies.confirm_link = () => {
			listed = [ LINK ];
			return { ok: true, event_id: 'ev-dropped' };
		};
		wire.replies.reject_link = never;
		wire.replies.accept_entity = ( tokens ) => {
			listed = tokens.includes( `--key=${ COASTLINE.entity_key }` )
				? [ LINK, SIBLING ]
				: [ LINK ];
			return { ok: true, event_id: 'ev-name' };
		};
		renderReview( { canTune: true } );
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: 'Accept Valley Times',
		} );
		const links = queue( 'Suggested matches' );
		const confirm = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( confirm );
		await elapse( UNDO_MS - 1000 );
		fireEvent.click(
			within( links ).getByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( 1000 );
		await settle();
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		jest.useRealTimers();
		const names = queue( 'Suggested names' );
		const accepts = [
			'Accept Valley Times',
			'Accept Coastline Weekly',
		].map( ( name ) => within( names ).getByRole( 'button', { name } ) );
		fakeUndoClock();
		for ( const accept of accepts ) {
			fireEvent.click( accept );
			await elapse( UNDO_MS );
			await settle();
		}
		jest.useRealTimers();
		expect(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		).toBeInTheDocument();
	} );

	it( 'brings back only its own queue’s judged rows on a refusal that does not name its send', async () => {
		wire.replies.confirm_link = never;
		wire.replies.accept_entity = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: 'Accept Valley Times',
		} );
		const confirm = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: 'Confirm Acme Quillsheet' }
		);
		fakeUndoClock();
		fireEvent.click( confirm );
		fireEvent.click(
			within( queue( 'Suggested names' ) ).getByRole( 'button', {
				name: 'Accept Valley Times',
			} )
		);
		await elapse( UNDO_MS );
		await settle();
		jest.useRealTimers();
		expect( wire.sent( 'accept_entity' ) ).toHaveLength( 1 );
		const bounce = newMessage();
		bounce[ TYPE ] = TM_ERROR;
		bounce[ TO ] = 'intelligence:confirm_link:in';
		bounce[ VALUE ] = 'NOT_AVAILABLE\n';
		await act( async () => {
			Core.node( '_command_interpreter' ).fill( bounce );
		} );
		await settle();
		judgedAs( PROPOSED.name, 'Accepted.', queue( 'Suggested names' ) );
		expect(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows a judged link again once a read has dropped it and a later one lists it', async () => {
		let listed = [ LINK, SIBLING ];
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: listed, total: listed.length, available: true }
				: { entities: [], total: 0, available: true };
		wire.replies.confirm_link = () => {
			listed = [ SIBLING ];
			return { ok: true, event_id: 'ev-c' };
		};
		wire.replies.reject_link = () => {
			listed = [ LINK ];
			return { ok: true, event_id: 'ev-r' };
		};
		renderReview( { canTune: true } );
		const control = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{
				name: 'Confirm Acme Quillsheet',
			}
		);
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Reject Valley Ledger',
			} )
		);
		await elapse( UNDO_MS );
		expect(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		).toBeInTheDocument();
	} );

	it( 'judges a link whose item key is a whole feed URL', async () => {
		const itemKey = `feed:https://news.example.org/2026/10/${ 'slug-'.repeat(
			30
		) }`;
		expect( encodeURIComponent( itemKey ).length ).toBeGreaterThan( 128 );
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [
							{ ...LINK, item_key: itemKey, item_links: 1 },
						],
						total: 1,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		wire.replies.confirm_link = never;
		renderReview( { canTune: true } );
		const links = queue( 'Suggested matches' );
		const control = await within( links ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[ `--item=${ itemKey }`, `--entity=${ LINK.entity_key }` ],
			] )
		);
		judgedAs( 'Acme Quillsheet', 'Confirmed.', links );
	} );

	it.each( [
		[
			'accept_entity',
			'Accept Valley Times',
			[ `--key=${ PROPOSED.entity_key }` ],
			'Accepted.',
		],
		[
			'reject_entity',
			'Reject Valley Times',
			[ `--key=${ PROPOSED.entity_key }` ],
			'Rejected.',
		],
	] )(
		'sends %s for a proposed entity and shows its verdict',
		async ( verb, name, tokens, words ) => {
			wire.replies[ verb ] = { ok: true, event_id: 'ev-e' };
			renderReview( { canTune: true } );
			const control = await within(
				queue( 'Suggested names' )
			).findByRole( 'button', { name } );
			fakeUndoClock();
			fireEvent.click( control );
			await elapse( UNDO_MS );
			await waitFor( () =>
				expect( wire.sent( verb ) ).toEqual( [ tokens ] )
			);
			judgedAs( PROPOSED.name, words, queue( 'Suggested names' ) );
		}
	);
} );

describe( 'Review — same as…', () => {
	const INTO = {
		entity_key: 'organization:the-valley-times',
		name: 'The Valley Times',
	};

	// jsdom lays nothing out, so it has no scrollIntoView for the picker.
	const { scrollIntoView } = window.Element.prototype;

	beforeEach( () => {
		window.Element.prototype.scrollIntoView = jest.fn();
		wire.replies.names = { entities: [ PROPOSED, INTO ], available: true };
	} );

	afterEach( () => {
		window.Element.prototype.scrollIntoView = scrollIntoView;
	} );

	async function openMerge() {
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested names' ) ).findByRole( 'button', {
				name: 'Valley Times is the same as…',
			} )
		);
		return screen.getByRole( 'dialog', {
			name: 'Merge Valley Times into…',
		} );
	}

	async function pick( dialog ) {
		const into = within( dialog ).getByRole( 'combobox', {
			name: 'Merge into',
		} );
		fireEvent.change( into, { target: { value: 'Valley' } } );
		fireEvent.click(
			await within( dialog ).findByRole( 'option', {
				name: 'The Valley Times',
			} )
		);
	}

	it( 'names the consequence, offers every other entity by name, and sends nothing before Merge', async () => {
		wire.replies.merge_entities = { ok: true, event_id: 'ev-m' };
		const dialog = await openMerge();
		expect( dialog ).toHaveTextContent(
			'Its names and items move to the record you pick. This cannot be undone.'
		);
		await pick( dialog );
		await waitFor( () =>
			expect( wire.sent( 'names' ) ).toEqual( [ [ '--prefix=Valley' ] ] )
		);
		expect(
			within( dialog ).queryByRole( 'option', { name: 'Valley Times' } )
		).toBeNull();
		await settle();
		expect( wire.sent( 'merge_entities' ) ).toEqual( [] );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toEqual( [
				[
					`--from=${ PROPOSED.entity_key }`,
					`--into=${ INTO.entity_key }`,
				],
			] )
		);
		const merged = await screen.findByText(
			'Merged Valley Times into The Valley Times.'
		);
		expect( merged.closest( '.components-snackbar' ) ).not.toBeNull();
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		judgedAs(
			'Valley Times',
			'Merged into The Valley Times.',
			queue( 'Suggested names' )
		);
	} );

	it( 'moves focus after a merge of a middle name to the name after it', async () => {
		const COASTLINE = {
			...PROPOSED,
			entity_key: 'organization:coastline-weekly',
			name: 'Coastline Weekly',
		};
		const FERNWOOD = {
			...PROPOSED,
			entity_key: 'organization:fernwood-daily',
			name: 'Fernwood Daily',
		};
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [], total: 0, available: true }
				: {
						entities: [ COASTLINE, PROPOSED, FERNWOOD ],
						total: 3,
						available: true,
				  };
		wire.replies.merge_entities = { ok: true, event_id: 'ev-m3' };
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested names' ) ).findByRole( 'button', {
				name: 'Valley Times is the same as…',
			} )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Merge Valley Times into…',
		} );
		await pick( dialog );
		act( () => document.activeElement.blur() );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);
		await screen.findByText( 'Merged Valley Times into The Valley Times.' );
		expect( document.activeElement ).toBe(
			rowOf(
				screen.getByRole( 'button', {
					name: `Reject ${ FERNWOOD.name }`,
				} )
			)
		);
	} );

	it( 'moves focus, as a merge owed its answer closes, to the name after it as the merge was sent, though a read has since dropped it', async () => {
		const COASTLINE = {
			...PROPOSED,
			entity_key: 'organization:coastline-weekly',
			name: 'Coastline Weekly',
		};
		const FERNWOOD = {
			...PROPOSED,
			entity_key: 'organization:fernwood-daily',
			name: 'Fernwood Daily',
		};
		let dropped = false;
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: [ LINK, SIBLING ], total: 2, available: true }
				: {
						entities: dropped
							? [ COASTLINE, FERNWOOD ]
							: [ COASTLINE, PROPOSED, FERNWOOD ],
						total: 3,
						available: true,
				  };
		wire.replies.confirm_link = () => {
			dropped = true;
			return { ok: true, event_id: 'ev-dropped' };
		};
		wire.replies.merge_entities = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Confirm Acme Quillsheet',
		} );
		fireEvent.click(
			await within( queue( 'Suggested names' ) ).findByRole( 'button', {
				name: 'Valley Times is the same as…',
			} )
		);
		const dialog = screen.getByRole( 'dialog', {
			name: 'Merge Valley Times into…',
		} );
		await pick( dialog );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toHaveLength( 1 )
		);
		fakeUndoClock();
		fireEvent.click(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Confirm Acme Quillsheet',
			} )
		);
		await elapse( UNDO_MS );
		jest.useRealTimers();
		await waitFor(
			() =>
				expect(
					wire
						.sent( 'review' )
						.filter( ( [ , size ] ) => '--limit=50' === size )
				).toHaveLength( 4 ),
			{ timeout: 3000 }
		);
		const names = queue( 'Suggested names' );
		await waitFor( () =>
			expect(
				within( names ).queryByText( 'Valley Times', {
					selector: '.newspack-intelligence__pill-name',
				} )
			).toBeNull()
		);
		await waitFor( () =>
			expect(
				within( names ).getByRole( 'button', {
					name: `Reject ${ FERNWOOD.name }`,
				} )
			).toBeInTheDocument()
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( document.activeElement ).toBe(
			rowOf(
				screen.getByRole( 'button', {
					name: `Reject ${ FERNWOOD.name }`,
				} )
			)
		);
	} );

	it( 'closes on Escape pressed in the picker once its list is closed', async () => {
		const dialog = await openMerge();
		const into = within( dialog ).getByRole( 'combobox', {
			name: 'Merge into',
		} );
		act( () => into.blur() );

		fireEvent.keyDown( into, { key: 'Escape', code: 'Escape' } );

		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( wire.sent( 'merge_entities' ) ).toEqual( [] );
	} );

	it( 'merges nothing on Cancel', async () => {
		const dialog = await openMerge();
		await pick( dialog );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		await settle();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( wire.sent( 'merge_entities' ) ).toEqual( [] );
		expect(
			within( queue( 'Suggested names' ) ).getByRole( 'button', {
				name: 'Valley Times',
			} )
		).toBeInTheDocument();
	} );

	it( 'announces a merge cancelled while it was owed, once it lands', async () => {
		let land;
		wire.replies.merge_entities = () =>
			new Promise( ( resolve ) => {
				land = resolve;
			} );
		const dialog = await openMerge();
		await pick( dialog );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);
		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toHaveLength( 1 )
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		await act( async () => land( { ok: true, event_id: 'ev-late' } ) );
		expect(
			await screen.findByText(
				'Merged Valley Times into The Valley Times.'
			)
		).toBeInTheDocument();
	} );

	it( 'names the record picked by its name when two share it', async () => {
		const TWIN = {
			entity_key: 'organization:the-valley-times-east',
			name: 'The Valley Times',
		};
		wire.replies.names = {
			entities: [ PROPOSED, INTO, TWIN ],
			available: true,
		};
		wire.replies.merge_entities = { ok: true, event_id: 'ev-m2' };
		const dialog = await openMerge();
		const into = within( dialog ).getByRole( 'combobox', {
			name: 'Merge into',
		} );
		fireEvent.change( into, { target: { value: 'Valley' } } );
		fireEvent.click(
			await within( dialog ).findByRole( 'option', {
				name: `The Valley Times (${ TWIN.entity_key })`,
			} )
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);

		await waitFor( () =>
			expect( wire.sent( 'merge_entities' ) ).toEqual( [
				[
					`--from=${ PROPOSED.entity_key }`,
					`--into=${ TWIN.entity_key }`,
				],
			] )
		);
		expect(
			await screen.findByText(
				'Merged Valley Times into The Valley Times.'
			)
		).toBeInTheDocument();
	} );

	it( 'takes neither a key no offered record carries nor the merging entity’s own name', async () => {
		const dialog = await openMerge();
		const into = within( dialog ).getByRole( 'combobox', {
			name: 'Merge into',
		} );
		act( () => into.focus() );
		fireEvent.change( into, { target: { value: 'Valley' } } );
		await within( dialog ).findByRole( 'option', {
			name: 'The Valley Times',
		} );

		for ( const typed of [ 'organization:valley-annex', 'Valley Times' ] ) {
			fireEvent.change( into, { target: { value: typed } } );
			await within( dialog ).findByRole( 'option', {
				name: 'The Valley Times',
			} );
			expect(
				within( dialog ).queryByRole( 'option', {
					name: 'Valley Times',
				} )
			).toBeNull();
		}

		expect(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		).toBeDisabled();
	} );

	it( 'keeps Merge off until a record is picked', async () => {
		const dialog = await openMerge();
		expect(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		).toBeDisabled();
	} );

	it( 'shows a refused merge in the dialog, the entity back in its queue', async () => {
		wire.replies.merge_entities = () =>
			new Error(
				'cannot merge organization:valley-times (organization) into person:jo (person)'
			);
		const dialog = await openMerge();
		await pick( dialog );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Merge' } )
		);
		expect(
			await within( dialog ).findByText(
				/cannot merge organization:valley-times/
			)
		).toBeInTheDocument();
		expect(
			screen.queryByText( 'Merged Valley Times into The Valley Times.' )
		).toBeNull();
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		expect(
			within( queue( 'Suggested names' ) ).getByRole( 'button', {
				name: 'Accept Valley Times',
			} )
		).toBeInTheDocument();
	} );
} );

describe( 'Review — themes', () => {
	const KEEP_FIRST = 'Keep "kiosk offboarding"';
	const KEEP_SECOND = 'Keep "kiosk offboarding from platform"';

	it( 'heads each suggested pair with both themes, then its stories, shared entities and words', async () => {
		renderReview();
		const themes = queue( 'Similar themes' );
		const first = await within( themes ).findByText( 'kiosk offboarding' );
		const heading = first.parentElement;
		expect( heading ).toHaveTextContent(
			/^kiosk offboarding ↔ kiosk offboarding from platform$/
		);
		expect( heading ).toContainElement(
			within( themes ).getByText( 'kiosk offboarding from platform' )
		);
		expect(
			within( themes ).getByText(
				'4 stories and 2 stories; 3 entities in common; both say "kiosk" and "offboarding"'
			)
		).toHaveClass( 'is-muted' );
	} );

	it( 'says one shared word alone, and one story and one entity in the singular', async () => {
		wire.replies.theme_suggestions = {
			suggestions: [
				{
					themes: [
						{ theme: 'harbor grants', stories: 1 },
						{ theme: 'harbor grant renewals', stories: 5 },
					],
					words: [ 'harbor' ],
					shared: 1,
				},
			],
			total: 1,
			available: true,
		};
		renderReview();
		expect(
			await within( queue( 'Similar themes' ) ).findByText(
				'1 story and 5 stories; 1 entity in common; both say "harbor"'
			)
		).toBeInTheDocument();
	} );

	it( 'drops a remembered How this was read once a theme merge lands, since it reads each theme as its survivor', async () => {
		const traceProbe = () => (
			<Probe
				scope="probe:trace"
				command="trace"
				args={ [ 'feed:kiosk-exit-61' ] }
			/>
		);
		wire.replies.trace = {
			item_key: 'feed:kiosk-exit-61',
			steps: [],
			available: true,
		};
		const seeded = render( traceProbe() );
		await screen.findByText( 'trace read' );
		seeded.unmount();
		wire.replies.merge_theme = { ok: true, event_id: 'ev-t' };
		const view = renderReview( { canTune: true } );
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: KEEP_FIRST }
		);
		fakeUndoClock();
		fireEvent.click( keep );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'merge_theme' ) ).toHaveLength( 1 )
		);
		await waitFor( () =>
			expect( wire.sent( 'theme_suggestions' ) ).toHaveLength( 2 )
		);
		view.unmount();
		jest.useRealTimers();
		wire.replies.trace = never;
		render( traceProbe() );
		expect( screen.getByText( 'trace owed' ) ).toBeInTheDocument();
	} );

	it( 'says when no theme pair is suggested', async () => {
		wire.replies.theme_suggestions = {
			suggestions: [],
			total: 0,
			available: true,
		};
		renderReview();
		expect(
			await within( queue( 'Similar themes' ) ).findByText(
				'Nothing to review. New suggestions arrive as items are read.'
			)
		).toBeInTheDocument();
	} );

	it( 'keeps the first theme by merging the second into it, the pair showing its verdict, then re-reads', async () => {
		wire.replies.merge_theme = { ok: true, event_id: 'ev-t' };
		renderReview( { canTune: true } );
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: KEEP_FIRST }
		);
		fakeUndoClock();
		fireEvent.click( keep );
		expect(
			screen.getByRole( 'tab', { name: 'Similar themes 12' } )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'merge_theme' ) ).toEqual( [
				[
					'--from=kiosk offboarding from platform',
					'--into=kiosk offboarding',
				],
			] )
		);
		const pair = within( queue( 'Similar themes' ) )
			.getByText( 'kiosk offboarding from platform' )
			.closest( '[data-review-key]' );
		expect( pair ).toHaveClass( 'is-muted' );
		expect( pair ).toHaveTextContent( 'Kept "kiosk offboarding".' );
		expect(
			within( pair ).queryByRole( 'button', { name: KEEP_SECOND } )
		).toBeNull();
		await waitFor( () =>
			expect( wire.sent( 'theme_suggestions' ) ).toHaveLength( 2 )
		);
	} );

	it( 'keeps the second theme by merging the first into it', async () => {
		wire.replies.merge_theme = never;
		renderReview( { canTune: true } );
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: KEEP_SECOND }
		);
		fakeUndoClock();
		fireEvent.click( keep );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'merge_theme' ) ).toEqual( [
				[
					'--from=kiosk offboarding',
					'--into=kiosk offboarding from platform',
				],
			] )
		);
	} );

	it( 'hides a pair called not the same for this reader alone, sending nothing', async () => {
		renderReview( { canTune: true } );
		const themes = queue( 'Similar themes' );
		fireEvent.click(
			await within( themes ).findByRole( 'button', {
				name: 'Not the same',
			} )
		);
		expect(
			within( themes ).queryByRole( 'button', { name: KEEP_FIRST } )
		).toBeNull();
		expect(
			screen.getByRole( 'tab', { name: 'Similar themes 11' } )
		).toBeInTheDocument();
		await act( async () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		await settle();
		expect( wire.sent( 'merge_theme' ) ).toEqual( [] );
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
	} );

	it( 'returns a refused merge to its pair with the refusal under it', async () => {
		wire.replies.merge_theme = () =>
			new Error(
				'kiosk offboarding merges into kiosk exit; merge kiosk offboarding from platform into kiosk exit instead'
			);
		renderReview( { canTune: true } );
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: KEEP_FIRST }
		);
		fakeUndoClock();
		fireEvent.click( keep );
		await elapse( UNDO_MS );
		const refusal = await screen.findByText( /into kiosk exit instead/ );
		const pair = rowOf(
			within( queue( 'Similar themes' ) ).getByRole( 'button', {
				name: KEEP_SECOND,
			} )
		);
		expect( pair ).toContainElement( refusal );
		expect( document.activeElement ).toBe( pair );
	} );
} );

describe( 'Review — keyboard', () => {
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'group', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );
	// A judgment is focused: one row, carrying the tabindex the ring keys on.
	const focusedHolds = ( name ) => {
		expect( document.activeElement ).toHaveAttribute( 'tabindex', '-1' );
		expect( document.activeElement ).toContainElement(
			screen.getByRole( 'button', { name } )
		);
	};

	it( 'names each list and describes its keys to a screen reader', async () => {
		renderReview( { canTune: true } );
		for ( const [ name, entity ] of [
			[ 'Suggested matches', 'Acme Quillsheet' ],
			[ 'Suggested names', 'Valley Times' ],
		] ) {
			await within( queue( name ) ).findByRole( 'button', {
				name: entity,
			} );
			expect( list( name ) ).toHaveAccessibleDescription( LEGEND );
		}
	} );

	it( 'moves through the judgments with j and k, each focusable', async () => {
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		focusedHolds( 'Acme Quillsheet' );
		key( document.activeElement, 'j' );
		focusedHolds( 'Valley Ledger' );
		key( document.activeElement, 'j' );
		focusedHolds( 'Valley Ledger' );
		key( document.activeElement, 'k' );
		focusedHolds( 'Acme Quillsheet' );
		expect( links ).toHaveAttribute( 'tabindex', '0' );
	} );

	it( 'confirms with y and rejects with n, focus moving to the next row', async () => {
		wire.replies.confirm_link = never;
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		fakeUndoClock();
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		await act( async () => key( document.activeElement, 'y' ) );
		focusedHolds( 'Valley Ledger' );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( [
				[
					`--item=${ LINK.item_key }`,
					`--entity=${ LINK.entity_key }`,
				],
			] )
		);
		focusedHolds( 'Valley Ledger' );
		await act( async () => key( document.activeElement, 'n' ) );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
		judgedAs( 'Acme Quillsheet', 'Confirmed.' );
		judgedAs( 'Valley Ledger', 'Rejected.' );
	} );

	it( 'focuses the row above once the last row is judged', async () => {
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Valley Ledger',
		} );
		fakeUndoClock();
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'k' );
		key( document.activeElement, 'j' );
		await act( async () => key( document.activeElement, 'n' ) );
		focusedHolds( 'Acme Quillsheet' );
		await elapse( UNDO_MS );
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ SIBLING.item_key }`,
				`--entity=${ SIBLING.entity_key }`,
			],
		] );
		focusedHolds( 'Acme Quillsheet' );
	} );

	it( 'accepts an entity with y, and opens its merge with m, the keys idle in the dialog', async () => {
		wire.replies.accept_entity = never;
		wire.replies.names = { entities: [], available: true };
		renderReview( { canTune: true } );
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: 'Valley Times',
		} );
		fakeUndoClock();
		const entities = list( 'Suggested names' );
		entities.focus();
		key( entities, 'j' );
		focusedHolds( 'Valley Times' );
		await act( async () => key( document.activeElement, 'm' ) );
		const dialog = screen.getByRole( 'dialog', {
			name: 'Merge Valley Times into…',
		} );
		const into = within( dialog ).getByRole( 'combobox', {
			name: 'Merge into',
		} );
		expect( document.activeElement ).toBe( into );
		key( into, 'y' );
		key( into, 'n' );
		expect( wire.sent( 'accept_entity' ) ).toEqual( [] );
		expect( wire.sent( 'reject_entity' ) ).toEqual( [] );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		rowOf(
			within( queue( 'Suggested names' ) ).getByRole( 'button', {
				name: 'Valley Times',
			} )
		).focus();
		await act( async () => key( document.activeElement, 'y' ) );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'accept_entity' ) ).toEqual( [
				[ `--key=${ PROPOSED.entity_key }` ],
			] )
		);
	} );

	it( 'ignores a key with a modifier, and a judgment key on no row', async () => {
		wire.replies.confirm_link = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		fireEvent.keyDown( document.activeElement, {
			key: 'y',
			metaKey: true,
		} );
		key( document.body, 'y' );
		focusedHolds( 'Acme Quillsheet' );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'offers a reader the moves and no judgment', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		key( document.activeElement, 'y' );
		focusedHolds( 'Acme Quillsheet' );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'opens the legend in a popover on ?, and closes it on Escape', async () => {
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		expect( screen.getAllByText( LEGEND ) ).toHaveLength( 1 );
		key( document.activeElement, '?' );
		const shown = screen.getAllByText( LEGEND );
		expect( shown ).toHaveLength( 2 );
		expect( shown[ 1 ].closest( '.components-popover' ) ).not.toBeNull();
		focusedHolds( 'Acme Quillsheet' );
		key( document.activeElement, 'Escape' );
		expect( screen.getAllByText( LEGEND ) ).toHaveLength( 1 );
	} );
} );

describe( 'Review — the keys from anywhere on the page', () => {
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );
	const row = ( name ) => {
		const chip = screen.queryByRole( 'button', { name } );
		return chip ? rowOf( chip ) : null;
	};
	const rejectAcme = () =>
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Reject Acme Quillsheet' } )
		);

	beforeEach( () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
	} );

	function renderAmidControls() {
		return renderOnPage(
			<>
				<button type="button">Briefing</button>
				<input aria-label="Search the graph" />
				<div role="dialog" aria-label="Save to folder">
					<button type="button">Close</button>
				</div>
				<Review canTune onOpenEntity={ jest.fn() } />
			</>
		);
	}

	it( 'focuses the first row with j or k pressed outside every row', async () => {
		renderAmidControls();
		await screen.findByRole( 'button', { name: 'Valley Ledger' } );
		key( document.body, 'k' );
		expect( document.activeElement ).toBe( row( 'Acme Quillsheet' ) );
		key( document.activeElement, 'j' );
		expect( document.activeElement ).toBe( row( 'Valley Ledger' ) );
		const outside = screen.getByRole( 'button', { name: 'Briefing' } );
		outside.focus();
		key( outside, 'j' );
		expect( document.activeElement ).toBe( row( 'Acme Quillsheet' ) );
	} );

	it( 'undoes with z pressed outside every row', async () => {
		renderAmidControls();
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		fakeUndoClock();
		rejectAcme();
		expect( row( 'Acme Quillsheet' ) ).toBeNull();
		document.activeElement.blur();
		await act( async () => key( document.body, 'z' ) );
		expect( document.activeElement ).toBe( row( 'Acme Quillsheet' ) );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'leaves a key alone in a text field and in a dialog', async () => {
		renderAmidControls();
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		fakeUndoClock();
		rejectAcme();
		const field = screen.getByRole( 'textbox', {
			name: 'Search the graph',
		} );
		const close = screen.getByRole( 'button', { name: 'Close' } );
		for ( const target of [ field, close ] ) {
			target.focus();
			key( target, 'j' );
			await act( async () => key( target, 'z' ) );
			expect( document.activeElement ).toBe( target );
			expect( row( 'Acme Quillsheet' ) ).toBeNull();
		}
	} );

	it( 'captures no key anywhere while the keys are off', async () => {
		renderAmidControls();
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		fireEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Turn off single-key shortcuts',
			} )
		);
		fakeUndoClock();
		rejectAcme();
		document.activeElement.blur();
		key( document.body, 'j' );
		await act( async () => key( document.body, 'z' ) );
		expect( document.activeElement ).toBe( document.body );
		expect( row( 'Acme Quillsheet' ) ).toBeNull();
	} );

	it( 'takes no key on the themes queue, which names none', async () => {
		wire.replies.merge_theme = never;
		renderAmidControls();
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: 'Keep "kiosk offboarding"' }
		);
		expect( screen.queryByText( LEGEND ) ).toBeNull();
		fakeUndoClock();
		fireEvent.click( keep );
		document.activeElement.blur();
		key( document.body, 'j' );
		await act( async () => key( document.body, 'z' ) );
		expect( document.activeElement ).toBe( document.body );
		expect(
			within( queue( 'Similar themes' ) ).queryByRole( 'button', {
				name: 'Keep "kiosk offboarding"',
			} )
		).toBeNull();
	} );

	it( 'removes its page listener when it unmounts', async () => {
		const added = jest.spyOn( document, 'addEventListener' );
		const removed = jest.spyOn( document, 'removeEventListener' );
		const { unmount } = renderAmidControls();
		await screen.findByRole( 'button', { name: 'Acme Quillsheet' } );
		const listeners = added.mock.calls
			.filter( ( [ type ] ) => 'keydown' === type )
			.map( ( [ , listener ] ) => listener );
		expect( listeners ).toHaveLength( 1 );
		unmount();
		expect( removed ).toHaveBeenCalledWith( 'keydown', listeners[ 0 ] );
		added.mockRestore();
		removed.mockRestore();
	} );
} );

describe.each( [
	[ '390 px', true ],
	[ '1,440 px', false ],
] )( 'Review — its states at %s', ( _, narrow ) => {
	let viewport;

	beforeEach( () => {
		viewport = installViewport( narrow );
	} );

	afterEach( () => {
		viewport.restore();
	} );

	const panel = () =>
		document.getElementById( 'nodes-tab-host-panel-review' );

	it( 'stands five placeholder rows in for a queue still owed', async () => {
		wire.replies.review = never;
		renderReview();
		const loading = await within( panel() ).findByRole( 'status', {
			name: 'Loading',
		} );
		expect(
			loading.querySelectorAll( '.newspack-intelligence__item' )
		).toHaveLength( 5 );
	} );

	it( 'says an empty queue has nothing to review', async () => {
		wire.replies.review = ( [ what ] ) => ( {
			[ what ]: [],
			total: 0,
			available: true,
		} );
		renderReview();
		expect(
			await within( panel() ).findByText(
				'Nothing to review. New suggestions arrive as items are read.'
			)
		).toBeInTheDocument();
	} );

	it( 'says a queue that could not be read, offering to ask again', async () => {
		wire.replies.review = () => new Error( 'review refused' );
		renderReview();
		expect(
			await within( panel() ).findByText(
				/Intelligence could not be read just now/
			)
		).toBeInTheDocument();
		expect(
			within( panel() ).getByRole( 'button', { name: 'Try again' } )
		).toBeInTheDocument();
	} );
} );

describe( 'Review — undo', () => {
	const HARBOR = {
		...LINK,
		entity_key: 'organization:harbor-ledger',
		name: 'Harbor Ledger',
	};
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'group', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );
	const harborRow = () => {
		const chip = within( queue( 'Suggested matches' ) ).queryByRole(
			'button',
			{ name: 'Harbor Ledger' }
		);
		return chip ? rowOf( chip ) : null;
	};

	beforeEach( () => {
		speak.mockClear();
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ HARBOR, SIBLING ],
						total: TOTALS.links,
						available: true,
				  }
				: {
						entities: [ PROPOSED ],
						total: TOTALS.entities,
						available: true,
				  };
		wire.replies.reject_link = { ok: true, event_id: 'ev-r' };
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
		wire.replies.accept_entity = { ok: true, event_id: 'ev-a' };
		wire.replies.merge_theme = { ok: true, event_id: 'ev-t' };
	} );

	async function focusHarbor() {
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Harbor Ledger',
		} );
		fakeUndoClock();
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		expect( document.activeElement ).toBe( harborRow() );
	}

	it( 'hides a row judged with n, and z brings it and its focus back with nothing sent', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		expect( harborRow() ).toBeNull();
		await elapse( UNDO_MS - 1 );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await act( async () => key( document.activeElement, 'z' ) );
		expect( harborRow() ).not.toBeNull();
		expect( document.activeElement ).toBe( harborRow() );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'sends a judgment once, after the undo window', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await elapse( UNDO_MS - 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await elapse( 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ HARBOR.item_key }`,
				`--entity=${ HARBOR.entity_key }`,
			],
		] );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( harborRow() ).toBeNull();
	} );

	it( 'offers the undo in a snackbar and announces it politely', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		const snackbar = screen.getByText( 'Rejected Harbor Ledger.' );
		expect( snackbar.closest( '.components-snackbar' ) ).toHaveTextContent(
			'Rejected Harbor Ledger.Undo'
		);
		expect( speak ).toHaveBeenCalledWith(
			'Rejected Harbor Ledger.',
			'polite'
		);
		expect( speak ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'undoes the judgment from the snackbar, the row focused again', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'y' ) );
		expect(
			screen.getByText( 'Confirmed Harbor Ledger.' )
		).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		expect( document.activeElement ).toBe( harborRow() );
		expect( screen.queryByText( 'Confirmed Harbor Ledger.' ) ).toBeNull();
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'undoes only the most recent judgment still pending', async () => {
		const prairie = {
			...SIBLING,
			entity_key: 'organization:prairie-signal',
			name: 'Prairie Signal',
		};
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ HARBOR, SIBLING, prairie ].map( ( link ) => ( {
							...link,
							item_links: 3,
						} ) ),
						total: 3,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await act( async () => key( document.activeElement, 'y' ) );
		expect(
			screen.getByText( 'Confirmed Valley Ledger.' )
		).toBeInTheDocument();
		await act( async () => key( document.activeElement, 'z' ) );
		expect(
			within( queue( 'Suggested matches' ) ).getByRole( 'button', {
				name: 'Valley Ledger',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Rejected Harbor Ledger.' )
		).toBeInTheDocument();
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'sends a pending judgment once on a tab switch, and withdraws its undo', async () => {
		const { unmount } = renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		unmount();
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ HARBOR.item_key }`,
				`--entity=${ HARBOR.entity_key }`,
			],
		] );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
	} );

	it( 'sends every pending judgment once when the page is left', async () => {
		renderReview( { canTune: true } );
		await focusHarbor();
		await act( async () => key( document.activeElement, 'n' ) );
		await act( async () => key( document.activeElement, 'y' ) );
		await act( async () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toHaveLength( 1 );
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		expect( wire.sent( 'confirm_link' ) ).toHaveLength( 1 );
	} );

	it( 'sends an accepted entity as its tab gives way to another, a kept theme then waiting out its own window', async () => {
		renderReview( { canTune: true } );
		const control = await within( queue( 'Suggested names' ) ).findByRole(
			'button',
			{
				name: 'Accept Valley Times',
			}
		);
		fakeUndoClock();
		fireEvent.click( control );
		expect(
			screen.getByText( 'Accepted Valley Times.' )
		).toBeInTheDocument();
		expect( wire.sent( 'accept_entity' ) ).toEqual( [] );
		fireEvent.click(
			await within( queue( 'Similar themes' ) ).findByRole( 'button', {
				name: 'Keep "kiosk offboarding"',
			} )
		);
		await settle();
		expect( wire.sent( 'accept_entity' ) ).toEqual( [
			[ `--key=${ PROPOSED.entity_key }` ],
		] );
		expect(
			screen.getByText(
				'Merged kiosk offboarding from platform into kiosk offboarding.'
			)
		).toBeInTheDocument();
		await elapse( UNDO_MS - 1 );
		await settle();
		expect( wire.sent( 'merge_theme' ) ).toEqual( [] );
		await elapse( 1 );
		await settle();
		expect( wire.sent( 'merge_theme' ) ).toEqual( [
			[
				'--from=kiosk offboarding from platform',
				'--into=kiosk offboarding',
			],
		] );
	} );

	it( 'sends a judgment as its tab gives way to another, z there undoing nothing', async () => {
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: 'Reject Harbor Ledger',
			} )
		);
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: 'Accept Valley Times',
		} );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 )
		);
		fireEvent.keyDown( document.body, { key: 'z' } );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		judgedAs( 'Harbor Ledger', 'Rejected.', queue( 'Suggested matches' ) );
		await settle();
	} );

	it( 'brings a theme pair back with its focus on an undo', async () => {
		renderReview( { canTune: true } );
		const keep = await within( queue( 'Similar themes' ) ).findByRole(
			'button',
			{ name: 'Keep "kiosk offboarding"' }
		);
		fakeUndoClock();
		fireEvent.click( keep );
		fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
		await settle();
		expect( document.activeElement ).toHaveAttribute( 'data-review-row' );
		expect( document.activeElement ).toHaveTextContent(
			/^kiosk offboarding.*kiosk offboarding from platform/
		);
	} );
} );

describe( 'Review — the keys legend and switch', () => {
	const SWITCH = 'Turn off single-key shortcuts';
	const list = ( name ) =>
		within( queue( name ) ).getByRole( 'group', { name } );
	const key = ( target, k ) => fireEvent.keyDown( target, { key: k } );

	beforeEach( () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-c' };
	} );

	it( 'shows the legend as visible text', async () => {
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const legend = screen.getByText( LEGEND );
		expect( legend.closest( '.components-visually-hidden' ) ).toBeNull();
	} );

	it( 'takes no single key while shortcuts are turned off, remembered per browser', async () => {
		const { unmount } = renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		const off = screen.getByRole( 'checkbox', { name: SWITCH } );
		expect( off ).not.toBeChecked();
		fireEvent.click( off );
		expect( off ).toBeChecked();
		expect( screen.queryByText( LEGEND ) ).toBeNull();
		unmount();

		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Acme Quillsheet',
		} );
		expect(
			screen.getByRole( 'checkbox', { name: SWITCH } )
		).toBeChecked();
		const links = list( 'Suggested matches' );
		links.focus();
		key( links, 'j' );
		expect( document.activeElement ).toBe( links );
		const row = rowOf(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		);
		row.focus();
		await act( async () => key( row, 'y' ) );
		key( row, '?' );
		expect( screen.queryByText( LEGEND ) ).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		await act( async () => {
			window.dispatchEvent( new Event( 'pagehide' ) );
		} );
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	describe( 'when narrow', () => {
		let viewport;

		beforeEach( () => {
			viewport = installViewport( true );
		} );

		afterEach( () => {
			viewport.restore();
		} );

		it( 'picks the queue from a select, and says the shortcuts need a keyboard', async () => {
			renderReview( { canTune: true } );
			const pick = screen.getByRole( 'combobox', { name: 'View' } );
			expect( screen.queryByRole( 'tab' ) ).toBeNull();
			expect(
				await within( pick ).findByRole( 'option', {
					name: 'Suggested names 7',
				} )
			).toBeInTheDocument();
			expect(
				await screen.findByRole( 'button', { name: 'Acme Quillsheet' } )
			).toBeInTheDocument();
			expect(
				screen.getByText( 'Shortcuts need a keyboard.' )
			).toBeInTheDocument();
			expect( screen.queryByText( LEGEND ) ).toBeNull();
			expect(
				screen.queryByRole( 'checkbox', { name: SWITCH } )
			).toBeNull();
			fireEvent.change( pick, { target: { value: 'entities' } } );
			expect(
				await screen.findByRole( 'button', { name: 'Valley Times' } )
			).toBeInTheDocument();
			expect(
				screen.queryByRole( 'button', { name: 'Acme Quillsheet' } )
			).toBeNull();
		} );
	} );
} );

describe( 'Review — what a judgment dirties', () => {
	const linksRead = () =>
		wire
			.sent( 'review' )
			.filter(
				( [ w, size ] ) => 'links' === w && '--limit=50' === size
			);

	it( 'leaves the health strip remembered once a link judgment lands', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-harbor-c' };
		await rememberHealth();
		const view = renderReview( { canTune: true } );
		const control = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{
				name: 'Confirm Acme Quillsheet',
			}
		);
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () => expect( linksRead() ).toHaveLength( 2 ) );
		view.unmount();
		jest.useRealTimers();
		wire.replies.health = never;
		render( healthProbe() );
		expect( screen.getByText( 'health remembered' ) ).toBeInTheDocument();
	} );

	it( 'has the page read its health and its lens list again once an accept lands', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-harbor-a' };
		wire.replies.lenses = { lenses: [], available: true };
		wire.replies.health = { sources: [], dead_clients: [] };
		const sourcesRead = () =>
			wire.sent( 'health' ).filter( ( [ what ] ) => 'sources' === what );
		renderReview( { canTune: true } );
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 1 )
		);
		expect( sourcesRead() ).toHaveLength( 1 );
		const control = await within( queue( 'Suggested names' ) ).findByRole(
			'button',
			{
				name: 'Accept Valley Times',
			}
		);
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'accept_entity' ) ).toHaveLength( 1 )
		);
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 2 )
		);
		expect( sourcesRead() ).toHaveLength( 2 );
	} );
} );

describe( 'Review — a checked run', () => {
	const HARBOR_ITEM = {
		...LINK,
		item_key: 'linear:LW-44',
		entity_key: 'organization:harbor-ledger',
		name: 'Harbor Ledger',
		title: 'Harbor Ledger renews its plan',
		url: 'https://linear.example/LW-44',
		evidence: '',
		item_links: 1,
	};
	const PRAIRIE_ITEM = {
		...SIBLING,
		item_key: 'feed:prairie-signal-9',
		entity_key: 'organization:prairie-signal',
		name: 'Prairie Signal',
		title: 'Prairie Signal hires an editor',
		url: 'https://prairie.example/9',
		evidence: '',
		item_links: 1,
	};
	const COASTLINE = {
		...PROPOSED,
		entity_key: 'organization:coastline-weekly',
		name: 'Coastline Weekly',
	};
	const RUN = [ { ...LINK, item_links: 1 }, HARBOR_ITEM, PRAIRIE_ITEM ];
	const tokensOf = ( link ) => [
		`--item=${ link.item_key }`,
		`--entity=${ link.entity_key }`,
	];

	// jsdom lays nothing out, so it has no scrollIntoView for a checked row.
	const { scrollIntoView } = window.Element.prototype;

	beforeEach( () => {
		speak.mockClear();
		window.Element.prototype.scrollIntoView = jest.fn();
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? { links: RUN, total: TOTALS.links, available: true }
				: {
						entities: [ PROPOSED, COASTLINE ],
						total: TOTALS.entities,
						available: true,
				  };
	} );

	afterEach( () => {
		window.Element.prototype.scrollIntoView = scrollIntoView;
	} );

	const check = ( name ) =>
		fireEvent.click(
			screen.getByRole( 'checkbox', { name: `Select ${ name }` } )
		);

	/**
	 * Check every match row, then press one of the run's answers.
	 *
	 * @param {string} answer The answer's words.
	 */
	async function judgeRun( answer ) {
		await within( queue( 'Suggested matches' ) ).findByRole( 'checkbox', {
			name: `Select ${ LINK.title }`,
		} );
		RUN.forEach( ( link ) => check( link.title ) );
		fakeUndoClock();
		await act( async () =>
			fireEvent.click( screen.getByRole( 'button', { name: answer } ) )
		);
	}

	const shownRow = ( name ) =>
		within( queue( 'Suggested matches' ) ).queryByRole( 'button', {
			name,
		} );

	it( 'checks rows into a run, the toolbar counting it, and offers a reader none', async () => {
		const { unmount } = renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'checkbox', {
			name: `Select ${ LINK.title }`,
		} );
		check( LINK.title );
		check( PRAIRIE_ITEM.title );
		expect(
			screen.getByRole( 'checkbox', {
				name: `Select ${ HARBOR_ITEM.title }`,
			} )
		).not.toBeChecked();
		expect( screen.getByText( '2 Items selected' ) ).toBeInTheDocument();
		unmount();
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: 'Harbor Ledger',
		} );
		expect(
			screen.queryByRole( 'checkbox', { name: /^Select/ } )
		).toBeNull();
	} );

	it( 'rejects a checked run with one No, sending each after one window', async () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-run' };
		renderReview( { canTune: true } );
		await judgeRun( 'No' );
		RUN.forEach( ( link ) => judgedAs( link.name, 'Rejected.' ) );
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 41' } )
		).toBeInTheDocument();
		expect( speak ).toHaveBeenCalledTimes( 1 );
		expect( speak ).toHaveBeenCalledWith( 'Rejected 3 matches.', 'polite' );
		await elapse( UNDO_MS - 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await elapse( 1 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( RUN.map( tokensOf ) );
	} );

	it( 'confirms a checked run with one Yes, and one Undo brings all of it back', async () => {
		wire.replies.confirm_link = { ok: true, event_id: 'ev-run' };
		renderReview( { canTune: true } );
		await judgeRun( 'Yes, it is' );
		const snackbar = screen
			.getByText( 'Confirmed 3 matches.' )
			.closest( '.components-snackbar' );
		expect(
			within( snackbar ).getAllByRole( 'button', { name: 'Undo' } )
		).toHaveLength( 1 );
		fireEvent.click(
			within( snackbar ).getByRole( 'button', { name: 'Undo' } )
		);
		RUN.forEach( ( link ) =>
			expect( shownRow( link.name ) ).not.toBeNull()
		);
		expect( document.activeElement ).toBe( rowOf( shownRow( LINK.name ) ) );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'confirm_link' ) ).toEqual( [] );
	} );

	it( 'returns a row whose write in the run is refused, the refusal under it, focused', async () => {
		wire.replies.confirm_link = ( tokens ) =>
			tokens.includes( `--item=${ HARBOR_ITEM.item_key }` )
				? new Error( 'no link: linear:LW-44' )
				: { ok: true, event_id: 'ev-run' };
		renderReview( { canTune: true } );
		await judgeRun( 'Yes, it is' );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( wire.sent( 'confirm_link' ) ).toEqual( RUN.map( tokensOf ) )
		);
		const refusal = await screen.findByText( 'no link: linear:LW-44' );
		const row = rowOf( shownRow( 'Confirm Harbor Ledger' ) );
		expect( row ).toContainElement( refusal );
		expect( document.activeElement ).toBe( row );
		judgedAs( LINK.name, 'Confirmed.' );
		judgedAs( PRAIRIE_ITEM.name, 'Confirmed.' );
	} );

	it( 'returns every row a run refuses, each with its own refusal', async () => {
		wire.replies.confirm_link = ( tokens ) => {
			if ( tokens.includes( `--item=${ HARBOR_ITEM.item_key }` ) ) {
				return new Error( 'no link: linear:LW-44' );
			}
			return tokens.includes( `--item=${ PRAIRIE_ITEM.item_key }` )
				? new Error( 'no link: feed:prairie-signal-9' )
				: { ok: true, event_id: 'ev-run' };
		};
		renderReview( { canTune: true } );
		await judgeRun( 'Yes, it is' );
		await elapse( UNDO_MS );
		const harbor = await screen.findByText( 'no link: linear:LW-44' );
		const prairie = await screen.findByText(
			'no link: feed:prairie-signal-9'
		);
		expect( rowOf( shownRow( 'Confirm Harbor Ledger' ) ) ).toContainElement(
			harbor
		);
		expect(
			rowOf( shownRow( 'Confirm Prairie Signal' ) )
		).toContainElement( prairie );
	} );

	it( 'judges every match a checked row asks about', async () => {
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ LINK, SIBLING, HARBOR_ITEM ],
						total: TOTALS.links,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		wire.replies.reject_link = { ok: true, event_id: 'ev-run' };
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'checkbox', {
			name: `Select ${ LINK.title }`,
		} );
		check( LINK.title );
		fakeUndoClock();
		await act( async () =>
			fireEvent.click( screen.getByRole( 'button', { name: 'No' } ) )
		);
		expect( speak ).toHaveBeenCalledWith( 'Rejected 2 matches.', 'polite' );
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual(
			[ LINK, SIBLING ].map( tokensOf )
		);
		expect( shownRow( HARBOR_ITEM.name ) ).not.toBeNull();
	} );

	it( 'accepts a checked run of names with one Add it', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-run' };
		renderReview( { canTune: true } );
		await within( queue( 'Suggested names' ) ).findByRole( 'checkbox', {
			name: `Select ${ COASTLINE.name }`,
		} );
		check( PROPOSED.name );
		check( COASTLINE.name );
		fakeUndoClock();
		await act( async () =>
			fireEvent.click( screen.getByRole( 'button', { name: 'Add it' } ) )
		);
		expect( speak ).toHaveBeenCalledWith( 'Accepted 2 names.', 'polite' );
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'accept_entity' ) ).toEqual( [
			[ `--key=${ PROPOSED.entity_key }` ],
			[ `--key=${ COASTLINE.entity_key }` ],
		] );
	} );

	it( 'answers a checked run from a narrow screen by named glyphs', async () => {
		const viewport = installViewport( true );
		wire.replies.reject_link = never;
		try {
			renderReview( { canTune: true } );
			await screen.findByRole( 'checkbox', {
				name: `Select ${ LINK.title }`,
			} );
			check( LINK.title );
			expect(
				screen.getByRole( 'button', { name: 'Yes, it is' } )
			).toBeInTheDocument();
			fakeUndoClock();
			await act( async () =>
				fireEvent.click( screen.getByRole( 'button', { name: 'No' } ) )
			);
			expect( speak ).toHaveBeenCalledWith(
				'Rejected 1 match.',
				'polite'
			);
		} finally {
			viewport.restore();
		}
	} );

	it( 'offers no answer for a checked item whose every match is judged', async () => {
		wire.replies.reject_link = never;
		wire.replies.review = ( [ what ] ) =>
			'links' === what
				? {
						links: [ LINK, SIBLING ],
						total: TOTALS.links,
						available: true,
				  }
				: { entities: [], total: 0, available: true };
		renderReview( { canTune: true } );
		for ( const link of [ LINK, SIBLING ] ) {
			fireEvent.click(
				await within( queue( 'Suggested matches' ) ).findByRole(
					'button',
					{ name: `Reject ${ link.name }` }
				)
			);
		}
		check( LINK.title );
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'No' } ) ).toBeNull();
		expect(
			screen.queryByRole( 'button', { name: 'Yes, it is' } )
		).toBeNull();
	} );

	it( 'offers no run on the themes queue', async () => {
		renderReview( { canTune: true } );
		await within( queue( 'Similar themes' ) ).findByRole( 'button', {
			name: 'Not the same',
		} );
		expect(
			screen.queryByRole( 'checkbox', { name: /^Select/ } )
		).toBeNull();
	} );
} );

describe( 'Review — pages', () => {
	const PAGE_TWO = {
		...LINK,
		item_key: 'linear:LW-208',
		entity_key: 'organization:pinecrest-courier',
		name: 'Pinecrest Courier',
		title: 'Pinecrest Courier asks about renewal',
		url: 'https://linear.example/LW-208',
		evidence: '',
		item_links: 1,
	};
	const BESIDE = {
		...LINK,
		item_key: 'linear:LW-311',
		entity_key: 'organization:brackwater-post',
		name: 'Brackwater Post',
		title: 'Brackwater Post moves its newsletter',
		url: 'https://linear.example/LW-311',
		evidence: '',
		item_links: 1,
	};
	const SLID = {
		...LINK,
		item_key: 'linear:LW-299',
		entity_key: 'organization:gullwing-gazette',
		name: 'Gullwing Gazette',
		title: 'Gullwing Gazette asks for a new section',
		url: 'https://linear.example/LW-299',
		evidence: '',
		item_links: 1,
	};
	const LATER_TOO = {
		...LINK,
		item_key: 'linear:LW-207',
		entity_key: 'organization:eelgrass-echo',
		name: 'Eelgrass Echo',
		title: 'Eelgrass Echo pauses its print edition',
		url: 'https://linear.example/LW-207',
		evidence: '',
		item_links: 1,
	};
	const NAMED_LATER = {
		...PROPOSED,
		entity_key: 'organization:saltmarsh-daily',
		name: 'Saltmarsh Daily',
	};
	const NAMED_TOO = {
		...PROPOSED,
		entity_key: 'organization:tidepool-times',
		name: 'Tidepool Times',
	};
	const LONG = { links: 137, entities: 64 };
	const CURSORS = {
		links: [ null, 'after-LW-311', 'after-LW-208' ],
		entities: [ null, 'after-valley-times' ],
	};

	/**
	 * The cursor a read's tokens name, null for the first page.
	 *
	 * @param {string[]} tokens The read's tokens.
	 * @return {?string} The cursor.
	 */
	const afterIn = ( tokens ) =>
		tokens
			.find( ( token ) => token.startsWith( '--after=' ) )
			?.slice( '--after='.length ) ?? null;

	// jsdom lays nothing out, so it has no scrollIntoView for a checked row.
	const { scrollIntoView } = window.Element.prototype;

	afterEach( () => {
		window.Element.prototype.scrollIntoView = scrollIntoView;
	} );

	beforeEach( () => {
		wire.replies.review = ( tokens ) => {
			const later = null !== afterIn( tokens );
			if ( 'links' === tokens[ 0 ] ) {
				return {
					links: later
						? [ PAGE_TWO, LATER_TOO ]
						: [ LINK, SIBLING, BESIDE ],
					total: LONG.links,
					next: later ? CURSORS.links[ 2 ] : CURSORS.links[ 1 ],
					available: true,
				};
			}
			return {
				entities: later ? [ NAMED_LATER, NAMED_TOO ] : [ PROPOSED ],
				total: LONG.entities,
				next: later ? null : CURSORS.entities[ 1 ],
				available: true,
			};
		};
	} );

	const shown = ( name ) => screen.queryByRole( 'button', { name } );
	const next = () =>
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
	const previous = () =>
		fireEvent.click( screen.getByRole( 'button', { name: 'Previous' } ) );
	const linkReads = () =>
		wire
			.sent( 'review' )
			.filter(
				( [ what, size ] ) => 'links' === what && '--limit=50' === size
			);
	const linkReadsAfter = ( cursor ) =>
		linkReads().filter( ( tokens ) => cursor === afterIn( tokens ) );

	it( 'sends a judgment as the page turns, z on the next page undoing nothing', async () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-turned' };
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: `Reject ${ SIBLING.name }`,
			} )
		);
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toEqual( [
				[
					`--item=${ SIBLING.item_key }`,
					`--entity=${ SIBLING.entity_key }`,
				],
			] )
		);
		fireEvent.keyDown( document.body, { key: 'z' } );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 );
		previous();
		await screen.findByRole( 'button', { name: LINK.name } );
		judgedAs( SIBLING.name, 'Rejected.' );
	} );

	it( 'sends a judgment made on the page held while the next is owed once the next shows', async () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-held' };
		const answer = wire.replies.review;
		let release;
		const owed = new Promise( ( resolve ) => ( release = resolve ) );
		wire.replies.review = ( tokens ) =>
			CURSORS.links[ 1 ] === afterIn( tokens )
				? owed.then( () => answer( tokens ) )
				: answer( tokens );
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: `Reject ${ SIBLING.name }`,
		} );
		next();
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 1 )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ SIBLING.name }` } )
		);
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		await act( async () => release() );
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		await waitFor( () =>
			expect( wire.sent( 'reject_link' ) ).toHaveLength( 1 )
		);
	} );

	it( 'shows a refusal landing after the page turned at the top, naming its row, until that row is judged again', async () => {
		wire.replies.reject_link = ( tokens ) =>
			tokens.includes( `--item=${ SIBLING.item_key }` )
				? new Error( 'no link: the ledger' )
				: { ok: true, event_id: 'ev-page-two' };
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: `Reject ${ SIBLING.name }`,
			} )
		);
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		const notice = await screen.findByText( /no link: the ledger/ );
		expect( notice.closest( '[role="alert"]' ) ).toHaveTextContent(
			SIBLING.name
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ PAGE_TWO.name }` } )
		);
		await settle();
		expect( screen.getByText( /no link: the ledger/ ) ).toBeInTheDocument();
		previous();
		const reject = await screen.findByRole( 'button', {
			name: `Reject ${ SIBLING.name }`,
		} );
		expect( rowOf( reject ) ).toHaveTextContent( 'no link: the ledger' );
		expect( screen.getAllByText( /no link: the ledger/ ) ).toHaveLength(
			1
		);
		fireEvent.click( reject );
		expect( screen.queryByText( /no link: the ledger/ ) ).toBeNull();
	} );

	it( 'answers a send that throws as the page turns as that row’s refusal, Review still showing', async () => {
		renderReview( { canTune: true } );
		const reject = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: `Reject ${ SIBLING.name }` }
		);
		jest.spyOn(
			Core.node( 'intelligence:reject_link:fetch' ),
			'send'
		).mockImplementation( () => {
			throw new Error( 'the wire is down' );
		} );
		fireEvent.click( reject );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		expect(
			( await screen.findByText( /the wire is down/ ) ).closest(
				'[role="alert"]'
			)
		).toHaveTextContent( SIBLING.name );
		previous();
		const back = await screen.findByRole( 'button', {
			name: `Reject ${ SIBLING.name }`,
		} );
		expect( rowOf( back ) ).toHaveTextContent( 'the wire is down' );
	} );

	it( 'undoes with z a judgment on the page shown, sending nothing', async () => {
		renderReview( { canTune: true } );
		const reject = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: `Reject ${ SIBLING.name }` }
		);
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		fakeUndoClock();
		fireEvent.click( reject );
		judgedAs( SIBLING.name, 'Rejected.' );
		await act( async () =>
			fireEvent.keyDown( document.body, { key: 'z' } )
		);
		const back = screen.getByRole( 'button', {
			name: `Reject ${ SIBLING.name }`,
		} );
		expect( document.activeElement ).toBe( rowOf( back ) );
		expect( speak ).toHaveBeenLastCalledWith( 'Undone.', 'polite' );
		await elapse( UNDO_MS * 2 );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [] );
	} );

	it( 'renders a page turn whose read forgets no judgment no more than the turn itself asks', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		await settle();
		mockList.renders = 0;
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		await settle();
		expect( mockList.renders ).toBe( 3 );
	} );

	it( 'pages the matches by their total, page 2 reading after the cursor page 1 answered, the tab still the total', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		expect( shown( LINK.name ) ).toBeNull();
		expect( screen.getByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toEqual(
			expect.arrayContaining( [
				[ 'links', '--limit=50', '--after=after-LW-311' ],
			] )
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 137' } )
		).toBeInTheDocument();
	} );

	it( 'sends one read for its first page of matches, naming its size', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		expect(
			wire.sent( 'review' ).filter( ( [ what ] ) => 'links' === what )
		).toEqual( [ [ 'links', '--limit=50' ] ] );
	} );

	it( 'pages the names by their total, each page naming its size and the cursor it follows', async () => {
		renderReview();
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: PROPOSED.name,
		} );
		expect( screen.getByText( 'Page 1 of 2' ) ).toBeInTheDocument();
		next();
		await screen.findByRole( 'button', { name: NAMED_LATER.name } );
		expect( shown( PROPOSED.name ) ).toBeNull();
		expect( wire.sent( 'review' ) ).toEqual(
			expect.arrayContaining( [
				[ 'entities', '--limit=50' ],
				[ 'entities', '--limit=50', '--after=after-valley-times' ],
			] )
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested names 64' } )
		).toBeInTheDocument();
	} );

	it( 'reads page 2 after the last item page 1 shows, so an item sliding up into page 1 once its neighbour is judged is never skipped', async () => {
		let judged = false;
		wire.replies.reject_link = () => {
			judged = true;
			return { ok: true, event_id: 'ev-slid' };
		};
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			'links' === tokens[ 0 ] && null === afterIn( tokens ) && judged
				? {
						links: [ BESIDE, SLID ],
						total: LONG.links - 1,
						next: 'after-LW-299',
						available: true,
				  }
				: answer( tokens );
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		fakeUndoClock();
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ LINK.name }` } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ SIBLING.name }` } )
		);
		await elapse( UNDO_MS );
		expect(
			await screen.findByRole( 'button', { name: SLID.name } )
		).toBeInTheDocument();
		next();
		await waitFor( () =>
			expect( linkReadsAfter( 'after-LW-299' ) ).toHaveLength( 1 )
		);
		expect( linkReadsAfter( 'after-LW-311' ) ).toEqual( [] );
	} );

	it( 'keeps each queue’s place across a switch of queue, reading neither again', async () => {
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: PROPOSED.name,
		} );
		next();
		await screen.findByRole( 'button', { name: NAMED_LATER.name } );
		const sent = wire.sent( 'review' ).length;
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: PAGE_TWO.name,
		} );
		expect( screen.getByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: NAMED_LATER.name,
		} );
		expect( screen.getByText( 'Page 2 of 2' ) ).toBeInTheDocument();
		expect( wire.sent( 'review' ) ).toHaveLength( sent );
	} );

	it( 'returns to the page before when a later page answers no rows', async () => {
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			CURSORS.links[ 2 ] === afterIn( tokens )
				? { links: [], total: 40, next: null, available: true }
				: answer( tokens );
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		next();
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 2 ] ) ).toHaveLength( 1 )
		);
		expect( await screen.findByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		expect( shown( PAGE_TWO.name ) ).not.toBeNull();
	} );

	it( 'keeps the tab at the last total, the page it shows and its pager while a page is owed, a further Next asking nothing', async () => {
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			null !== afterIn( tokens ) ? never() : answer( tokens );
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 1 )
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested matches 137' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		expect( shown( LINK.name ) ).not.toBeNull();
		next();
		await settle();
		expect( linkReads() ).toHaveLength( 2 );
	} );

	it( 'keeps focus on Next across a turn of the page', async () => {
		const answer = wire.replies.review;
		let release;
		const owed = new Promise( ( resolve ) => ( release = resolve ) );
		wire.replies.review = ( tokens ) =>
			CURSORS.links[ 1 ] === afterIn( tokens )
				? owed.then( () => answer( tokens ) )
				: answer( tokens );
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		const button = screen.getByRole( 'button', { name: 'Next' } );
		act( () => button.focus() );
		fireEvent.click( button );
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 1 )
		);
		expect( document.activeElement ).toBe( button );
		await act( async () => release() );
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		expect( screen.getByText( 'Page 2 of 3' ) ).toBeInTheDocument();
		expect( document.activeElement ).toBe( button );
	} );

	it( 'keeps the names tab at its total and its page while a later page is owed', async () => {
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			'entities' === tokens[ 0 ] && null !== afterIn( tokens )
				? never()
				: answer( tokens );
		renderReview();
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: PROPOSED.name,
		} );
		next();
		await waitFor( () =>
			expect( wire.sent( 'review' ) ).toContainEqual( [
				'entities',
				'--limit=50',
				'--after=after-valley-times',
			] )
		);
		expect(
			screen.getByRole( 'tab', { name: 'Suggested names 64' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 2' ) ).toBeInTheDocument();
	} );

	it( 'offers Previous beside a later page that could not be read', async () => {
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			null !== afterIn( tokens )
				? new Error( 'review refused' )
				: answer( tokens );
		renderReview();
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		expect(
			await screen.findByText( /Intelligence could not be read just now/ )
		).toBeInTheDocument();
		previous();
		expect(
			await screen.findByRole( 'button', { name: LINK.name } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
	} );

	it( 'reads the page of matches it shows again after a judgment there', async () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-page-two' };
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		const control = await screen.findByRole( 'button', {
			name: `Reject ${ PAGE_TWO.name }`,
		} );
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 2 )
		);
		expect( linkReadsAfter( null ) ).toHaveLength( 1 );
	} );

	it( 'stays on a middle page whose every row is judged, showing the rows its read brings up', async () => {
		const REFILL = {
			...LINK,
			item_key: 'linear:LW-206',
			entity_key: 'organization:shingle-sentinel',
			name: 'Shingle Sentinel',
			title: 'Shingle Sentinel asks about archives',
			url: 'https://linear.example/LW-206',
			evidence: '',
			item_links: 1,
		};
		let judged = 0;
		wire.replies.reject_link = () => {
			judged += 1;
			return { ok: true, event_id: 'ev-middle' };
		};
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) =>
			CURSORS.links[ 1 ] === afterIn( tokens ) && 2 === judged
				? {
						links: [ REFILL ],
						total: LONG.links - 2,
						next: CURSORS.links[ 2 ],
						available: true,
				  }
				: answer( tokens );
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		fakeUndoClock();
		for ( const link of [ PAGE_TWO, LATER_TOO ] ) {
			fireEvent.click(
				screen.getByRole( 'button', { name: `Reject ${ link.name }` } )
			);
		}
		await elapse( UNDO_MS );
		expect(
			await screen.findByRole( 'button', { name: REFILL.name } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Page 2 of 3' ) ).toBeInTheDocument();
	} );

	it( 'keeps a judgment from page 2 that slides onto page 1, though page 2 read after a new cursor no longer lists it', async () => {
		const RENEWED = 'after-LW-208-renewed';
		let slid = false;
		wire.replies.reject_link = ( tokens ) => {
			slid ||= tokens.includes( `--item=${ BESIDE.item_key }` );
			return { ok: true, event_id: 'ev-slide' };
		};
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) => {
			const at = afterIn( tokens );
			if ( 'links' === tokens[ 0 ] && slid && null === at ) {
				return {
					links: [ LINK, SIBLING, PAGE_TWO ],
					total: LONG.links,
					next: RENEWED,
					available: true,
				};
			}
			if ( 'links' === tokens[ 0 ] && RENEWED === at ) {
				return {
					links: [ LATER_TOO ],
					total: LONG.links,
					next: CURSORS.links[ 2 ],
					available: true,
				};
			}
			return answer( tokens );
		};
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		const reject = await screen.findByRole( 'button', {
			name: `Reject ${ PAGE_TWO.name }`,
		} );
		fakeUndoClock();
		fireEvent.click( reject );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 2 )
		);
		previous();
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: `Reject ${ BESIDE.name }`,
			} )
		);
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: PAGE_TWO.name } )
			).toBeInTheDocument()
		);
		next();
		await waitFor( () =>
			expect( linkReadsAfter( RENEWED ) ).toHaveLength( 1 )
		);
		await screen.findByRole( 'button', { name: LATER_TOO.name } );
		const firstPage = linkReadsAfter( null ).length;
		previous();
		await waitFor( () =>
			expect( linkReadsAfter( null ) ).toHaveLength( firstPage + 1 )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: PAGE_TWO.name } )
			).toBeInTheDocument()
		);
		await settle();
		judgedAs( PAGE_TWO.name, 'Rejected.' );
	} );

	it( 'forgets a judgment sent on a page no read reaches once page 1 answers a new cursor, a later page listing it as owed a judgment', async () => {
		const RENEWED = 'after-LW-207-renewed';
		let rejected = false;
		wire.replies.reject_link = () => {
			rejected = true;
			return { ok: true, event_id: 'ev-dead-page' };
		};
		const answer = wire.replies.review;
		wire.replies.review = ( tokens ) => {
			const at = afterIn( tokens );
			if ( 'links' === tokens[ 0 ] && rejected && null === at ) {
				return {
					links: [ LINK, SIBLING ],
					total: LONG.links,
					next: RENEWED,
					available: true,
				};
			}
			if ( 'links' === tokens[ 0 ] && RENEWED === at ) {
				return {
					links: [ BESIDE, PAGE_TWO ],
					total: LONG.links,
					next: CURSORS.links[ 2 ],
					available: true,
				};
			}
			return answer( tokens );
		};
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		const reject = await screen.findByRole( 'button', {
			name: `Reject ${ PAGE_TWO.name }`,
		} );
		fakeUndoClock();
		fireEvent.click( reject );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toHaveLength( 2 )
		);
		judgedAs( PAGE_TWO.name, 'Rejected.' );
		jest.useRealTimers();
		previous();
		await screen.findByRole( 'button', { name: SIBLING.name } );
		expect( shown( BESIDE.name ) ).toBeNull();
		next();
		await waitFor( () =>
			expect( linkReadsAfter( RENEWED ) ).toHaveLength( 1 )
		);
		expect(
			await screen.findByRole( 'button', {
				name: `Reject ${ PAGE_TWO.name }`,
			} )
		).toBeInTheDocument();
	} );

	it( 'keeps a judgment sent on a page showing its verdict across a turn of the page', async () => {
		wire.replies.reject_link = { ok: true, event_id: 'ev-paged' };
		renderReview( { canTune: true } );
		const control = await within( queue( 'Suggested matches' ) ).findByRole(
			'button',
			{ name: `Reject ${ BESIDE.name }` }
		);
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( linkReadsAfter( null ) ).toHaveLength( 2 )
		);
		judgedAs( BESIDE.name, 'Rejected.' );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		previous();
		await waitFor( () =>
			expect( linkReadsAfter( null ) ).toHaveLength( 3 )
		);
		await settle();
		judgedAs( BESIDE.name, 'Rejected.' );
	} );

	describe( 'on the last page', () => {
		const ITEM_THREE = {
			...LINK,
			item_key: 'linear:LW-417',
			entity_key: 'organization:fogbank-review',
			name: 'Fogbank Review',
			title: 'Fogbank Review leaves the network',
			url: 'https://linear.example/LW-417',
			evidence: '',
			item_links: 1,
		};
		let judged;

		beforeEach( () => {
			judged = false;
			const answer = wire.replies.review;
			wire.replies.review = ( tokens ) => {
				if ( 'links' !== tokens[ 0 ] ) {
					return answer( tokens );
				}
				if ( CURSORS.links[ 2 ] === afterIn( tokens ) ) {
					return {
						links: judged ? [] : [ ITEM_THREE ],
						total: judged ? 100 : 101,
						next: null,
						available: true,
					};
				}
				return { ...answer( tokens ), total: judged ? 100 : 101 };
			};
			wire.replies.reject_link = () => {
				judged = true;
				return { ok: true, event_id: 'ev-last' };
			};
		} );

		const toPageThree = async () => {
			await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
				name: LINK.name,
			} );
			next();
			await screen.findByRole( 'button', { name: PAGE_TWO.name } );
			next();
			return screen.findByRole( 'button', {
				name: `Reject ${ ITEM_THREE.name }`,
			} );
		};

		it( 'goes to the page before once its last item is judged', async () => {
			renderReview( { canTune: true } );
			const control = await toPageThree();
			fakeUndoClock();
			fireEvent.click( control );
			await elapse( UNDO_MS );
			expect(
				await screen.findByText( 'Page 2 of 3' )
			).toBeInTheDocument();
			expect( shown( PAGE_TWO.name ) ).not.toBeNull();
		} );

		it( 'stays on the page while its judgment can be undone, and Undo brings the row back focused', async () => {
			renderReview( { canTune: true } );
			const control = await toPageThree();
			fakeUndoClock();
			fireEvent.click( control );
			await settle();
			fireEvent.click( screen.getByRole( 'button', { name: 'Undo' } ) );
			const row = rowOf(
				await screen.findByRole( 'button', {
					name: `Reject ${ ITEM_THREE.name }`,
				} )
			);
			expect( document.activeElement ).toBe( row );
			expect( screen.getByText( 'Page 3 of 3' ) ).toBeInTheDocument();
			expect( wire.sent( 'reject_link' ) ).toEqual( [] );
		} );
	} );

	it( 'asks nothing of the first page of names while a later one shows', async () => {
		wire.replies.accept_entity = { ok: true, event_id: 'ev-name' };
		const nameReads = ( cursor ) =>
			wire
				.sent( 'review' )
				.filter(
					( tokens ) =>
						'entities' === tokens[ 0 ] &&
						cursor === afterIn( tokens )
				);
		renderReview( { canTune: true } );
		await within( queue( 'Suggested names' ) ).findByRole( 'button', {
			name: PROPOSED.name,
		} );
		next();
		const control = await screen.findByRole( 'button', {
			name: `Accept ${ NAMED_LATER.name }`,
		} );
		fakeUndoClock();
		fireEvent.click( control );
		await elapse( UNDO_MS );
		await waitFor( () =>
			expect( nameReads( CURSORS.entities[ 1 ] ) ).toHaveLength( 2 )
		);
		expect( nameReads( null ) ).toHaveLength( 1 );
	} );

	it( 'keeps the checked run and the page while judging a row changes the list', async () => {
		window.Element.prototype.scrollIntoView = jest.fn();
		wire.replies.reject_link = never;
		renderReview( { canTune: true } );
		await within( queue( 'Suggested matches' ) ).findByRole( 'button', {
			name: LINK.name,
		} );
		next();
		await screen.findByRole( 'button', { name: PAGE_TWO.name } );
		previous();
		fireEvent.click(
			await screen.findByRole( 'checkbox', {
				name: `Select ${ BESIDE.title }`,
			} )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ LINK.name }` } )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: `Reject ${ SIBLING.name }` } )
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
	} );

	it( 'keeps the checked run on a change of view that moves no page', async () => {
		window.Element.prototype.scrollIntoView = jest.fn();
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole(
				'checkbox',
				{ name: `Select ${ LINK.title }` }
			)
		);
		act( () =>
			mockList.props.onChangeView( {
				...mockList.props.view,
				layout: { density: 'compact' },
			} )
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
	} );

	it( 'keeps the checked run on a move to a page it cannot turn to', async () => {
		window.Element.prototype.scrollIntoView = jest.fn();
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole(
				'checkbox',
				{ name: `Select ${ LINK.title }` }
			)
		);
		act( () =>
			mockList.props.onChangeView( { ...mockList.props.view, page: 3 } )
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Page 1 of 3' ) ).toBeInTheDocument();
		expect( linkReadsAfter( CURSORS.links[ 1 ] ) ).toEqual( [] );
	} );

	it( 'lets the checked run go on a change of page', async () => {
		window.Element.prototype.scrollIntoView = jest.fn();
		wire.replies.reject_link = { ok: true, event_id: 'ev-paged' };
		renderReview( { canTune: true } );
		fireEvent.click(
			await within( queue( 'Suggested matches' ) ).findByRole(
				'checkbox',
				{ name: `Select ${ LINK.title }` }
			)
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		next();
		fireEvent.click(
			await screen.findByRole( 'checkbox', {
				name: `Select ${ PAGE_TWO.title }`,
			} )
		);
		expect( screen.getByText( '1 Item selected' ) ).toBeInTheDocument();
		fakeUndoClock();
		await act( async () =>
			fireEvent.click( screen.getByRole( 'button', { name: 'No' } ) )
		);
		await elapse( UNDO_MS );
		await settle();
		expect( wire.sent( 'reject_link' ) ).toEqual( [
			[
				`--item=${ PAGE_TWO.item_key }`,
				`--entity=${ PAGE_TWO.entity_key }`,
			],
		] );
	} );
} );

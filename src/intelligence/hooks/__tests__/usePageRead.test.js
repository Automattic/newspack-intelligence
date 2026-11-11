/* eslint-env jest */
/**
 * The page reads each read its views and blocks share once, however many of
 * them read it, and every one of them shows that one live read: none marks an
 * answer fresh that its read has not answered since it asked.
 */

import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { intelWire } from '../../__tests__/intelWire';
import { renderOnPage } from '../../__tests__/pageReads';
import { pageData } from '../../views/__tests__/fixtures';
import { useIntelWrite } from '../useIntelCommand';
import { usePageRead } from '../usePageRead';

let wire;

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	wire = intelWire();
} );

/** A reply the suite holds open until it answers it. */
function held() {
	let answer;
	const reply = new Promise( ( resolve ) => ( answer = resolve ) );
	return { reply: () => reply, answer: ( value ) => answer( value ) };
}

/**
 * Lenses named as the suite gives them.
 *
 * @param {...string} names Their names, each its key too.
 * @return {Object} The `lenses` answer.
 */
const named = ( ...names ) => ( {
	lenses: names.map( ( name ) => ( { lens_key: name, name } ) ),
} );

/**
 * The page's lens list as one view or block shows it.
 *
 * @param {Object} props
 * @param {string} props.label Names the region it stands in.
 * @return {import('react').ReactElement} The list.
 */
function LensNames( { label } ) {
	const { answer, loading, stale } = usePageRead( 'lenses' );
	return (
		<section aria-label={ label }>
			{ loading && 'loading' }
			{ answer &&
				`${ stale ? 'stale' : 'fresh' } ${ answer.lenses
					.map( ( lens ) => lens.name )
					.join( ', ' ) }` }
		</section>
	);
}

/** A write dirtying the lens list. */
function SaveLens() {
	const { run } = useIntelWrite( 'save_lens', {
		scope: 'probe:save-lens',
		dirties: [ 'lenses:' ],
	} );
	return (
		<button
			type="button"
			className="button"
			onClick={ () => run( { name: 'Harbor Ledger desk' } ) }
		>
			save
		</button>
	);
}

const region = ( name ) => screen.getByRole( 'region', { name } );

/** Let a tick or two pass, so a second send would have gone. */
const settle = () =>
	act( () => new Promise( ( resolve ) => setTimeout( resolve, 300 ) ) );

describe( 'usePageRead', () => {
	it( 'sends a read once a page, however many views and blocks read it, a hidden one among them', async () => {
		wire.replies.lenses = named( 'Harbor Ledger desk' );
		renderOnPage(
			<>
				<div hidden>
					<LensNames label="hidden view" />
				</div>
				<LensNames label="profile" />
				<LensNames label="block" />
			</>
		);
		await waitFor( () =>
			expect( region( 'block' ) ).toHaveTextContent(
				'fresh Harbor Ledger desk'
			)
		);
		expect( region( 'profile' ) ).toHaveTextContent(
			'fresh Harbor Ledger desk'
		);
		await settle();
		expect( wire.sent( 'lenses' ) ).toEqual( [ [] ] );
	} );

	it( 'shows a view mounting late the one live read, asking nothing more', async () => {
		wire.replies.lenses = named( 'Harbor Ledger desk' );
		const page = renderOnPage( <LensNames label="hidden view" /> );
		await waitFor( () =>
			expect( region( 'hidden view' ) ).toHaveTextContent( 'fresh' )
		);
		page.rerender(
			<>
				<LensNames label="hidden view" />
				<LensNames label="profile" />
			</>
		);
		expect( region( 'profile' ) ).toHaveTextContent(
			'fresh Harbor Ledger desk'
		);
		await settle();
		expect( wire.sent( 'lenses' ) ).toEqual( [ [] ] );
	} );

	it( 'reads again once a write dirties it, every reader showing the new answer', async () => {
		wire.replies.lenses = named( 'Harbor Ledger desk' );
		wire.replies.save_lens = { ok: true, event_id: 'ev-91' };
		renderOnPage(
			<>
				<LensNames label="view" />
				<LensNames label="block" />
				<SaveLens />
			</>
		);
		await waitFor( () =>
			expect( region( 'view' ) ).toHaveTextContent( 'fresh' )
		);
		wire.replies.lenses = named( 'Harbor Ledger desk', 'Tide desk' );
		fireEvent.click( screen.getByRole( 'button', { name: 'save' } ) );
		await waitFor( () =>
			expect( region( 'block' ) ).toHaveTextContent(
				'fresh Harbor Ledger desk, Tide desk'
			)
		);
		expect( region( 'view' ) ).toHaveTextContent(
			'fresh Harbor Ledger desk, Tide desk'
		);
		expect( wire.sent( 'lenses' ) ).toEqual( [ [], [] ] );
	} );

	it( 'asks again on a page mounting after a write dirtied its read, showing nothing as fresh until it lands', async () => {
		wire.replies.lenses = named( 'Harbor Ledger desk' );
		wire.replies.save_lens = { ok: true, event_id: 'ev-92' };
		const first = renderOnPage( <LensNames label="view" /> );
		await waitFor( () =>
			expect( region( 'view' ) ).toHaveTextContent( 'fresh' )
		);
		first.unmount();
		const writer = render( <SaveLens /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'save' } ) );
		await waitFor( () =>
			expect( wire.sent( 'save_lens' ) ).toHaveLength( 1 )
		);
		await settle();
		writer.unmount();

		const later = held();
		wire.replies.lenses = later.reply;
		renderOnPage( <LensNames label="view" /> );
		expect( region( 'view' ) ).toHaveTextContent( 'loading' );
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 2 )
		);
		await act( async () => later.answer( named( 'Tide desk' ) ) );
		await waitFor( () =>
			expect( region( 'view' ) ).toHaveTextContent( 'fresh Tide desk' )
		);
	} );

	it( 'paints its last answer stale on a page mounting again, until the read it sends lands', async () => {
		wire.replies.lenses = named( 'Harbor Ledger desk' );
		const first = renderOnPage( <LensNames label="view" /> );
		await waitFor( () =>
			expect( region( 'view' ) ).toHaveTextContent( 'fresh' )
		);
		first.unmount();

		const later = held();
		wire.replies.lenses = later.reply;
		renderOnPage( <LensNames label="view" /> );
		expect( region( 'view' ) ).toHaveTextContent(
			'stale Harbor Ledger desk'
		);
		await waitFor( () =>
			expect( wire.sent( 'lenses' ) ).toHaveLength( 2 )
		);
		await act( async () => later.answer( named( 'Tide desk' ) ) );
		await waitFor( () =>
			expect( region( 'view' ) ).toHaveTextContent( 'fresh Tide desk' )
		);
	} );

	it( 'reads the review queue only while the page asks it', async () => {
		wire.replies.review = { links: [], total: 4 };
		const page = renderOnPage( <LensNames label="view" /> );
		await settle();
		expect( wire.sent( 'review' ) ).toEqual( [] );
		page.unmount();
		renderOnPage( <LensNames label="view" />, { review: true } );
		await waitFor( () =>
			expect( wire.sent( 'review' ) ).toEqual( [ [ 'links' ] ] )
		);
	} );

	it( 'refuses a read no page provides', () => {
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
		expect( () => render( <LensNames label="view" /> ) ).toThrow(
			'usePageRead( lenses ): no page provides its reads'
		);
		console.error.mockRestore();
	} );
} );

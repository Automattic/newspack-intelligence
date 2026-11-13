/* eslint-env jest */
/**
 * Intelligence's pages, read by the substrate's edit-mode links: a page's
 * layout is edited on the page it lays out, opened by a link naming the
 * page, and for a team view or a profile the subject it was opened from;
 * anything else names no edit.
 */

import {
	editSearch,
	editTarget as editTargetOf,
	leaveEdit as leaveEditOf,
} from '@newspack-nodes/shared/composition/page';
import { layoutContext, PAGES } from '../editMode';

/**
 * The layout a query string edits, among Intelligence's pages.
 *
 * @param {string} search A query string.
 * @return {?Object} The page and its subject.
 */
const editTarget = ( search ) => editTargetOf( search, PAGES );

/** Drop the edit and every subject Intelligence's pages take. */
const leaveEdit = () => leaveEditOf( PAGES );

afterEach( () => {
	window.history.replaceState( {}, '', '/' );
} );

describe( 'editTarget', () => {
	it( 'reads the page and the subject the link names', () => {
		expect(
			editTarget( '?page=newspack-intelligence&edit=briefing' )
		).toEqual( { page: 'briefing' } );
		expect(
			editTarget(
				'?page=newspack-intelligence&edit=lens&lens=desk-watch'
			)
		).toEqual( { page: 'lens', lens_key: 'desk-watch' } );
		expect(
			editTarget(
				'?edit=entity&entity=organization%3Aharbor-ledger&lens=desk-watch'
			)
		).toEqual( {
			page: 'entity',
			entity_key: 'organization:harbor-ledger',
		} );
	} );

	it( 'names no edit for a page that is not composed, or none', () => {
		expect( editTarget( '?page=newspack-intelligence&edit=folders' ) ).toBe(
			null
		);
		expect( editTarget( '?page=newspack-intelligence' ) ).toBe( null );
		expect( editTarget( '' ) ).toBe( null );
	} );

	it( 'leaves out a subject the page does not take, or a blank one', () => {
		expect( editTarget( '?edit=briefing&lens=desk-watch' ) ).toEqual( {
			page: 'briefing',
		} );
		expect( editTarget( '?edit=lens&lens=' ) ).toEqual( { page: 'lens' } );
	} );
} );

describe( 'editSearch', () => {
	it( 'adds the edit and its view to every param the page holds, and reads back as given', () => {
		const search = editSearch(
			'?page=newspack-intelligence&watch=9f3a51c07be2d468&view=review',
			{ page: 'lens', lens_key: 'desk-watch' },
			PAGES
		);

		expect( search ).toBe(
			'?page=newspack-intelligence&watch=9f3a51c07be2d468&view=lenses&edit=lens&lens=desk-watch'
		);
		expect( editTarget( search ) ).toEqual( {
			page: 'lens',
			lens_key: 'desk-watch',
		} );
		expect(
			editTarget(
				editSearch(
					'?page=newspack-intelligence&edit=lens&lens=desk-watch',
					{
						page: 'briefing',
					},
					PAGES
				)
			)
		).toEqual( { page: 'briefing' } );
	} );
} );

describe( 'editSearch for a profile', () => {
	it( 'keeps the view the profile stands over, which names no tab of its own', () => {
		const search = editSearch(
			'?page=newspack-intelligence&view=watches&lens=desk-watch',
			{ page: 'entity', entity_key: 'organization:harbor-ledger' },
			PAGES
		);

		expect( search ).toBe(
			'?page=newspack-intelligence&view=watches&edit=entity&entity=organization%3Aharbor-ledger'
		);
		expect( editTarget( search ) ).toEqual( {
			page: 'entity',
			entity_key: 'organization:harbor-ledger',
		} );
	} );
} );

describe( 'leaveEdit', () => {
	it( 'drops the edit and its subject, and keeps every other param', () => {
		window.history.replaceState(
			{},
			'',
			'/wp-admin/admin.php?page=newspack-intelligence&edit=entity&entity=organization%3Aharbor-ledger'
		);

		leaveEdit();

		expect( window.location.search ).toBe( '?page=newspack-intelligence' );
	} );
} );

describe( 'layoutContext', () => {
	it( 'names the context a page lays out', () => {
		expect( layoutContext( 'lens' ) ).toBe( 'newspack-intelligence/lens' );
	} );
} );

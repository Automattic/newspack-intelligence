/* eslint-env jest */
/**
 * Citation is an answer's marker for one source: a button named for the
 * source, whose hover or focus previews it in a popover with Open source and
 * Show in list, and whose click shows the source in the list. Every string
 * from the graph renders as text, and only an http or https URL is linked.
 */

import { act, render, screen, fireEvent } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { pageData } from '../../views/__tests__/fixtures';
import Citation from '../Citation';

const ITEM = {
	item_key: 'slack:sponsor-placement',
	title: 'Sponsorship placement in newsletters',
	url: 'https://chat.example/archives/C1/p2',
	source: 'slack',
	tier: 'internal',
	item_ts: 1793232000,
	summary:
		'Prairie Signal asked whether a sponsor block can sit in the weekly.',
	excerpt: '',
	kind: 'feature_request',
	entities: [],
};

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	jest.spyOn( Core, 'now' ).mockReturnValue( 1793318400 );
} );

afterEach( () => {
	jest.restoreAllMocks();
} );

/**
 * Lets the popover place itself, which it does a microtask after it opens.
 *
 * @return {Promise<void>} Settled.
 */
const settle = () => act( async () => {} );

describe( 'Citation', () => {
	it( 'is a button named for its source, showing its number', () => {
		render( <Citation n={ 2 } item={ ITEM } onShow={ jest.fn() } /> );

		const marker = screen.getByRole( 'button', {
			name: 'Source 2: Sponsorship placement in newsletters',
		} );
		expect( marker ).toHaveTextContent( '[2]' );
		expect( screen.queryByText( 'Open source' ) ).toBeNull();
	} );

	it( 'previews its source on focus, with Open source and Show in list', async () => {
		const onShow = jest.fn();
		render( <Citation n={ 2 } item={ ITEM } onShow={ onShow } /> );

		fireEvent.focus( screen.getByRole( 'button', { name: /^Source 2/ } ) );
		await settle();

		const preview = screen.getByRole( 'dialog', { name: 'Source 2' } );
		expect( preview ).toHaveTextContent(
			'Sponsorship placement in newsletters'
		);
		expect( preview ).toHaveTextContent( 'Chat, 29 Oct' );
		expect( preview ).toHaveTextContent( 'Wish' );
		expect( preview ).toHaveTextContent(
			'Prairie Signal asked whether a sponsor block can sit in the weekly.'
		);
		expect(
			screen.getByRole( 'link', { name: 'Open source' } )
		).toHaveAttribute( 'href', ITEM.url );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show in list' } )
		);
		expect( onShow ).toHaveBeenCalledWith( 2 );
	} );

	it( 'previews its source on hover, and closes it on Escape', async () => {
		render( <Citation n={ 3 } item={ ITEM } onShow={ jest.fn() } /> );
		const marker = screen.getByRole( 'button', { name: /^Source 3/ } );

		fireEvent.mouseEnter( marker );
		await settle();
		expect(
			screen.getByRole( 'dialog', { name: 'Source 3' } )
		).toBeTruthy();

		fireEvent.keyDown( marker, { key: 'Escape' } );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'hands focus back to its marker on Escape from inside the preview, and stays closed', async () => {
		render( <Citation n={ 5 } item={ ITEM } onShow={ jest.fn() } /> );
		const marker = screen.getByRole( 'button', { name: /^Source 5/ } );

		act( () => marker.focus() );
		await settle();
		const show = screen.getByRole( 'button', { name: 'Show in list' } );
		act( () => show.focus() );
		fireEvent.keyDown( show, { key: 'Escape' } );
		await settle();

		expect( marker ).toHaveFocus();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
	} );

	it( 'shows its source in the list on a click', () => {
		const onShow = jest.fn();
		render( <Citation n={ 4 } item={ ITEM } onShow={ onShow } /> );

		fireEvent.click( screen.getByRole( 'button', { name: /^Source 4/ } ) );

		expect( onShow ).toHaveBeenCalledWith( 4 );
	} );

	it( 'renders markup in a title or summary as text, and links no other scheme', async () => {
		const item = {
			...ITEM,
			title: '<img src=x onerror=alert(1)>Payload',
			summary: '<b>bold</b> claim',
			url: 'javascript:alert(1)',
		};
		const { container } = render(
			<Citation n={ 1 } item={ item } onShow={ jest.fn() } />
		);

		fireEvent.focus(
			screen.getByRole( 'button', {
				name: 'Source 1: <img src=x onerror=alert(1)>Payload',
			} )
		);
		await settle();

		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( document.querySelector( 'img, b' ) ).toBeNull();
		expect( screen.getByText( '<b>bold</b> claim' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Open source' } )
		).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Show in list' } )
		).toBeInTheDocument();
	} );
} );

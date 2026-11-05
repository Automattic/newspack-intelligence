/* eslint-env jest */
/**
 * A signal's value names its axis and kind in the page's own language, then
 * the value it counts, when it counts one. A trend table counting by theme
 * shows each row's client chips above its items in one column, every row,
 * one of a single item too, keeping its value under Value; counting by any
 * other axis it shows its items alone.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import TrendTable, { signalValue } from '../TrendTable';

const THEMES = [
	{
		bucket: '2026-09-21',
		value: 'site blocked from sending email',
		count: 12,
		clients: [
			{
				entity_key: 'organization:harbor-ledger',
				name: 'Harbor Ledger',
			},
			{
				entity_key: 'organization:acme-quillsheet',
				name: 'Acme Quillsheet',
			},
		],
		items: [
			{
				item_key: 'linear:SUP-120',
				url: 'https://linear.example/SUP-120',
				title: 'Acme mail bounces at the sender',
			},
		],
	},
	{
		bucket: '2026-09-14',
		value: 'client offboarding',
		count: 1,
		clients: [
			{
				entity_key: 'organization:kestrel-bayou',
				name: 'Kestrel Bayou',
			},
		],
		items: [
			{
				item_key: 'linear:SUP-64',
				url: 'https://linear.example/SUP-64',
				title: 'Kestrel Bayou asks to export its archive',
			},
		],
	},
];

afterEach( () => resetLocaleData( undefined, 'newspack-intelligence' ) );

describe( 'signalValue', () => {
	it( 'translates the axis and the kind', () => {
		setLocaleData(
			{
				'': { domain: 'newspack-intelligence' },
				publisher: [ 'éditeur' ],
				drop: [ 'baisse' ],
				about: [ 'sujet' ],
				burst: [ 'rafale' ],
			},
			'newspack-intelligence'
		);
		expect(
			signalValue( { axis: 'publisher', signal: 'drop', value: null } )
		).toBe( 'éditeur baisse' );
		expect(
			signalValue( {
				axis: 'about',
				signal: 'burst',
				value: 'partnership',
			} )
		).toBe( 'sujet rafale: partnership' );
	} );

	it( 'names an axis or a kind it does not know as the server spelled it', () => {
		expect(
			signalValue( { axis: 'harbor', signal: 'swell', value: null } )
		).toBe( 'harbor swell' );
	} );
} );

describe( 'TrendTable', () => {
	const rowOf = ( bucket ) =>
		screen.getByRole( 'row', { name: new RegExp( bucket ) } );

	it( 'shows a theme row’s client chips above its items in one cell', () => {
		const onOpenEntity = jest.fn();
		render(
			<TrendTable
				rows={ THEMES }
				by="theme"
				onOpenEntity={ onOpenEntity }
			/>
		);
		expect(
			screen
				.getAllByRole( 'columnheader' )
				.map( ( header ) => header.textContent )
		).toEqual( [ 'Bucket', 'Value', 'Count', 'Clients and items' ] );
		const row = rowOf( '2026-09-21' );
		const cells = within( row ).getAllByRole( 'cell' );
		expect( cells ).toHaveLength( 4 );
		expect( cells[ 1 ] ).toHaveTextContent(
			'site blocked from sending email'
		);
		expect( cells[ 2 ] ).toHaveTextContent( '12' );
		expect(
			within( cells[ 3 ] )
				.getAllByRole( 'button' )
				.map( ( chip ) => chip.textContent )
		).toEqual( [ 'Harbor Ledger', 'Acme Quillsheet' ] );
		const [ chips, facts ] = within( cells[ 3 ] ).getAllByRole( 'list' );
		expect(
			within( chips ).getByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
		expect(
			within( facts ).getByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-120' );
		fireEvent.click(
			within( cells[ 3 ] ).getByRole( 'button', {
				name: 'Harbor Ledger',
			} )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:harbor-ledger'
		);
	} );

	it( 'shows a theme row of one item under its theme, the item under Items', () => {
		render(
			<TrendTable rows={ THEMES } by="theme" onOpenEntity={ jest.fn() } />
		);
		const cells = within( rowOf( '2026-09-14' ) ).getAllByRole( 'cell' );
		expect( cells[ 1 ] ).toHaveTextContent( 'client offboarding' );
		expect( within( cells[ 1 ] ).queryByRole( 'link' ) ).toBeNull();
		expect(
			within( cells[ 3 ] ).getByRole( 'link', {
				name: 'Kestrel Bayou asks to export its archive',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-64' );
		expect(
			within( cells[ 3 ] ).getByRole( 'button', {
				name: 'Kestrel Bayou',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows a theme row with no clients as its items alone', () => {
		render(
			<TrendTable
				rows={ [
					{
						bucket: '2026-09-28',
						value: 'kite permit backlog',
						count: 1,
						clients: [],
						items: [
							{
								item_key: 'linear:SUP-77',
								url: 'https://linear.example/SUP-77',
								title: 'Kite permits wait a month',
							},
						],
					},
				] }
				by="theme"
				onOpenEntity={ jest.fn() }
			/>
		);
		const cells = within( rowOf( '2026-09-28' ) ).getAllByRole( 'cell' );
		expect( within( cells[ 3 ] ).queryByRole( 'button' ) ).toBeNull();
		expect( within( cells[ 3 ] ).getAllByRole( 'list' ) ).toHaveLength( 1 );
		expect(
			within( cells[ 3 ] ).getByRole( 'link', {
				name: 'Kite permits wait a month',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows a theme row that answers no items and no clients as its theme', () => {
		render(
			<TrendTable
				rows={ [
					{
						bucket: '2026-09-07',
						value: 'newsletter sender rejected',
						count: 1,
					},
				] }
				by="theme"
				onOpenEntity={ jest.fn() }
			/>
		);
		const cells = within( rowOf( '2026-09-07' ) ).getAllByRole( 'cell' );
		expect( cells[ 1 ] ).toHaveTextContent( 'newsletter sender rejected' );
		expect( cells[ 2 ] ).toHaveTextContent( '1' );
		expect( within( cells[ 3 ] ).queryByRole( 'button' ) ).toBeNull();
		expect( within( cells[ 3 ] ).queryByRole( 'link' ) ).toBeNull();
	} );

	it( 'shows no clients on another axis', () => {
		render( <TrendTable rows={ THEMES } /> );
		expect(
			screen
				.getAllByRole( 'columnheader' )
				.map( ( header ) => header.textContent )
		).toEqual( [ 'Bucket', 'Value', 'Count', 'Items' ] );
		expect( screen.queryByRole( 'button' ) ).toBeNull();
		const cells = within( rowOf( '2026-09-14' ) ).getAllByRole( 'cell' );
		expect( cells[ 1 ] ).toHaveTextContent( 'client offboarding' );
		expect(
			within( cells[ 3 ] ).getByRole( 'link', {
				name: 'Kestrel Bayou asks to export its archive',
			} )
		).toBeInTheDocument();
	} );
} );

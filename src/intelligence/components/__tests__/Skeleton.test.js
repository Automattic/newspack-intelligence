/* eslint-env jest */
/**
 * Skeleton stands in for rows still owed: one placeholder per row, each
 * shaped like the row it stands for, announced as loading and carrying no
 * text of its own.
 */

import { render, screen } from '@testing-library/react';
import Skeleton from '../Skeleton';

const bars = ( el ) => el.querySelectorAll( '.newspack-nodes-skeleton' );

describe( 'Skeleton', () => {
	it( 'stands in for as many item rows as it is told, each a title, meta line and summary', () => {
		render( <Skeleton count={ 7 } /> );
		const rows = screen
			.getByRole( 'status', { name: 'Loading' } )
			.querySelectorAll( '.newspack-intelligence__item' );
		expect( rows ).toHaveLength( 7 );
		rows.forEach( ( row ) => expect( bars( row ) ).toHaveLength( 3 ) );
	} );

	it( 'stands in for three item rows by default', () => {
		render( <Skeleton /> );
		expect(
			screen
				.getByRole( 'status', { name: 'Loading' } )
				.querySelectorAll( '.newspack-intelligence__item' )
		).toHaveLength( 3 );
	} );

	it( 'stands in for lines of prose as one bar each', () => {
		render( <Skeleton shape="line" count={ 2 } /> );
		const status = screen.getByRole( 'status', { name: 'Loading' } );
		expect( status.querySelectorAll( 'p' ) ).toHaveLength( 2 );
		expect( bars( status ) ).toHaveLength( 2 );
		expect(
			status.querySelectorAll( '.newspack-intelligence__item' )
		).toHaveLength( 0 );
	} );

	it( 'sizes each bar by the part it stands for, from the sheet', () => {
		const { container } = render(
			<>
				<Skeleton count={ 1 } />
				<Skeleton shape="line" count={ 1 } />
			</>
		);
		const parts = [ ...bars( container ) ].map( ( bar ) =>
			[ ...bar.classList ].filter( ( name ) => name.startsWith( 'is-' ) )
		);
		expect( parts ).toEqual( [
			[ 'is-title' ],
			[ 'is-meta' ],
			[ 'is-summary' ],
			[ 'is-line' ],
		] );
		bars( container ).forEach( ( bar ) => {
			expect( bar ).toHaveClass( 'newspack-intelligence__skeleton-bar' );
			expect( bar.getAttribute( 'style' ) ).toBeNull();
		} );
	} );

	it( 'carries no text, so nothing reads "Loading…"', () => {
		const { container } = render( <Skeleton count={ 4 } /> );
		expect( container ).toHaveTextContent( /^$/ );
	} );
} );

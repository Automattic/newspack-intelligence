/* eslint-env jest */
/**
 * ListPicker holds a closed list's values as tokens shown by their words,
 * the given values or else every key the map words, and hands them on as
 * comma-separated text; a value outside the list is refused, typed alone or
 * pasted beside others with commas.
 */

import { fireEvent, render, screen } from '@testing-library/react';
import { pageData } from '../../views/__tests__/fixtures';
import ListPicker from '../ListPicker';

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

/**
 * Type into the field, then end the entry with Enter.
 *
 * @param {string} text What is typed.
 */
const type = ( text ) => {
	const field = screen.getByLabelText( 'Kinds' );
	fireEvent.change( field, { target: { value: text } } );
	fireEvent.keyDown( field, { key: 'Enter' } );
};

describe( 'ListPicker', () => {
	it( 'shows each value by its word', () => {
		render(
			<ListPicker
				label="Kinds"
				map="kindWords"
				values={ null }
				value="award, launch"
				onChange={ () => {} }
			/>
		);
		expect( screen.getByText( 'Prize' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Going live' ) ).toBeInTheDocument();
	} );

	it( 'adds a listed value and hands on the text', () => {
		const onChange = jest.fn();
		render(
			<ListPicker
				label="Kinds"
				map="kindWords"
				values={ null }
				value="award"
				onChange={ onChange }
			/>
		);
		type( 'release' );
		expect( onChange ).toHaveBeenLastCalledWith( 'award, release' );
	} );

	it( 'drops a value outside the list that a typed comma ends', () => {
		const onChange = jest.fn();
		render(
			<ListPicker
				label="Kinds"
				map="kindWords"
				values={ null }
				value="award"
				onChange={ onChange }
			/>
		);
		fireEvent.change( screen.getByLabelText( 'Kinds' ), {
			target: { value: 'heron,launch' },
		} );
		expect( onChange ).toHaveBeenLastCalledWith( 'award' );
	} );

	it( 'offers only the values it is given', () => {
		const onChange = jest.fn();
		render(
			<ListPicker
				label="Kinds"
				map="kindWords"
				values={ [ 'award' ] }
				value=""
				onChange={ onChange }
			/>
		);
		type( 'launch' );
		expect( onChange ).not.toHaveBeenCalled();
		type( 'award' );
		expect( onChange ).toHaveBeenLastCalledWith( 'award' );
	} );
} );

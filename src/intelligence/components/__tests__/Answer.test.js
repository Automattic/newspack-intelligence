/* eslint-env jest */
/**
 * Answer renders a read in exactly one of its states, and each kind of
 * nothing says which kind it is: still asking, refused, a graph that would
 * not open, or a graph that holds no rows. As a card it names and heads
 * itself by its title.
 */

import { render, screen } from '@testing-library/react';
import Answer, { AnswerBody } from '../Answer';

const UNAVAILABLE =
	'The intelligence graph could not be opened, so this was not read.';

const props = { rows: 'leads', empty: 'No lead holds this.' };

const answered = ( answer ) => ( { answer, error: null, loading: false } );

describe( 'AnswerBody', () => {
	it( 'shows that the answer is still owed', () => {
		render(
			<AnswerBody
				read={ { answer: null, error: null, loading: true } }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent( /loading/i );
	} );

	it( 'shows a refusal as an alert', () => {
		render(
			<AnswerBody
				read={ {
					answer: null,
					error: 'limit must be positive',
					loading: false,
				} }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'limit must be positive'
		);
	} );

	it( 'says the graph is unavailable rather than empty', () => {
		render(
			<AnswerBody
				read={ answered( { leads: [], available: false } ) }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByText( UNAVAILABLE ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No lead holds this.' ) ).toBeNull();
	} );

	it( 'says the graph holds nothing here when its rows are empty', () => {
		render(
			<AnswerBody
				read={ answered( { leads: [], available: true } ) }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByText( 'No lead holds this.' ) ).toBeInTheDocument();
		expect( screen.queryByText( UNAVAILABLE ) ).toBeNull();
	} );

	it( 'renders the answer when its rows hold some', () => {
		render(
			<AnswerBody
				read={ answered( { leads: [ 'a', 'b' ], available: true } ) }
				{ ...props }
			>
				{ ( a ) => `${ a.leads.length } leads` }
			</AnswerBody>
		);
		expect( screen.getByText( '2 leads' ) ).toBeInTheDocument();
	} );

	it( 'never calls an answer without rows empty', () => {
		render(
			<AnswerBody read={ answered( { name: 'Acme', available: true } ) }>
				{ ( a ) => a.name }
			</AnswerBody>
		);
		expect( screen.getByText( 'Acme' ) ).toBeInTheDocument();
	} );
} );

describe( 'Answer', () => {
	it( 'is a card named and headed by its title at its level', () => {
		render(
			<Answer
				title="Leads"
				level={ 4 }
				read={ answered( { leads: [ 'a' ], available: true } ) }
				{ ...props }
			>
				{ () => 'rows' }
			</Answer>
		);
		const card = screen.getByRole( 'region', { name: 'Leads' } );
		expect( card ).toHaveClass( 'newspack-nodes-card' );
		expect(
			screen.getByRole( 'heading', { level: 4, name: 'Leads' } )
		).toBeInTheDocument();
	} );

	it( 'is an unheaded card without a title', () => {
		const { container } = render(
			<Answer read={ answered( { name: 'Acme', available: true } ) }>
				{ ( a ) => a.name }
			</Answer>
		);
		expect( container.querySelector( 'section' ) ).toHaveClass(
			'newspack-nodes-card'
		);
		expect( screen.queryByRole( 'heading' ) ).toBeNull();
	} );
} );

/* eslint-env jest */
/**
 * Answer renders a read in exactly one of its states, and each kind of
 * nothing says which kind it is: still asking, refused, a store that would
 * not open, or a graph that holds no rows. A remembered answer says when it
 * was read. As a card it names itself by its title, heads itself in a header
 * band and insets its rows in a body.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import Answer, { AnswerBody, CanManage, Unread } from '../Answer';
import { sinceLabel } from '../format';
import Skeleton from '../Skeleton';

const UNAVAILABLE =
	'The intelligence store did not open, so this was not read. An administrator can check the status above.';

const UNREAD = 'Intelligence could not be read just now. Try again.';

const props = { rows: 'leads', empty: 'No lead holds this.' };

const answered = ( answer ) => ( { answer, error: null, loading: false } );

describe( 'AnswerBody', () => {
	it( 'stands skeleton rows in for an answer still owed, and says no "Loading…"', () => {
		render(
			<AnswerBody
				read={ { answer: null, error: null, loading: true } }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		const status = screen.getByRole( 'status', { name: 'Loading' } );
		expect(
			status.querySelectorAll( '.newspack-intelligence__item' )
		).toHaveLength( 3 );
		expect( screen.queryByText( /Loading/ ) ).toBeNull();
	} );

	it( 'stands in the skeleton it is given for an answer still owed', () => {
		render(
			<AnswerBody
				read={ { answer: null, error: null, loading: true } }
				skeleton={ <Skeleton shape="line" count={ 2 } /> }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect(
			screen
				.getByRole( 'status', { name: 'Loading' } )
				.querySelectorAll( '.newspack-nodes-skeleton' )
		).toHaveLength( 2 );
	} );

	describe( 'a refused read', () => {
		const refused = ( refresh ) => ( {
			answer: null,
			error: 'limit must be positive',
			loading: false,
			refresh,
		} );

		afterEach( () => {
			delete window.NewspackNodesData;
		} );

		it( 'says it could not be read, offers Try again, and hides the refusal from a reader', () => {
			const refresh = jest.fn();
			render(
				<CanManage.Provider value={ false }>
					<AnswerBody read={ refused( refresh ) } { ...props }>
						{ () => 'rows' }
					</AnswerBody>
				</CanManage.Provider>
			);
			expect( screen.getByRole( 'status' ) ).toHaveTextContent( UNREAD );
			expect( screen.queryByRole( 'alert' ) ).toBeNull();
			expect( screen.queryByText( 'limit must be positive' ) ).toBeNull();
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Try again' } )
			);
			expect( refresh ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'shows an operator the refusal in muted words', () => {
			render(
				<CanManage.Provider value={ true }>
					<AnswerBody read={ refused( jest.fn() ) } { ...props }>
						{ () => 'rows' }
					</AnswerBody>
				</CanManage.Provider>
			);
			expect( screen.getByRole( 'status' ) ).toHaveTextContent( UNREAD );
			expect( screen.queryByRole( 'alert' ) ).toBeNull();
			expect( screen.getByText( 'limit must be positive' ) ).toHaveClass(
				'is-muted'
			);
		} );

		it( 'renders nothing without an error, as a refusal does', () => {
			const { container } = render(
				<Unread error={ null } refresh={ jest.fn() } />
			);
			expect( container ).toBeEmptyDOMElement();
		} );

		it( 'reads the operator from the page alone, never the localized global', () => {
			window.NewspackNodesData = { canManage: '1' };
			render(
				<AnswerBody read={ refused( jest.fn() ) } { ...props }>
					{ () => 'rows' }
				</AnswerBody>
			);
			expect( screen.queryByText( 'limit must be positive' ) ).toBeNull();
		} );
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

	it( 'says when a remembered answer was read', () => {
		const at = Core.now() - 3 * 86400;
		const shown = sinceLabel( at, Core.now() );
		const { rerender } = render(
			<AnswerBody
				read={ {
					...answered( { leads: [ 'a' ], available: true } ),
					stale: true,
					updatedAt: at,
				} }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByText( `Updated ${ shown }` ) ).toHaveClass(
			'is-muted'
		);
		expect( screen.getByText( 'rows' ) ).toBeInTheDocument();
		rerender(
			<AnswerBody
				read={ answered( { leads: [ 'a' ], available: true } ) }
				{ ...props }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.queryByText( /^Updated / ) ).toBeNull();
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

	it( 'is empty only when every list it names is empty', () => {
		const two = { rows: [ 'leads', 'deals' ], empty: 'No lead or deal.' };
		const { rerender } = render(
			<AnswerBody
				read={ answered( { leads: [], deals: [], available: true } ) }
				{ ...two }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByText( 'No lead or deal.' ) ).toBeInTheDocument();
		rerender(
			<AnswerBody
				read={ answered( {
					leads: [],
					deals: [ 'd' ],
					available: true,
				} ) }
				{ ...two }
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		expect( screen.getByText( 'rows' ) ).toBeInTheDocument();
	} );

	it( 'says an empty answer with the controls an invitation carries', () => {
		const onAct = jest.fn();
		render(
			<AnswerBody
				read={ answered( { leads: [], available: true } ) }
				rows="leads"
				empty={
					<>
						No lead yet.{ ' ' }
						<button
							type="button"
							className="button"
							onClick={ onAct }
						>
							Add a lead
						</button>
					</>
				}
			>
				{ () => 'rows' }
			</AnswerBody>
		);
		screen.getByRole( 'button', { name: 'Add a lead' } ).click();
		expect( onAct ).toHaveBeenCalledTimes( 1 );
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
	it( 'is a card named by its title, headed in its header band', () => {
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
		expect( card ).toHaveClass( 'components-card' );
		const heading = screen.getByRole( 'heading', {
			level: 4,
			name: 'Leads',
		} );
		expect( heading.closest( '.components-card__header' ) ).not.toBeNull();
		expect(
			screen.getByText( 'rows' ).closest( '.components-card__body' )
		).not.toBeNull();
	} );

	it( 'is an unheaded card without a title, its rows still inset', () => {
		const { container } = render(
			<Answer read={ answered( { name: 'Acme', available: true } ) }>
				{ ( a ) => a.name }
			</Answer>
		);
		expect( container.querySelector( 'section' ) ).toHaveClass(
			'components-card'
		);
		expect(
			container.querySelector( '.components-card__header' )
		).toBeNull();
		expect( screen.queryByRole( 'heading' ) ).toBeNull();
		expect(
			screen.getByText( 'Acme' ).closest( '.components-card__body' )
		).not.toBeNull();
	} );
} );

/* eslint-env jest */
/**
 * `Header` is the one header every Intelligence tab and section stands
 * under, its whole row in the shared section-heading role, which rules it:
 * its title a level-two heading, an optional muted line under it, and its
 * actions at the end of the line.
 * A heading handed a ref takes focus, so only then is it focusable. A
 * `Section` heads its body with the same `Header`.
 */

import { createRef } from '@wordpress/element';
import { render, screen } from '@testing-library/react';
import Section, { Header } from '../Section';

describe( 'Header', () => {
	it( 'heads with its title, its whole row in the shared section-heading role', () => {
		render( <Header title="Quillmere desk" /> );
		const heading = screen.getByRole( 'heading', {
			level: 2,
			name: 'Quillmere desk',
		} );
		expect( heading ).not.toHaveClass( 'newspack-nodes-section-heading' );
		expect( heading ).not.toHaveAttribute( 'tabindex' );
		expect(
			heading.closest(
				'.newspack-intelligence__section-head.newspack-nodes-section-heading'
			)
		).not.toBeNull();
	} );

	it( 'says its description muted under the title, and nothing without one', () => {
		const { rerender, container } = render(
			<Header
				title="Quillmere desk"
				description="What the desk filed this week."
			/>
		);
		expect(
			screen.getByText( 'What the desk filed this week.' )
		).toHaveClass( 'newspack-nodes-status', 'is-muted' );
		rerender( <Header title="Quillmere desk" /> );
		expect( container.querySelector( 'p' ) ).toBeNull();
	} );

	it( 'stands its actions at the end of its line', () => {
		render(
			<Header
				title="Quillmere desk"
				actions={
					<button type="button" className="button">
						Fold the desk
					</button>
				}
			/>
		);
		const actions = screen
			.getByRole( 'button', { name: 'Fold the desk' } )
			.closest( '.newspack-intelligence__actions' );
		expect( actions.parentElement ).toHaveClass(
			'newspack-intelligence__section-head'
		);
	} );

	it( 'names its heading by the id it is given', () => {
		render( <Header id="quill-head" title="Quillmere desk" /> );
		expect( screen.getByRole( 'heading' ) ).toHaveAttribute(
			'id',
			'quill-head'
		);
	} );

	it( 'makes a heading it hands a ref focusable', () => {
		const ref = createRef();
		render( <Header ref={ ref } title="Quillmere desk" /> );
		expect( ref.current ).toBe( screen.getByRole( 'heading' ) );
		ref.current.focus();
		expect( ref.current ).toHaveFocus();
	} );
} );

describe( 'Section', () => {
	it( 'is a region named by its Header, its actions at the end of the line', () => {
		render(
			<Section
				heading="Harbor notes"
				actions={
					<button type="button" className="button">
						Sort harbor
					</button>
				}
			>
				<p>Tide tables</p>
			</Section>
		);
		const region = screen.getByRole( 'region', { name: 'Harbor notes' } );
		expect(
			screen
				.getByRole( 'heading', { name: 'Harbor notes' } )
				.closest( '.newspack-nodes-section-heading' )
		).not.toBeNull();
		expect(
			screen
				.getByRole( 'button', { name: 'Sort harbor' } )
				.closest( '.newspack-intelligence__actions' )
		).not.toBeNull();
		expect( region ).toHaveTextContent( 'Tide tables' );
	} );

	it( 'carries its class, says its description, and hands its ref to its heading', () => {
		const ref = createRef();
		render(
			<Section
				ref={ ref }
				className="newspack-intelligence__harbor"
				heading="Harbor notes"
				description="Every tide the desk logged."
			>
				<p>Tide tables</p>
			</Section>
		);
		const region = screen.getByRole( 'region', { name: 'Harbor notes' } );
		expect( region ).toHaveClass(
			'newspack-intelligence__section',
			'newspack-intelligence__harbor'
		);
		expect( region ).toHaveTextContent( 'Every tide the desk logged.' );
		expect( ref.current ).toBe(
			screen.getByRole( 'heading', { name: 'Harbor notes' } )
		);
		ref.current.focus();
		expect( ref.current ).toHaveFocus();
	} );
} );

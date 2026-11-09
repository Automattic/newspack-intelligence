/* eslint-env jest */
/**
 * An entity chip is the one pill every view opens an entity from: full-round
 * rather than a button's box, its name inside the part its width cuts short,
 * so its accessible name stays whole, its key where the graph holds no name,
 * and its description as its tooltip.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import { EntityChip } from '../Cite';

describe( 'EntityChip', () => {
	it( 'is a pill naming the entity, which opens it', () => {
		const onOpen = jest.fn();
		render(
			<EntityChip
				entityKey="organization:tern-ledger"
				name="Tern Ledger"
				description="Client since 2025"
				onOpen={ onOpen }
			/>
		);
		const chip = screen.getByRole( 'button', { name: 'Tern Ledger' } );
		expect( chip ).toHaveClass(
			'newspack-nodes-status-badge',
			'is-pill',
			'is-info',
			'newspack-intelligence__pill'
		);
		expect( chip ).not.toHaveClass( 'button' );
		expect( chip ).toHaveAttribute( 'title', 'Client since 2025' );
		expect( within( chip ).getByText( 'Tern Ledger' ) ).toHaveClass(
			'newspack-intelligence__pill-name'
		);
		fireEvent.click( chip );
		expect( onOpen ).toHaveBeenCalledWith( 'organization:tern-ledger' );
	} );

	it( 'names an entity the graph holds no name for by its key', () => {
		render(
			<EntityChip
				entityKey="product:quill-relay"
				name=""
				onOpen={ jest.fn() }
			/>
		);
		expect(
			screen.getByRole( 'button', { name: 'product:quill-relay' } )
		).toBeInTheDocument();
	} );
} );

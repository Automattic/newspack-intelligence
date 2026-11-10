/* eslint-env jest */
/**
 * An entity chip is the one pill every view opens an entity from: full-round
 * rather than a button's box, its name inside the part its width cuts short,
 * so its accessible name stays whole, its key where the graph holds no name,
 * and its description as its tooltip. Given a removal, the pill holds a
 * second button, an × named for what it removes, beside the one opening it.
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

	it( 'holds an × inside the pill that removes the entity, apart from opening it', () => {
		const onOpen = jest.fn();
		const onRemove = jest.fn();
		render(
			<EntityChip
				entityKey="organization:prairie-signal"
				name="Prairie Signal"
				onOpen={ onOpen }
				onRemove={ onRemove }
				removeLabel="Remove Prairie Signal from this item"
			/>
		);
		const open = screen.getByRole( 'button', { name: 'Prairie Signal' } );
		const remove = screen.getByRole( 'button', {
			name: 'Remove Prairie Signal from this item',
		} );
		const pill = open.parentElement;
		expect( pill ).toHaveClass(
			'newspack-nodes-status-badge',
			'is-pill',
			'is-info',
			'newspack-intelligence__pill',
			'is-removable'
		);
		expect( pill ).toContainElement( remove );
		expect( remove ).toHaveClass( 'newspack-intelligence__pill-remove' );
		fireEvent.click( remove );
		expect( onRemove ).toHaveBeenCalledWith(
			'organization:prairie-signal'
		);
		expect( onOpen ).not.toHaveBeenCalled();
		fireEvent.click( open );
		expect( onOpen ).toHaveBeenCalledWith( 'organization:prairie-signal' );
	} );

	it( 'offers no × without a removal', () => {
		render(
			<EntityChip
				entityKey="organization:prairie-signal"
				name="Prairie Signal"
				onOpen={ jest.fn() }
			/>
		);
		expect( screen.getAllByRole( 'button' ) ).toHaveLength( 1 );
	} );
} );

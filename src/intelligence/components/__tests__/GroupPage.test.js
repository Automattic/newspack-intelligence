/* eslint-env jest */
/**
 * GroupPage renders a lens's ranked theme groups in the answer's order, each
 * headed by its theme with its count, kind, newest day and client chips, its
 * items behind a disclosure button; a group of one is that item's row; and
 * it pages by group.
 */

import { render, screen, fireEvent, within } from '@testing-library/react';
import GroupPage from '../GroupPage';
import { GROUPS } from '../../views/__tests__/fixtures';

const page = ( props = {} ) =>
	render(
		<GroupPage
			answer={ GROUPS }
			starts={ [ 0 ] }
			onStarts={ jest.fn() }
			onOpenEntity={ jest.fn() }
			{ ...props }
		/>
	);

const groupOf = ( theme ) =>
	screen.getByRole( 'heading', { level: 4, name: theme } ).closest( 'li' );

describe( 'GroupPage', () => {
	it( 'heads each group with its theme, in the order the answer gives', () => {
		page();
		expect(
			screen
				.getAllByRole( 'heading', { level: 4 } )
				.map( ( heading ) => heading.textContent )
		).toEqual( [ 'site blocked from sending email', 'bot registrations' ] );
	} );

	it( 'shows a group’s count, kind and newest day', () => {
		page();
		expect(
			within( groupOf( 'site blocked from sending email' ) ).getByText(
				'12 items · complaint · 2026-09-23'
			)
		).toBeInTheDocument();
		expect(
			within( groupOf( 'bot registrations' ) ).getByText(
				'3 items · incident · 2026-09-24'
			)
		).toBeInTheDocument();
	} );

	it( 'opens each client of a group from its chip', () => {
		const onOpenEntity = jest.fn();
		page( { onOpenEntity } );
		const group = groupOf( 'site blocked from sending email' );
		fireEvent.click(
			within( group ).getByRole( 'button', { name: 'Harbor Ledger' } )
		);
		expect( onOpenEntity ).toHaveBeenCalledWith(
			'organization:harbor-ledger'
		);
		expect(
			within( group ).getByRole( 'button', { name: 'Acme Quillsheet' } )
		).toBeInTheDocument();
	} );

	it( 'expands a group onto its item rows and collapses it again', () => {
		const onSave = jest.fn();
		page( { onSave } );
		const toggle = screen.getByRole( 'button', {
			name: 'Show items: site blocked from sending email',
		} );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toBeNull();
		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		expect( toggle ).toHaveAccessibleName(
			'Hide items: site blocked from sending email'
		);
		const group = groupOf( 'site blocked from sending email' );
		expect(
			within( group ).getByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-120' );
		expect(
			within( group ).getByRole( 'link', {
				name: 'Harbor Ledger newsletters held',
			} )
		).toBeInTheDocument();
		fireEvent.click(
			within( group ).getAllByRole( 'button', {
				name: 'Save to collection',
			} )[ 1 ]
		);
		expect( onSave ).toHaveBeenCalledWith( GROUPS.groups[ 0 ].items[ 1 ] );
		expect(
			screen.queryByRole( 'link', {
				name: 'Harbor Ledger floods with fake readers',
			} )
		).toBeNull();
		fireEvent.click( toggle );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect(
			screen.queryByRole( 'link', {
				name: 'Acme mail bounces at the sender',
			} )
		).toBeNull();
	} );

	it( 'renders a group of one as that item’s row, themed or not', () => {
		page();
		expect(
			screen.queryByRole( 'heading', { name: 'storage extension' } )
		).toBeNull();
		expect(
			screen.getByRole( 'link', {
				name: 'Acme asks for more media storage',
			} )
		).toHaveAttribute( 'href', 'https://linear.example/SUP-77' );
		expect(
			screen.getByRole( 'link', {
				name: 'Acme Quillsheet wins regional prize',
			} )
		).toHaveAttribute( 'href', 'https://inkfold-review.example/acme' );
		expect(
			screen.getAllByRole( 'button', { name: /^Show items/ } )
		).toHaveLength( 2 );
	} );

	it( 'heads a group that answers no items and no clients, disclosing none', () => {
		page( {
			answer: {
				groups: [
					{
						theme: 'registration bots',
						kind: 'incident',
						count: 1,
						newest_ts: 1790294400,
					},
				],
				total: 1,
			},
		} );
		const group = groupOf( 'registration bots' );
		expect(
			within( group ).getByText( '1 item · incident · 2026-09-25' )
		).toBeInTheDocument();
		fireEvent.click(
			within( group ).getByRole( 'button', {
				name: 'Show items: registration bots',
			} )
		);
		expect( within( group ).queryByRole( 'link' ) ).toBeNull();
		expect(
			within( group )
				.getAllByRole( 'button' )
				.map( ( b ) => b.textContent )
		).toEqual( [ 'Hide items' ] );
	} );

	it( 'renders two one-story groups sharing a story as two keyed rows', () => {
		const story = GROUPS.groups[ 2 ].items[ 0 ];
		page( {
			answer: {
				groups: [
					{ ...GROUPS.groups[ 2 ], items: [ story ] },
					{
						...GROUPS.groups[ 2 ],
						theme: 'media storage limits',
						items: [ story ],
					},
					{ ...GROUPS.groups[ 1 ], theme: story.item_key },
				],
				total: 3,
			},
		} );
		expect(
			screen.getAllByRole( 'link', {
				name: 'Acme asks for more media storage',
			} )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'heading', { level: 4, name: story.item_key } )
		).toBeInTheDocument();
	} );

	it( 'says the fixed order the groups are ranked in, and offers no switch', () => {
		page();
		expect(
			screen.getByText(
				'Ranked by severity, clients affected, then recency'
			)
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Show newest first' } )
		).toBeNull();
	} );

	it( 'pages by group, starting the next page past the groups shown', () => {
		const onStarts = jest.fn();
		page( { starts: [ 0, 4 ], onStarts } );
		expect( screen.getByText( '4 of 41 groups' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Next' } ) );
		expect( onStarts ).toHaveBeenCalledWith( [ 0, 4, 8 ] );
	} );
} );

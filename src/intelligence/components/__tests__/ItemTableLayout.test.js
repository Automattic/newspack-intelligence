/* eslint-env jest */
/* global NEWSPACK_NODES_SRC */
/**
 * A table row's cells stack their parts. Stock DataViews lays each cell's
 * wrapper out as a flex row, so a cell handing it several blocks would set
 * them side by side and squeeze the title. Each cell hands the wrapper one
 * element, and the title cell's and the kind cell's parts stack inside it:
 * the title's head, the toggle counting its other copies and the drawer
 * laying out how it was read; the kind's badge and its kind check.
 */

import fs from 'fs';
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';
import { fireEvent, screen, within } from '@testing-library/react';
import { Core } from '@newspack-nodes/runtime';
import { renderOnPage } from '../../__tests__/pageReads';
import { intelWire } from '../../__tests__/intelWire';
import { ITEM, pageData } from '../../views/__tests__/fixtures';
import ItemList from '../ItemList';

const { winning } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/css-reader.cjs' )
);
const { sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);

const STOCK = path.resolve(
	__dirname,
	'../../../../node_modules/@wordpress/dataviews/build-style/style.css'
);
if ( ! fs.existsSync( STOCK ) ) {
	throw new Error(
		`no stock DataViews stylesheet at ${ STOCK }; run npm ci in newspack-intelligence`
	);
}
const compiled = ( file ) =>
	sass.compile( file, { importers: [ sassImporter( NEWSPACK_NODES_SRC ) ] } )
		.css;

/**
 * A sheet keeping the rules jsdom can match: it cannot read every selector
 * a stylesheet holds, and none it drops reaches a table cell.
 *
 * @param {string} css  The sheet.
 * @param {string} from Where it came from.
 * @return {import('postcss').Root} The rules jsdom reads.
 */
const readable = ( css, from ) => {
	const sheet = scss.parse( css, { from } );
	const probe = document.createElement( 'div' );
	sheet.each( ( node ) => {
		try {
			if ( 'rule' !== node.type ) {
				throw new Error( 'not a rule' );
			}
			node.selectors.forEach( ( one ) => probe.matches( one ) );
		} catch ( _err ) {
			node.remove();
		}
	} );
	return sheet;
};

const SHEETS = [
	readable( fs.readFileSync( STOCK, 'utf8' ), STOCK ),
	readable(
		compiled(
			path.join( NEWSPACK_NODES_SRC, 'ui/newspack-nodes-ui.scss' )
		),
		'newspack-nodes-ui'
	),
	readable(
		compiled( path.resolve( __dirname, '../../styles/intelligence.scss' ) ),
		'intelligence'
	),
];

const display = ( element ) => winning( element, 'display', SHEETS );

/** An item with other copies and a kind check, apart from every default. */
const KESTREL = {
	...ITEM,
	item_key: 'feed:kestrel-weekly-88',
	title: 'Kestrel Weekly names a night editor for its river bureau',
	copies: 4,
	kind_check: { kind: 'award', verdict: 'passed', ts: 1790121600 },
};

beforeEach( () => {
	Core.reset();
	window.NewspackNodesData = pageData();
	intelWire();
} );

/**
 * The wrapper DataViews draws a cell's content in, by the cell it is in.
 *
 * @param {HTMLElement} cell The cell.
 * @return {HTMLElement} The wrapper.
 */
const wrapperOf = ( cell ) =>
	cell.querySelector( '.dataviews-view-table__cell-content-wrapper' );

/**
 * Whether a parent lays its children out one under another: a block box
 * whose every child is a block box. Where every rule leaves the display
 * unset, the element keeps its own, which for a `div` or a `p` is block.
 *
 * @param {HTMLElement} parent The parent.
 */
const expectStacked = ( parent ) => {
	expect( [ undefined, 'block' ] ).toContain( display( parent ) );
	for ( const child of parent.children ) {
		expect( [ 'DIV', 'P', 'SECTION' ] ).toContain( child.tagName );
		expect( [ undefined, 'block', 'flex', 'grid' ] ).toContain(
			display( child )
		);
	}
};

describe( 'an item table row', () => {
	it( 'stacks the title cell’s head, its copies and how it was read', async () => {
		renderOnPage(
			<ItemList
				items={ [ KESTREL ] }
				view={ { type: 'table', page: 1, perPage: 7 } }
				layout="table"
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				rowProps={ { onOpenEntity: jest.fn() } }
			/>
		);
		const link = await screen.findByRole( 'link', {
			name: KESTREL.title,
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'More actions' } )
		);
		fireEvent.click(
			within( await screen.findByRole( 'menu' ) ).getByRole( 'menuitem', {
				name: 'How this was read',
			} )
		);
		const [ titleCell, kindCell ] = link
			.closest( 'tr' )
			.querySelectorAll( 'td' );
		const wrapper = wrapperOf( titleCell );
		expect( display( wrapper ) ).toBe( 'flex' );
		expect( wrapper.children ).toHaveLength( 1 );
		const [ stack ] = wrapper.children;
		expectStacked( stack );
		expect( stack.children.length ).toBeGreaterThanOrEqual( 3 );
		expect(
			within( stack ).getByRole( 'button', {
				name: 'and 4 other copies',
			} )
		).toBeInTheDocument();
		expect(
			within( stack ).getByRole( 'region', {
				name: 'How this was read',
			} )
		).toBeInTheDocument();

		const kind = wrapperOf( kindCell );
		expect( kind.children ).toHaveLength( 1 );
		const [ kindStack ] = kind.children;
		expect( [ undefined, 'block' ] ).toContain( display( kindStack ) );
		const check = kindStack.lastElementChild;
		expect( check.tagName ).toBe( 'P' );
		expect( [ undefined, 'block' ] ).toContain( display( check ) );
		expect( check.previousElementSibling ).toHaveTextContent( 'Prize' );
		expect( kind ).toHaveTextContent( 'Prize' );
		expect( kind ).toHaveTextContent( /Prize passed its kind check on/ );
	} );

	it( 'hands every other cell’s wrapper one element at most', async () => {
		renderOnPage(
			<ItemList
				items={ [ KESTREL ] }
				view={ { type: 'table', page: 1, perPage: 7 } }
				layout="table"
				onChangeView={ () => {} }
				empty="Nothing filed here."
				paginationInfo={ { totalItems: 1, totalPages: 1 } }
				rowProps={ { onOpenEntity: jest.fn() } }
			/>
		);
		const link = await screen.findByRole( 'link', {
			name: KESTREL.title,
		} );
		for ( const cell of link.closest( 'tr' ).querySelectorAll( 'td' ) ) {
			expect( wrapperOf( cell ).children.length ).toBeLessThanOrEqual(
				1
			);
		}
	} );
} );

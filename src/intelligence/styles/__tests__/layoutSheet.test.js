/* eslint-env jest */
/**
 * A composed page's layout sits the parent's own gap under the header above
 * it, adding no margin of its own, and a list of item rows, the only place
 * a row is a list item, draws no list marker.
 */

/* global NEWSPACK_NODES_SRC */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';

const { sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);
const { winning } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/css-reader.cjs' )
);

const SHEET = path.resolve( __dirname, '../intelligence.scss' );
const STOCK = path.resolve(
	NEWSPACK_NODES_SRC,
	'../node_modules/@wordpress/dataviews/build-style/style.css'
);

// The stock activity rules an item row meets; jsdom cannot read every other.
const stock = scss.parse( fs.readFileSync( STOCK, 'utf8' ), {
	from: STOCK,
} );
stock.each( ( node ) => {
	if (
		'rule' !== node.type ||
		! /dataviews-view-activity/.test( node.selector ) ||
		/:has\(/.test( node.selector )
	) {
		node.remove();
	}
} );

const SHEETS = [
	stock,
	scss.parse(
		sass.compile( SHEET, {
			importers: [ sassImporter( NEWSPACK_NODES_SRC ) ],
		} ).css,
		{ from: SHEET }
	),
];

beforeEach( () => {
	document.body.innerHTML = `
		<div class="newspack-intelligence">
			<div class="newspack-intelligence__view">
				<div class="newspack-nodes-composed-section-head">Briefing</div>
				<div class="newspack-nodes-composed-layout">
					<ol class="newspack-intelligence__items">
						<li class="newspack-intelligence__item">Heron cove reopens</li>
					</ol>
				</div>
			</div>
		</div>`;
} );

describe( 'a composed page’s layout', () => {
	it( 'adds no margin above itself, leaving the gap to its parent', () => {
		const layout = document.querySelector(
			'.newspack-nodes-composed-layout'
		);
		expect( winning( layout, 'margin-top', SHEETS ) ).toBeUndefined();
	} );

	it( 'draws a list of item rows with no list marker', () => {
		const list = document.querySelector( '.newspack-intelligence__items' );
		expect( winning( list, 'list-style-type', SHEETS ) ).toBe( 'none' );
	} );
} );

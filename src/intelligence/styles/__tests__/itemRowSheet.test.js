/* @jest-environment node */
/**
 * The compiled Intelligence sheet lays an item row out so nothing in it
 * scrolls sideways at 390 px: a pill may shrink and cuts its name with an
 * ellipsis, a title breaks anywhere, and no row rule sets `overflow-x`. The
 * title reads in Ink at rest and takes the accent on hover and focus alone;
 * a pill and the source's initial take their paint from the shared status
 * badge, so this sheet gives them geometry alone; and the meta line spaces
 * its cells, with no mark between them.
 * Review's joined control, an entity chip then its glyphs, rounds its outer
 * corners and squares the joins whichever segment is the chip.
 */

/* global NEWSPACK_NODES_SRC */
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';

const SHEET = path.resolve( __dirname, '../intelligence.scss' );

// The aliases resolved against the base the jest config hands every test.
const { sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);

const sheet = scss.parse(
	sass.compile( SHEET, {
		importers: [ sassImporter( NEWSPACK_NODES_SRC ) ],
	} ).css
);

/**
 * Every declaration of the rules whose selector list holds `selector`.
 *
 * @param {string} selector One selector, as compiled.
 * @return {Object<string, string>} Each property's last value.
 */
const declared = ( selector ) => {
	const found = {};
	sheet.walkRules( ( rule ) => {
		if ( rule.selectors.includes( selector ) ) {
			rule.walkDecls( ( decl ) => {
				found[ decl.prop ] = decl.value;
			} );
		}
	} );
	return found;
};

const ROOT = '.newspack-intelligence';

/** What the shared status badge paints, which this sheet must leave to it. */
const PAINT = [
	'background',
	'color',
	'border-radius',
	'font-size',
	'font-weight',
	'text-transform',
	'letter-spacing',
];

describe( 'the item row sheet', () => {
	it( 'lets a pill shrink and cuts its name with an ellipsis, painting nothing', () => {
		const pill = declared( `${ ROOT } ${ ROOT }__pill` );
		expect( pill ).toEqual(
			expect.objectContaining( { 'min-width': '0', 'max-width': '100%' } )
		);
		for ( const paint of PAINT ) {
			expect( pill ).not.toHaveProperty( paint );
		}
		expect( declared( `${ ROOT }__pill-name` ) ).toEqual(
			expect.objectContaining( {
				overflow: 'hidden',
				'text-overflow': 'ellipsis',
				'white-space': 'nowrap',
			} )
		);
		expect( declared( `${ ROOT }__chips > li` ) ).toEqual(
			expect.objectContaining( { 'min-width': '0' } )
		);
	} );

	it( 'breaks a title anywhere, in Ink at rest and the accent on hover and focus', () => {
		expect( declared( `${ ROOT }__item-head :is(strong, h4)` ) ).toEqual(
			expect.objectContaining( {
				'min-width': '0',
				'overflow-wrap': 'anywhere',
			} )
		);
		expect( declared( `${ ROOT }__item-title` ) ).toEqual( {
			'font-size': 'var(--np-text-s)',
			'line-height': 'var(--np-lh-s)',
			'font-weight': '600',
		} );
		expect( declared( `${ ROOT }__item-title a` ) ).toEqual(
			expect.objectContaining( {
				color: 'var(--ink, var(--np-text))',
				'text-decoration': 'none',
			} )
		);
		for ( const state of [ ':hover', ':focus' ] ) {
			expect( declared( `${ ROOT }__item-title a${ state }` ) ).toEqual(
				expect.objectContaining( {
					color: 'var(--cyan, var(--np-primary))',
				} )
			);
		}
	} );

	it( 'sets no overflow-x on a row or anything in it', () => {
		const offending = [];
		sheet.walkDecls( 'overflow-x', ( decl ) => {
			if ( /__(item|pill|chips|meta)/.test( decl.parent.selector ) ) {
				offending.push( decl.parent.selector );
			}
		} );
		expect( offending ).toEqual( [] );
	} );

	it( 'leaves the source’s initial and the kind badge their shared paint', () => {
		const icon = declared( `${ ROOT } ${ ROOT }__source-icon` );
		expect( icon ).toEqual(
			expect.objectContaining( {
				width: 'var(--np-lh-2xs)',
				padding: '0',
			} )
		);
		for ( const paint of PAINT ) {
			expect( icon ).not.toHaveProperty( paint );
		}
		expect( declared( `${ ROOT }__kind` ) ).toEqual( {} );
	} );

	it( 'spaces the meta line’s cells', () => {
		expect( declared( `${ ROOT }__meta` ) ).toEqual(
			expect.objectContaining( {
				display: 'flex',
				'flex-wrap': 'wrap',
				'align-items': 'center',
			} )
		);
	} );
} );

describe( 'the joined judgment control', () => {
	const JOIN = `${ ROOT } ${ ROOT }__judgment > :is(.button.button-small, ${ ROOT }__pill)`;

	it( 'squares every segment, the entity pill among them', () => {
		expect( declared( JOIN ) ).toEqual(
			expect.objectContaining( { 'border-radius': '0' } )
		);
	} );

	it( 'rounds the outer corners of the first and last segments, a pill or a glyph', () => {
		expect( declared( `${ JOIN }:first-child` ) ).toEqual( {
			'border-start-start-radius': 'var(--button-radius, 5px)',
			'border-end-start-radius': 'var(--button-radius, 5px)',
		} );
		expect( declared( `${ JOIN }:last-child` ) ).toEqual( {
			'border-start-end-radius': 'var(--button-radius, 5px)',
			'border-end-end-radius': 'var(--button-radius, 5px)',
		} );
	} );

	it( 'overlaps a glyph onto the segment before it, the pill included', () => {
		expect( declared( `${ JOIN } + .button.button-small` ) ).toEqual(
			expect.objectContaining( { 'margin-inline-start': '-1px' } )
		);
	} );
} );

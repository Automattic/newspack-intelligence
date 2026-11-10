/* @jest-environment node */
/**
 * The compiled Intelligence sheet washes a source a marker shows in the
 * accent's wash, counts a marker's number in tabular figures, and lays a
 * sheet at the window's right edge, or at its foot with 16 px top corners
 * when narrow.
 */

/* global NEWSPACK_NODES_SRC */
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';

const SHEET = path.resolve( __dirname, '../intelligence.scss' );

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

const BACKDROP =
	'.newspack-nodes-modal__backdrop.newspack-intelligence__sheet-backdrop';

describe( 'the Ask and sheet rules', () => {
	it( 'washes a shown source in the accent wash, and counts markers in tabular figures', () => {
		expect( declared( `${ ROOT }__item.is-washed` ) ).toEqual( {
			background: 'var(--cyan-subtle, var(--np-primary-subtle))',
		} );
		expect( declared( `${ ROOT }__marker` ) ).toEqual( {
			'font-variant-numeric': 'tabular-nums',
		} );
	} );

	it( 'stands a sheet at the right edge, or at the foot with 16 px top corners when narrow', () => {
		expect( declared( BACKDROP ) ).toEqual(
			expect.objectContaining( { 'justify-content': 'flex-end' } )
		);
		expect(
			declared( `${ BACKDROP } > .newspack-intelligence__sheet` )
		).toEqual( expect.objectContaining( { width: 'min(480px, 100vw)' } ) );
		expect(
			declared(
				`${ BACKDROP } > .newspack-intelligence__sheet.is-bottom`
			)
		).toEqual(
			expect.objectContaining( {
				'align-self': 'flex-end',
				'border-start-start-radius': 'var(--np-radius-lg)',
				'border-start-end-radius': 'var(--np-radius-lg)',
			} )
		);
	} );
} );

/* @jest-environment node */
/**
 * Nothing on the Intelligence page sets type below the product scale's
 * smallest step, `--np-text-2xs` (12px).
 */

/* global NEWSPACK_NODES_SRC */
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';

const SHEET = path.resolve( __dirname, '../intelligence.scss' );
const { sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);
const { stylesheetOffenders } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/type-scale-floor.cjs' )
);

it( 'sets no UI type below --np-text-2xs', () => {
	const compiled = scss.parse(
		sass.compile( SHEET, {
			importers: [ sassImporter( NEWSPACK_NODES_SRC ) ],
		} ).css
	);
	expect( stylesheetOffenders( compiled, 'intelligence.scss' ) ).toEqual(
		[]
	);
} );

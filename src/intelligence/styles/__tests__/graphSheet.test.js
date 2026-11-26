/* eslint-env jest */
/**
 * The graph's paint: each type's color, the lines at rest, what a selection
 * lights and dims, and the canvas's height. The force graph's own sheet and
 * this plugin's ship in files that may load in either order, so each is read
 * both ways, and the colors are resolved under every skin.
 */

/* global NEWSPACK_NODES_SRC */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';
import { pageData } from '../../views/__tests__/fixtures';
import { lightGraph, toEntityGraph } from '../../components/entityGraph';
import { THEMES } from '@newspack-nodes/shared/theme';

const { sassImporter } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/alias-map.cjs' )
);
const { computed, winning } = require(
	path.join( NEWSPACK_NODES_SRC, 'build-kit/css-reader.cjs' )
);

const compile = ( file ) =>
	scss.parse(
		sass.compile( file, {
			importers: [ sassImporter( NEWSPACK_NODES_SRC ) ],
		} ).css
	);
const SHEET = compile( path.resolve( __dirname, '../intelligence.scss' ) );
const OWN = compile(
	path.join( NEWSPACK_NODES_SRC, 'shared/forceGraph/ForceGraph.scss' )
);
// jsdom does not read `:has( > … )`; the rule settles what takes a press.
OWN.walkRules( /:has\(/, ( rule ) => rule.remove() );
const SKINS = compile(
	path.join( NEWSPACK_NODES_SRC, 'theme/newspack-theme.scss' )
);
// The shared badge's and active button's rules; jsdom cannot read every other.
const SHARED = compile(
	path.join( NEWSPACK_NODES_SRC, 'ui/newspack-nodes-ui.scss' )
);
SHARED.each( ( one ) => {
	if (
		'rule' !== one.type ||
		! /status-badge|\.button\.is-active$/.test( one.selector )
	) {
		one.remove();
	}
} );
// The skins the shared controls set `color-scheme: dark` for.
const DARK_SKINS = fs
	.readFileSync(
		path.join( NEWSPACK_NODES_SRC, 'shared/styles/_controls.scss' ),
		'utf8'
	)
	.match( /\$np-dark-skins:\s*([^;]+);/ )[ 1 ]
	.split( ',' )
	.map( ( skin ) => skin.trim() );

const ORDERS = [
	[ OWN, SHEET ],
	[ SHEET, OWN ],
];

const BLOCK = 'newspack-nodes-force-graph';

/**
 * What both load orders make of a longhand on a part of the canvas.
 *
 * @param {string} part     The part, under the svg.
 * @param {string} at       A selector of the element read.
 * @param {string} longhand The longhand.
 * @return {string|undefined} Its winning value.
 */
const paint = ( part, at, longhand ) => {
	document.body.innerHTML = `
		<div class="newspack-intelligence"><div class="newspack-intelligence__graph">
			<div class="newspack-intelligence__graph-canvas">
				<svg class="${ BLOCK }">${ part }</svg>
			</div>
			<div class="newspack-intelligence__graph-list"></div>
		</div></div>`;
	const el = document.querySelector( at );
	const [ one, two ] = ORDERS.map( ( sheets ) =>
		winning( el, longhand, sheets )
	);
	expect( two ).toBe( one );
	return one;
};

const node = ( names, longhand, part = '__node' ) =>
	paint(
		`<g class="${ BLOCK }__node ${ names }"><rect class="${ BLOCK }__shape"></rect></g>`,
		`.${ BLOCK }${ part }`,
		longhand
	);
const shape = ( names, longhand ) => node( names, longhand, '__shape' );
const line = ( names, longhand ) =>
	paint(
		`<path class="${ BLOCK }__link ${ names }">`,
		`.${ BLOCK }__link`,
		longhand
	);

/** The six types' entities, the first two sharing 9 items. */
const ANSWER = {
	entities: [
		'organization',
		'person',
		'place',
		'product',
		'topic',
		'event',
	].map( ( type ) => ( {
		key: `${ type }:heron`,
		name: 'Heron',
		type,
		roles: [],
		items: 1,
	} ) ),
	pairs: [ [ 0, 1, 9 ] ],
};

/**
 * Those entities as the canvas is handed them.
 *
 * @param {?string} selected The selected entity.
 * @return {Object} The graph.
 */
const graphOf = ( selected ) =>
	lightGraph( toEntityGraph( ANSWER, { minShared: 9 } ), selected );

/**
 * What a longhand comes to under a skin, every token resolved, whichever
 * order the sheets load in.
 *
 * @param {string}     slug     The skin.
 * @param {string}     html     What stands on the page.
 * @param {string}     at       A selector of the element read.
 * @param {string}     longhand The longhand, or a token.
 * @param {Object[][]} orders   The sheets, in each order.
 * @return {string} Its value.
 */
const skinned = ( slug, html, at, longhand, orders ) => {
	document.body.innerHTML = `
		<div class="theme-${ slug }"><div class="newspack-nodes-ui newspack-nodes-theme newspack-nodes-skin-root">
			<div class="newspack-intelligence">${ html }</div>
		</div></div>`;
	const el = document.querySelector( at );
	const [ first, ...rest ] = orders.map( ( sheets ) =>
		computed( el, longhand, [ SKINS, ...sheets ] )
	);
	rest.forEach( ( one ) => expect( one ).toBe( first ) );
	return first;
};

/**
 * The same of a part of the canvas.
 *
 * @param {string} slug     The skin.
 * @param {string} part     The part, under the svg.
 * @param {string} at       A selector of the element read.
 * @param {string} longhand The longhand.
 * @return {string} Its value.
 */
const onCanvas = ( slug, part, at, longhand ) =>
	skinned(
		slug,
		`<div class="newspack-intelligence__graph-canvas"><svg class="${ BLOCK }">${ part }</svg></div>`,
		at,
		longhand,
		ORDERS
	);

/**
 * Each type's stroke, or another longhand, under a skin, as its scheme takes it.
 *
 * @param {string} slug       The skin.
 * @param {string} [longhand] The longhand; the stroke without one.
 * @return {string[]} The values, in the vocabulary's order.
 */
const typeColors = ( slug, longhand = 'stroke' ) =>
	graphOf( null ).nodes.map( ( { className } ) =>
		schemed(
			slug,
			onCanvas(
				slug,
				`<g class="${ BLOCK }__node ${ className }"><rect class="${ BLOCK }__shape"></rect></g>`,
				`.${ BLOCK }__shape`,
				longhand
			)
		)
	);

/**
 * The branch of a `light-dark()` value a skin's color scheme takes.
 *
 * @param {string} slug  The skin.
 * @param {string} value A resolved value.
 * @return {string} The light or the dark branch, or the value without one.
 */
const schemed = ( slug, value ) => {
	const inner = value.match( /^light-dark\((.*)\)$/ )?.[ 1 ];
	if ( ! inner ) {
		return value;
	}
	let depth = 0;
	const comma = [ ...inner ].findIndex( ( char ) => {
		depth += { '(': 1, ')': -1 }[ char ] ?? 0;
		return ',' === char && 0 === depth;
	} );
	const [ light, dark ] = [
		inner.slice( 0, comma ),
		inner.slice( comma + 1 ),
	].map( ( one ) => one.trim() );
	return DARK_SKINS.includes( slug ) ? dark : light;
};

/**
 * A `color-mix( in srgb, A p%, B )` as its two colors and A's share.
 *
 * @param {string} value A resolved color.
 * @return {{color: string, share: number, with: string}} The mix.
 */
const mixOf = ( value ) => {
	const [ , color, share, other ] = value.match(
		/^color-mix\(in srgb, (#[0-9a-f]{3,6}) (\d+)%, (#[0-9a-f]{3,6}|transparent)\)$/i
	);
	return { color, share: Number( share ), with: other };
};

/**
 * A longhand of a part of the canvas under a skin, as its scheme takes it.
 *
 * @param {string} slug     The skin.
 * @param {string} part     The part, under the svg.
 * @param {string} at       A selector of the element read.
 * @param {string} longhand The longhand.
 * @return {string} Its value.
 */
const paintedOn = ( slug, part, at, longhand ) =>
	schemed( slug, onCanvas( slug, part, at, longhand ) );

/**
 * A token's value under a skin, read on the root that sets it.
 *
 * @param {string} slug  The skin.
 * @param {string} token The token.
 * @return {string} Its value.
 */
const tokenOf = ( slug, token ) =>
	skinned( slug, '', '.newspack-nodes-skin-root', token, [ [] ] );

beforeEach( () => {
	window.NewspackNodesData = pageData();
} );

describe( 'the graph’s paint', () => {
	it( 'colors each type from a palette slot of its own', () => {
		const strokes = graphOf( null ).nodes.map( ( { className } ) =>
			shape( className, 'stroke' )
		);
		strokes.forEach( ( stroke, i ) => {
			const slot = [ 1, 2, 4, 5, 8, 7 ][ i ];
			expect( stroke ).toMatch(
				new RegExp(
					`^light-dark\\(var\\(--chart-${ slot }, var\\(--np-chart-${ slot }\\)\\),`
				)
			);
		} );
	} );

	describe.each( THEMES.map( ( { slug } ) => [ slug ] ) )(
		'under the %s skin',
		( slug ) => {
			const dark = DARK_SKINS.includes( slug );

			it( 'resolves every type’s color', () => {
				const slots = typeColors( slug ).map( ( stroke ) =>
					dark ? mixOf( stroke ).color : stroke
				);
				slots.forEach( ( color ) =>
					expect( color ).toMatch( /^#[0-9a-f]{6}$/i )
				);
				typeColors( slug, 'fill' ).forEach( ( fill, i ) => {
					const { color, share } = mixOf( fill );
					expect( color ).toBe( slots[ i ] );
					expect( share ).toBe( dark ? 50 : 14 );
				} );
			} );

			it( 'edges each box in its type’s color, toward the ink on a dark skin', () => {
				const slots = typeColors( slug, 'fill' ).map(
					( fill ) => mixOf( fill ).color
				);
				const ink = tokenOf( slug, '--ink' );
				expect( typeColors( slug ) ).toEqual(
					slots.map( ( color ) =>
						dark
							? `color-mix(in srgb, ${ color } 55%, ${ ink })`
							: color
					)
				);
			} );

			it( 'draws a line faintly at rest, full when lit, and fainter dimmed', () => {
				const stroke = ( names ) =>
					paintedOn(
						slug,
						`<path class="${ BLOCK }__link ${ names }">`,
						`.${ BLOCK }__link`,
						'stroke'
					);
				const share = ( names ) => {
					const mix = mixOf( stroke( names ) );
					expect( mix.color ).toBe( tokenOf( slug, '--ink-3' ) );
					expect( mix.with ).toBe( 'transparent' );
					return mix.share;
				};
				expect( share( '' ) ).toBe( dark ? 70 : 35 );
				expect( share( 'is-dimmed' ) ).toBe( dark ? 25 : 10 );
				expect( stroke( 'is-lit' ) ).toBe( tokenOf( slug, '--cyan' ) );
			} );

			it( 'paints the selected chip as an active button, and any other as its badge', () => {
				const paintOf = ( names ) =>
					[ 'background-color', 'color' ].map( ( longhand ) =>
						skinned(
							slug,
							`<button class="${ names }"></button>`,
							'button',
							longhand,
							[ [ SHEET, SHARED ] ]
						)
					);
				const pill =
					'newspack-nodes-status-badge is-pill is-info newspack-intelligence__pill';
				const active = paintOf( 'button is-active' );
				active.forEach( ( one ) => expect( one ).toBeTruthy() );
				expect( paintOf( `${ pill } is-active` ) ).toEqual( active );
				expect( paintOf( pill ) ).toEqual(
					paintOf( 'newspack-nodes-status-badge is-info' )
				);
				expect( paintOf( pill ) ).not.toEqual( active );
			} );
		}
	);

	it( 'rings the selected entity in ink, whatever its type', () => {
		const { nodes } = graphOf( 'event:heron' );
		expect(
			shape( `${ nodes[ 5 ].className } is-selected`, 'stroke' )
		).toBe( 'var(--ink, var(--np-text))' );
	} );

	it( 'dims what a selection does not reach', () => {
		const { nodes, links } = graphOf( 'event:heron' );
		expect(
			Number( node( nodes[ 0 ].className, 'opacity' ) )
		).toBeLessThan( 0.5 );
		expect( node( nodes[ 5 ].className, 'opacity' ) ).toBeUndefined();
		expect( line( links[ 0 ].className, 'stroke' ) ).toMatch(
			/ 10%, transparent\), .* 25%, transparent\)\)$/
		);
	} );

	it( 'lights the selected entity’s lines in the accent', () => {
		const { nodes, links } = graphOf( 'person:heron' );
		expect( line( links[ 0 ].className, 'stroke' ) ).toBe(
			'var(--cyan, var(--np-primary))'
		);
		expect( node( nodes[ 0 ].className, 'opacity' ) ).toBeUndefined();
		expect( line( '', 'stroke' ) ).toMatch(
			/^light-dark\(color-mix\(in srgb, var\(--ink-3, var\(--np-text-secondary\)\) 35%/
		);
	} );

	it( 'fills the viewport under its header, and scrolls its list', () => {
		expect(
			paint( '', '.newspack-intelligence__graph', 'grid-template-rows' )
		).toMatch( /^max\(\d+px, 100vh - \d+px\)$/ );
		expect(
			paint( '', '.newspack-intelligence__graph-list', 'overflow-y' )
		).toBe( 'auto' );
	} );
} );

/* eslint-env jest */
/**
 * The graph's paint: each type's color on a box or a circle, the lines at
 * rest, what a selection lights and dims, and the canvas's height. The force
 * graph's own sheet and this plugin's ship in files that may load in either
 * order, so each is read both ways, and the colors are resolved under every
 * skin.
 */

/* global NEWSPACK_NODES_SRC */
import path from 'path';
import * as sass from 'sass';
import scss from 'postcss-scss';
import { pageData } from '../../views/__tests__/fixtures';
import { lightGraph } from '@newspack-nodes/shared/forceGraph';
import { toEntityGraph } from '../../components/entityGraph';
import { THEMES } from '@newspack-nodes/shared/theme';
import { DARK_SKINS, schemed } from '@newspack-nodes/shared/test-utils/schemes';

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
// The shared badge's rules; jsdom cannot read every other.
const SHARED = compile(
	path.join( NEWSPACK_NODES_SRC, 'ui/newspack-nodes-ui.scss' )
);
SHARED.each( ( one ) => {
	if ( 'rule' !== one.type || ! /status-badge/.test( one.selector ) ) {
		one.remove();
	}
} );
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

/** The six types' entities, each two in turn sharing 9 items. */
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
	pairs: [
		[ 0, 1, 9 ],
		[ 2, 3, 9 ],
		[ 4, 5, 9 ],
	],
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
 * @param {string} [view]     The view's classes, such as `is-far`.
 * @param {string} [element]  The shape's element, a box's `rect` or a `circle`.
 * @return {string[]} The values, in the vocabulary's order.
 */
const typeColors = ( slug, longhand = 'stroke', view = '', element = 'rect' ) =>
	graphOf( null ).nodes.map( ( { className } ) =>
		schemed(
			onCanvas(
				slug,
				`<g class="${ BLOCK }__view ${ view }"><g class="${ BLOCK }__node ${ className }"><${ element } class="${ BLOCK }__shape"></${ element }></g></g>`,
				`.${ BLOCK }__shape`,
				longhand
			),
			slug
		)
	);

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
	schemed( onCanvas( slug, part, at, longhand ), slug );

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
					`^color-mix\\(in srgb, var\\(--chart-${ slot }, var\\(--np-chart-${ slot }\\)\\) 35%,`
				)
			);
		} );
	} );

	describe.each( THEMES.map( ( { slug } ) => [ slug ] ) )(
		'under the %s skin',
		( slug ) => {
			const dark = DARK_SKINS.includes( slug );

			it( 'resolves every type’s color', () => {
				const slots = typeColors( slug ).map(
					( stroke ) => mixOf( stroke ).color
				);
				slots.forEach( ( color ) =>
					expect( color ).toMatch( /^#[0-9a-f]{6}$/i )
				);
				typeColors( slug, 'fill' ).forEach( ( fill, i ) => {
					const { color, share } = mixOf( fill );
					expect( color ).toBe( slots[ i ] );
					expect( share ).toBe( 35 );
				} );
			} );

			it( 'edges each box in its type’s color, toward the ink', () => {
				const slots = typeColors( slug, 'fill' ).map(
					( fill ) => mixOf( fill ).color
				);
				const ink = tokenOf( slug, '--ink' );
				expect( typeColors( slug ) ).toEqual(
					slots.map(
						( color ) =>
							`color-mix(in srgb, ${ color } 35%, ${ ink })`
					)
				);
			} );

			it( 'fills each box mostly with its type’s color while names are hidden', () => {
				const near = typeColors( slug, 'fill' );
				const under = dark
					? tokenOf( slug, '--ink' )
					: mixOf( near[ 0 ] ).with;
				expect( typeColors( slug, 'fill', 'is-far' ) ).toEqual(
					near.map(
						( fill ) =>
							`color-mix(in srgb, ${
								mixOf( fill ).color
							} 85%, ${ under })`
					)
				);
			} );

			it( 'paints each circle as it paints a box, over the shared ring', () => {
				for ( const [ longhand, view ] of [
					[ 'stroke', '' ],
					[ 'fill', '' ],
					[ 'fill', 'is-far' ],
				] ) {
					expect(
						typeColors( slug, longhand, view, 'circle' )
					).toEqual( typeColors( slug, longhand, view ) );
				}
			} );

			it( 'writes a circle’s count in the ink', () => {
				expect(
					paintedOn(
						slug,
						`<g class="${ BLOCK }__node is-type-1"><circle class="${ BLOCK }__shape"></circle><text class="${ BLOCK }__detail"></text></g>`,
						`.${ BLOCK }__detail`,
						'fill'
					)
				).toBe( tokenOf( slug, '--ink' ) );
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
				expect( share( '' ) ).toBe( dark ? 50 : 35 );
				expect( share( 'is-dimmed' ) ).toBe( dark ? 20 : 10 );
				expect( stroke( 'is-lit' ) ).toBe( tokenOf( slug, '--cyan' ) );
			} );

			it( 'strengthens a line at rest while names are hidden, and leaves a lit or dimmed one', () => {
				const stroke = ( names, view = '' ) =>
					paintedOn(
						slug,
						`<g class="${ BLOCK }__view ${ view }"><path class="${ BLOCK }__link ${ names }"></path></g>`,
						`.${ BLOCK }__link`,
						'stroke'
					);
				expect( mixOf( stroke( '', 'is-far' ) ).share ).toBe(
					dark ? 85 : 45
				);
				[ 'is-lit', 'is-dimmed' ].forEach( ( names ) =>
					expect( stroke( names, 'is-far' ) ).toBe( stroke( names ) )
				);
			} );

			it( 'paints a chip as its badge', () => {
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
				paintOf( pill ).forEach( ( one ) =>
					expect( one ).toBeTruthy()
				);
				expect( paintOf( pill ) ).toEqual(
					paintOf( 'newspack-nodes-status-badge is-info' )
				);
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
			/ 10%, transparent\), .* 20%, transparent\)\)$/
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

	it( 'centres each checkbox among the filters on the line of a 40px field', () => {
		document.body.innerHTML = `
			<div class="newspack-intelligence"><div class="newspack-intelligence__controls">
				<div class="components-base-control components-checkbox-control"></div>
			</div></div>`;
		const box = document.querySelector( '.components-checkbox-control' );
		const of = ( longhand ) => {
			const [ one, two ] = ORDERS.map( ( sheets ) =>
				winning( box, longhand, sheets )
			);
			expect( two ).toBe( one );
			return one;
		};
		expect( [ 'display', 'align-items', 'min-height' ].map( of ) ).toEqual(
			[ 'flex', 'center', '40px' ]
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

	it( 'rules its list off from the canvas, as the class graph rules its inspector', () => {
		const list = ( longhand ) =>
			paint( '', '.newspack-intelligence__graph-list', longhand );
		expect(
			[ 'width', 'style', 'color' ].map( ( part ) =>
				list( `border-left-${ part }` )
			)
		).toEqual( [
			'1px',
			'solid',
			'var(--paper-shadow, var(--np-border))',
		] );
	} );
} );

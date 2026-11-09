/* eslint-env jest */
/**
 * The plugin ships no operator's name, nor the platform it runs on: every
 * shipped file and doc is read, case aside, and names the operator, its
 * parent or its host only as the plugin's own name, the substrate's, the
 * ContextA8C connector's, the AI proxy's feature header, the P2 provider
 * ContextA8C names, or the vendor lines of the plugin's own header.
 */

const fs = require( 'node:fs' );
const path = require( 'node:path' );

const ROOT = path.resolve( __dirname, '../..' );

/** What is read: the shipped trees, the plugin's two root files and the two docs. */
const SHIPPED = [
	'includes',
	'src',
	'topologies',
	'examples',
	'newspack-intelligence.php',
	'uninstall.php',
	'README.md',
	'AGENTS.md',
];

/** The words no shipped file may hold, case aside. */
const REFUSED = /newspack|automattic|atomic|a8c|wpcom/i;

/** The names that may hold one anywhere: the plugin's, the substrate's, the ContextA8C connector's and the proxy's header. */
const ALLOWED = [
	/newspack[-_](intelligence|nodes)/gi,
	/Newspack_\w+/g,
	/NewspackNodes\w*/g,
	/NEWSPACK_INTELLIGENCE_\w+/g,
	/NEWSPACK_NODES_\w+/g,
	/Newspack Intelligence/g,
	/@newspack-nodes\//g,
	/X-WPCOM-AI-Feature/g,
	/context[-_]?a8c[\w-]*/gi,
];

/** Each file => the whole lines it alone may hold: the plugin header's vendor and ContextA8C's P2 provider. */
const ALLOWED_LINES = {
	'newspack-intelligence.php': [
		' * Author: Automattic',
		' * Author URI: https://newspack.com/',
	],
	'includes/class-context-a8c-p2-node.php': [
		"\tprivate const PROVIDER = 'wpcom';",
	],
};

/**
 * The lines of a file that name a refused word outside every allowance,
 * each as `<file>:<line>: <text>`.
 *
 * @param {string} file A path relative to the plugin, which scopes its whole-line allowances.
 * @param {string} text The file's text.
 * @return {string[]} The lines refused.
 */
function refusedLines( file, text ) {
	const lines = ALLOWED_LINES[ file ] ?? [];
	return text
		.split( '\n' )
		.map( ( line, at ) => [ at, line ] )
		.filter(
			( [ , line ] ) =>
				! lines.includes( line ) &&
				REFUSED.test(
					ALLOWED.reduce(
						( rest, allowed ) => rest.replace( allowed, '' ),
						line
					)
				)
		)
		.map( ( [ at, line ] ) => `${ file }:${ at + 1 }: ${ line.trim() }` );
}

/**
 * Every file under a shipped path, a test directory left out.
 *
 * @param {string} at A path relative to the plugin.
 * @return {string[]} The files, relative to the plugin.
 */
function files( at ) {
	const full = path.join( ROOT, at );
	if ( fs.statSync( full ).isFile() ) {
		return [ at ];
	}
	return fs
		.readdirSync( full, { withFileTypes: true } )
		.filter( ( entry ) => '__tests__' !== entry.name )
		.flatMap( ( entry ) => files( path.join( at, entry.name ) ) );
}

describe( 'shipped names', () => {
	it( 'names no operator, parent or host', () => {
		const hits = SHIPPED.flatMap( files ).flatMap( ( file ) =>
			refusedLines(
				file,
				fs.readFileSync( path.join( ROOT, file ), 'utf8' )
			)
		);

		expect( hits ).toEqual( [] );
	} );

	it( 'refuses a stored vocabulary value, a vendor and a host, case aside', () => {
		expect(
			refusedLines(
				'includes/class-heron.php',
				"'role' => 'newspack_product',\nby AUTOMATTIC\nan Atomic site\nthe Wpcom api"
			)
		).toEqual( [
			"includes/class-heron.php:1: 'role' => 'newspack_product',",
			'includes/class-heron.php:2: by AUTOMATTIC',
			'includes/class-heron.php:3: an Atomic site',
			'includes/class-heron.php:4: the Wpcom api',
		] );
	} );

	it( 'passes the plugin, substrate, connector and proxy names', () => {
		expect(
			refusedLines(
				'includes/class-heron.php',
				'X-WPCOM-AI-Feature: heron\nNewspack_Intelligence\\Prompts\nnewspack-nodes and newspack_intelligence_restricted\nContext_A8C_Linear_Node'
			)
		).toEqual( [] );
	} );

	it( 'admits a whole allowed line only in its own file', () => {
		expect(
			refusedLines(
				'includes/class-context-a8c-p2-node.php',
				"\tprivate const PROVIDER = 'wpcom';\n\t\t'wpcom',"
			)
		).toEqual( [ "includes/class-context-a8c-p2-node.php:2: 'wpcom'," ] );
		expect(
			refusedLines( 'uninstall.php', ' * Author: Automattic' )
		).toEqual( [ 'uninstall.php:1: * Author: Automattic' ] );
	} );
} );

// Jest config — built from the substrate's shared build-kit factory (resolved
// from the sibling newspack-nodes checkout, the same place the @newspack-nodes/*
// aliases point). React + @wordpress/element + d3 are pinned to THIS plugin's
// node_modules so a runtime hook called from an ELN render can't trip React's
// "Invalid hook call" (two dispatchers), and d3 (installed only here) resolves
// for the shared useTimeChart. d3 ships ESM-only, so its packages opt out of
// transformIgnorePatterns; @noble/hashes (the substrate's command signer) is
// ESM-only too.

const path = require( 'node:path' );
const {
	createJestConfig,
} = require( '../newspack-nodes/src/build-kit/jest.cjs' );

// A zone whose day leads UTC's, so a local time cannot pass as a UTC one.
process.env.TZ = 'Pacific/Auckland';

module.exports = createJestConfig( {
	aliasBase: path.resolve( __dirname, '../newspack-nodes/src' ),
	pinReactFrom: path.resolve( __dirname, 'node_modules' ),
	// Shared composition code meets this plugin's block packages, so a test's
	// registry and mock are the ones it reads.
	extraMappers: {
		'^d3$': path.resolve( __dirname, 'node_modules/d3' ),
		'^@wordpress/blocks$': path.resolve(
			__dirname,
			'node_modules/@wordpress/blocks'
		),
		'^@wordpress/block-editor$': path.resolve(
			__dirname,
			'node_modules/@wordpress/block-editor'
		),
		// One copy, so the shared list and a caller's header share a context.
		'^@wordpress/dataviews$': path.resolve(
			__dirname,
			'node_modules/@wordpress/dataviews/build/index.cjs'
		),
		// One copy, as WordPress hands every package one wp.privateApis.
		'^@wordpress/private-apis$': path.resolve(
			__dirname,
			'node_modules/@wordpress/private-apis'
		),
	},
	transformIgnorePatterns: [
		'node_modules/(?!(@noble/.*|d3|d3-.*|internmap|delaunator|robust-predicates)/)',
	],
} );

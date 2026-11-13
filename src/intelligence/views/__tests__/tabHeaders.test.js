/* eslint-env jest */
/**
 * Every tab stands under the one shared `SectionHeader` and lays its content on
 * the page itself: no tab writes a heading, a section head or a card of its
 * own, so none can set a border or a heading's appearance apart from the
 * rest.
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';

/** Each tab's view, by the file the page renders it from. */
const TABS = [
	'Briefing.js',
	'Search.js',
	'Lens.js',
	'Review.js',
	'Collections.js',
	'Watches.js',
];

/** What a tab must leave to the shared header and the page, by pattern. */
const OWN = {
	'heading of its own': /<h[1-6][\s>]/,
	'section head of its own': /newspack-nodes-composed-section-head/,
	card: /<Card[\s>]|newspack-nodes-card|<Answer[\s>]/,
};

describe.each( TABS )( '%s', ( file ) => {
	const source = readFileSync(
		path.resolve( __dirname, '..', file ),
		'utf8'
	);

	it.each( Object.entries( OWN ) )( 'writes no %s', ( what, pattern ) => {
		expect( source ).not.toMatch( pattern );
	} );
} );

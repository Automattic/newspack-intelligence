/**
 * markdownToBlockMarkup — the converter that turns digest markdown into block
 * markup using the block editor's OWN paste engine (registerCoreBlocks +
 * pasteHandler + serialize), so a created draft opens as the exact blocks you'd
 * get by pasting the digest into the editor (tables included).
 *
 * KNOWN jsdom LIMITATION — the markdown→blocks CONVERSION cannot be exercised
 * here. The conversion path calls `registerCoreBlocks` from
 * `@wordpress/block-library`, and that module cannot load under jest: jest
 * resolves its transitive `@wordpress/block-editor` to untranspiled `src/` (the
 * full editor UI — inserter, block-preview, the ESM-only parsel-js), which
 * babel-jest will not execute. (`@wordpress/blocks` itself — pasteHandler /
 * serialize — DOES load under jest; only the block-library registry pulls the
 * editor tree.) The module loads block-library LAZILY, so importing it and the
 * empty-input short-circuit ARE testable; the real conversion is verified
 * IN-BROWSER on the admin page (WordPress enqueues wp-blocks + wp-block-library
 * and the page runs the same pasteHandler path).
 *
 * If a future jest setup makes block-library loadable under jsdom, replace the
 * "real conversion is browser-only" test with assertions that a GFM table →
 * `<!-- wp:table` and `## Heading` → `<!-- wp:heading`.
 */

import { markdownToBlockMarkup } from '../markdownToBlockMarkup';

describe( 'markdownToBlockMarkup', () => {
	it( 'exports a function', () => {
		expect( typeof markdownToBlockMarkup ).toBe( 'function' );
	} );

	it( 'returns an empty string for empty input (short-circuit, no paste)', () => {
		// The short-circuit returns before touching the block registry, so this
		// runs even though block-library cannot load under jsdom. The real
		// markdown→blocks conversion (## → wp:heading, GFM table → wp:table) is
		// browser-only here — see the file docblock for why and what to switch to
		// if block-library ever becomes loadable under jest.
		expect( markdownToBlockMarkup( '' ) ).toBe( '' );
		expect( markdownToBlockMarkup() ).toBe( '' );
	} );

	it( 'lazily registers core blocks once before pasting markdown', () => {
		jest.resetModules();
		const registerCoreBlocks = jest.fn();
		const pasteHandler = jest.fn( () => [ { name: 'core/heading' } ] );
		const serialize = jest.fn( () => '<!-- wp:heading -->Title' );
		jest.doMock( '@wordpress/block-library', () => ( {
			registerCoreBlocks,
		} ) );
		jest.doMock( '@wordpress/blocks', () => ( {
			pasteHandler,
			serialize,
		} ) );

		const {
			markdownToBlockMarkup: isolatedMarkdownToBlockMarkup,
		} = require( '../markdownToBlockMarkup' );

		expect( isolatedMarkdownToBlockMarkup( '## Title' ) ).toBe(
			'<!-- wp:heading -->Title'
		);
		expect( isolatedMarkdownToBlockMarkup( 123 ) ).toBe(
			'<!-- wp:heading -->Title'
		);
		expect( registerCoreBlocks ).toHaveBeenCalledTimes( 1 );
		expect( pasteHandler ).toHaveBeenNthCalledWith( 1, {
			HTML: '',
			plainText: '## Title',
			mode: 'BLOCKS',
		} );
		expect( pasteHandler ).toHaveBeenNthCalledWith( 2, {
			HTML: '',
			plainText: '123',
			mode: 'BLOCKS',
		} );
		expect( serialize ).toHaveBeenCalledWith( [
			{ name: 'core/heading' },
		] );
	} );
} );

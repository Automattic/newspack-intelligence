/**
 * markdownToBlocks — converts the digest's markdown subset into serialized
 * Gutenberg block markup (the block-comment-delimited HTML WordPress stores as
 * post_content), so a REST-created draft opens as native editable blocks.
 */

import { markdownToBlocks } from '../markdownToBlocks';

describe( 'markdownToBlocks', () => {
	it( 'returns an empty string for empty or whitespace-only input', () => {
		expect( markdownToBlocks( '' ) ).toBe( '' );
		expect( markdownToBlocks( '   \n\t\n' ) ).toBe( '' );
		expect( markdownToBlocks() ).toBe( '' );
	} );

	it( 'renders an h1 with a level attribute and a matching h1 tag', () => {
		expect( markdownToBlocks( '# Title' ) ).toBe(
			'<!-- wp:heading {"level":1} -->\n' +
				'<h1 class="wp-block-heading">Title</h1>\n' +
				'<!-- /wp:heading -->'
		);
	} );

	it( 'renders an h2 with NO attribute object (WordPress default)', () => {
		expect( markdownToBlocks( '## Section' ) ).toBe(
			'<!-- wp:heading -->\n' +
				'<h2 class="wp-block-heading">Section</h2>\n' +
				'<!-- /wp:heading -->'
		);
	} );

	it( 'renders an h3 with a level attribute and a matching h3 tag', () => {
		expect( markdownToBlocks( '### Sub' ) ).toBe(
			'<!-- wp:heading {"level":3} -->\n' +
				'<h3 class="wp-block-heading">Sub</h3>\n' +
				'<!-- /wp:heading -->'
		);
	} );

	it( 'renders a paragraph for plain text', () => {
		expect( markdownToBlocks( 'Hello world' ) ).toBe(
			'<!-- wp:paragraph -->\n' +
				'<p>Hello world</p>\n' +
				'<!-- /wp:paragraph -->'
		);
	} );

	it( 'groups consecutive non-blank lines into one paragraph', () => {
		expect( markdownToBlocks( 'line one\nline two' ) ).toBe(
			'<!-- wp:paragraph -->\n' +
				'<p>line one line two</p>\n' +
				'<!-- /wp:paragraph -->'
		);
	} );

	it( 'separates paragraphs on a blank line', () => {
		const out = markdownToBlocks( 'first\n\nsecond' );
		expect( out ).toBe(
			'<!-- wp:paragraph -->\n' +
				'<p>first</p>\n' +
				'<!-- /wp:paragraph -->\n\n' +
				'<!-- wp:paragraph -->\n' +
				'<p>second</p>\n' +
				'<!-- /wp:paragraph -->'
		);
	} );

	it( 'wraps each list item in a list-item block inside one list block', () => {
		const out = markdownToBlocks( '- one\n- two\n* three' );
		expect( out ).toBe(
			'<!-- wp:list -->\n' +
				'<ul class="wp-block-list">' +
				'<!-- wp:list-item -->\n<li>one</li>\n<!-- /wp:list-item -->' +
				'<!-- wp:list-item -->\n<li>two</li>\n<!-- /wp:list-item -->' +
				'<!-- wp:list-item -->\n<li>three</li>\n<!-- /wp:list-item -->' +
				'</ul>\n' +
				'<!-- /wp:list -->'
		);
	} );

	it( 'renders a separator for a dashes-only line', () => {
		expect( markdownToBlocks( '---' ) ).toBe(
			'<!-- wp:separator -->\n' +
				'<hr class="wp-block-separator has-alpha-channel-opacity"/>\n' +
				'<!-- /wp:separator -->'
		);
	} );

	it( 'converts **bold** to a strong tag', () => {
		expect( markdownToBlocks( 'a **bold** word' ) ).toContain(
			'<p>a <strong>bold</strong> word</p>'
		);
	} );

	it( 'converts [text](url) to an anchor', () => {
		expect( markdownToBlocks( 'see [docs](https://x.test/p)' ) ).toContain(
			'<p>see <a href="https://x.test/p">docs</a></p>'
		);
	} );

	it( 'converts an <autolink> to an anchor with the URL as text', () => {
		expect( markdownToBlocks( '<https://x.test/p>' ) ).toContain(
			'<p><a href="https://x.test/p">https://x.test/p</a></p>'
		);
	} );

	it( 'HTML-escapes the five significant chars in text content', () => {
		expect( markdownToBlocks( `a & b < c > d " e ' f` ) ).toContain(
			'<p>a &amp; b &lt; c &gt; d &quot; e &#039; f</p>'
		);
	} );

	it( 'escapes text inside bold and link labels but keeps the tags real', () => {
		const out = markdownToBlocks(
			'**a<b>** and [x&y](https://t.test/?a=1&b=2)'
		);
		expect( out ).toContain( '<strong>a&lt;b&gt;</strong>' );
		expect( out ).toContain(
			'<a href="https://t.test/?a=1&amp;b=2">x&amp;y</a>'
		);
	} );

	it( 'folds a list-item continuation line into the same item', () => {
		const out = markdownToBlocks(
			'- **Thing** – did a thing\n  <https://t.test/pull/1>'
		);
		expect( out ).toContain( '<!-- wp:list -->' );
		expect( out ).not.toContain( '<!-- wp:paragraph -->' );
		expect( out ).toContain(
			'<li><strong>Thing</strong> – did a thing ' +
				'<a href="https://t.test/pull/1">https://t.test/pull/1</a></li>'
		);
	} );

	it( 'converts a GFM table to a core/table block', () => {
		const md = [
			'| Release | Key changes |',
			'|--------|-------------|',
			'| **v6.43** | Access-control |',
		].join( '\n' );
		expect( markdownToBlocks( md ) ).toBe(
			'<!-- wp:table -->\n' +
				'<figure class="wp-block-table"><table>' +
				'<thead><tr><th>Release</th><th>Key changes</th></tr></thead>' +
				'<tbody><tr>' +
				'<td><strong>v6.43</strong></td><td>Access-control</td>' +
				'</tr></tbody>' +
				'</table></figure>\n' +
				'<!-- /wp:table -->'
		);
	} );

	it( 'renders <br> as a real line break inside a table cell, with an autolink', () => {
		const md = [
			'| A | B |',
			'|---|---|',
			'| x | one<br><https://t.test/p> |',
		].join( '\n' );
		expect( markdownToBlocks( md ) ).toContain(
			'<td>one<br><a href="https://t.test/p">https://t.test/p</a></td>'
		);
	} );

	it( 'does not leave raw table pipes in a paragraph', () => {
		const md = [ '| A | B |', '|---|---|', '| 1 | 2 |' ].join( '\n' );
		const out = markdownToBlocks( md );
		expect( out ).not.toContain( '<p>' );
		expect( out ).not.toContain( '|' );
	} );

	it( 'handles the full real digest sample cleanly', () => {
		const sample = [
			'## What mattered for the past sprint (Engineers – Newspack & Nodes)',
			'',
			'Below are the shipped changes that affect runtime, APIs, or user-facing behavior.',
			'',
			'---',
			'',
			'### 1. Content-gate rule engine',
			'- **Cascade taxonomy rules to child terms** – parent-category paywall rules now apply to all descendants.',
			'  <https://github.com/Automattic/newspack-workspace/pull/283>',
			'',
			'### 2. Insights dashboard',
			'- **Prompts (Tab 5) wired to BigQuery + caching** – live queries replace placeholder data.',
			'  <https://github.com/Automattic/newspack-workspace/pull/289>',
		].join( '\n' );

		const out = markdownToBlocks( sample );

		// Heading + paragraph + separator + two h3 headings + two lists.
		expect( out ).toContain(
			'<h2 class="wp-block-heading">What mattered for the past sprint'
		);
		expect( out ).toContain(
			'<!-- wp:separator -->\n' +
				'<hr class="wp-block-separator has-alpha-channel-opacity"/>\n' +
				'<!-- /wp:separator -->'
		);
		expect( out ).toContain(
			'<!-- wp:heading {"level":3} -->\n' +
				'<h3 class="wp-block-heading">1. Content-gate rule engine</h3>'
		);
		expect( out ).toContain( '<!-- wp:list -->' );
		expect( out ).toContain(
			'<a href="https://github.com/Automattic/newspack-workspace/pull/283">'
		);
		// Continuation URL folded into the bullet, not a stray paragraph.
		expect( out ).not.toContain( '<p><a href="https://github.com' );
		// No empty paragraph blocks.
		expect( out ).not.toContain( '<p></p>' );
	} );
} );

const HTML_ENTITIES = {
	'&': '&amp;',
	'<': '&lt;',
	'>': '&gt;',
	'"': '&quot;',
	"'": '&#039;',
};

/**
 * Escape the five HTML-significant characters so raw markdown text can't break
 * out of the block markup we build.
 *
 * @param {string} value Raw text.
 * @return {string} HTML-safe text.
 */
function escapeHtml( value ) {
	return String( value ).replace(
		/[&<>"']/g,
		( char ) => HTML_ENTITIES[ char ]
	);
}

/**
 * Convert one line's inline markdown to safe HTML: escape the raw text first,
 * THEN apply the inline tag transforms so the tags we emit stay real markup.
 * Supports **bold**, [text](url), and <https://…> autolinks.
 *
 * @param {string} text Raw line text (markdown markers intact).
 * @return {string} HTML-safe inline markup.
 */
function inline( text ) {
	let html = escapeHtml( text );
	// Autolink: <https://…> — the url is both href and label.
	html = html.replace(
		/&lt;(https?:\/\/[^\s&]+)&gt;/g,
		( _match, url ) => `<a href="${ url }">${ url }</a>`
	);
	// [text](url) — url already escaped, so &amp; in a query string is valid.
	html = html.replace(
		/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
		( _match, label, url ) => `<a href="${ url }">${ label }</a>`
	);
	// <br> line breaks (table cells use them) — emit a real break, not escaped text.
	html = html.replace( /&lt;br\s*\/?&gt;/gi, '<br>' );
	// **bold**.
	html = html.replace(
		/\*\*([^*]+)\*\*/g,
		( _match, inner ) => `<strong>${ inner }</strong>`
	);
	return html;
}

/**
 * Serialize a heading. WordPress omits the level attr for the default h2, and the
 * tag MUST match the level (`<h3>` for level 3) — a level/tag mismatch fails the
 * block's save-validation ("unexpected or invalid content") in the editor.
 *
 * @param {number} level Heading level (1–3).
 * @param {string} text  Raw heading text.
 * @return {string} A serialized heading block.
 */
function headingBlock( level, text ) {
	const attr = 2 === level ? '' : ` {"level":${ level }}`;
	return (
		`<!-- wp:heading${ attr } -->\n` +
		`<h${ level } class="wp-block-heading">${ inline(
			text
		) }</h${ level }>\n` +
		'<!-- /wp:heading -->'
	);
}

/**
 * Serialize a list, wrapping each item in its own list-item block (WP 6.x).
 *
 * @param {Array<string>} items Raw list-item texts.
 * @return {string} A serialized list block.
 */
function listBlock( items ) {
	const lis = items
		.map(
			( item ) =>
				`<!-- wp:list-item -->\n<li>${ inline(
					item
				) }</li>\n<!-- /wp:list-item -->`
		)
		.join( '' );
	return (
		'<!-- wp:list -->\n' +
		`<ul class="wp-block-list">${ lis }</ul>\n` +
		'<!-- /wp:list -->'
	);
}

/**
 * Serialize a paragraph from one or more text lines (joined with a space).
 *
 * @param {Array<string>} lines Raw paragraph lines.
 * @return {string} A serialized paragraph block.
 */
function paragraphBlock( lines ) {
	return (
		'<!-- wp:paragraph -->\n' +
		`<p>${ inline( lines.join( ' ' ) ) }</p>\n` +
		'<!-- /wp:paragraph -->'
	);
}

/**
 * Split a GFM table row into trimmed cell texts (leading/trailing pipes dropped).
 *
 * @param {string} row A `| a | b |` row.
 * @return {Array<string>} The cell texts.
 */
function tableCells( row ) {
	return row
		.replace( /^\|/, '' )
		.replace( /\|$/, '' )
		.split( '|' )
		.map( ( cell ) => cell.trim() );
}

/**
 * Serialize a GFM table as a core/table block (the markup WP stores), so a
 * pasted-equivalent table opens natively instead of as a pipe-laden paragraph.
 *
 * @param {Array<string>}        header Header cell texts.
 * @param {Array<Array<string>>} rows   Body rows of cell texts.
 * @return {string} A serialized table block.
 */
function tableBlock( header, rows ) {
	const th = header
		.map( ( cell ) => `<th>${ inline( cell ) }</th>` )
		.join( '' );
	const body = rows
		.map(
			( cells ) =>
				`<tr>${ cells
					.map( ( cell ) => `<td>${ inline( cell ) }</td>` )
					.join( '' ) }</tr>`
		)
		.join( '' );
	return (
		'<!-- wp:table -->\n' +
		'<figure class="wp-block-table"><table>' +
		`<thead><tr>${ th }</tr></thead>` +
		`<tbody>${ body }</tbody>` +
		'</table></figure>\n' +
		'<!-- /wp:table -->'
	);
}

const HEADING = /^(#{1,3})\s+(.*)$/;
const LIST_ITEM = /^[-*]\s+(.*)$/;
const SEPARATOR = /^-{3,}$/;
// A table row is pipe-delimited; the delimiter row under the header is only
// pipes/dashes/colons/space with at least one dash (distinct from a `---` rule,
// which has no pipe).
const TABLE_ROW = ( line ) =>
	line.length > 1 && line.startsWith( '|' ) && line.endsWith( '|' );
const TABLE_DELIM = ( line ) =>
	line.includes( '|' ) && line.includes( '-' ) && /^[\s|:-]+$/.test( line );

/**
 * Convert the digest's markdown subset into serialized Gutenberg block markup —
 * the block-comment-delimited HTML WordPress stores as post_content — so a
 * REST-created draft opens as native, editable blocks. Pure, dependency-free.
 *
 * Supported: # / ## / ### headings, `-`/`*` lists, `---` separators, GFM tables
 * (→ core/table), paragraphs, and inline **bold** / [text](url) / <autolink> /
 * <br>. An indented continuation line after a bullet folds into that bullet
 * rather than starting a new block.
 *
 * @param {string} [markdown] The markdown source.
 * @return {string} Serialized Gutenberg block markup ('' for empty input).
 */
export function markdownToBlocks( markdown = '' ) {
	const lines = String( markdown ).split( '\n' );
	const blocks = [];
	let para = [];
	let list = null;

	const flushPara = () => {
		if ( para.length ) {
			blocks.push( paragraphBlock( para ) );
			para = [];
		}
	};
	const flushList = () => {
		if ( list ) {
			blocks.push( listBlock( list ) );
			list = null;
		}
	};

	for ( let i = 0; i < lines.length; i++ ) {
		const raw = lines[ i ];
		const line = raw.trim();

		// A list-item continuation: an indented, non-special line under a bullet
		// folds into the last item instead of opening a paragraph.
		if (
			list &&
			'' !== line &&
			raw !== line &&
			! HEADING.test( line ) &&
			! LIST_ITEM.test( line ) &&
			! SEPARATOR.test( line )
		) {
			list[ list.length - 1 ] += ` ${ line }`;
			continue;
		}

		if ( '' === line ) {
			flushPara();
			flushList();
			continue;
		}

		// A GFM table: a pipe row immediately followed by a delimiter row. Consume
		// the header, the delimiter, and every following pipe row as one table.
		if (
			TABLE_ROW( line ) &&
			TABLE_DELIM( ( lines[ i + 1 ] ?? '' ).trim() )
		) {
			flushPara();
			flushList();
			const header = tableCells( line );
			const rows = [];
			let j = i + 2;
			while ( j < lines.length && TABLE_ROW( lines[ j ].trim() ) ) {
				rows.push( tableCells( lines[ j ].trim() ) );
				j++;
			}
			blocks.push( tableBlock( header, rows ) );
			i = j - 1;
			continue;
		}

		const heading = HEADING.exec( line );
		if ( heading ) {
			flushPara();
			flushList();
			blocks.push( headingBlock( heading[ 1 ].length, heading[ 2 ] ) );
			continue;
		}

		if ( SEPARATOR.test( line ) ) {
			flushPara();
			flushList();
			blocks.push(
				'<!-- wp:separator -->\n' +
					'<hr class="wp-block-separator has-alpha-channel-opacity"/>\n' +
					'<!-- /wp:separator -->'
			);
			continue;
		}

		const item = LIST_ITEM.exec( line );
		if ( item ) {
			flushPara();
			if ( ! list ) {
				list = [];
			}
			list.push( item[ 1 ] );
			continue;
		}

		flushList();
		para.push( line );
	}

	flushPara();
	flushList();

	return blocks.join( '\n\n' );
}

import { pasteHandler, serialize } from '@wordpress/blocks';

// Register core blocks exactly once per page load; pasteHandler needs the block
// registry populated, but re-registering warns ("Block ... is already
// registered") on every call. block-library is required lazily (only on the
// first real conversion) so merely importing this module — which the dashboard
// does at load — doesn't pull the whole block-library/block-editor tree.
let registered = false;
function ensureCoreBlocks() {
	if ( registered ) {
		return;
	}
	// eslint-disable-next-line global-require
	const { registerCoreBlocks } = require( '@wordpress/block-library' );
	registerCoreBlocks();
	registered = true;
}

/**
 * Convert digest markdown to serialized block markup using the block editor's
 * OWN paste engine — the same path the editor runs when you paste markdown into
 * it (empty HTML + plainText markdown, mode BLOCKS → markdown→blocks), which is
 * why it produces real core blocks (tables included) where a hand-rolled
 * converter falls short. Returns the block-delimited markup the draft-create
 * REST call stores as post_content.
 *
 * @param {string} [markdown] The digest markdown source.
 * @return {string} Serialized Gutenberg block markup ('' for empty input).
 */
export function markdownToBlockMarkup( markdown = '' ) {
	const plainText = String( markdown );
	if ( '' === plainText ) {
		return '';
	}
	ensureCoreBlocks();
	const blocks = pasteHandler( { HTML: '', plainText, mode: 'BLOCKS' } );
	return serialize( blocks );
}

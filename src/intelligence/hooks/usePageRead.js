/**
 * The reads the Intelligence page's views and blocks share, each read once
 * for the whole page and provided to everything beneath it, a view hidden
 * under a profile and the layout editor's preview among them: so the page
 * sends one read per verb and arguments, and every reader shows that one
 * live read, never an answer another read holds.
 */

import { createContext, useContext } from '@wordpress/element';
import { useIntelRead } from './useIntelCommand';

/**
 * Each shared read by name: its verb and its argument tokens.
 *
 * @type {Object<string, {command: string, args: string[]}>}
 */
const READS = {
	lenses: { command: 'lenses', args: [] },
	watches: { command: 'watches', args: [] },
	folders: { command: 'collections', args: [] },
	review: { command: 'review', args: [ 'links' ] },
	collection: { command: 'health', args: [ 'collection' ] },
	sources: { command: 'health', args: [ 'sources' ] },
	backlog: { command: 'health', args: [ 'backlog' ] },
	extraction: { command: 'health', args: [ 'extraction' ] },
};

/**
 * @typedef {ReturnType<typeof useIntelRead>} Read
 * @typedef {Object<string, Read>}           SharedReads
 */

/** @type {import('react').Context<?SharedReads>} */
export const PageReads = createContext( null );

/**
 * One shared read, under the page's scope for it.
 *
 * @param {string}  name    The read.
 * @param {boolean} [asked] Whether the page asks it now.
 * @return {Read} The read.
 */
function useShared( name, asked = true ) {
	const { command, args } = READS[ name ];
	return useIntelRead( `page:${ name }`, command, asked ? args : null );
}

/**
 * The page's shared reads, for `PageReads` to provide. The review queue is
 * read only while the page asks it, for a curator's count or the open
 * Review view.
 *
 * @param {Object}  options
 * @param {boolean} options.review Whether the page reads the review queue.
 * @return {SharedReads} The reads.
 */
export function usePageReads( { review } ) {
	return {
		lenses: useShared( 'lenses' ),
		watches: useShared( 'watches' ),
		folders: useShared( 'folders' ),
		review: useShared( 'review', review ),
		collection: useShared( 'collection' ),
		sources: useShared( 'sources' ),
		backlog: useShared( 'backlog' ),
		extraction: useShared( 'extraction' ),
	};
}

/**
 * One of the page's shared reads.
 *
 * @param {string} name The read, a key of the page's shared reads.
 * @return {Read} The read the page provides.
 * @throws {Error} Outside a page, which provides its reads.
 */
export function usePageRead( name ) {
	const reads = useContext( PageReads );
	if ( ! reads ) {
		throw new Error(
			`usePageRead( ${ name } ): no page provides its reads`
		);
	}
	return reads[ name ];
}

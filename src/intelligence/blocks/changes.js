/**
 * The Briefing's one `changes` read, which its `since` and `week` blocks
 * share: the Briefing reads it once for its whole layout and provides it, and
 * a block reads its own only when the page provides it no read, as the
 * layout editor's preview does.
 */

import { createContext, useContext } from '@wordpress/element';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelPage } from '../layout/PageContext';

/** @type {import('react').Context<?ReturnType<typeof useIntelRead>>} */
export const ChangesRead = createContext( null );

/**
 * The `changes` read's tokens: since the last look, filed under the lenses.
 *
 * @param {?number}  since  The last look, UTC seconds, or null for none.
 * @param {string[]} lenses The category lenses, in order.
 * @return {string[]} The tokens.
 */
export const changesArgs = ( since, lenses ) => [
	'changes',
	...( null === since ? [] : [ `--since=${ since }` ] ),
	...( 0 === lenses.length ? [] : [ `--lenses=${ lenses.join( ',' ) }` ] ),
];

/**
 * The read provided, else this block's own under `scope`.
 *
 * @param {string}   scope  Names the block's own read.
 * @param {string[]} lenses The lenses it files the week under.
 * @return {ReturnType<typeof useIntelRead>} The read.
 */
export function useChanges( scope, lenses ) {
	const shared = useContext( ChangesRead );
	const { since } = useIntelPage();
	const own = useIntelRead(
		scope,
		'briefing',
		shared ? null : changesArgs( since, lenses )
	);
	return shared ?? own;
}

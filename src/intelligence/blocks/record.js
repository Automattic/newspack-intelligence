/**
 * A profile's one `entity` read, which its header and its blocks share: the
 * profile reads it once and provides it, as the layout editor does for the
 * entity it previews. A block's read about the page's entity is owed while
 * the page names none.
 */

import { createContext, useContext } from '@wordpress/element';

/** @type {import('react').Context<?ReturnType<typeof import('../hooks/useIntelCommand').useIntelRead>>} */
export const EntityRecord = createContext( null );

/**
 * A read about the page's entity, owed while the page names none, as the
 * editor's preview does before one is picked, so it asks nothing and shows
 * its skeleton.
 *
 * @param {?string}                                                            key  The page's entity.
 * @param {ReturnType<typeof import('../hooks/useIntelCommand').useIntelRead>} read The read, asked only for a key.
 * @return {ReturnType<typeof import('../hooks/useIntelCommand').useIntelRead>} The read.
 */
export const aboutEntity = ( key, read ) =>
	key ? read : { ...read, loading: true };

/**
 * The record the profile provides.
 *
 * @return {ReturnType<typeof import('../hooks/useIntelCommand').useIntelRead>} The read.
 * @throws {Error} Outside a profile, which provides its record.
 */
export function useEntityRecord() {
	const record = useContext( EntityRecord );
	if ( ! record ) {
		throw new Error( 'useEntityRecord(): no profile provides its record' );
	}
	return record;
}

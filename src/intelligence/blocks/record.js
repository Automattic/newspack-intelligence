/**
 * A profile's one `entity` read, which its header and its blocks share: the
 * profile reads it once and provides it, and a block reads its own only when
 * the page provides none, as the layout editor's preview does. A block's read
 * about the page's entity is owed while the page names none.
 */

import { createContext, useContext, useId } from '@wordpress/element';
import { useIntelRead } from '../hooks/useIntelCommand';
import { IntelPageContext } from '../layout/PageContext';

/** @type {import('react').Context<?ReturnType<typeof useIntelRead>>} */
export const EntityRecord = createContext( null );

/**
 * A read about the page's entity, owed while the page names none, as the
 * editor's preview does before one is picked, so it asks nothing and shows
 * its skeleton.
 *
 * @param {?string}                         key  The page's entity.
 * @param {ReturnType<typeof useIntelRead>} read The read, asked only for a key.
 * @return {ReturnType<typeof useIntelRead>} The read.
 */
export const aboutEntity = ( key, read ) =>
	key ? read : { ...read, loading: true };

/**
 * The record provided, else this block's own read of the page's entity,
 * under a scope of its own, since several blocks on a page read it.
 *
 * @return {ReturnType<typeof useIntelRead>} The read.
 */
export function useEntityRecord() {
	const shared = useContext( EntityRecord );
	const { entity_key: key } = useContext( IntelPageContext );
	const own = useIntelRead(
		`block:entity:${ useId() }`,
		'entity',
		shared || ! key ? null : [ key ]
	);
	return shared ?? aboutEntity( key, own );
}

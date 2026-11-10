/**
 * The saved team view a block reads through, as `lenses` lists it, wherever
 * the block stands: on its own page, another view's, the Briefing, a profile
 * or the layout editor's preview. Every block's read is one question, which
 * `useIntelRead` asks once for the page.
 */

import { useId } from '@wordpress/element';
import { useIntelRead } from '../hooks/useIntelCommand';

/**
 * The saved view `key` names: undefined while `lenses` is owed, null for a
 * key it does not list or a read it refused, so the read through it answers
 * the server's own refusal.
 *
 * @param {?string} key The view's key, '' or null for none.
 * @return {?Object|undefined} The view.
 */
export function useSavedLens( key ) {
	const read = useIntelRead(
		`block:lenses:${ useId() }`,
		'lenses',
		key ? [] : null
	);
	if ( ! key ) {
		return null;
	}
	if ( read.error ) {
		return null;
	}
	const listed = read.answer?.lenses;
	return listed
		? listed.find( ( lens ) => key === lens.lens_key ) ?? null
		: undefined;
}

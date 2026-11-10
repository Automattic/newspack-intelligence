/**
 * The **Edit layout** entry a composed page's own ⋯ menu holds for a
 * curator: it reloads the page naming the edit, because only such a load,
 * for a `tune` caller, brings the block editor.
 */

import { __ } from '@wordpress/i18n';
import { editSearch } from './editMode';

/**
 * The menu entry that opens a page's layout editor, for a view's own ⋯ menu.
 *
 * @param {import('./editMode').EditTarget} target The page and its subject.
 * @return {{title: string, onClick: () => void}} The entry.
 */
export const editLayoutControl = ( target ) => ( {
	title: __( 'Edit layout', 'newspack-intelligence' ),
	onClick: () =>
		window.location.assign( editSearch( window.location.search, target ) ),
} );

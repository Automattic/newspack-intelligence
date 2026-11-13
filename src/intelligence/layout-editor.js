/**
 * The layout editor's bundle, which `Composition::enqueue_editor()` loads
 * for a `tune` caller alone: the localized context's blocks and its editor.
 */

import { BLOCK_VIEWS } from './blocks';
import { mountLayoutEditor } from './layout/LayoutEditor';

mountLayoutEditor(
	/** @type {{NewspackNodesComposition: import('@newspack-nodes/shared/composition/page/mountComposedEditor').LocalizedComposition}} */ (
		/** @type {unknown} */ ( window )
	).NewspackNodesComposition,
	BLOCK_VIEWS
);

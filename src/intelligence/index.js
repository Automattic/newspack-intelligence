import { createRoot } from '@wordpress/element';
import IntelligencePage from './IntelligencePage';

const MOUNT_ID = 'newspack-intelligence-app';

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( MOUNT_ID );
	if ( ! el ) {
		return;
	}
	// The payload `enqueue_intelligence_assets()` localizes.
	const { canTune, vocabulary } =
		/** @type {{NewspackNodesData: {canTune: boolean, vocabulary: Object}}} */ (
			/** @type {unknown} */ ( window )
		).NewspackNodesData;
	createRoot( el ).render(
		<IntelligencePage
			canTune={ true === canTune }
			vocabulary={ vocabulary }
		/>
	);
} );

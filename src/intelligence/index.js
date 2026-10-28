import { createRoot } from '@wordpress/element';
import IntelligencePage from './IntelligencePage';

const MOUNT_ID = 'newspack-intelligence-app';

document.addEventListener( 'DOMContentLoaded', () => {
	const el = document.getElementById( MOUNT_ID );
	if ( ! el ) {
		return;
	}
	// wp_localize_script() sends the flag as a string: '1' or ''.
	const { canTune, vocabulary } =
		/** @type {{NewspackNodesData: {canTune: string, vocabulary: Object}}} */ (
			/** @type {unknown} */ ( window )
		).NewspackNodesData;
	createRoot( el ).render(
		<IntelligencePage
			canTune={ '1' === canTune }
			vocabulary={ vocabulary }
		/>
	);
} );

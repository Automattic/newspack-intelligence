import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Snackbar } from '@wordpress/components';

/**
 * One message at the foot of the page, until it is dismissed: the most
 * recent write still pending, offering its Undo, until it is sent, undone
 * or dismissed; or, with no `onUndo`, a write that settled, which the
 * snackbar announces itself and lets go of on its own. A pending write's
 * message is not spoken here: `useDeferredWrite` announced it as it was
 * deferred.
 *
 * @param {Object}                                  props
 * @param {?{id: (number|string), message: string}} props.latest   The message, null for none.
 * @param {() => void}                              [props.onUndo] Undoes the pending write.
 * @return {?import('react').ReactElement} The snackbar.
 */
export default function UndoSnackbar( { latest, onUndo } ) {
	const [ dismissed, setDismissed ] = useState( null );
	if ( ! latest || latest.id === dismissed ) {
		return null;
	}
	return (
		<div className="newspack-intelligence__undo">
			<Snackbar
				explicitDismiss={ Boolean( onUndo ) }
				spokenMessage={ onUndo ? '' : latest.message }
				actions={
					onUndo
						? [
								{
									label: __(
										'Undo',
										'newspack-intelligence'
									),
									onClick: onUndo,
								},
						  ]
						: []
				}
				onRemove={ () => setDismissed( latest.id ) }
			>
				{ latest.message }
			</Snackbar>
		</div>
	);
}

/**
 * A question whose yes loses layout work, its safe answer focused, as the
 * layout editor asks before Cancel and Restore default.
 */

import { useEffect, useRef } from '@wordpress/element';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';

/**
 * @param {Object}     props
 * @param {string}     props.title  The question.
 * @param {string}     [props.body] What the yes costs.
 * @param {string}     props.yes    The yes button.
 * @param {string}     props.no     The safe button.
 * @param {() => void} props.onYes  Runs on the yes.
 * @param {() => void} props.onNo   Runs on the safe answer, ESC or a click outside.
 * @return {import('react').ReactElement} The dialog.
 */
export default function Confirm( { title, body, yes, no, onYes, onNo } ) {
	const noRef = useRef( null );
	useEffect( () => noRef.current?.focus(), [] );
	return (
		<Modal onClose={ onNo }>
			<ModalHeader title={ title } onClose={ onNo } />
			{ body && <p>{ body }</p> }
			<div className="newspack-nodes-modal__actions">
				<button
					type="button"
					ref={ noRef }
					className="button"
					onClick={ onNo }
				>
					{ no }
				</button>
				<button
					type="button"
					className={ primaryButtonClass( false, 'is-danger' ) }
					onClick={ onYes }
				>
					{ yes }
				</button>
			</div>
		</Modal>
	);
}

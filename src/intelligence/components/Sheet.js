import { useEffect, useRef } from '@wordpress/element';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';

/**
 * A panel over the page: a right-hand drawer, or a bottom sheet with
 * rounded top corners when the page is narrow. It is the shared modal shell,
 * so Escape and a click outside close it and Tab stays inside; its heading
 * names it and takes focus as it opens, and closing hands focus back to what
 * opened it.
 *
 * @param {Object}                    props
 * @param {string}                    props.title      The heading, and its name.
 * @param {() => void}                props.onClose    Closes it.
 * @param {string}                    props.closeLabel The close button's name.
 * @param {import('react').ReactNode} props.children   What it holds.
 * @return {import('react').ReactElement} The sheet.
 */
export default function Sheet( { title, onClose, closeLabel, children } ) {
	const narrow = useNarrow();
	const bodyRef = useRef( null );
	useEffect( () => {
		const heading = /** @type {?HTMLElement} */ (
			bodyRef.current
				.closest( '[role="dialog"]' )
				.querySelector( '.newspack-nodes-modal__title' )
		);
		heading.tabIndex = -1;
		heading.focus();
	}, [] );
	return (
		<Modal
			onClose={ onClose }
			className={ `newspack-intelligence__sheet ${
				narrow ? 'is-bottom' : 'is-side'
			}` }
			backdropClassName="newspack-intelligence__sheet-backdrop"
		>
			<ModalHeader
				title={ title }
				onClose={ onClose }
				closeLabel={ closeLabel }
			/>
			<div ref={ bodyRef } className="newspack-intelligence__sheet-body">
				{ children }
			</div>
		</Modal>
	);
}

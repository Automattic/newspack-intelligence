import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';

/**
 * A panel over the page: a right-hand drawer, or a bottom sheet with
 * rounded top corners when the page is narrow. It is the shared modal shell,
 * so Escape and a click outside close it and Tab stays inside; its heading
 * names it. Opening it moves focus to its first field Tab can reach, else to
 * its heading, the title the shell names it by; closing hands focus back to
 * what opened it. A control inside closes it through `handleRef`, playing its
 * exit.
 *
 * @param {Object}                                                                    props
 * @param {string}                                                                    props.title       The heading, and its name.
 * @param {() => void}                                                                props.onClose     Closes it.
 * @param {string}                                                                    props.closeLabel  The close button's name.
 * @param {{current: ?import('@newspack-nodes/shared/hooks/useDialog').DialogHandle}} [props.handleRef] Takes the sheet's animated close.
 * @param {import('react').ReactNode}                                                 props.children    What it holds.
 * @return {import('react').ReactElement} The sheet.
 */
export default function Sheet( {
	title,
	onClose,
	closeLabel,
	handleRef,
	children,
} ) {
	const narrow = useNarrow();
	return (
		<Modal
			onClose={ onClose }
			handleRef={ handleRef }
			initialFocus="field"
			className={ `newspack-intelligence__sheet ${
				narrow ? 'is-bottom' : 'is-side'
			}` }
			backdropClassName="newspack-intelligence__sheet-backdrop"
		>
			<ModalHeader title={ title } closeLabel={ closeLabel } />
			<div className="newspack-intelligence__sheet-body">
				{ children }
			</div>
		</Modal>
	);
}

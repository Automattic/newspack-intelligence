import { useContext, useEffect, useRef } from '@wordpress/element';
import Modal, {
	ModalHeader,
	ModalTitleId,
} from '@newspack-nodes/shared/components/Modal';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';

/** The fields a sheet's focus lands on first. */
const FIELDS = 'input, select, textarea';

/**
 * A panel over the page: a right-hand drawer, or a bottom sheet with
 * rounded top corners when the page is narrow. It is the shared modal shell,
 * so Escape and a click outside close it and Tab stays inside; its heading
 * names it. Opening it moves focus to its first field, else to its heading,
 * the title the shell names it by; closing hands focus back to what opened
 * it.
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
			<SheetBody>{ children }</SheetBody>
		</Modal>
	);
}

/**
 * A sheet's body, focusing its first field as it opens, else the title the
 * shell names the sheet by.
 *
 * @param {Object}                    props
 * @param {import('react').ReactNode} props.children What it holds.
 * @return {import('react').ReactElement} The body.
 */
function SheetBody( { children } ) {
	const titleId = useContext( ModalTitleId );
	const body = useRef( null );
	useEffect( () => {
		const field = /** @type {?HTMLElement} */ (
			body.current.querySelector( FIELDS )
		);
		if ( field ) {
			field.focus();
			return;
		}
		const heading = body.current.ownerDocument.getElementById( titleId );
		heading.tabIndex = -1;
		heading.focus();
	}, [ titleId ] );
	return (
		<div ref={ body } className="newspack-intelligence__sheet-body">
			{ children }
		</div>
	);
}

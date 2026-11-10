/* global Element */
import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Popover, ToggleControl } from '@wordpress/components';
import useNarrow from '@newspack-nodes/shared/hooks/useNarrow';
import { usePersistedFlag } from '@newspack-nodes/shared/hooks/usePersistedState';
import { isTypingTarget } from '@newspack-nodes/shared/utils/isTypingTarget';

/** Where this browser keeps whether the single-key shortcuts are off. */
const KEYS_OFF_KEY = 'newspack-intelligence:single-keys-off';

/** The attribute marking a control whose click takes its row out. */
const LEAVES_ATTRIBUTE = 'data-leaves-row';

/** The props marking a control whose click takes its row out. */
export const LEAVES = { [ LEAVES_ATTRIBUTE ]: '' };

/**
 * Whether an element sits in an open dialog, where the keys stay out.
 *
 * @param {?EventTarget} element The element.
 * @return {boolean} Whether it does.
 */
const inDialog = ( element ) =>
	element instanceof Element && null !== element.closest( '[role="dialog"]' );

/**
 * A list, or a function drawing one described by the given id.
 *
 * @typedef {import('react').ReactNode | ((describedBy: ?string) => import('react').ReactNode)} DrawnList
 */

/**
 * A list that takes single keys: `j` and `k` focus the next and the
 * previous of its rows, else its first; a letter presses the focused row's
 * control whose `aria-keyshortcuts` names it; `z` undoes the latest pending
 * write; and `?` shows the legend in a popover, which Escape hides. The keys
 * work while focus is inside the list, or anywhere on the page given `page`
 * while the list sits in a shown view. A key typed in a field or in a dialog,
 * or with a modifier, is left alone.
 * Above the list stand the legend and the switch turning the keys off, which
 * this browser remembers; on a narrow screen, that the keys need a keyboard.
 * A list whose legend is null takes no key and shows neither.
 *
 * A click on a control carrying `LEAVES` takes its row out, keys or none, so
 * once the list renders without it focus moves to the row in its place, else
 * the last, else the list's heading.
 *
 * @param {Object}             props
 * @param {string}             props.rows      Selects each row's focusable element inside the list.
 * @param {() => void}         props.onUndo    Undoes the latest pending write.
 * @param {?string}            [props.legend]  The keys in words, or null for a list taking none.
 * @param {boolean}            [props.page]    Whether the keys work from anywhere on the page.
 * @param {() => ?HTMLElement} [props.heading] The list's heading, which takes focus once the last row leaves.
 * @param {DrawnList}          props.children  The list, or a function drawing it described by the legend's id.
 * @return {import('react').ReactElement} The legend and the list.
 */
export default function ListKeys( {
	rows,
	onUndo,
	legend = __( 'Keys: J and K to move, Z undo.', 'newspack-intelligence' ),
	page = false,
	heading,
	children,
} ) {
	const narrow = useNarrow();
	const [ off, , toggle ] = usePersistedFlag( KEYS_OFF_KEY, false );
	const [ shown, setShown ] = useState( false );
	const id = useId();
	const list = useRef( null );
	const judged = useRef( null );
	const keys = null !== legend && ! off && ! narrow;

	useLayoutEffect( () => {
		if ( null === judged.current ) {
			return;
		}
		const at = judged.current;
		judged.current = null;
		const left = list.current.querySelectorAll( rows );
		( left[ at ] ?? left[ left.length - 1 ] ?? heading?.() )?.focus();
	} );

	const onKeyDown = ( event ) => {
		const { key, target } = event;
		if (
			null !== list.current.closest( '[hidden]' ) ||
			event.altKey ||
			event.ctrlKey ||
			event.metaKey ||
			isTypingTarget( target ) ||
			inDialog( target ) ||
			inDialog( target.ownerDocument?.activeElement )
		) {
			return;
		}
		if ( '?' === key || ( 'Escape' === key && shown ) ) {
			event.preventDefault();
			setShown( '?' === key );
			return;
		}
		if ( 'z' === key ) {
			event.preventDefault();
			onUndo();
			return;
		}
		const found = /** @type {HTMLElement[]} */ ( [
			...list.current.querySelectorAll( rows ),
		] );
		const at = found.findIndex( ( row ) => row.contains( target ) );
		const next =
			( 'j' === key || 'k' === key ) &&
			found[
				Math.min(
					Math.max( at + ( 'j' === key ? 1 : -1 ), 0 ),
					found.length - 1
				)
			];
		const control =
			! next &&
			/^[a-z]$/.test( key ) &&
			/** @type {?HTMLElement} */ (
				found[ at ]?.querySelector( `[aria-keyshortcuts="${ key }"]` )
			);
		if ( next ) {
			event.preventDefault();
			next.focus();
		} else if ( control ) {
			event.preventDefault();
			control.click();
		}
	};
	const handler = useRef( onKeyDown );
	handler.current = onKeyDown;

	useEffect( () => {
		if ( ! keys || ! page ) {
			return undefined;
		}
		const listener = ( event ) => handler.current( event );
		document.addEventListener( 'keydown', listener );
		return () => document.removeEventListener( 'keydown', listener );
	}, [ keys, page ] );

	const onClickCapture = ( { target } ) => {
		if (
			target instanceof Element &&
			target.closest( `[${ LEAVES_ATTRIBUTE }]` )
		) {
			judged.current = [
				...list.current.querySelectorAll( rows ),
			].findIndex( ( row ) => row.contains( target ) );
		}
	};

	const describedBy = keys ? id : undefined;
	const drawn = 'function' === typeof children;
	let chrome = null;
	if ( null !== legend ) {
		chrome = narrow ? (
			<p className="newspack-nodes-status is-muted newspack-intelligence__keys">
				{ __( 'Shortcuts need a keyboard.', 'newspack-intelligence' ) }
			</p>
		) : (
			<div className="newspack-intelligence__keys">
				{ keys && (
					<p id={ id } className="newspack-nodes-status is-muted">
						{ legend }
					</p>
				) }
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Turn off single-key shortcuts',
						'newspack-intelligence'
					) }
					checked={ off }
					onChange={ toggle }
				/>
			</div>
		);
	}

	return (
		<>
			{ chrome }
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions, jsx-a11y/click-events-have-key-events -- keys and clicks reach it from the rows inside. */ }
			<div
				ref={ list }
				aria-describedby={ drawn ? undefined : describedBy }
				onKeyDown={ keys && ! page ? onKeyDown : undefined }
				onClickCapture={ onClickCapture }
			>
				{ drawn ? children( describedBy ) : children }
			</div>
			{ keys && shown && (
				<Popover
					anchor={ list.current }
					placement="top-start"
					focusOnMount={ false }
					onClose={ () => setShown( false ) }
				>
					<p className="newspack-intelligence__legend">{ legend }</p>
				</Popover>
			) }
		</>
	);
}

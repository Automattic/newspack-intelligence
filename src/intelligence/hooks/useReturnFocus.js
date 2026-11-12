import { useEffect, useRef } from '@wordpress/element';

/**
 * Once `open` turns false with nothing focused, as when the button that
 * opened a dialog left the page while it stood, focus `stays`, a control
 * that outlives it. Focus the dialog handed back to its opener stays put.
 *
 * @param {boolean}                 open  Whether the dialog stands.
 * @param {{current: ?HTMLElement}} stays The control that stays.
 * @return {void}
 */
export function useReturnFocus( open, stays ) {
	const was = useRef( open );
	useEffect( () => {
		const closed = was.current && ! open;
		was.current = open;
		const doc = stays.current?.ownerDocument;
		if ( closed && doc && doc.body === doc.activeElement ) {
			stays.current.focus();
		}
	}, [ open, stays ] );
}

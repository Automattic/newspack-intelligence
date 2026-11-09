import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { Core, raise, reservedNames } from '@newspack-nodes/runtime';

/**
 * How long a deferred write waits for an Undo, in milliseconds.
 *
 * @testonly Tests time the window by it; the hook reads it itself.
 */
export const UNDO_MS = 5000;

/**
 * Writes held for the undo window, each its own timer. `defer()` hides its
 * key at once, announces its message politely and sends after `UNDO_MS`;
 * `undo()` cancels the most recent write still pending and answers its key,
 * or null with none pending; `flush()` sends every pending write now, each
 * once, and raises what the sends and the tick threw only after all have run.
 * The hook flushes itself on `pagehide` and on unmount, which is a tab
 * switch, so a reader who leaves loses no judgment.
 *
 * A flush runs the Router's tick in the same task, so each send it makes
 * leaves before the graph it rides is torn down: a send only parks its ask,
 * and the tick `pollNow()` asks for comes a microtask later, after an
 * unmount's teardown has removed the Fetcher. Call this hook before the
 * writes its sends run, so its unmount flush runs while their nodes stand.
 *
 * @return {{hidden: Set<string>, latest: ?{id: number, key: string, message: string}, defer: (key: string, send: () => void, message: string) => void, undo: () => ?string, flush: () => void}}
 *   The keys pending; the write an undo takes, `id` telling apart two
 *   writes of one key; and the three controls.
 */
export function useDeferredWrite() {
	const pending = useRef( [] );
	const sequence = useRef( 0 );
	const [ shown, setShown ] = useState( [] );

	const publish = useCallback( ( writes ) => {
		pending.current = writes;
		setShown( writes );
	}, [] );

	const defer = useCallback(
		( key, send, message ) => {
			sequence.current += 1;
			const write = { id: sequence.current, key, message };
			write.timer = setTimeout( () => {
				publish( pending.current.filter( ( one ) => one !== write ) );
				send();
			}, UNDO_MS );
			write.send = send;
			publish( [ ...pending.current, write ] );
			speak( message, 'polite' );
		},
		[ publish ]
	);

	const undo = useCallback( () => {
		const write = pending.current.at( -1 );
		if ( ! write ) {
			return null;
		}
		clearTimeout( write.timer );
		publish( pending.current.slice( 0, -1 ) );
		return write.key;
	}, [ publish ] );

	const flush = useCallback( () => {
		const writes = pending.current;
		if ( 0 === writes.length ) {
			return;
		}
		publish( [] );
		const failures = [];
		for ( const write of writes ) {
			clearTimeout( write.timer );
			try {
				write.send();
			} catch ( error ) {
				failures.push( error );
			}
		}
		try {
			Core.node( reservedNames.ROUTER )?.fireCb();
		} catch ( error ) {
			failures.push( error );
		}
		raise( failures );
	}, [ publish ] );

	useEffect( () => {
		window.addEventListener( 'pagehide', flush );
		return () => {
			window.removeEventListener( 'pagehide', flush );
			flush();
		};
	}, [ flush ] );

	const last = shown.at( -1 );
	return {
		hidden: new Set( shown.map( ( write ) => write.key ) ),
		latest: last
			? { id: last.id, key: last.key, message: last.message }
			: null,
		defer,
		undo,
		flush,
	};
}

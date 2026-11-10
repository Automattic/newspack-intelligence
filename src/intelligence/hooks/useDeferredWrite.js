import {
	useCallback,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { speak } from '@wordpress/a11y';
import { raise, sendQueuedWrites } from '@newspack-nodes/runtime';

/**
 * How long a deferred write waits for an Undo, in milliseconds.
 *
 * @testonly Tests time the window by it; the hook reads it itself.
 */
export const UNDO_MS = 5000;

/**
 * Sends each of a held write's sends, every one though another throws.
 *
 * @param {{sends: Array<() => void>}} write The write.
 * @return {Error[]} What the sends threw.
 */
function sendEach( write ) {
	const failures = [];
	for ( const send of write.sends ) {
		try {
			send();
		} catch ( error ) {
			failures.push( error );
		}
	}
	return failures;
}

/**
 * Writes held for the undo window, each its own timer. `defer()` hides its
 * key at once, announces its message politely and sends after `UNDO_MS`;
 * `deferGroup()` does the same for several rows' writes under one message,
 * one timer and one undo; `undo()` cancels the most recent write still
 * pending and answers its key, a group's first, or null with none pending;
 * `flush()` sends every pending write now, each once, and raises what the
 * sends threw only after all have run. The hook
 * flushes itself on `pagehide` and on unmount, which is a tab switch, so a
 * reader who leaves loses no judgment.
 *
 * A send only queues its command on the write's Fetcher. On unmount the
 * Fetcher's own removal sends it, and the flush runs in a layout cleanup,
 * which React runs before any graph teardown, so the hooks may be called in
 * any order. On `pagehide` nothing is removed and a hidden tab's Timer may be
 * stopped, so after the flush `sendQueuedWrites()` posts every queued write
 * in the same task, with `keepalive`.
 *
 * @return {{hidden: Set<string>, latest: ?{id: number, key: string, message: string}, defer: (key: string, send: () => void, message: string) => void, deferGroup: (writes: Array<{key: string, send: () => void}>, message: string) => void, undo: () => ?string, flush: () => void}}
 *   The keys pending; the write an undo takes, `id` telling apart two
 *   writes of one key; and the four controls.
 */
export function useDeferredWrite() {
	const pending = useRef( [] );
	const sequence = useRef( 0 );
	const [ shown, setShown ] = useState( [] );

	const publish = useCallback( ( writes ) => {
		pending.current = writes;
		setShown( writes );
	}, [] );

	const deferGroup = useCallback(
		( writes, message ) => {
			sequence.current += 1;
			const write = {
				id: sequence.current,
				keys: writes.map( ( one ) => one.key ),
				sends: writes.map( ( one ) => one.send ),
				message,
			};
			write.timer = setTimeout( () => {
				publish( pending.current.filter( ( one ) => one !== write ) );
				raise( sendEach( write ) );
			}, UNDO_MS );
			publish( [ ...pending.current, write ] );
			speak( message, 'polite' );
		},
		[ publish ]
	);

	const defer = useCallback(
		( key, send, message ) => deferGroup( [ { key, send } ], message ),
		[ deferGroup ]
	);

	const undo = useCallback( () => {
		const write = pending.current.at( -1 );
		if ( ! write ) {
			return null;
		}
		clearTimeout( write.timer );
		publish( pending.current.slice( 0, -1 ) );
		return write.keys[ 0 ];
	}, [ publish ] );

	const flush = useCallback( () => {
		const writes = pending.current;
		if ( 0 === writes.length ) {
			return;
		}
		publish( [] );
		raise(
			writes.flatMap( ( write ) => {
				clearTimeout( write.timer );
				return sendEach( write );
			} )
		);
	}, [ publish ] );

	useLayoutEffect( () => {
		const leave = () => {
			const failures = [];
			try {
				flush();
			} catch ( error ) {
				failures.push( error );
			}
			try {
				sendQueuedWrites();
			} catch ( error ) {
				failures.push( error );
			}
			raise( failures );
		};
		window.addEventListener( 'pagehide', leave );
		return () => {
			window.removeEventListener( 'pagehide', leave );
			flush();
		};
	}, [ flush ] );

	const last = shown.at( -1 );
	return {
		hidden: new Set( shown.flatMap( ( write ) => write.keys ) ),
		latest: last
			? { id: last.id, key: last.keys[ 0 ], message: last.message }
			: null,
		defer,
		deferGroup,
		undo,
		flush,
	};
}

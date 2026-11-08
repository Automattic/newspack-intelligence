/**
 * Ending a client: its `client` role closed with `end_role`, and only once
 * that lands a `former_client` role opened with `assert_role`, because two
 * role writes sent together race and one of them is lost. The entity page's
 * churn prompt and the health strip's dead client feeds both end a client
 * through it, each hiding the client from the click through `useHeld` until
 * a read no longer lists it, since the projector records the end only on
 * its next flush.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useIntelWrite } from './useIntelCommand';
import { useHeld } from './useHeld';

/** The role a client holds while it is one. */
const CLIENT = 'client';

/** The role a client that left is given once its client role ends. */
const FORMER_CLIENT = 'former_client';

/**
 * A listed client's key.
 *
 * @param {{entity_key: string}} row A row naming a client.
 * @return {string} Its key.
 */
const clientKey = ( row ) => row.entity_key;

/**
 * End one client at a time. `run( client )` sends the end and holds the
 * client's key, so `read` leaves out every row of `rows` naming it until a
 * read no longer lists it, and does nothing while a pair is owed its
 * replies. A refused end releases the hold and answers as `refused`; a
 * refused assert keeps it, because the end landed, and answers as
 * `unrecorded`, which stands until the next `run()` so it outlives the
 * client's rows. `asked` names the client the last pair ends, and `owed` the
 * same client while that pair is owed its replies. `refresh` runs once the
 * pair is answered, or once either write is refused, never between the two.
 *
 * @param {string}                                              scope   Names the pair's nodes, so the two writes never
 *                                                                      share a Fetcher with another write of their verb.
 * @param {{answer: ?Object, error: ?string, loading: boolean}} read    The read listing the clients.
 * @param {string}                                              rows    Its answer's list of rows, each naming a client by `entity_key`.
 * @param {() => void}                                          refresh Re-reads what ending the client changed.
 * @return {{read: Object, run: (client: {entity_key: string, name: string}) => void, asked: ?string, owed: ?string, refused: ?string, unrecorded: ?{client: {entity_key: string, name: string}, refusal: string}}}
 *   The read with every held client left out, `run()`, the client the
 *   last pair ends, that client while its pair is owed or null, the last
 *   end's refusal, and the client whose end landed and whose assert was
 *   refused, with that refusal.
 */
export function useEndClient( scope, read, rows, refresh ) {
	const held = useHeld( read, rows, clientKey );
	const [ pair, setPair ] = useState( null );
	const [ owed, setOwed ] = useState( false );
	const [ unrecorded, setUnrecorded ] = useState( null );
	const settle = () => {
		setOwed( false );
		refresh();
	};
	const assert = useIntelWrite( 'assert_role', {
		scope: `${ scope }:assert_role`,
		refresh: settle,
		onRefused: () => {
			setUnrecorded( pair.client );
			settle();
		},
	} );
	const end = useIntelWrite( 'end_role', {
		scope: `${ scope }:end_role`,
		refresh: () =>
			assert.run( {
				key: pair.client.entity_key,
				role: FORMER_CLIENT,
			} ),
		onRefused: () => {
			held.refused( pair.tokens );
			setOwed( false );
		},
	} );
	return {
		read: held.read,
		run: ( client ) => {
			if ( ! owed ) {
				setOwed( true );
				setUnrecorded( null );
				held.hold( client.entity_key, null, () => {
					const tokens = end.run( {
						key: client.entity_key,
						role: CLIENT,
					} );
					setPair( { client, tokens } );
					return tokens;
				} );
			}
		},
		asked: pair?.client.entity_key ?? null,
		owed: owed ? pair?.client.entity_key ?? null : null,
		refused: end.refusal,
		unrecorded: unrecorded && {
			client: unrecorded,
			refusal: assert.refusal,
		},
	};
}

/**
 * What a client whose end landed and whose assert was refused reads as: its
 * name and key, that `former_client` was not recorded, and why.
 *
 * @param {{client: {entity_key: string, name: string}, refusal: string}} unrecorded `useEndClient()`'s `unrecorded`.
 * @return {string} The sentence.
 */
export function unrecordedText( { client, refusal } ) {
	return sprintf(
		/* translators: 1: the client's name, 2: its key, 3: why the role was refused. */
		__(
			'%1$s (%2$s) is no longer a client, but former_client was not recorded: %3$s',
			'newspack-intelligence'
		),
		client.name,
		client.entity_key,
		refusal
	);
}

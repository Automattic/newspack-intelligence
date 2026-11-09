/**
 * Ending a client: its `client` role closed with `end_role`, and only once
 * that lands a `former_client` role opened with `assert_role`, because two
 * role writes sent together race and one of them is lost. Nothing ends on
 * one click: a control asks first, and the end is sent only once the reader
 * confirms in the End client dialog. The entity page's churn notice and
 * Edit drawer and the health strip's dead client feeds all end a client
 * through it, each hiding the client from the confirmation through
 * `useHeld` until a read no longer lists it, since the projector records
 * the end only on its next flush.
 */

import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { CLIENT_READS, useIntelWrite } from './useIntelCommand';
import { useHeld } from './useHeld';

/** The role a client holds while it is one. */
export const CLIENT = 'client';

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
 * End one client at a time, asked first. `ask( client )` names the client
 * the dialog asks about as `asking`, and does nothing while a pair is owed
 * its replies; `cancel()` drops the question, and `end()` closes it and
 * sends the end, holding the client's key, so `read` leaves out every row
 * of `rows` naming it until a read no longer lists it. A refused end
 * releases the hold and answers as `refused`; a refused assert keeps it,
 * because the end landed, and answers as `unrecorded`, which stands until
 * the next `end()` so it outlives the client's rows. `asked` names the
 * client the last pair ends, `owed` the same client while that pair is
 * owed its replies, and `ended` says, for the host's snackbar, that the
 * client whose pair landed whole is now a former one. Each write re-reads
 * what it changes through its `dirties` as it lands.
 *
 * @param {string}                                              scope Names the pair's nodes, so the two writes never
 *                                                                    share a Fetcher with another write of their verb.
 * @param {{answer: ?Object, error: ?string, loading: boolean}} read  The read listing the clients.
 * @param {string}                                              rows  Its answer's list of rows, each naming a client by `entity_key`.
 * @return {{read: Object, asking: ?{entity_key: string, name: string}, ask: (client: {entity_key: string, name: string}) => void, cancel: () => void, end: () => void, asked: ?string, owed: ?string, refused: ?string, unrecorded: ?{client: {entity_key: string, name: string}, refusal: string}, ended: ?{id: string, message: string}}}
 *   The read with every held client left out, the client asked about or
 *   null, the three controls, the client the last pair ends, that client
 *   while its pair is owed or null, the last end's refusal, the client
 *   whose end landed and whose assert was refused, with that refusal, and
 *   the message naming the client the last pair ended whole.
 */
export function useEndClient( scope, read, rows ) {
	const held = useHeld( read, rows, clientKey );
	const [ asking, setAsking ] = useState( null );
	const [ pair, setPair ] = useState( null );
	const [ owed, setOwed ] = useState( false );
	const [ unrecorded, setUnrecorded ] = useState( null );
	const [ ended, setEnded ] = useState( null );
	const settle = () => setOwed( false );
	const assert = useIntelWrite( 'assert_role', {
		scope: `${ scope }:assert_role`,
		dirties: CLIENT_READS,
		refresh: () => {
			setEnded( pair.client );
			settle();
		},
		onRefused: () => {
			setUnrecorded( pair.client );
			settle();
		},
	} );
	const end = useIntelWrite( 'end_role', {
		scope: `${ scope }:end_role`,
		dirties: CLIENT_READS,
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
		asking,
		ask: ( client ) => {
			if ( ! owed ) {
				setAsking( client );
			}
		},
		cancel: () => setAsking( null ),
		end: () => {
			const client = asking;
			setAsking( null );
			setOwed( true );
			setUnrecorded( null );
			setEnded( null );
			held.hold( client.entity_key, null, () => {
				const tokens = end.run( {
					key: client.entity_key,
					role: CLIENT,
				} );
				setPair( { client, tokens } );
				return tokens;
			} );
		},
		asked: pair?.client.entity_key ?? null,
		owed: owed ? pair?.client.entity_key ?? null : null,
		refused: end.refusal,
		unrecorded: unrecorded && {
			client: unrecorded,
			refusal: assert.refusal,
		},
		ended: ended && {
			id: ended.entity_key,
			message: sprintf(
				/* translators: %s: the client's name. */
				__( '%s is now a former client.', 'newspack-intelligence' ),
				ended.name
			),
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

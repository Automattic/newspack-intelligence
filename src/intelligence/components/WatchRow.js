/**
 * One of the reader's watches as the Briefing lists it.
 */

import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * What a watch's inbox holds: that it is off, or its unseen count.
 *
 * @param {Object} watch The watch.
 * @return {string} The words.
 */
const inboxText = ( watch ) =>
	watch.inbox
		? sprintf(
				/* translators: %d: matches not yet seen. */
				__( '%d new', 'newspack-intelligence' ),
				watch.unseen
		  )
		: __( 'Not listed here; email only', 'newspack-intelligence' );

/**
 * One watch as a plain button named by it, which opens it, then, given
 * `count`, what its inbox holds; a watch still saving shows its name as
 * saving.
 *
 * @param {Object}                props
 * @param {Object}                props.watch    A watch as `watches` answers it.
 * @param {boolean}               [props.active] Whether it is the watch opened.
 * @param {boolean}               [props.count]  Whether to show its new count.
 * @param {(key: string) => void} props.onOpen   Opens it.
 * @return {import('react').ReactElement} The row.
 */
export default function WatchRow( {
	watch,
	active = false,
	count = false,
	onOpen,
} ) {
	if ( ! watch.watch_key ) {
		return (
			<li>
				<span>{ watch.name }</span>
				<span className="newspack-nodes-status is-muted">
					{ __( 'Saving…', 'newspack-intelligence' ) }
				</span>
			</li>
		);
	}
	return (
		<li>
			<Button
				variant="link"
				aria-current={ active || undefined }
				onClick={ () => onOpen( watch.watch_key ) }
			>
				{ watch.name }
			</Button>
			{ count && (
				<span className="newspack-intelligence__count">
					{ inboxText( watch ) }
				</span>
			) }
		</li>
	);
}

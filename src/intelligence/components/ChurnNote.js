/**
 * A client's churn-risk note in words: what puts the client at risk, who
 * owns it and the next step, each only when given. The profile shows it,
 * and the Briefing's Needs attention lists the client by it.
 */

import { Button } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { shownDay } from './format';

/**
 * @typedef {Object} ChurnNoteRecord
 * @property {string} text      What puts the client at risk.
 * @property {string} owner     Who owns the next step; '' for none.
 * @property {string} next_step What happens next; '' for none.
 * @property {string} day       The day it is due, Y-m-d; '' for none.
 */

/**
 * Words without the full stops they end in, so a sentence ends in one.
 *
 * @param {string} words The words.
 * @return {string} The words, unstopped.
 */
const unstopped = ( words ) => words.replace( /\.+$/, '' );

/**
 * The next step in words, or null for none.
 *
 * @param {ChurnNoteRecord} note The note.
 * @return {?string} The sentence.
 */
function nextText( note ) {
	if ( note.next_step && note.day ) {
		return sprintf(
			/* translators: 1: the next step, 2: the day it is due. */
			__( 'Next: %1$s, by %2$s.', 'newspack-intelligence' ),
			unstopped( note.next_step ),
			shownDay( note.day )
		);
	}
	if ( note.next_step ) {
		return sprintf(
			/* translators: %s: the next step. */
			__( 'Next: %s.', 'newspack-intelligence' ),
			unstopped( note.next_step )
		);
	}
	return note.day
		? sprintf(
				/* translators: %s: the day the next step is due. */
				__( 'By %s.', 'newspack-intelligence' ),
				shownDay( note.day )
		  )
		: null;
}

/**
 * The note as one line.
 *
 * @param {ChurnNoteRecord} note The note.
 * @return {string} The line.
 */
export function noteText( note ) {
	return [
		sprintf(
			/* translators: %s: what puts the client at risk. */
			__( 'Churn risk: %s.', 'newspack-intelligence' ),
			unstopped( note.text )
		),
		note.owner &&
			sprintf(
				/* translators: %s: who owns the next step. */
				__( 'Owner: %s.', 'newspack-intelligence' ),
				note.owner
			),
		nextText( note ),
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * The note, with **Edit note** where the reader may edit it.
 *
 * @param {Object}          props
 * @param {ChurnNoteRecord} props.note   The note.
 * @param {?() => void}     props.onEdit Opens its form; null for a reader.
 * @return {import('react').ReactElement} The note.
 */
export default function ChurnNote( { note, onEdit } ) {
	return (
		<p className="newspack-intelligence__churn-note">
			<span>{ noteText( note ) }</span>
			{ onEdit && (
				<Button variant="link" onClick={ onEdit }>
					{ __( 'Edit note', 'newspack-intelligence' ) }
				</Button>
			) }
		</p>
	);
}

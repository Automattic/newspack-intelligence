/**
 * A refused write as the alert every view shows it in; nothing when there is
 * none. A read that failed is `Unread`'s, a polite status instead.
 *
 * @param {Object}  props
 * @param {?string} props.error The refusal.
 * @return {?import('react').ReactElement} The alert.
 */
export default function Refusal( { error } ) {
	return error ? (
		<div className="newspack-nodes-error-banner" role="alert">
			{ error }
		</div>
	) : null;
}

import { useEffect, useId, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import Modal, { ModalHeader } from '@newspack-nodes/shared/components/Modal';
import { primaryButtonClass } from '@newspack-nodes/shared/utils/buttonClass';
import { safeHref } from './format';

/**
 * One piece of evidence as the dialog lists it: its title quoted, its source
 * as the caller words it and its day, then, for an item naming the client as its subject, that it
 * does, and a link to the item where its URL is safe to link.
 *
 * @param {Object} props
 * @param {Object} props.one The evidence: `{ title, url, source, day, role }`.
 * @return {import('react').ReactElement} The row.
 */
function Evidence( { one } ) {
	const href = safeHref( one.url );
	return (
		<li>
			<p>
				{ [ `“${ one.title }”`, one.source, one.day ]
					.filter( Boolean )
					.join( ', ' ) }
			</p>
			{ ( 'subject' === one.role || href ) && (
				<p>
					{ 'subject' === one.role &&
						__(
							'Main subject of the item.',
							'newspack-intelligence'
						) }{ ' ' }
					{ href && (
						<a
							href={ href }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Open item', 'newspack-intelligence' ) }
						</a>
					) }
				</p>
			) }
		</li>
	);
}

/**
 * The question every End client control asks before it writes: whether to
 * end the client's role today and record it as a former client, naming the
 * evidence the question rests on. It writes nothing itself. **End client**
 * hands back the end, and **Not about this client**, offered only with an
 * `onNotAbout`, hands back that the evidence is not about this client.
 * Focus moves to the close button as it opens, never to the destructive
 * button, and the shared modal hands it back to the opener on close.
 *
 * @param {Object}                                                                                                props
 * @param {string}                                                                                                props.name         The client's name.
 * @param {Array<{item_key: ?string, title: string, url: ?string, source: ?string, day: ?string, role: ?string}>} props.evidence     What the question rests on.
 * @param {() => void}                                                                                            props.onEnd        Ends the client.
 * @param {() => void}                                                                                            [props.onNotAbout] Says the evidence is not about the client.
 * @param {() => void}                                                                                            props.onClose      Closes the dialog.
 * @return {import('react').ReactElement} The dialog.
 */
export default function EndClientDialog( {
	name,
	evidence,
	onEnd,
	onNotAbout,
	onClose,
} ) {
	const closeRef = useRef( null );
	const evidenceId = useId();
	useEffect( () => closeRef.current?.focus(), [] );
	return (
		<Modal onClose={ onClose }>
			<ModalHeader
				title={ sprintf(
					/* translators: %s: the client's name. */
					__( 'End %s as a client?', 'newspack-intelligence' ),
					name
				) }
				onClose={ onClose }
				closeRef={ closeRef }
				closeLabel={ __( 'Close', 'newspack-intelligence' ) }
			/>
			<p>
				{ sprintf(
					/* translators: %s: the client's name. */
					__(
						'This ends the client role today and records %s as a former client. Watches and team views will treat it as a former client from now on.',
						'newspack-intelligence'
					),
					name
				) }
			</p>
			{ 0 < evidence.length && (
				<>
					<h3 id={ evidenceId }>
						{ __( 'Evidence', 'newspack-intelligence' ) }
					</h3>
					<ul
						className="newspack-intelligence__facts"
						aria-labelledby={ evidenceId }
					>
						{ evidence.map( ( one ) => (
							<Evidence
								key={ one.item_key ?? one.title }
								one={ one }
							/>
						) ) }
					</ul>
				</>
			) }
			<div className="newspack-nodes-modal__actions">
				{ onNotAbout && (
					<button
						type="button"
						className="button"
						onClick={ onNotAbout }
					>
						{ __(
							'Not about this client',
							'newspack-intelligence'
						) }
					</button>
				) }
				<button
					type="button"
					className={ primaryButtonClass( false, 'is-danger' ) }
					onClick={ onEnd }
				>
					{ __( 'End client', 'newspack-intelligence' ) }
				</button>
			</div>
		</Modal>
	);
}

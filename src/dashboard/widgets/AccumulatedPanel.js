import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNodeState } from '@newspack-nodes/runtime';
import { markdownToBlockMarkup } from '../markdownToBlockMarkup';

// How long a transient ack note stays before auto-dismissing.
const NOTE_TTL_MS = 6000;

// Safety net: release the Collect lock if a cycle never reports completion.
const COLLECT_SAFETY_MS = 180000;

/**
 * REST-call seam for the "Create draft post" action. Lazily defaulted to a thin
 * apiFetch wrapper; tests inject a fake so the suite never hits the network but
 * still exercises the success/failure rendering paths.
 *
 * @param {Object} draft         Draft fields.
 * @param {string} draft.title   Post title.
 * @param {string} draft.content Post body.
 */
const defaultCreateDraft = ( { title, content } ) =>
	apiFetch( {
		path: '/wp/v2/posts',
		method: 'POST',
		data: { title, content, status: 'draft' },
	} );

// Parse a verb ack: {…} | {error} on success/handled-failure, null on garbage.
function parseAck( payload ) {
	try {
		const parsed = JSON.parse( payload );
		return parsed && 'object' === typeof parsed ? parsed : null;
	} catch ( e ) {
		return null;
	}
}

/**
 * AccumulatedPanel — the Total items KPI + collection progress + digest/newsletter
 * card. Reads ONLY the `accumulated:view` node's slice ({ accumulated, done, total,
 * digest }) via useNodeState. The Collect / Regenerate action verbs arrive as the
 * `collect` / `generate` props (the hook's awaited verbs that route to the worker);
 * Copy / Create-draft act on the shown digest (the durable digest:log content the
 * poll delivers) via the `createDraft` / `markdownToContent` seams.
 *
 * @param {Object}   props
 * @param {Function} props.generate            Awaited `generate` verb → ack payload.
 * @param {Function} props.collect             Awaited `collect` verb → ack payload.
 * @param {Function} [props.createDraft]       REST-call seam: ({title,content}) => Promise (tests).
 * @param {Function} [props.markdownToContent] Markdown→block-markup seam (tests inject a fake).
 */
export function AccumulatedPanel( {
	generate,
	collect,
	createDraft = defaultCreateDraft,
	markdownToContent = markdownToBlockMarkup,
} ) {
	const slice = useNodeState( 'accumulated:view', 'view' ) || {
		accumulated: 0,
		done: 0,
		total: 0,
		digest: '',
	};
	const error = slice.error || null;
	const accumulated = slice.accumulated || 0;
	const digest = slice.digest || '';
	const done = slice.done || 0;
	const total = slice.total || 0;
	const collectComplete = total > 0 && done >= total;

	const [ generating, setGenerating ] = useState( false );
	const [ regenNote, setRegenNote ] = useState( null );
	// `collecting`: in-flight lock; timeout un-sticks a no-op collect.
	const [ collecting, setCollecting ] = useState( false );
	const [ collectNote, setCollectNote ] = useState( null );
	const startDone = useRef( 0 );
	// Latch: seen this cycle in-progress (done < total) since the click?
	const sawIncomplete = useRef( false );
	const [ copied, setCopied ] = useState( false );
	const [ editLink, setEditLink ] = useState( null );
	const [ draftError, setDraftError ] = useState( null );
	const [ creating, setCreating ] = useState( false );

	// Optimistic display: show 0 right after the click, real once done moves.
	const displayDone = collecting && done === startDone.current ? 0 : done;
	// Clickable only at a boundary (empty or complete) — survives a reload.
	const canCollect = ! collecting && ( 0 === done || collectComplete );

	// Hold the Collect lock until THIS cycle completes (seen in-progress).
	useEffect( () => {
		if ( ! collecting ) {
			return undefined;
		}
		if ( ! collectComplete ) {
			sawIncomplete.current = true;
		}
		if ( sawIncomplete.current && collectComplete ) {
			setCollecting( false );
			setCollectNote( null );
			return undefined;
		}
		const timer = setTimeout(
			() => setCollecting( false ),
			COLLECT_SAFETY_MS
		);
		return () => clearTimeout( timer );
	}, [ collecting, collectComplete ] );

	// Transient ack notes auto-dismiss so they don't linger after the action.
	useEffect( () => {
		if ( null === collectNote ) {
			return undefined;
		}
		const timer = setTimeout( () => setCollectNote( null ), NOTE_TTL_MS );
		return () => clearTimeout( timer );
	}, [ collectNote ] );
	useEffect( () => {
		if ( null === regenNote ) {
			return undefined;
		}
		const timer = setTimeout( () => setRegenNote( null ), NOTE_TTL_MS );
		return () => clearTimeout( timer );
	}, [ regenNote ] );

	const onCopy = () => {
		setDraftError( null );
		// navigator.clipboard is undefined on insecure/old origins — guard it.
		const clipboard = window.navigator.clipboard;
		if ( ! clipboard || ! clipboard.writeText ) {
			setCopied( false );
			setDraftError(
				__(
					'Clipboard unavailable here — copy from the preview instead.',
					'newspack-intelligence'
				)
			);
			return;
		}
		clipboard
			.writeText( digest )
			.then( () => setCopied( true ) )
			.catch( () => {
				setCopied( false );
				setDraftError(
					__(
						'Could not copy to the clipboard.',
						'newspack-intelligence'
					)
				);
			} );
	};

	const onCollect = () => {
		// Capture pre-click count (optimistic 0/total) and arm the latch.
		startDone.current = done;
		sawIncomplete.current = false;
		setCollecting( true );
		setCollectNote( null );
		collect()
			.then( ( payload ) => {
				// { collecting, workers } on success, { error } if no worker.
				const parsed = parseAck( payload );
				if ( ! parsed ) {
					setCollecting( false );
					setCollectNote( {
						type: 'error',
						text: __(
							'Collection returned an unexpected response.',
							'newspack-intelligence'
						),
					} );
					return;
				}
				if ( parsed.error ) {
					setCollecting( false );
					setCollectNote( {
						type: 'error',
						text: String( parsed.error ),
					} );
					return;
				}
				// Success: ack now, keep the lock; effect frees it on complete.
				setCollectNote( {
					type: 'ok',
					text: sprintf(
						/* translators: %d: number of workers the collection was dispatched to. */
						__(
							'Collecting from %d worker(s)…',
							'newspack-intelligence'
						),
						Number( parsed.workers ) || 0
					),
				} );
			} )
			.catch( ( err ) => {
				setCollecting( false );
				setCollectNote( {
					type: 'error',
					text:
						err && err.message
							? err.message
							: __(
									'Could not start collection.',
									'newspack-intelligence'
							  ),
				} );
			} );
	};

	const onGenerate = () => {
		setGenerating( true );
		setDraftError( null );
		setRegenNote( null );
		setCopied( false );
		generate()
			.then( ( payload ) => {
				setGenerating( false );
				// { regenerating, workers } on success, { error } if no worker.
				const parsed = parseAck( payload );
				if ( ! parsed ) {
					setDraftError(
						__(
							'Regeneration returned an unexpected response.',
							'newspack-intelligence'
						)
					);
					return;
				}
				if ( parsed.error ) {
					setDraftError( String( parsed.error ) );
					return;
				}
				// Worker is recomposing; the digest lands on the next poll.
				setRegenNote(
					__(
						'Regenerating… the draft updates on the next poll.',
						'newspack-intelligence'
					)
				);
			} )
			.catch( ( err ) => {
				setGenerating( false );
				setDraftError(
					err && err.message
						? err.message
						: __(
								'Could not regenerate the digest.',
								'newspack-intelligence'
						  )
				);
			} );
	};

	const onCreateDraft = () => {
		setCreating( true );
		setDraftError( null );
		setEditLink( null );
		setCopied( false );
		createDraft( {
			title: __( 'Publisher Newsletter', 'newspack-intelligence' ),
			content: markdownToContent( digest ),
		} )
			.then( ( post ) => {
				setCreating( false );
				if ( ! post || ! post.id ) {
					setDraftError(
						__(
							'Draft created, but no post id was returned.',
							'newspack-intelligence'
						)
					);
					return;
				}
				setEditLink(
					`${ window.location.origin }/wp-admin/post.php?post=${ post.id }&action=edit`
				);
			} )
			.catch( ( err ) => {
				setCreating( false );
				setDraftError(
					err && err.message
						? err.message
						: __(
								'Could not create the draft.',
								'newspack-intelligence'
						  )
				);
			} );
	};

	if ( error ) {
		return (
			<section className="eai-insights__card eai-insights__draft">
				<div
					className="eai-insights__notice eai-insights__notice--error"
					role="alert"
				>
					{ error }
				</div>
			</section>
		);
	}

	const collectFeedback = null !== collectNote && (
		<div
			className={ `eai-insights__notice eai-insights__notice--${
				'error' === collectNote.type ? 'error' : 'ok'
			}` }
			role={ 'error' === collectNote.type ? 'alert' : 'status' }
		>
			{ collectNote.text }
		</div>
	);

	return (
		<section className="eai-insights__card eai-insights__draft">
			<div className="eai-insights__stat">
				<span className="eai-insights__stat-num">{ accumulated }</span>
				<span className="eai-insights__stat-label">
					{ __( 'Total items', 'newspack-intelligence' ) }
				</span>
			</div>

			<h2>{ __( 'Newsletter', 'newspack-intelligence' ) }</h2>
			<div className="eai-insights__actions">
				<button
					type="button"
					className="button button-primary"
					onClick={ onCollect }
					disabled={ ! canCollect }
				>
					{ collecting
						? __( 'Collecting…', 'newspack-intelligence' )
						: __( 'Collect', 'newspack-intelligence' ) }
				</button>
				{ total > 0 && (
					<span className="eai-insights__progress" role="status">
						{ sprintf(
							/* translators: 1: sources done collecting, 2: total sources. */
							__(
								'Collected %1$d/%2$d',
								'newspack-intelligence'
							),
							displayDone,
							total
						) }
					</span>
				) }
				<button
					type="button"
					className="button button-primary"
					onClick={ onGenerate }
					disabled={ generating || ! collectComplete }
				>
					{ generating
						? __( 'Generating…', 'newspack-intelligence' )
						: __( 'Regenerate digest', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className="button"
					onClick={ onCopy }
					disabled={ '' === digest }
				>
					{ __( 'Copy markdown', 'newspack-intelligence' ) }
				</button>
				<button
					type="button"
					className="button"
					onClick={ onCreateDraft }
					disabled={ creating || '' === digest }
				>
					{ __( 'Create draft post', 'newspack-intelligence' ) }
				</button>
				{ copied && (
					<span className="eai-insights__copied" role="status">
						{ __( 'Copied', 'newspack-intelligence' ) }
					</span>
				) }
			</div>

			{ collectFeedback }

			{ null !== regenNote && (
				<div
					className="eai-insights__notice eai-insights__notice--ok"
					role="status"
				>
					{ regenNote }
				</div>
			) }

			{ null !== editLink && (
				<p className="eai-insights__draft-result">
					<a href={ editLink }>
						{ __( 'Edit draft', 'newspack-intelligence' ) }
					</a>
				</p>
			) }
			{ null !== draftError && (
				<div
					className="eai-insights__notice eai-insights__notice--error"
					role="alert"
				>
					{ draftError }
				</div>
			) }

			{ '' !== digest ? (
				<pre
					className="eai-insights__preview"
					data-testid="eai-insights-preview"
				>
					{ digest }
				</pre>
			) : (
				<p className="eai-insights__preview-empty">
					{ __( 'No digest yet.', 'newspack-intelligence' ) }
				</p>
			) }
		</section>
	);
}

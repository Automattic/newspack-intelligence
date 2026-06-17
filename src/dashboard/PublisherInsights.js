import apiFetch from '@wordpress/api-fetch';
import { useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNodeState } from '@newspack-nodes/runtime';
import { useInsightsGraph } from './hooks/useInsightsGraph';
import { emptyModel } from './nodes/insightsView';
import { markdownToBlocks } from './markdownToBlocks';
import './styles/insights.scss';

// How long a transient ack note stays before auto-dismissing.
const NOTE_TTL_MS = 6000;

// Safety net: release the Collect lock if a dispatched cycle never reports
// completion (a source hung), so the button can't latch forever.
const COLLECT_SAFETY_MS = 180000;

// REST-call seam for the "Create draft post" action. Lazily defaulted to a
// thin apiFetch wrapper; tests inject a fake so the suite never hits the
// network but still exercises the success/failure rendering paths.
const defaultCreateDraft = ( { title, content } ) =>
	apiFetch( {
		path: '/wp/v2/posts',
		method: 'POST',
		data: { title, content, status: 'draft' },
	} );

/**
 * Publisher Insights — the thin view over the `insights:view` node graph. The
 * graph (mounted by useInsightsGraph) owns the data: the page-visibility-gated
 * poll fires the `insights` command, and `insights:view` holds the model React
 * reads via useNodeState. This component renders that model — an error notice,
 * an empty state, or the populated analytics dashboard (KPI stat cards,
 * proportion bars, a score-ranked table, and a newsletter section that shows the
 * REAL rendered digest — the latest digest:log content the poll delivers;
 * "Regenerate digest" asks the worker to recompose and the next poll brings it
 * in — then copies its markdown or creates a WordPress draft from it as native
 * blocks). Styling follows the
 * Newspack in-product design system (docs/DESIGN.product.md): light surfaces, a
 * Cobalt accent, Inter, laid out in flow within wp-admin.
 *
 * @param {Object}   props
 * @param {number}   [props.refreshMs]     Poll interval in ms (default 4000).
 * @param {Object}   [props.commandClient] CommandClient seam forwarded to the hook (tests).
 * @param {Function} [props.createDraft]   REST-call seam: ({title,content}) => Promise (tests).
 */
export default function PublisherInsights( {
	refreshMs = 4000,
	commandClient,
	createDraft = defaultCreateDraft,
} ) {
	const { generate, collect } = useInsightsGraph( {
		refreshMs,
		commandClient,
	} );
	// One fallback to the canonical empty shape; the node guarantees the data
	// fields on every publish (model, error-model, or empty), so no per-field guards.
	const model = useNodeState( 'insights:view', 'view' ) || emptyModel();
	const [ generating, setGenerating ] = useState( false );
	// Regenerate ack note (the worker recomposes; the poll surfaces the result).
	const [ regenNote, setRegenNote ] = useState( null );
	// `collecting` is the optimistic in-flight lock: set on click, it shows 0/total and keeps
	// Collect disabled until the poll reflects the new cycle (done moves off its click-time
	// value) or a timeout fires — the timeout is the anti-stick guard so a no-op collection
	// (no live worker, nothing produced, total 0) can't latch the button forever. `startDone`
	// is the click-time count the clear compares against. `collectNote` is the collect-specific
	// feedback (success ack or error), shown wherever Collect is.
	const [ collecting, setCollecting ] = useState( false );
	const [ collectNote, setCollectNote ] = useState( null );
	const startDone = useRef( 0 );
	// Latch: have we observed this cycle in-progress (done < total) since the click?
	// Only then does a `complete` reading mean THIS cycle finished (not the pre-click one).
	const sawIncomplete = useRef( false );
	const [ copied, setCopied ] = useState( false );
	const [ editLink, setEditLink ] = useState( null );
	const [ draftError, setDraftError ] = useState( null );
	const [ creating, setCreating ] = useState( false );

	const error = model.error || null;
	// Defensive ?? {}/[]: emptyModel + the CI guarantee these, but a malformed
	// 200 reply could publish a partial object — never crash the page on it.
	const sources = Object.entries( model.sources ?? {} );
	// `top` is per-source: { source: [{title, score}], … }. topBySource is its entries; the
	// flattened list feeds the KPI top-score and the proportion bars (comparable across sources).
	const top = model.top ?? {};
	const topBySource = Object.entries( top );
	const allTopItems = topBySource.flatMap( ( [ , items ] ) => items );
	const isEmpty = ! model.accumulated && 0 === topBySource.length;
	// The shown digest is the durable digest:log content the poll delivers; a
	// Regenerate recomposes it in the worker and the next poll brings it here.
	const digest = model.digest || '';
	// Collection progress (X/total) the poll delivers; Generate only unlocks once
	// every source has reported DONE.
	const done = model.done || 0;
	const total = model.total || 0;
	const collectComplete = total > 0 && done >= total;
	// Optimistic display: show 0 right after the click (while `done` is still the
	// stale pre-click value) instead of the prior cycle's count; show real progress once it moves.
	const displayDone = collecting && done === startDone.current ? 0 : done;
	// Clickable only when there's nothing in flight AND the pipeline is at a clean boundary —
	// empty (0) or complete (>= total). Mid-collection (0 < done < total) it's locked by the
	// boundary rule alone, so gating survives a page reload (which loses `collecting`).
	const canCollect = ! collecting && ( 0 === done || collectComplete );

	// Hold the Collect lock for the WHOLE dispatched cycle: release only once THIS
	// cycle finishes (a `complete` reading AFTER we've seen it in-progress — never the
	// pre-click complete state), or a long safety timeout if a source never reports.
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

	// Transient ack notes (collect dispatch, regenerate request) auto-dismiss so
	// they don't linger on screen forever after the action has landed.
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

	const topScore = allTopItems.reduce(
		( max, item ) => Math.max( max, item.score || 0 ),
		0
	);
	const sourceTotal = sources.reduce(
		( sum, [ , count ] ) => sum + count,
		0
	);

	const onCopy = () => {
		setDraftError( null );
		// navigator.clipboard is undefined on insecure (non-HTTPS) origins and
		// older browsers — guard it, and only flag "Copied" once the write
		// actually resolves.
		const clipboard = window.navigator.clipboard;
		if ( ! clipboard || ! clipboard.writeText ) {
			setCopied( false );
			setDraftError(
				__(
					'Clipboard unavailable here — copy from the preview instead.',
					'newspack-ai-newsletter'
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
						'newspack-ai-newsletter'
					)
				);
			} );
	};

	const onCollect = () => {
		// Capture the pre-click count (for the optimistic 0/total display) and arm the
		// latch: this cycle hasn't been seen in-progress yet.
		startDone.current = done;
		sawIncomplete.current = false;
		setCollecting( true );
		setCollectNote( null );
		collect()
			.then( ( payload ) => {
				// The verb replies with JSON: { collecting, workers } on success or
				// { error } (a normal reply, not a TM_ERROR) when no worker is live.
				let parsed = null;
				try {
					parsed = JSON.parse( payload );
				} catch ( e ) {
					parsed = null;
				}
				if ( ! parsed || 'object' !== typeof parsed ) {
					setCollecting( false );
					setCollectNote( {
						type: 'error',
						text: __(
							'Collection returned an unexpected response.',
							'newspack-ai-newsletter'
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
				// Success: acknowledge now; keep the lock — the effect releases it when the
				// poll shows the cycle complete (done >= total).
				setCollectNote( {
					type: 'ok',
					text: sprintf(
						/* translators: %d: number of workers the collection was dispatched to. */
						__(
							'Collecting from %d worker(s)…',
							'newspack-ai-newsletter'
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
									'newspack-ai-newsletter'
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
				// The verb replies with JSON: { regenerating, workers } on success
				// or { error } (a normal reply, not a TM_ERROR) when no worker is live.
				let parsed = null;
				try {
					parsed = JSON.parse( payload );
				} catch ( e ) {
					parsed = null;
				}
				if ( ! parsed || 'object' !== typeof parsed ) {
					setDraftError(
						__(
							'Regeneration returned an unexpected response.',
							'newspack-ai-newsletter'
						)
					);
					return;
				}
				if ( parsed.error ) {
					setDraftError( String( parsed.error ) );
					return;
				}
				// The worker is recomposing; the durable digest lands on the next poll.
				setRegenNote(
					__(
						'Regenerating… the draft updates on the next poll.',
						'newspack-ai-newsletter'
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
								'newspack-ai-newsletter'
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
			title: __( 'Publisher Newsletter', 'newspack-ai-newsletter' ),
			content: markdownToBlocks( digest ),
		} )
			.then( ( post ) => {
				setCreating( false );
				if ( ! post || ! post.id ) {
					setDraftError(
						__(
							'Draft created, but no post id was returned.',
							'newspack-ai-newsletter'
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
								'newspack-ai-newsletter'
						  )
				);
			} );
	};

	// Collect drives a collection cycle. The button + progress (collectButton) and the
	// success/error note (collectFeedback) render in BOTH the empty and populated states —
	// you need Collect most when there's nothing scored yet. collectButton is a bare
	// fragment so the populated card can sit it inline with Generate/Copy/Create. Enabled
	// only at a clean boundary (empty or complete); progress shows the optimistic count.
	const collectButton = (
		<>
			<button
				type="button"
				className="eai-insights__btn"
				onClick={ onCollect }
				disabled={ ! canCollect }
			>
				{ collecting
					? __( 'Collecting…', 'newspack-ai-newsletter' )
					: __( 'Collect', 'newspack-ai-newsletter' ) }
			</button>
			{ total > 0 && (
				<span className="eai-insights__progress" role="status">
					{ sprintf(
						/* translators: 1: sources done collecting, 2: total sources. */
						__( 'Collected %1$d/%2$d', 'newspack-ai-newsletter' ),
						displayDone,
						total
					) }
				</span>
			) }
		</>
	);

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

	// One branch wins: an error notice, the empty state, or the populated
	// dashboard. (if/else, not a nested ternary — keeps each branch readable.)
	let content;
	if ( error ) {
		content = (
			<div
				className="eai-insights__notice eai-insights__notice--error"
				role="alert"
			>
				{ error }
			</div>
		);
	} else if ( isEmpty ) {
		content = (
			<div className="eai-insights__empty">
				<p>
					{ __( 'No scored items yet.', 'newspack-ai-newsletter' ) }
				</p>
				<p className="eai-insights__empty-hint">
					{ __(
						'Hit Collect to run the sources (or tick them from the REPL); this updates on the next poll.',
						'newspack-ai-newsletter'
					) }
				</p>
				<div className="eai-insights__actions">{ collectButton }</div>
				{ collectFeedback }
			</div>
		);
	} else {
		content = (
			<div className="eai-insights__layout">
				<div className="eai-insights__side">
					<div className="eai-insights__stats">
						<div className="eai-insights__stat">
							<span className="eai-insights__stat-num">
								{ model.accumulated }
							</span>
							<span className="eai-insights__stat-label">
								{ __(
									'Total items',
									'newspack-ai-newsletter'
								) }
							</span>
						</div>
						<div className="eai-insights__stat">
							<span className="eai-insights__stat-num">
								{ topScore }
							</span>
							<span className="eai-insights__stat-label">
								{ __( 'Top score', 'newspack-ai-newsletter' ) }
							</span>
						</div>
						<div className="eai-insights__stat">
							<span className="eai-insights__stat-num">
								{ sources.length }
							</span>
							<span className="eai-insights__stat-label">
								{ __( 'Sources', 'newspack-ai-newsletter' ) }
							</span>
						</div>
					</div>

					<section className="eai-insights__card eai-insights__sources">
						<h2>{ __( 'By source', 'newspack-ai-newsletter' ) }</h2>
						<ul>
							{ sources.map( ( [ name, count ] ) => (
								<li key={ name }>
									<div className="eai-insights__bar-head">
										<span className="eai-insights__source-name">
											{ name }
										</span>
										<span className="eai-insights__source-count">
											{ count }
										</span>
									</div>
									<div
										className="eai-insights__bar"
										aria-hidden="true"
									>
										<div
											className="eai-insights__bar-fill"
											style={ {
												width: `${
													sourceTotal
														? ( count /
																sourceTotal ) *
														  100
														: 0
												}%`,
											} }
										/>
									</div>
								</li>
							) ) }
						</ul>
					</section>

					<section className="eai-insights__card eai-insights__draft">
						<h2>
							{ __( 'Newsletter', 'newspack-ai-newsletter' ) }
						</h2>
						<div className="eai-insights__actions">
							{ collectButton }
							<button
								type="button"
								className="eai-insights__btn"
								onClick={ onGenerate }
								disabled={ generating || ! collectComplete }
							>
								{ generating
									? __(
											'Generating…',
											'newspack-ai-newsletter'
									  )
									: __(
											'Regenerate digest',
											'newspack-ai-newsletter'
									  ) }
							</button>
							<button
								type="button"
								className="eai-insights__btn eai-insights__btn--secondary"
								onClick={ onCopy }
								disabled={ '' === digest }
							>
								{ __(
									'Copy markdown',
									'newspack-ai-newsletter'
								) }
							</button>
							<button
								type="button"
								className="eai-insights__btn eai-insights__btn--secondary"
								onClick={ onCreateDraft }
								disabled={ creating || '' === digest }
							>
								{ __(
									'Create draft post',
									'newspack-ai-newsletter'
								) }
							</button>
							{ copied && (
								<span
									className="eai-insights__copied"
									role="status"
								>
									{ __( 'Copied', 'newspack-ai-newsletter' ) }
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
									{ __(
										'Edit draft',
										'newspack-ai-newsletter'
									) }
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
								{ __(
									'No digest yet.',
									'newspack-ai-newsletter'
								) }
							</p>
						) }
					</section>
				</div>

				<div className="eai-insights__main">
					<section className="eai-insights__card eai-insights__top">
						<h2>
							{ __(
								'Top items by source',
								'newspack-ai-newsletter'
							) }
						</h2>
						<div className="eai-insights__source-grid">
							{ topBySource.map( ( [ source, items ] ) => (
								<div
									className="eai-insights__source-top"
									key={ source }
								>
									<h3>{ source }</h3>
									<table>
										<thead>
											<tr>
												<th className="eai-insights__rank-col">
													{ __(
														'#',
														'newspack-ai-newsletter'
													) }
												</th>
												<th>
													{ __(
														'Title',
														'newspack-ai-newsletter'
													) }
												</th>
												<th>
													{ __(
														'Score',
														'newspack-ai-newsletter'
													) }
												</th>
											</tr>
										</thead>
										<tbody>
											{ items.map( ( item, i ) => (
												<tr
													key={ `${ source }-${ i }` }
												>
													<td className="eai-insights__rank">
														{ sprintf(
															/* translators: %d: the item's rank within its source. */
															__(
																'#%d',
																'newspack-ai-newsletter'
															),
															i + 1
														) }
													</td>
													<td
														className="eai-insights__item-title"
														title={ item.title }
													>
														{ item.title }
													</td>
													<td className="eai-insights__score-cell">
														<div
															className="eai-insights__score-bar-track"
															aria-hidden="true"
														>
															<div
																className="eai-insights__score-bar"
																style={ {
																	width: `${
																		topScore
																			? ( ( item.score ||
																					0 ) /
																					topScore ) *
																			  100
																			: 0
																	}%`,
																} }
															/>
														</div>
														<span className="eai-insights__score-num">
															{ item.score }
														</span>
													</td>
												</tr>
											) ) }
										</tbody>
									</table>
								</div>
							) ) }
						</div>
					</section>
				</div>
			</div>
		);
	}

	return (
		<div className="eai-insights">
			<header className="eai-insights__header">
				<h1>
					{ __( 'Publisher Insights', 'newspack-ai-newsletter' ) }
				</h1>
				<p className="eai-insights__sub">
					{ sprintf(
						/* translators: %d: total items accumulated across the pipeline. */
						__( 'Accumulated items: %d', 'newspack-ai-newsletter' ),
						model.accumulated
					) }
				</p>
			</header>
			{ content }
		</div>
	);
}

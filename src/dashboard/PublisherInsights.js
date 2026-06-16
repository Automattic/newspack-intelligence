import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { useNodeState } from '@newspack-nodes/runtime';
import { useInsightsGraph } from './hooks/useInsightsGraph';
import { emptyModel } from './nodes/insightsView';
import { markdownToBlocks } from './markdownToBlocks';
import './styles/insights.scss';

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
 * REAL rendered digest — the latest digest:log content the poll delivers, or a
 * fresh recompose from the "Generate digest" button — then copies its markdown
 * or creates a WordPress draft from it as native blocks). Styling follows the
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
	const [ generated, setGenerated ] = useState( null );
	const [ generating, setGenerating ] = useState( false );
	const [ collecting, setCollecting ] = useState( false );
	const [ copied, setCopied ] = useState( false );
	const [ editLink, setEditLink ] = useState( null );
	const [ draftError, setDraftError ] = useState( null );
	const [ creating, setCreating ] = useState( false );

	const error = model.error || null;
	// Defensive ?? {}/[]: emptyModel + the CI guarantee these, but a malformed
	// 200 reply could publish a partial object — never crash the page on it.
	const sources = Object.entries( model.sources ?? {} );
	const top = model.top ?? [];
	const isEmpty = ! model.accumulated && 0 === top.length;
	// The shown digest: a freshly generated one wins; else the durable digest:log
	// content the poll delivered. Empty until a FLUSH/Generate has produced one.
	const digest = null !== generated ? generated : model.digest || '';
	// Collection progress (X/total) the poll delivers; Generate only unlocks once
	// every source has reported DONE.
	const done = model.done || 0;
	const total = model.total || 0;
	const collectComplete = total > 0 && done >= total;

	const topScore = top.reduce(
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
		setCollecting( true );
		setDraftError( null );
		collect()
			.then( ( payload ) => {
				setCollecting( false );
				// The verb replies with JSON: { collecting, workers } on success or
				// { error } (a normal reply, not a TM_ERROR) when no worker is live.
				// Surface the error; on success the progress arrives via the poll.
				let parsed = null;
				try {
					parsed = JSON.parse( payload );
				} catch ( e ) {
					parsed = null;
				}
				if ( ! parsed || 'object' !== typeof parsed ) {
					setDraftError(
						__(
							'Collection returned an unexpected response.',
							'newspack-ai-newsletter'
						)
					);
					return;
				}
				if ( parsed.error ) {
					setDraftError( String( parsed.error ) );
				}
			} )
			.catch( ( err ) => {
				setCollecting( false );
				setDraftError(
					err && err.message
						? err.message
						: __(
								'Could not start collection.',
								'newspack-ai-newsletter'
						  )
				);
			} );
	};

	const onGenerate = () => {
		setGenerating( true );
		setDraftError( null );
		setCopied( false );
		generate()
			.then( ( markdown ) => {
				setGenerating( false );
				// A successful-but-empty recompose (no scored items / empty LLM
				// reply) must not wipe the digest already on screen — notify and
				// keep what's shown.
				if ( '' === markdown.trim() ) {
					setDraftError(
						__(
							'Nothing to generate yet — the pipeline has no scored items.',
							'newspack-ai-newsletter'
						)
					);
					return;
				}
				setGenerated( markdown );
			} )
			.catch( ( err ) => {
				setGenerating( false );
				setDraftError(
					err && err.message
						? err.message
						: __(
								'Could not generate the digest.',
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
						'Drive the pipeline — tick the sources — and this updates on the next poll.',
						'newspack-ai-newsletter'
					) }
				</p>
			</div>
		);
	} else {
		content = (
			<div className="eai-insights__grid">
				<div className="eai-insights__stats">
					<div className="eai-insights__stat">
						<span className="eai-insights__stat-num">
							{ model.accumulated }
						</span>
						<span className="eai-insights__stat-label">
							{ __( 'Total items', 'newspack-ai-newsletter' ) }
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
													? ( count / sourceTotal ) *
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

				<section className="eai-insights__card eai-insights__top">
					<h2>{ __( 'Top items', 'newspack-ai-newsletter' ) }</h2>
					<table>
						<thead>
							<tr>
								<th className="eai-insights__rank-col">
									{ __( '#', 'newspack-ai-newsletter' ) }
								</th>
								<th>
									{ __( 'Source', 'newspack-ai-newsletter' ) }
								</th>
								<th>
									{ __( 'Title', 'newspack-ai-newsletter' ) }
								</th>
								<th>
									{ __( 'Score', 'newspack-ai-newsletter' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ top.map( ( item, i ) => (
								<tr key={ `${ item.source }-${ i }` }>
									<td className="eai-insights__rank">
										{ sprintf(
											/* translators: %d: the item's rank in the score-ordered list. */
											__(
												'#%d',
												'newspack-ai-newsletter'
											),
											i + 1
										) }
									</td>
									<td>{ item.source }</td>
									<td>{ item.title }</td>
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
				</section>

				<section className="eai-insights__card eai-insights__draft">
					<h2>{ __( 'Newsletter', 'newspack-ai-newsletter' ) }</h2>
					<div className="eai-insights__actions">
						<button
							type="button"
							className="eai-insights__btn"
							onClick={ onCollect }
							disabled={ collecting }
						>
							{ collecting
								? __( 'Collecting…', 'newspack-ai-newsletter' )
								: __( 'Collect', 'newspack-ai-newsletter' ) }
						</button>
						{ total > 0 && (
							<span
								className="eai-insights__progress"
								role="status"
							>
								{ sprintf(
									/* translators: 1: sources done collecting, 2: total sources. */
									__(
										'Collected %1$d/%2$d',
										'newspack-ai-newsletter'
									),
									done,
									total
								) }
							</span>
						) }
						<button
							type="button"
							className="eai-insights__btn"
							onClick={ onGenerate }
							disabled={ generating || ! collectComplete }
						>
							{ generating
								? __( 'Generating…', 'newspack-ai-newsletter' )
								: __(
										'Generate digest',
										'newspack-ai-newsletter'
								  ) }
						</button>
						<button
							type="button"
							className="eai-insights__btn eai-insights__btn--secondary"
							onClick={ onCopy }
							disabled={ '' === digest }
						>
							{ __( 'Copy markdown', 'newspack-ai-newsletter' ) }
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

					{ null !== editLink && (
						<p className="eai-insights__draft-result">
							<a href={ editLink }>
								{ __( 'Edit draft', 'newspack-ai-newsletter' ) }
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
								'No digest yet — Generate one, or run a FLUSH from the REPL.',
								'newspack-ai-newsletter'
							) }
						</p>
					) }
				</section>
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

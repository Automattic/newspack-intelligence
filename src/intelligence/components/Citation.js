import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Popover } from '@wordpress/components';
import { Core } from '@newspack-nodes/runtime';
import { dayLabel, safeHref, utcDay } from './format';
import { word } from './words';

/**
 * One source an answer's sentence cites: the marker `[n]`, a button named
 * "Source n: <title>", which a click shows in the source list. Hovering or
 * focusing it previews the source in a popover beside it: its title, its
 * source and day, its kind, its summary, **Open source** for an http or
 * https URL alone, and **Show in list**. The popover renders inline, so Tab
 * reaches its controls from the marker; leaving both, or Escape, closes it,
 * and focus moving within them opens nothing again.
 * Every string the graph holds renders as React text.
 *
 * @param {Object}              props
 * @param {number}              props.n      The source's number in the list.
 * @param {Object}              props.item   The source, as `search` answers an item.
 * @param {(n: number) => void} props.onShow Shows source `n` in the list.
 * @return {import('react').ReactElement} The marker.
 */
export default function Citation( { n, item, onShow } ) {
	const [ open, setOpen ] = useState( false );
	const wrapRef = useRef( null );
	const markerRef = useRef( null );
	const href = safeHref( item.url );
	const day = utcDay( item.item_ts );
	const named = sprintf(
		/* translators: %d: the source's number in the list. */
		__( 'Source %d', 'newspack-intelligence' ),
		n
	);
	const leaves = ( next ) => ! wrapRef.current?.contains( next );
	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions -- delegates its marker's and preview's hover and focus
		<span
			ref={ wrapRef }
			className="newspack-intelligence__citation"
			onMouseEnter={ () => setOpen( true ) }
			onMouseLeave={ () => setOpen( false ) }
			onFocus={ ( event ) =>
				leaves( event.relatedTarget ) && setOpen( true )
			}
			onBlur={ ( event ) =>
				leaves( event.relatedTarget ) && setOpen( false )
			}
			onKeyDown={ ( event ) => {
				if ( 'Escape' === event.key && open ) {
					event.stopPropagation();
					setOpen( false );
					if ( event.target !== markerRef.current ) {
						markerRef.current?.focus();
					}
				}
			} }
		>
			<button
				ref={ markerRef }
				type="button"
				className="button-link newspack-intelligence__marker"
				aria-label={ sprintf(
					/* translators: 1: the source's number, 2: its title. */
					__( 'Source %1$d: %2$s', 'newspack-intelligence' ),
					n,
					item.title
				) }
				onClick={ () => onShow( n ) }
			>
				{ `[${ n }]` }
			</button>
			{ open && (
				<Popover
					inline
					placement="bottom-start"
					focusOnMount={ false }
					className="newspack-intelligence__preview"
				>
					<div role="dialog" aria-label={ named }>
						<p className="newspack-intelligence__preview-title">
							{ item.title }
						</p>
						<p className="newspack-nodes-status is-muted newspack-intelligence__meta">
							<span>
								{ day
									? sprintf(
											/* translators: 1: the source's word, 2: its day. */
											__(
												'%1$s, %2$s',
												'newspack-intelligence'
											),
											word( 'sourceWords', item.source ),
											dayLabel(
												day,
												utcDay(
													Math.floor( Core.now() )
												)
											)
									  )
									: word( 'sourceWords', item.source ) }
							</span>
							{ item.kind && (
								<span className="newspack-nodes-status-badge is-neutral">
									{ word( 'kindWords', item.kind ) }
								</span>
							) }
						</p>
						{ item.summary && (
							<p className="newspack-intelligence__preview-summary">
								{ item.summary }
							</p>
						) }
						<p className="newspack-intelligence__actions">
							{ href && (
								<a
									href={ href }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'Open source',
										'newspack-intelligence'
									) }
								</a>
							) }
							<button
								type="button"
								className="button-link"
								onClick={ () => onShow( n ) }
							>
								{ __(
									'Show in list',
									'newspack-intelligence'
								) }
							</button>
						</p>
					</div>
				</Popover>
			) }
		</span>
	);
}

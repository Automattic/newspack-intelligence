import { useEffect, useId, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import { useIntelRead } from '../hooks/useIntelCommand';
import { AnswerBody } from './Answer';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import { actorNoun, dayLabel, reasonText, utcDay } from './format';
import { word } from './words';

/** The one question a kind check asks, as the trace names it. */
const KIND_QUESTION = 'fits_kind';

/**
 * A link step in words, by the state its judgment or the machine left it
 * in: the entity, the method that found it, and who judged it.
 *
 * @return {Object<string, (step: Object, method: string) => string>} Each state's line.
 */
const linkLines = () => ( {
	asserted: ( step, method ) =>
		sprintf(
			/* translators: 1: an entity's name, 2: how it was found, such as "its domain". */
			__( 'Linked to %1$s by %2$s', 'newspack-intelligence' ),
			step.name,
			method
		),
	proposed: ( step, method ) =>
		sprintf(
			/* translators: 1: an entity's name, 2: how it was found, such as "its domain". */
			__(
				'Suggested a link to %1$s by %2$s, not yet reviewed',
				'newspack-intelligence'
			),
			step.name,
			method
		),
	confirmed: ( step, method ) =>
		sprintf(
			/* translators: 1: an entity's name, 2: how it was found, 3: who confirmed it, such as "a curator". */
			__(
				'Linked to %1$s by %2$s, confirmed by %3$s',
				'newspack-intelligence'
			),
			step.name,
			method,
			actorNoun( step.judged.actor )
		),
	rejected: ( step, method ) =>
		sprintf(
			/* translators: 1: an entity's name, 2: how it was found, 3: who rejected it, such as "a curator". */
			__(
				'Linked to %1$s by %2$s, rejected by %3$s',
				'newspack-intelligence'
			),
			step.name,
			method,
			actorNoun( step.judged.actor )
		),
} );

/**
 * A theme as a sentence starts: its first letter capitalized.
 *
 * @param {string} theme The theme, as the extraction wrote it.
 * @return {string} The theme.
 */
const sentenceCase = ( theme ) =>
	theme.charAt( 0 ).toUpperCase() + theme.slice( 1 );

/**
 * An extraction in words: the kind it read, the theme it placed the item
 * in when it named one, then why the item ranks.
 *
 * @param {Object} step The `extracted` step.
 * @return {string} The line.
 */
function extractedLine( step ) {
	const kind = word( 'kindWords', step.kind );
	const read = step.theme
		? sprintf(
				/* translators: 1: a kind of news, 2: the theme it was placed in. */
				__(
					'Read as %1$s, in the theme “%2$s”.',
					'newspack-intelligence'
				),
				kind,
				sentenceCase( step.theme )
		  )
		: sprintf(
				/* translators: %s: a kind of news. */
				__( 'Read as %s.', 'newspack-intelligence' ),
				kind
		  );
	return `${ read } ${ reasonText( step.rank_reason ) }`;
}

/**
 * A kind check in words. A question the trace does not ask throws.
 *
 * @param {Object} step The `checked` step.
 * @return {string} The line.
 */
function checkedLine( step ) {
	if ( KIND_QUESTION !== step.question ) {
		throw new Error( `no words for the check "${ step.question }"` );
	}
	const format =
		'passed' === step.verdict
			? /* translators: %s: a kind of news. */
			  __( 'Checked whether it fits %s: yes', 'newspack-intelligence' )
			: /* translators: %s: a kind of news. */
			  __( 'Checked whether it fits %s: no', 'newspack-intelligence' );
	return sprintf( format, word( 'kindWords', step.kind ) );
}

/**
 * A cluster place in words: whether the item stands for its story, and
 * how many of the story's other copies the reader may read.
 *
 * @param {Object} step The `clustered` step.
 * @return {string} The line.
 */
function clusteredLine( step ) {
	if ( 0 === step.copies ) {
		return step.canonical
			? __( 'The main copy of its story', 'newspack-intelligence' )
			: __( 'A copy of another item', 'newspack-intelligence' );
	}
	const format = step.canonical
		? /* translators: %d: the story's other copies the reader may read. */
		  _n(
				'The main copy, merged with %d other',
				'The main copy, merged with %d others',
				step.copies,
				'newspack-intelligence'
		  )
		: /* translators: %d: the story's other copies the reader may read. */
		  _n(
				'A copy, merged with %d other',
				'A copy, merged with %d others',
				step.copies,
				'newspack-intelligence'
		  );
	return sprintf( format, step.copies );
}

/**
 * Each step the trace writes, as one line in words.
 *
 * @return {Object<string, (step: Object) => string>} Each step's line.
 */
const stepLines = () => ( {
	recorded: ( step ) =>
		sprintf(
			/* translators: 1: the source, such as "Linear", 2: who may read the item, such as "Internal". */
			__( 'Recorded from %1$s; marked %2$s', 'newspack-intelligence' ),
			word( 'sourceWords', step.source ),
			word( 'tierWords', step.tier )
		),
	linked: ( step ) => {
		const line = linkLines()[ step.state ];
		if ( ! line ) {
			throw new Error( `no words for the link state "${ step.state }"` );
		}
		return line( step, word( 'methodWords', step.method ) );
	},
	extracted: extractedLine,
	gated: () =>
		__(
			'Gated: about its own publisher’s journalism, so not extracted',
			'newspack-intelligence'
		),
	heuristic: () =>
		__(
			'Not read by the model: a fallback summary',
			'newspack-intelligence'
		),
	checked: checkedLine,
	clustered: clusteredLine,
} );

/**
 * One step in words. A step the trace does not write throws.
 *
 * @param {Object} step The step.
 * @return {string} The line.
 */
function lineOf( step ) {
	const line = stepLines()[ step.step ];
	if ( ! line ) {
		throw new Error( `no words for the step "${ step.step }"` );
	}
	return line( step );
}

/**
 * The drawer laying out how one item was read (`trace`): a heading taking
 * focus as it opens, the item's title, and each step of its record in the
 * order the pipeline took them, one line in words beside who took it and
 * its UTC day (`dayLabel()`), as the row's meta line dates the item. Close
 * and Escape close it.
 *
 * @param {Object}     props
 * @param {Object}     props.item    The item.
 * @param {() => void} props.onClose Closes the drawer.
 * @return {import('react').ReactElement} The drawer.
 */
export default function HowRead( { item, onClose } ) {
	const read = useIntelRead( `trace:${ useId() }`, 'trace', [
		item.item_key,
	] );
	const headingId = useId();
	const heading = useRef( null );
	useEffect( () => heading.current.focus(), [] );
	const today = utcDay( Math.floor( Core.now() ) );
	return (
		// eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions -- Escape bubbles up from the controls inside; the region takes no input itself.
		<div
			role="region"
			aria-labelledby={ headingId }
			className="newspack-intelligence__drawer newspack-intelligence__how-read"
			onKeyDown={ ( event ) => 'Escape' === event.key && onClose() }
		>
			<div className="newspack-intelligence__actions">
				<h4 id={ headingId } tabIndex={ -1 } ref={ heading }>
					{ __( 'How this was read', 'newspack-intelligence' ) }
				</h4>
				<button
					type="button"
					className="button button-small"
					onClick={ onClose }
				>
					{ __( 'Close', 'newspack-intelligence' ) }
				</button>
			</div>
			<p className="newspack-nodes-status is-muted">{ item.title }</p>
			<AnswerBody
				read={ read }
				skeleton={ <Skeleton shape="line" count={ 4 } /> }
			>
				{ ( answer ) => (
					<ol className="newspack-intelligence__steps">
						{ /* A step has no identity but its place in the record. */ }
						{ answer.steps.map( ( step, place ) => (
							<li key={ place }>
								<span className="newspack-intelligence__step-said">
									{ lineOf( step ) }
								</span>
								<span className="newspack-nodes-status is-muted newspack-intelligence__step-by">
									{ sprintf(
										/* translators: 1: who took the step, such as "a curator", 2: its day. */
										__(
											'By %1$s, %2$s',
											'newspack-intelligence'
										),
										actorNoun( step.actor ),
										dayLabel( utcDay( step.at ), today )
									) }
								</span>
							</li>
						) ) }
					</ol>
				) }
			</AnswerBody>
		</div>
	);
}

import { Card, CardBody, CardHeader } from '@wordpress/components';
import { createContext, useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import Skeleton from './Skeleton';
import { sinceLabel } from './format';

/**
 * Whether the reader is an operator, provided once by the page from its
 * `canManage`: a refusal's own words are for someone who can act on them. It
 * gates words the browser already holds, never what a read answers, and a
 * tree with no provider renders as it does for a reader.
 *
 * @type {import('react').Context<boolean>}
 */
export const CanManage = createContext( false );

/**
 * One read's answer in exactly one of its states: owed, refused, a store
 * that would not open, a graph holding no rows here, or the rows themselves.
 * The two kinds of nothing carry different words, because "the store did
 * not open" and "the graph holds nothing" call for different responses. An
 * answer remembered from an earlier read says when it was read while the
 * fresh one is owed.
 *
 * @param {Object}                                                                                                          props
 * @param {{answer: ?Object, error: ?string, loading: boolean, stale?: boolean, updatedAt?: ?number, refresh?: () => void}} props.read       What `useIntelRead` returned.
 * @param {string|string[]}                                                                                                 [props.rows]     The answer's row lists, empty when every one is; an answer without one is never empty.
 * @param {import('react').ReactNode}                                                                                       [props.empty]    What an answer with no rows says, and any control inviting what to do.
 * @param {import('react').ReactNode}                                                                                       [props.skeleton] What stands in while the answer is owed; three item rows when omitted.
 * @param {(answer: Object) => import('react').ReactNode}                                                                   props.children   Renders the rows.
 * @return {import('react').ReactNode} The answer's state.
 */
export function AnswerBody( { read, rows, empty, skeleton, children } ) {
	const { answer, error, loading, stale, updatedAt, refresh } = read;
	if ( error ) {
		return <Unread error={ error } refresh={ refresh } />;
	}
	if ( loading ) {
		return skeleton ?? <Skeleton />;
	}
	return (
		<>
			<Rows answer={ answer } rows={ rows } empty={ empty }>
				{ children }
			</Rows>
			{ stale && (
				<p className="newspack-nodes-status is-muted">
					{ sprintf(
						/* translators: %s: the time the answer shown was read. */
						__( 'Updated %s', 'newspack-intelligence' ),
						sinceLabel( updatedAt, Core.now() )
					) }
				</p>
			) }
		</>
	);
}

/**
 * An answer in hand: a store that would not open, no rows, or the rows.
 *
 * @param {Object}                                        props
 * @param {Object}                                        props.answer   The answer.
 * @param {string|string[]}                               [props.rows]   Its row lists.
 * @param {import('react').ReactNode}                     [props.empty]  What no rows says.
 * @param {(answer: Object) => import('react').ReactNode} props.children Renders the rows.
 * @return {import('react').ReactNode} What the answer holds.
 */
function Rows( { answer, rows, empty, children } ) {
	if ( false === answer.available ) {
		return (
			<p className="newspack-nodes-banner is-warning">
				{ __(
					'The intelligence store did not open, so this was not read. An administrator can check the status above.',
					'newspack-intelligence'
				) }
			</p>
		);
	}
	if (
		rows &&
		[].concat( rows ).every( ( key ) => 0 === answer[ key ].length )
	) {
		return <p className="newspack-nodes-empty-state is-quiet">{ empty }</p>;
	}
	return children( answer );
}

/**
 * A read that was refused or failed, or nothing when it was not: one
 * sentence and a way to ask again, and for an operator the refusal's own
 * words beneath, muted. It is a polite status, because nothing the reader
 * did failed; a refused write stays an alert (`Refusal`).
 *
 * @param {Object}     props
 * @param {?string}    props.error   The refusal.
 * @param {() => void} props.refresh Asks the read again.
 * @return {?import('react').ReactElement} The status.
 */
export function Unread( { error, refresh } ) {
	const operator = useContext( CanManage );
	if ( ! error ) {
		return null;
	}
	return (
		<div className="newspack-nodes-error-banner" role="status">
			<p>
				{ __(
					'Intelligence could not be read just now. Try again.',
					'newspack-intelligence'
				) }{ ' ' }
				<button type="button" className="button" onClick={ refresh }>
					{ __( 'Try again', 'newspack-intelligence' ) }
				</button>
			</p>
			{ operator && (
				<p className="newspack-nodes-status is-muted">{ error }</p>
			) }
		</div>
	);
}

/**
 * One read as a card: a section, named by `title` and headed by it in the
 * card's header band when given, around the read's state inset in the card's
 * body, `AnswerBody` taking every other prop. The stock card is the one the
 * shared `newspack-nodes-ui` sheet paints, as on every Event Logger dashboard.
 *
 * @param {Object}                                        props
 * @param {string}                                        [props.title]    Names and heads the section.
 * @param {number}                                        [props.level]    The heading's level.
 * @param {Object}                                        props.read       What `useIntelRead` returned.
 * @param {string|string[]}                               [props.rows]     The answer's row lists.
 * @param {import('react').ReactNode}                     [props.empty]    What an answer with no rows says.
 * @param {import('react').ReactNode}                     [props.skeleton] What stands in while it is owed.
 * @param {(answer: Object) => import('react').ReactNode} props.children   Renders the rows.
 * @return {import('react').ReactElement} The card.
 */
export default function Answer( { title, level = 2, ...body } ) {
	const Heading = /** @type {'h2' | 'h3' | 'h4'} */ ( `h${ level }` );
	return (
		<Card as="section" aria-label={ title }>
			{ title && (
				<CardHeader>
					<Heading>{ title }</Heading>
				</CardHeader>
			) }
			<CardBody>
				<AnswerBody { ...body } />
			</CardBody>
		</Card>
	);
}

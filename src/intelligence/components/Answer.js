import { Card, CardBody, CardHeader } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Refusal from './Refusal';

/**
 * One read's answer in exactly one of its states: owed, refused, a graph
 * that would not open, a graph holding no rows here, or the rows themselves.
 * The two kinds of nothing carry different words, because "the graph could
 * not be read" and "the graph holds nothing" call for different responses.
 *
 * @param {Object}                                              props
 * @param {{answer: ?Object, error: ?string, loading: boolean}} props.read     What `useIntelRead` returned.
 * @param {string}                                              [props.rows]   The answer's row list; an answer without one is never empty.
 * @param {string}                                              [props.empty]  What an answer with no rows says.
 * @param {(answer: Object) => import('react').ReactNode}       props.children Renders the rows.
 * @return {import('react').ReactNode} The answer's state.
 */
export function AnswerBody( { read, rows, empty, children } ) {
	const { answer, error, loading } = read;
	if ( error ) {
		return <Refusal error={ error } />;
	}
	if ( loading ) {
		return (
			<p className="newspack-nodes-status is-muted" role="status">
				{ __( 'Loading…', 'newspack-intelligence' ) }
			</p>
		);
	}
	if ( false === answer.available ) {
		return (
			<p className="newspack-nodes-banner is-warning">
				{ __(
					'The intelligence graph could not be opened, so this was not read.',
					'newspack-intelligence'
				) }
			</p>
		);
	}
	if ( rows && 0 === answer[ rows ].length ) {
		return <p className="newspack-nodes-empty-state is-quiet">{ empty }</p>;
	}
	return children( answer );
}

/**
 * One read as a card: a section, named by `title` and headed by it in the
 * card's header band when given, around the read's state inset in the card's
 * body, `AnswerBody` taking every other prop. The stock card is the one the
 * shared `newspack-nodes-ui` sheet paints, as on every Event Logger dashboard.
 *
 * @param {Object}                                              props
 * @param {string}                                              [props.title]  Names and heads the section.
 * @param {number}                                              [props.level]  The heading's level.
 * @param {{answer: ?Object, error: ?string, loading: boolean}} props.read     What `useIntelRead` returned.
 * @param {string}                                              [props.rows]   The answer's row list.
 * @param {string}                                              [props.empty]  What an answer with no rows says.
 * @param {(answer: Object) => import('react').ReactNode}       props.children Renders the rows.
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

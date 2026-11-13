/**
 * The `since` block, the Briefing's lede: what arrived since the reader last
 * looked, as one sentence naming each kind the block counts, each phrase
 * opening Search on its kind, among how many items arrived.
 */

import { CheckboxControl } from '@wordpress/components';
import { createInterpolateElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Core } from '@newspack-nodes/runtime';
import { AnswerBody } from '../components/Answer';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import Section from '@newspack-nodes/shared/components/Section';
import { pageFormat, sinceLabel, utcDay } from '../components/format';
import { word } from '../components/words';
import { useIntelActions, useIntelPage } from '../layout/PageContext';
import { useChanges } from './changes';

/** The kinds whose phrase counts the clients their items are about. */
const CLIENT_KINDS = [ 'churned', 'churn_signal' ];

/**
 * Each kind's phrase for its count, in the order the lede counts them.
 *
 * @return {Object<string, (n: number) => string>} The phrases.
 */
const phrases = () => ( {
	churned: ( n ) =>
		/* translators: %d: clients. */
		_n(
			'%d client started offboarding',
			'%d clients started offboarding',
			n,
			'newspack-intelligence'
		),
	churn_signal: ( n ) =>
		/* translators: %d: clients. */
		_n(
			'%d client showed a churn signal',
			'%d clients showed churn signals',
			n,
			'newspack-intelligence'
		),
	incident: ( n ) =>
		/* translators: %d: incidents. */
		_n(
			'%d incident was reported',
			'%d incidents were reported',
			n,
			'newspack-intelligence'
		),
	bug: ( n ) =>
		/* translators: %d: bugs. */
		_n(
			'%d bug was filed',
			'%d bugs were filed',
			n,
			'newspack-intelligence'
		),
	launch: ( n ) =>
		/* translators: %d: launches. */
		_n(
			'%d launch went live',
			'%d launches went live',
			n,
			'newspack-intelligence'
		),
	funding: ( n ) =>
		/* translators: %d: funding announcements. */
		_n(
			'%d funding round was announced',
			'%d funding rounds were announced',
			n,
			'newspack-intelligence'
		),
	award: ( n ) =>
		/* translators: %d: awards. */
		_n(
			'%d award was announced',
			'%d awards were announced',
			n,
			'newspack-intelligence'
		),
	feature_request: ( n ) =>
		/* translators: %d: feature requests. */
		_n(
			'%d feature request came in',
			'%d feature requests came in',
			n,
			'newspack-intelligence'
		),
	complaint: ( n ) =>
		/* translators: %d: complaints. */
		_n(
			'%d complaint came in',
			'%d complaints came in',
			n,
			'newspack-intelligence'
		),
} );

/**
 * The lede: each counted kind's phrase, joined as a list in the page's
 * language, each a button opening Search on its kind since the instant
 * counted, among how many items arrived; or, when the summary counts no
 * item, that nothing is new.
 *
 * @param {Object}                    props
 * @param {Object}                    props.summary     The `changes` answer's summary.
 * @param {string[]}                  props.kinds       The kinds the block counts.
 * @param {string}                    props.label       The instant counted from, named.
 * @param {(filters: Object) => void} props.onSearchFor Opens Search on filters.
 * @return {import('react').ReactElement} The lede.
 */
function Lede( { summary, kinds, label, onSearchFor } ) {
	if ( 0 === summary.items ) {
		return (
			<p className="newspack-nodes-status is-muted">
				{ sprintf(
					/* translators: %s: when the reader last looked, e.g. "Tuesday at 14:31". */
					__( 'Nothing new since %s.', 'newspack-intelligence' ),
					label
				) }
			</p>
		);
	}
	const words = phrases();
	const since = utcDay( summary.since );
	const counted = summary.kinds
		.filter( ( { kind } ) => kinds.includes( kind ) )
		.map( ( { kind, items, clients } ) => ( {
			kind,
			n: CLIENT_KINDS.includes( kind ) ? clients : items,
		} ) )
		.filter( ( { n } ) => 0 < n );
	const links = counted.map( ( { kind, n } ) => (
		<button
			key={ kind }
			type="button"
			className="button-link"
			onClick={ () => onSearchFor( { kind, since } ) }
		>
			{ sprintf( words[ kind ]( n ), n ) }
		</button>
	) );
	let next = 0;
	const list = pageFormat( Intl.ListFormat, { type: 'conjunction' } )
		.formatToParts( counted.map( ( { kind } ) => kind ) )
		.map( ( part ) =>
			'element' === part.type ? links[ next++ ] : part.value
		);
	const total = pageFormat( Intl.NumberFormat ).format( summary.items );
	return (
		<p className="newspack-intelligence__lede">
			{ 0 === counted.length
				? sprintf(
						/* translators: %s: how many items arrived. */
						__( '%s new items.', 'newspack-intelligence' ),
						total
				  )
				: createInterpolateElement(
						sprintf(
							/* translators: 1: what arrived, as a list, 2: how many items arrived. */
							__(
								'%1$s, among %2$s new items.',
								'newspack-intelligence'
							),
							'<list />',
							total
						),
						{ list: <>{ list }</> }
				  ) }
		</p>
	);
}

/**
 * @param {Object}            props
 * @param {{kinds: string[]}} props.attributes Its attributes.
 * @return {?import('react').ReactElement} The lede, or nothing where the browser keeps no last look.
 */
function View( { attributes: { kinds } } ) {
	const { since, kept } = useIntelPage();
	const { onSearchFor } = useIntelActions();
	const read = useChanges( 'briefing:since', [] );
	if ( false === kept ) {
		return null;
	}
	const label = sinceLabel(
		read.answer?.summary?.since ?? since ?? Core.now(),
		Core.now()
	);
	return (
		<Section
			heading={
				null === since
					? __( 'This week', 'newspack-intelligence' )
					: sprintf(
							/* translators: %s: when the reader last looked, e.g. "Tuesday at 14:31". */
							__( 'Since %s', 'newspack-intelligence' ),
							label
					  )
			}
		>
			<AnswerBody
				read={ read }
				skeleton={ <Skeleton shape="line" count={ 2 } /> }
			>
				{ ( answer ) => (
					<Lede
						summary={ answer.summary }
						kinds={ kinds }
						label={ label }
						onSearchFor={ onSearchFor }
					/>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object}                            props
 * @param {{kinds: string[]}}                 props.attributes    Its attributes.
 * @param {(next: {kinds: string[]}) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} A checkbox per kind, in the lede's order.
 */
function Controls( { attributes: { kinds }, setAttributes } ) {
	const order = Object.keys( phrases() );
	return (
		<>
			{ order.map( ( kind ) => (
				<CheckboxControl
					key={ kind }
					__nextHasNoMarginBottom
					label={ word( 'kindWords', kind ) }
					checked={ kinds.includes( kind ) }
					onChange={ ( on ) =>
						setAttributes( {
							kinds: order.filter( ( one ) =>
								one === kind ? on : kinds.includes( one )
							),
						} )
					}
				/>
			) ) }
		</>
	);
}

export default { View, Controls };

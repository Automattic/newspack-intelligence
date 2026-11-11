/**
 * The `needs-attention` block: every client carrying a churn-risk note, by
 * its note, then every signal of every client and team view the block
 * admits, the most recent first, each a pulse line opening the profile or
 * the team view it is about, at most `max` until the rest are asked for. A
 * client's signal is worded by the week, and a team view's by the span the
 * view counts by, as `lenses` lists it.
 */

import {
	Icon,
	RangeControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { chevronRight } from '@wordpress/icons';
import { Core } from '@newspack-nodes/runtime';
import { AnswerBody } from '../components/Answer';
import { noteText } from '../components/ChurnNote';
import LensTokens from '../components/LensTokens';
import PulseLine, { spanWords } from '../components/PulseLine';
import Section from '../components/Section';
import Skeleton from '../components/Skeleton';
import { signalValue } from '../components/TrendTable';
import { useIntelRead } from '../hooks/useIntelCommand';
import { usePageRead } from '../hooks/usePageRead';
import { useIntelActions } from '../layout/PageContext';

/** The span a client's signals count by, and one a view names none by. */
const SPAN = 'week';

/** The buckets before the latest every signal is judged against. */
const BASELINE = 8;

/** The page processes whose first pulse lines already faded in. */
const faded = new WeakSet();

/**
 * Whether this paint fades the latest points in: only the page's first, and
 * never where the reader asks for less motion.
 *
 * @return {boolean} Whether it fades.
 */
function useFirstPaint() {
	const [ fades ] = useState( () => {
		const first = ! faded.has( Core.registry );
		faded.add( Core.registry );
		return (
			first &&
			! window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
		);
	} );
	return fades;
}

/**
 * @typedef {Object} AttentionAttributes
 * @property {string}   heading The heading.
 * @property {number}   max     The rows shown before "Show %d more".
 * @property {boolean}  clients Whether clients' signals are listed.
 * @property {string[]} lenses  The team views listed; none lists every one.
 */

/**
 * Each noted client the block admits, as a row, then each signal, the most
 * recent first.
 *
 * @param {Object}                 answer     The `briefing signals` answer.
 * @param {AttentionAttributes}    attributes The block's attributes.
 * @param {Object}                 actions    The page's actions.
 * @param {Object<string, string>} spans      Each team view's span, by key.
 * @return {Array<{key: string, name: string, open: () => void, span?: string, signal?: Object, note?: Object}>} The rows.
 */
function rowsOf( answer, { clients, lenses }, actions, spans ) {
	const noted = ( clients ? answer.clients : [] )
		.filter( ( client ) => client.churn_note )
		.map( ( client ) => ( {
			key: `${ client.entity_key } note`,
			name: client.name,
			open: () => actions.onOpenEntity( client.entity_key ),
			note: client.churn_note,
		} ) );
	const rows = [
		...( clients ? answer.clients : [] ).flatMap( ( client ) =>
			client.signals.map( ( signal, n ) => ( {
				key: `${ client.entity_key } ${ n }`,
				name: client.name,
				open: () => actions.onOpenEntity( client.entity_key ),
				span: SPAN,
				signal,
			} ) )
		),
		...answer.lenses
			.filter(
				( lens ) =>
					0 === lenses.length || lenses.includes( lens.lens_key )
			)
			.flatMap( ( lens ) =>
				lens.signals.map( ( signal, n ) => ( {
					key: `${ lens.lens_key } ${ n }`,
					name: lens.name,
					open: () => actions.onOpenLens( lens.lens_key ),
					span: spans[ lens.lens_key ] ?? SPAN,
					signal,
				} ) )
			),
	];
	return [
		...noted,
		...rows.sort( ( a, b ) =>
			b.signal.bucket.localeCompare( a.signal.bucket )
		),
	];
}

/**
 * @param {Object}                 props
 * @param {Object}                 props.answer     The `briefing signals` answer.
 * @param {AttentionAttributes}    props.attributes The block's attributes.
 * @param {Object<string, string>} props.spans      Each team view's span, by key.
 * @return {import('react').ReactElement} The rows, or why there are none.
 */
function Rows( { answer, attributes, spans } ) {
	const actions = useIntelActions();
	const fades = useFirstPaint();
	const [ all, setAll ] = useState( false );
	const rows = rowsOf( answer, attributes, actions, spans );
	if ( 0 === rows.length ) {
		return (
			<p className="newspack-nodes-empty-state is-quiet">
				{ answer.judged
					? __(
							'No client or team view moved off its usual range last week.',
							'newspack-intelligence'
					  )
					: __(
							'Too little history to judge changes yet. Signals start once a view has nine weeks.',
							'newspack-intelligence'
					  ) }
			</p>
		);
	}
	const shown = all ? rows : rows.slice( 0, attributes.max );
	return (
		<>
			<ul className="newspack-intelligence__attention">
				{ shown.map( ( row ) => {
					const subject = row.signal ? signalValue( row.signal ) : '';
					return (
						<li
							key={ row.key }
							className={ fades ? 'is-fading-in' : undefined }
						>
							{ row.note ? (
								<p className="newspack-intelligence__churn-note">
									{ noteText( row.note ) }
								</p>
							) : (
								<PulseLine
									signal={ row.signal }
									subject={
										subject.charAt( 0 ).toUpperCase() +
										subject.slice( 1 )
									}
									trend={ row.signal.trend }
									partial={ null }
									judged
									several={ false }
									words={ spanWords( row.span, BASELINE ) }
								/>
							) }
							<button
								type="button"
								className="button-link"
								onClick={ row.open }
							>
								{ row.name }
								<Icon icon={ chevronRight } size={ 16 } />
							</button>
						</li>
					);
				} ) }
			</ul>
			{ shown.length < rows.length && (
				<button
					type="button"
					className="button-link"
					onClick={ () => setAll( true ) }
				>
					{ sprintf(
						/* translators: %d: rows not yet shown. */
						__( 'Show %d more', 'newspack-intelligence' ),
						rows.length - shown.length
					) }
				</button>
			) }
		</>
	);
}

/**
 * @param {Object}              props
 * @param {AttentionAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const read = useIntelRead( 'briefing:signals', 'briefing', [ 'signals' ] );
	const lenses = usePageRead( 'lenses' );
	const spans = Object.fromEntries(
		( lenses.answer?.lenses ?? [] )
			.filter( ( lens ) => lens.filters.bucket )
			.map( ( lens ) => [ lens.lens_key, lens.filters.bucket ] )
	);
	return (
		<Section heading={ attributes.heading }>
			<AnswerBody
				read={ lenses.loading ? { ...read, loading: true } : read }
				skeleton={ <Skeleton shape="line" count={ 3 } /> }
			>
				{ ( answer ) => (
					<Rows
						answer={ answer }
						attributes={ attributes }
						spans={ spans }
					/>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object}                                       props
 * @param {AttentionAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<AttentionAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Heading', 'newspack-intelligence' ) }
				value={ attributes.heading }
				onChange={ ( heading ) => setAttributes( { heading } ) }
			/>
			<RangeControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Rows shown', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 20 }
				value={ attributes.max }
				onChange={ ( max ) => setAttributes( { max } ) }
			/>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __( 'Include clients', 'newspack-intelligence' ) }
				checked={ attributes.clients }
				onChange={ ( clients ) => setAttributes( { clients } ) }
			/>
			<LensTokens
				label={ __(
					'Team views (none lists every one)',
					'newspack-intelligence'
				) }
				value={ attributes.lenses }
				onChange={ ( lenses ) => setAttributes( { lenses } ) }
			/>
		</>
	);
}

export default { View, Controls };

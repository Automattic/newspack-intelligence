/**
 * The `trend-chart` block: the page's subject, the team view or the
 * profile's entity, counted per bucket over the last weeks, the bucket still
 * running apart, by the axis and span the block names, else those the view
 * sets.
 */

import { SelectControl, TextControl } from '@wordpress/components';
import { useContext } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import Section from '../components/Section';
import { TrendChart, chartTitle } from '../components/TrendTable';
import { words } from '../components/words';
import { commandArgs, useIntelRead } from '../hooks/useIntelCommand';
import { IntelPageContext } from '../layout/PageContext';

/** The chart's skeleton height in pixels, the chart's own. */
const SKELETON_PX = 180;

/**
 * @typedef {Object} TrendChartAttributes
 * @property {string}          heading The title, `%s` the axis.
 * @property {string}          by      The axis; '' for the axis the view sets.
 * @property {string}          bucket  The span; '' for the span the view sets.
 * @property {'stack'|'lines'} style   How the series draw at first.
 */

/**
 * What the chart reads through: the profile's entity, else the team view.
 *
 * @param {import('../layout/PageContext').IntelPage} page The page.
 * @return {Object<string, ?string>} The subject's option.
 */
const subjectOf = ( page ) =>
	'entity' === page.page
		? { entity: page.entity_key }
		: { lens: page.lens_key };

/**
 * What an empty chart says: nothing over the days the view looks back, or
 * nothing yet where it looks back over everything.
 *
 * @param {?number} days The view's days, or null.
 * @return {string} The sentence.
 */
const emptySentence = ( days ) =>
	days
		? sprintf(
				/* translators: %d: how many days the view looks back. */
				__(
					'Nothing in this view in the last %d days.',
					'newspack-intelligence'
				),
				days
		  )
		: __( 'Nothing in this view yet.', 'newspack-intelligence' );

/**
 * @param {Object}               props
 * @param {TrendChartAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes: { heading, by, bucket, style } } ) {
	const page = useContext( IntelPageContext );
	const read = useIntelRead(
		`trend-chart:${ by }:${ bucket }`,
		'trends',
		commandArgs( { ...subjectOf( page ), by, bucket } )
	);
	const { answer } = read;
	if ( ! read.error && 0 < ( answer?.rows?.length ?? 0 ) ) {
		return (
			<div className="newspack-intelligence__section">
				<TrendChart
					answer={ answer }
					heading={ heading }
					style={ style }
				/>
			</div>
		);
	}
	const axis = answer?.by || by || page.lens?.filters.by;
	const body = (
		<AnswerBody
			read={ read }
			rows="rows"
			empty={ emptySentence( page.lens?.filters.days ?? null ) }
			skeleton={
				<div
					role="status"
					aria-label={ __( 'Loading', 'newspack-intelligence' ) }
				>
					<div
						className="newspack-nodes-skeleton"
						style={ { height: `${ SKELETON_PX }px` } }
						aria-hidden="true"
					/>
				</div>
			}
		>
			{ () => null }
		</AnswerBody>
	);
	return axis ? (
		<Section heading={ chartTitle( heading, axis ) }>{ body }</Section>
	) : (
		<div className="newspack-intelligence__section">{ body }</div>
	);
}

/**
 * A select's options over a vocabulary map, led by the view's own.
 *
 * @param {string} map The vocabulary map.
 * @return {{label: string, value: string}[]} The options.
 */
const choicesOf = ( map ) => [
	{ label: __( 'As the view sets it', 'newspack-intelligence' ), value: '' },
	...Object.entries( words( map ) ).map( ( [ value, label ] ) => ( {
		label,
		value,
	} ) ),
];

/**
 * @param {Object}                                        props
 * @param {TrendChartAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<TrendChartAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	const shared = {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
	};
	return (
		<>
			<TextControl
				{ ...shared }
				label={ __( 'Heading', 'newspack-intelligence' ) }
				value={ attributes.heading }
				onChange={ ( heading ) => setAttributes( { heading } ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Chart by', 'newspack-intelligence' ) }
				options={ choicesOf( 'axisWords' ) }
				value={ attributes.by }
				onChange={ ( value ) => setAttributes( { by: value } ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Group by', 'newspack-intelligence' ) }
				options={ choicesOf( 'spanWords' ) }
				value={ attributes.bucket }
				onChange={ ( bucket ) => setAttributes( { bucket } ) }
			/>
			<SelectControl
				{ ...shared }
				label={ __( 'Draw as', 'newspack-intelligence' ) }
				options={ [
					{
						label: __( 'Stacked', 'newspack-intelligence' ),
						value: 'stack',
					},
					{
						label: __( 'Lines', 'newspack-intelligence' ),
						value: 'lines',
					},
				] }
				value={ attributes.style }
				onChange={ ( style ) => setAttributes( { style } ) }
			/>
		</>
	);
}

export default { View, Controls };

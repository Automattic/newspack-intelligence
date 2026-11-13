/**
 * The `pulse` block: the pulse line of the page's subject, the profile's
 * entity or the team view's lens, and on the Briefing of the lens it names.
 */

import { formatCommandArgs } from '@newspack-nodes/runtime';
import { useId } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import LensTokens from '../components/LensTokens';
import PulseLine from '../components/PulseLine';
import Skeleton from '@newspack-nodes/shared/components/Skeleton';
import { pulseOf } from '../components/TrendTable';
import { useIntelRead } from '../hooks/useIntelCommand';
import { useIntelPage } from '../layout/PageContext';

/**
 * @typedef {Object} PulseAttributes
 * @property {string} lens The team view read on the Briefing.
 */

/**
 * The `signals` tokens the page's subject reads, or null for none.
 *
 * @param {import('../layout/PageContext').IntelPage} page The page.
 * @param {string}                                    lens The block's lens.
 * @return {?string[]} The tokens.
 */
function signalsArgs( page, lens ) {
	if ( 'entity' === page.page ) {
		return page.entity_key
			? formatCommandArgs( [], { entity: page.entity_key } )
			: null;
	}
	const key = 'lens' === page.page ? page.lens_key : lens;
	return key ? formatCommandArgs( [], { lens: key } ) : null;
}

/**
 * @param {Object}          props
 * @param {PulseAttributes} props.attributes Its attributes.
 * @return {?import('react').ReactElement} The block.
 */
function View( { attributes } ) {
	const args = signalsArgs( useIntelPage(), attributes.lens );
	const read = useIntelRead( `block:pulse:${ useId() }`, 'signals', args );
	if ( null === args ) {
		return null;
	}
	return (
		<AnswerBody
			read={ read }
			skeleton={ <Skeleton shape="line" count={ 1 } /> }
		>
			{ ( answer ) => <PulseLine { ...pulseOf( answer ) } /> }
		</AnswerBody>
	);
}

/**
 * @param {Object}                                   props
 * @param {PulseAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<PulseAttributes>) => void} props.setAttributes Writes them.
 * @return {import('react').ReactElement} The controls.
 */
function Controls( { attributes, setAttributes } ) {
	return (
		<LensTokens
			label={ __(
				'Team view (on the Briefing)',
				'newspack-intelligence'
			) }
			value={ attributes.lens ? [ attributes.lens ] : [] }
			max={ 1 }
			onChange={ ( [ lens = '' ] ) => setAttributes( { lens } ) }
		/>
	);
}

export default { View, Controls };

/**
 * The `watches` block: the head of the reader's watches, each with what is
 * new in it, and **New watch**.
 */

import { RangeControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { AnswerBody } from '../components/Answer';
import Section, { Invitation } from '../components/Section';
import Skeleton from '../components/Skeleton';
import WatchRow from '../components/WatchRow';
import { usePageRead } from '../hooks/usePageRead';
import { useIntelActions, useWriteControl } from '../layout/PageContext';

/**
 * @typedef {Object} WatchesAttributes
 * @property {string} heading The heading.
 * @property {number} max     The most watches listed.
 */

/**
 * @param {Object}            props
 * @param {WatchesAttributes} props.attributes Its attributes.
 * @return {import('react').ReactElement} The block.
 */
function View( { attributes: { heading, max } } ) {
	const { onOpenWatch, onNewWatch } = useIntelActions();
	const newWatch = useWriteControl( onNewWatch );
	const read = usePageRead( 'watches' );
	const label = __( 'New watch', 'newspack-intelligence' );
	return (
		<Section
			heading={ heading }
			controls={
				<button type="button" className="button-link" { ...newWatch }>
					{ label }
				</button>
			}
		>
			<AnswerBody
				read={ read }
				rows="watches"
				skeleton={ <Skeleton count={ 3 } /> }
				empty={
					<Invitation
						text={ __(
							'Watch a client, a team view or a folder to see its new items here.',
							'newspack-intelligence'
						) }
						label={ label }
						{ ...newWatch }
					/>
				}
			>
				{ ( answer ) => (
					<ul className="newspack-intelligence__watch-rows">
						{ answer.watches.slice( 0, max ).map( ( watch ) => (
							<WatchRow
								key={ watch.watch_key }
								watch={ watch }
								count
								onOpen={ onOpenWatch }
							/>
						) ) }
					</ul>
				) }
			</AnswerBody>
		</Section>
	);
}

/**
 * @param {Object}                                     props
 * @param {WatchesAttributes}                          props.attributes    Its attributes.
 * @param {(next: Partial<WatchesAttributes>) => void} props.setAttributes Writes them.
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
				label={ __( 'Watches shown', 'newspack-intelligence' ) }
				min={ 1 }
				max={ 20 }
				value={ attributes.max }
				onChange={ ( max ) => setAttributes( { max } ) }
			/>
		</>
	);
}

export default { View, Controls };

/**
 * One block's part of a composed page's one surface: a region named and
 * headed by its heading, with any control the block keeps beside it. In a
 * layout a reader reads, the heading's id leads with its block's jump scope,
 * so a profile's jump link reaches it by its place.
 */

import { useContext, useId } from '@wordpress/element';
import { JumpScope, jumpId } from '../layout/PageContext';

/**
 * @param {Object}                    props
 * @param {string}                    props.heading    The heading, plain text.
 * @param {import('react').ReactNode} [props.controls] Controls beside the heading.
 * @param {import('react').ReactNode} props.children   The block's body.
 * @return {import('react').ReactElement} The section.
 */
export default function Section( { heading, controls = null, children } ) {
	const own = useId();
	const scope = useContext( JumpScope );
	const id = null === scope ? own : jumpId( scope, heading );
	return (
		<section
			className="newspack-intelligence__section"
			aria-labelledby={ id }
		>
			<div className="newspack-intelligence__section-head">
				<h2 id={ id }>{ heading }</h2>
				{ controls }
			</div>
			{ children }
		</section>
	);
}

/**
 * An empty section's words and the one control that acts on them.
 *
 * @param {{text: string, label: string, onClick: () => void, 'aria-disabled'?: true}} props
 *                                                                                           What the section found and what to do, the control's label, what it
 *                                                                                           does, and `aria-disabled` while it is inert (`useWriteControl`).
 * @return {import('react').ReactElement} The invitation.
 */
export function Invitation( { text, label, ...control } ) {
	return (
		<>
			{ text }{ ' ' }
			<button type="button" className="button" { ...control }>
				{ label }
			</button>
		</>
	);
}

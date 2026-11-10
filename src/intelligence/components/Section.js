/**
 * One block's part of a composed page's one surface: a region named and
 * headed by its heading, with any control the block keeps beside it.
 */

import { useId } from '@wordpress/element';

/**
 * @param {Object}                    props
 * @param {string}                    props.heading    The heading, plain text.
 * @param {import('react').ReactNode} [props.controls] Controls beside the heading.
 * @param {import('react').ReactNode} props.children   The block's body.
 * @return {import('react').ReactElement} The section.
 */
export default function Section( { heading, controls = null, children } ) {
	const id = useId();
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
 * @param {Object}     props
 * @param {string}     props.text    What the section found, and what to do.
 * @param {string}     props.label   The control's label.
 * @param {() => void} props.onClick What the control does.
 * @return {import('react').ReactElement} The invitation.
 */
export function Invitation( { text, label, onClick } ) {
	return (
		<>
			{ text }{ ' ' }
			<button type="button" className="button" onClick={ onClick }>
				{ label }
			</button>
		</>
	);
}

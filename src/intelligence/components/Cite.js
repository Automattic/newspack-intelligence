import { Icon, closeSmall } from '@wordpress/icons';
import { safeHref } from './format';

/**
 * One item cited by an answer: its title linking to its URL when that URL
 * is http or https, else the title as text.
 *
 * @param {Object}  props
 * @param {?string} props.url   The item's URL.
 * @param {?string} props.title The item's title; the URL stands in without one.
 * @return {import('react').ReactElement} The citation.
 */
export default function Cite( { url, title } ) {
	const href = safeHref( url );
	const text = title || url || '';
	return href ? (
		<a href={ href } target="_blank" rel="noopener noreferrer">
			{ text }
		</a>
	) : (
		<span>{ text }</span>
	);
}

/**
 * One entity an answer names, as the pill opening it: the shared status
 * badge's `is-pill is-info` rather than a button's box, its name, or its key where the graph holds no name, cut
 * short by its width alone, so its accessible name stays whole. Given
 * `onRemove`, the pill holds two buttons, the name opening the entity and an
 * × beside it, named `removeLabel`, removing it.
 *
 * @param {Object}                props
 * @param {string}                props.entityKey     The entity's key.
 * @param {?string}               props.name          Its name.
 * @param {(key: string) => void} props.onOpen        Opens it.
 * @param {string}                [props.description] Its tooltip.
 * @param {(key: string) => void} [props.onRemove]    Removes it; absent for a pill that only opens.
 * @param {string}                [props.removeLabel] What the × says it removes.
 * @return {import('react').ReactElement} The chip.
 */
export function EntityChip( {
	entityKey,
	name,
	onOpen,
	description,
	onRemove,
	removeLabel,
} ) {
	const pill =
		'newspack-nodes-status-badge is-pill is-info newspack-intelligence__pill';
	const label = (
		<span className="newspack-intelligence__pill-name">
			{ name || entityKey }
		</span>
	);
	if ( ! onRemove ) {
		return (
			<button
				type="button"
				className={ pill }
				title={ description }
				onClick={ () => onOpen( entityKey ) }
			>
				{ label }
			</button>
		);
	}
	return (
		<span className={ `${ pill } is-removable` }>
			<button
				type="button"
				className="newspack-intelligence__pill-open"
				title={ description }
				onClick={ () => onOpen( entityKey ) }
			>
				{ label }
			</button>
			<button
				type="button"
				className="newspack-intelligence__pill-remove"
				aria-label={ removeLabel }
				title={ removeLabel }
				onClick={ () => onRemove( entityKey ) }
			>
				<Icon icon={ closeSmall } size={ 16 } />
			</button>
		</span>
	);
}

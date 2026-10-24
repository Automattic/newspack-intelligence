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
 * One entity an answer names, as the chip opening it: its name, or its key
 * where the graph holds no name.
 *
 * @param {Object}                props
 * @param {string}                props.entityKey The entity's key.
 * @param {?string}               props.name      Its name.
 * @param {(key: string) => void} props.onOpen    Opens it.
 * @return {import('react').ReactElement} The chip.
 */
export function EntityChip( { entityKey, name, onOpen } ) {
	return (
		<button
			type="button"
			className="button button-small"
			onClick={ () => onOpen( entityKey ) }
		>
			{ name || entityKey }
		</button>
	);
}

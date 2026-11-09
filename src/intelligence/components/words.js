/**
 * The words the page shows for the keys it reads: the maps the server sends
 * in `vocabulary` (`screen_words()`), one per closed list, each value the
 * word a reader sees for its key, already translated and already naming the
 * operator where a word does. Every view reads a word through `word()`, so
 * no view spells one of its own.
 */

/**
 * One of the vocabulary's word maps, each key the server sends worded.
 *
 * @param {string} map The map, such as `kindWords`.
 * @return {Object<string, string>} Each key's word.
 */
export function words( map ) {
	const shown =
		/** @type {{NewspackNodesData: {vocabulary: Object<string, Object<string, string>>}}} */ (
			/** @type {unknown} */ ( window )
		).NewspackNodesData.vocabulary[ map ];
	if ( ! shown ) {
		throw new Error( `no ${ map } in the vocabulary` );
	}
	return shown;
}

/**
 * The word for `key` in the vocabulary's `map`. A key the map does not word
 * throws, since the server words every value of each list it sends.
 *
 * @param {string} map The map, such as `kindWords`.
 * @param {string} key The stored key.
 * @return {string} The word.
 */
export function word( map, key ) {
	const shown = words( map );
	if ( ! Object.prototype.hasOwnProperty.call( shown, key ) ) {
		throw new Error( `no ${ map } word for "${ key }"` );
	}
	return shown[ key ];
}

/**
 * A lens as `lenses` answers it, read into the text a form holds and back
 * into the options `save_lens` and `search` take. The one place that knows
 * the lens's shape, so Lenses and Search read it alike. Its list filters are
 * the page vocabulary's `lensLists`, each `{ field, option, values }`; its
 * tiers, a list narrowing its reader rather than filtering items, are not
 * among them.
 */

import { utcDay } from './format';

/** The lens's tiers, the list it holds beside its list filters. */
const TIERS = { field: 'tiers', option: 'tier' };

/**
 * Every list a lens holds: its list filters, then its tiers.
 *
 * @param {Array<Object>} lists The lens's list filters.
 * @return {Array<{field: string, option: string}>} Every list.
 */
const listsOf = ( lists ) => [ ...lists, TIERS ];

/** Each one-value filter, named alike as the lens's field and its option. */
const VALUES = [ 'entity', 'days', 'by', 'bucket' ];

/** Seconds in a UTC day. */
const DAY_SECONDS = 86400;

/**
 * A lens as a form's text, keyed by option: each list joined by commas,
 * each value as its string, and a value the lens leaves unset, null, as ''.
 *
 * @param {Object}        lens  A lens as `lenses` answers it.
 * @param {Array<Object>} lists The lens's list filters.
 * @return {Object<string, string>} The draft.
 */
export function lensDraft( lens, lists ) {
	const { filters } = lens;
	return {
		key: lens.lens_key,
		name: lens.name,
		description: lens.description,
		...Object.fromEntries(
			listsOf( lists ).map( ( { field, option } ) => [
				option,
				filters[ field ].join( ', ' ),
			] )
		),
		...Object.fromEntries(
			VALUES.map( ( name ) => [
				name,
				null === filters[ name ] ? '' : String( filters[ name ] ),
			] )
		),
	};
}

/**
 * The draft of a lens not yet saved: every field blank.
 *
 * @param {Array<Object>} lists The lens's list filters.
 * @return {Object<string, string>} The draft.
 */
export function newLens( lists ) {
	return Object.fromEntries(
		[
			'key',
			'name',
			'description',
			...listsOf( lists ).map( ( { option } ) => option ),
			...VALUES,
		].map( ( name ) => [ name, '' ] )
	);
}

/**
 * A draft as the lens it saves, shaped as `lenses` answers one.
 *
 * @param {Object<string, string>} draft The form's text, keyed by option.
 * @param {Array<Object>}          lists The lens's list filters.
 * @return {Object} The lens.
 */
export function draftLens( draft, lists ) {
	return {
		lens_key: draft.key,
		name: draft.name,
		description: draft.description,
		filters: {
			...Object.fromEntries(
				listsOf( lists ).map( ( { field, option } ) => [
					field,
					listOf( draft[ option ] ),
				] )
			),
			...Object.fromEntries(
				VALUES.map( ( name ) => [
					name,
					'' === draft[ name ] ? null : draft[ name ],
				] )
			),
		},
	};
}

/**
 * The values a list field's text holds, split at its commas.
 *
 * @param {string} text The field's text.
 * @return {string[]} The values, trimmed, blanks left out.
 */
export function listOf( text ) {
	return text
		.split( ',' )
		.map( ( one ) => one.trim() )
		.filter( Boolean );
}

/**
 * A draft as the options it sends: each list option split at its commas,
 * every other value as entered, for `commandArgs()` to name.
 *
 * @param {Object<string, string>} draft The form's text, keyed by option.
 * @param {Array<Object>}          lists The lens's list filters.
 * @return {Object<string, string|string[]>} The options.
 */
export function draftArgs( draft, lists ) {
	const listed = new Set( listsOf( lists ).map( ( { option } ) => option ) );
	return Object.fromEntries(
		Object.entries( draft ).map( ( [ option, text ] ) => [
			option,
			listed.has( option ) ? listOf( text ) : text,
		] )
	);
}

/**
 * The first UTC day of a lens's rolling window, Y-m-d: the last `days` days
 * ending today, as the server reads the lens.
 *
 * @param {number} days How many days.
 * @param {number} now  Milliseconds since the epoch.
 * @return {string} The day.
 */
export function daysSince( days, now ) {
	return utcDay( Math.floor( now / 1000 ) - ( days - 1 ) * DAY_SECONDS );
}

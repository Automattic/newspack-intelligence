/**
 * The DataViews view Search holds: the search before anything is asked, the
 * view a Briefing line or an ask names, and the view read as the `search`
 * command's own args.
 */

import { commandArgs } from '../hooks/useIntelCommand';
import { listOf } from '../components/lensDraft';

/** The items a page of results holds. */
const PER_PAGE = 20;

/**
 * Each filter field by the args its value names: a list's values repeat its
 * one arg, a topic's text repeats it at each comma, and the day's bounds name
 * `since` and `until` by operator, each bound counted in.
 *
 * @type {Object<string, (filter: {operator: string, value: *}) => Object>}
 */
const FILTER_ARGS = {
	kind: ( { value } ) => ( { kind: value ?? [] } ),
	topic: ( { value } ) => ( { topic: listOf( value ?? '' ) } ),
	role: ( { value } ) => ( { role: value ?? [] } ),
	source: ( { value } ) => ( { source: value ?? [] } ),
	lens: ( { value } ) => ( { lens: value ?? '' } ),
	entity: ( { value } ) => ( { entity: value ?? '' } ),
	day: ( { operator, value } ) =>
		( {
			between: { since: value?.[ 0 ] ?? '', until: value?.[ 1 ] ?? '' },
			afterInc: { since: value ?? '' },
			beforeInc: { until: value ?? '' },
		} )[ operator ],
};

/**
 * Each filter a line or an ask names by the view filter it reads as: a list
 * as its comma-separated values, a topic, a lens and an entity as given.
 *
 * @type {Object<string, (text: string) => {field: string, operator: string, value: *}>}
 */
const NAMED = {
	kind: ( text ) => ( {
		field: 'kind',
		operator: 'isAny',
		value: listOf( text ),
	} ),
	role: ( text ) => ( {
		field: 'role',
		operator: 'isAny',
		value: listOf( text ),
	} ),
	source: ( text ) => ( {
		field: 'source',
		operator: 'isAny',
		value: listOf( text ),
	} ),
	topic: ( text ) => ( { field: 'topic', operator: 'is', value: text } ),
	lens: ( text ) => ( { field: 'lens', operator: 'is', value: text } ),
	entity: ( text ) => ( { field: 'entity', operator: 'is', value: text } ),
};

/**
 * The search before anything is asked: the first page of a list, unfiltered,
 * in the order the server picks for what it is asked.
 *
 * @return {Object} The view.
 */
export function newView() {
	return {
		type: 'list',
		search: '',
		filters: [],
		page: 1,
		perPage: PER_PAGE,
		fields: [],
	};
}

/**
 * The day filter `since` and `until` name: a span when both do, else the one
 * bound given, or none.
 *
 * @param {string} [since] The first day, Y-m-d.
 * @param {string} [until] The last day, Y-m-d.
 * @return {Object[]} The filter, or none.
 */
function dayFilter( since, until ) {
	if ( since && until ) {
		return [
			{ field: 'day', operator: 'between', value: [ since, until ] },
		];
	}
	if ( since ) {
		return [ { field: 'day', operator: 'afterInc', value: since } ];
	}
	return until
		? [ { field: 'day', operator: 'beforeInc', value: until } ]
		: [];
}

/**
 * A fresh view on the filters named, as a Briefing line or an ask names
 * them: `q` the words, each list's values comma-separated, and `since` and
 * `until` days; a filter named blank is left out.
 *
 * @param {Object<string, string>} named The filters, by name.
 * @return {Object} The view.
 * @throws {Error} For a filter Search does not take.
 */
export function namedView( named ) {
	const { q = '', since, until, ...lists } = named;
	return {
		...newView(),
		search: q,
		filters: [
			...Object.entries( lists )
				.filter( ( [ name, text ] ) => {
					if ( ! NAMED[ name ] ) {
						throw new Error( `no search filter: ${ name }` );
					}
					return '' !== text;
				} )
				.map( ( [ name, text ] ) => NAMED[ name ]( text ) ),
			...dayFilter( since, until ),
		],
	};
}

/**
 * The `search` tokens a view names: its words as `q`, each filter in the
 * order the view holds them, its sort's field when it names one, a page of
 * `perPage` and the offset of its page, left out on the first.
 *
 * @param {{search: string, filters: Array<{field: string, operator: string, value: *}>, page: number, perPage: number, sort?: {field: string}}} view The view.
 * @return {string[]} The tokens.
 * @throws {Error} For a filter field `search` does not take.
 */
export function viewToArgs( view ) {
	const offset = ( view.page - 1 ) * view.perPage;
	return [
		...commandArgs( { q: view.search } ),
		...view.filters.flatMap( ( filter ) => {
			const args = FILTER_ARGS[ filter.field ];
			if ( ! args ) {
				throw new Error( `no search filter: ${ filter.field }` );
			}
			return commandArgs( args( filter ) );
		} ),
		...commandArgs( {
			sort: view.sort?.field ?? '',
			limit: view.perPage,
			...( 0 < offset ? { offset } : {} ),
		} ),
	];
}

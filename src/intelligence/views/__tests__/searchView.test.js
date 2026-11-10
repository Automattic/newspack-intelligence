/* eslint-env jest */
/**
 * `viewToArgs` reads the DataViews view Search holds as the `search`
 * command's own tokens: each list filter's values repeated, the words as
 * `q`, the page as an offset, and the sort by its name. `newView` is the
 * search before anything is asked, and `namedView` the view a Briefing line
 * or an ask opens Search on, from the filters it names.
 */

import { namedView, newView, viewToArgs } from '../searchView';

/**
 * A view as DataViews hands it back, every value distinct from its default.
 *
 * @param {Object} over What the case changes.
 * @return {Object} The view.
 */
const view = ( over ) => ( {
	type: 'list',
	search: '',
	filters: [],
	page: 1,
	perPage: 20,
	sort: { field: 'importance', direction: 'desc' },
	...over,
} );

describe( 'viewToArgs', () => {
	it( "maps kinds, words, page and Newest to the search command's own args", () => {
		expect(
			viewToArgs(
				view( {
					search: 'sponsor',
					filters: [
						{
							field: 'kind',
							operator: 'isAny',
							value: [ 'bug', 'feature_request' ],
						},
					],
					page: 3,
					perPage: 15,
					sort: { field: 'time', direction: 'desc' },
				} )
			)
		).toEqual( [
			'--q=sponsor',
			'--kind=bug',
			'--kind=feature_request',
			'--sort=time',
			'--limit=15',
			'--offset=30',
		] );
	} );

	it( 'names the team view, the entity, the other lists and the days, in the order the view holds them', () => {
		expect(
			viewToArgs(
				view( {
					filters: [
						{ field: 'lens', operator: 'is', value: 'desk-week' },
						{
							field: 'entity',
							operator: 'is',
							value: 'org:harbor-ledger',
						},
						{
							field: 'role',
							operator: 'isAny',
							value: [ 'partner' ],
						},
						{
							field: 'topic',
							operator: 'is',
							value: 'topic:ads',
						},
						{
							field: 'source',
							operator: 'isAny',
							value: [ 'linear' ],
						},
						{
							field: 'day',
							operator: 'between',
							value: [ '2026-09-14', '2026-10-02' ],
						},
					],
				} )
			)
		).toEqual( [
			'--lens=desk-week',
			'--entity=org:harbor-ledger',
			'--role=partner',
			'--topic=topic:ads',
			'--source=linear',
			'--since=2026-09-14',
			'--until=2026-10-02',
			'--sort=importance',
			'--limit=20',
		] );
	} );

	it( 'reads a day bound on one side alone, and an empty filter as none', () => {
		expect(
			viewToArgs(
				view( {
					filters: [
						{
							field: 'day',
							operator: 'afterInc',
							value: '2026-08-03',
						},
						{ field: 'kind', operator: 'isAny', value: [] },
						{ field: 'lens', operator: 'is', value: undefined },
					],
				} )
			)
		).toEqual( [
			'--since=2026-08-03',
			'--sort=importance',
			'--limit=20',
		] );
		expect(
			viewToArgs(
				view( {
					filters: [
						{
							field: 'day',
							operator: 'beforeInc',
							value: '2026-07-21',
						},
					],
				} )
			)
		).toEqual( [
			'--until=2026-07-21',
			'--sort=importance',
			'--limit=20',
		] );
	} );

	it( 'leaves the order to the server for a view that names none', () => {
		expect(
			viewToArgs( view( { sort: undefined, search: 'paywall' } ) )
		).toEqual( [ '--q=paywall', '--limit=20' ] );
	} );

	it( 'repeats each topic a topic filter holds, split at its commas', () => {
		expect(
			viewToArgs(
				view( {
					filters: [
						{
							field: 'topic',
							operator: 'is',
							value: 'topic:ads, topic:events',
						},
					],
				} )
			)
		).toEqual( [
			'--topic=topic:ads',
			'--topic=topic:events',
			'--sort=importance',
			'--limit=20',
		] );
	} );

	it( 'refuses a filter field the command does not take', () => {
		expect( () =>
			viewToArgs(
				view( {
					filters: [ { field: 'tier', operator: 'is', value: 'x' } ],
				} )
			)
		).toThrow( 'no search filter: tier' );
	} );
} );

describe( 'newView', () => {
	it( 'is the first page of a list, unfiltered, in the order the server picks', () => {
		expect( newView() ).toEqual( {
			type: 'list',
			search: '',
			filters: [],
			page: 1,
			perPage: 20,
			fields: [],
		} );
		expect( viewToArgs( newView() ) ).toEqual( [ '--limit=20' ] );
	} );
} );

describe( 'namedView', () => {
	it( 'reads the filters a line or an ask names into a fresh view', () => {
		expect(
			namedView( {
				q: 'newsletter digest',
				kind: 'bug, feature_request',
				role: 'partner',
				source: 'slack',
				topic: 'topic:ads',
				lens: 'desk-week',
				entity: 'organization:prairie-signal',
				since: '2026-09-14',
			} )
		).toEqual( {
			...newView(),
			search: 'newsletter digest',
			filters: [
				{
					field: 'kind',
					operator: 'isAny',
					value: [ 'bug', 'feature_request' ],
				},
				{ field: 'role', operator: 'isAny', value: [ 'partner' ] },
				{ field: 'source', operator: 'isAny', value: [ 'slack' ] },
				{ field: 'topic', operator: 'is', value: 'topic:ads' },
				{ field: 'lens', operator: 'is', value: 'desk-week' },
				{
					field: 'entity',
					operator: 'is',
					value: 'organization:prairie-signal',
				},
				{ field: 'day', operator: 'afterInc', value: '2026-09-14' },
			],
		} );
	} );

	it( 'reads a day bound on either side, or both as a span', () => {
		expect( namedView( { until: '2026-10-02' } ).filters ).toEqual( [
			{ field: 'day', operator: 'beforeInc', value: '2026-10-02' },
		] );
		expect(
			namedView( { since: '2026-09-14', until: '2026-10-02' } ).filters
		).toEqual( [
			{
				field: 'day',
				operator: 'between',
				value: [ '2026-09-14', '2026-10-02' ],
			},
		] );
	} );

	it( 'leaves out a filter named blank', () => {
		expect( namedView( { kind: '', lens: '', q: '' } ) ).toEqual(
			newView()
		);
	} );

	it( 'refuses a filter Search does not take', () => {
		expect( () => namedView( { tier: 'internal' } ) ).toThrow(
			'no search filter: tier'
		);
	} );
} );

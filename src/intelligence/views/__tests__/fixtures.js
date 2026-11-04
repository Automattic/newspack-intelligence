/**
 * Verb answers the view suites render, shaped as `Graph_Query` answers them,
 * every value distinct from the views' empty defaults.
 */

export const VOCABULARY = {
	roles: [ 'client', 'former_client', 'competitor' ],
	nameKinds: [ 'canonical', 'alias', 'acronym' ],
	identifierKinds: [ 'domain', 'github_org' ],
	itemKinds: [ 'award', 'launch', 'funding' ],
	tiers: [ 'public', 'internal', 'restricted' ],
	trendAxes: [ 'kind', 'topic' ],
	buckets: [ 'day', 'week', 'month' ],
};

VOCABULARY.lensLists = [
	{ field: 'kinds', option: 'kind', values: VOCABULARY.itemKinds },
	{ field: 'topics', option: 'topic', values: null },
	{ field: 'roles', option: 'role', values: VOCABULARY.roles },
	{ field: 'sources', option: 'source', values: null },
];

export const ITEM = {
	item_key: 'feed:inkfold-review-17',
	title: 'Acme Quillsheet wins regional prize',
	url: 'https://inkfold-review.example/acme',
	source: 'feed',
	tier: 'public',
	item_ts: 1790035200,
	summary: 'The Acme Quillsheet took the regional prize.',
	kind: 'award',
	relevance: 7,
	importance: 6,
	entities: [
		{
			entity_key: 'organization:acme-quillsheet',
			name: 'Acme Quillsheet',
			state: 'asserted',
		},
	],
};

export const ENTITY = {
	entity_key: 'organization:acme-quillsheet',
	type: 'organization',
	name: 'Acme Quillsheet',
	state: 'accepted',
	merged_from: [ 'organization:acme-quillsheet-inc' ],
	names: [ { kind: 'alias', value: 'The Quillsheet' } ],
	identifiers: [ { kind: 'domain', value: 'acmequillsheet.example' } ],
	roles: [
		{ role: 'client', from_ts: 1788912000, to_ts: 0 },
		{ role: 'prospect', from_ts: 1780000000, to_ts: 1788912000 },
	],
	topics: [ { topic_key: 'topic:local-news', name: 'Local news' } ],
	counts: { items: 12, proposed_links: 3 },
	published: { count: 0 },
	status: {
		client_since: 1788912000,
		kinds: { churn_signal: 4, feature_request: 2, complaint: 5 },
		last_item_ts: 1790035200,
	},
	available: true,
};

export const LENSES = {
	lenses: [
		{
			lens_key: 'support-desk',
			name: 'Support desk',
			description: 'What clients complain about',
			filters: {
				kinds: [ 'complaint', 'praise' ],
				topics: [],
				roles: [ 'client' ],
				sources: [],
				entity: 'organization:acme-quillsheet',
				days: 21,
				tiers: [ 'internal' ],
				by: 'kind',
				bucket: 'day',
			},
		},
		{
			lens_key: 'dev-shipping',
			name: 'Developer shipping',
			description: 'Releases and incidents',
			filters: {
				kinds: [ 'release' ],
				topics: [ 'topic:product' ],
				roles: [],
				sources: [ 'github', 'linear' ],
				entity: null,
				days: null,
				tiers: [],
				by: null,
				bucket: null,
			},
		},
	],
	available: true,
};

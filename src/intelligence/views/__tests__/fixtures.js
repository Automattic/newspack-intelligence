/**
 * Verb answers the view suites render, shaped as `Graph_Query` answers them,
 * every value distinct from the views' empty defaults.
 */

export const VOCABULARY = {
	roles: [ 'client', 'former_client', 'competitor' ],
	nameKinds: [ 'canonical', 'alias', 'acronym' ],
	identifierKinds: [ 'domain', 'github_org' ],
	itemKinds: [ 'award', 'launch', 'funding' ],
};

export const ITEM = {
	item_key: 'feed:press-gazette-17',
	title: 'Acme Gazette wins regional prize',
	url: 'https://pressgazette.example/acme',
	source: 'feed',
	tier: 'public',
	item_ts: 1790035200,
	summary: 'The Acme Gazette took the regional prize.',
	kind: 'award',
	relevance: 7,
	importance: 6,
	entities: [
		{
			entity_key: 'organization:acme-gazette',
			name: 'Acme Gazette',
			state: 'asserted',
		},
	],
};

export const ENTITY = {
	entity_key: 'organization:acme-gazette',
	type: 'organization',
	name: 'Acme Gazette',
	state: 'accepted',
	merged_from: [ 'organization:acme-gazette-inc' ],
	names: [ { kind: 'alias', value: 'The Gazette' } ],
	identifiers: [ { kind: 'domain', value: 'acmegazette.example' } ],
	roles: [
		{ role: 'client', from_ts: 1788912000, to_ts: 0 },
		{ role: 'prospect', from_ts: 1780000000, to_ts: 1788912000 },
	],
	topics: [ { topic_key: 'topic:local-news', name: 'Local news' } ],
	counts: { items: 12, proposed_links: 3 },
	available: true,
};

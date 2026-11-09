/**
 * Command answers the view suites render, shaped as `Graph_Query` answers them,
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
	markWords: {
		newsletter: 'Digest',
		linkedin: 'LinkedIn post',
		site: 'Homepage',
	},
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
			role: 'subject',
			client: true,
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
		kinds: {
			churned: 2,
			churn_signal: 4,
			feature_request: 2,
			complaint: 5,
		},
		last_item_ts: 1790035200,
		churn_open: false,
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

const HARBOR = {
	entity_key: 'organization:harbor-ledger',
	name: 'Harbor Ledger',
};

const ACME = {
	entity_key: 'organization:acme-quillsheet',
	name: 'Acme Quillsheet',
};

/**
 * A lens's items as `search --lens` answers them: ranked groups, the second
 * newer than the first so a view re-sorting by recency would swap them, then
 * a themed group of one and an unthemed group of one.
 */
export const GROUPS = {
	groups: [
		{
			theme: 'site blocked from sending email',
			kind: 'complaint',
			count: 12,
			clients: [ ACME, HARBOR ],
			newest_ts: 1790121600,
			items: [
				{
					...ITEM,
					item_key: 'linear:SUP-120',
					title: 'Acme mail bounces at the sender',
					url: 'https://linear.example/SUP-120',
					kind: 'complaint',
				},
				{
					...ITEM,
					item_key: 'linear:SUP-118',
					title: 'Harbor Ledger newsletters held',
					url: 'https://linear.example/SUP-118',
					kind: 'complaint',
					entities: [
						{
							...HARBOR,
							state: 'asserted',
							role: 'mention',
							client: true,
						},
					],
				},
			],
		},
		{
			theme: 'bot registrations',
			kind: 'incident',
			count: 3,
			clients: [ HARBOR ],
			newest_ts: 1790208000,
			items: [
				{
					...ITEM,
					item_key: 'linear:SUP-131',
					title: 'Harbor Ledger floods with fake readers',
					url: 'https://linear.example/SUP-131',
					kind: 'incident',
				},
			],
		},
		{
			theme: 'storage extension',
			kind: 'feature_request',
			count: 1,
			clients: [ ACME ],
			newest_ts: 1789948800,
			items: [
				{
					...ITEM,
					item_key: 'linear:SUP-77',
					title: 'Acme asks for more media storage',
					url: 'https://linear.example/SUP-77',
					kind: 'feature_request',
				},
			],
		},
		{
			theme: '',
			kind: 'award',
			count: 1,
			clients: [],
			newest_ts: 1790035200,
			items: [ ITEM ],
		},
	],
	total: 41,
	sort: 'importance',
	available: true,
};

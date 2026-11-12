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
	kindWords: {
		award: 'Prize',
		launch: 'Going live',
		release: 'Version out',
		funding: 'Money raised',
		hire: 'New hire',
		departure: 'Someone left',
		partnership: 'Teaming up',
		incident: 'Outage',
		bug: 'Defect',
		feature_request: 'Wish',
		complaint: 'Grumble',
		praise: 'Kudos',
		churned: 'Walking away',
		churn_signal: 'Wobbling',
		research: 'Study',
		other: 'Anything else',
	},
	roleWords: {
		client: 'Paying member',
		former_client: 'Lapsed member',
		prospect: 'Lead',
		competitor: 'Rival',
		partner: 'Ally',
		funder: 'Backer',
		industry_body: 'Trade group',
		operator_product: 'House product',
	},
	typeWords: {
		organization: 'Outfit',
		person: 'Human',
		place: 'Locale',
		product: 'Offering',
		topic: 'Subject area',
		event: 'Happening',
	},
	stateWords: {
		accepted: 'Settled',
		proposed: 'Guessed',
		rejected: 'Turned down',
	},
	nameKindWords: {
		canonical: 'Proper name',
		alias: 'Goes by',
		acronym: 'Initials',
		locality: 'Hometown',
	},
	identifierWords: {
		domain: 'Web address',
		github_org: 'Code org',
		github_repo: 'Code repo',
		linkedin_company: 'Company page',
		x_handle: 'Social handle',
		site_id: 'Site number',
		linear_team: 'Tracker team',
	},
	predicateWords: {
		works_for: 'is employed by',
		part_of: 'belongs to',
		funds: 'pays for',
		partners_with: 'teams with',
		competes_with: 'rivals',
		uses_product: 'runs',
		won: 'took',
		launched: 'started',
		located_in: 'sits in',
	},
	sourceWords: {
		github: 'Code host',
		feed: 'Web feed',
		p2: 'Team blog',
		linear: 'Tracker',
		slack: 'Chat',
		unthread: 'Help desk',
	},
	signalWords: {
		spike: '%s climbed',
		burst: 'Posted a lot',
		drop: 'Posted little',
		silent: 'Fell silent',
	},
	healthWords: {
		collection: 'Gathering',
		backlog: 'Pairing items',
		dropped: 'Let go',
		extraction: 'Digests',
		calls: 'Queued asks',
	},
	tierWords: {
		public: 'Anyone',
		internal: 'Staff',
		restricted: 'Few',
	},
	spanWords: { day: 'Daily', week: 'Weekly', month: 'Monthly' },
	axisWords: { kind: 'News type', topic: 'Subject area', theme: 'Thread' },
	linkRoleWords: {
		subject: 'Headlined',
		mention: 'Named in passing',
		topic: 'Tagged',
		publisher: 'Put out by',
	},
	methodWords: {
		identifier: 'its handle',
		name: 'its exact name',
		alias: 'another name',
		acronym: 'its initials',
		domain_stem: 'its web address',
		ner_fuzzy: 'a lookalike',
		human: 'a curator',
		extract: 'the summary',
	},
	markWords: {
		newsletter: 'Digest',
		linkedin: 'LinkedIn post',
		site: 'Homepage',
	},
};

/**
 * What the page is localized with, as the suites install it on `window`: the
 * substrate's REST base and nonce, the vocabulary, its words replaced by
 * any `words` given, map by map, and no operator.
 *
 * @param {Object} [words] Word maps that replace the fixture maps of their names.
 * @return {Object} The page data.
 */
export const pageData = ( words = {} ) => ( {
	restUrl: '/wp-json/',
	nonce: 'NONCE',
	operatorName: '',
	vocabulary: { ...VOCABULARY, ...words },
	compositions: { ...COMPOSITIONS },
} );

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
			week_items: 29,
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
			week_items: 4,
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

/** The ask block's declared defaults, as the page data carries them. */
export { default as ASK_DEFAULTS } from '../../../../tests/fixtures/ask-defaults.json';

/** The Briefing blocks' attributes at their declared defaults, in its default order. */
export const BRIEFING_DEFAULTS = {
	'newspack-intelligence/since': {
		kinds: [
			'churned',
			'churn_signal',
			'incident',
			'bug',
			'launch',
			'funding',
			'award',
			'feature_request',
			'complaint',
		],
	},
	'newspack-intelligence/needs-attention': {
		heading: 'Needs attention',
		max: 8,
		clients: true,
		lenses: [],
	},
	'newspack-intelligence/watches': { heading: 'Your watches', max: 5 },
	'newspack-intelligence/week': {
		heading: 'This week, most important first',
		top: 10,
		categories: [],
		otherwise: 'Everything else',
		by_client: true,
	},
};

/** The team view's default layout's blocks, at their shipped attributes. */
export const LENS_DEFAULTS = {
	'newspack-intelligence/pulse': { lens: '' },
	'newspack-intelligence/trend-chart': {
		heading: 'Last 8 weeks, by %s',
		by: '',
		bucket: '',
		style: 'stack',
	},
	'newspack-intelligence/item-list': {
		heading: 'Stories, ranked by severity, importance and clients',
		kinds: [],
		topics: [],
		roles: [],
		sources: [],
		lens: '',
		group: 'theme',
		sort: 'importance',
		layout: 'list',
		limit: 10,
		tiers: [],
		empty: 'Nothing here yet.',
	},
};

/** Each page whose default the page data's layouts hold. */
const DEFAULTS = {
	briefing: BRIEFING_DEFAULTS,
	lens: LENS_DEFAULTS,
};

/** The page data's layouts: the Briefing and a team view at their defaults, the rest blank. */
export const COMPOSITIONS = Object.fromEntries(
	[ 'briefing', 'lens', 'entity' ].map( ( page ) => [
		`newspack-intelligence/${ page }`,
		{
			context: `newspack-intelligence/${ page }`,
			source: 'default',
			blocks: Object.entries( DEFAULTS[ page ] ?? {} ).map(
				( [ name, attributes ] ) => ( {
					name,
					attributes,
					inner: [],
				} )
			),
			saved_ts: null,
			actor: null,
		},
	] )
);

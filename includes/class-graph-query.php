<?php
/**
 * Graph_Query: every read the `intelligence` verbs answer from the
 * intel-graph projection, through `Intel_Graph_Row_Schema::read()`. Each
 * takes its verb's bound args.
 *
 * A read opens on `graph()`, which binds once, as the prelude every
 * statement opens with, `mg`, each merged key's survivor in
 * `Entity_Index::survivors()`, and `ri`, the items of the tiers
 * `Intel_Tiers::readable()` grants. Every statement reads items through
 * `ri`, so an item, link, claim, count or citation never rests on an item
 * of another tier, and an entity that was never accepted by a person
 * answers only while a
 * readable item cites it (`visible()`). A key a read names, and every key a
 * row names, answers as its survivor; a key whose chain cycles or runs too
 * long is refused, and its rows count nowhere. A link reads as one per item
 * and survivor, a topic link apart from the rest, subject over mention; its
 * state is the highest-ranked judgment's (`JUDGE_RANK`), else the machine's,
 * and a `rejected` link counts nowhere. Each read hands `live()` the links
 * it can rest on and windows only those; `trends` with no entity reads all.
 * A read counts the links saying an item is about its entity
 * (`Intel_Event::ABOUT_ROLES`) unless it names other roles: an entity's
 * publishing reads its `publisher` links, and visibility and a claim's
 * subject rest on any link.
 * Summaries, kinds and scores come from `extractions`, a name from
 * `name_of()`, joined, since a link may name an entity with no row.
 *
 * `search` and `trends` read through a lens when the call names one: each
 * filter the lens saved stands for one the call does not name, and its
 * tiers narrow the caller's readable ones, never widen them (`through()`).
 *
 * Every value a caller sends is bound, a blank one as given (ADR-25); `q`
 * reaches FTS5 only as quoted phrases, one per word. An answer never carries
 * an item's body, but its excerpt, the opening as plain text. A projection that will not open answers `available: false`
 * beside empty rows; a graph that has not been written by any partition answers
 * empty rows,
 * available.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Sql array{0:string,1:list<scalar|null>}
 * @phpstan-type Graph array{survivors:array<string,string>,keys:array<string,list<string>>,with:string,binds:list<scalar>}
 * @phpstan-type Filter array{q:?string,entity:?string,about:bool,lists:array<string,list<string>>,days:array{0:int,1:int}|null}
 * @phpstan-type Lens array{lists:array<string,list<string>>,entity:?string,days:?int,tiers:list<string>,by:?string,bucket:?string}
 * @phpstan-type Within array{join:string,binds:list<scalar>,fields:array<string,string>,order:string}
 * @phpstan-type Item array{item_key:string,url:string,title:string,source:string,item_ts:int,retrieved_ts:int}
 * @phpstan-type Scope \Closure(array{0:int,1:int}, bool): array{0:string,1:list<scalar|null>,2:string,3:string,4:string,5:list<scalar|null>}
 */
final class Graph_Query {

	/** Rows a listing verb answers when its caller does not name a limit. */
	public const DEFAULT_LIMIT = 50;

	/** Entities `related` answers when its caller does not name a limit. */
	public const RELATED_LIMIT = 20;

	/** The most rows any verb answers. */
	public const MAX_LIMIT = 200;

	/** Items a trend row, a related entity, a proposal or a group cites, the newest. */
	public const CITATIONS = 5;

	/** Each judging actor kind's rank; a person outranks an agent. */
	private const JUDGE_RANK = [
		'user'  => 2,
		'agent' => 1,
	];

	/** What a trend counts, as its value and the join reaching it; a theme `stories()` joins once the items are read. */
	private const TREND_AXES = [
		'kind'  => [ 'x.kind', 'JOIN {extractions} x ON x.item_key = i.item_key' ],
		'topic' => [ 'tl.entity_key', 'JOIN live tl ON tl.item_key = i.item_key AND tl.topical' ],
		'theme' => [ 'NULL', '' ],
	];

	/** The trend axes `search` may group its items by. */
	private const GROUPINGS = [ 'theme' ];

	/** Groups a grouped search answers when its caller does not name a limit. */
	public const GROUP_LIMIT = 20;

	/** The days a grouped search reads when neither the call nor its lens bounds them. */
	public const GROUP_DAYS = 30;

	/** The most pairs of themes `theme_suggestions` answers. */
	public const THEME_SUGGESTIONS = 10;

	/** The most themes a word may be held by and still pair them. */
	public const SUGGESTION_WORD_MAX_THEMES = 20;

	/** The most open clients an item is about and still names; one about more names none. */
	public const MAX_ITEM_CLIENTS = 3;

	/** The role an entity holds while it is a client. */
	public const CLIENT = 'client';

	/** The item kind reporting that a client has left or is leaving. */
	public const CHURNED = 'churned';

	/** The role an entity holds while it is Newspack's own work. */
	public const NEWSPACK_PRODUCT = 'newspack_product';

	/** The item kinds a client's status counts, in the order it answers them. */
	public const STATUS_KINDS = [ self::CHURNED, 'churn_signal', 'feature_request', 'complaint' ];

	/** The item kind that says nothing, so it never signals about an entity. */
	private const MUTE_KIND = 'other';

	/** What a count of every item counts: one value, no join. */
	private const EVERY_ITEM = [ "''", '' ];

	/** Each trend bucket's first day, over an item's time `t`. */
	private const BUCKETS = [
		'day'   => "date( t, 'unixepoch' )",
		'week'  => "date( t, 'unixepoch', 'weekday 0', '-6 days' )",
		'month' => "date( t, 'unixepoch', 'start of month' )",
	];

	/** The characters of plain text an item's excerpt holds at most, the ellipsis aside. */
	public const EXCERPT_CHARS = 800;

	/** The characters of a body a list reads for its excerpt: enough past the markup, without moving 64 KiB a row. */
	private const EXCERPT_READ_CHARS = 4096;

	/** Each field a listed item answers, by the column it is read from. */
	private const ITEM_COLUMNS = [
		'item_key'   => 'i.item_key',
		'title'      => 'i.title',
		'url'        => 'i.url',
		'source'     => 'i.source',
		'tier'       => 'i.tier',
		'item_ts'    => 'i.item_ts',
		'summary'    => 'x.summary',
		'excerpt'    => 'i.head',
		'kind'       => 'x.kind',
		'relevance'  => 'x.relevance',
		'importance' => 'x.importance',
	];

	/** The queues `review` answers. */
	private const REVIEWS = [ 'links', 'entities' ];

	/**
	 * The list filters `search`, `trends` and a lens share, each matching
	 * any of its values: field => [ the repeatable arg naming it, its
	 * vocabulary or null when free, what a value is ].
	 */
	public const LENS_LISTS = [
		'kinds'   => [ 'kind', Intel_Event::ITEM_KINDS, 'An item kind its extraction gave' ],
		'topics'  => [ 'topic', null, 'A topic the item is tagged with' ],
		'roles'   => [ 'role', Intel_Event::ROLES, 'A role an entity the item links held at the item\'s time' ],
		'sources' => [ 'source', null, 'The source that recorded it' ],
	];

	/** What a trend counts when neither the call nor its lens names it. */
	public const DEFAULT_AXIS = 'kind';

	/** The span a trend row counts when neither the call nor its lens names one. */
	public const DEFAULT_BUCKET = 'week';

	/** The fewest items a signal counts. */
	public const SIGNAL_MIN = 3;

	/** How many standard deviations above its baseline's mean a signal stands. */
	public const SIGNAL_SIGMAS = 2;

	/** How many buckets before the latest complete one a signal's baseline spans. */
	public const SIGNAL_BASELINE = 8;

	/** A signals read's trend buckets, latest first; at most SIGNAL_BASELINE + 1. */
	public const SIGNAL_TREND = 8;

	/** The share of its baseline mean at or below which a publisher's week drops. */
	public const SIGNAL_DROP = 0.5;

	/** The days an unbounded range runs between. */
	private const ALL_DAYS = [ '1970-01-01', '9999-12-31' ];

	/** The columns `item_row()` reads, in its order, over `i`. */
	public const ITEM_ROW = 'i.item_key, i.url, i.title, i.source, i.item_ts, i.retrieved_ts';

	/** The answer a listing of items gives with no rows. */
	public const NO_ITEMS = [
		'items' => [],
		'total' => 0,
	];

	/** The answer a grouped search gives with no rows. */
	private const NO_GROUPS = [
		'groups' => [],
		'total'  => 0,
	];

	/** How a page of items reads with nothing joined to it: newest first. */
	private const NEWEST = [
		'join'   => '',
		'binds'  => [],
		'fields' => [],
		'order'  => Intel_Graph_Row_Schema::ITEM_TIME . ' DESC, i.item_key DESC',
	];

	/** How a page of items reads by importance: the highest, then the most relevant, then the newest, the unscored last. */
	private const MOST_IMPORTANT = [
		'join'   => '',
		'binds'  => [],
		'fields' => [],
		'order'  => 'x.importance DESC, x.relevance DESC, ' . Intel_Graph_Row_Schema::ITEM_TIME . ' DESC, i.item_key DESC',
	];

	/** Each order `search` takes, by its `sort`. */
	private const SORTS = [
		'importance' => self::MOST_IMPORTANT,
		'time'       => self::NEWEST,
	];

	/** The line logged when the projection will not open. */
	private const UNAVAILABLE = 'intelligence: no intel-graph to read; answering none: ';

	/**
	 * An entity's items, newest first, as `search` answers them: those about
	 * it, or with `published` those its own site published.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `published`, `since`, `until`, `limit`, `offset`.
	 * @return array<string,mixed> `{ items, total, available }`.
	 * @throws \InvalidArgumentException On a key that does not name an entity
	 *                                   the caller may see, or as `search()`.
	 */
	public static function timeline( array $args ): array {
		$key                = Core::as_string( $args['key'] );
		$links              = true === $args['published'] ? [ Intel_Event::PUBLISHER ] : Intel_Event::ABOUT_ROLES;
		[ $limit, $offset ] = self::page( $args );
		$filter             = self::filter_of( [ 'entity' => $key ] + $args );
		return self::answer(
			self::NO_ITEMS,
			static function ( Row_Reader $r, array $g ) use ( $key, $links, $filter, $limit, $offset ): array {
				self::seen( $r, $g, $key );
				return self::items( $r, $g, $filter, $limit, $offset, self::NEWEST, $links );
			}
		);
	}

	/**
	 * Items matching every filter given, in the order `sort_of()` picks: `q`
	 * over title, body and summary, `entity` linked by any link, any `topic`
	 * by a topic link, any `role` held at the item's time by an entity the
	 * item links, any `kind`, any `source`, and the UTC days `since` to
	 * `until`, each read through `lens` when the call names one; with
	 * `group`, the same items as the groups `grouped()` ranks, which take no
	 * sort, over the last GROUP_DAYS days when neither the call nor the lens
	 * bounds them. A page holds `limit` items, DEFAULT_LIMIT when it names
	 * none, or `limit` groups, GROUP_LIMIT when it names none.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array<string,mixed> `{ items, total, available, sort }`, or `{ groups, total, available }` with `group`.
	 * @throws \InvalidArgumentException On a bad page, day, sort or grouping,
	 *                                   a sort beside a grouping, or a lens
	 *                                   that was never saved.
	 */
	public static function search( array $args ): array {
		$group = self::given( $args, 'group' );
		if ( null === $group ) {
			[ $limit, $offset ] = self::page( [ 'limit' => $args['limit'] ?? self::DEFAULT_LIMIT ] + $args );
			$sort               = self::sort_of( $args );
			return self::through( $args, self::NO_ITEMS, static fn ( Row_Reader $r, array $g, array $filter ): array => self::items( $r, $g, $filter, $limit, $offset, self::SORTS[ $sort ] ) ) + [ 'sort' => $sort ];
		}
		self::one_of( 'group', $group, self::GROUPINGS );
		if ( null !== $args['sort'] ) {
			throw new \InvalidArgumentException( 'sort does not apply to groups, which rank by severity, then importance, then clients, then recency' );
		}
		[ $limit, $offset ] = self::page( [ 'limit' => $args['limit'] ?? self::GROUP_LIMIT ] + $args );
		return self::through( $args, self::NO_GROUPS, static fn ( Row_Reader $r, array $g, array $filter ): array => self::grouped( $r, $g, [ 'days' => $filter['days'] ?? self::last_days( self::GROUP_DAYS ) ] + $filter, $group, $limit, $offset ) );
	}

	/**
	 * One page of the groups the items the filters admit form, and how many
	 * groups there are, in two statements: the page with its stories, then
	 * their entities. Each item counts as its story (`stories()`), and a
	 * group is the stories whose copies share a value of the axis; a story
	 * none of whose copies has a value is a group of its own, its theme ''.
	 * A group answers `{ theme, kind, count, clients, newest_ts, items }`,
	 * each read over every copy of its stories, a copy with no value among
	 * them: the most severe kind of those copies, a kind's severity its
	 * place in the filters' `kinds`, most severe first, every kind alike
	 * where they name none, the newest copy's among equals; how many stories
	 * it holds; the open clients those copies are about by a subject or a
	 * mention, by name, a copy about more than MAX_ITEM_CLIENTS naming none
	 * though it still counts; the time of its newest story, each dated by its
	 * earliest copy; and its CITATIONS newest stories as
	 * `search` answers them, the most important first, as MOST_IMPORTANT
	 * orders them. Groups rank by severity, then by the highest importance
	 * of any copy, then by clients, the most first, then by newest story,
	 * then by theme, so a grave unattributed bug outranks a named client's
	 * styling fix.
	 *
	 * @param Row_Reader $r      The reader.
	 * @param Graph      $g      The graph the read opened.
	 * @param Filter     $filter The filters.
	 * @param string     $by     The trend axis it groups by, one of GROUPINGS.
	 * @param int        $limit  The groups a page holds.
	 * @param int        $offset The groups before the page.
	 * @return array<string,mixed> `{ groups, total }`.
	 */
	private static function grouped( Row_Reader $r, array $g, array $filter, string $by, int $limit, int $offset ): array {
		[ $stories, $binds ] = self::stories( $r, self::filtered( $r, $g, $filter ), $filter['days'] ?? self::all_days(), $by, false );
		$kinds               = $filter['lists']['kinds'];
		$x                   = Intel_Graph_Row_Schema::all( $r, 'extractions' );
		$columns             = self::ITEM_COLUMNS;
		$picked              = \implode( ', ', \array_map( static fn ( string $as, string $column ): string => "{$column} AS {$as}", \array_keys( $columns ), $columns ) );
		$rows                = $r->rows(
			"{$stories}, " . self::item_clients( $r )
			. ', sev AS ( SELECT CAST( key AS INTEGER ) AS severity, value AS kind FROM json_each( ? ) ),'
			. " gm AS ( SELECT DISTINCT CASE WHEN c.value IS NULL THEN 'item:' || c.story ELSE 'theme:' || c.value END AS gid, COALESCE( c.value, '' ) AS theme, c.story FROM copies c"
			. ' WHERE c.value IS NOT NULL OR c.story NOT IN ( SELECT story FROM copies WHERE value IS NOT NULL ) ),'
			. ' ga AS ( SELECT DISTINCT gm.gid, c.copy, c.t FROM gm JOIN copies c ON c.story = gm.story ),'
			. " gk AS ( SELECT ga.gid, ga.t, x.kind, x.importance, COALESCE( sev.severity, ? ) AS severity FROM ga LEFT JOIN {$x} x ON x.item_key = ga.copy LEFT JOIN sev ON sev.kind = x.kind ),"
			. ' gi AS ( SELECT gid, MAX( importance ) AS top FROM gk GROUP BY gid ),'
			. ' gw AS ( SELECT gid, kind, severity FROM ( SELECT gid, kind, severity, ROW_NUMBER() OVER ( PARTITION BY gid ORDER BY severity, t DESC, kind ) AS pick FROM gk ) WHERE pick = 1 ),'
			. ' gs AS ( SELECT gm.gid, MAX( gm.theme ) AS theme, gm.story, MIN( c.t ) AS t FROM gm JOIN copies c ON c.story = gm.story GROUP BY gm.gid, gm.story ),'
			. ' gl AS ( SELECT DISTINCT ga.gid, ic.entity_key FROM ga JOIN ic ON ic.item_key = ga.copy ),'
			. ' gn AS ( SELECT gid, COUNT(*) AS n FROM gl GROUP BY gid ),'
			. ' ranked AS ( SELECT s.gid, MAX( s.theme ) AS theme, w.kind, COUNT(*) AS n, MAX( s.t ) AS newest,'
			. ' ROW_NUMBER() OVER ( ORDER BY w.severity, COALESCE( gi.top, -1 ) DESC, COALESCE( gn.n, 0 ) DESC, MAX( s.t ) DESC, MAX( s.theme ), s.gid ) AS ord'
			. ' FROM gs s JOIN gw w ON w.gid = s.gid LEFT JOIN gi ON gi.gid = s.gid LEFT JOIN gn ON gn.gid = s.gid GROUP BY s.gid ),'
			. " page AS MATERIALIZED ( SELECT ranked.*, ( SELECT json_group_array( json_object( 'entity_key', gl.entity_key, 'name', " . self::name_of( $r, 'gl.entity_key' ) . ' ) ) FROM gl WHERE gl.gid = ranked.gid ) AS named FROM ranked WHERE ord > ? AND ord <= ? ),'
			. ' cited AS ( SELECT gid, story, ROW_NUMBER() OVER ( PARTITION BY gid ORDER BY t DESC, story DESC ) AS rn FROM gs WHERE gid IN ( SELECT gid FROM page ) )'
			. ' SELECT tot.total, q.* FROM ( SELECT COUNT(*) AS total FROM ranked ) tot LEFT JOIN ( SELECT p.ord, p.theme AS g_theme, p.kind AS g_kind, p.n AS g_count, p.newest AS g_newest, p.named AS g_clients, '
			. $picked . ', ' . Intel_Graph_Row_Schema::ITEM_TIME . " AS t FROM page p JOIN cited ON cited.gid = p.gid AND cited.rn <= ? JOIN ri i ON i.item_key = cited.story LEFT JOIN {$x} x ON x.item_key = i.item_key ) q ON 1"
			. ' ORDER BY q.ord, q.importance DESC, q.relevance DESC, q.t DESC, q.item_key DESC',
			[ ...$binds, \wp_json_encode( $kinds, \JSON_THROW_ON_ERROR ), \count( $kinds ), $offset, $offset + $limit, self::CITATIONS ]
		);
		$groups              = [];
		foreach ( $rows as $row ) {
			if ( null === $row[1] ) {
				continue;
			}
			$item                         = self::excerpted( \array_combine( \array_keys( $columns ), \array_slice( $row, 7, \count( $columns ) ) ) );
			$at                           = Core::as_int( $row[1] );
			$groups[ $at ]              ??= [ 'theme' => Core::as_string( $row[2] ), 'kind' => $row[3], 'count' => Core::as_int( $row[4] ), 'clients' => self::clients_of( $row[6] ), 'newest_ts' => Core::as_int( $row[5] ), 'items' => [] ];
			$groups[ $at ]['items'][ Core::as_string( $item['item_key'] ) ] ??= $item;
		}
		$keys   = [];
		foreach ( $groups as $group ) {
			\array_push( $keys, ...\array_map( 'strval', \array_keys( $group['items'] ) ) );
		}
		$linked = self::entities_of( $r, $g, \array_values( \array_unique( $keys ) ) );
		foreach ( $groups as $at => $group ) {
			$groups[ $at ]['items'] = \array_map( static fn ( array $item ): array => $item + [ 'entities' => $linked[ Core::as_string( $item['item_key'] ) ] ?? [] ], \array_values( $group['items'] ) );
		}
		return [
			'groups' => \array_values( $groups ),
			'total'  => Core::as_int( $rows[0][0] ?? 0 ),
		];
	}

	/**
	 * The order a search reads in: the `sort` it names, else `time` for
	 * words with no lens, else `importance`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `sort`, `q`, `lens`.
	 * @throws \InvalidArgumentException On a sort SORTS does not hold.
	 */
	private static function sort_of( array $args ): string {
		if ( null === $args['sort'] ) {
			return null === $args['lens'] && '' !== \trim( Core::as_string( $args['q'] ) ) ? 'time' : 'importance';
		}
		return self::one_of( 'sort', Core::as_string( $args['sort'] ), self::sorts() );
	}

	/**
	 * The orders `search` takes, for the arg's description.
	 *
	 * @return list<string>
	 */
	public static function sorts(): array {
		return \array_keys( self::SORTS );
	}

	/**
	 * The pairs of themes worth merging: of the readable stories of the last
	 * GROUP_DAYS days, each theme read as `theme_survivors()` answers it, two
	 * themes whose stories share an entity they are about by a subject or a
	 * mention, and whose names share a word `Prompts::theme_words()` keeps.
	 * Each answers `{ themes: [ { theme, stories }, { theme, stories } ],
	 * words, shared }`, the theme with more stories first, then by name, the
	 * words the names share, sorted, and how many entities their stories
	 * share. Pairs rank by how alike the names are (`alike()`), then by the
	 * entities shared, then by their stories, then by name; THEME_SUGGESTIONS
	 * are answered. One statement reads every theme's stories and entities.
	 *
	 * @return array<string,mixed> `{ suggestions, available }`.
	 */
	public static function theme_suggestions(): array {
		return self::answer( [ 'suggestions' => [] ], static fn ( Row_Reader $r, array $g ): array => [ 'suggestions' => self::suggested( self::theme_entities( $r, $g ) ) ] );
	}

	/**
	 * Each theme of the last GROUP_DAYS days' readable stories, as its
	 * survivor, with how many stories carry it and the entities those
	 * stories are about by a subject or a mention.
	 *
	 * @param Row_Reader $r The reader.
	 * @param Graph      $g The graph the read opened.
	 * @return array<string,array{stories:int,entities:array<string,true>}>
	 */
	private static function theme_entities( Row_Reader $r, array $g ): array {
		$rank        = self::judge_rank( 'j.actor_kind' );
		[ $tm, $map ] = self::theme_survivors( $r );
		$rows        = $r->rows(
			"{$g['with']}, {$tm},"
			. ' w AS ( ' . Intel_Graph_Row_Schema::newest( $r, 'themes', 'item_key', 'item_key, theme', 'item_day >= ?' ) . ' ),'
			. ' wi AS MATERIALIZED ( SELECT w.item_key, COALESCE( tm.survivor, w.theme ) AS theme FROM w JOIN ri i ON i.item_key = w.item_key LEFT JOIN tm ON tm.theme = w.theme ),'
			. ' wc AS MATERIALIZED ( ' . Intel_Graph_Row_Schema::newest( $r, 'clusters', 'item_key', 'item_key, cluster_key', 'item_key IN ( SELECT item_key FROM wi )' ) . ' ),'
			. ' ws AS ( SELECT wc.item_key, k.canonical_item_key AS story FROM wc JOIN ( ' . Intel_Graph_Row_Schema::newest( $r, 'canonicals', 'cluster_key', 'cluster_key, canonical_item_key', 'cluster_key IN ( SELECT cluster_key FROM wc )' ) . ' ) k ON k.cluster_key = wc.cluster_key'
			. ' WHERE EXISTS ( SELECT 1 FROM ri s WHERE s.item_key = k.canonical_item_key ) ),'
			. ' wn AS ( SELECT wi.theme, COUNT( DISTINCT COALESCE( ws.story, wi.item_key ) ) AS n FROM wi LEFT JOIN ws ON ws.item_key = wi.item_key GROUP BY 1 )'
			. ' SELECT DISTINCT wi.theme, wn.n, COALESCE( mg.value, l.entity_key ) FROM wi JOIN wn ON wn.theme = wi.theme JOIN ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l ON l.item_key = wi.item_key LEFT JOIN mg ON mg.key = l.entity_key'
			. " WHERE l.role IN ( ?, ? ) AND COALESCE( mg.value, l.entity_key ) <> ''"
			. " AND 'rejected' IS NOT ( SELECT state FROM ( SELECT j.state, {$rank} AS rank, j.ts FROM " . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j LEFT JOIN mg jm ON jm.key = j.entity_key'
			. ' WHERE j.item_key = l.item_key AND COALESCE( jm.value, j.entity_key ) = COALESCE( mg.value, l.entity_key ) ) ORDER BY rank DESC, ts DESC LIMIT 1 )',
			[ ...$g['binds'], $map, \intdiv( self::last_days( self::GROUP_DAYS )[0], Intel_Graph_Row_Schema::DAY_SECONDS ), ...Intel_Event::NAMING_ROLES ]
		);
		$themes = [];
		foreach ( $rows as [ $theme, $count, $entity ] ) {
			$theme                                                      = Core::as_string( $theme );
			$themes[ $theme ]['stories']                                = Core::as_int( $count );
			$themes[ $theme ]['entities'][ Core::as_string( $entity ) ] = true;
		}
		return $themes;
	}

	/**
	 * The THEME_SUGGESTIONS pairs of themes sharing an entity and a kept word,
	 * the most alike first, each as `theme_suggestions()` answers it. A word
	 * more than SUGGESTION_WORD_MAX_THEMES themes hold pairs none of them, so
	 * the pairs grow with the words rather than with the square of the
	 * themes; each pair is scored once, from the first of its pairing words
	 * in string order, and the best are kept as they are scored.
	 *
	 * @param array<string,array{stories:int,entities:array<string,true>}> $themes Each theme's stories and entities.
	 * @return list<array{themes:list<array{theme:string,stories:int}>,words:list<string>,shared:int}>
	 */
	private static function suggested( array $themes ): array {
		$words = [];
		$holds = [];
		foreach ( \array_keys( $themes ) as $theme ) {
			$holds[ $theme ] = \array_fill_keys( Prompts::theme_words( $theme ), true );
			foreach ( \array_keys( $holds[ $theme ] ) as $word ) {
				$words[ $word ][] = $theme;
			}
		}
		$weight  = \array_map( static fn ( array $holding ): float => \log( 1 + \count( $themes ) / \count( $holding ) ), $words );
		$pairing = \array_filter( $words, static fn ( array $holding ): bool => \count( $holding ) <= self::SUGGESTION_WORD_MAX_THEMES );
		$ranked  = [];
		foreach ( $pairing as $word => $holding ) {
			\sort( $holding );
			foreach ( $holding as $at => $a ) {
				foreach ( \array_slice( $holding, $at + 1 ) as $b ) {
					$both     = \array_keys( \array_intersect_key( $holds[ $a ], $holds[ $b ] ) );
					$eligible = \array_values( \array_filter( $both, static fn ( string $shared ): bool => isset( $pairing[ $shared ] ) ) );
					\sort( $eligible, \SORT_STRING );
					if ( $word !== $eligible[0] ) {
						continue;
					}
					$shared = \count( \array_intersect_key( $themes[ $a ]['entities'], $themes[ $b ]['entities'] ) );
					if ( 0 === $shared ) {
						continue;
					}
					$sides = [ [ 'theme' => $a, 'stories' => $themes[ $a ]['stories'] ], [ 'theme' => $b, 'stories' => $themes[ $b ]['stories'] ] ];
					\usort( $sides, static fn ( array $x, array $y ): int => [ $y['stories'], $x['theme'] ] <=> [ $x['stories'], $y['theme'] ] );
					\sort( $both );
					$ranked[] = [
						[ -self::alike( $both, \array_keys( $holds[ $a ] + $holds[ $b ] ), $weight ), -$shared, -( $sides[0]['stories'] + $sides[1]['stories'] ), $a, $b ],
						[
							'themes' => $sides,
							'words'  => $both,
							'shared' => $shared,
						],
					];
					if ( \count( $ranked ) >= 2 * self::THEME_SUGGESTIONS ) {
						$ranked = self::best( $ranked );
					}
				}
			}
		}
		return \array_column( self::best( $ranked ), 1 );
	}

	/**
	 * The THEME_SUGGESTIONS best of scored pairs, the best first.
	 *
	 * @template T
	 * @param list<array{0:array<int,float|int|string>,1:T}> $ranked Each pair's sort key and answer.
	 * @return list<array{0:array<int,float|int|string>,1:T}>
	 */
	private static function best( array $ranked ): array {
		\usort( $ranked, static fn ( array $x, array $y ): int => $x[0] <=> $y[0] );
		return \array_slice( $ranked, 0, self::THEME_SUGGESTIONS );
	}

	/**
	 * How alike two theme names are, 0 to 1: the weight of the words they
	 * share over the weight of every word either holds, each word weighing
	 * the more the fewer themes hold it, `log( 1 + themes / holding )`.
	 *
	 * @param list<string>       $shared The words both names hold.
	 * @param list<string>       $either The words either name holds.
	 * @param array<string,float> $weight Each word's weight.
	 */
	private static function alike( array $shared, array $either, array $weight ): float {
		$sum = [ 0.0, 0.0 ];
		foreach ( [ $shared, $either ] as $at => $words ) {
			foreach ( $words as $word ) {
				$sum[ $at ] += $weight[ $word ];
			}
		}
		return $sum[0] / $sum[1];
	}

	/**
	 * The items a watch's target holds that the caller may read and the
	 * watch has not matched, entering the target at or after `$since`, the
	 * oldest first: a lens's or an entity's item at its retrieval, read
	 * through the lens or the entity as `search` reads it, and a
	 * collection's at its saving, through the readable items.
	 *
	 * @api Read by the watcher, as each watch's owner, inside its one read.
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The owner's graph.
	 * @param string     $kind  `lens`, `entity` or `collection`.
	 * @param string     $key   The target's key.
	 * @param string     $watch The watch key.
	 * @param float      $since The earliest entry read.
	 * @param int        $limit The most items answered.
	 * @return list<array{at:float,item:Item}>
	 * @throws \InvalidArgumentException On a lens that was never saved, a
	 *                                   collection that was never made, or an
	 *                                   entity whose merge chain does not
	 *                                   resolve.
	 */
	public static function arrivals( Row_Reader $r, array $g, string $kind, string $key, string $watch, float $since, int $limit ): array {
		$tail = static fn ( string $at ): string => "{$at} >= ? AND NOT EXISTS ( SELECT 1 FROM " . Intel_Graph_Row_Schema::all( $r, 'watch_matches' ) . ' m WHERE m.watch_key = ? AND m.item_key = i.item_key ) ORDER BY at, i.item_key LIMIT ?';
		$last = [ $since, $watch, $limit ];
		if ( 'collection' === $kind ) {
			if ( null === self::collection( $r, $key ) ) {
				throw new \InvalidArgumentException( \esc_html( "no collection: {$key}" ) );
			}
			$rows = $r->rows( "{$g['with']} SELECT " . self::ITEM_ROW . ', d.ts AS at FROM ' . Intel_Graph_Row_Schema::all( $r, 'collected' ) . ' d JOIN ri i ON i.item_key = d.item_key WHERE d.collection_key = ? AND ' . $tail( 'd.ts' ), [ ...$g['binds'], $key, ...$last ] );
		} else {
			[ $call, $name ] = self::asked( [ $kind => $key ] );
			$rows            = self::lensed(
				$r,
				$g,
				$call,
				$name,
				static function ( Row_Reader $r, array $g, array $filter ) use ( $tail, $last ): array {
					[ $with, $where, $lead, $binds ] = self::narrowed( $r, $g, $filter, false );
					return $r->rows( "{$with} SELECT " . self::ITEM_ROW . ", i.retrieved_ts AS at FROM ri i WHERE {$where} AND " . $tail( 'i.retrieved_ts' ), [ ...$lead, ...$binds, ...$last ] );
				}
			);
		}
		return \array_map( static fn ( array $row ): array => [ 'at' => Core::as_float( $row[6] ), 'item' => self::item_row( $row ) ], $rows );
	}

	/**
	 * A cited item from a row leading with `ITEM_ROW`'s columns: the one
	 * shape an arrival, an owed match and an email carry.
	 *
	 * @api Graph_Watches reads its owed matches by it.
	 * @param list<mixed> $row The row.
	 * @return Item
	 */
	public static function item_row( array $row ): array {
		return [
			'item_key'     => Core::as_string( $row[0] ),
			'url'          => Core::as_string( $row[1] ),
			'title'        => Core::as_string( $row[2] ),
			'source'       => Core::as_string( $row[3] ),
			'item_ts'      => Core::as_int( $row[4] ),
			'retrieved_ts' => Core::as_int( $row[5] ),
		];
	}

	/**
	 * Those of `$keys` whose items sit in a watch's target now, read as
	 * `arrivals()` reads the target: through the lens or the entity exactly
	 * as `search` reads it, and for a collection every key, since a saved
	 * item stays saved.
	 *
	 * @api Graph_Watches holds a watch's inbox to what its target shows.
	 * @param Row_Reader   $r    The reader.
	 * @param Graph        $g    The caller's graph.
	 * @param string       $kind `lens`, `entity` or `collection`.
	 * @param string       $key  The target's key.
	 * @param list<string> $keys The item keys to test.
	 * @return list<string>
	 */
	public static function in_target( Row_Reader $r, array $g, string $kind, string $key, array $keys ): array {
		if ( 'collection' === $kind || [] === $keys ) {
			return $keys;
		}
		[ $call, $name ] = self::asked( [ $kind => $key ] );
		return self::lensed(
			$r,
			$g,
			$call,
			$name,
			static function ( Row_Reader $r, array $g, array $filter ) use ( $keys ): array {
				[ $with, $where, $lead, $binds ] = self::narrowed( $r, $g, $filter, false );
				$rows                            = $r->rows( "{$with} SELECT i.item_key FROM ri i WHERE {$where} AND i.item_key IN ( " . Intel_Graph_Row_Schema::marks( $keys ) . ' )', [ ...$lead, ...$binds, ...$keys ] );
				return \array_map( static fn ( array $row ): string => Core::as_string( $row[0] ), $rows );
			}
		);
	}

	/**
	 * A page of the readable items a join admits, each as `search` answers
	 * it plus the join's fields, in the join's order, and their count.
	 *
	 * @api Read by Graph_Watches, whose join is a watch's unseen matches.
	 * @param Row_Reader             $r      The reader.
	 * @param Graph                  $g      The graph the read opened.
	 * @param Within                 $within The join, its fields and the order.
	 * @param array<array-key,mixed> $args   The bound verb args: `limit`, `offset`.
	 * @return array<string,mixed> `{ items, total }`.
	 * @throws \InvalidArgumentException On a bad page.
	 */
	public static function listed( Row_Reader $r, array $g, array $within, array $args ): array {
		[ $limit, $offset ] = self::page( $args );
		return self::items( $r, $g, self::filter_of( [] ), $limit, $offset, $within );
	}

	/**
	 * A page's size and start, checked.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `limit`, `offset`.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException On a limit below 1 or a negative offset.
	 */
	public static function page( array $args ): array {
		$limit  = self::limit( $args );
		$offset = Core::as_int( $args['offset'] );
		if ( $offset < 0 ) {
			throw new \InvalidArgumentException( 'offset must not be negative' );
		}
		return [ $limit, $offset ];
	}

	/**
	 * The most important readable items naming any entity as their subject
	 * or a mention, a topic tag aside, dated in the last `$days` UTC days, as
	 * `search` answers them, how many there are, and what of them is dated
	 * at or after `$since` (`summed()`).
	 *
	 * @api Read by Intelligence_Briefing.
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The graph the read opened.
	 * @param int        $days  How many days, today the last.
	 * @param int        $limit The most items answered.
	 * @param int        $since The first instant the summary counts, UTC seconds.
	 * @return array<string,mixed> `{ items, total, summary }`.
	 */
	public static function most_important( Row_Reader $r, array $g, int $days, int $limit, int $since ): array {
		$filter = [
			'about' => true,
			'days'  => self::last_days( $days ),
		] + self::filter_of( [] );
		return self::items( $r, $g, $filter, $limit, 0, self::MOST_IMPORTANT, Intel_Event::NAMING_ROLES, $since );
	}

	/**
	 * One page of matching items, the count of all of them, and the entities
	 * each is about; `$within` joins a set the items must be in, the fields
	 * it adds after the links, and the order it lists them in, and `$links`
	 * are the link roles the filters read.
	 *
	 * @param Row_Reader   $r      The reader.
	 * @param Graph        $g      The graph the read opened.
	 * @param Filter       $filter The filters.
	 * @param int          $limit  The page size.
	 * @param int          $offset The page start.
	 * @param Within       $within The join, its fields and the order.
	 * @param list<string> $links  The link roles the filters read.
	 * @param int|null     $since  With `about`, the first instant a summary counts, in the statement counting the total; null for none.
	 * @return array<string,mixed>
	 */
	private static function items( Row_Reader $r, array $g, array $filter, int $limit, int $offset, array $within = self::NEWEST, array $links = Intel_Event::ABOUT_ROLES, ?int $since = null ): array {
		[ $with, $where, $lead, $binds ] = self::narrowed( $r, $g, $filter, false, $links );
		$binds                           = [ ...$lead, ...$within['binds'], ...$binds ];
		$columns                         = self::ITEM_COLUMNS + $within['fields'];
		[ $total, $summary ]             = null === $since
			? [ Core::as_int( $r->rows( "{$with} SELECT COUNT(*) FROM ri i {$within['join']} WHERE {$where}", $binds )[0][0] ?? 0 ), null ]
			: self::summed( $r, $with, $within['join'], $where, $binds, $since );
		$page                            = $r->rows(
			"{$with} SELECT " . \implode( ', ', $columns ) . " FROM ri i {$within['join']} LEFT JOIN " . Intel_Graph_Row_Schema::all( $r, 'extractions' )
			. " x ON x.item_key = i.item_key WHERE {$where} ORDER BY {$within['order']} LIMIT ? OFFSET ?",
			[ ...$binds, $limit, $offset ]
		);
		$items                           = \array_map( static fn ( array $row ): array => self::excerpted( \array_combine( \array_keys( $columns ), $row ) ), $page );
		$linked                          = self::entities_of( $r, $g, \array_map( static fn ( array $item ): string => Core::as_string( $item['item_key'] ), $items ) );
		return [
			'items' => \array_map( static fn ( array $item ): array => \array_diff_key( $item, $within['fields'] ) + [ 'entities' => $linked[ Core::as_string( $item['item_key'] ) ] ?? [] ] + \array_intersect_key( $item, $within['fields'] ), $items ),
			'total' => $total,
		] + ( null === $summary ? [] : [ 'summary' => $summary ] );
	}

	/**
	 * The entities each item is about, but its topics and any whose survivor
	 * is rejected, as `search` lists them: by survivor, an open client
	 * (`open_clients()`) first, then a subject, each with its name, its
	 * link's state and role, and whether it is an open client, read in one
	 * statement; none for no items.
	 *
	 * @param Row_Reader   $r    The reader.
	 * @param Graph        $g    The graph the read opened.
	 * @param list<string> $keys The item keys.
	 * @return array<string,list<array{entity_key:mixed,name:mixed,state:mixed,role:mixed,client:bool}>> Each item with an entity => them.
	 */
	private static function entities_of( Row_Reader $r, array $g, array $keys ): array {
		$linked = [];
		if ( [] === $keys ) {
			return $linked;
		}
		[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' )', $keys );
		$rows                  = $r->rows(
			"{$g['with']}{$live}, " . self::open_clients( $r ) . ' SELECT live.item_key, live.entity_key, ' . self::name_of( $r, 'live.entity_key' ) . ', live.state, live.role, oc.entity_key IS NOT NULL AS client'
			. ' FROM live LEFT JOIN oc ON oc.entity_key = live.entity_key WHERE NOT live.topical AND ' . self::unrejected( $r, 'live.entity_key' ) . " ORDER BY client DESC, live.role = 'subject' DESC, live.entity_key",
			[ ...$g['binds'], ...$live_binds ]
		);
		foreach ( $rows as [ $item_key, $entity, $name, $state, $role, $client ] ) {
			$linked[ Core::as_string( $item_key ) ][] = [ 'entity_key' => $entity, 'name' => $name, 'state' => $state, 'role' => $role, 'client' => 1 === Core::as_int( $client ) ];
		}
		return $linked;
	}

	/**
	 * A listed item with the body's opening its row read replaced by its
	 * excerpt, so no answer carries the body itself.
	 *
	 * @param array<string,mixed> $item The item as its row read it.
	 * @return array<string,mixed>
	 */
	private static function excerpted( array $item ): array {
		$item['excerpt'] = self::excerpt( Core::as_string( $item['excerpt'] ) );
		return $item;
	}

	/**
	 * A body's opening as plain text, cut on a word boundary to
	 * EXCERPT_CHARS with an ellipsis when cut, as it is when the read stopped
	 * short of the body's end, whose last word may be partial. A `<script>`
	 * or `<style>` left open is cut first: stripping tags keeps what an
	 * unclosed one holds, which is source, and what precedes it is whole.
	 *
	 * @param string $head The body's first EXCERPT_READ_CHARS characters.
	 */
	private static function excerpt( string $head ): string {
		$text  = self::closed( $head );
		$plain = Entity_Index::plain( $text );
		if ( '' === $plain ) {
			return '';
		}
		if ( \mb_strlen( $plain ) <= self::EXCERPT_CHARS ) {
			if ( $text !== $head ) {
				return "{$plain}…";
			}
			if ( \mb_strlen( $head ) < self::EXCERPT_READ_CHARS ) {
				return $plain;
			}
		}
		$kept  = \mb_substr( $plain, 0, self::EXCERPT_CHARS + 1 );
		$space = \mb_strrpos( $kept, ' ' );
		return \mb_substr( $kept, 0, false === $space ? self::EXCERPT_CHARS : $space ) . '…';
	}

	/**
	 * Text up to any `<script` or `<style` left unclosed.
	 *
	 * @param string $text The text.
	 */
	private static function closed( string $text ): string {
		foreach ( [ 'script', 'style' ] as $tag ) {
			$open = \strripos( $text, "<{$tag}" );
			if ( false !== $open && false === \stripos( $text, "</{$tag}", $open ) ) {
				$text = \substr( $text, 0, $open );
			}
		}
		return $text;
	}

	/**
	 * How many items a read admits, and, of those dated at or after
	 * `$since`, how many there are, how many open clients they are about by
	 * a subject or a mention (`item_clients()`), and each kind's items and
	 * clients, in the one statement that counts them.
	 *
	 * @param Row_Reader        $r     The reader.
	 * @param string            $with  The read's `WITH` clauses, `live` among them.
	 * @param string            $join  What it joins to `ri i`.
	 * @param string            $where Its condition.
	 * @param list<scalar|null> $binds Their binds.
	 * @param int               $since The first instant counted, UTC seconds.
	 * @return array{0:int,1:array{items:int,clients:int,kinds:array<string,array{items:int,clients:int}>}} The total and the summary, each kind by name.
	 */
	private static function summed( Row_Reader $r, string $with, string $join, string $where, array $binds, int $since ): array {
		$time    = Intel_Graph_Row_Schema::ITEM_TIME;
		$counts  = 'COUNT( DISTINCT item_key ), COUNT( DISTINCT CASE WHEN t >= ? THEN item_key END ), COUNT( DISTINCT CASE WHEN t >= ? THEN entity_key END ) FROM wc';
		$rows    = $r->rows(
			"{$with}, " . self::item_clients( $r ) . ", w AS MATERIALIZED ( SELECT i.item_key, x.kind, {$time} AS t FROM ri i {$join} LEFT JOIN " . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . " x ON x.item_key = i.item_key WHERE {$where} ),"
			. ' wc AS MATERIALIZED ( SELECT w.item_key, w.kind, w.t, ic.entity_key FROM w LEFT JOIN ic ON ic.item_key = w.item_key )'
			. " SELECT 1, NULL, {$counts} UNION ALL SELECT 0, kind, {$counts} WHERE kind IS NOT NULL GROUP BY kind",
			[ ...$binds, $since, $since, $since, $since ]
		);
		$total   = 0;
		$summary = [
			'items'   => 0,
			'clients' => 0,
			'kinds'   => [],
		];
		foreach ( $rows as [ $every, $kind, $admitted, $items, $clients ] ) {
			if ( 1 === Core::as_int( $every ) ) {
				$total              = Core::as_int( $admitted );
				$summary['items']   = Core::as_int( $items );
				$summary['clients'] = Core::as_int( $clients );
				continue;
			}
			$summary['kinds'][ Core::as_string( $kind ) ] = [
				'items'   => Core::as_int( $items ),
				'clients' => Core::as_int( $clients ),
			];
		}
		return [ $total, $summary ];
	}

	/**
	 * Every lens, by name: its key, name, description and filters, its
	 * entity and topics only those the caller may see now (`sees()`).
	 *
	 * @return array<string,mixed> `{ lenses: [ { lens_key, name, description, filters } ], available }`.
	 */
	public static function lenses(): array {
		return self::answer(
			[ 'lenses' => [] ],
			static function ( Row_Reader $r, array $g ): array {
				$lenses = [];
				foreach ( $r->rows( 'SELECT lens_key, name, description, filters FROM ' . Intel_Graph_Row_Schema::all( $r, 'lenses' ) . ' l ORDER BY name, lens_key', [] ) as [ $key, $name, $description, $filters ] ) {
					$lens                    = self::lens_of( $filters );
					$lens['entity']          = null !== $lens['entity'] && self::sees( $r, $g, $lens['entity'] ) ? $lens['entity'] : null;
					$lens['lists']['topics'] = \array_values( \array_filter( $lens['lists']['topics'], static fn ( string $topic ): bool => self::sees( $r, $g, $topic ) ) );
					$lenses[]                = [ 'lens_key' => $key, 'name' => $name, 'description' => $description, 'filters' => self::flat( $lens ) ];
				}
				return [ 'lenses' => $lenses ];
			}
		);
	}

	/**
	 * Whether the caller may see a key: its chain resolves and `visible()`
	 * answers it.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key An entity key.
	 */
	private static function sees( Row_Reader $r, array $g, string $key ): bool {
		return '' !== ( $g['survivors'][ $key ] ?? $key ) && null !== self::visible( $r, $g, $key );
	}

	/**
	 * A lens's filters with none set, in the shape every lens is saved in.
	 *
	 * @return array<string,mixed>
	 */
	public static function unfiltered(): array {
		return self::flat( self::no_lens() );
	}

	/**
	 * A lens's filters in the shape it is saved and answered in: each list
	 * by its field, then `entity`, `days`, `tiers`, `by` and `bucket`.
	 *
	 * @param Lens $lens The lens.
	 * @return array<string,mixed>
	 */
	private static function flat( array $lens ): array {
		return $lens['lists'] + \array_diff_key( $lens, [ 'lists' => true ] );
	}

	/**
	 * One entity, read as its survivor: its record, the keys merged into it,
	 * every name, identifier and role it or they carry, every topic they are
	 * tagged with that the caller may see (`visible()`), how many readable
	 * items are about it and how many of those links are proposed, how
	 * many its own site published, and a client's `status()`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the key does not name an entity,
	 *                                   or its merge chain does not resolve.
	 */
	public static function entity( array $args ): array {
		$key   = Core::as_string( $args['key'] );
		$empty = [ 'entity_key' => $key, 'type' => null, 'name' => null, 'state' => null, 'merged_from' => [], 'names' => [], 'identifiers' => [], 'roles' => [], 'topics' => [], 'counts' => [ 'items' => 0, 'proposed_links' => 0 ], 'published' => [ 'count' => 0 ], 'status' => null ];
		return self::answer( $empty, static fn ( Row_Reader $r, array $g ): array => self::entity_record( $r, $g, $key ) );
	}

	/**
	 * Read one entity's record, if `visible()` lets the caller see it.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key The key asked for.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the key does not name an entity,
	 *                                   or names one this caller may not read.
	 */
	private static function entity_record( Row_Reader $r, array $g, string $key ): array {
		$survivor              = self::seen( $r, $g, $key );
		$keys                  = self::keys_of( $survivor, $g );
		$in                    = Intel_Graph_Row_Schema::marks( $keys );
		$record                = $r->rows( 'SELECT type, ' . self::name_of( $r, 'e.entity_key' ) . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' e WHERE entity_key = ? LIMIT 1', [ $survivor ] )[0] ?? null;
		[ $live, $live_binds ] = self::live( $r, "{t}.entity_key IN ( {$in} )", $keys );
		$pairs                 = static fn ( string $table, string $a, string $b ): array => \array_map(
			static fn ( array $row ): array => [ $a => $row[0], $b => $row[1] ],
			$r->rows( "SELECT DISTINCT {$a}, {$b} FROM " . Intel_Graph_Row_Schema::all( $r, $table ) . " t WHERE entity_key IN ( {$in} ) ORDER BY 1, 2", $keys )
		);
		$roles                 = \array_map(
			static fn ( array $row ): array => [ 'role' => $row[0], 'from_ts' => $row[1], 'to_ts' => $row[2] ],
			$r->rows( 'SELECT DISTINCT role, from_ts, to_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . " t WHERE entity_key IN ( {$in} ) ORDER BY from_ts, role", $keys )
		);
		$topics                = \array_filter(
			$r->rows(
				"{$g['with']} SELECT DISTINCT topic_key, " . self::name_of( $r, 'topic_key' ) . ' FROM ( SELECT COALESCE( mg.value, t.topic_key ) AS topic_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_topics' )
				. " t LEFT JOIN mg ON mg.key = t.topic_key WHERE t.entity_key IN ( {$in} ) ) WHERE topic_key <> '' ORDER BY 1",
				[ ...$g['binds'], ...$keys ]
			),
			static fn ( array $row ): bool => null !== self::visible( $r, $g, Core::as_string( $row[0] ) )
		);
		$counts                = $r->rows( "{$g['with']}{$live} SELECT COUNT( DISTINCT item_key ), COUNT( CASE WHEN state = 'proposed' THEN 1 END ) FROM live WHERE entity_key = ?", [ ...$g['binds'], ...$live_binds, $survivor ] )[0] ?? [ 0, 0 ];
		[ $sites, $site_binds ] = self::live( $r, "{t}.entity_key IN ( {$in} )", $keys, [ Intel_Event::PUBLISHER ] );
		$published             = $r->rows( "{$g['with']}{$sites} SELECT COUNT( DISTINCT item_key ) FROM live WHERE entity_key = ?", [ ...$g['binds'], ...$site_binds, $survivor ] )[0][0] ?? 0;
		return [
			'entity_key'  => $survivor,
			'type'        => $record[0] ?? null,
			'name'        => $record[1] ?? null,
			'state'       => self::state_of( $r, $survivor ),
			'merged_from' => \array_slice( $keys, 1 ),
			'names'       => $pairs( 'names', 'kind', 'value' ),
			'identifiers' => $pairs( 'identifiers', 'kind', 'value' ),
			'roles'       => $roles,
			'topics'      => \array_values( \array_map( static fn ( array $row ): array => [ 'topic_key' => $row[0], 'name' => $row[1] ], $topics ) ),
			'counts'      => [ 'items' => Core::as_int( $counts[0] ), 'proposed_links' => Core::as_int( $counts[1] ) ],
			'published'   => [ 'count' => Core::as_int( $published ) ],
			'status'      => self::status( $r, $g, $survivor, $roles ),
		];
	}

	/**
	 * A client's status: when its open client role began, how many readable
	 * items about it are of each STATUS_KINDS kind, when the newest of them
	 * is dated, and whether one about it dated after that start is extracted
	 * as CHURNED, which the Entity view offers to end the role on; null for
	 * an entity holding no open client role. Its signals are the `signals`
	 * read's, which the page already makes.
	 *
	 * @param Row_Reader                                            $r        The reader.
	 * @param Graph                                                 $g        The graph the read opened.
	 * @param string                                                $survivor The entity.
	 * @param list<array{role:mixed,from_ts:mixed,to_ts:mixed}> $roles    Every role row of its keys.
	 * @return array{client_since:int,kinds:array<string,int>,last_item_ts:int|null,churn_open:bool}|null
	 */
	private static function status( Row_Reader $r, array $g, string $survivor, array $roles ): ?array {
		$open = \array_filter( $roles, static fn ( array $row ): bool => self::CLIENT === $row['role'] && 0 === Core::as_int( $row['to_ts'] ) );
		if ( [] === $open ) {
			return null;
		}
		$since                           = \min( \array_map( static fn ( array $row ): int => Core::as_int( $row['from_ts'] ), $open ) );
		$filter                          = self::filter_of( [ 'entity' => $survivor ] );
		[ $with, $where, $lead, $binds ] = self::narrowed( $r, $g, $filter, false );
		$time                            = Intel_Graph_Row_Schema::ITEM_TIME;
		$counted                         = \implode( ', ', \array_fill( 0, \count( self::STATUS_KINDS ), 'COUNT( DISTINCT CASE WHEN x.kind = ? THEN i.item_key END )' ) );
		$row                             = $r->rows(
			"{$with} SELECT MAX( {$time} ), COUNT( CASE WHEN x.kind = ? AND {$time} > ? THEN 1 END ) > 0, {$counted} FROM ri i LEFT JOIN " . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . " x ON x.item_key = i.item_key WHERE {$where}",
			[ ...$lead, self::CHURNED, $since, ...self::STATUS_KINDS, ...$binds ]
		)[0];
		[ $last, $churned ]              = \array_splice( $row, 0, 2 );
		return [
			'client_since' => $since,
			'kinds'        => \array_combine( self::STATUS_KINDS, \array_map( Core::as_int( ... ), $row ) ),
			'last_item_ts' => null === $last ? null : Core::as_int( $last ),
			'churn_open'   => 1 === Core::as_int( $churned ),
		];
	}

	/**
	 * The survivor a key answers as, refused as `no entity` when the caller
	 * may not see it (`visible()`).
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key An entity key.
	 * @throws \InvalidArgumentException When the caller may not see it, or its merge chain does not resolve.
	 */
	public static function seen( Row_Reader $r, array $g, string $key ): string {
		return self::visible( $r, $g, $key ) ?? throw new \InvalidArgumentException( \esc_html( "no entity: {$key}" ) );
	}

	/**
	 * Whether a key is taken: visible to the caller and no longer a
	 * machine's proposal. A key the caller cannot see is free, so a person's
	 * creation over it answers as one over a missing key.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key An entity key.
	 * @throws \InvalidArgumentException When its merge chain does not resolve.
	 */
	public static function taken( Row_Reader $r, array $g, string $key ): bool {
		$survivor = self::visible( $r, $g, $key );
		return null !== $survivor && 'proposed' !== self::state_of( $r, $survivor );
	}

	/**
	 * The entities sharing the most readable items with one, each with how
	 * many it shares and the newest it shares as citations, each its key,
	 * URL and title.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `limit`.
	 * @return array<string,mixed> `{ entities, available }`.
	 * @throws \InvalidArgumentException On a bad limit or an unresolvable key.
	 */
	public static function related( array $args ): array {
		$key   = Core::as_string( $args['key'] );
		$limit = self::limit( $args );
		return self::answer(
			[ 'entities' => [] ],
			static function ( Row_Reader $r, array $g ) use ( $key, $limit ): array {
				$survivor              = self::survivor( $key, $g );
				$keys                  = self::keys_of( $survivor, $g );
				[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' n WHERE entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) AND n.role IN ( ' . Intel_Graph_Row_Schema::marks( Intel_Event::ABOUT_ROLES ) . ' ) )', [ ...$keys, ...Intel_Event::ABOUT_ROLES ] );
				$rows                  = $r->rows(
					"{$g['with']}{$live}, mine AS ( SELECT DISTINCT item_key FROM live WHERE entity_key = ? ),"
					. ' pairs AS ( SELECT DISTINCT live.entity_key, live.item_key, live.url, live.title, live.t FROM live JOIN mine ON mine.item_key = live.item_key WHERE live.entity_key <> ? ),'
					. ' best AS ( SELECT entity_key, COUNT(*) AS n FROM pairs GROUP BY entity_key ORDER BY n DESC, entity_key LIMIT ? ),'
					. ' cited AS ( SELECT p.entity_key, p.item_key, p.url, p.title, ROW_NUMBER() OVER ( PARTITION BY p.entity_key ORDER BY p.t DESC, p.item_key DESC ) AS rn FROM pairs p JOIN best ON best.entity_key = p.entity_key )'
					. ' SELECT best.entity_key, ' . self::name_of( $r, 'best.entity_key' ) . ', ' . self::type_of( $r, 'best.entity_key' )
					. ', best.n, cited.item_key, cited.url, cited.title FROM best JOIN cited ON cited.entity_key = best.entity_key WHERE cited.rn <= ? ORDER BY best.n DESC, best.entity_key, cited.rn',
					[ ...$g['binds'], ...$live_binds, $survivor, $survivor, $limit, self::CITATIONS ]
				);
				$entities              = [];
				foreach ( $rows as [ $entity, $name, $type, $shared, $item, $url, $title ] ) {
					$entity              = Core::as_string( $entity );
					$entities[ $entity ] ??= [ 'entity_key' => $entity, 'name' => $name, 'type' => $type, 'shared_items' => Core::as_int( $shared ), 'items' => [] ];
					$entities[ $entity ]['items'][] = [ 'item_key' => $item, 'url' => $url, 'title' => $title ];
				}
				return [ 'entities' => \array_values( $entities ) ];
			}
		);
	}

	/**
	 * The claims naming an entity as subject or object, newest first, each
	 * resting on a readable item that links its subject by a live link, so
	 * the caller sees every subject, and answering its item's URL and title
	 * and its subject's name (`name_of()`). An object answers its key and
	 * its name only when `visible()` lets the caller see it, else the item's
	 * spelling of it and a null name; a claim holding neither for the caller
	 * is left out.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `predicate`, `limit`.
	 * @return array<string,mixed> `{ claims: [ { subject, subject_name, predicate, object, object_name, confidence, item_key, url, title } ], available }`.
	 * @throws \InvalidArgumentException On a bad limit or an unresolvable key.
	 */
	public static function claims( array $args ): array {
		$key       = Core::as_string( $args['key'] );
		$predicate = self::given( $args, 'predicate' );
		$limit     = self::limit( $args );
		return self::answer(
			[ 'claims' => [] ],
			static function ( Row_Reader $r, array $g ) use ( $key, $predicate, $limit ): array {
				$survivor              = self::survivor( $key, $g );
				$keys                  = self::keys_of( $survivor, $g );
				$in                    = Intel_Graph_Row_Schema::marks( $keys );
				[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'claims' ) . " n WHERE subject_key IN ( {$in} ) OR object_key IN ( {$in} ) )", [ ...$keys, ...$keys ], Intel_Event::LINK_ROLES );
				$only                  = null === $predicate ? '' : ' AND cl.predicate = ?';
				$rows                  = $r->rows(
					"{$g['with']}{$live}, cl AS ( SELECT COALESCE( ms.value, c.subject_key ) AS subject, c.predicate, COALESCE( mo.value, c.object_key ) AS object_key, c.object_text, c.confidence, c.item_key, c.ord"
					. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'claims' ) . ' c LEFT JOIN mg ms ON ms.key = c.subject_key LEFT JOIN mg mo ON mo.key = c.object_key'
					. " WHERE c.subject_key IN ( {$in} ) OR c.object_key IN ( {$in} ) )"
					. ' SELECT cl.subject, ' . self::name_of( $r, 'cl.subject' ) . ', cl.predicate, cl.object_key, cl.object_text, cl.confidence, cl.item_key, live.url, live.title'
					. ' FROM cl JOIN live ON live.item_key = cl.item_key AND live.entity_key = cl.subject AND NOT live.topical'
					. " WHERE ( cl.subject = ? OR cl.object_key = ? ){$only} ORDER BY live.t DESC, cl.item_key, cl.ord LIMIT ?",
					[ ...$g['binds'], ...$live_binds, ...$keys, ...$keys, $survivor, $survivor, ...( null === $predicate ? [] : [ $predicate ] ), $limit ]
				);
				$claims                = [];
				$sees                  = [];
				foreach ( $rows as [ $subject, $subject_name, $said, $object_key, $object_text, $confidence, $item, $url, $title ] ) {
					$key          = Core::as_string( $object_key );
					$sees[ $key ] ??= '' !== $key && null !== self::visible( $r, $g, $key );
					$object       = $sees[ $key ] ? $key : Core::as_string( $object_text );
					if ( '' !== $object ) {
						$claims[] = [ 'subject' => $subject, 'subject_name' => $subject_name ?? $subject, 'predicate' => $said, 'object' => $object, 'object_name' => $sees[ $key ] ? $key : null, 'confidence' => $confidence, 'item_key' => $item, 'url' => $url, 'title' => $title ];
					}
				}
				$names = self::names_of( $r, \array_map( 'strval', \array_keys( \array_filter( $sees ) ) ) );
				return [ 'claims' => \array_map( static fn ( array $claim ): array => null === $claim['object_name'] ? $claim : \array_replace( $claim, [ 'object_name' => $names[ $claim['object'] ] ?? $claim['object'] ] ), $claims ) ];
			}
		);
	}

	/**
	 * Each key's name, as `name_of()` reads it, in one read; a key holding
	 * none is left out.
	 *
	 * @param Row_Reader   $r    The reader.
	 * @param list<string> $keys Entity keys.
	 * @return array<string,string>
	 */
	private static function names_of( Row_Reader $r, array $keys ): array {
		$names = [];
		if ( [] === $keys ) {
			return $names;
		}
		foreach ( $r->rows( 'SELECT j.value, ' . self::name_of( $r, 'j.value' ) . ' FROM json_each( ? ) j', [ \wp_json_encode( $keys, \JSON_THROW_ON_ERROR ) ] ) as [ $key, $name ] ) {
			if ( null !== $name ) {
				$names[ Core::as_string( $key ) ] = Core::as_string( $name );
			}
		}
		return $names;
	}

	/**
	 * The survivor a key answers as, when the caller may see that entity:
	 * one a person accepted, or a proposed or rejected one a readable item
	 * cites, by a link or as the item it was proposed from. Null for any
	 * other key.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key An entity key.
	 * @throws \InvalidArgumentException When its merge chain does not resolve.
	 */
	public static function visible( Row_Reader $r, array $g, string $key ): ?string {
		$survivor = self::survivor( $key, $g );
		$state    = self::state_of( $r, $survivor );
		if ( null === $state && [] === $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' e WHERE entity_key = ? LIMIT 1', [ $survivor ] ) ) {
			return null;
		}
		if ( 'accepted' === $state ) {
			return $survivor;
		}
		$keys                  = self::keys_of( $survivor, $g );
		$in                    = Intel_Graph_Row_Schema::marks( $keys );
		[ $live, $live_binds ] = self::live( $r, "{t}.entity_key IN ( {$in} )", $keys, Intel_Event::LINK_ROLES );
		[ $cites, $cited ]     = self::cites( $r, "ev.subject IN ( {$in} )", $keys );
		return [] === $r->rows( "{$g['with']}{$live}, {$cites} SELECT 1 FROM cites WHERE entity_key = ? LIMIT 1", [ ...$g['binds'], ...$live_binds, ...$cited, $survivor ] ) ? null : $survivor;
	}

	/**
	 * An entity's latest state, or null when it has none.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The entity key.
	 */
	public static function state_of( Row_Reader $r, string $key ): ?string {
		$state = $r->rows( 'SELECT state FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_states' ) . ' s WHERE entity_key = ? ORDER BY ts DESC LIMIT 1', [ $key ] )[0][0] ?? null;
		return null === $state ? null : Core::as_string( $state );
	}

	/**
	 * Readable items counted by kind, topic or theme per day, week or month,
	 * the newest bucket first, filtered as `search` filters them but for
	 * `q`, each read through `lens` when the call names one, and each
	 * counted once per story (`stories()`); each row cites its newest items,
	 * each its key, URL and title, and a theme row first names its open
	 * clients (`tally()`). The axis and the bucket are the call's,
	 * else its lens's, else DEFAULT_AXIS and DEFAULT_BUCKET.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array<string,mixed> `{ rows, available }`.
	 * @throws \InvalidArgumentException On an unknown axis or bucket, a bad
	 *                                   day, an unresolvable key, or a lens
	 *                                   that was never saved.
	 */
	public static function trends( array $args ): array {
		$by     = self::given( $args, 'by' );
		$bucket = self::given( $args, 'bucket' );
		self::check_trend( $by, $bucket );
		return self::through( $args, [ 'rows' => [] ], static fn ( Row_Reader $r, array $g, array $filter, array $lens ): array => [ 'rows' => self::counts( $r, $g, $filter, ...self::axis( $by, $bucket, $lens ) ) ] );
	}

	/**
	 * Run a read through the lens the call names, on the graph the read
	 * opens (`lensed()`).
	 *
	 * @param array<array-key,mixed>                                       $args  The bound verb args.
	 * @param array<string,mixed>                                          $empty The answer with no rows.
	 * @param \Closure(Row_Reader,Graph,Filter,Lens): array<string,mixed> $read  The read.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad day, a lens key that sanitizes
	 *                                   to nothing, or a lens that was never
	 *                                   saved.
	 */
	private static function through( array $args, array $empty, \Closure $read ): array {
		[ $call, $name ] = self::asked( $args );
		return self::available( $empty, static fn ( Row_Reader $r ): array => self::lensed( $r, self::graph( $r ), $call, $name, $read ) );
	}

	/**
	 * Refuse a trend axis or bucket outside its set; null names none.
	 *
	 * @param string|null $by     The axis.
	 * @param string|null $bucket The bucket.
	 * @throws \InvalidArgumentException Naming the set.
	 */
	public static function check_trend( ?string $by, ?string $bucket ): void {
		if ( null !== $by ) {
			self::one_of( 'by', $by, self::trend_axes() );
		}
		if ( null !== $bucket ) {
			self::one_of( 'bucket', $bucket, self::buckets() );
		}
	}

	/**
	 * The spans a trend row may count.
	 *
	 * @return list<string>
	 */
	public static function buckets(): array {
		return \array_keys( self::BUCKETS );
	}

	/**
	 * What a trend may count.
	 *
	 * @return list<string>
	 */
	public static function trend_axes(): array {
		return \array_keys( self::TREND_AXES );
	}

	/**
	 * The signals of the latest complete bucket, and the trend they stand
	 * in, both read by `signaled()`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `lens`, `entity`.
	 * @return array<string,mixed> `{ signals: [ { axis, signal, bucket, by, value, count, baseline, items } ], trend: [ { bucket, count } ], available }`.
	 * @throws \InvalidArgumentException As `trends()`.
	 */
	public static function signals( array $args ): array {
		[ $call, $name ] = self::asked( $args );
		return self::available(
			[
				'signals' => [],
				'trend'   => [],
			],
			static function ( Row_Reader $r ) use ( $call, $name ): array {
				[ $signals, $trend ] = self::signaled( $r, self::graph( $r ), $call, $name, 0.0 );
				return [
					'signals' => $signals,
					'trend'   => $trend,
				];
			}
		);
	}

	/**
	 * `signals()` on an open read, answering none unless the latest
	 * complete bucket closed after an instant.
	 *
	 * @api Read by the watcher's digest, as each watch's owner, and the briefing.
	 * @param Row_Reader             $r     The reader.
	 * @param Graph                  $g     The owner's graph.
	 * @param array<array-key,mixed> $args  `lens` or `entity`.
	 * @param float                  $after The instant, UTC seconds.
	 * @return list<array<string,mixed>>
	 * @throws \InvalidArgumentException As `trends()`.
	 */
	public static function spikes( Row_Reader $r, array $g, array $args, float $after ): array {
		[ $call, $name ] = self::asked( $args );
		return self::signaled( $r, $g, $call, $name, $after )[0];
	}

	/**
	 * An entity named with no lens, as `signals_of()` reads each entity it
	 * is given, so one entity and many read alike; else the spikes of what
	 * is about the lens, its entity included, counted as `trends` counts for
	 * the same lens and entity, so its filters, axis, bucket and tiers apply
	 * alike: `spiking()` over `filtered()`, its one subject `''`, muted on
	 * the kind axis, where each value spikes only when it was counted before
	 * the window; none unless the latest complete bucket closed after
	 * `$after`. Beside them, the trend they stand in (`trend_of()`), its
	 * totals read in the statement that counts the spikes.
	 *
	 * @param Row_Reader  $r     The reader.
	 * @param Graph       $g     The graph.
	 * @param Filter      $call  The call's own filters.
	 * @param string|null $name  The lens key, null for none.
	 * @param float       $after The instant, UTC seconds.
	 * @return array{0:list<array<string,mixed>>,1:list<array{bucket:string,count:int}>} The spikes, and the trend.
	 * @throws \InvalidArgumentException On a lens that was never saved, or an
	 *                                   entity whose merge chain does not
	 *                                   resolve.
	 */
	private static function signaled( Row_Reader $r, array $g, array $call, ?string $name, float $after ): array {
		if ( null === $name && null !== $call['entity'] ) {
			$survivor             = self::survivor( $call['entity'], $g );
			[ $signals, $trends ] = self::entities_read( $r, $g, [ $survivor ], $after );
			return [ $signals[ $survivor ], $trends[ $survivor ] ];
		}
		return self::lensed(
			$r,
			$g,
			$call,
			$name,
			static function ( Row_Reader $r, array $g, array $filter, array $lens ) use ( $after ): array {
				[ $by, $span ] = self::axis( null, null, $lens );
				$starts        = self::window( $r, $span, $after );
				if ( null === $starts ) {
					return [ [], [] ];
				}
				[ $spikes, $totals ] = self::spiking( $r, self::filtered( $r, $g, $filter ), $filter['days'] ?? self::all_days(), $starts, $by, $span, true, 'kind' === $by );
				return [ \array_map( static fn ( array $spike ): array => self::signal( 'about', 'spike', $spike ), $spikes[''] ?? [] ), self::trend_of( $totals[''] ?? [], $starts ) ];
			}
		);
	}

	/**
	 * What a trend counts and the bucket it counts by: the call's, else the
	 * lens's, else DEFAULT_AXIS and DEFAULT_BUCKET.
	 *
	 * @param string|null $by     The call's axis.
	 * @param string|null $bucket The call's bucket.
	 * @param Lens        $lens   The lens.
	 * @return array{0:string,1:string} The axis and its bucket's SQL over `t`.
	 */
	private static function axis( ?string $by, ?string $bucket, array $lens ): array {
		return [ $by ?? $lens['by'] ?? self::DEFAULT_AXIS, self::BUCKETS[ $bucket ?? $lens['bucket'] ?? self::DEFAULT_BUCKET ] ];
	}

	/**
	 * Run a read on a graph through a lens, if one is named: each of the
	 * lens's filters stands for one the call does not name, its days for
	 * the call's `since` and `until` together, and the graph's `ri` holds
	 * the caller's readable tiers narrowed to the lens's, never widened. A
	 * lens's entity or topic the caller cannot see stays in the filter,
	 * where it matches no item the caller may read, so it narrows to
	 * nothing and never widens.
	 *
	 * @template T
	 * @param Row_Reader                              $r    The reader.
	 * @param Graph                                   $g    The caller's graph.
	 * @param Filter                                  $call The call's own filters.
	 * @param string|null                             $name The lens key, null for none.
	 * @param \Closure(Row_Reader,Graph,Filter,Lens): T $read The read.
	 * @return T
	 * @throws \InvalidArgumentException On a lens that was never saved.
	 */
	private static function lensed( Row_Reader $r, array $g, array $call, ?string $name, \Closure $read ): mixed {
		$lens = null === $name ? self::no_lens() : self::lens( $r, $name );
		$g    = [] === $lens['tiers'] ? $g : self::bound( $r, $g['survivors'], \array_values( \array_intersect( Intel_Tiers::readable(), $lens['tiers'] ) ) );
		return $read( $r, $g, self::over( $lens, $call ), $lens );
	}

	/**
	 * The call's filters over a lens's: each the call names replaces the
	 * lens's filter; the call's days, from `since`, `until` or both, replace the
	 * lens's window whole.
	 *
	 * @param Lens   $lens The lens, `no_lens()` for none.
	 * @param Filter $call The call's own filters.
	 * @return Filter
	 */
	private static function over( array $lens, array $call ): array {
		$lists = [];
		foreach ( \array_keys( self::LENS_LISTS ) as $field ) {
			$lists[ $field ] = [] === $call['lists'][ $field ] ? $lens['lists'][ $field ] : $call['lists'][ $field ];
		}
		return [
			'q'      => $call['q'],
			'entity' => $call['entity'] ?? $lens['entity'],
			'about'  => $call['about'],
			'lists'  => $lists,
			'days'   => $call['days'] ?? ( null === $lens['days'] ? null : self::last_days( $lens['days'] ) ),
		];
	}

	/**
	 * The last `$days` UTC days, today the last of them, as `days` bounds
	 * them; an item dated past today still falls inside.
	 *
	 * @api Intelligence_Briefing reads its window by it.
	 * @param int $days How many days.
	 * @return array{0:int,1:int}
	 */
	public static function last_days( int $days ): array {
		$today = \intdiv( Intel_Event::now(), Intel_Graph_Row_Schema::DAY_SECONDS ) * Intel_Graph_Row_Schema::DAY_SECONDS;
		return [ $today - ( $days - 1 ) * Intel_Graph_Row_Schema::DAY_SECONDS, self::all_days()[1] ];
	}

	/**
	 * A lens's filters, read back as saved.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The lens key.
	 * @return Lens
	 * @throws \InvalidArgumentException When the key does not name a lens.
	 */
	private static function lens( Row_Reader $r, string $key ): array {
		$filters = $r->rows( 'SELECT filters FROM ' . Intel_Graph_Row_Schema::all( $r, 'lenses' ) . ' l WHERE lens_key = ? LIMIT 1', [ $key ] )[0][0] ?? throw new \InvalidArgumentException( \esc_html( "no lens: {$key}" ) );
		return self::lens_of( $filters );
	}

	/**
	 * A lens's stored filters, typed, its lists gathered by LENS_LISTS.
	 *
	 * @param mixed $filters The `filters` column, JSON.
	 * @return Lens
	 * @throws \JsonException When the column does not decode.
	 */
	private static function lens_of( mixed $filters ): array {
		$saved = Core::arr( \json_decode( Core::as_string( $filters ), true, 512, \JSON_THROW_ON_ERROR ) );
		$text  = static fn ( mixed $value ): ?string => null === $value ? null : Core::as_string( $value );
		$lists = [];
		foreach ( \array_keys( self::LENS_LISTS ) as $field ) {
			$lists[ $field ] = self::strings( $saved[ $field ] );
		}
		return [
			'lists'  => $lists,
			'entity' => $text( $saved['entity'] ),
			'days'   => null === $saved['days'] ? null : Core::as_int( $saved['days'] ),
			'tiers'  => self::strings( $saved['tiers'] ),
			'by'     => $text( $saved['by'] ),
			'bucket' => $text( $saved['bucket'] ),
		];
	}

	/**
	 * A lens setting no filter: what a read through none applies.
	 *
	 * @return Lens
	 */
	private static function no_lens(): array {
		return [
			'lists'  => \array_fill_keys( \array_keys( self::LENS_LISTS ), [] ),
			'entity' => null,
			'days'   => null,
			'tiers'  => [],
			'by'     => null,
			'bucket' => null,
		];
	}
	/**
	 * The filters a call names and the lens it names, its key slugged as a
	 * save slugs it.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array{0:Filter,1:?string}
	 * @throws \InvalidArgumentException On a bad day, or a lens key that sanitizes to nothing.
	 */
	private static function asked( array $args ): array {
		$name = self::given( $args, 'lens' );
		return [ self::filter_of( $args ), null === $name ? null : Intel_Event::lens_key( $name ) ];
	}

	/**
	 * Each entity's signals, named with no lens, every entity read in the
	 * same statements: the spikes of what is about it, per kind by week, a
	 * MUTE_KIND one left out, each kind spiking only when it was counted
	 * before the window; what its own site published, per week against its
	 * own baseline, a `burst` for each kind spiking by the rule `spiked_in()`
	 * applies, a kind it never put out before included; and, for a client,
	 * the `drop` or `silent` `falls()` reads.
	 *
	 * @api Read by Intelligence_Briefing over every open client.
	 * @param Row_Reader   $r         The reader.
	 * @param Graph        $g         The graph.
	 * @param list<string> $survivors The entities, as survivors.
	 * @param float        $after     The instant, UTC seconds.
	 * @return array<string,list<array<string,mixed>>> Each survivor => its signals, the about axis first.
	 */
	public static function signals_of( Row_Reader $r, array $g, array $survivors, float $after ): array {
		return self::entities_read( $r, $g, $survivors, $after )[0];
	}

	/**
	 * Each entity's signals, as `signals_of()` answers them, and the trend
	 * of what is about it, per week by kind, `other` muted, its totals read
	 * in the statement that counts the about spikes (`trend_of()`); none of
	 * either unless the latest complete week closed after `$after`.
	 *
	 * @param Row_Reader   $r         The reader.
	 * @param Graph        $g         The graph.
	 * @param list<string> $survivors The entities, as survivors.
	 * @param float        $after     The instant, UTC seconds.
	 * @return array{0:array<string,list<array<string,mixed>>>,1:array<string,list<array{bucket:string,count:int}>>} Each survivor => its signals, the about axis first; and each survivor => its trend.
	 */
	private static function entities_read( Row_Reader $r, array $g, array $survivors, float $after ): array {
		$signals = \array_fill_keys( $survivors, [] );
		$trends  = $signals;
		$span    = self::BUCKETS[ self::DEFAULT_BUCKET ];
		$starts  = [] === $survivors ? null : self::window( $r, $span, $after );
		if ( null === $starts ) {
			return [ $signals, $trends ];
		}
		[ $about, $totals ] = self::spiking( $r, self::linking( $r, $g, $survivors, Intel_Event::ABOUT_ROLES ), self::all_days(), $starts, self::DEFAULT_AXIS, $span, true, true );
		[ $bursts ]         = self::spiking( $r, self::linking( $r, $g, $survivors, [ Intel_Event::PUBLISHER ] ), self::all_days(), $starts, self::DEFAULT_AXIS, $span, false, false );
		$falls              = self::falls( $r, $g, $survivors, $starts, $span );
		foreach ( $survivors as $survivor ) {
			$signals[ $survivor ] = [
				...\array_map( static fn ( array $spike ): array => self::signal( 'about', 'spike', $spike ), $about[ $survivor ] ?? [] ),
				...( $falls[ $survivor ] ?? [] ),
				...\array_map( static fn ( array $spike ): array => self::signal( 'publisher', 'burst', $spike ), $bursts[ $survivor ] ?? [] ),
			];
			$trends[ $survivor ]  = self::trend_of( $totals[ $survivor ] ?? [], $starts );
		}
		return [ $signals, $trends ];
	}

	/**
	 * The trend a subject's spikes stand in: its stories in each of the
	 * SIGNAL_TREND latest complete buckets of the window, the latest first,
	 * 0 where a bucket counts none.
	 *
	 * @param array<string,int> $totals Each bucket's start => the subject's stories in it (`tally()`).
	 * @param list<string>      $starts The window's starts, newest first.
	 * @return list<array{bucket:string,count:int}>
	 */
	private static function trend_of( array $totals, array $starts ): array {
		return \array_map( static fn ( string $start ): array => [ 'bucket' => $start, 'count' => $totals[ $start ] ?? 0 ], \array_slice( $starts, 1, self::SIGNAL_TREND ) );
	}

	/**
	 * Each client among the entities whose own output's latest complete
	 * week fell (`fell()`): a `drop`, or `silent` when it is the first of two
	 * empty weeks, and neither while the output of every client fell that
	 * week too, since that is collection failing, not the client; that is
	 * read once, and only when a client fell.
	 *
	 * @param Row_Reader   $r         The reader.
	 * @param Graph        $g         The graph.
	 * @param list<string> $survivors The entities.
	 * @param list<string> $starts    The window's starts, newest first.
	 * @param string       $span      A BUCKETS expression over `t`.
	 * @return array<string,list<array<string,mixed>>> Each client that fell => its one signal.
	 */
	private static function falls( Row_Reader $r, array $g, array $survivors, array $starts, string $span ): array {
		$keys = [];
		foreach ( $survivors as $survivor ) {
			$keys[ $survivor ] = self::keys_of( $survivor, $g );
		}
		$clients = \array_map( 'strval', \array_keys( self::open_roles( $r, $keys, [ self::CLIENT ] ) ) );
		if ( [] === $clients ) {
			return [];
		}
		$weeks  = self::weeks_of( $starts );
		$weekly = self::tally( $r, self::linking( $r, $g, $clients, [ Intel_Event::PUBLISHER ] ), $weeks, null, $span, $weeks[0] )[0];
		$outage = null;
		$falls  = [];
		foreach ( $clients as $client ) {
			[ $counts, $rows ] = self::weeks( $weekly[ $client ] ?? [], $starts );
			$empty             = 0 === $counts[1] && 0 === $counts[2];
			if ( ! self::fell( $counts ) || ( $empty && 0 === $counts[3] ) ) {
				continue;
			}
			$outage ??= self::fell( self::weeks( self::counts( $r, $g, [ 'days' => self::weeks_of( $starts ) ] + self::filter_of( [ 'role' => [ self::CLIENT ] ] ), null, $span, [ Intel_Event::PUBLISHER ] ), $starts )[0] );
			if ( $outage ) {
				continue;
			}
			$week             = [ 'bucket' => $starts[1], 'by' => null, 'value' => null, 'count' => $counts[1], 'baseline' => \array_sum( \array_slice( $counts, 2 ) ) / (float) self::SIGNAL_BASELINE, 'items' => $rows[ $starts[1] ]['items'] ?? [] ];
			$falls[ $client ] = [ self::signal( 'publisher', $empty ? 'silent' : 'drop', $week ) ];
		}
		return $falls;
	}

	/**
	 * A counted bucket as a signal, led by its axis and its kind.
	 *
	 * @param string              $axis   `about` or `publisher`.
	 * @param string              $signal `spike`, `drop`, `silent` or `burst`.
	 * @param array<string,mixed> $row    The bucket, what it counts, its count, baseline and items.
	 * @return array<string,mixed>
	 */
	private static function signal( string $axis, string $signal, array $row ): array {
		return [
			'axis'   => $axis,
			'signal' => $signal,
		] + $row;
	}

	/**
	 * The filters a call names: `q`, `entity`, each list its repeatable arg
	 * gives, and the UTC days `since` to `until`, null when it names neither;
	 * a call never names `about`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return Filter
	 * @throws \InvalidArgumentException On a bad day.
	 */
	private static function filter_of( array $args ): array {
		$since = self::given( $args, 'since' );
		$until = self::given( $args, 'until' );
		$lists = [];
		foreach ( self::LENS_LISTS as $field => [ $arg ] ) {
			$lists[ $field ] = self::strings( $args[ $arg ] ?? [] );
		}
		return [
			'q'      => self::given( $args, 'q' ),
			'entity' => self::given( $args, 'entity' ),
			'about'  => false,
			'lists'  => $lists,
			'days'   => null === $since && null === $until ? null : Intel_Graph_Row_Schema::range( $since ?? self::ALL_DAYS[0], $until ?? self::ALL_DAYS[1] ),
		];
	}

	/**
	 * A list of values as strings.
	 *
	 * @param mixed $values The values.
	 * @return list<string>
	 */
	private static function strings( mixed $values ): array {
		return \array_values( \array_map( static fn ( mixed $value ): string => Core::as_string( $value ), Core::arr( $values ) ) );
	}

	/**
	 * The trend rows: readable items the filters admit, counted per bucket
	 * and value of the axis, or of none to count every item, each citing its
	 * newest items (`tally()`), a theme row naming its open clients first.
	 *
	 * @param Row_Reader   $r      The reader.
	 * @param Graph        $g      The graph.
	 * @param Filter       $filter The filters.
	 * @param string|null  $by     The axis, null for none.
	 * @param string       $span   A BUCKETS expression over `t`.
	 * @param list<string> $links  The link roles the filters read.
	 * @return list<array{bucket:mixed,value:mixed,count:int,items:list<array{item_key:mixed,url:mixed,title:mixed}>}>
	 */
	private static function counts( Row_Reader $r, array $g, array $filter, ?string $by, string $span, array $links = Intel_Event::ABOUT_ROLES ): array {
		$days = $filter['days'] ?? self::all_days();
		return self::tally( $r, self::filtered( $r, $g, $filter, $links ), $days, $by, $span, $days[0], false, false, 'theme' === $by )[0][''] ?? [];
	}

	/**
	 * A scope over the readable items the filters admit, under the one
	 * subject `''`, as `narrowed()` builds it for the days asked.
	 *
	 * @param Row_Reader   $r      The reader.
	 * @param Graph        $g      The graph.
	 * @param Filter       $filter The filters.
	 * @param list<string> $links  The link roles the filters read.
	 * @return Scope
	 */
	private static function filtered( Row_Reader $r, array $g, array $filter, array $links = Intel_Event::ABOUT_ROLES ): \Closure {
		return static function ( array $days, bool $linked ) use ( $r, $g, $filter, $links ): array {
			$days                            = [ Core::as_int( $days[0] ), Core::as_int( $days[1] ) ];
			[ $with, $where, $lead, $binds ] = self::narrowed( $r, $g, [ 'days' => $days ] + $filter, $linked, $links );
			return [ $with, $lead, "''", '', $where, $binds ];
		};
	}

	/**
	 * The CTEs and the condition over `ri i` the filters make: the item's
	 * time inside `days`, `q` as `matching()` reads it, `entity` linked, with
	 * `about` any entity linked, any of `topics` by a topic link, any of
	 * `roles` held at the item's time by an entity the item links, any of
	 * `kinds` and any of `sources`. `live` follows the graph's prelude when a
	 * filter reads links or `$linked` asks for it, over the links of the items linking a named entity or topic,
	 * else of the readable items inside `days`, over the link roles named;
	 * `rl`, each role row under its survivor, follows it when a role is named.
	 *
	 * @param Row_Reader   $r      The reader.
	 * @param Graph        $g      The graph the read opened.
	 * @param Filter       $filter The filters.
	 * @param bool         $linked Whether the read joins `live` itself.
	 * @param list<string> $links  The link roles `live` reads.
	 * @return array{0:string,1:string,2:list<scalar|null>,3:list<scalar|null>} The `WITH` clauses, the condition, the clauses' binds and the condition's.
	 */
	private static function narrowed( Row_Reader $r, array $g, array $filter, bool $linked, array $links = Intel_Event::ABOUT_ROLES ): array {
		$time  = Intel_Graph_Row_Schema::ITEM_TIME;
		$where = [ "{$time} >= ?", "{$time} < ?" ];
		$days  = $filter['days'] ?? self::all_days();
		$binds = $days;
		if ( null !== $filter['q'] ) {
			[ $matching, $match_binds ] = self::matching( $r, $filter['q'] );
			$where[]                    = "i.item_key IN {$matching}";
			\array_push( $binds, ...$match_binds );
		}
		if ( $filter['about'] ) {
			$where[] = 'i.item_key IN ( SELECT item_key FROM live )';
		}
		$keys = [];
		if ( null !== $filter['entity'] ) {
			$survivor = self::survivor( $filter['entity'], $g );
			$where[]  = 'i.item_key IN ( SELECT item_key FROM live WHERE entity_key = ? )';
			$binds[]  = $survivor;
			\array_push( $keys, ...self::keys_of( $survivor, $g ) );
		}
		[ 'kinds' => $kinds, 'topics' => $topics, 'roles' => $roles, 'sources' => $sources ] = $filter['lists'];
		if ( [] !== $topics ) {
			$topics  = \array_map( static fn ( string $topic ): string => self::survivor( $topic, $g ), $topics );
			$where[] = 'i.item_key IN ( SELECT item_key FROM live WHERE topical AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $topics ) . ' ) )';
			\array_push( $binds, ...$topics );
			foreach ( $topics as $topic ) {
				\array_push( $keys, ...self::keys_of( $topic, $g ) );
			}
		}
		if ( [] !== $roles ) {
			$where[] = 'EXISTS ( SELECT 1 FROM live JOIN rl ON rl.entity_key = live.entity_key WHERE live.item_key = i.item_key AND NOT live.topical AND rl.role IN ( '
				. Intel_Graph_Row_Schema::marks( $roles ) . " ) AND rl.from_ts <= {$time} AND ( 0 = rl.to_ts OR {$time} < rl.to_ts ) )";
			\array_push( $binds, ...$roles );
		}
		if ( [] !== $kinds ) {
			$where[] = 'i.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . ' xk WHERE kind IN ( ' . Intel_Graph_Row_Schema::marks( $kinds ) . ' ) )';
			\array_push( $binds, ...$kinds );
		}
		if ( [] !== $sources ) {
			$where[] = 'i.source IN ( ' . Intel_Graph_Row_Schema::marks( $sources ) . ' )';
			\array_push( $binds, ...$sources );
		}
		$with = $g['with'];
		$lead = $g['binds'];
		if ( $linked || $filter['about'] || [] !== $keys || [] !== $roles ) {
			$only                  = [] === $keys
				? [ "{t}.item_key IN ( SELECT item_key FROM ri i WHERE {$time} >= ? AND {$time} < ? )", $days ]
				: self::linked_to( $r, $keys, $links );
			[ $live, $live_binds ] = self::live( $r, $only[0], $only[1], $links );
			$with                 .= $live;
			\array_push( $lead, ...$live_binds );
		}
		if ( [] !== $roles ) {
			$with .= ', rl AS ( SELECT COALESCE( mg.value, r.entity_key ) AS entity_key, r.role, r.from_ts, r.to_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . ' r LEFT JOIN mg ON mg.key = r.entity_key )';
		}
		return [ $with, \implode( ' AND ', $where ), $lead, $binds ];
	}

	/**
	 * The key a read names, as its survivor.
	 *
	 * @param string $key An entity key.
	 * @param Graph  $g   The graph the read opened.
	 * @throws \InvalidArgumentException When its merge chain does not resolve.
	 */
	private static function survivor( string $key, array $g ): string {
		$survivor = $g['survivors'][ $key ] ?? $key;
		if ( '' === $survivor ) {
			throw new \InvalidArgumentException( \esc_html( "entity {$key}: its merge chain cycles or runs past " . Entity_Index::MAX_MERGE_HOPS . ' hops' ) );
		}
		return $survivor;
	}

	/**
	 * The item keys whose title, body or summary holds every word of `q`,
	 * each word one quoted phrase, as a subquery over every partition. A `q`
	 * holding no word is one empty phrase, which matches nothing.
	 *
	 * @param Row_Reader $r The reader.
	 * @param string     $q The words.
	 * @return Sql
	 */
	private static function matching( Row_Reader $r, string $q ): array {
		$words = \preg_split( '/\s+/u', \trim( $q ), -1, \PREG_SPLIT_NO_EMPTY );
		$match = \implode( ' ', \array_map( Intel_Graph_Row_Schema::fts_phrase( ... ), false === $words || [] === $words ? [ '' ] : $words ) );
		$parts = [];
		$binds = [];
		foreach ( $r->aliases() as $a ) {
			$parts[] = "SELECT item_key FROM {$a}.items WHERE id IN ( SELECT rowid FROM {$a}.items_fts WHERE items_fts MATCH ? )";
			$parts[] = "SELECT item_key FROM {$a}.extractions WHERE id IN ( SELECT rowid FROM {$a}.extractions_fts WHERE extractions_fts MATCH ? )";
			\array_push( $binds, $match, $match );
		}
		return [ '( ' . \implode( ' UNION ALL ', $parts ) . ' )', $binds ];
	}

	/**
	 * Whether the latest complete bucket fell: at most SIGNAL_DROP of the
	 * mean of the SIGNAL_BASELINE buckets before it, a mean of at least
	 * SIGNAL_MIN.
	 *
	 * @param list<int> $counts Each bucket's count, the one holding now first.
	 */
	private static function fell( array $counts ): bool {
		$sum = \array_sum( \array_slice( $counts, 2 ) );
		return $sum >= self::SIGNAL_MIN * self::SIGNAL_BASELINE && $counts[1] <= self::SIGNAL_DROP * $sum / self::SIGNAL_BASELINE;
	}

	/**
	 * Trend rows counting every item, as each bucket's count in the order
	 * of its start, and each bucket's row by its start.
	 *
	 * @param list<array{bucket:mixed,value:mixed,count:int,items:list<array{item_key:mixed,url:mixed,title:mixed}>}> $rows   The rows.
	 * @param list<string>                                                                                          $starts The window's starts, newest first.
	 * @return array{0:list<int>,1:array<array-key,array{bucket:mixed,value:mixed,count:int,items:list<array{item_key:mixed,url:mixed,title:mixed}>}>}
	 */
	private static function weeks( array $rows, array $starts ): array {
		$rows = \array_column( $rows, null, 'bucket' );
		return [ \array_map( static fn ( string $start ): int => Core::as_int( $rows[ $start ]['count'] ?? 0 ), $starts ), $rows ];
	}

	/**
	 * A scope over each entity's readable items linked by `$links`, under
	 * its survivor: each statement's `live` reads the links of the items
	 * linking any of their keys, joined as `l`.
	 *
	 * @param Row_Reader   $r         The reader.
	 * @param Graph        $g         The graph.
	 * @param list<string> $survivors The entities.
	 * @param list<string> $links     The link roles read.
	 * @return Scope
	 */
	private static function linking( Row_Reader $r, array $g, array $survivors, array $links ): \Closure {
		$keys = [];
		foreach ( $survivors as $survivor ) {
			\array_push( $keys, ...self::keys_of( $survivor, $g ) );
		}
		[ $only, $only_binds ] = self::linked_to( $r, $keys, $links );
		[ $live, $live_binds ] = self::live( $r, $only, $only_binds, $links );
		$time                  = Intel_Graph_Row_Schema::ITEM_TIME;
		$where                 = 'l.entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $survivors ) . " ) AND {$time} >= ? AND {$time} < ?";
		return static fn ( array $days ): array => [ $g['with'] . $live, [ ...$g['binds'], ...$live_binds ], 'l.entity_key', 'JOIN live l ON l.item_key = i.item_key', $where, [ ...$survivors, Core::as_int( $days[0] ), Core::as_int( $days[1] ) ] ];
	}
	/**
	 * The condition on `{t}` admitting the items any of the keys is linked
	 * to in one of the roles.
	 *
	 * @param Row_Reader   $r     The reader.
	 * @param list<string> $keys  Entity keys.
	 * @param list<string> $links The link roles.
	 * @return array{0:string,1:list<string>}
	 */
	private static function linked_to( Row_Reader $r, array $keys, array $links ): array {
		return [ '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' n WHERE entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) AND n.role IN ( ' . Intel_Graph_Row_Schema::marks( $links ) . ' ) )', [ ...$keys, ...$links ] ];
	}

	/**
	 * A survivor and every key merged into it, the keys its rows sit under.
	 *
	 * @param string $survivor A survivor.
	 * @param Graph  $g        The graph the read opened.
	 * @return list<string>
	 */
	public static function keys_of( string $survivor, array $g ): array {
		return $g['keys'][ $survivor ] ?? [ $survivor ];
	}

	/**
	 * The days the window's buckets span, its oldest bucket's first on.
	 *
	 * @param list<string> $starts The window's starts, newest first.
	 * @return array{0:int,1:int}
	 */
	private static function weeks_of( array $starts ): array {
		return [ Intel_Graph_Row_Schema::day( $starts[ self::SIGNAL_BASELINE + 1 ] ), self::all_days()[1] ];
	}

	/**
	 * The open rows of the roles, of each survivor's keys, as each survivor
	 * => role => its rows, `[ the key it is held under, from_ts ]`, the
	 * latest first.
	 *
	 * @param Row_Reader                $r         The reader.
	 * @param array<string,list<string>> $survivors Each survivor => the keys its rows sit under.
	 * @param list<string>              $roles     The roles.
	 * @return array<string,array<string,list<array{0:string,1:int}>>>
	 */
	public static function open_roles( Row_Reader $r, array $survivors, array $roles ): array {
		$owner = [];
		foreach ( $survivors as $survivor => $keys ) {
			foreach ( $keys as $key ) {
				$owner[ $key ] = $survivor;
			}
		}
		$keys = \array_map( 'strval', \array_keys( $owner ) );
		$open = [];
		if ( [] === $keys || [] === $roles ) {
			return $open;
		}
		$rows = $r->rows(
			'SELECT entity_key, role, from_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . ' t WHERE to_ts = 0 AND role IN ( ' . Intel_Graph_Row_Schema::marks( $roles )
			. ' ) AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) ORDER BY from_ts DESC, entity_key',
			[ ...$roles, ...$keys ]
		);
		foreach ( $rows as [ $key, $role, $from ] ) {
			$key = Core::as_string( $key );
			$open[ $owner[ $key ] ][ Core::as_string( $role ) ][] = [ $key, Core::as_int( $from ) ];
		}
		return $open;
	}

	/**
	 * Each subject's spikes over what the scope admits in `$days`, counted
	 * per value of the axis by the window's buckets alone (`spiked_in()`).
	 * With `$known`, as the about axis counting by kind sets it, a value
	 * spikes only when the same value was counted before the oldest of them,
	 * read across the whole retention whatever `$days` keeps: in the same
	 * statement when `$days` is the whole retention, else in one more.
	 * Without it, any axis but that one and the publisher axis, a subject
	 * spikes only when a story precedes the oldest of them within `$days`,
	 * read in the same statement. Beside the spikes, each subject's stories
	 * per bucket, counted in the statement that counts the window (`tally()`).
	 *
	 * @param Row_Reader     $r      The reader.
	 * @param Scope          $scope  The items read, by subject.
	 * @param array{int,int} $days   The first second and the second after the last.
	 * @param list<string>   $starts The window's starts, newest first.
	 * @param string         $by     The axis.
	 * @param string         $span   A BUCKETS expression over `t`.
	 * @param bool           $muted  Whether the kind axis leaves out MUTE_KIND.
	 * @param bool           $known  Whether a value spikes only once counted before the window.
	 * @return array{0:array<string,list<array<string,mixed>>>,1:array<string,array<string,int>>} Each subject with a count => its spikes; and each subject => each bucket's start => its stories.
	 */
	private static function spiking( Row_Reader $r, \Closure $scope, array $days, array $starts, string $by, string $span, bool $muted, bool $known ): array {
		$cut                   = Intel_Graph_Row_Schema::day( $starts[ self::SIGNAL_BASELINE + 1 ] );
		$whole                 = self::all_days() === $days;
		[ $tallied, $history, $totals ] = self::tally( $r, $scope, $days, $by, $span, $cut, $muted, $known && $whole, totals: true );
		if ( $known && ! $whole ) {
			$history = self::tally( $r, $scope, self::all_days(), $by, $span, $cut, $muted, true )[1];
		}
		$spikes = [];
		foreach ( $tallied as $subject => $rows ) {
			$spikes[ $subject ] = self::spiked_in( $rows, $starts, $by, $history[ $subject ] ?? [], $known );
		}
		return [ $spikes, $totals ];
	}

	/**
	 * Per value counted, the latest complete bucket's count when it is at
	 * least SIGNAL_MIN, above the mean of the SIGNAL_BASELINE buckets before
	 * it and at least that mean plus SIGNAL_SIGMAS standard deviations of
	 * them (`spiked()`), each citing its row's items, the highest count
	 * first. With `$known`, a value `$history` does not hold spikes nothing,
	 * since a first appearance within the window is not a change. Without
	 * it, a history holding no counted item before the oldest of them spikes
	 * nothing, since its zeros are not a baseline: an item in the oldest
	 * bucket answers that, else a `$history` that is not empty.
	 *
	 * @param list<array{bucket:mixed,value:mixed,count:int,items:list<array{item_key:mixed,url:mixed,title:mixed}>}> $rows   The trend rows, the newest bucket first.
	 * @param list<string>                                                                                          $starts The window's starts, newest first.
	 * @param string                                                                                                $by     The axis.
	 * @param array<string,true>                                                                                    $history Each value counted before the oldest bucket, or '' alone for a subject's history.
	 * @param bool                                                                                                  $known   Whether a value spikes only when `$history` holds it.
	 * @return list<array<string,mixed>>
	 */
	private static function spiked_in( array $rows, array $starts, string $by, array $history, bool $known ): array {
		if ( [] === $rows || ( ! $known && [] === $history && Core::as_string( $rows[ \count( $rows ) - 1 ]['bucket'] ) > $starts[ self::SIGNAL_BASELINE + 1 ] ) ) {
			return [];
		}
		$counts = [];
		foreach ( $rows as $row ) {
			$counts[ Core::as_string( $row['value'] ) ][ Core::as_string( $row['bucket'] ) ] = $row;
		}
		$signals = [];
		foreach ( $counts as $value => $buckets ) {
			$latest = $buckets[ $starts[1] ] ?? null;
			$base   = \array_map( static fn ( string $start ): int => Core::as_int( $buckets[ $start ]['count'] ?? 0 ), \array_slice( $starts, 2 ) );
			if ( null !== $latest && ( ! $known || isset( $history[ $value ] ) ) && self::spiked( Core::as_int( $latest['count'] ), $base ) ) {
				$signals[] = [ 'bucket' => $starts[1], 'by' => $by, 'value' => $value, 'count' => $latest['count'], 'baseline' => \array_sum( $base ) / (float) self::SIGNAL_BASELINE, 'items' => $latest['items'] ];
			}
		}
		\usort( $signals, static fn ( array $a, array $b ): int => [ $b['count'], $a['value'] ] <=> [ $a['count'], $b['value'] ] );
		return $signals;
	}

	/**
	 * Whether a count spikes over its baseline: at least SIGNAL_MIN, above
	 * the baseline's mean, so a flat history never spikes, and at least that
	 * mean plus SIGNAL_SIGMAS population standard deviations, compared in
	 * whole numbers so the threshold itself counts.
	 *
	 * @param int       $count    The count.
	 * @param list<int> $baseline The counts before it.
	 */
	private static function spiked( int $count, array $baseline ): bool {
		$n     = \count( $baseline );
		$sum   = \array_sum( $baseline );
		$above = $n * $count - $sum;
		$var   = $n * \array_sum( \array_map( static fn ( int $x ): int => $x * $x, $baseline ) ) - $sum * $sum;
		return $count >= self::SIGNAL_MIN && $above > 0 && $above * $above >= self::SIGNAL_SIGMAS * self::SIGNAL_SIGMAS * $var;
	}

	/**
	 * Stories counted per subject, bucket and value of the axis, or of none
	 * to count every story, each citing its newest stories, of what the
	 * scope admits in `$days` (`stories()`): those dated at or after `$cut`
	 * per bucket, and, in the same statement, each subject holding a story
	 * dated before it, its history, by each value it counted there when
	 * `$values` asks. A story is cited by its key, URL and title, each read
	 * once however many partitions hold it, and only for the stories cited. A story with no value, one with
	 * no theme on the theme axis, counts under none, and is not history.
	 * With `$totals`, the same statement also counts each subject's stories
	 * per bucket at or after `$cut`, a story once whatever values it holds,
	 * dated by its earliest copy, counted only when it holds a value.
	 *
	 * @param Row_Reader     $r      The reader.
	 * @param Scope          $scope  The items read, by subject.
	 * @param array{int,int} $days   The first second and the second after the last.
	 * @param string|null    $by     The axis, null for none.
	 * @param string         $span   A BUCKETS expression over `t`.
	 * @param int            $cut    The first second a bucket counts.
	 * @param bool           $muted  Whether the kind axis leaves out MUTE_KIND.
	 * @param bool           $values Whether the history is read per value.
	 * @param bool           $named  Whether each row names the open clients its stories' copies are about, by a subject or a mention, before its items, a copy about more than MAX_ITEM_CLIENTS naming none.
	 * @param bool           $totals Whether to count each subject's stories per bucket too.
	 * @return array{0:array<string,list<array{bucket:mixed,value:mixed,count:int,items:list<array{item_key:mixed,url:mixed,title:mixed}>}>>,1:array<string,array<string,true>>,2:array<string,array<string,int>>} Each subject => its rows, the newest bucket first, then the highest count; each subject with a history => each value in it, or '' alone; and, with `$totals`, each subject => each bucket's start => its stories.
	 */
	private static function tally( Row_Reader $r, \Closure $scope, array $days, ?string $by, string $span, int $cut, bool $muted = false, bool $values = false, bool $named = false, bool $totals = false ): array {
		[ $stories, $binds ] = self::stories( $r, $scope, $days, $by, $muted );
		$clients             = $named
			? ', ' . self::item_clients( $r ) . ", named AS ( SELECT b.subject, b.bucket, b.value, json_group_array( json_object( 'entity_key', b.entity_key, 'name', " . self::name_of( $r, 'b.entity_key' ) . ' ) ) AS clients'
				. " FROM ( SELECT DISTINCT s.subject, s.bucket, s.value, ic.entity_key FROM ( SELECT subject, {$span} AS bucket, value, item_key FROM stories WHERE t >= ? AND value IS NOT NULL ) s"
				. ' JOIN copies c ON c.subject = s.subject AND c.value = s.value AND c.story = s.item_key JOIN ic ON ic.item_key = c.copy ) b GROUP BY 1, 2, 3 )'
			: '';
		$rows                = $r->rows(
			"{$stories}, ranked AS ( SELECT s.subject, {$span} AS bucket, s.value, s.item_key, ROW_NUMBER() OVER ( PARTITION BY s.subject, {$span}, s.value ORDER BY t DESC, s.item_key DESC ) AS rn,"
			. " COUNT(*) OVER ( PARTITION BY s.subject, {$span}, s.value ) AS n FROM stories s WHERE t >= ? AND s.value IS NOT NULL ),"
			. ' cited AS ( SELECT ranked.*, c.url, c.title FROM ranked JOIN ( SELECT item_key, MAX( url ) AS url, MAX( title ) AS title FROM ri'
			. " WHERE item_key IN ( SELECT item_key FROM ranked WHERE rn <= ? ) GROUP BY item_key ) c ON c.item_key = ranked.item_key WHERE ranked.rn <= ? ){$clients}"
			. ' SELECT cited.subject, cited.bucket, cited.value, cited.n, cited.item_key, cited.url, cited.title, cited.rn, ' . ( $named ? 'nm.clients FROM cited LEFT JOIN named nm ON nm.subject = cited.subject AND nm.bucket = cited.bucket AND nm.value = cited.value' : 'NULL FROM cited' )
			. ' UNION ALL SELECT DISTINCT subject, NULL, ' . ( $values ? 'value' : 'NULL' ) . ', NULL, NULL, NULL, NULL, NULL, NULL FROM stories WHERE t < ? AND value IS NOT NULL'
			. ( $totals ? " UNION ALL SELECT subject, {$span}, NULL, COUNT(*), NULL, NULL, NULL, NULL, NULL FROM ( SELECT subject, item_key, MIN( t ) AS t FROM stories WHERE value IS NOT NULL GROUP BY 1, 2 ) WHERE t >= ? GROUP BY 1, 2" : '' )
			. ' ORDER BY 1, 2 DESC, 4 DESC, 3, 8',
			[ ...$binds, $cut, self::CITATIONS, self::CITATIONS, ...( $named ? [ $cut ] : [] ), $cut, ...( $totals ? [ $cut ] : [] ) ]
		);
		$out                 = [];
		$history             = [];
		$counts              = [];
		foreach ( $rows as [ $of, $day, $counted, $n, $item, $url, $title, , $listed ] ) {
			$of = Core::as_string( $of );
			if ( null === $day ) {
				$history[ $of ][ Core::as_string( $counted ) ] = true;
				continue;
			}
			if ( null === $item ) {
				$counts[ $of ][ Core::as_string( $day ) ] = Core::as_int( $n );
				continue;
			}
			$at                          = Core::as_string( $day ) . '|' . Core::as_string( $counted );
			$out[ $of ][ $at ]         ??= [ 'bucket' => $day, 'value' => $counted, 'count' => Core::as_int( $n ) ] + ( $named ? [ 'clients' => self::clients_of( $listed ) ] : [] ) + [ 'items' => [] ];
			$out[ $of ][ $at ]['items'][] = [ 'item_key' => $item, 'url' => $url, 'title' => $title ];
		}
		return [ \array_map( \array_values( ... ), $out ), $history, $counts ];
	}

	/**
	 * Clients aggregated as JSON, each its key and name, by name, then key.
	 *
	 * @param mixed $named A `json_group_array` of `{ entity_key, name }`, or null for none.
	 * @return list<array{entity_key:string,name:?string}>
	 */
	private static function clients_of( mixed $named ): array {
		$clients = \array_map(
			static function ( mixed $client ): array {
				$client = Core::arr( $client );
				return [ 'entity_key' => Core::as_string( $client['entity_key'] ), 'name' => null === $client['name'] ? null : Core::as_string( $client['name'] ) ];
			},
			null === $named ? [] : \array_values( Core::arr( \json_decode( Core::as_string( $named ), true, 512, \JSON_THROW_ON_ERROR ) ) )
		);
		\usort( $clients, static fn ( array $a, array $b ): int => [ $a['name'], $a['entity_key'] ] <=> [ $b['name'], $b['entity_key'] ] );
		return $clients;
	}

	/**
	 * The `oc` and `ic` CTEs, to follow `live`: every open client
	 * (`open_clients()`), and each item with the open clients it is about by
	 * a subject or a mention, an item about more than MAX_ITEM_CLIENTS
	 * naming none, as the briefing files such an item once.
	 *
	 * @param Row_Reader $r The reader.
	 */
	private static function item_clients( Row_Reader $r ): string {
		return self::open_clients( $r ) . ','
			. ' ic AS ( SELECT item_key, entity_key FROM ( SELECT item_key, entity_key, COUNT(*) OVER ( PARTITION BY item_key ) AS n'
			. ' FROM ( SELECT DISTINCT l.item_key, l.entity_key FROM live l JOIN oc ON oc.entity_key = l.entity_key WHERE NOT l.topical ) ) WHERE n <= ' . self::MAX_ITEM_CLIENTS . ' )';
	}

	/**
	 * The `oc` CTE, to follow `mg`: every survivor holding an open client
	 * role under any of its keys, as `open_roles()` reads one.
	 *
	 * @param Row_Reader $r The reader.
	 */
	private static function open_clients( Row_Reader $r ): string {
		return 'oc AS ( SELECT DISTINCT COALESCE( mg.value, ro.entity_key ) AS entity_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' )
			. " ro LEFT JOIN mg ON mg.key = ro.entity_key WHERE ro.role = '" . self::CLIENT . "' AND ro.to_ts = 0 )";
	}

	/**
	 * The `WITH` clauses ending in `copies ( subject, value, story, copy, t )`
	 * and `stories ( subject, value, item_key, t )`: each item the scope
	 * admits in `$days`, per value of the axis, as the story it belongs to,
	 * and each story dated by the earliest of its copies the scope admits.
	 * The story is its cluster's canonical, the cluster and the canonical
	 * each read from the newest partition holding a row, when the caller may
	 * read the canonical, else the item itself. On the theme axis a copy's
	 * value is its theme, read from the newest partition holding a `themes`
	 * row for it, as `theme_survivors()` answers it, null for a copy with
	 * none. With `$muted`, the kind axis
	 * admits no item extracted as MUTE_KIND.
	 *
	 * @param Row_Reader     $r     The reader.
	 * @param Scope          $scope The items read, by subject.
	 * @param array{int,int} $days  The first second and the second after the last.
	 * @param string|null    $by    The axis, null for none.
	 * @param bool           $muted Whether the kind axis leaves out MUTE_KIND.
	 * @return Sql The clauses and their binds.
	 */
	private static function stories( Row_Reader $r, \Closure $scope, array $days, ?string $by, bool $muted ): array {
		$themed                                           = 'theme' === $by;
		[ $with, $lead, $subject, $join, $where, $binds ] = $scope( $days, 'topic' === $by || $themed );
		[ $value, $axis ]                                 = null === $by ? self::EVERY_ITEM : self::TREND_AXES[ $by ];
		$axis = \str_replace( '{extractions}', Intel_Graph_Row_Schema::all( $r, 'extractions' ), $axis ) . ( $muted && 'kind' === $by ? " AND x.kind <> '" . self::MUTE_KIND . "'" : '' );
		[ $tm, $map ]                                     = $themed ? self::theme_survivors( $r ) : [ '', null ];
		$sql  = "{$with}, hits AS ( SELECT {$subject} AS subject, {$value} AS value, i.item_key, MIN( " . Intel_Graph_Row_Schema::ITEM_TIME . " ) AS t FROM ri i {$join} {$axis} WHERE {$where} GROUP BY 1, 2, 3 ),"
			. ' sc AS ( SELECT item_key, cluster_key FROM ( SELECT c.item_key, c.cluster_key, ROW_NUMBER() OVER ( PARTITION BY c.item_key ORDER BY c.partition_ordinal DESC ) AS pick FROM '
			. Intel_Graph_Row_Schema::all( $r, 'clusters', true ) . ' c WHERE c.item_key IN ( SELECT item_key FROM hits ) ) WHERE pick = 1 ),'
			. ' st AS ( SELECT sc.item_key, CASE WHEN EXISTS ( SELECT 1 FROM ri s WHERE s.item_key = k.canonical_item_key ) THEN k.canonical_item_key END AS story FROM sc JOIN'
			. ' ( SELECT k.cluster_key, k.canonical_item_key, ROW_NUMBER() OVER ( PARTITION BY k.cluster_key ORDER BY k.partition_ordinal DESC ) AS pick FROM '
			. Intel_Graph_Row_Schema::all( $r, 'canonicals', true ) . ' k WHERE k.cluster_key IN ( SELECT cluster_key FROM sc ) ) k ON k.cluster_key = sc.cluster_key AND k.pick = 1 ),'
			. ( $themed
				? " {$tm}, th AS ( SELECT item_key, theme FROM ( SELECT t.item_key, t.theme, ROW_NUMBER() OVER ( PARTITION BY t.item_key ORDER BY t.partition_ordinal DESC ) AS pick FROM "
					. Intel_Graph_Row_Schema::all( $r, 'themes', true ) . ' t WHERE t.item_key IN ( SELECT item_key FROM hits ) ) WHERE pick = 1 ),'
					. ' copies AS ( SELECT h.subject, COALESCE( tm.survivor, th.theme ) AS value, COALESCE( st.story, h.item_key ) AS story, h.item_key AS copy, h.t FROM hits h LEFT JOIN st ON st.item_key = h.item_key LEFT JOIN th ON th.item_key = h.item_key LEFT JOIN tm ON tm.theme = th.theme ),'
				: ' copies AS ( SELECT h.subject, h.value, COALESCE( st.story, h.item_key ) AS story, h.item_key AS copy, h.t FROM hits h LEFT JOIN st ON st.item_key = h.item_key ),' )
			. ' stories AS ( SELECT subject, value, story AS item_key, MIN( t ) AS t FROM copies GROUP BY 1, 2, 3 )';
		return [ $sql, [ ...$lead, ...$binds, ...( null === $map ? [] : [ $map ] ) ] ];
	}

	/**
	 * The theme survivor map a read binds, as `[ clause, bind ]`: the clause
	 * `tm ( theme, survivor )` over the JSON bind, each merged theme's
	 * survivor as `Entity_Index::walk()` reads `theme_merges()`, the walker
	 * curation judges a merge by. A theme whose chain cycles or runs past
	 * `Entity_Index::MAX_MERGE_HOPS` has no `tm` row, so it reads as itself,
	 * and its chain is logged once; a read takes a theme as
	 * `COALESCE( tm.survivor, theme )`. One statement reads the merges.
	 *
	 * @api Prompts offers themes by it.
	 * @param Row_Reader $r The reader.
	 * @return array{0:string,1:string}
	 */
	public static function theme_survivors( Row_Reader $r ): array {
		[ $survivors, $broken ] = Entity_Index::walk( self::theme_merges( $r ) );
		if ( [] !== $broken ) {
			Core::print_less_often( 'leaving theme merges that do not resolve unapplied: ', \implode( '; ', \array_keys( $broken ) ) );
		}
		return [ 'tm AS MATERIALIZED ( SELECT key AS theme, value AS survivor FROM json_each( ? ) )', (string) \wp_json_encode( (object) \array_filter( $survivors, static fn ( string $survivor ): bool => '' !== $survivor ), \JSON_THROW_ON_ERROR ) ];
	}

	/**
	 * Each merged theme => the theme it merged into, the newest partition's
	 * `theme_merges` row, by theme.
	 *
	 * @api Graph_Curation judges a theme merge by it.
	 * @param Row_Reader $r The reader.
	 * @return array<string,string>
	 */
	public static function theme_merges( Row_Reader $r ): array {
		$merges = [];
		foreach ( $r->rows( Intel_Graph_Row_Schema::newest( $r, 'theme_merges', 'theme', 'theme, into_theme', '1' ), [] ) as [ $theme, $into ] ) {
			$merges[ Core::as_string( $theme ) ] = Core::as_string( $into );
		}
		\ksort( $merges );
		return $merges;
	}

	/**
	 * Every UTC day, as `days` bounds them.
	 *
	 * @return array{0:int,1:int}
	 */
	private static function all_days(): array {
		return Intel_Graph_Row_Schema::range( self::ALL_DAYS[0], self::ALL_DAYS[1] );
	}

	/**
	 * The first day of the bucket holding now and of the SIGNAL_BASELINE + 1
	 * before it, newest first; null unless the latest complete one closed
	 * after `$after`.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param string     $span  A BUCKETS expression over `t`.
	 * @param float      $after The instant, UTC seconds.
	 * @return list<string>|null Y-m-d days.
	 */
	private static function window( Row_Reader $r, string $span, float $after ): ?array {
		$starts = self::bucket_starts( $r, $span, self::SIGNAL_BASELINE + 2 );
		return Intel_Graph_Row_Schema::day( $starts[0] ) <= $after ? null : $starts;
	}

	/**
	 * The first day of the bucket holding now and of each before it, newest
	 * first, as the bucket's own SQL spells them, in one statement: each
	 * start is that of the bucket holding the second before the next start.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param string     $span  A BUCKETS expression over `t`.
	 * @param int        $count How many buckets.
	 * @return list<string> Y-m-d days.
	 */
	private static function bucket_starts( Row_Reader $r, string $span, int $count ): array {
		$rows = $r->rows(
			"WITH RECURSIVE b( n, t ) AS ( SELECT 1, ? UNION ALL SELECT n + 1, CAST( strftime( '%s', {$span} ) AS INTEGER ) - 1 FROM b WHERE n < ? ) SELECT {$span} FROM b ORDER BY n",
			[ Intel_Event::now(), $count ]
		);
		return \array_map( static fn ( array $row ): string => Core::as_string( $row[0] ), $rows );
	}

	/**
	 * The review queue: proposed links, newest item first, each with its item
	 * and evidence; or proposed entities that a merge never took, the newest
	 * proposal first, each with the readable items citing it, by a link or as
	 * its source, and the sentence each quotes.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `what`, `limit`.
	 * @return array<string,mixed> `{ links|entities, available }`.
	 * @throws \InvalidArgumentException On an unknown queue or a bad limit.
	 */
	public static function review( array $args ): array {
		$what = self::one_of( 'what', Core::as_string( $args['what'] ), self::REVIEWS );
		$limit = self::limit( $args );
		return self::answer(
			[ $what => [] ],
			static fn ( Row_Reader $r, array $g ): array => [ $what => 'links' === $what ? self::proposed_links( $r, $g, $limit ) : self::proposed_entities( $r, $g, $limit ) ]
		);
	}

	/**
	 * The proposed entities a readable item cites, newest proposal first.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The graph the read opened.
	 * @param int        $limit The most entities answered.
	 * @return list<array<string,mixed>>
	 */
	private static function proposed_entities( Row_Reader $r, array $g, int $limit ): array {
		$proposed              = 'SELECT entity_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_states' ) . " p WHERE state = 'proposed'";
		[ $live, $live_binds ] = self::live( $r, "( {t}.entity_key IN ( {$proposed} ) OR {t}.entity_key IN ( SELECT key FROM mg ) )", [] );
		[ $cites, $cited ]     = self::cites( $r, "ev.subject IN ( {$proposed} )", [] );
		$rows                  = $r->rows(
			"{$g['with']}{$live}, {$cites}, proposed AS ( SELECT s.entity_key, MAX( s.ts ) AS ts FROM " . Intel_Graph_Row_Schema::all( $r, 'entity_states' ) . " s WHERE s.state = 'proposed'"
			. ' AND s.entity_key NOT IN ( SELECT key FROM mg ) GROUP BY s.entity_key ),'
			. ' best AS ( SELECT p.entity_key, p.ts FROM proposed p WHERE EXISTS ( SELECT 1 FROM cites c WHERE c.entity_key = p.entity_key ) ORDER BY p.ts DESC, p.entity_key LIMIT ? ),'
			. ' quoted AS ( SELECT c.entity_key, c.item_key, MAX( c.title ) AS title, MAX( c.url ) AS url, MAX( c.t ) AS t, MAX( c.evidence ) AS evidence FROM cites c JOIN best ON best.entity_key = c.entity_key GROUP BY c.entity_key, c.item_key ),'
			. ' cited AS ( SELECT q.*, ROW_NUMBER() OVER ( PARTITION BY q.entity_key ORDER BY q.t DESC, q.item_key DESC ) AS rn FROM quoted q )'
			. ' SELECT best.entity_key, ' . self::type_of( $r, 'best.entity_key' ) . ', ' . self::name_of( $r, 'best.entity_key' )
			. ', cited.item_key, cited.url, cited.title, cited.evidence FROM best JOIN cited ON cited.entity_key = best.entity_key WHERE cited.rn <= ? ORDER BY best.ts DESC, best.entity_key, cited.rn',
			[ ...$g['binds'], ...$live_binds, ...$cited, $limit, self::CITATIONS ]
		);
		$entities              = [];
		foreach ( $rows as [ $entity, $type, $name, $item, $url, $title, $evidence ] ) {
			$entity              = Core::as_string( $entity );
			$entities[ $entity ] ??= [ 'entity_key' => $entity, 'type' => $type, 'name' => $name, 'items' => [] ];
			$entities[ $entity ]['items'][] = [ 'item_key' => $item, 'url' => $url, 'title' => $title, 'evidence' => Core::as_string( $evidence ) ];
		}
		return \array_values( $entities );
	}

	/**
	 * The `cites` CTE, to follow `live()`: each readable item citing an
	 * entity, by a live link or as the item a proposal came from, the
	 * proposals `$only` admits over the `events` row `ev`, with the sentence
	 * the link or the proposal quotes.
	 *
	 * @param Row_Reader   $r     The reader.
	 * @param string       $only  A condition on `ev`, the proposals read.
	 * @param list<scalar> $binds Its binds.
	 * @return Sql The CTE and its binds.
	 */
	private static function cites( Row_Reader $r, string $only, array $binds ): array {
		$sql = 'cites AS ( SELECT entity_key, item_key, title, url, t, evidence FROM live UNION SELECT COALESCE( mg.value, ev.subject ), i.item_key, i.title, i.url, '
			. Intel_Graph_Row_Schema::ITEM_TIME . ", json_extract( ev.detail, '$.evidence' ) FROM " . Intel_Graph_Row_Schema::all( $r, 'events' )
			. " ev JOIN ri i ON i.item_key = json_extract( ev.detail, '$.from_item_key' ) LEFT JOIN mg ON mg.key = ev.subject"
			. " WHERE ev.kind = 'entity_proposed' AND {$only} )";
		return [ $sql, $binds ];
	}

	/**
	 * The proposed links, newest item first, but those to an entity whose
	 * survivor is rejected: a `ner_fuzzy` row outlives a re-resolution that
	 * did not run NER, and there is nothing left to judge of one.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The graph the read opened.
	 * @param int        $limit The most rows answered.
	 * @return list<array<string,mixed>>
	 */
	private static function proposed_links( Row_Reader $r, array $g, int $limit ): array {
		[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . " n WHERE state = 'proposed' AND n.role IN ( " . Intel_Graph_Row_Schema::marks( Intel_Event::ABOUT_ROLES ) . ' ) )', Intel_Event::ABOUT_ROLES );
		$links                 = [];
		$rows                  = $r->rows(
			"{$g['with']}{$live} SELECT live.item_key, live.entity_key, " . self::name_of( $r, 'live.entity_key' ) . ', ' . self::type_of( $r, 'live.entity_key' )
			. ", live.role, live.method, live.confidence, live.evidence, live.title, live.url FROM live WHERE live.state = 'proposed' AND " . self::unrejected( $r, 'live.entity_key' ) . " ORDER BY live.t DESC, live.item_key, live.entity_key LIMIT ?",
			[ ...$g['binds'], ...$live_binds, $limit ]
		);
		foreach ( $rows as [ $item, $entity, $name, $type, $role, $method, $confidence, $evidence, $title, $url ] ) {
			$links[] = [ 'item_key' => $item, 'entity_key' => $entity, 'name' => $name, 'type' => $type, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'evidence' => $evidence, 'title' => $title, 'url' => $url ];
		}
		return $links;
	}

	/**
	 * A condition holding where a survivor key's state, as
	 * `Entity_Index::winning_states()` reads one, is not `rejected`.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The SQL expression naming a survivor key.
	 */
	private static function unrejected( Row_Reader $r, string $key ): string {
		return "{$key} NOT IN ( SELECT s.entity_key FROM " . Entity_Index::winning_states( $r ) . " s WHERE s.state = 'rejected' )";
	}

	/**
	 * An entity's type, as a scalar subquery on the key an expression names;
	 * null where it has no `entities` row.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The SQL naming the entity key.
	 */
	private static function type_of( Row_Reader $r, string $key ): string {
		return '( SELECT en.type FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . " en WHERE en.entity_key = {$key} LIMIT 1 )";
	}

	/**
	 * The name every read shows an entity by, as a scalar subquery on the key
	 * an expression names: the newest canonical name added to it, the highest
	 * partition's first, as `Entity_Index` reads states, else the name it was
	 * created with; null where it holds neither.
	 *
	 * @api Feed_Source_Node reads which clients are still named by a domain.
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The SQL naming the entity key.
	 */
	public static function name_of( Row_Reader $r, string $key ): string {
		return 'COALESCE( ( SELECT nm.value FROM ' . Intel_Graph_Row_Schema::all( $r, 'names', true ) . " nm WHERE nm.entity_key = {$key} AND nm.kind = 'canonical' ORDER BY nm.partition_ordinal DESC, nm.id DESC LIMIT 1 ),"
			. ' ( SELECT en.name FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . " en WHERE en.entity_key = {$key} LIMIT 1 ) )";
	}

	/**
	 * The CTEs, to follow the graph's prelude, that every read counts links
	 * through, over the links of `$roles` and the judgments `$only` admits,
	 * `{t}` naming either table: `live`, one link per item and survivor, a
	 * topic link apart, at its effective state, on a readable item, with the
	 * item's title, URL and time `t`. A judgment, which does not name a role,
	 * judges an about-link; a `publisher` link keeps the machine's state. A
	 * rejected link drops out before the pick, which takes subject over mention
	 * over publisher, so a read of every role answers an entity's about-link
	 * where one stands and its publisher link where a curator rejected the
	 * rest.
	 *
	 * @param Row_Reader   $r     The reader.
	 * @param string       $only  A condition on `{t}`, the links or judgments a read rests on.
	 * @param list<scalar> $binds Its binds.
	 * @param list<string> $roles The link roles read, ABOUT_ROLES unless a read names others.
	 * @return Sql The `, lk AS …, live AS …` clauses and their binds.
	 */
	private static function live( Row_Reader $r, string $only, array $binds, array $roles = Intel_Event::ABOUT_ROLES ): array {
		$rank = self::judge_rank( 'j.actor_kind' );
		$time = Intel_Graph_Row_Schema::ITEM_TIME;
		$sql  = ', lk AS ( SELECT l.item_key, COALESCE( mg.value, l.entity_key ) AS entity_key, l.entity_key AS raw_key, l.role, l.method, l.confidence, l.state, l.evidence'
			. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l LEFT JOIN mg ON mg.key = l.entity_key WHERE ( ' . \str_replace( '{t}', 'l', $only ) . ' ) AND l.role IN ( ' . Intel_Graph_Row_Schema::marks( $roles ) . ' ) ),'
			. ' jt AS ( SELECT item_key, entity_key, state FROM ( SELECT j.item_key, COALESCE( mg.value, j.entity_key ) AS entity_key, j.state,'
			. " ROW_NUMBER() OVER ( PARTITION BY j.item_key, COALESCE( mg.value, j.entity_key ) ORDER BY {$rank} DESC, j.ts DESC ) AS pick"
			. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j LEFT JOIN mg ON mg.key = j.entity_key WHERE ' . \str_replace( '{t}', 'j', $only ) . ' ) WHERE pick = 1 ),'
			. ' lj AS ( SELECT lk.item_key, lk.entity_key, lk.raw_key, lk.role, lk.method, lk.confidence, lk.evidence, lk.role = \'topic\' AS topical,'
			. " i.title, i.url, {$time} AS t, CASE WHEN lk.role = ? THEN lk.state ELSE COALESCE( jt.state, lk.state ) END AS state"
			. ' FROM lk JOIN ri i ON i.item_key = lk.item_key'
			. " LEFT JOIN jt ON jt.item_key = lk.item_key AND jt.entity_key = lk.entity_key WHERE lk.entity_key <> '' ),"
			. ' live AS ( SELECT item_key, entity_key, role, method, confidence, evidence, topical, title, url, t, state FROM ( SELECT lj.*,'
			. ' ROW_NUMBER() OVER ( PARTITION BY lj.item_key, lj.entity_key, lj.topical'
			. " ORDER BY lj.role = 'subject' DESC, lj.role = 'publisher', lj.confidence DESC, lj.raw_key ) AS pick FROM lj WHERE lj.state <> 'rejected' ) WHERE pick = 1 )";
		return [ $sql, [ ...$binds, ...$roles, ...$binds, Intel_Event::PUBLISHER ] ];
	}

	/**
	 * The SQL ranking a judgment by its actor kind, as `JUDGE_RANK` ranks
	 * them, an unranked kind lowest: the one ranking every reader of
	 * judgments orders by, descending, then by `ts` descending for the
	 * latest among equals, which each caller appends.
	 *
	 * @param string $column The actor kind column.
	 */
	public static function judge_rank( string $column ): string {
		$rank = "CASE {$column}";
		foreach ( self::JUDGE_RANK as $kind => $n ) {
			$rank .= " WHEN '{$kind}' THEN {$n}";
		}
		return "{$rank} ELSE 0 END";
	}

	/**
	 * Run a read over the graph it opens, through `available()`.
	 *
	 * @param array<string,mixed>                     $empty The answer with no rows.
	 * @param \Closure(Row_Reader,Graph): array<string,mixed> $read  The read.
	 * @return array<string,mixed>
	 */
	public static function answer( array $empty, \Closure $read ): array {
		return self::available( $empty, static fn ( Row_Reader $r ): array => $read( $r, self::graph( $r ) ) );
	}

	/**
	 * The graph a read opens on: each merged key's survivor, each survivor's
	 * keys, and the prelude every statement opens with, binding the survivor
	 * map as `mg` and the caller's readable tiers' items as `ri`.
	 *
	 * @param Row_Reader $r The reader.
	 * @return Graph
	 */
	public static function graph( Row_Reader $r ): array {
		return self::bound( $r, Entity_Index::survivors( $r ), Intel_Tiers::readable() );
	}

	/**
	 * A verb's limit: at least 1, at most MAX_LIMIT.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @throws \InvalidArgumentException Below 1.
	 */
	private static function limit( array $args ): int {
		$limit = Core::as_int( $args['limit'] );
		if ( $limit < 1 ) {
			throw new \InvalidArgumentException( 'limit must be at least 1' );
		}
		return \min( $limit, self::MAX_LIMIT );
	}

	/**
	 * A value from its set, refused naming the set; null names none.
	 *
	 * @template T of string
	 * @param string      $name  What the value is, for the refusal.
	 * @param string|null $value The value.
	 * @param list<T>     $set   The set.
	 * @return T
	 * @throws \InvalidArgumentException Outside the set.
	 */
	public static function one_of( string $name, ?string $value, array $set ): string {
		if ( null === $value || ! \in_array( $value, $set, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} must be one of: " . \implode( ', ', $set ) ) );
		}
		return $value;
	}

	/**
	 * A graph as a caller without restricted access reads it: its survivors,
	 * its `ri` the items of `Intel_Tiers::UNRESTRICTED` alone.
	 *
	 * @param Row_Reader $r The reader.
	 * @param Graph      $g The graph the read opened.
	 * @return Graph
	 */
	public static function unrestricted( Row_Reader $r, array $g ): array {
		return self::bound( $r, $g['survivors'], Intel_Tiers::UNRESTRICTED );
	}

	/**
	 * The graph over a survivor map, its `ri` the items of `$tiers`.
	 *
	 * @param Row_Reader           $r         The reader.
	 * @param array<string,string> $survivors Each merged key's survivor, '' where it does not resolve.
	 * @param list<string>         $tiers     The tiers `ri` holds.
	 * @return Graph
	 */
	private static function bound( Row_Reader $r, array $survivors, array $tiers ): array {
		return [
			'survivors' => $survivors,
			'keys'      => self::inverse( $survivors ),
			'with'      => 'WITH mg AS MATERIALIZED ( SELECT key, value FROM json_each( ? ) ),'
				. ' ri AS NOT MATERIALIZED ( SELECT item_key, source, tier, url, title, item_ts, retrieved_ts, substr( body, 1, ' . self::EXCERPT_READ_CHARS . ' ) AS head FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' )
				. ' a WHERE tier IN ( ' . Intel_Graph_Row_Schema::marks( $tiers ) . ' ) )',
			'binds'     => [ \wp_json_encode( (object) $survivors, \JSON_THROW_ON_ERROR ), ...$tiers ],
		];
	}

	/**
	 * Each survivor => itself and every key merged into it, sorted.
	 *
	 * @param array<string,string> $survivors Each merged key's survivor, '' where it does not resolve.
	 * @return array<string,list<string>>
	 */
	public static function inverse( array $survivors ): array {
		$merged = [];
		foreach ( $survivors as $key => $survivor ) {
			if ( '' !== $survivor ) {
				$merged[ $survivor ][] = $key;
			}
		}
		$keys = [];
		foreach ( $merged as $survivor => $into ) {
			\sort( $into );
			$keys[ $survivor ] = [ $survivor, ...$into ];
		}
		return $keys;
	}

	/**
	 * Every collection with how many readable items it holds, newest first;
	 * or one collection with its readable items, the latest saved first.
	 * Each saved item reads at the tier it was saved at, so it outlives its
	 * item row.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, absent for every collection.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the key does not name a
	 *                                   collection.
	 */
	public static function collections( array $args ): array {
		$key   = self::given( $args, 'key' );
		$tiers = Intel_Tiers::readable();
		$in    = Intel_Graph_Row_Schema::marks( $tiers );
		if ( null === $key ) {
			return self::available(
				[ 'collections' => [] ],
				static fn ( Row_Reader $r ): array => [
					'collections' => \array_map(
						static fn ( array $row ): array => [ 'collection_key' => $row[0], 'name' => $row[1], 'created_ts' => $row[2], 'items' => Core::as_int( $row[3] ) ],
						$r->rows(
							'SELECT c.collection_key, c.name, c.created_ts, ( SELECT COUNT(*) FROM ' . Intel_Graph_Row_Schema::all( $r, 'collected' )
							. " d WHERE d.collection_key = c.collection_key AND d.tier IN ( {$in} ) ) FROM " . Intel_Graph_Row_Schema::all( $r, 'collections' ) . ' c ORDER BY c.created_ts DESC, c.collection_key',
							$tiers
						)
					),
				]
			);
		}
		return self::available(
			[ 'collection_key' => $key, 'name' => null, 'created_ts' => null, 'items' => [] ],
			static function ( Row_Reader $r ) use ( $key, $tiers, $in ): array {
				[ $name, $created ] = self::collection( $r, $key ) ?? throw new \InvalidArgumentException( \esc_html( "no collection: {$key}" ) );
				$items              = $r->rows(
					'SELECT d.item_key, d.url, d.title, d.actor FROM ' . Intel_Graph_Row_Schema::all( $r, 'collected' ) . " d WHERE d.collection_key = ? AND d.tier IN ( {$in} ) ORDER BY d.ts DESC, d.item_key",
					[ $key, ...$tiers ]
				);
				return [
					'collection_key' => $key,
					'name'           => $name,
					'created_ts'     => $created,
					'items'          => \array_map( static fn ( array $row ): array => [ 'item_key' => $row[0], 'url' => $row[1], 'title' => $row[2], 'actor' => $row[3] ], $items ),
				];
			}
		);
	}

	/**
	 * A collection's name and when it was created, or null when the key
	 * does not name a collection.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The collection key.
	 * @return array{0:string,1:int}|null
	 */
	public static function collection( Row_Reader $r, string $key ): ?array {
		$row = $r->rows( 'SELECT name, created_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'collections' ) . ' c WHERE collection_key = ? LIMIT 1', [ $key ] )[0] ?? null;
		return null === $row ? null : [ Core::as_string( $row[0] ), Core::as_int( $row[1] ) ];
	}

	/**
	 * Run a read: its answer marked available, an empty one where
	 * every partition is unwritten, or the empty answer marked
	 * unavailable, logged, where the projection will not open.
	 *
	 * @param array<string,mixed>                   $empty The answer with no rows.
	 * @param \Closure(Row_Reader): array<string,mixed> $read  The read.
	 * @return array<string,mixed>
	 */
	private static function available( array $empty, \Closure $read ): array {
		$rows = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => [] === $r->aliases() ? $empty : $read( $r ),
			self::UNAVAILABLE
		);
		return null === $rows ? $empty + [ 'available' => false ] : $rows + [ 'available' => true ];
	}

	/**
	 * An optional string arg as given, a blank one included (ADR-25), or
	 * null when it is absent.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @param string                 $name The arg.
	 */
	private static function given( array $args, string $name ): ?string {
		$value = $args[ $name ] ?? null;
		return null === $value ? null : Core::as_string( $value );
	}

	/**
	 * What `search` may group by, for the arg's description.
	 *
	 * @return list<string>
	 */
	public static function groupings(): array {
		return self::GROUPINGS;
	}
}

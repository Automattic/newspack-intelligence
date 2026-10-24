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
 * `ri`, so no item, link, claim, count or citation rests on an item of
 * another tier, and an entity no person accepted answers only while a
 * readable item cites it (`visible()`). A key a read names, and every key a
 * row names, answers as its survivor; a key whose chain cycles or runs too
 * long is refused, and its rows count nowhere. A link reads as one per item
 * and survivor, a topic link apart from the rest, subject over mention; its
 * state is the highest-ranked judgment's (`JUDGE_RANK`), else the machine's,
 * and a `rejected` link counts nowhere. Each read hands `live()` the links
 * it can rest on and windows only those; `trends` with no entity reads all.
 * Summaries, kinds and scores come from `extractions`, a name from
 * `entities`, joined, since a link may name an entity with no row.
 *
 * Every value a caller sends is bound, a blank one as given (ADR-25); `q`
 * reaches FTS5 only as quoted phrases, one per word. No answer carries an
 * item body. A projection that will not open answers `available: false`
 * beside empty rows; a graph no partition has written answers empty rows,
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
 */
final class Graph_Query {

	/** Rows a listing verb answers when its caller names no limit. */
	public const DEFAULT_LIMIT = 50;

	/** Entities `related` answers when its caller names no limit. */
	public const RELATED_LIMIT = 20;

	/** The most rows any verb answers. */
	public const MAX_LIMIT = 200;

	/** Items a trend row, a related entity or a proposal cites, the newest. */
	private const CITATIONS = 5;

	/** Each judging actor kind's rank; a person outranks an agent. */
	private const JUDGE_RANK = [
		'user'  => 2,
		'agent' => 1,
	];

	/** What a trend counts, as its value and the join reaching it. */
	private const TREND_AXES = [
		'kind'  => [ 'x.kind', 'JOIN {extractions} x ON x.item_key = i.item_key' ],
		'topic' => [ 'tl.entity_key', 'JOIN live tl ON tl.item_key = i.item_key AND tl.topical' ],
	];

	/** Each trend bucket's first day, over an item's time `t`. */
	private const BUCKETS = [
		'day'   => "date( t, 'unixepoch' )",
		'week'  => "date( t, 'unixepoch', 'weekday 0', '-6 days' )",
		'month' => "date( t, 'unixepoch', 'start of month' )",
	];

	/** Each field a listed item answers, by the column it is read from. */
	private const ITEM_COLUMNS = [
		'item_key'   => 'i.item_key',
		'title'      => 'i.title',
		'url'        => 'i.url',
		'source'     => 'i.source',
		'tier'       => 'i.tier',
		'item_ts'    => 'i.item_ts',
		'summary'    => 'x.summary',
		'kind'       => 'x.kind',
		'relevance'  => 'x.relevance',
		'importance' => 'x.importance',
	];

	/** The queues `review` answers. */
	private const REVIEWS = [ 'links', 'entities' ];

	/** The days an unbounded range runs between. */
	private const ALL_DAYS = [ '1970-01-01', '9999-12-31' ];

	/** The answer a listing of items gives with no rows. */
	private const NO_ITEMS = [
		'items' => [],
		'total' => 0,
	];

	/** The line logged when no projection opens. */
	private const UNAVAILABLE = 'intelligence: no intel-graph to read; answering none: ';

	/**
	 * An entity's items, newest first, as `search` answers them.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `since`, `until`, `limit`, `offset`.
	 * @return array<string,mixed> `{ items, total, available }`.
	 * @throws \InvalidArgumentException On a key that names no entity the caller may see, or as `search()`.
	 */
	public static function timeline( array $args ): array {
		$key  = Core::as_string( $args['key'] );
		$page = self::page_of( [ 'entity' => $key ] + $args );
		return self::answer(
			self::NO_ITEMS,
			static function ( Row_Reader $r, array $g ) use ( $key, $page ): array {
				self::seen( $r, $g, $key );
				return self::items( $r, $g, ...$page );
			}
		);
	}

	/**
	 * Items matching every filter given, newest first: `q` over title, body
	 * and summary, `entity` linked by any link, `topic` by a topic link,
	 * `kind`, `source`, and the UTC days `since` to `until`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array<string,mixed> `{ items, total, available }`.
	 * @throws \InvalidArgumentException On a bad page or day.
	 */
	public static function search( array $args ): array {
		$page = self::page_of( $args );
		return self::answer( self::NO_ITEMS, static fn ( Row_Reader $r, array $g ): array => self::items( $r, $g, ...$page ) );
	}

	/**
	 * One page of matching items, the count of all of them, and each item's
	 * live entity links.
	 *
	 * @param Row_Reader            $r      The reader.
	 * @param Graph                 $g      The graph the read opened.
	 * @param array<string,?string> $filter The filters given.
	 * @param array{0:int,1:int}    $days   The time range.
	 * @param int                   $limit  The page size.
	 * @param int                   $offset The page start.
	 * @return array<string,mixed>
	 */
	private static function items( Row_Reader $r, array $g, array $filter, array $days, int $limit, int $offset ): array {
		$time  = Intel_Graph_Row_Schema::ITEM_TIME;
		$where = [ "{$time} >= ?", "{$time} < ?" ];
		$binds = $days;
		if ( null !== $filter['q'] ) {
			[ $matching, $match_binds ] = self::matching( $r, $filter['q'] );
			$where[]                    = "i.item_key IN {$matching}";
			\array_push( $binds, ...$match_binds );
		}
		$linked = [
			'entity' => 'i.item_key IN ( SELECT item_key FROM live WHERE entity_key = ? )',
			'topic'  => 'i.item_key IN ( SELECT item_key FROM live WHERE topical AND entity_key = ? )',
		];
		$keys   = [];
		foreach ( $linked as $name => $sql ) {
			if ( null !== $filter[ $name ] ) {
				$survivor = self::survivor( $filter[ $name ], $g );
				$where[]  = $sql;
				$binds[]  = $survivor;
				\array_push( $keys, ...self::keys_of( $survivor, $g ) );
			}
		}
		foreach ( [ 'kind' => 'x.kind = ?', 'source' => 'i.source = ?' ] as $name => $sql ) {
			if ( null !== $filter[ $name ] ) {
				$where[] = $sql;
				$binds[] = $filter[ $name ];
			}
		}
		[ $live, $live_binds ] = [] === $keys ? [ '', [] ] : self::live( $r, '{t}.entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' )', $keys );
		$with                  = $g['with'] . $live;
		$binds                 = [ ...$g['binds'], ...$live_binds, ...$binds ];
		$where                 = ' WHERE ' . \implode( ' AND ', $where );
		$extracted             = ' LEFT JOIN ' . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . ' x ON x.item_key = i.item_key';
		$total                 = Core::as_int( $r->rows( "{$with} SELECT COUNT(*) FROM ri i" . ( null === $filter['kind'] ? '' : $extracted ) . $where, $binds )[0][0] ?? 0 );
		$page                  = $r->rows( "{$with} SELECT " . \implode( ', ', self::ITEM_COLUMNS ) . " FROM ri i{$extracted}{$where} ORDER BY {$time} DESC, i.item_key DESC LIMIT ? OFFSET ?", [ ...$binds, $limit, $offset ] );
		$items                 = \array_map( static fn ( array $row ): array => \array_combine( \array_keys( self::ITEM_COLUMNS ), $row ), $page );
		$linked                = [];
		if ( [] !== $items ) {
			$page_keys             = \array_map( static fn ( array $item ): string => Core::as_string( $item['item_key'] ), $items );
			[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( ' . Intel_Graph_Row_Schema::marks( $page_keys ) . ' )', $page_keys );
			foreach ( $r->rows( "{$g['with']}{$live} SELECT live.item_key, live.entity_key, " . self::column_of( $r, 'name', 'live.entity_key' ) . " , live.state FROM live WHERE NOT live.topical ORDER BY live.role = 'subject' DESC, live.entity_key", [ ...$g['binds'], ...$live_binds ] ) as [ $item_key, $entity, $name, $state ] ) {
				$linked[ Core::as_string( $item_key ) ][] = [ 'entity_key' => $entity, 'name' => $name, 'state' => $state ];
			}
		}
		return [
			'items' => \array_map( static fn ( array $item ): array => $item + [ 'entities' => $linked[ Core::as_string( $item['item_key'] ) ] ?? [] ], $items ),
			'total' => $total,
		];
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
	 * The filters, days, limit and offset of one page of the items the args
	 * match, the page and days checked.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array{0:array<string,?string>,1:array{0:int,1:int},2:int,3:int}
	 * @throws \InvalidArgumentException On a bad page or day.
	 */
	private static function page_of( array $args ): array {
		$limit  = self::limit( $args );
		$offset = Core::as_int( $args['offset'] );
		if ( $offset < 0 ) {
			throw new \InvalidArgumentException( 'offset must not be negative' );
		}
		$days   = self::days( $args );
		$filter = [];
		foreach ( [ 'q', 'entity', 'topic', 'kind', 'source' ] as $name ) {
			$filter[ $name ] = self::given( $args, $name );
		}
		return [ $filter, $days, $limit, $offset ];
	}

	/**
	 * One entity, read as its survivor: its record, the keys merged into it,
	 * every name, identifier, role and topic it or they carry, and how many
	 * readable items link it and how many of those links are proposed.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When no entity has the key, or its merge chain does not resolve.
	 */
	public static function entity( array $args ): array {
		$key   = Core::as_string( $args['key'] );
		$empty = [ 'entity_key' => $key, 'type' => null, 'name' => null, 'state' => null, 'merged_from' => [], 'names' => [], 'identifiers' => [], 'roles' => [], 'topics' => [], 'counts' => [ 'items' => 0, 'proposed_links' => 0 ] ];
		return self::answer( $empty, static fn ( Row_Reader $r, array $g ): array => self::entity_record( $r, $g, $key ) );
	}

	/**
	 * Read one entity's record, if `visible()` lets the caller see it.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key The key asked for.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When no entity has the key, or none this caller may read.
	 */
	private static function entity_record( Row_Reader $r, array $g, string $key ): array {
		$survivor              = self::seen( $r, $g, $key );
		$keys                  = self::keys_of( $survivor, $g );
		$in                    = Intel_Graph_Row_Schema::marks( $keys );
		$record                = $r->rows( 'SELECT type, name FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' e WHERE entity_key = ? LIMIT 1', [ $survivor ] )[0] ?? null;
		[ $live, $live_binds ] = self::live( $r, "{t}.entity_key IN ( {$in} )", $keys );
		$pairs                 = static fn ( string $table, string $a, string $b ): array => \array_map(
			static fn ( array $row ): array => [ $a => $row[0], $b => $row[1] ],
			$r->rows( "SELECT DISTINCT {$a}, {$b} FROM " . Intel_Graph_Row_Schema::all( $r, $table ) . " t WHERE entity_key IN ( {$in} ) ORDER BY 1, 2", $keys )
		);
		$roles                 = \array_map(
			static fn ( array $row ): array => [ 'role' => $row[0], 'from_ts' => $row[1], 'to_ts' => $row[2] ],
			$r->rows( 'SELECT DISTINCT role, from_ts, to_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . " t WHERE entity_key IN ( {$in} ) ORDER BY from_ts, role", $keys )
		);
		$topics                = $r->rows(
			"{$g['with']} SELECT DISTINCT topic_key, " . self::column_of( $r, 'name', 'topic_key' ) . ' FROM ( SELECT COALESCE( mg.value, t.topic_key ) AS topic_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_topics' )
			. " t LEFT JOIN mg ON mg.key = t.topic_key WHERE t.entity_key IN ( {$in} ) ) WHERE topic_key <> '' ORDER BY 1",
			[ ...$g['binds'], ...$keys ]
		);
		$counts                = $r->rows( "{$g['with']}{$live} SELECT COUNT( DISTINCT item_key ), COUNT( CASE WHEN state = 'proposed' THEN 1 END ) FROM live WHERE entity_key = ?", [ ...$g['binds'], ...$live_binds, $survivor ] )[0] ?? [ 0, 0 ];
		return [
			'entity_key'  => $survivor,
			'type'        => $record[0] ?? null,
			'name'        => $record[1] ?? null,
			'state'       => self::state_of( $r, $survivor ),
			'merged_from' => \array_slice( $keys, 1 ),
			'names'       => $pairs( 'names', 'kind', 'value' ),
			'identifiers' => $pairs( 'identifiers', 'kind', 'value' ),
			'roles'       => $roles,
			'topics'      => \array_map( static fn ( array $row ): array => [ 'topic_key' => $row[0], 'name' => $row[1] ], $topics ),
			'counts'      => [ 'items' => Core::as_int( $counts[0] ), 'proposed_links' => Core::as_int( $counts[1] ) ],
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
		[ $live, $live_binds ] = self::live( $r, "{t}.entity_key IN ( {$in} )", $keys );
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
	 * The entities sharing the most readable items with one, each with how
	 * many it shares and the newest it shares as citations.
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
				[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' n WHERE entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) )', $keys );
				$rows                  = $r->rows(
					"{$g['with']}{$live}, mine AS ( SELECT DISTINCT item_key FROM live WHERE entity_key = ? ),"
					. ' pairs AS ( SELECT DISTINCT live.entity_key, live.item_key, live.t FROM live JOIN mine ON mine.item_key = live.item_key WHERE live.entity_key <> ? ),'
					. ' best AS ( SELECT entity_key, COUNT(*) AS n FROM pairs GROUP BY entity_key ORDER BY n DESC, entity_key LIMIT ? ),'
					. ' cited AS ( SELECT p.entity_key, p.item_key, ROW_NUMBER() OVER ( PARTITION BY p.entity_key ORDER BY p.t DESC, p.item_key DESC ) AS rn FROM pairs p JOIN best ON best.entity_key = p.entity_key )'
					. ' SELECT best.entity_key, ' . self::column_of( $r, 'name', 'best.entity_key' ) . ', ' . self::column_of( $r, 'type', 'best.entity_key' )
					. ', best.n, cited.item_key FROM best JOIN cited ON cited.entity_key = best.entity_key WHERE cited.rn <= ? ORDER BY best.n DESC, best.entity_key, cited.rn',
					[ ...$g['binds'], ...$live_binds, $survivor, $survivor, $limit, self::CITATIONS ]
				);
				$entities              = [];
				foreach ( $rows as [ $entity, $name, $type, $shared, $item ] ) {
					$entity              = Core::as_string( $entity );
					$entities[ $entity ] ??= [ 'entity_key' => $entity, 'name' => $name, 'type' => $type, 'shared_items' => Core::as_int( $shared ), 'item_keys' => [] ];
					$entities[ $entity ]['item_keys'][] = Core::as_string( $item );
				}
				return [ 'entities' => \array_values( $entities ) ];
			}
		);
	}

	/**
	 * The claims naming an entity as subject or object, newest first, each
	 * resting on a readable item that links its subject by a live link.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `predicate`, `limit`.
	 * @return array<string,mixed> `{ claims, available }`.
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
				[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'claims' ) . " n WHERE subject_key IN ( {$in} ) OR object_key IN ( {$in} ) )", [ ...$keys, ...$keys ] );
				$only                  = null === $predicate ? '' : ' AND cl.predicate = ?';
				$rows                  = $r->rows(
					"{$g['with']}{$live}, cl AS ( SELECT COALESCE( ms.value, c.subject_key ) AS subject, c.predicate, COALESCE( mo.value, c.object_key ) AS object_key, c.object_text, c.confidence, c.item_key, c.ord"
					. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'claims' ) . ' c LEFT JOIN mg ms ON ms.key = c.subject_key LEFT JOIN mg mo ON mo.key = c.object_key'
					. " WHERE c.subject_key IN ( {$in} ) OR c.object_key IN ( {$in} ) )"
					. " SELECT cl.subject, cl.predicate, CASE WHEN cl.object_key = '' THEN cl.object_text ELSE cl.object_key END, cl.confidence, cl.item_key, live.url"
					. ' FROM cl JOIN live ON live.item_key = cl.item_key AND live.entity_key = cl.subject AND NOT live.topical'
					. " WHERE ( cl.subject = ? OR cl.object_key = ? ){$only} ORDER BY live.t DESC, cl.item_key, cl.ord LIMIT ?",
					[ ...$g['binds'], ...$live_binds, ...$keys, ...$keys, $survivor, $survivor, ...( null === $predicate ? [] : [ $predicate ] ), $limit ]
				);
				$claims                = [];
				foreach ( $rows as [ $subject, $said, $object, $confidence, $item, $url ] ) {
					$claims[] = [ 'subject' => $subject, 'predicate' => $said, 'object' => $object, 'confidence' => $confidence, 'item_key' => $item, 'url' => $url ];
				}
				return [ 'claims' => $claims ];
			}
		);
	}

	/**
	 * Readable items counted by kind or by topic per day, week or month,
	 * filtered by an entity they link, a role an entity they link held at
	 * the item's time, and UTC days; each row cites its newest items.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `by`, `role`, `entity`, `since`, `until`, `bucket`.
	 * @return array<string,mixed> `{ rows, available }`.
	 * @throws \InvalidArgumentException On an unknown axis or bucket, a bad day, or an unresolvable key.
	 */
	public static function trends( array $args ): array {
		$by     = Core::as_string( $args['by'] );
		$bucket = Core::as_string( $args['bucket'] );
		if ( ! isset( self::TREND_AXES[ $by ] ) ) {
			throw new \InvalidArgumentException( \esc_html( 'by must be one of: ' . \implode( ', ', \array_keys( self::TREND_AXES ) ) ) );
		}
		if ( ! isset( self::BUCKETS[ $bucket ] ) ) {
			throw new \InvalidArgumentException( \esc_html( 'bucket must be one of: ' . \implode( ', ', \array_keys( self::BUCKETS ) ) ) );
		}
		$days   = self::days( $args );
		$role   = self::given( $args, 'role' );
		$entity = self::given( $args, 'entity' );
		return self::answer(
			[ 'rows' => [] ],
			static function ( Row_Reader $r, array $g ) use ( $by, $bucket, $days, $role, $entity ): array {
				$time  = Intel_Graph_Row_Schema::ITEM_TIME;
				$where = [ "{$time} >= ?", "{$time} < ?" ];
				$binds = $days;
				$only  = [ '1', [] ];
				if ( null !== $entity ) {
					$survivor = self::survivor( $entity, $g );
					$keys     = self::keys_of( $survivor, $g );
					$only     = [ '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' n WHERE entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) )', $keys ];
					$where[]  = 'i.item_key IN ( SELECT item_key FROM live WHERE entity_key = ? )';
					$binds[]  = $survivor;
				}
				if ( null !== $role ) {
					$where[] = "EXISTS ( SELECT 1 FROM live JOIN rl ON rl.entity_key = live.entity_key WHERE live.item_key = i.item_key AND NOT live.topical AND rl.role = ? AND rl.from_ts <= {$time} AND ( 0 = rl.to_ts OR {$time} < rl.to_ts ) )";
					$binds[] = $role;
				}
				[ $live, $live_binds ] = self::live( $r, ...$only );
				[ $value, $join ]      = self::TREND_AXES[ $by ];
				$rows                  = $r->rows(
					"{$g['with']}{$live}, rl AS ( SELECT COALESCE( mg.value, r.entity_key ) AS entity_key, r.role, r.from_ts, r.to_ts FROM " . Intel_Graph_Row_Schema::all( $r, 'roles' ) . ' r LEFT JOIN mg ON mg.key = r.entity_key ),'
					. " hits AS ( SELECT DISTINCT {$value} AS value, i.item_key, {$time} AS t FROM ri i " . \str_replace( '{extractions}', Intel_Graph_Row_Schema::all( $r, 'extractions' ), $join ) . ' WHERE ' . \implode( ' AND ', $where ) . ' ),'
					. ' ranked AS ( SELECT ' . self::BUCKETS[ $bucket ] . ' AS bucket, value, item_key, ROW_NUMBER() OVER ( PARTITION BY ' . self::BUCKETS[ $bucket ] . ', value ORDER BY t DESC, item_key DESC ) AS rn,'
					. ' COUNT(*) OVER ( PARTITION BY ' . self::BUCKETS[ $bucket ] . ', value ) AS n FROM hits )'
					. ' SELECT bucket, value, n, item_key FROM ranked WHERE rn <= ? ORDER BY bucket, n DESC, value, rn',
					[ ...$g['binds'], ...$live_binds, ...$binds, self::CITATIONS ]
				);
				$out                   = [];
				foreach ( $rows as [ $day, $counted, $n, $item ] ) {
					$at          = Core::as_string( $day ) . '|' . Core::as_string( $counted );
					$out[ $at ] ??= [ 'bucket' => $day, 'value' => $counted, 'count' => Core::as_int( $n ), 'item_keys' => [] ];
					$out[ $at ]['item_keys'][] = Core::as_string( $item );
				}
				return [ 'rows' => \array_values( $out ) ];
			}
		);
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
	 * The first second and the second after the last of the UTC days the
	 * args bound, all of time where they bind none.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException When a day is not Y-m-d, or the range runs backwards.
	 */
	private static function days( array $args ): array {
		return Intel_Graph_Row_Schema::range( self::given( $args, 'since' ) ?? self::ALL_DAYS[0], self::given( $args, 'until' ) ?? self::ALL_DAYS[1] );
	}

	/**
	 * The review queue: proposed links, newest item first, each with its
	 * item; or proposed entities no merge took, the newest proposal first,
	 * each with the readable items citing it, by a link or as its source.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `what`, `limit`.
	 * @return array<string,mixed> `{ links|entities, available }`.
	 * @throws \InvalidArgumentException On an unknown queue or a bad limit.
	 */
	public static function review( array $args ): array {
		$what = Core::as_string( $args['what'] );
		if ( ! \in_array( $what, self::REVIEWS, true ) ) {
			throw new \InvalidArgumentException( \esc_html( 'what must be one of: ' . \implode( ', ', self::REVIEWS ) ) );
		}
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
			. ' cited AS ( SELECT c.entity_key, c.item_key, c.title, c.url, ROW_NUMBER() OVER ( PARTITION BY c.entity_key ORDER BY c.t DESC, c.item_key DESC ) AS rn FROM cites c JOIN best ON best.entity_key = c.entity_key )'
			. ' SELECT best.entity_key, ' . self::column_of( $r, 'type', 'best.entity_key' ) . ', ' . self::column_of( $r, 'name', 'best.entity_key' )
			. ', cited.item_key, cited.url, cited.title FROM best JOIN cited ON cited.entity_key = best.entity_key WHERE cited.rn <= ? ORDER BY best.ts DESC, best.entity_key, cited.rn',
			[ ...$g['binds'], ...$live_binds, ...$cited, $limit, self::CITATIONS ]
		);
		$entities              = [];
		foreach ( $rows as [ $entity, $type, $name, $item, $url, $title ] ) {
			$entity              = Core::as_string( $entity );
			$entities[ $entity ] ??= [ 'entity_key' => $entity, 'type' => $type, 'name' => $name, 'items' => [] ];
			$entities[ $entity ]['items'][] = [ 'item_key' => $item, 'url' => $url, 'title' => $title ];
		}
		return \array_values( $entities );
	}

	/**
	 * The `cites` CTE, to follow `live()`: each readable item citing an
	 * entity, by a live link or as the item a proposal came from, the
	 * proposals `$only` admits over the `events` row `ev`.
	 *
	 * @param Row_Reader   $r     The reader.
	 * @param string       $only  A condition on `ev`, the proposals read.
	 * @param list<scalar> $binds Its binds.
	 * @return Sql The CTE and its binds.
	 */
	private static function cites( Row_Reader $r, string $only, array $binds ): array {
		$sql = 'cites AS ( SELECT entity_key, item_key, title, url, t FROM live UNION SELECT COALESCE( mg.value, ev.subject ), i.item_key, i.title, i.url, '
			. Intel_Graph_Row_Schema::ITEM_TIME . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'events' )
			. " ev JOIN ri i ON i.item_key = json_extract( ev.detail, '$.from_item_key' ) LEFT JOIN mg ON mg.key = ev.subject"
			. " WHERE ev.kind = 'entity_proposed' AND {$only} )";
		return [ $sql, $binds ];
	}

	/**
	 * The proposed links, newest item first.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The graph the read opened.
	 * @param int        $limit The most rows answered.
	 * @return list<array<string,mixed>>
	 */
	private static function proposed_links( Row_Reader $r, array $g, int $limit ): array {
		[ $live, $live_binds ] = self::live( $r, '{t}.item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . " n WHERE state = 'proposed' )", [] );
		$links                 = [];
		$rows                  = $r->rows(
			"{$g['with']}{$live} SELECT live.item_key, live.entity_key, " . self::column_of( $r, 'name', 'live.entity_key' ) . ', ' . self::column_of( $r, 'type', 'live.entity_key' )
			. ", live.role, live.method, live.confidence, live.title, live.url FROM live WHERE live.state = 'proposed' ORDER BY live.t DESC, live.item_key, live.entity_key LIMIT ?",
			[ ...$g['binds'], ...$live_binds, $limit ]
		);
		foreach ( $rows as [ $item, $entity, $name, $type, $role, $method, $confidence, $title, $url ] ) {
			$links[] = [ 'item_key' => $item, 'entity_key' => $entity, 'name' => $name, 'type' => $type, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'title' => $title, 'url' => $url ];
		}
		return $links;
	}

	/**
	 * An entity's column, as a scalar subquery on the key an expression
	 * names; null where no `entities` row holds it.
	 *
	 * @param Row_Reader $r      The reader.
	 * @param string     $column `name` or `type`.
	 * @param string     $key    The SQL naming the entity key.
	 */
	private static function column_of( Row_Reader $r, string $column, string $key ): string {
		return "( SELECT e.{$column} FROM " . Intel_Graph_Row_Schema::all( $r, 'entities' ) . " e WHERE e.entity_key = {$key} LIMIT 1 )";
	}

	/**
	 * The CTEs, to follow the graph's prelude, that every read counts links
	 * through, over the links and judgments `$only` admits, `{t}` naming
	 * either table: `live`, one link per item and survivor, a topic link
	 * apart, subject over mention, at its effective state, on a readable
	 * item, never rejected, with the item's title, URL and time `t`.
	 *
	 * @param Row_Reader   $r     The reader.
	 * @param string       $only  A condition on `{t}`, the links or judgments a read rests on.
	 * @param list<scalar> $binds Its binds.
	 * @return Sql The `, lk AS …, live AS …` clauses and their binds.
	 */
	private static function live( Row_Reader $r, string $only, array $binds ): array {
		$rank = 'CASE j.actor_kind';
		foreach ( self::JUDGE_RANK as $kind => $n ) {
			$rank .= " WHEN '{$kind}' THEN {$n}";
		}
		$rank .= ' ELSE 0 END';
		$time = Intel_Graph_Row_Schema::ITEM_TIME;
		$sql  = ', lk AS ( SELECT l.item_key, COALESCE( mg.value, l.entity_key ) AS entity_key, l.role, l.method, l.confidence, l.state,'
			. " l.role = 'topic' AS topical, ROW_NUMBER() OVER ( PARTITION BY l.item_key, COALESCE( mg.value, l.entity_key ), l.role = 'topic'"
			. " ORDER BY l.role = 'subject' DESC, l.confidence DESC, l.entity_key ) AS pick"
			. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l LEFT JOIN mg ON mg.key = l.entity_key WHERE ' . \str_replace( '{t}', 'l', $only ) . ' ),'
			. ' jt AS ( SELECT item_key, entity_key, state FROM ( SELECT j.item_key, COALESCE( mg.value, j.entity_key ) AS entity_key, j.state,'
			. " ROW_NUMBER() OVER ( PARTITION BY j.item_key, COALESCE( mg.value, j.entity_key ) ORDER BY {$rank} DESC, j.ts DESC ) AS pick"
			. ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j LEFT JOIN mg ON mg.key = j.entity_key WHERE ' . \str_replace( '{t}', 'j', $only ) . ' ) WHERE pick = 1 ),'
			. ' live AS ( SELECT * FROM ( SELECT lk.item_key, lk.entity_key, lk.role, lk.method, lk.confidence, lk.topical,'
			. " i.title, i.url, {$time} AS t, COALESCE( jt.state, lk.state ) AS state"
			. ' FROM lk JOIN ri i ON i.item_key = lk.item_key'
			. " LEFT JOIN jt ON jt.item_key = lk.item_key AND jt.entity_key = lk.entity_key WHERE lk.pick = 1 AND lk.entity_key <> '' ) WHERE state <> 'rejected' )";
		return [ $sql, [ ...$binds, ...$binds ] ];
	}

	/**
	 * Run a read over the graph it opens, through `available()`.
	 *
	 * @param array<string,mixed>                     $empty The answer with no rows.
	 * @param \Closure(Row_Reader,Graph): array<string,mixed> $read  The read.
	 * @return array<string,mixed>
	 */
	private static function answer( array $empty, \Closure $read ): array {
		return self::available( $empty, static fn ( Row_Reader $r ): array => $read( $r, self::graph( $r ) ) );
	}

	/**
	 * The graph a read opens on: each merged key's survivor, each survivor's
	 * keys, and the prelude every statement opens with, binding the survivor
	 * map as `mg` and the readable tiers' items as `ri`.
	 *
	 * @param Row_Reader $r The reader.
	 * @return Graph
	 */
	public static function graph( Row_Reader $r ): array {
		$survivors = Entity_Index::survivors( $r );
		$tiers     = Intel_Tiers::readable();
		return [
			'survivors' => $survivors,
			'keys'      => self::inverse( $survivors ),
			'with'      => 'WITH mg AS MATERIALIZED ( SELECT key, value FROM json_each( ? ) ),'
				. ' ri AS NOT MATERIALIZED ( SELECT item_key, source, tier, url, title, item_ts, retrieved_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' )
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
	 * Every collection with how many readable items it holds, newest first;
	 * or one collection with its readable items, the latest saved first.
	 * Each saved item reads at the tier it was saved at, so it outlives its
	 * item row.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, absent for every collection.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When no collection has the key.
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
	 * A collection's name and when it was created, or null when no
	 * collection has the key.
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
	 * Run a read: its answer marked available, an empty one where no
	 * partition has written, or the empty answer marked unavailable, logged,
	 * where no projection opens.
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
}

<?php
/**
 * Intel_Graph_Row_Schema: the tables the projector replays the event streams
 * into, declared as `make_node Row_Table intel-graph Intel_Graph`.
 *
 * The item-scoped tables keep `RETAIN_DAYS` by a day column, the item's
 * retrieval `floor( retrieved_ts / 86400 )`, which leads an index, so an item
 * and every row about it expire together. The ontology and curation tables keep every
 * row, as the ontology stream does. An entity's identity (`entities`), its
 * state (`entity_states`) and its merge (`entity_merges`) are three rows, so
 * every event writes whole rows and the projector never reads before it
 * writes; an item's extraction is its own row beside the item for the same
 * reason, and its theme its own row beside the extraction; a theme merged
 * into another is a `theme_merges` row, so no item's row is rewritten. Each
 * tier's saved copy of an item is a `saved_copies` row of its own, and its
 * place in a folder a `collected` row, so a save never overwrites another
 * tier's and a move carries no copy; each use mark is an `item_marks` row
 * of the tier of the copy it marks. An item a curator excluded is one
 * kept `excluded_items` row, written by the ontology stream, so it
 * outlives the item's own rows, their expiry and a purge. Each distinct
 * line of an item's body is a `boilerplate` row of its own, so how many
 * items of a source carry a line is a count of rows. Each ask is one `asks`
 * row, kept `Ask_Node::KEEP_DAYS` by the day it was last written.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Row_Spec array{
 *   columns: array<string,string>,
 *   key?: list<string>,
 *   indexes?: array<string,list<string>>,
 *   retain?: array{0:string,1:int,2:int},
 *   keep?: bool,
 *   autoincrement?: string,
 *   fts?: list<string>
 * }
 */
final class Intel_Graph_Row_Schema implements \Newspack_Nodes\Row_Schema {

	/** Days an item-scoped row is kept, matching the items stream. */
	public const RETAIN_DAYS = 730;

	/** Seconds in the unit a day column counts. */
	public const DAY_SECONDS = 86400;

	/** An item's time in SQL over `items i`: the indexed column `item_time()` writes. */
	public const ITEM_TIME = 'i.item_time';

	/**
	 * Projection-read seam: replaces the one `Row_Table_Node::read()` call
	 * every read of the intel-graph goes through. Tests reassign it to make a
	 * read fail; null runs the real read.
	 * Signature: `function ( \Closure $read ): mixed`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $read_rows = null;

	/**
	 * Each open reader's tables, by alias, read once per connection.
	 *
	 * @var \WeakMap<Row_Reader,array<string,array<string,true>>>|null
	 */
	private static ?\WeakMap $attached = null;

	/**
	 * A UTC day range as its first second and the second after its last.
	 *
	 * @param string $since The first day, Y-m-d.
	 * @param string $until The last day, Y-m-d.
	 * @return array{0:int,1:int}
	 * @throws \InvalidArgumentException When a day is not a Y-m-d date, or the range runs backwards.
	 */
	public static function range( string $since, string $until ): array {
		$from = self::day( $since );
		$to   = self::day( $until ) + self::DAY_SECONDS;
		if ( $from >= $to ) {
			throw new \InvalidArgumentException( \esc_html( "since {$since} is after until {$until}" ) );
		}
		return [ $from, $to ];
	}

	/**
	 * A Y-m-d day's first second, UTC.
	 *
	 * @param string $ymd The day.
	 * @throws \InvalidArgumentException When it is not a Y-m-d date.
	 */
	public static function day( string $ymd ): int {
		$day = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, new \DateTimeZone( 'UTC' ) );
		if ( false === $day || $day->format( 'Y-m-d' ) !== $ymd ) {
			throw new \InvalidArgumentException( \esc_html( "not a Y-m-d date: {$ymd}" ) );
		}
		return $day->getTimestamp();
	}

	/**
	 * A table's rows `$where` admits, one per `$key`, the newest partition's,
	 * as a statement selecting `$columns`.
	 *
	 * @param Row_Reader $r       The reader.
	 * @param string     $table   A declared table.
	 * @param string     $key     The column a row is one per.
	 * @param string     $columns The columns selected, comma-separated.
	 * @param string     $where   The condition on its rows.
	 */
	public static function newest( Row_Reader $r, string $table, string $key, string $columns, string $where ): string {
		return "SELECT {$columns} FROM ( SELECT {$columns}, ROW_NUMBER() OVER ( PARTITION BY {$key} ORDER BY partition_ordinal DESC ) AS pick FROM "
			. self::all( $r, $table, true ) . " WHERE {$where} ) WHERE pick = 1";
	}

	/**
	 * A table's rows across every attached partition whose file holds it, as
	 * one subquery, so a file opened before a table was declared at the same
	 * version reads as holding none of it; with no such partition, an empty
	 * row set of its declared columns. Given `$ordinal`, each row also
	 * carries `partition_ordinal`, its file's place among the attached ones,
	 * for a read that takes a later partition's row.
	 *
	 * @param Row_Reader $r       The reader.
	 * @param string     $table   A declared table.
	 * @param bool       $ordinal Whether each row carries its partition ordinal.
	 */
	public static function all( Row_Reader $r, string $table, bool $ordinal = false ): string {
		$holding = self::holding( $r, $table );
		if ( [] === $holding ) {
			$columns = [];
			foreach ( self::tables()[ $table ]['columns'] as $column => $type ) {
				$columns[] = 'CAST( NULL AS ' . \explode( ' ', $type )[0] . " ) AS {$column}";
			}
			if ( $ordinal ) {
				$columns[] = 'CAST( NULL AS INTEGER ) AS partition_ordinal';
			}
			return '( SELECT ' . \implode( ', ', $columns ) . ' WHERE 0 )';
		}
		$arms = [];
		foreach ( $holding as $at => $alias ) {
			$arms[] = 'SELECT *' . ( $ordinal ? ", {$at} AS partition_ordinal" : '' ) . " FROM {$alias}.{$table}";
		}
		return '( ' . \implode( ' UNION ALL ', $arms ) . ' )';
	}

	/**
	 * The declared tables: each item-scoped one keeps `RETAIN_DAYS` by its day
	 * column, which an index leads, and every other keeps every row.
	 *
	 * @api Read by the Row_Table that opens the file.
	 * @return array<string,Row_Spec>
	 */
	public static function tables(): array {
		return [
			'items'         => [
				'columns'       => [
					'id'            => 'INTEGER',
					'item_key'      => 'TEXT NOT NULL',
					'source'        => 'TEXT NOT NULL',
					'tier'          => 'TEXT NOT NULL',
					'url'           => 'TEXT NOT NULL',
					'url_canonical' => 'TEXT NOT NULL',
					'title'         => 'TEXT NOT NULL',
					'title_norm'    => 'TEXT NOT NULL',
					'body'          => 'TEXT NOT NULL',
					'item_ts'       => 'INTEGER NOT NULL',
					'retrieved_ts'  => 'INTEGER NOT NULL',
					'retrieved_day' => 'INTEGER NOT NULL',
					'item_time'     => 'INTEGER NOT NULL',
				],
				'key'           => [ 'item_key' ],
				'indexes'       => [
					'items_url_canonical' => [ 'url_canonical' ],
					'items_title_norm'    => [ 'title_norm' ],
					'items_retrieved_day' => [ 'retrieved_day' ],
					'items_item_time'     => [ 'item_time' ],
				],
				'autoincrement' => 'id',
				'fts'           => [ 'title', 'body' ],
				'retain'  => [ 'retrieved_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'extractions'   => [
				'columns'       => [
					'id'           => 'INTEGER',
					'item_key'     => 'TEXT NOT NULL',
					'summary'      => 'TEXT NOT NULL',
					'kind'         => 'TEXT NOT NULL',
					'relevance'    => 'REAL NOT NULL',
					'importance'   => 'REAL NOT NULL',
					'sentiment'    => 'REAL NOT NULL',
					'checked_kind' => 'TEXT',
					'kind_verdict' => 'TEXT',
					'checked_ts'   => 'INTEGER',
					'kind_subject' => 'TEXT',
					'rank_reason'  => 'TEXT NOT NULL',
					'actor'        => 'TEXT NOT NULL',
					'item_day'     => 'INTEGER NOT NULL',
				],
				'key'           => [ 'item_key' ],
				'autoincrement' => 'id',
				'fts'           => [ 'summary' ],
				'indexes' => [
					'extractions_item_day' => [ 'item_day' ],
					'extractions_kind'     => [ 'kind' ],
				],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'extracted_subjects' => [
				'columns' => [
					'item_key'   => 'TEXT NOT NULL',
					'entity_key' => 'TEXT NOT NULL',
					'item_day'   => 'INTEGER NOT NULL',
				],
				'key'     => [ 'item_key', 'entity_key' ],
				'indexes' => [ 'extracted_subjects_item_day' => [ 'item_day' ] ],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'themes'        => [
				'columns' => [
					'item_key' => 'TEXT NOT NULL',
					'theme'    => 'TEXT NOT NULL',
					'item_day' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'item_key' ],
				'indexes' => [ 'themes_item_day' => [ 'item_day' ] ],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'links'         => [
				'columns' => [
					'item_key'   => 'TEXT NOT NULL',
					'entity_key' => 'TEXT NOT NULL',
					'role'       => 'TEXT NOT NULL',
					'method'     => 'TEXT NOT NULL',
					'confidence' => 'REAL NOT NULL',
					'state'      => 'TEXT NOT NULL',
					'evidence'   => 'TEXT NOT NULL',
					'actor'      => 'TEXT NOT NULL',
					'ts'         => 'REAL NOT NULL',
					'item_day'   => 'INTEGER NOT NULL',
				],
				'key'     => [ 'item_key', 'entity_key', 'role' ],
				'indexes' => [
					'links_entity_key' => [ 'entity_key' ],
					'links_item_day' => [ 'item_day' ],
				],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'claims'        => [
				'columns' => [
					'item_key'    => 'TEXT NOT NULL',
					'ord'         => 'INTEGER NOT NULL',
					'subject_key' => 'TEXT NOT NULL',
					'predicate'   => 'TEXT NOT NULL',
					'object_key'  => 'TEXT NOT NULL',
					'object_text' => 'TEXT NOT NULL',
					'confidence'  => 'REAL NOT NULL',
					'item_day'    => 'INTEGER NOT NULL',
				],
				'key'     => [ 'item_key', 'ord' ],
				'indexes' => [
					'claims_subject_key' => [ 'subject_key' ],
					'claims_object_key'  => [ 'object_key' ],
					'claims_item_day' => [ 'item_day' ],
				],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'clusters'      => [
				'columns' => [
					'item_key'    => 'TEXT NOT NULL',
					'cluster_key' => 'TEXT NOT NULL',
					'item_day'    => 'INTEGER NOT NULL',
				],
				'key'     => [ 'item_key' ],
				'indexes' => [
					'clusters_cluster_key' => [ 'cluster_key' ],
					'clusters_item_day' => [ 'item_day' ],
				],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'canonicals'    => [
				'columns' => [
					'cluster_key'        => 'TEXT NOT NULL',
					'canonical_item_key' => 'TEXT NOT NULL',
					'rank'               => 'REAL NOT NULL',
					'item_day'           => 'INTEGER NOT NULL',
				],
				'key'     => [ 'cluster_key' ],
				'indexes' => [ 'canonicals_item_day' => [ 'item_day' ] ],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'boilerplate'   => [
				'columns' => [
					'source'    => 'TEXT NOT NULL',
					'line_hash' => 'TEXT NOT NULL',
					'item_key'  => 'TEXT NOT NULL',
					'item_day'  => 'INTEGER NOT NULL',
				],
				'key'     => [ 'source', 'line_hash', 'item_key' ],
				'indexes' => [
					'boilerplate_item_key' => [ 'item_key' ],
					'boilerplate_item_day' => [ 'item_day' ],
				],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'entities'      => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'type'       => 'TEXT NOT NULL',
					'name'       => 'TEXT NOT NULL',
					'norm'       => 'TEXT NOT NULL',
					'created_ts' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'entity_key' ],
				'indexes' => [ 'entities_norm' => [ 'norm' ] ],
				'keep'    => true,
			],
			'entity_states' => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'state'      => 'TEXT NOT NULL',
					'actor'      => 'TEXT NOT NULL',
					'ts'         => 'REAL NOT NULL',
				],
				'key'     => [ 'entity_key' ],
				'keep'    => true,
			],
			'entity_merges' => [
				'columns' => [
					'entity_key'  => 'TEXT NOT NULL',
					'merged_into' => 'TEXT NOT NULL',
					'actor'       => 'TEXT NOT NULL',
					'ts'          => 'REAL NOT NULL',
				],
				'key'     => [ 'entity_key' ],
				'keep'    => true,
			],
			'theme_merges'  => [
				'columns' => [
					'theme'      => 'TEXT NOT NULL',
					'into_theme' => 'TEXT NOT NULL',
					'actor'      => 'TEXT NOT NULL',
					'ts'         => 'REAL NOT NULL',
				],
				'key'     => [ 'theme' ],
				'keep'    => true,
			],
			'names'         => [
				'columns'       => [
					'id'         => 'INTEGER',
					'entity_key' => 'TEXT NOT NULL',
					'kind'       => 'TEXT NOT NULL',
					'value'      => 'TEXT NOT NULL',
					'norm'       => 'TEXT NOT NULL',
				],
				'key'           => [ 'kind', 'norm', 'entity_key' ],
				'indexes'       => [ 'names_norm' => [ 'norm' ] ],
				'autoincrement' => 'id',
				'fts'           => [ 'value' ],
				'keep'    => true,
			],
			'name_starts'   => [
				'columns' => [
					'start' => 'TEXT NOT NULL',
					'norm'  => 'TEXT NOT NULL',
				],
				'key'     => [ 'start', 'norm' ],
				'keep'    => true,
			],
			'identifiers'   => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'kind'       => 'TEXT NOT NULL',
					'value'      => 'TEXT NOT NULL',
				],
				'key'     => [ 'kind', 'value' ],
				'indexes' => [ 'identifiers_entity_key' => [ 'entity_key' ] ],
				'keep'    => true,
			],
			'roles'         => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'role'       => 'TEXT NOT NULL',
					'from_ts'    => 'INTEGER NOT NULL',
					'to_ts'      => 'INTEGER NOT NULL',
					'actor'      => 'TEXT NOT NULL',
				],
				'key'     => [ 'entity_key', 'role', 'from_ts' ],
				'keep'    => true,
			],
			'entity_topics' => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'topic_key'  => 'TEXT NOT NULL',
					'actor'      => 'TEXT NOT NULL',
				],
				'key'     => [ 'entity_key', 'topic_key' ],
				'indexes' => [ 'entity_topics_topic_key' => [ 'topic_key' ] ],
				'keep'    => true,
			],
			'judgments'     => [
				'columns' => [
					'item_key'   => 'TEXT NOT NULL',
					'entity_key' => 'TEXT NOT NULL',
					'actor_kind' => 'TEXT NOT NULL',
					'state'      => 'TEXT NOT NULL',
					'actor'      => 'TEXT NOT NULL',
					'item_url'   => 'TEXT NOT NULL',
					'ts'         => 'REAL NOT NULL',
				],
				'key'     => [ 'item_key', 'entity_key', 'actor_kind' ],
				'indexes' => [ 'judgments_entity_key' => [ 'entity_key' ] ],
				'keep'    => true,
			],
			'excluded_items' => [
				'columns' => [
					'item_key' => 'TEXT NOT NULL',
					'tier'     => 'TEXT NOT NULL',
					'url'      => 'TEXT NOT NULL',
					'title'    => 'TEXT NOT NULL',
					'why'      => 'TEXT NOT NULL',
					'actor'    => 'TEXT NOT NULL',
					'ts'       => 'REAL NOT NULL',
				],
				'key'     => [ 'item_key' ],
				'keep'    => true,
			],
			'collections'   => [
				'columns' => [
					'collection_key' => 'TEXT NOT NULL',
					'name'           => 'TEXT NOT NULL',
					'actor'          => 'TEXT NOT NULL',
					'created_ts'     => 'INTEGER NOT NULL',
				],
				'key'     => [ 'collection_key' ],
				'keep'    => true,
			],
			'collected'     => [
				'columns' => [
					'collection_key' => 'TEXT NOT NULL',
					'item_key'       => 'TEXT NOT NULL',
					'tier'           => 'TEXT NOT NULL',
					'actor'          => 'TEXT NOT NULL',
					'ts'             => 'REAL NOT NULL',
				],
				'key'     => [ 'collection_key', 'item_key', 'tier' ],
				'keep'    => true,
			],
			'saved_copies'  => [
				'columns' => [
					'item_key' => 'TEXT NOT NULL',
					'tier'     => 'TEXT NOT NULL',
					'url'      => 'TEXT NOT NULL',
					'title'    => 'TEXT NOT NULL',
				],
				'key'     => [ 'item_key', 'tier' ],
				'keep'    => true,
			],
			'item_marks'    => [
				'columns' => [
					'item_key' => 'TEXT NOT NULL',
					'tier'     => 'TEXT NOT NULL',
					'mark'     => 'TEXT NOT NULL',
					'day'      => 'TEXT NOT NULL',
					'actor'    => 'TEXT NOT NULL',
					'ts'       => 'REAL NOT NULL',
				],
				'key'     => [ 'item_key', 'tier', 'mark' ],
				'keep'    => true,
			],
			'lenses'        => [
				'columns' => [
					'lens_key'    => 'TEXT NOT NULL',
					'name'        => 'TEXT NOT NULL',
					'description' => 'TEXT NOT NULL',
					'filters'     => 'TEXT NOT NULL',
					'actor'       => 'TEXT NOT NULL',
					'ts'          => 'REAL NOT NULL',
				],
				'key'     => [ 'lens_key' ],
				'keep'    => true,
			],
			'watches'       => [
				'columns' => [
					'watch_key'   => 'TEXT NOT NULL',
					'owner'       => 'INTEGER NOT NULL',
					'name'        => 'TEXT NOT NULL',
					'target_kind' => 'TEXT NOT NULL',
					'target_key'  => 'TEXT NOT NULL',
					'digest'      => 'INTEGER NOT NULL',
					'instant'     => 'INTEGER NOT NULL',
					'inbox'       => 'INTEGER NOT NULL',
					'actor'       => 'TEXT NOT NULL',
					'ts'          => 'REAL NOT NULL',
				],
				'key'     => [ 'watch_key' ],
				'indexes' => [ 'watches_owner' => [ 'owner' ] ],
				'keep'    => true,
			],
			'watch_matches' => [
				'columns' => [
					'watch_key'  => 'TEXT NOT NULL',
					'owner'      => 'INTEGER NOT NULL',
					'item_key'   => 'TEXT NOT NULL',
					'at'         => 'REAL NOT NULL',
					'matched_ts' => 'REAL NOT NULL',
					'quiet'      => 'INTEGER NOT NULL',
					'item_day'   => 'INTEGER NOT NULL',
				],
				'key'     => [ 'watch_key', 'item_key' ],
				'indexes' => [ 'watch_matches_item_day' => [ 'item_day' ] ],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'watch_seen'    => [
				'columns' => [
					'watch_key' => 'TEXT NOT NULL',
					'owner'     => 'INTEGER NOT NULL',
					'item_key'  => 'TEXT NOT NULL',
					'ts'        => 'REAL NOT NULL',
					'item_day'  => 'INTEGER NOT NULL',
				],
				'key'     => [ 'watch_key', 'item_key' ],
				'indexes' => [ 'watch_seen_item_day' => [ 'item_day' ] ],
				'retain'  => [ 'item_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'watch_deliveries' => [
				'columns' => [
					'owner'     => 'INTEGER NOT NULL',
					'mode'      => 'TEXT NOT NULL',
					'watch_key' => 'TEXT NOT NULL',
					'through'   => 'REAL NOT NULL',
					'ts'        => 'REAL NOT NULL',
				],
				'key'     => [ 'owner', 'mode', 'watch_key' ],
				'keep'    => true,
			],
			'asks'          => [
				'columns' => [
					'ask_key'   => 'TEXT NOT NULL',
					'owner'     => 'INTEGER NOT NULL',
					'state'     => 'TEXT NOT NULL',
					'reason'    => 'TEXT NOT NULL',
					'found'     => 'INTEGER NOT NULL',
					'dropped'   => 'INTEGER NOT NULL',
					'sentences' => 'TEXT NOT NULL',
					'items'     => 'TEXT NOT NULL',
					'tiers'     => 'TEXT NOT NULL',
					'searches'  => 'TEXT NOT NULL',
					'ts'        => 'REAL NOT NULL',
					'ask_day'   => 'INTEGER NOT NULL',
				],
				'key'     => [ 'ask_key' ],
				'indexes' => [ 'asks_ask_day' => [ 'ask_day' ] ],
				'retain'  => [ 'ask_day', Ask_Node::KEEP_DAYS, self::DAY_SECONDS ],
			],
			'events'        => [
				'columns' => [
					'event_id'       => 'TEXT NOT NULL',
					'kind'           => 'TEXT NOT NULL',
					'actor'          => 'TEXT NOT NULL',
					'subject'        => 'TEXT NOT NULL',
					'detail'         => 'TEXT NOT NULL',
					'config_version' => 'TEXT NOT NULL',
					'ts'             => 'REAL NOT NULL',
				],
				'key'     => [ 'event_id' ],
				'indexes' => [ 'events_subject_kind' => [ 'subject', 'kind' ] ],
				'keep'    => true,
			],
		];
	}

	/**
	 * The attached partitions whose file holds a table, by ordinal => alias,
	 * for a read that writes its own arm per partition, as a correlated
	 * lookup must, since SQLite pushes no correlated term into a compound.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param string     $table A declared table.
	 * @return array<int,string>
	 */
	public static function holding( Row_Reader $r, string $table ): array {
		return \array_filter( $r->aliases(), static fn ( string $alias ): bool => isset( self::tables_in( $r )[ $alias ][ $table ] ) );
	}

	/**
	 * The tables each of a reader's files holds, alias => table => true,
	 * read from each file's `sqlite_master` on the connection's first ask.
	 *
	 * @param Row_Reader $r The reader.
	 * @return array<string,array<string,true>>
	 */
	private static function tables_in( Row_Reader $r ): array {
		self::$attached ??= new \WeakMap();
		if ( ! isset( self::$attached[ $r ] ) ) {
			$tables = [];
			foreach ( $r->aliases() as $alias ) {
				$tables[ $alias ] = [];
				foreach ( $r->rows( "SELECT name FROM {$alias}.sqlite_master WHERE type = 'table'", [] ) as [ $name ] ) {
					$tables[ $alias ][ Core::as_string( $name ) ] = true;
				}
			}
			self::$attached[ $r ] = $tables;
		}
		return self::$attached[ $r ];
	}

	/**
	 * Run a read against every partition's intel-graph file. Given a line, a
	 * projection that will not open is logged rate-limited under it and
	 * answers null; given none, it throws.
	 *
	 * @template T
	 * @param \Closure(Row_Reader): T $read        The read.
	 * @param string|null             $unavailable The line logged when the
	 *                                             projection will not open.
	 * @return ($unavailable is null ? T : T|null)
	 * @throws Table_Unavailable When the projection will not open and the
	 *                           caller gives no line.
	 * @throws \RuntimeException As `Row_Table_Node::read()`, or what the read throws.
	 */
	public static function read( \Closure $read, ?string $unavailable = null ): mixed {
		try {
			return null === self::$read_rows ? Row_Table_Node::read( self::class, $read ) : ( self::$read_rows )( $read );
		} catch ( Table_Unavailable $e ) {
			if ( null === $unavailable ) {
				throw $e;
			}
			Core::print_less_often( $unavailable, $e->getMessage() );
			return null;
		}
	}

	/**
	 * An extraction row's kind check as the JSON record its event carried,
	 * `{ kind, verdict, ts }`, or null for one never checked.
	 *
	 * @param string $alias The `extractions` row's alias.
	 */
	public static function kind_check_sql( string $alias ): string {
		return "CASE WHEN {$alias}.kind_verdict IS NOT NULL THEN json_object( 'kind', {$alias}.checked_kind, 'verdict', {$alias}.kind_verdict, 'ts', {$alias}.checked_ts ) END";
	}

	/**
	 * Text as one quoted FTS5 phrase, its quotes doubled, so a MATCH reads
	 * it as words and never as query syntax.
	 *
	 * @param string $text Any text.
	 */
	public static function fts_phrase( string $text ): string {
		return '"' . \str_replace( '"', '""', $text ) . '"';
	}

	/**
	 * One `?` per value, comma-separated.
	 *
	 * @param list<mixed> $values The values.
	 */
	public static function marks( array $values ): string {
		return \implode( ', ', \array_fill( 0, \count( $values ), '?' ) );
	}

	/**
	 * An item's time: its item_ts, or its retrieved_ts when undated, as the
	 * projector writes it to `item_time`, which ITEM_TIME reads.
	 *
	 * @param int $item_ts      The item's own date, 0 when it has none.
	 * @param int $retrieved_ts When it was retrieved.
	 */
	public static function item_time( int $item_ts, int $retrieved_ts ): int {
		return 0 === $item_ts ? $retrieved_ts : $item_ts;
	}

	/**
	 * The version of the declared shape.
	 *
	 * @api Read by the Row_Table that opens the file.
	 */
	public static function version(): int {
		return 11;
	}
}

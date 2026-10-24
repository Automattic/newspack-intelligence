<?php
/**
 * Intel_Graph_Row_Schema: the tables the projector replays both event streams
 * into, declared as `make_node Row_Table intel-graph Intel_Graph`.
 *
 * The item-scoped tables keep `RETAIN_DAYS` by a day column, the item's
 * retrieval `floor( retrieved_ts / 86400 )`, which leads an index, so an item
 * and every row about it expire together. The ontology and curation tables keep every
 * row, as the ontology stream does. An entity's identity (`entities`), its
 * state (`entity_states`) and its merge (`entity_merges`) are three rows, so
 * every event writes whole rows and the projector never reads before it
 * writes; an item's extraction is its own row beside the item for the same
 * reason.
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

	/** An item's time in SQL over `items i`: its item_ts, or its retrieved_ts when undated. */
	public const ITEM_TIME = 'COALESCE( NULLIF( i.item_ts, 0 ), i.retrieved_ts )';

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
	 * Run a read against every partition's intel-graph file. Given a line, a
	 * projection that will not open is logged rate-limited under it and
	 * answers null; given none, it throws.
	 *
	 * @template T
	 * @param \Closure(Row_Reader): T $read        The read.
	 * @param string|null             $unavailable The line logged when no projection opens.
	 * @return T|null
	 * @throws Table_Unavailable When no projection opens and no line is given.
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
	 * An item's time: its item_ts, or its retrieved_ts when undated; the PHP
	 * twin of ITEM_TIME.
	 *
	 * @param int $item_ts      The item's own date, 0 when it has none.
	 * @param int $retrieved_ts When it was retrieved.
	 */
	public static function item_time( int $item_ts, int $retrieved_ts ): int {
		return 0 === $item_ts ? $retrieved_ts : $item_ts;
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
				],
				'key'           => [ 'item_key' ],
				'indexes'       => [
					'items_url_canonical' => [ 'url_canonical' ],
					'items_title_norm'    => [ 'title_norm' ],
					'items_retrieved_day' => [ 'retrieved_day' ],
				],
				'autoincrement' => 'id',
				'fts'           => [ 'title', 'body' ],
				'retain'  => [ 'retrieved_day', self::RETAIN_DAYS, self::DAY_SECONDS ],
			],
			'extractions'   => [
				'columns'       => [
					'id'         => 'INTEGER',
					'item_key'   => 'TEXT NOT NULL',
					'summary'    => 'TEXT NOT NULL',
					'kind'       => 'TEXT NOT NULL',
					'relevance'  => 'REAL NOT NULL',
					'importance' => 'REAL NOT NULL',
					'sentiment'  => 'REAL NOT NULL',
					'actor'      => 'TEXT NOT NULL',
					'item_day'   => 'INTEGER NOT NULL',
				],
				'key'           => [ 'item_key' ],
				'autoincrement' => 'id',
				'fts'           => [ 'summary' ],
				'indexes' => [ 'extractions_item_day' => [ 'item_day' ] ],
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
			'entities'      => [
				'columns' => [
					'entity_key' => 'TEXT NOT NULL',
					'type'       => 'TEXT NOT NULL',
					'name'       => 'TEXT NOT NULL',
					'created_ts' => 'INTEGER NOT NULL',
				],
				'key'     => [ 'entity_key' ],
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
					'url'            => 'TEXT NOT NULL',
					'title'          => 'TEXT NOT NULL',
					'actor'          => 'TEXT NOT NULL',
					'ts'             => 'REAL NOT NULL',
				],
				'key'     => [ 'collection_key', 'item_key' ],
				'keep'    => true,
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
				'keep'    => true,
			],
		];
	}

	/**
	 * The version of the declared shape.
	 *
	 * @api Read by the Row_Table that opens the file.
	 */
	public static function version(): int {
		return 1;
	}
}

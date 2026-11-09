<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/projects-intel-graph.php';

/**
 * The intel-graph tables: each item-scoped one keeps 730 days by an indexed
 * day column, and every other keeps every row.
 */
final class IntelGraphRowSchemaTest extends TestCase {
	use Projects_Intel_Graph;

	protected function setUp(): void {
		parent::setUp();
		$this->use_base_dir( $this->make_temp_dir( 'intel-graph-schema-' ) );
		Core::$var['partition'] = '0';
		Topology_Registry::reset();
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_the_schema_retains_item_rows_for_730_days_and_asks_for_a_week(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		foreach ( $tables as $table => $spec ) {
			$this->assertNotSame( isset( $spec['retain'] ), isset( $spec['keep'] ), "{$table} is retained or kept, never both" );
			if ( isset( $spec['retain'] ) ) {
				[ $day, $window, $unit ] = $spec['retain'];
				$this->assertSame( [ \in_array( $table, [ 'asks', 'ask_steps' ], true ) ? \Newspack_Intelligence\Ask_Node::KEEP_DAYS : 730, 86400 ], [ $window, $unit ], $table );
				$this->assertContains( [ $day ], $spec['indexes'] ?? [], "{$table} indexes the day it is retained by" );
			}
		}
		$this->assertSame( [ 'retrieved_day', 730, 86400 ], $tables['items']['retain'] );
		$this->assertSame( [ 'ask_day', 7, 86400 ], $tables['asks']['retain'] );
		$this->assertSame( [ 'entity_key', 'type', 'name', 'norm', 'created_ts' ], \array_keys( $tables['entities']['columns'] ), 'an entity row carries no id' );
		$this->assertSame( [ 'entities_norm' => [ 'norm' ] ], $tables['entities']['indexes'] );
		$this->assertArrayNotHasKey( 'autoincrement', $tables['entities'] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
		$this->projector_graph();
		$this->assertSame( [ 'Intel_Graph' ], Core::node( 'intel-graph' )->arguments(), 'the Row_Table accepts the schema' );
	}

	/**
	 * An item's record, its extraction and its cluster placement each carry
	 * who wrote them and when, the steps a trace lays out, at the shape
	 * version that first holds them.
	 */
	public function test_each_step_of_an_items_record_carries_its_actor_and_time(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		$this->assertSame( 'TEXT NOT NULL', $tables['items']['columns']['actor'] );
		$this->assertSame( 'REAL NOT NULL', $tables['extractions']['columns']['ts'] );
		$this->assertSame( [ 'item_key', 'cluster_key', 'actor', 'ts', 'item_day' ], \array_keys( $tables['clusters']['columns'] ) );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	/** A name's word starts are kept rows keyed by the start and the norm it begins in, carrying nothing else. */
	public function test_name_starts_key_each_start_to_its_norm(): void {
		$starts = Intel_Graph_Row_Schema::tables()['name_starts'];

		$this->assertSame( [ 'start' => 'TEXT NOT NULL', 'norm' => 'TEXT NOT NULL' ], $starts['columns'] );
		$this->assertSame( [ 'start', 'norm' ], $starts['key'] );
		$this->assertTrue( $starts['keep'] );
	}

	/** An item's theme is one row keyed by the item, dated and kept like its extraction, at the same shape version. */
	public function test_an_items_theme_is_a_keyed_item_scoped_row(): void {
		$themes = Intel_Graph_Row_Schema::tables()['themes'];

		$this->assertSame( [ 'item_key' => 'TEXT NOT NULL', 'theme' => 'TEXT NOT NULL', 'item_day' => 'INTEGER NOT NULL' ], $themes['columns'] );
		$this->assertSame( [ 'item_key' ], $themes['key'] );
		$this->assertSame( [ 'themes_item_day' => [ 'item_day' ] ], $themes['indexes'] );
		$this->assertSame( [ 'item_day', 730, 86400 ], $themes['retain'] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	/**
	 * An item's extraction row carries its kind check, null when it was never
	 * checked, its kind subject, null when it names none, and its rank
	 * reason, at the shape version that first holds them.
	 */
	public function test_an_extraction_row_carries_its_kind_check(): void {
		$columns = Intel_Graph_Row_Schema::tables()['extractions']['columns'];

		$this->assertSame( [ 'id', 'item_key', 'summary', 'kind', 'relevance', 'importance', 'sentiment', 'checked_kind', 'kind_verdict', 'checked_ts', 'kind_subject', 'rank_reason', 'actor', 'ts', 'item_day' ], \array_keys( $columns ) );
		$this->assertSame( [ 'TEXT', 'TEXT', 'INTEGER', 'TEXT', 'TEXT NOT NULL' ], [ $columns['checked_kind'], $columns['kind_verdict'], $columns['checked_ts'], $columns['kind_subject'], $columns['rank_reason'] ] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	/** One fragment reads an extraction row's kind check back as the record its event carried, null for one never checked. */
	public function test_the_kind_check_reads_back_as_its_record(): void {
		$projector = $this->projector_graph();
		$extracted = static fn ( string $key, array $check ): array => Intel_Event::make( 'extraction_recorded', [ 'item_key' => $key, 'summary' => 'Wren Ledger goes live.', 'kind' => 'launch', 'relevance' => 6, 'importance' => 4, 'sentiment' => 0.3, 'topics' => [], 'retrieved_ts' => 1791300000 ] + $check, 'machine:extract@9.4.1', '' );
		self::deliver( $projector, $extracted( 'feed:wren-6611', [ 'kind_check' => [ 'kind' => 'launch', 'verdict' => Intel_Event::PASSED, 'ts' => 1791300450 ] ] ) );
		self::deliver( $projector, $extracted( 'feed:wren-6612', [] ) );
		$projector->save_state();

		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => \Newspack_Nodes\Table_Node::file( 'intel-graph', 0 ) ], Intel_Graph_Row_Schema::version() );
		$this->assertSame(
			[ [ '{"kind":"launch","verdict":"passed","ts":1791300450}' ], [ null ] ],
			$reader->rows( 'SELECT ' . Intel_Graph_Row_Schema::kind_check_sql( 'k' ) . ' FROM p0.extractions k ORDER BY k.item_key', [] )
		);
	}

	/**
	 * An attached partition whose file lacks a table, as a file a worker
	 * opened before the table was declared does, reads as holding none of
	 * it, keeping every other partition's rows and ordinals; with no file
	 * holding it, the table is an empty typed row set.
	 */
	public function test_a_partition_lacking_a_table_reads_as_holding_none_of_it(): void {
		$graph = $this->projector_graph();
		self::deliver( $graph, Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:wren-6610', 'source' => 'feed', 'tier' => 'public', 'url' => 'https://wren.example/a', 'title' => 'Wren Ledger mail stops', 'body' => 'Mail stopped.', 'item_ts' => 1791300000, 'retrieved_ts' => 1791300000 ], 'machine:source:feed@9.4.1', '9.4.1' ) );
		self::deliver( $graph, Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'feed:wren-6610', 'summary' => 'Mail stopped.', 'kind' => 'bug', 'relevance' => 6, 'importance' => 5, 'sentiment' => 0.0, 'topics' => [], 'theme' => 'site blocked from sending email', 'retrieved_ts' => 1791300000 ], 'machine:extract@9.4.1', '9.4.1' ) );
		$graph->save_state();
		$p0 = \Newspack_Nodes\Table_Node::file( 'intel-graph', 0 );
		$p1 = \Newspack_Nodes\Table_Node::file( 'intel-graph', 1 );
		( new \PDO( "sqlite:{$p0}" ) )->exec( "VACUUM INTO '{$p1}'" );
		( new \PDO( "sqlite:{$p1}" ) )->exec( 'DROP TABLE themes' );

		$both  = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => $p0, 1 => $p1 ], Intel_Graph_Row_Schema::version() );
		$alone = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 1 => $p1 ], Intel_Graph_Row_Schema::version() );

		$this->assertSame( [ [ 'site blocked from sending email', 0 ] ], $both->rows( 'SELECT theme, partition_ordinal FROM ' . Intel_Graph_Row_Schema::all( $both, 'themes', true ) . ' t', [] ) );
		$this->assertSame( [ [ 'site blocked from sending email' ] ], $both->rows( 'SELECT theme FROM ' . Intel_Graph_Row_Schema::all( $both, 'themes' ) . ' t', [] ), 'a second read of one connection answers alike' );
		$this->assertSame( [ [ 0 ], [ 1 ] ], $both->rows( 'SELECT partition_ordinal FROM ' . Intel_Graph_Row_Schema::all( $both, 'items', true ) . ' i ORDER BY 1', [] ) );
		$this->assertSame( [], $alone->rows( 'SELECT theme FROM ' . Intel_Graph_Row_Schema::all( $alone, 'themes' ) . ' t', [] ) );
		$this->assertSame( [ [ 'feed:wren-6610' ] ], $alone->rows( 'SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $alone, 'items' ) . ' i', [] ) );
	}

	/**
	 * A read given a line answers null while the projection will not open,
	 * logged once; a read given none throws.
	 */
	public function test_a_read_given_a_line_answers_null_while_no_projection_opens(): void {
		$stderr = $this->capture_stderr();

		$this->assertNull( Intel_Graph_Row_Schema::read( static fn (): int => 41, 'no intel-graph for the harbor: ' ) );
		$this->assertNull( Intel_Graph_Row_Schema::read( static fn (): int => 41, 'no intel-graph for the harbor: ' ) );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph for the harbor: ' ) );
		$this->expectException( Table_Unavailable::class );
		Intel_Graph_Row_Schema::read( static fn (): int => 41 );
	}

	/** The seam replaces the one call every projection read goes through. */
	public function test_the_read_seam_replaces_the_projection_read(): void {
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): array => [ 'seamed', 17 ];

		$this->assertSame( [ 'seamed', 17 ], Intel_Graph_Row_Schema::read( static fn (): int => 41 ) );
		$this->assertSame( [ 'seamed', 17 ], Intel_Graph_Row_Schema::read( static fn (): int => 41, 'never logged: ' ) );
	}

	/**
	 * An item's time is one indexed column, `item_time`, which every read
	 * compares as ITEM_TIME: its item_ts, or its retrieved_ts when undated,
	 * as `item_time()` computes it; and an extraction's kind is indexed, so
	 * a kinds filter searches it.
	 */
	public function test_an_items_time_is_one_indexed_column(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		$this->assertSame( 'INTEGER NOT NULL', $tables['items']['columns']['item_time'] );
		$this->assertSame( [ 'item_time' ], $tables['items']['indexes']['items_item_time'] );
		$this->assertSame( [ 'kind' ], $tables['extractions']['indexes']['extractions_kind'] );
		$this->assertSame( 'i.item_time', Intel_Graph_Row_Schema::ITEM_TIME );
		$this->assertSame( [ 1793000017, 1792000041 ], [ Intel_Graph_Row_Schema::item_time( 0, 1793000017 ), Intel_Graph_Row_Schema::item_time( 1792000041, 1793000017 ) ] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	public function test_judgments_and_collected_outlive_their_items(): void {
		$now       = 1793000000;
		Core::$now = (float) ( $now - 731 * 86400 );
		$projector = $this->projector_graph();
		$old       = (int) Core::$now;
		$events    = [
			Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:abc-7731', 'source' => 'feed', 'tier' => 'public', 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'body' => 'Body', 'item_ts' => $old, 'retrieved_ts' => $old ], 'machine:source:feed@9.4.1', '9.4.1' ),
			Intel_Event::make( 'link_asserted', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:harbor-gazette', 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'retrieved_ts' => $old ], 'machine:resolve@9.4.1', '9.4.1' ),
			Intel_Event::make( 'link_confirmed', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:harbor-gazette', 'item_url' => 'https://harborgazette.example/a' ], 'user:41', 'clients-v3' ),
			Intel_Event::make( 'collected', [ 'collection_key' => 'awards-2024', 'item_key' => 'feed:abc-7731', 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'tier' => 'public' ], 'user:41', 'clients-v3' ),
		];
		foreach ( $events as $event ) {
			self::deliver( $projector, $event );
		}
		$projector->save_state();
		$this->assertSame( [ 1, 1, 1, 1 ], self::counts( [ 'items', 'links', 'judgments', 'collected' ] ) );

		Core::$clock = static fn (): float => (float) $now;
		Core::node( 'intel-graph' )->purge( 1000 );

		$this->assertSame( [ 0, 0, 1, 1 ], self::counts( [ 'items', 'links', 'judgments', 'collected' ] ) );
	}

	/**
	 * A body line's count is one row per item, keyed by the item's source,
	 * the line's hash and the item, dated by the item's retrieval day, so
	 * it leaves with its item when retention purges the item.
	 */
	public function test_boilerplate_expires_with_its_items(): void {
		$boilerplate = Intel_Graph_Row_Schema::tables()['boilerplate'];
		$this->assertSame( [ 'source' => 'TEXT NOT NULL', 'line_hash' => 'TEXT NOT NULL', 'item_key' => 'TEXT NOT NULL', 'item_day' => 'INTEGER NOT NULL' ], $boilerplate['columns'] );
		$this->assertSame( [ 'source', 'line_hash', 'item_key' ], $boilerplate['key'] );
		$this->assertSame( [ 'item_day', 730, 86400 ], $boilerplate['retain'] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );

		$now       = 1793000000;
		Core::$now = (float) ( $now - 731 * 86400 );
		$projector = $this->projector_graph();
		$old       = (int) Core::$now;
		$recorded  = static fn ( string $key, int $at ): array => Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => 'linear', 'tier' => 'internal', 'url' => "https://linear.app/heron-example/issue/{$key}", 'title' => 'Offboarding', 'body' => "Archive the site backups.\nNotify the Heron Ledger desk.", 'item_ts' => $at, 'retrieved_ts' => $at ], 'machine:source:linear@9.4.1', '9.4.1' );
		self::deliver( $projector, $recorded( 'linear:WREN-7701', $old ) );
		self::deliver( $projector, $recorded( 'linear:WREN-7702', $now - 86400 ) );
		$projector->save_state();
		$this->assertSame( [ 2, 4 ], self::counts( [ 'items', 'boilerplate' ] ) );

		Core::$clock = static fn (): float => (float) $now;
		Core::node( 'intel-graph' )->purge( 1000 );

		$this->assertSame( [ 1, 2 ], self::counts( [ 'items', 'boilerplate' ] ) );
		$this->assertSame( [ [ 'linear:WREN-7702' ], [ 'linear:WREN-7702' ] ], self::graph_rows( 'boilerplate', '1', 'item_key' ) );
	}

	/**
	 * Row counts per table.
	 *
	 * @param list<string> $tables The tables.
	 * @return list<int>
	 */
	private static function counts( array $tables ): array {
		return \array_map( static fn ( string $t ): int => (int) self::graph_rows( $t, '1', 'COUNT(*)' )[0][0], $tables );
	}

	/**
	 * Each tier's saved copy of an item is a kept `saved_copies` row keyed by
	 * the item and the tier, holding its URL and title, and its place in a
	 * folder a kept `collected` row keyed by the folder, the item and the
	 * tier, which a folder's read leads by and a move rewrites carrying no
	 * copy; each use mark is a kept row keyed by the item, the tier of the
	 * copy it marks and the mark, so marking it again replaces the day.
	 */
	public function test_each_saved_copy_and_each_use_mark_is_a_row_of_its_tier(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		$this->assertSame( [ 'collection_key' => 'TEXT NOT NULL', 'item_key' => 'TEXT NOT NULL', 'tier' => 'TEXT NOT NULL', 'actor' => 'TEXT NOT NULL', 'ts' => 'REAL NOT NULL' ], $tables['collected']['columns'] );
		$this->assertSame( [ 'collection_key', 'item_key', 'tier' ], $tables['collected']['key'] );
		$this->assertArrayNotHasKey( 'indexes', $tables['collected'] );
		$this->assertSame( [ 'item_key' => 'TEXT NOT NULL', 'tier' => 'TEXT NOT NULL', 'url' => 'TEXT NOT NULL', 'title' => 'TEXT NOT NULL' ], $tables['saved_copies']['columns'] );
		$this->assertSame( [ 'item_key', 'tier' ], $tables['saved_copies']['key'] );
		$this->assertTrue( $tables['saved_copies']['keep'] );
		$this->assertSame( [ 'item_key' => 'TEXT NOT NULL', 'tier' => 'TEXT NOT NULL', 'mark' => 'TEXT NOT NULL', 'day' => 'TEXT NOT NULL', 'actor' => 'TEXT NOT NULL', 'ts' => 'REAL NOT NULL' ], $tables['item_marks']['columns'] );
		$this->assertSame( [ 'item_key', 'tier', 'mark' ], $tables['item_marks']['key'] );
		$this->assertTrue( $tables['item_marks']['keep'] );
		$this->assertTrue( $tables['collected']['keep'] );
	}

	/**
	 * An exclusion is one kept row keyed by its item, so it outlives the
	 * item's expiry and its purge, holding what the excluded list answers.
	 */
	public function test_an_exclusion_is_a_kept_row_keyed_by_its_item(): void {
		$excluded = Intel_Graph_Row_Schema::tables()['excluded_items'];

		$this->assertSame( [ 'item_key' => 'TEXT NOT NULL', 'tier' => 'TEXT NOT NULL', 'url' => 'TEXT NOT NULL', 'title' => 'TEXT NOT NULL', 'why' => 'TEXT NOT NULL', 'actor' => 'TEXT NOT NULL', 'ts' => 'REAL NOT NULL' ], $excluded['columns'] );
		$this->assertSame( [ 'item_key' ], $excluded['key'] );
		$this->assertTrue( $excluded['keep'] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	/** The reads that open on a proposal's subject or a judged entity reach it by an index. */
	public function test_events_and_judgments_are_indexed_where_the_reads_open(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		$this->assertContains( [ 'subject', 'kind' ], $tables['events']['indexes'] ?? [] );
		$this->assertContains( [ 'entity_key' ], $tables['judgments']['indexes'] ?? [] );
		$this->assertSame( 12, Intel_Graph_Row_Schema::version() );
	}

	/** With no partition written, a table reads as an empty row set of its declared columns. */
	public function test_a_table_with_no_partition_is_an_empty_typed_row_set(): void {
		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => $this->make_temp_dir( 'intel-unwritten-' ) . '/intel-graph.p0.sqlite' ], Intel_Graph_Row_Schema::version() );

		$this->assertSame( [], $reader->aliases() );
		$this->assertSame( [], $reader->rows( 'SELECT entity_key, role, from_ts FROM ' . Intel_Graph_Row_Schema::all( $reader, 'roles' ) . ' r WHERE to_ts = 0', [] ) );
	}

	/** Only a read that asks for it sees each row's partition ordinal; every other read sees the declared columns alone. */
	public function test_the_partition_ordinal_is_opt_in(): void {
		$graph = $this->projector_graph();
		self::deliver( $graph, Intel_Event::make( 'entity_created', [ 'entity_key' => 'organization:plover-ledger', 'type' => 'organization', 'name' => 'Plover Ledger' ], 'user:58', 'fixture-3' ) );
		$graph->save_state();
		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => \Newspack_Nodes\Table_Node::file( 'intel-graph', 0 ) ], Intel_Graph_Row_Schema::version() );

		$this->assertCount( 5, $reader->rows( 'SELECT * FROM ' . Intel_Graph_Row_Schema::all( $reader, 'entities' ) . ' e', [] )[0] );
		$this->assertSame( [ 0 ], \array_slice( $reader->rows( 'SELECT * FROM ' . Intel_Graph_Row_Schema::all( $reader, 'entities', true ) . ' e', [] )[0], 5 ) );
	}

	public function test_the_range_bounds_are_whole_utc_days(): void {
		$this->assertSame( [ 1785715200, 1787270400 ], Intel_Graph_Row_Schema::range( '2026-08-03', '2026-08-20' ) );
		$this->assertSame( [ 1787184000, 1787270400 ], Intel_Graph_Row_Schema::range( '2026-08-20', '2026-08-20' ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'since 2026-08-21 is after until 2026-08-20' );
		Intel_Graph_Row_Schema::range( '2026-08-21', '2026-08-20' );
	}
}

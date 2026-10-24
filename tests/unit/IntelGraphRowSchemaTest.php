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

	public function test_the_schema_retains_item_rows_for_730_days(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		foreach ( $tables as $table => $spec ) {
			$this->assertNotSame( isset( $spec['retain'] ), isset( $spec['keep'] ), "{$table} is retained or kept, never both" );
			if ( isset( $spec['retain'] ) ) {
				[ $day, $window, $unit ] = $spec['retain'];
				$this->assertSame( [ 730, 86400 ], [ $window, $unit ], $table );
				$this->assertContains( [ $day ], $spec['indexes'] ?? [], "{$table} indexes the day it is retained by" );
			}
		}
		$this->assertSame( [ 'retrieved_day', 730, 86400 ], $tables['items']['retain'] );
		$this->assertSame( [ 'entity_key', 'type', 'name', 'created_ts' ], \array_keys( $tables['entities']['columns'] ), 'an entity row carries no id' );
		$this->assertArrayNotHasKey( 'autoincrement', $tables['entities'] );
		$this->assertSame( 1, Intel_Graph_Row_Schema::version() );
		$this->projector_graph();
		$this->assertSame( [ 'Intel_Graph' ], Core::node( 'intel-graph' )->arguments(), 'the Row_Table accepts the schema' );
	}

	/** A read given a line answers null while no projection opens, logged once; a read given none throws. */
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

	/** An item's time is its item_ts, or its retrieved_ts when undated, alike in SQL and in PHP. */
	public function test_an_items_time_is_one_rule_in_sql_and_php(): void {
		$pdo = new \PDO( 'sqlite::memory:' );
		$sql = $pdo->prepare( 'SELECT ' . Intel_Graph_Row_Schema::ITEM_TIME . ' FROM ( SELECT ? AS item_ts, ? AS retrieved_ts ) i' );
		$at  = [];
		foreach ( [ [ 0, 1793000017 ], [ 1792000041, 1793000017 ] ] as [ $item_ts, $retrieved_ts ] ) {
			$sql->bindValue( 1, $item_ts, \PDO::PARAM_INT );
			$sql->bindValue( 2, $retrieved_ts, \PDO::PARAM_INT );
			$sql->execute();
			$this->assertSame( Intel_Graph_Row_Schema::item_time( $item_ts, $retrieved_ts ), (int) $sql->fetchColumn() );
			$at[] = Intel_Graph_Row_Schema::item_time( $item_ts, $retrieved_ts );
		}
		$this->assertSame( [ 1793000017, 1792000041 ], $at );
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
	 * Row counts per table.
	 *
	 * @param list<string> $tables The tables.
	 * @return list<int>
	 */
	private static function counts( array $tables ): array {
		return \array_map( static fn ( string $t ): int => (int) self::graph_rows( $t, '1', 'COUNT(*)' )[0][0], $tables );
	}

	/** The reads that open on a proposal's subject or a judged entity reach it by an index. */
	public function test_events_and_judgments_are_indexed_where_the_reads_open(): void {
		$tables = Intel_Graph_Row_Schema::tables();

		$this->assertContains( [ 'subject', 'kind' ], $tables['events']['indexes'] ?? [] );
		$this->assertContains( [ 'entity_key' ], $tables['judgments']['indexes'] ?? [] );
		$this->assertSame( 1, Intel_Graph_Row_Schema::version() );
	}

	/** With no partition written, a table reads as an empty row set of its declared columns. */
	public function test_a_table_with_no_partition_is_an_empty_typed_row_set(): void {
		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => $this->make_temp_dir( 'intel-unwritten-' ) . '/intel-graph.p0.sqlite' ], Intel_Graph_Row_Schema::version() );

		$this->assertSame( [], $reader->aliases() );
		$this->assertSame( [], $reader->rows( 'SELECT entity_key, role, from_ts FROM ' . Intel_Graph_Row_Schema::all( $reader, 'roles' ) . ' r WHERE to_ts = 0', [] ) );
	}

	public function test_the_range_bounds_are_whole_utc_days(): void {
		$this->assertSame( [ 1785715200, 1787270400 ], Intel_Graph_Row_Schema::range( '2026-08-03', '2026-08-20' ) );
		$this->assertSame( [ 1787184000, 1787270400 ], Intel_Graph_Row_Schema::range( '2026-08-20', '2026-08-20' ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'since 2026-08-21 is after until 2026-08-20' );
		Intel_Graph_Row_Schema::range( '2026-08-21', '2026-08-20' );
	}
}

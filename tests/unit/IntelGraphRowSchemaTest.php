<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Core;
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

	public function test_judgments_and_collected_outlive_their_items(): void {
		$now       = 1793000000;
		Core::$now = (float) ( $now - 731 * 86400 );
		$projector = $this->projector_graph();
		$old       = (int) Core::$now;
		$events    = [
			Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:abc-7731', 'source' => 'feed', 'tier' => 'public', 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'body' => 'Body', 'item_ts' => $old, 'retrieved_ts' => $old ], 'machine:source:feed@9.4.1', '9.4.1' ),
			Intel_Event::make( 'link_asserted', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:harbor-gazette', 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0 ], 'machine:resolve@9.4.1', '9.4.1' ),
			Intel_Event::make( 'link_confirmed', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:harbor-gazette', 'item_url' => 'https://harborgazette.example/a' ], 'user:41', 'clients-v3' ),
			Intel_Event::make( 'collected', [ 'collection_key' => 'awards-2024', 'item_key' => 'feed:abc-7731', 'url' => 'https://harborgazette.example/a', 'title' => 'Award' ], 'user:41', 'clients-v3' ),
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
}

<?php
/**
 * Every reader this plugin's topologies declare must be durable, and the two
 * event streams must land where their request-side writer appends.
 *
 * The offsetlog and dead-letter dirs are arguments, and omitting one fails SILENT:
 * no cursor means the reader replays from the head after every restart; no
 * quarantine means poison is logged and dropped. ingest:consumer and
 * scored:consumer both shipped without a dead-letter dir before this guard existed.
 *
 * The substrate owns the audit itself (it reads each node's own node_schema for the
 * argument positions); this pins THIS plugin's topologies to it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests\Unit;

use Newspack_Intelligence\Intel_Event_Writer_Node;
use Newspack_Intelligence\Projector_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\Helpers\TopologyDurability;
use Newspack_Nodes\Tests\TestCase;

class TopologyDurabilityTest extends TestCase {

	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'intelligence-durability-' );
		$this->use_base_dir( $this->tmp );
		Topology_Registry::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_stock_topologies_declare_a_cursor_and_a_quarantine(): void {
		$violations = TopologyDurability::audit( \dirname( __DIR__, 2 ) . '/topologies' );
		$this->assertSame( [], $violations, \implode( "\n", $violations ) );
	}

	/** The composed graph builds both streams over the writer's own directories. */
	public function test_the_composed_topology_builds_both_durable_streams(): void {
		$this->load( 'newspack-intelligence' );

		$items    = Core::node( 'intel:items' );
		$ontology = Core::node( 'intel:ontology' );
		$this->assertInstanceOf( Partition_Node::class, $items );
		$this->assertInstanceOf( Partition_Node::class, $ontology );
		$this->assertSame( $this->tmp . '/logs/intel-ontology.p0', $ontology->partition_dir() );
		$this->assertSame( $this->tmp . '/logs/intel-items.p0', $items->partition_dir() );
		$this->assertSame( 16777216, $this->read_private( $items, 'segment_size' ) );
		$this->assertSame( 8192, $this->read_private( $items, 'max_segments' ) );
		$this->assertSame( 63072000, $this->read_private( $items, 'lifetime' ) );
		$this->assertSame( Partition_Node::LARGE_WRITE_VOID, $this->read_private( $items, 'large_write_mode' ) );
		$this->assertFalse( $this->read_private( $items, 'keep_everything' ) );
		$this->assertTrue( $this->read_private( $ontology, 'keep_everything' ) );
		$this->assertSame( Partition_Node::LARGE_WRITE_NONE, $this->read_private( $ontology, 'large_write_mode' ) );
		$this->assertInstanceOf( Intel_Event_Writer_Node::class, Core::node( 'intel:writer' ) );
		$this->assertSame( [ 'intel:items', 'intel:ontology' ], Core::node( 'intel:writer' )->display_targets() );
		$this->assertInstanceOf( Projector_Node::class, Core::node( 'projector' ), 'the composed graph projects both streams' );
	}

	/** A TICK blocks inside one fill(), so a peer must not steal the lock at 60s. */
	public function test_the_composed_topology_declares_a_ten_minute_stale_timeout(): void {
		$this->assertSame( 600, Topology_Registry::synthesize_entry( 'newspack-intelligence' )['stale_timeout'] ?? null );
	}

	/** Every source records into the writer at its declared tier. */
	public function test_the_sources_record_into_the_writer_at_their_tiers(): void {
		$this->load( 'newspack-intelligence' );

		foreach ( [ 'github' => 'public', 'linear' => 'internal', 'feed' => 'public' ] as $name => $tier ) {
			$source = Core::node( $name );
			$this->assertInstanceOf( Source_Node::class, $source, $name );
			$this->assertSame( 'intel:writer', $source->target(), $name );
			$this->assertSame( $tier, $this->read_private( $source, 'tier' ), $name );
		}
	}

	/** Compose a topology the way a worker does, over the test base dir. */
	private function load( string $topology ): void {
		Core::$var['partition'] = '0';
		Core::$var['topology']  = $topology;
		$router                 = new Router_Node();
		$router->name( '_router' );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include {$topology}\n" );
	}
}

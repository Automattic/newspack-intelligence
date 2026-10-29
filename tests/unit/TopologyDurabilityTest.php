<?php
/**
 * Every reader this plugin's topologies declare must be durable, and the two
 * event streams must land where their request-side writer appends.
 *
 * The offsetlog and dead-letter dirs are arguments, and omitting one fails SILENT:
 * no cursor means the reader replays from the head after every restart; no
 * quarantine means poison is logged and dropped.
 *
 * The substrate owns the audit itself (it reads each node's own node_schema for the
 * argument positions); this pins THIS plugin's topologies to it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests\Unit;

use Newspack_Intelligence\Intel_Event_Writer_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Projector_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Delay_Node;
use Newspack_Nodes\Log_Discovery;
use Newspack_Nodes\Message;
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
		$this->assertSame( [ 'intel:items', 'intel:ontology', 'intel:watches' ], Core::node( 'intel:writer' )->display_targets() );
		$this->assertInstanceOf( Projector_Node::class, Core::node( 'projector' ), 'the composed graph projects both streams' );
	}

	/**
	 * The watches stream keeps a match as long as the item it names, two
	 * years, one PIPE_BUF line a record, and the projector reads it through
	 * its own multi-writer Consumer, which checkpoints the projector.
	 */
	public function test_the_composed_topology_builds_the_watches_stream_and_projects_it(): void {
		$this->load( 'newspack-intelligence' );

		$watches = Core::node( 'intel:watches' );
		$this->assertInstanceOf( Partition_Node::class, $watches );
		$this->assertSame( $this->tmp . '/logs/intel-watches.p0', $watches->partition_dir() );
		$this->assertSame(
			[ 4194304, 2, 4096, 8192, 63072000, 63072000 ],
			[ $this->read_private( $watches, 'segment_size' ), $this->read_private( $watches, 'min_segments' ), $this->read_private( $watches, 'num_segments' ), $this->read_private( $watches, 'max_segments' ), $this->read_private( $watches, 'min_lifetime' ), $this->read_private( $watches, 'lifetime' ) ]
		);
		$this->assertSame( Intel_Graph_Row_Schema::RETAIN_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS, $this->read_private( $watches, 'lifetime' ) );
		$this->assertSame( Partition_Node::LARGE_WRITE_NONE, $this->read_private( $watches, 'large_write_mode' ) );
		$this->assertFalse( $this->read_private( $watches, 'keep_everything' ) );
		$consumer = Core::node( 'intel:watches:consumer' );
		$this->assertSame( [ 'projector', [ 'projector' ], true ], [ $consumer?->target(), $this->read_private( $consumer, 'snapshot_nodes' ), $this->read_private( $consumer, 'multi_writer' ) ] );
		$this->assertSame( $this->tmp . '/offsets/newspack-intelligence.intel-watches.p0', $this->read_private( $consumer, 'offsetlog_dir' ) );
	}

	/** The later queue keeps a day, sweeps every minute, and replays each due record as a re-resolution. */
	public function test_the_later_queue_replays_into_the_reresolve_stream(): void {
		$this->load( 'newspack-intelligence' );

		$later = Core::node( 'intel:later' );
		$this->assertInstanceOf( Delay_Node::class, $later );
		$this->assertSame( 'intel:reresolve', $later->target() );
		$this->assertSame(
			[ $this->tmp . '/logs/intel-later.p0', $this->tmp . '/offsets/newspack-intelligence.intel-later.p0', $this->tmp . '/deadletter/newspack-intelligence.intel-later.p0', 60, 86400 ],
			[ $this->read_private( $later, 'dir' ), $this->read_private( $later, 'offsets_dir' ), $this->read_private( $later, 'deadletter_dir' ), $this->read_private( $later, 'interval_s' ), $this->read_private( $later, 'max_age_s' ) ]
		);
		$this->assertSame( [ 'extractor', 'intel:writer', 'intel:later' ], Core::node( 'resolver' )->display_targets() );
	}

	/**
	 * Every intel stream gates a raw read on the restricted tier's own
	 * capability, `Intel_Tiers::CAPABILITY`, which the TSL spells out: once
	 * each of the four Partitions and the later Delay's queue writes, its dir
	 * holds the `.read-capability` marker naming it, and so do the cursor and
	 * the dead letters of every reader of them, once each has checkpointed and
	 * quarantined a torn line.
	 */
	public function test_every_intel_stream_and_its_readers_carry_the_restricted_marker(): void {
		$this->load( 'newspack-intelligence' );
		foreach ( [ 'intel:items', 'intel:ontology', 'intel:reresolve', 'intel:watches' ] as $stream ) {
			$partition                 = Core::node( $stream );
			$probe                     = Message::new_message();
			$probe[ Message::TYPE ]    = Message::TM_BYTESTREAM;
			$probe[ Message::VALUE ]   = "marker probe 7713 {$stream}";
			$partition->fill( $probe );
			$partition->flush();
			self::tear( $partition->partition_dir() );
		}
		$at                      = static function ( float $t ): void {
			Core::$clock = static fn (): float => $t;
			Core::right_now();
		};
		$at( 1793278000.0 );
		$later                   = Core::node( 'intel:later' );
		$park                    = Message::new_message();
		$park[ Message::TYPE ]   = Message::TM_STRUCT;
		$park[ Message::KEY ]    = 'feed:probe-7713';
		$park[ Message::VALUE ]  = [ 'event' => 'item_reresolve', 'item_key' => 'feed:probe-7713', 'fresh_after' => 0, 'not_before' => Core::right_now() + 3600 ];
		$later->fill( $park );
		$later->fire_cb();
		self::tear( "{$this->tmp}/logs/intel-later.p0" );
		$at( 1793278070.0 );
		$later->fire_cb();
		foreach ( [ 'intel:items:consumer', 'intel:items:resolve', 'intel:ontology:consumer', 'intel:watches:consumer' ] as $name ) {
			$reader = Core::node( $name );
			$reader->poll();
			while ( $reader->poll() > 0 ) {
				continue;
			}
			$reader->checkpoint();
		}

		$marked = [];
		foreach ( [ 'logs/intel-items.p0', 'logs/intel-ontology.p0', 'logs/intel-reresolve.p0', 'logs/intel-later.p0', 'logs/intel-watches.p0' ] as $dir ) {
			$marked[ $dir ] = self::marker( "{$this->tmp}/{$dir}" );
		}
		foreach ( [ 'intel-items', 'intel-items-resolve', 'intel-ontology', 'intel-later', 'intel-watches' ] as $reader ) {
			foreach ( [ 'offsets', 'deadletter' ] as $root ) {
				$marked[ "{$root}/newspack-intelligence.{$reader}.p0" ] = self::marker( "{$this->tmp}/{$root}/newspack-intelligence.{$reader}.p0" );
			}
		}
		$this->assertSame( \array_fill_keys( \array_keys( $marked ), Intel_Tiers::CAPABILITY . "\n" ), $marked );
	}

	/**
	 * Append a line no reader can unpack to a log's newest segment.
	 *
	 * @param string $dir The log's dir.
	 */
	private static function tear( string $dir ): void {
		$segments = \glob( "{$dir}/*.log" ) ?: [];
		\natsort( $segments );
		\file_put_contents( (string) \end( $segments ), "torn record 7713\n", \FILE_APPEND );
	}

	/**
	 * What a dir's read-capability marker holds, or `missing`.
	 *
	 * @param string $dir The dir.
	 */
	private static function marker( string $dir ): string {
		$file = "{$dir}/" . Log_Discovery::READ_MARKER;
		return \is_file( $file ) ? (string) \file_get_contents( $file ) : 'missing';
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

	/** The Linear source fetches Newspack's own teams and no others. */
	public function test_the_linear_source_names_newspacks_teams(): void {
		$this->load( 'newspack-intelligence' );

		$this->assertSame(
			[ 'NEWS', 'NPAI', 'NPPRO', 'NPPM', 'NPMIG', 'NPLAUNC', 'NPSUPP', 'NPPD', 'DSGNEWS' ],
			$this->read_private( Core::node( 'linear' ), 'teams' )
		);
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

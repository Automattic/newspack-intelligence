<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Log_Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Guards the topology's Partition/Log retention geometry after the substrate's
 * retention rename (segment_size min_segments num_segments min_lifetime lifetime
 * [max_segments]) — num_segments is the count target, lifetime the age rule, and
 * max_segments a trailing hard cap.
 *
 * Asserts RESOLVED node properties, not the raw arg string: a raw-string check
 * would pass on the pre-rename form even though the tokens land in the wrong slots.
 */
final class TopologyRetentionTest extends TestCase {

	/** Sentinel proving num_segments (count target) lands in its own slot (not the default 4). */
	private const SENTINEL_NUM_SEGMENTS = 5;

	/** Sentinel proving min_lifetime lands right. */
	private const SENTINEL_MIN_LIFETIME = 86400;

	/** Sentinel proving lifetime (age rule) lands in its own slot (not the default 0). */
	private const SENTINEL_LIFETIME = 99999;

	/** Sentinel proving segment_size lands right. */
	private const SENTINEL_SEGMENT_SIZE = 12345;

	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = $this->make_temp_dir( 'intelligence-retention-' );
		Topology_Registry::reset();
		Topology_Registry::register_stock_dir( \dirname( __DIR__, 2 ) . '/topologies' );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** Compose the real split topology (its three includes) with sentinel <config:...> retention values. */
	private function load_topology(): void {
		Core::register_config_namespace(
			'config',
			fn ( string $key ) => [
				'logs_dir'     => $this->tmp . '/logs',
				'offsets_dir'  => $this->tmp . '/offsets',
				'segment_size' => self::SENTINEL_SEGMENT_SIZE,
				'min_segments' => Partition_Node::DEFAULT_MIN_SEGMENTS,
				'num_segments' => self::SENTINEL_NUM_SEGMENTS,
				'min_lifetime' => self::SENTINEL_MIN_LIFETIME,
				'lifetime'     => self::SENTINEL_LIFETIME,
				'max_segments' => 0,
			][ $key ] ?? null
		);

		Core::$var['partition'] = '0';

		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( new Capture_Sink_Node() );

		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );

		$shell->eval_script( "include newspack-intelligence\n" );
	}

	/** Each durable Partition resolves the renamed knobs into the right slots. */
	public function test_partitions_resolve_split_retention_geometry(): void {
		$this->load_topology();

		foreach ( [ 'ingest:partition', 'scored:partition' ] as $name ) {
			$partition = Core::node( $name );
			$this->assertInstanceOf( Partition_Node::class, $partition, $name );
			$this->assertSame( self::SENTINEL_SEGMENT_SIZE, $this->read_private( $partition, 'segment_size' ), $name );
			$this->assertSame( Partition_Node::DEFAULT_MIN_SEGMENTS, $this->read_private( $partition, 'min_segments' ), $name );
			$this->assertSame( self::SENTINEL_NUM_SEGMENTS, $this->read_private( $partition, 'num_segments' ), $name );
			$this->assertSame( self::SENTINEL_MIN_LIFETIME, $this->read_private( $partition, 'min_lifetime' ), $name );
			$this->assertSame( self::SENTINEL_LIFETIME, $this->read_private( $partition, 'lifetime' ), $name );
			// No trailing hard-cap token on this line → derived as 2 × num_segments.
			$this->assertSame( 2 * self::SENTINEL_NUM_SEGMENTS, $this->read_private( $partition, 'max_segments' ), $name );
		}
	}

	/** The digest Log resolves file segment_size=1 min_segments=2 num_segments=7. */
	public function test_digest_log_resolves_count_retention(): void {
		$this->load_topology();

		$log = Core::node( 'digest:log' );
		$this->assertInstanceOf( Log_Node::class, $log );
		$this->assertSame( 2, $this->read_private( $log, 'min_segments' ) );
		$this->assertSame( 7, $this->read_private( $log, 'num_segments' ) );
	}
}

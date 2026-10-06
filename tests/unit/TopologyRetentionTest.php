<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Digest_Builder_Node;
use Newspack_Intelligence\Insights_CI_Node;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\Summarizer_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Log_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;
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
 *
 * Also guards the digest's RESET fence along its real route: the composed
 * topology routes through `_router`, so a DONE still in flight at RESET completes
 * its own cycle before the fence clears the digest.
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
		$this->use_base_dir( $this->tmp );
		Topology_Registry::reset();
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
	}

	protected function tearDown(): void {
		Summarizer_Node::$llm_factory     = null;
		Digest_Builder_Node::$llm_factory = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** Compose the real split topology (its four stage includes) with sentinel <config:...> retention values. */
	private function load_topology(): void {
		Core::register_config_namespace(
			'config',
			fn ( string $key ) => [
				'logs_dir'       => $this->tmp . '/logs',
				'offsets_dir'    => $this->tmp . '/offsets',
				'deadletter_dir' => $this->tmp . '/deadletter',
				'segment_size'   => self::SENTINEL_SEGMENT_SIZE,
				'min_segments'   => Partition_Node::DEFAULT_MIN_SEGMENTS,
				'num_segments'   => self::SENTINEL_NUM_SEGMENTS,
				'min_lifetime'   => self::SENTINEL_MIN_LIFETIME,
				'lifetime'       => self::SENTINEL_LIFETIME,
				'max_segments'   => 0,
			][ $key ] ?? null
		);

		Core::$var['partition'] = '0';

		$router = new Router_Node();
		$router->name( '_router' );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( '_command_interpreter' );
		$interpreter->sink( $router );

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

	/**
	 * A DONE already in the ingest log when RESET fires completes the cycle it
	 * belongs to, and the items clear only when the fence comes back around.
	 */
	public function test_reset_fence_trails_a_late_done_through_the_pipeline(): void {
		Summarizer_Node::$llm_factory     = static fn (): ?LLM_Client => null;
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$this->load_topology();
		$asker = new Capture_Sink_Node();
		$asker->name( 'fence-asker' );
		$this->drain_pipeline();
		$this->ingest( Message::TM_STRUCT, 'github', [ 'source' => 'github', 'id' => 'github:73', 'title' => 'Late cycle ships', 'url' => 'u', 'body' => 'b', 'timestamp' => 0 ] );
		$this->ingest( Message::TM_INFO, 'github', "DONE\n" );
		$this->ingest( Message::TM_INFO, 'linear', "DONE\n" );
		$this->drain_pipeline();
		$this->ingest( Message::TM_INFO, 'feed', "DONE\n" );

		$reset                   = Message::new_message();
		$reset[ Message::TYPE ]  = Message::TM_REQUEST;
		$reset[ Message::FROM ]  = 'fence-asker';
		$reset[ Message::TO ]    = 'digest';
		$reset[ Message::VALUE ] = 'RESET';
		Core::node( '_router' )->fill( $reset );
		$digest = Core::node( 'digest' );
		$this->assertInstanceOf( Digest_Builder_Node::class, $digest );
		$this->assertCount( 1, $digest->save_state()['items'], 'RESET clears nothing until its fence returns' );

		$this->drain_pipeline();
		$log = Core::node( 'digest:log' );
		$this->assertInstanceOf( Log_Node::class, $log );
		$log->flush();

		$this->assertStringContainsString( 'Late cycle ships', Insights_CI_Node::read_latest_digest( $this->tmp . '/logs/digest.md' ) );
		$this->assertSame( [ 'items' => [], 'done' => 0, 'total' => 3, 'reported' => [] ], $digest->save_state() );
	}

	/**
	 * Append one message to the ingest log, as a source's TICK does.
	 *
	 * @param mixed $value The VALUE.
	 */
	private function ingest( int $type, string $from, mixed $value ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = $type;
		$message[ Message::FROM ]  = $from;
		$message[ Message::VALUE ] = $value;
		Core::node( 'ingest:partition' )->fill( $message );
	}

	/** Read both durable hops to their end: ingest through the summary stage, then scored into the digest. */
	private function drain_pipeline(): void {
		foreach ( [ 'ingest' => 'ingest', 'scored' => 'scored' ] as $log ) {
			$partition = Core::node( "{$log}:partition" );
			$consumer  = Core::node( "{$log}:consumer" );
			$this->assertInstanceOf( Partition_Node::class, $partition );
			$this->assertInstanceOf( Consumer_Node::class, $consumer );
			$partition->flush();
			$consumer->drain();
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

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/routes-to-workers.php';

/**
 * A request from outside the worker reaches its nodes through each live
 * worker's input IPC partition, addressed `{worker}/{node}`, the worker's
 * topology being the one active topology that declares the event writer,
 * whatever its name.
 */
final class WorkerRouteTest extends TestCase {
	use Routes_To_Workers;

	protected function setUp(): void {
		parent::setUp();
		$this->run_intelligence_as( 'plover-intel' );
	}

	protected function tearDown(): void {
		\update_option( 'newspack_nodes_topologies', [] );
		\Newspack_Nodes\Config::reset();
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** Intelligence's topology is the active one declaring the event writer, under any name. */
	public function test_the_topology_is_the_active_one_declaring_the_writer(): void {
		$this->run_intelligence_as( 'tern-desk', 'make_node Watcher watcher intel:writer' );
		\update_option( 'newspack_nodes_topologies', [ 'tern-desk' ] );
		\Newspack_Nodes\Config::reset();

		$this->assertSame( 'tern-desk', Worker_Route::topology() );
	}

	/**
	 * While the active topologies declare no writer, routing is refused naming
	 * why.
	 */
	public function test_no_active_topology_declaring_the_writer_is_refused(): void {
		\update_option( 'newspack_nodes_topologies', [ 'topic-probe' ] );
		\Newspack_Nodes\Config::reset();
		$base = $this->make_temp_dir( 'worker-route-unowned-' );
		self::live_worker( $base, 'plover-intel.p0' );

		$this->assertNull( Worker_Route::running() );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No active topology declares an Intel_Event_Writer node' );
		Worker_Route::send( $this->interpreter( 'route-asker-21' ), $base, [ [ 'feed', 'TICK' ] ] );
	}

	/** Two active topologies declaring the writer are refused, naming both. */
	public function test_two_active_topologies_declaring_the_writer_are_refused(): void {
		$this->run_intelligence_as( 'wren-intel' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Active topologies plover-intel and wren-intel each declare an Intel_Event_Writer node; activate one.' );
		Worker_Route::running();
	}

	/** An active topology that will not read, with none declaring the writer, is refused, naming it and why. */
	public function test_an_unreadable_active_topology_is_named_when_none_declares_the_writer(): void {
		$dir = $this->make_temp_dir( 'worker-route-broken-' );
		\file_put_contents( "{$dir}/gannet-intel.tsl", "include gannet-intel-absent\n" );
		Topology_Registry::register_stock_dir( $dir );
		\update_option( 'newspack_nodes_topologies', [ 'gannet-intel' ] );
		\Newspack_Nodes\Config::reset();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No active topology declares an Intel_Event_Writer node, and gannet-intel will not read: unknown topology in include: gannet-intel-absent' );
		Worker_Route::running();
	}

	/**
	 * Every unreadable topology rides the refusal's chain, so a caller below
	 * manage reads a fixed line wherever among them an engine error sits.
	 */
	public function test_an_engine_error_behind_any_unreadable_topology_is_held_back_below_manage(): void {
		require_once \dirname( __DIR__, 3 ) . '/newspack-nodes/tests/Helpers/EngineFaultNode.php';
		Command_Interpreter_Node::register_namespace( 'Newspack_Nodes\\Tests\\' );
		$dir = $this->make_temp_dir( 'worker-route-fault-' );
		\file_put_contents( "{$dir}/gannet-intel.tsl", "include gannet-intel-absent\n" );
		\file_put_contents( "{$dir}/shag-intel.tsl", "make_node Engine_Fault shag-3317\n" );
		Topology_Registry::register_stock_dir( $dir );
		\update_option( 'newspack_nodes_topologies', [ 'gannet-intel', 'shag-intel' ] );
		\Newspack_Nodes\Config::reset();

		try {
			Worker_Route::running();
			$this->fail( 'two unreadable topologies must refuse' );
		} catch ( \RuntimeException $e ) {
			\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::READ;
			$this->assertSame( 'health: internal error; the reason is in the server\'s log', Core::caller_message( $e, 'health' ) );
		}
	}

	/**
	 * An active name that does not resolve to any `.tsl` declares nothing, so
	 * without an Intelligence topology none runs.
	 */
	public function test_a_stale_active_name_with_no_tsl_is_not_running(): void {
		\update_option( 'newspack_nodes_topologies', [ 'heron-old' ] );
		\Newspack_Nodes\Config::reset();

		$this->assertNull( Worker_Route::running() );
	}

	/** Only this topology's workers are reached, however other locks are named. */
	public function test_only_the_topologys_workers_are_reached(): void {
		$base = $this->make_temp_dir( 'worker-route-workers-' );
		foreach ( [ 'plover-intel.p0', 'plover-intel.p5', 'newspack-intelligence.p1', 'newspack-intelligence-collect.p2', 'other.p0' ] as $worker_id ) {
			self::live_worker( $base, $worker_id );
		}
		// A worker id never spells a padded partition, so this lock is unowned.
		\mkdir( $base . '/locks/plover-intel.p03.lock.d', 0777, true );

		$reached = Worker_Route::send( $this->interpreter( 'route-asker-3' ), $base, [ [ 'feed', 'TICK' ] ] );

		$this->assertSame( 2, $reached );
		foreach ( [ 'plover-intel.p0' => 1, 'plover-intel.p5' => 1, 'newspack-intelligence.p1' => 0, 'newspack-intelligence-collect.p2' => 0, 'other.p0' => 0 ] as $worker_id => $count ) {
			$this->assertCount( $count, self::worker_input( $base, $worker_id ), $worker_id );
		}
	}

	/** A live worker whose input cannot be mounted is not counted as reached. */
	public function test_an_unreachable_worker_is_not_counted(): void {
		$base = $this->make_temp_dir( 'worker-route-unreachable-' );
		self::live_worker( $base, 'plover-intel.p1' );
		\mkdir( $base . '/locks/plover-intel.p2.lock.d', 0777, true );

		$this->assertSame( 1, Worker_Route::send( $this->interpreter( 'route-asker-4' ), $base, [ [ 'github', 'TICK' ] ] ) );
	}

	/**
	 * When every live worker is unreachable, the send is refused as if there
	 * were no worker at all.
	 */
	public function test_no_reachable_worker_is_refused(): void {
		$base = $this->make_temp_dir( 'worker-route-none-reached-' );
		\mkdir( $base . '/locks/plover-intel.p2.lock.d', 0777, true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No plover-intel worker could be reached' );

		Worker_Route::send( $this->interpreter( 'route-asker-5' ), $base, [ [ 'github', 'TICK' ] ] );
	}

	/** Every request reaches every live worker, in order, FROM the asking interpreter. */
	public function test_each_request_is_appended_to_each_live_worker_input(): void {
		$base = $this->make_temp_dir( 'worker-route-send-' );
		self::live_worker( $base, 'plover-intel.p2' );
		self::live_worker( $base, 'plover-intel.p6' );

		$workers = Worker_Route::send( $this->interpreter( 'route-asker-8' ), $base, [ [ 'reresolver', 'RERESOLVE 2026-01-04 2026-02-07' ], [ 'feed', 'TICK' ] ] );

		$this->assertSame( 2, $workers );
		foreach ( [ 'plover-intel.p2', 'plover-intel.p6' ] as $worker_id ) {
			$written = self::worker_input( $base, $worker_id );
			$this->assertSame(
				[
					[ Message::TM_REQUEST, 'route-asker-8', 'reresolver', 'RERESOLVE 2026-01-04 2026-02-07' ],
					[ Message::TM_REQUEST, 'route-asker-8', 'feed', 'TICK' ],
				],
				\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::FROM ], $m[ Message::TO ], $m[ Message::VALUE ] ], $written ),
				$worker_id
			);
		}
	}

	/** Every event reaches every live worker's node as a struct, in order, FROM the asking interpreter. */
	public function test_each_event_is_appended_as_a_struct_to_each_live_worker_input(): void {
		$base = $this->make_temp_dir( 'worker-route-append-' );
		self::live_worker( $base, 'plover-intel.p2' );
		self::live_worker( $base, 'plover-intel.p6' );
		$events = [ [ 'event' => 'item_purged', 'item_key' => 'linear:WREN-4410' ], [ 'event' => 'item_purged', 'item_key' => 'linear:WREN-4411' ] ];

		$this->assertSame( 2, Worker_Route::append( $this->interpreter( 'route-asker-11' ), $base, 'intel:writer', $events ) );

		foreach ( [ 'plover-intel.p2', 'plover-intel.p6' ] as $worker_id ) {
			$this->assertSame(
				[
					[ Message::TM_STRUCT, 'route-asker-11', 'intel:writer', $events[0] ],
					[ Message::TM_STRUCT, 'route-asker-11', 'intel:writer', $events[1] ],
				],
				\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::FROM ], $m[ Message::TO ], $m[ Message::VALUE ] ], self::worker_input( $base, $worker_id ) ),
				$worker_id
			);
		}
	}

	/** Appending with no live worker is refused like a request. */
	public function test_an_append_with_no_live_worker_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No plover-intel worker could be reached' );
		Worker_Route::append( $this->interpreter( 'route-asker-12' ), $this->make_temp_dir( 'worker-route-append-none-' ), 'intel:writer', [ [ 'event' => 'item_purged' ] ] );
	}

	/**
	 * The workers have read through once every live worker's committed input
	 * cursor reaches the end; another topology's worker is not asked.
	 */
	public function test_the_workers_have_read_through_once_every_cursor_reaches_the_end(): void {
		$base = $this->make_temp_dir( 'worker-route-read-' );
		self::live_worker( $base, 'plover-intel.p3' );
		self::live_worker( $base, 'plover-intel.p4' );
		self::live_worker( $base, 'newspack-intelligence-collect.p2' );

		$this->assertTrue( Worker_Route::read_through( $base ), 'empty inputs hold nothing unread' );

		$asker = $this->interpreter( 'route-asker-13' );
		Worker_Route::append( $asker, $base, 'intel:writer', [ [ 'event' => 'item_purged', 'item_key' => 'feed:abc-77' ] ] );
		Worker_Route::send( $asker, $base, [ [ 'feed', 'TICK' ] ] );
		\file_put_contents( Worker_Base::ipc_dir( $base, 'newspack-intelligence-collect', 2, Worker_Base::IPC_INPUT ) . '/0.log', "[1]\n" );

		$this->assertFalse( Worker_Route::read_through( $base ) );

		self::read_input( $base, 'plover-intel.p3' );

		$this->assertFalse( Worker_Route::read_through( $base ), 'one worker has not read it' );

		self::read_input( $base, 'plover-intel.p4' );

		$this->assertTrue( Worker_Route::read_through( $base ) );
	}

	public function test_no_live_worker_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No plover-intel worker could be reached' );
		Worker_Route::send( $this->interpreter( 'route-asker-9' ), $this->make_temp_dir( 'worker-route-none-' ), [ [ 'github', 'TICK' ] ] );
	}

	/** An interpreter under `$name`, sinking into a real Router. */
	private function interpreter( string $name ): Command_Interpreter_Node {
		$router = new Router_Node();
		$router->name( '_router' );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( $name );
		$interpreter->sink( $router );
		return $interpreter;
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/routes-to-workers.php';

/**
 * A request from outside the worker reaches its nodes through each live
 * worker's input IPC partition, addressed `{worker}/{node}`.
 */
final class WorkerRouteTest extends TestCase {
	use Routes_To_Workers;

	/** Only this topology's workers are reached, however other locks are named. */
	public function test_only_the_topologys_workers_are_reached(): void {
		$base = $this->make_temp_dir( 'worker-route-workers-' );
		foreach ( [ 'newspack-intelligence.p0', 'newspack-intelligence.p5', 'newspack-intelligence-collect.p2', 'other.p0' ] as $worker_id ) {
			self::live_worker( $base, $worker_id );
		}
		// No worker id spells a padded partition, so no worker owns this lock.
		\mkdir( $base . '/locks/newspack-intelligence.p03.lock.d', 0777, true );

		$reached = Worker_Route::send( $this->interpreter( 'route-asker-3' ), $base, [ [ 'feed', 'TICK' ] ] );

		$this->assertSame( 2, $reached );
		foreach ( [ 'newspack-intelligence.p0' => 1, 'newspack-intelligence.p5' => 1, 'newspack-intelligence-collect.p2' => 0, 'other.p0' => 0 ] as $worker_id => $count ) {
			$this->assertCount( $count, self::worker_input( $base, $worker_id ), $worker_id );
		}
	}

	/** A live worker whose input cannot be mounted is not counted as reached. */
	public function test_an_unreachable_worker_is_not_counted(): void {
		$base = $this->make_temp_dir( 'worker-route-unreachable-' );
		self::live_worker( $base, 'newspack-intelligence.p1' );
		\mkdir( $base . '/locks/newspack-intelligence.p2.lock.d', 0777, true );

		$this->assertSame( 1, Worker_Route::send( $this->interpreter( 'route-asker-4' ), $base, [ [ 'github', 'TICK' ] ] ) );
	}

	/** Live workers none of which can be reached are refused like no worker at all. */
	public function test_no_reachable_worker_is_refused(): void {
		$base = $this->make_temp_dir( 'worker-route-none-reached-' );
		\mkdir( $base . '/locks/newspack-intelligence.p2.lock.d', 0777, true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );

		Worker_Route::send( $this->interpreter( 'route-asker-5' ), $base, [ [ 'github', 'TICK' ] ] );
	}

	/** Every request reaches every live worker, in order, FROM the asking interpreter. */
	public function test_each_request_is_appended_to_each_live_worker_input(): void {
		$base = $this->make_temp_dir( 'worker-route-send-' );
		self::live_worker( $base, 'newspack-intelligence.p2' );
		self::live_worker( $base, 'newspack-intelligence.p6' );

		$workers = Worker_Route::send( $this->interpreter( 'route-asker-8' ), $base, [ [ 'reresolver', 'RERESOLVE 2026-01-04 2026-02-07' ], [ 'feed', 'TICK' ] ] );

		$this->assertSame( 2, $workers );
		foreach ( [ 'newspack-intelligence.p2', 'newspack-intelligence.p6' ] as $worker_id ) {
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
		self::live_worker( $base, 'newspack-intelligence.p2' );
		self::live_worker( $base, 'newspack-intelligence.p6' );
		$events = [ [ 'event' => 'item_purged', 'item_key' => 'linear:WPCOM-4410' ], [ 'event' => 'item_purged', 'item_key' => 'linear:WPCOM-4411' ] ];

		$this->assertSame( 2, Worker_Route::append( $this->interpreter( 'route-asker-11' ), $base, 'intel:writer', $events ) );

		foreach ( [ 'newspack-intelligence.p2', 'newspack-intelligence.p6' ] as $worker_id ) {
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
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );
		Worker_Route::append( $this->interpreter( 'route-asker-12' ), $this->make_temp_dir( 'worker-route-append-none-' ), 'intel:writer', [ [ 'event' => 'item_purged' ] ] );
	}

	/**
	 * The workers have read through once every live worker's committed input
	 * cursor reaches the end; another topology's worker is not asked.
	 */
	public function test_the_workers_have_read_through_once_every_cursor_reaches_the_end(): void {
		$base = $this->make_temp_dir( 'worker-route-read-' );
		self::live_worker( $base, 'newspack-intelligence.p3' );
		self::live_worker( $base, 'newspack-intelligence.p4' );
		self::live_worker( $base, 'newspack-intelligence-collect.p2' );

		$this->assertTrue( Worker_Route::read_through( $base ), 'empty inputs hold nothing unread' );

		$asker = $this->interpreter( 'route-asker-13' );
		Worker_Route::append( $asker, $base, 'intel:writer', [ [ 'event' => 'item_purged', 'item_key' => 'feed:abc-77' ] ] );
		Worker_Route::send( $asker, $base, [ [ 'feed', 'TICK' ] ] );
		\file_put_contents( Worker_Base::ipc_dir( $base, 'newspack-intelligence-collect', 2, Worker_Base::IPC_INPUT ) . '/0.log', "[1]\n" );

		$this->assertFalse( Worker_Route::read_through( $base ) );

		self::read_input( $base, 'newspack-intelligence.p3' );

		$this->assertFalse( Worker_Route::read_through( $base ), 'one worker has not read it' );

		self::read_input( $base, 'newspack-intelligence.p4' );

		$this->assertTrue( Worker_Route::read_through( $base ) );
	}

	public function test_no_live_worker_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );
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

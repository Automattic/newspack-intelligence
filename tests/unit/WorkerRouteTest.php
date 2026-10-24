<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Router_Node;
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
		foreach ( [ 'newspack-intelligence.p0', 'newspack-intelligence.p5', 'newspack-intelligence-gate.p2', 'other.p0' ] as $worker_id ) {
			self::live_worker( $base, $worker_id );
		}
		// No worker id spells a padded partition, so no worker owns this lock.
		\mkdir( $base . '/locks/newspack-intelligence.p03.lock.d', 0777, true );

		$reached = Worker_Route::send( $this->interpreter( 'route-asker-3' ), $base, [ [ 'digest', 'REGENERATE' ] ] );

		$this->assertSame( 2, $reached );
		foreach ( [ 'newspack-intelligence.p0' => 1, 'newspack-intelligence.p5' => 1, 'newspack-intelligence-gate.p2' => 0, 'other.p0' => 0 ] as $worker_id => $count ) {
			$this->assertCount( $count, self::worker_input( $base, $worker_id ), $worker_id );
		}
	}

	/** A live worker whose input cannot be mounted is not counted as reached. */
	public function test_an_unreachable_worker_is_not_counted(): void {
		$base = $this->make_temp_dir( 'worker-route-unreachable-' );
		self::live_worker( $base, 'newspack-intelligence.p1' );
		\mkdir( $base . '/locks/newspack-intelligence.p2.lock.d', 0777, true );

		$this->assertSame( 1, Worker_Route::send( $this->interpreter( 'route-asker-4' ), $base, [ [ 'digest', 'RESET' ] ] ) );
	}

	/** Live workers none of which can be reached are refused like no worker at all. */
	public function test_no_reachable_worker_is_refused(): void {
		$base = $this->make_temp_dir( 'worker-route-none-reached-' );
		\mkdir( $base . '/locks/newspack-intelligence.p2.lock.d', 0777, true );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );

		Worker_Route::send( $this->interpreter( 'route-asker-5' ), $base, [ [ 'digest', 'RESET' ] ] );
	}

	/** Every request reaches every live worker, in order, FROM the asking interpreter. */
	public function test_each_request_is_appended_to_each_live_worker_input(): void {
		$base = $this->make_temp_dir( 'worker-route-send-' );
		self::live_worker( $base, 'newspack-intelligence.p2' );
		self::live_worker( $base, 'newspack-intelligence.p6' );

		$workers = Worker_Route::send( $this->interpreter( 'route-asker-8' ), $base, [ [ 'reresolver', 'RERESOLVE 2026-01-04 2026-02-07' ], [ 'digest', 'REGENERATE' ] ] );

		$this->assertSame( 2, $workers );
		foreach ( [ 'newspack-intelligence.p2', 'newspack-intelligence.p6' ] as $worker_id ) {
			$written = self::worker_input( $base, $worker_id );
			$this->assertSame(
				[
					[ Message::TM_REQUEST, 'route-asker-8', 'reresolver', 'RERESOLVE 2026-01-04 2026-02-07' ],
					[ Message::TM_REQUEST, 'route-asker-8', 'digest', 'REGENERATE' ],
				],
				\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::FROM ], $m[ Message::TO ], $m[ Message::VALUE ] ], $written ),
				$worker_id
			);
		}
	}

	public function test_no_live_worker_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );
		Worker_Route::send( $this->interpreter( 'route-asker-9' ), $this->make_temp_dir( 'worker-route-none-' ), [ [ 'digest', 'RESET' ] ] );
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

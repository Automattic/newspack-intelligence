<?php
/**
 * Worker_Route: how a request from outside the worker reaches its nodes. Each
 * live worker's input IPC partition is mounted into the caller's graph, and
 * each request is routed to `{worker_id}/{node}`, which the Router peels to
 * that partition; the worker reads it and routes it on to `{node}`. Durable
 * and synchronous, with no live-worker dependency on read.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Spawn_Coordinator;

\defined( 'ABSPATH' ) || exit;

final class Worker_Route {

	/** The worker topology the requests reach; also the worker-id prefix. */
	private const TOPOLOGY = 'newspack-intelligence';

	/**
	 * Append each request, in order, to every live worker's input partition,
	 * a TM_REQUEST to `{worker_id}/{node}` carrying its line as
	 * `request_node <path> <line>` mints it. FROM is the interpreter's name;
	 * the worker's input reader stamps it under `_repl`, so the answer lands
	 * on the worker's output partition. A worker whose partition cannot be
	 * mounted is skipped and not counted.
	 *
	 * @param Command_Interpreter_Node   $interpreter The caller's interpreter, whose name each request carries as FROM.
	 * @param string                     $base_dir    The substrate base directory (ipc/locks live under it).
	 * @param list<array{0:string,1:string}> $requests Each `[ node, request line ]`.
	 * @return int The workers reached.
	 * @throws \RuntimeException When no worker of the topology is reached.
	 */
	public static function send( Command_Interpreter_Node $interpreter, string $base_dir, array $requests ): int {
		$reached = 0;
		foreach ( self::live_workers( $base_dir ) as $worker_id ) {
			$out = self::ipc_out( $worker_id, $base_dir );
			if ( null === $out ) {
				continue;
			}
			foreach ( $requests as [ $node, $line ] ) {
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_REQUEST;
				$message[ Message::FROM ]  = $interpreter->name();
				$message[ Message::TO ]    = Message::join_path( $worker_id, $node );
				$message[ Message::VALUE ] = $line;
				$interpreter->fill( $message );
			}
			$out->flush();
			++$reached;
		}
		if ( 0 === $reached ) {
			throw new \RuntimeException( \esc_html( 'No ' . self::TOPOLOGY . ' worker could be reached: none is live with a mountable input — start the workers first.' ) );
		}
		return $reached;
	}

	/**
	 * The Partition appending to a worker's input IPC log, mounted by the
	 * substrate; null when the worker cannot be mounted.
	 *
	 * @param string $worker_id The worker id.
	 * @param string $base_dir  The substrate base directory.
	 */
	private static function ipc_out( string $worker_id, string $base_dir ): ?Partition_Node {
		if ( ! Bootstrap::register_worker_partition( $worker_id, $base_dir ) ) {
			return null;
		}
		$node = Core::node( $worker_id );
		return $node instanceof Partition_Node ? $node : null;
	}

	/**
	 * Live worker ids of the topology — those holding a lock dir.
	 *
	 * @param string $base_dir The substrate base directory.
	 * @return list<string>
	 */
	private static function live_workers( string $base_dir ): array {
		$ids = [];
		foreach ( Spawn_Coordinator::worker_lock_dirs( $base_dir ) as $lock ) {
			if ( self::TOPOLOGY === $lock['type'] ) {
				$ids[] = $lock['id'];
			}
		}
		return $ids;
	}
}

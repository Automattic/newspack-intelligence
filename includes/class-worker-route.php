<?php
/**
 * Worker_Route: how a request or an event from outside the worker reaches
 * its nodes. Each live worker's input IPC partition is mounted into the
 * caller's graph, and each message is routed to `{worker_id}/{node}`, which
 * the Router peels to that partition; the worker reads it and routes it on
 * to `{node}`. Durable and synchronous, with no live-worker dependency on
 * read; `read_through()` tells a caller pacing its writes when a worker has
 * read them.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Worker_Base;

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
		return self::deliver( $interpreter, $base_dir, \array_map( static fn ( array $request ): array => [ $request[0], Message::TM_REQUEST, $request[1] ], $requests ) );
	}

	/**
	 * Append each event, in order, to every live worker's input partition, a
	 * TM_STRUCT to `{worker_id}/{node}`, which the worker routes on to that
	 * node as it routes a request.
	 *
	 * @param Command_Interpreter_Node  $interpreter The caller's interpreter, whose name each event carries as FROM.
	 * @param string                    $base_dir    The substrate base directory.
	 * @param string                    $node        The worker's node the events reach.
	 * @param list<array<string,mixed>> $events      The events.
	 * @return int The workers reached.
	 * @throws \RuntimeException When no worker of the topology is reached.
	 */
	public static function append( Command_Interpreter_Node $interpreter, string $base_dir, string $node, array $events ): int {
		return self::deliver( $interpreter, $base_dir, \array_map( static fn ( array $event ): array => [ $node, Message::TM_STRUCT, $event ], $events ) );
	}

	/**
	 * Append each message, `[ node, type, value ]`, in order, to every live
	 * worker's input partition, addressed `{worker_id}/{node}`, and flush
	 * each worker's once.
	 *
	 * @param Command_Interpreter_Node             $interpreter The caller's interpreter.
	 * @param string                               $base_dir    The substrate base directory.
	 * @param list<array{0:string,1:int,2:mixed}> $messages    Each `[ node, TYPE, VALUE ]`.
	 * @return int The workers reached.
	 * @throws \RuntimeException When no worker of the topology is reached.
	 */
	private static function deliver( Command_Interpreter_Node $interpreter, string $base_dir, array $messages ): int {
		$reached = 0;
		foreach ( self::live_workers( $base_dir ) as $worker_id ) {
			$out = self::ipc_out( $worker_id, $base_dir );
			if ( null === $out ) {
				continue;
			}
			foreach ( $messages as [ $node, $type, $value ] ) {
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = $type;
				$message[ Message::FROM ]  = $interpreter->name();
				$message[ Message::TO ]    = Message::join_path( $worker_id, $node );
				$message[ Message::VALUE ] = $value;
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
	 * Whether every live worker of the topology has read its input to the
	 * end, as its input reader's committed cursor says; that reader commits
	 * about every 30 seconds, so a worker reads as behind until its next
	 * commit.
	 *
	 * @param string $base_dir The substrate base directory.
	 * @throws \LogicException When a live worker's id does not parse.
	 */
	public static function read_through( string $base_dir ): bool {
		foreach ( self::live_workers( $base_dir ) as $worker_id ) {
			[ $type, $partition ] = CLI::parse_worker_id( $worker_id ) ?? throw new \LogicException( \esc_html( "not a worker id: {$worker_id}" ) );
			$input                = Worker_Base::ipc_dir( $base_dir, $type, $partition, Worker_Base::IPC_INPUT );
			if ( ! Consumer_Node::lag_from_disk( $input, Worker_Base::ipc_dir( $base_dir, $type, $partition, Worker_Base::IPC_INPUT_OFFSETS ) )['caught_up'] ) {
				return false;
			}
		}
		return true;
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

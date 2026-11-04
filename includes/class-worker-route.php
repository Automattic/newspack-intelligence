<?php
/**
 * Worker_Route: how a request or an event from outside the worker reaches
 * its nodes. Each live worker's input IPC partition is mounted into the
 * caller's graph, and each message is routed to `{worker_id}/{node}`, which
 * the Router peels to that partition; the worker reads it and routes it on
 * to `{node}`. Durable and synchronous, with no live-worker dependency on
 * read; `read_through()` tells a caller pacing its writes when a worker has
 * read them. The workers are those of Intelligence's topology: the one
 * active topology that declares an `Intel_Event_Writer`, whatever its name,
 * so a deployment may compose its own.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Missing;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

final class Worker_Route {

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
	 * @throws \RuntimeException When the topology's workers cannot be reached.
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
	 * @throws \RuntimeException When the topology's workers cannot be reached.
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
	 * @throws \RuntimeException When the topology's workers cannot be reached.
	 */
	private static function deliver( Command_Interpreter_Node $interpreter, string $base_dir, array $messages ): int {
		$topology = self::topology();
		$reached  = 0;
		foreach ( self::workers_of( $topology, $base_dir ) as $worker_id ) {
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
			throw new \RuntimeException( \esc_html( "No {$topology} worker could be reached: none is live with a mountable input — start the workers first." ) );
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
	 * @api Read by `ontology reextract`, each of whose workers re-extracts the range.
	 * @param string $base_dir The substrate base directory.
	 * @return list<string>
	 */
	public static function live_workers( string $base_dir ): array {
		return self::workers_of( self::topology(), $base_dir );
	}

	/**
	 * Intelligence's topology, which every route reaches.
	 *
	 * @throws \RuntimeException When the active topologies declare no
	 *                           `Intel_Event_Writer`, or several do.
	 */
	public static function topology(): string {
		return self::running() ?? throw new \RuntimeException( 'No active topology declares an Intel_Event_Writer node: activate the topology that composes Intelligence.' );
	}

	/**
	 * The one active topology declaring an `Intel_Event_Writer`; null while
	 * none does and every active `.tsl` reads, which is collection off. An
	 * active name that does not resolve to any `.tsl` declares nothing, so it
	 * counts as read.
	 *
	 * @throws \RuntimeException When several do, naming them; or none does while an active `.tsl` will not read, naming it, its failure chained.
	 */
	public static function running(): ?string {
		[ $readable, $unreadable ] = Bootstrap::active_topologies();
		$unreadable                = \array_filter( $unreadable, static fn ( \Throwable $e ): bool => ! $e instanceof Topology_Missing );
		$declaring                 = self::declaring( \array_map( Core::as_string( ... ), \array_keys( $readable ) ) );
		if ( \count( $declaring ) > 1 ) {
			throw new \RuntimeException( \esc_html( 'Active topologies ' . \implode( ' and ', $declaring ) . ' each declare an Intel_Event_Writer node; activate one.' ) );
		}
		if ( [] === $declaring && [] !== $unreadable ) {
			$why = \array_map( static fn ( string $name, \Throwable $e ): string => "{$name} will not read: {$e->getMessage()}", \array_keys( $unreadable ), $unreadable );
			throw new \RuntimeException( \esc_html( 'No active topology declares an Intel_Event_Writer node, and ' . \implode( '; ', $why ) ), 0, \reset( $unreadable ) );
		}
		return $declaring[0] ?? null;
	}

	/**
	 * Live worker ids of `$topology`.
	 *
	 * @param string $topology The topology.
	 * @param string $base_dir The substrate base directory.
	 * @return list<string>
	 */
	private static function workers_of( string $topology, string $base_dir ): array {
		$ids = [];
		foreach ( Spawn_Coordinator::worker_lock_dirs( $base_dir ) as $lock ) {
			if ( $topology === $lock['type'] ) {
				$ids[] = $lock['id'];
			}
		}
		return $ids;
	}

	/**
	 * The topologies to activate, sorted: each catalog topology declaring an
	 * `Intel_Event_Writer` that is not included by any other such topology, so
	 * a part a composition includes is never offered. One that will not read is
	 * not offered, nor one declaring a source that cannot collect on this host.
	 *
	 * @return list<string>
	 * @throws Worker_Should_Stop When reading a topology raises a cooperative stop.
	 * @throws \InvalidArgumentException When a source's `make_node` line does not bind.
	 */
	public static function candidates(): array {
		$declaring = [];
		$failures  = Worker_Should_Stop::attempt_each(
			\array_map( Core::as_string( ... ), \array_keys( Bootstrap::get_topology_catalog() ) ),
			static function ( string $name ) use ( &$declaring ): void {
				$declaring = [ ...$declaring, ...self::declaring( [ $name ] ) ];
			}
		);
		Worker_Should_Stop::raise( \array_filter( $failures, static fn ( \Throwable $e ): bool => $e instanceof Worker_Should_Stop ) );
		$included = [];
		foreach ( $declaring as $name ) {
			$included = [ ...$included, ...self::included( Topology_Analyzer::statements( $name )['tree'] ) ];
		}
		$roots = \array_values( \array_filter( \array_diff( $declaring, $included ), self::collects_here( ... ) ) );
		\sort( $roots );
		return $roots;
	}

	/**
	 * Whether every source `$topology` declares can collect on this host.
	 *
	 * @throws \InvalidArgumentException When a source's `make_node` line does not bind, naming the topology.
	 */
	private static function collects_here( string $topology ): bool {
		foreach ( Topology_Analyzer::nodes_of_type( $topology, Source_Node::class ) as $node ) {
			/** @var class-string<Source_Node> $class nodes_of_type() kept only Source_Node types. */
			$class = Command_Interpreter_Node::resolve_class( Core::as_string( $node['type'] ) );
			try {
				$args = self::line( $class, $node );
			} catch ( \InvalidArgumentException $e ) {
				throw new \InvalidArgumentException( \esc_html( "{$topology}: {$e->getMessage()}" ), 0, $e );
			}
			if ( ! $class::runs_here( $args ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A node's `make_node` line bound by its class's own arguments, so each
	 * is read by name.
	 *
	 * @api Read by Intelligence_Health.
	 * @param class-string<\Newspack_Nodes\Node> $class The node's class.
	 * @param array<array-key,mixed>              $node  Its `nodes_of_type()` row.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the line does not bind, naming the node.
	 */
	public static function line( string $class, array $node ): array {
		try {
			return Command_Args::bind( Core::arr( $class::node_schema()['arguments'] ?? [] ), \array_map( Core::as_string( ... ), \array_values( Core::arr( $node['args'] ) ) ) );
		} catch ( \InvalidArgumentException $e ) {
			throw new \InvalidArgumentException( \esc_html( Core::as_string( $node['type'] ) . ' ' . Core::as_string( $node['name'] ) . ": {$e->getMessage()}" ), 0, $e );
		}
	}

	/**
	 * Every topology an include tree names, at any depth.
	 *
	 * @param array<array-key,mixed> $tree As `Topology_Analyzer::statements()` answers it.
	 * @return list<string>
	 */
	private static function included( array $tree ): array {
		$names = [];
		foreach ( $tree as $name => $subtree ) {
			$names = [ ...$names, (string) $name, ...self::included( Core::arr( $subtree ) ) ];
		}
		return $names;
	}

	/**
	 * Those of `$names` whose graph declares an `Intel_Event_Writer`, in order.
	 *
	 * @param list<string> $names Topology names.
	 * @return list<string>
	 * @throws \RuntimeException When a topology will not read.
	 */
	private static function declaring( array $names ): array {
		return \array_values( \array_filter( $names, static fn ( string $name ): bool => [] !== Topology_Analyzer::nodes_of_type( $name, Intel_Event_Writer_Node::class ) ) );
	}
}

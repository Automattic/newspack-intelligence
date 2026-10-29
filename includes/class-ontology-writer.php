<?php
/**
 * Ontology_Writer: appends ontology events from outside the worker — a
 * WP-CLI run or a request. Each append builds a transient Partition exactly
 * as the streams topology declares `intel:ontology`: its directory and its
 * `make_node` arguments, read from the topology rather than copied, and its
 * two config lines: `keep_everything`, without which the writer would prune
 * the kept-forever stream when it rotates, and `set_read_capability`, the
 * raw-read gate, whose marker the writer leaves in the shared directory when
 * it flushes, so the directory is gated whichever writer opens it first.
 * Each line binds against the Partition's own declaration of its verb, by
 * position or by name, as a load binds it, and is honoured through the
 * Partition's own setter, not replayed as a command, because a command's
 * role gate judges the caller and would refuse a curator below MANAGE; any
 * other `command_node intel:ontology:config` line is refused by name, so a
 * line added to the declaration is never skipped in silence. It fills,
 * flushes and removes the Partition whatever happens, so a second append in
 * one process finds the name free, and a failure propagates to the caller.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Topology_Analyzer;

\defined( 'ABSPATH' ) || exit;

final class Ontology_Writer {

	/** The topology that declares the ontology stream. */
	private const TOPOLOGY = 'newspack-intelligence-streams';

	/** The ontology stream's Partition in that topology. */
	private const PARTITION = 'intel:ontology';

	/** The name the transient Partition holds while it writes. */
	public const WRITER = 'intel:ontology:writer';

	/** The `intel:ontology:config` verbs this writer honours. */
	private const HONOURED = [ 'keep_everything', 'set_read_capability' ];

	/**
	 * Append events to the ontology stream, in order, as one flush.
	 *
	 * @param list<array<string,mixed>> $events Events `Intel_Event::make()` minted.
	 * @throws \Throwable What reading the declaration or the append threw.
	 */
	public static function append( array $events ): void {
		[ $args, $config ] = self::declaration();
		$writer            = null;
		try {
			$writer = new Partition_Node();
			$writer->name( self::WRITER );
			$writer->arguments( $args );
			foreach ( $config as $apply ) {
				$apply( $writer );
			}
			foreach ( $events as $event ) {
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_STRUCT;
				$message[ Message::VALUE ] = $event;
				$writer->fill( $message );
			}
			$writer->flush();
		} finally {
			$writer?->remove_node();
		}
	}

	/**
	 * The Partition's arguments, its directory resolved for partition 0, and
	 * each config line it declares, as the setter call that applies it.
	 *
	 * @return array{0: list<string>, 1: list<\Closure(Partition_Node): void>}
	 * @throws \RuntimeException When the topology declares no such Partition, or a config line this writer does not honour.
	 */
	private static function declaration(): array {
		$args = null;
		foreach ( Topology_Analyzer::graph_for( self::TOPOLOGY )['nodes'] as $node ) {
			if ( self::PARTITION === $node['name'] ) {
				$args = \array_map( Core::as_string( ... ), \array_values( Core::arr( $node['args'] ) ) );
			}
		}
		$config = [];
		foreach ( Topology_Analyzer::statements( self::TOPOLOGY )['statements'] as $statement ) {
			$values = $statement['values'];
			if ( 'command_node' === $statement['verb'] && self::PARTITION . ':config' === ( $values[1] ?? null ) ) {
				$config[] = self::setter( \array_slice( $values, 2 ) );
			}
		}
		if ( null === $args ) {
			throw new \RuntimeException( \esc_html( self::TOPOLOGY . ' declares no ' . self::PARTITION . ' Partition' ) );
		}
		$rest = \array_map( static fn ( string $token ): string => Core::resolve_config_tokens( $token ), \array_slice( $args, 1 ) );
		return [ [ self::stream_dir(), ...$rest ], $config ];
	}

	/**
	 * The ontology stream's directory these appends write, the streams
	 * topology's declaration resolved for partition 0.
	 *
	 * @throws \RuntimeException When the topology declares no such Partition.
	 */
	public static function stream_dir(): string {
		return Topology_Analyzer::resolved_node_dirs( self::TOPOLOGY, self::PARTITION, 1 )[0]
			?? throw new \RuntimeException( \esc_html( self::TOPOLOGY . ' declares no ' . self::PARTITION . ' Partition' ) );
	}

	/**
	 * The setter call a config line makes: `keep_everything` turned on, or
	 * `set_read_capability` naming one capability, the two lines this writer
	 * honours, each bound against the Partition's own declaration of the
	 * verb, by position or by name, as a topology load binds it.
	 *
	 * @param list<string> $line The verb and its tokens.
	 * @return \Closure(Partition_Node): void
	 * @throws \RuntimeException Naming any other line, `keep_everything` turned off, or a line its declaration refuses.
	 */
	private static function setter( array $line ): \Closure {
		$verb    = $line[0] ?? '';
		$written = self::PARTITION . ':config ' . \implode( ' ', $line );
		if ( ! \in_array( $verb, self::HONOURED, true ) ) {
			throw new \RuntimeException( \esc_html( 'the ontology writer honours only ' . \implode( ' and ', self::HONOURED ) . "; refusing {$written}" ) );
		}
		try {
			$args = Command_Args::bind( Core::arr( Command_Interpreter_Node::declared_verbs( Partition_Node::class )[ $verb ]['args'] ?? [] ), \array_slice( $line, 1 ) );
		} catch ( \InvalidArgumentException $e ) {
			throw new \RuntimeException( \esc_html( "the ontology writer refuses {$written}: {$e->getMessage()}" ), 0, $e );
		}
		if ( 'set_read_capability' === $verb ) {
			$capability = Core::as_string( $args['capability'] );
			return static fn ( Partition_Node $writer ) => $writer->set_read_capability( $capability );
		}
		if ( false === $args['enabled'] ) {
			throw new \RuntimeException( \esc_html( "the ontology writer honours keep_everything only turned on; refusing {$written}" ) );
		}
		return static fn ( Partition_Node $writer ) => $writer->set_keep_everything( true );
	}
}

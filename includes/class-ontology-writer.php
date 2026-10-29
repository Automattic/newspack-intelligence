<?php
/**
 * Ontology_Writer: appends ontology events from outside the worker — a
 * WP-CLI run or a request. Each append builds a transient Partition exactly
 * as the streams topology declares `intel:ontology`: its directory and its
 * `make_node` arguments, read from the topology rather than copied, and its
 * declared `keep_everything`, without which the writer would prune the
 * kept-forever stream when it rotates. That line is honoured through the
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

	/**
	 * Append events to the ontology stream, in order, as one flush.
	 *
	 * @param list<array<string,mixed>> $events Events `Intel_Event::make()` minted.
	 * @throws \Throwable What reading the declaration or the append threw.
	 */
	public static function append( array $events ): void {
		[ $args, $keep ] = self::declaration();
		$writer          = null;
		try {
			$writer = new Partition_Node();
			$writer->name( self::WRITER );
			$writer->arguments( $args );
			$writer->set_keep_everything( $keep );
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
	 * whether it keeps everything.
	 *
	 * @return array{0: list<string>, 1: bool}
	 * @throws \RuntimeException When the topology declares no such Partition, or a config line this writer does not honour.
	 */
	private static function declaration(): array {
		$args = null;
		foreach ( Topology_Analyzer::graph_for( self::TOPOLOGY )['nodes'] as $node ) {
			if ( self::PARTITION === $node['name'] ) {
				$args = \array_map( Core::as_string( ... ), \array_values( Core::arr( $node['args'] ) ) );
			}
		}
		$keep = false;
		foreach ( Topology_Analyzer::statements( self::TOPOLOGY )['statements'] as $statement ) {
			$values = $statement['values'];
			if ( 'command_node' === $statement['verb'] && self::PARTITION . ':config' === ( $values[1] ?? null ) ) {
				$keep = self::keeps_everything( \array_slice( $values, 2 ) );
			}
		}
		if ( null === $args ) {
			throw new \RuntimeException( \esc_html( self::TOPOLOGY . ' declares no ' . self::PARTITION . ' Partition' ) );
		}
		$rest = \array_map( static fn ( string $token ): string => Core::resolve_config_tokens( $token ), \array_slice( $args, 1 ) );
		return [ [ self::stream_dir(), ...$rest ], $keep ];
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
	 * Whether a config line turns `keep_everything` on, the one line this
	 * writer honours.
	 *
	 * @param list<string> $line The verb and its tokens.
	 * @throws \RuntimeException Naming any other line.
	 */
	private static function keeps_everything( array $line ): bool {
		if ( 'keep_everything' === ( $line[0] ?? null ) && \count( $line ) <= 2 && true === Command_Args::typed( $line[1] ?? 'true', 'bool' ) ) {
			return true;
		}
		throw new \RuntimeException( \esc_html( 'the ontology writer honours only keep_everything; refusing ' . self::PARTITION . ':config ' . \implode( ' ', $line ) ) );
	}
}

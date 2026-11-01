<?php
/**
 * Intelligence_Health: the `health` read verb's four slices, each answering
 * one row set the health strip lays out. What durable state can answer —
 * the worker's lock, the active topologies, the Vault and the projection —
 * is derived in the request; the rest is the snapshot a worker published
 * through `Publishes_Status`, which reads as unknown, never as healthy, when
 * no cache holds it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Topology_Analyzer;

\defined( 'ABSPATH' ) || exit;

final class Intelligence_Health {

	/** The slices `what` names, in the order the strip reads them. */
	private const SLICES = [ 'collection', 'sources', 'backlog', 'extraction' ];

	/** The worker partition every snapshot is read at: the topology runs one. */
	private const PARTITION = 0;

	/** The fields of a source's or the extractor's row only a manager reads. */
	private const OPERATOR_FIELDS = [ 'credential', 'vault_id', 'last_error' ];

	/**
	 * The slice `what` names.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When `what` names no slice.
	 */
	public static function answer( array $args ): array {
		return match ( Core::as_string( $args['what'] ) ) {
			'collection' => self::collection(),
			'sources'    => self::sources(),
			'backlog'    => self::backlog(),
			'extraction' => self::extraction(),
			default      => throw new \InvalidArgumentException( \esc_html( 'what must be one of: ' . \implode( ', ', self::SLICES ) ) ),
		};
	}

	/**
	 * How the extractor extracts: its snapshot, redacted below manage; else
	 * unknown, beside `last_mode`, how the newest extraction's actor says it
	 * was made, when the projection holds one. An actor cannot say why a
	 * stand-in was written, so no fallback claims a missing Vault entry.
	 *
	 * @return array<array-key,mixed>
	 */
	private static function extraction(): array {
		$status = self::status_of( Extractor_Node::class );
		if ( null !== $status ) {
			return self::redacted( $status );
		}
		$actor = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): mixed => $r->rows( 'SELECT actor FROM ' . Intel_Graph_Row_Schema::all( $r, 'extractions' ) . ' e ORDER BY id DESC LIMIT 1', [] )[0][0] ?? null, 'no intel-graph to read the newest extraction from: ' );
		if ( null === $actor ) {
			return [ 'mode' => 'unknown' ];
		}
		$heuristic = \str_starts_with( Core::as_string( $actor ), Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ) );
		return [
			'mode'      => 'unknown',
			'last_mode' => $heuristic ? 'heuristic' : 'call',
		];
	}

	/**
	 * Why items wait to resolve, as the resolver's hold decides it, beside
	 * the count and start its snapshot holds when that snapshot names the
	 * same reason, else null, and the dropped days its snapshot holds, null
	 * with none or once a re-resolution has covered them.
	 *
	 * @return array{reason:string,held:mixed,since:mixed,dropped:mixed}
	 */
	private static function backlog(): array {
		$reason = Entity_Index::current()->hold_cause();
		$hold   = self::status_of( Resolver_Node::class ) ?? [];
		$same   = $reason === ( $hold['reason'] ?? null );
		return [
			'reason'  => $reason,
			'held'    => $same ? $hold['held'] ?? null : null,
			'since'   => $same ? $hold['since'] ?? null : null,
			'dropped' => self::uncovered( $hold['dropped'] ?? null ),
		];
	}

	/**
	 * The dropped days, or null once the reresolver's last range covers them.
	 *
	 * @param mixed $dropped The resolver's `{ first_day, last_day }`, or null.
	 */
	private static function uncovered( mixed $dropped ): mixed {
		if ( ! \is_array( $dropped ) ) {
			return $dropped;
		}
		$range  = self::status_of( Reresolver_Node::class )['last_range'] ?? null;
		$covers = \is_array( $range )
			&& Core::as_string( $range['since'] ) <= Core::as_string( $dropped['first_day'] )
			&& Core::as_string( $range['until'] ) >= Core::as_string( $dropped['last_day'] );
		return $covers ? null : $dropped;
	}

	/**
	 * The snapshot the active topology's node of `$class` published, null
	 * when the topology declares none or no cache holds one.
	 *
	 * @param class-string<Extractor_Node|Resolver_Node|Reresolver_Node> $class The node's class.
	 * @return array<array-key,mixed>|null
	 */
	private static function status_of( string $class ): ?array {
		$node = Topology_Analyzer::nodes_of_type( Worker_Route::TOPOLOGY, $class )[0] ?? null;
		return null === $node ? null : $class::read_status( Core::as_string( $node['name'] ), self::PARTITION );
	}

	/**
	 * Every source the collect topology declares, in its order, but those
	 * of a tier the caller cannot read: idle with the credential it needs
	 * when its group lends none on the origin it fetches, whatever its
	 * snapshot says; else its snapshot; else unknown; each redacted for a
	 * caller below manage.
	 *
	 * @return array{sources:list<array<array-key,mixed>>}
	 */
	private static function sources(): array {
		$readable = Intel_Tiers::readable();
		$sources  = [];
		foreach ( Intelligence_CI_Node::sources() as $node ) {
			$type = Core::as_string( $node['type'] );
			/** @var class-string<Source_Node> $class nodes_of_type() kept only Source_Node types. */
			$class = Command_Interpreter_Node::resolve_class( $type );
			$line = Command_Args::bind( Core::arr( $class::node_schema()['arguments'] ?? [] ), \array_map( Core::as_string( ... ), \array_values( Core::arr( $node['args'] ) ) ) );
			$tier = Core::as_string( $line['tier'] );
			if ( ! \in_array( $tier, $readable, true ) ) {
				continue;
			}
			$name      = Core::as_string( $node['name'] );
			$sources[] = [ 'name' => $name, 'class' => $type, 'tier' => $tier ]
				+ self::redacted( self::idle( $class, Core::as_string( $line['vault_group'] ?? '' ) ) ?? Source_Node::read_status( $name, self::PARTITION ) ?? [ 'state' => 'unknown' ] );
		}
		return [ 'sources' => $sources ];
	}

	/**
	 * A credentialed source's idle row when its group lends nothing on the
	 * url it fetches; null when it needs no credential or one is lent.
	 *
	 * @param class-string<Source_Node> $class The source's class.
	 * @param string                    $group Its vault group, '' for none.
	 * @return array{state:string,credential:array{url:string,group:string}}|null
	 */
	private static function idle( string $class, string $group ): ?array {
		$url = $class::credential_url();
		if ( '' === $url || Curl_Node::lends_credential( $group, $url ) ) {
			return null;
		}
		return [
			'state'      => 'idle',
			'credential' => [
				'url'   => $url,
				'group' => $group,
			],
		];
	}

	/**
	 * A row as the caller may read it: whole for a manager, else without
	 * the credential, Vault id and error text, leaving the state, times,
	 * counts and an idle source's reason, which is fixed text naming at most
	 * an HTTP status. Field redaction; the verb's role stays read (ADR-26).
	 *
	 * @param array<array-key,mixed> $row A source's or the extractor's row.
	 * @return array<array-key,mixed>
	 */
	private static function redacted( array $row ): array {
		return Capabilities::can( Capabilities::MANAGE ) ? $row : \array_diff_key( $row, \array_flip( self::OPERATOR_FIELDS ) );
	}

	/**
	 * The worker's state, the word `wp nodes status` reports for it:
	 * `inactive` while the topology is outside the active set.
	 *
	 * @return array{state:string,topology:string}
	 */
	private static function collection(): array {
		$id = CLI::worker_id( Worker_Route::TOPOLOGY, self::PARTITION );
		return [
			'state'    => ( new CLI( Config::get_base_directory() ) )->worker_states( [ $id ], Bootstrap::get_topologies(), false )[ $id ]['state'],
			'topology' => Worker_Route::TOPOLOGY,
		];
	}
}

<?php
/**
 * Projector_Node: replays the three event streams into the `intel-graph`
 * Row_Table (`Intel_Graph_Row_Schema`). Three Consumers, one per stream,
 * fill it with one event each; `projections()` declares, for each kind, the
 * row ops it writes and where each column comes from. Every op is an
 * `ignore` or a whole-row `set` on a keyed table but for the `delete`s:
 * the items stream's own — an extraction deletes the item's claims and
 * topic links, a `subjects_extracted` the item's extracted subjects, a
 * `link_retracted` the one link row it keys, and an `item_purged` the rows
 * that stream has written about its item by then — and the ontology
 * stream's `lens_removed` and `watch_removed`, the one lens or watch row
 * each keys.
 * Each table is written by one stream alone, so a replayed event writes what
 * it wrote before and the streams project the same rows in any order. A row naming an entity no `entities` row
 * holds yet is left to the readers' join. A machine's proposal writes with
 * `ignore` and a person's creation with `set`, so a machine never overwrites
 * a person. Every ontology event also lands in `events`, ignored by its id.
 *
 * Ops buffer and leave as one `ROWS` batch, one transaction, every
 * FLUSH_INTERVAL_S on the Router tick, at MAX_BUFFERED_OPS or
 * MAX_BUFFERED_BYTES, at every checkpoint and on a clean stop. A batch that
 * fails raises a plain stop and keeps the buffer, so the Consumer commits no
 * cursor past what it held.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * Projects intelligence events into the intel-graph rows.
 *
 * @phpstan-import-type Row_Op from \Newspack_Nodes\Sqlite_Arm
 * @phpstan-import-type Row_Delete from \Newspack_Nodes\Sqlite_Arm
 */
class Projector_Node extends Timer_Node implements Shutdown_Sweeper {
	use \Newspack_Nodes\Schema_Reflection;

	/** Seconds between periodic flushes, on the Router tick. */
	public const FLUSH_INTERVAL_S = 5;

	/** Ops the buffer holds before the event reaching the count writes it. */
	public const MAX_BUFFERED_OPS = 2000;

	/** String bytes the buffered ops carry before the event crossing it writes them: one transaction's stall. */
	public const MAX_BUFFERED_BYTES = 1048576;

	/** The column spec naming the element a fan-out op is written for. */
	private const EACH = '@each';

	/** The specs a heuristic stand-in's `extraction_recorded` is projected by. */
	private const STAND_IN = 'extraction_recorded/stand-in';

	/**
	 * Each kind's op specs, built once: `[ verb, table, columns, fan-out field? ]`.
	 *
	 * @var array<string,list<array{0:string,1:string,2:list<mixed>,3?:string}>>|null
	 */
	private static ?array $projections = null;

	/** @var array<string,array<int|string,string>> Each table's column types, by position and by name. */
	private static array $types = [];

	/** Node NAME of the Row_Table the rows are written to ('' = unnamed). */
	private string $graph_target = '';

	/** The projector's asker for its Row_Table. */
	private Table_Client $client;

	/**
	 * The ROWS batch the next write sends, in the order it was buffered.
	 *
	 * @var list<Row_Op|Row_Delete>
	 */
	private array $rows = [];

	/** String bytes the buffered ops carry. */
	private int $bytes = 0;

	/**
	 * Build the Table asker and the `:config` interpreter.
	 *
	 * @api Used by substrate.
	 */
	public function __construct() {
		$this->client = new Table_Client( $this );
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Store the tokens and arm the periodic flush on the Router tick.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 */
	public function arguments( ?array $args = null ): array {
		if ( null !== $args ) {
			$this->set_timer( self::FLUSH_INTERVAL_S * 1000 );
		}
		return parent::arguments( $args );
	}

	/**
	 * Take the Row_Table's reply, or buffer one event's row ops. The event
	 * that brings the buffer to MAX_BUFFERED_OPS ops or MAX_BUFFERED_BYTES
	 * string bytes writes it. A message that carries no valid event throws,
	 * so its Consumer dead-letters it and reads on (ADR-12).
	 *
	 * @param array<int,mixed> $message Positional Message array.
	 * @throws \InvalidArgumentException When the message carries no valid event.
	 * @throws Worker_Should_Stop When the write fails.
	 */
	public function fill( array $message ): void {
		if ( $this->client->accepts( $message ) ) {
			return;
		}
		++$this->counter;
		$event = Intel_Event::from_message( $message );
		foreach ( self::projections()[ self::projected_as( $event ) ] as $spec ) {
			foreach ( self::ops( $event, $spec ) as $op ) {
				$this->rows[] = $op;
				foreach ( $op[2] as $value ) {
					$this->bytes += \is_string( $value ) ? \strlen( $value ) : 0;
				}
			}
		}
		if ( \count( $this->rows ) >= self::MAX_BUFFERED_OPS || $this->bytes >= self::MAX_BUFFERED_BYTES ) {
			$this->flush();
		}
	}

	/**
	 * Router-TIMER tick: write what the buffer holds.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->flush();
	}

	/**
	 * The projection an event is written by: its kind's, or `STAND_IN` for an
	 * extraction the heuristic stage recorded.
	 *
	 * @param array<array-key,mixed> $event A validated event.
	 */
	private static function projected_as( array $event ): string {
		$kind = Core::as_string( $event['event'] );
		return 'extraction_recorded' === $kind && \str_starts_with( Core::as_string( $event['actor'] ), Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ) ) ? self::STAND_IN : $kind;
	}

	/**
	 * Each kind's op specs. A column spec is an event field's name, a
	 * one-element list holding a literal, EACH for the element a fan-out op
	 * is written for, or a closure computing the value from the event; a
	 * `delete` names its columns, each mapped to such a spec. An extraction
	 * deletes the item's claims and topic links before it writes its own, so
	 * one yielding fewer leaves none of the earlier behind; both tables are
	 * written by the items stream alone, so a replay deletes and writes in
	 * the same order; the entities an extraction was given replace the
	 * item's earlier set the same way. A retraction deletes the one link row
	 * its item, entity and role key. A purge deletes the item's row, its
	 * extraction, extracted subjects, links, claims and cluster membership,
	 * and the canonical row of any cluster it heads, which no event elects
	 * again; rows another stream wrote about it stay, and every read joins
	 * the items, so they answer nothing, as do rows a later resolution of
	 * the item writes. A heuristic stand-in only fills an item
	 * no extraction holds yet (`STAND_IN`), so it never replaces a call's
	 * extraction, its claims or its topics. Every ontology kind also writes
	 * its `events` row.
	 *
	 * @return array<string,list<array{0:string,1:string,2:array<mixed>,3?:string}>>
	 */
	private static function projections(): array {
		if ( null !== self::$projections ) {
			return self::$projections;
		}
		$day    = static fn ( string $field ): \Closure => static fn ( array $e ): int => (int) \floor( Core::as_float( $e[ $field ] ) / Intel_Graph_Row_Schema::DAY_SECONDS );
		$link   = static fn ( string $state ): array => [ [ 'set', 'links', [ 'item_key', 'entity_key', 'role', 'method', 'confidence', [ $state ], 'actor', 'ts', $day( 'retrieved_ts' ) ] ] ];
		$entity = static fn ( string $verb, string $state ): array => [
			[ $verb, 'entities', [ 'entity_key', 'type', 'name', 'ts' ] ],
			[ $verb, 'entity_states', [ 'entity_key', [ $state ], 'actor', 'ts' ] ],
		];
		$state_set = static fn ( string $state ): array => [ [ 'set', 'entity_states', [ 'entity_key', [ $state ], 'actor', 'ts' ] ] ];
		$extraction = [ [ null ], 'item_key', 'summary', 'kind', 'relevance', 'importance', 'sentiment', 'actor', $day( 'retrieved_ts' ) ];
		$judgment = static fn ( string $state ): array => [ [ 'set', 'judgments', [ 'item_key', 'entity_key', static fn ( array $e ): string => \explode( ':', Core::as_string( $e['actor'] ), 2 )[0], [ $state ], 'actor', 'item_url', 'ts' ] ] ];
		$table    = [
			'item_recorded'       => [ [ 'ignore', 'items', [ [ null ], 'item_key', 'source', 'tier', 'url', static fn ( array $e ): string => Url_Canon::of( Core::as_string( $e['url'] ) ), 'title', static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e['title'] ) ), 'body', 'item_ts', 'retrieved_ts', $day( 'retrieved_ts' ) ] ] ],
			'extraction_recorded' => [
				[ 'delete', 'claims', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'links', [ 'item_key' => 'item_key', 'role' => [ 'topic' ] ] ],
				[ 'set', 'extractions', $extraction ],
				[ 'set', 'links', [ 'item_key', self::EACH, [ 'topic' ], [ 'extract' ], [ 1.0 ], [ 'asserted' ], 'actor', 'ts', $day( 'retrieved_ts' ) ], 'topics' ],
			],
			'subjects_extracted'  => [
				[ 'delete', 'extracted_subjects', [ 'item_key' => 'item_key' ] ],
				[ 'set', 'extracted_subjects', [ 'item_key', self::EACH, $day( 'retrieved_ts' ) ], 'entity_keys' ],
			],
			'link_asserted'       => $link( 'asserted' ),
			'link_proposed'       => $link( 'proposed' ),
			'link_retracted'      => [ [ 'delete', 'links', [ 'item_key' => 'item_key', 'entity_key' => 'entity_key', 'role' => 'role' ] ] ],
			'claim_asserted'      => [ [ 'set', 'claims', [ 'item_key', 'ord', 'subject_key', 'predicate', 'object_key', 'object_text', 'confidence', $day( 'retrieved_ts' ) ] ] ],
			'cluster_joined'      => [ [ 'set', 'clusters', [ 'item_key', 'cluster_key', $day( 'retrieved_ts' ) ] ] ],
			'cluster_superseded'  => [ [ 'set', 'canonicals', [ 'cluster_key', 'canonical_item_key', 'rank', $day( 'retrieved_ts' ) ] ] ],
			'item_purged'         => [
				[ 'delete', 'items', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'extractions', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'extracted_subjects', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'links', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'claims', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'clusters', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'canonicals', [ 'canonical_item_key' => 'item_key' ] ],
			],
			'entity_created'      => $entity( 'set', 'accepted' ),
			'entity_proposed'     => $entity( 'ignore', 'proposed' ),
			'entity_accepted'     => $state_set( 'accepted' ),
			'entity_rejected'     => $state_set( 'rejected' ),
			'entity_merged'       => [ [ 'set', 'entity_merges', [ 'entity_key', 'into_key', 'actor', 'ts' ] ] ],
			'name_added'          => [ [ 'set', 'names', [ [ null ], 'entity_key', 'kind', 'value', static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e['value'] ) ) ] ] ],
			'identifier_added'    => [ [ 'set', 'identifiers', [ 'entity_key', 'kind', 'value' ] ] ],
			'role_asserted'       => [ [ 'set', 'roles', [ 'entity_key', 'role', 'from_ts', [ 0 ], 'actor' ] ] ],
			'role_ended'          => [ [ 'set', 'roles', [ 'entity_key', 'role', 'from_ts', 'to_ts', 'actor' ] ] ],
			'topic_tagged'        => [ [ 'set', 'entity_topics', [ 'entity_key', 'topic_key', 'actor' ] ] ],
			'link_confirmed'      => $judgment( 'confirmed' ),
			'link_rejected'       => $judgment( 'rejected' ),
			'collection_created'  => [ [ 'set', 'collections', [ 'collection_key', 'name', 'actor', 'ts' ] ] ],
			'collected'           => [ [ 'set', 'collected', [ 'collection_key', 'item_key', 'url', 'title', 'tier', 'actor', 'ts' ] ] ],
			'lens_saved'          => [ [ 'set', 'lenses', [ 'lens_key', 'name', 'description', static fn ( array $e ): string => self::json( $e['filters'] ), 'actor', 'ts' ] ] ],
			'lens_removed'        => [ [ 'delete', 'lenses', [ 'lens_key' => 'lens_key' ] ] ],
			'watch_saved'         => [ [ 'set', 'watches', [ 'watch_key', 'owner', 'name', 'target_kind', 'target_key', 'digest', 'instant', 'inbox', 'actor', 'ts' ] ] ],
			'watch_removed'       => [ [ 'delete', 'watches', [ 'watch_key' => 'watch_key' ] ] ],
			'watch_matched'       => [ [ 'set', 'watch_matches', [ 'watch_key', 'owner', 'item_key', 'at', 'ts', 'quiet', $day( 'retrieved_ts' ) ] ] ],
			'watch_seen'          => [ [ 'set', 'watch_seen', [ 'watch_key', 'owner', 'item_key', 'ts', $day( 'retrieved_ts' ) ] ] ],
			'watch_delivered'     => [ [ 'set', 'watch_deliveries', [ 'owner', 'mode', 'watch_key', 'through', 'ts' ] ] ],
		];
		$event_row = [
			'ignore',
			'events',
			[
				'event_id',
				'event',
				'actor',
				static fn ( array $e ): string => Core::as_string( $e['entity_key'] ?? $e['collection_key'] ?? $e['lens_key'] ?? $e['watch_key'] ?? null ),
				static fn ( array $e ): string => self::json( \array_diff_key( $e, \array_flip( Intel_Event::ENVELOPE ) ) ),
				'config_version',
				'ts',
			],
		];
		foreach ( $table as $kind => $specs ) {
			if ( Intel_Event::ONTOLOGY === Intel_Event::stream_of( $kind ) ) {
				$table[ $kind ][] = $event_row;
			}
		}
		$table[ self::STAND_IN ] = [ [ 'ignore', 'extractions', $extraction ] ];
		return self::$projections = $table;
	}

	/**
	 * A value as a TEXT column stores it: JSON, slashes and Unicode unescaped.
	 *
	 * @param mixed $value The value.
	 * @throws \JsonException When it will not encode.
	 */
	private static function json( mixed $value ): string {
		return (string) \wp_json_encode( $value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR );
	}

	/**
	 * The row ops one spec writes for an event: a delete, whose columns are
	 * named; or one positional op, or one per element of its fan-out field.
	 * Each value is typed by its column's declared type.
	 *
	 * @param array<array-key,mixed>                          $e    A validated event.
	 * @param array{0:string,1:string,2:array<mixed>,3?:string} $spec The op spec.
	 * @return list<Row_Op|Row_Delete>
	 */
	private static function ops( array $e, array $spec ): array {
		[ $verb, $table, $columns ] = $spec;
		$types                      = self::$types[ $table ] ??= self::types_of( $table );
		if ( 'delete' === $verb ) {
			$where = [];
			foreach ( $columns as $name => $column ) {
				$name           = Core::as_string( $name );
				$where[ $name ] = self::typed( self::value( $e, $column, null ), $types[ $name ] );
			}
			return [ [ 'delete', $table, $where ] ];
		}
		$ops = [];
		foreach ( isset( $spec[3] ) ? Core::arr( $e[ $spec[3] ] ) : [ null ] as $element ) {
			$row = [];
			foreach ( \array_values( $columns ) as $at => $column ) {
				$row[] = self::typed( self::value( $e, $column, $element ), $types[ $at ] );
			}
			$ops[] = [ $verb, $table, $row ];
		}
		return $ops;
	}

	/**
	 * One column spec's value for an event: a closure's answer, a literal,
	 * the fan-out element, or the event field it names.
	 *
	 * @param array<array-key,mixed> $e       A validated event.
	 * @param mixed                  $column  The column spec.
	 * @param mixed                  $element The fan-out element, or null.
	 */
	private static function value( array $e, mixed $column, mixed $element ): mixed {
		return match ( true ) {
			$column instanceof \Closure => $column( $e ),
			\is_array( $column )        => $column[0],
			self::EACH === $column      => $element,
			default                     => $e[ Core::as_string( $column ) ],
		};
	}

	/**
	 * A table's column types, by position and by name, so a positional row
	 * and a delete's named columns read one map.
	 *
	 * @param string $table The table.
	 * @return array<int|string,string>
	 */
	private static function types_of( string $table ): array {
		$declared = Intel_Graph_Row_Schema::tables()[ $table ]['columns'];
		return \array_values( $declared ) + $declared;
	}

	/**
	 * A value as its column's declared type stores it; null stays null.
	 *
	 * @param mixed  $value The value.
	 * @param string $type  The column's declared type.
	 * @return int|float|string|null
	 */
	private static function typed( mixed $value, string $type ): int|float|string|null {
		return match ( true ) {
			null === $value                     => null,
			\str_starts_with( $type, 'INTEGER' ) => Core::as_int( $value ),
			\str_starts_with( $type, 'REAL' )    => Core::as_float( $value ),
			default                             => Core::as_string( $value ),
		};
	}

	/**
	 * Write the buffer before the Consumer commits its cursor; the checkpoint
	 * carries nothing, since a replayed event writes the same rows again.
	 *
	 * @api Used by substrate.
	 * @return array{}
	 * @throws Worker_Should_Stop When the write fails.
	 */
	public function save_state(): array {
		$this->flush();
		return [];
	}

	/**
	 * Write the buffer on a clean stop, while the graph is intact.
	 *
	 * @api Used by substrate.
	 * @throws Worker_Should_Stop When the write fails.
	 */
	public function shutdown_sweep(): void {
		$this->flush();
	}

	/**
	 * Send the buffer as one `ROWS` batch and start clean. A refused or
	 * unanswered batch, or any other failure, raises a plain stop carrying it,
	 * and the buffer stays, so the successor replays every event it held.
	 *
	 * @throws Worker_Should_Stop Carrying whatever failed the write (ADR-14).
	 */
	public function flush(): void {
		if ( [] === $this->rows ) {
			return;
		}
		try {
			$this->client->write_rows( $this->graph_table(), $this->rows );
		} catch ( \Throwable $e ) {
			throw Worker_Should_Stop::combine( [ new Worker_Should_Stop( 'intel graph write failed' ), $e ] );
		}
		$this->rows  = [];
		$this->bytes = 0;
	}

	/**
	 * The Row_Table `set_graph_target` named, which every write needs.
	 *
	 * @throws \LogicException When the verb named none.
	 */
	private function graph_table(): string {
		return '' === $this->graph_target ? throw new \LogicException( 'Projector: no Row_Table named by set_graph_target' ) : $this->graph_target;
	}

	/**
	 * Whether a projector's reader of a stream has a committed cursor short
	 * of its end. The projector flushes at every checkpoint, so a cursor at
	 * the end means every event the stream holds is projected.
	 *
	 * @api Read by the resolver's hold and the watcher's pass.
	 * @param string $stream_dir The stream's directory.
	 */
	public static function behind( string $stream_dir ): bool {
		foreach ( self::readers() as $reader ) {
			if ( $stream_dir === $reader['source'] && ! Consumer_Node::lag_from_disk( $reader['source'], $reader['offsets'] )['caught_up'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every Consumer whose config adds a projector as a snapshot node, for
	 * each partition of each active topology that declares them: the source
	 * it reads and its cursor directory, as the TSL spells them, resolved.
	 *
	 * @api Read by the resolver's hold and `ontology reproject`.
	 * @return list<array{source:string,offsets:string}>
	 */
	public static function readers(): array {
		$readers = [];
		foreach ( Bootstrap::active_topologies()[0] as $topology => $entry ) {
			$projectors = [];
			$consumers  = [];
			$snapshots  = [];
			foreach ( Topology_Analyzer::statements( $topology )['statements'] as $statement ) {
				$values = $statement['values'];
				if ( 'make_node' === $statement['verb'] && 'Projector' === ( $values[1] ?? null ) ) {
					$projectors[ $values[2] ?? '' ] = true;
				} elseif ( 'make_node' === $statement['verb'] && 'Consumer' === ( $values[1] ?? null ) && isset( $values[4] ) ) {
					$consumers[ $values[2] ] = [ $values[3], $values[4] ];
				} elseif ( 'command_node' === $statement['verb'] && 'add_snapshot_node' === ( $values[2] ?? null ) ) {
					$snapshots[] = [ \substr( $values[1], 0, -\strlen( ':config' ) ), $values[3] ?? '' ];
				}
			}
			foreach ( $snapshots as [ $consumer, $snapshot ] ) {
				if ( isset( $projectors[ $snapshot ], $consumers[ $consumer ] ) ) {
					for ( $p = 0; $p < Bootstrap::partitions_of( $entry ); $p++ ) {
						$readers[] = [
							'source'  => Core::resolve_partition_template( $consumers[ $consumer ][0], $p, $topology ),
							'offsets' => Core::resolve_partition_template( $consumers[ $consumer ][1], $p, $topology ),
						];
					}
				}
			}
		}
		return \array_values( \array_unique( $readers, \SORT_REGULAR ) );
	}

	/**
	 * The `set_graph_target` verb's entry point, which the declared setter
	 * calls by name.
	 *
	 * @param string $table The Row_Table the rows are written to.
	 */
	public function set_graph_target( string $table ): void {
		$this->graph_target = $table;
	}

	/**
	 * The Row_Table the verb named, which the flush writes past any target.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->graph_target ];
	}

	/**
	 * The base config plus the `set_graph_target` line.
	 *
	 * @api Used by substrate.
	 */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
	}

	/**
	 * Declarative schema: the `:config` verb and the palette metadata.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Replays the intel:items, intel:ontology and intel:watches events into an Intel_Graph Row_Table.',
			'arguments'   => [],
			'commands'    => [
				[
					'name'        => 'set_graph_target',
					'description' => 'Name the Row_Table, declared with the Intel_Graph schema, every flush writes its rows to: intel-graph.',
					'args'        => [
						[ 'name' => 'target', 'type' => 'node_name', 'required' => true ],
					],
					'setter'      => 'graph_target',
				],
			],
		];
	}
}

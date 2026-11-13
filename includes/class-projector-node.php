<?php
/**
 * Projector_Node: replays the four event streams into the `intel-graph`
 * Row_Table (`Intel_Graph_Row_Schema`). Four Consumers, one per stream, fill
 * it with one event each; `projections()` declares, for each kind, the row ops
 * it writes and where each column comes from. Every op is an `ignore` or a
 * whole-row `set` on a keyed table but for the `delete`s: the items stream's
 * own — an extraction deletes the item's claims, topic links and theme, a
 * `subjects_extracted` the item's extracted subjects, a `link_retracted` the
 * one link row it keys, and an `item_purged` the rows that stream has written
 * about its item by then — and the ontology stream's `lens_removed` and
 * `watch_removed`, the one lens or watch row each keys. Each table is written
 * by one stream alone, so a replayed event writes what it wrote before and the
 * streams project the same rows in any order. A row naming an entity that has
 * no `entities` row yet is left to the readers' join. A machine's proposal
 * writes with `ignore` and a person's creation with `set`, so a machine never
 * overwrites a person. Every ontology event also lands in `events`, ignored by
 * its id.
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
use Newspack_Nodes\Config;
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
	 * Each kind's op specs, built once: `[ verb, table, columns, fan-out? ]`.
	 *
	 * @var array<string,list<array{0:string,1:string,2:array<mixed>,3?:string|\Closure}>>|null
	 */
	private static ?array $projections = null;

	/**
	 * The `events` row's op spec, built once.
	 *
	 * @var array{0:string,1:string,2:list<mixed>}|null
	 */
	private static ?array $event_row = null;

	/** @var array<string,array<int|string,string>> Each table's column types, by position and by name. */
	private static array $types = [];

	/** @var array{0:float|null,1:string,2:list<array{0:array{source:string,offsets:string},1:array<string,mixed>}>} The last reading(): its tick, its runtime base and each reader with its lag. */
	private static array $reading = [ null, '', [] ];

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
		foreach ( self::specs_of( $event ) as $spec ) {
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
	 * The op specs an event is written by: its projection's, and for an
	 * ontology kind the `events` row every one of them also writes.
	 *
	 * @param array<array-key,mixed> $event A validated event.
	 * @return list<array{0:string,1:string,2:array<mixed>,3?:string|\Closure}>
	 */
	private static function specs_of( array $event ): array {
		$specs = self::projections()[ self::projected_as( $event ) ];
		return Intel_Event::ONTOLOGY === Intel_Event::stream_of( Core::as_string( $event['event'] ) ) ? [ ...$specs, self::event_row() ] : $specs;
	}

	/**
	 * The projection an event is written by: its kind's, or `STAND_IN` for an
	 * extraction a stand-in stage recorded (`Extractor_Node::stands_in()`).
	 *
	 * @param array<array-key,mixed> $event A validated event.
	 */
	private static function projected_as( array $event ): string {
		$kind = Core::as_string( $event['event'] );
		return 'extraction_recorded' === $kind && Extractor_Node::stands_in( Core::as_string( $event['actor'] ) ) ? self::STAND_IN : $kind;
	}

	/**
	 * Each kind's op specs. A column spec is an event field's name, a
	 * one-element list holding a literal, EACH for the element a fan-out op is
	 * written for, or a closure computing the value from the event; a fan-out
	 * is a list field's name or a closure answering the list; a `delete`
	 * names its columns, each mapped to such a spec, and fans out as a write
	 * does. A move deletes one placement per tier it names from the folder
	 * it leaves and writes one into the folder it enters with `ignore`, so a
	 * placement there already keeps its actor and time. An extraction
	 * deletes the item's claims, topic links and theme before it writes its
	 * own, so one yielding fewer leaves none of the earlier behind, writes
	 * its kind check, the kind checked, the verdict and when, on its row,
	 * null where it carries none, and writes a theme
	 * row only when it names a theme; each table is written by the
	 * items stream alone, so a replay deletes and writes in the same order; the
	 * entities an extraction was given replace the item's earlier set the same
	 * way. A retraction deletes the one link row its item, entity and role key.
	 * An entity's created name and every added name write their norm and
	 * one `name_starts` row per word their norm holds, the norm read from
	 * that word on, ignored when present, so a prefix finds a name by a
	 * range over the starts.
	 * A record or a growth writes one `boilerplate` row per distinct body
	 * line (`Entity_Index::line_hashes()`), ignored when present, so a line
	 * counts once per item.
	 * A purge deletes the item's row, its extraction, theme, extracted
	 * subjects, links, claims, lines and cluster membership, and the canonical row of any
	 * cluster it heads, which an event never elects again; rows another stream
	 * wrote about it stay, and every read joins the items, so they answer
	 * nothing, as do rows a later resolution of the item writes. A heuristic
	 * or gated stand-in only fills an item that has no extraction yet (`STAND_IN`), so
	 * it never replaces a call's extraction, its claims, topics or theme. A
	 * proposed link's evidence, which one written before proposals carried it
	 * lacks, projects as '', as an asserted link's always does, and an
	 * extraction carrying no rank reason projects one of '' and a null kind
	 * subject. An ask writes
	 * its row with `ignore`, so its answer or failure, a whole-row `set`,
	 * stands whichever lands first; its plan and its read each set an
	 * `ask_steps` row of their own, keyed by the ask and the step's stage, so
	 * neither touches the outcome and the steps read the same in any order. A
	 * link a person adds sets their confirmation, as `link_confirmed` does,
	 * and its `added_links` row, the role the resolver writes it in, which
	 * a later addition of the same link replaces. An exclusion sets its item's one
	 * `excluded_items` row, its why '' when it names none, and an inclusion
	 * deletes it. A save writes its saved copy and its placement, and a
	 * folder created with an item writes the save's own specs, each reading
	 * the item's record. A removal from a folder deletes the placement of each tier
	 * it names there, as a move deletes the ones it leaves, and keeps the saved
	 * copies. A churn note deletes its client's `churn_notes` row and,
	 * unless its text is '', writes it again, so an empty note clears it.
	 * Every ontology kind also writes its `events` row, under
	 * the key it names, an item's when it names nothing else.
	 *
	 * @return array<string,list<array{0:string,1:string,2:array<mixed>,3?:string|\Closure}>>
	 */
	private static function projections(): array {
		if ( null !== self::$projections ) {
			return self::$projections;
		}
		$day    = static fn ( string $field ): \Closure => static fn ( array $e ): int => (int) \floor( Core::as_float( $e[ $field ] ) / Intel_Graph_Row_Schema::DAY_SECONDS );
		$json   = static fn ( string $field ): \Closure => static fn ( array $e ): string => self::json( $e[ $field ] );
		$link   = static fn ( string $state, mixed $evidence ): array => [ [ 'set', 'links', [ 'item_key', 'entity_key', 'role', 'method', 'confidence', [ $state ], $evidence, 'actor', 'ts', $day( 'retrieved_ts' ) ] ] ];
		$starts = static fn ( string $field ): array => [ 'ignore', 'name_starts', [ self::EACH, static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e[ $field ] ) ) ], static fn ( array $e ): array => Entity_Index::starts( Entity_Index::norm( Core::as_string( $e[ $field ] ) ) ) ];
		$entity = static fn ( string $verb, string $state ): array => [
			[ $verb, 'entities', [ 'entity_key', 'type', 'name', static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e['name'] ) ), 'ts' ] ],
			[ $verb, 'entity_states', [ 'entity_key', [ $state ], 'actor', 'ts' ] ],
			$starts( 'name' ),
		];
		$state_set = static fn ( string $state ): array => [ [ 'set', 'entity_states', [ 'entity_key', [ $state ], 'actor', 'ts' ] ] ];
		$item       = [ [ null ], 'item_key', 'source', 'tier', 'url', static fn ( array $e ): string => Url_Canon::of( Core::as_string( $e['url'] ) ), 'title', static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e['title'] ) ), 'body', 'item_ts', 'retrieved_ts', $day( 'retrieved_ts' ), static fn ( array $e ): int => Intel_Graph_Row_Schema::item_time( Core::as_int( $e['item_ts'] ), Core::as_int( $e['retrieved_ts'] ) ), 'actor' ];
		$checked    = \array_map( static fn ( string $member ): \Closure => static fn ( array $e ): mixed => Core::arr( $e['kind_check'] ?? null )[ $member ] ?? null, \array_keys( Intel_Event::RECORDS['extraction_recorded']['kind_check'] ) );
		$extraction = [ [ null ], 'item_key', 'summary', 'kind', 'relevance', 'importance', 'sentiment', ...$checked, static fn ( array $e ): ?string => isset( $e['kind_subject'] ) ? Core::as_string( $e['kind_subject'] ) : null, static fn ( array $e ): string => Core::as_string( $e['rank_reason'] ?? '' ), 'actor', 'ts', $day( 'retrieved_ts' ) ];
		$judgment = static fn ( string $state ): array => [ [ 'set', 'judgments', [ 'item_key', 'entity_key', static fn ( array $e ): string => \explode( ':', Core::as_string( $e['actor'] ), 2 )[0], [ $state ], 'actor', 'item_url', 'ts' ] ] ];
		$lines    = [ 'ignore', 'boilerplate', [ 'source', self::EACH, 'item_key', $day( 'retrieved_ts' ) ], static fn ( array $e ): array => Entity_Index::line_hashes( Core::as_string( $e['body'] ) ) ];
		$placement = [
			[ 'set', 'saved_copies', [ 'item_key', 'tier', 'url', 'title' ] ],
			[ 'set', 'collected', [ 'collection_key', 'item_key', 'tier', 'actor', 'ts' ] ],
		];
		$table    = [
			'item_recorded'       => [ [ 'ignore', 'items', $item ], $lines ],
			'item_grown'          => [ [ 'set', 'items', $item ], $lines ],
			'extraction_recorded' => [
				[ 'delete', 'claims', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'links', [ 'item_key' => 'item_key', 'role' => [ 'topic' ] ] ],
				[ 'set', 'extractions', $extraction ],
				[ 'set', 'links', [ 'item_key', self::EACH, [ 'topic' ], [ 'extract' ], [ 1.0 ], [ 'asserted' ], [ '' ], 'actor', 'ts', $day( 'retrieved_ts' ) ], 'topics' ],
				[ 'delete', 'themes', [ 'item_key' => 'item_key' ] ],
				[ 'set', 'themes', [ 'item_key', self::EACH, $day( 'retrieved_ts' ) ], static fn ( array $e ): array => isset( $e['theme'] ) ? [ $e['theme'] ] : [] ],
			],
			'subjects_extracted'  => [
				[ 'delete', 'extracted_subjects', [ 'item_key' => 'item_key' ] ],
				[ 'set', 'extracted_subjects', [ 'item_key', self::EACH, $day( 'retrieved_ts' ) ], 'entity_keys' ],
			],
			'link_asserted'       => $link( 'asserted', [ '' ] ),
			'link_proposed'       => $link( 'proposed', static fn ( array $e ): string => Core::as_string( $e['evidence'] ?? '' ) ),
			'link_retracted'      => [ [ 'delete', 'links', [ 'item_key' => 'item_key', 'entity_key' => 'entity_key', 'role' => 'role' ] ] ],
			'claim_asserted'      => [ [ 'set', 'claims', [ 'item_key', 'ord', 'subject_key', 'predicate', 'object_key', 'object_text', 'confidence', $day( 'retrieved_ts' ) ] ] ],
			'cluster_joined'      => [ [ 'set', 'clusters', [ 'item_key', 'cluster_key', 'actor', 'ts', $day( 'retrieved_ts' ) ] ] ],
			'cluster_superseded'  => [ [ 'set', 'canonicals', [ 'cluster_key', 'canonical_item_key', 'rank', $day( 'retrieved_ts' ) ] ] ],
			'item_purged'         => [
				[ 'delete', 'items', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'extractions', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'extracted_subjects', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'themes', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'links', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'claims', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'clusters', [ 'item_key' => 'item_key' ] ],
				[ 'delete', 'canonicals', [ 'canonical_item_key' => 'item_key' ] ],
				[ 'delete', 'boilerplate', [ 'item_key' => 'item_key' ] ],
			],
			'entity_created'      => $entity( 'set', 'accepted' ),
			'entity_proposed'     => $entity( 'ignore', 'proposed' ),
			'entity_accepted'     => $state_set( 'accepted' ),
			'entity_rejected'     => $state_set( 'rejected' ),
			'entity_merged'       => [ [ 'set', 'entity_merges', [ 'entity_key', 'into_key', 'actor', 'ts' ] ] ],
			'name_added'          => [ [ 'set', 'names', [ [ null ], 'entity_key', 'kind', 'value', static fn ( array $e ): string => Entity_Index::norm( Core::as_string( $e['value'] ) ) ] ], $starts( 'value' ) ],
			'identifier_added'    => [ [ 'set', 'identifiers', [ 'entity_key', 'kind', 'value' ] ] ],
			'role_asserted'       => [ [ 'set', 'roles', [ 'entity_key', 'role', 'from_ts', [ 0 ], 'actor' ] ] ],
			'role_ended'          => [ [ 'set', 'roles', [ 'entity_key', 'role', 'from_ts', 'to_ts', 'actor' ] ] ],
			'topic_tagged'        => [ [ 'set', 'entity_topics', [ 'entity_key', 'topic_key', 'actor' ] ] ],
			'link_confirmed'      => $judgment( 'confirmed' ),
			'link_rejected'       => $judgment( 'rejected' ),
			'link_added'          => [ ...$judgment( 'confirmed' ), [ 'set', 'added_links', [ 'item_key', 'entity_key', 'role', 'actor', 'ts' ] ] ],
			'item_excluded'       => [ [ 'set', 'excluded_items', [ 'item_key', 'tier', 'url', 'title', static fn ( array $e ): string => Core::as_string( $e['why'] ?? '' ), 'actor', 'ts' ] ] ],
			'item_included'       => [ [ 'delete', 'excluded_items', [ 'item_key' => 'item_key' ] ] ],
			'collection_created'  => [ [ 'set', 'collections', [ 'collection_key', 'name', 'actor', 'ts' ] ], ...\array_map( self::placed( ... ), $placement ) ],
			'collected'           => $placement,
			'item_moved'          => [
				[ 'delete', 'collected', [ 'collection_key' => 'from', 'item_key' => 'item_key', 'tier' => self::EACH ], 'tiers' ],
				[ 'ignore', 'collected', [ 'collection_key', 'item_key', self::EACH, 'actor', 'ts' ], 'tiers' ],
			],
			'item_uncollected'    => [ [ 'delete', 'collected', [ 'collection_key' => 'collection_key', 'item_key' => 'item_key', 'tier' => self::EACH ], 'tiers' ] ],
			'item_used'           => [ [ 'set', 'item_marks', [ 'item_key', 'tier', 'mark', 'day', 'actor', 'ts' ] ] ],
			'lens_saved'          => [ [ 'set', 'lenses', [ 'lens_key', 'name', 'description', static fn ( array $e ): string => self::json( $e['filters'] ), 'actor', 'ts' ] ] ],
			'lens_removed'        => [ [ 'delete', 'lenses', [ 'lens_key' => 'lens_key' ] ] ],
			'theme_merged'        => [ [ 'set', 'theme_merges', [ 'from', 'into', 'actor', 'ts' ] ] ],
			'themes_kept_apart'   => [ [ 'set', 'themes_apart', [ 'theme', 'other', 'actor', 'ts' ] ] ],
			'themes_rejoined'     => [ [ 'delete', 'themes_apart', [ 'theme' => 'theme', 'other_theme' => 'other' ] ] ],
			'churn_note_set'      => [
				[ 'delete', 'churn_notes', [ 'entity_key' => 'entity_key' ] ],
				[ 'set', 'churn_notes', [ 'entity_key', self::EACH, 'owner', 'next_step', 'day', 'actor', 'ts' ], static fn ( array $e ): array => '' === $e['text'] ? [] : [ $e['text'] ] ],
			],
			'watch_saved'         => [ [ 'set', 'watches', [ 'watch_key', 'owner', 'name', 'target_kind', 'target_key', 'digest', 'instant', 'inbox', 'actor', 'ts' ] ] ],
			'watch_removed'       => [ [ 'delete', 'watches', [ 'watch_key' => 'watch_key' ] ] ],
			'watch_matched'       => [ [ 'set', 'watch_matches', [ 'watch_key', 'owner', 'item_key', 'at', 'ts', 'quiet', $day( 'retrieved_ts' ) ] ] ],
			'watch_seen'          => [ [ 'set', 'watch_seen', [ 'watch_key', 'owner', 'item_key', 'ts', $day( 'retrieved_ts' ) ] ] ],
			'watch_delivered'     => [ [ 'set', 'watch_deliveries', [ 'owner', 'mode', 'watch_key', 'through', 'ts' ] ] ],
			'ask_asked'           => [ [ 'ignore', 'asks', [ 'ask_key', 'owner', [ Graph_Asks::ASKED ], [ '' ], [ 0 ], [ 0 ], [ '[]' ], [ '[]' ], [ '[]' ], [ '[]' ], 'ts', $day( 'ts' ) ] ] ],
			'ask_planned'         => [ [ 'set', 'ask_steps', [ 'ask_key', [ Graph_Asks::STAGES[ Graph_Asks::PLANNED ] ], [ 0 ], $json( 'searches' ), 'ts', $day( 'ts' ) ] ] ],
			'ask_read'            => [ [ 'set', 'ask_steps', [ 'ask_key', [ Graph_Asks::STAGES[ Graph_Asks::READ ] ], 'found', [ '[]' ], 'ts', $day( 'ts' ) ] ] ],
			'ask_answered'        => [ [ 'set', 'asks', [ 'ask_key', 'owner', [ Graph_Asks::ANSWERED ], [ '' ], 'found', 'dropped', $json( 'sentences' ), $json( 'items' ), $json( 'tiers' ), $json( 'searches' ), 'ts', $day( 'ts' ) ] ] ],
			'ask_failed'          => [ [ 'set', 'asks', [ 'ask_key', 'owner', [ Graph_Asks::FAILED ], 'reason', [ 0 ], [ 0 ], [ '[]' ], [ '[]' ], [ '[]' ], $json( 'searches' ), 'ts', $day( 'ts' ) ] ] ],
		];
		$table[ self::STAND_IN ] = [ [ 'ignore', 'extractions', $extraction ] ];
		return self::$projections = $table;
	}

	/**
	 * A save's op spec as a folder created with an item writes it: each
	 * column a save reads off its own fields read off the folder's `item`
	 * record instead, written once when the event carries one, else not.
	 *
	 * @param array{0:string,1:string,2:list<string>} $spec A save's op spec.
	 * @return array{0:string,1:string,2:list<string|\Closure>,3:\Closure}
	 */
	private static function placed( array $spec ): array {
		$members = [ 'item_key' => 'key', 'url' => 'url', 'title' => 'title', 'tier' => 'tier' ];
		$columns = \array_map( static fn ( string $column ): string|\Closure => isset( $members[ $column ] ) ? static fn ( array $e ): mixed => Core::arr( $e['item'] )[ $members[ $column ] ] : $column, $spec[2] );
		return [ $spec[0], $spec[1], $columns, static fn ( array $e ): array => isset( $e['item'] ) ? [ null ] : [] ];
	}

	/**
	 * The op spec of the `events` row every ontology kind writes, under the
	 * key it names, an item's when it names nothing else.
	 *
	 * @return array{0:string,1:string,2:list<mixed>}
	 */
	private static function event_row(): array {
		return self::$event_row ??= [
			'ignore',
			'events',
			[
				'event_id',
				'event',
				'actor',
				static fn ( array $e ): string => Core::as_string( $e['entity_key'] ?? $e['collection_key'] ?? $e['lens_key'] ?? $e['watch_key'] ?? $e['from'] ?? $e['item_key'] ?? null ),
				static fn ( array $e ): string => self::json( \array_diff_key( $e, \array_flip( Intel_Event::ENVELOPE ) ) ),
				'config_version',
				'ts',
			],
		];
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
	 * The row ops one spec writes for an event: one op, or one per element
	 * of its fan-out, a delete's columns named and a write's positional.
	 * Each value is typed by its column's declared type.
	 *
	 * @param array<array-key,mixed>                                   $e    A validated event.
	 * @param array{0:string,1:string,2:array<mixed>,3?:string|\Closure} $spec The op spec.
	 * @return list<Row_Op|Row_Delete>
	 */
	private static function ops( array $e, array $spec ): array {
		[ $verb, $table, $columns ] = $spec;
		$types                      = self::$types[ $table ] ??= self::types_of( $table );
		$fan                        = $spec[3] ?? null;
		$elements                   = null === $fan ? [ null ] : \array_values( Core::arr( $fan instanceof \Closure ? $fan( $e ) : $e[ $fan ] ) );
		if ( 'delete' === $verb ) {
			return \array_map( static fn ( mixed $element ): array => [ 'delete', $table, self::where( $e, $columns, $types, $element ) ], $elements );
		}
		$ops = [];
		foreach ( $elements as $element ) {
			$row = [];
			foreach ( \array_values( $columns ) as $at => $column ) {
				$row[] = self::typed( self::value( $e, $column, $element ), $types[ $at ] );
			}
			$ops[] = [ $verb, $table, $row ];
		}
		return $ops;
	}

	/**
	 * A delete's named columns for an event and one fan-out element, each
	 * typed by its column's declared type.
	 *
	 * @param array<array-key,mixed>   $e       A validated event.
	 * @param array<mixed>             $columns Column name => column spec.
	 * @param array<int|string,string> $types   The table's column types.
	 * @param mixed                    $element The fan-out element, or null.
	 * @return array<string,int|float|string|null>
	 */
	private static function where( array $e, array $columns, array $types, mixed $element ): array {
		$where = [];
		foreach ( $columns as $name => $column ) {
			$name           = Core::as_string( $name );
			$where[ $name ] = self::typed( self::value( $e, $column, $element ), $types[ $name ] );
		}
		return $where;
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
	 * @throws \LogicException When the command named none.
	 */
	private function graph_table(): string {
		return '' === $this->graph_target ? throw new \LogicException( 'Projector: no Row_Table named by set_graph_target' ) : $this->graph_target;
	}

	/**
	 * Whether a projector's reader of a stream has a committed cursor short
	 * of its end. The projector flushes at every checkpoint, so a cursor at
	 * the end means every event the stream holds is projected. It reads the
	 * disk on every call, since a caller may ask again after it appends.
	 *
	 * @api Read by the resolver's hold and the watcher's pass.
	 * @param string $stream_dir The stream's directory.
	 */
	public static function behind( string $stream_dir ): bool {
		$readers = \array_values( \array_filter( self::readers(), static fn ( array $reader ): bool => $stream_dir === $reader['source'] ) );
		foreach ( self::lags( $readers ) as [ , $lag ] ) {
			if ( ! $lag['caught_up'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The end each projector reader's stream holds, its last segment and
	 * that segment's size, by the reader's cursor dir: what short_of()
	 * measures the readers against. Read as reading() reads, once a tick.
	 *
	 * @api Read by Source_Node, which waits on it before a pass.
	 * @return array<string,array{0:int,1:int}>
	 */
	public static function ends(): array {
		$ends = [];
		foreach ( self::reading() as [ $reader, $lag ] ) {
			$ends[ $reader['offsets'] ] = [ Core::as_int( $lag['end_segment'] ), Core::as_int( $lag['end_size'] ) ];
		}
		return $ends;
	}

	/**
	 * The stream of the first projector reader whose committed cursor stands
	 * short of the end `$ends` names for it, null once every one has reached
	 * it. The projector flushes at every checkpoint, so a reader that has
	 * reached its end has projected every event its stream held there. A
	 * stream that now ends before its mark was recreated, so its reader is
	 * judged by whether it has read the stream to its end now; a reader
	 * `$ends` does not name is not waited for. Read as reading() reads.
	 *
	 * @api Read by Source_Node, which waits on it before a pass.
	 * @param array<string,array{0:int,1:int}> $ends The ends ends() answered, by cursor dir.
	 */
	public static function short_of( array $ends ): ?string {
		foreach ( self::reading() as [ $reader, $lag ] ) {
			if ( ! isset( $ends[ $reader['offsets'] ] ) ) {
				continue;
			}
			$mark = $ends[ $reader['offsets'] ];
			$end  = [ Core::as_int( $lag['end_segment'] ), Core::as_int( $lag['end_size'] ) ];
			$at   = [ Core::as_int( $lag['cursor_segment'] ), Core::as_int( $lag['cursor_offset'] ) ];
			if ( self::before( $end, $mark ) ? ! $lag['caught_up'] : self::before( $at, $mark ) ) {
				return $reader['source'];
			}
		}
		return null;
	}

	/**
	 * Every projector reader and its lag, read once per tick of the tick
	 * clock, the readers among them, since readers() parses every active
	 * topology: the sources waiting in one Router tick share one reading.
	 * The runtime base keys it too, which a worker never changes.
	 *
	 * @return list<array{0:array{source:string,offsets:string},1:array<string,mixed>}>
	 */
	private static function reading(): array {
		$base = Config::get_base_directory();
		if ( Core::$now !== self::$reading[0] || $base !== self::$reading[1] ) {
			self::$reading = [ Core::$now, $base, \iterator_to_array( self::lags( self::readers() ), false ) ];
		}
		return self::$reading[2];
	}

	/**
	 * Each reader with its lag as the disk holds it now.
	 *
	 * @param list<array{source:string,offsets:string}> $readers The readers, as readers() answers them.
	 * @return \Generator<int,array{0:array{source:string,offsets:string},1:array<string,mixed>}>
	 */
	private static function lags( array $readers ): \Generator {
		foreach ( $readers as $reader ) {
			yield [ $reader, Consumer_Node::lag_from_disk( $reader['source'], $reader['offsets'] ) ];
		}
	}

	/**
	 * Whether one stream position, a segment and an offset in it, lies
	 * before another.
	 *
	 * @param array{0:int,1:int} $position The position.
	 * @param array{0:int,1:int} $other    The position it is measured against.
	 */
	private static function before( array $position, array $other ): bool {
		return $position[0] < $other[0] || ( $position[0] === $other[0] && $position[1] < $other[1] );
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
	 * The `set_graph_target` command's entry point, which the declared setter
	 * calls by name.
	 *
	 * @param string $table The Row_Table the rows are written to.
	 */
	public function set_graph_target( string $table ): void {
		$this->graph_target = $table;
	}

	/**
	 * The Row_Table the command named, which the flush writes past any target.
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
	 * Declarative schema: the `:config` command and the palette metadata.
	 *
	 * @api Used by the substrate to provide UI etc.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		return [
			'category'    => 'Transform',
			'description' => 'Replays the intel:items, intel:ontology, intel:watches and intel:asks events into an Intel_Graph Row_Table.',
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

<?php
/**
 * Digest_Builder_Node: accumulates summaries and composes a markdown draft.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

class Digest_Builder_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;

	/** Digest filename under the configured logs dir; the path itself is digest_path(). */
	public const DIGEST_FILE = 'digest.md';

	/** The in-band marker RESET appends and this node clears on. */
	public const FENCE = "RESET\n";

	/**
	 * LLM-client factory seam. Lazily-defaulted at the call site to this node's
	 * own verb-configured `make_llm_client()` (null when no vault token resolves).
	 * Tests reassign in setUp to inject a real `Proxy_LLM_Client` — faking only its
	 * `$http_post` seam — so prompt assembly, the client, and the briefing compose
	 * all run as real, covered production code; tearDown resets it to null.
	 *
	 * Signature: `function (): ?LLM_Client`.
	 *
	 * @var (\Closure(): ?LLM_Client)|null
	 */
	public static ?\Closure $llm_factory = null;

	/**
	 * Accumulated summarized items (array-key: they round-trip through offsetlog JSON).
	 *
	 * @var array<int,array<array-key,mixed>>
	 */
	private array $items = [];

	/**
	 * Partition at the pipeline head (arg 0). RESET appends its fence here, so the
	 * fence reaches this node behind every DONE already in flight.
	 */
	private string $ingest_partition = '';

	/**
	 * Distinct sources that signalled DONE this cycle, keyed by FROM. Counting
	 * distinct names keeps a re-tick or a replay from advancing `done`.
	 *
	 * @var array<string,bool>
	 */
	private array $reported = [];

	/**
	 * Seen item ids for in-cycle dedup; rebuilt from items on restore, cleared on RESET.
	 *
	 * @var array<string,bool>
	 */
	private array $seen = [];

	/** Sources expected per cycle (arg 1). */
	private int $total = 0;

	/** Tachikoma-parity: no-arg ctor. Wires the sibling :config interpreter from node_schema()['commands']. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Answers TM_REQUEST 'RESET' and 'REGENERATE'; accepts TM_INFO DONE and the RESET fence, and TM_STRUCT items.
	 *
	 * @param array<int,mixed> $message Message reference.
	 */
	public function fill( array $message ): void {
		if ( $this->answer_request( $message ) ) {
			return;
		}
		$type = \is_numeric( $message[ Message::TYPE ] ) ? (int) $message[ Message::TYPE ] : 0;
		if ( $type & Message::TM_INFO ) {
			$this->handle_info( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$item = $message[ Message::VALUE ];
		if ( ! \is_array( $item ) ) {
			return;
		}
		/** @var array<array-key,mixed> $item */
		$id = isset( $item['id'] ) && \is_string( $item['id'] ) ? $item['id'] : '';
		if ( '' !== $id && isset( $this->seen[ $id ] ) ) {
			return;
		}
		if ( '' !== $id ) {
			$this->seen[ $id ] = true;
		}
		if ( ! \is_string( $item['title'] ?? null ) ) {
			$item['title'] = '(untitled)';
		}
		$this->set_state( 'RECEIVED', $item['title'] );
		$this->items[] = $item;
		++$this->counter;
	}

	/**
	 * Runtime notifications: the RESET fence clears the cycle, and a DONE from a
	 * source not yet counted advances it, composing the draft on the one DONE
	 * that completes it.
	 *
	 * @param array<int,mixed> $message Incoming TM_INFO Message.
	 */
	private function handle_info( array $message ): void {
		$value = \is_string( $message[ Message::VALUE ] ?? null ) ? $message[ Message::VALUE ] : '';
		if ( self::FENCE === $value ) {
			$this->reset();
			return;
		}
		if ( "DONE\n" !== $value ) {
			return;
		}
		$from = \is_string( $message[ Message::FROM ] ?? null ) ? $message[ Message::FROM ] : '';
		if ( isset( $this->reported[ $from ] ) ) {
			return;
		}
		$this->reported[ $from ] = true;
		if ( \count( $this->reported ) === $this->total ) {
			$this->compose_draft();
		}
	}

	/** Empty the accumulator, its dedup set and the reported-source tally. */
	private function reset(): void {
		$this->items    = [];
		$this->seen     = [];
		$this->reported = [];
	}

	/**
	 * REGENERATE handler: compose a draft from the items already collected.
	 *
	 * @return array{composed:int} The reply data: how many items the draft drew on.
	 */
	private function regenerate(): array {
		$this->compose_draft();
		return [ 'composed' => \count( $this->items ) ];
	}

	private function compose_draft(): void {
		$client = self::$llm_factory ? ( self::$llm_factory )() : $this->make_llm_client();
		$draft  = Digest_Composer::compose( $this->items, $client, $this->relevance_profile() );
		$this->set_state( 'COMPOSED', \count( $this->items ) . ' items' );
		$response                   = Message::new_message();
		$response[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$response[ Message::FROM ]  = $this->name;
		$response[ Message::VALUE ] = $draft;
		parent::fill( $response );
	}

	/**
	 * RESET handler (the dashboard's Collect, before it TICKs the sources):
	 * append the fence to the ingest Partition. It returns through the pipeline
	 * behind every message already in flight, and clears the cycle on arrival,
	 * where scored:consumer co-commits the emptied snapshot with its cursor.
	 * TO is set explicitly because `target` is the draft sink (digest:tee).
	 *
	 * @return array{fence:string} The reply data: the Partition the fence went to.
	 */
	private function fence(): array {
		$fence                   = Message::new_message();
		$fence[ Message::TYPE ]  = Message::TM_INFO;
		$fence[ Message::FROM ]  = $this->name;
		$fence[ Message::TO ]    = $this->ingest_partition;
		$fence[ Message::VALUE ] = self::FENCE;
		parent::fill( $fence );
		return [ 'fence' => $this->ingest_partition ];
	}

	/**
	 * The Partition the fence writes past `target`, so the console draws its edge.
	 *
	 * @api Unioned into display_targets() by the substrate's Node.
	 * @return list<string>
	 */
	protected function extra_targets(): array {
		return [ $this->ingest_partition ];
	}

	/**
	 * Where the digest:log Node writes the rendered newsletter — derived from the
	 * substrate's configured `logs_dir`, so it lands INSIDE the runtime base.
	 * MUST match `<config:logs_dir>/digest.md` in
	 * topologies/newspack-intelligence-digest.tsl; the substrate's Log path guard
	 * refuses a Log outside the base.
	 */
	public static function digest_path(): string {
		$dir = \Newspack_Nodes\Core::resolve_config_token( 'config', 'logs_dir' );
		return \rtrim( $dir, '/' ) . '/' . self::DIGEST_FILE;
	}

	/**
	 * Snapshot contract: items + collection progress, co-committed by the Consumer
	 * into its offsetlog (via `add_snapshot_node digest`), so a respawned worker
	 * restores this in lockstep with the cursor and the dashboard reads live
	 * progress. Bounded — keep the digest small.
	 *
	 * @return array{items: array<int,array<array-key,mixed>>, done: int, total: int, reported: array<int,string>}
	 */
	public function save_state(): array {
		return [
			'items'    => $this->items,
			'done'     => \count( $this->reported ),
			'total'    => $this->total,
			'reported' => \array_keys( $this->reported ),
		];
	}

	/**
	 * Restore the accumulated items and reported sources from a snapshot cache;
	 * `total` stays the configured argument. Tolerates a malformed
	 * payload (resets to empty, drops non-array items) rather than fataling a
	 * fresh worker on boot.
	 *
	 * @param array<string,mixed> $state
	 */
	public function restore_state( array $state ): void {
		$this->items    = [];
		$this->seen     = [];
		$this->reported = [];
		$sources        = $state['reported'] ?? null;
		if ( \is_array( $sources ) ) {
			foreach ( $sources as $source ) {
				if ( \is_string( $source ) ) {
					$this->reported[ $source ] = true;
				}
			}
		}
		$items = $state['items'] ?? null;
		if ( ! \is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( ! \is_array( $item ) ) {
				continue;
			}
			$id = isset( $item['id'] ) && \is_string( $item['id'] ) ? $item['id'] : '';
			if ( '' !== $id && isset( $this->seen[ $id ] ) ) {
				continue;
			}
			if ( '' !== $id ) {
				$this->seen[ $id ] = true;
			}
			$this->items[] = $item;
		}
	}

	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'     => 'Transform',
			'description'  => 'Accumulates summaries',
			'arguments'    => [
				[
					'name'        => 'ingest_partition',
					'type'        => 'string',
					'required'    => true,
					'description' => 'Partition at the pipeline head; RESET appends its fence there so the reset trails every DONE in flight.',
				],
				[
					'name'        => 'total',
					'type'        => 'int',
					'required'    => true,
					'description' => 'Sources per cycle; the DONE that brings the reported count to it composes the draft.',
				],
			],
			'requests'     => [
				[
					'name'        => 'RESET',
					'description' => 'Fence the ingest Partition; the cycle clears when the fence arrives back (the dashboard Collect sends this before TICKing sources).',
					'reply_shape' => '{ fence }',
					'handler'     => static fn ( self $node ): array => $node->fence(),
				],
				[
					'name'        => 'REGENERATE',
					'description' => 'Compose a new draft based on the items already collected.',
					'reply_shape' => '{ composed }',
					'handler'     => static fn ( self $node ): array => $node->regenerate(),
				],
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		] );
	}
}

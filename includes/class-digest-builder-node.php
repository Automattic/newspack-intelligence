<?php
/**
 * Digest_Builder_Node: accumulates summaries; `flush` emits a markdown draft.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

class Digest_Builder_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;

	/** Where the digest:log Node writes the rendered newsletter. MUST match topologies/newspack-ai-newsletter.tsl. */
	public const DIGEST_PATH = '/tmp/newspack-ai-newsletter/digest.md';

	/**
	 * Accumulated summarized items (array-key: they round-trip through offsetlog JSON).
	 *
	 * @var array<int,array<array-key,mixed>>
	 */
	private array $items = [];

	/**
	 * Seen item ids for in-cycle dedup; rebuilt from items on restore, cleared on RESET.
	 *
	 * @var array<string,bool>
	 */
	private array $seen = [];

	/** Scored-partition node name to nudge on RESET (arg 0); '' disables the nudge. */
	private string $scored_partition = '';

	/**
	 * Distinct sources that signalled DONE this cycle (keyed by source name).
	 * Counting distinct names — not raw signals — is idempotent across re-ticks, replays,
	 * and a stale cross-cycle DONE, so `done` can't overshoot the real source count.
	 *
	 * @var array<string,bool>
	 */
	private array $reported = [];

	/** Sources expected this cycle, set by a RESET (the dashboard's Collect). 0 until a collect. */
	private int $total = 0;

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

	/** Tachikoma-parity: no-arg ctor. Wires the sibling :config interpreter from node_schema()['commands']. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Parse the positional arguments: arg 0 is the total number of sources.
	 *
	 * @param string|null $args Space-separated argument string, or null to read.
	 */
	public function arguments( ?string $args = null ): string {
		if ( null === $args ) {
			return parent::arguments();
		}
		$this->parse_schema_args( $args );
		return $args;
	}

	/**
	 * Accepts TM_REQUEST 'RESET' and 'REGENERATE', TM_INFO "DONE\n", and TM_STRUCT messages.
	 *
	 * @param array<int,mixed> $message Message reference.
	 */
	public function fill( array $message ): void {
		$type = \is_numeric( $message[ Message::TYPE ] ) ? (int) $message[ Message::TYPE ] : 0;
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
			return;
		}
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
	 * Runtime triggers. RESET (the dashboard's Collect, before it TICKs the sources) zeroes the progress counter.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_request( array $message ): void {
		$value = \is_string( $message[ Message::VALUE ] ?? null ) ? $message[ Message::VALUE ] : '';
		if ( 'RESET' === $value ) {
			$this->items    = [];
			$this->seen     = [];
			$this->reported = [];
			$this->nudge_scored_partition();
		} elseif ( 'REGENERATE' === $value ) {
			$this->compose_draft();
		}
	}

 	/**
	 * Append a throwaway message to the scored Partition (if configured) so
	 * scored:consumer advances its cursor and its next checkpoint co-commits this
	 * node's now-emptied snapshot. Without it a RESET changes our state but not the
	 * consumer cursor, so the offsetlog keeps the stale full items list and a worker
	 * restart reloads it. The 'RESET' is ignored downstream.
	 * TO is set explicitly because `target` is the draft sink (digest:tee).
	 */
	private function nudge_scored_partition(): void {
		if ( '' === $this->scored_partition ) {
			return;
		}
		$nudge                   = Message::new_message();
		$nudge[ Message::TYPE ]  = Message::TM_INFO;
		$nudge[ Message::FROM ]  = $this->name;
		$nudge[ Message::TO ]    = $this->scored_partition;
		$nudge[ Message::VALUE ] = 'RESET';
		parent::fill( $nudge );
	}

	/**
	 * Runtime notifications. DONE signals from sources are tallied here.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_info( array $message ): void {
		$value = \is_string( $message[ Message::VALUE ] ?? null ) ? $message[ Message::VALUE ] : '';
		if ( "DONE\n" === $value ) {
			$from                    = \is_string( $message[ Message::FROM ] ?? null ) ? $message[ Message::FROM ] : '';
			$this->reported[ $from ] = true;
			if ( \count( $this->reported ) >= $this->total ) {
				$this->compose_draft();
			}
		}
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
	 * Snapshot contract: items + collection progress, co-committed by the Consumer
	 * into its offsetlog (via `set_snapshot_node digest`), so a respawned worker
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
	 * Restore the accumulated items from a snapshot cache. Tolerates a malformed
	 * payload (resets to empty, drops non-array items) rather than fataling a
	 * fresh worker on boot.
	 *
	 * @param array<string,mixed> $state
	 */
	public function restore_state( array $state ): void {
		$this->items    = [];
		$this->seen     = [];
		$this->reported = [];
		$this->total    = isset( $state['total'] ) && \is_numeric( $state['total'] ) ? (int) $state['total'] : 0;
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
					'name'        => 'scored_partition',
					'type'        => 'string',
					'required'    => true,
					'description' => 'Scored Partition node to nudge on RESET so the consumer persists the emptied snapshot.',
				],
				[
					'name'        => 'total',
					'type'        => 'int',
					'default'     => 0,
					'description' => 'Total number of sources about to collect.',
				],
			],
			'requests'     => [
				[
					'name'        => 'RESET',
					'description' => 'Zero the collection counter (the dashboard Collect sends this before TICKing sources). `total` comes from the make_node argument, not this request.',
				],
				[
					'name'        => 'REGENERATE',
					'description' => 'Compose a new draft based on the items already collected.',
				],
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		] );
	}
}

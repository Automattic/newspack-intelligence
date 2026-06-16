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

	/** @var array<int,array<array-key,mixed>> Accumulated summarized items (array-key: they round-trip through offsetlog JSON). */
	private array $items = [];

	/** @var array<string,bool> Seen item ids for in-cycle dedup; rebuilt from items on restore, cleared on FLUSH. */
	private array $seen = [];

	/** Scored-partition node name to nudge on FLUSH (arg 0); '' disables the nudge. */
	private string $scored_partition = '';

	/** @var array<string,bool> Distinct sources that signalled DONE this cycle (keyed by source name). Counting distinct names — not raw signals — is idempotent across re-ticks, replays, and a stale cross-cycle DONE, so `done` can't overshoot the real source count. */
	private array $reported = [];

	/** Sources expected this cycle, set by a RESET (the dashboard's Collect). 0 until a collect. */
	private int $total = 0;

	/**
	 * LLM-client factory seam. Lazily-defaulted at the call site to
	 * `Settings::llm_client()` (null when no proxy token is configured). Tests
	 * reassign in setUp to inject a real `Proxy_LLM_Client` — faking only its
	 * `$http_post` seam — so prompt assembly, the client, and the briefing compose
	 * all run as real, covered production code; tearDown resets it to null.
	 *
	 * Signature: `function (): ?LLM_Client`.
	 *
	 * @var (\Closure(): ?LLM_Client)|null
	 */
	public static ?\Closure $llm_factory = null;

	/**
	 * Parse the positional arguments: arg 0 is the scored Partition node name to
	 * nudge on FLUSH (so scored:consumer persists the emptied snapshot).
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
	 * FLUSH is a runtime trigger: a TM_REQUEST handled here in fill() (NOT a
	 * TM_COMMAND verb — that flag is for startup/admin). A TM_STRUCT message is
	 * data to accumulate; everything else is ignored.
	 *
	 * @param array<int,mixed> $message Message reference.
	 */
	public function fill( array &$message ): void {
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
		if ( ! \is_string( $item['title'] ?? null ) ) {
			$item['title'] = '(untitled)';
		}
		/** @var array<array-key,mixed> $item */
		$id = isset( $item['id'] ) && \is_string( $item['id'] ) ? $item['id'] : '';
		if ( '' !== $id && isset( $this->seen[ $id ] ) ) {
			return;
		}
		if ( '' !== $id ) {
			$this->seen[ $id ] = true;
		}
		$this->items[] = $item;
		$this->set_state( 'RECEIVED', $item['title'] );
		++$this->counter;
	}

	/**
	 * Runtime triggers. RESET (the dashboard's Collect, before it TICKs the sources)
	 * zeroes the progress counter. FLUSH composes + emits the draft, clears items, and
	 * resets progress. Fire-and-forget.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_request( array $message ): void {
		$value = \is_string( $message[ Message::VALUE ] ?? null ) ? $message[ Message::VALUE ] : '';
		if ( 'RESET' === $value ) {
			$this->reported = [];
		} elseif ( 'FLUSH' === $value ) {
			$client = ( self::$llm_factory ?? static fn (): ?LLM_Client => Settings::llm_client() )();
			$draft  = Digest_Composer::compose( $this->items, $client, Settings::get_string( 'relevance_profile' ) );
			$this->set_state( 'COMPOSED', \count( $this->items ) . ' items' );

			$response                   = Message::new_message();
			$response[ Message::TYPE ]  = Message::TM_BYTESTREAM;
			$response[ Message::FROM ]  = $this->name;
			$response[ Message::VALUE ] = $draft;
			// parent::fill stamps TO from a connect_node-set target, then forwards to sink.
			parent::fill( $response );
			$this->items    = [];
			$this->seen     = [];
			$this->reported = [];
			$this->nudge_scored_partition();
		}
	}

	/**
	 * Append a throwaway message to the scored Partition (if configured) so
	 * scored:consumer advances its cursor and its next checkpoint co-commits this
	 * node's now-emptied snapshot. Without it a FLUSH changes our state but not the
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
		if ( 'DONE' === $value ) {
			$from                    = \is_string( $message[ Message::FROM ] ?? null ) ? $message[ Message::FROM ] : '';
			$this->reported[ $from ] = true;
		}
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
			'description'  => 'Accumulates summaries; a FLUSH request emits a markdown newsletter draft (request_node digest FLUSH).',
			'arguments'    => [
				[
					'name'        => 'scored_partition',
					'type'        => 'string',
					'required'    => true,
					'description' => 'Scored Partition node to nudge on FLUSH so the consumer persists the emptied snapshot.',
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
					'name'        => 'FLUSH',
					'description' => 'Emit the accumulated draft and clear. Trigger with `request_node digest FLUSH`.',
				],
				[
					'name'        => 'RESET',
					'description' => 'Zero the collection counter (the dashboard Collect sends this before TICKing sources). `total` comes from the make_node argument, not this request.',
				],
			],
			'accepts_fill' => true,
			'has_target'   => true,
		] );
	}
}

<?php
/**
 * Digest_Builder_Node: accumulates summaries; `flush` emits a markdown draft.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;

\defined( 'ABSPATH' ) || exit;

class Digest_Builder_Node extends Node {

	/** @var array<int,array<array-key,mixed>> Accumulated summarized items (array-key: they round-trip through offsetlog JSON). */
	private array $items = [];

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
		if ( 0 === ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$value = $message[ Message::VALUE ];
		if ( ! \is_array( $value ) ) {
			return;
		}
		/** @var array<string,mixed> $value */
		$this->items[] = $value;
		++$this->counter;
	}

	/**
	 * FLUSH handler: compose an LLM briefing (ranked-list fallback), emit, clear —
	 * fire-and-forget.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_request( array $message ): void {
		$client = ( self::$llm_factory ?? static fn (): ?LLM_Client => Settings::llm_client() )();
		$draft  = null;
		if ( $client instanceof LLM_Client ) {
			try {
				$draft = $client->chat(
					Prompts::digest( $this->top_items( 10 ), Settings::get_string( 'relevance_profile' ) ),
					[ 'max_tokens' => 1500 ]
				);
			} catch ( \RuntimeException $e ) {
				// Rate-limited; an LLM failure NEVER throws out of flush — fall back to the ranked list.
				$this->print_less_often( 'AI digest compose failed: ' . $e->getMessage() );
			}
		}
		if ( null === $draft || '' === \trim( $draft ) ) {
			$draft = $this->render_ranked_list();
		}

		$response                   = Message::new_message();
		$response[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$response[ Message::FROM ]  = $this->name;
		$response[ Message::VALUE ] = $draft;
		// parent::fill stamps TO from a connect_node-set target, then forwards to sink.
		parent::fill( $response );
		$this->items = [];
	}

	/** Render the accumulated summaries to a markdown bullet list — the no-AI fallback. */
	private function render_ranked_list(): string {
		$lines = [ '# Newsletter draft', '' ];
		foreach ( $this->items as $item ) {
			$summary = $item['summary'] ?? '';
			$lines[] = '- ' . ( \is_string( $summary ) ? $summary : '' );
		}
		return \implode( "\n", $lines ) . "\n";
	}

	/**
	 * The top $n accumulated items, highest `score` first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function top_items( int $n ): array {
		$items = $this->items;
		\usort(
			$items,
			static fn ( array $a, array $b ): int => self::score_of( $b ) <=> self::score_of( $a )
		);
		/** @var array<int,array<string,mixed>> $top */
		$top = \array_slice( $items, 0, $n );
		return $top;
	}

	/**
	 * Read an item's `score` as a float; absent or non-numeric becomes 0.
	 *
	 * @param array<array-key,mixed> $item
	 */
	private static function score_of( array $item ): float {
		$score = $item['score'] ?? 0;
		return \is_numeric( $score ) ? (float) $score : 0.0;
	}

	/**
	 * Snapshot contract: the accumulated items the Consumer co-commits into its
	 * offsetlog (via `set_snapshot_node digest`), so a respawned worker restores
	 * this in lockstep with the cursor. Bounded — keep the digest small.
	 *
	 * @return array{items: array<int,array<array-key,mixed>>}
	 */
	public function save_state(): array {
		return [ 'items' => $this->items ];
	}

	/**
	 * Restore the accumulated items from a snapshot cache. Tolerates a malformed
	 * payload (resets to empty, drops non-array items) rather than fataling a
	 * fresh worker on boot.
	 *
	 * @param array<string,mixed> $state
	 */
	public function restore_state( array $state ): void {
		$this->items = [];
		$items       = $state['items'] ?? null;
		if ( ! \is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( \is_array( $item ) ) {
				$this->items[] = $item;
			}
		}
	}

	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'     => 'Transform',
			'description'  => 'Accumulates summaries; a FLUSH request emits a markdown newsletter draft (request_node digest FLUSH).',
			'arguments'    => [],
			'requests'     => [
				[
					'name'        => 'FLUSH',
					'description' => 'Emit the accumulated draft and clear. Trigger with `request_node digest FLUSH`.',
				],
			],
			'accepts_fill' => true,
			'has_target'   => true,
		] );
	}
}

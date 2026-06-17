<?php
/**
 * Digest_Composer: the shared "items → markdown digest" core.
 *
 * Used by both the worker's Digest_Builder FLUSH (writes digest:log) and the
 * dashboard's Insights_CI `generate` verb, so the two paths can't drift: every
 * accumulated item — ranked by score — goes through the LLM, with a ranked-list
 * fallback when there's no client or the call fails / returns empty.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Digest_Composer {

	// No item cap by design — the briefing covers every accumulated item. The set
	// is self-limiting, not enforced here: the builder resets each cycle and the
	// sources page-cap their fetches (~10 each), so a few dozen items reach this at
	// most; 3000 output tokens comfortably fits a briefing that size.
	private const MAX_TOKENS = 3000;

	/**
	 * Compose a markdown digest from accumulated items.
	 *
	 * @param array<int,array<array-key,mixed>> $items   Accumulated summarized items.
	 * @param LLM_Client|null                   $client  LLM client, or null to skip straight to the fallback.
	 * @param string                            $profile The relevance profile for the briefing prompt.
	 */
	public static function compose( array $items, ?LLM_Client $client, string $profile ): string {
		$draft = null;
		if ( $client instanceof LLM_Client ) {
			try {
				$draft = $client->chat(
					Prompts::digest( self::ranked_by_score( $items ), $profile ),
					[ 'max_tokens' => self::MAX_TOKENS ]
				);
			} catch ( \RuntimeException $e ) {
				// Rate-limited; an LLM failure NEVER throws out of compose — fall back to the ranked list.
				Core::print_less_often( 'AI digest compose failed: ' . $e->getMessage() );
				$draft = null;
			}
		}
		if ( null === $draft || '' === \trim( $draft ) ) {
			return self::render_ranked_list( $items );
		}
		return $draft;
	}

	/**
	 * Render the accumulated summaries to a markdown bullet list — the no-AI fallback.
	 *
	 * @param array<int,array<array-key,mixed>> $items Accumulated summarized items.
	 */
	private static function render_ranked_list( array $items ): string {
		$lines = [ '# Newsletter draft', '' ];
		foreach ( $items as $item ) {
			$summary = $item['summary'] ?? '';
			$lines[] = '- ' . ( \is_string( $summary ) ? $summary : '' );
		}
		return \implode( "\n", $lines ) . "\n";
	}

	/**
	 * Every item, highest `score` first.
	 *
	 * @param array<int,array<array-key,mixed>> $items Accumulated items.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function ranked_by_score( array $items ): array {
		\usort(
			$items,
			static fn ( array $a, array $b ): int => self::score_of( $b ) <=> self::score_of( $a )
		);
		return $items;
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
}

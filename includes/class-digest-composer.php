<?php
/**
 * Digest_Composer: the shared "items → markdown digest" core.
 *
 * Used by both the worker's Digest_Builder FLUSH (writes digest:log) and the
 * dashboard's Insights_CI `generate` verb, so the two paths can't drift: the top
 * N items PER SOURCE (so no single source crowds the others out) go through the
 * LLM, with a ranked-list fallback when there's no client or the call fails / returns empty.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Digest_Composer {

	// Per-source cap: the briefing draws the top N items FROM EACH SOURCE, so a
	// high-volume source (e.g. github) can't crowd linear/feed out of the digest.
	private const PER_SOURCE = 10;
	private const MAX_TOKENS = 5000;

	/**
	 * Compose a markdown digest from accumulated items.
	 *
	 * @param array<int,array<array-key,mixed>> $items   Accumulated summarized items.
	 * @param LLM_Client|null                   $client  LLM client, or null to skip straight to the fallback.
	 * @param string                            $profile The relevance profile for the briefing prompt.
	 */
	public static function compose( array $items, ?LLM_Client $client, string $profile ): string {
		// Select the top N per source up front so the LLM path and the no-AI
		// fallback both work from the same balanced set.
		$selected = self::top_per_source( $items, self::PER_SOURCE );
		$draft    = null;
		if ( $client instanceof LLM_Client ) {
			try {
				$draft = $client->chat(
					Prompts::digest( $selected, $profile ),
					[ 'max_tokens' => self::MAX_TOKENS ]
				);
			} catch ( \RuntimeException $e ) {
				// Rate-limited; an LLM failure NEVER throws out of compose — fall back to the ranked list.
				Core::print_less_often( 'AI digest compose failed: ' . $e->getMessage() );
				$draft = null;
			}
		}
		if ( null === $draft || '' === \trim( $draft ) ) {
			return self::render_ranked_list( $selected );
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
	 * The top $n items PER SOURCE: grouped by `source` (first-seen order), each
	 * group sorted by `score` desc and capped at $n, then flattened. Keeps every
	 * source represented regardless of how many items a single source contributed.
	 *
	 * @param array<int,array<array-key,mixed>> $items Accumulated items.
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function top_per_source( array $items, int $n ): array {
		$by_source = [];
		foreach ( $items as $item ) {
			$source                 = \is_string( $item['source'] ?? null ) ? $item['source'] : '?';
			$by_source[ $source ][] = $item;
		}
		$out = [];
		foreach ( $by_source as $list ) {
			\usort(
				$list,
				static fn ( array $a, array $b ): int => self::score_of( $b ) <=> self::score_of( $a )
			);
			foreach ( \array_slice( $list, 0, $n ) as $item ) {
				$out[] = $item;
			}
		}
		return $out;
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

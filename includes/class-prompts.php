<?php
/**
 * Prompts: the single source of truth for the LLM chat-message builders.
 *
 * Two pure static builders — `enrich()` (one ingested item → summary + relevance
 * score + reason) and `digest()` (the ranked item set → one markdown briefing) —
 * each returning the OpenAI chat-messages shape the `LLM_Client` consumes. No I/O.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Prompts {
	/**
	 * Build the enrich chat messages for a single ingested item.
	 *
	 * @param array<string,mixed> $item              The ingested item (title/source/body).
	 * @param string              $relevance_profile The audience's relevance profile.
	 * @return array<int,array{role:string,content:string}>
	 */
	public static function enrich( array $item, string $relevance_profile ): array {
		$system = 'You are an editorial assistant triaging team-intelligence items. '
			. 'Reply with ONLY a JSON object, no prose or code fences, in exactly this shape: '
			. '{"summary": "one-sentence summary", "relevance_score": 0-10 integer, "reason": "why it scored that"}.';

		$user = \sprintf(
			"Title: %s\nSource: %s\nBody: %s\n\nRate relevance to: %s",
			self::field( $item, 'title' ),
			self::field( $item, 'source' ),
			self::field( $item, 'body' ),
			$relevance_profile
		);

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user', 'content' => $user ],
		];
	}

	/**
	 * Read a scalar item field as a string; absent or non-scalar values become ''.
	 *
	 * @param array<array-key,mixed> $item The item array.
	 */
	private static function field( array $item, string $key ): string {
		$value = $item[ $key ] ?? '';
		return Core::as_string( $value );
	}

	/**
	 * Build the digest chat messages for the ranked item set.
	 *
	 * @param array<int,array<array-key,mixed>> $items             The ranked items (title/summary/source/score/url); JSON-sourced, so array-key.
	 * @param string                            $relevance_profile The audience's relevance profile.
	 * @return array<int,array{role:string,content:string}>
	 */
	public static function digest( array $items, string $relevance_profile ): array {
		$system = 'You are an editorial assistant writing a concise "what mattered" team briefing in markdown. '
			. 'Open with a short intro, group related items into sections, and give each item a one-line blurb '
			. 'with its link. Be brief and skimmable.';

		$lines = [];
		foreach ( $items as $item ) {
			$lines[] = \sprintf(
				'- %s (%s, score %s): %s — %s',
				self::field( $item, 'title' ),
				self::field( $item, 'source' ),
				self::field( $item, 'score' ),
				self::field( $item, 'summary' ),
				self::field( $item, 'url' )
			);
		}

		$user = \sprintf(
			"Compose the briefing for this audience: %s\n\nItems:\n%s",
			$relevance_profile,
			\implode( "\n", $lines )
		);

		return [
			[ 'role' => 'system', 'content' => $system ],
			[ 'role' => 'user', 'content' => $user ],
		];
	}
}

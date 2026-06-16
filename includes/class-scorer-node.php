<?php
/**
 * Scorer_Node: assigns a notional priority score to one item. Knows nothing about sources
 * beyond a per-source weight. The ONE seam a real scorer replaces is score().
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Scorer_Node extends Node {

	/** Per-source base weight; unknown sources score 1.0. */
	private const SOURCE_WEIGHT = [
		'releases'  => 5.0,
		'community' => 3.0,
	];

	/** Title keywords that bump priority, +1.0 each (case-insensitive). */
	private const KEYWORDS = [ 'award', 'launch', 'ships', 'GA', 'million', '10k' ];

	/** relevance_score (0-10) dominates the deterministic scale. */
	private const RELEVANCE_WEIGHT = 1.0;

	/** Max points a brand-new item earns from recency. */
	private const RECENCY_BONUS_MAX = 2.0;

	/** Recency half-life: 7 days, in seconds. */
	private const RECENCY_HALF_LIFE = 604800;

	/**
	 * The ONE seam a real scorer replaces: item -> notional priority score.
	 * Deterministic: source weight + a +1.0 bump per matched title keyword.
	 *
	 * @param array<string,mixed> $item
	 */
	protected function score( array $item ): float {
		$source = \is_string( $item['source'] ?? null ) ? $item['source'] : '';
		$base   = self::SOURCE_WEIGHT[ $source ] ?? 1.0;
		$title  = \is_string( $item['title'] ?? null ) ? $item['title'] : '';
		$bump   = 0.0;
		foreach ( self::KEYWORDS as $kw ) {
			// Whole-word, case-insensitive — so 'GA' doesn't match "Garage" nor 'award' "awarded".
			if ( 1 === \preg_match( '/\b' . \preg_quote( $kw, '/' ) . '\b/i', $title ) ) {
				$bump += 1.0;
			}
		}
		return \round( $base + $bump, 1 );
	}

	/**
	 * Deterministic final score. When the item carries a numeric relevance_score (LLM ran),
	 * combine it with a recency bonus and the source weight. Otherwise fall back to the
	 * keyword/source heuristic above (Summarizer fell back, no relevance_score attached).
	 *
	 * @param array<string,mixed> $item
	 */
	private function compute_score( array $item ): float {
		$rel = $item['relevance_score'] ?? null;
		if ( ! \is_int( $rel ) && ! \is_float( $rel ) ) {
			return $this->score( $item );
		}
		$source = \is_string( $item['source'] ?? null ) ? $item['source'] : '';
		$src    = self::SOURCE_WEIGHT[ $source ] ?? 0.0;
		$ts     = $item['timestamp'] ?? 0;
		$ts     = \is_numeric( $ts ) ? (int) $ts : 0;
		return \round( (float) $rel * self::RELEVANCE_WEIGHT + self::recency_bonus( $ts ) + $src, 2 );
	}

	/** Exponential decay over RECENCY_HALF_LIFE; older items earn less. Uses Core::$now, time() fallback. */
	private static function recency_bonus( int $ts ): float {
		if ( $ts <= 0 ) {
			return 0.0;
		}
		$now = Core::$now > 0.0 ? (int) Core::$now : \time();
		$age = \max( 0, $now - $ts );
		return self::RECENCY_BONUS_MAX * \exp( -$age / self::RECENCY_HALF_LIFE );
	}

	public function fill( array &$message ): void {
		/** @var int $type */
		$type = $message[ Message::TYPE ];
		// Forward control signals (e.g. a source's DONE) unchanged toward the digest.
		if ( $type & Message::TM_INFO ) {
			parent::fill( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$item = $message[ Message::VALUE ];
		if ( ! \is_array( $item ) ) {
			return;
		}
		/** @var array<string,mixed> $item */
		$item['score'] = $this->compute_score( $item );

		$out                   = Message::new_message();
		$out[ Message::TYPE ]  = Message::TM_STRUCT;
		$out[ Message::FROM ]  = $this->name;
		$out[ Message::VALUE ] = $item;
		// parent::fill (base, not $this — would recurse) stamps TO from target, increments the counter, and forwards to sink.
		parent::fill( $out );
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Assigns a notional priority score to one item; source-agnostic.',
			'arguments'    => [],
			'commands'     => [],
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

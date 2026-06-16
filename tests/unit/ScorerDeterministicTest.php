<?php
/**
 * Deterministic Scorer: relevance_score + recency when an item carries a numeric
 * relevance_score; the keyword heuristic when it doesn't. Source-agnostic.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Scorer_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class ScorerDeterministicTest extends TestCase {

	/**
	 * @param array<string,mixed> $v
	 */
	private function score_of( array $v ): float {
		$sink = new Capture_Sink_Node();
		$node = new Scorer_Node();
		$node->sink( $sink );
		$m                     = Message::new_message();
		$m[ Message::TYPE ]    = Message::TM_STRUCT;
		$m[ Message::VALUE ]   = $v;
		$node->fill( $m );
		return $sink->captured[0][ Message::VALUE ]['score'];
	}

	public function test_higher_relevance_scores_higher(): void {
		$now = \time();
		$lo  = $this->score_of( [ 'source' => 'github', 'relevance_score' => 2, 'timestamp' => $now ] );
		$hi  = $this->score_of( [ 'source' => 'github', 'relevance_score' => 9, 'timestamp' => $now ] );
		$this->assertGreaterThan( $lo, $hi );
	}

	public function test_newer_scores_higher_at_equal_relevance(): void {
		$now = \time();
		$old = $this->score_of( [ 'source' => 'github', 'relevance_score' => 5, 'timestamp' => $now - 30 * 86400 ] );
		$new = $this->score_of( [ 'source' => 'github', 'relevance_score' => 5, 'timestamp' => $now ] );
		$this->assertGreaterThan( $old, $new );
	}

	public function test_without_relevance_score_uses_heuristic(): void {
		$s = $this->score_of( [ 'source' => 'github', 'title' => 'launch ships', 'timestamp' => \time() ] );
		$this->assertIsFloat( $s );
		$this->assertGreaterThan( 0.0, $s );
	}

	public function test_forwards_a_done_signal_unchanged(): void {
		$sink = new Capture_Sink_Node();
		$node = new Scorer_Node();
		$node->sink( $sink );

		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::VALUE ] = 'DONE';
		$node->fill( $m );

		$this->assertCount( 1, $sink->captured );
		$this->assertSame( 'DONE', $sink->captured[0][ Message::VALUE ] );
		$this->assertSame( Message::TM_INFO, $sink->captured[0][ Message::TYPE ] & Message::TM_INFO );
	}
}

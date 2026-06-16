<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Summarizer_Node;
use Newspack_AI_Newsletter\Scorer_Node;
use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class SpineTest extends TestCase {

	private function struct( array $value ): array {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $value;
		return $m;
	}

	public function test_summarizer_adds_summary_field(): void {
		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );

		$message = $this->struct( [ 'source' => 'github', 'id' => 'github:x#1', 'title' => 'Roundup ships', 'url' => 'u', 'body' => 'long body text here' ] );
		$node->fill( $message );

		$this->assertCount( 1, $sink->captured );
		$out = $sink->captured[0];
		$this->assertArrayHasKey( 'summary', $out[ Message::VALUE ] );
		$this->assertStringContainsString( 'Roundup ships', $out[ Message::VALUE ]['summary'] );
	}

	public function test_scorer_adds_numeric_score(): void {
		$sink = new Capture_Sink_Node();
		$node = new Scorer_Node();
		$node->sink( $sink );

		$message = $this->struct( [ 'source' => 'github', 'id' => 'github:x#2', 'title' => 'launch', 'url' => 'u', 'body' => 'b' ] );
		$node->fill( $message );

		$this->assertCount( 1, $sink->captured );
		$out = $sink->captured[0];
		$this->assertIsFloat( $out[ Message::VALUE ]['score'] );
		$this->assertGreaterThan( 0.0, $out[ Message::VALUE ]['score'] );
	}

	/**
	 * On the LLM path (item carries a numeric relevance_score) the score is
	 * relevance + recency only — there is no per-source prior, so two items that
	 * differ only by source must score identically.
	 */
	public function test_scorer_blended_score_ignores_source(): void {
		$score_for = function ( string $source ): float {
			$sink = new Capture_Sink_Node();
			$node = new Scorer_Node();
			$node->sink( $sink );
			$m = $this->struct( [ 'source' => $source, 'relevance_score' => 7, 'timestamp' => 1000 ] );
			$node->fill( $m );
			return $sink->captured[0][ Message::VALUE ]['score'];
		};
		$this->assertSame( $score_for( 'github' ), $score_for( 'releases' ) );
	}

	public function test_digest_builder_accumulates_and_flushes_markdown(): void {
		$sink = new Capture_Sink_Node();
		$node = new Digest_Builder_Node();
		$node->sink( $sink );

		foreach ( [ 'a', 'b' ] as $i ) {
			$message = $this->struct( [ 'summary' => "item $i" ] );
			$node->fill( $message );
		}
		// FLUSH is fire-and-forget: a TM_REQUEST (VALUE=FLUSH) handled in fill(), not a cmd verb.
		$flush                   = Message::new_message();
		$flush[ Message::TYPE ]  = Message::TM_REQUEST;
		$flush[ Message::VALUE ] = 'FLUSH';
		$node->fill( $flush );

		$this->assertNotEmpty( $sink->captured );
		$this->assertStringContainsString( '- item a', $sink->captured[0][ Message::VALUE ] );
		$this->assertStringContainsString( '- item b', $sink->captured[0][ Message::VALUE ] );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Composer;
use Newspack_AI_Newsletter\LLM_Client;
use Newspack_Nodes\Tests\TestCase;

/**
 * Digest_Composer is the shared compose core used by both the worker's
 * Digest_Builder FLUSH and the dashboard's `generate` verb, so the two can't
 * drift: every item, ranked by score, through the LLM, ranked-list fallback otherwise.
 */
final class DigestComposerTest extends TestCase {

	/** A test double for the LLM client capturing the prompt it was handed. */
	private function client( string $reply, ?array &$seen = null ): LLM_Client {
		return new class( $reply, $seen ) implements LLM_Client {
			/** @param array<int,array<string,string>>|null $seen */
			public function __construct( private string $reply, private ?array &$seen ) {}
			/** @param array<int,array<string,string>> $messages */
			public function chat( array $messages, array $opts = [] ): string {
				$this->seen = $messages;
				return $this->reply;
			}
		};
	}

	public function test_llm_reply_is_the_digest(): void {
		$draft = Digest_Composer::compose(
			[ [ 'summary' => 'sa', 'score' => 9.0, 'title' => 'A', 'source' => 'github', 'url' => 'http://a' ] ],
			$this->client( "## What mattered\n\nThe big briefing." ),
			'engineers'
		);
		$this->assertStringContainsString( 'The big briefing.', $draft );
	}

	public function test_null_client_falls_back_to_ranked_list(): void {
		$draft = Digest_Composer::compose( [ [ 'summary' => 'sa', 'score' => 9.0 ] ], null, 'p' );
		$this->assertStringContainsString( '- sa', $draft );
	}

	public function test_empty_llm_reply_falls_back_to_ranked_list(): void {
		$draft = Digest_Composer::compose( [ [ 'summary' => 'sa' ] ], $this->client( "   \n  " ), 'p' );
		$this->assertStringContainsString( '- sa', $draft );
	}

	public function test_llm_runtime_failure_falls_back_to_ranked_list(): void {
		$throwing = new class implements LLM_Client {
			/** @param array<int,array<string,string>> $messages */
			public function chat( array $messages, array $opts = [] ): string {
				throw new \RuntimeException( 'rate limited' );
			}
		};
		$draft = Digest_Composer::compose( [ [ 'summary' => 'sa' ] ], $throwing, 'p' );
		$this->assertStringContainsString( '- sa', $draft );
	}

	public function test_sends_top_n_per_source_so_no_source_is_crowded_out(): void {
		$items = [];
		// github: 12 high-scoring items — only its top 10 should reach the prompt.
		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = [ 'summary' => "g$i", 'score' => (float) $i, 'title' => "g$i", 'source' => 'github', 'url' => 'u' ];
		}
		// linear + feed: low-scoring, low-volume — must STILL appear (not crowded out).
		$items[] = [ 'summary' => 'lin-a', 'score' => 0.5, 'title' => 'lin-a', 'source' => 'linear', 'url' => 'u' ];
		$items[] = [ 'summary' => 'feed-x', 'score' => 0.1, 'title' => 'feed-x', 'source' => 'feed', 'url' => 'u' ];

		$seen = null;
		Digest_Composer::compose( $items, $this->client( 'ok', $seen ), 'p' );
		$user = $seen[1]['content'] ?? '';

		// Every source is represented despite github's volume + higher scores.
		$this->assertStringContainsString( 'lin-a ', $user );
		$this->assertStringContainsString( 'feed-x ', $user );
		// github is capped at its top 10 — its two lowest (g1, g2) are dropped.
		$this->assertStringContainsString( 'g12 ', $user );
		$this->assertStringNotContainsString( 'g1 ', $user );
		$this->assertStringNotContainsString( 'g2 ', $user );
	}
}

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

	public function test_every_item_by_score_is_sent_to_the_llm(): void {
		$items = [];
		for ( $i = 0; $i < 15; $i++ ) {
			$items[] = [ 'summary' => "s$i", 'score' => (float) $i, 'title' => "t$i", 'source' => 'x', 'url' => 'u' ];
		}
		$seen = null;
		Digest_Composer::compose( $items, $this->client( 'ok', $seen ), 'p' );

		// Every item lands in the prompt, ranked by score — the highest (t14) first
		// down to the lowest (t0); none are cut.
		$user = $seen[1]['content'] ?? '';
		$this->assertStringContainsString( 't14 ', $user );
		$this->assertStringContainsString( 't0 ', $user );
		$this->assertLessThan( \strpos( $user, 't0 ' ), \strpos( $user, 't14 ' ) );
	}
}

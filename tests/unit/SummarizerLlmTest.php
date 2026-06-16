<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Summarizer_Node;
use Newspack_AI_Newsletter\Proxy_LLM_Client;
use Newspack_AI_Newsletter\LLM_Client;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class SummarizerLlmTest extends TestCase {

	protected function tearDown(): void {
		Summarizer_Node::$llm_factory = null;
		Proxy_LLM_Client::$http_post  = null;
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array<int,mixed>
	 */
	private function struct( array $value ): array {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $value;
		return $m;
	}

	public function test_llm_path_attaches_summary_score_reason(): void {
		Proxy_LLM_Client::$http_post  = static fn () => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) \json_encode(
				[ 'choices' => [ [ 'message' => [ 'content' => '{"summary":"One line.","relevance_score":8,"reason":"on-topic"}' ] ] ] ]
			),
		];
		Summarizer_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' );

		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );
		$message  = $this->struct( [ 'source' => 'github', 'id' => 'g#1', 'title' => 'T', 'body' => 'B' ] );
		$node->fill( $message );

		$out = $sink->captured[0][ Message::VALUE ];
		$this->assertSame( 'One line.', $out['summary'] );
		$this->assertSame( 8, $out['relevance_score'] );
		$this->assertSame( 'on-topic', $out['reason'] );
	}

	public function test_no_client_falls_back_to_heuristic_summary_without_score(): void {
		Summarizer_Node::$llm_factory = static fn (): ?LLM_Client => null;

		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );
		$message  = $this->struct( [ 'source' => 'github', 'id' => 'g#2', 'title' => 'Roundup', 'body' => 'body text' ] );
		$node->fill( $message );

		$out = $sink->captured[0][ Message::VALUE ];
		$this->assertArrayHasKey( 'summary', $out );
		$this->assertStringContainsString( 'Roundup', $out['summary'] );
		$this->assertArrayNotHasKey( 'relevance_score', $out );
	}

	public function test_llm_error_falls_back_without_throwing(): void {
		Proxy_LLM_Client::$http_post  = static fn () => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ];
		Summarizer_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' );

		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );
		$message  = $this->struct( [ 'source' => 'github', 'id' => 'g#3', 'title' => 'X', 'body' => 'b' ] );
		$node->fill( $message );

		$out = $sink->captured[0][ Message::VALUE ];
		$this->assertArrayHasKey( 'summary', $out );
		$this->assertArrayNotHasKey( 'relevance_score', $out );
	}
}

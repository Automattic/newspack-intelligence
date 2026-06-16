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

	/** Read a node's set_state cache (the substrate observability/notify cache). */
	private function set_state_cache( Summarizer_Node $node ): array {
		$ref = new \ReflectionProperty( \Newspack_Nodes\Node::class, 'set_state' );
		$ref->setAccessible( true );
		/** @var array<string,mixed> $cache */
		$cache = $ref->getValue( $node );
		return $cache;
	}

	public function test_publishes_summarized_state_with_via_llm_on_the_llm_path(): void {
		Proxy_LLM_Client::$http_post  = static fn () => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) \json_encode(
				[ 'choices' => [ [ 'message' => [ 'content' => '{"summary":"One line.","relevance_score":8,"reason":"on-topic"}' ] ] ] ]
			),
		];
		Summarizer_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' );

		$node = new Summarizer_Node();
		$node->sink( new Capture_Sink_Node() );
		$message = $this->struct( [ 'source' => 'github', 'id' => 'g#8', 'title' => 'T', 'body' => 'B' ] );
		$node->fill( $message );

		$state = $this->set_state_cache( $node );
		$this->assertSame( 'T', $state['SUMMARIZED'] );
	}

	public function test_publishes_enrich_failed_state_when_the_llm_errors(): void {
		Proxy_LLM_Client::$http_post  = static fn () => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ];
		Summarizer_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' );

		$node = new Summarizer_Node();
		$node->sink( new Capture_Sink_Node() );
		$message = $this->struct( [ 'source' => 'github', 'id' => 'g#9', 'title' => 'X', 'body' => 'b' ] );
		$node->fill( $message );

		$state = $this->set_state_cache( $node );
		$this->assertArrayHasKey( 'FAILED', $state );
		$this->assertSame( 'X', $state['FAILED'] );
	}

	public function test_forwards_a_done_signal_unchanged(): void {
		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );

		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::VALUE ] = 'DONE';
		$node->fill( $m );

		$this->assertCount( 1, $sink->captured );
		$this->assertSame( 'DONE', $sink->captured[0][ Message::VALUE ] );
		$this->assertSame( Message::TM_INFO, $sink->captured[0][ Message::TYPE ] & Message::TM_INFO );
	}

	public function test_strips_the_body_after_summarizing_to_save_downstream_bytes(): void {
		$sink = new Capture_Sink_Node();
		$node = new Summarizer_Node();
		$node->sink( $sink );

		$body    = 'A long release body that nothing past the summarizer needs.';
		$message = $this->struct( [ 'source' => 'github', 'id' => 'g#1', 'title' => 'Big release', 'body' => $body ] );
		$node->fill( $message );

		$out = $sink->captured[0][ Message::VALUE ];
		// The summary was produced from the body, but the body itself is dropped.
		$this->assertArrayNotHasKey( 'body', $out );
		$this->assertArrayHasKey( 'summary', $out );
		$this->assertStringContainsString( 'Big release', $out['summary'] );
	}
}

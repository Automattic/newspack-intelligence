<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\Proxy_LLM_Client;
use Newspack_AI_Newsletter\LLM_Client;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class DigestComposeTest extends TestCase {

	protected function tearDown(): void {
		Digest_Builder_Node::$llm_factory = null;
		Proxy_LLM_Client::$http_post      = null;
	}

	/**
	 * @param array<string,mixed> $v
	 */
	private function feed( Digest_Builder_Node $n, array $v ): void {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $v;
		$n->fill( $m );
	}

	/** Fire a fire-and-forget FLUSH request (TM_REQUEST) — the runtime trigger. */
	private function flush( Digest_Builder_Node $n ): void {
		$r                  = Message::new_message();
		$r[ Message::TYPE ] = Message::TM_REQUEST;
		$r[ Message::KEY ]  = 'FLUSH';
		$n->fill( $r );
	}

	public function test_llm_path_emits_composed_markdown(): void {
		Proxy_LLM_Client::$http_post = static fn () => [
			'response' => [ 'code' => 200 ],
			'body'     => \json_encode(
				[ 'choices' => [ [ 'message' => [ 'content' => "## What mattered\n\nThe big briefing." ] ] ] ]
			),
		];
		Digest_Builder_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' );

		$sink = new Capture_Sink_Node();
		$node = new Digest_Builder_Node();
		$node->sink( $sink );

		$this->feed( $node, [ 'summary' => 'sa', 'score' => 9.0, 'title' => 'A', 'source' => 'github', 'url' => 'http://a' ] );
		$this->flush( $node );

		$this->assertStringContainsString( 'The big briefing.', $sink->captured[0][ Message::VALUE ] );
	}

	public function test_no_client_falls_back_to_ranked_list(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;

		$sink = new Capture_Sink_Node();
		$node = new Digest_Builder_Node();
		$node->sink( $sink );

		$this->feed( $node, [ 'summary' => 'sa', 'score' => 9.0 ] );
		$this->flush( $node );

		$this->assertStringContainsString( '- sa', $sink->captured[0][ Message::VALUE ] );
	}
}

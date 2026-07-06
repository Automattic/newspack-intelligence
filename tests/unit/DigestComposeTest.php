<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\Proxy_LLM_Client;
use Newspack_AI_Newsletter\LLM_Client;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;

final class DigestComposeTest extends TestCase {

	protected function tearDown(): void {
		Digest_Builder_Node::$llm_factory = null;
		Proxy_LLM_Client::$http_post      = null;
		delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
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

	/**
	 * Complete the cycle: the digest auto-composes once every source reports DONE.
	 * Raise the total to one and fire a single DONE to trigger exactly one compose.
	 */
	private function complete( Digest_Builder_Node $n ): void {
		$n->arguments( '1' );
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::FROM ]  = 'src';
		$m[ Message::VALUE ] = 'DONE';
		$n->fill( $m );
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
		$this->complete( $node );

		$this->assertStringContainsString( 'The big briefing.', $sink->captured[0][ Message::VALUE ] );
	}

	public function test_no_client_falls_back_to_ranked_list(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;

		$sink = new Capture_Sink_Node();
		$node = new Digest_Builder_Node();
		$node->sink( $sink );

		$this->feed( $node, [ 'summary' => 'sa', 'score' => 9.0 ] );
		$this->complete( $node );

		$this->assertStringContainsString( '- sa', $sink->captured[0][ Message::VALUE ] );
	}

	public function test_uses_own_verb_configured_client_and_relevance_profile_when_factory_unset(): void {
		update_option(
			Vault::OPTION_KEY,
			[ 'ai-vault' => [ 'id' => 'ai-vault', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token' ] ]
		);
		Vault::get_instance()->reset_cache();

		$captured = [];
		Proxy_LLM_Client::$http_post = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = $args;
			return [
				'response' => [ 'code' => 200 ],
				'body'     => \json_encode(
					[ 'choices' => [ [ 'message' => [ 'content' => '## What mattered' ] ] ] ]
				),
			];
		};

		$node = new Digest_Builder_Node();
		$node->name( 'digest' );
		$node->set_vault_id( 'ai-vault' );
		$node->set_model( 'own-model' );
		$node->set_feature( 'own-feature' );
		$node->add_profile( 'Engineering audience' );
		$node->sink( new Capture_Sink_Node() );

		$this->feed( $node, [ 'summary' => 'sa', 'score' => 9.0, 'title' => 'A', 'source' => 'github', 'url' => 'http://a' ] );
		$this->complete( $node );

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'Bearer proxy-token', $captured[0]['headers']['Authorization'] );
		$this->assertSame( 'own-feature', $captured[0]['headers']['X-WPCOM-AI-Feature'] );
		$this->assertStringContainsString( '"model":"own-model"', $captured[0]['body'] );
		$this->assertStringContainsString( 'Engineering audience', $captured[0]['body'] );
	}

	public function test_falls_back_to_ranked_list_when_no_factory_and_no_vault_configured(): void {
		$sink = new Capture_Sink_Node();
		$node = new Digest_Builder_Node();
		$node->name( 'digest' );
		$node->sink( $sink );

		$this->feed( $node, [ 'summary' => 'sa', 'score' => 9.0 ] );
		$this->complete( $node );

		$this->assertStringContainsString( '- sa', $sink->captured[0][ Message::VALUE ] );
	}
}

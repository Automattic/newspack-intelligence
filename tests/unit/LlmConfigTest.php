<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\LLM_Client;
use Newspack_AI_Newsletter\LLM_Config;
use Newspack_AI_Newsletter\Proxy_LLM_Client;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;

final class LlmConfigTest extends TestCase {

	protected function tearDown(): void {
		delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
	}

	/** Anonymous Node fixture exposing the trait's protected seams publicly, mirroring VaultSecretTest's pattern. */
	private function fixture(): object {
		return new class() extends Node {
			use LLM_Config;

			public function api_url(): string {
				return $this->api_url;
			}

			public function vault_id(): string {
				return $this->vault_id;
			}

			public function model(): string {
				return $this->model;
			}

			public function feature(): string {
				return $this->feature;
			}

			public function client(): ?LLM_Client {
				return $this->make_llm_client();
			}

			public function profile(): string {
				return $this->relevance_profile();
			}
		};
	}

	public function test_defaults_match_the_retired_settings_constants(): void {
		$node = $this->fixture();

		$this->assertSame( 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1', $node->api_url() );
		$this->assertSame( 'gpt-oss-120b', $node->model() );
		$this->assertSame( 'newspack-ai-newsletter', $node->feature() );
		$this->assertSame( '', $node->vault_id() );
		$this->assertSame( '', $node->profile() );
	}

	public function test_set_api_url_mutates_state(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->set_api_url( 'https://proxy.test/v1' ) );
		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
	}

	public function test_set_vault_id_mutates_state(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->set_vault_id( 'ai-vault' ) );
		$this->assertSame( 'ai-vault', $node->vault_id() );
	}

	public function test_set_model_mutates_state(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->set_model( 'gpt-5' ) );
		$this->assertSame( 'gpt-5', $node->model() );
	}

	public function test_set_feature_mutates_state(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->set_feature( 'my-feature' ) );
		$this->assertSame( 'my-feature', $node->feature() );
	}

	public function test_add_profile_appends_and_joins_with_newline(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->add_profile( 'Engineering' ) );
		$this->assertSame( 'ok', $node->add_profile( 'Product' ) );
		$this->assertSame( "Engineering\nProduct", $node->profile() );
	}

	public function test_add_profile_rejects_blank_text(): void {
		$node = $this->fixture();

		$this->assertStringStartsWith( 'error:', $node->add_profile( '   ' ) );
		$this->assertSame( '', $node->profile() );
	}

	public function test_make_llm_client_returns_configured_client_when_vault_resolves(): void {
		update_option(
			Vault::OPTION_KEY,
			[ 'ai-vault' => [ 'id' => 'ai-vault', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token' ] ]
		);
		Vault::get_instance()->reset_cache();

		$node = $this->fixture();
		$node->set_vault_id( 'ai-vault' );

		$this->assertInstanceOf( Proxy_LLM_Client::class, $node->client() );
	}

	public function test_make_llm_client_returns_null_when_api_url_blank(): void {
		update_option(
			Vault::OPTION_KEY,
			[ 'ai-vault' => [ 'id' => 'ai-vault', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token' ] ]
		);
		Vault::get_instance()->reset_cache();

		$node = $this->fixture();
		$node->set_vault_id( 'ai-vault' );
		$node->set_api_url( '' );

		$this->assertNull( $node->client() );
	}

	public function test_make_llm_client_returns_null_when_token_unresolved(): void {
		$node = $this->fixture();
		$node->set_vault_id( 'no-such-id' );

		$this->assertNull( $node->client() );
	}

	public function test_dump_config_round_trips_all_five_verbs(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		$node->set_api_url( 'https://proxy.test/v1' );
		$node->set_vault_id( 'ai-vault' );
		$node->set_model( 'gpt-5' );
		$node->set_feature( 'my-feature' );
		$node->add_profile( 'Engineering' );
		$node->add_profile( 'Product' );

		$dump = $node->dump_config();

		$this->assertStringContainsString( 'cmd llmnode:config set_api_url https://proxy.test/v1', $dump );
		$this->assertStringContainsString( 'cmd llmnode:config set_vault_id ai-vault', $dump );
		$this->assertStringContainsString( 'cmd llmnode:config set_model gpt-5', $dump );
		$this->assertStringContainsString( 'cmd llmnode:config set_feature my-feature', $dump );
		$this->assertStringContainsString( 'cmd llmnode:config add_profile Engineering', $dump );
		$this->assertStringContainsString( 'cmd llmnode:config add_profile Product', $dump );
	}

	public function test_dump_config_omits_untouched_defaults(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );

		$dump = $node->dump_config();

		$this->assertStringNotContainsString( 'set_api_url', $dump );
		$this->assertStringNotContainsString( 'set_model', $dump );
		$this->assertStringNotContainsString( 'set_feature', $dump );
		$this->assertStringNotContainsString( 'set_vault_id', $dump );
		$this->assertStringNotContainsString( 'add_profile', $dump );
	}

	/** Wire a `:config`-style interpreter whose patron is $node, matching the runtime dispatch shape. */
	private function interpreter_for( Node $node ): Command_Interpreter_Node {
		$interpreter = new Command_Interpreter_Node();
		$interpreter->patron( $node );
		return $interpreter;
	}

	public function test_cmd_set_api_url_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_api_url( $this->interpreter_for( $node ), [ 'https://proxy.test/v1' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
	}

	public function test_cmd_set_vault_id_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_vault_id( $this->interpreter_for( $node ), [ 'ai-vault' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'ai-vault', $node->vault_id() );
	}

	public function test_cmd_set_model_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_model( $this->interpreter_for( $node ), [ 'gpt-5' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'gpt-5', $node->model() );
	}

	public function test_cmd_set_feature_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_feature( $this->interpreter_for( $node ), [ 'my-feature' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'my-feature', $node->feature() );
	}

	public function test_cmd_add_profile_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_add_profile( $this->interpreter_for( $node ), [ 'Engineering' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'Engineering', $node->profile() );
	}

	public function test_cmd_add_profile_propagates_blank_error_from_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_add_profile( $this->interpreter_for( $node ), [ '   ' ] );

		$this->assertStringStartsWith( 'error:', $result );
		$this->assertSame( '', $node->profile() );
	}

	public function test_llm_config_command_handlers_dispatch_through_to_patron_state(): void {
		$node        = $this->fixture();
		$interpreter = $this->interpreter_for( $node );
		$handlers    = \array_column( $node::llm_config_commands(), 'handler', 'name' );

		$this->assertSame( 'ok', $handlers['set_api_url']( $interpreter, [ 'https://proxy.test/v1' ] ) );
		$this->assertSame( 'ok', $handlers['set_vault_id']( $interpreter, [ 'ai-vault' ] ) );
		$this->assertSame( 'ok', $handlers['set_model']( $interpreter, [ 'gpt-5' ] ) );
		$this->assertSame( 'ok', $handlers['set_feature']( $interpreter, [ 'my-feature' ] ) );
		$this->assertSame( 'ok', $handlers['add_profile']( $interpreter, [ 'Engineering' ] ) );

		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
		$this->assertSame( 'ai-vault', $node->vault_id() );
		$this->assertSame( 'gpt-5', $node->model() );
		$this->assertSame( 'my-feature', $node->feature() );
		$this->assertSame( 'Engineering', $node->profile() );
	}

	public function test_llm_config_commands_declares_all_five_verbs_and_the_vault_id_type(): void {
		$commands   = $this->fixture()::llm_config_commands();
		$verb_names = \array_column( $commands, 'name' );

		$this->assertSame( [ 'set_api_url', 'set_vault_id', 'set_model', 'set_feature', 'add_profile' ], $verb_names );

		$vault_verb = $commands[ \array_search( 'set_vault_id', $verb_names, true ) ];
		$this->assertSame( 'vault_id', $vault_verb['args'][0]['type'] );

		$profile_verb = $commands[ \array_search( 'add_profile', $verb_names, true ) ];
		$this->assertTrue( $profile_verb['multiple'] );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\LLM_Config;
use Newspack_Intelligence\Proxy_LLM_Client;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Node;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;

final class LlmConfigTest extends TestCase {

	protected function tearDown(): void {
		delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
	}

	/** Anonymous Node fixture exposing the trait's protected seams publicly, wired as Summarizer_Node is. */
	private function fixture(): object {
		return new class() extends Node {
			use Schema_Reflection;
			use LLM_Config;

			public function __construct() {
				parent::__construct();
				$this->auto_wire_interpreter();
			}

			public static function node_schema(): array {
				return [ 'commands' => self::llm_config_commands() ];
			}

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
		$this->assertSame( 'newspack-intelligence', $node->feature() );
		$this->assertSame( '', $node->vault_id() );
		$this->assertSame( '', $node->profile() );
	}

	public function test_set_api_url_mutates_state(): void {
		$node = $this->fixture();

		$this->assertSame( 'ok', $node->set_api_url( 'https://proxy.test/v1' ) );
		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
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

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_profile', [ 'Engineering' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_profile', [ 'Product' ] ) );
		$this->assertSame( "Engineering\nProduct", $node->profile() );
	}

	public function test_add_profile_rejects_blank_text(): void {
		$node = $this->fixture();

		$caught = $this->caught_any( fn () => $node->interpreter()->dispatch( 'add_profile', [ '   ' ] ) );
		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( 'usage: add_profile <text>', $caught->getMessage() );
		$this->assertSame( '', $node->profile() );
	}

	public function test_remove_profile_drops_it_from_the_relevance_profile(): void {
		$node = $this->fixture();
		$node->interpreter()->dispatch( 'add_profile', [ 'Cover', 'heron', 'migrations.' ] );
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_profile', [ 'Cover', 'heron', 'migrations.' ] ) );

		$this->assertSame( 'Skip kite trivia.', $node->profile() );
	}

	public function test_remove_profile_naming_no_profile_is_refused_naming_it(): void {
		$node = $this->fixture();
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );

		$caught = $this->caught_any( fn () => $node->interpreter()->dispatch( 'remove_profile', [ '--text=Cover ghosts.' ] ) );

		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( "remove_profile 'Cover ghosts.' names no profile this node declares", $caught->getMessage() );
		$this->assertSame( 'Skip kite trivia.', $node->profile() );
	}

	public function test_a_removed_profile_leaves_the_dump_and_its_replay(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'add_profile', [ 'Cover heron migrations.' ] );
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );
		$node->interpreter()->dispatch( 'remove_profile', [ 'Cover heron migrations.' ] );

		$dump = $node->dump_config();
		$this->assertStringNotContainsString( 'heron', $dump );

		$replayed = $this->fixture();
		$this->replay_config( $dump, $replayed );
		$this->assertSame( 'Skip kite trivia.', $replayed->profile() );
	}

	/** What $fn threw, whatever its class; the test fails when it throws nothing. */
	private function caught_any( callable $fn ): \Throwable {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( 'nothing was refused' );
	}

	public function test_make_llm_client_returns_configured_client_when_vault_resolves(): void {
		$this->seed_vault( 'ai-vault', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token' ] );

		$node = $this->fixture();
		$node->interpreter()->dispatch( 'set_vault_id', [ 'ai-vault' ] );

		$this->assertInstanceOf( Proxy_LLM_Client::class, $node->client() );
	}

	public function test_make_llm_client_returns_null_when_api_url_blank(): void {
		$this->seed_vault( 'ai-vault', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token' ] );

		$node = $this->fixture();
		$node->interpreter()->dispatch( 'set_vault_id', [ 'ai-vault' ] );
		$node->set_api_url( '' );

		$this->assertNull( $node->client() );
	}

	public function test_make_llm_client_returns_null_when_token_unresolved(): void {
		$node = $this->fixture();
		$node->interpreter()->dispatch( 'set_vault_id', [ 'no-such-id' ] );

		$this->assertNull( $node->client() );
	}

	public function test_dump_config_round_trips_all_five_verbs(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		$node->set_api_url( 'https://proxy.test/v1' );
		$node->interpreter()->dispatch( 'set_vault_id', [ 'ai-vault' ] );
		$node->set_model( 'gpt-5' );
		$node->set_feature( 'my-feature' );
		$node->interpreter()->dispatch( 'add_profile', [ 'Engineering' ] );
		$node->interpreter()->dispatch( 'add_profile', [ 'Product' ] );

		$dump = $node->dump_config();

		$this->assertStringContainsString( 'command_node llmnode:config set_api_url https://proxy.test/v1', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config set_vault_id ai-vault', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config set_model gpt-5', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config set_feature my-feature', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config add_profile Engineering', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config add_profile Product', $dump );
		$replayed = $this->fixture();
		$replayed->name( 'llmnode-replayed' );
		$this->replay_config( $dump, $replayed );
		$this->assertSame( 'ai-vault', $replayed->vault_id() );
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

		$result = $node::cmd_set_api_url( $this->interpreter_for( $node ), [ 'url' => 'https://proxy.test/v1' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
	}

	public function test_cmd_set_model_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_model( $this->interpreter_for( $node ), [ 'model' => 'gpt-5' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'gpt-5', $node->model() );
	}

	public function test_cmd_set_feature_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node::cmd_set_feature( $this->interpreter_for( $node ), [ 'feature' => 'my-feature' ] );

		$this->assertSame( 'ok', $result );
		$this->assertSame( 'my-feature', $node->feature() );
	}

	public function test_the_add_profile_handler_delegates_to_patron(): void {
		$node = $this->fixture();

		$result = $node->interpreter()->commands()['add_profile']( $this->interpreter_for( $node ), [ 'text' => [ 'Engineering' ] ] );

		$this->assertSame( "ok\n", $result );
		$this->assertSame( 'Engineering', $node->profile() );
	}

	public function test_the_add_profile_handler_joins_all_positional_tokens(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		// An unquoted TSL line arrives as many tokens; the profile is all of them.
		$result = $node->interpreter()->commands()['add_profile'](
			$this->interpreter_for( $node ),
			[ 'text' => [ 'Do', 'not', 'produce', 'tables.' ] ]
		);
		$this->assertSame( "ok\n", $result );
		$this->assertSame( 'Do not produce tables.', $node->profile() );
	}

	public function test_dump_config_keeps_an_explicit_set_even_at_the_default_value(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		// Pinned to today's default: a future default bump must not move it.
		$node->set_model( 'gpt-oss-120b' );
		$node->set_feature( 'newspack-intelligence' );
		$dump = $node->dump_config();
		$this->assertStringContainsString( 'command_node llmnode:config set_model gpt-oss-120b', $dump );
		$this->assertStringContainsString( 'command_node llmnode:config set_feature newspack-intelligence', $dump );
	}

	public function test_dump_config_quotes_a_multi_word_profile(): void {
		$node = $this->fixture();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'add_profile', [ 'Do not produce tables.' ] );
		$this->assertStringContainsString(
			"command_node llmnode:config add_profile 'Do not produce tables.'",
			$node->dump_config()
		);
	}

	public function test_the_add_profile_handler_refuses_blank_text(): void {
		$node = $this->fixture();

		$caught = $this->caught_any( fn () => $node->interpreter()->commands()['add_profile']( $this->interpreter_for( $node ), [ 'text' => [ '   ' ] ] ) );
		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( 'usage: add_profile <text>', $caught->getMessage() );
		$this->assertSame( '', $node->profile() );
	}

	public function test_llm_config_command_handlers_dispatch_through_to_patron_state(): void {
		$node        = $this->fixture();
		$interpreter = $this->interpreter_for( $node );
		$handlers    = \array_column( $node::llm_config_commands(), 'handler', 'name' );

		$this->assertSame( 'ok', $handlers['set_api_url']( $interpreter, [ 'url' => 'https://proxy.test/v1' ] ) );
		$this->assertSame( 'ok', $handlers['set_model']( $interpreter, [ 'model' => 'gpt-5' ] ) );
		$this->assertSame( 'ok', $handlers['set_feature']( $interpreter, [ 'feature' => 'my-feature' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->commands()['add_profile']( $interpreter, [ 'text' => [ 'Engineering' ] ] ) );

		$this->assertSame( 'https://proxy.test/v1', $node->api_url() );
		$this->assertSame( 'gpt-5', $node->model() );
		$this->assertSame( 'my-feature', $node->feature() );
		$this->assertSame( 'Engineering', $node->profile() );
	}

	public function test_llm_config_commands_declares_all_six_verbs_and_the_vault_id_type(): void {
		$commands   = $this->fixture()::llm_config_commands();
		$verb_names = \array_column( $commands, 'name' );

		$this->assertSame( [ 'set_api_url', 'set_vault_id', 'set_model', 'set_feature', 'add_profile', 'remove_profile' ], $verb_names );

		$vault_verb = $commands[ \array_search( 'set_vault_id', $verb_names, true ) ];
		$this->assertSame( 'vault_id', $vault_verb['args'][0]['type'] );

		$profile_verb = $commands[ \array_search( 'add_profile', $verb_names, true ) ];
		$this->assertTrue( $profile_verb['multiple'] );
		$this->assertSame( 'profiles', $commands[ \array_search( 'remove_profile', $verb_names, true ) ]['unlist'] );
	}

	/** The using class's factory seam answers before any proxy client is built. */
	public function test_the_factory_seam_answers_first(): void {
		$node   = $this->fixture();
		$canned = new class() implements LLM_Client {
			public function chat( array $messages, array $opts = [] ): string {
				return 'Quarry Ledger canned reply';
			}
		};
		try {
			$node::$llm_factory = static fn (): LLM_Client => $canned;

			$this->assertSame( $canned, $node->client() );
		} finally {
			$node::$llm_factory = null;
		}
	}
}

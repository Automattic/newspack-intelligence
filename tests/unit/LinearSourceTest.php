<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Linear_Source_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class LinearSourceTest extends TestCase {

	protected function tearDown(): void {
		Linear_Source_Node::$http_post = null;
		delete_option( 'newspack_nodes_vault' );
		Vault::get_instance()->reset_cache();
	}

	/** A canned 200 with two issue nodes in the GraphQL response shape. */
	private function stub_linear(): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ): array {
			$body = [
				'data' => [
					'issues' => [
						'nodes' => [
							[ 'identifier' => 'ABC-123', 'title' => 'First issue', 'url' => 'https://linear.app/abc-123', 'description' => 'first body', 'updatedAt' => '2026-06-12T00:00:00Z' ],
							[ 'identifier' => 'ABC-124', 'title' => 'Second issue', 'url' => 'https://linear.app/abc-124', 'description' => 'second body', 'updatedAt' => '2026-06-13T00:00:00Z' ],
						],
					],
				],
			];
			return [ 'response' => [ 'code' => 200 ], 'body' => (string) \json_encode( $body ) ];
		};
	}

	/** @return array<string,array<string,mixed>> items keyed by id */
	private function fetch_by_id( array $config ): array {
		$node  = new Linear_Source_Node();
		$items = $node->fetch( $config );
		$out   = [];
		foreach ( $items as $item ) {
			$out[ $item['id'] ] = $item;
		}
		return $out;
	}

	public function test_fetch_normalizes_issue_nodes_into_items(): void {
		$this->stub_linear();
		$by = $this->fetch_by_id( [ 'token' => 'lin_secret' ] );

		$this->assertArrayHasKey( 'linear:ABC-123', $by );
		$this->assertArrayHasKey( 'linear:ABC-124', $by );

		$item = $by['linear:ABC-123'];
		$this->assertSame( 'linear', $item['source'] );
		$this->assertSame( 'First issue', $item['title'] );
		$this->assertSame( 'https://linear.app/abc-123', $item['url'] );
		$this->assertSame( 'first body', $item['body'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $item['timestamp'] );
	}

	public function test_fetch_sends_raw_token_as_authorization_header(): void {
		$captured = [];
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = $args;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"issues":{"nodes":[]}}}' ];
		};
		$node = new Linear_Source_Node();
		$node->fetch( [ 'token' => 'lin_secret' ] );

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'lin_secret', $captured[0]['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'Bearer', $captured[0]['headers']['Authorization'] );
	}

	public function test_fetch_returns_empty_and_makes_no_call_when_token_blank(): void {
		$called = false;
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$called ): array {
			$called = true;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'token' => '' ] ) );
		$this->assertFalse( $called );
	}

	public function test_fetch_returns_empty_on_wp_error_without_throwing(): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ): mixed {
			return new \WP_Error( 'http', 'boom' );
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'token' => 'lin_secret' ] ) );
	}

	public function test_fetch_returns_empty_on_non_200(): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ): array {
			return [ 'response' => [ 'code' => 401 ], 'body' => '' ];
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'token' => 'lin_secret' ] ) );
	}

	public function test_tick_reads_token_from_verb_state(): void {
		update_option(
			'newspack_nodes_vault',
			[ 'lin-creds' => [ 'id' => 'lin-creds', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'lin_from_vault' ] ]
		);
		Vault::get_instance()->reset_cache();
		$captured = [];
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"issues":{"nodes":[]}}}' ];
		};

		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->set_vault_id( 'lin-creds' );
		$node->sink( new Capture_Sink_Node() );
		$message                  = Message::new_message();
		$message[ Message::TYPE ] = Message::TM_REQUEST;
		$node->fill( $message );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://api.linear.app/graphql', $captured[0]['url'] );
		$this->assertSame( 'lin_from_vault', $captured[0]['args']['headers']['Authorization'] );
	}

	public function test_set_vault_id_resolves_seeded_entry_into_config_token(): void {
		update_option(
			'newspack_nodes_vault',
			[ 'lin-creds' => [ 'id' => 'lin-creds', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'lin_from_vault' ] ]
		);
		Vault::get_instance()->reset_cache();
		$node = new Linear_Source_Node();
		$node->name( 'linear' );

		$this->assertSame( 'ok', $node->set_vault_id( 'lin-creds' ) );
		$this->assertSame( 'lin_from_vault', $this->config_of( $node )['token'] );
	}

	public function test_set_vault_id_unknown_id_yields_empty_token(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );

		$node->set_vault_id( 'no-such-id' );
		$this->assertSame( '', $this->config_of( $node )['token'] );
	}

	public function test_set_vault_id_last_write_wins(): void {
		update_option(
			'newspack_nodes_vault',
			[
				'first'  => [ 'id' => 'first', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'first-secret' ],
				'second' => [ 'id' => 'second', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'second-secret' ],
			]
		);
		Vault::get_instance()->reset_cache();
		$node = new Linear_Source_Node();
		$node->name( 'linear' );

		$node->set_vault_id( 'first' );
		$node->set_vault_id( 'second' );

		$this->assertSame( 'second-secret', $this->config_of( $node )['token'] );
	}

	public function test_dump_config_re_emits_set_vault_id(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->set_vault_id( 'lin-creds' );

		$this->assertStringContainsString( 'cmd linear:config set_vault_id lin-creds', $node->dump_config() );
	}

	public function test_node_schema_declares_linear_source_contract(): void {
		$schema = Linear_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'Linear issues', $schema['description'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'set_vault_id', $verb_names );
		$vault_id_verb = $schema['commands'][ \array_search( 'set_vault_id', $verb_names, true ) ];
		$this->assertSame( 'vault_id', $vault_id_verb['args'][0]['type'] );
	}

	/** config() is protected — read it via reflection, matching SettingsSyncNodeTest's registry-access pattern. */
	private function config_of( Linear_Source_Node $node ): array {
		$method = new \ReflectionMethod( $node, 'config' );
		$method->setAccessible( true );
		return $method->invoke( $node );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Linear_Source_Node;
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

	/**
	 * Capture each request's args into $captured and answer an empty page.
	 *
	 * @param list<array{url:string,args:array<string,mixed>}> $captured
	 */
	private function capture_requests( array &$captured ): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '{"data":{"issues":{"nodes":[]}}}' ];
		};
	}

	/** @return array<string,array<string,mixed>> items keyed by item_key */
	private function fetch_by_id( array $config ): array {
		$node  = new Linear_Source_Node();
		$items = $node->fetch( $config );
		$out   = [];
		foreach ( $items as $item ) {
			$out[ $item['item_key'] ] = $item;
		}
		return $out;
	}

	public function test_fetch_normalizes_issue_nodes_into_items(): void {
		$this->stub_linear();
		$by = $this->fetch_by_id( [ 'teams' => [ 'KESTREL' ], 'token' => 'lin_secret' ] );

		$this->assertArrayHasKey( 'linear:ABC-123', $by );
		$this->assertArrayHasKey( 'linear:ABC-124', $by );

		$item = $by['linear:ABC-123'];
		$this->assertSame( 'linear', $item['source'] );
		$this->assertSame( 'First issue', $item['title'] );
		$this->assertSame( 'https://linear.app/abc-123', $item['url'] );
		$this->assertSame( 'first body', $item['body'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $item['item_ts'] );
	}

	public function test_fetch_sends_raw_token_as_authorization_header(): void {
		$captured = [];
		$this->capture_requests( $captured );
		$node = new Linear_Source_Node();
		$node->fetch( [ 'teams' => [ 'KESTREL' ], 'token' => 'lin_secret' ] );

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'lin_secret', $captured[0]['args']['headers']['Authorization'] );
		$this->assertStringNotContainsString( 'Bearer', $captured[0]['args']['headers']['Authorization'] );
	}

	/** The keys ride as a GraphQL variable, never spliced into the query text. */
	public function test_fetch_filters_issues_by_the_teams_through_a_variable(): void {
		$captured = [];
		$this->capture_requests( $captured );
		$node = new Linear_Source_Node();
		$node->fetch( [ 'teams' => [ 'KESTREL', 'WREN9' ], 'token' => 'lin_secret' ] );

		$this->assertCount( 1, $captured );
		$body = \json_decode( $captured[0]['args']['body'], true );
		$this->assertSame( [ 'teams' => [ 'KESTREL', 'WREN9' ] ], $body['variables'] );
		$this->assertStringContainsString( '$teams: [String!]', $body['query'] );
		$this->assertStringContainsString( 'issues(first: 30, orderBy: updatedAt, filter: { team: { key: { in: $teams } } })', $body['query'] );
		$this->assertStringNotContainsString( 'KESTREL', $body['query'] );
	}

	public function test_fetch_returns_empty_and_makes_no_call_with_no_team(): void {
		$called = false;
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$called ): array {
			$called = true;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'teams' => [], 'token' => 'lin_secret' ] ) );
		$this->assertFalse( $called );
	}

	public function test_fetch_returns_empty_and_makes_no_call_when_token_blank(): void {
		$called = false;
		Linear_Source_Node::$http_post = static function ( string $url, array $args ) use ( &$called ): array {
			$called = true;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'teams' => [ 'KESTREL' ], 'token' => '' ] ) );
		$this->assertFalse( $called );
	}

	public function test_fetch_returns_empty_on_wp_error_without_throwing(): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ): mixed {
			return new \WP_Error( 'http', 'boom' );
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'teams' => [ 'KESTREL' ], 'token' => 'lin_secret' ] ) );
	}

	public function test_fetch_returns_empty_on_non_200(): void {
		Linear_Source_Node::$http_post = static function ( string $url, array $args ): array {
			return [ 'response' => [ 'code' => 401 ], 'body' => '' ];
		};
		$node = new Linear_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'teams' => [ 'KESTREL' ], 'token' => 'lin_secret' ] ) );
	}

	public function test_tick_reads_teams_and_token_from_verb_state(): void {
		$this->seed_vault( 'lin-creds', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'lin_from_vault' ] );
		$captured = [];
		$this->capture_requests( $captured );

		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );
		$node->interpreter()->dispatch( 'set_vault_id', [ 'lin-creds' ] );
		$this->tick( $node );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://api.linear.app/graphql', $captured[0]['url'] );
		$this->assertSame( 'lin_from_vault', $captured[0]['args']['headers']['Authorization'] );
		$this->assertSame( [ 'teams' => [ 'KESTREL' ] ], \json_decode( $captured[0]['args']['body'], true )['variables'] );
	}

	public function test_add_team_accumulates_ordered_teams_and_ignores_a_repeat(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] ) );

		$this->assertSame( [ 'KESTREL', 'WREN9' ], $this->config_of( $node )['teams'] );
	}

	public function test_remove_team_stops_fetching_it_and_keeps_the_rest(): void {
		$captured = [];
		$this->capture_requests( $captured );
		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] );
		$node->interpreter()->dispatch( 'set_vault_id', [ 'lin-creds' ] );
		$this->seed_vault( 'lin-creds', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'lin_from_vault' ] );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_team', [ 'KESTREL' ] ) );
		$this->tick( $node );

		$this->assertSame( [ 'teams' => [ 'WREN9' ] ], \json_decode( $captured[0]['args']['body'], true )['variables'] );
	}

	public function test_remove_team_naming_no_team_is_refused_naming_it(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] );

		try {
			$node->interpreter()->dispatch( 'remove_team', [ 'GHOST' ] );
			$this->fail( 'an unregistered team was removed' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( "remove_team 'GHOST' names no team this node declares", $e->getMessage() );
		}
		$this->assertSame( [ 'WREN9' ], $this->config_of( $node )['teams'] );
	}

	public function test_teams_and_vault_id_round_trip_through_the_dump(): void {
		$this->seed_vault( 'lin-creds', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'lin_from_vault' ] );
		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->interpreter()->dispatch( 'set_vault_id', [ 'lin-creds' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'HERON' ] );
		$node->interpreter()->dispatch( 'remove_team', [ 'WREN9' ] );
		$dump = $node->dump_config();

		$replayed = new Linear_Source_Node();
		$replayed->name( 'linear-replayed' );
		$this->replay_config( $dump, $replayed );

		$this->assertStringContainsString( 'command_node linear:config set_vault_id lin-creds', $dump );
		$this->assertStringContainsString( 'command_node linear:config add_team KESTREL', $dump );
		$this->assertStringNotContainsString( 'WREN9', $dump );
		$this->assertSame( [ 'teams' => [ 'KESTREL', 'HERON' ], 'token' => 'lin_from_vault' ], $this->config_of( $replayed ) );
	}

	public function test_node_schema_declares_linear_source_contract(): void {
		$schema = Linear_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'Linear issues', $schema['description'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'add_team', $verb_names );
		$this->assertContains( 'remove_team', $verb_names );
		$this->assertContains( 'set_vault_id', $verb_names );
		$vault_id_verb = $schema['commands'][ \array_search( 'set_vault_id', $verb_names, true ) ];
		$this->assertSame( 'vault_id', $vault_id_verb['args'][0]['type'] );
	}

	/** Send the node a TICK request, as the worker's trigger does. */
	private function tick( Linear_Source_Node $node ): void {
		$node->arguments( [ 'internal' ] );
		$node->sink( new Capture_Sink_Node() );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::VALUE ] = 'TICK';
		$node->fill( $message );
	}

	/** config() is protected — read it via reflection, matching SettingsSyncNodeTest's registry-access pattern. */
	private function config_of( Linear_Source_Node $node ): array {
		$method = new \ReflectionMethod( $node, 'config' );
		return $method->invoke( $node );
	}
}

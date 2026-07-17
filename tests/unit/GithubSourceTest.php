<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Github_Source_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class GithubSourceTest extends TestCase {

	protected function tearDown(): void {
		Github_Source_Node::$http_get = null;
		delete_option( 'newspack_nodes_vault' );
		Vault::get_instance()->reset_cache();
	}

	/** Route a canned JSON body by which GitHub endpoint the URL hits. */
	private function stub_github(): void {
		Github_Source_Node::$http_get = static function ( string $url, array $args ): array {
			if ( false !== \strpos( $url, '/releases' ) ) {
				$body = [
					[ 'id' => 11, 'name' => 'v2.0', 'tag_name' => 'v2.0', 'html_url' => 'https://gh/r/releases/11', 'body' => 'release notes', 'published_at' => '2026-06-10T00:00:00Z' ],
				];
			} elseif ( false !== \strpos( $url, '/pulls' ) ) {
				$body = [
					[ 'number' => 5, 'title' => 'Merged PR', 'html_url' => 'https://gh/r/pull/5', 'body' => 'pr body', 'merged_at' => '2026-06-11T00:00:00Z' ],
					[ 'number' => 6, 'title' => 'Closed-not-merged', 'html_url' => 'https://gh/r/pull/6', 'body' => 'x', 'merged_at' => null ],
				];
			} else { // /issues
				$body = [
					[ 'number' => 7, 'title' => 'Real issue', 'html_url' => 'https://gh/r/issues/7', 'body' => 'issue body', 'updated_at' => '2026-06-12T00:00:00Z' ],
					[ 'number' => 5, 'title' => 'PR masquerading as issue', 'html_url' => 'https://gh/r/pull/5', 'body' => 'x', 'updated_at' => '2026-06-11T00:00:00Z', 'pull_request' => [ 'url' => 'https://gh/r/pull/5' ] ],
				];
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => (string) \json_encode( $body ) ];
		};
	}

	/** @return array<string,array<string,mixed>> items keyed by id */
	private function fetch_by_id( array $config ): array {
		$node  = new Github_Source_Node();
		$items = $node->fetch( $config );
		$out   = [];
		foreach ( $items as $item ) {
			$out[ $item['id'] ] = $item;
		}
		return $out;
	}

	public function test_fetch_normalizes_releases_merged_prs_and_issues(): void {
		$this->stub_github();
		$by = $this->fetch_by_id( [ 'repos' => [ 'owner/repo' ], 'token' => '' ] );

		$this->assertArrayHasKey( 'github:owner/repo#release-11', $by );
		$this->assertArrayHasKey( 'github:owner/repo#pr-5', $by );
		$this->assertArrayHasKey( 'github:owner/repo#issue-7', $by );

		// Closed-not-merged PR is excluded; an issues-endpoint entry that's really a
		// PR (has pull_request) is dropped, so it can't double up with the merged PR.
		$this->assertArrayNotHasKey( 'github:owner/repo#pr-6', $by );
		$this->assertArrayNotHasKey( 'github:owner/repo#issue-5', $by );

		$release = $by['github:owner/repo#release-11'];
		$this->assertSame( 'github', $release['source'] );
		$this->assertSame( 'v2.0', $release['title'] );
		$this->assertSame( 'https://gh/r/releases/11', $release['url'] );
		$this->assertSame( 'release notes', $release['body'] );
		$this->assertSame( \strtotime( '2026-06-10T00:00:00Z' ), $release['timestamp'] );

		$issue = $by['github:owner/repo#issue-7'];
		$this->assertSame( 'Real issue', $issue['title'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $issue['timestamp'] );
	}

	public function test_fetch_sends_bearer_auth_and_useragent_when_token_set(): void {
		$captured = [];
		Github_Source_Node::$http_get = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = $args;
			return [ 'response' => [ 'code' => 200 ], 'body' => '[]' ];
		};
		$node = new Github_Source_Node();
		$node->fetch( [ 'repos' => [ 'owner/repo' ], 'token' => 'ghp_secret' ] );

		$this->assertNotEmpty( $captured );
		$this->assertSame( 'Bearer ghp_secret', $captured[0]['headers']['Authorization'] );
		$this->assertArrayHasKey( 'User-Agent', $captured[0]['headers'] );
	}

	public function test_fetch_omits_auth_header_when_no_token(): void {
		$captured = [];
		Github_Source_Node::$http_get = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = $args;
			return [ 'response' => [ 'code' => 200 ], 'body' => '[]' ];
		};
		$node = new Github_Source_Node();
		$node->fetch( [ 'repos' => [ 'owner/repo' ], 'token' => '' ] );

		$this->assertArrayNotHasKey( 'Authorization', $captured[0]['headers'] );
	}

	public function test_fetch_skips_a_repo_endpoint_that_errors_without_throwing(): void {
		Github_Source_Node::$http_get = static function ( string $url, array $args ): mixed {
			if ( false !== \strpos( $url, '/releases' ) ) {
				return new \WP_Error( 'http', 'boom' );
			}
			if ( false !== \strpos( $url, '/pulls' ) ) {
				return [ 'response' => [ 'code' => 500 ], 'body' => '' ];
			}
			return [ 'response' => [ 'code' => 200 ], 'body' => '[]' ];
		};
		$node = new Github_Source_Node();
		// Must not throw; a failed endpoint just contributes no items.
		$this->assertSame( [], $node->fetch( [ 'repos' => [ 'owner/repo' ], 'token' => '' ] ) );
	}

	public function test_fetch_returns_empty_when_no_repos_configured(): void {
		$node = new Github_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'repos' => [], 'token' => '' ] ) );
	}

	public function test_tick_reads_repos_and_token_from_verb_state(): void {
		update_option(
			'newspack_nodes_vault',
			[ 'gh-creds' => [ 'id' => 'gh-creds', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'ghp_from_vault' ] ]
		);
		Vault::get_instance()->reset_cache();
		$captured = [];
		Github_Source_Node::$http_get = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => '[]' ];
		};

		$node = new Github_Source_Node();
		$node->name( 'github' );
		$node->add_repo( 'owner/repo' );
		$node->set_vault_id( 'gh-creds' );
		$node->sink( new Capture_Sink_Node() );
		$message                  = Message::new_message();
		$message[ Message::TYPE ] = Message::TM_REQUEST;
		$node->fill( $message );

		$this->assertCount( 3, $captured );
		$this->assertStringContainsString( '/repos/owner/repo/releases', $captured[0]['url'] );
		$this->assertSame( 'Bearer ghp_from_vault', $captured[0]['args']['headers']['Authorization'] );
	}

	public function test_add_repo_accumulates_ordered_repos_into_config(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );

		$this->assertSame( 'ok', $node->add_repo( 'owner/repo-a' ) );
		$this->assertSame( 'ok', $node->add_repo( 'owner/repo-b' ) );

		$this->assertSame( [ 'owner/repo-a', 'owner/repo-b' ], $this->config_of( $node )['repos'] );
	}

	public function test_set_vault_id_resolves_seeded_entry_into_config_token(): void {
		update_option(
			'newspack_nodes_vault',
			[ 'gh-creds' => [ 'id' => 'gh-creds', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'ghp_from_vault' ] ]
		);
		Vault::get_instance()->reset_cache();
		$node = new Github_Source_Node();
		$node->name( 'github' );

		$node->set_vault_id( 'gh-creds' );
		$this->assertSame( 'ghp_from_vault', $this->config_of( $node )['token'] );
	}

	public function test_set_vault_id_unknown_id_yields_empty_token(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );

		$node->set_vault_id( 'no-such-id' );
		$this->assertSame( '', $this->config_of( $node )['token'] );
	}

	public function test_dump_config_re_emits_add_repo_and_set_vault_id(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );
		$node->add_repo( 'owner/repo-a' );
		$node->add_repo( 'owner/repo-b' );
		$node->set_vault_id( 'gh-creds' );

		$dump = $node->dump_config();
		$this->assertStringContainsString( 'cmd github:config add_repo owner/repo-a', $dump );
		$this->assertStringContainsString( 'cmd github:config add_repo owner/repo-b', $dump );
		$this->assertStringContainsString( 'cmd github:config set_vault_id gh-creds', $dump );
	}

	public function test_node_schema_declares_github_source_contract(): void {
		$schema = Github_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'GitHub Releases', $schema['description'] );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'add_repo', $verb_names );
		$this->assertContains( 'set_vault_id', $verb_names );
		$vault_id_verb = $schema['commands'][ \array_search( 'set_vault_id', $verb_names, true ) ];
		$this->assertSame( 'vault_id', $vault_id_verb['args'][0]['type'] );
	}

	/** config() is protected — read it via reflection, matching SettingsSyncNodeTest's registry-access pattern. */
	private function config_of( Github_Source_Node $node ): array {
		$method = new \ReflectionMethod( $node, 'config' );
		$method->setAccessible( true );
		return $method->invoke( $node );
	}
}

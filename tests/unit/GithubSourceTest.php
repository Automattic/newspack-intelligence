<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Github_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The GitHub source on the chain: per repo, releases, closed pull requests
 * and issues, one request at a time, through its Curl sibling under its
 * vault group.
 */
final class GithubSourceTest extends TestCase {
	use Drives_Curl;

	private const HERON = 'https://api.github.com/repos/acme/heron-repo/';

	protected function setUp(): void {
		parent::setUp();
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A GitHub source as the collect topology builds it.
	 *
	 * @param list<string> $args  The make_node arguments.
	 * @param string       ...$repos The repos it fetches.
	 */
	private function github( array $args, string ...$repos ): Github_Source_Node {
		$node = $this->wire( new Github_Source_Node(), 'github', $args );
		foreach ( $repos as $repo ) {
			$node->interpreter()->dispatch( 'add_repo', [ $repo ] );
		}
		return $node;
	}

	/** The three endpoint urls of a repo, in the order a pass fetches them. */
	private static function endpoints( string $repo ): array {
		return [
			"https://api.github.com/repos/{$repo}/releases?per_page=30",
			"https://api.github.com/repos/{$repo}/pulls?state=closed&sort=updated&direction=desc&per_page=30",
			"https://api.github.com/repos/{$repo}/issues?state=all&sort=updated&direction=desc&per_page=30",
		];
	}

	/** A pass GETs each repo's releases, closed pull requests and issues, 30 a page, one at a time. */
	public function test_a_pass_fetches_three_endpoints_per_repo_one_at_a_time(): void {
		$node = $this->github( [ 'public' ], 'acme/heron-repo', 'acme/kite-repo' );
		$urls = [ ...self::endpoints( 'acme/heron-repo' ), ...self::endpoints( 'acme/kite-repo' ) ];

		$this->tick( $node );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 6 ] ] ], $this->answers() );

		foreach ( $urls as $i => $url ) {
			$this->assertSame( \array_slice( $urls, 0, $i + 1 ), $this->dispatched );
			$this->assertTrue( $this->opts[ $url ][ \CURLOPT_HTTPGET ] );
			$this->complete( $url, '[]' );
		}
		$this->assertSame( $urls, $this->dispatched );
	}

	/** Each endpoint's answer maps to items as it lands: a release, a merged PR, an issue; never an unmerged PR or a PR the issues list repeats. */
	public function test_answers_normalize_releases_merged_prs_and_issues(): void {
		$node = $this->github( [ 'public' ], 'acme/heron-repo' );
		$this->tick( $node );
		[ $releases, $pulls, $issues ] = self::endpoints( 'acme/heron-repo' );

		$this->complete(
			$releases,
			(string) \json_encode(
				[
					[ 'id' => 11, 'name' => 'v2.0', 'tag_name' => 'v2.0', 'html_url' => 'https://gh/r/releases/11', 'body' => 'release notes', 'published_at' => '2026-06-10T00:00:00Z' ],
					[ 'id' => 12, 'name' => '', 'tag_name' => 'v2.1-tag', 'html_url' => 'https://gh/r/releases/12', 'body' => '', 'published_at' => '2026-06-14T00:00:00Z' ],
				]
			)
		);
		$this->complete(
			$pulls,
			(string) \json_encode(
				[
					[ 'number' => 5, 'title' => 'Merged PR', 'html_url' => 'https://gh/r/pull/5', 'body' => 'pr body', 'merged_at' => '2026-06-11T00:00:00Z' ],
					[ 'number' => 6, 'title' => 'Closed-not-merged', 'html_url' => 'https://gh/r/pull/6', 'body' => 'x', 'merged_at' => null ],
				]
			)
		);
		$this->complete(
			$issues,
			(string) \json_encode(
				[
					[ 'number' => 7, 'title' => 'Real issue', 'html_url' => 'https://gh/r/issues/7', 'body' => 'issue body', 'updated_at' => '2026-06-12T00:00:00Z' ],
					[ 'number' => 5, 'title' => 'PR masquerading as issue', 'html_url' => 'https://gh/r/pull/5', 'body' => 'x', 'updated_at' => '2026-06-11T00:00:00Z', 'pull_request' => [ 'url' => 'https://gh/r/pull/5' ] ],
				]
			)
		);

		$by = $this->recorded();
		$this->assertSame( [ 'github:acme/heron-repo#release-11', 'github:acme/heron-repo#release-12', 'github:acme/heron-repo#pr-5', 'github:acme/heron-repo#issue-7' ], \array_keys( $by ) );
		$release = $by['github:acme/heron-repo#release-11'];
		$this->assertSame( 'github', $release['source'] );
		$this->assertSame( 'v2.0', $release['title'] );
		$this->assertSame( 'https://gh/r/releases/11', $release['url'] );
		$this->assertSame( 'release notes', $release['body'] );
		$this->assertSame( \strtotime( '2026-06-10T00:00:00Z' ), $release['item_ts'] );
		$this->assertSame( 'v2.1-tag', $by['github:acme/heron-repo#release-12']['title'], 'a nameless release takes its tag' );
		$this->assertSame( \strtotime( '2026-06-11T00:00:00Z' ), $by['github:acme/heron-repo#pr-5']['item_ts'], 'a PR dates from its merge' );
		$issue = $by['github:acme/heron-repo#issue-7'];
		$this->assertSame( 'Real issue', $issue['title'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $issue['item_ts'] );
	}

	/** An endpoint that errors records nothing, and the pass goes on to the next. */
	public function test_an_endpoint_that_errors_records_nothing_and_the_pass_goes_on(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->github( [ 'public' ], 'acme/heron-repo' );
		$this->tick( $node );
		[ $releases, $pulls, $issues ] = self::endpoints( 'acme/heron-repo' );

		$this->complete( $releases, '', 502 );
		$this->complete( $pulls, '{"message":"not a list"', 200 );
		$this->complete( $issues, (string) \json_encode( [ [ 'number' => 8, 'title' => 'Still here', 'html_url' => 'https://gh/r/issues/8', 'updated_at' => '2026-06-13T00:00:00Z' ] ] ) );

		$this->assertSame( [ 'github:acme/heron-repo#issue-8' ], \array_keys( $this->recorded() ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, "github: a fetch failed: HTTP 502 {$releases}" ) );
	}

	/** The vault group the make_node line names lends every request GitHub's token, as its entry names the header. */
	public function test_the_vault_group_sends_the_github_token(): void {
		$this->seed_vault(
			'github',
			[
				'url'           => 'https://api.github.com',
				'group'         => 'github-7731',
				'auth_password' => 'ghp_vault_5512',
				'auth_header'   => 'Authorization',
				'auth_scheme'   => 'Bearer',
			]
		);
		$node = $this->github( [ 'public', 'github-7731' ], 'acme/heron-repo' );

		$this->tick( $node );

		$this->assertContains( 'Authorization: Bearer ghp_vault_5512', $this->opts[ self::HERON . 'releases?per_page=30' ][ \CURLOPT_HTTPHEADER ] );
		$this->assertMatchesRegularExpression( '/^make_node \S+ github public github-7731$/m', $node->dump_config() );
	}

	public function test_no_repo_fetches_nothing(): void {
		$this->tick( $this->github( [ 'public' ] ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
	}

	/** A repo is refused unless it is one owner/name pair, naming the value; nothing is added. */
	public function test_add_repo_refuses_anything_but_owner_and_name(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );

		foreach ( [ '   ', 'acme', 'acme/heron/extra', 'acme/heron repo', 'acme/heron?per_page=99', '/heron-repo' ] as $repo ) {
			try {
				$node->interpreter()->dispatch( 'add_repo', [ $repo ] );
				$this->fail( "add_repo accepted {$repo}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "add_repo: '{$repo}' is not a GitHub owner/name", $e->getMessage() );
			}
		}
		$this->assertSame( [], $this->read_private( $node, 'repos' ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_repo', [ 'Acme_9/heron.repo-2' ] ) );
		$this->assertSame( [ 'Acme_9/heron.repo-2' ], $this->read_private( $node, 'repos' ) );
	}

	/** A repeated add_repo keeps one entry. */
	public function test_add_repo_ignores_a_repeat(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );

		$node->interpreter()->dispatch( 'add_repo', [ 'acme/heron-repo' ] );
		$node->interpreter()->dispatch( 'add_repo', [ 'acme/heron-repo' ] );

		$this->assertSame( [ 'acme/heron-repo' ], $this->read_private( $node, 'repos' ) );
	}

	/** A body that is not a JSON list, a JSON object of release-shaped rows among them, records nothing. */
	public function test_a_body_that_is_not_a_list_records_nothing(): void {
		$node = $this->github( [ 'public' ], 'acme/heron-repo' );
		$this->tick( $node );

		$this->complete( self::endpoints( 'acme/heron-repo' )[0], (string) \json_encode( [ 'first' => [ 'id' => 91, 'name' => 'v9.1', 'html_url' => 'https://gh/r/releases/91', 'published_at' => '2026-06-15T00:00:00Z' ] ] ) );

		$this->assertSame( [], $this->recorded() );
	}

	public function test_add_repo_accumulates_ordered_repos(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_repo', [ 'owner/repo-a' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_repo', [ 'owner/repo-b' ] ) );

		$this->assertSame( [ 'owner/repo-a', 'owner/repo-b' ], $this->read_private( $node, 'repos' ) );
	}

	public function test_repos_round_trip_through_the_dump(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );
		$node->interpreter()->dispatch( 'add_repo', [ 'owner/repo-a' ] );
		$node->interpreter()->dispatch( 'add_repo', [ 'owner/repo-b' ] );
		$dump = $node->dump_config();

		$replayed = new Github_Source_Node();
		$replayed->name( 'github-replayed' );
		$this->replay_config( $dump, $replayed );

		$this->assertSame( [ 'owner/repo-a', 'owner/repo-b' ], $this->read_private( $replayed, 'repos' ) );
	}

	public function test_node_schema_declares_github_source_contract(): void {
		$schema = Github_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'GitHub Releases', $schema['description'] );
		$this->assertSame( [ 'tier', 'vault_group' ], \array_column( $schema['arguments'], 'name' ) );
		$this->assertContains( 'add_repo', \array_column( $schema['commands'], 'name' ) );
	}

	public function test_remove_repo_stops_fetching_it_and_keeps_the_rest(): void {
		$node = $this->github( [ 'public' ], 'acme/heron-repo', 'acme/kite-repo' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_repo', [ 'acme/heron-repo' ] ) );
		$this->tick( $node );

		$this->assertSame( [ self::endpoints( 'acme/kite-repo' )[0] ], $this->dispatched );
	}

	public function test_remove_repo_naming_no_repo_is_refused_naming_it(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );
		$node->interpreter()->dispatch( 'add_repo', [ 'acme/kite-repo' ] );

		try {
			$node->interpreter()->dispatch( 'remove_repo', [ 'acme/ghost-repo' ] );
			$this->fail( 'an unregistered repo was removed' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( "remove_repo 'acme/ghost-repo' names no repo this node declares", $e->getMessage() );
		}
		$this->assertSame( [ 'acme/kite-repo' ], $this->read_private( $node, 'repos' ) );
	}

	public function test_a_removed_repo_leaves_the_dump_and_its_replay(): void {
		$node = new Github_Source_Node();
		$node->name( 'github' );
		$node->interpreter()->dispatch( 'add_repo', [ 'acme/heron-repo' ] );
		$node->interpreter()->dispatch( 'add_repo', [ 'acme/kite-repo' ] );
		$node->interpreter()->dispatch( 'remove_repo', [ 'acme/heron-repo' ] );

		$dump = $node->dump_config();
		$this->assertStringNotContainsString( 'heron-repo', $dump );

		Core::reset();
		$replayed = new Github_Source_Node();
		$replayed->name( 'github' );
		$this->replay_config( $dump, $replayed );
		$this->assertSame( [ 'acme/kite-repo' ], $this->read_private( $replayed, 'repos' ) );
	}
}

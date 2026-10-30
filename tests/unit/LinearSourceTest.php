<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The Linear source on the chain: one GraphQL POST of `{ query, variables }`
 * a pass, through its Curl sibling under its vault group, and the page of
 * issues it answers.
 */
final class LinearSourceTest extends TestCase {
	use Drives_Curl;

	private const GRAPHQL = 'https://api.linear.app/graphql';

	protected function setUp(): void {
		parent::setUp();
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A Linear source as the collect topology builds it.
	 *
	 * @param list<string> $args  The make_node arguments.
	 * @param string       ...$teams The team keys it fetches.
	 */
	private function linear( array $args, string ...$teams ): Linear_Source_Node {
		$node = $this->wire( new Linear_Source_Node(), 'linear', $args );
		foreach ( $teams as $team ) {
			$node->interpreter()->dispatch( 'add_team', [ $team ] );
		}
		return $node;
	}

	/** @return array<string,mixed> The body the pass POSTed, decoded. */
	private function posted(): array {
		return \json_decode( (string) $this->opts[ self::GRAPHQL ][ \CURLOPT_POSTFIELDS ], true );
	}

	/** A pass POSTs the query as JSON, the team keys riding as a variable and never in the query text. */
	public function test_a_pass_posts_the_query_with_the_teams_as_a_variable(): void {
		$this->tick( $this->linear( [ 'internal' ], 'KESTREL', 'WREN9' ) );

		$this->assertSame( [ self::GRAPHQL ], $this->dispatched );
		$this->assertTrue( $this->opts[ self::GRAPHQL ][ \CURLOPT_POST ] );
		$this->assertContains( 'Content-Type: application/json', $this->opts[ self::GRAPHQL ][ \CURLOPT_HTTPHEADER ] );
		$body = $this->posted();
		$this->assertSame( [ 'teams' => [ 'KESTREL', 'WREN9' ] ], $body['variables'] );
		$this->assertStringContainsString( '$teams: [String!]', $body['query'] );
		$this->assertStringContainsString( 'issues(first: 30, orderBy: updatedAt, filter: { team: { key: { in: $teams } } })', $body['query'] );
		$this->assertStringNotContainsString( 'KESTREL', $body['query'] );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 1 ] ] ], $this->answers() );
	}

	/** The page's issue nodes record as items; a node with no identifier is skipped. */
	public function test_the_page_records_its_issues(): void {
		$this->tick( $this->linear( [ 'internal' ], 'KESTREL' ) );

		$this->complete(
			self::GRAPHQL,
			(string) \json_encode(
				[
					'data' => [
						'issues' => [
							'nodes' => [
								[ 'identifier' => 'ABC-123', 'title' => 'First issue', 'url' => 'https://linear.app/abc-123', 'description' => 'first body', 'updatedAt' => '2026-06-12T00:00:00Z' ],
								[ 'title' => 'No identifier' ],
								[ 'identifier' => 'ABC-124', 'title' => 'Second issue', 'url' => 'https://linear.app/abc-124', 'description' => 'second body', 'updatedAt' => '2026-06-13T00:00:00Z' ],
							],
						],
					],
				]
			)
		);

		$by = $this->recorded();
		$this->assertSame( [ 'linear:ABC-123', 'linear:ABC-124' ], \array_keys( $by ) );
		$item = $by['linear:ABC-123'];
		$this->assertSame( 'linear', $item['source'] );
		$this->assertSame( 'internal', $item['tier'] );
		$this->assertSame( 'First issue', $item['title'] );
		$this->assertSame( 'https://linear.app/abc-123', $item['url'] );
		$this->assertSame( 'first body', $item['body'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $item['item_ts'] );
	}

	/** A GraphQL error answer, which Linear sends with a 200, records nothing and logs the first error. */
	public function test_an_error_answer_records_nothing_and_logs_it(): void {
		$stderr = $this->capture_stderr();
		$this->tick( $this->linear( [ 'internal' ], 'KESTREL' ) );

		$this->complete( self::GRAPHQL, '{"errors":[{"message":"linear-key-revoked-6618"},{"message":"second-error-6619"}]}' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: Linear answered an error: linear-key-revoked-6618' ) );
	}

	/** An answer whose issues are not a list records nothing, and says so. */
	public function test_an_answer_without_an_issue_list_is_logged(): void {
		$stderr = $this->capture_stderr();
		$this->tick( $this->linear( [ 'internal' ], 'KESTREL' ) );

		$this->complete( self::GRAPHQL, '{"data":{"issues":{"nodes":{"ABC-7":{"identifier":"ABC-7"}}}}}' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: Linear answered an error: data.issues.nodes is not a list' ) );
	}

	public function test_no_team_fetches_nothing(): void {
		$this->tick( $this->linear( [ 'internal' ] ) );

		$this->assertSame( [], $this->dispatched );
	}

	/** The vault group the make_node line names lends the POST Linear's raw key, as its entry names the header. */
	public function test_the_vault_group_sends_the_raw_linear_key(): void {
		$this->seed_vault(
			'linear',
			[
				'url'           => 'https://api.linear.app',
				'group'         => 'linear-4402',
				'auth_password' => 'lin_api_vault_6630',
				'auth_header'   => 'Authorization',
			]
		);
		$node = $this->linear( [ 'internal', 'linear-4402' ], 'KESTREL' );

		$this->tick( $node );

		$this->assertContains( 'Authorization: lin_api_vault_6630', $this->opts[ self::GRAPHQL ][ \CURLOPT_HTTPHEADER ] );
		$this->assertMatchesRegularExpression( '/^make_node \S+ linear internal linear-4402$/m', $node->dump_config() );
	}

	public function test_add_team_accumulates_ordered_teams_and_ignores_a_repeat(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] ) );

		$this->assertSame( [ 'KESTREL', 'WREN9' ], $this->read_private( $node, 'teams' ) );
	}

	public function test_remove_team_stops_fetching_it_and_keeps_the_rest(): void {
		$node = $this->linear( [ 'internal' ], 'KESTREL', 'WREN9' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_team', [ 'KESTREL' ] ) );
		$this->tick( $node );

		$this->assertSame( [ 'teams' => [ 'WREN9' ] ], $this->posted()['variables'] );
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
		$this->assertSame( [ 'WREN9' ], $this->read_private( $node, 'teams' ) );
	}

	public function test_teams_round_trip_through_the_dump(): void {
		$node = new Linear_Source_Node();
		$node->name( 'linear' );
		$node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'WREN9' ] );
		$node->interpreter()->dispatch( 'add_team', [ 'HERON' ] );
		$node->interpreter()->dispatch( 'remove_team', [ 'WREN9' ] );
		$dump = $node->dump_config();

		$replayed = new Linear_Source_Node();
		$replayed->name( 'linear-replayed' );
		$this->replay_config( $dump, $replayed );

		$this->assertStringContainsString( 'command_node linear:config add_team KESTREL', $dump );
		$this->assertStringNotContainsString( 'WREN9', $dump );
		$this->assertSame( [ 'KESTREL', 'HERON' ], $this->read_private( $replayed, 'teams' ) );
	}

	public function test_node_schema_declares_linear_source_contract(): void {
		$schema = Linear_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'Linear issues', $schema['description'] );
		$this->assertSame( [ 'tier', 'vault_group' ], \array_column( $schema['arguments'], 'name' ) );
		$verb_names = \array_column( $schema['commands'], 'name' );
		$this->assertContains( 'add_team', $verb_names );
		$this->assertContains( 'remove_team', $verb_names );
	}
}

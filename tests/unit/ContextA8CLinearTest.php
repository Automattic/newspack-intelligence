<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_Linear_Node;
use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-context-a8c.php';

/**
 * The ContextA8C base, driven through its Linear node: the token read from
 * the relay's newest file and refreshed before it expires, the JSON-RPC
 * envelope and its answer, and each team's newest page recorded as the
 * Linear source records it.
 */
final class ContextA8CLinearTest extends TestCase {
	use Drives_Context_A8C;

	protected function setUp(): void {
		parent::setUp();
		$this->install_context_a8c();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A ContextA8C Linear node as the local topology builds it.
	 *
	 * @param string ...$teams The team keys it reads.
	 */
	private function linear( string ...$teams ): Context_A8C_Linear_Node {
		$node = $this->wire( new Context_A8C_Linear_Node(), 'linear', [ 'internal', $this->auth_dir, self::PROXY ] );
		foreach ( $teams as $team ) {
			$node->interpreter()->dispatch( 'add_team', [ $team ] );
		}
		return $node;
	}

	/** One issue as team-issues answers it. */
	private static function issue( string $identifier, string $updated ): array {
		return [
			'identifier'  => $identifier,
			'title'       => "Kestrel rollout {$identifier}",
			'description' => "Body of {$identifier}",
			'url'         => "https://linear.app/heron/issue/{$identifier}",
			'updated_at'  => $updated,
			'priority'    => 2,
		];
	}

	/** A pass asks each team's newest page, one call at a time, as a JSON-RPC `tools/call`. */
	public function test_a_pass_asks_each_teams_newest_page(): void {
		$this->signed_in();
		$node = $this->linear( 'KESTREL', 'WREN9' );

		$this->tick( $node );
		$this->initialized();
		$this->assertCount( 3, $this->sent );
		$opts = $this->sent[2];
		$this->assertSame( self::MCP, $opts[ \CURLOPT_URL ] );
		$this->assertTrue( $opts[ \CURLOPT_POST ] );
		$this->assertSame( [ 'Content-Type: application/json', 'Authorization: Bearer ' . self::ACCESS, 'Accept: application/json, text/event-stream', 'Mcp-Session-Id: ' . self::SESSION, 'MCP-Protocol-Version: ' . self::REVISION ], $opts[ \CURLOPT_HTTPHEADER ] );
		$this->assertSame( self::PROXY, $opts[ \CURLOPT_PROXY ] );
		$envelope = \json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true );
		$this->assertSame( '2.0', $envelope['jsonrpc'] );
		$this->assertSame( 'tools/call', $envelope['method'] );
		$this->assertSame( 'context-a8c-execute-tool', $envelope['params']['name'] );
		$this->answer( [ 'groups' => [] ] );

		$this->assertSame(
			[
				[ 'provider' => 'linear', 'subtool' => 'team-issues', 'subtool_args' => [ 'team' => 'KESTREL', 'limit' => 30 ] ],
				[ 'provider' => 'linear', 'subtool' => 'team-issues', 'subtool_args' => [ 'team' => 'WREN9', 'limit' => 30 ] ],
			],
			$this->calls()
		);
	}

	/**
	 * Each issue of every status group records under the Linear source's key
	 * and item shape: the same issue the Linear source reads records the same.
	 */
	public function test_each_issue_records_as_the_linear_source_records_it(): void {
		$this->signed_in();
		$real = $this->wire( new Linear_Source_Node(), 'linear-real', [ 'internal' ] );
		$real->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );
		$this->tick( $real );
		$nodes = [];
		foreach ( [ self::issue( 'KESTREL-41', '2026-10-28T09:15:00Z' ), self::issue( 'KESTREL-7', '2026-10-27T11:00:00Z' ) ] as $issue ) {
			$nodes[] = [ 'identifier' => $issue['identifier'], 'title' => $issue['title'], 'url' => $issue['url'], 'description' => $issue['description'], 'updatedAt' => $issue['updated_at'] ];
		}
		$this->complete( 'https://api.linear.app/graphql', (string) \json_encode( [ 'data' => [ 'issues' => [ 'nodes' => $nodes ] ] ] ) );
		$expected                = $this->items_recorded();
		$this->writer->captured = [];

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->initialized();
		$this->answer(
			[
				'groups'   => [
					'In Progress' => [ self::issue( 'KESTREL-41', '2026-10-28T09:15:00Z' ), [ 'title' => 'No identifier' ] ],
					'Todo'        => [ self::issue( 'KESTREL-7', '2026-10-27T11:00:00Z' ) ],
				],
				'pageInfo' => [ 'hasNextPage' => true, 'endCursor' => 'cur-5521' ],
			]
		);

		$this->assertCount( 2, $expected );
		$this->assertSame( 'linear:KESTREL-41', $expected[0]['item_key'] );
		$this->assertSame( $expected, $this->items_recorded() );
		$this->assertCount( 1, $this->calls(), 'the newest page alone' );
	}

	/**
	 * A pass with no session asks `initialize` first, naming the protocol
	 * revision it speaks and asking for the `Mcp-Session-Id` header; then the
	 * `notifications/initialized` the protocol requires, then its calls, each
	 * carrying the session and the revision the server answered.
	 */
	public function test_a_pass_initializes_before_its_calls(): void {
		$this->signed_in();

		$this->tick( $this->linear( 'KESTREL', 'WREN9' ) );
		$this->assertCount( 1, $this->sent );
		$this->assertIsCallable( $this->sent[0][ \CURLOPT_HEADERFUNCTION ], 'it reads the response headers' );
		$this->assertSame( [ 'Content-Type: application/json', 'Authorization: Bearer ' . self::ACCESS, 'Accept: application/json, text/event-stream' ], $this->sent[0][ \CURLOPT_HTTPHEADER ] );
		$envelope = \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true );
		$this->assertSame( [ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize' ], \array_intersect_key( $envelope, \array_flip( [ 'jsonrpc', 'id', 'method' ] ) ) );
		$this->assertSame( '2025-06-18', $envelope['params']['protocolVersion'] );
		$this->assertSame( 'newspack-intelligence', $envelope['params']['clientInfo']['name'] );
		$this->assertSame( '{}', \json_encode( $envelope['params']['capabilities'] ?? null, \JSON_FORCE_OBJECT ) );
		$this->initialized();
		$this->answer( [ 'groups' => [] ] );

		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call', 'tools/call' ], $this->methods() );
		$this->assertArrayNotHasKey( 'id', \json_decode( (string) $this->sent[1][ \CURLOPT_POSTFIELDS ], true ), 'a notification carries no id' );
		foreach ( [ 1, 2, 3 ] as $i ) {
			$this->assertContains( 'Mcp-Session-Id: ' . self::SESSION, $this->sent[ $i ][ \CURLOPT_HTTPHEADER ], "request {$i}" );
			$this->assertContains( 'MCP-Protocol-Version: ' . self::REVISION, $this->sent[ $i ][ \CURLOPT_HTTPHEADER ], "request {$i}" );
			$this->assertSame( self::PROXY, $this->sent[ $i ][ \CURLOPT_PROXY ], "request {$i}" );
		}
	}

	/**
	 * Each pass opens its own session and, once nothing is left to send,
	 * closes it with a DELETE carrying its id; the next pass opens another.
	 */
	public function test_each_pass_opens_its_own_session_and_closes_it(): void {
		$this->signed_in();
		$node = $this->linear( 'KESTREL' );
		$this->tick( $node );
		$this->initialized();
		$this->answer( [ 'groups' => [] ] );

		$close = \end( $this->sent );
		$this->assertSame( 'DELETE', $close[ \CURLOPT_CUSTOMREQUEST ] );
		$this->assertSame( self::MCP, $close[ \CURLOPT_URL ] );
		$this->assertContains( 'Mcp-Session-Id: ' . self::SESSION, $close[ \CURLOPT_HTTPHEADER ] );
		$this->assertContains( 'Authorization: Bearer ' . self::ACCESS, $close[ \CURLOPT_HTTPHEADER ] );
		$this->assertSame( self::PROXY, $close[ \CURLOPT_PROXY ] );
		$this->ended();

		$this->tick( $node );
		$this->initialized( 'sess-kite-9902' );
		$this->answer( [ 'groups' => [] ] );
		$this->ended();

		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call', 'DELETE', 'initialize', 'notifications/initialized', 'tools/call', 'DELETE' ], $this->methods() );
		$this->assertNotContains( 'Mcp-Session-Id: ' . self::SESSION, $this->sent[4][ \CURLOPT_HTTPHEADER ], 'the next pass initializes with no session' );
		$this->assertContains( 'Mcp-Session-Id: sess-kite-9902', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * A call the server answers 400 or 404 drops the session and ends the
	 * pass, its remaining calls unsent and no DELETE sent, logged; the next
	 * pass initializes again.
	 */
	public function test_a_refused_session_ends_the_pass_and_the_next_initializes_again(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();
		$node = $this->linear( 'KESTREL', 'WREN9' );
		$this->tick( $node );
		$this->initialized();
		$this->complete( self::MCP, '{"jsonrpc":"2.0","id":1,"error":{"code":-32001,"message":"Session not found"}}', 404 );
		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call' ], $this->methods() );

		$this->tick( $node );
		$this->initialized( 'sess-kite-9902' );

		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call', 'initialize', 'notifications/initialized', 'tools/call' ], $this->methods() );
		$this->assertContains( 'Mcp-Session-Id: sess-kite-9902', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C refused the session; initializing again next pass: HTTP 404 ' . self::MCP ) );
	}

	/**
	 * A call the server answers 401 ends the pass, its remaining calls
	 * unsent, marks the token stale, logged, and the next pass refreshes it
	 * though it has not neared its expiry.
	 */
	public function test_a_refused_token_ends_the_pass_and_the_next_refreshes(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();
		$node = $this->linear( 'KESTREL', 'WREN9' );
		$this->tick( $node );
		$this->initialized();
		$this->complete( self::MCP, '', 401 );
		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call' ], $this->methods() );

		$this->tick( $node );

		$this->assertSame( self::TOKEN, \end( $this->sent )[ \CURLOPT_URL ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C refused the token; refreshing it next pass: HTTP 401 ' . self::MCP ) );
	}

	/** An `initialize` answered with no session header is logged and calls nothing. */
	public function test_an_initialize_naming_no_session_calls_nothing(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->complete( self::MCP, '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-03-26"}}' );

		$this->assertSame( [ 'initialize' ], $this->methods() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C answered initialize with no Mcp-Session-Id: mcp/initialize' ) );
	}

	/** An expiring token is refreshed, then the session opens, then the calls go out. */
	public function test_a_refresh_comes_before_initialize(): void {
		$this->signed_in( 3599 - 30 );

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-new-7713', 'expires_in' => 3600 ] ) );
		$this->initialized();

		$this->assertSame( [ self::TOKEN, self::MCP, self::MCP, self::MCP ], \array_column( $this->sent, \CURLOPT_URL ) );
		$this->assertSame( [ 'initialize', 'notifications/initialized', 'tools/call' ], $this->methods() );
		$this->assertContains( 'Authorization: Bearer acc-new-7713', $this->sent[1][ \CURLOPT_HTTPHEADER ] );
	}

	/** The token is read from the relay file obtained last, whatever its version. */
	public function test_the_token_obtained_last_is_read(): void {
		$this->signed_in( 300 );
		$this->seed_token( '0.9.9', [ 'access_token' => 'acc-stale-0001', 'refresh_token' => 'ref-stale-0002', 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW - 900 ) * 1000 ) ] );
		$this->seed_token( '0.4.0', [ 'access_token' => 'acc-older-0003', 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW - 1200 ) * 1000 ) ] );

		$this->tick( $this->linear( 'KESTREL' ) );

		$this->assertContains( 'Authorization: Bearer ' . self::ACCESS, $this->sent[0][ \CURLOPT_HTTPHEADER ] );
	}

	/** A token within ten minutes of its expiry is refreshed; the margin is 600 seconds. */
	public function test_a_token_within_ten_minutes_of_expiring_is_refreshed(): void {
		$this->signed_in( 3599 - 599 );

		$this->tick( $this->linear( 'KESTREL' ) );

		$this->assertSame( 600, \Newspack_Intelligence\Context_A8C_Source_Node::EXPIRY_MARGIN );
		$this->assertSame( [ self::TOKEN ], $this->dispatched );
	}

	/**
	 * While a refresh is in flight, no property of the node or its Curl,
	 * anywhere in either's class chain, holds the access token or the
	 * refresh token, though the form Curl sent carries the refresh token.
	 */
	public function test_no_node_state_holds_a_token_while_a_refresh_is_in_flight(): void {
		$this->signed_in( 4000 );
		$node = $this->linear( 'KESTREL' );

		$this->tick( $node );

		$this->assertStringContainsString( self::REFRESH, (string) $this->opts[ self::TOKEN ][ \CURLOPT_POSTFIELDS ] );
		foreach ( [ $node, \Newspack_Nodes\Core::node( 'linear:curl' ) ] as $holder ) {
			$state = self::reflected_state( $holder ) . (string) \json_encode( $holder->dump_node(), \JSON_PARTIAL_OUTPUT_ON_ERROR );
			$this->assertStringNotContainsString( self::REFRESH, $state );
			$this->assertStringNotContainsString( self::ACCESS, $state );
		}
	}

	/**
	 * Every array and string property a node holds, declared anywhere in its
	 * class chain, private ones included, as one string.
	 */
	private static function reflected_state( object $node ): string {
		$values = [];
		for ( $class = new \ReflectionClass( $node ); false !== $class; $class = $class->getParentClass() ) {
			foreach ( $class->getProperties() as $property ) {
				if ( $property->getDeclaringClass()->getName() === $class->getName() && $property->isInitialized( $property->isStatic() ? null : $node ) ) {
					$value = $property->isStatic() ? $property->getValue() : $property->getValue( $node );
					if ( \is_array( $value ) || \is_string( $value ) ) {
						$values[] = $value;
					}
				}
			}
		}
		return (string) \json_encode( $values, \JSON_PARTIAL_OUTPUT_ON_ERROR );
	}

	/**
	 * Of two nodes reading one token dir, one refreshes the expiring token
	 * and the other waits: it starts its pass on its next tick once the
	 * refresh lands, under the new token, and the refresh token is spent once.
	 */
	public function test_one_refresh_serves_every_node_reading_the_token(): void {
		$this->signed_in( 4000 );
		$first  = $this->linear( 'KESTREL' );
		$second = $this->wire( new Context_A8C_Linear_Node(), 'linear-wren', [ 'internal', $this->auth_dir, self::PROXY ] );
		$second->interpreter()->dispatch( 'add_team', [ 'WREN9' ] );

		$this->tick( $first );
		$this->tick( $second );
		$this->assertSame( [ self::TOKEN ], $this->dispatched, 'the second waits for the first refresh' );
		$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-shared-3390', 'expires_in' => 3600 ] ) );
		$this->in_drain( static fn () => $second->fire_cb() );

		$urls = \array_column( $this->sent, \CURLOPT_URL );
		$this->assertSame( 1, \count( \array_keys( $urls, self::TOKEN, true ) ) );
		$this->assertContains( 'Authorization: Bearer acc-shared-3390', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
		$this->assertSame( 2, \count( \array_keys( $this->methods(), 'initialize', true ) ), 'each node opens its own session' );
	}

	/**
	 * A token the relay refreshed after this process read it is taken from
	 * the file, re-read under the lock, rather than refreshed again.
	 */
	public function test_a_token_the_relay_refreshed_is_used_rather_than_refreshed(): void {
		$this->signed_in();
		$node = $this->linear( 'KESTREL' );
		$this->tick( $node );
		$this->initialized();
		$this->answer( [ 'groups' => [] ] );
		$this->ended();
		Core::$clock = static fn (): float => self::NOW + 3000;
		$this->seed_token( '0.5.4', [ 'access_token' => 'acc-relay-4471', 'refresh_token' => 'ref-relay-4472', 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW + 2990 ) * 1000 ) ] );

		$this->tick( $node );

		$this->assertNotContains( self::TOKEN, \array_column( $this->sent, \CURLOPT_URL ) );
		$this->assertContains( 'Authorization: Bearer acc-relay-4471', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * A refresh refused with 400 or 401 is logged, and the same token is not
	 * spent again; once the relay signs in again, the next pass uses its
	 * token.
	 */
	public function test_a_refused_refresh_waits_for_the_relay_to_sign_in_again(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in( 4000 );
		$node = $this->linear( 'KESTREL' );

		$this->tick( $node );
		$this->complete( self::TOKEN, '', 400 );
		$this->tick( $node );
		$this->assertSame( [ self::TOKEN ], \array_column( $this->sent, \CURLOPT_URL ), 'the refused token is not spent again' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C refresh refused (HTTP 400): sign in to ContextA8C again on the host' ) );

		$this->seed_token( '0.5.4', [ 'access_token' => 'acc-resigned-4420', 'refresh_token' => 'ref-resigned-4421', 'expires_in' => 3599, 'obtained_at' => (int) ( self::NOW * 1000 ) ] );
		$this->tick( $node );

		$this->assertContains( 'Authorization: Bearer acc-resigned-4420', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * A token within a minute of expiring is refreshed before any call: a
	 * form POST of the refresh token, the client and the MCP url, which
	 * sends no bearer; the answer is written back over the relay's file by a
	 * rename beside it, and the call goes out under the new token.
	 */
	public function test_an_expiring_token_is_refreshed_and_written_back_before_the_call(): void {
		$this->signed_in( 3599 - 59 );
		$node = $this->linear( 'KESTREL' );

		$this->tick( $node );
		$this->assertSame( [ self::TOKEN ], $this->dispatched );
		$this->assertSame( [ 'Content-Type: application/x-www-form-urlencoded' ], $this->opts[ self::TOKEN ][ \CURLOPT_HTTPHEADER ] );
		$this->assertSame( self::PROXY, $this->opts[ self::TOKEN ][ \CURLOPT_PROXY ], 'the refresh goes through the proxy too' );
		\parse_str( (string) $this->opts[ self::TOKEN ][ \CURLOPT_POSTFIELDS ], $form );
		$this->assertSame( [ 'grant_type' => 'refresh_token', 'refresh_token' => self::REFRESH, 'client_id' => self::CLIENT, 'resource' => self::MCP ], $form );

		$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-new-5120', 'refresh_token' => 'ref-new-8802', 'expires_in' => 3600, 'token_type' => 'bearer' ] ) );

		$this->assertSame(
			[
				'access_token'  => 'acc-new-5120',
				'refresh_token' => 'ref-new-8802',
				'token_type'    => 'bearer',
				'expires_in'    => 3600,
				'scope'         => 'global-4471',
				'obtained_at'   => (int) ( self::NOW * 1000 ),
			],
			$this->token_file()
		);
		$this->assertSame( [], \glob( \dirname( $this->token_path() ) . '/*.tmp' ), 'no temp file is left beside it' );
		$this->assertSame( [ self::TOKEN, self::MCP ], $this->dispatched );
		$this->assertContains( 'Authorization: Bearer acc-new-5120', $this->sent[1][ \CURLOPT_HTTPHEADER ] );
	}

	/** A refresh answering no new refresh token keeps the old one in the file. */
	public function test_a_refresh_that_keeps_the_refresh_token_writes_the_new_access_token(): void {
		$this->signed_in( 4000 );

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-new-6631', 'expires_in' => 1800 ] ) );

		$file = $this->token_file();
		$this->assertSame( 'acc-new-6631', $file['access_token'] );
		$this->assertSame( self::REFRESH, $file['refresh_token'] );
		$this->assertSame( 1800, $file['expires_in'] );
		$this->assertContains( 'Authorization: Bearer acc-new-6631', $this->sent[1][ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * A token the relay signs in with after a server refused the old one and
	 * the endpoint refused its refresh is fresh: neither the refusal nor the
	 * stale mark the old token left outlives it, so it is used, not refreshed.
	 */
	public function test_a_newer_token_after_a_refusal_is_used_not_refreshed(): void {
		$this->signed_in();
		$node = $this->linear( 'KESTREL' );
		$this->tick( $node );
		$this->initialized();
		$this->complete( self::MCP, '', 401 );
		$this->tick( $node );
		$this->complete( self::TOKEN, '', 400 );
		$this->seed_token( '0.5.4', [ 'access_token' => 'acc-signed-in-5530', 'refresh_token' => 'ref-signed-in-5531', 'expires_in' => 3599, 'obtained_at' => (int) ( self::NOW * 1000 ) ] );

		$this->tick( $node );

		$this->assertSame( 1, \count( \array_keys( \array_column( $this->sent, \CURLOPT_URL ), self::TOKEN, true ) ) );
		$this->assertContains( 'Authorization: Bearer acc-signed-in-5530', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * A refresh refused after a write-back that did not land leaves the file
	 * holding an older token than the one refused: reading it again keeps
	 * the refusal, so its spent refresh token is never sent, until the file
	 * holds a token obtained after the refused one.
	 */
	public function test_an_older_file_keeps_the_refusal_and_a_newer_one_clears_it(): void {
		$this->signed_in( 4000 );
		\touch( $this->token_path() . '.lock' );
		$dir  = \dirname( $this->token_path() );
		$node = $this->linear( 'KESTREL' );
		\chmod( $dir, 0555 );
		try {
			$this->tick( $node );
			$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-unwritten-2290', 'refresh_token' => 'ref-unwritten-2291', 'expires_in' => 3600 ] ) );
		} finally {
			\chmod( $dir, 0700 );
		}
		$this->complete( self::MCP, '', 401 );
		$this->tick( $node );
		$this->complete( self::TOKEN, '', 400 );

		$this->tick( $node );
		$tokens = static fn ( array $sent ): int => \count( \array_keys( \array_column( $sent, \CURLOPT_URL ), self::TOKEN, true ) );
		$this->assertSame( 2, $tokens( $this->sent ), 'the older file is not refreshed' );

		$this->seed_token( '0.5.4', [ 'access_token' => 'acc-after-2292', 'refresh_token' => 'ref-after-2293', 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW + 60 ) * 1000 ) ] );
		$this->tick( $node );

		$this->assertSame( 2, $tokens( $this->sent ) );
		$this->assertContains( 'Authorization: Bearer acc-after-2292', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
	}

	/** A pass the request cap ends still closes its session: the DELETE is exempt from the cap. */
	public function test_a_capped_pass_still_closes_its_session(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();
		$this->tick( $this->linear( ...\array_map( static fn ( int $i ): string => \sprintf( 'KITE%02d', $i ), \range( 0, 60 ) ) ) );
		$this->initialized();
		for ( $i = 0; $i < 70 && 'tools/call' === \array_slice( $this->methods(), -1 )[0]; $i++ ) {
			$this->answer( [ 'groups' => [] ] );
		}

		$methods = $this->methods();
		$this->assertSame( 58, \count( \array_keys( $methods, 'tools/call', true ) ) );
		$this->assertSame( 'DELETE', \end( $methods ) );
		$this->assertContains( 'Mcp-Session-Id: ' . self::SESSION, \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: a pass reached 60 requests; sending no more: ' ) );
	}

	/** A refresh whose dispatch throws releases its claim, so the next tick claims and refreshes again. */
	public function test_a_refresh_whose_dispatch_throws_leaves_the_next_tick_free_to_claim(): void {
		$this->signed_in( 4000 );
		$node                          = $this->linear( 'KESTREL' );
		$this->refusing[ self::TOKEN ] = true;
		try {
			$this->tick( $node );
			$this->fail( 'the refresh dispatch threw nothing' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringStartsWith( 'dispatch-down-5108', $e->getMessage() );
		}

		$this->tick( $node );

		$this->assertSame( [ self::TOKEN, self::TOKEN ], $this->dispatched );
	}

	/**
	 * A lock beside the token that will not open, its dir read-only, is
	 * logged naming the token file, and the pass refreshes nothing and
	 * sends nothing.
	 */
	public function test_a_lock_that_will_not_open_refreshes_nothing(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in( 4000 );
		$dir = \dirname( $this->token_path() );
		\chmod( $dir, 0555 );
		try {
			$this->tick( $this->linear( 'KESTREL' ) );
		} finally {
			\chmod( $dir, 0700 );
		}

		$this->assertSame( [], $this->dispatched );
		$this->assertFileDoesNotExist( $this->token_path() . '.lock' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: could not lock the ContextA8C token: ' . $this->token_path() ) );
	}

	/**
	 * A refreshed token that will not reach the file, its dir read-only beside
	 * a lock that holds, is logged, the file left as it was, and the pass
	 * calls under the new token it holds.
	 */
	public function test_a_refreshed_token_that_will_not_land_is_logged_and_used(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in( 4000 );
		$before = (string) \file_get_contents( $this->token_path() );
		\touch( $this->token_path() . '.lock' );
		$dir = \dirname( $this->token_path() );
		\chmod( $dir, 0555 );
		try {
			$this->tick( $this->linear( 'KESTREL' ) );
			$this->complete( self::TOKEN, (string) \json_encode( [ 'access_token' => 'acc-unwritten-8840', 'expires_in' => 3600 ] ) );
		} finally {
			\chmod( $dir, 0700 );
		}

		$this->assertSame( $before, (string) \file_get_contents( $this->token_path() ) );
		$this->assertSame( [], \glob( $dir . '/*.tmp' ) );
		$this->assertContains( 'Authorization: Bearer acc-unwritten-8840', \end( $this->sent )[ \CURLOPT_HTTPHEADER ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: could not write the refreshed ContextA8C token back: ' . $this->auth_dir . '/wordpress-remote-*/' . \md5( self::MCP ) . '_tokens.json' ) );
	}

	/** A refresh answered 200 with no access token is logged; nothing is written and no call goes out. */
	public function test_a_refresh_answering_no_access_token_logs_and_calls_nothing(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in( 4000 );
		$before = (string) \file_get_contents( $this->token_path() );

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->complete( self::TOKEN, '{"token_type":"bearer-6620"}' );

		$this->assertSame( [ self::TOKEN ], $this->dispatched );
		$this->assertSame( $before, (string) \file_get_contents( $this->token_path() ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: the token endpoint answered no access token: ' . self::TOKEN ) );
	}

	/** An expiring token with no refresh token is logged naming its file, and nothing is sent. */
	public function test_an_expiring_token_without_a_refresh_token_logs_and_sends_nothing(): void {
		$stderr = $this->capture_stderr();
		$this->seed_token( '0.5.4', [ 'access_token' => self::ACCESS, 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW - 7200 ) * 1000 ) ] );

		$this->tick( $this->linear( 'KESTREL' ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: the ContextA8C token has expired and holds no refresh token; sign in to ContextA8C on the host: ' . $this->token_path() ) );
	}

	/** No token file is logged naming the path it looked for, and nothing is sent. */
	public function test_no_token_file_logs_the_path_and_sends_nothing(): void {
		$stderr = $this->capture_stderr();

		$this->tick( $this->linear( 'KESTREL' ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: no readable ContextA8C token: ' . $this->auth_dir . '/wordpress-remote-*/' . \md5( self::MCP ) . '_tokens.json' ) );
	}

	/** A token file that is not JSON counts as none. */
	public function test_an_unreadable_token_file_counts_as_none(): void {
		$stderr = $this->capture_stderr();
		$this->seed_token( '0.5.4', [] );
		\file_put_contents( $this->token_path(), 'not-json-3318' );

		$this->tick( $this->linear( 'KESTREL' ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: no readable ContextA8C token: ' ) );
	}

	/** A tool's error result is logged under its call, records nothing, and the next call goes out. */
	public function test_a_tool_error_is_logged_and_the_pass_goes_on(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();

		$this->tick( $this->linear( 'KESTREL', 'WREN9' ) );
		$this->initialized();
		$this->complete( self::MCP, '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"Access forbidden-3381"}],"isError":true}}' );

		$this->assertSame( [], $this->writer->captured );
		$this->assertCount( 2, $this->calls() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C answered an error for linear/team-issues?team=KESTREL&limit=30: Access forbidden-3381' ) );
	}

	/** A JSON-RPC error is logged with its message. */
	public function test_a_json_rpc_error_is_logged(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->initialized();
		$this->complete( self::MCP, '{"jsonrpc":"2.0","id":1,"error":{"code":-32600,"message":"Missing Mcp-Session-Id header-7710"}}' );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C answered an error for linear/team-issues?team=KESTREL&limit=30: Missing Mcp-Session-Id header-7710' ) );
	}

	/** A text content that is not a JSON object is logged and records nothing. */
	public function test_a_text_that_is_not_json_is_logged(): void {
		$stderr = $this->capture_stderr();
		$this->signed_in();

		$this->tick( $this->linear( 'KESTREL' ) );
		$this->initialized();
		$this->complete( self::MCP, '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"plain words 4410"}]}}' );

		$this->assertSame( [], $this->writer->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'linear: ContextA8C answered an error for linear/team-issues?team=KESTREL&limit=30: the text content holds no JSON object' ) );
	}

	/** With no proxy named, a call goes out direct. */
	public function test_no_proxy_calls_direct(): void {
		$this->signed_in();
		$node = $this->wire( new Context_A8C_Linear_Node(), 'linear', [ 'internal', $this->auth_dir ] );
		$node->interpreter()->dispatch( 'add_team', [ 'KESTREL' ] );

		$this->tick( $node );

		$this->assertArrayNotHasKey( \CURLOPT_PROXY, $this->sent[0] );
	}

	/** The token dir is required at load. */
	public function test_the_token_dir_is_required(): void {
		$node = new Context_A8C_Linear_Node();
		$node->name( 'linear' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: auth_dir' );
		$node->arguments( [ 'internal' ] );
	}

	/** The teams and the token dir round-trip through the dump. */
	public function test_the_teams_and_token_dir_round_trip_through_the_dump(): void {
		$node = $this->linear( 'KESTREL', 'WREN9' );
		$dump = $node->dump_config();

		Core::reset();
		$replayed = new Context_A8C_Linear_Node();
		$replayed->name( 'linear' );
		$this->replay_config( $dump, $replayed );

		$this->assertStringContainsString( "make_node Context_A8C_Linear linear internal {$this->auth_dir} " . self::PROXY, $dump );
		$this->assertSame( [ 'KESTREL', 'WREN9' ], $this->read_private( $replayed, 'teams' ) );
	}
}

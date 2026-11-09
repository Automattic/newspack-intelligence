<?php
/**
 * ContextA8CTokenTest: the process's hold on a ContextA8C token is one per
 * token dir and MCP server, so two servers sharing a dir never share a token,
 * a refresh, a refusal or a stale mark.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_Token;
use Newspack_Nodes\Tests\TestCase;

final class ContextA8CTokenTest extends TestCase {

	/** One MCP server the dir holds a token for. */
	private const HERON = 'https://mcp-heron.example/kite/v1';

	/** The other, sharing the dir. */
	private const OSPREY = 'https://mcp-osprey.example/wren/v2';

	/** The pinned clock, in seconds. */
	private const NOW = 1792999817.0;

	/** The dir standing in for `~/.mcp-auth`. */
	private string $dir = '';

	protected function setUp(): void {
		parent::setUp();
		$this->dir = $this->make_temp_dir( 'context-a8c-token-' );
		\mkdir( "{$this->dir}/wordpress-remote-0.5.4", 0700 );
		$this->seed( self::HERON, 'acc-heron-3307', 'ref-heron-1185' );
		$this->seed( self::OSPREY, 'acc-osprey-2208', 'ref-osprey-6614' );
	}

	/** Each server's token is held apart, under one dir. */
	public function test_each_server_holds_its_own_token(): void {
		$this->assertSame( 'acc-heron-3307', Context_A8C_Token::held( $this->dir, self::HERON )['token']['access_token'] ?? null );
		$this->assertSame( 'acc-osprey-2208', Context_A8C_Token::held( $this->dir, self::OSPREY )['token']['access_token'] ?? null );
		$this->assertSame( [ 'acc-heron-3307', 'acc-osprey-2208' ], [ Context_A8C_Token::access( $this->dir, self::HERON ), Context_A8C_Token::access( $this->dir, self::OSPREY ) ] );
	}

	/** One server's refresh, refusal and stale mark leave the other's token as it was. */
	public function test_one_servers_refresh_state_leaves_the_other_alone(): void {
		Context_A8C_Token::held( $this->dir, self::HERON );
		Context_A8C_Token::held( $this->dir, self::OSPREY );

		$this->assertSame( Context_A8C_Token::REFRESH, Context_A8C_Token::claim( $this->dir, self::HERON ) );
		$this->assertSame( Context_A8C_Token::REFRESH, Context_A8C_Token::claim( $this->dir, self::OSPREY ), 'the other server refreshes on its own' );
		Context_A8C_Token::failed( $this->dir, self::HERON, 401 );
		Context_A8C_Token::stale( $this->dir, self::OSPREY );

		$this->assertSame( [ 401, 0 ], [ Context_A8C_Token::refused_status( $this->dir, self::HERON ), Context_A8C_Token::refused_status( $this->dir, self::OSPREY ) ] );
		$this->assertSame( [ '', 'acc-osprey-2208' ], [ Context_A8C_Token::access( $this->dir, self::HERON ), Context_A8C_Token::access( $this->dir, self::OSPREY ) ] );
		$this->assertTrue( Context_A8C_Token::due( $this->dir, self::OSPREY, self::NOW, 60 ) );
		$this->assertSame( [ true, true ], Context_A8C_Token::landed( $this->dir, self::OSPREY, (string) \json_encode( [ 'access_token' => 'acc-osprey-9902', 'expires_in' => 3600 ] ), self::NOW ) );
		$this->assertFalse( Context_A8C_Token::due( $this->dir, self::OSPREY, self::NOW, 60 ) );
		$this->assertStringContainsString( 'refresh_token=ref-osprey-6614', Context_A8C_Token::form( $this->dir, self::OSPREY ) );
	}

	/**
	 * A token dir that will not list raises, naming it, rather than reading as
	 * signed out; nothing is held, so the next read finds the token.
	 */
	public function test_a_token_dir_that_will_not_list_raises_and_holds_nothing(): void {
		$e = $this->unlistable( $this->dir, fn () => Context_A8C_Token::held( $this->dir, self::HERON ) );

		$this->assertSame( "dir {$this->dir} will not list", $e->getMessage() );
		$this->assertSame( 'acc-heron-3307', Context_A8C_Token::held( $this->dir, self::HERON )['token']['access_token'] ?? null );
	}

	/** Write a relay's token file and client file for the server at $url. */
	private function seed( string $url, string $access, string $refresh ): void {
		$at = "{$this->dir}/wordpress-remote-0.5.4/" . \md5( $url );
		\file_put_contents( "{$at}_tokens.json", (string) \json_encode( [ 'access_token' => $access, 'refresh_token' => $refresh, 'expires_in' => 3599, 'obtained_at' => (int) ( ( self::NOW - 600 ) * 1000 ) ] ) );
		\file_put_contents( "{$at}_client_info.json", (string) \json_encode( [ 'client_id' => 'client-' . \substr( \md5( $url ), 0, 6 ) ] ) );
	}
}

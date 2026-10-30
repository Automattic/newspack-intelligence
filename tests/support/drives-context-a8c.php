<?php
/**
 * Drives_Context_A8C: a ContextA8C source's real graph, as Drives_Curl
 * builds it, over a token dir laid out as the host's `~/.mcp-auth` is, with
 * the MCP server's answers stood in for at libcurl.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;

require_once __DIR__ . '/drives-curl.php';

trait Drives_Context_A8C {
	use Drives_Curl;

	private const MCP = Context_A8C_Source_Node::MCP_URL;

	private const TOKEN = Context_A8C_Source_Node::TOKEN_URL;

	private const ACCESS = 'acc-heron-7731';

	private const REFRESH = 'ref-kite-4410';

	private const CLIENT = '911337';

	/** The proxy every call goes through. */
	private const PROXY = 'socks5h://proxy-heron.example:1088';

	/** The session the server opens. */
	private const SESSION = 'sess-heron-5531';

	/** The protocol revision the server answers. */
	private const REVISION = '2025-03-26';

	/** The pinned clock: no default's, and a past instant. */
	private const NOW = 1792999817.0;

	/** The dir standing in for `~/.mcp-auth`. */
	private string $auth_dir = '';

	/** Pin the clock, stand in for libcurl and lay out an empty token dir. */
	private function install_context_a8c(): void {
		Core::$now      = self::NOW;
		Core::$clock    = static fn (): float => self::NOW;
		$this->auth_dir = $this->make_temp_dir( 'context-a8c-auth-' );
		$this->install_curl();
	}

	/** The token file a relay of $version writes. */
	private function token_path( string $version = '0.5.4' ): string {
		return "{$this->auth_dir}/wordpress-remote-{$version}/" . \md5( self::MCP ) . '_tokens.json';
	}

	/**
	 * Write a relay's token file.
	 *
	 * @param array<string,mixed> $fields What it holds.
	 */
	private function seed_token( string $version, array $fields ): void {
		$path = $this->token_path( $version );
		if ( ! \is_dir( \dirname( $path ) ) ) {
			\mkdir( \dirname( $path ), 0700, true );
		}
		\file_put_contents( $path, (string) \json_encode( $fields ) );
	}

	/** Write the relay's client file beside its tokens. */
	private function seed_client( string $client = self::CLIENT, string $version = '0.5.4' ): void {
		\file_put_contents( \dirname( $this->token_path( $version ) ) . '/' . \md5( self::MCP ) . '_client_info.json', (string) \json_encode( [ 'client_id' => $client, 'token_endpoint_auth_method' => 'none' ] ) );
	}

	/** Sign in as the relay does: a pair obtained $ago seconds before the clock, with its client. */
	private function signed_in( int $ago = 600 ): void {
		$this->seed_token(
			'0.5.4',
			[
				'access_token'  => self::ACCESS,
				'refresh_token' => self::REFRESH,
				'token_type'    => 'bearer',
				'expires_in'    => 3599,
				'scope'         => 'global-4471',
				'obtained_at'   => (int) ( ( self::NOW - $ago ) * 1000 ),
			]
		);
		$this->seed_client();
	}

	/** @return array<string,mixed> The token file, decoded. */
	private function token_file( string $version = '0.5.4' ): array {
		return \json_decode( (string) \file_get_contents( $this->token_path( $version ) ), true );
	}

	/**
	 * A `tools/call` answer whose one text content is $answer as JSON.
	 *
	 * @param array<string,mixed> $answer The provider's answer.
	 */
	private static function tool_answer( array $answer ): string {
		return (string) \json_encode(
			[
				'jsonrpc' => '2.0',
				'id'      => 1,
				'result'  => [ 'content' => [ [ 'type' => 'text', 'text' => (string) \json_encode( $answer ) ] ] ],
			]
		);
	}

	/**
	 * Answer the `initialize` in flight with $session in its `Mcp-Session-Id`
	 * header, then the `notifications/initialized` that follows with a 202.
	 */
	private function initialized( string $session = self::SESSION ): void {
		$this->complete(
			self::MCP,
			(string) \json_encode(
				[
					'jsonrpc' => '2.0',
					'id'      => 1,
					'result'  => [ 'protocolVersion' => self::REVISION, 'serverInfo' => [ 'name' => 'ContextA8C MCP Server' ] ],
				]
			),
			200,
			\CURLE_OK,
			[ "HTTP/1.1 200 OK\r\n", "Content-Type: application/json\r\n", "Mcp-Session-Id: {$session}\r\n", "\r\n" ]
		);
		$this->complete( self::MCP, '', 202 );
	}

	/** Answer the session's closing DELETE, which ends a pass. */
	private function ended(): void {
		$this->complete( self::MCP, '', 204 );
	}

	/**
	 * The JSON-RPC method of every request to the MCP url, in order, and
	 * `DELETE` for a session's close.
	 *
	 * @return list<string>
	 */
	private function methods(): array {
		$methods = [];
		foreach ( $this->sent as $opts ) {
			if ( self::MCP === $opts[ \CURLOPT_URL ] ) {
				$methods[] = 'DELETE' === ( $opts[ \CURLOPT_CUSTOMREQUEST ] ?? null ) ? 'DELETE' : \json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true )['method'];
			}
		}
		return $methods;
	}

	/**
	 * Answer the call in flight with $answer.
	 *
	 * @param array<string,mixed> $answer The provider's answer.
	 */
	private function answer( array $answer ): void {
		$this->complete( self::MCP, self::tool_answer( $answer ) );
	}

	/** @return list<array<string,mixed>> Every `tools/call`'s arguments, in the order they went out. */
	private function calls(): array {
		$calls = [];
		foreach ( $this->sent as $opts ) {
			$envelope = self::MCP === $opts[ \CURLOPT_URL ] ? \json_decode( (string) ( $opts[ \CURLOPT_POSTFIELDS ] ?? '' ), true ) : null;
			if ( 'tools/call' === ( $envelope['method'] ?? null ) ) {
				$calls[] = $envelope['params']['arguments'];
			}
		}
		return $calls;
	}

	/**
	 * The item fields of each recorded event, in order, so a ContextA8C
	 * source's items compare with a real source's.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function items_recorded(): array {
		$fields = [ 'item_key', 'source', 'tier', 'url', 'title', 'body', 'item_ts', 'retrieved_ts' ];
		return \array_map( static fn ( array $m ): array => \array_intersect_key( $m[ Message::VALUE ], \array_flip( $fields ) ), $this->writer->captured );
	}
}

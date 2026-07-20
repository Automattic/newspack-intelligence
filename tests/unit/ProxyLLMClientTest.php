<?php
namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Proxy_LLM_Client;
use PHPUnit\Framework\TestCase;

final class ProxyLLMClientTest extends TestCase {
	protected function tearDown(): void {
		Proxy_LLM_Client::$http_post = null;
	}

	public function test_sends_feature_header_bearer_and_model_and_parses_content(): void {
		$seen = null;
		Proxy_LLM_Client::$http_post = static function ( string $url, array $args ) use ( &$seen ) {
			$seen = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => \json_encode(
				[ 'choices' => [ [ 'message' => [ 'content' => 'A one-line summary.' ] ] ] ]
			) ];
		};
		$client = new Proxy_LLM_Client( 'https://proxy.test/v1', 'SEKRET', 'gpt-oss-120b', 'newspack-intelligence' );
		$out    = $client->chat( [ [ 'role' => 'user', 'content' => 'summarize this' ] ], [ 'max_tokens' => 120 ] );

		$this->assertSame( 'A one-line summary.', $out );
		$this->assertSame( 'https://proxy.test/v1/chat/completions', $seen['url'] );
		$this->assertSame( 'Bearer SEKRET', $seen['args']['headers']['Authorization'] );
		$this->assertSame( 'newspack-intelligence', $seen['args']['headers']['X-WPCOM-AI-Feature'] );
		$body = \json_decode( $seen['args']['body'], true );
		$this->assertSame( 'gpt-oss-120b', $body['model'] );
		$this->assertSame( 120, $body['max_tokens'] );
		$this->assertSame( 'summarize this', $body['messages'][0]['content'] );
	}

	public function test_throws_on_non_200(): void {
		Proxy_LLM_Client::$http_post = static fn () => [ 'response' => [ 'code' => 503 ], 'body' => 'unavailable' ];
		$client = new Proxy_LLM_Client( 'https://proxy.test/v1', 'SEKRET', 'gpt-oss-120b', 'newspack-intelligence' );
		$this->expectException( \RuntimeException::class );
		$client->chat( [ [ 'role' => 'user', 'content' => 'x' ] ] );
	}

	/**
	 * Ingested content (GitHub/Linear/feed text) is not guaranteed clean UTF-8.
	 * Before this fix, an unencodable message body made wp_json_encode() return
	 * false, which the bare (string) cast silently swallowed to '' — an EMPTY
	 * request body sent to the AI proxy instead of a loud, diagnosable failure.
	 */
	public function test_throws_instead_of_sending_empty_body_on_unencodable_message(): void {
		$seen = null;
		Proxy_LLM_Client::$http_post = static function ( string $url, array $args ) use ( &$seen ) {
			$seen = $args;
			return [ 'response' => [ 'code' => 200 ], 'body' => '{}' ];
		};
		$client = new Proxy_LLM_Client( 'https://proxy.test/v1', 'SEKRET', 'gpt-oss-120b', 'newspack-intelligence' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/encod/i' );
		try {
			$client->chat( [ [ 'role' => 'user', 'content' => "bad byte \xE9" ] ] );
		} finally {
			$this->assertNull( $seen, 'no request may be sent when the body could not be encoded' );
		}
	}

	public function test_wp_error_message_is_plain_text_not_html_escaped(): void {
		Proxy_LLM_Client::$http_post = static fn () => new \WP_Error( 'http_request_failed', 'host \'proxy.test\' said <nope>' );
		$client = new Proxy_LLM_Client( 'https://proxy.test/v1', 'SEKRET', 'gpt-oss-120b', 'newspack-intelligence' );
		try {
			$client->chat( [ [ 'role' => 'user', 'content' => 'x' ] ] );
			$this->fail( 'expected RuntimeException' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( "host 'proxy.test' said <nope>", $e->getMessage() );
			$this->assertStringNotContainsString( '&#039;', $e->getMessage() );
			$this->assertStringNotContainsString( '&lt;', $e->getMessage() );
		}
	}
}

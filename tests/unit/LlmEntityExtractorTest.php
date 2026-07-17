<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\LLM_Entity_Extractor;
use Newspack_Intelligence\Proxy_LLM_Client;
use PHPUnit\Framework\TestCase;

final class LlmEntityExtractorTest extends TestCase {

	protected function tearDown(): void {
		Proxy_LLM_Client::$http_post = null;
		parent::tearDown();
	}

	private function extractor(): LLM_Entity_Extractor {
		return new LLM_Entity_Extractor( new Proxy_LLM_Client( 'https://p/v1', 'K', 'm', 'f' ) );
	}

	private function respond( string $content ): void {
		Proxy_LLM_Client::$http_post = static fn (): array => [
			'response' => [ 'code' => 200 ],
			'body'     => (string) \json_encode( [ 'choices' => [ [ 'message' => [ 'content' => $content ] ] ] ] ),
		];
	}

	public function test_parses_clean_json(): void {
		$this->respond( '{"orgs":["The Texas Tribune"],"people":["Jane Doe"],"locations":["Austin"]}' );
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [ 'The Texas Tribune' ], $out['orgs'] );
		$this->assertSame( [ 'Jane Doe' ], $out['people'] );
		$this->assertSame( [ 'Austin' ], $out['locations'] );
	}

	public function test_parses_json_wrapped_in_prose_or_fences(): void {
		$this->respond( "Here you go:\n```json\n{\"orgs\":[\"WyoFile\"],\"people\":[],\"locations\":[]}\n```" );
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [ 'WyoFile' ], $out['orgs'] );
	}

	public function test_garbage_reply_yields_empty_triple(): void {
		$this->respond( 'no json here' );
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [ 'orgs' => [], 'people' => [], 'locations' => [] ], $out );
	}

	public function test_http_error_yields_empty_triple_without_throwing(): void {
		Proxy_LLM_Client::$http_post = static fn (): array => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ];
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [], $out['orgs'] );
	}

	public function test_non_string_entries_are_dropped(): void {
		$this->respond( '{"orgs":["Valid", 123, null, "Also"],"people":[],"locations":[]}' );
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [ 'Valid', 'Also' ], $out['orgs'] );
	}
}

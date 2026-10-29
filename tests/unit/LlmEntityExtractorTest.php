<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Tests\TestCase;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\LLM_Entity_Extractor;
use Newspack_Intelligence\Proxy_LLM_Client;
use Newspack_Nodes\Worker_Should_Stop;

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

	/** A reply that does not parse is no answer, told apart from an empty one. */
	public function test_garbage_reply_answers_none(): void {
		$this->respond( 'no json here' );
		$stderr = $this->capture_stderr();

		$this->assertNull( $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'NER reply named no organization list' ) );
	}

	/** A failed call answers none without throwing, told apart from an empty answer. */
	public function test_http_error_answers_none_without_throwing(): void {
		Proxy_LLM_Client::$http_post = static fn (): array => [ 'response' => [ 'code' => 503 ], 'body' => 'down' ];
		$stderr                      = $this->capture_stderr();

		$this->assertNull( $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'NER call failed: ' ) );
	}

	/**
	 * A reply is an answer only when its `orgs` is a list: an empty object,
	 * an error object or a scalar `orgs` answers none, logged.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function no_answers(): array {
		return [
			'an empty object'  => [ '{}' ],
			'an error object'  => [ '{"error":"rate limited for 41s"}' ],
			'a scalar orgs'    => [ '{"orgs":"The Texas Tribune"}' ],
			'an object orgs'   => [ '{"orgs":{"first":"The Texas Tribune"}}' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'no_answers' )]
	public function test_a_reply_without_an_orgs_list_answers_none( string $reply ): void {
		$this->respond( $reply );
		$stderr = $this->capture_stderr();

		$this->assertNull( $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'NER reply named no organization list' ) );
	}

	/** An answer naming nothing is an answer: an empty triple. */
	public function test_an_empty_answer_is_an_empty_triple(): void {
		$this->respond( '{"orgs":[],"people":["Ana Ruiz"]}' );
		$this->assertSame( [ 'orgs' => [], 'people' => [ 'Ana Ruiz' ], 'locations' => [] ], $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] ) );
	}

	public function test_non_string_entries_are_dropped(): void {
		$this->respond( '{"orgs":["Valid", 123, null, "Also"],"people":[],"locations":[]}' );
		$out = $this->extractor()->extract( [ 'title' => 'T', 'body' => 'B' ] );
		$this->assertSame( [ 'Valid', 'Also' ], $out['orgs'] );
	}

	/** A cooperative stop inside the call is not a failed call: it propagates. */
	public function test_a_cooperative_stop_propagates(): void {
		$extractor = new LLM_Entity_Extractor( new class() implements LLM_Client {
				public function chat( array $messages, array $opts = [] ): string {
					throw new Worker_Should_Stop( 'max_runtime 595 reached' );
				}
			} );

		$this->expectException( Worker_Should_Stop::class );
		$this->expectExceptionMessage( 'max_runtime 595 reached' );
		$extractor->extract( [ 'title' => 'Quarry Ledger merges', 'body' => 'B' ] );
	}
}

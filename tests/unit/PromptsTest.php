<?php
/**
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Prompts;
use PHPUnit\Framework\TestCase;

final class PromptsTest extends TestCase {
	public function test_enrich_includes_item_and_profile_and_demands_json(): void {
		$m = Prompts::enrich(
			[ 'title' => 'Roundup ships', 'source' => 'github', 'body' => 'AI summarizes posts.' ],
			'engineering velocity and AI features'
		);
		$this->assertSame( 'system', $m[0]['role'] );
		$this->assertStringContainsStringIgnoringCase( 'json', $m[0]['content'] );
		$user = $m[1]['content'];
		$this->assertStringContainsString( 'Roundup ships', $user );
		$this->assertStringContainsString( 'engineering velocity', $user );
	}

	public function test_extract_entities_demands_json_orgs_people_locations(): void {
		$m = Prompts::extract_entities( [ 'title' => 'City Council votes', 'body' => 'The Texas Tribune reported the vote in Austin.' ] );
		$this->assertSame( 'system', $m[0]['role'] );
		$this->assertStringContainsStringIgnoringCase( 'json', $m[0]['content'] );
		$this->assertStringContainsStringIgnoringCase( 'orgs', $m[0]['content'] );
		$user = $m[1]['content'];
		$this->assertStringContainsString( 'City Council votes', $user );
		$this->assertStringContainsString( 'The Texas Tribune reported', $user );
	}

	public function test_digest_lists_items_and_profile(): void {
		$m = Prompts::digest(
			[ [ 'title' => 'A', 'summary' => 'sa', 'source' => 'github', 'score' => 9.0, 'url' => 'http://a' ] ],
			'what shipped'
		);
		$this->assertSame( 'system', $m[0]['role'] );
		$this->assertStringContainsStringIgnoringCase( 'markdown', $m[0]['content'] );
		$this->assertStringContainsString( 'sa', $m[1]['content'] );
		$this->assertStringContainsString( 'what shipped', $m[1]['content'] );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Feed_Source_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class FeedSourceTest extends TestCase {

	protected function tearDown(): void {
		Feed_Source_Node::$http_get = null;
	}

	private const RSS = <<<XML
<?xml version="1.0"?>
<rss version="2.0">
  <channel>
	<title>Example Feed</title>
	<item>
	  <title>First Post</title>
	  <link>https://example.com/first</link>
	  <description>First body</description>
	  <guid>guid-first</guid>
	  <pubDate>Mon, 10 Jun 2026 00:00:00 GMT</pubDate>
	</item>
	<item>
	  <title>No Guid Post</title>
	  <link>https://example.com/second</link>
	  <description>Second body</description>
	  <pubDate>Tue, 11 Jun 2026 00:00:00 GMT</pubDate>
	</item>
  </channel>
</rss>
XML;

	private const ATOM = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Feed</title>
  <entry>
	<title>Atom Entry</title>
	<link href="https://example.com/atom-entry"/>
	<id>atom-id-1</id>
	<summary>Atom summary</summary>
	<updated>2026-06-12T00:00:00Z</updated>
  </entry>
</feed>
XML;

	/** Stub the HTTP seam to return a fixed body for every feed URL. */
	private function stub_body( string $xml ): void {
		Feed_Source_Node::$http_get = static function ( string $url, array $args ) use ( $xml ): array {
			return [ 'response' => [ 'code' => 200 ], 'body' => $xml ];
		};
	}

	/** @return array<string,array<string,mixed>> items keyed by id */
	private function fetch_by_id( array $config ): array {
		$node  = new Feed_Source_Node();
		$items = $node->fetch( $config );
		$out   = [];
		foreach ( $items as $item ) {
			$out[ $item['id'] ] = $item;
		}
		return $out;
	}

	public function test_rss_item_parses_to_item_with_guid_id(): void {
		$this->stub_body( self::RSS );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] );

		$this->assertArrayHasKey( 'feed:guid-first', $by );
		$item = $by['feed:guid-first'];
		$this->assertSame( 'feed', $item['source'] );
		$this->assertSame( 'First Post', $item['title'] );
		$this->assertSame( 'https://example.com/first', $item['url'] );
		$this->assertSame( 'First body', $item['body'] );
		$this->assertSame( \strtotime( 'Mon, 10 Jun 2026 00:00:00 GMT' ), $item['timestamp'] );
	}

	public function test_rss_item_without_guid_falls_back_to_link_for_id(): void {
		$this->stub_body( self::RSS );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] );

		$this->assertArrayHasKey( 'feed:https://example.com/second', $by );
		$this->assertSame( 'No Guid Post', $by['feed:https://example.com/second']['title'] );
	}

	public function test_atom_entry_parses_link_href_summary_id_updated(): void {
		$this->stub_body( self::ATOM );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/atom.xml' ] ] );

		$this->assertArrayHasKey( 'feed:atom-id-1', $by );
		$item = $by['feed:atom-id-1'];
		$this->assertSame( 'Atom Entry', $item['title'] );
		$this->assertSame( 'https://example.com/atom-entry', $item['url'] );
		$this->assertSame( 'Atom summary', $item['body'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $item['timestamp'] );
	}

	public function test_wp_error_contributes_no_items_without_throwing(): void {
		Feed_Source_Node::$http_get = static function ( string $url, array $args ): mixed {
			return new \WP_Error( 'http', 'boom' );
		};
		$node = new Feed_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] ) );
	}

	public function test_malformed_xml_contributes_no_items_without_throwing(): void {
		$this->stub_body( '<rss><this is not xml' );
		$node = new Feed_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] ) );
	}

	public function test_empty_feeds_config_returns_empty(): void {
		$node = new Feed_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'feeds' => [] ] ) );
	}

	public function test_fetch_returns_empty_on_non_200(): void {
		Feed_Source_Node::$http_get = static fn (): array => [ 'response' => [ 'code' => 503 ], 'body' => '' ];
		$node = new Feed_Source_Node();
		$this->assertSame( [], $node->fetch( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] ) );
	}

	public function test_atom_entry_prefers_alternate_link_over_self(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
	<title>Multi-link</title>
	<link rel="self" href="https://example.com/self"/>
	<link rel="alternate" href="https://example.com/alternate"/>
	<id>atom-multi</id>
	<updated>2026-06-12T00:00:00Z</updated>
  </entry>
</feed>
XML;
		$this->stub_body( $xml );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/atom.xml' ] ] );

		$this->assertArrayHasKey( 'feed:atom-multi', $by );
		$this->assertSame( 'https://example.com/alternate', $by['feed:atom-multi']['url'] );
	}

	public function test_atom_entry_falls_back_to_first_non_alternate_link(): void {
		$xml = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
	<title>Self only</title>
	<link rel="self" href="https://example.com/self"/>
	<id>atom-self</id>
	<updated>2026-06-12T00:00:00Z</updated>
  </entry>
</feed>
XML;
		$this->stub_body( $xml );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/atom.xml' ] ] );

		$this->assertArrayHasKey( 'feed:atom-self', $by );
		$this->assertSame( 'https://example.com/self', $by['feed:atom-self']['url'] );
	}

	public function test_rss_item_uses_dc_date_when_pubdate_absent(): void {
		$xml = <<<XML
<?xml version="1.0"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
	<title>DC Feed</title>
	<item>
	  <title>DC Dated</title>
	  <link>https://example.com/dc</link>
	  <description>body</description>
	  <guid>dc-1</guid>
	  <dc:date>2026-06-09T00:00:00Z</dc:date>
	</item>
  </channel>
</rss>
XML;
		$this->stub_body( $xml );
		$by = $this->fetch_by_id( [ 'feeds' => [ 'https://example.com/feed.xml' ] ] );

		$this->assertArrayHasKey( 'feed:dc-1', $by );
		$this->assertSame( \strtotime( '2026-06-09T00:00:00Z' ), $by['feed:dc-1']['timestamp'] );
	}

	public function test_tick_reads_feeds_from_settings(): void {
		update_option( 'newspack_ai_newsletter_feeds', [ 'https://example.com/feed.xml' ] );
		$captured = [];
		Feed_Source_Node::$http_get = static function ( string $url, array $args ) use ( &$captured ): array {
			$captured[] = [ 'url' => $url, 'args' => $args ];
			return [ 'response' => [ 'code' => 200 ], 'body' => self::RSS ];
		};

		$node = new Feed_Source_Node();
		$node->sink( new Capture_Sink_Node() );
		$message                  = Message::new_message();
		$message[ Message::TYPE ] = Message::TM_REQUEST;
		$node->fill( $message );

		$this->assertCount( 1, $captured );
		$this->assertSame( 'https://example.com/feed.xml', $captured[0]['url'] );
		$this->assertSame( 'newspack-ai-newsletter', $captured[0]['args']['headers']['User-Agent'] );
		delete_option( 'newspack_ai_newsletter_feeds' );
	}

	public function test_node_schema_declares_feed_source_contract(): void {
		$schema = Feed_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertStringContainsString( 'RSS 2.0 / Atom', $schema['description'] );
	}
}

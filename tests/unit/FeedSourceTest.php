<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The feed source: a pass sends each feed URL to its `feed:curl` sibling,
 * at most its concurrency at once, and records each body's items
 * as the body lands. The graph is real — a Router, an interpreter, the Curl
 * sibling and a capturing target — and only libcurl is stood in for,
 * through the substrate's dispatch and result seams.
 */
final class FeedSourceTest extends TestCase {
	use Seeds_Intel_Graph;
	use Drives_Curl;

	/** The Gazette's own site, where its items link. */
	private const GAZETTE = 'https://harborgazette.example';

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

	protected function setUp(): void {
		parent::setUp();
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/**
	 * A feed source as the collect topology builds it: named, its tier set,
	 * sinking into the interpreter, its items addressed to the writer.
	 *
	 * @param string ...$urls The feeds it fetches.
	 */
	private function feed( string ...$urls ): Feed_Source_Node {
		$node = $this->wire( new Feed_Source_Node(), 'feed', [ 'public' ] );
		foreach ( $urls as $url ) {
			$node->interpreter()->dispatch( 'add_url', [ $url ] );
		}
		return $node;
	}

	/** Run one Router tick through the feed inside a drain, its clock at a moment. */
	private function fire_at( Feed_Source_Node $node, float $at ): void {
		$this->in_drain(
			static function () use ( $node, $at ): void {
				Core::$now = $at;
				$node->fire_cb();
			}
		);
	}

	/**
	 * One feed fetched once, answering $xml.
	 *
	 * @return array<string,array<string,mixed>> Each recorded item, by item_key.
	 */
	private function items_from( string $xml ): array {
		$node = $this->feed( 'https://example.com/feed.xml' );
		$this->tick( $node );
		$this->complete( 'https://example.com/feed.xml', $xml );
		return $this->recorded();
	}

	/**
	 * An RSS body of items, each a guid, a title, a pubDate or null, and the
	 * site its link is on.
	 *
	 * @param list<array{0:string,1:string,2:int|null,3?:string}> $items The items.
	 */
	private static function rss( array $items ): string {
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel>';
		foreach ( $items as $item ) {
			[ $guid, $title, $when ] = $item;
			$site                    = $item[3] ?? 'https://example.com';
			$xml                    .= "<item><title>{$title}</title><link>{$site}/{$guid}</link><guid>{$guid}</guid>"
				. ( null === $when ? '' : '<pubDate>' . \gmdate( 'D, d M Y H:i:s', $when ) . ' GMT</pubDate>' ) . '</item>';
		}
		return $xml . '</channel></rss>';
	}

	/**
	 * An RSS body with one item carrying `$fields` beside its title and guid.
	 *
	 * @param string $fields The item's other elements.
	 */
	private static function rss_with( string $fields ): string {
		return '<?xml version="1.0"?><rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>'
			. "<item><title>Heron count</title><link>https://example.com/heron</link><guid>heron-44</guid>{$fields}</item></channel></rss>";
	}

	/** An item's full `content:encoded` is its body, a CDATA one's markup kept, over its shorter `description`. */
	public function test_rss_prefers_content_encoded_over_description(): void {
		$item = $this->items_from( self::rss_with( '<description>Tide tables teaser</description><content:encoded><![CDATA[<p>The whole <em>tide</em> report &amp; its charts.</p>]]></content:encoded>' ) )['feed:heron-44'];

		$this->assertSame( '<p>The whole <em>tide</em> report &amp; its charts.</p>', $item['body'] );
	}

	/**
	 * An item with no `content:encoded`, or a blank one, keeps its
	 * `description`.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function bodies_without_content(): array {
		return [
			'no element'    => [ '<description>Tide tables teaser</description>' ],
			'a blank one'   => [ '<description>Tide tables teaser</description><content:encoded><![CDATA[  ]]></content:encoded>' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bodies_without_content' )]
	public function test_rss_falls_back_to_description( string $fields ): void {
		$this->assertSame( 'Tide tables teaser', $this->items_from( self::rss_with( $fields ) )['feed:heron-44']['body'] );
	}

	/** A `content:encoded` past the body cap is cut to it. */
	public function test_rss_content_encoded_is_cut_to_the_body_cap(): void {
		$item = $this->items_from( self::rss_with( '<content:encoded><![CDATA[' . \str_repeat( 'é', Source_Node::MAX_BODY_BYTES ) . ']]></content:encoded>' ) )['feed:heron-44'];

		$this->assertSame( Source_Node::MAX_BODY_BYTES, \strlen( $item['body'] ) );
		$this->assertTrue( \mb_check_encoding( $item['body'], 'UTF-8' ) );
	}

	public function test_rss_item_parses_to_item_with_guid_id(): void {
		$by = $this->items_from( self::RSS );

		$this->assertArrayHasKey( 'feed:guid-first', $by );
		$item = $by['feed:guid-first'];
		$this->assertSame( 'feed', $item['source'] );
		$this->assertSame( 'public', $item['tier'] );
		$this->assertSame( 'First Post', $item['title'] );
		$this->assertSame( 'https://example.com/first', $item['url'] );
		$this->assertSame( 'First body', $item['body'] );
		$this->assertSame( \strtotime( 'Mon, 10 Jun 2026 00:00:00 GMT' ), $item['item_ts'] );
	}

	public function test_rss_item_without_guid_falls_back_to_link_for_id(): void {
		$by = $this->items_from( self::RSS );

		$this->assertArrayHasKey( 'feed:https://example.com/second', $by );
		$this->assertSame( 'No Guid Post', $by['feed:https://example.com/second']['title'] );
	}

	public function test_atom_entry_parses_link_href_summary_id_updated(): void {
		$by = $this->items_from( self::ATOM );

		$this->assertArrayHasKey( 'feed:atom-id-1', $by );
		$item = $by['feed:atom-id-1'];
		$this->assertSame( 'Atom Entry', $item['title'] );
		$this->assertSame( 'https://example.com/atom-entry', $item['url'] );
		$this->assertSame( 'Atom summary', $item['body'] );
		$this->assertSame( \strtotime( '2026-06-12T00:00:00Z' ), $item['item_ts'] );
	}

	/** A transport failure contributes nothing, logged, and the pass ends. */
	public function test_a_transport_error_contributes_nothing_logged(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->feed( 'https://heron-4410.example/feed/' );
		$this->tick( $node );

		$this->complete( 'https://heron-4410.example/feed/', '', 0, \CURLE_COULDNT_CONNECT );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a fetch failed for https://heron-4410.example/feed/: curl error 7 (' ) );
		$this->tick( $node );
		$this->assertSame( [ 'https://heron-4410.example/feed/', 'https://heron-4410.example/feed/' ], $this->dispatched, 'the answered pass leaves room for the next' );
	}

	public function test_an_error_status_contributes_nothing(): void {
		$node = $this->feed( 'https://example.com/feed.xml' );
		$this->tick( $node );

		$this->complete( 'https://example.com/feed.xml', self::RSS, 503 );

		$this->assertSame( [], $this->recorded() );
	}

	/** A pass some feeds of which answered 404 is ok, counting them. */
	public function test_partly_failing_feed_pass_is_ok_with_count(): void {
		$this->publishing();
		Core::$now   = 6262.0;
		Core::$clock = static fn (): float => 6262.0;
		$urls        = \array_map( static fn ( int $i ): string => "https://outlet-{$i}.example/feed/", \range( 1, 5 ) );
		$node        = $this->feed( ...$urls );
		$this->tick( $node );

		foreach ( $urls as $i => $url ) {
			$this->complete( $url, 1 === $i % 2 ? '' : self::RSS, 1 === $i % 2 ? 404 : 200 );
		}

		$this->assertSame( self::snapshot( [ 'state' => 'ok', 'last_ok_at' => 6262, 'failed' => 2, 'requests' => 5, 'dead_clients' => [] ] ), $this->published( 'feed' ) );
	}

	/** A redirect is a hop, not an answer: a feed moved to a 404 publishes failing, its one request counted once. */
	public function test_a_redirect_to_a_404_publishes_failing(): void {
		$this->publishing();
		Core::$now   = 6262.0;
		Core::$clock = static fn (): float => 6262.0;
		$node        = $this->feed( 'https://moved-7720.example/feed/' );
		$this->tick( $node );

		$this->redirect( 'https://moved-7720.example/feed/', 'https://moved-7721.example/feed/' );
		$this->complete( 'https://moved-7721.example/feed/', '', 404 );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'HTTP 404 https://moved-7721.example/feed/', 'failing_since' => 6262, 'failed' => 1, 'requests' => 1, 'dead_clients' => [] ] ), $this->published( 'feed' ) );
	}

	/** A redirect not followed records nothing, so its request counts as failed, naming why. */
	public function test_a_redirect_past_five_hops_counts_as_failed(): void {
		$this->publishing();
		$node = $this->feed( 'https://hop-0.example/feed/' );
		$this->tick( $node );

		for ( $hop = 0; $hop <= Curl_Node::MAX_REDIRECTS; $hop++ ) {
			$this->redirect( "https://hop-{$hop}.example/feed/", 'https://hop-' . ( $hop + 1 ) . '.example/feed/' );
		}

		$status = $this->published( 'feed' );
		$this->assertSame( [ 'failing', 1, 1, 'a request redirected past 5 hops; recording nothing: https://hop-6.example/feed/' ], [ $status['state'], $status['requests'], $status['failed'], $status['last_error'] ] );
	}

	/**
	 * A body that is not an RSS or Atom feed, malformed or another document,
	 * counts as a failed answer naming its url.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function bodies_that_are_no_feed(): array {
		return [
			'malformed' => [ '<rss><this is not xml' ],
			'html'      => [ '<html>not-a-feed-4471</html>' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bodies_that_are_no_feed' )]
	public function test_a_body_that_is_no_feed_counts_as_failed( string $body ): void {
		$this->publishing();
		$node = $this->feed( 'https://heron-4471.example/feed/' );
		$this->tick( $node );

		$this->complete( 'https://heron-4471.example/feed/', $body );

		$status = $this->published( 'feed' );
		$this->assertSame( [ 'failing', 1, 1 ], [ $status['state'], $status['requests'], $status['failed'] ] );
		$this->assertStringStartsWith( 'no RSS or Atom feed at https://heron-4471.example/feed/: ', $status['last_error'] );
	}

	/**
	 * A body that is not a feed is logged under its own url, saying why: two
	 * feeds failing log two lines, and a feed failing again logs none.
	 */
	public function test_each_body_that_is_no_feed_is_logged_once_under_its_url(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->feed( 'https://heron-4471.example/feed/', 'https://grebe-4472.example/feed/' );
		$this->tick( $node );
		$this->complete( 'https://heron-4471.example/feed/', '<html>not-a-feed-4471</html>' );
		$this->complete( 'https://grebe-4472.example/feed/', '<rss><channel></channel></rss><rss>' );

		$this->tick( $node );
		$this->complete( 'https://heron-4471.example/feed/', '<html>not-a-feed-4471</html>' );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: no RSS or Atom feed at https://heron-4471.example/feed/: its root element is <html>' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: no RSS or Atom feed at https://grebe-4472.example/feed/: Extra content at the end of the document' ) );
		$this->assertCount( 2, self::stderr_lines( $stderr, 'feed: no RSS or Atom feed at ' ) );
	}

	public function test_malformed_xml_contributes_nothing(): void {
		$this->assertSame( [], $this->items_from( '<rss><this is not xml' ) );
	}

	public function test_no_feed_fetches_nothing(): void {
		$this->tick( $this->feed() );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
	}

	/** A TICK sends each feed to the Curl sibling keyed by its url, and answers how many the pass fetches. */
	public function test_a_tick_sends_each_feed_and_answers_how_many(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/', 'https://wren-2202.example/rss' );

		$this->tick( $node );

		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://wren-2202.example/rss' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 2 ] ] ], $this->answers() );
		$curl = Core::node( 'feed:curl' );
		$this->assertInstanceOf( Curl_Node::class, $curl );
		$this->assertSame( 'feed', $curl->target() );
		$this->assertSame( 2, $curl->transfers_in_flight() );
	}

	/**
	 * By default a feed sends as many as Curl takes at once, sixteen; each
	 * answer sends the next.
	 */
	public function test_at_most_sixteen_in_flight_by_default_and_the_next_sent_as_one_answers(): void {
		$urls = \array_map( static fn ( int $i ): string => \sprintf( 'https://outlet-%02d.example/feed/', $i ), \range( 1, 18 ) );
		$node = $this->feed( ...$urls );

		$this->tick( $node );
		$this->assertSame( 16, Curl_Node::MAX_IN_FLIGHT );
		$this->assertSame( \array_slice( $urls, 0, 16 ), $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 18 ] ] ], $this->answers() );

		$this->complete( $urls[3], self::RSS );
		$this->assertSame( \array_slice( $urls, 0, 17 ), $this->dispatched );
		$this->complete( $urls[0], '', 404 );
		$this->assertSame( $urls, $this->dispatched );
	}

	/** `set_concurrency` bounds the feeds in flight, rides the dump, and refuses a count Curl cannot take. */
	public function test_set_concurrency_bounds_the_feeds_in_flight(): void {
		$urls = \array_map( static fn ( int $i ): string => "https://outlet-{$i}.example/feed/", \range( 1, 8 ) );
		$node = $this->feed( ...$urls );
		foreach ( [ '0', '17' ] as $refused ) {
			try {
				$node->interpreter()->dispatch( 'set_concurrency', [ $refused ] );
				$this->fail( "concurrency {$refused} accepted" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "concurrency wants a whole number from 1 to 16, got '{$refused}'", $e->getMessage() );
			}
		}
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_concurrency', [ '6' ] ) );

		$this->tick( $node );

		$this->assertSame( \array_slice( $urls, 0, 6 ), $this->dispatched );
		$this->assertStringContainsString( "command_node feed:config set_concurrency 6\n", $node->dump_config() );
	}

	/** A body's items are recorded when it lands, while the other feeds are still in flight. */
	public function test_a_body_records_its_items_when_it_lands(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/', 'https://wren-2202.example/rss' );
		$this->tick( $node );

		$this->complete( 'https://wren-2202.example/rss', self::ATOM );

		$this->assertSame( [ 'feed:atom-id-1' ], \array_keys( $this->recorded() ) );
		$event = $this->writer->captured[0];
		$this->assertSame( [ Message::TM_STRUCT, 'feed' ], [ $event[ Message::TYPE ], $event[ Message::FROM ] ] );
		$this->assertSame( 'item_recorded', $event[ Message::VALUE ]['event'] );
	}

	/**
	 * A configured feed belongs to anyone, so a copy it lists of a client's post
	 * never stands in for the client's own, whichever answers first.
	 *
	 * @param bool $configured_first Whether the configured feed answers first.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'answer_orders' )]
	public function test_a_configured_feed_never_suppresses_a_clients_copy( bool $configured_first ): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->set_client_feeds( true );
		$this->tick( $node );
		$guid    = 'tag:harborgazette.example,2026:cup-61';
		$listing = static fn ( string $link ): string => '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Osprey wins the cup</title><link>' . $link . "</link><guid>{$guid}</guid><pubDate>" . \gmdate( 'D, d M Y H:i:s', (int) Core::$now - 60 ) . ' GMT</pubDate></item></channel></rss>';
		$answers = [
			[ 'https://kite-2201.example/feed/', $listing( 'https://www.harborgazette.example/cup/?utm_source=kite' ) ],
			[ self::GAZETTE_FEED, $listing( self::GAZETTE . '/cup' ) ],
		];

		foreach ( $configured_first ? $answers : \array_reverse( $answers ) as [ $url, $body ] ) {
			$this->complete( $url, $body );
		}

		$recorded = \array_keys( $this->recorded() );
		\sort( $recorded );
		$this->assertSame( [ "feed:harborgazette.example:{$guid}", "feed:{$guid}" ], $recorded );
	}

	/**
	 * A configured guid shaped like a client's lane is keyed apart from it,
	 * so it neither takes the client's key nor reads as the client's copy.
	 *
	 * @param bool $configured_first Whether the configured feed answers first.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'answer_orders' )]
	public function test_a_configured_guid_shaped_like_a_lane_keeps_out_of_it( bool $configured_first ): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->set_client_feeds( true );
		$this->tick( $node );
		$listing = static fn ( string $guid, string $title ): string => '<?xml version="1.0"?><rss version="2.0"><channel><item><title>' . $title . '</title><link>' . self::GAZETTE . '/cup-73</link>'
			. "<guid>{$guid}</guid><pubDate>" . \gmdate( 'D, d M Y H:i:s', (int) Core::$now - 60 ) . ' GMT</pubDate></item></channel></rss>';
		$answers = [
			[ 'https://kite-2201.example/feed/', $listing( 'harborgazette.example:cup-73', 'Forged headline' ) ],
			[ self::GAZETTE_FEED, $listing( 'cup-73', 'Osprey wins the cup' ) ],
		];

		foreach ( $configured_first ? $answers : \array_reverse( $answers ) as [ $url, $body ] ) {
			$this->complete( $url, $body );
		}

		$titles = \array_map( static fn ( array $item ): string => $item['title'], $this->recorded() );
		\ksort( $titles );
		$this->assertSame(
			[
				'feed::harborgazette.example:cup-73' => 'Forged headline',
				'feed:harborgazette.example:cup-73'  => 'Osprey wins the cup',
			],
			$titles
		);
	}

	/** @return array<string,array{bool}> */
	public static function answer_orders(): array {
		return [
			'configured first' => [ true ],
			'client first'     => [ false ],
		];
	}

	/** Two clients' lanes listing one post under one guid record it once. */
	public function test_a_post_two_client_lanes_list_records_once(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$this->seed(
			self::entity( 'organization:gazette-sports', 'Gazette Sports', [ 'domain' => [ 'sports.harborgazette.example' ] ] ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:gazette-sports', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ]
		);
		$node = $this->feed();
		$node->set_client_feeds( true );
		$this->tick( $node );
		$guid    = 'https://sports.harborgazette.example/?p=4418';
		$listing = '<?xml version="1.0"?><rss version="2.0"><channel><item><title>Osprey wins the cup</title><link>https://sports.harborgazette.example/cup-4418/</link>'
			. "<guid>{$guid}</guid><pubDate>" . \gmdate( 'D, d M Y H:i:s', (int) Core::$now - 60 ) . ' GMT</pubDate></item></channel></rss>';

		$this->complete( self::GAZETTE_FEED, $listing );
		$this->complete( 'https://sports.harborgazette.example/feed/', $listing );

		$this->assertSame( [ "feed:harborgazette.example:{$guid}" ], \array_keys( $this->recorded() ) );
	}

	/** A podcast's episodes share their show's one link under their own guids, so each records. */
	public function test_a_podcasts_episodes_sharing_one_link_each_record(): void {
		$node = $this->feed( 'https://podcast-7731.example/feed/' );
		$this->tick( $node );

		$this->complete( 'https://podcast-7731.example/feed/', '<?xml version="1.0"?><rss version="2.0"><channel>'
			. '<item><title>Episode 1</title><link>https://podcast-7731.example/show</link><guid>ep-7731-1</guid></item>'
			. '<item><title>Episode 2</title><link>https://podcast-7731.example/show</link><guid>ep-7731-2</guid></item>'
			. '</channel></rss>' );

		$this->assertSame( [ 'feed:ep-7731-1', 'feed:ep-7731-2' ], \array_keys( $this->recorded() ) );
	}

	/** A due fetch, by TICK or on the grid, starts nothing while a pass is in flight. */
	public function test_no_second_pass_while_one_is_in_flight(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->interpreter()->dispatch( 'set_interval', [ '1800' ] );
		$this->tick( $node );

		$this->tick( $node );
		$this->fire_at( $node, 1800.0 * 995004 - 3.0 );
		$this->fire_at( $node, 1800.0 * 995004 + 3.0 );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 1 ] ], [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );

		$this->complete( 'https://kite-2201.example/feed/', self::RSS );
		$this->fire_at( $node, 1800.0 * 995005 + 3.0 );
		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://kite-2201.example/feed/' ], $this->dispatched );
	}

	/**
	 * Ask for a TICK that throws, and answer the message thrown.
	 *
	 * @param Feed_Source_Node $node The feed.
	 */
	private function tick_throwing( Feed_Source_Node $node ): string {
		try {
			$this->tick( $node );
		} catch ( \RuntimeException $e ) {
			return $e->getMessage();
		}
		$this->fail( 'the TICK threw nothing' );
	}

	/** A send Curl throws on leaves nothing in flight, so the next TICK fetches the feed again. */
	public function test_a_send_that_throws_leaves_the_feed_out_of_flight(): void {
		$node                    = $this->feed( 'https://kite-2201.example/feed/' );
		$this->refusing['https://kite-2201.example/feed/'] = true;

		$this->assertSame( 'dispatch-down-5108 https://kite-2201.example/feed/', $this->tick_throwing( $node ) );
		$this->tick( $node );

		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 1 ] ] ], $this->answers() );
	}

	/** A queue a throw strands is replaced by the next TICK's pass, which fetches every feed. */
	public function test_a_stranded_queue_is_replaced_by_the_next_pass(): void {
		$node                    = $this->feed( 'https://kite-2201.example/feed/', 'https://wren-2202.example/rss' );
		$this->refusing['https://kite-2201.example/feed/'] = true;
		$this->tick_throwing( $node );

		$this->tick( $node );

		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://kite-2201.example/feed/', 'https://wren-2202.example/rss' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 2 ] ] ], $this->answers() );
	}

	/** An answer that does not match a feed in flight is dropped. */
	public function test_an_answer_for_no_feed_in_flight_records_nothing(): void {
		$node                      = $this->feed( 'https://kite-2201.example/feed/' );
		$stray                     = Message::new_message();
		$stray[ Message::TYPE ]    = Message::TM_BYTESTREAM;
		$stray[ Message::KEY ]     = 'https://stray-9901.example/feed/';
		$stray[ Message::VALUE ]   = self::RSS;

		$node->fill( $stray );

		$this->assertSame( [], $this->recorded() );
	}

	/**
	 * A pass's feeds wait in the feed's Table, so the process after a recycle
	 * sends on its first tick the feed it had in flight and the one it never
	 * sent, with no TICK and no boundary.
	 */
	public function test_a_pass_cut_by_a_recycle_resumes_in_the_next_process(): void {
		$urls = [ 'https://auk-3301.example/feed/', 'https://bittern-3302.example/feed/', 'https://coot-3303.example/feed/' ];
		$node = $this->feed( ...$urls );
		$node->interpreter()->dispatch( 'set_concurrency', [ '1' ] );
		$this->tick( $node );
		$this->complete( $urls[0], self::RSS );

		$successor = $this->successor( $node );
		$this->fire_at( $successor, 7351.0 );
		$this->complete( $urls[1], self::ATOM );
		$this->complete( $urls[2], '', 404 );

		$this->assertSame( [ $urls[0], $urls[1], $urls[1], $urls[2] ], $this->dispatched );
		$this->assertArrayHasKey( 'feed:atom-id-1', $this->recorded() );
	}

	/** A client's feed the next process resumes keeps the client's lane, which rides with the request. */
	public function test_a_resumed_client_feed_keeps_its_lane(): void {
		$node = $this->client_feed();
		$this->tick( $node );

		$this->fire_at( $this->successor( $node ), Core::$now + 9.0 );
		$this->complete( self::GAZETTE_FEED, self::rss( [ [ 'own-site', 'Own', (int) Core::$now - 60, self::GAZETTE ], [ 'offsite', 'Offsite', (int) Core::$now - 60, 'https://salemledger.example' ] ] ) );

		$this->assertSame( [ 'feed:harborgazette.example:own-site' ], \array_keys( $this->recorded() ) );
	}

	/**
	 * The process after a recycle: a feed under the stopped one's name and
	 * arguments, configured alike, its Table the same.
	 */
	private function successor( Feed_Source_Node $stopped ): Feed_Source_Node {
		$successor = $this->respawn( $stopped, new Feed_Source_Node() );
		foreach ( $this->read_private( $stopped, 'urls' ) as $url ) {
			$successor->interpreter()->dispatch( 'add_url', [ $url ] );
		}
		$successor->set_client_feeds( $this->read_private( $stopped, 'client_feeds' ) );
		return $successor;
	}

	/** A rename carries the Curl sibling and its answers with it. */
	public function test_a_rename_moves_the_curl_sibling_and_its_target(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/' );

		$node->name( 'feed-3307' );

		$curl = Core::node( 'feed-3307:curl' );
		$this->assertInstanceOf( Curl_Node::class, $curl );
		$this->assertSame( 'feed-3307', $curl->target() );
	}

	/** A feed sends no credential, so it declares no vault group. */
	public function test_a_feed_declares_no_vault_group(): void {
		$this->assertSame( [ 'tier' ], \array_column( Feed_Source_Node::node_schema()['arguments'], 'name' ) );
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
		$by = $this->items_from( $xml );

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
		$by = $this->items_from( $xml );

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
		$by = $this->items_from( $xml );

		$this->assertArrayHasKey( 'feed:dc-1', $by );
		$this->assertSame( \strtotime( '2026-06-09T00:00:00Z' ), $by['feed:dc-1']['item_ts'] );
	}

	/**
	 * With client feeds on, a pass adds `https://<domain>/feed/` for each
	 * domain of an entity holding an open client role: none for a churned
	 * client or an entity that never was one, and one fetch, as configured,
	 * for a client feed already configured.
	 */
	public function test_client_feeds_add_each_open_clients_domain(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://kite-2201.example/feed/', 'https://pelican-7714.example/feed/' );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] ) );

		$this->tick( $node );

		$this->assertSame( [ 'https://harborgazette.example/feed/', 'https://kite-2201.example/feed/', 'https://pelican-7714.example/feed/' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 3 ] ] ], $this->answers() );
	}

	/** With client feeds off, the default, a pass fetches the configured feeds alone. */
	public function test_client_feeds_off_fetch_no_client(): void {
		$this->start_intel_graph();
		$this->seed_clients();

		$this->tick( $this->feed( 'https://kite-2201.example/feed/' ) );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
	}

	/**
	 * A client feed records only an item dated within CLIENT_FEED_DAYS of
	 * the fetch; a configured feed records every item, undated ones too.
	 */
	public function test_a_client_feed_records_only_the_last_week(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://pelican-7714.example/feed/' );
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );
		$now  = (int) Core::$now;
		$week = Feed_Source_Node::CLIENT_FEED_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;

		$this->complete( 'https://harborgazette.example/feed/', self::rss( [ [ 'gazette-fresh', 'Fresh', $now - $week + 60, self::GAZETTE ], [ 'gazette-stale', 'Stale', $now - $week - 60, self::GAZETTE ], [ 'gazette-undated', 'Undated', null, self::GAZETTE ] ] ) );
		$this->complete( 'https://pelican-7714.example/feed/', self::rss( [ [ 'pelican-stale', 'Stale', $now - $week - 60 ], [ 'pelican-undated', 'Undated', null ] ] ) );

		$this->assertSame( 7, Feed_Source_Node::CLIENT_FEED_DAYS );
		$this->assertSame( [ 'feed:harborgazette.example:gazette-fresh', 'feed:pelican-stale', 'feed:pelican-undated' ], \array_keys( $this->recorded() ) );
	}

	/**
	 * A client feed's items are keyed by the client's domain, so another
	 * feed never takes their keys; each must link to the client's own site or a
	 * subdomain of it, and be dated no more than a day past the fetch.
	 */
	public function test_a_client_feeds_items_stay_in_its_lane(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed();
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );
		$now = (int) Core::$now;
		$day = Intel_Graph_Row_Schema::DAY_SECONDS;

		$this->complete(
			'https://harborgazette.example/feed/',
			self::rss(
				[
					[ 'own-site', 'Own', $now - 3600, self::GAZETTE ],
					[ 'subdomain', 'Sub', $now - 3600, 'https://news.harborgazette.example' ],
					[ 'cased', 'Cased', $now - 3600, 'https://WWW.HarborGazette.Example' ],
					[ 'neighbour', 'Neighbour', $now - 3600, 'https://salemledger.example' ],
					[ 'lookalike', 'Lookalike', $now - 3600, 'https://notharborgazette.example' ],
					[ 'tomorrow', 'Tomorrow', $now + $day - 60, self::GAZETTE ],
					[ 'past-tomorrow', 'Past tomorrow', $now + $day + 60, self::GAZETTE ],
				]
			)
		);

		$this->assertSame(
			[ 'feed:harborgazette.example:own-site', 'feed:harborgazette.example:subdomain', 'feed:harborgazette.example:cased', 'feed:harborgazette.example:tomorrow' ],
			\array_keys( $this->recorded() )
		);
	}

	/** The Kestrel, a client the roster named by its domain. */
	private const KESTREL = 'organization:site-880044';

	private const KESTREL_FEED = 'https://kestrelbay.example/feed/';

	private const SANDPIPER_FEED = 'https://sandpipergazette.example/feed/';

	/** A canonical name a curator gave stands: no feed title is written over it, whatever the client was created as. */
	public function test_a_canonical_name_takes_no_feed_title(): void {
		$node = $this->titled_clients();
		$this->seed( self::facts( self::KESTREL, [ 'canonical' => [ 'Kestrel Bay Tribune' ] ] ), self::facts( 'organization:sandpiper-gazette', [ 'canonical' => [ 'Sandpiper Gazette Tribune' ] ] ) );

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, self::titled_rss( 'Kestrel Bay Courier', 'https://kestrelbay.example' ) );
		$this->complete( self::SANDPIPER_FEED, self::titled_rss( 'Sandpiper Gazette Online', 'https://sandpipergazette.example' ) );

		$this->assertSame( [], $this->names() );
	}

	/** A canonical that spells the client's own domain is not a name, so its feed's title is written over it. */
	public function test_a_canonical_spelling_a_domain_takes_the_feeds_title(): void {
		$node = $this->titled_clients();
		$this->seed( self::facts( 'organization:sandpiper-gazette', [ 'canonical' => [ 'sandpipergazette.example' ] ] ) );

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, self::titled_rss( 'Kestrel Bay Courier', 'https://kestrelbay.example' ) );
		$this->complete( self::SANDPIPER_FEED, self::titled_rss( 'Sandpiper Gazette Online', 'https://sandpipergazette.example' ) );

		$this->assertContains( [ 'organization:sandpiper-gazette', 'canonical', 'Sandpiper Gazette Online' ], self::brief_names( $this->names() ) );
	}

	/**
	 * A client named by its own domain takes its feed's title as its
	 * canonical name, its whitespace collapsed, once: not again on the next
	 * pass, nor from the next process once the projection holds it. A client
	 * already holding a name takes none.
	 */
	public function test_a_client_named_by_its_domain_takes_its_feeds_title_once(): void {
		$node = $this->titled_clients();

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, self::titled_rss( "\n\t\tKestrel  Bay Courier\n", 'https://kestrelbay.example' ) );
		$this->complete( self::SANDPIPER_FEED, self::titled_rss( 'Sandpiper Gazette Online', 'https://sandpipergazette.example' ) );

		$this->assertSame( [ [ self::KESTREL, 'canonical', 'Kestrel Bay Courier' ] ], self::brief_names( $this->names() ) );
		$this->assertStringStartsWith( Intel_Event::machine_prefix( 'source:feed' ), $this->names()[0]['actor'] );

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, self::titled_rss( 'Kestrel Bay Courier', 'https://kestrelbay.example' ) );
		$this->complete( self::SANDPIPER_FEED, self::titled_rss( 'Sandpiper Gazette Online', 'https://sandpipergazette.example' ) );
		$this->assertCount( 1, $this->names() );

		$this->seed( $this->names() );
		$successor = $this->successor( $node );
		$this->tick( $successor );
		$this->complete( self::KESTREL_FEED, self::titled_rss( 'Kestrel Bay Courier', 'https://kestrelbay.example' ) );
		$this->complete( self::SANDPIPER_FEED, self::titled_rss( 'Sandpiper Gazette Online', 'https://sandpipergazette.example' ) );
		$this->assertCount( 1, $this->names() );
	}

	/** An Atom feed's own title names the client as an RSS channel's does. */
	public function test_an_atom_feeds_title_names_the_client(): void {
		$node = $this->titled_clients();

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Kestrel Bay Atom Desk</title><entry><title>Tide</title><link href="https://kestrelbay.example/tide"/><id>tide-1</id><updated>2026-10-19T12:00:00Z</updated></entry></feed>' );

		$this->assertSame( [ [ self::KESTREL, 'canonical', 'Kestrel Bay Atom Desk' ] ], self::brief_names( $this->names() ) );
	}

	/** @return array<string,array{0:string}> */
	public static function titles_never_written(): array {
		return [
			'empty'               => [ '' ],
			'only whitespace'     => [ " \n\t " ],
			'the domain'          => [ 'KestrelBay.example' ],
			'the domain, www'     => [ 'www.kestrelbay.example' ],
			'past the name bound' => [ \str_repeat( 'Kestrel Bay ', 30 ) ],
			'a generic word'      => [ 'News' ],
			'another'             => [ 'Home' ],
			'a part of the label' => [ 'Kestrel Daily' ],
		];
	}

	/**
	 * A title names the site only when its letters and digits spell the
	 * domain's registrable label, its spacing and case aside.
	 */
	public function test_a_title_spelling_the_domains_label_names_the_client(): void {
		$node = $this->titled_clients( 'organization:site-880055', 'harborgazette.example' );

		$this->tick( $node );
		$this->complete( self::GAZETTE_FEED, self::titled_rss( 'Harbor Gazette', self::GAZETTE ) );

		$this->assertSame( [ [ 'organization:site-880055', 'canonical', 'Harbor Gazette' ] ], self::brief_names( $this->names() ) );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function titles_with_an_article_or_connecting_words(): array {
		return [
			'an article the label spells' => [ 'thewren.example', 'The Wren' ],
			'a lowercase de'              => [ 'pueblodecobre.example', 'Pueblo de Cobre' ],
			'a lowercase in'              => [ 'ridersintdw.example', 'Riders in TDW' ],
		];
	}

	/**
	 * A title leading with an article its label spells, or joining its
	 * capitalized words with a lowercase connecting word, names the client.
	 *
	 * @dataProvider titles_with_an_article_or_connecting_words
	 */
	public function test_a_title_with_an_article_or_connecting_words_names_the_client( string $domain, string $title ): void {
		$node = $this->titled_clients( 'organization:site-880088', $domain );

		$this->tick( $node );
		$this->complete( "https://{$domain}/feed/", self::titled_rss( $title, "https://{$domain}" ) );

		$this->assertSame( [ [ 'organization:site-880088', 'canonical', $title ] ], self::brief_names( $this->names() ) );
	}

	/**
	 * A tagline after a separator is not part of the name: when the title's
	 * part before its first separator names the site, that part alone is
	 * written.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function titles_with_a_tagline(): array {
		return [
			'a colon'    => [ 'Wren Supper: Good Food For The Curious', 'Wren Supper' ],
			'a pipe'     => [ 'Wren Supper | Good Food For The Curious', 'Wren Supper' ],
			'a hyphen'   => [ 'Wren Supper - Good Food For The Curious', 'Wren Supper' ],
			'an en dash' => [ 'Wren Supper – Good Food For The Curious', 'Wren Supper' ],
			'an em dash' => [ 'Wren Supper — Good Food: For The Curious', 'Wren Supper' ],
			'no tagline' => [ 'Wren Supper Kitchen', 'Wren Supper Kitchen' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'titles_with_a_tagline' )]
	public function test_a_tagline_after_a_separator_is_not_written( string $title, string $written ): void {
		$node = $this->titled_clients( 'organization:site-880099', 'wrensupper.example' );

		$this->tick( $node );
		$this->complete( 'https://wrensupper.example/feed/', self::titled_rss( $title, 'https://wrensupper.example' ) );

		$this->assertSame( [ [ 'organization:site-880099', 'canonical', $written ] ], self::brief_names( $this->names() ) );
	}

	/** A title whose site name follows its separator is written whole, since its lead part names no site. */
	public function test_a_title_naming_the_site_after_its_separator_is_written_whole(): void {
		$node = $this->titled_clients( 'organization:site-880100', 'wrensupper.example' );

		$this->tick( $node );
		$this->complete( 'https://wrensupper.example/feed/', self::titled_rss( 'Recipes: Wren Supper', 'https://wrensupper.example' ) );

		$this->assertSame( [ [ 'organization:site-880100', 'canonical', 'Recipes: Wren Supper' ] ], self::brief_names( $this->names() ) );
	}

	/**
	 * A Google News copy's title ends with " - " and its `<source>`'s text;
	 * that exact suffix is cut, so it dedups against the publisher's own,
	 * while a title without a `<source>`, or with a different suffix, stays.
	 */
	public function test_a_source_suffix_is_cut_from_an_items_title(): void {
		$by = $this->items_from(
			'<?xml version="1.0"?><rss version="2.0"><channel>'
			. '<item><title>Harbor Gazette Donors Honored - Harbor Gazette</title><link>https://news.example/a</link><guid>gn-a</guid><source url="https://harborgazette.example">Harbor Gazette</source></item>'
			. '<item><title>Harbor Gazette Donors Honored - Harbor Gazette</title><link>https://news.example/b</link><guid>gn-b</guid></item>'
			. '<item><title>Tern Weekly Opens - Tern Daily</title><link>https://news.example/c</link><guid>gn-c</guid><source url="https://ternweekly.example">Tern Weekly</source></item>'
			. '</channel></rss>'
		);

		$this->assertSame(
			[ 'Harbor Gazette Donors Honored', 'Harbor Gazette Donors Honored - Harbor Gazette', 'Tern Weekly Opens - Tern Daily' ],
			[ $by['feed:gn-a']['title'], $by['feed:gn-b']['title'], $by['feed:gn-c']['title'] ]
		);
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function titles_off_the_labels_word_boundaries(): array {
		return [
			'the label running into a word' => [ 'kestrelbay.example', 'Kestrel Bayou News' ],
			'the label inside a word'       => [ 'lantern.example', 'Lanternese Weekly' ],
		];
	}

	/**
	 * The label must start and end on word boundaries, as the resolver's stem
	 * match requires, or the title names another word.
	 *
	 * @dataProvider titles_off_the_labels_word_boundaries
	 */
	public function test_a_label_off_a_word_boundary_names_no_one( string $domain, string $title ): void {
		$node = $this->titled_clients( 'organization:site-880077', $domain );

		$this->tick( $node );
		$this->complete( "https://{$domain}/feed/", self::titled_rss( $title, "https://{$domain}" ) );

		$this->assertSame( [], $this->names() );
	}

	/** A label shorter than the resolver's least stem is too common a word to vouch for a title. */
	public function test_a_short_label_names_no_one(): void {
		$node = $this->titled_clients( 'organization:site-880066', 'abq.example' );

		$this->tick( $node );
		$this->complete( 'https://abq.example/feed/', self::titled_rss( 'ABQ Tide Desk', 'https://abq.example' ) );

		$this->assertSame( [], $this->names() );
	}

	/**
	 * A title that is empty, only whitespace, the bare domain again, or past
	 * the name bound names no one.
	 *
	 * @dataProvider titles_never_written
	 */
	public function test_a_title_naming_nothing_new_is_never_written( string $title ): void {
		$node = $this->titled_clients();

		$this->tick( $node );
		$this->complete( self::KESTREL_FEED, self::titled_rss( $title, 'https://kestrelbay.example' ) );

		$this->assertSame( [], $this->names() );
	}

	/**
	 * Client feeds on, over a client the roster named by its domain, the
	 * Kestrel unless another is given, and the Sandpiper Gazette, a client
	 * already holding a name.
	 *
	 * @param string $key    The domain-named client.
	 * @param string $domain Its domain.
	 */
	private function titled_clients( string $key = self::KESTREL, string $domain = 'kestrelbay.example' ): Feed_Source_Node {
		$this->start_intel_graph();
		$this->seed(
			self::entity( $key, $domain, [ 'domain' => [ $domain ] ] ),
			self::entity( 'organization:sandpiper-gazette', 'Sandpiper Gazette', [ 'domain' => [ 'sandpipergazette.example' ] ] ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:sandpiper-gazette', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
			]
		);
		$node = $this->feed();
		$node->set_client_feeds( true );
		return $node;
	}

	/** An RSS body titled $title, listing one item on $site from an hour ago. */
	private static function titled_rss( string $title, string $site ): string {
		$when = \gmdate( 'D, d M Y H:i:s', (int) Core::$now - 3600 );
		return '<?xml version="1.0"?><rss version="2.0"><channel><title>' . \htmlspecialchars( $title, \ENT_XML1 ) . "</title><item><title>Tide</title><link>{$site}/tide</link><guid>tide-1</guid><pubDate>{$when} GMT</pubDate></item></channel></rss>";
	}

	/**
	 * The names the feed wrote, as the writer took them.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function names(): array {
		return \array_values( \array_filter( \array_column( $this->writer->captured, Message::VALUE ), static fn ( array $event ): bool => 'name_added' === $event['event'] ) );
	}

	/**
	 * Each name as its entity, kind and value.
	 *
	 * @param list<array<string,mixed>> $names The names.
	 * @return list<list<mixed>>
	 */
	private static function brief_names( array $names ): array {
		return \array_map( static fn ( array $name ): array => [ $name['entity_key'], $name['kind'], $name['value'] ], $names );
	}

	/** A client item's key counts its domain against the key cap, through the one record path. */
	public function test_a_client_items_key_counts_its_domain_against_the_cap(): void {
		$stderr = $this->capture_stderr();
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed();
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );
		$guid = \str_repeat( 'g', 486 );

		$this->complete( 'https://harborgazette.example/feed/', self::rss( [ [ $guid, 'Long', (int) Core::$now - 60, self::GAZETTE ] ] ) );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: refused an item whose key runs 513 bytes, past 512: feed:harborgazette.example:ggg' ) );
	}

	/** A stored client domain that is not a plain hostname is never fetched, and is named. */
	public function test_a_client_domain_that_is_not_a_hostname_is_skipped_and_named(): void {
		$stderr = $this->capture_stderr();
		$this->start_intel_graph();
		$this->seed(
			self::entity( 'organization:quay-press', 'Quay Press', [ 'domain' => [ '10.0.0.5' ] ] ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:quay-press', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ]
		);
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );

		$this->tick( $node );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: skipping a client domain that is not a plain hostname: 10.0.0.5: its top-level domain is not alphabetic' ) );
	}

	/** A configured feed is public-only too: one naming a private address is refused, never fetched, and fails. */
	public function test_a_feed_url_naming_a_private_address_is_refused(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->feed( 'http://10.20.30.40/feed/', 'https://kite-2201.example/feed/' );

		$this->tick( $node );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a fetch failed for http://10.20.30.40/feed/: refused private address http://10.20.30.40/feed/' ) );
	}

	/** Curl follows no redirect; the feed follows each Location itself, keeping the client's lane. */
	public function test_a_redirect_is_followed_by_the_feed_keeping_its_lane(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed();
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );

		$this->redirect( 'https://harborgazette.example/feed/', 'https://cdn-4410.example/gazette.xml' );
		$this->complete( 'https://cdn-4410.example/gazette.xml', self::rss( [ [ 'moved', 'Moved', (int) Core::$now - 60, self::GAZETTE ] ] ) );

		$this->assertFalse( $this->opts['https://harborgazette.example/feed/'][ \CURLOPT_FOLLOWLOCATION ] );
		$this->assertSame( [ 'https://harborgazette.example/feed/', 'https://pelican-7714.example/feed/', 'https://cdn-4410.example/gazette.xml' ], $this->dispatched );
		$this->assertSame( [ 'feed:harborgazette.example:moved' ], \array_keys( $this->recorded() ) );
	}

	/** The news node's searches share one host, so they go out one at a time. */
	public function test_searches_on_one_host_go_out_one_at_a_time(): void {
		$searches = \array_map( static fn ( int $i ): string => "https://search-7713.example/rss?q=%22Heron+{$i}%22&hl=en-US", \range( 1, 9 ) );
		$node     = $this->feed( ...$searches );

		$this->tick( $node );
		$this->assertSame( [ $searches[0] ], $this->dispatched );

		$this->complete( $searches[0], self::RSS );
		$this->assertSame( [ $searches[0], $searches[1] ], $this->dispatched );
	}

	/** A client feed redirected to a configured feed's host keeps the client's lane. */
	public function test_a_client_feed_redirected_to_a_configured_host_keeps_its_lane(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );
		$now = (int) Core::$now;

		$this->redirect( 'https://harborgazette.example/feed/', 'https://kite-2201.example/gazette.xml' );
		$this->complete( 'https://kite-2201.example/feed/', self::rss( [ [ 'kite-own', 'Kite', $now - 60, 'https://kite-2201.example' ] ] ) );
		$this->complete( 'https://kite-2201.example/gazette.xml', self::rss( [ [ 'moved', 'Moved', $now - 60, self::GAZETTE ], [ 'stray', 'Stray', $now - 60, 'https://kite-2201.example' ] ] ) );

		$this->assertSame( [ 'feed:kite-own', 'feed:harborgazette.example:moved' ], \array_keys( $this->recorded() ) );
	}

	/** A configured feed redirected to a client's /feed/ keeps the configured lane. */
	public function test_a_configured_feed_redirected_to_a_clients_feed_keeps_its_lane(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$this->tick( $node );

		$this->redirect( 'https://kite-2201.example/feed/', 'https://harborgazette.example/feed/' );
		$this->complete( 'https://harborgazette.example/feed/', self::rss( [ [ 'undated', 'Undated', null, 'https://salemledger.example' ] ] ) );

		$this->assertSame( [ 'feed:undated' ], \array_keys( $this->recorded() ) );
	}

	/**
	 * A configured feed redirected onto the very url a client's feed is
	 * fetching keeps its own lane, and the client keeps its, though both
	 * fetch the one url.
	 */
	public function test_a_redirect_onto_a_client_feed_in_flight_keeps_both_lanes(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed( 'https://kite-2201.example/feed/' );
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );
		$body = self::rss( [ [ 'own-site', 'Own', (int) Core::$now - 60, self::GAZETTE ], [ 'offsite', 'Offsite', (int) Core::$now - 60, 'https://salemledger.example' ] ] );

		$this->redirect( 'https://kite-2201.example/feed/', 'https://harborgazette.example/feed/' );
		$this->complete( 'https://harborgazette.example/feed/', $body );
		$this->complete( 'https://harborgazette.example/feed/', $body );

		$this->assertSame( [ 'feed:harborgazette.example:own-site', 'feed:own-site', 'feed:offsite' ], \array_keys( $this->recorded() ) );
	}

	/** A redirect onto a link-local url is a public-only hop like any other, so Curl refuses it. */
	public function test_a_redirect_to_a_private_address_is_refused(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->feed( 'https://kite-2201.example/feed/' );
		$this->tick( $node );

		$this->redirect( 'https://kite-2201.example/feed/', 'http://169.254.169.254/feed/' );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a fetch failed for https://kite-2201.example/feed/: refused private address http://169.254.169.254/feed/' ) );
	}

	/** A feed follows at most MAX_REDIRECTS hops; the next records nothing, logged. */
	public function test_a_feed_follows_at_most_five_redirects(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->feed( 'https://hop-0.example/feed/' );
		$this->tick( $node );

		for ( $hop = 0; $hop <= Curl_Node::MAX_REDIRECTS; $hop++ ) {
			$this->redirect( "https://hop-{$hop}.example/feed/", 'https://hop-' . ( $hop + 1 ) . '.example/feed/' );
		}

		$this->assertSame( 5, Curl_Node::MAX_REDIRECTS );
		$this->assertCount( 6, $this->dispatched );
		$this->assertSame( 'https://hop-5.example/feed/', \end( $this->dispatched ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a request redirected past 5 hops; recording nothing: https://hop-6.example/feed/' ) );
	}

	/** The Gazette's conventional feed, and the home page discovery reads. */
	private const GAZETTE_FEED = 'https://harborgazette.example/feed/';

	private const GAZETTE_HOME = 'https://harborgazette.example/';

	/** Where the Gazette's home page says its feed lives. */
	private const GAZETTE_FOUND = 'https://gazette-news-6612.example/rss.xml';

	/** The Pelican's conventional feed, which answers every pass. */
	private const PELICAN_FEED = 'https://pelican-7714.example/feed/';

	/**
	 * The client feeds' source: the graph seeded with the clients, client
	 * feeds on, a pass every 1,800 seconds.
	 */
	private function client_feed(): Feed_Source_Node {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed();
		$node->set_client_feeds( true );
		$node->interpreter()->dispatch( 'set_interval', [ '1800' ] );
		return $node;
	}

	/** A client pass opened, fetching the Gazette's and the Pelican's feeds. */
	private function client_pass(): Feed_Source_Node {
		$node = $this->client_feed();
		$this->tick( $node );
		return $node;
	}

	/** A home page whose head advertises one feed of $type at $href. */
	private static function home_page( string $type, string $href ): string {
		return "<!doctype html><html><head><title>Gazette</title><link rel='stylesheet' href='/a.css'><link rel=\"alternate\" type=\"{$type}\" title=\"Gazette &raquo; Feed\" href=\"{$href}\"></head><body>news</body></html>";
	}

	/** The feed's memory of each client domain, as its Table holds it. */
	private static function domains(): mixed {
		$table = Core::node( 'feed:frontier' );
		\assert( $table instanceof Table_Node );
		return $table->lookup( 'domains' );
	}

	/** Seed the feed's memory of each client domain, in its Table, before a pass asks for it. */
	private function remembered( array $domains ): void {
		$table = Core::node( 'feed:frontier' );
		\assert( $table instanceof Table_Node );
		$table->store( 'domains', $domains );
	}

	/**
	 * A client feed answering 404 sends discovery to the client's home page,
	 * whose advertised feed is fetched in the same pass under the client's
	 * lane, which now takes the advertised feed's host too.
	 */
	public function test_a_client_feed_answering_404_is_found_on_its_home_page(): void {
		$this->client_pass();
		$now = (int) Core::$now;

		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );
		$this->complete( self::GAZETTE_FOUND, self::rss( [ [ 'found', 'Found', $now - 60, 'https://gazette-news-6612.example' ], [ 'own', 'Own', $now - 60, self::GAZETTE ], [ 'stray', 'Stray', $now - 60, 'https://salemledger.example' ] ] ) );

		$this->assertSame( [ self::GAZETTE_FEED, self::PELICAN_FEED, self::GAZETTE_HOME, self::GAZETTE_FOUND ], $this->dispatched );
		$this->assertSame( [ 'feed:harborgazette.example:found', 'feed:harborgazette.example:own' ], \array_keys( $this->recorded() ) );
	}

	/** A found feed on a `www.` host admits items on the bare host and its subdomains, as a domain identifier spells it. */
	public function test_a_found_feed_on_a_www_host_keeps_the_bare_host_in_its_lane(): void {
		$this->client_pass();
		$now   = (int) Core::$now;
		$found = 'https://www.osprey-cdn-4420.example/feed.xml';

		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', $found ) );
		$this->complete( $found, self::rss( [ [ 'bare-4420', 'Bare', $now - 60, 'https://osprey-cdn-4420.example' ], [ 'sub-4420', 'Sub', $now - 60, 'https://news.osprey-cdn-4420.example' ], [ 'stray-4420', 'Stray', $now - 60, 'https://salemledger.example' ] ] ) );

		$this->assertSame( [ 'feed:harborgazette.example:bare-4420', 'feed:harborgazette.example:sub-4420' ], \array_keys( $this->recorded() ) );
	}

	/** A feed the home page advertised is fetched from then on, by the next process's passes too. */
	public function test_a_found_feed_is_fetched_from_then_on(): void {
		$node = $this->client_pass();
		$this->complete( self::GAZETTE_FEED, '<html>a theme page-7740</html>' );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );
		$this->complete( self::GAZETTE_FOUND, self::rss( [] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->tick( $this->successor( $node ) );

		$this->assertSame( [ self::GAZETTE_FOUND, self::PELICAN_FEED ], \array_slice( $this->dispatched, -2 ) );
	}

	/**
	 * A home page that redirects is read where it lands, so a relative feed
	 * link resolves against that host, entities decoded, and Atom is taken.
	 */
	public function test_a_relative_feed_link_resolves_where_the_home_page_landed(): void {
		$this->client_pass();
		$now = (int) Core::$now;

		$this->complete( self::GAZETTE_FEED, '', 410 );
		$this->redirect( self::GAZETTE_HOME, 'https://gazette-moved-6613.example/' );
		$this->complete( 'https://gazette-moved-6613.example/', self::home_page( 'application/atom+xml', '/search/?f=rss&amp;t=article' ) );
		$this->complete( 'https://gazette-moved-6613.example/search/?f=rss&t=article', self::rss( [ [ 'moved', 'Moved', $now - 60, 'https://gazette-moved-6613.example' ] ] ) );

		$this->assertSame( 'https://gazette-moved-6613.example/search/?f=rss&t=article', \end( $this->dispatched ) );
		$this->assertSame( [ 'feed:harborgazette.example:moved' ], \array_keys( $this->recorded() ) );
	}

	/**
	 * Where a home page landed, the page, and the feed it advertises.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function advertised_feeds(): array {
		$moved = 'https://gazette-moved-6613.example/en/front';
		return [
			'whole'              => [ $moved, self::home_page( 'application/rss+xml', 'http://feeds-6614.example/g' ), 'http://feeds-6614.example/g' ],
			'from the scheme'    => [ $moved, self::home_page( 'application/rss+xml', '//feeds-6614.example/g' ), 'https://feeds-6614.example/g' ],
			'from the directory' => [ $moved, self::home_page( 'application/atom+xml', 'atom.xml' ), 'https://gazette-moved-6613.example/en/atom.xml' ],
			'from the root page' => [ self::GAZETTE_HOME, self::home_page( 'application/rss+xml', 'rss' ), 'https://harborgazette.example/rss' ],
			'single and bare'    => [ $moved, "<link type='Application/RSS+XML' rel='home alternate' href=/en/rss>", 'https://gazette-moved-6613.example/en/rss' ],
			'after another link' => [ $moved, "<link rel=alternate type=text/html href=/es/><link rel=alternate type=application/atom+xml href=/en/atom>", 'https://gazette-moved-6613.example/en/atom' ],
		];
	}

	/** A home page's feed link resolves against where the page landed. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'advertised_feeds' )]
	public function test_a_home_pages_feed_link_resolves_where_it_landed( string $landed, string $page, string $fetched ): void {
		$this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 404 );
		if ( self::GAZETTE_HOME !== $landed ) {
			$this->redirect( self::GAZETTE_HOME, $landed );
		}

		$this->complete( $landed, $page );

		$this->assertSame( $fetched, \end( $this->dispatched ) );
	}

	/**
	 * A home page's link the feed may not fetch, and why it is refused.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refused_feeds(): array {
		return [
			'not over http' => [ 'feed://gazette-moved-6613.example/rss', 'invalid url feed://gazette-moved-6613.example/rss' ],
			'userinfo'      => [ 'https://reader:pw-6615@feeds-6614.example/g', 'invalid url carrying userinfo' ],
			'an address'    => [ 'http://10.0.0.5/rss', '10.0.0.5: its top-level domain is not alphabetic' ],
			'a single word' => [ 'https://intranet-6616/rss', 'intranet-6616: it has a single label' ],
		];
	}

	/**
	 * A home page advertising a feed that is not an http or https url on a
	 * plain hostname fetches nothing: the refusal is logged once, the answer
	 * fails, and discovery fails for the domain, which backs off.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_feeds' )]
	public function test_a_discovered_feed_must_be_a_web_url_on_a_plain_hostname( string $href, string $why ): void {
		$stderr = $this->capture_stderr();
		$this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 404 );

		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', $href ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->assertSame( [ self::GAZETTE_FEED, self::PELICAN_FEED, self::GAZETTE_HOME ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: refused a feed the home page advertised at https://harborgazette.example/: ' . $why ) );
		$this->assertSame( 1, self::domains()['harborgazette.example']['failures'] );
	}

	/** A home page answering a 4xx backs its domain off, so the next pass skips it. */
	public function test_a_home_page_answering_a_4xx_backs_off(): void {
		$node = $this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, '', 403 );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->tick( $node );

		$this->assertSame( [ self::GAZETTE_FEED, self::PELICAN_FEED, self::GAZETTE_HOME, self::PELICAN_FEED ], $this->dispatched );
	}

	/** A home page advertising no feed fails its answer, naming the page. */
	public function test_a_home_page_advertising_no_feed_fails_naming_it(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->client_feed();
		$this->publishing();
		$this->tick( $node );

		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, '<html><head><link rel="alternate" type="text/html" href="/es/"></head></html>' );
		$this->complete( self::PELICAN_FEED, '', 503 );

		$this->assertSame( [ 'failing', 3, 3 ], [ $this->published( 'feed' )['state'], $this->published( 'feed' )['requests'], $this->published( 'feed' )['failed'] ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: no RSS or Atom feed is advertised at https://harborgazette.example/: ' ) );
		$this->assertCount( 3, $this->dispatched, 'nothing is fetched for a feed that is not named on any page' );
	}

	/**
	 * A client feed redirected to another domain whose items none link to
	 * the client fails, named once, and its home page names where the feed
	 * moved, which the lane then takes.
	 */
	public function test_a_client_feed_redirected_off_its_domain_fails_and_is_found(): void {
		$stderr = $this->capture_stderr();
		$this->client_pass();
		$now  = (int) Core::$now;
		$body = self::rss( [ [ 'wendlemark', 'Wendlemark', $now - 60, 'https://wendlemark-7781.example' ] ] );

		$this->redirect( self::GAZETTE_FEED, 'https://wendlemark-7781.example/feed/' );
		$this->complete( 'https://wendlemark-7781.example/feed/', $body );
		$this->assertSame( [], $this->recorded() );
		$this->redirect( self::GAZETTE_HOME, 'https://wendlemark-7781.example/' );
		$this->complete( 'https://wendlemark-7781.example/', self::home_page( 'application/rss+xml', 'https://wendlemark-7781.example/feed/' ) );
		$this->complete( 'https://wendlemark-7781.example/feed/', $body );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a client feed redirected off its domain: https://harborgazette.example/feed/: https://wendlemark-7781.example/feed/' ) );
		$this->assertSame( [ 'feed:harborgazette.example:wendlemark' ], \array_keys( $this->recorded() ) );
	}

	/**
	 * Run $passes passes of the client feeds, one every 1,800 seconds after
	 * the seeded clock, answering the Pelican's feed with an empty one and
	 * each Gazette fetch with the next of $answers: `feed`, a status, or
	 * `curl <code>`.
	 *
	 * @param list<string|int> $answers How each Gazette fetch is answered, in order.
	 * @param int              $from    The first pass run.
	 * @return list<int> The passes, counted from 1, that fetched the Gazette.
	 */
	private function gazette_passes( Feed_Source_Node $node, int $passes, array $answers, int $from = 1 ): array {
		$fetched = [];
		for ( $pass = $from; $pass < $from + $passes; $pass++ ) {
			$at          = (float) ( self::$oct_20 + $pass * 1800 + 5 );
			Core::$now   = $at;
			Core::$clock = static fn (): float => $at;
			$seen        = \count( $this->dispatched );
			$this->tick( $node );
			$gazette = \array_values( \array_diff( \array_slice( $this->dispatched, $seen ), [ self::PELICAN_FEED ] ) );
			if ( [] !== $gazette ) {
				$fetched[] = $pass;
				$answer    = \array_shift( $answers );
				match ( true ) {
					'feed' === $answer                                       => $this->complete( $gazette[0], self::rss( [] ) ),
					\is_string( $answer ) && \str_starts_with( $answer, 'curl ' ) => $this->complete( $gazette[0], '', 0, (int) \substr( $answer, 5 ) ),
					default                                                  => $this->complete( $gazette[0], '', (int) $answer ),
				};
			}
			$this->complete( self::PELICAN_FEED, self::rss( [] ) );
		}
		return $fetched;
	}

	/**
	 * A client domain whose host does not resolve sits out whole passes,
	 * one, then two, then four, and an answering feed resets it.
	 */
	public function test_a_domain_that_keeps_failing_skips_passes_doubling(): void {
		$node = $this->client_feed();

		$fetched = $this->gazette_passes( $node, 13, [ 'curl 6', 'curl 6', 'curl 6', 'feed', 'feed' ] );

		$this->assertSame( [ 1, 3, 6, 11, 12, 13 ], $fetched );
	}

	/**
	 * A client domain whose host does not resolve three fetches running is
	 * published dead, since the first of the three, and an answering feed
	 * takes it off the list.
	 */
	public function test_a_host_unresolved_three_passes_running_is_published_dead(): void {
		$node = $this->client_feed();
		$this->publishing();

		$this->assertSame( [ 1, 3 ], $this->gazette_passes( $node, 5, [ 'curl 6', 'curl 6' ] ) );
		$this->assertSame( [], $this->published( 'feed' )['dead_clients'], 'two fetches are not three' );
		$this->assertSame( [ 6 ], $this->gazette_passes( $node, 1, [ 'curl 6' ], 6 ) );
		$this->assertSame( [ 'harborgazette.example' => self::$oct_20 + 1800 + 5 ], $this->published( 'feed' )['dead_clients'] );

		$this->assertSame( [ 11 ], $this->gazette_passes( $node, 5, [ 'feed' ], 7 ) );
		$this->assertSame( [], $this->published( 'feed' )['dead_clients'] );
	}

	/**
	 * A domain that answers anything breaks its run, even a body that is not
	 * a feed, so a found feed on another host that does not resolve starts a
	 * run of its own.
	 */
	public function test_an_answered_fetch_breaks_the_unresolved_run(): void {
		$node = $this->client_feed();
		$this->publishing();
		$this->gazette_passes( $node, 5, [ 'curl 6', 'curl 6' ] );
		$at          = (float) ( self::$oct_20 + 6 * 1800 + 5 );
		Core::$now   = $at;
		Core::$clock = static fn (): float => $at;
		$this->tick( $node );

		$this->complete( self::GAZETTE_FEED, '<html>not-a-feed-2290</html>' );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );
		$this->complete( self::GAZETTE_FOUND, '', 0, \CURLE_COULDNT_RESOLVE_HOST );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->assertSame( [ 1, (int) $at ], [ self::domains()['harborgazette.example']['unresolved'], self::domains()['harborgazette.example']['unresolved_since'] ] );
		$this->assertSame( [], $this->published( 'feed' )['dead_clients'] );
	}

	/** A 5xx, a timeout or another curl error between two unresolved fetches breaks the run, so three never list the domain. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'passing_failures' )]
	public function test_only_an_unresolved_host_counts_toward_dead( string|int $failure ): void {
		$node = $this->client_feed();
		$this->publishing();

		$fetched = $this->gazette_passes( $node, 7, [ 'curl 6', 'curl 6', $failure, 'curl 6' ] );

		$this->assertSame( [ 1, 3, 6, 7 ], $fetched );
		$this->assertSame( [], $this->published( 'feed' )['dead_clients'] );
	}

	/** A domain's backoff stops doubling at 48 passes, kept as the moment it may be fetched again. */
	public function test_a_domains_backoff_is_capped_at_48_passes(): void {
		$node = $this->client_feed();
		$this->remembered( [ 'harborgazette.example' => [ 'feed' => '', 'failures' => 9, 'retry_at' => 0, 'unresolved' => 4, 'unresolved_since' => self::$oct_01 ] ] );

		$this->gazette_passes( $node, 1, [ 'curl 6' ] );

		$this->assertSame( 48, Feed_Source_Node::MAX_SKIP_PASSES );
		$this->assertSame( [ 'feed' => '', 'failures' => 10, 'retry_at' => self::$oct_20 + ( 1 + 48 + 1 ) * 1800, 'unresolved' => 5, 'unresolved_since' => self::$oct_01 ], self::domains()['harborgazette.example'] );
	}

	/**
	 * A found feed that fails is forgotten and backs its domain off, and
	 * once its skip runs out the conventional feed is fetched again.
	 */
	public function test_a_found_feed_that_fails_is_forgotten_after_its_skip(): void {
		$node = $this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );
		$this->complete( self::GAZETTE_FOUND, self::rss( [] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );
		$seen = \count( $this->dispatched );

		$fetched = $this->gazette_passes( $node, 3, [ 410, 'feed' ] );

		$this->assertSame( [ 1, 3 ], $fetched, 'the pass after the found feed fails is skipped' );
		$this->assertSame( [ self::GAZETTE_FOUND, self::PELICAN_FEED, self::PELICAN_FEED, self::GAZETTE_FEED, self::PELICAN_FEED ], \array_slice( $this->dispatched, $seen ) );
	}

	/**
	 * Failures that are not any domain's fault, each backing nothing off.
	 *
	 * @return array<string,array{0:string|int}>
	 */
	public static function passing_failures(): array {
		return [
			'a 5xx'            => [ 503 ],
			'a timeout'        => [ 'curl ' . \CURLE_OPERATION_TIMEDOUT ],
			'a TLS handshake'  => [ 'curl ' . \CURLE_SSL_CONNECT_ERROR ],
			'an oversize body' => [ 'curl ' . \CURLE_FILESIZE_EXCEEDED ],
		];
	}

	/** A 5xx, a timeout, curl 35 and curl 63 are not structural: the domain skips no pass. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'passing_failures' )]
	public function test_only_a_structural_failure_backs_off( string|int $failure ): void {
		$node = $this->client_feed();

		$fetched = $this->gazette_passes( $node, 3, [ $failure, 'feed', 'feed' ] );

		$this->assertSame( [ 1, 2, 3 ], $fetched );
	}

	/**
	 * The domains are one row of the feed's Table: recalled once a pass,
	 * and written once, as the pass ends, only when the pass changed them.
	 */
	public function test_the_domains_are_recalled_once_a_pass_and_written_only_when_changed(): void {
		$node  = $this->client_feed();
		$table = $this->recording_table();
		$this->tick( $node );
		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );
		$this->complete( self::GAZETTE_FOUND, self::rss( [] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );
		$this->assertSame( [ 'GET domains', 'RM domains', 'ADD domains' ], $table->memory_asks() );

		$this->tick( $node );
		$this->complete( self::GAZETTE_FOUND, self::rss( [] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->assertSame( [ 'GET domains', 'RM domains', 'ADD domains', 'GET domains' ], $table->memory_asks() );
	}

	/** A pass whose only news is a domain sitting out writes nothing. */
	public function test_a_pass_that_only_skips_writes_nothing(): void {
		$node  = $this->client_feed();
		$table = $this->recording_table();

		$fetched = $this->gazette_passes( $node, 2, [ 'curl 6' ] );

		$this->assertSame( [ 1 ], $fetched, 'the second pass sits the domain out' );
		$this->assertSame( [ 'GET domains', 'RM domains', 'ADD domains', 'GET domains' ], $table->memory_asks() );
	}

	/**
	 * A recall the Table does not answer is not an empty memory: the pass
	 * runs with no backoff, and the row stands as it was.
	 */
	public function test_a_failed_recall_backs_nothing_off_and_leaves_the_row(): void {
		$row  = [ 'harborgazette.example' => [ 'feed' => '', 'failures' => 3, 'retry_at' => self::$oct_20 + 99000 ] ];
		$node = $this->client_feed();
		$this->remembered( $row );
		$recording             = $this->recording_table();
		$recording->refuse_get = true;

		$this->gazette_passes( $node, 1, [ 'curl 6' ] );

		$this->assertContains( self::GAZETTE_FEED, $this->dispatched, 'the remembered backoff does not hold the domain back' );
		$this->assertSame( [ 'GET domains' ], $recording->memory_asks() );
		$this->assertSame( $row, self::domains() );
	}

	/** Every feed request, a configured feed's, a client's and a home page's, bounds its connection at 10 seconds within Curl's 30. */
	public function test_every_feed_request_bounds_its_connection(): void {
		$node = $this->client_feed();
		$node->interpreter()->dispatch( 'add_url', [ 'https://kite-2201.example/feed/' ] );
		$this->tick( $node );
		$this->complete( self::GAZETTE_FEED, '', 404 );

		$this->assertSame( 10, Feed_Source_Node::CONNECT_TIMEOUT );
		foreach ( [ 'https://kite-2201.example/feed/', self::GAZETTE_FEED, self::GAZETTE_HOME ] as $url ) {
			$this->assertSame( [ 10, Curl_Node::REQUEST_TIMEOUT * 1000 ], [ $this->opts[ $url ][ \CURLOPT_CONNECTTIMEOUT ], $this->opts[ $url ][ \CURLOPT_TIMEOUT_MS ] ], $url );
		}
	}

	/**
	 * The feed's Table, as one recording the memory asks it takes, over the
	 * same file, in its name, so the feed asks it.
	 */
	private function recording_table(): Recording_Table_Node {
		Core::node( 'feed:frontier' )?->remove_node();
		$table = new Recording_Table_Node();
		$this->bound(
			static function () use ( $table ): void {
				$table->name( 'feed:frontier' );
				$table->arguments( \array_values( Feed_Source_Node::owned_table( 'frontier', 'feed', [] ) ) );
			}
		);
		$table->sink( Core::node( \Newspack_Nodes\Node_Names::COMMAND_INTERPRETER ) );
		return $table;
	}

	/**
	 * A feed the home page advertised, cut by a recycle before it answers,
	 * keeps its lane in the next process and is remembered from then on.
	 */
	public function test_a_found_feed_resumed_in_a_new_process_keeps_its_lane_and_is_remembered(): void {
		$node = $this->client_pass();
		$now  = (int) Core::$now;
		$this->complete( self::GAZETTE_FEED, '', 404 );
		$this->complete( self::GAZETTE_HOME, self::home_page( 'application/rss+xml', self::GAZETTE_FOUND ) );

		$successor = $this->successor( $node );
		$this->fire_at( $successor, Core::$now + 11.0 );
		$this->complete( self::GAZETTE_FOUND, self::rss( [ [ 'found-9', 'Found', $now - 60, 'https://gazette-news-6612.example' ], [ 'stray-9', 'Stray', $now - 60, 'https://salemledger.example' ] ] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );
		$this->tick( $successor );

		$this->assertSame( [ 'feed:harborgazette.example:found-9' ], \array_keys( $this->recorded() ) );
		$this->assertSame( [ 'feed' => self::GAZETTE_FOUND, 'failures' => 0, 'retry_at' => 0, 'unresolved' => 0, 'unresolved_since' => 0 ], self::domains()['harborgazette.example'] );
		$this->assertSame( [ self::GAZETTE_FOUND, self::PELICAN_FEED ], \array_slice( $this->dispatched, -2 ) );
	}

	/** A backoff a pass sets is written at the next tick, so the process after a recycle holds it. */
	public function test_a_backoff_set_before_a_recycle_holds_after_it(): void {
		$at          = (float) self::$oct_20 + 5;
		Core::$now   = $at;
		Core::$clock = static fn (): float => $at;
		$node        = $this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 0, \CURLE_COULDNT_RESOLVE_HOST );
		$this->fire_at( $node, Core::$now + 3.0 );

		$fetched = $this->gazette_passes( $this->successor( $node ), 2, [ 'feed' ] );

		$this->assertSame( [ 2 ], $fetched, 'the first pass after the recycle sits the domain out' );
	}

	/** A write the Table refuses leaves the domains to write again, which the next tick does. */
	public function test_a_refused_write_is_made_again_at_the_next_tick(): void {
		$node             = $this->client_feed();
		$table            = $this->recording_table();
		$table->refuse_add = true;
		$this->tick( $node );
		$this->complete( self::GAZETTE_FEED, '', 0, \CURLE_COULDNT_RESOLVE_HOST );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );
		$this->assertNull( self::domains(), 'the pass end removed the row and could not add it' );

		$table->refuse_add = false;
		$this->fire_at( $node, Core::$now + 7.0 );

		$this->assertSame( 1, self::domains()['harborgazette.example']['failures'] ?? null );
	}

	/** A clean stop writes what the pass changed since the last tick, so the next process holds it. */
	public function test_a_clean_stop_writes_the_domains(): void {
		$at          = (float) self::$oct_20 + 5;
		Core::$now   = $at;
		Core::$clock = static fn (): float => $at;
		$node        = $this->client_pass();
		$this->complete( self::GAZETTE_FEED, '', 0, \CURLE_COULDNT_RESOLVE_HOST );

		$node->shutdown_sweep();
		$fetched = $this->gazette_passes( $this->successor( $node ), 2, [ 'feed' ] );

		$this->assertSame( [ 2 ], $fetched, 'the first pass after the recycle sits the domain out' );
	}

	/** The row keeps the pass's clients alone, so a churned client or one never a client leaves it. */
	public function test_the_domains_row_sheds_every_domain_no_longer_a_client(): void {
		$node = $this->client_feed();
		$this->remembered(
			[
				'harborgazette.example' => [ 'feed' => self::GAZETTE_FOUND, 'failures' => 0, 'retry_at' => 0 ],
				'salemledger.example'   => [ 'feed' => '', 'failures' => 2, 'retry_at' => self::$oct_20 + 7200 ],
				'grebe-5519.example'    => [ 'feed' => 'https://grebe-5519.example/atom.xml', 'failures' => 0, 'retry_at' => 0 ],
			]
		);
		$this->tick( $node );
		$this->complete( self::GAZETTE_FOUND, self::rss( [] ) );
		$this->complete( self::PELICAN_FEED, self::rss( [] ) );

		$this->assertSame( [ 'harborgazette.example' ], \array_keys( self::domains() ) );
	}

	/** The toggle round-trips through dump_config(), and a source with it off dumps no line. */
	public function test_set_client_feeds_rides_the_dump(): void {
		$node = $this->feed();
		$this->assertStringNotContainsString( 'set_client_feeds', $node->dump_config() );
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );

		$dump = $node->dump_config();
		$this->assertStringContainsString( "command_node feed:config set_client_feeds true\n", $dump );

		Core::reset();
		$replayed = new Feed_Source_Node();
		$replayed->name( 'feed' );
		$this->replay_config( $dump, $replayed );
		$this->assertTrue( $this->read_private( $replayed, 'client_feeds' ) );
	}

	/**
	 * The Gazette, a client whose domain is configured nowhere; the
	 * Pelican, a client whose feed is configured; the Ledger, a churned
	 * client; and the Osprey, never a client.
	 */
	private function seed_clients(): void {
		$this->seed(
			self::entity( 'organization:harbor-gazette', 'Harbor Gazette', [ 'domain' => [ 'www.HarborGazette.example' ] ] ),
			self::entity( 'organization:pelican-post', 'Pelican Post', [ 'domain' => [ 'pelican-7714.example' ] ] ),
			self::entity( 'organization:salem-ledger', 'Salem Ledger', [ 'domain' => [ 'salemledger.example' ] ] ),
			self::entity( 'organization:osprey-times', 'Osprey Times', [ 'domain' => [ 'ospreytimes.example' ] ] ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:harbor-gazette', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:pelican-post', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:salem-ledger', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_ended', [ 'entity_key' => 'organization:salem-ledger', 'role' => 'client', 'from_ts' => self::$jan_01, 'to_ts' => self::$oct_01 ], 'user:41', 'fixture-9' ),
			]
		);
	}

	public function test_add_url_refuses_a_blank_url(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'usage: add_url <url>' );
		$node->interpreter()->dispatch( 'add_url', [ '   ' ] );
	}

	public function test_add_url_accumulates_ordered_urls_into_config(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_url', [ 'https://example.com/a.xml' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_url', [ 'https://example.com/b.xml' ] ) );

		$this->assertSame(
			[ 'https://example.com/a.xml', 'https://example.com/b.xml' ],
			$this->config_of( $node )['feeds']
		);
	}

	public function test_dump_config_re_emits_add_url_lines_in_order(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );
		$node->interpreter()->dispatch( 'add_url', [ 'https://example.com/a.xml' ] );
		$node->interpreter()->dispatch( 'add_url', [ 'https://example.com/b.xml' ] );

		$dump = $node->dump_config();
		$this->assertStringContainsString( 'command_node feed:config add_url https://example.com/a.xml', $dump );
		$this->assertStringContainsString( 'command_node feed:config add_url https://example.com/b.xml', $dump );
	}

	public function test_add_url_command_dispatches_through_sibling_interpreter(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );

		$sibling = Core::node( 'feed:config' );
		$this->assertNotNull( $sibling );

		$result = $sibling->dispatch( 'add_url', [ 'https://example.com/c.xml' ] );

		$this->assertSame( "ok\n", $result );
		$this->assertSame( [ 'https://example.com/c.xml' ], $this->config_of( $node )['feeds'] );
	}

	public function test_node_schema_declares_feed_source_contract(): void {
		$schema = Feed_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertSame( '{ fetching }', $schema['requests'][0]['reply_shape'] );
		$this->assertStringContainsString( 'RSS 2.0 / Atom', $schema['description'] );
		$this->assertContains( 'add_url', \array_column( $schema['commands'], 'name' ) );
		$this->assertContains( 'set_client_feeds', \array_column( $schema['commands'], 'name' ) );
	}

	public function test_remove_url_stops_fetching_it_and_keeps_the_rest(): void {
		$node = $this->feed( 'https://heron.test/feed.xml', 'https://kite.test/feed.xml' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_url', [ 'https://heron.test/feed.xml' ] ) );
		$this->tick( $node );

		$this->assertSame( [ 'https://kite.test/feed.xml' ], $this->dispatched );
	}

	public function test_remove_url_naming_no_url_is_refused_naming_it(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );
		$node->interpreter()->dispatch( 'add_url', [ 'https://kite.test/feed.xml' ] );

		try {
			$node->interpreter()->dispatch( 'remove_url', [ 'https://ghost.test/feed.xml' ] );
			$this->fail( 'an unregistered url was removed' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( "remove_url 'https://ghost.test/feed.xml' names a url this node does not declare", $e->getMessage() );
		}
		$this->assertSame( [ 'https://kite.test/feed.xml' ], $this->config_of( $node )['feeds'] );
	}

	public function test_a_removed_url_leaves_the_dump_and_its_replay(): void {
		$node = new Feed_Source_Node();
		$node->name( 'feed' );
		$node->interpreter()->dispatch( 'add_url', [ 'https://heron.test/feed.xml' ] );
		$node->interpreter()->dispatch( 'add_url', [ 'https://kite.test/feed.xml' ] );
		$node->interpreter()->dispatch( 'remove_url', [ 'https://heron.test/feed.xml' ] );

		$dump = $node->dump_config();
		$this->assertStringNotContainsString( 'heron.test', $dump );

		Core::reset();
		$replayed = new Feed_Source_Node();
		$replayed->name( 'feed' );
		$this->replay_config( $dump, $replayed );
		$this->assertSame( [ 'https://kite.test/feed.xml' ], $this->config_of( $replayed )['feeds'] );
	}

	/** config() is protected — read it via reflection, matching SettingsSyncNodeTest's registry-access pattern. */
	private function config_of( Feed_Source_Node $node ): array {
		$method = new \ReflectionMethod( $node, 'config' );
		return $method->invoke( $node );
	}
}

/**
 * A durable Table that records each ask it takes, and may refuse every
 * GET as a backend read that failed.
 */
final class Recording_Table_Node extends Table_Node {

	/** @var list<string> Each ask, its verb then its keys. */
	public array $asked = [];

	/** Whether a GET is refused. */
	public bool $refuse_get = false;

	/** Whether an ADD is refused. */
	public bool $refuse_add = false;

	public function fill( array $message ): void {
		$value         = $message[ Message::VALUE ];
		$ask           = \is_array( $value ) ? \implode( ' ', [ (string) \array_key_first( $value ), ...\array_keys( Core::arr( \reset( $value ) ) ) ] ) : \trim( Core::as_string( $value ) );
		$this->asked[] = $ask;
		if ( ( $this->refuse_get && \str_starts_with( $ask, 'GET ' ) ) || ( $this->refuse_add && \str_starts_with( $ask, 'ADD ' ) ) ) {
			$reply                     = Message::new_message();
			$reply[ Message::TYPE ]    = Message::TM_ERROR;
			$reply[ Message::FROM ]    = $this->name();
			$reply[ Message::TO ]      = $message[ Message::FROM ];
			$reply[ Message::VALUE ]   = "{$ask}: backend failed-7715\n";
			$this->sink()->fill( $reply );
			return;
		}
		parent::fill( $message );
	}

	/** @return list<string> The asks naming the feed's memory row. */
	public function memory_asks(): array {
		return \array_values( \array_filter( $this->asked, static fn ( string $ask ): bool => \str_ends_with( $ask, ' domains' ) ) );
	}
}

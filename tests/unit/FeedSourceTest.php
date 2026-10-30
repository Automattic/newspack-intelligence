<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;

require_once __DIR__ . '/../support/seeds-intel-graph.php';

/**
 * The feed source: a pass sends each feed URL to its `feed:curl` sibling,
 * at most Curl_Node::MAX_IN_FLIGHT at once, and records each body's items
 * as the body lands. The graph is real — a Router, an interpreter, the Curl
 * sibling and a capturing target — and only libcurl is stood in for,
 * through the substrate's dispatch and result seams.
 */
final class FeedSourceTest extends TestCase {
	use Seeds_Intel_Graph;

	/** The target the feed's items go to. */
	private const WRITER = 'writer-6127';

	/** The Gazette's own site, where its items link. */
	private const GAZETTE = 'https://harborgazette.example';

	/** Who asks for a TICK, and hears its answer. */
	private const ASKER = 'asker-5513';

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

	private Capture_Sink_Node $writer;

	private Capture_Sink_Node $asker;

	/** @var list<string> Every url a dispatch was handed, in order. */
	private array $dispatched = [];

	/** @var array<string,\CurlHandle> The handle each url's dispatch returned. */
	private array $handles = [];

	/** @var array<int,string> The url each handle fetches, by spl_object_id. */
	private array $url_of = [];

	/** @var array<string,array{0:int,1:string}> The status and body each url answers with. */
	private array $answers = [];

	/** @var array<string,true> Urls whose next dispatch throws. */
	private array $refusing = [];

	/** @var array<string,true> Urls the URL check refuses, as WordPress refuses a private address. */
	private array $unsafe = [];

	/** @var list<string> Every url the URL check was handed, in order. */
	private array $validated = [];

	/** @var array<string,string> The Location each url answers a 301 with. */
	private array $locations = [];

	/** @var array<string,array<int,mixed>> The opts each url's dispatch was handed. */
	private array $opts = [];

	protected function setUp(): void {
		parent::setUp();
		Event_Framework::reset();
		Event_Framework::$curl_dispatch = function ( array $opts ): \CurlHandle {
			$url                = (string) $opts[ \CURLOPT_URL ];
			$this->dispatched[] = $url;
			$this->opts[ $url ] = $opts;
			if ( isset( $this->refusing[ $url ] ) ) {
				unset( $this->refusing[ $url ] );
				throw new \RuntimeException( "dispatch-down-5108 {$url}" );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$easy                                    = \curl_init();
			$this->handles[ $url ]                   = $easy;
			$this->url_of[ \spl_object_id( $easy ) ] = $url;
			return $easy;
		};
		Curl_Node::$curl_result = function ( \CurlHandle $easy ): array {
			$url = $this->url_of[ \spl_object_id( $easy ) ];
			if ( isset( $this->locations[ $url ] ) ) {
				return [
					'code'     => 301,
					'body'     => '',
					'redirect' => $this->locations[ $url ],
				];
			}
			[ $code, $body ] = $this->answers[ $url ];
			return [
				'code'     => $code,
				'body'     => $body,
				'redirect' => '',
			];
		};
		Feed_Source_Node::$validate_url = function ( string $url ): string|false {
			$this->validated[] = $url;
			return isset( $this->unsafe[ $url ] ) ? false : $url;
		};
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$this->writer = new Capture_Sink_Node();
		$this->writer->name( self::WRITER );
		$this->asker = new Capture_Sink_Node();
		$this->asker->name( self::ASKER );
	}

	protected function tearDown(): void {
		Event_Framework::$curl_dispatch    = null;
		Curl_Node::$curl_result            = null;
		Feed_Source_Node::$validate_url    = null;
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
		$node = new Feed_Source_Node();
		$node->name( 'feed' );
		$node->arguments( [ 'public' ] );
		foreach ( $urls as $url ) {
			$node->interpreter()->dispatch( 'add_url', [ $url ] );
		}
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$node->target( self::WRITER );
		return $node;
	}

	/** Run $work once inside a running drain loop, where a fetch may start. */
	private function in_drain( \Closure $work ): void {
		$done = false;
		Event_Framework::instance()->drain(
			static function () use ( $work, &$done ): bool {
				if ( ! $done ) {
					$done = true;
					$work();
				}
				return false;
			}
		);
	}

	/** Ask the feed for a TICK inside a drain. */
	private function tick( Feed_Source_Node $node ): void {
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_REQUEST;
		$request[ Message::FROM ]  = self::ASKER;
		$request[ Message::VALUE ] = 'TICK';
		$this->in_drain( static fn () => $node->fill( $request ) );
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

	/** Complete one url's transfer inside a drain, answering a status and a body. */
	private function complete( string $url, string $body, int $code = 200, int $result = \CURLE_OK ): void {
		$this->answers[ $url ] = [ $code, $body ];
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ], $result ) );
	}

	/** Complete one url's transfer inside a drain as a 301 to $location. */
	private function redirect( string $url, string $location ): void {
		$this->locations[ $url ] = $location;
		$this->in_drain( fn () => $this->complete_curl( $this->handles[ $url ] ) );
	}

	/** @return list<array<int,mixed>> The answers the asker heard. */
	private function answers(): array {
		return \array_column( $this->asker->captured, Message::VALUE );
	}

	/** @return array<string,array<string,mixed>> Each recorded item's event, by item_key. */
	private function recorded(): array {
		$out = [];
		foreach ( $this->writer->captured as $message ) {
			$out[ $message[ Message::VALUE ]['item_key'] ] = $message[ Message::VALUE ];
		}
		return $out;
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
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: Feed fetch failed: curl error 7 (' ) );
		$this->tick( $node );
		$this->assertSame( [ 'https://heron-4410.example/feed/', 'https://heron-4410.example/feed/' ], $this->dispatched, 'the answered pass leaves room for the next' );
	}

	public function test_an_error_status_contributes_nothing(): void {
		$node = $this->feed( 'https://example.com/feed.xml' );
		$this->tick( $node );

		$this->complete( 'https://example.com/feed.xml', self::RSS, 503 );

		$this->assertSame( [], $this->recorded() );
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

	/** At most MAX_IN_FLIGHT feeds are in flight; each answer sends the next. */
	public function test_at_most_sixteen_in_flight_and_the_next_sent_as_one_answers(): void {
		$urls = \array_map( static fn ( int $i ): string => "https://outlet-{$i}.example/feed/", \range( 1, 18 ) );
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

	/** An answer naming no feed in flight is dropped. */
	public function test_an_answer_for_no_feed_in_flight_records_nothing(): void {
		$node                      = $this->feed( 'https://kite-2201.example/feed/' );
		$stray                     = Message::new_message();
		$stray[ Message::TYPE ]    = Message::TM_BYTESTREAM;
		$stray[ Message::KEY ]     = 'https://stray-9901.example/feed/';
		$stray[ Message::VALUE ]   = self::RSS;

		$node->fill( $stray );

		$this->assertSame( [], $this->recorded() );
	}

	/** A rename carries the Curl sibling and its answers with it. */
	public function test_a_rename_moves_the_curl_sibling_and_its_target(): void {
		$node = $this->feed( 'https://kite-2201.example/feed/' );

		$node->name( 'feed-3307' );

		$curl = Core::node( 'feed-3307:curl' );
		$this->assertInstanceOf( Curl_Node::class, $curl );
		$this->assertSame( 'feed-3307', $curl->target() );
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

		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://pelican-7714.example/feed/', 'https://harborgazette.example/feed/' ], $this->dispatched );
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
	 * A client feed's items are keyed by the client's domain, so no other
	 * feed takes their keys; each must link to the client's own site or a
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

	/** Every feed URL passes WordPress's URL check before it is sent; one it refuses records nothing, logged. */
	public function test_a_feed_url_wordpress_refuses_is_never_sent(): void {
		$stderr                                          = $this->capture_stderr();
		$this->unsafe['https://intranet-3390.example/feed/'] = true;
		$node                                            = $this->feed( 'https://intranet-3390.example/feed/', 'https://kite-2201.example/feed/' );

		$this->tick( $node );

		$this->assertSame( [ 'https://intranet-3390.example/feed/', 'https://kite-2201.example/feed/' ], $this->validated );
		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: refusing a feed URL WordPress will not fetch: https://intranet-3390.example/feed/' ) );
	}

	/** Curl follows no redirect; the feed follows each Location itself, checked, keeping the client's lane. */
	public function test_a_redirect_is_followed_by_the_feed_keeping_its_lane(): void {
		$this->start_intel_graph();
		$this->seed_clients();
		$node = $this->feed();
		$node->interpreter()->dispatch( 'set_client_feeds', [ 'true' ] );
		$this->tick( $node );

		$this->redirect( 'https://harborgazette.example/feed/', 'https://cdn-4410.example/gazette.xml' );
		$this->complete( 'https://cdn-4410.example/gazette.xml', self::rss( [ [ 'moved', 'Moved', (int) Core::$now - 60, self::GAZETTE ] ] ) );

		$this->assertFalse( $this->opts['https://harborgazette.example/feed/'][ \CURLOPT_FOLLOWLOCATION ] );
		$this->assertSame( [ 'https://harborgazette.example/feed/', 'https://pelican-7714.example/feed/', 'https://cdn-4410.example/gazette.xml' ], $this->validated );
		$this->assertSame( [ 'feed:harborgazette.example:moved' ], \array_keys( $this->recorded() ) );
	}

	/** A redirect to a URL WordPress refuses records nothing, logged. */
	public function test_a_redirect_to_a_refused_url_is_not_followed(): void {
		$stderr                                       = $this->capture_stderr();
		$this->unsafe['http://169.254.169.254/feed/'] = true;
		$node                                         = $this->feed( 'https://kite-2201.example/feed/' );
		$this->tick( $node );

		$this->redirect( 'https://kite-2201.example/feed/', 'http://169.254.169.254/feed/' );

		$this->assertSame( [ 'https://kite-2201.example/feed/' ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: refusing a feed URL WordPress will not fetch: http://169.254.169.254/feed/' ) );
		$this->tick( $node );
		$this->assertSame( [ 'https://kite-2201.example/feed/', 'https://kite-2201.example/feed/' ], $this->dispatched, 'the refused hop ends the pass' );
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
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed: a feed redirected past 5 hops; recording nothing: https://hop-6.example/feed/' ) );
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

	public function test_add_url_verb_dispatches_through_sibling_interpreter(): void {
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
			$this->assertSame( "remove_url 'https://ghost.test/feed.xml' names no url this node declares", $e->getMessage() );
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

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Github_Source_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Intelligence\P2_Source_Node;
use Newspack_Intelligence\Slack_Source_Node;
use Newspack_Intelligence\Unthread_Source_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The chain every source rides: a due tick queues the pass's first
 * requests, each answer's items record and its next requests send, through
 * the source's own Curl sibling. The graph is real; only libcurl is stood
 * in for.
 */
final class SourceNodeTest extends TestCase {
	use Seeds_Intel_Graph;
	use Drives_Curl;

	private const RETRIEVED = 1793011234.5;

	/**
	 * A source's fetch interval, in seconds: 1,700, distinct from every
	 * default.
	 */
	private const INTERVAL = 1700;

	/** A grid boundary: a whole multiple of INTERVAL. */
	private const BOUNDARY = self::INTERVAL * 1054712;

	protected function setUp(): void {
		parent::setUp();
		Core::$now   = self::RETRIEVED;
		Core::$clock = static fn (): float => self::RETRIEVED;
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Count every projection read, each still run for real.
	 *
	 * @return \ArrayObject<int,int> One entry per read.
	 */
	private static function counted_reads(): \ArrayObject {
		$reads                             = new \ArrayObject();
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( $reads ): mixed {
			$reads[] = 1;
			return Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};
		return $reads;
	}

	/**
	 * A fake source as a topology builds it: named, its arguments given,
	 * sinking into the interpreter, its items addressed to the writer, its
	 * pass opening on $first.
	 *
	 * @param list<string> $args  The make_node arguments.
	 * @param list<string> $first The urls each pass opens on.
	 */
	private function fake( array $args = [ 'public' ], array $first = [ Fake_Source_Node::PAGE . '1' ] ): Fake_Source_Node {
		$node        = $this->wire( new Fake_Source_Node(), 'fake-source-12', $args );
		$node->first = $first;
		return $node;
	}

	/**
	 * A page's body: its records, and the urls it names next.
	 *
	 * @param list<array<string,mixed>> $items Records: id, title, url, body, when; or `raw`, returned as fetched.
	 * @param list<string>              $next  The urls to fetch next.
	 */
	private static function page( array $items, array $next = [] ): string {
		return (string) \json_encode(
			[
				'items' => $items,
				'next'  => $next,
			]
		);
	}

	/** Tick, then answer the first page with $items. */
	private function fetch_page( Fake_Source_Node $node, array $items ): void {
		$this->tick( $node );
		$this->complete( Fake_Source_Node::PAGE . '1', self::page( $items ) );
	}

	public function test_a_source_needs_its_tier_at_load(): void {
		$node = new Fake_Source_Node();
		$node->name( 'fake-source-12' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: tier' );
		$node->arguments( [] );
	}

	/**
	 * A topology's row for a source names the tier it records at: the last
	 * `set_tier` its config is sent, bound by that verb's own args, else its
	 * make_node line's.
	 */
	public function test_the_graph_row_names_the_last_set_tier_else_the_lines(): void {
		$this->assertSame( [ 'tier' => 'internal' ], Fake_Source_Node::graph_facts( [ 'internal', 'tern-vault-3' ], [ [ 'set_interval', [ '900' ] ] ] ) );
		$this->assertSame(
			[ 'tier' => 'restricted' ],
			Fake_Source_Node::graph_facts( [ 'public' ], [ [ 'set_tier', [ 'internal' ] ], [ 'set_interval', [ '1800' ] ], [ 'set_tier', [ '--tier=restricted' ] ] ] )
		);
	}

	public function test_a_tier_outside_the_set_is_refused_at_load_and_by_set_tier(): void {
		$node = $this->fake( [ 'internal' ] );
		foreach ( [ static fn () => $node->arguments( [ 'secret' ] ), static fn () => $node->interpreter()->dispatch( 'set_tier', [ 'secret' ] ) ] as $refused ) {
			try {
				$refused();
				$this->fail( 'tier secret accepted' );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'fake-source-12: tier secret is not one of public, internal, restricted', $e->getMessage() );
			}
		}
		$this->assertSame( 'internal', $this->read_private( $node, 'tier' ), 'a refusal leaves the tier' );
	}

	public function test_an_answer_records_item_recorded_events_with_the_sources_tier(): void {
		$node = $this->fake();
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_tier', [ 'internal' ] ) );

		$this->fetch_page(
			$node,
			[
				[
					'id'    => 'abc-7731',
					'title' => 'Harbor Gazette wins state award',
					'url'   => 'https://harborgazette.example/award',
					'body'  => 'The Gazette took first place.',
					'when'  => 'Wed, 21 Oct 2026 14:00:00 GMT',
				],
			]
		);

		$this->assertCount( 1, $this->writer->captured );
		$message = $this->writer->captured[0];
		$this->assertSame( Message::TM_STRUCT, $message[ Message::TYPE ] );
		$this->assertSame( 'fake-source-12', $message[ Message::FROM ] );
		$event = $message[ Message::VALUE ];
		$this->assertSame( 'item_recorded', $event['event'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $event['event_id'] );
		$this->assertSame( self::RETRIEVED, $event['ts'] );
		$this->assertSame( 'machine:source:fake-source-12@' . NEWSPACK_INTELLIGENCE_VERSION, $event['actor'] );
		$this->assertSame( NEWSPACK_INTELLIGENCE_VERSION, $event['config_version'] );
		$this->assertNotSame( '', NEWSPACK_INTELLIGENCE_VERSION );
		$this->assertSame( 'fake:abc-7731', $event['item_key'] );
		$this->assertSame( 'fake', $event['source'] );
		$this->assertSame( 'internal', $event['tier'] );
		$this->assertSame( 'https://harborgazette.example/award', $event['url'] );
		$this->assertSame( 'Harbor Gazette wins state award', $event['title'] );
		$this->assertSame( 'The Gazette took first place.', $event['body'] );
		$this->assertSame( 1792591200, $event['item_ts'] );
		$this->assertSame( 1793011234, $event['retrieved_ts'], 'whole seconds, as item_ts and every reader take it' );
	}

	public function test_a_long_body_is_cut_on_a_character_boundary(): void {
		$body = 'a' . \str_repeat( 'é', 32770 );

		$this->fetch_page( $this->fake(), [ [ 'id' => 'long-1', 'title' => 'Long', 'body' => $body ] ] );

		$cut = $this->writer->captured[0][ Message::VALUE ]['body'];
		$this->assertSame( 65541, \strlen( $body ) );
		$this->assertSame( 65535, \strlen( $cut ) );
		$this->assertTrue( \mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertSame( 'a' . \str_repeat( 'é', 32767 ), $cut );
	}

	public function test_a_short_body_passes_whole(): void {
		$this->fetch_page( $this->fake(), [ [ 'id' => 'short-1', 'title' => 'Short', 'body' => 'Twelve bytes' ] ] );

		$this->assertSame( 'Twelve bytes', $this->writer->captured[0][ Message::VALUE ]['body'] );
	}

	public function test_an_unparseable_date_records_item_ts_zero(): void {
		$this->fetch_page( $this->fake(), [ [ 'id' => 'when-1', 'title' => 'Undated', 'when' => 'the third moon' ] ] );

		$this->assertSame( 0, $this->writer->captured[0][ Message::VALUE ]['item_ts'] );
	}

	/** A TICK answers how many requests the pass opens on, to whoever asked, echoing its ID and KEY. */
	public function test_tick_answers_the_request_with_how_many_it_fetches(): void {
		$node = $this->fake( [ 'restricted' ], [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '2', Fake_Source_Node::PAGE . '3' ] );

		$this->tick( $node );

		$this->assertCount( 1, $this->asker->captured );
		$reply = $this->asker->captured[0];
		$this->assertSame( Message::TM_STRUCT | Message::TM_RESPONSE, $reply[ Message::TYPE ] );
		$this->assertSame( 'fake-source-12', $reply[ Message::FROM ] );
		$this->assertSame( '4:812:97', $reply[ Message::ID ] );
		$this->assertSame( 'tick-key-31', $reply[ Message::KEY ] );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'fetching' => 3 ] ], $reply[ Message::VALUE ] );
	}

	public function test_a_request_other_than_tick_fetches_nothing(): void {
		$node = $this->fake();

		$this->tick( $node, 'SCAN' );

		$this->assertSame( 0, $node->passes );
		$this->assertSame( [], $this->dispatched );
		$this->assertCount( 1, $this->asker->captured, 'only the refusal' );
		$this->assertSame( Message::TM_ERROR, $this->asker->captured[0][ Message::TYPE ] & Message::TM_ERROR );
	}

	/**
	 * A request travels to the Curl sibling as a TM_STRUCT GET, its url in
	 * KEY; an API source's Curl follows a redirect, hop by hop itself, since
	 * the request is public-only.
	 */
	public function test_a_request_goes_to_the_curl_sibling_keyed_by_its_url(): void {
		$node = $this->fake();

		$this->tick( $node );

		$curl = Core::node( 'fake-source-12:curl' );
		$this->assertInstanceOf( Curl_Node::class, $curl );
		$this->assertSame( 'fake-source-12', $curl->target() );
		$this->assertSame( [ Fake_Source_Node::PAGE . '1' ], $this->dispatched );
		$this->assertTrue( $this->opts[ Fake_Source_Node::PAGE . '1' ][ \CURLOPT_HTTPGET ] );
		$this->assertTrue( $this->read_private( $curl, 'follow' ), 'an API source lets Curl follow' );
		$this->assertFalse( $this->opts[ Fake_Source_Node::PAGE . '1' ][ \CURLOPT_FOLLOWLOCATION ], 'not libcurl' );
	}

	/** An API source sends its requests strictly one after another. */
	public function test_an_api_source_never_has_two_requests_in_flight(): void {
		$urls = [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '2', Fake_Source_Node::PAGE . '3' ];
		$node = $this->fake( [ 'public' ], $urls );

		$this->tick( $node );
		$this->assertSame( [ $urls[0] ], $this->dispatched );
		$this->assertSame( 1, Core::node( 'fake-source-12:curl' )->transfers_in_flight() );

		$this->complete( $urls[0], self::page( [] ) );
		$this->assertSame( [ $urls[0], $urls[1] ], $this->dispatched );
		$this->complete( $urls[1], '', 503 );
		$this->assertSame( $urls, $this->dispatched );
	}

	/**
	 * Two requests to one url go out under their own subjects, one after the
	 * other, each carrying the header lines and the proxy the source names
	 * for its subject, and each answer reaches received() under its subject.
	 */
	public function test_requests_to_one_url_keep_their_own_subjects_and_options(): void {
		$node           = $this->wire( new Fake_Tunnel_Source_Node(), 'fake-source-12', [ 'public' ] );
		$node->subjects = [
			'heron/history?page=4' => Fake_Source_Node::PAGE . 'mcp',
			'kite/history?page=7'  => Fake_Source_Node::PAGE . 'mcp',
		];
		$node->options  = [
			'heron/history?page=4' => [
				'headers' => [ 'Authorization: Bearer heron-6610', 'Accept: text/heron-1' ],
				'proxy'   => 'socks5h://proxy-heron.example:1099',
			],
			'kite/history?page=7'  => [ 'headers' => [ 'Accept: text/kite-2' ] ],
		];

		$this->tick( $node );
		$this->assertSame( [ 'Content-Type: application/json', 'Authorization: Bearer heron-6610', 'Accept: text/heron-1' ], $this->opts[ Fake_Source_Node::PAGE . 'mcp' ][ \CURLOPT_HTTPHEADER ] );
		$this->assertSame( 'body:heron/history?page=4', $this->opts[ Fake_Source_Node::PAGE . 'mcp' ][ \CURLOPT_POSTFIELDS ] );
		$this->assertSame( 'socks5h://proxy-heron.example:1099', $this->opts[ Fake_Source_Node::PAGE . 'mcp' ][ \CURLOPT_PROXY ] );
		$this->complete( Fake_Source_Node::PAGE . 'mcp', self::page( [] ) );
		$this->assertSame( [ 'Content-Type: application/json', 'Accept: text/kite-2' ], $this->opts[ Fake_Source_Node::PAGE . 'mcp' ][ \CURLOPT_HTTPHEADER ] );
		$this->assertArrayNotHasKey( \CURLOPT_PROXY, $this->opts[ Fake_Source_Node::PAGE . 'mcp' ] );
		$this->complete( Fake_Source_Node::PAGE . 'mcp', self::page( [] ) );

		$this->assertSame( [ Fake_Source_Node::PAGE . 'mcp', Fake_Source_Node::PAGE . 'mcp' ], $this->dispatched );
		$this->assertSame( [ 'heron/history?page=4', 'kite/history?page=7' ], $node->heard );
	}

	/**
	 * A source whose Curl follows no redirect re-queues a plain GET at its
	 * Location, but not a request carrying header lines of its own, nor a
	 * POST, which would carry them or the method to another url past Curl's
	 * own redirect guard: each is logged and records nothing.
	 */
	public function test_only_a_plain_get_is_requeued_at_its_location(): void {
		$stderr         = $this->capture_stderr();
		$node           = $this->wire( new Fake_Unfollowing_Source_Node(), 'fake-source-12', [ 'public' ] );
		$node->first    = [ Fake_Source_Node::PAGE . 'plain', Fake_Source_Node::PAGE . 'headed' ];
		$node->subjects = [ 'kite/post?page=3' => 'https://posted-5520.example/mcp' ];
		$node->room     = 3;
		$node->options  = [ Fake_Source_Node::PAGE . 'headed' => [ 'headers' => [ 'X-Session-8: sess-3317' ] ] ];
		$this->tick( $node );

		$this->redirect( Fake_Source_Node::PAGE . 'headed', 'https://moved-headed-4411.example/headed' );
		$this->redirect( Fake_Source_Node::PAGE . 'plain', 'https://moved-plain-4410.example/plain' );
		$this->redirect( 'https://posted-5520.example/mcp', 'https://moved-post-4412.example/post' );

		$this->assertContains( 'https://moved-plain-4410.example/plain', $this->dispatched );
		$this->assertNotContains( 'https://moved-headed-4411.example/headed', $this->dispatched );
		$this->assertNotContains( 'https://moved-post-4412.example/post', $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a redirect of a request carrying its own headers or a method other than GET is not followed; recording nothing: https://moved-headed-4411.example/headed' ) );
	}

	/** An answer's items record, and the requests it names next send, as one pass. */
	public function test_an_answer_records_its_items_and_sends_its_next_requests(): void {
		$node = $this->fake();
		$this->tick( $node );

		$this->complete( Fake_Source_Node::PAGE . '1', self::page( [ [ 'id' => 'heron-1', 'title' => 'Heron' ] ], [ Fake_Source_Node::PAGE . '9' ] ) );
		$this->assertSame( [ 'fake:heron-1' ], $this->recorded_keys() );
		$this->assertSame( [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '9' ], $this->dispatched );

		$this->tick( $node );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ], \end( $this->asker->captured )[ Message::VALUE ], 'the chained request keeps the pass open' );

		$this->complete( Fake_Source_Node::PAGE . '9', self::page( [ [ 'id' => 'kite-9', 'title' => 'Kite' ] ] ) );
		$this->assertSame( [ 'fake:heron-1', 'fake:kite-9' ], $this->recorded_keys() );
	}

	/** A TM_ERROR is logged and ends its own branch; the pass's other requests still send. */
	public function test_an_error_ends_only_its_branch(): void {
		$stderr = $this->capture_stderr();
		$urls   = [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '2' ];
		$node   = $this->fake( [ 'public' ], $urls );
		$this->tick( $node );

		$this->complete( $urls[0], self::page( [ [ 'id' => 'lost-1' ] ], [ Fake_Source_Node::PAGE . '7' ] ), 404 );
		$this->complete( $urls[1], self::page( [ [ 'id' => 'wren-2', 'title' => 'Wren' ] ] ) );

		$this->assertSame( $urls, $this->dispatched );
		$this->assertSame( [ 'fake:wren-2' ], $this->recorded_keys() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a fetch failed for ' . $urls[0] . ': HTTP 404 ' . $urls[0] ) );
		$this->tick( $node );
		$this->assertSame( [ ...$urls, $urls[0] ], $this->dispatched, 'the pass ended, so the next one starts' );
	}

	/** Each failed fetch, and each answer that throws, is logged under its own url. */
	public function test_each_failure_is_logged_under_its_own_url(): void {
		$stderr = $this->capture_stderr();
		$urls   = [ Fake_Source_Node::PAGE . '3', Fake_Source_Node::PAGE . '4', Fake_Source_Node::PAGE . '5', Fake_Source_Node::PAGE . '6' ];
		$node   = $this->fake( [ 'public' ], $urls );
		$this->tick( $node );

		$this->complete( $urls[0], '', 404 );
		$this->complete( $urls[1], '', 503 );
		$node->answer_failure = new \RuntimeException( 'page-broke-3308' );
		$this->complete( $urls[2], self::page( [] ) );
		$node->answer_failure = new \RuntimeException( 'page-broke-3309' );
		$this->complete( $urls[3], self::page( [] ) );

		$this->assertCount( 1, self::stderr_lines( $stderr, "fake-source-12: a fetch failed for {$urls[0]}: HTTP 404 {$urls[0]}" ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, "fake-source-12: a fetch failed for {$urls[1]}: HTTP 503 {$urls[1]}" ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, "fake-source-12: an answer failed for {$urls[2]}: page-broke-3308" ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, "fake-source-12: an answer failed for {$urls[3]}: page-broke-3309" ) );
	}

	/** A pass sends at most MAX_REQUESTS_PER_PASS; the next is logged and dropped, and the pass ends. */
	public function test_the_sixty_first_request_in_a_pass_is_not_sent(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->fake();
		$this->tick( $node );

		for ( $page = 1; $page <= 60; $page++ ) {
			$this->complete( Fake_Source_Node::PAGE . $page, self::page( [], [ Fake_Source_Node::PAGE . ( $page + 1 ) ] ) );
		}

		$this->assertSame( 60, Source_Node::MAX_REQUESTS_PER_PASS );
		$this->assertCount( 60, $this->dispatched );
		$this->assertSame( Fake_Source_Node::PAGE . '60', \end( $this->dispatched ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a pass reached 60 requests; sending no more: ' . Fake_Source_Node::PAGE . '61' ) );
		$this->tick( $node );
		$this->assertSame( Fake_Source_Node::PAGE . '1', \end( $this->dispatched ), 'the capped pass ended, and the next opens afresh' );
	}

	/** An answer received() throws on is logged naming its url; the drain runs on, and the next answer records. */
	public function test_an_answer_that_throws_is_logged_and_the_next_still_records(): void {
		$stderr               = $this->capture_stderr();
		$urls                 = [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '2' ];
		$node                 = $this->fake( [ 'public' ], $urls );
		$node->answer_failure = new \RuntimeException( 'page-broke-3307' );
		$this->tick( $node );

		$this->complete( $urls[0], self::page( [ [ 'id' => 'lost-1', 'title' => 'Lost' ] ] ) );
		$this->complete( $urls[1], self::page( [ [ 'id' => 'kept-2', 'title' => 'Kept' ] ] ) );

		$this->assertSame( $urls, $this->dispatched );
		$this->assertSame( [ 'fake:kept-2' ], $this->recorded_keys() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: an answer failed for ' . $urls[0] . ': page-broke-3307' ) );
	}

	/** A cooperative stop inside an answer stops the worker. */
	public function test_a_stop_inside_an_answer_propagates(): void {
		$node                 = $this->fake();
		$node->answer_failure = new Worker_Should_Stop( 'memory watermark 4410 reached' );
		$this->tick( $node );

		$this->expectException( Worker_Should_Stop::class );
		$this->expectExceptionMessage( 'memory watermark 4410 reached' );
		$this->complete( Fake_Source_Node::PAGE . '1', self::page( [] ) );
	}

	/** A host takes one request at a time; a request for another host goes out beside it. */
	public function test_a_host_takes_one_request_at_a_time(): void {
		$same = [ 'https://heron-5508.example/a', 'https://heron-5508.example/b' ];
		$node = $this->fake( [ 'public' ], [ $same[0], $same[1], 'https://kite-5509.example/a' ] );
		$node->room = 3;

		$this->tick( $node );
		$this->assertSame( [ $same[0], 'https://kite-5509.example/a' ], $this->dispatched );

		$this->complete( $same[0], self::page( [] ) );
		$this->assertSame( [ $same[0], 'https://kite-5509.example/a', $same[1] ], $this->dispatched );
	}

	/** A request Curl refuses as it is handed frees its host for the next on it, in the same send. */
	public function test_a_refused_request_frees_its_host_at_once(): void {
		$node           = $this->fake( [ 'public' ], [] );
		$node->subjects = [
			'a-curlew/1' => 'https://curlew-6630.example/one',
			'b-curlew/2' => 'https://curlew-6630.example/two',
		];
		$node->options  = [ 'a-curlew/1' => [ 'method' => 'PATCH' ] ];

		$this->tick( $node );

		$this->assertSame( [ 'https://curlew-6630.example/two' ], $this->dispatched );
	}

	/** Requests waiting on a busy host leave the room to the hosts behind them. */
	public function test_a_busy_host_does_not_spend_the_room(): void {
		$busy  = \array_map( static fn ( int $i ): string => "https://avocet-3307.example/{$i}", \range( 1, 6 ) );
		$other = \array_map( static fn ( int $i ): string => "https://plover-{$i}.example/feed", \range( 1, 5 ) );
		$node       = $this->fake( [ 'public' ], [ ...$busy, ...$other ] );
		$node->room = 6;

		$this->tick( $node );

		$this->assertSame( [ $busy[0], ...$other ], $this->dispatched );
	}

	/** However many a source asks for, Curl's own cap bounds what is in flight, and the rest wait rather than drop. */
	public function test_in_flight_is_clamped_to_curls_cap(): void {
		$stderr     = $this->capture_stderr();
		$urls       = \array_map( static fn ( int $i ): string => \sprintf( 'https://outlet-%02d.example/page', $i ), \range( 1, 19 ) );
		$node       = $this->fake( [ 'public' ], $urls );
		$node->room = 40;

		$this->tick( $node );
		$this->assertSame( \array_slice( $urls, 0, Curl_Node::MAX_IN_FLIGHT ), $this->dispatched );

		$this->complete( $urls[0], self::page( [] ) );
		$this->assertSame( \array_slice( $urls, 0, Curl_Node::MAX_IN_FLIGHT + 1 ), $this->dispatched );
		$this->assertCount( 0, self::stderr_lines( $stderr, 'busy' ) );
	}

	/** A due fetch while requests are in flight starts nothing. */
	public function test_a_tick_with_requests_in_flight_starts_nothing(): void {
		$node = $this->fake();
		$this->tick( $node );

		$this->tick( $node );

		$this->assertSame( 1, $node->passes );
		$this->assertSame( [ Fake_Source_Node::PAGE . '1' ], $this->dispatched );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 1 ] ], [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
	}

	/** An answer that does not match a request in flight is dropped. */
	public function test_an_answer_for_no_request_in_flight_records_nothing(): void {
		$node                    = $this->fake();
		$stray                   = Message::new_message();
		$stray[ Message::TYPE ]  = Message::TM_BYTESTREAM;
		$stray[ Message::KEY ]   = 'https://stray-9901.example/page/1';
		$stray[ Message::VALUE ] = self::page( [ [ 'id' => 'stray-1', 'title' => 'Stray' ] ] );

		$node->fill( $stray );

		$this->assertSame( [], $this->recorded_keys() );
	}

	/** The vault group the make_node line names builds the Curl sibling, which sends its entry's credential. */
	public function test_the_vault_group_lends_the_curl_sibling_its_credential(): void {
		$this->seed_vault(
			'fake-creds-5521',
			[
				'url'           => 'https://fake-4471.example',
				'group'         => 'fake-group-3310',
				'auth_password' => 'fake-secret-8812',
				'auth_header'   => 'X-Fake-Key',
			]
		);
		$node = $this->fake( [ 'internal', 'fake-group-3310' ] );

		$this->tick( $node );

		$this->assertContains( 'X-Fake-Key: fake-secret-8812', $this->opts[ Fake_Source_Node::PAGE . '1' ][ \CURLOPT_HTTPHEADER ] );
		$this->assertMatchesRegularExpression( '/^make_node \S+ fake-source-12 internal fake-group-3310$/m', $node->dump_config() );
	}

	/**
	 * A replay naming another vault group rebuilds Curl, whose transfers go
	 * with it, so the next send fetches again what was in flight.
	 */
	public function test_a_new_vault_group_sends_again_what_was_in_flight(): void {
		$node = $this->fake( [ 'internal', 'fake-group-3310' ] );
		$this->tick( $node );

		$this->bound( fn () => $node->arguments( [ 'internal', 'fake-group-4421' ] ) );
		$this->tick_at( $node, self::BOUNDARY + 23.0 );
		$this->complete( Fake_Source_Node::PAGE . '1', self::page( [ [ 'id' => 'sandpiper-1', 'title' => 'Sandpiper' ] ] ) );

		$this->assertSame( [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '1' ], $this->dispatched );
		$this->assertSame( [ 'fake:sandpiper-1' ], $this->recorded_keys() );
		$this->assertSame( 1, $node->passes, 'the pass resumed, not begun' );
	}

	/** With no vault group a request carries no credential, whatever the vault holds for its origin. */
	public function test_no_vault_group_sends_no_credential(): void {
		$this->seed_vault(
			'fake-creds-5521',
			[
				'url'           => 'https://fake-4471.example',
				'group'         => 'fake-group-3310',
				'auth_password' => 'fake-secret-8812',
				'auth_header'   => 'X-Fake-Key',
			]
		);

		$this->tick( $this->fake() );

		$this->assertArrayNotHasKey( \CURLOPT_HTTPHEADER, $this->opts[ Fake_Source_Node::PAGE . '1' ] );
	}

	/**
	 * Each source that needs a credential: its class, the add verb and value
	 * that give a pass something to ask, the origin it fetches from, and how
	 * many requests its pass opens on.
	 *
	 * @return array<string,array{0:class-string<Source_Node>,1:string,2:string,3:string,4:int}>
	 */
	public static function credentialed_sources(): array {
		return [
			'linear'   => [ Linear_Source_Node::class, 'add_team', 'KESTREL', 'https://api.linear.app', 1 ],
			'slack'    => [ Slack_Source_Node::class, 'add_channel', 'C0HERON7731', 'https://slack.com', 1 ],
			'p2'       => [ P2_Source_Node::class, 'add_site', 'heron-desk.wordpress.com', 'https://public-api.wordpress.com', 2 ],
			'unthread' => [ Unthread_Source_Node::class, '', '', 'https://api.unthread.io', 1 ],
		];
	}

	/**
	 * A credentialed source as the collect topology builds it, under the
	 * group `heron-vault-7731`, given something to ask.
	 *
	 * @param class-string<Source_Node> $class The source.
	 * @param list<string>              $args  The make_node arguments.
	 */
	private function credentialed( string $class, string $verb, string $value, array $args = [ 'internal', 'heron-vault-7731' ] ): Source_Node {
		$node = $this->wire( new $class(), 'heron-source-31', $args );
		if ( '' !== $verb ) {
			$node->interpreter()->dispatch( $verb, [ $value ] );
		}
		return $node;
	}

	/**
	 * With no entry of its group on its origin, a pass sends nothing, answers
	 * that it fetches nothing and logs nothing: an entry of its group on
	 * another origin, and one on its origin in another group, lend it none.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'credentialed_sources' )]
	public function test_a_source_with_no_vault_entry_on_its_origin_sends_nothing_silently( string $class, string $verb, string $value, string $origin ): void {
		$this->lend( 'heron-vault-7731', 'https://elsewhere-4410.example' );
		$this->lend( 'kestrel-vault-2208', $origin );
		$this->lend( 'heron-vault-7731', \str_replace( 'https://', 'http://', $origin ) );
		$node   = $this->credentialed( $class, $verb, $value );
		$stderr = $this->capture_stderr();

		$this->tick( $node );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [], self::stderr_lines( $stderr ) );
	}

	/**
	 * A credentialed source that does not name a vault group sends nothing,
	 * whatever the Vault holds for its origin.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'credentialed_sources' )]
	public function test_a_source_with_no_vault_group_sends_nothing( string $class, string $verb, string $value, string $origin ): void {
		$this->lend( 'heron-vault-7731', $origin );
		$node = $this->credentialed( $class, $verb, $value, [ 'internal' ] );

		$this->tick( $node );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
		$this->assertSame( [], $this->dispatched );
	}

	/** An entry added between two passes makes the second fetch, carrying its credential, with no restart. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'credentialed_sources' )]
	public function test_the_pass_after_the_entry_is_added_fetches_with_it( string $class, string $verb, string $value, string $origin, int $opening ): void {
		$node = $this->credentialed( $class, $verb, $value );
		$this->tick( $node );

		$this->lend( 'heron-vault-7731', $origin, 'X-Heron-Key', 'heron-secret-6604' );
		$this->tick( $node );

		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'fetching' => $opening ] ], $this->answers()[1] );
		$this->assertCount( 1, $this->dispatched );
		$this->assertStringStartsWith( "{$origin}/", $this->dispatched[0] );
		$this->assertContains( 'X-Heron-Key: heron-secret-6604', $this->sent[0][ \CURLOPT_HTTPHEADER ] );
	}

	/** A scheduled pass of a credentialed source with no entry sends nothing and logs nothing. */
	public function test_a_scheduled_pass_with_no_vault_entry_sends_nothing_silently(): void {
		$node = $this->credentialed( Unthread_Source_Node::class, '', '' );
		$node->interpreter()->dispatch( 'set_interval', [ (string) self::INTERVAL ] );
		$stderr = $this->capture_stderr();

		$this->tick_at( $node, self::BOUNDARY - 7.0 );
		$this->tick_at( $node, self::BOUNDARY + 2.0 );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [], self::stderr_lines( $stderr ) );
	}

	/** GitHub's public API answers without a token, so GitHub fetches with no Vault entry. */
	public function test_github_fetches_with_no_vault_entry(): void {
		$node = $this->wire( new Github_Source_Node(), 'heron-github-31', [ 'public', 'heron-vault-7731' ] );
		$node->interpreter()->dispatch( 'add_repo', [ 'heron/gazette-9' ] );

		$this->tick( $node );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 3 ] ] ], $this->answers() );
		$this->assertStringStartsWith( 'https://api.github.com/repos/heron/gazette-9/', $this->dispatched[0] );
	}

	/** An item make() refuses is skipped and logged; the rest of the answer records. */
	public function test_a_refused_item_is_skipped_and_logged(): void {
		Core::$recent_log = [];

		$this->fetch_page(
			$this->fake(),
			[
				[ 'raw' => [ 'item_key' => 'fake:harbor-9', 'source' => 'fake', 'tier' => 'public' ] ],
				[ 'id' => 'harbor-10', 'title' => 'Harbor Gazette wins state award' ],
				[ 'id' => 'harbor-11', 'title' => 'Harbor Gazette names an editor' ],
			]
		);

		$this->assertSame( [ 'fake:harbor-10', 'fake:harbor-11' ], $this->recorded_keys() );
		$this->assertStringContainsString( 'fake:harbor-9: item_recorded: missing field url', \implode( "\n", Core::$recent_log ) );
	}

	/**
	 * An item whose key runs past Graph_Curation::ITEM_KEY_BYTES as the stream
	 * writes it, JSON-escaped, is refused with a line naming the source and
	 * the key's length, so a record naming it never outgrows one line.
	 */
	public function test_an_item_key_past_its_cap_is_refused(): void {
		$stderr = $this->capture_stderr();

		$this->fetch_page(
			$this->fake(),
			[
				[ 'id' => \str_repeat( '"', 253 ), 'title' => 'At the cap, escaped' ],
				[ 'id' => \str_repeat( '"', 254 ), 'title' => 'One byte past it' ],
			]
		);

		$this->assertSame( [ 'fake:' . \str_repeat( '"', 253 ) ], $this->recorded_keys() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: refused an item whose key runs 513 bytes, past 512: fake:' . \str_repeat( '"', 59 ) ) );
		$this->assertSame( 512, \Newspack_Intelligence\Graph_Curation::ITEM_KEY_BYTES );
	}

	/** A key refused for its size is remembered, so the next pass neither records nor logs it again. */
	public function test_a_size_refused_key_is_refused_once_per_process(): void {
		$node  = $this->fake();
		$items = [ [ 'id' => \str_repeat( 'k', 600 ), 'title' => 'Too long a key' ] ];
		$this->fetch_page( $node, $items );
		Core::$recent_log_timers = [];
		$stderr                  = $this->capture_stderr();

		$this->fetch_page( $node, $items );

		$this->assertSame( [], $this->recorded_keys() );
		$this->assertCount( 0, self::stderr_lines( $stderr, 'refused an item whose key runs' ) );
	}

	/** The refused key is named cut to 64 bytes and terminal-safe, so a hostile key cannot drive the operator's terminal. */
	public function test_a_size_refused_key_is_named_terminal_safe(): void {
		$stderr = $this->capture_stderr();

		$this->fetch_page( $this->fake(), [ [ 'id' => "\033[2J" . \str_repeat( 'k', 600 ), 'title' => 'A hostile key' ] ] );

		$lines = self::stderr_lines( $stderr, 'refused an item whose key runs 614 bytes, past 512: fake:' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\033", \implode( '', $lines ) );
	}

	/** A refused item is not remembered, so a later fetch of it records. */
	public function test_a_refused_item_is_recorded_on_a_later_pass(): void {
		$node = $this->fake();
		$this->fetch_page( $node, [ [ 'raw' => [ 'item_key' => 'fake:harbor-9', 'source' => 'fake', 'tier' => 'public' ] ] ] );

		$this->fetch_page( $node, [ [ 'id' => 'harbor-9', 'title' => 'Harbor Gazette wins state award' ] ] );

		$this->assertSame( [ 'fake:harbor-9' ], $this->recorded_keys() );
	}

	public function test_dedups_by_item_key_across_passes(): void {
		$node = $this->fake();

		$this->fetch_page( $node, [ [ 'id' => '1', 'title' => 'A' ], [ 'id' => '1', 'title' => 'A again' ] ] );
		$this->fetch_page( $node, [ [ 'id' => '1', 'title' => 'A' ] ] );

		$this->assertSame( [ 'fake:1' ], $this->recorded_keys(), 'a seen item_key must not be re-emitted' );
	}

	public function test_seen_set_is_bounded_and_evicts_the_oldest_key(): void {
		$node  = $this->fake();
		$items = [];
		for ( $i = 1; $i <= 2001; $i++ ) {
			$items[] = [ 'id' => "$i", 'title' => "Item $i" ];
		}
		$this->fetch_page( $node, $items );

		$this->fetch_page( $node, [ [ 'id' => '1', 'title' => 'Item 1 again' ] ] );

		$this->assertCount( 2002, $this->recorded_keys() );
	}

	/** A source with an interval set. */
	private function scheduled(): Fake_Source_Node {
		$node = $this->fake();
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_interval', [ (string) self::INTERVAL ] ) );
		return $node;
	}

	/** Run one Router tick through the source inside a drain, its clock at a moment. */
	private function tick_at( Source_Node $node, float $at ): void {
		$this->in_drain(
			static function () use ( $node, $at ): void {
				Core::$now = $at;
				$node->fire_cb();
			}
		);
	}

	/** Answer whatever the last pass left in flight, so the next can open. */
	private function settle(): void {
		$this->complete( Fake_Source_Node::PAGE . '1', self::page( [] ) );
	}

	/** A source with an interval rides the Router's tick, and its first tick in a process fetches nothing. */
	public function test_the_first_tick_of_a_process_fetches_nothing(): void {
		$node = $this->scheduled();

		$this->assertSame( 'router', $node->timer_mode() );
		$this->tick_at( $node, self::BOUNDARY - 5.0 );
		$this->tick_at( $node, self::BOUNDARY + 3.0 - self::INTERVAL + 10 );

		$this->assertSame( 0, $node->passes, 'no boundary crossed since the first tick' );
	}

	/** Each boundary crossed opens a pass once, and a second tick past the same boundary opens nothing. */
	public function test_each_boundary_crossed_fetches_once(): void {
		$node = $this->scheduled();

		$this->tick_at( $node, self::BOUNDARY - 7.0 );
		$this->tick_at( $node, self::BOUNDARY + 2.0 );
		$this->settle();
		$this->tick_at( $node, self::BOUNDARY + 41.0 );
		$this->assertSame( 1, $node->passes );
		$this->assertSame( [ Fake_Source_Node::PAGE . '1' ], $this->dispatched );

		$this->tick_at( $node, self::BOUNDARY + self::INTERVAL + 1.0 );
		$this->assertSame( 2, $node->passes );
	}

	/**
	 * A boundary passed while the source was not ticking is skipped; the next
	 * tick past it fetches once.
	 */
	public function test_ticks_far_apart_fetch_once(): void {
		$node = $this->scheduled();

		$this->tick_at( $node, self::BOUNDARY - 9.0 );
		$this->tick_at( $node, self::BOUNDARY + 3 * self::INTERVAL + 4.0 );

		$this->assertSame( 1, $node->passes );
	}

	/** A scheduled pass that throws is logged naming the source, and the Router's tick carries on. */
	public function test_a_scheduled_fetch_that_throws_is_logged_and_contained(): void {
		$stderr        = $this->capture_stderr();
		$node          = $this->scheduled();
		$node->failure = new \RuntimeException( 'fetch-broke-4471' );

		$this->tick_at( $node, self::BOUNDARY - 4.0 );
		$this->tick_at( $node, self::BOUNDARY + 4.0 );

		$this->assertSame( 1, $node->passes );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a scheduled fetch failed; trying again at the next boundary: fetch-broke-4471' ) );
	}

	/**
	 * A pass a source resumes off the grid, as one held back by a refresh
	 * does, runs guarded as a scheduled one: a throw is logged and contained.
	 */
	public function test_a_resumed_pass_that_throws_is_logged_and_contained(): void {
		$stderr          = $this->capture_stderr();
		$node            = $this->fake();
		$node->resumable = true;
		$node->failure   = new \RuntimeException( 'resume-broke-7731' );

		$this->tick_at( $node, self::BOUNDARY + 11.0 );

		$this->assertSame( 1, $node->passes );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a scheduled fetch failed; trying again at the next boundary: resume-broke-7731' ) );
	}

	/** A cooperative stop inside a scheduled pass stops the worker. */
	public function test_a_stop_inside_a_scheduled_fetch_propagates(): void {
		$node          = $this->scheduled();
		$node->failure = new Worker_Should_Stop( 'max_runtime 595 reached' );
		$this->tick_at( $node, self::BOUNDARY - 4.0 );

		$this->expectException( Worker_Should_Stop::class );
		$this->expectExceptionMessage( 'max_runtime 595 reached' );
		$this->tick_at( $node, self::BOUNDARY + 4.0 );
	}

	/** A source with no interval holds no timer, and only TICK fetches. */
	public function test_no_interval_fetches_only_on_tick(): void {
		$node = $this->fake();

		$this->assertFalse( $node->timer_is_active() );
		$this->tick_at( $node, self::BOUNDARY - 3.0 );
		$this->tick_at( $node, self::BOUNDARY + 3.0 );
		$this->assertSame( 0, $node->passes );

		$this->tick( $node );
		$this->assertSame( 1, $node->passes );
	}

	/** The interval round-trips through dump_config(), and a source with none dumps no line. */
	public function test_set_interval_rides_the_dump(): void {
		$this->assertStringNotContainsString( 'set_interval', $this->fake()->dump_config() );
		Core::reset();
		$this->install_curl();
		$node = $this->scheduled();

		$dump = $node->dump_config();
		$this->assertStringContainsString( "command_node fake-source-12:config set_interval 1700\n", $dump );

		Core::reset();
		$this->install_curl();
		$replayed = $this->fake();
		$this->replay_config( $dump, $replayed );
		$this->assertSame( self::INTERVAL, $this->read_private( $replayed, 'interval' ) );
		$this->assertTrue( $replayed->timer_is_active() );
	}

	/** An interval below one second is refused, and the source keeps none. */
	public function test_set_interval_refuses_less_than_a_second(): void {
		$node = $this->fake();

		try {
			$node->interpreter()->dispatch( 'set_interval', [ '0' ] );
			$this->fail( 'interval 0 accepted' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'fake-source-12: set_interval wants a whole number of seconds from 1 up, got 0', $e->getMessage() );
		}
		$this->assertNull( $this->read_private( $node, 'interval' ) );
		$this->assertFalse( $node->timer_is_active() );
	}

	/**
	 * A respawned source, its seen set empty, records only what the
	 * projection lacks, reading the projection once for the answer; the held
	 * item is remembered, so the next answer reads nothing.
	 */
	public function test_a_fresh_source_records_only_what_the_projection_lacks(): void {
		$this->start_intel_graph();
		$this->seed( self::item( 'fake:harbor-held', 'Harbor Gazette already recorded', 'fake', 'public', self::$oct_13 ) );
		$reads = self::counted_reads();
		$node  = $this->fake();
		$items = [
			[ 'id' => 'harbor-held', 'title' => 'Harbor Gazette already recorded' ],
			[ 'id' => 'harbor-fresh', 'title' => 'Harbor Gazette names a publisher' ],
		];

		$this->fetch_page( $node, $items );

		$this->assertSame( [ 'fake:harbor-fresh' ], $this->recorded_keys() );
		$this->assertCount( 1, $reads );

		$this->fetch_page( $node, $items );
		$this->assertSame( [ 'fake:harbor-fresh' ], $this->recorded_keys() );
		$this->assertCount( 1, $reads, 'every key is seen, so nothing is read' );
	}

	/**
	 * A copy, of the same source, raw id and canonical URL, that the
	 * projection holds under another lane's key records nothing, found in the
	 * same one read and remembered, so the next answer reads nothing; one of
	 * another source records.
	 */
	public function test_an_item_whose_copy_is_held_records_nothing(): void {
		$this->start_intel_graph();
		$this->seed(
			self::item( 'fake:kite:sister-9', 'Osprey wins the cup', 'fake', 'public', self::$oct_13 ),
			self::item( 'feed:kite:sister-5', 'Osprey signs a coach', 'feed', 'public', self::$oct_13 )
		);
		$reads       = self::counted_reads();
		$node        = $this->fake();
		$node->lanes = true;
		$items       = [
			[ 'id' => 'wren:sister-9', 'title' => 'Osprey wins the cup', 'url' => 'https://www.news.example/fake/kite/sister-9/?utm_source=rss-5521' ],
			[ 'id' => 'wren:sister-5', 'title' => 'Osprey signs a coach', 'url' => 'https://news.example/feed/kite/sister-5' ],
		];

		$this->fetch_page( $node, $items );

		$this->assertSame( [ 'fake:wren:sister-5' ], $this->recorded_keys() );
		$this->assertCount( 1, $reads );

		$this->fetch_page( $node, $items );
		$this->assertSame( [ 'fake:wren:sister-5' ], $this->recorded_keys() );
		$this->assertCount( 1, $reads, 'both are remembered, so nothing is read' );
	}

	/**
	 * A copy recorded earlier in the process records nothing again, in the
	 * same answer or a later one. Items sharing a URL but not a raw id, as a
	 * podcast's episodes share their show's page, each record; so do items
	 * whose URL is empty or names no host, judged by key alone.
	 */
	public function test_only_a_copy_recorded_in_the_process_records_nothing_again(): void {
		$node        = $this->fake();
		$node->lanes = true;

		$this->fetch_page(
			$node,
			[
				[ 'id' => 'kite:heron-7', 'title' => 'Heron', 'url' => 'https://heron-3307.example/story' ],
				[ 'id' => 'wren:heron-7', 'title' => 'Heron', 'url' => 'http://heron-3307.example/story/#top' ],
				[ 'id' => 'wren:heron-8', 'title' => 'Heron, part two', 'url' => 'https://heron-3307.example/story' ],
				[ 'id' => 'kite:blank-1', 'title' => 'Blank' ],
				[ 'id' => 'wren:blank-1', 'title' => 'Blank' ],
				[ 'id' => 'kite:rel-2', 'title' => 'Relative', 'url' => '/rel-2' ],
				[ 'id' => 'wren:rel-2', 'title' => 'Relative', 'url' => '/rel-2' ],
			]
		);
		$this->fetch_page( $node, [ [ 'id' => 'osprey:heron-7', 'title' => 'Heron', 'url' => 'https://www.heron-3307.example/story?utm_medium=feed' ] ] );

		$this->assertSame( [ 'fake:kite:heron-7', 'fake:wren:heron-8', 'fake:kite:blank-1', 'fake:wren:blank-1', 'fake:kite:rel-2', 'fake:wren:rel-2' ], $this->recorded_keys() );
	}

	/** A source judging copies remembers as many items as one judging keys: one entry an item. */
	public function test_a_source_judging_copies_remembers_one_entry_an_item(): void {
		$node        = $this->fake();
		$node->lanes = true;
		$items       = [];
		for ( $i = 1; $i <= 1500; $i++ ) {
			$items[] = [ 'id' => "kite:{$i}", 'title' => "Item {$i}", 'url' => "https://lane-6604.example/{$i}" ];
		}
		$this->fetch_page( $node, $items );

		$this->fetch_page( $node, [ [ 'id' => 'kite:1', 'title' => 'Item 1 again', 'url' => 'https://lane-6604.example/1' ] ] );

		$this->assertCount( 1500, $this->recorded_keys() );
	}

	/** A source naming no raw id, the base's default, records each key: a post's comments share its canonical URL. */
	public function test_a_source_judges_by_key_alone_by_default(): void {
		$this->fetch_page(
			$this->fake(),
			[
				[ 'id' => 'comment-1', 'title' => 'Ada: agreed', 'url' => 'https://p2-8812.example/post/#comment-1' ],
				[ 'id' => 'comment-2', 'title' => 'Bo: not yet', 'url' => 'https://p2-8812.example/post/#comment-2' ],
			]
		);

		$this->assertSame( [ 'fake:comment-1', 'fake:comment-2' ], $this->recorded_keys() );
	}

	/** A projection that will not open costs repeats, not items: everything records, logged. */
	public function test_a_projection_that_will_not_open_records_everything_logged(): void {
		$stderr                            = $this->capture_stderr();
		Intel_Graph_Row_Schema::$read_rows = static function (): never {
			throw new Table_Unavailable( 'graph-gone-7731' );
		};

		$this->fetch_page( $this->fake(), [ [ 'id' => 'harbor-a', 'title' => 'A' ], [ 'id' => 'harbor-b', 'title' => 'B' ] ] );

		$this->assertCount( 2, $this->recorded_keys() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: no intel-graph to read recorded items from; recording every item fetched: graph-gone-7731' ) );
	}

	public function test_the_tier_rides_the_make_node_line(): void {
		$node = $this->fake();
		$node->interpreter()->dispatch( 'set_tier', [ 'restricted' ] );

		$dump = $node->dump_config();
		$this->assertMatchesRegularExpression( '/^make_node \S+ fake-source-12 restricted$/m', $dump );
		$this->assertStringNotContainsString( 'set_tier', $dump );
	}

	/** A source never fires a FIRE event, so none declares one. */
	public function test_no_source_declares_a_fire_registration(): void {
		foreach ( [ Fake_Source_Node::class, Github_Source_Node::class, Linear_Source_Node::class, Feed_Source_Node::class, Slack_Source_Node::class, P2_Source_Node::class, Unthread_Source_Node::class ] as $class ) {
			$this->assertSame( [], $class::node_schema()['registrations'], $class );
		}
	}

	/** Pin the worker's clock at $at, as each Router tick reads it. */
	private static function at( float $at ): void {
		Core::$now   = $at;
		Core::$clock = static fn (): float => $at;
	}

	/** A fake source whose pass opens on $urls, answering each with $codes in turn. */
	private function pass( Fake_Source_Node $node, array $urls, array $codes ): void {
		$node->first = $urls;
		$this->tick( $node );
		foreach ( $urls as $i => $url ) {
			$this->complete( $url, self::page( [] ), $codes[ $i ] );
		}
	}

	/**
	 * A pass any request of which answered publishes ok, at the clock its end
	 * reads, with how many it sent and how many failed, under twice the
	 * slowest source's interval.
	 */
	public function test_pass_with_an_answer_publishes_ok_with_its_counts(): void {
		$memd = $this->publishing();
		self::at( 5551.0 );
		$urls = [ Fake_Source_Node::PAGE . '1', Fake_Source_Node::PAGE . '2', Fake_Source_Node::PAGE . '3' ];

		$this->pass( $this->fake(), $urls, [ 200, 404, 200 ] );

		$this->assertSame( self::snapshot( [ 'state' => 'ok', 'last_ok_at' => 5551, 'failed' => 1, 'requests' => 3 ] ), $this->published( 'fake-source-12' ) );
		$this->assertCount( 1, $memd->sets, 'one publish, at the pass end' );
		$this->assertSame( 7200, $memd->sets[0]['ttl'] );
	}

	/**
	 * A pass whose every request failed publishes failing, with the last
	 * failure's text.
	 */
	public function test_pass_answering_nothing_publishes_failing_with_last_error(): void {
		$this->publishing();
		self::at( 5551.0 );

		$this->pass( $this->fake(), [ 'https://example.test/feed/', 'https://example.test/news/' ], [ 404, 404 ] );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'HTTP 404 https://example.test/news/', 'failing_since' => 5551, 'failed' => 2, 'requests' => 2 ] ), $this->published( 'fake-source-12' ) );
	}

	/** A failing pass after a failing one keeps the first one's failing_since. */
	public function test_failing_since_survives_a_second_failing_pass(): void {
		$this->publishing();
		$node = $this->fake();
		self::at( 6000.0 );
		$this->pass( $node, [ 'https://example.test/feed/' ], [ 503 ] );

		self::at( 7800.0 );
		$this->pass( $node, [ 'https://example.test/feed/' ], [ 404 ] );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'HTTP 404 https://example.test/feed/', 'failing_since' => 6000, 'failed' => 1, 'requests' => 1 ] ), $this->published( 'fake-source-12' ) );
	}

	/** A respawned worker's first failing pass keeps the failing_since its predecessor published. */
	public function test_failing_since_survives_a_worker_recycle(): void {
		$memd = $this->publishing();
		$memd->set( Source_Node::status_key( 'fake-source-12', self::PARTITION ), self::snapshot( [ 'state' => 'failing', 'last_error' => 'HTTP 500 https://example.test/old/', 'failing_since' => 4321, 'failed' => 3, 'requests' => 3 ] ), 60 );
		self::at( 7800.0 );

		$this->pass( $this->fake(), [ 'https://example.test/feed/' ], [ 404 ] );

		$this->assertSame( 4321, $this->published( 'fake-source-12' )['failing_since'] );
	}

	/** A pass that answers after a failing one publishes ok, clearing the failure. */
	public function test_recovery_publishes_ok(): void {
		$this->publishing();
		$node = $this->fake();
		self::at( 6000.0 );
		$this->pass( $node, [ 'https://example.test/feed/' ], [ 503 ] );

		self::at( 7800.0 );
		$this->pass( $node, [ 'https://example.test/feed/' ], [ 200 ] );

		$this->assertSame( self::snapshot( [ 'state' => 'ok', 'last_ok_at' => 7800, 'failed' => 0, 'requests' => 1 ] ), $this->published( 'fake-source-12' ) );
	}

	/** A body json_answer() reads an error off counts as a failed request, its error naming the url. */
	public function test_json_error_counts_as_failed(): void {
		$this->publishing();
		self::at( 5551.0 );
		$this->lend( 'heron-vault-7731', 'https://api.linear.app' );
		$node = $this->credentialed( Linear_Source_Node::class, 'add_team', 'KESTREL' );
		$this->tick( $node );

		$this->complete( 'https://api.linear.app/graphql', '{"errors":[{"message":"bad"}]}' );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'Linear answered an error for https://api.linear.app/graphql: bad', 'failing_since' => 5551, 'failed' => 1, 'requests' => 1 ] ), $this->published( 'heron-source-31' ) );
	}

	/** An answer received() throws on counts as failed, its error naming the subject. */
	public function test_an_answer_that_throws_counts_as_failed(): void {
		$this->publishing();
		self::at( 5551.0 );
		$node                 = $this->fake();
		$node->answer_failure = new \RuntimeException( 'page-broke-6620' );

		$this->pass( $node, [ Fake_Source_Node::PAGE . '1' ], [ 200 ] );

		$this->assertSame( 'failing', $this->published( 'fake-source-12' )['state'] );
		$this->assertSame( 'an answer failed for ' . Fake_Source_Node::PAGE . '1: page-broke-6620', $this->published( 'fake-source-12' )['last_error'] );
	}

	/** A TM_ERROR's text reaches the status bare and terminal-safe, a control character scrubbed. */
	public function test_a_tm_error_publishes_its_text_terminal_safe(): void {
		$this->publishing();
		$url  = 'https://example.test/x-7731';
		$node = $this->fake( [ 'public' ], [ $url ] );
		$this->tick( $node );
		$raw                     = "HTTP 500\x1b[31m {$url}";
		$error                   = Message::new_message();
		$error[ Message::TYPE ]  = Message::TM_ERROR;
		$error[ Message::KEY ]   = $url;
		$error[ Message::VALUE ] = $raw;

		$this->in_drain( static fn () => $node->fill( $error ) );

		$published = $this->published( 'fake-source-12' )['last_error'];
		$this->assertSame( Core::terminal_safe( $raw ), $published );
		$this->assertStringNotContainsString( "\x1b", $published );
	}

	/** A failure's text is clipped to 500 bytes on a character boundary before it reaches the cache. */
	public function test_a_long_error_is_clipped_on_a_character_boundary(): void {
		$this->publishing();
		$node                 = $this->fake();
		$message              = 'a' . \str_repeat( 'é', 999 ) . 'z';
		$node->answer_failure = new \RuntimeException( $message );

		$this->pass( $node, [ Fake_Source_Node::PAGE . '1' ], [ 200 ] );

		$full  = 'an answer failed for ' . Fake_Source_Node::PAGE . "1: {$message}";
		$error = $this->published( 'fake-source-12' )['last_error'];
		$this->assertSame( 2000, \strlen( $message ) );
		$this->assertSame( \mb_strcut( $full, 0, 500, 'UTF-8' ), $error );
		$this->assertLessThanOrEqual( 500, \strlen( $error ) );
		$this->assertGreaterThanOrEqual( 499, \strlen( $error ) );
		$this->assertTrue( \mb_check_encoding( $error, 'UTF-8' ) );
	}

	/** A scheduled pass that throws publishes failing with the throw's message. */
	public function test_a_scheduled_pass_that_throws_publishes_failing(): void {
		$this->publishing();
		$node          = $this->scheduled();
		$node->failure = new \RuntimeException( 'fetch-broke-8812' );

		$this->tick_at( $node, self::BOUNDARY - 4.0 );
		$this->tick_at( $node, self::BOUNDARY + 4.0 );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'fetch-broke-8812', 'failing_since' => self::BOUNDARY + 4, 'failed' => 0, 'requests' => 0 ] ), $this->published( 'fake-source-12' ) );
	}

	/** A scheduled pass's throw is published terminal-safe, its control characters rendered. */
	public function test_a_scheduled_throw_is_published_terminal_safe(): void {
		$this->publishing();
		$node          = $this->scheduled();
		$node->failure = new \RuntimeException( "fetch-broke\e[2J-8813" );

		$this->tick_at( $node, self::BOUNDARY - 4.0 );
		$this->tick_at( $node, self::BOUNDARY + 4.0 );

		$this->assertSame( 'fetch-broke<1B>[2J-8813', $this->published( 'fake-source-12' )['last_error'] ?? null );
	}

	/**
	 * A throw dropping what an earlier pass stranded in the queue publishes
	 * this pass's counts, not the stranded pass's.
	 */
	public function test_a_throw_dropping_a_stranded_queue_publishes_this_passs_counts(): void {
		$this->publishing();
		$urls                       = [ 'https://heron-3301.example/a', 'https://kite-3302.example/b', 'https://wren-3303.example/c' ];
		$node                       = $this->scheduled();
		$node->first                = $urls;
		$this->refusing[ $urls[1] ] = true;
		$this->tick_at( $node, self::BOUNDARY - 3.0 );
		$this->tick( $node );
		try {
			$this->complete( $urls[0], self::page( [] ) );
			$this->fail( 'the next send threw nothing' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( "dispatch-down-5108 {$urls[1]}", $e->getMessage() );
		}
		$node->abandon_failure = new \RuntimeException( 'abandon-broke-6604' );

		$this->tick_at( $node, self::BOUNDARY + 3.0 );

		$this->assertSame( self::snapshot( [ 'state' => 'failing', 'last_error' => 'abandon-broke-6604', 'failing_since' => self::BOUNDARY + 3, 'failed' => 0, 'requests' => 0 ] ), $this->published( 'fake-source-12' ) );
	}

	/** The credential gate publishes nothing and logs nothing: the request derives idle from the Vault. */
	public function test_credential_gate_publishes_nothing_and_logs_nothing(): void {
		$memd             = $this->publishing();
		Core::$recent_log = [];
		$node             = $this->credentialed( Linear_Source_Node::class, 'add_team', 'KESTREL' );

		$this->tick( $node );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
		$this->assertSame( [], $memd->sets );
		$this->assertSame( [], Core::$recent_log );
	}

	/**
	 * A pass with nothing to ask, as a Linear source without a team, publishes
	 * a quiet ok with both counts 0.
	 */
	public function test_a_pass_with_nothing_to_ask_publishes_a_quiet_ok(): void {
		$this->publishing();
		self::at( 6273.0 );
		$this->lend( 'heron-vault-7731', 'https://api.linear.app' );

		$this->tick( $this->credentialed( Linear_Source_Node::class, '', '' ) );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 0 ] ] ], $this->answers() );
		$this->assertSame( self::snapshot( [ 'state' => 'ok', 'last_ok_at' => 6273, 'failed' => 0, 'requests' => 0 ] ), $this->published( 'heron-source-31' ) );
	}

	/** Each class names the url its credential is lent on, with no instance; a source needing none names ''. */
	public function test_credential_url_is_static_per_class(): void {
		$this->assertSame( 'https://api.linear.app/graphql', Linear_Source_Node::credential_url() );
		$this->assertSame( 'https://slack.com/api/', Slack_Source_Node::credential_url() );
		$this->assertSame( 'https://public-api.wordpress.com/rest/v1.1/sites/', P2_Source_Node::credential_url() );
		$this->assertSame( 'https://api.unthread.io/api/conversations/', Unthread_Source_Node::credential_url() );
		$this->assertSame( '', Github_Source_Node::credential_url() );
		$this->assertSame( '', Feed_Source_Node::credential_url() );
	}

	/**
	 * A pass's requests wait in the source's Table, so the process after a
	 * recycle sends what the last one left on its first tick, with no TICK
	 * and no boundary: the request it had in flight, then the one it never
	 * sent, each with the note it was queued with.
	 */
	public function test_a_pass_cut_by_a_recycle_resumes_from_its_table(): void {
		$urls        = [ Fake_Source_Node::PAGE . 'a-1', Fake_Source_Node::PAGE . 'b-2', Fake_Source_Node::PAGE . 'c-3' ];
		$node        = $this->fake( [ 'public' ], $urls );
		$node->notes = [ $urls[1] => [ 'heron' => 'note-4471' ], $urls[2] => [ 'kite' => 'note-5520' ] ];
		$this->tick( $node );
		$this->complete( $urls[0], self::page( [ [ 'id' => 'heron-1', 'title' => 'Heron' ] ] ) );

		$successor = $this->respawn( $node, new Fake_Source_Node() );
		$this->tick_at( $successor, self::BOUNDARY + 17.0 );
		$this->assertSame( [ $urls[0], $urls[1], $urls[1] ], $this->dispatched );
		$this->complete( $urls[1], self::page( [ [ 'id' => 'kite-2', 'title' => 'Kite' ] ] ) );
		$this->complete( $urls[2], self::page( [] ) );

		$this->assertSame( [ $urls[0], $urls[1], $urls[1], $urls[2] ], $this->dispatched );
		$this->assertSame( 0, $successor->passes, 'the pass resumed, not begun' );
		$this->assertSame( [ [ 'heron' => 'note-4471' ], [ 'kite' => 'note-5520' ] ], $successor->noted );
		$this->assertSame( [ 'fake:heron-1', 'fake:kite-2' ], $this->recorded_keys() );
	}

	/**
	 * A clean stop removes from the Table what was answered and not yet
	 * removed, so the next process sends again only what was in flight.
	 */
	public function test_a_clean_stop_sweeps_the_answered_out_of_the_table(): void {
		$urls       = \array_map( static fn ( int $i ): string => "https://dunlin-{$i}.example/page", \range( 1, 4 ) );
		$node       = $this->fake( [ 'public' ], $urls );
		$node->room = 4;
		$this->tick( $node );
		$this->complete( $urls[0], self::page( [ [ 'id' => 'dunlin-1', 'title' => 'Dunlin' ] ] ) );

		$node->shutdown_sweep();
		$successor       = new Fake_Source_Node();
		$successor->room = 4;
		$this->tick_at( $this->respawn( $node, $successor ), self::BOUNDARY + 41.0 );

		$this->assertSame( [ ...$urls, ...\array_slice( $urls, 1 ) ], $this->dispatched );
	}

	/** A pass that ended leaves nothing in its Table, so the next process's first tick sends nothing. */
	public function test_a_finished_pass_leaves_nothing_to_resume(): void {
		$node = $this->fake();
		$this->fetch_page( $node, [] );

		$this->tick_at( $this->respawn( $node, new Fake_Source_Node() ), self::BOUNDARY + 17.0 );

		$this->assertSame( [ Fake_Source_Node::PAGE . '1' ], $this->dispatched );
	}

	/**
	 * Every request a source sends is public-only, so an address outside
	 * the global ranges is refused, failing the answer.
	 */
	public function test_every_request_reaches_public_addresses_alone(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->fake( [ 'public' ], [ Fake_Source_Node::PAGE . '1' ] );
		$this->connected[ Fake_Source_Node::PAGE . '1' ] = '10.20.30.40';
		$this->tick( $node );

		$this->complete( Fake_Source_Node::PAGE . '1', self::page( [ [ 'id' => 'inside-1', 'title' => 'Inside' ] ] ) );

		$this->assertSame( '', $this->opts[ Fake_Source_Node::PAGE . '1' ][ \CURLOPT_PROXY ] );
		$this->assertSame( [], $this->recorded_keys() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a fetch failed for ' . Fake_Source_Node::PAGE . '1: refused private address' ) );
	}

	/** A source answering public_only() false sends its requests through a proxy, which Curl refuses beside the flag. */
	public function test_a_source_reaching_private_addresses_sends_through_its_proxy(): void {
		$node           = $this->wire( new Fake_Tunnel_Source_Node(), 'tunnel-source-31', [ 'internal' ] );
		$node->first    = [];
		$node->subjects = [ 'tunnel/page?4' => 'https://tunnel-6610.example/mcp' ];
		$node->options  = [ 'tunnel/page?4' => [ 'proxy' => 'socks5h://proxy-heron.example:1099' ] ];
		$this->tick( $node );

		$this->complete( 'https://tunnel-6610.example/mcp', self::page( [ [ 'id' => 'tunnel-4', 'title' => 'Tunnel' ] ] ) );

		$this->assertSame( 'socks5h://proxy-heron.example:1099', $this->opts['https://tunnel-6610.example/mcp'][ \CURLOPT_PROXY ] );
		$this->assertSame( [ 'fake:tunnel-4' ], $this->recorded_keys() );
	}

	/**
	 * A source owns its Table, `<name>:frontier`, a sqlite file of the
	 * worker's partition, as the analyzer reads it off the class.
	 */
	public function test_a_source_owns_its_frontier_table(): void {
		$node  = $this->fake();
		$table = Core::node( 'fake-source-12:frontier' );

		$this->assertInstanceOf( \Newspack_Nodes\Table_Node::class, $table );
		$this->assertSame( $node, $table->publisher() );
		$this->assertSame( [ 'fake-source-12', (string) \Newspack_Nodes\Frontier::MEMBER_TTL, 'sqlite' ], $table->arguments() );
		$this->assertSame( [ 'frontier' => 'Table' ], Fake_Source_Node::node_schema()['owns'] );
		$this->assertSame(
			[
				'namespace' => 'heron-feed-7',
				'ttl'       => (string) \Newspack_Nodes\Frontier::MEMBER_TTL,
				'backend'   => 'sqlite',
			],
			Fake_Source_Node::owned_table( 'frontier', 'heron-feed-7', [ 'public' ] )
		);
		$this->assertStringNotContainsString( 'fake-source-12:frontier', $node->dump_config() );
	}

	public function test_source_schema_declares_the_shared_tick_contract(): void {
		$schema = Fake_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'Fake source', $schema['description'] );
		$this->assertCount( 1, $schema['requests'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertSame( 'Fetch fake items.', $schema['requests'][0]['description'] );
		$this->assertSame( '{ fetching }', $schema['requests'][0]['reply_shape'] );
		$this->assertIsCallable( $schema['requests'][0]['handler'] );
		$this->assertContains( 'set_tier', \array_column( $schema['commands'], 'name' ) );
		$this->assertSame( [ 'tier', 'vault_group' ], \array_column( $schema['arguments'], 'name' ) );
		$this->assertTrue( $schema['arguments'][0]['required'] );
		$this->assertSame( 'vault_group', $schema['arguments'][1]['type'] );
		$this->assertSame( '', $schema['arguments'][1]['default'] );
	}

	/**
	 * A source that is not limited to any host runs anywhere, whatever its line
	 * names.
	 */
	public function test_a_source_runs_here_by_default(): void {
		$this->assertTrue(
			Linear_Source_Node::runs_here(
				[
					'tier'        => 'restricted',
					'vault_group' => 'heron-7719',
					'auth_dir'    => '/tmp/no-relay-7719',
				]
			)
		);
	}
}

/**
 * Concrete Source_Node over a JSON page API: each pass opens on `first`, and
 * each page's body is `{ items, next }`, its records and the urls it names
 * next.
 */
class Fake_Source_Node extends Source_Node {

	public const PAGE = 'https://fake-4471.example/page/';

	/** @var list<string> The urls each pass opens on. */
	public array $first = [];

	/** @var array<string,string> The url each pass POSTs under each subject, after the first urls. */
	public array $subjects = [];

	/** @var array<string,array<string,mixed>> The fields each subject's request adds: its headers, its proxy. */
	public array $options = [];

	/** @var list<string> Every subject received() was handed, in order. */
	public array $heard = [];

	/** @var array<string,array<string,mixed>> The note each first url is queued with. */
	public array $notes = [];

	/** @var list<array<array-key,mixed>> The note of every answer received() was handed, in order. */
	public array $noted = [];

	public int $passes = 0;

	/** Requests in flight at once; 1, as for every API source, unless a test widens it. */
	public int $room = 1;

	/** What the next received() throws, once; null for none. */
	public ?\Throwable $answer_failure = null;

	/** What the next abandoned() throws, once; null for none. */
	public ?\Throwable $abandon_failure = null;

	/** Whether the next tick resumes a pass, off the grid, once. */
	public bool $resumable = false;

	/** Whether a key's raw id is the part after its last colon, past a lane; off, as on the base, unless a test turns it on. */
	public bool $lanes = false;

	/** What begin() throws, after counting the pass; null for none. */
	public ?\Throwable $failure = null;

	protected function begin(): void {
		++$this->passes;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		foreach ( $this->first as $url ) {
			$this->queue( $url, null, null, $this->notes[ $url ] ?? [] );
		}
		foreach ( $this->subjects as $subject => $url ) {
			$this->queue( $url, "body:{$subject}", $subject );
		}
	}

	protected function abandoned( string $subject ): void {
		$failure               = $this->abandon_failure;
		$this->abandon_failure = null;
		if ( null !== $failure ) {
			throw $failure;
		}
	}

	protected function resumes(): bool {
		$resume          = $this->resumable;
		$this->resumable = false;
		return $resume;
	}

	protected function raw_id( string $key ): ?string {
		return $this->lanes ? \substr( $key, (int) \strrpos( $key, ':' ) + 1 ) : null;
	}

	/** @return array<string,mixed> */
	protected function request_options( string $subject ): array {
		return $this->options[ $subject ] ?? [];
	}

	/** @return array<int,array<string,mixed>> */
	protected function max_in_flight(): int {
		return $this->room;
	}

	protected function received( string $url, string $body ): array {
		$this->heard[]        = $url;
		$this->noted[]        = $this->note();
		$failure              = $this->answer_failure;
		$this->answer_failure = null;
		if ( null !== $failure ) {
			throw $failure;
		}
		$page = Core::arr( \json_decode( $body, true ) );
		foreach ( Core::arr( $page['next'] ) as $next ) {
			$this->queue( Core::as_string( $next ) );
		}
		return \array_map(
			fn ( array $r ): array => $r['raw'] ?? $this->normalize_item( 'fake', $r['id'], $r['title'] ?? '', $r['url'] ?? '', $r['body'] ?? '', $r['when'] ?? '' ),
			Core::arr( $page['items'] )
		);
	}

	public static function node_schema(): array {
		return self::source_schema( 'Fake source', 'Fetch fake items.' );
	}
}

/** A fake source whose Curl follows no redirect, answering each Location to re-queue. */
class Fake_Unfollowing_Source_Node extends Fake_Source_Node {

	protected function follows_redirects(): bool {
		return false;
	}
}

/** A fake source that reaches private addresses through its proxy, as a tunnel does. */
class Fake_Tunnel_Source_Node extends Fake_Source_Node {

	protected function public_only(): bool {
		return false;
	}
}

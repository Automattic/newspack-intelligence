<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class SourceNodeTest extends TestCase {

	private const RETRIEVED = 1793011234.5;

	protected function setUp(): void {
		parent::setUp();
		Core::$now = self::RETRIEVED;
	}

	protected function tearDown(): void {
		Feed_Source_Node::$http_get = null;
		parent::tearDown();
	}

	private function tick( string $verb = 'TICK' ): array {
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_REQUEST;
		$request[ Message::FROM ]  = '_repl/intelligence-asker';
		$request[ Message::ID ]    = '4:812:97';
		$request[ Message::KEY ]   = 'tick-key-31';
		$request[ Message::VALUE ] = $verb;
		return $request;
	}

	/**
	 * The emitted event structs (a TICK then answers the request, which these
	 * event-count assertions exclude).
	 *
	 * @param array<int,array<int,mixed>> $captured
	 * @return array<int,array<int,mixed>>
	 */
	private function structs( array $captured ): array {
		return \array_values(
			\array_filter( $captured, static fn ( $m ) => Message::TM_STRUCT === ( $m[ Message::TYPE ] & ( Message::TM_STRUCT | Message::TM_RESPONSE ) ) )
		);
	}

	/** A fake source with its tier set and a capture sink wired. */
	private function fake( Capture_Sink_Node $sink, string $tier = 'public' ): Fake_Source_Node {
		$node = new Fake_Source_Node();
		$node->name( 'fake-source-12' );
		$node->arguments( [ $tier ] );
		$node->sink( $sink );
		return $node;
	}

	public function test_a_source_needs_its_tier_at_load(): void {
		$node = new Fake_Source_Node();
		$node->name( 'fake-source-12' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: tier' );
		$node->arguments( [] );
	}

	public function test_a_tier_outside_the_set_is_refused_at_load_and_by_set_tier(): void {
		$node = $this->fake( new Capture_Sink_Node(), 'internal' );
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

	public function test_a_tick_emits_item_recorded_events_with_the_sources_tier(): void {
		Feed_Source_Node::$http_get = static fn ( string $url, array $args ): array => [
			'response' => [ 'code' => 200 ],
			'body'     => '<?xml version="1.0"?><rss version="2.0"><channel><item>'
				. '<title>Harbor Gazette wins state award</title>'
				. '<link>https://harborgazette.example/award</link>'
				. '<description>The Gazette took first place.</description>'
				. '<guid>abc-7731</guid>'
				. '<pubDate>Wed, 21 Oct 2026 14:00:00 GMT</pubDate>'
				. '</item></channel></rss>',
		];
		$sink = new Capture_Sink_Node();
		$node = new Feed_Source_Node();
		$node->name( 'feed' );
		$node->interpreter()->dispatch( 'add_url', [ 'https://harborgazette.example/feed/' ] );
		$node->arguments( [ 'public' ] );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_tier', [ 'internal' ] ) );
		$node->target( 'intel:writer' );
		$node->sink( $sink );

		$node->fill( $this->tick() );

		$this->assertCount( 2, $sink->captured, 'one event, then the reply; no DONE' );
		$message = $sink->captured[0];
		$this->assertSame( Message::TM_STRUCT, $message[ Message::TYPE ] );
		$this->assertSame( 'feed', $message[ Message::FROM ] );
		$this->assertSame( 'intel:writer', $message[ Message::TO ] );
		$event = $message[ Message::VALUE ];
		$this->assertSame( 'item_recorded', $event['event'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $event['event_id'] );
		$this->assertSame( self::RETRIEVED, $event['ts'] );
		$this->assertSame( 'machine:source:feed@' . NEWSPACK_INTELLIGENCE_VERSION, $event['actor'] );
		$this->assertSame( NEWSPACK_INTELLIGENCE_VERSION, $event['config_version'] );
		$this->assertNotSame( '', NEWSPACK_INTELLIGENCE_VERSION );
		$this->assertSame( 'feed:abc-7731', $event['item_key'] );
		$this->assertSame( 'feed', $event['source'] );
		$this->assertSame( 'internal', $event['tier'] );
		$this->assertSame( 'https://harborgazette.example/award', $event['url'] );
		$this->assertSame( 'Harbor Gazette wins state award', $event['title'] );
		$this->assertSame( 'The Gazette took first place.', $event['body'] );
		$this->assertSame( 1792591200, $event['item_ts'] );
		$this->assertSame( 1793011234, $event['retrieved_ts'], 'whole seconds, as item_ts and every reader take it' );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 1, 'refused' => 0 ] ], $sink->captured[1][ Message::VALUE ] );
	}

	public function test_a_long_body_is_cut_on_a_character_boundary(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$body        = 'a' . \str_repeat( 'é', 32770 );
		$node->items = [ [ 'id' => 'long-1', 'title' => 'Long', 'body' => $body ] ];

		$node->fill( $this->tick() );

		$cut = $this->structs( $sink->captured )[0][ Message::VALUE ]['body'];
		$this->assertSame( 65541, \strlen( $body ) );
		$this->assertSame( 65535, \strlen( $cut ) );
		$this->assertTrue( \mb_check_encoding( $cut, 'UTF-8' ) );
		$this->assertSame( 'a' . \str_repeat( 'é', 32767 ), $cut );
	}

	public function test_a_short_body_passes_whole(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => 'short-1', 'title' => 'Short', 'body' => 'Twelve bytes' ] ];

		$node->fill( $this->tick() );

		$this->assertSame( 'Twelve bytes', $this->structs( $sink->captured )[0][ Message::VALUE ]['body'] );
	}

	public function test_an_unparseable_date_records_item_ts_zero(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => 'when-1', 'title' => 'Undated', 'when' => 'the third moon' ] ];

		$node->fill( $this->tick() );

		$this->assertSame( 0, $this->structs( $sink->captured )[0][ Message::VALUE ]['item_ts'] );
	}

	public function test_tick_answers_the_request_with_the_count_it_emitted(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink, 'restricted' );
		$node->items = [
			[ 'id' => '1', 'title' => 'A' ],
			[ 'id' => '1', 'title' => 'A again' ],
			[ 'id' => '2', 'title' => 'B' ],
		];

		$node->fill( $this->tick() );

		$this->assertCount( 3, $sink->captured );
		$this->assertSame( 'restricted', $sink->captured[1][ Message::VALUE ]['tier'] );
		$reply = \end( $sink->captured );
		$this->assertSame( Message::TM_STRUCT | Message::TM_RESPONSE, $reply[ Message::TYPE ] );
		$this->assertSame( 'fake-source-12', $reply[ Message::FROM ] );
		$this->assertSame( '_repl/intelligence-asker', $reply[ Message::TO ] );
		$this->assertSame( '4:812:97', $reply[ Message::ID ] );
		$this->assertSame( 'tick-key-31', $reply[ Message::KEY ] );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 2, 'refused' => 0 ] ], $reply[ Message::VALUE ] );
	}

	public function test_a_request_other_than_tick_fetches_nothing(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => '1', 'title' => 'A' ] ];

		$node->fill( $this->tick( 'SCAN' ) );

		$this->assertSame( 0, $node->fetches );
		$this->assertCount( 1, $sink->captured, 'no event: only the refusal' );
		$refusal = $sink->captured[0];
		$this->assertSame( Message::TM_ERROR, $refusal[ Message::TYPE ] & Message::TM_ERROR );
		$this->assertSame( '_repl/intelligence-asker', $refusal[ Message::TO ] );
	}

	/** An item make() refuses is skipped and counted; the rest of the fetch records. */
	public function test_a_refused_item_is_skipped_and_counted_without_throwing(): void {
		Core::$recent_log = [];
		$sink             = new Capture_Sink_Node();
		$node             = $this->fake( $sink );
		$node->items      = [
			[ 'raw' => [ 'item_key' => 'fake:harbor-9', 'source' => 'fake', 'tier' => 'public' ] ],
			[ 'id' => 'harbor-10', 'title' => 'Harbor Gazette wins state award' ],
			[ 'id' => 'harbor-11', 'title' => 'Harbor Gazette names an editor' ],
		];

		$node->fill( $this->tick() );

		$this->assertSame( [ 'fake:harbor-10', 'fake:harbor-11' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->structs( $sink->captured ) ) );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 2, 'refused' => 1 ] ], \end( $sink->captured )[ Message::VALUE ] );
		$this->assertStringContainsString( 'fake:harbor-9: item_recorded: missing field url', \implode( "\n", Core::$recent_log ) );
	}

	/**
	 * An item whose key runs past Graph_Curation::ITEM_KEY_BYTES as the stream
	 * writes it, JSON-escaped, is refused with a line naming the source and
	 * the key's length, so no record naming it outgrows one line.
	 */
	public function test_an_item_key_past_its_cap_is_refused(): void {
		$stderr      = $this->capture_stderr();
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [
			[ 'id' => \str_repeat( '"', 253 ), 'title' => 'At the cap, escaped' ],
			[ 'id' => \str_repeat( '"', 254 ), 'title' => 'One byte past it' ],
		];

		$node->fill( $this->tick() );

		$this->assertSame( [ 'fake:' . \str_repeat( '"', 253 ) ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->structs( $sink->captured ) ) );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 1, 'refused' => 1 ] ], \end( $sink->captured )[ Message::VALUE ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: refused an item whose key runs 513 bytes, past 512: fake:' . \str_repeat( '"', 59 ) ) );
		$this->assertSame( 512, \Newspack_Intelligence\Graph_Curation::ITEM_KEY_BYTES );
	}

	/** A key refused for its size is remembered, so the next tick neither counts nor logs it again. */
	public function test_a_size_refused_key_is_refused_once_per_process(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => \str_repeat( 'k', 600 ), 'title' => 'Too long a key' ] ];
		$node->fill( $this->tick() );
		Core::$recent_log_timers = [];
		$stderr                  = $this->capture_stderr();

		$node->fill( $this->tick() );

		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 0, 'refused' => 0 ] ], \end( $sink->captured )[ Message::VALUE ] );
		$this->assertCount( 0, self::stderr_lines( $stderr, 'refused an item whose key runs' ) );
	}

	/** The refused key is named cut to 64 bytes and terminal-safe, so a hostile key cannot drive the operator's terminal. */
	public function test_a_size_refused_key_is_named_terminal_safe(): void {
		$stderr      = $this->capture_stderr();
		$node        = $this->fake( new Capture_Sink_Node() );
		$node->items = [ [ 'id' => "\033[2J" . \str_repeat( 'k', 600 ), 'title' => 'A hostile key' ] ];

		$node->fill( $this->tick() );

		$lines = self::stderr_lines( $stderr, 'refused an item whose key runs 614 bytes, past 512: fake:' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\033", \implode( '', $lines ) );
	}

	/** A refused item is not remembered, so a later fetch of it records. */
	public function test_a_refused_item_is_recorded_on_a_later_tick(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'raw' => [ 'item_key' => 'fake:harbor-9', 'source' => 'fake', 'tier' => 'public' ] ] ];
		$node->fill( $this->tick() );

		$node->items = [ [ 'id' => 'harbor-9', 'title' => 'Harbor Gazette wins state award' ] ];
		$node->fill( $this->tick() );

		$this->assertSame( [ 'fake:harbor-9' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->structs( $sink->captured ) ) );
	}

	public function test_dedups_by_item_key_across_ticks(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => '1', 'title' => 'A' ] ];

		$node->fill( $this->tick() );
		$node->fill( $this->tick() );

		$this->assertCount( 1, $this->structs( $sink->captured ), 'a seen item_key must not be re-emitted on a later tick' );
	}

	public function test_non_request_message_is_ignored(): void {
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => '1' ] ];

		$data                  = Message::new_message();
		$data[ Message::TYPE ] = Message::TM_STRUCT;
		$node->fill( $data );

		$this->assertCount( 0, $sink->captured );
	}

	public function test_seen_set_is_bounded_and_evicts_the_oldest_key(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->fake( $sink );
		for ( $i = 1; $i <= 2001; $i++ ) {
			$node->items[] = [ 'id' => "$i", 'title' => "Item $i" ];
		}
		$node->fill( $this->tick() );

		$node->items = [ [ 'id' => '1', 'title' => 'Item 1 again' ] ];
		$node->fill( $this->tick() );

		$this->assertCount( 2002, $this->structs( $sink->captured ) );
	}

	public function test_the_tier_rides_the_make_node_line(): void {
		$node = $this->fake( new Capture_Sink_Node(), 'public' );
		$node->interpreter()->dispatch( 'set_tier', [ 'restricted' ] );

		$dump = $node->dump_config();
		$this->assertMatchesRegularExpression( '/^make_node \S+ fake-source-12 restricted$/m', $dump );
		$this->assertStringNotContainsString( 'set_tier', $dump );
	}

	public function test_source_schema_declares_the_shared_tick_contract(): void {
		$schema = Fake_Source_Node::node_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'Fake source', $schema['description'] );
		$this->assertCount( 1, $schema['requests'] );
		$this->assertSame( 'TICK', $schema['requests'][0]['name'] );
		$this->assertSame( 'Fetch fake items.', $schema['requests'][0]['description'] );
		$this->assertIsCallable( $schema['requests'][0]['handler'] );
		$this->assertContains( 'set_tier', \array_column( $schema['commands'], 'name' ) );
		$this->assertSame( [ 'tier' ], \array_column( $schema['arguments'], 'name' ) );
		$this->assertTrue( $schema['arguments'][0]['required'] );
	}
}

/**
 * Concrete Source_Node whose fetch() normalizes canned records, so the base's
 * fill()/dedup/emit behavior can be tested without HTTP.
 */
class Fake_Source_Node extends Source_Node {

	/** @var array<int,array<string,mixed>> Records: id, title, url, body, when; or `raw`, returned as fetched. */
	public array $items = [];

	public int $fetches = 0;

	protected function config(): array {
		return [];
	}

	public function fetch( array $config ): array {
		++$this->fetches;
		return \array_map(
			fn ( array $r ): array => $r['raw'] ?? $this->normalize_item( 'fake', $r['id'], $r['title'] ?? '', $r['url'] ?? '', $r['body'] ?? '', $r['when'] ?? '' ),
			$this->items
		);
	}

	public static function node_schema(): array {
		return self::source_schema( 'Fake source', 'Fetch fake items.' );
	}
}

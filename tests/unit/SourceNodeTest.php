<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Github_Source_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;

require_once __DIR__ . '/../support/seeds-intel-graph.php';

final class SourceNodeTest extends TestCase {
	use Seeds_Intel_Graph;

	private const RETRIEVED = 1793011234.5;

	/** A source's fetch interval, in seconds: 1,700, no default's. */
	private const INTERVAL = 1700;

	/** A grid boundary: a whole multiple of INTERVAL. */
	private const BOUNDARY = self::INTERVAL * 1054712;

	protected function setUp(): void {
		parent::setUp();
		Core::$now = self::RETRIEVED;
		self::backbone();
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** A Router and the interpreter sinking into it, as a worker holds them. */
	private static function backbone(): void {
		$router = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
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
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->name( 'feed' );
		$node->arguments( [ 'public' ] );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_tier', [ 'internal' ] ) );
		$node->target( 'intel:writer' );
		$node->sink( $sink );
		$node->items = [
			[
				'id'    => 'abc-7731',
				'title' => 'Harbor Gazette wins state award',
				'url'   => 'https://harborgazette.example/award',
				'body'  => 'The Gazette took first place.',
				'when'  => 'Wed, 21 Oct 2026 14:00:00 GMT',
			],
		];

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
		$this->assertSame( 'fake:abc-7731', $event['item_key'] );
		$this->assertSame( 'fake', $event['source'] );
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

	/** A source with an interval set, its tick clock at a moment. */
	private function scheduled( Capture_Sink_Node $sink ): Fake_Source_Node {
		$node = $this->fake( $sink );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_interval', [ (string) self::INTERVAL ] ) );
		$node->items = [ [ 'id' => 'grid-1', 'title' => 'On the grid' ] ];
		return $node;
	}

	/** Run one Router tick through the source at a moment. */
	private static function tick_at( Fake_Source_Node $node, float $at ): void {
		Core::$now = $at;
		$node->fire_cb();
	}

	/** A source with an interval rides the Router's tick, and its first tick in a process fetches nothing. */
	public function test_the_first_tick_of_a_process_fetches_nothing(): void {
		$node = $this->scheduled( new Capture_Sink_Node() );

		$this->assertSame( 'router', $node->timer_mode() );
		self::tick_at( $node, self::BOUNDARY - 5.0 );
		self::tick_at( $node, self::BOUNDARY + 3.0 - self::INTERVAL + 10 );

		$this->assertSame( 0, $node->fetches, 'no boundary crossed since the first tick' );
	}

	/** Each boundary crossed fetches once, and a second tick past the same boundary fetches nothing. */
	public function test_each_boundary_crossed_fetches_once(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->scheduled( $sink );

		self::tick_at( $node, self::BOUNDARY - 7.0 );
		self::tick_at( $node, self::BOUNDARY + 2.0 );
		self::tick_at( $node, self::BOUNDARY + 41.0 );
		$this->assertSame( 1, $node->fetches );
		$this->assertSame( [ 'fake:grid-1' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->structs( $sink->captured ) ) );

		self::tick_at( $node, self::BOUNDARY + self::INTERVAL + 1.0 );
		$this->assertSame( 2, $node->fetches );
	}

	/** A boundary passed while no tick ran is skipped; the next tick past it fetches once. */
	public function test_ticks_far_apart_fetch_once(): void {
		$node = $this->scheduled( new Capture_Sink_Node() );

		self::tick_at( $node, self::BOUNDARY - 9.0 );
		self::tick_at( $node, self::BOUNDARY + 3 * self::INTERVAL + 4.0 );

		$this->assertSame( 1, $node->fetches );
	}

	/** A scheduled fetch that throws is logged naming the source, and the Router's tick carries on. */
	public function test_a_scheduled_fetch_that_throws_is_logged_and_contained(): void {
		$stderr        = $this->capture_stderr();
		$node          = $this->scheduled( new Capture_Sink_Node() );
		$node->failure = new \RuntimeException( 'fetch-broke-4471' );

		self::tick_at( $node, self::BOUNDARY - 4.0 );
		self::tick_at( $node, self::BOUNDARY + 4.0 );

		$this->assertSame( 1, $node->fetches );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: a scheduled fetch failed; trying again at the next boundary: fetch-broke-4471' ) );
	}

	/** A cooperative stop inside a scheduled fetch stops the worker. */
	public function test_a_stop_inside_a_scheduled_fetch_propagates(): void {
		$node          = $this->scheduled( new Capture_Sink_Node() );
		$node->failure = new Worker_Should_Stop( 'max_runtime 595 reached' );
		self::tick_at( $node, self::BOUNDARY - 4.0 );

		$this->expectException( Worker_Should_Stop::class );
		$this->expectExceptionMessage( 'max_runtime 595 reached' );
		self::tick_at( $node, self::BOUNDARY + 4.0 );
	}

	/** A source with no interval holds no timer, and only TICK fetches. */
	public function test_no_interval_fetches_only_on_tick(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->fake( $sink );
		$node->items = [ [ 'id' => 'manual-1', 'title' => 'Asked for' ] ];

		$this->assertFalse( $node->timer_is_active() );
		self::tick_at( $node, self::BOUNDARY - 3.0 );
		self::tick_at( $node, self::BOUNDARY + 3.0 );
		$this->assertSame( 0, $node->fetches );

		$node->fill( $this->tick() );
		$this->assertSame( 1, $node->fetches );
	}

	/** The interval round-trips through dump_config(), and a source with none dumps no line. */
	public function test_set_interval_rides_the_dump(): void {
		$this->assertStringNotContainsString( 'set_interval', $this->fake( new Capture_Sink_Node() )->dump_config() );
		Core::reset();
		self::backbone();
		$node = $this->scheduled( new Capture_Sink_Node() );

		$dump = $node->dump_config();
		$this->assertStringContainsString( "command_node fake-source-12:config set_interval 1700\n", $dump );

		Core::reset();
		self::backbone();
		$replayed = $this->fake( new Capture_Sink_Node() );
		$this->replay_config( $dump, $replayed );
		$this->assertSame( self::INTERVAL, $this->read_private( $replayed, 'interval' ) );
		$this->assertTrue( $replayed->timer_is_active() );
	}

	/** An interval below one second is refused, and the source keeps none. */
	public function test_set_interval_refuses_less_than_a_second(): void {
		$node = $this->fake( new Capture_Sink_Node() );

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
	 * projection lacks, reading the projection once for the fetch; the held
	 * item is remembered, so the next tick reads nothing.
	 */
	public function test_a_fresh_source_records_only_what_the_projection_lacks(): void {
		$this->start_intel_graph();
		$this->seed( self::item( 'fake:harbor-held', 'Harbor Gazette already recorded', 'fake', 'public', self::$oct_13 ) );
		$reads       = self::counted_reads();
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [
			[ 'id' => 'harbor-held', 'title' => 'Harbor Gazette already recorded' ],
			[ 'id' => 'harbor-fresh', 'title' => 'Harbor Gazette names a publisher' ],
		];

		$node->fill( $this->tick() );

		$this->assertSame( [ 'fake:harbor-fresh' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item_key'], $this->structs( $sink->captured ) ) );
		$this->assertSame( [ 'verb' => 'TICK', 'data' => [ 'emitted' => 1, 'refused' => 0 ] ], \end( $sink->captured )[ Message::VALUE ] );
		$this->assertCount( 1, $reads );

		$sink->captured = [];
		$node->fill( $this->tick() );
		$this->assertSame( [], $this->structs( $sink->captured ) );
		$this->assertCount( 1, $reads, 'every key is seen, so nothing is read' );
	}

	/** A projection that will not open costs repeats, not items: everything records, logged. */
	public function test_a_projection_that_will_not_open_records_everything_logged(): void {
		$stderr                            = $this->capture_stderr();
		Intel_Graph_Row_Schema::$read_rows = static function (): never {
			throw new Table_Unavailable( 'graph-gone-7731' );
		};
		$sink        = new Capture_Sink_Node();
		$node        = $this->fake( $sink );
		$node->items = [ [ 'id' => 'harbor-a', 'title' => 'A' ], [ 'id' => 'harbor-b', 'title' => 'B' ] ];

		$node->fill( $this->tick() );

		$this->assertCount( 2, $this->structs( $sink->captured ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'fake-source-12: no intel-graph to read recorded items from; recording every item fetched: graph-gone-7731' ) );
	}

	public function test_the_tier_rides_the_make_node_line(): void {
		$node = $this->fake( new Capture_Sink_Node(), 'public' );
		$node->interpreter()->dispatch( 'set_tier', [ 'restricted' ] );

		$dump = $node->dump_config();
		$this->assertMatchesRegularExpression( '/^make_node \S+ fake-source-12 restricted$/m', $dump );
		$this->assertStringNotContainsString( 'set_tier', $dump );
	}

	/** No source fires a FIRE event, so none declares one. */
	public function test_no_source_declares_a_fire_registration(): void {
		foreach ( [ Fake_Source_Node::class, Github_Source_Node::class, Linear_Source_Node::class, Feed_Source_Node::class ] as $class ) {
			$this->assertSame( [], $class::node_schema()['registrations'], $class );
		}
	}

	/**
	 * The seam a TICK and the grid call is the source's own tick(); the
	 * feed fetches by pass and declares no fetch(), and no Source interface
	 * stands over the three.
	 */
	public function test_tick_is_the_seam_every_source_implements(): void {
		$this->assertTrue( ( new \ReflectionMethod( Source_Node::class, 'tick' ) )->isAbstract() );
		$this->assertFalse( \method_exists( Feed_Source_Node::class, 'fetch' ) );
		$this->assertFalse( \interface_exists( 'Newspack_Intelligence\\Source' ) );
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
 * Concrete Source_Node whose tick() records canned records, so the base's
 * fill()/grid/record behavior can be tested without HTTP.
 */
class Fake_Source_Node extends Source_Node {

	/** @var array<int,array<string,mixed>> Records: id, title, url, body, when; or `raw`, returned as fetched. */
	public array $items = [];

	public int $fetches = 0;

	/** What fetch() throws, after counting the fetch; null for none. */
	public ?\Throwable $failure = null;

	protected function tick(): array {
		return $this->record( $this->fetch() );
	}

	/** @return array<int,array<string,mixed>> */
	public function fetch(): array {
		++$this->fetches;
		if ( null !== $this->failure ) {
			throw $this->failure;
		}
		return \array_map(
			fn ( array $r ): array => $r['raw'] ?? $this->normalize_item( 'fake', $r['id'], $r['title'] ?? '', $r['url'] ?? '', $r['body'] ?? '', $r['when'] ?? '' ),
			$this->items
		);
	}

	public static function node_schema(): array {
		return self::source_schema( 'Fake source', 'Fetch fake items.', '{ emitted, refused }' );
	}
}

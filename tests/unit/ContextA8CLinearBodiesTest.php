<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_Linear_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;

require_once __DIR__ . '/../support/drives-context-a8c.php';
require_once __DIR__ . '/../support/seeds-intel-graph.php';

/**
 * The whole of a Linear issue through ContextA8C: `team-issues` cuts a
 * description at 300 characters, so an issue that moved is asked of `issue`
 * and recorded whole, a stored one grows, and the items of the last 30 days
 * still cut short are walked under the request cap across passes.
 */
final class ContextA8CLinearBodiesTest extends TestCase {
	use Drives_Context_A8C;
	use Seeds_Intel_Graph;

	/** Seconds between passes, as the topology sets it. */
	private const INTERVAL = 900;

	/** A time inside two intervals of the clock. */
	private const MOVED = '2026-10-26T07:20:00Z';

	/** A time long before two intervals of the clock. */
	private const QUIET = '2026-10-20T08:00:00Z';

	protected function setUp(): void {
		parent::setUp();
		$this->install_context_a8c();
		$this->start_intel_graph();
		Core::$now = self::NOW;
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A node over one team, on the topology's interval, signed in.
	 *
	 * @param string ...$teams The team keys.
	 */
	private function linear( string ...$teams ): Context_A8C_Linear_Node {
		$this->signed_in();
		$node = $this->wire( new Context_A8C_Linear_Node(), 'linear', [ 'internal', $this->auth_dir, self::PROXY ] );
		$node->interpreter()->dispatch( 'set_interval', [ (string) self::INTERVAL ] );
		foreach ( $teams as $team ) {
			$node->interpreter()->dispatch( 'add_team', [ $team ] );
		}
		return $node;
	}

	/** A description as `team-issues` cuts it, led by $lead. */
	private static function cut( string $lead ): string {
		return \str_pad( $lead, 297, ' tide' ) . '...';
	}

	/** A description `issue` answers whole, led by $lead. */
	private static function whole( string $lead ): string {
		return \str_pad( $lead, 1200, ' tide table' );
	}

	/**
	 * One issue as either tool answers it.
	 *
	 * @param string $id          The identifier.
	 * @param string $updated     Its `updated_at`.
	 * @param string $description Its description.
	 * @return array<string,mixed>
	 */
	private static function issue( string $id, string $updated, string $description ): array {
		return [ 'identifier' => $id, 'title' => "Kestrel rollout {$id}", 'description' => $description, 'url' => "https://linear.app/heron/issue/{$id}", 'updated_at' => $updated ];
	}

	/**
	 * A team page of these issues.
	 *
	 * @param array<string,mixed> ...$issues The issues.
	 * @return array<string,mixed>
	 */
	private static function page( array ...$issues ): array {
		return [ 'groups' => [ 'In Progress' => $issues ], 'pageInfo' => [ 'hasNextPage' => false, 'endCursor' => '' ] ];
	}

	/**
	 * Open a pass and answer its one team page.
	 *
	 * @param array<string,mixed> ...$issues The page's issues.
	 */
	private function pass( Context_A8C_Linear_Node $node, array ...$issues ): void {
		$this->tick( $node );
		$this->initialized();
		$this->answer( self::page( ...$issues ) );
	}

	/** @return list<string> The id of each `issue` call, in order. */
	private function issue_calls(): array {
		return \array_column( \array_column( \array_values( \array_filter( $this->calls(), static fn ( array $call ): bool => 'issue' === $call['subtool'] ) ), 'subtool_args' ), 'id' );
	}

	/**
	 * Every event the node sent of a kind.
	 *
	 * @param string $kind The kind.
	 * @return list<array<string,mixed>>
	 */
	private function events( string $kind ): array {
		return \array_values( \array_filter( \array_column( $this->writer->captured, Message::VALUE ), static fn ( array $event ): bool => $kind === $event['event'] ) );
	}

	/** An issue that moved, cut short, waits for `issue` and records once, whole. */
	public function test_a_moved_issue_cut_short_records_whole(): void {
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-41', self::MOVED, self::cut( 'Heron rollout' ) ) );

		$this->assertSame( [ 'KESTREL-41' ], $this->issue_calls() );
		$this->assertSame( [], $this->events( 'item_recorded' ), 'nothing records before the whole issue answers' );

		$this->answer( self::issue( 'KESTREL-41', self::MOVED, self::whole( 'Heron rollout' ) ) );

		$this->assertSame( [ self::whole( 'Heron rollout' ) ], \array_column( $this->events( 'item_recorded' ), 'body' ) );
	}

	/** A description `team-issues` did not cut needs no call. */
	public function test_an_issue_not_cut_short_asks_nothing(): void {
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-42', self::MOVED, 'A short description ending in dots...' ) );

		$this->assertSame( [], $this->issue_calls() );
		$this->assertSame( [ 'A short description ending in dots...' ], \array_column( $this->events( 'item_recorded' ), 'body' ) );
	}

	/** An issue that has not moved records as the page answers it, and the walk reaches it later. */
	public function test_a_quiet_issue_records_at_once(): void {
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-43', self::QUIET, self::cut( 'Heron rollout' ) ) );

		$this->assertSame( [ self::cut( 'Heron rollout' ) ], \array_column( $this->events( 'item_recorded' ), 'body' ) );
		$this->assertSame( [], $this->issue_calls() );
	}

	/**
	 * An `issue` call that fails, or answers no description, leaves the
	 * issue its cut description, logged.
	 *
	 * @return array<string,array{0:string,1:int}>
	 */
	public static function failed_calls(): array {
		return [
			'an HTTP failure'   => [ 'bad gateway', 502 ],
			'a tool error'      => [ (string) \json_encode( [ 'jsonrpc' => '2.0', 'id' => 1, 'result' => [ 'isError' => true, 'content' => [ [ 'type' => 'text', 'text' => 'no such issue' ] ] ] ] ), 200 ],
			'no description'    => [ self::tool_answer( [ 'identifier' => 'KESTREL-44', 'title' => 'Kestrel' ] ), 200 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'failed_calls' )]
	public function test_a_failed_issue_call_keeps_the_cut_description( string $body, int $code ): void {
		$stderr = $this->capture_stderr();
		$node   = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-44', self::MOVED, self::cut( 'Heron rollout' ) ) );
		$this->complete( self::MCP, $body, $code );

		$this->assertSame( [ self::cut( 'Heron rollout' ) ], \array_column( $this->events( 'item_recorded' ), 'body' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'recording 1 issues as team-issues cut them' ) );
	}

	/**
	 * A stored issue grows: its row's own fields, its first retrieval among
	 * them, with the longer body, and nothing records again.
	 */
	public function test_a_stored_issue_grows_keeping_its_row(): void {
		$this->seed( self::item( 'linear:KESTREL-45', 'The title as first recorded', 'linear', 'internal', self::$oct_13, self::cut( 'Heron rollout' ) ) );
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-45', self::MOVED, self::cut( 'Heron rollout' ) ) );
		$this->answer( self::issue( 'KESTREL-45', self::MOVED, self::whole( 'Heron rollout' ) ) );

		$this->assertSame( [], $this->events( 'item_recorded' ) );
		$grown = $this->events( 'item_grown' );
		$this->assertCount( 1, $grown );
		$this->assertSame(
			[ 'linear:KESTREL-45', 'linear', 'internal', 'The title as first recorded', self::whole( 'Heron rollout' ), self::$oct_13, self::$oct_13 + 600 ],
			[ $grown[0]['item_key'], $grown[0]['source'], $grown[0]['tier'], $grown[0]['title'], $grown[0]['body'], $grown[0]['item_ts'], $grown[0]['retrieved_ts'] ]
		);
	}

	/** A stored body at least as long as what `issue` answers stays. */
	public function test_a_shorter_answer_never_grows_a_stored_issue(): void {
		$this->seed( self::item( 'linear:KESTREL-46', 'Kestrel rollout', 'linear', 'internal', self::$oct_13, self::whole( 'Heron rollout' ) ) );
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-46', self::MOVED, self::cut( 'Heron rollout' ) ) );
		$this->answer( self::issue( 'KESTREL-46', self::MOVED, \substr( self::whole( 'Heron rollout' ), 0, 900 ) ) );

		$this->assertSame( [ [], [] ], [ $this->events( 'item_recorded' ), $this->events( 'item_grown' ) ] );
	}

	/** A body the projection has not caught up with still bars a shorter one from the same process. */
	public function test_a_grown_body_bars_a_shorter_one_before_it_is_projected(): void {
		$this->seed( self::item( 'linear:KESTREL-47', 'Kestrel rollout', 'linear', 'internal', self::$oct_13, self::cut( 'Heron rollout' ) ) );
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node, self::issue( 'KESTREL-47', self::MOVED, self::cut( 'Heron rollout' ) ) );
		$this->answer( self::issue( 'KESTREL-47', self::MOVED, self::whole( 'Heron rollout' ) ) );
		$this->ended();
		$this->pass( $node, self::issue( 'KESTREL-47', self::MOVED, self::cut( 'Heron rollout' ) ) );
		$this->answer( self::issue( 'KESTREL-47', self::MOVED, \substr( self::whole( 'Heron rollout' ), 0, 900 ) ) );

		$this->assertCount( 1, $this->events( 'item_grown' ) );
	}

	/** A pass past the request cap's room records the rest as the page cut them, and hits no cap. */
	public function test_a_pass_keeps_its_calls_under_the_cap(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->linear( 'KESTREL' );
		$ids    = \array_map( static fn ( int $n ): string => 'KESTREL-' . ( 100 + $n ), \range( 1, 70 ) );

		$this->pass( $node, ...\array_map( static fn ( string $id ): array => self::issue( $id, self::MOVED, self::cut( 'Heron rollout' ) ), $ids ) );
		$asked = Source_Node::MAX_REQUESTS_PER_PASS - 4;
		$this->assertCount( 70 - $asked, $this->events( 'item_recorded' ), 'what the pass has no room for records at once' );
		for ( $n = 0; $n < $asked; $n++ ) {
			$this->answer( self::issue( $this->issue_calls()[ $n ], self::MOVED, self::whole( 'Heron rollout' ) ) );
		}

		$this->assertCount( $asked, $this->issue_calls() );
		$this->assertCount( 70, $this->events( 'item_recorded' ) );
		$this->assertSame( [], self::stderr_lines( $stderr, 'requests; sending no more' ) );
	}

	/**
	 * The team pages done, the pass walks the stored Linear items of the
	 * last 30 days still cut short, and no other.
	 */
	public function test_the_walk_reaches_issues_cut_short_in_the_last_thirty_days(): void {
		$day = static fn ( int $ago ): int => (int) Core::$now - $ago * 86400 - 600;
		$this->seed(
			self::item( 'linear:WREN-1', 'Cut short', 'linear', 'internal', $day( 29 ), self::cut( 'Heron rollout' ) ),
			self::item( 'linear:WREN-2', 'Too old', 'linear', 'internal', $day( 31 ), self::cut( 'Heron rollout' ) ),
			self::item( 'linear:WREN-3', 'Whole', 'linear', 'internal', $day( 2 ), 'A whole description.' ),
			self::item( 'feed:wren-4', 'Another source', 'feed', 'public', $day( 2 ), self::cut( 'Heron rollout' ) )
		);
		$node = $this->linear( 'KESTREL' );

		$this->pass( $node );

		$this->assertSame( [ 'WREN-1' ], $this->issue_calls() );

		$this->answer( self::issue( 'WREN-1', self::QUIET, self::whole( 'Heron rollout' ) ) );

		$this->assertSame( [ [ 'linear:WREN-1', self::whole( 'Heron rollout' ) ] ], \array_map( static fn ( array $e ): array => [ $e['item_key'], $e['body'] ], $this->events( 'item_grown' ) ) );
	}

	/** The walk takes what room a pass has and goes on where it stopped, the next pass, never asking again what it asked. */
	public function test_the_walk_pages_across_passes(): void {
		$rows = [];
		foreach ( \range( 1, 80 ) as $n ) {
			$rows[] = self::item( "linear:WREN-{$n}", 'Cut short', 'linear', 'internal', (int) Core::$now - 86400, self::cut( 'Heron rollout' ) );
		}
		$this->seed( ...$rows );
		$node = $this->linear( 'KESTREL' );
		$room = Source_Node::MAX_REQUESTS_PER_PASS - 4;

		$this->pass( $node );
		for ( $n = 0; $n < $room; $n++ ) {
			$this->complete( self::MCP, 'bad gateway', 502 );
		}
		$this->ended();
		$first = $this->issue_calls();
		$this->assertCount( $room, $first );

		$this->pass( $node );
		for ( $n = 0; $n < 80 - $room; $n++ ) {
			$this->complete( self::MCP, 'bad gateway', 502 );
		}

		$second = \array_slice( $this->issue_calls(), $room );
		$this->assertCount( 80 - $room, $second );
		$this->assertSame( [], \array_intersect( $first, $second ) );
	}
}

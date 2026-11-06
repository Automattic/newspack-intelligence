<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * Signals: per value of the trend axis, the latest complete bucket's count
 * is a spike when it reaches SIGNAL_MIN and the mean plus SIGNAL_SIGMAS
 * standard deviations of the SIGNAL_BASELINE buckets before it, and the
 * same value was counted before them, read from the same trend rows, tiers
 * and lens as `trends`.
 *
 * The fixture's tick is Tuesday 2026-10-20, so the current week opens on
 * Monday 2026-10-19, the latest complete one on 2026-10-12, and the eight
 * baseline weeks run back to 2026-08-17.
 */
final class IntelligenceSignalsTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	/** Monday 2026-10-19 00:00 UTC, when the current week opened. */
	private const THIS_WEEK = 1792368000;

	private const WEEK = 604800;

	private const HOLDER = 5521;

	private const READER = 5522;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::HOLDER, 'tidewater-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		self::add_user( self::READER, 'tidewater-reader', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_the_signal_rule_is_three_constants(): void {
		$this->assertSame( [ 3, 2, 8 ], [ Graph_Query::SIGNAL_MIN, Graph_Query::SIGNAL_SIGMAS, Graph_Query::SIGNAL_BASELINE ] );
		$this->assertSame( Capabilities::READ, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['signals'] );
	}

	/**
	 * A baseline of 1, 3, 1, 3, 1, 3, 1, 3 has mean 2 and deviation 1, so
	 * its threshold is 4: four items the latest week spike, and six cite
	 * their five newest.
	 */
	public function test_a_count_at_the_threshold_is_a_signal(): void {
		$this->weeks( 'complaint', [ 1 => 6, 2 => 1, 3 => 3, 4 => 1, 5 => 3, 6 => 1, 7 => 3, 8 => 1, 9 => 3, 10 => 1 ] );
		$this->weeks( 'praise', [ 1 => 4, 2 => 1, 3 => 3, 4 => 1, 5 => 3, 6 => 1, 7 => 3, 8 => 1, 9 => 3, 10 => 1 ] );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertTrue( $answer['available'] );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 6, 2.0 ], [ '2026-10-12', 'kind', 'praise', 4, 2.0 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'feed:complaint-1-6', 'feed:complaint-1-5', 'feed:complaint-1-4', 'feed:complaint-1-3', 'feed:complaint-1-2' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
		$this->assertSame( [ 'item_key' => 'feed:complaint-1-6', 'url' => 'https://news.example/feed/complaint-1-6', 'title' => 'complaint 6 of week 1' ], $answer['signals'][0]['items'][0] );
	}

	/** One short of the threshold is not a signal. */
	public function test_a_count_just_under_the_threshold_is_none(): void {
		$this->weeks( 'praise', [ 1 => 3, 2 => 1, 3 => 3, 4 => 1, 5 => 3, 6 => 1, 7 => 3, 8 => 1, 9 => 3, 10 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
	}

	/** A flat history never signals: the latest count must pass the baseline mean, and one above a flat baseline does. */
	public function test_a_flat_history_signals_nothing(): void {
		$this->weeks( 'hire', [ 1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3 ] );
		$this->weeks( 'release', [ 1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3 ] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'release', 4, 3.0 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
	}

	/** Over a quiet baseline a count still needs SIGNAL_MIN; the current, incomplete week never signals. */
	public function test_a_quiet_baseline_still_needs_the_minimum(): void {
		$this->weeks( 'launch', [ 0 => 9, 1 => 3, 10 => 1 ] );
		$this->weeks( 'hire', [ 1 => 2, 10 => 1 ] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'launch', 3, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
	}

	/**
	 * A value spikes only when it was counted before the oldest baseline
	 * week, because a first appearance within the window is not a change: a
	 * backfill of one item in the baseline and five in the latest week does
	 * not spike, nor does a value first counted in the oldest baseline week,
	 * while the same counts after an earlier one do; through a lens too.
	 */
	public function test_a_value_first_counted_inside_the_window_never_spikes(): void {
		$this->weeks( 'incident', [ 1 => 5, 2 => 1 ] );
		$this->weeks( 'praise', [ 1 => 5, 9 => 1 ] );
		$this->weeks( 'complaint', [ 1 => 5, 2 => 1, 20 => 1 ] );
		$this->ci->dispatch( 'save_lens', [ 'harbor-desk', 'Harbor desk', '--source=feed' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 5, 0.125 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
		$this->assertSame( [ 'complaint' ], \array_column( $this->ci->dispatch( 'signals', [ '--lens=harbor-desk' ] )['signals'], 'value' ) );
	}

	/** With no item of its value before the oldest baseline week, the history is short and nothing signals. */
	public function test_a_short_history_signals_nothing(): void {
		$this->weeks( 'complaint', [ 1 => 7, 8 => 1 ] );
		$this->weeks( 'research', [ 12 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
		$this->weeks( 'complaint', [ 12 => 1 ] );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 7, 0.125 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
	}

	/**
	 * On the kind axis a value's history is read across the whole retention,
	 * whatever days a lens keeps, so a 60-day lens spikes on a value counted
	 * long before them; a value with no such history does not.
	 */
	public function test_a_values_history_reaches_past_a_lens_days(): void {
		$this->weeks( 'complaint', [ 1 => 7, 20 => 1 ] );
		$this->weeks( 'incident', [ 1 => 6 ] );
		$this->ci->dispatch( 'save_lens', [ 'season-desk', 'Season desk', '--days=60' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 7, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 7, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=season-desk' ] ) ) );
	}

	/**
	 * On the topic axis a topic needs no history of its own: a new topic
	 * surging through a topic lens spikes once the lens holds any story
	 * before its oldest baseline week, read within the lens's own days, so
	 * the same lens kept to 30 days signals nothing.
	 */
	public function test_a_new_topic_surging_through_a_topic_lens_spikes(): void {
		$this->seed( self::entity( 'topic:squalls', 'Squalls' ), self::entity( 'topic:tides', 'Tides' ) );
		for ( $n = 1; $n <= 12; $n++ ) {
			$this->seed( ...self::counted( "feed:squall-{$n}", 'research', self::THIS_WEEK - self::WEEK + 2 * 86400 + $n * 60, 'public', 'feed', [ 'topic:squalls' ] ) );
		}
		$this->seed( ...self::counted( 'feed:tide-old', 'research', self::THIS_WEEK - 12 * self::WEEK, 'public', 'feed', [ 'topic:tides' ] ) );
		$this->ci->dispatch( 'save_lens', [ 'surge-desk', 'Surge desk', '--by=topic' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
		$this->ci->dispatch( 'save_lens', [ 'surge-month', 'Surge month', '--by=topic', '--days=30' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [ [ '2026-10-12', 'topic', 'topic:squalls', 12, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=surge-desk' ] ) ) );
		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--lens=surge-month' ] )['signals'] );
	}

	/**
	 * Through a lens, signals count by its axis and bucket over its filters,
	 * and its tiers and the caller's narrow them: a restricted spike reaches
	 * a holder and not a reader.
	 */
	public function test_a_lens_filters_and_tiers_shape_its_signals(): void {
		$this->weeks( 'complaint', [ 1 => 5, 10 => 1 ], 'restricted' );
		$this->weeks( 'praise', [ 1 => 4, 10 => 1 ], 'internal', 'linear' );
		$this->weeks( 'award', [ 1 => 8, 10 => 1 ], 'public', 'github' );
		$this->ci->dispatch( 'save_lens', [ 'tidewater-desk', 'Tidewater desk', '--source=feed', '--source=linear' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [ 'praise' ], \array_column( $this->ci->dispatch( 'signals', [ '--lens=tidewater-desk' ] )['signals'], 'value' ) );
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ 'complaint', 'praise' ], \array_column( $this->ci->dispatch( 'signals', [ '--lens=tidewater-desk' ] )['signals'], 'value' ) );
		$this->assertSame( [ 'award', 'complaint', 'praise' ], \array_column( $this->ci->dispatch( 'signals', [] )['signals'], 'value' ) );
		$this->assertSame( [ 'complaint' ], \array_column( $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] )['signals'], 'value' ) );
	}

	/** A lens counting topics by day signals per topic, its latest complete day yesterday. */
	public function test_a_lens_axis_and_bucket_set_what_a_signal_counts(): void {
		$day = 86400;
		$this->seed( self::entity( 'topic:storms', 'Storms' ) );
		for ( $n = 0; $n < 5; $n++ ) {
			$this->seed( ...self::counted( "feed:storm-{$n}", 'research', self::THIS_WEEK + 6 * 3600 + $n, 'public', 'feed', [ 'topic:storms' ] ) );
		}
		$this->seed( ...self::counted( 'feed:storm-old', 'research', self::THIS_WEEK - 12 * $day, 'public', 'feed', [ 'topic:storms' ] ) );
		$this->ci->dispatch( 'save_lens', [ 'storm-desk', 'Storm desk', '--by=topic', '--bucket=day' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [ [ '2026-10-19', 'topic', 'topic:storms', 5, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=storm-desk' ] ) ) );
	}

	/** `other` is the kind that says nothing, so it never spikes on the about axis. */
	public function test_other_never_signals(): void {
		$this->weeks( 'other', [ 1 => 9, 9 => 1, 10 => 1 ] );
		$this->weeks( 'incident', [ 1 => 3, 9 => 1, 10 => 1 ] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'incident', 3, 0.125 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
		$this->assertSame( [ 'incident' ], \array_column( Intel_Graph_Row_Schema::read( static fn ( $r ): array => Graph_Query::spikes( $r, Graph_Query::graph( $r ), [], 0.0 ) ), 'value' ) );
	}

	/**
	 * `other` counts nowhere on the about axis, so it is not history either;
	 * trends still count it.
	 */
	public function test_other_is_no_history(): void {
		$this->weeks( 'incident', [ 1 => 3 ] );
		$this->weeks( 'other', [ 9 => 1, 20 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
		$this->assertContains( 'other', \array_column( $this->ci->dispatch( 'trends', [] )['rows'], 'value' ) );
		$this->weeks( 'incident', [ 20 => 1 ] );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'incident', 3, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [] ) ) );
	}

	/**
	 * A story is dated by its earliest copy, so one whose early copy precedes
	 * the window is history and leaves the window, though its canonical
	 * falls inside it.
	 */
	public function test_a_story_with_an_early_copy_is_history(): void {
		$this->weeks( 'incident', [ 1 => 4 ] );
		$early = self::THIS_WEEK - 10 * self::WEEK + 2 * 86400;
		$this->seed( ...self::counted( 'feed:incident-early', 'incident', $early, 'internal', 'feed', [] ) );
		$this->seed( self::story( 'feed:incident-1-1', [ 'feed:incident-early', 'feed:incident-1-1' ], $early ) );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'incident', 3, 0.0 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'feed:incident-1-4', 'feed:incident-1-3', 'feed:incident-1-2' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
	}

	/**
	 * Seven syndicated copies of one story count once, as their cluster's
	 * canonical, which the signal cites, in `signals` and in `spikes()`.
	 */
	public function test_a_story_counts_once_as_its_canonical(): void {
		$this->weeks( 'incident', [ 1 => 8, 9 => 1, 10 => 1 ] );
		$copies = \array_map( static fn ( int $n ): string => "feed:incident-1-{$n}", \range( 1, 7 ) );
		$this->seed( self::story( 'feed:incident-1-4', $copies, self::THIS_WEEK - self::WEEK + 2 * 86400 ) );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
		$this->weeks( 'incident', [ 1 => 9 ] );
		$answer = $this->ci->dispatch( 'signals', [] );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'incident', 3, 0.125 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'feed:incident-1-9', 'feed:incident-1-8', 'feed:incident-1-4' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
		$this->assertSame( [ 'item_key' => 'feed:incident-1-4', 'url' => 'https://news.example/feed/incident-1-4', 'title' => 'incident 4 of week 1' ], $answer['signals'][0]['items'][2] );
		$this->assertSame( [ 3 ], \array_column( Intel_Graph_Row_Schema::read( static fn ( $r ): array => Graph_Query::spikes( $r, Graph_Query::graph( $r ), [], 0.0 ) ), 'count' ) );
	}

	/**
	 * A copy whose canonical the caller may not read counts as itself and
	 * cites itself; a holder counts the story once, by its canonical.
	 */
	public function test_a_restricted_canonical_never_stands_for_its_copies(): void {
		$this->weeks( 'incident', [ 1 => 3, 9 => 1, 10 => 1 ] );
		$this->weeks( 'incident', [ 1 => 1 ], 'restricted', 'unthread' );
		$this->seed( self::story( 'unthread:incident-1-1', [ 'unthread:incident-1-1', 'feed:incident-1-2', 'feed:incident-1-3' ], self::THIS_WEEK - self::WEEK + 2 * 86400 ) );

		$this->assertSame( [ 'feed:incident-1-3', 'feed:incident-1-2', 'feed:incident-1-1' ], \array_column( $this->ci->dispatch( 'signals', [] )['signals'][0]['items'], 'item_key' ) );
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
		$this->assertSame( [ [ 'unthread:incident-1-1', 'feed:incident-1-1' ] ], \array_map( static fn ( array $row ): array => \array_column( $row['items'], 'item_key' ), \array_values( \array_filter( $this->ci->dispatch( 'trends', [ '--since=2026-10-12', '--until=2026-10-18' ] )['rows'], static fn ( array $row ): bool => 'incident' === $row['value'] ) ) ) );
	}

	/** With no projection to read, signals answer unavailable. */
	public function test_no_table_answers_no_signal_unavailable(): void {
		$this->activate();
		$this->capture_stderr();

		$this->assertSame( [ 'signals' => [], 'available' => false ], Graph_Query::signals( [] ) );
	}

	/**
	 * Seed items of one kind per week back from the current one.
	 *
	 * @param string          $kind   The kind every item is extracted as.
	 * @param array<int,int>  $counts Weeks back => how many items.
	 * @param string          $tier   Their tier.
	 * @param string          $source Their source.
	 */
	private function weeks( string $kind, array $counts, string $tier = 'internal', string $source = 'feed' ): void {
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$this->seed( ...self::counted( "{$source}:{$kind}-{$back}-{$n}", $kind, self::THIS_WEEK - $back * self::WEEK + 2 * 86400 + $n * 60, $tier, $source, [], "{$kind} {$n} of week {$back}" ) );
			}
		}
	}

	/**
	 * One item, its extraction and, for a restricted one, its link to the
	 * Tidewater Ledger.
	 *
	 * @param string       $key    The item key.
	 * @param string       $kind   Its kind.
	 * @param int          $at     Its date.
	 * @param string       $tier   Its tier.
	 * @param string       $source Its source.
	 * @param list<string> $topics Its topics.
	 * @param string       $title  Its title.
	 * @return list<list<array<string,mixed>>>
	 */
	private static function counted( string $key, string $kind, int $at, string $tier, string $source, array $topics, string $title = '' ): array {
		$events = [
			[ \Newspack_Intelligence\Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => $source, 'tier' => $tier, 'url' => self::url( $key ), 'title' => '' === $title ? $key : $title, 'body' => '', 'item_ts' => $at, 'retrieved_ts' => $at + 600 ], self::$machine, 'fixture-9' ) ],
			self::extraction( $key, "A {$kind}.", $kind, $at, $topics ),
		];
		if ( 'restricted' === $tier ) {
			$events[] = self::link( 'link_asserted', $key, 'organization:tidewater-ledger', 'subject', 'name', 1.0, $at );
		}
		return $events;
	}

	/**
	 * Each signal as its bucket, axis, value, count and baseline.
	 *
	 * @param array<string,mixed> $answer A signals answer.
	 * @return list<list<mixed>>
	 */
	private static function signals( array $answer ): array {
		return \array_map( static fn ( array $s ): array => [ $s['bucket'], $s['by'], $s['value'], $s['count'], $s['baseline'] ], $answer['signals'] );
	}
}

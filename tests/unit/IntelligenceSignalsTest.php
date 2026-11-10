<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

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
	use Counts_Statements;

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

	/** An item a curator excluded counts toward no signal: four praise items less one excluded is three, under the threshold. */
	public function test_an_excluded_item_counts_toward_no_signal(): void {
		$this->weeks( 'praise', [ 1 => 4, 2 => 1, 3 => 3, 4 => 1, 5 => 3, 6 => 1, 7 => 3, 8 => 1, 9 => 3, 10 => 1 ] );
		$this->assertCount( 1, $this->ci->dispatch( 'signals', [] )['signals'] );

		$this->seed( [ Intel_Event::make( 'item_excluded', [ 'item_key' => 'feed:praise-1-4', 'url' => self::url( 'feed:praise-1-4' ), 'title' => 'praise 4 of week 1', 'tier' => 'internal' ], 'user:tidewater-reader', 'fixture-9' ) ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
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
	 * before its oldest baseline week, read across the whole retention, so
	 * the same lens kept to 30 days spikes alike.
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
		$this->assertSame( [ [ '2026-10-12', 'topic', 'topic:squalls', 12, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=surge-month' ] ) ) );
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

	/**
	 * Beside its spikes a read answers its trend: the stories the spikes
	 * count in each of the SIGNAL_TREND latest complete buckets, the latest
	 * first, the current bucket left out, `other` muted as the spikes mute
	 * it, a restricted story counted for a holder and never for a reader.
	 */
	public function test_signals_answer_the_trend_beside_the_spikes(): void {
		$this->weeks( 'complaint', [ 0 => 4, 1 => 6, 2 => 1, 3 => 3, 4 => 1, 5 => 3, 6 => 1, 7 => 3, 8 => 1, 9 => 3, 10 => 1 ] );
		$this->weeks( 'incident', [ 1 => 2, 4 => 5 ], 'restricted' );
		$this->weeks( 'other', [ 2 => 5, 6 => 2 ] );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ 'complaint' ], \array_column( $answer['signals'], 'value' ) );
		$this->assertSame( self::trend( [ 6, 1, 3, 1, 3, 1, 3, 1 ] ), $answer['trend'] );
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( self::trend( [ 8, 1, 3, 6, 3, 1, 3, 1 ] ), $this->ci->dispatch( 'signals', [] )['trend'] );
	}

	/**
	 * A lens that does not spike still answers its trend, counted by its
	 * bucket over its filters: by week, and by day back from yesterday.
	 */
	public function test_a_quiet_lens_answers_its_trend(): void {
		$this->weeks( 'praise', [ 1 => 2, 3 => 4 ], 'internal', 'linear' );
		$this->weeks( 'complaint', [ 1 => 5, 2 => 7 ] );
		$this->ci->dispatch( 'save_lens', [ 'channel-desk', 'Channel desk', '--source=linear' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
		$this->ci->dispatch( 'save_lens', [ 'channel-days', 'Channel days', '--source=linear', '--bucket=day' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$weekly = $this->ci->dispatch( 'signals', [ '--lens=channel-desk' ] );

		$this->assertSame( [], $weekly['signals'] );
		$this->assertSame( self::trend( [ 2, 0, 4, 0, 0, 0, 0, 0 ] ), $weekly['trend'] );
		$days = \array_map( static fn ( int $back ): string => \gmdate( 'Y-m-d', self::THIS_WEEK - $back * 86400 ), \range( 0, 7 ) );
		$daily = $this->ci->dispatch( 'signals', [ '--lens=channel-days' ] );
		$this->assertSame( \array_map( static fn ( string $day, int $count ): array => [ 'bucket' => $day, 'count' => $count, 'in_window' => true ], $days, [ 0, 0, 0, 0, 0, 2, 0, 0 ] ), $daily['trend'] );
		$this->assertSame( 'day', $daily['bucket'] );
		$this->save( 'channel-months', '--source=linear', '--bucket=month' );
		$this->assertSame( 'month', $this->ci->dispatch( 'signals', [ '--lens=channel-months' ] )['bucket'] );
	}

	/**
	 * An entity's trend counts the items naming it as their subject by
	 * week, `other` muted as its about spikes mute it, a restricted story for
	 * a holder and never for a reader.
	 */
	public function test_an_entitys_trend_counts_what_is_about_it(): void {
		$this->weeks( 'complaint', [ 1 => 3, 5 => 2 ], 'restricted' );
		for ( $n = 1; $n <= 4; $n++ ) {
			$at = self::THIS_WEEK - 2 * self::WEEK + 3 * 86400 + $n * 60;
			$this->seed( ...[ ...self::counted( "feed:ledger-note-{$n}", 'research', $at, 'internal', 'feed', [] ), self::link( 'link_asserted', "feed:ledger-note-{$n}", 'organization:tidewater-ledger', 'subject', 'name', 1.0, $at ) ] );
		}
		$this->weeks( 'praise', [ 1 => 6 ] );
		$at = self::THIS_WEEK - 3 * self::WEEK + 86400;
		$this->seed( ...[ ...self::counted( 'feed:ledger-aside', 'other', $at, 'internal', 'feed', [] ), self::link( 'link_asserted', 'feed:ledger-aside', 'organization:tidewater-ledger', 'subject', 'name', 1.0, $at ) ] );

		$this->assertSame( self::trend( [ 0, 4, 0, 0, 0, 0, 0, 0 ] ), $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] )['trend'] );
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( self::trend( [ 3, 4, 0, 0, 2, 0, 0, 0 ] ), $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] )['trend'] );
	}

	/**
	 * An entity's about signals and its trend rest on the items naming it as
	 * their subject: a burst of complaints that only mention the Tidewater
	 * Ledger spikes nothing and leaves its trend flat, and the same burst as
	 * its subject spikes.
	 */
	public function test_an_entitys_about_signals_rest_on_its_subject_links(): void {
		$this->about_ledger( 'complaint', 'subject', [ 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1 ] );
		$this->about_ledger( 'complaint', 'mention', [ 1 => 5 ] );
		$read = fn (): array => $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] );

		$this->assertSame( [], $read()['signals'] );
		$this->assertSame( self::trend( [ 0, 1, 1, 1, 1, 1, 1, 1 ] ), $read()['trend'] );

		$this->about_ledger( 'complaint', 'subject', [ 1 => 5 ] );

		$this->assertSame( [ [ \gmdate( 'Y-m-d', self::THIS_WEEK - self::WEEK ), 'kind', 'complaint', 5, 1.0 ] ], self::signals( $read() ) );
	}

	/**
	 * A topic lens's trend counts only stories tagged with a topic, as its
	 * spikes count them.
	 */
	public function test_a_topic_lens_trend_counts_tagged_stories(): void {
		$this->seed( self::entity( 'topic:currents', 'Currents' ) );
		for ( $n = 1; $n <= 3; $n++ ) {
			$this->seed( ...self::counted( "linear:current-{$n}", 'research', self::THIS_WEEK - 2 * self::WEEK + 86400 + $n, 'internal', 'linear', [ 'topic:currents' ] ) );
		}
		$this->weeks( 'research', [ 1 => 4 ], 'internal', 'linear' );
		$this->ci->dispatch( 'save_lens', [ 'current-desk', 'Current desk', '--by=topic', '--source=linear' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( self::trend( [ 0, 3, 0, 0, 0, 0, 0, 0 ] ), $this->ci->dispatch( 'signals', [ '--lens=current-desk' ] )['trend'] );
	}

	/**
	 * The trend rides the statement the spikes run, so a signals read costs
	 * no statement over the spikes alone, through a lens on the kind axis
	 * keeping fewer days, on the theme axis, and for an entity.
	 */
	public function test_the_trend_costs_no_statement_over_the_spikes(): void {
		$this->weeks( 'complaint', [ 1 => 6, 3 => 2, 10 => 1 ] );
		$this->ci->dispatch( 'save_lens', [ 'ledger-desk', 'Ledger desk', '--days=90' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
		$this->ci->dispatch( 'save_lens', [ 'motif-desk', 'Motif desk', '--by=theme' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		foreach ( [ [ 'lens' => 'ledger-desk' ], [ 'lens' => 'motif-desk' ], [ 'entity' => 'organization:tidewater-ledger' ] ] as $args ) {
			$spikes = $this->statements_of( static fn (): mixed => Intel_Graph_Row_Schema::read( static fn ( $r ): array => Graph_Query::spikes( $r, Graph_Query::graph( $r ), $args, 0.0 ) ) );
			$this->assertSame( $spikes, $this->statements_of( static fn (): array => Graph_Query::signals( $args ) ), (string) \current( $args ) );
		}
	}

	/** With no projection to read, signals answer unavailable. */
	public function test_no_table_answers_no_signal_unavailable(): void {
		$this->activate();
		$this->capture_stderr();

		$this->assertSame(
			[
				'signals'          => [],
				'trend'            => [],
				'partial'          => null,
				'judged'           => false,
				'bucket'           => null,
				'baseline_buckets' => 8,
				'available'        => false,
			],
			Graph_Query::signals( [] )
		);
	}

	/**
	 * A 30-day weekly view whose stories all fall inside its days holds no
	 * story before its oldest baseline week, so it is too new to judge.
	 */
	public function test_a_thirty_day_weekly_view_with_no_older_items_is_not_judged(): void {
		$this->weeks( 'complaint', [ 1 => 4, 2 => 1, 3 => 2 ] );
		$this->save( 'month-desk', '--days=30' );
		$this->save( 'month-topics', '--days=30', '--by=topic' );

		foreach ( [ 'month-desk', 'month-topics' ] as $lens ) {
			$answer = $this->ci->dispatch( 'signals', [ "--lens={$lens}" ] );
			$this->assertSame( [ false, [] ], [ $answer['judged'], $answer['signals'] ], $lens );
		}
	}

	/**
	 * Ten flat weeks of three are enough history to judge, and nothing
	 * changed; an entity nothing is about holds no history to judge by.
	 */
	public function test_ten_flat_weeks_are_judged_without_a_signal(): void {
		$this->weeks( 'hire', [ 1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3, 10 => 3 ] );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ true, [], 'week', 8 ], [ $answer['judged'], $answer['signals'], $answer['bucket'], $answer['baseline_buckets'] ] );
		$this->assertArrayNotHasKey( 'usual', $answer );
		$this->assertFalse( $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] )['judged'] );
	}

	/**
	 * Off the kind axis a story in the oldest baseline week is history
	 * enough to judge, and a lens holding none from that week back is not.
	 */
	public function test_a_story_in_the_oldest_baseline_week_judges_a_topic_view(): void {
		$this->seed( self::entity( 'topic:eddies', 'Eddies' ) );
		$this->seed( ...self::counted( 'feed:eddy-late', 'research', self::THIS_WEEK - 8 * self::WEEK + 86400, 'public', 'feed', [ 'topic:eddies' ] ) );
		$this->save( 'eddy-desk', '--by=topic' );

		$this->assertFalse( $this->ci->dispatch( 'signals', [ '--lens=eddy-desk' ] )['judged'] );
		$this->seed( ...self::counted( 'feed:eddy-early', 'research', self::THIS_WEEK - 9 * self::WEEK + 86400, 'public', 'feed', [ 'topic:eddies' ] ) );
		$this->assertTrue( $this->ci->dispatch( 'signals', [ '--lens=eddy-desk' ] )['judged'] );
	}

	/**
	 * A bucket a view's days never reach is out of its window, not a zero:
	 * a 30-day view, opening on Monday 2026-09-21, reads four whole weeks,
	 * and a 31-day one, opening a day earlier, reaches into a fifth.
	 */
	public function test_buckets_before_the_window_are_out_of_window(): void {
		$this->weeks( 'complaint', [ 1 => 2, 6 => 5 ] );
		$this->save( 'month-desk', '--days=30' );
		$this->save( 'long-month-desk', '--days=31' );

		$this->assertSame( [ true, true, true, true, false, false, false, false ], \array_column( $this->ci->dispatch( 'signals', [ '--lens=month-desk' ] )['trend'], 'in_window' ) );
		$this->assertSame( [ true, true, true, true, true, false, false, false ], \array_column( $this->ci->dispatch( 'signals', [ '--lens=long-month-desk' ] )['trend'], 'in_window' ) );
		$this->assertSame( \array_fill( 0, 8, true ), \array_column( $this->ci->dispatch( 'signals', [] )['trend'], 'in_window' ) );
	}

	/**
	 * A signal's own series is out of its window before the view's first
	 * day: a 30-day view counting kinds spikes on a kind counted long before
	 * it, and its series breaks before 2026-09-21.
	 */
	public function test_a_signals_series_is_out_of_window_before_the_views_first_day(): void {
		$this->weeks( 'complaint', [ 1 => 5, 2 => 1, 20 => 1 ] );
		$this->save( 'month-desk', '--days=30' );

		$signal = $this->ci->dispatch( 'signals', [ '--lens=month-desk' ] )['signals'][0];

		$this->assertSame( [ 5, 1, 0, 0, 0, 0, 0, 0 ], \array_column( $signal['trend'], 'count' ) );
		$this->assertSame( [ true, true, true, true, false, false, false, false ], \array_column( $signal['trend'], 'in_window' ) );
	}

	/**
	 * A subject whose every story falls before the window holds the history
	 * to judge by and nothing in the window to signal.
	 */
	public function test_stories_only_before_the_window_are_judged_without_a_signal(): void {
		$this->weeks( 'hire', [ 12 => 2 ] );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ true, [] ], [ $answer['judged'], $answer['signals'] ] );
		$this->assertSame( \array_fill( 0, 8, 0 ), \array_column( $answer['trend'], 'count' ) );
	}

	/** The current, incomplete week answers apart from the trend: eleven stories so far, not on any point of the line. */
	public function test_the_current_bucket_answers_apart(): void {
		$this->weeks( 'launch', [ 0 => 11, 1 => 2, 3 => 1 ] );

		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ 'bucket' => '2026-10-19', 'count' => 11 ], $answer['partial'] );
		$this->assertNotContains( 11, \array_column( $answer['trend'], 'count' ) );
		$this->assertSame( [ 'bucket' => '2026-10-19', 'count' => 0 ], $this->ci->dispatch( 'signals', [ '--entity=organization:tidewater-ledger' ] )['partial'] );
	}

	/**
	 * A signal's usual range is its own value's series, never the totals: a
	 * praise baseline of 2, 3, 2, 3, 2, 3, 2, 3 beside a complaint one has
	 * mean 2.5 and deviation 0.5, so it runs from 1.5 to 3.5, and four
	 * praise items the latest week spike while the answer carries no range
	 * of its own; three praise items do not spike.
	 */
	public function test_usual_spans_the_spike_threshold(): void {
		$this->weeks( 'praise', [ 1 => 3, 2 => 2, 3 => 3, 4 => 2, 5 => 3, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 1 ] );
		$this->weeks( 'complaint', [ 2 => 1, 4 => 5, 6 => 1, 10 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [] )['signals'] );
		$this->seed( ...self::counted( 'feed:praise-1-extra', 'praise', self::THIS_WEEK - self::WEEK + 3 * 86400, 'internal', 'feed', [] ) );
		$answer = $this->ci->dispatch( 'signals', [] );

		$this->assertSame( [ [ '2026-10-12', 'kind', 'praise', 4, 2.5 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'mean' => 2.5, 'low' => 1.5, 'high' => 3.5 ], $answer['signals'][0]['usual'] );
		$this->assertArrayNotHasKey( 'baseline', $answer['signals'][0] );
		$this->assertSame( [ 4, 2, 3, 2, 3, 2, 3, 2 ], \array_column( $answer['signals'][0]['trend'], 'count' ) );
		$this->assertSame( [ 4, 3, 3, 7, 3, 3, 3, 2 ], \array_column( $answer['trend'], 'count' ) );
		$this->assertArrayNotHasKey( 'usual', $answer );
	}

	/**
	 * A view's baseline reads across the retention, past its own days: a
	 * 30-day weekly view counting themes, its baseline weeks 2, 3, 2, 3, 2,
	 * 3, 2, 3 running back before its first day, signals nine stories last
	 * week against a mean of 2.5, as the same view with no days does.
	 */
	public function test_a_thirty_day_view_signals_against_older_history(): void {
		$this->themed( 'ferry closures', [ 1 => 9, 2 => 2, 3 => 3, 4 => 2, 5 => 3, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 2 ] );
		$this->save( 'crossing-month', '--days=30', '--by=theme' );
		$this->save( 'crossing-ever', '--by=theme' );

		$month = $this->ci->dispatch( 'signals', [ '--lens=crossing-month' ] );

		$this->assertSame( [ [ '2026-10-12', 'theme', 'ferry closures', 9, 2.5 ] ], self::signals( $month ) );
		$this->assertTrue( $month['judged'] );
		$this->assertSame( self::signals( $this->ci->dispatch( 'signals', [ '--lens=crossing-ever' ] ) ), self::signals( $month ) );
	}

	/**
	 * Look back still bounds what the view lists and charts: the 30-day view
	 * that signals on older history searches, trends and cites no story
	 * before its first day, 2026-09-21, and its pulse marks the weeks before
	 * that day out of its window.
	 */
	public function test_look_back_still_bounds_the_listed_items(): void {
		$this->themed( 'ferry closures', [ 1 => 9, 2 => 2, 3 => 3, 4 => 2, 5 => 3, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 2 ] );
		$this->save( 'crossing-month', '--days=30', '--by=theme' );
		$first = self::THIS_WEEK - 4 * self::WEEK;

		$listed  = \array_column( $this->ci->dispatch( 'search', [ '--lens=crossing-month', '--limit=200' ] )['items'], 'item_ts' );
		$charted = \array_column( $this->ci->dispatch( 'trends', [ '--lens=crossing-month' ] )['rows'], 'bucket' );
		$signals = $this->ci->dispatch( 'signals', [ '--lens=crossing-month' ] );

		$this->assertSame( 9 + 2 + 3 + 2, \count( $listed ) );
		$this->assertGreaterThanOrEqual( $first, \min( $listed ) );
		$this->assertSame( [ '2026-10-12', '2026-10-05', '2026-09-28', '2026-09-21' ], \array_values( \array_unique( $charted ) ) );
		$this->assertCount( Graph_Query::CITATIONS, $signals['signals'][0]['items'] );
		foreach ( $signals['signals'][0]['items'] as $item ) {
			$this->assertStringEndsWith( ' of week 1', $item['title'] );
		}
		$this->assertSame( [ true, true, true, true, false, false, false, false ], \array_column( $signals['trend'], 'in_window' ) );
		$this->assertSame( [ true, true, true, true, false, false, false, false ], \array_column( $signals['signals'][0]['trend'], 'in_window' ) );
	}

	/**
	 * A view whose filters match no item is not judged, however much history
	 * the graph holds outside it, and draws no zero it did not read nor any
	 * usual range.
	 */
	public function test_a_view_matching_nothing_is_not_judged(): void {
		$this->themed( 'ferry closures', [ 1 => 9, 2 => 2, 3 => 3, 4 => 2, 5 => 3, 6 => 2, 7 => 3, 8 => 2, 9 => 3, 10 => 2 ] );
		$this->save( 'empty-month', '--days=30', '--by=theme', '--source=github' );

		$answer = $this->ci->dispatch( 'signals', [ '--lens=empty-month' ] );

		$this->assertSame( [ false, [] ], [ $answer['judged'], $answer['signals'] ] );
		$this->assertSame( [ true, true, true, true, false, false, false, false ], \array_column( $answer['trend'], 'in_window' ) );
		$this->assertSame( \array_fill( 0, Graph_Query::SIGNAL_TREND, 0 ), \array_column( $answer['trend'], 'count' ) );
		$this->assertArrayNotHasKey( 'usual', $answer );
	}

	/**
	 * A view signals only a bucket its look back overlaps: a day lens
	 * looking back one day never signals yesterday, though it is judged,
	 * and looking back two days it does.
	 */
	public function test_a_day_view_looking_back_one_day_signals_nothing(): void {
		$day = 86400;
		$this->seed( self::entity( 'topic:gales', 'Gales' ) );
		for ( $n = 0; $n < 6; $n++ ) {
			$this->seed( ...self::counted( "feed:gale-{$n}", 'research', self::THIS_WEEK + 7 * 3600 + $n, 'public', 'feed', [ 'topic:gales' ] ) );
		}
		$this->seed( ...self::counted( 'feed:gale-old', 'research', self::THIS_WEEK - 15 * $day, 'public', 'feed', [ 'topic:gales' ] ) );
		$this->save( 'gale-today', '--by=topic', '--bucket=day', '--days=1' );
		$this->save( 'gale-two-days', '--by=topic', '--bucket=day', '--days=2' );

		$today = $this->ci->dispatch( 'signals', [ '--lens=gale-today' ] );

		$this->assertSame( [ true, [] ], [ $today['judged'], $today['signals'] ] );
		$this->assertFalse( $today['trend'][0]['in_window'] );
		$this->assertSame( [ [ '2026-10-19', 'topic', 'topic:gales', 6, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=gale-two-days' ] ) ) );
	}

	/**
	 * On Sunday 2026-10-25 a week lens looking back seven days opens on
	 * Monday 2026-10-19, after the latest complete week closed, so it
	 * signals nothing that week; looking back eight days it does.
	 */
	public function test_a_week_view_looking_back_seven_days_on_a_sunday_signals_nothing(): void {
		$this->weeks( 'complaint', [ 1 => 6, 20 => 1 ] );
		$this->save( 'squall-week', '--days=7' );
		$this->save( 'squall-eight', '--days=8' );
		Core::$now = (float) ( self::THIS_WEEK + 6 * 86400 + 12 * 3600 );

		$week = $this->ci->dispatch( 'signals', [ '--lens=squall-week' ] );

		$this->assertSame( [ true, [], '2026-10-19' ], [ $week['judged'], $week['signals'], $week['partial']['bucket'] ] );
		$this->assertFalse( $week['trend'][0]['in_window'] );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 6, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=squall-eight' ] ) ) );
	}

	/**
	 * A signal counts its whole bucket but cites only the stories its view's
	 * look back reaches: a lens looking back three days, from Sunday
	 * 2026-10-18, counts seven complaints last week and cites the two from
	 * that Sunday.
	 */
	public function test_a_signal_cites_only_what_its_look_back_lists(): void {
		$this->weeks( 'complaint', [ 1 => 5, 20 => 1 ] );
		for ( $n = 1; $n <= 2; $n++ ) {
			$this->seed( ...self::counted( "feed:complaint-sunday-{$n}", 'complaint', self::THIS_WEEK - 86400 + $n * 60, 'internal', 'feed', [] ) );
		}
		$this->save( 'squall-three', '--days=3' );

		$signal = $this->ci->dispatch( 'signals', [ '--lens=squall-three' ] )['signals'][0];

		$this->assertSame( [ 'complaint', 7 ], [ $signal['value'], $signal['count'] ] );
		$this->assertSame( [ 'feed:complaint-sunday-2', 'feed:complaint-sunday-1' ], \array_column( $signal['items'], 'item_key' ) );
		$this->assertSame( 7, $signal['trend'][0]['count'] );
	}

	/**
	 * A view filtering by role reads its history before the window as one
	 * existence test per subject, on each item's links, judgments and roles
	 * by their keys: the statement builds `live` over the window's items
	 * alone, and scans neither links nor judgments.
	 */
	public function test_the_history_reads_links_by_their_keys(): void {
		$this->about_ledger( 'complaint', 'subject', [ 1 => 5, 20 => 1 ] );
		$this->seed( [ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:tidewater-ledger', 'role' => 'client', 'from_ts' => self::THIS_WEEK - 30 * self::WEEK ], 'user:41', 'fixture-9' ) ] );
		$this->save( 'ledger-clients', '--days=30', '--role=client' );

		$queries = $this->queries_of( fn () => $this->ci->dispatch( 'signals', [ '--lens=ledger-clients' ] ) );

		$spikes = \array_values( \array_filter( $queries, static fn ( string $sql ): bool => \str_contains( $sql, 'ranked AS' ) ) );
		$this->assertCount( 1, $spikes );
		$this->assertMatchesRegularExpression( '/ pc AS \( (?:(?! live\b).)*\), wh AS/s', $spikes[0] );
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
		$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $spikes[0] )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
		$this->assertMatchesRegularExpression( '/SEARCH (p0\.judgments|\w+) USING (COVERING )?INDEX sqlite_autoindex_judgments_1 \(item_key=\?\)/', $plan );
		$this->assertDoesNotMatchRegularExpression( '/SCAN (p0\.)?(links|judgments)\b/', $plan );
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 5, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=ledger-clients' ] ) ) );
	}

	/**
	 * A view filtering by role reads the role rows once: each statement of
	 * the signals and of a search naming roles builds `rl` from one scan of
	 * `roles`, and every window item and every earlier link finds its
	 * entity's roles by entity key, however many roles it names, never by
	 * scanning `roles` again or probing every role row's links.
	 */
	public function test_a_role_filter_reads_the_roles_once_and_finds_them_by_entity_key(): void {
		$this->about_ledger( 'complaint', 'subject', [ 1 => 5, 20 => 1 ] );
		$this->seed( [ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:tidewater-ledger', 'role' => 'partner', 'from_ts' => self::THIS_WEEK - 30 * self::WEEK ], 'user:41', 'fixture-9' ) ] );
		$this->save( 'ledger-partners', '--days=30', '--role=partner', '--role=funder', '--kind=complaint' );
		$reads = [
			'signals' => fn () => $this->ci->dispatch( 'signals', [ '--lens=ledger-partners' ] ),
			'search'  => fn () => $this->ci->dispatch( 'search', [ '--role=partner', '--role=funder', '--sort=time' ] ),
			'one'     => fn () => $this->ci->dispatch( 'search', [ '--role=partner', '--sort=time' ] ),
		];
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );

		foreach ( $reads as $name => $read ) {
			$roled = \array_values( \array_filter( $this->queries_of( $read ), static fn ( string $sql ): bool => \str_contains( $sql, ' rl AS ' ) ) );
			$this->assertNotSame( [], $roled, $name );
			foreach ( $roled as $sql ) {
				$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $sql )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
				$this->assertSame( 1, \preg_match_all( '/ p0\.roles\b/', $plan ), $name );
				$this->assertMatchesRegularExpression( '/SEARCH rl USING /', $plan, $name );
				$this->assertDoesNotMatchRegularExpression( '/(SCAN rl|SEARCH rl USING (?![^\n]*entity_key=\?))/', $plan, $name );
			}
		}
		$this->assertSame( [ [ '2026-10-12', 'kind', 'complaint', 5, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--lens=ledger-partners' ] ) ) );
	}

	/**
	 * No statement a team view's signals, the briefing or a search read
	 * scans items or extractions: the signals and the briefing search item
	 * time through `items_item_time`, and the signals and a search by kind
	 * search `extractions_kind`.
	 */
	public function test_the_reads_search_item_time_and_kind_by_their_indexes(): void {
		$this->themed( 'ferry closures', [ 1 => 9, 2 => 2, 10 => 2 ] );
		$this->save( 'crossing-clients', '--days=30', '--by=theme', '--kind=research', '--kind=complaint', '--role=client' );
		$reads = [
			'signals'  => fn () => $this->ci->dispatch( 'signals', [ '--lens=crossing-clients' ] ),
			'briefing' => fn () => $this->ci->dispatch( 'briefing', [ 'changes' ] ),
			'search'   => fn () => $this->ci->dispatch( 'search', [ '--since=2026-09-21', '--kind=research', '--sort=time' ] ),
		];
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );

		foreach ( $reads as $name => $read ) {
			$timed = \array_values( \array_filter( $this->queries_of( $read ), static fn ( string $sql ): bool => \str_contains( $sql, Intel_Graph_Row_Schema::ITEM_TIME . ' >= ?' ) ) );
			$this->assertNotSame( [], $timed, $name );
			foreach ( $timed as $sql ) {
				$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $sql )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
				$this->assertDoesNotMatchRegularExpression( '/SCAN p0\.(items|extractions)\b/', $plan, $name );
				if ( 'search' !== $name ) {
					$this->assertMatchesRegularExpression( '/USING (COVERING )?INDEX items_item_time \(item_time>\? AND item_time<\?\)/', $plan, $name );
				}
				if ( 'briefing' !== $name ) {
					$this->assertMatchesRegularExpression( '/USING (COVERING )?INDEX extractions_kind \(kind=\?\)/', $plan, $name );
				}
			}
		}
	}

	/**
	 * The spike read resolves stories only for the window's items and the
	 * copies sharing their clusters, which it finds through the clusters'
	 * cluster key rather than by reading every cluster row.
	 */
	public function test_the_spike_read_resolves_stories_through_the_cluster_key(): void {
		$this->weeks( 'incident', [ 1 => 4, 20 => 1 ] );
		$this->seed( self::story( 'feed:incident-1-1', [ 'feed:incident-1-1', 'feed:incident-1-2' ], self::THIS_WEEK - self::WEEK + 2 * 86400 ) );
		$this->save( 'tide-month', '--days=30' );

		$queries = $this->queries_of( fn () => $this->ci->dispatch( 'signals', [ '--lens=tide-month' ] ) );

		$spikes = \array_values( \array_filter( $queries, static fn ( string $sql ): bool => \str_contains( $sql, '.clusters' ) ) );
		$this->assertCount( 1, $spikes );
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
		$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $spikes[0] )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
		$this->assertMatchesRegularExpression( '/SEARCH (m|p0\.clusters) USING (COVERING )?INDEX clusters_cluster_key \(cluster_key=\?\)/', $plan );
	}

	/** A signal's usual range never runs below zero stories. */
	public function test_usual_never_runs_below_zero(): void {
		$this->weeks( 'hire', [ 1 => 4, 9 => 4, 10 => 1 ] );

		$usual = $this->ci->dispatch( 'signals', [] )['signals'][0]['usual'];

		$this->assertSame( [ 0.5, 0.0 ], [ $usual['mean'], $usual['low'] ] );
		$this->assertEqualsWithDelta( 0.5 + 2 * \sqrt( 1.75 ), $usual['high'], 1e-9 );
	}

	/**
	 * The spike rule and the band are one: over every baseline in the table
	 * and every count from 0 to 20, a count that spikes is at or above the
	 * band's high end, a count below it never spikes, and a count at or
	 * above it spikes once it passes the mean; a flat or empty baseline's
	 * high end is SIGNAL_MIN.
	 */
	public function test_the_spike_rule_reads_the_usual_range(): void {
		$usual   = new \ReflectionMethod( Graph_Query::class, 'usual_of' );
		$spiked  = new \ReflectionMethod( Graph_Query::class, 'spiked' );
		$table   = [ [ 1, 3, 1, 3, 1, 3, 1, 3 ], [ 2, 3, 2, 3, 2, 3, 2, 3 ], [ 3, 3, 3, 3, 3, 3, 3, 3 ], [ 0, 0, 0, 0, 0, 0, 0, 0 ], [ 0, 0, 0, 0, 0, 0, 0, 4 ], [ 5, 9, 2, 0, 7, 1, 1, 6 ], [ 12, 11, 13, 12, 12, 11, 13, 12 ] ];
		$spikes  = 0;
		foreach ( $table as $base ) {
			$range = $usual->invoke( null, $base );
			for ( $count = 0; $count <= 20; $count++ ) {
				$fires = $spiked->invoke( null, $count, $range );
				$this->assertSame( $count >= $range['high'] && $count > $range['mean'], $fires, \implode( ',', $base ) . " at {$count}" );
				$spikes += (int) $fires;
			}
		}
		$this->assertSame( [ 'mean' => 0.0, 'low' => 0.0, 'high' => 3.0 ], $usual->invoke( null, [ 0, 0, 0, 0, 0, 0, 0, 0 ] ) );
		$this->assertSame( [ 'mean' => 2.0, 'low' => 0.0, 'high' => 4.0 ], $usual->invoke( null, [ 1, 3, 1, 3, 1, 3, 1, 3 ] ) );
		$this->assertGreaterThan( 0, $spikes );
	}

	/**
	 * The drop rule and its band are one: over every baseline in the table
	 * and every count from 0 to 20, a week falls exactly when its baseline's
	 * mean reaches SIGNAL_MIN and its count is at or below the band's low
	 * end, which is SIGNAL_DROP of the mean, so a week inside the band never
	 * falls; the high end is the spike range's.
	 */
	public function test_the_drop_rule_reads_the_usual_range(): void {
		$usual = new \ReflectionMethod( Graph_Query::class, 'usual_of' );
		$fall  = new \ReflectionMethod( Graph_Query::class, 'fall_of' );
		$fell  = new \ReflectionMethod( Graph_Query::class, 'fell' );
		$table = [ [ 4, 4, 4, 4, 4, 4, 4, 4 ], [ 3, 3, 3, 3, 3, 3, 3, 3 ], [ 2, 2, 2, 2, 2, 2, 2, 2 ], [ 4, 4, 4, 4, 4, 4, 4, 0 ], [ 9, 1, 7, 3, 8, 2, 6, 4 ], [ 0, 0, 0, 0, 0, 0, 0, 0 ] ];
		$drops = 0;
		foreach ( $table as $base ) {
			$range = $fall->invoke( null, $base );
			$this->assertSame( [ $usual->invoke( null, $base )['mean'], Graph_Query::SIGNAL_DROP * $range['mean'], $usual->invoke( null, $base )['high'] ], [ $range['mean'], $range['low'], $range['high'] ], \implode( ',', $base ) );
			for ( $count = 0; $count <= 20; $count++ ) {
				$falls = $fell->invoke( null, $count, $range );
				$this->assertSame( $range['mean'] >= Graph_Query::SIGNAL_MIN && $count <= $range['low'], $falls, \implode( ',', $base ) . " at {$count}" );
				$drops += (int) $falls;
			}
		}
		$this->assertSame( [ 'mean' => 3.5, 'low' => 1.75, 'high' => 3.5 + 2 * \sqrt( 1.75 ) ], $fall->invoke( null, [ 4, 4, 4, 4, 4, 4, 4, 0 ] ) );
		$this->assertGreaterThan( 0, $drops );
	}

	/**
	 * Save a lens and project its event.
	 *
	 * @param string $key     The lens key, its name too.
	 * @param string ...$args Its filters.
	 */
	private function save( string $key, string ...$args ): void {
		$this->ci->dispatch( 'save_lens', [ $key, $key, ...$args ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
	}

	/**
	 * A weekly trend over the latest complete weeks, the latest first.
	 *
	 * @param list<int> $counts Each week's count, the latest first.
	 * @return list<array{bucket:string,count:int,in_window:bool}>
	 */
	private static function trend( array $counts ): array {
		return \array_map( static fn ( int $back, int $count ): array => [ 'bucket' => \gmdate( 'Y-m-d', self::THIS_WEEK - $back * self::WEEK ), 'count' => $count, 'in_window' => true ], \range( 1, \count( $counts ) ), $counts );
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
	 * Seed research items of one theme per week back from the current one.
	 *
	 * @param string         $theme  The theme every item is extracted with.
	 * @param array<int,int> $counts Weeks back => how many items.
	 */
	private function themed( string $theme, array $counts ): void {
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$this->seed( ...self::counted( "feed:themed-{$back}-{$n}", 'research', self::THIS_WEEK - $back * self::WEEK + 2 * 86400 + $n * 60, 'internal', 'feed', [], "{$theme} {$n} of week {$back}", $theme ) );
			}
		}
	}

	/**
	 * Seed items of one kind per week back from the current one, each linked
	 * to the Tidewater Ledger in one role.
	 *
	 * @param string         $kind   The kind every item is extracted as.
	 * @param string         $role   The link's role.
	 * @param array<int,int> $counts Weeks back => how many items.
	 */
	private function about_ledger( string $kind, string $role, array $counts ): void {
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$key = "feed:ledger-{$role}-{$kind}-{$back}-{$n}";
				$at  = self::THIS_WEEK - $back * self::WEEK + 2 * 86400 + $n * 60;
				$this->seed( ...[ ...self::counted( $key, $kind, $at, 'internal', 'feed', [] ), self::link( 'link_asserted', $key, 'organization:tidewater-ledger', $role, 'name', 1.0, $at ) ] );
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
	 * @param string       $theme  Its theme, or '' for none.
	 * @return list<list<array<string,mixed>>>
	 */
	private static function counted( string $key, string $kind, int $at, string $tier, string $source, array $topics, string $title = '', string $theme = '' ): array {
		$events = [
			[ \Newspack_Intelligence\Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => $source, 'tier' => $tier, 'url' => self::url( $key ), 'title' => '' === $title ? $key : $title, 'body' => '', 'item_ts' => $at, 'retrieved_ts' => $at + 600 ], self::$machine, 'fixture-9' ) ],
			self::extraction( $key, "A {$kind}.", $kind, $at, $topics, 5.0, 5.0, $theme ),
		];
		if ( 'restricted' === $tier ) {
			$events[] = self::link( 'link_asserted', $key, 'organization:tidewater-ledger', 'subject', 'name', 1.0, $at );
		}
		return $events;
	}

	/**
	 * Each signal as its bucket, axis, value, count and usual mean.
	 *
	 * @param array<string,mixed> $answer A signals answer.
	 * @return list<list<mixed>>
	 */
	private static function signals( array $answer ): array {
		return \array_map( static fn ( array $s ): array => [ $s['bucket'], $s['by'], $s['value'], $s['count'], $s['usual']['mean'] ], $answer['signals'] );
	}
}

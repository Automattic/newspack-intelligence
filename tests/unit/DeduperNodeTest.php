<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Deduper_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Projector_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The deduper: an extracted item joins the cluster of an earlier copy found
 * by its canonical URL, or by a long enough title, within the window; the
 * founding item keys the cluster and the best-ranked member is its
 * canonical, each decision written as an event and none edited.
 */
final class DeduperNodeTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1794200000.0;

	private const DAY = 86400;

	private const TS = 1794000000;

	private Deduper_Node $deduper;

	private Capture_Sink_Node $out;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
		$this->out     = new Capture_Sink_Node();
		$this->deduper = self::deduper( $this->out );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** Scheme, `www.`, tracking keys, key order, the fragment and a trailing slash do not part two copies. */
	public function test_tracking_params_and_www_do_not_split_a_url(): void {
		$this->extracted( 'feed:quarry-41', 'https://www.Quarry.example/news/levy/?utm_source=np&id=7&fbclid=Iw9#top', 'Levy passes', 5, 3 );
		$this->extracted( 'linear:NEWS-882', 'http://quarry.example/news/levy?gclid=Cj0&mc_cid=a1&mc_eid=b2&id=7', 'Quarry levy', 4, 2 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:quarry-41', 'feed:quarry-41' ],
				[ 'cluster_joined', 'linear:NEWS-882', 'feed:quarry-41' ],
				[ 'cluster_superseded', 'feed:quarry-41', 'feed:quarry-41', 'feed:quarry-41', 8.0 ],
			],
			$this->decisions()
		);
	}

	/**
	 * A title shorter than MIN_TITLE_NORM never matches; a long one matches
	 * across URLs, but only within WINDOW_DAYS of the earlier copy.
	 */
	public function test_a_short_generic_title_never_clusters(): void {
		$this->extracted( 'feed:roundup-1', 'https://mesa.example/a', 'The Weekly Roundup!', 2, 2 );
		$this->extracted( 'feed:roundup-2', 'https://harbor.example/b', 'the weekly roundup', 2, 2 );
		$this->assertSame( [], $this->decisions() );

		$long = 'Harbor Gazette wins a state press award';
		$this->extracted( 'feed:award-1', 'https://harbor.example/award', $long, 3, 1 );
		$this->extracted( 'github:award-2', 'https://github.example/harbor/award', "{$long}.", 6, 1, self::TS + 14 * self::DAY );
		$this->extracted( 'feed:award-3', 'https://mesa.example/award-again', $long, 1, 1, self::TS + 29 * self::DAY );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:award-1', 'feed:award-1' ],
				[ 'cluster_joined', 'github:award-2', 'feed:award-1' ],
				[ 'cluster_superseded', 'feed:award-1', 'github:award-2', 'feed:award-1', 7.0 ],
			],
			$this->decisions()
		);
	}

	/**
	 * A tie keeps the earlier canonical; a later copy that outranks it
	 * supersedes it by a new event, one that does not joins quietly, and the
	 * projection reads the last canonical.
	 */
	public function test_a_higher_ranked_copy_supersedes_by_event_not_edit(): void {
		$url = 'https://harbor.example/funding';
		$this->extracted( 'feed:fund-1', $url, 'Fund one', 4, 2 );
		$this->extracted( 'feed:fund-2', $url, 'Fund two', 3, 3 );
		$this->extracted( 'feed:fund-3', $url, 'Fund three', 9, 6 );
		$this->extracted( 'feed:fund-4', $url, 'Fund four', 7, 3 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:fund-1', 'feed:fund-1' ],
				[ 'cluster_joined', 'feed:fund-2', 'feed:fund-1' ],
				[ 'cluster_superseded', 'feed:fund-1', 'feed:fund-1', 'feed:fund-1', 6.0 ],
				[ 'cluster_joined', 'feed:fund-3', 'feed:fund-1' ],
				[ 'cluster_superseded', 'feed:fund-1', 'feed:fund-3', 'feed:fund-1', 15.0 ],
				[ 'cluster_joined', 'feed:fund-4', 'feed:fund-1' ],
			],
			$this->decisions()
		);
		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		foreach ( $this->written() as $event ) {
			Intel_Event::validate( $event );
			$this->assertSame( "machine:dedup@{$version}", $event['actor'] );
		}

		$this->seed( $this->written() );
		$this->assertSame( [ [ 'feed:fund-1', 'feed:fund-3', 15.0 ] ], self::graph_rows( 'canonicals', '1', 'cluster_key, canonical_item_key, rank' ) );
		$this->assertSame( [ [ 'feed:fund-1' ], [ 'feed:fund-1' ], [ 'feed:fund-1' ], [ 'feed:fund-1' ] ], self::graph_rows( 'clusters', 'item_key', 'cluster_key' ) );
	}

	/** Two copies arriving before the projection holds either still meet. */
	public function test_two_copies_in_one_tick_meet_in_the_lru(): void {
		$this->extracted( 'feed:tick-1', 'https://mesa.example/launch', 'Mesa launches', 2, 5 );
		$this->extracted( 'feed:tick-2', 'https://mesa.example/launch/', 'Mesa launch', 6, 4 );

		$this->assertSame( [], self::graph_rows( 'items' ) );
		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:tick-1', 'feed:tick-1' ],
				[ 'cluster_joined', 'feed:tick-2', 'feed:tick-1' ],
				[ 'cluster_superseded', 'feed:tick-1', 'feed:tick-2', 'feed:tick-1', 10.0 ],
			],
			$this->decisions()
		);
	}

	/** A fresh deduper finds an earlier copy, its cluster and its canonical in the projection. */
	public function test_a_miss_reads_the_cluster_from_the_projection(): void {
		$url = 'https://lion.example/grant';
		$this->seed(
			self::recorded( 'feed:grant-1', $url, 'Grant one', 2, 1, self::TS - 9 * self::DAY ),
			self::recorded( 'feed:grant-2', $url, 'Grant two', 7, 5, self::TS - 4 * self::DAY ),
			self::recorded( 'feed:grant-3', 'https://other.example/x', 'Grant elsewhere', 9, 9, self::TS - self::DAY ),
			[
				Intel_Event::machine_event( 'dedup', 'cluster_joined', [ 'item_key' => 'feed:grant-1', 'cluster_key' => 'feed:grant-1', 'retrieved_ts' => self::TS + 60 ] ),
				Intel_Event::machine_event( 'dedup', 'cluster_joined', [ 'item_key' => 'feed:grant-2', 'cluster_key' => 'feed:grant-1', 'retrieved_ts' => self::TS + 60 ] ),
				Intel_Event::machine_event( 'dedup', 'cluster_superseded', [ 'cluster_key' => 'feed:grant-1', 'canonical_item_key' => 'feed:grant-2', 'previous_item_key' => 'feed:grant-1', 'rank' => 12.0, 'retrieved_ts' => self::TS + 60 ] ),
			]
		);

		$this->extracted( 'feed:grant-4', 'https://www.lion.example/grant?utm_medium=email', 'Grant four', 8, 5 );
		$this->extracted( 'feed:grant-5', 'https://elsewhere.example/y', 'Grant elsewhere again', 9, 9 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:grant-4', 'feed:grant-1' ],
				[ 'cluster_superseded', 'feed:grant-1', 'feed:grant-4', 'feed:grant-2', 13.0 ],
			],
			$this->decisions()
		);
	}

	/** An earlier singleton in the projection founds the cluster with the newcomer. */
	public function test_a_singleton_in_the_projection_founds_a_cluster(): void {
		$this->seed( self::recorded( 'feed:solo-1', 'https://knight.example/solo', 'Solo', 6, 4, self::TS - 3 * self::DAY ) );

		$this->extracted( 'feed:solo-2', 'https://knight.example/solo', 'Solo again', 1, 1 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:solo-1', 'feed:solo-1' ],
				[ 'cluster_joined', 'feed:solo-2', 'feed:solo-1' ],
				[ 'cluster_superseded', 'feed:solo-1', 'feed:solo-1', 'feed:solo-1', 10.0 ],
			],
			$this->decisions()
		);
	}

	/** A newcomer ranked below a projected canonical joins without superseding it. */
	public function test_a_lower_ranked_copy_does_not_supersede_a_projected_canonical(): void {
		$url = 'https://inn.example/merger';
		$this->seed(
			self::recorded( 'feed:merge-1', $url, 'Merger one', 3, 2, self::TS - 6 * self::DAY ),
			self::recorded( 'feed:merge-2', $url, 'Merger two', 7, 5, self::TS - 2 * self::DAY ),
			self::clustered( 'feed:merge-1', [ 'feed:merge-1', 'feed:merge-2' ], 'feed:merge-2', 12.0 )
		);

		$this->extracted( 'feed:merge-3', $url, 'Merger three', 5, 3 );

		$this->assertSame( [ [ 'cluster_joined', 'feed:merge-3', 'feed:merge-1' ] ], $this->decisions() );
	}

	/** A projected cluster whose canonical is not projected yet takes the newcomer's join alone. */
	public function test_a_projected_cluster_with_no_canonical_takes_only_the_join(): void {
		$url = 'https://press.example/lag';
		$this->seed(
			self::recorded( 'feed:lag-1', $url, 'Lag one', 1, 1, self::TS - 5 * self::DAY ),
			self::recorded( 'feed:lag-2', $url, 'Lag two', 9, 8, self::TS - 4 * self::DAY ),
			self::clustered( 'feed:lag-1', [ 'feed:lag-1', 'feed:lag-2' ] )
		);

		$this->extracted( 'feed:lag-3', $url, 'Lag three', 10, 10 );

		$this->assertSame( [ [ 'cluster_joined', 'feed:lag-3', 'feed:lag-1' ] ], $this->decisions() );
	}

	/** A projected copy 15 days from the newcomer is outside the window. */
	public function test_a_projected_copy_past_the_window_does_not_match(): void {
		$this->seed( self::recorded( 'feed:old-1', 'https://knight.example/old', 'Old', 4, 4, self::TS - 15 * self::DAY ) );

		$this->extracted( 'feed:old-2', 'https://knight.example/old', 'Old again', 9, 9 );

		$this->assertSame( [], $this->decisions() );
	}

	/** On a miss, a copy at the same URL wins over a nearer one sharing only the title. */
	public function test_the_url_wins_over_a_nearer_title_on_a_miss(): void {
		$title = 'Press Forward names its newest local grantees';
		$this->seed(
			self::recorded( 'feed:pf-url', 'https://pressforward.example/grantees', 'Grantees', 2, 3, self::TS - 8 * self::DAY ),
			self::recorded( 'feed:pf-title', 'https://mesa.example/press-forward', $title, 6, 6, self::TS - self::DAY )
		);

		$this->extracted( 'feed:pf-new', 'https://pressforward.example/grantees', $title, 1, 1 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:pf-url', 'feed:pf-url' ],
				[ 'cluster_joined', 'feed:pf-new', 'feed:pf-url' ],
				[ 'cluster_superseded', 'feed:pf-url', 'feed:pf-url', 'feed:pf-url', 5.0 ],
			],
			$this->decisions()
		);
	}

	/**
	 * A placed item is never placed again: re-delivered, it stays in its
	 * cluster though its title now meets a nearer copy, and only re-ranks.
	 */
	public function test_a_placed_item_is_never_re_placed(): void {
		$url   = 'https://lenfest.example/fellows';
		$title = 'Lenfest names the next class of local news fellows';
		$this->extracted( 'feed:fel-a', $url, 'Fellows', 4, 4, self::TS - 6 * self::DAY );
		$this->extracted( 'feed:fel-t', 'https://other.example/fellows', $title, 2, 2, self::TS - self::DAY );
		$this->extracted( 'feed:fel-b', $url, $title, 3, 3 );
		$placed = $this->decisions();
		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:fel-a', 'feed:fel-a' ],
				[ 'cluster_joined', 'feed:fel-b', 'feed:fel-a' ],
				[ 'cluster_superseded', 'feed:fel-a', 'feed:fel-a', 'feed:fel-a', 8.0 ],
			],
			$placed
		);

		$this->extracted( 'feed:fel-b', $url, $title, 3, 3 );
		$this->extracted( 'feed:fel-b', $url, $title, 6, 5 );

		$this->assertSame( [ ...$placed, [ 'cluster_superseded', 'feed:fel-a', 'feed:fel-b', 'feed:fel-a', 11.0 ] ], $this->decisions() );
	}

	/** On a miss, an item whose own clusters row the projection holds stays in that cluster. */
	public function test_a_projected_placed_item_is_never_re_placed(): void {
		$url   = 'https://lenfest.example/fellows';
		$title = 'Lenfest names the next class of local news fellows';
		$this->seed(
			self::recorded( 'feed:fel-a', $url, 'Fellows', 4, 4, self::TS - 6 * self::DAY ),
			self::recorded( 'feed:fel-t', 'https://other.example/fellows', $title, 2, 2, self::TS - self::DAY ),
			self::recorded( 'feed:fel-b', $url, $title, 3, 3, self::TS ),
			self::clustered( 'feed:fel-a', [ 'feed:fel-a', 'feed:fel-b' ], 'feed:fel-a', 8.0 )
		);

		$this->extracted( 'feed:fel-b', $url, $title, 3, 3 );

		$this->assertSame( [], $this->decisions() );
	}

	/** An undated item is timed by its retrieval, in the LRU and in the projection. */
	public function test_an_undated_item_is_timed_by_its_retrieval(): void {
		$this->extracted( 'linear:NEWS-1', 'https://linear.example/news-1', 'Undated', 2, 2, 0, self::TS - 3 * self::DAY );
		$this->extracted( 'feed:dated-1', 'https://linear.example/news-1', 'Dated', 1, 1 );
		$this->seed( self::recorded( 'linear:NEWS-2', 'https://linear.example/news-2', 'Undated two', 5, 5, 0, self::TS - 2 * self::DAY ) );
		$this->extracted( 'feed:dated-2', 'https://linear.example/news-2', 'Dated two', 1, 1 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'linear:NEWS-1', 'linear:NEWS-1' ],
				[ 'cluster_joined', 'feed:dated-1', 'linear:NEWS-1' ],
				[ 'cluster_superseded', 'linear:NEWS-1', 'linear:NEWS-1', 'linear:NEWS-1', 4.0 ],
				[ 'cluster_joined', 'linear:NEWS-2', 'linear:NEWS-2' ],
				[ 'cluster_joined', 'feed:dated-2', 'linear:NEWS-2' ],
				[ 'cluster_superseded', 'linear:NEWS-2', 'linear:NEWS-2', 'linear:NEWS-2', 10.0 ],
			],
			$this->decisions()
		);
	}

	/** The lookup checkpoints with the cursor, so a respawned deduper with no projection still meets a remembered copy. */
	public function test_the_lookup_survives_a_respawn_through_its_snapshot(): void {
		$url = 'https://lion.example/summit';
		$this->extracted( 'feed:sum-1', $url, 'Summit', 3, 3 );
		$this->extracted( 'feed:sum-2', $url, 'Summit two', 4, 4 );
		$state = \json_decode( (string) \json_encode( $this->deduper->save_state() ), true );
		$this->assertIsArray( $state );
		$this->activate();
		$this->capture_stderr();
		$this->out     = new Capture_Sink_Node();
		$this->deduper = self::respawn( $this->out, $state );

		$this->extracted( 'feed:sum-3', $url, 'Summit three', 7, 6 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:sum-3', 'feed:sum-1' ],
				[ 'cluster_superseded', 'feed:sum-1', 'feed:sum-3', 'feed:sum-2', 13.0 ],
			],
			$this->decisions()
		);
	}

	/** A canonical re-delivered at a new rank records that rank by event, and a newcomer is judged against it. */
	public function test_a_re_ranked_canonical_is_recorded_by_event(): void {
		$url = 'https://knight.example/prize';
		$this->extracted( 'feed:prize-1', $url, 'Prize', 2, 2 );
		$this->extracted( 'feed:prize-2', $url, 'Prize two', 7, 5 );
		$this->extracted( 'feed:prize-2', $url, 'Prize two', 3, 2 );
		$this->extracted( 'feed:prize-3', $url, 'Prize three', 4, 4 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:prize-1', 'feed:prize-1' ],
				[ 'cluster_joined', 'feed:prize-2', 'feed:prize-1' ],
				[ 'cluster_superseded', 'feed:prize-1', 'feed:prize-2', 'feed:prize-1', 12.0 ],
				[ 'cluster_superseded', 'feed:prize-1', 'feed:prize-2', 'feed:prize-2', 5.0 ],
				[ 'cluster_joined', 'feed:prize-3', 'feed:prize-1' ],
				[ 'cluster_superseded', 'feed:prize-1', 'feed:prize-3', 'feed:prize-2', 8.0 ],
			],
			$this->decisions()
		);
	}

	/** A member whose spelling a later copy took is found by its own cluster row, so it re-ranks rather than joining again. */
	public function test_a_re_delivered_member_is_found_by_its_own_cluster_row(): void {
		$url = 'https://knight.example/medal';
		$this->extracted( 'feed:medal-1', $url, 'Medal', 1, 1, self::TS - 2 * self::DAY );
		$this->extracted( 'feed:medal-2', $url, 'Medal two', 7, 5, self::TS - self::DAY );
		$this->extracted( 'feed:medal-3', $url, 'Medal three', 2, 1 );
		$this->seed(
			self::recorded( 'feed:medal-1', $url, 'Medal', 1, 1, self::TS - 2 * self::DAY ),
			self::recorded( 'feed:medal-2', $url, 'Medal two', 7, 5, self::TS - self::DAY ),
			self::recorded( 'feed:medal-3', $url, 'Medal three', 2, 1, self::TS ),
			$this->written()
		);
		$placed = $this->decisions();

		$this->extracted( 'feed:medal-2', $url, 'Medal two', 3, 2, self::TS - self::DAY );

		$this->assertSame( [ ...$placed, [ 'cluster_superseded', 'feed:medal-1', 'feed:medal-2', 'feed:medal-2', 5.0 ] ], $this->decisions() );
	}

	/** A founder whose URL a later cluster took stays in its own cluster when re-delivered. */
	public function test_a_re_delivered_founder_stays_in_its_own_cluster(): void {
		$url   = 'https://lion.example/award';
		$title = 'Lion Publishers names its newest award winners';
		$this->extracted( 'feed:lion-a', $url, $title, 3, 3, self::TS );
		$this->extracted( 'feed:lion-b', 'https://other.example/lion', $title, 1, 1, self::TS + self::DAY );
		$this->extracted( 'feed:lion-c', $url, 'Lion C', 2, 2, self::TS + 20 * self::DAY );
		$this->extracted( 'feed:lion-d', $url, 'Lion D', 1, 2, self::TS + 10 * self::DAY );
		$this->seed(
			self::recorded( 'feed:lion-a', $url, $title, 3, 3, self::TS ),
			self::recorded( 'feed:lion-b', 'https://other.example/lion', $title, 1, 1, self::TS + self::DAY ),
			self::recorded( 'feed:lion-c', $url, 'Lion C', 2, 2, self::TS + 20 * self::DAY ),
			self::recorded( 'feed:lion-d', $url, 'Lion D', 1, 2, self::TS + 10 * self::DAY ),
			$this->written()
		);
		$placed = $this->decisions();
		$this->assertSame( [ [ 'feed:lion-a' ], [ 'feed:lion-a' ], [ 'feed:lion-c' ], [ 'feed:lion-c' ] ], self::graph_rows( 'clusters', 'item_key', 'cluster_key' ) );

		$this->extracted( 'feed:lion-a', $url, $title, 3, 3, self::TS );

		$this->assertSame( $placed, $this->decisions() );
	}

	/** A canonical that lowers its rank hands the cluster to its best-ranked member. */
	public function test_a_lowered_canonical_yields_to_its_best_member(): void {
		$url = 'https://inn.example/survey';
		$this->extracted( 'feed:survey-1', $url, 'Survey', 6, 4, self::TS - 3 * self::DAY );
		$this->extracted( 'feed:survey-2', $url, 'Survey two', 7, 5, self::TS - 2 * self::DAY );
		$this->extracted( 'feed:survey-3', $url, 'Survey three', 1, 1 );
		$this->seed(
			self::recorded( 'feed:survey-1', $url, 'Survey', 6, 4, self::TS - 3 * self::DAY ),
			self::recorded( 'feed:survey-2', $url, 'Survey two', 7, 5, self::TS - 2 * self::DAY ),
			self::recorded( 'feed:survey-3', $url, 'Survey three', 1, 1, self::TS ),
			$this->written()
		);
		$placed = $this->decisions();

		$this->extracted( 'feed:survey-2', $url, 'Survey two', 3, 2, self::TS - 2 * self::DAY );

		$this->assertSame( [ ...$placed, [ 'cluster_superseded', 'feed:survey-1', 'feed:survey-1', 'feed:survey-2', 10.0 ] ], $this->decisions() );
	}

	/**
	 * Holding a cluster again replaces its entry wherever the LRU holds it, so
	 * an item placed afresh after its spellings moved on costs one entry.
	 */
	public function test_a_cluster_is_held_once(): void {
		$url = 'https://press.example/busy';
		$this->extracted( 'feed:busy-1', $url, 'Busy', 1, 1 );
		$this->extracted( 'feed:busy-2', $url, 'Busy two', 2, 2, self::TS + 30 * self::DAY );
		for ( $i = 0; $i < 600; $i++ ) {
			$this->extracted( "feed:filler-{$i}", "https://filler.example/{$i}", "Filler {$i}", 1, 1 );
		}
		$this->extracted( 'feed:busy-1', $url, 'Busy', 1, 1 );

		$held = 0;
		foreach ( $this->deduper->save_state()['seen']['buckets'] as $bucket ) {
			$held += (int) \array_key_exists( 'cluster:feed:busy-1', $bucket );
		}
		$this->assertSame( 1, $held );
	}

	/**
	 * A full lookup checkpoints as positional entries under hashed spellings,
	 * carrying no URL or title, so its size is the item keys it names.
	 */
	public function test_a_full_lookup_checkpoints_compactly(): void {
		for ( $i = 0; $i < 1666; $i++ ) {
			$this->extracted( "github:Automattic/newspack-plugin#{$i}", "https://www.harborgazette.example/2026/10/county-commission-approves-new-budget-{$i}/", "County commission approves the new budget after a long night number {$i}", 7, 5, self::TS + $i );
		}
		$state   = $this->deduper->save_state();
		$count   = 0;
		foreach ( $state['seen']['buckets'] as $bucket ) {
			foreach ( $bucket as $key => $entry ) {
				++$count;
				$this->assertMatchesRegularExpression( '/^([0-9a-f]{16}|cluster:github:Automattic\/newspack-plugin#\d+)$/', (string) $key );
				$this->assertTrue( \array_is_list( $entry ) );
			}
		}
		$this->assertSame( 4998, $count );
		$json = (string) \json_encode( $state, \JSON_UNESCAPED_SLASHES );
		$this->assertStringNotContainsString( 'harborgazette', $json );
		$this->assertStringNotContainsString( 'county commission', $json );
		$this->assertLessThan( 465000, \strlen( $json ) );
	}

	/** A singleton's and a founder's entries name no cluster of their own, and still resolve to it after a respawn. */
	public function test_an_item_in_its_own_cluster_stores_no_cluster_key(): void {
		$this->extracted( 'feed:own-solo', 'https://inn.example/solo', 'Solo', 2, 3 );
		$this->extracted( 'feed:own-found', 'https://inn.example/found', 'The founding story keeps a long title', 4, 4 );
		$this->extracted( 'feed:own-join', 'https://inn.example/found', 'Join', 1, 1 );
		$state = \json_decode( (string) \json_encode( $this->deduper->save_state() ), true );
		$this->assertIsArray( $state );
		$clusters = [];
		foreach ( $state['seen']['buckets'] as $bucket ) {
			foreach ( $bucket as $entry ) {
				if ( \is_string( $entry[0] ) && \str_starts_with( $entry[0], 'feed:own-' ) && ! \is_bool( $entry[2] ) ) {
					$clusters[ $entry[0] ] = $entry[2];
				}
			}
		}
		\ksort( $clusters );
		$this->assertSame( [ 'feed:own-found' => null, 'feed:own-join' => 'feed:own-found', 'feed:own-solo' => null ], $clusters );
		$this->activate();
		$this->out     = new Capture_Sink_Node();
		$this->deduper = self::respawn( $this->out, $state );

		$this->extracted( 'feed:own-solo-2', 'https://inn.example/solo', 'Solo two', 6, 6 );
		$this->extracted( 'feed:own-found-2', 'https://elsewhere.example/found', 'The founding story keeps a long title', 9, 9 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:own-solo', 'feed:own-solo' ],
				[ 'cluster_joined', 'feed:own-solo-2', 'feed:own-solo' ],
				[ 'cluster_superseded', 'feed:own-solo', 'feed:own-solo-2', 'feed:own-solo', 12.0 ],
				[ 'cluster_joined', 'feed:own-found-2', 'feed:own-found' ],
				[ 'cluster_superseded', 'feed:own-found', 'feed:own-found-2', 'feed:own-found', 18.0 ],
			],
			$this->decisions()
		);
	}

	/** A founder remembered past its cluster's entry is compared with the projected canonical, never demoting it. */
	public function test_an_evicted_cluster_is_read_again_from_the_projection(): void {
		$url = 'https://inn.example/index';
		$this->extracted( 'feed:idx-1', $url, 'Index', 2, 2 );
		$this->extracted( 'feed:idx-2', $url, 'Index two', 6, 6 );
		$this->seed(
			self::recorded( 'feed:idx-1', $url, 'Index', 2, 2, self::TS ),
			self::recorded( 'feed:idx-2', $url, 'Index two', 6, 6, self::TS ),
			$this->written()
		);
		$state = $this->deduper->save_state();
		$this->out     = new Capture_Sink_Node();
		$this->deduper = self::respawn( $this->out, self::without( $state, 'cluster:feed:idx-1' ) );

		$this->extracted( 'feed:idx-3', $url, 'Index three', 5, 4 );
		$this->extracted( 'feed:idx-4', $url, 'Index four', 7, 6 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:idx-3', 'feed:idx-1' ],
				[ 'cluster_joined', 'feed:idx-4', 'feed:idx-1' ],
				[ 'cluster_superseded', 'feed:idx-1', 'feed:idx-4', 'feed:idx-2', 13.0 ],
			],
			$this->decisions()
		);
	}

	/** The same item delivered twice is not its own copy. */
	public function test_an_item_never_clusters_with_itself(): void {
		$this->seed( self::recorded( 'feed:self-1', 'https://press.example/self', 'Self', 3, 3, self::TS ) );

		$this->extracted( 'feed:self-1', 'https://press.example/self', 'Self', 3, 3 );
		$this->extracted( 'feed:self-1', 'https://press.example/self', 'Self', 3, 3 );

		$this->assertSame( [], $this->decisions() );
	}

	/** With no intel-graph declared the projection is skipped with a line, and copies still meet in the LRU. */
	public function test_an_unavailable_projection_is_read_as_empty(): void {
		$this->activate();
		$stderr = $this->capture_stderr();

		$this->extracted( 'feed:dark-1', 'https://dark.example/a', 'Dark', 1, 2 );
		$this->extracted( 'feed:dark-2', 'https://dark.example/a', 'Dark too', 1, 1 );

		$this->assertSame( [ 'cluster_joined', 'cluster_joined', 'cluster_superseded' ], \array_column( $this->written(), 'event' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to find copies in; reading none: ' ) );
	}

	/** Anything but an item_extracted is ignored; a malformed one is dropped with a line. */
	public function test_a_malformed_item_is_dropped_and_other_kinds_ignored(): void {
		$stderr = $this->capture_stderr();

		$this->deduper->fill( self::message( [ 'event' => 'item_resolved', 'item' => self::item( 'feed:x', 'https://x.example/', 'X', self::TS ) ] ) );
		$this->deduper->fill( self::message( 'TICK' ) );
		$this->deduper->fill( self::message( [ 'event' => 'item_extracted', 'item' => [ 'item_key' => 'feed:x' ], 'relevance' => 1, 'importance' => 1 ] ) );
		$this->deduper->fill( self::message( [ 'event' => 'item_extracted', 'item' => self::item( 'feed:x', 'https://x.example/', 'X', self::TS ), 'relevance' => 'high' ] ) );

		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping an extracted item it cannot read from extractor' ) );
	}

	public function test_the_writer_is_declared_as_its_destination(): void {
		$this->assertSame( [ 'intel:writer' ], $this->deduper->display_targets() );
	}

	public function test_a_load_without_a_writer_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: writer' );
		( new Deduper_Node() )->arguments( [] );
	}

	/**
	 * A deduper addressing intel:writer, sinking into a capture.
	 *
	 * @param Capture_Sink_Node $out The capture.
	 */
	private static function deduper( Capture_Sink_Node $out ): Deduper_Node {
		$deduper = new Deduper_Node();
		$deduper->name( 'deduper' );
		$deduper->arguments( [ 'intel:writer' ] );
		$deduper->sink( $out );
		return $deduper;
	}

	/**
	 * A deduper restored from a saved state, addressing intel:writer.
	 *
	 * @param Capture_Sink_Node    $out   The capture.
	 * @param array<array-key,mixed> $state What save_state() answered.
	 */
	private static function respawn( Capture_Sink_Node $out, array $state ): Deduper_Node {
		Core::unregister_node( 'deduper' );
		$deduper = self::deduper( $out );
		$deduper->restore_state( $state );
		return $deduper;
	}

	/**
	 * A saved state with one LRU entry removed, as an eviction leaves it.
	 *
	 * @param array<array-key,mixed> $state What save_state() answered.
	 * @param string                 $key   The entry.
	 * @return array<array-key,mixed>
	 */
	private static function without( array $state, string $key ): array {
		$found = false;
		foreach ( $state['seen']['buckets'] as $i => $bucket ) {
			if ( isset( $bucket[ $key ] ) ) {
				unset( $state['seen']['buckets'][ $i ][ $key ] );
				$found = true;
			}
		}
		self::assertTrue( $found, "no {$key} in the state" );
		return $state;
	}

	/** A lookup checkpoint it cannot read is dropped, and the drop logged once naming it. */
	public function test_a_dropped_checkpoint_is_logged(): void {
		$stderr = $this->capture_stderr();

		$this->deduper->restore_state( [ 'seen' => [ 'buckets' => 'not-a-bucket-list', 'current' => 2 ] ] );

		$this->assertSame( [], $this->deduper->save_state()['seen']['buckets'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a lookup checkpoint it cannot read: {"buckets":"not-a-bucket-list","current":2}' ) );
	}

	/** Each join carries its member's retrieval, and each supersession its canonical's, from the LRU or the projection. */
	public function test_each_decision_carries_its_items_retrieval(): void {
		$this->seed( self::recorded( 'feed:kept-1', 'https://lion.example/kept', 'Kept one', 1, 1, self::TS - self::DAY, self::TS - 700 * self::DAY ) );
		$this->extracted( 'feed:kept-2', 'https://lion.example/kept', 'Kept two', 1, 0, self::TS, self::TS + 11 );
		$this->extracted( 'feed:lru-1', 'https://lion.example/lru', 'Lru one', 3, 3, self::TS, self::TS + 22 );
		$this->extracted( 'feed:lru-2', 'https://lion.example/lru', 'Lru two', 1, 1, self::TS, self::TS + 33 );

		$this->assertSame(
			[
				[ 'cluster_joined', 'feed:kept-1', self::TS - 700 * self::DAY ],
				[ 'cluster_joined', 'feed:kept-2', self::TS + 11 ],
				[ 'cluster_superseded', 'feed:kept-1', self::TS - 700 * self::DAY ],
				[ 'cluster_joined', 'feed:lru-1', self::TS + 22 ],
				[ 'cluster_joined', 'feed:lru-2', self::TS + 33 ],
				[ 'cluster_superseded', 'feed:lru-1', self::TS + 22 ],
			],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['item_key'] ?? $e['canonical_item_key'], $e['retrieved_ts'] ], $this->written() )
		);
	}

	/**
	 * A cluster as the projection holds it: each member's join, and its
	 * canonical when one is named.
	 *
	 * @param string       $cluster   The cluster key.
	 * @param list<string> $members   Its members.
	 * @param string|null  $canonical Its canonical, or null for none yet.
	 * @param float        $rank      The canonical's rank.
	 * @return list<array<string,mixed>>
	 */
	private static function clustered( string $cluster, array $members, ?string $canonical = null, float $rank = 0.0 ): array {
		$events = \array_map( static fn ( string $member ): array => Intel_Event::machine_event( 'dedup', 'cluster_joined', [ 'item_key' => $member, 'cluster_key' => $cluster, 'retrieved_ts' => self::TS + 60 ] ), $members );
		if ( null !== $canonical ) {
			$events[] = Intel_Event::machine_event( 'dedup', 'cluster_superseded', [ 'cluster_key' => $cluster, 'canonical_item_key' => $canonical, 'previous_item_key' => $cluster, 'rank' => $rank, 'retrieved_ts' => self::TS + 60 ] );
		}
		return $events;
	}

	/**
	 * Hand the deduper one extracted item.
	 *
	 * @param string $key        The item key.
	 * @param string $url        Its URL.
	 * @param string $title      Its title.
	 * @param int    $relevance  Its relevance.
	 * @param int    $importance Its importance.
	 * @param int    $item_ts    Its timestamp.
	 * @param int    $retrieved  When it was retrieved.
	 */
	private function extracted( string $key, string $url, string $title, int $relevance, int $importance, int $item_ts = self::TS, int $retrieved = self::TS + 60 ): void {
		$this->deduper->fill( self::message( [ 'event' => 'item_extracted', 'item' => self::item( $key, $url, $title, $item_ts, $retrieved ), 'relevance' => $relevance, 'importance' => $importance ] ) );
	}

	/**
	 * An item and its extraction, as the projection holds them.
	 *
	 * @param string $key        The item key.
	 * @param string $url        Its URL.
	 * @param string $title      Its title.
	 * @param int    $relevance  Its relevance.
	 * @param int    $importance Its importance.
	 * @param int    $item_ts    Its timestamp.
	 * @param int    $retrieved  When it was retrieved.
	 * @return list<array<string,mixed>>
	 */
	private static function recorded( string $key, string $url, string $title, int $relevance, int $importance, int $item_ts, int $retrieved = self::TS + 60 ): array {
		$extraction = [ 'item_key' => $key, 'summary' => $title, 'kind' => 'other', 'relevance' => $relevance, 'importance' => $importance, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => $retrieved ];
		return [
			Intel_Event::machine_event( 'feed', 'item_recorded', self::item( $key, $url, $title, $item_ts, $retrieved ) ),
			Intel_Event::machine_event( 'extract', 'extraction_recorded', $extraction ),
		];
	}

	/**
	 * An item's eight recorded fields.
	 *
	 * @param string $key     The item key.
	 * @param string $url     Its URL.
	 * @param string $title   Its title.
	 * @param int    $item_ts   Its timestamp.
	 * @param int    $retrieved When it was retrieved.
	 * @return array<string,mixed>
	 */
	private static function item( string $key, string $url, string $title, int $item_ts, int $retrieved = self::TS + 60 ): array {
		return [ 'item_key' => $key, 'source' => \explode( ':', $key )[0], 'tier' => 'public', 'url' => $url, 'title' => $title, 'body' => 'Body.', 'item_ts' => $item_ts, 'retrieved_ts' => $retrieved ];
	}

	/**
	 * A message from the extractor carrying a VALUE.
	 *
	 * @param mixed $value The VALUE.
	 * @return array<int,mixed>
	 */
	private static function message( mixed $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = \is_array( $value ) ? Message::TM_STRUCT : Message::TM_INFO;
		$message[ Message::FROM ]  = 'extractor';
		$message[ Message::VALUE ] = $value;
		return $message;
	}

	/** @return list<array<string,mixed>> The events addressed to the writer. */
	private function written(): array {
		$events = [];
		foreach ( $this->out->captured as $message ) {
			$this->assertSame( [ 'intel:writer', Message::TM_STRUCT, 'deduper' ], [ $message[ Message::TO ], $message[ Message::TYPE ], $message[ Message::FROM ] ] );
			$events[] = $message[ Message::VALUE ];
		}
		return $events;
	}

	/** @return list<list<mixed>> Each event's kind and fields, in order. */
	private function decisions(): array {
		return \array_map(
			static fn ( array $e ): array => 'cluster_joined' === $e['event']
				? [ $e['event'], $e['item_key'], $e['cluster_key'] ]
				: [ $e['event'], $e['cluster_key'], $e['canonical_item_key'], $e['previous_item_key'], $e['rank'] ],
			$this->written()
		);
	}
}

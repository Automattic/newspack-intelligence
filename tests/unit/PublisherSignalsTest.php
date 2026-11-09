<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * An entity's signals gain a publisher axis: what its own site published,
 * per week, against its own baseline. A kind reaching the spike rule in any
 * entity's own output is a burst. A client's week at half or less of a
 * baseline mean of at least SIGNAL_MIN is a drop, the first two empty weeks
 * of one a silence, unless every client's output fell that week, which is
 * collection failing rather than the client. A lens answers none of them.
 * A client's record answers its status beside them.
 *
 * The Courier is a client, the Tern Ledger a client publishing five items a
 * week throughout, and the Heron Weekly a former client. The fixture's tick
 * is Tuesday 2026-10-20, so the latest complete week opens on Monday
 * 2026-10-12 and the eight baseline weeks run back to 2026-08-17.
 */
final class PublisherSignalsTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const COURIER = 'organization:marsh-courier';

	private const TERN = 'organization:tern-ledger';

	private const HERON = 'organization:heron-weekly';

	/** Monday 2026-10-19 00:00 UTC, when the current week opened. */
	private const THIS_WEEK = 1792368000;

	private const WEEK = 604800;

	private const READER = 6614;

	/** A curator holding the restricted tier. */
	private const HOLDER = 6615;

	/** The Tern Ledger's steady output: five items each week, the current one included. */
	private const STEADY = [ 0 => 5, 1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5 ];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::READER, 'marsh-reader', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed(
			self::entity( self::COURIER, 'Marsh Courier', [ 'domain' => [ 'marshcourier.example' ] ] ),
			self::entity( self::TERN, 'Tern Ledger', [ 'domain' => [ 'ternledger.example' ] ] ),
			self::entity( self::HERON, 'Heron Weekly', [ 'domain' => [ 'heronweekly.example' ] ] ),
			self::role( self::COURIER, 'client' ),
			self::role( self::TERN, 'client' ),
			self::role( self::HERON, 'former_client' )
		);
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_a_drop_is_half_the_baseline_mean(): void {
		$this->assertSame( 0.5, Graph_Query::SIGNAL_DROP );
	}

	/** Two items against a baseline mean of four is a drop, citing the week's items. */
	public function test_a_week_at_half_its_baseline_is_a_drop(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );

		$answer = $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] );

		$this->assertSame( [ [ 'publisher', 'drop', '2026-10-12', null, null, 2, 4.0 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'feed:marsh-courier-research-1-2', 'feed:marsh-courier-research-1-1' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
	}

	/** A baseline mean of exactly SIGNAL_MIN is the floor: one item against it drops. */
	public function test_a_mean_at_the_floor_drops(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 1, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3 ] );

		$this->assertSame( [ [ 'publisher', 'drop', '2026-10-12', null, null, 1, 3.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) ) );
	}

	/** A quiet publisher falling from two a week to one is not news. */
	public function test_a_drop_below_the_minimum_mean_is_none(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 1, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/**
	 * A week in which every client's output fell is collection failing: none of
	 * the clients drops.
	 */
	public function test_an_outage_week_drops_no_client(): void {
		$this->published( self::TERN, 'research', [ 1 => 1, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5 ] );
		$this->published( self::COURIER, 'research', [ 1 => 1, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/** An entity holding no open client role neither drops nor goes silent, and still bursts. */
	public function test_a_former_client_only_bursts(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::HERON, 'research', [ 2 => 6, 3 => 6, 4 => 6, 5 => 6, 6 => 6, 7 => 6, 8 => 6, 9 => 6 ] );
		$this->published( self::HERON, 'partnership', [ 1 => 3 ] );

		$this->assertSame( [ [ 'publisher', 'burst', '2026-10-12', 'kind', 'partnership', 3, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::HERON ] ) ) );
	}

	/** The first two empty weeks after a baseline at the floor are a silence, reported once. */
	public function test_silence_fires_on_the_first_empty_pair_alone(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );

		$answer = $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] );
		$this->assertSame( [ [ 'publisher', 'silent', '2026-10-12', null, null, 0, 3.5 ] ], self::signals( $answer ) );
		$this->assertSame( [], $answer['signals'][0]['items'] );

		Core::$now += self::WEEK;
		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/** A quiet publisher, its baseline mean below the floor, never goes silent. */
	public function test_a_quiet_baseline_never_goes_silent(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 3 => 1, 4 => 2, 5 => 1, 6 => 2, 7 => 1, 8 => 2, 9 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/** One empty week is a drop, not a silence. */
	public function test_one_empty_week_is_a_drop(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );

		$this->assertSame( [ [ 'publisher', 'drop', '2026-10-12', null, null, 0, 4.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) ) );
	}

	/** A kind the publisher never put out, three times in the week, is a burst naming that kind. */
	public function test_a_kind_spiking_in_its_own_output_is_a_burst(): void {
		$this->published( self::COURIER, 'research', [ 1 => 2, 2 => 2, 3 => 2, 4 => 2, 5 => 2, 6 => 2, 7 => 2, 8 => 2, 9 => 2 ] );
		$this->published( self::COURIER, 'partnership', [ 1 => 3 ] );

		$answer = $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] );

		$this->assertSame( [ [ 'publisher', 'burst', '2026-10-12', 'kind', 'partnership', 3, 0.0 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'feed:marsh-courier-partnership-1-3', 'feed:marsh-courier-partnership-1-2', 'feed:marsh-courier-partnership-1-1' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
	}

	/**
	 * `other` says nothing about an entity, so it never spikes on the about
	 * axis; what the entity published still bursts in it.
	 */
	public function test_other_never_signals_about_an_entity_and_still_bursts(): void {
		$this->about( 'other', [ 1 => 5, 9 => 1, 10 => 1 ] );
		$this->about( 'praise', [ 1 => 4, 9 => 1, 10 => 1 ] );
		$this->published( self::COURIER, 'other', [ 1 => 3, 9 => 1 ] );

		$this->assertSame(
			[ [ 'about', 'spike', '2026-10-12', 'kind', 'praise', 4, 0.125 ], [ 'publisher', 'burst', '2026-10-12', 'kind', 'other', 3, 0.125 ] ],
			self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) )
		);
	}

	/**
	 * A story's copy about the Courier before the window makes it history,
	 * counted there once, though its canonical inside the window is not
	 * about the Courier.
	 */
	public function test_a_story_first_about_an_entity_before_the_window_is_its_history(): void {
		$this->about( 'incident', [ 1 => 3 ] );
		$early = self::THIS_WEEK - 10 * self::WEEK + 3 * 86400;
		$later = self::THIS_WEEK - self::WEEK + 3 * 86400;
		$this->seed(
			self::item( 'github:incident-early', 'An early incident', 'github', 'public', $early ),
			self::extraction( 'github:incident-early', 'An incident.', 'incident', $early, [] ),
			self::link( 'link_asserted', 'github:incident-early', self::COURIER, 'subject', 'name', 1.0, $early ),
			self::item( 'github:incident-wire', 'A wire incident', 'github', 'public', $later + 600 ),
			self::extraction( 'github:incident-wire', 'An incident.', 'incident', $later + 600, [] ),
			self::link( 'link_asserted', 'github:incident-wire', self::COURIER, 'subject', 'name', 1.0, $later + 600 ),
			self::item( 'github:incident-canon', 'The incident', 'github', 'public', $later + 900 ),
			self::extraction( 'github:incident-canon', 'An incident.', 'incident', $later + 900, [] ),
			self::story( 'github:incident-canon', [ 'github:incident-early', 'github:incident-wire', 'github:incident-canon' ], $early )
		);

		$this->assertSame( [ [ 'about', 'spike', '2026-10-12', 'kind', 'incident', 3, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) ) );
	}

	/**
	 * A kind about an entity that was not counted before the oldest baseline
	 * week does not spike, a backfill of one in the baseline and five in the
	 * latest week included, while a kind new to its own output still bursts.
	 */
	public function test_a_kind_new_about_an_entity_never_spikes_and_still_bursts(): void {
		$this->about( 'incident', [ 1 => 5, 2 => 1 ] );
		$this->about( 'praise', [ 20 => 1 ] );
		$this->published( self::COURIER, 'research', [ 9 => 1 ] );
		$this->published( self::COURIER, 'partnership', [ 1 => 4 ] );

		$this->assertSame( [ [ 'publisher', 'burst', '2026-10-12', 'kind', 'partnership', 4, 0.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) ) );
	}

	/** `other` about an entity counts as no history of what is about it. */
	public function test_other_is_no_history_about_an_entity(): void {
		$this->about( 'praise', [ 1 => 3 ] );
		$this->about( 'other', [ 20 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/**
	 * A story the Courier's sister feeds carry counts once on either axis, as
	 * its canonical, which its signal cites.
	 */
	public function test_a_story_counts_once_on_both_axes(): void {
		$this->published( self::COURIER, 'research', [ 9 => 1 ] );
		$this->published( self::COURIER, 'partnership', [ 1 => 3 ] );
		$this->about( 'award', [ 1 => 3, 9 => 1, 10 => 1 ] );
		$this->assertSame( [ 'award', 'partnership' ], \array_column( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'], 'value' ) );

		$at = self::THIS_WEEK - self::WEEK + 2 * 86400;
		$this->seed(
			self::story( 'feed:marsh-courier-partnership-1-2', [ 'feed:marsh-courier-partnership-1-1', 'feed:marsh-courier-partnership-1-2', 'feed:marsh-courier-partnership-1-3' ], $at ),
			self::story( 'github:award-1-1', [ 'github:award-1-1', 'github:award-1-3' ], $at )
		);

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
		$this->about( 'award', [ 1 => 4 ] );
		$answer = $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] );
		$this->assertSame( [ [ 'about', 'spike', '2026-10-12', 'kind', 'award', 3, 0.125 ] ], self::signals( $answer ) );
		$this->assertSame( [ 'github:award-1-4', 'github:award-1-2', 'github:award-1-1' ], \array_column( $answer['signals'][0]['items'], 'item_key' ) );
	}

	/** A steady publisher answers no publisher signal. */
	public function test_a_steady_publisher_signals_nothing(): void {
		$this->published( self::COURIER, 'research', [ 1 => 3, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3 ] );
		$this->published( self::COURIER, 'hire', [ 1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1 ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] )['signals'] );
	}

	/** One more of a kind than its flat history is a burst. */
	public function test_one_above_a_flat_history_is_a_burst(): void {
		$this->published( self::COURIER, 'research', [ 1 => 4, 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3 ] );

		$this->assertSame( [ [ 'publisher', 'burst', '2026-10-12', 'kind', 'research', 4, 3.0 ] ], self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) ) );
	}

	/** An entity answers what is about it and what it published, each marked by its axis. */
	public function test_an_entity_answers_both_axes(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$this->about( 'complaint', [ 1 => 4, 9 => 1, 10 => 1 ] );

		$this->assertSame(
			[ [ 'about', 'spike', '2026-10-12', 'kind', 'complaint', 4, 0.125 ], [ 'publisher', 'drop', '2026-10-12', null, null, 2, 4.0 ] ],
			self::signals( $this->ci->dispatch( 'signals', [ '--entity=' . self::COURIER ] ) )
		);
	}

	/**
	 * A lens's signals leave out the publisher signal, its own entity's
	 * included.
	 */
	public function test_a_lens_answers_no_publisher_signal(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$this->ci->dispatch( 'save_lens', [ 'courier-desk', 'Courier desk', '--entity=' . self::COURIER ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--lens=courier-desk' ] )['signals'] );
		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--lens=courier-desk', '--entity=' . self::COURIER ] )['signals'] );
	}

	/** The watcher's digest reads an entity's publisher signals through `spikes()`. */
	public function test_spikes_answer_publisher_signals_after_an_instant(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$read = static fn ( float $after ): array => Intel_Graph_Row_Schema::read( static fn ( $r ): array => Graph_Query::spikes( $r, Graph_Query::graph( $r ), [ 'entity' => self::COURIER ], $after ) );

		$this->assertSame( [ 'drop' ], \array_column( $read( 0.0 ), 'signal' ) );
		$this->assertSame( [], $read( (float) self::THIS_WEEK ) );
	}

	/**
	 * A client's record heads with its status: when its open client role
	 * began, how many readable items about it are feature requests and
	 * complaints, when the newest item about it is dated, and the newest
	 * churn reports, by key, title, URL, source and day. What its own site
	 * published counts in none. Its signals are the signals read's.
	 */
	public function test_a_clients_record_answers_its_status(): void {
		$this->published( self::TERN, 'research', self::STEADY );
		$this->published( self::COURIER, 'research', [ 1 => 1, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$this->published( self::COURIER, 'complaint', [ 1 => 1 ] );
		$this->about( 'feature_request', [ 2 => 2 ] );
		$this->about( 'complaint', [ 3 => 3 ] );
		$this->about( 'praise', [ 4 => 1 ] );
		$this->about( 'churn_signal', [ 6 => 4 ] );
		$this->about( 'churned', [ 7 => 5 ] );

		$this->assertSame(
			[ 'client_since' => self::$jan_01, 'kinds' => [ 'churned' => 5, 'churn_signal' => 4, 'feature_request' => 2, 'complaint' => 3 ], 'last_item_ts' => self::THIS_WEEK - 2 * self::WEEK + 3 * 86400 + 2 * 60, 'churn_open' => true, 'churn_items' => \array_map( static fn ( int $n ): array => [ 'item_key' => "github:churned-7-{$n}", 'title' => "churned {$n} about the Courier", 'url' => self::url( "github:churned-7-{$n}" ), 'source' => 'github', 'day' => \gmdate( 'Y-m-d', self::THIS_WEEK - 7 * self::WEEK + 3 * 86400 ) ], [ 5, 4, 3, 2, 1 ] ) ],
			$this->ci->dispatch( 'entity', [ self::COURIER ] )['status']
		);
	}

	/** A client with nothing about it answers its start alone. */
	public function test_a_quiet_clients_status_answers_its_start_alone(): void {
		$this->assertSame(
			[ 'client_since' => self::$jan_01, 'kinds' => [ 'churned' => 0, 'churn_signal' => 0, 'feature_request' => 0, 'complaint' => 0 ], 'last_item_ts' => null, 'churn_open' => false, 'churn_items' => [] ],
			$this->ci->dispatch( 'entity', [ self::TERN ] )['status']
		);
	}

	/**
	 * A client's status names a churn report: an item about it extracted
	 * churned after its open client role began. One dated before the role
	 * began, as a client that left and signed again carries, names none, and
	 * neither does a churn signal.
	 */
	public function test_status_names_an_open_client_with_a_churn_report(): void {
		$kite = 'organization:kite-herald';
		$this->seed(
			self::entity( $kite, 'Kite Herald' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => $kite, 'role' => 'client', 'from_ts' => self::THIS_WEEK ], 'user:41', 'fixture-9' ) ]
		);
		$before = self::THIS_WEEK - 3 * self::WEEK;
		$this->seed( self::item( 'github:kite-left', 'The Kite Herald left', 'github', 'public', $before ), self::extraction( 'github:kite-left', 'It left.', 'churned', $before, [] ), self::link( 'link_asserted', 'github:kite-left', $kite, 'subject', 'name', 1.0, $before ) );
		$this->about( 'churn_signal', [ 3 => 2 ] );

		$this->assertFalse( $this->ci->dispatch( 'entity', [ self::COURIER ] )['status']['churn_open'], 'a churn signal is not a churn report' );
		$this->assertSame( 1, $this->ci->dispatch( 'entity', [ $kite ] )['status']['kinds']['churned'] );
		$this->assertFalse( $this->ci->dispatch( 'entity', [ $kite ] )['status']['churn_open'], 'a report before the role began names none' );

		$this->about( 'churned', [ 1 => 1 ] );

		$this->assertTrue( $this->ci->dispatch( 'entity', [ self::COURIER ] )['status']['churn_open'] );
	}

	/**
	 * A churn report rests only on an item the caller may read: one only a
	 * restricted item makes names none to a caller without the restricted
	 * tier, and names it to one holding it.
	 */
	public function test_a_restricted_churn_report_names_one_only_to_a_holder(): void {
		$at = self::THIS_WEEK - self::WEEK;
		$this->seed( self::item( 'github:courier-leaving', 'The Courier is leaving', 'github', 'restricted', $at ), self::extraction( 'github:courier-leaving', 'It is leaving.', 'churned', $at, [] ), self::link( 'link_asserted', 'github:courier-leaving', self::COURIER, 'subject', 'name', 1.0, $at ) );

		$this->assertFalse( $this->ci->dispatch( 'entity', [ self::COURIER ] )['status']['churn_open'] );

		self::add_user( self::HOLDER, 'marsh-analyst', 'manage_options', Intel_Tiers::CAPABILITY );
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->assertTrue( $this->ci->dispatch( 'entity', [ self::COURIER ] )['status']['churn_open'] );
	}

	/** An entity holding no client role, a former client's included, answers no status. */
	public function test_an_entity_holding_no_client_role_answers_no_status(): void {
		$this->about( 'complaint', [ 2 => 3 ] );

		$this->assertNull( $this->ci->dispatch( 'entity', [ self::HERON ] )['status'] );
	}

	/**
	 * An open role on an entity since 2026-01-01.
	 *
	 * @param string $entity The entity key.
	 * @param string $role   The role.
	 * @return list<array<string,mixed>>
	 */
	private static function role( string $entity, string $role ): array {
		return [ Intel_Event::make( 'role_asserted', [ 'entity_key' => $entity, 'role' => $role, 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ];
	}

	/**
	 * Seed items an entity's own site published, of one kind, per week back
	 * from the current one, each keyed by the entity's slug.
	 *
	 * @param string         $entity The publishing entity.
	 * @param string         $kind   Their kind.
	 * @param array<int,int> $counts Weeks back => how many.
	 */
	private function published( string $entity, string $kind, array $counts ): void {
		$slug = \explode( ':', $entity )[1];
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$key = "feed:{$slug}-{$kind}-{$back}-{$n}";
				$at  = self::THIS_WEEK - $back * self::WEEK + 2 * 86400 + $n * 60;
				$this->seed( self::item( $key, "{$kind} {$n} of week {$back}", 'feed', 'public', $at ), self::extraction( $key, "A {$kind}.", $kind, $at, [] ), self::link( 'link_asserted', $key, $entity, Intel_Event::PUBLISHER, 'identifier', 1.0, $at ) );
			}
		}
	}

	/**
	 * Seed items on other sites about the Courier, of one kind, per week back.
	 *
	 * @param string         $kind   Their kind.
	 * @param array<int,int> $counts Weeks back => how many.
	 */
	private function about( string $kind, array $counts ): void {
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$key = "github:{$kind}-{$back}-{$n}";
				$at  = self::THIS_WEEK - $back * self::WEEK + 3 * 86400 + $n * 60;
				$this->seed( self::item( $key, "{$kind} {$n} about the Courier", 'github', 'public', $at ), self::extraction( $key, "A {$kind}.", $kind, $at, [] ), self::link( 'link_asserted', $key, self::COURIER, 'subject', 'name', 1.0, $at ) );
			}
		}
	}

	/**
	 * Each signal as its axis, kind, bucket, axis counted, value, count and baseline.
	 *
	 * @param array<string,mixed> $answer A signals answer.
	 * @return list<list<mixed>>
	 */
	private static function signals( array $answer ): array {
		return \array_map( static fn ( array $s ): array => [ $s['axis'], $s['signal'], $s['bucket'], $s['by'], $s['value'], $s['count'], $s['baseline'] ], $answer['signals'] );
	}
}

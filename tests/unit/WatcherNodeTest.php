<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Stream_Writer;
use Newspack_Intelligence\Watcher_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The watcher: every WATCH_INTERVAL_S, as each watch's owner, it matches the
 * target's items newer than the watch's high-water mark, emails each new
 * match when the watch asks for it, and once a day at DIGEST_HOUR_UTC sends
 * each owner one digest across their digest watches. A watch's first pass
 * records its matches quietly; a failed send is retried, never re-matched.
 *
 * The fixture's tick is Tuesday 2026-10-20 12:00 UTC, so the digest hour
 * falls an hour on.
 */
final class WatcherNodeTest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const OWNER = 8201;

	private const HOLDER = 8203;

	private const MUTE = 8205;

	private const GONE = 8209;

	private const OWNER_MAIL = 'pier.reader@harbor.example';

	private const HOLDER_MAIL = 'pier.analyst@harbor.example';

	/** The owner's watch over the Gazette. */
	private const DESK = '3c5e7a9b1d2f4608';

	/** The holder's watch over the Gazette. */
	private const VAULT = '6a4b2c8d0e1f3957';

	/** The owner's watch over a collection. */
	private const SHELF = 'b7d9f1a3c5e70246';

	/** A gala the Gazette hosted, retrieved twenty minutes before the tick. */
	private const GALA = 'feed:gazette-gala';

	/** What the gala's own text says, which no email carries. */
	private const GALA_BODY = 'Donors pledged a record sum at the waterfront.';

	private Watcher_Node $watcher;

	private Capture_Sink_Node $out;

	/** @var list<array{to:string,subject:string,message:string,headers:list<string>}> */
	private array $mails = [];

	/** What the mail seam answers. */
	private bool $sends = true;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::OWNER, 'pier-reader', 'manage_options' );
		self::add_user( self::HOLDER, 'pier-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		self::add_user( self::MUTE, 'pier-guest', 'read' );
		$GLOBALS['_wp_test_user_emails'] = [
			self::OWNER  => self::OWNER_MAIL,
			self::HOLDER => self::HOLDER_MAIL,
			self::MUTE   => 'pier.guest@harbor.example',
		];
		$this->seed_world();
		$this->out          = new Capture_Sink_Node();
		$this->watcher      = $this->watcher();
		Watcher_Node::$wp_mail = function ( string $to, string $subject, string $message, array $headers ): bool {
			$this->mails[] = [ 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ];
			return $this->sends;
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_wp_actions']['set_current_user'] );
		Watcher_Node::$wp_mail             = null;
		Intel_Graph_Row_Schema::$read_rows = null;
		self::forget_users();
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** The cadence and the hour are constants on the node. */
	public function test_the_cadence_and_the_digest_hour(): void {
		$this->assertSame( [ 300, 13 ], [ Watcher_Node::WATCH_INTERVAL_S, Watcher_Node::DIGEST_HOUR_UTC ] );
		$this->assertTrue( $this->watcher->timer_is_active() );
		$this->assertSame( Watcher_Node::WATCH_INTERVAL_S * 1000, $this->read_private( $this->watcher, 'interval_ms' ) );
	}

	/**
	 * A watch's first pass reads back a day from its saving: an item that
	 * entered the target before the watch was saved is recorded quiet and
	 * emailed nothing, one entering after is news, and nothing older than
	 * the day is read at all. The mark lands on the newest.
	 */
	public function test_the_first_pass_records_what_came_before_the_save_quietly(): void {
		$saved = self::$oct_20 - 600;
		$this->seed(
			self::saved( self::DESK, self::OWNER, 'Gazette desk', 'entity', self::GAZETTE, false, true, $saved ),
			self::arrival( 'feed:gazette-eve', 'Harbor Gazette prepares a gala', $saved - 3600 ),
			self::arrival( self::GALA, 'Harbor Gazette hosts a gala', $saved + 300 )
		);

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame(
			[
				[ 'watch_matched', self::DESK, self::OWNER, 'feed:gazette-eve', (float) ( $saved - 3600 ), true ],
				[ 'watch_matched', self::DESK, self::OWNER, self::GALA, (float) ( $saved + 300 ), false ],
				[ 'watch_delivered', self::DESK, self::OWNER, 'scan', (float) ( $saved + 300 ) ],
				[ 'watch_delivered', self::DESK, self::OWNER, 'instant', self::$oct_20 + 60.0 ],
			],
			self::shapes( $this->emitted() )
		);
		$this->assertSame( 'machine:watcher@' . \NEWSPACK_INTELLIGENCE_VERSION, $this->emitted()[0]['actor'] );
		$this->assertSame( $saved - 3600, $this->emitted()[0]['retrieved_ts'] );
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Harbor Gazette hosts a gala', $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'prepares', $this->mails[0]['message'] );
		$this->project();

		$this->pass_at( self::$oct_20 + 400 );
		$this->assertSame( [ [], 1 ], [ $this->emitted(), \count( $this->mails ) ], 'a quiet match is owed no email' );
	}

	/**
	 * A first pass that finds nothing still marks the watch, at its saving,
	 * so the first item to arrive afterwards is news and nothing older is.
	 */
	public function test_a_first_pass_over_nothing_marks_the_saving(): void {
		$this->seed(
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'pier-reading', 'name' => 'Pier reading' ], 'user:pier-reader', 'fixture-9' ) ],
			self::saved( self::SHELF, self::OWNER, 'Pier shelf', 'collection', 'pier-reading', false, true )
		);
		$this->pass_at( self::$oct_20 + 60 );
		$this->assertSame( [ [ 'watch_delivered', self::SHELF, self::OWNER, 'scan', (float) self::$oct_13 ] ], self::shapes( $this->emitted() ) );
		$this->project();

		$this->seed( [ self::collected_at( 'pier-reading', 'linear:joint-grant', self::$oct_20 + 120 ), self::collected_at( 'pier-reading', 'feed:gazette-award', self::$oct_13 - 2 * 86400 ) ] );
		$this->pass_at( self::$oct_20 + 400 );

		$this->assertSame(
			[
				[ 'watch_matched', self::SHELF, self::OWNER, 'linear:joint-grant', (float) ( self::$oct_20 + 120 ), false ],
				[ 'watch_delivered', self::SHELF, self::OWNER, 'scan', (float) ( self::$oct_20 + 120 ) ],
				[ 'watch_delivered', self::SHELF, self::OWNER, 'instant', self::$oct_20 + 400.0 ],
			],
			self::shapes( $this->emitted() )
		);
		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( 'Gazette and Ledger share a grant', $this->mails[0]['message'] );
	}

	/**
	 * An item that joins the target after a later one moved the mark, as
	 * an extraction landing late does, is matched and emailed within a day
	 * of the mark; one joining further back is not read.
	 */
	public function test_a_late_joiner_within_a_day_is_matched(): void {
		$this->seed(
			[ Intel_Event::make( 'lens_saved', [ 'lens_key' => 'pier-launches', 'name' => 'Pier launches', 'description' => '', 'filters' => [ 'kinds' => [ 'launch' ] ] + Graph_Query::unfiltered() ], 'user:pier-reader', 'fixture-9' ) ],
			self::saved( self::DESK, self::OWNER, 'Launch desk', 'lens', 'pier-launches', false, true, self::$oct_20 - 7200 ),
			self::delivered( 'scan', self::DESK, self::OWNER, (float) ( self::$oct_20 - 7200 ) ),
			self::item( 'feed:pier-early', 'Pier opens a ferry line', 'feed', 'internal', self::$oct_20 - 3600 ),
			self::item( 'feed:pier-stale', 'Pier opened a dock', 'feed', 'internal', self::$oct_20 - 3 * 86400 ),
			self::item( 'feed:pier-late', 'Pier opens a market', 'feed', 'internal', self::$oct_20 - 1200 ),
			self::extraction( 'feed:pier-late', 'A launch.', 'launch', self::$oct_20 - 1200, [] )
		);
		$this->pass_at( self::$oct_20 + 60 );
		$this->assertSame( [ 'feed:pier-late' ], \array_column( \array_filter( $this->emitted(), static fn ( array $e ): bool => 'watch_matched' === $e['event'] ), 'item_key' ) );
		$this->project();

		$this->seed(
			self::extraction( 'feed:pier-early', 'A launch.', 'launch', self::$oct_20 - 3600, [] ),
			self::extraction( 'feed:pier-stale', 'A launch.', 'launch', self::$oct_20 - 3 * 86400, [] )
		);
		$this->pass_at( self::$oct_20 + 400 );

		$this->assertSame( [ [ 'watch_matched', self::DESK, self::OWNER, 'feed:pier-early', (float) ( self::$oct_20 - 3000 ), false ] ], \array_slice( self::shapes( $this->emitted() ), 0, 1 ) );
		$this->assertSame( 'watch_delivered', $this->emitted()[1]['event'] );
		$this->assertCount( 2, $this->emitted(), 'the mark stays where the later item put it' );
		$this->assertStringContainsString( 'Pier opens a ferry line', $this->mails[1]['message'] );
	}

	/**
	 * Saving a watch again, here over another target, starts it afresh:
	 * its next pass is a first pass, what came before the save quiet,
	 * whatever its old mark said.
	 */
	public function test_a_saved_watch_starts_afresh(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed(
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'pier-reading', 'name' => 'Pier reading' ], 'user:pier-reader', 'fixture-9' ) ],
			[ self::collected_at( 'pier-reading', 'github:ledger-launch', self::$oct_20 + 120 ) ],
			self::saved( self::DESK, self::OWNER, 'Pier desk', 'collection', 'pier-reading', false, true, self::$oct_20 + 200 )
		);

		$this->pass_at( self::$oct_20 + 400 );

		$this->assertSame(
			[
				[ 'watch_matched', self::DESK, self::OWNER, 'github:ledger-launch', (float) ( self::$oct_20 + 120 ), true ],
				[ 'watch_delivered', self::DESK, self::OWNER, 'scan', (float) ( self::$oct_20 + 200 ) ],
			],
			self::shapes( $this->emitted() )
		);
		$this->assertSame( [], $this->mails );
	}

	/**
	 * A new item is matched and emailed, plain text, to its owner's own
	 * address alone: its title, URL, source and day and a link to the watch,
	 * never its body.
	 */
	public function test_a_new_match_is_emailed_to_its_owner_alone(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame(
			[
				[ 'watch_matched', self::DESK, self::OWNER, self::GALA, (float) ( self::$oct_20 - 1200 ), false ],
				[ 'watch_delivered', self::DESK, self::OWNER, 'scan', (float) ( self::$oct_20 - 1200 ) ],
				[ 'watch_delivered', self::DESK, self::OWNER, 'instant', self::$oct_20 + 60.0 ],
			],
			self::shapes( $this->emitted() )
		);
		$this->assertCount( 1, $this->mails );
		[ 'to' => $to, 'subject' => $subject, 'message' => $message, 'headers' => $headers ] = $this->mails[0];
		$this->assertSame( self::OWNER_MAIL, $to );
		$this->assertSame( [ 'Content-Type: text/plain; charset=UTF-8' ], $headers );
		$this->assertSame( '[Test Site] Gazette desk: 1 new match', $subject );
		$this->assertStringContainsString( "Harbor Gazette hosts a gala\n  https://news.example/feed/gazette-gala\n  feed, 2026-10-20\n", $message );
		$this->assertStringContainsString( 'http://localhost/wp-admin/admin.php?page=newspack-intelligence&watch=' . self::DESK, $message );
		$this->assertStringNotContainsString( self::GALA_BODY, $message );
	}

	/** A watch that asks for no instant email records its matches and sends none. */
	public function test_a_watch_without_instant_email_records_and_sends_nothing(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', false );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [ 'watch_matched', 'watch_delivered' ], \array_column( $this->emitted(), 'event' ) );
		$this->assertSame( [], $this->mails );
	}

	/**
	 * A matched item is never matched again, and an item retrieved in the
	 * mark's own second but linked after it is matched once.
	 */
	public function test_the_high_water_mark_never_rematches(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );
		$this->pass_at( self::$oct_20 + 60 );
		$this->project();

		$this->pass_at( self::$oct_20 + 400 );
		$this->assertSame( [], $this->emitted() );

		$this->seed( self::arrival( 'feed:gazette-tide', 'Harbor Gazette charts the tide', self::$oct_20 - 1200 ) );
		$this->pass_at( self::$oct_20 + 800 );
		$this->assertSame( [ [ 'watch_matched', self::DESK, self::OWNER, 'feed:gazette-tide', (float) ( self::$oct_20 - 1200 ), false ] ], \array_slice( self::shapes( $this->emitted() ), 0, 1 ) );
		$this->assertCount( 2, $this->mails );
	}

	/**
	 * A failed send is logged and retried on the next pass from the matches
	 * already recorded, never matched again.
	 */
	public function test_a_failed_send_is_retried_and_never_rematched(): void {
		$stderr = $this->capture_stderr();
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );
		$this->sends = false;

		$this->pass_at( self::$oct_20 + 60 );
		$this->assertSame( [ 'watch_matched', 'watch_delivered' ], \array_column( $this->emitted(), 'event' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'a watch email did not send; retrying next pass: pier-reader ' . self::DESK ) );
		$this->project();

		$this->sends = true;
		$this->pass_at( self::$oct_20 + 400 );

		$this->assertSame( [ [ 'watch_delivered', self::DESK, self::OWNER, 'instant', self::$oct_20 + 400.0 ] ], self::shapes( $this->emitted() ) );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'Harbor Gazette hosts a gala', $this->mails[1]['message'] );
	}

	/** A match recorded before the watch asked for instant email is not owed one. */
	public function test_instant_email_owes_nothing_from_before_the_watch_was_saved(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true, self::$oct_20 - 1500 );
		$this->seed( self::matched( self::DESK, self::OWNER, 'feed:gazette-award', self::$oct_20 - 1600 ) );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertCount( 1, $this->mails );
		$this->assertStringNotContainsString( 'Harbor Gazette wins state award', $this->mails[0]['message'] );
	}

	/**
	 * Each pass reads as the watch's owner: a restricted item reaches a
	 * holder's watch and not a reader's, and a revoked grant stops it at the
	 * next pass, the owner's cached capabilities dropped first.
	 */
	public function test_each_watch_matches_what_its_owner_may_read_now(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->watching( self::VAULT, self::HOLDER, 'Gazette vault', true );
		$this->seed( self::arrival( 'hubspot:renewal-6620', 'Harbor Gazette renewal at risk', self::$oct_20 - 900, 'restricted' ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [ [ 'watch_matched', self::VAULT, self::HOLDER, 'hubspot:renewal-6620', (float) ( self::$oct_20 - 900 ), false ] ], \array_slice( self::shapes( \array_values( \array_filter( $this->emitted(), static fn ( array $e ): bool => 'watch_matched' === $e['event'] ) ) ), 0 ) );
		$this->assertSame( [ self::HOLDER_MAIL ], \array_column( $this->mails, 'to' ) );
		$this->assertContains( self::HOLDER, $GLOBALS['_wp_test_cleaned_users'] );
		$this->assertSame( 0, \get_current_user_id(), 'the pass signs out' );
	}

	/**
	 * A revoked grant stops a restricted match at the very next pass, and
	 * an email still owed for one recorded before the revoke is not sent.
	 */
	public function test_a_revoke_stops_a_restricted_match(): void {
		$this->watching( self::VAULT, self::HOLDER, 'Gazette vault', true );
		$this->seed( self::arrival( 'hubspot:renewal-6620', 'Harbor Gazette renewal at risk', self::$oct_20 - 900, 'restricted' ) );
		$this->sends = false;
		$this->pass_at( self::$oct_20 + 60 );
		$this->assertSame( [ 'watch_matched', 'watch_delivered' ], \array_column( $this->emitted(), 'event' ) );
		$this->project();
		$this->mails = [];
		$this->seed( self::arrival( 'hubspot:renewal-6621', 'Harbor Gazette renewal lapses', self::$oct_20 - 300, 'restricted' ) );
		unset( $GLOBALS['_wp_test_site_caps'][ \get_current_blog_id() ][ self::HOLDER ]['newspack_intelligence_restricted'] );
		$this->sends = true;

		$this->pass_at( self::$oct_20 + 400 );

		$this->assertSame( [], $this->emitted() );
		$this->assertSame( [], $this->mails );
	}

	/**
	 * Once a day at its hour each owner gets one digest across every digest
	 * watch, of the matches since the last; a second pass that day, a
	 * respawned watcher and a pass before the hour send none.
	 */
	public function test_the_digest_goes_once_a_day_at_its_hour_across_watches(): void {
		$this->seed( [ Intel_Event::make( 'collection_created', [ 'collection_key' => 'pier-reading', 'name' => 'Pier reading' ], 'user:pier-reader', 'fixture-9' ) ] );
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', false, self::$oct_13, true );
		$this->seed(
			self::saved( self::SHELF, self::OWNER, 'Pier shelf', 'collection', 'pier-reading', true, false, self::$oct_13 ),
			self::delivered( 'scan', self::SHELF, self::OWNER, 0.0 ),
			self::matched( self::DESK, self::OWNER, 'feed:gazette-award', self::$oct_20 - 7200 ),
			self::matched( self::SHELF, self::OWNER, 'linear:joint-grant', self::$oct_20 - 3600 )
		);

		$this->pass_at( self::$oct_20 + 1800 );
		$this->assertSame( [], $this->mails, 'before the hour' );

		$this->pass_at( self::$oct_20 + 3630 );
		$this->assertCount( 1, $this->mails );
		[ 'to' => $to, 'subject' => $subject, 'message' => $message ] = $this->mails[0];
		$this->assertSame( [ self::OWNER_MAIL, '[Test Site] Intelligence digest: 2 new matches' ], [ $to, $subject ] );
		$this->assertStringContainsString( "Gazette desk: 1 new match\n- Harbor Gazette wins state award\n", $message );
		$this->assertStringContainsString( "Pier shelf: 1 new match\n- Gazette and Ledger share a grant\n", $message );
		$this->assertStringContainsString( 'watch=' . self::SHELF, $message );
		$this->assertSame( [ [ 'watch_delivered', '', self::OWNER, 'digest', self::$oct_20 + 3630.0 ] ], self::shapes( $this->emitted() ) );
		$this->project();
		$this->seed( self::matched( self::DESK, self::OWNER, 'github:ledger-launch', self::$oct_20 + 3800 ) );

		$this->pass_at( self::$oct_20 + 4000 );
		$this->assertSame( [ 1, [] ], [ \count( $this->mails ), $this->emitted() ], 'once a day' );
		$respawned = $this->watcher( 'watcher-2' );
		Core::$now = self::$oct_20 + 4400;
		$respawned->fire_cb();
		$this->assertSame( [ 1, [] ], [ \count( $this->mails ), $this->emitted() ], 'a respawn reads the digest it sent' );

		$this->pass_at( self::$oct_20 + 86400 + 3630 );
		$this->assertCount( 2, $this->mails );
		$this->assertStringContainsString( 'Salem Ledger launches a newsletter tool', $this->mails[1]['message'] );
		$this->assertStringNotContainsString( 'Harbor Gazette wins state award', $this->mails[1]['message'] );
	}

	/** A digest names its lens's signals since the last digest, each against its baseline. */
	public function test_a_digest_names_its_lens_signals(): void {
		$week = 604800;
		for ( $back = 1; $back <= 9; $back++ ) {
			for ( $n = 1; $n <= ( 1 === $back ? 5 : 1 ); $n++ ) {
				$at = 1792368000 - $back * $week + 2 * 86400 + $n * 60;
				$this->seed( self::item( "feed:complaint-{$back}-{$n}", "Complaint {$n} of week {$back}", 'feed', 'internal', $at ), self::extraction( "feed:complaint-{$back}-{$n}", 'A complaint.', 'complaint', $at, [] ) );
			}
		}
		$this->seed(
			[ Intel_Event::make( 'lens_saved', [ 'lens_key' => 'pier-complaints', 'name' => 'Pier complaints', 'description' => '', 'filters' => [ 'kinds' => [ 'complaint' ] ] + Graph_Query::unfiltered() ], 'user:pier-reader', 'fixture-9' ) ],
			self::saved( self::DESK, self::OWNER, 'Complaint desk', 'lens', 'pier-complaints', true, false, self::$oct_13 ),
			self::delivered( 'scan', self::DESK, self::OWNER, (float) self::$oct_20 )
		);

		$this->pass_at( self::$oct_20 + 3630 );

		$this->assertCount( 1, $this->mails );
		$this->assertStringContainsString( "Complaint desk\nSignal: kind complaint, 5 from 2026-10-12, against a mean of 1.0\n- Complaint 5 of week 1\n", $this->mails[0]['message'] );
	}

	/** A digest with nothing in it sends no email and still marks the day. */
	public function test_an_empty_digest_sends_nothing_and_marks_the_day(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', false, self::$oct_13, true );

		$this->pass_at( self::$oct_20 + 3630 );

		$this->assertSame( [], $this->mails );
		$this->assertSame( [ [ 'watch_delivered', '', self::OWNER, 'digest', self::$oct_20 + 3630.0 ] ], self::shapes( $this->emitted() ) );
	}

	/** A watch whose owner no longer exists, or no longer reads, is skipped and logged. */
	public function test_an_owner_gone_or_without_read_is_skipped_and_logged(): void {
		$stderr = $this->capture_stderr();
		$this->seed(
			self::saved( 'aa00aa00aa00aa00', self::GONE, 'Orphan', 'entity', self::GAZETTE, false, true ),
			self::saved( 'bb11bb11bb11bb11', self::MUTE, 'Guest desk', 'entity', self::GAZETTE, false, true )
		);
		$GLOBALS['_current_user_id'] = 4401;

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [], $this->emitted() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'skipping the watches of a user who no longer exists: 8209' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'skipping the watches of a user who no longer reads: pier-guest' ) );
		$this->assertSame( 4401, \get_current_user_id(), 'the pass leaves the user it found' );
	}

	/** A watch whose target is gone is logged and the next still runs. */
	public function test_a_watch_whose_target_is_gone_is_logged_and_the_rest_run(): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::saved( 'cc22cc22cc22cc22', self::OWNER, 'Ghost desk', 'lens', 'ghost-desk', false, true ) );
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'skipping a watch this pass: pier-reader cc22cc22cc22cc22: no lens: ghost-desk' ) );
		$this->assertCount( 1, $this->mails );
	}

	/** An owner with no valid address is logged and sent nothing, and the email stays owed. */
	public function test_an_owner_without_an_address_is_logged_and_owed(): void {
		$stderr = $this->capture_stderr();
		$GLOBALS['_wp_test_user_emails'][ self::OWNER ] = 'not an address';
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [], $this->mails );
		$this->assertSame( [ 'watch_matched', 'watch_delivered' ], \array_column( $this->emitted(), 'event' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'a watch owner has no valid address; not emailing: pier-reader' ) );
	}

	/** A projection that will not open runs no watch and is logged. */
	public function test_no_projection_runs_nothing(): void {
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$stderr = $this->capture_stderr();
		$this->activate();

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [ [], [] ], [ $this->emitted(), $this->mails ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'watcher: no intel-graph to read the watches from; waiting: ' ) );
	}

	/** A digest watch whose lens is gone leaves its signals out, logged, and the digest still goes. */
	public function test_a_digest_leaves_out_the_signals_of_a_gone_lens(): void {
		$stderr = $this->capture_stderr();
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', false, self::$oct_13, true );
		$this->seed(
			self::saved( self::SHELF, self::OWNER, 'Ghost desk', 'lens', 'ghost-desk', true, false, self::$oct_13 ),
			self::delivered( 'scan', self::SHELF, self::OWNER, 0.0 ),
			self::matched( self::DESK, self::OWNER, 'feed:gazette-award', self::$oct_20 - 7200 )
		);

		$this->pass_at( self::$oct_20 + 3630 );

		$this->assertCount( 1, $this->mails );
		$this->assertCount( 1, self::stderr_lines( $stderr, "leaving a watch's signals out of a digest: pier-reader " . self::SHELF . ': no lens: ghost-desk' ) );
	}

	/** While the projector has not recorded the watches stream's end, a pass matches nothing. */
	public function test_a_pass_waits_while_the_projector_is_behind(): void {
		$stderr = $this->capture_stderr();
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );
		Stream_Writer::append( self::matched( self::DESK, self::OWNER, 'feed:gazette-award', self::$oct_20 - 60 ) );

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( [], $this->emitted() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'waiting for the projector to record the watches stream' ) );
		$this->pass_at( self::$oct_20 + 62 );
		$this->assertSame( [], $this->emitted(), 'never a pass behind the projector' );

		\array_map( 'unlink', \glob( Stream_Writer::stream_dir( Intel_Event::WATCHES ) . '/*' ) ?: [] );
		$this->pass_at( self::$oct_20 + 64 );
		$this->assertSame( [ 'watch_matched', 'watch_delivered', 'watch_delivered' ], \array_column( $this->emitted(), 'event' ), 'retried on the next tick' );
		$this->assertSame( Watcher_Node::WATCH_INTERVAL_S * 1000, $this->read_private( $this->watcher, 'interval_ms' ) );
		$this->out->captured = [];
		$this->pass_at( self::$oct_20 + 66 );
		$this->assertSame( [], $this->emitted(), 'a pass that ran waits its interval again' );
	}

	/**
	 * Which owner's sign-in throws, the first owner or the last, and the
	 * login, watch and address of the owner that still runs.
	 *
	 * @return array<string,array{0:int,1:string,2:string,3:string}>
	 */
	public static function throwing_sign_ins(): array {
		return [
			'the first owner'                  => [ self::OWNER, 'pier-reader', self::VAULT, self::HOLDER_MAIL ],
			'the last owner, none signed after' => [ self::HOLDER, 'pier-analyst', self::DESK, self::OWNER_MAIL ],
		];
	}

	/**
	 * A sign-in listener that throws for one owner leaves no one signed in
	 * as them: the user found before the pass is current after it, that
	 * owner is reported, and the other owner still runs.
	 *
	 * @param int    $thrower The owner whose sign-in throws.
	 * @param string $login   Their login.
	 * @param string $ran     The other owner's watch.
	 * @param string $mailed  The other owner's address.
	 */
	#[DataProvider( 'throwing_sign_ins' )]
	public function test_a_throwing_sign_in_is_reported_and_the_other_owner_runs( int $thrower, string $login, string $ran, string $mailed ): void {
		$stderr = $this->capture_stderr();
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$this->watching( self::VAULT, self::HOLDER, 'Gazette vault', true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );
		$GLOBALS['_current_user_id'] = 4401;
		\add_action(
			'set_current_user',
			static function () use ( $thrower ): void {
				if ( $thrower === \get_current_user_id() ) {
					throw new \RuntimeException( 'the session store is down' );
				}
			}
		);

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( 4401, \get_current_user_id() );
		$this->assertCount( 1, self::stderr_lines( $stderr, "skipping an owner's watches this pass: {$login}: the session store is down" ) );
		$this->assertSame( [ $ran ], \array_values( \array_unique( \array_column( $this->emitted(), 'watch_key' ) ) ) );
		$this->assertSame( [ $mailed ], \array_column( $this->mails, 'to' ) );
	}

	/** Each failing watch is logged under its own owner and key, not only the first. */
	public function test_each_failing_watch_is_logged(): void {
		$stderr = $this->capture_stderr();
		$this->seed(
			self::saved( 'cc22cc22cc22cc22', self::OWNER, 'Ghost desk', 'lens', 'ghost-desk', false, true ),
			self::saved( 'dd33dd33dd33dd33', self::OWNER, 'Phantom desk', 'lens', 'phantom-desk', false, true )
		);

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'skipping a watch this pass: pier-reader cc22cc22cc22cc22: no lens: ghost-desk' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'skipping a watch this pass: pier-reader dd33dd33dd33dd33: no lens: phantom-desk' ) );
	}

	/** One projection read per pass names the watches, and one per owner serves all of theirs. */
	public function test_a_pass_reads_once_per_owner(): void {
		$this->seed( [ Intel_Event::make( 'collection_created', [ 'collection_key' => 'pier-reading', 'name' => 'Pier reading' ], 'user:pier-reader', 'fixture-9' ) ] );
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true, self::$oct_13, true );
		$this->seed( self::saved( self::SHELF, self::OWNER, 'Pier shelf', 'collection', 'pier-reading', true, true ), self::delivered( 'scan', self::SHELF, self::OWNER, (float) self::$oct_13 ) );
		$this->watching( self::VAULT, self::HOLDER, 'Gazette vault', true, self::$oct_13, true );
		$this->seed( self::arrival( self::GALA, 'Harbor Gazette hosts a gala', self::$oct_20 - 1200 ) );
		$reads                             = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$reads ): mixed {
			++$reads;
			return \Newspack_Nodes\Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};

		$this->pass_at( self::$oct_20 + 3630 );

		$this->assertSame( 3, $reads );
		$this->assertCount( 4, $this->mails, 'two instant emails and two digests' );
	}

	/** A failed read of an owner's watches is logged and signs them out; a cooperative stop propagates. */
	public function test_a_failure_signs_out_and_a_stop_propagates(): void {
		$stderr = $this->capture_stderr();
		$this->watching( self::DESK, self::OWNER, 'Gazette desk', true );
		$reads                             = 0;
		$failure                           = new \PDOException( 'database disk image is malformed' );
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$reads, &$failure ): mixed {
			if ( 1 < ++$reads ) {
				throw $failure;
			}
			return \Newspack_Nodes\Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};

		$this->pass_at( self::$oct_20 + 60 );
		$this->assertCount( 1, self::stderr_lines( $stderr, "skipping an owner's watches this pass: pier-reader: database disk image is malformed" ) );
		$this->assertSame( 0, \get_current_user_id() );

		$reads   = 0;
		$failure = new Worker_Should_Stop( 'max_runtime 595 reached' );
		try {
			$this->pass_at( self::$oct_20 + 400 );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( 0, \get_current_user_id(), 'a stop signs out too' );
	}

	/**
	 * A title reaches an email as one line of text, and a URL only when it
	 * is http or https; the watch's name reaches the subject as one line.
	 */
	public function test_an_email_carries_text_and_only_web_urls(): void {
		$this->watching( self::DESK, self::OWNER, "Gazette\r\nBcc: desk@harbor.example", true );
		$this->seed(
			[ Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:gazette-trap', 'source' => 'feed', 'tier' => 'internal', 'url' => 'javascript:alert(1)', 'title' => "Gala\nBcc: trap@harbor.example", 'body' => '', 'item_ts' => self::$oct_20 - 1800, 'retrieved_ts' => self::$oct_20 - 1200 ], self::$machine, 'fixture-9' ) ],
			self::link( 'link_asserted', 'feed:gazette-trap', self::GAZETTE, 'subject', 'name', 1.0, self::$oct_20 - 1800 )
		);

		$this->pass_at( self::$oct_20 + 60 );

		$this->assertSame( '[Test Site] Gazette Bcc: desk@harbor.example: 1 new match', $this->mails[0]['subject'] );
		$this->assertStringContainsString( "- Gala Bcc: trap@harbor.example\n  feed, 2026-10-20\n", $this->mails[0]['message'] );
		$this->assertStringNotContainsString( 'javascript:', $this->mails[0]['message'] );
	}

	/** The watcher names the writer it addresses, and refuses a load without one. */
	public function test_the_writer_is_its_declared_destination(): void {
		$this->assertSame( [ 'intel:writer' ], $this->watcher->display_targets() );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: writer' );
		( new Watcher_Node() )->arguments( [] );
	}

	/** The composed graph runs a watcher on the Router's tick, writing to intel:writer. */
	public function test_the_composed_topology_runs_the_watcher(): void {
		Core::reset();
		Core::$var['partition'] = '0';
		Core::$var['topology']  = 'newspack-intelligence';
		$router                 = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include newspack-intelligence\n" );

		$watcher = Core::node( 'watcher' );
		$this->assertInstanceOf( Watcher_Node::class, $watcher );
		$this->assertSame( [ 'intel:writer' ], $watcher->display_targets() );
		$this->assertTrue( $watcher->timer_is_active() );
	}

	/**
	 * A watcher named `watcher`, writing to the capture sink.
	 *
	 * @param string $name Its name.
	 */
	private function watcher( string $name = 'watcher' ): Watcher_Node {
		$node = new Watcher_Node();
		$node->name( $name );
		$node->arguments( [ 'intel:writer' ] );
		$node->sink( $this->out );
		return $node;
	}

	/**
	 * Run a pass at a moment.
	 *
	 * @param float|int $at The tick clock.
	 */
	private function pass_at( float|int $at ): void {
		Core::$now = (float) $at;
		$this->watcher->fire_cb();
	}

	/** @return list<array<string,mixed>> Each event the watcher addressed to intel:writer since the last projection. */
	private function emitted(): array {
		$events = [];
		foreach ( $this->out->captured as $message ) {
			$this->assertSame( [ Message::TM_STRUCT, 'intel:writer' ], [ $message[ Message::TYPE ], $message[ Message::TO ] ] );
			$events[] = $message[ Message::VALUE ];
		}
		return $events;
	}

	/** Project what the watcher wrote, as the projector would, and forget it. */
	private function project(): void {
		$this->seed( $this->emitted() );
		$this->out->captured = [];
	}

	/**
	 * Each event as its kind, watch, owner and what it says.
	 *
	 * @param list<array<string,mixed>> $events The events.
	 * @return list<list<mixed>>
	 */
	private static function shapes( array $events ): array {
		return \array_map(
			static fn ( array $e ): array => 'watch_matched' === $e['event']
				? [ $e['event'], $e['watch_key'], $e['owner'], $e['item_key'], $e['at'], $e['quiet'] ]
				: [ $e['event'], $e['watch_key'], $e['owner'], $e['mode'], $e['through'] ],
			$events
		);
	}

	/**
	 * A watch past its first pass over the Gazette, its first pass's quiet
	 * matches and its mark at the newest of them.
	 *
	 * @param string    $key     The watch key.
	 * @param int       $owner   Its owner.
	 * @param string    $name    Its name.
	 * @param bool      $instant Whether it emails each new match.
	 * @param int|null  $saved   When it was saved, a week before the tick by default.
	 * @param bool      $digest  Whether it joins its owner's digest.
	 */
	private function watching( string $key, int $owner, string $name, bool $instant, ?int $saved = null, bool $digest = false ): void {
		$quiet = static function ( string $item, int $retrieved ) use ( $key, $owner ): array {
			$event       = Intel_Event::make( 'watch_matched', [ 'watch_key' => $key, 'owner' => $owner, 'item_key' => $item, 'at' => (float) $retrieved, 'retrieved_ts' => $retrieved, 'quiet' => true ], 'machine:watcher@9.4.1', 'fixture-9' );
			$event['ts'] = (float) self::$oct_13;
			return $event;
		};
		$this->seed(
			self::saved( $key, $owner, $name, 'entity', self::GAZETTE, $digest, $instant, $saved ?? self::$oct_13 ),
			[ $quiet( 'feed:gazette-award', self::$oct_06 + 600 ), $quiet( 'linear:joint-grant', self::$oct_01 + 600 ) ],
			self::delivered( 'scan', $key, $owner, (float) ( self::$oct_06 + 600 ) )
		);
	}

	/**
	 * A watch_saved, stamped when it was saved.
	 *
	 * @param string   $key     The watch key.
	 * @param int      $owner   Its owner.
	 * @param string   $name    Its name.
	 * @param string   $kind    Its target's kind.
	 * @param string   $target  Its target's key.
	 * @param bool     $digest  Its digest box.
	 * @param bool     $instant Its instant box.
	 * @param int|null $saved   When it was saved, a week before the tick by default.
	 * @return list<array<string,mixed>>
	 */
	private static function saved( string $key, int $owner, string $name, string $kind, string $target, bool $digest, bool $instant, ?int $saved = null ): array {
		$event       = Intel_Event::make( 'watch_saved', [ 'watch_key' => $key, 'owner' => $owner, 'name' => $name, 'target_kind' => $kind, 'target_key' => $target, 'digest' => $digest, 'instant' => $instant, 'inbox' => true ], 'user:pier-reader', 'fixture-9' );
		$event['ts'] = (float) ( $saved ?? self::$oct_13 );
		return [ $event ];
	}

	/**
	 * A match the watcher recorded at a moment, not quiet.
	 *
	 * @param string $key   The watch key.
	 * @param int    $owner Its owner.
	 * @param string $item  The item key.
	 * @param int    $at    When it was recorded.
	 * @return list<array<string,mixed>>
	 */
	private static function matched( string $key, int $owner, string $item, int $at ): array {
		$event       = Intel_Event::make( 'watch_matched', [ 'watch_key' => $key, 'owner' => $owner, 'item_key' => $item, 'at' => (float) $at, 'retrieved_ts' => self::$oct_13 + 600, 'quiet' => false ], 'machine:watcher@9.4.1', 'fixture-9' );
		$event['ts'] = (float) $at;
		return [ $event ];
	}

	/**
	 * How far the watcher has gone for a watch.
	 *
	 * @param string $mode    `scan`, `instant` or `digest`.
	 * @param string $key     The watch key, '' for an owner's digest.
	 * @param int    $owner   Its owner.
	 * @param float  $through How far.
	 * @return list<array<string,mixed>>
	 */
	private static function delivered( string $mode, string $key, int $owner, float $through ): array {
		return [ Intel_Event::make( 'watch_delivered', [ 'owner' => $owner, 'mode' => $mode, 'watch_key' => $key, 'through' => $through ], 'machine:watcher@9.4.1', 'fixture-9' ) ];
	}

	/**
	 * An item retrieved at a moment, ten minutes after its date, linked to
	 * the Gazette as its subject.
	 *
	 * @param string $key       The item key.
	 * @param string $title     Its title.
	 * @param int    $retrieved When it was retrieved.
	 * @param string $tier      Its tier.
	 * @return list<array<string,mixed>>
	 */
	private static function arrival( string $key, string $title, int $retrieved, string $tier = 'internal' ): array {
		return [
			Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => 'feed', 'tier' => $tier, 'url' => self::url( $key ), 'title' => $title, 'body' => self::GALA_BODY, 'item_ts' => $retrieved - 600, 'retrieved_ts' => $retrieved ], self::$machine, 'fixture-9' ),
			...self::link( 'link_asserted', $key, self::GAZETTE, 'subject', 'name', 1.0, $retrieved - 600 ),
		];
	}

	/**
	 * An item a person saved into a collection at a moment.
	 *
	 * @param string $collection The collection key.
	 * @param string $item       The item key.
	 * @param int    $at         When it was saved.
	 * @return array<string,mixed>
	 */
	private static function collected_at( string $collection, string $item, int $at ): array {
		$event       = Intel_Event::make( 'collected', [ 'collection_key' => $collection, 'item_key' => $item, 'url' => self::url( $item ), 'title' => 'Saved', 'tier' => 'internal' ], 'user:pier-reader', 'fixture-9' );
		$event['ts'] = (float) $at;
		return $event;
	}
}

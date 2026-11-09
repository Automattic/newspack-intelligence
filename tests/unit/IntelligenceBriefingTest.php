<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

/**
 * The `briefing` read command: what changed in the last seven UTC days, one
 * slice a call. `changes` ranks the window's items about any entity by
 * importance and groups them by the clients they are about, the rest in the
 * no-client group; `signals` answers every client's signals on both axes and
 * every lens's, through the one read `signals` answers by, in as many
 * statements for twenty clients as for two.
 *
 * The Plover Quillpost and the Sedgewick Inkfold are clients, the Wren
 * Cooperative a former client. The fixture's tick is Tuesday 2026-10-20
 * 12:00 UTC, so the window opens on 2026-10-14 and the latest complete
 * week on Monday 2026-10-12.
 */
final class IntelligenceBriefingTest extends TestCase {
	use Seeds_Intel_Graph;
	use Counts_Statements;
	use Signs_In_Users;

	private const PLOVER = 'organization:plover-quillpost';

	private const SEDGE = 'organization:sedgewick-inkfold';

	private const WREN = 'organization:wren-cooperative';

	private const PAYWALLS = 'topic:paywalls';

	/** Monday 2026-10-19 00:00 UTC, when the current week opened. */
	private const THIS_WEEK = 1792368000;

	private const WEEK = 604800;

	private const DAY = 86400;

	/** A client's own output falling: three a week for eight weeks, then none. */
	private const FALLING = [ 2 => 3, 3 => 3, 4 => 3, 5 => 3, 6 => 3, 7 => 3, 8 => 3, 9 => 3 ];

	private const READER = 5521;

	private const HOLDER = 5522;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::READER, 'plover-desk', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed(
			self::entity( self::PLOVER, 'Plover Quillpost', [ 'domain' => [ 'ploverquillpost.example' ] ] ),
			self::entity( self::SEDGE, 'Sedgewick Inkfold', [ 'domain' => [ 'sedgewickinkfold.example' ] ] ),
			self::entity( self::WREN, 'Wren Cooperative' ),
			self::entity( self::PAYWALLS, 'Paywalls' ),
			self::role( self::PLOVER, 'client' ),
			self::role( self::SEDGE, 'client' ),
			self::role( self::WREN, 'former_client' )
		);
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_the_briefing_is_a_read(): void {
		$this->assertSame( 'read', \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['briefing'] );
	}

	public function test_an_unknown_slice_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'what must be one of: changes, signals' );

		$this->ci->dispatch( 'briefing', [ 'watches' ] );
	}

	/**
	 * The window's items naming any entity as subject or mention, ranked by
	 * importance and grouped by the open clients each is about, each group in
	 * the order of its most important item and its items by importance, then
	 * the newest; an item about two clients sits once, under the first it
	 * names, so the page lists each item once, and one that is
	 * not about a client, only about a former client, in the no-client group. An
	 * item its own site published, one only tagged with a topic, one linked
	 * to nothing, one whose only link a curator rejected and one dated before
	 * the window are left out. Every group carries its role: `client` for a
	 * client's group, null for the no-client group.
	 */
	public function test_changes_rank_the_week_and_group_it_by_client(): void {
		$this->about( 'linear:plover-churn', self::PLOVER, 'subject', 'churn_signal', 9.0, 1 );
		$this->about( 'unthread:plover-call', self::PLOVER, 'subject', 'feature_request', 6.5, 2 );
		$this->about( 'github:plover-ask', self::PLOVER, 'mention', 'feature_request', 6.5, 4 );
		$this->about( 'slack:joint-migration', self::PLOVER, 'subject', 'launch', 5.5, 3 );
		$this->seed( self::link( 'link_asserted', 'slack:joint-migration', self::SEDGE, 'subject', 'name', 1.0, self::back( 3 ) ) );
		$this->about( 'p2:sedge-bug', self::SEDGE, 'subject', 'complaint', 8.25, 2 );
		$this->about( 'feed:wren-grant', self::WREN, 'subject', 'funding', 7.75, 3 );
		$this->about( 'feed:paywall-study', self::PAYWALLS, 'topic', 'research', 4.0, 6 );
		$this->about( 'feed:plover-tagged', self::PLOVER, 'topic', 'complaint', 9.6, 1 );
		$this->about( 'feed:plover-own-story', self::PLOVER, Intel_Event::PUBLISHER, 'other', 9.5, 1 );
		$this->about( 'slack:sedge-rumor', self::SEDGE, 'subject', 'churn_signal', 9.25, 1 );
		$this->seed( self::judged( 'link_rejected', 'slack:sedge-rumor', self::SEDGE, 'user:41' ) );
		$this->about( 'linear:plover-old-churn', self::PLOVER, 'subject', 'churn_signal', 9.9, 7 );
		$this->seed( self::item( 'feed:unlinked-scoop', 'An unlinked scoop', 'feed', 'public', self::back( 1 ) ), self::extraction( 'feed:unlinked-scoop', 'Nothing linked.', 'other', self::back( 1 ), [], 5.0, 9.75 ) );

		$answer = $this->ci->dispatch( 'briefing', [ 'changes' ] );

		$this->assertSame(
			[
				[ self::PLOVER, 'Plover Quillpost', [ 'linear:plover-churn', 'unthread:plover-call', 'github:plover-ask', 'slack:joint-migration' ] ],
				[ self::SEDGE, 'Sedgewick Inkfold', [ 'p2:sedge-bug' ] ],
				[ null, null, [ 'feed:wren-grant' ] ],
			],
			\array_map( static fn ( array $group ): array => [ $group['entity_key'], $group['name'], \array_column( $group['items'], 'item_key' ) ], $answer['groups'] )
		);
		$this->assertSame( \array_fill( 0, 3, [ 'entity_key', 'name', 'role', 'items' ] ), \array_map( 'array_keys', $answer['groups'] ) );
		$this->assertSame( [ 'client', 'client', null ], \array_column( $answer['groups'], 'role' ) );
		$this->assertSame( [ 6, true ], [ $answer['total'], $answer['available'] ] );
		$search = \array_column( $this->ci->dispatch( 'search', [ '--entity=' . self::SEDGE ] )['items'], null, 'item_key' );
		$this->assertSame( $search['p2:sedge-bug'], $answer['groups'][1]['items'][0] );
	}

	/**
	 * `changes` sums up what arrived since an instant inside the window: the
	 * items and the open clients they are about, and each kind that matters,
	 * in the order the briefing reads them, with its items and clients; a
	 * kind with nothing since is left out, and so is a kind the summary does
	 * not read, though its items count. `total` still counts the window.
	 */
	public function test_changes_answer_a_summary_of_counts(): void {
		$this->about( 'linear:plover-offboard', self::PLOVER, 'subject', 'churned', 7.0, 1 );
		$this->about( 'unthread:plover-exit-call', self::PLOVER, 'mention', 'churned', 6.0, 0 );
		$this->about( 'p2:sedge-offboard', self::SEDGE, 'subject', 'churned', 8.0, 1 );
		$this->about( 'github:wren-crash', self::WREN, 'subject', 'bug', 5.0, 1 );
		$this->about( 'slack:plover-relaunch', self::PLOVER, 'subject', 'launch', 4.0, 1 );
		$this->about( 'feed:sedge-study', self::SEDGE, 'mention', 'research', 3.0, 1 );
		$this->about( 'linear:sedge-wobble', self::SEDGE, 'subject', 'churn_signal', 9.0, 4 );
		$this->about( 'linear:plover-old-exit', self::PLOVER, 'subject', 'churned', 9.0, 8 );
		$since = self::back( 2 ) + 3600;

		$answer = $this->ci->dispatch( 'briefing', [ 'changes', '--since=' . $since ] );

		$this->assertSame(
			[
				'since'   => $since,
				'items'   => 6,
				'clients' => 2,
				'kinds'   => [
					[ 'kind' => 'churned', 'items' => 3, 'clients' => 2 ],
					[ 'kind' => 'bug', 'items' => 1, 'clients' => 0 ],
					[ 'kind' => 'launch', 'items' => 1, 'clients' => 1 ],
				],
			],
			$answer['summary']
		);
		$this->assertSame( 7, $answer['total'] );
	}

	/**
	 * With no instant named, or one before the window, the summary counts
	 * from the window's first instant.
	 */
	public function test_a_summary_counts_from_the_window_at_the_latest(): void {
		$this->about( 'linear:sedge-wobble', self::SEDGE, 'subject', 'churn_signal', 9.0, 5 );
		$this->about( 'p2:plover-offboard', self::PLOVER, 'subject', 'churned', 8.0, 6 );
		$this->about( 'linear:plover-old-exit', self::PLOVER, 'subject', 'churned', 9.0, 8 );
		$opens   = Graph_Query::last_days( 7 )[0];
		$summary = [
			'since'   => $opens,
			'items'   => 2,
			'clients' => 2,
			'kinds'   => [
				[ 'kind' => 'churned', 'items' => 1, 'clients' => 1 ],
				[ 'kind' => 'churn_signal', 'items' => 1, 'clients' => 1 ],
			],
		];

		$this->assertSame( $summary, $this->ci->dispatch( 'briefing', [ 'changes' ] )['summary'] );
		$this->assertSame( $summary, $this->ci->dispatch( 'briefing', [ 'changes', '--since=' . ( $opens - 5 * self::DAY ) ] )['summary'] );
	}

	/**
	 * A restricted item about a client counts in the summary only for a
	 * reader who holds the restricted tier.
	 */
	public function test_a_summary_counts_a_restricted_item_only_for_its_holder(): void {
		$key = 'hubspot:plover-renewal-lapse';
		$at  = self::back( 1 );
		$this->seed( self::item( $key, 'Plover renewal lapses', 'hubspot', 'restricted', $at ), self::extraction( $key, 'A churn.', 'churned', $at, [], 5.0, 8.0 ), self::link( 'link_asserted', $key, self::PLOVER, 'subject', 'name', 1.0, $at ) );
		$this->about( 'p2:sedge-offboard', self::SEDGE, 'subject', 'churned', 7.0, 1 );
		$since = self::back( 3 );

		$reader = $this->ci->dispatch( 'briefing', [ 'changes', '--since=' . $since ] );
		self::add_user( self::HOLDER, 'plover-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$holder = $this->ci->dispatch( 'briefing', [ 'changes', '--since=' . $since ] );

		$this->assertSame( [ 1, 1, [ [ 'kind' => 'churned', 'items' => 1, 'clients' => 1 ] ], 1 ], [ $reader['summary']['items'], $reader['summary']['clients'], $reader['summary']['kinds'], $reader['total'] ] );
		$this->assertSame( [ 2, 2, [ [ 'kind' => 'churned', 'items' => 2, 'clients' => 2 ] ], 2 ], [ $holder['summary']['items'], $holder['summary']['clients'], $holder['summary']['kinds'], $holder['total'] ] );
	}

	/** An instant to count from belongs to `changes`; `signals` refuses one. */
	public function test_signals_refuse_an_instant(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'since applies to changes' );

		$this->ci->dispatch( 'briefing', [ 'signals', '--since=1792000000' ] );
	}

	/** Items of equal importance rank the more relevant first, however old it is. */
	public function test_changes_break_an_importance_tie_by_relevance(): void {
		$this->about( 'p2:plover-plugin-bug', self::PLOVER, 'subject', 'complaint', 8.0, 1, 2.0 );
		$this->about( 'linear:plover-leaving', self::PLOVER, 'subject', 'churn_signal', 8.0, 4, 7.0 );

		$this->assertSame( [ 'linear:plover-leaving', 'p2:plover-plugin-bug' ], \array_column( $this->ci->dispatch( 'briefing', [ 'changes' ] )['groups'][0]['items'], 'item_key' ) );
	}

	/**
	 * An item about no open client but about an entity holding an open
	 * `newspack_product` role, under any key merged into it, sits in that
	 * role's group, in the order of its most important item; one about a
	 * client too sits under the client alone, and one about a product whose
	 * role ended sits in the no-client group.
	 */
	public function test_changes_group_newspack_work_under_its_role(): void {
		$product = 'product:tern-newsletters';
		$annex   = 'product:tern-mailer';
		$retired = 'product:tern-classic';
		$this->seed(
			self::entity( $product, 'Tern Newsletters' ),
			self::entity( $annex, 'Tern Mailer' ),
			self::entity( $retired, 'Tern Classic' ),
			self::merged( $annex, $product ),
			self::role( $product, 'newspack_product' ),
			[ Intel_Event::make( 'role_ended', [ 'entity_key' => $retired, 'role' => 'newspack_product', 'from_ts' => self::$jan_01, 'to_ts' => self::back( 30 ) ], 'user:41', 'fixture-9' ) ]
		);
		$this->about( 'github:tern-crash', $product, 'subject', 'incident', 8.5, 2 );
		$this->about( 'github:tern-mailer-bug', $annex, 'mention', 'complaint', 6.0, 1 );
		$this->about( 'linear:plover-tern-ask', self::PLOVER, 'subject', 'feature_request', 7.0, 3 );
		$this->seed( self::link( 'link_asserted', 'linear:plover-tern-ask', $product, 'mention', 'name', 1.0, self::back( 3 ) ) );
		$this->about( 'feed:tern-classic-sunset', $retired, 'subject', 'other', 9.0, 2 );

		$groups = $this->ci->dispatch( 'briefing', [ 'changes' ] )['groups'];

		$this->assertSame(
			[
				[ 'entity_key' => null, 'name' => null, 'role' => null, 'items' => [ 'feed:tern-classic-sunset' ] ],
				[ 'entity_key' => null, 'name' => null, 'role' => 'newspack_product', 'items' => [ 'github:tern-crash', 'github:tern-mailer-bug' ] ],
				[ 'entity_key' => self::PLOVER, 'name' => 'Plover Quillpost', 'role' => 'client', 'items' => [ 'linear:plover-tern-ask' ] ],
			],
			\array_map( static fn ( array $group ): array => \array_merge( $group, [ 'items' => \array_column( $group['items'], 'item_key' ) ] ), $groups )
		);
	}

	/**
	 * An item about more than three open clients is filed once: in the
	 * `newspack_product` group when it is about a holder of that role, else
	 * in the group of role null, its entities still naming every client. An
	 * item about three sits once, under the first of them it names.
	 */
	public function test_an_item_about_many_clients_is_filed_once(): void {
		$dunlin  = 'organization:dunlin-dispatch';
		$godwit  = 'organization:godwit-gazette';
		$product = 'product:tern-newsletters';
		$this->seed(
			self::entity( $dunlin, 'Dunlin Dispatch' ),
			self::entity( $godwit, 'Godwit Gazette' ),
			self::entity( $product, 'Tern Newsletters' ),
			self::role( $dunlin, 'client' ),
			self::role( $godwit, 'client' ),
			self::role( $product, 'newspack_product' )
		);
		$this->about( 'p2:election-roundup', self::PLOVER, 'subject', 'launch', 8.5, 2 );
		$this->about( 'p2:election-digest', self::PLOVER, 'subject', 'launch', 7.5, 2 );
		$this->about( 'slack:trio-migration', self::PLOVER, 'subject', 'launch', 6.5, 3 );
		foreach ( [ self::SEDGE, $dunlin, $godwit, $product ] as $entity ) {
			$this->seed( self::link( 'link_asserted', 'p2:election-roundup', $entity, 'mention', 'name', 1.0, self::back( 2 ) ) );
		}
		foreach ( [ self::SEDGE, $dunlin, $godwit ] as $entity ) {
			$this->seed( self::link( 'link_asserted', 'p2:election-digest', $entity, 'mention', 'name', 1.0, self::back( 2 ) ) );
		}
		foreach ( [ self::SEDGE, $dunlin ] as $entity ) {
			$this->seed( self::link( 'link_asserted', 'slack:trio-migration', $entity, 'mention', 'name', 1.0, self::back( 3 ) ) );
		}

		$groups = $this->ci->dispatch( 'briefing', [ 'changes' ] )['groups'];

		$this->assertSame(
			[
				[ null, 'newspack_product', [ 'p2:election-roundup' ] ],
				[ null, null, [ 'p2:election-digest' ] ],
				[ self::PLOVER, 'client', [ 'slack:trio-migration' ] ],
			],
			\array_map( static fn ( array $group ): array => [ $group['entity_key'], $group['role'], \array_column( $group['items'], 'item_key' ) ], $groups )
		);
		$named = \array_column( $groups[0]['items'][0]['entities'], 'entity_key' );
		\sort( $named );
		$this->assertSame( [ $dunlin, $godwit, self::PLOVER, self::SEDGE, $product ], $named );
	}

	/** The window opens at the first of the last seven UTC days, today the last. */
	public function test_changes_open_at_the_windows_first_day(): void {
		$this->about( 'linear:plover-first-day', self::PLOVER, 'subject', 'complaint', 6.0, 0 );
		$this->seed( self::item( 'linear:plover-edge', 'Plover edge', 'linear', 'public', self::$oct_20 - 6 * self::DAY - 12 * 3600 + 60 ), self::link( 'link_asserted', 'linear:plover-edge', self::PLOVER, 'subject', 'name', 1.0, self::$oct_20 ) );
		$this->seed( self::item( 'linear:plover-eve', 'Plover eve', 'linear', 'public', self::$oct_20 - 6 * self::DAY - 12 * 3600 - 60 ), self::link( 'link_asserted', 'linear:plover-eve', self::PLOVER, 'subject', 'name', 1.0, self::$oct_20 ) );

		$this->assertSame( [ 'linear:plover-first-day', 'linear:plover-edge' ], \array_column( $this->ci->dispatch( 'briefing', [ 'changes' ] )['groups'][0]['items'], 'item_key' ) );
	}

	/** The changes answer the CHANGES most important items, and count every one. */
	public function test_changes_are_capped(): void {
		for ( $n = 0; $n <= Graph_Query::DEFAULT_LIMIT; $n++ ) {
			$this->about( "feed:wren-note-{$n}", self::WREN, 'subject', 'other', 9.0 - $n / 10, 2 );
		}

		$answer = $this->ci->dispatch( 'briefing', [ 'changes' ] );

		$this->assertSame( Graph_Query::DEFAULT_LIMIT + 1, $answer['total'] );
		$keys = \array_column( $answer['groups'][0]['items'], 'item_key' );
		$this->assertCount( Graph_Query::DEFAULT_LIMIT, $keys );
		$this->assertSame( [ 'feed:wren-note-0', 'feed:wren-note-49' ], [ $keys[0], $keys[ Graph_Query::DEFAULT_LIMIT - 1 ] ] );
	}

	/**
	 * Every client with a signal, by name, its signals on both axes, and
	 * every lens with a signal, by name; a client signaling nothing is left
	 * out.
	 */
	public function test_signals_answer_each_client_and_each_lens(): void {
		$this->published( self::SEDGE, [ 0 => 5, 1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5 ] );
		$this->published( self::PLOVER, [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$this->complaints( self::PLOVER, [ 1 => 4, 9 => 1, 10 => 1 ] );
		$this->seed( self::lens( 'complaint-desk', 'Complaint desk', [ 'kinds' => [ 'complaint' ] ] ) );

		$answer = $this->ci->dispatch( 'briefing', [ 'signals' ] );

		$this->assertSame(
			[ [ self::PLOVER, 'Plover Quillpost', [ [ 'about', 'spike', '2026-10-12', 'complaint', 4, 0.125 ], [ 'publisher', 'drop', '2026-10-12', null, 2, 4.0 ] ] ] ],
			\array_map( static fn ( array $client ): array => [ $client['entity_key'], $client['name'], self::signals( $client['signals'] ) ], $answer['clients'] )
		);
		$this->assertSame(
			[ [ 'complaint-desk', 'Complaint desk', [ [ 'about', 'spike', '2026-10-12', 'complaint', 4, 0.125 ] ] ] ],
			\array_map( static fn ( array $lens ): array => [ $lens['lens_key'], $lens['name'], self::signals( $lens['signals'] ) ], $answer['lenses'] )
		);
		$this->assertTrue( $answer['available'] );
		$this->assertSame( $this->ci->dispatch( 'signals', [ '--entity=' . self::PLOVER ] )['signals'], $answer['clients'][0]['signals'] );
	}

	/** A week in which every client's output fell drops no client, as the signals read decides. */
	public function test_an_outage_week_drops_no_client(): void {
		$this->published( self::SEDGE, [ 1 => 1, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5 ] );
		$this->published( self::PLOVER, [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );

		$this->assertSame( [], $this->ci->dispatch( 'briefing', [ 'signals' ] )['clients'] );
	}

	/** A lens bucketing by month signals only when its month closed inside the window. */
	public function test_a_signal_whose_bucket_closed_before_the_window_is_left_out(): void {
		$this->seed( self::lens( 'monthly-desk', 'Monthly desk', [ 'kinds' => [ 'complaint' ], 'bucket' => 'month' ] ) );
		$this->complaints( self::WREN, [ 4 => 4, 39 => 1, 45 => 1 ] );

		$this->assertSame( 'spike', $this->ci->dispatch( 'signals', [ '--lens=monthly-desk' ] )['signals'][0]['signal'] );
		$this->assertSame( [], $this->ci->dispatch( 'briefing', [ 'signals' ] )['lenses'] );
	}

	/**
	 * The signals read every client in the same statements, the read of
	 * every client's output among them once however many clients fell: a
	 * roster of twenty, four of them falling, costs what a roster of two,
	 * one falling, does. The Sedgewick Inkfold's steady output keeps every
	 * client's together from falling, so each fall is the client's own.
	 */
	public function test_signals_read_as_many_statements_for_twenty_clients_as_for_two(): void {
		$this->published( self::SEDGE, \array_fill( 1, 9, 14 ) );
		$this->published( self::PLOVER, self::FALLING );
		$two = $this->statements( 'signals' );
		$this->assertSame( [ 'drop' ], $this->drops() );

		for ( $n = 1; $n <= 18; $n++ ) {
			$key = "organization:marram-desk-{$n}";
			$this->seed( self::entity( $key, "Marram Desk {$n}" ), self::role( $key, 'client' ) );
			if ( $n <= 3 ) {
				$this->published( $key, self::FALLING );
			}
		}

		$this->assertSame( \array_fill( 0, 4, 'drop' ), $this->drops() );
		$this->assertSame( $two, $this->statements( 'signals' ) );
	}

	/**
	 * A client's signals count what is linked under a key merged into it,
	 * on both axes.
	 */
	public function test_a_clients_signals_count_a_merged_keys_items(): void {
		$annex = 'organization:plover-annex';
		$this->seed( self::entity( $annex, 'Plover Annex' ), self::merged( $annex, self::PLOVER ) );
		$this->published( self::SEDGE, [ 0 => 5, 1 => 5, 2 => 5, 3 => 5, 4 => 5, 5 => 5, 6 => 5, 7 => 5, 8 => 5, 9 => 5 ] );
		$this->published( $annex, [ 1 => 2, 2 => 4, 3 => 4, 4 => 4, 5 => 4, 6 => 4, 7 => 4, 8 => 4, 9 => 4 ] );
		$this->complaints( $annex, [ 1 => 4, 9 => 1, 10 => 1 ] );

		$this->assertSame(
			[ [ self::PLOVER, [ [ 'about', 'spike', '2026-10-12', 'complaint', 4, 0.125 ], [ 'publisher', 'drop', '2026-10-12', null, 2, 4.0 ] ] ] ],
			\array_map( static fn ( array $client ): array => [ $client['entity_key'], self::signals( $client['signals'] ) ], $this->ci->dispatch( 'briefing', [ 'signals' ] )['clients'] )
		);
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
	 * Noon a number of days before the fixture's tick.
	 *
	 * @param int $days Days back.
	 */
	private static function back( int $days ): int {
		return self::$oct_20 - $days * self::DAY;
	}

	/**
	 * Seed an item linked to one entity, extracted with a kind and an importance.
	 *
	 * @param string $key        The item key.
	 * @param string $entity     The entity it links.
	 * @param string $role       The link's role.
	 * @param string $kind       Its kind.
	 * @param float  $importance Its importance.
	 * @param int    $days       How many days before the tick it is dated.
	 * @param float  $relevance  Its relevance.
	 */
	private function about( string $key, string $entity, string $role, string $kind, float $importance, int $days, float $relevance = 5.0 ): void {
		$at = self::back( $days );
		$this->seed( self::item( $key, "Item {$key}", \explode( ':', $key )[0], 'public', $at ), self::extraction( $key, "A {$kind}.", $kind, $at, [], $relevance, $importance ), self::link( 'link_asserted', $key, $entity, $role, 'name', 1.0, $at ) );
	}

	/**
	 * Seed research items an entity's own site published, per week back.
	 *
	 * @param string         $entity The publishing entity.
	 * @param array<int,int> $counts Weeks back => how many.
	 */
	private function published( string $entity, array $counts ): void {
		$slug = \explode( ':', $entity )[1];
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$key = "feed:{$slug}-{$back}-{$n}";
				$at  = self::THIS_WEEK - $back * self::WEEK + 2 * self::DAY + $n * 60;
				$this->seed( self::item( $key, "Story {$n} of week {$back}", 'feed', 'public', $at ), self::extraction( $key, 'A story.', 'research', $at, [], 5.0, 2.0 ), self::link( 'link_asserted', $key, $entity, Intel_Event::PUBLISHER, 'identifier', 1.0, $at ) );
			}
		}
	}

	/**
	 * Seed complaints about an entity on other sites, per week back.
	 *
	 * @param string         $entity The entity.
	 * @param array<int,int> $counts Weeks back => how many.
	 */
	private function complaints( string $entity, array $counts ): void {
		foreach ( $counts as $back => $count ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$key = "github:complaint-{$back}-{$n}";
				$at  = self::THIS_WEEK - $back * self::WEEK + 3 * self::DAY + $n * 60;
				$this->seed( self::item( $key, "Complaint {$n} of week {$back}", 'github', 'public', $at ), self::extraction( $key, 'A complaint.', 'complaint', $at, [], 5.0, 6.0 ), self::link( 'link_asserted', $key, $entity, 'subject', 'name', 1.0, $at ) );
			}
		}
	}

	/**
	 * A saved lens: its filters over none set.
	 *
	 * @param string              $key     The lens key.
	 * @param string              $name    Its name.
	 * @param array<string,mixed> $filters The filters it sets.
	 * @return list<array<string,mixed>>
	 */
	private static function lens( string $key, string $name, array $filters ): array {
		return [ Intel_Event::make( 'lens_saved', [ 'lens_key' => $key, 'name' => $name, 'description' => '', 'filters' => $filters + Graph_Query::unfiltered() ], 'user:41', 'fixture-9' ) ];
	}

	/**
	 * Each publisher signal the briefing's clients answer, by its kind.
	 *
	 * @return list<mixed>
	 */
	private function drops(): array {
		$signals = \array_merge( ...\array_column( $this->ci->dispatch( 'briefing', [ 'signals' ] )['clients'], 'signals' ) );
		return \array_column( \array_filter( $signals, static fn ( array $s ): bool => 'publisher' === $s['axis'] ), 'signal' );
	}

	/**
	 * How many statements one briefing slice prepares, each counted on a
	 * connection to partition 0's file.
	 *
	 * @param string $what The slice.
	 */
	private function statements( string $what ): int {
		return $this->statements_of( fn () => $this->ci->dispatch( 'briefing', [ $what ] ) );
	}

	/**
	 * Each signal as its axis, kind, bucket, value, count and baseline.
	 *
	 * @param list<array<string,mixed>> $signals Signals.
	 * @return list<list<mixed>>
	 */
	private static function signals( array $signals ): array {
		return \array_map( static fn ( array $s ): array => [ $s['axis'], $s['signal'], $s['bucket'], $s['value'], $s['count'], $s['baseline'] ], $signals );
	}
}


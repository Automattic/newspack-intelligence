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
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

/**
 * Theme groups: `search --group=theme` answers the items it reads as one
 * group per theme, each story counted once, ranked by the severity of the
 * group's kind in the effective kinds list, then the open clients its items
 * name as their subject, then its newest story, then its theme; and `trends`
 * counts by theme, each row naming those clients.
 *
 * The support desk lists its kinds most severe first: complaint, incident,
 * feature_request, churn_signal, praise.
 */
final class LensThemeGroupsTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;
	use Counts_Statements;

	private const ACME = 'organization:acme-quill';

	private const HARBOR = 'organization:harbor-ledger';

	private const PIER = 'organization:pier-post';

	private const SALT = 'organization:salt-works';

	private const EMAIL = 'site blocked from sending email';

	private const BOTS = 'bot registrations';

	private const BILLING = 'billing dispute';

	private const SENDER = 'newsletter sender rejected';

	private const STORAGE = 'storage extension';

	private const READER = 7311;

	private const DAY = 86400;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::READER, 'cobalt-reader', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed_desk();
		$this->save( 'support-desk', '--kind=complaint', '--kind=incident', '--kind=feature_request', '--kind=churn_signal', '--kind=praise', '--by=theme', '--bucket=month' );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/**
	 * Grouping is opt-in: a lens's search answers items as it always has,
	 * and with `group` its groups, counted by `total`.
	 */
	public function test_a_lens_groups_only_when_asked(): void {
		$items = $this->ci->dispatch( 'search', [ '--lens=support-desk' ] );
		$this->assertSame( [ 'items', 'total', 'available', 'sort' ], \array_keys( $items ) );
		$this->assertSame( 11, $items['total'] );

		$groups = $this->ci->dispatch( 'search', [ '--lens=support-desk', '--group=theme' ] );
		$this->assertSame( [ 'groups', 'total', 'available' ], \array_keys( $groups ) );
		$this->assertSame( 6, $groups['total'] );
	}

	/**
	 * Groups rank by their kind's place in the lens's kinds, then by the
	 * most important story they hold, then by open clients, most first,
	 * then by newest story; an item with no theme is a group of its own,
	 * its theme ''. So a group holding a 7 outranks one holding only 5s
	 * however many clients the 5s name.
	 */
	public function test_groups_rank_by_severity_then_importance_then_clients_then_recency(): void {
		$groups = $this->grouped( '--lens=support-desk' );

		$this->assertSame(
			[
				[ self::EMAIL, 'complaint', 3, 1 ],
				[ self::BILLING, 'complaint', 1, 1 ],
				[ self::SENDER, 'complaint', 1, 0 ],
				[ self::BOTS, 'incident', 2, 1 ],
				[ self::STORAGE, 'feature_request', 1, 1 ],
				[ '', 'praise', 1, 1 ],
			],
			\array_map( static fn ( array $g ): array => [ $g['theme'], $g['kind'], $g['count'], \count( $g['clients'] ) ], $groups['groups'] )
		);
	}

	/**
	 * A group answers its theme, its most severe kind, its count of stories,
	 * its open clients by name, its newest story's time and its stories as
	 * search answers them, the most important first: a syndicated copy
	 * counts once, as its canonical, a copy with no theme makes no group of
	 * its own, and a former client is not among its clients.
	 */
	public function test_a_group_answers_its_stories_and_clients(): void {
		$bots     = \array_column( $this->grouped( '--lens=support-desk' )['groups'], null, 'theme' )[ self::BOTS ];
		$by_hand  = $this->ci->dispatch( 'search', [ '--source=linear', '--kind=incident', '--sort=importance' ] )['items'];
		$expected = [
			'theme'     => self::BOTS,
			'kind'      => 'incident',
			'count'     => 2,
			'clients'   => [ [ 'entity_key' => self::HARBOR, 'name' => 'Harbor Ledger' ] ],
			'newest_ts' => self::$oct_01 + 15 * self::DAY,
			'items'     => $by_hand,
		];

		$this->assertSame( [ 'linear:SUP-4', 'linear:SUP-5' ], \array_column( $by_hand, 'item_key' ) );
		$this->assertSame( $expected, $bots );
	}

	/** A group's clients list by name, and a group's kind is its most severe item's. */
	public function test_a_groups_kind_is_its_most_severe(): void {
		$email = \array_column( $this->grouped( '--lens=support-desk' )['groups'], null, 'theme' )[ self::EMAIL ];

		$this->assertSame( 'complaint', $email['kind'] );
		$this->assertSame( [ [ 'entity_key' => self::ACME, 'name' => 'Acme Quill' ] ], $email['clients'] );
		$this->assertSame( [ 'linear:SUP-2', 'linear:SUP-3', 'linear:SUP-1' ], \array_column( $email['items'], 'item_key' ) );
	}

	/** A call's kinds replace the lens's, so their order sets the severity. */
	public function test_a_calls_kinds_set_the_severity(): void {
		$groups = $this->grouped( '--lens=support-desk', '--kind=praise', '--kind=incident' );

		$this->assertSame( [ [ '', 'praise' ], [ self::BOTS, 'incident' ] ], \array_map( static fn ( array $g ): array => [ $g['theme'], $g['kind'] ], $groups['groups'] ) );
	}

	/**
	 * With no kinds every kind ranks alike, a group's kind its newest
	 * item's, and groups level on importance, clients and recency order by
	 * theme.
	 */
	public function test_with_no_kinds_groups_rank_by_importance_then_clients_then_recency_then_theme(): void {
		$at = self::$oct_20 - 4 * self::DAY;
		$this->seed(
			self::item( 'news:tie-b', 'Tide report b', 'news', 'internal', $at ),
			self::extraction( 'news:tie-b', 'A tide.', 'research', $at, [], 5.0, 5.0, 'tide reports' ),
			self::item( 'news:tie-a', 'Ferry report a', 'news', 'internal', $at ),
			self::extraction( 'news:tie-a', 'A ferry.', 'research', $at, [], 5.0, 5.0, 'ferry reports' ),
			self::item( 'news:tie-c', 'Tide report c', 'news', 'internal', $at - self::DAY ),
			self::extraction( 'news:tie-c', 'A tide prize.', 'award', $at - self::DAY, [], 5.0, 5.0, 'tide reports' )
		);
		$this->save( 'news-desk', '--source=news' );

		$news = $this->grouped( '--lens=news-desk' )['groups'];
		$this->assertSame( [ [ 'ferry reports', 'research' ], [ 'tide reports', 'research' ] ], \array_map( static fn ( array $g ): array => [ $g['theme'], $g['kind'] ], $news ) );
		$this->assertSame( [ 'complaint', 'incident', 'praise', 'complaint', 'feature_request', 'complaint' ], \array_column( $this->grouped( '--source=linear' )['groups'], 'kind' ) );
	}

	/** A group cites its five newest stories, the most important of them first. */
	public function test_a_group_cites_its_five_newest_by_importance(): void {
		for ( $n = 1; $n <= 7; $n++ ) {
			$at = self::$oct_01 + $n * self::DAY;
			$this->seed(
				self::item( "news:relay-{$n}", "Relay down {$n}", 'news', 'internal', $at ),
				self::extraction( "news:relay-{$n}", 'The relay is down.', 'incident', $at, [], 5.0, (float) ( $n % 4 ), 'relay outage' )
			);
		}
		$this->save( 'relay-desk', '--source=news' );

		$relay = $this->grouped( '--lens=relay-desk' )['groups'][0];
		$this->assertSame( [ 'relay outage', 7 ], [ $relay['theme'], $relay['count'] ] );
		$this->assertSame( [ 'news:relay-7', 'news:relay-3', 'news:relay-6', 'news:relay-5', 'news:relay-4' ], \array_column( $relay['items'], 'item_key' ) );
	}

	/**
	 * Groups page twenty at a time unless the call names a limit, `limit`
	 * and `offset` counting groups and `total` every group.
	 */
	public function test_groups_page_by_group(): void {
		$page = $this->grouped( '--lens=support-desk', '--limit=2', '--offset=2' );
		$this->assertSame( [ [ self::SENDER, self::BOTS ], 6 ], [ \array_column( $page['groups'], 'theme' ), $page['total'] ] );

		$this->seed_drift( 25 );
		$this->save( 'drift-desk', '--source=drift' );
		$first = $this->grouped( '--lens=drift-desk' );
		$this->assertSame( [ 20, 25 ], [ \count( $first['groups'] ), $first['total'] ] );
		$this->assertSame( [ 'groups' => [], 'total' => 25, 'available' => true ], $this->grouped( '--lens=drift-desk', '--offset=30' ) );
	}

	/**
	 * A group's kind and clients read every copy of its stories, a copy
	 * with no theme among them: a themed praise canonical about one client
	 * and its unthemed complaint copy about another answer complaint and
	 * both clients.
	 */
	public function test_a_groups_kind_and_clients_read_every_copy_of_its_stories(): void {
		$at = self::$oct_01 + 10 * self::DAY;
		$this->seed(
			...$this->ticket( 'news:gift-1', 'praise', $at, 5.0, 'gift links', [ [ self::ACME, 'subject' ] ] ),
			...$this->ticket( 'news:gift-2', 'complaint', $at + 60, 5.0, '', [ [ self::HARBOR, 'subject' ] ] ),
			...[ self::story( 'news:gift-1', [ 'news:gift-1', 'news:gift-2' ], $at ) ]
		);
		$this->save( 'gift-desk', '--source=news', '--kind=complaint', '--kind=praise' );

		$this->assertSame(
			[ [ 'gift links', 'complaint', 1, [ self::ACME, self::HARBOR ] ] ],
			\array_map( static fn ( array $g ): array => [ $g['theme'], $g['kind'], $g['count'], \array_column( $g['clients'], 'entity_key' ) ], $this->grouped( '--lens=gift-desk' )['groups'] )
		);
	}

	/**
	 * A grouped read with no other bound keeps the last GROUP_DAYS
	 * days; a lens's days or a call's since widen it, and items still read
	 * the whole retention.
	 */
	public function test_an_unbounded_grouped_read_keeps_the_last_thirty_days(): void {
		$this->seed(
			...$this->ticket( 'news:outage-old', 'incident', self::$oct_20 - 40 * self::DAY, 5.0, 'stale outage', [] ),
			...$this->ticket( 'news:outage-new', 'incident', self::$oct_20 - 2 * self::DAY, 5.0, 'fresh outage', [] )
		);
		$this->save( 'outage-desk', '--source=news' );
		$this->save( 'outage-season', '--source=news', '--days=60' );

		$themes = fn ( string ...$args ): array => \array_column( $this->grouped( ...$args )['groups'], 'theme' );
		$this->assertSame( [ 'fresh outage' ], $themes( '--lens=outage-desk' ) );
		$this->assertSame( [ 'fresh outage', 'stale outage' ], $themes( '--lens=outage-desk', '--since=2026-09-01' ) );
		$this->assertSame( [ 'fresh outage', 'stale outage' ], $themes( '--lens=outage-season' ) );
		$this->assertSame( 2, $this->ci->dispatch( 'search', [ '--lens=outage-desk' ] )['total'] );
	}

	/**
	 * An item's theme is read from the newest partition holding a row for
	 * it, so a stale row in another file makes no second group.
	 */
	public function test_an_items_theme_reads_from_its_newest_partition(): void {
		$at = self::$oct_20 - 3 * self::DAY;
		$this->seed( ...$this->ticket( 'news:split', 'incident', $at, 5.0, 'old theme', [] ) );
		$this->second_partition( self::extraction( 'news:split', 'An incident.', 'incident', $at, [], 5.0, 5.0, 'new theme' ) );

		$this->assertSame( [ [ 'new theme', 1 ] ], \array_map( static fn ( array $g ): array => [ $g['theme'], $g['count'] ], $this->grouped( '--source=news' )['groups'] ) );
		$this->assertSame( [ 'new theme' ], \array_column( $this->ci->dispatch( 'trends', [ 'theme', '--source=news' ] )['rows'], 'value' ) );
	}

	/** The search command's description names the grouped answer whole, `available` included. */
	public function test_search_describes_its_grouped_answer(): void {
		$search = \array_column( Intelligence_CI_Node::node_schema()['commands'], null, 'name' )['search'];

		$this->assertStringContainsString( 'newest_ts, items } ], total, available }', $search['description'] );
		$this->assertStringContainsString( (string) Graph_Query::GROUP_DAYS, $search['description'] );
		$limit = \array_column( $search['args'], null, 'name' )['limit'];
		$this->assertArrayNotHasKey( 'default', $limit );
	}

	/** A search that groups takes no sort, since the groups rank themselves. */
	public function test_a_grouped_search_refuses_a_sort(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sort does not apply to groups, which rank by severity, then importance, then clients, then recency' );
		$this->ci->dispatch( 'search', [ '--lens=support-desk', '--group=theme', '--sort=time' ] );
	}

	/** A grouping outside its set is refused, naming the set. */
	public function test_an_unknown_grouping_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'group must be one of: theme' );
		$this->ci->dispatch( 'search', [ '--group=kind' ] );
	}

	/**
	 * A theme merged into another reads as its survivor wherever a theme is
	 * read: its stories join the survivor's group, trend row and signal,
	 * while the item's own `themes` row keeps the theme it was extracted with.
	 */
	public function test_a_merged_theme_groups_under_its_survivor(): void {
		$this->ci->dispatch( 'merge_theme', [ self::SENDER, self::EMAIL ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame(
			[
				[ self::EMAIL, 'complaint', 4, 1 ],
				[ self::BILLING, 'complaint', 1, 1 ],
				[ self::BOTS, 'incident', 2, 1 ],
				[ self::STORAGE, 'feature_request', 1, 1 ],
				[ '', 'praise', 1, 1 ],
			],
			\array_map( static fn ( array $g ): array => [ $g['theme'], $g['kind'], $g['count'], \count( $g['clients'] ) ], $this->grouped( '--lens=support-desk' )['groups'] )
		);
		$this->assertSame(
			[ [ self::EMAIL, 4 ], [ self::BOTS, 2 ], [ self::BILLING, 1 ], [ self::STORAGE, 1 ] ],
			\array_map( static fn ( array $row ): array => [ $row['value'], $row['count'] ], $this->ci->dispatch( 'trends', [ '--lens=support-desk' ] )['rows'] )
		);
		$this->assertContains( [ 'linear:SUP-6', self::SENDER ], self::graph_rows( 'themes', 'item_key', 'item_key, theme' ) );
	}

	/**
	 * A page of groups costs the same statements whatever it holds: two
	 * groups read as many as twenty.
	 */
	public function test_a_page_of_groups_costs_the_same_statements_however_many(): void {
		$this->seed_drift( 2 );
		$this->save( 'drift-desk', '--source=drift' );
		$two = $this->statements_of( fn () => $this->ci->dispatch( 'search', [ '--lens=drift-desk', '--group=theme' ] ) );

		$this->seed_drift( 25, 3 );
		$this->assertSame( 20, \count( $this->grouped( '--lens=drift-desk' )['groups'] ) );
		$this->assertSame( $two, $this->statements_of( fn () => $this->ci->dispatch( 'search', [ '--lens=drift-desk', '--group=theme' ] ) ) );
	}

	/**
	 * Trends count stories by theme, each row naming the open clients its
	 * stories name as their subject; an item with no theme counts under none, and the
	 * kind axis keeps its own shape.
	 */
	public function test_trends_count_themes_with_their_clients(): void {
		$rows = $this->ci->dispatch( 'trends', [ '--lens=support-desk' ] )['rows'];

		$this->assertSame(
			[
				[ '2026-10-01', self::EMAIL, 3, [ self::ACME ] ],
				[ '2026-10-01', self::BOTS, 2, [ self::HARBOR ] ],
				[ '2026-10-01', self::BILLING, 1, [ self::ACME ] ],
				[ '2026-10-01', self::SENDER, 1, [] ],
				[ '2026-10-01', self::STORAGE, 1, [ self::ACME ] ],
			],
			\array_map( static fn ( array $row ): array => [ $row['bucket'], $row['value'], $row['count'], \array_column( $row['clients'], 'entity_key' ) ], $rows )
		);
		$this->assertSame( [ 'bucket', 'value', 'count', 'clients', 'items' ], \array_keys( $rows[1] ) );
		$this->assertSame( [ [ 'entity_key' => self::HARBOR, 'name' => 'Harbor Ledger' ] ], $rows[1]['clients'] );
		$this->assertSame( [ 'linear:SUP-5', 'linear:SUP-4' ], \array_column( $rows[1]['items'], 'item_key' ) );
		$this->assertSame( [ 'bucket', 'value', 'count', 'items' ], \array_keys( $this->ci->dispatch( 'trends', [ 'kind', '--lens=support-desk' ] )['rows'][0] ) );
	}

	/**
	 * A theme row's clients are the open clients its churn stories name as
	 * leaving: Prairie Signal, which the story only mentions, is in neither
	 * the group's clients nor the trend row's.
	 */
	public function test_a_theme_rows_clients_are_its_kind_subjects(): void {
		$prairie = 'organization:prairie-signal';
		$at      = self::$oct_01 + 9 * self::DAY;
		$this->seed(
			self::entity( $prairie, 'Prairie Signal' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => $prairie, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ],
			...$this->ticket( 'exits:EX-1', 'churned', $at, 6.0, 'leaving the platform', [ [ self::HARBOR, 'subject' ], [ $prairie, 'mention' ] ] )
		);
		$this->save( 'exit-desk', '--source=exits', '--by=theme', '--bucket=month' );

		$this->assertSame( [ [ 'leaving the platform', [ self::HARBOR ] ] ], \array_map( static fn ( array $g ): array => [ $g['theme'], \array_column( $g['clients'], 'entity_key' ) ], $this->grouped( '--lens=exit-desk' )['groups'] ) );
		$this->assertSame( [ [ 'leaving the platform', [ self::HARBOR ] ] ], \array_map( static fn ( array $row ): array => [ $row['value'], \array_column( $row['clients'], 'entity_key' ) ], $this->ci->dispatch( 'trends', [ '--lens=exit-desk' ] )['rows'] ) );
	}

	/** A lens saves `by: theme`, and the axis and save refusals name it. */
	public function test_a_lens_saves_the_theme_axis(): void {
		$this->ci->dispatch( 'save_lens', [ 'theme-week', 'Theme week', '--by=theme' ] );
		$this->assertSame( 'theme', \array_slice( self::ontology_events(), -1 )[0]['filters']['by'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'by must be one of: kind, topic, theme' );
		$this->ci->dispatch( 'trends', [ 'sentiment' ] );
	}

	/**
	 * On the theme axis a theme needs no history of its own: its first surge
	 * through a theme lens spikes once the lens holds any story before its
	 * oldest baseline week.
	 */
	public function test_a_themes_first_surge_spikes(): void {
		$week = 1792368000 - 604800;
		for ( $n = 1; $n <= 9; $n++ ) {
			$at = $week + 2 * self::DAY + $n * 60;
			$this->seed(
				self::item( "news:surge-{$n}", "Paywall loop {$n}", 'news', 'internal', $at ),
				self::extraction( "news:surge-{$n}", 'Readers loop.', 'incident', $at, [], 5.0, 5.0, 'paywall login loop' )
			);
		}
		$old = $week - 12 * 604800;
		$this->seed( self::item( 'news:old', 'An old note', 'news', 'internal', $old ), self::extraction( 'news:old', 'Old.', 'incident', $old, [], 5.0, 5.0, 'old note' ) );
		$this->save( 'surge-desk', '--source=news', '--by=theme' );

		$this->assertSame(
			[ [ '2026-10-12', 'theme', 'paywall login loop', 9, 0.0 ] ],
			\array_map( static fn ( array $s ): array => [ $s['bucket'], $s['by'], $s['value'], $s['count'], $s['usual']['mean'] ], $this->ci->dispatch( 'signals', [ '--lens=surge-desk' ] )['signals'] )
		);
	}

	/**
	 * A theme whose merge chain cycles reads as itself, as
	 * `Entity_Index::walk()` leaves it unresolved for curation too, and the
	 * chain is logged once.
	 */
	public function test_a_theme_on_a_cycling_chain_reads_as_itself(): void {
		$stderr = $this->capture_stderr();
		$this->seed(
			[
				Intel_Event::make( 'theme_merged', [ 'from' => self::SENDER, 'into' => self::EMAIL ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'theme_merged', [ 'from' => self::EMAIL, 'into' => self::SENDER ], 'user:41', 'fixture-9' ),
			]
		);

		$counts = \array_column( $this->grouped( '--lens=support-desk' )['groups'], 'count', 'theme' );
		$this->grouped( '--lens=support-desk' );

		$this->assertSame( [ 3, 1 ], [ $counts[ self::EMAIL ], $counts[ self::SENDER ] ] );
		$lines = self::stderr_lines( $stderr, 'leaving theme merges that do not resolve unapplied: ' );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'merge cycle ' . self::EMAIL . ' → ' . self::SENDER . ' → ' . self::EMAIL, $lines[0] );
	}

	/**
	 * A surge split across a theme and the one merged into it signals once,
	 * as the survivor, counting both.
	 */
	public function test_a_merged_themes_surge_signals_as_its_survivor(): void {
		$week = 1792368000 - 604800;
		for ( $n = 1; $n <= 9; $n++ ) {
			$at = $week + 2 * self::DAY + $n * 60;
			$this->seed(
				self::item( "news:surge-{$n}", "Paywall loop {$n}", 'news', 'internal', $at ),
				self::extraction( "news:surge-{$n}", 'Readers loop.', 'incident', $at, [], 5.0, 5.0, $n <= 5 ? 'paywall login loop' : 'login redirect loop' )
			);
		}
		$old = $week - 12 * 604800;
		$this->seed( self::item( 'news:old', 'An old note', 'news', 'internal', $old ), self::extraction( 'news:old', 'Old.', 'incident', $old, [], 5.0, 5.0, 'old note' ) );
		$this->save( 'surge-desk', '--source=news', '--by=theme' );
		$this->ci->dispatch( 'merge_theme', [ 'login redirect loop', 'paywall login loop' ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );

		$this->assertSame(
			[ [ 'paywall login loop', 9 ] ],
			\array_map( static fn ( array $s ): array => [ $s['value'], $s['count'] ], $this->ci->dispatch( 'signals', [ '--lens=surge-desk' ] )['signals'] )
		);
	}

	/**
	 * A story with no theme is not history on the theme axis, so a surge
	 * whose lens holds only unthemed stories before it spikes nothing.
	 */
	public function test_an_unthemed_story_is_no_theme_history(): void {
		$week = 1792368000 - 604800;
		for ( $n = 1; $n <= 9; $n++ ) {
			$at = $week + 2 * self::DAY + $n * 60;
			$this->seed(
				self::item( "news:surge-{$n}", "Paywall loop {$n}", 'news', 'internal', $at ),
				self::extraction( "news:surge-{$n}", 'Readers loop.', 'incident', $at, [], 5.0, 5.0, 'paywall login loop' )
			);
		}
		$old = $week - 12 * 604800;
		$this->seed( self::item( 'news:old', 'An old note', 'news', 'internal', $old ), self::extraction( 'news:old', 'Old.', 'incident', $old, [] ) );
		$this->save( 'surge-desk', '--source=news', '--by=theme' );

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--lens=surge-desk' ] )['signals'] );
	}

	/**
	 * An item about more than `Graph_Query::MAX_ITEM_CLIENTS` open clients
	 * names none of them for its group, which still counts it: the sweep
	 * group lists only the two clients its narrow item is about, and so
	 * ranks below the outage group, whose one item is about three, though
	 * the sweep's story is newer.
	 */
	public function test_an_item_about_too_many_clients_names_none_for_its_group(): void {
		$this->seed_sweep();

		$this->assertSame( 3, Graph_Query::MAX_ITEM_CLIENTS );
		$this->assertSame(
			[
				[ 'kite outage', 1, [ 'organization:kite-c', 'organization:kite-d', 'organization:kite-e' ] ],
				[ 'kite sweep', 2, [ 'organization:kite-a', 'organization:kite-b' ] ],
			],
			\array_map( static fn ( array $g ): array => [ $g['theme'], $g['count'], \array_column( $g['clients'], 'entity_key' ) ], $this->grouped( '--lens=sweep-desk' )['groups'] )
		);
	}

	/** A theme trend row names the clients of its items about at most MAX_ITEM_CLIENTS, and counts every item. */
	public function test_an_item_about_too_many_clients_names_none_for_its_trend_row(): void {
		$this->seed_sweep();

		$this->assertSame(
			[
				[ 'kite sweep', 2, [ 'organization:kite-a', 'organization:kite-b' ] ],
				[ 'kite outage', 1, [ 'organization:kite-c', 'organization:kite-d', 'organization:kite-e' ] ],
			],
			\array_map( static fn ( array $row ): array => [ $row['value'], $row['count'], \array_column( $row['clients'], 'entity_key' ) ], $this->ci->dispatch( 'trends', [ '--lens=sweep-desk' ] )['rows'] )
		);
	}

	/**
	 * The sweep desk: five more open clients, a sweep item about all five
	 * and one about two of them, and an older outage item about three.
	 */
	private function seed_sweep(): void {
		$at      = static fn ( int $days ): int => self::$oct_01 + $days * self::DAY;
		$clients = [];
		foreach ( [ 'a', 'b', 'c', 'd', 'e' ] as $letter ) {
			$key       = "organization:kite-{$letter}";
			$clients[] = $key;
			$this->seed(
				self::entity( $key, 'Kite ' . \strtoupper( $letter ) ),
				[ Intel_Event::make( 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ]
			);
		}
		$about = static fn ( array $keys ): array => \array_map( static fn ( string $key ): array => [ $key, 'subject' ], $keys );
		$this->seed(
			...$this->ticket( 'sweep:W-1', 'incident', $at( 22 ), 5.0, 'kite sweep', $about( $clients ) ),
			...$this->ticket( 'sweep:W-2', 'incident', $at( 21 ), 5.0, 'kite sweep', $about( \array_slice( $clients, 0, 2 ) ) ),
			...$this->ticket( 'sweep:W-3', 'incident', $at( 20 ), 5.0, 'kite outage', $about( \array_slice( $clients, 2 ) ) )
		);
		$this->save( 'sweep-desk', '--source=sweep', '--by=theme', '--bucket=month' );
	}

	/**
	 * A grouped search.
	 *
	 * @param string ...$args Its options.
	 * @return array<string,mixed>
	 */
	private function grouped( string ...$args ): array {
		return $this->ci->dispatch( 'search', [ '--group=theme', ...$args ] );
	}

	/**
	 * Save a lens as a curator, then project it.
	 *
	 * @param string $key     The lens key, its name too.
	 * @param string ...$args Its filter options.
	 */
	private function save( string $key, string ...$args ): void {
		$this->ci->dispatch( 'save_lens', [ $key, $key, ...$args ] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
	}

	/**
	 * Drift items, each its own theme, from a number on.
	 *
	 * @param int $count How many.
	 * @param int $from  The first number.
	 */
	private function seed_drift( int $count, int $from = 1 ): void {
		$events = [];
		for ( $n = $from; $n < $from + $count; $n++ ) {
			$at       = self::$oct_01 + $n * 3600;
			$events[] = self::item( "drift:{$n}", "Drift {$n}", 'drift', 'internal', $at )[0];
			$events[] = self::extraction( "drift:{$n}", 'Drift.', 'other', $at, [], 5.0, 5.0, "drift theme {$n}" )[0];
		}
		$this->seed( $events );
	}

	/**
	 * The support desk: four organizations, two of them open clients and one
	 * a former client, and eleven items in six groups, two of them copies of
	 * SUP-4 in one story with it.
	 */
	private function seed_desk(): void {
		$at = static fn ( int $days ): int => self::$oct_01 + $days * self::DAY;
		$this->seed(
			self::entity( self::ACME, 'Acme Quill' ),
			self::entity( self::HARBOR, 'Harbor Ledger' ),
			self::entity( self::PIER, 'Pier Post' ),
			self::entity( self::SALT, 'Salt Works' ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => self::ACME, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => self::HARBOR, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => self::PIER, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_ended', [ 'entity_key' => self::PIER, 'role' => 'client', 'from_ts' => self::$jan_01, 'to_ts' => self::$jan_01 + 30 * self::DAY ], 'user:41', 'fixture-9' ),
			],
			...$this->ticket( 'linear:SUP-1', 'complaint', $at( 5 ), 3.0, self::EMAIL, [ [ self::ACME, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-2', 'complaint', $at( 12 ), 7.0, self::EMAIL, [ [ self::HARBOR, 'mention' ] ] ),
			...$this->ticket( 'linear:SUP-3', 'feature_request', $at( 8 ), 5.0, self::EMAIL, [ [ self::ACME, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-4', 'incident', $at( 14 ), 6.0, self::BOTS, [ [ self::HARBOR, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-5', 'incident', $at( 15 ), 4.0, self::BOTS, [ [ self::PIER, 'subject' ] ] ),
			...$this->ticket( 'feed:SUP-4-a', 'incident', $at( 14 ) + 60, 2.0, self::BOTS, [ [ self::HARBOR, 'subject' ] ] ),
			...$this->ticket( 'feed:SUP-4-b', 'incident', $at( 14 ) + 120, 2.0, '', [ [ self::HARBOR, 'subject' ] ] ),
			...[ self::story( 'linear:SUP-4', [ 'linear:SUP-4', 'feed:SUP-4-a', 'feed:SUP-4-b' ], $at( 14 ) ) ],
			...$this->ticket( 'linear:SUP-6', 'complaint', $at( 17 ), 5.0, self::SENDER, [ [ self::SALT, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-7', 'praise', $at( 18 ), 5.0, '', [ [ self::ACME, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-8', 'feature_request', $at( 16 ), 5.0, self::STORAGE, [ [ self::ACME, 'subject' ] ] ),
			...$this->ticket( 'linear:SUP-9', 'complaint', $at( 18 ), 5.0, self::BILLING, [ [ self::ACME, 'subject' ], [ self::HARBOR, 'mention' ] ] )
		);
	}

	/**
	 * One ticket: its record, its extraction and its links, a churn kind
	 * naming its first subject as the client whose churn it reports.
	 *
	 * @param string                           $key        The item key.
	 * @param string                           $kind       Its kind.
	 * @param int                              $at         Its date.
	 * @param float                            $importance Its importance.
	 * @param string                           $theme      Its theme, or ''.
	 * @param list<array{0:string,1:string}>   $links      Each entity and role it links.
	 * @return list<list<array<string,mixed>>>
	 */
	private function ticket( string $key, string $kind, int $at, float $importance, string $theme, array $links ): array {
		$subjects = \array_column( \array_filter( $links, static fn ( array $link ): bool => 'subject' === $link[1] ), 0 );
		$events   = [
			self::item( $key, "Ticket {$key}", \explode( ':', $key )[0], 'internal', $at ),
			self::extraction( $key, "A {$kind}.", $kind, $at, [], 5.0, $importance, $theme, \in_array( $kind, Intel_Event::CHURN_KINDS, true ) ? ( $subjects[0] ?? '' ) : '' ),
		];
		foreach ( $links as [ $entity, $role ] ) {
			$events[] = self::link( 'link_asserted', $key, $entity, $role, 'name', 1.0, $at );
		}
		return $events;
	}
}

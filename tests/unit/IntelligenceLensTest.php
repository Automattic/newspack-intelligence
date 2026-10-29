<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * Lenses: a named, curated set of read filters, saved and removed as
 * ontology events at `tune`, listed by `lenses`, and read through by
 * `search` and `trends`, where a filter the call names replaces the lens's
 * and the lens's tiers only narrow what the caller reads.
 */
final class IntelligenceLensTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const LEDGER = 'organization:salem-ledger';

	private const DEAL = 'hubspot:renewal-4417';

	/** A proposal only the restricted deal cites. */
	private const QUIET = 'organization:quiet-buyer';

	private const HOLDER = 6143;

	private const READER = 6144;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::HOLDER, 'harbor-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		self::add_user( self::READER, 'harbor-reader', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed_lens_world();
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** The lens verbs declare their roles: the list a read, saving and removing tune. */
	public function test_the_lens_verbs_declare_their_roles(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( [ Capabilities::READ, Capabilities::TUNE, Capabilities::TUNE ], [ $roles['lenses'], $roles['save_lens'], $roles['remove_lens'] ] );
	}

	/** A lens saves as one `lens_saved` event carrying every filter, and lists as it was saved. */
	public function test_a_lens_saves_and_lists(): void {
		$answer = $this->ci->dispatch( 'save_lens', [ 'Deadline Desk', 'Deadline Desk', '--description=Newsroom wins this month.', '--kind=award', '--kind=funding', '--role=client', '--source=feed', '--source=linear', '--topic=topic:awards', '--entity=' . self::GAZETTE, '--days=30', '--tier=public', '--tier=internal', '--by=topic', '--bucket=day' ] );

		$events  = self::ontology_events();
		$filters = [
			'kinds'   => [ 'award', 'funding' ],
			'topics'  => [ 'topic:awards' ],
			'roles'   => [ 'client' ],
			'sources' => [ 'feed', 'linear' ],
			'entity'  => self::GAZETTE,
			'days'    => 30,
			'tiers'   => [ 'public', 'internal' ],
			'by'      => 'topic',
			'bucket'  => 'day',
		];
		$this->assertCount( 1, $events );
		$this->assertSame( [ 'event' => 'lens_saved', 'lens_key' => 'deadline-desk', 'name' => 'Deadline Desk', 'description' => 'Newsroom wins this month.', 'filters' => $filters ], \array_intersect_key( $events[0], \array_flip( [ 'event', 'lens_key', 'name', 'description', 'filters' ] ) ) );
		$this->assertSame( 'user:harbor-reader', $events[0]['actor'] );
		$this->assertSame( [ 'ok' => true, 'event_id' => $events[0]['event_id'], 'lens_key' => 'deadline-desk' ], $answer );

		$this->seed( $events );
		$this->assertSame(
			[
				'lenses'    => [ [ 'lens_key' => 'deadline-desk', 'name' => 'Deadline Desk', 'description' => 'Newsroom wins this month.', 'filters' => $filters ] ],
				'available' => true,
			],
			$this->ci->dispatch( 'lenses', [] )
		);
	}

	/** A lens naming no filter stores every one empty, and a key merged away saves as its survivor. */
	public function test_an_unfiltered_lens_stores_every_filter_empty(): void {
		$this->seed( self::entity( 'organization:gazette-daily', 'Gazette Daily' ), self::merged( 'organization:gazette-daily', self::GAZETTE ) );

		$this->ci->dispatch( 'save_lens', [ 'everything', 'Everything' ] );
		$this->ci->dispatch( 'save_lens', [ 'daily', 'Daily', '--entity=organization:gazette-daily' ] );

		$events = self::ontology_events();
		$this->assertSame( [ 'kinds' => [], 'topics' => [], 'roles' => [], 'sources' => [], 'entity' => null, 'days' => null, 'tiers' => [], 'by' => null, 'bucket' => null ], $events[0]['filters'] );
		$this->assertSame( '', $events[0]['description'] );
		$this->assertSame( self::GAZETTE, $events[1]['filters']['entity'] );
	}

	/** Saving a key again replaces the lens; removing it deletes it. */
	public function test_a_lens_replaces_and_removes(): void {
		$this->ci->dispatch( 'save_lens', [ 'renewals', 'Renewals', '--kind=churn_signal' ] );
		$this->ci->dispatch( 'save_lens', [ 'renewals', 'Renewal watch', '--kind=complaint', '--days=14' ] );
		$this->ci->dispatch( 'save_lens', [ 'launches', 'Launches', '--kind=launch' ] );
		$this->seed( self::ontology_events() );

		$listed = $this->ci->dispatch( 'lenses', [] )['lenses'];
		$this->assertSame( [ 'launches' => 'Launches', 'renewals' => 'Renewal watch' ], \array_column( $listed, 'name', 'lens_key' ) );
		$this->assertSame( [ [ 'complaint' ], 14 ], [ $listed[1]['filters']['kinds'], $listed[1]['filters']['days'] ] );

		$answer = $this->ci->dispatch( 'remove_lens', [ 'renewals' ] );
		$events = self::ontology_events();
		$this->assertSame( [ 'event' => 'lens_removed', 'lens_key' => 'renewals' ], \array_intersect_key( \end( $events ), [ 'event' => 1, 'lens_key' => 1 ] ) );
		$this->assertSame( [ 'ok' => true, 'event_id' => \end( $events )['event_id'] ], $answer );
		$this->seed( [ \end( $events ) ] );
		$this->assertSame( [ 'launches' ], \array_column( $this->ci->dispatch( 'lenses', [] )['lenses'], 'lens_key' ) );
	}

	/**
	 * Each refusal and what it names; none writes anything.
	 *
	 * @return array<string,array{0:int,1:string,2:list<string>,3:string}>
	 */
	public static function refusals(): array {
		$sprawl = \array_map( static fn ( int $n ): string => "--source={$n}" . \str_repeat( 's', 240 ), \range( 1, 20 ) );
		return [
			'a kind outside its set'   => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--kind=rumor' ], 'kind must be one of: ' . \implode( ', ', Intel_Event::ITEM_KINDS ) ],
			'a role outside its set'   => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--role=ally' ], 'role must be one of: ' . \implode( ', ', Intel_Event::ROLES ) ],
			'a tier outside its set'   => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--tier=secret' ], 'tier must be one of: public, internal, restricted' ],
			'an axis outside its set'  => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--by=sentiment' ], 'by must be one of: kind, topic' ],
			'a bucket outside its set' => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--bucket=fortnight' ], 'bucket must be one of: day, week, month' ],
			'no days'                  => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--days=0' ], 'days must be between 1 and 730' ],
			'days past the horizon'    => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--days=731' ], 'days must be between 1 and 730' ],
			'an entity it cannot see'  => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--entity=' . self::QUIET ], 'no entity: ' . self::QUIET ],
			'a topic it cannot see'    => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--topic=topic:ghost-beat' ], 'no entity: topic:ghost-beat' ],
			'a restricted-only entity' => [ self::HOLDER, 'save_lens', [ 'odd', 'Odd', '--entity=' . self::QUIET ], 'a lens naming ' . self::QUIET . ' would show it to every reader; no item outside the restricted tier cites it' ],
			'a blank source'           => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--source= ' ], 'source is blank' ],
			'a blank name'             => [ self::READER, 'save_lens', [ 'odd', ' ' ], 'name is blank' ],
			'an unsluggable key'       => [ self::READER, 'save_lens', [ '!?!', 'Odd' ], 'lens key !?! sanitizes to nothing' ],
			'an over-long lens'        => [ self::READER, 'save_lens', [ 'sprawl', 'Sprawl', ...$sprawl ], 'lens_saved: ' ],
			'a missing lens removed'   => [ self::READER, 'remove_lens', [ 'Nowhere Desk' ], 'no lens: nowhere-desk' ],
			'a topic that is no topic' => [ self::READER, 'save_lens', [ 'odd', 'Odd', '--topic=topic:awards', '--topic=' . self::GAZETTE ], self::GAZETTE . ' is not a topic' ],
		];
	}

	/**
	 * A refusal throws naming its cause and writes nothing.
	 *
	 * @param int          $user    The caller.
	 * @param string       $verb    The verb.
	 * @param list<string> $args    Its tokens.
	 * @param string       $message The refusal, or how it begins.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_refusal_names_its_cause_and_writes_nothing( int $user, string $verb, array $args, string $message ): void {
		$GLOBALS['_current_user_id'] = $user;

		try {
			$this->ci->dispatch( $verb, $args );
			$this->fail( "{$verb} was not refused" );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringStartsWith( $message, $e->getMessage() );
			if ( 'lens_saved: ' === $message ) {
				$this->assertStringContainsString( 'past the 4096-byte line the ontology stream takes', $e->getMessage() );
			}
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/** Search and trends through a lens answer exactly what its filters given by hand answer. */
	public function test_a_lens_reads_as_its_filters_given_by_hand(): void {
		$this->save( 'deadline-desk', '--kind=award', '--kind=funding', '--role=client', '--source=feed', '--source=linear', '--days=30', '--by=kind', '--bucket=day' );
		$by_hand = [ '--kind=award', '--kind=funding', '--role=client', '--source=feed', '--source=linear', '--since=2026-09-21' ];

		$lensed = $this->ci->dispatch( 'search', [ '--lens=deadline-desk' ] );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $lensed['items'], 'item_key' ) );
		$this->assertSame( $this->ci->dispatch( 'search', $by_hand ), $lensed );
		$trends = $this->ci->dispatch( 'trends', [ '--lens=deadline-desk' ] );
		$this->assertSame( [ 'funding', 'award' ], \array_column( $trends['rows'], 'value' ) );
		$this->assertSame( $this->ci->dispatch( 'trends', [ 'kind', '--bucket=day', ...$by_hand ] ), $trends );
	}

	/** A lens's days run back from today, UTC, today included. */
	public function test_a_lens_reads_the_last_days(): void {
		$this->save( 'fifteen-days', '--days=15' );
		$this->save( 'fourteen-days', '--days=14' );

		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=fifteen-days' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=fourteen-days' ] ) ) );
	}

	/** A filter the call names replaces the lens's for that field and leaves the rest standing. */
	public function test_a_calls_filter_replaces_the_lenss(): void {
		$this->save( 'recent-awards', '--kind=award', '--source=feed', '--days=7', '--by=topic', '--bucket=month' );

		$this->assertSame( [], self::keys( $this->ci->dispatch( 'search', [ '--lens=recent-awards' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=recent-awards', '--since=2026-10-05' ] ) ) );
		$this->assertSame( [], self::keys( $this->ci->dispatch( 'search', [ '--lens=recent-awards', '--since=2026-10-05', '--kind=launch' ] ) ), 'the lens keeps its source' );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=recent-awards', '--since=2026-10-05', '--kind=launch', '--source=github' ] ) ) );
		$this->assertSame( [ 'linear:joint-grant' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=recent-awards', '--until=2026-10-02', '--kind=funding', '--source=linear' ] ) ), 'until alone opens the window behind it' );

		$this->assertSame( [ [ '2026-10-01', 'topic:awards' ] ], self::rows( $this->ci->dispatch( 'trends', [ '--lens=recent-awards', '--since=2026-09-01' ] ) ) );
		$this->assertSame( [ [ '2026-10-06', 'award' ] ], self::rows( $this->ci->dispatch( 'trends', [ 'kind', '--lens=recent-awards', '--since=2026-09-01', '--bucket=day' ] ) ) );
	}

	/** Trends counts kinds by week when neither the call nor its lens names an axis or a bucket. */
	public function test_trends_default_to_kinds_by_week(): void {
		$this->save( 'no-axis', '--kind=award', '--kind=launch' );

		$by_week = [ [ '2026-10-05', 'award' ], [ '2026-10-12', 'launch' ] ];
		$this->assertSame( $by_week, self::rows( $this->ci->dispatch( 'trends', [ '--lens=no-axis' ] ) ) );
		$this->assertSame( $by_week, self::rows( $this->ci->dispatch( 'trends', [ '--kind=award', '--kind=launch' ] ) ) );
		$this->assertSame( [ [ '2026-10-01', 'topic:ai' ], [ '2026-10-01', 'topic:awards' ] ], self::rows( $this->ci->dispatch( 'trends', [ 'topic', '--lens=no-axis', '--bucket=month' ] ) ) );
	}

	/** A read through a lens no one saved is refused, the key slugged as a save slugs it. */
	public function test_a_missing_lens_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no lens: ghost-desk' );
		$this->ci->dispatch( 'search', [ '--lens=Ghost Desk' ] );
	}

	/** Reading through and removing a lens slug its key as saving it does. */
	public function test_a_lens_is_named_by_its_slugged_key(): void {
		$this->save( 'harbor-wins', '--kind=award' );

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=Harbor Wins' ] ) ) );
		$this->ci->dispatch( 'remove_lens', [ 'Harbor Wins' ] );
		$this->assertSame( 'harbor-wins', \array_slice( self::ontology_events(), -1 )[0]['lens_key'] );
	}

	/**
	 * A lens lists only the entity and topics its reader may see, now: one
	 * a judgment has since left to restricted items alone is left out for a
	 * reader and listed to a holder. Read through, it narrows a reader to
	 * nothing rather than dropping the filter.
	 */
	public function test_a_lens_shows_only_the_keys_its_reader_sees(): void {
		$this->seed(
			self::proposal( 'topic:harbor-churn', 'Harbor churn', self::DEAL ),
			self::link( 'link_asserted', 'feed:gazette-award', 'topic:harbor-churn', 'topic', 'extract', 1.0, self::$oct_06 ),
			self::link( 'link_asserted', self::DEAL, 'topic:harbor-churn', 'topic', 'extract', 1.0, self::$oct_13 - 60 ),
			self::proposal( 'organization:pier-buyer', 'Pier Buyer', self::DEAL ),
			self::link( 'link_asserted', 'feed:gazette-award', 'organization:pier-buyer', 'mention', 'name', 1.0, self::$oct_06 ),
			self::link( 'link_asserted', self::DEAL, 'organization:pier-buyer', 'mention', 'name', 1.0, self::$oct_13 - 60 )
		);
		$this->save( 'churn-desk', '--topic=topic:harbor-churn', '--topic=topic:ai' );
		$this->save( 'pier-desk', '--entity=organization:pier-buyer' );
		$this->seed(
			self::judged( 'link_rejected', 'feed:gazette-award', 'topic:harbor-churn', 'user:41' ),
			self::judged( 'link_rejected', 'feed:gazette-award', 'organization:pier-buyer', 'user:41' )
		);
		$listed = fn (): array => \array_column( \array_column( $this->ci->dispatch( 'lenses', [] )['lenses'], 'filters', 'lens_key' ), null );

		$GLOBALS['_current_user_id'] = self::READER;
		[ $churn, $pier ]            = $listed();
		$this->assertSame( [ [ 'topic:ai' ], null ], [ $churn['topics'], $pier['entity'] ] );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=churn-desk' ] ) ) );
		$this->assertSame( [], self::keys( $this->ci->dispatch( 'search', [ '--lens=pier-desk' ] ) ) );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		[ $churn, $pier ]            = $listed();
		$this->assertSame( [ [ 'topic:harbor-churn', 'topic:ai' ], 'organization:pier-buyer' ], [ $churn['topics'], $pier['entity'] ] );
		$this->assertSame( [ 'github:ledger-launch', self::DEAL ], self::keys( $this->ci->dispatch( 'search', [ '--lens=churn-desk' ] ) ) );
		$this->assertSame( [ self::DEAL ], self::keys( $this->ci->dispatch( 'search', [ '--lens=pier-desk' ] ) ) );
	}

	/** Each repeatable filter matches any of its values. */
	public function test_repeatable_filters_match_any(): void {
		$search = fn ( string ...$args ): array => self::keys( $this->ci->dispatch( 'search', $args ) );

		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], $search( '--kind=award', '--kind=launch' ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], $search( '--topic=topic:awards', '--topic=topic:ai' ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], $search( '--source=feed', '--source=github' ) );
		$this->assertSame( [ 'feed:gazette-award' ], $search( '--source=feed', '--source=github', '--kind=award', '--kind=funding' ), 'filters still meet' );
		$this->assertSame( [ 'award', 'launch' ], \array_column( $this->ci->dispatch( 'trends', [ 'kind', '--kind=award', '--kind=launch' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'topic:ai' ], \array_column( $this->ci->dispatch( 'trends', [ 'topic', '--source=github', '--source=hubspot' ] )['rows'], 'value' ) );
	}

	/** Search's role reads items linking an entity holding the role at the item's time. */
	public function test_search_filters_by_role(): void {
		$this->seed( [ Intel_Event::make( 'role_asserted', [ 'entity_key' => self::LEDGER, 'role' => 'partner', 'from_ts' => self::$oct_06 ], 'user:41', 'fixture-9' ) ] );
		$search = fn ( string ...$args ): array => self::keys( $this->ci->dispatch( 'search', $args ) );

		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], $search( '--role=client' ) );
		$this->assertSame( [ 'github:ledger-launch' ], $search( '--role=partner' ), 'the grant predates the partnership' );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], $search( '--role=client', '--role=partner' ) );
		$this->assertSame( [], $search( '--role=funder' ) );
		$this->assertSame( [ 'github:ledger-launch' ], $search( '--role=partner', '--entity=' . self::LEDGER ) );
	}

	/** A lens's tiers narrow what a holder reads and never widen what a reader reads. */
	public function test_a_lenss_tiers_narrow_and_never_widen(): void {
		$this->save( 'public-only', '--tier=public' );
		$this->save( 'inside', '--tier=internal', '--tier=restricted' );
		$this->save( 'sealed', '--tier=restricted' );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=public-only' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', self::DEAL, 'linear:joint-grant' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=inside' ] ) ) );
		$this->assertSame( [ self::DEAL ], self::keys( $this->ci->dispatch( 'search', [ '--lens=sealed' ] ) ) );
		$this->assertContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind', '--lens=inside' ] )['rows'], 'value' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=public-only' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'linear:joint-grant' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=inside' ] ) ) );
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => true ], $this->ci->dispatch( 'search', [ '--lens=sealed' ] ) );
		$this->assertNotContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind', '--lens=inside' ] )['rows'], 'value' ) );
	}

	/** With no projection to read, the lens list answers unavailable. */
	public function test_no_table_answers_no_lens_unavailable(): void {
		$this->activate();
		$this->capture_stderr();

		$this->assertSame( [ 'lenses' => [], 'available' => false ], Graph_Query::lenses() );
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
	 * Each trend row as its bucket and value, in order.
	 *
	 * @param array<string,mixed> $answer A trends answer.
	 * @return list<array{0:string,1:string}>
	 */
	private static function rows( array $answer ): array {
		return \array_map( static fn ( array $row ): array => [ $row['bucket'], $row['value'] ], $answer['rows'] );
	}

	/**
	 * The item keys a search answers, in order.
	 *
	 * @param array<string,mixed> $answer A search answer.
	 * @return list<string>
	 */
	private static function keys( array $answer ): array {
		return \array_column( $answer['items'], 'item_key' );
	}

	/**
	 * The read world, plus a restricted renewal the Gazette is the subject
	 * of, extracted as a churn signal, and a proposal only it cites.
	 */
	private function seed_lens_world(): void {
		$this->seed_world();
		$this->seed(
			self::item( self::DEAL, 'Harbor Gazette renewal under review', 'hubspot', 'restricted', self::$oct_13 - 60, 'The renewal clause expires next quarter.' ),
			self::extraction( self::DEAL, 'The Gazette may not renew.', 'churn_signal', self::$oct_13 - 60, [ 'topic:awards' ] ),
			self::link( 'link_asserted', self::DEAL, self::GAZETTE, 'subject', 'identifier', 1.0, self::$oct_13 - 60 ),
			self::proposal( self::QUIET, 'Quiet Buyer', self::DEAL )
		);
	}
}

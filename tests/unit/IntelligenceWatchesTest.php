<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Graph_Watches;
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
 * Watches: one person's standing question over a lens, an entity or a
 * collection, saved and removed as ontology events at `read`, its unseen
 * matches listed and marked seen as watches-stream events, and no verb
 * answering or changing another person's watch.
 */
final class IntelligenceWatchesTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const DEAL = 'hubspot:renewal-5817';

	/** A proposal only the restricted deal cites. */
	private const QUIET = 'organization:quiet-dock';

	private const OWNER = 7101;

	private const NEIGHBOR = 7102;

	private const HOLDER = 7103;

	/** A watch the owner saved over the Gazette. */
	private const OWN = '7a1f00c3b2e4d5f6';

	/** A watch the neighbor saved. */
	private const THEIRS = '9e8d7c6b5a493827';

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::OWNER, 'quay-reader', 'manage_options' );
		self::add_user( self::NEIGHBOR, 'quay-neighbor', 'manage_options' );
		self::add_user( self::HOLDER, 'quay-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		$GLOBALS['_current_user_id'] = self::OWNER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed_world();
		$this->seed(
			self::item( self::DEAL, 'Harbor Gazette renewal at risk', 'hubspot', 'restricted', self::$oct_13 - 120, 'The renewal clause lapses.' ),
			self::link( 'link_asserted', self::DEAL, self::GAZETTE, 'subject', 'identifier', 1.0, self::$oct_13 - 120 ),
			self::proposal( self::QUIET, 'Quiet Dock', self::DEAL ),
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'harbor-reading', 'name' => 'Harbor reading' ], 'user:quay-reader', 'fixture-9' ) ]
		);
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** Anyone who reads keeps watches: every watch verb declares read. */
	public function test_every_watch_verb_declares_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \array_fill( 0, 5, Capabilities::READ ), [ $roles['watches'], $roles['save_watch'], $roles['remove_watch'], $roles['watch_matches'], $roles['mark_seen'] ] );
	}

	/**
	 * A watch saves as one `watch_saved` under a key the server mints, its
	 * owner the caller, its entity the survivor the caller sees; listed, it
	 * reads back with its target and its three boxes.
	 */
	public function test_a_watch_saves_under_a_minted_key_and_lists(): void {
		$this->seed( self::entity( 'organization:gazette-daily', 'Gazette Daily' ), self::merged( 'organization:gazette-daily', self::GAZETTE ) );

		$answer = $this->ci->dispatch( 'save_watch', [ 'Gazette watch', '--entity=organization:gazette-daily', '--digest=yes', '--instant' ] );

		$events = self::ontology_events();
		$this->assertCount( 1, $events );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $answer['watch_key'] );
		$this->assertSame(
			[ 'event' => 'watch_saved', 'watch_key' => $answer['watch_key'], 'owner' => self::OWNER, 'name' => 'Gazette watch', 'target_kind' => 'entity', 'target_key' => self::GAZETTE, 'digest' => true, 'instant' => true, 'inbox' => true ],
			\array_intersect_key( $events[0], \array_flip( [ 'event', 'watch_key', 'owner', 'name', 'target_kind', 'target_key', 'digest', 'instant', 'inbox' ] ) )
		);
		$this->assertSame( 'user:quay-reader', $events[0]['actor'] );
		$this->assertSame( [ 'ok' => true, 'event_id' => $events[0]['event_id'], 'watch_key' => $answer['watch_key'] ], $answer );

		$this->seed( $events );
		$this->assertSame(
			[
				'watches'   => [ [ 'watch_key' => $answer['watch_key'], 'name' => 'Gazette watch', 'target' => [ 'kind' => 'entity', 'key' => self::GAZETTE ], 'digest' => true, 'instant' => true, 'inbox' => true, 'unseen' => 0 ] ],
				'available' => true,
			],
			$this->ci->dispatch( 'watches', [] )
		);
	}

	/** Each box binds as a bool, and one left out takes its default: no digest, no instant email, the inbox. */
	public function test_the_boxes_bind_as_bools_with_defaults(): void {
		$this->seed( [ Intel_Event::make( 'lens_saved', [ 'lens_key' => 'harbor-desk', 'name' => 'Harbor desk', 'description' => '', 'filters' => Graph_Query::unfiltered() ], 'user:quay-reader', 'fixture-9' ) ] );
		$this->ci->dispatch( 'save_watch', [ 'Defaults', '--collection=harbor-reading' ] );
		$this->ci->dispatch( 'save_watch', [ 'Spelled', '--lens=Harbor Desk', '--digest=on', '--instant=0', '--inbox=no' ] );

		$boxes = \array_map( static fn ( array $e ): array => [ $e['target_kind'], $e['target_key'], $e['digest'], $e['instant'], $e['inbox'] ], self::ontology_events() );
		$this->assertSame( [ [ 'collection', 'harbor-reading', false, false, true ], [ 'lens', 'harbor-desk', true, false, false ] ], $boxes );
	}

	/** Saving with the key of one's own watch replaces it; with another's, or none saved, it is refused. */
	public function test_a_key_replaces_only_the_callers_own_watch(): void {
		$this->own_watches();

		$answer = $this->ci->dispatch( 'save_watch', [ 'Gazette, daily', '--entity=' . self::GAZETTE, '--digest', '--inbox=false', '--key=' . self::OWN ] );

		$this->assertSame( self::OWN, $answer['watch_key'] );
		$this->seed( [ \array_slice( self::ontology_events(), -1 )[0] ] );
		$listed = $this->ci->dispatch( 'watches', [] )['watches'];
		$this->assertSame( [ [ self::OWN, 'Gazette, daily', true, false, false ] ], \array_map( static fn ( array $w ): array => [ $w['watch_key'], $w['name'], $w['digest'], $w['instant'], $w['inbox'] ], $listed ) );
	}

	/**
	 * Each refusal and what it names; none writes anything.
	 *
	 * @return array<string,array{0:string,1:list<string>,2:string}>
	 */
	public static function refusals(): array {
		return [
			'no target'                    => [ 'save_watch', [ 'Nothing' ], 'a watch follows exactly one of lens, entity or collection' ],
			'two targets'                  => [ 'save_watch', [ 'Both', '--entity=' . self::GAZETTE, '--collection=harbor-reading' ], 'a watch follows exactly one of lens, entity or collection' ],
			'no box ticked'                => [ 'save_watch', [ 'Mute', '--entity=' . self::GAZETTE, '--inbox=off' ], 'a watch delivers by digest, instant email or inbox; tick one' ],
			'an entity it cannot see'      => [ 'save_watch', [ 'Quiet', '--entity=' . self::QUIET ], 'no entity: ' . self::QUIET ],
			'a lens no one saved'          => [ 'save_watch', [ 'Ghost', '--lens=Ghost Desk' ], 'no lens: ghost-desk' ],
			'a collection no one made'     => [ 'save_watch', [ 'Shelf', '--collection=empty-shelf' ], 'no collection: empty-shelf' ],
			'a blank name'                 => [ 'save_watch', [ ' ', '--entity=' . self::GAZETTE ], 'name is blank' ],
			'another\'s key replaced'      => [ 'save_watch', [ 'Mine now', '--entity=' . self::GAZETTE, '--key=' . self::THEIRS ], 'no watch: ' . self::THEIRS ],
			'a key no one saved replaced'  => [ 'save_watch', [ 'Mine now', '--entity=' . self::GAZETTE, '--key=00000000deadbeef' ], 'no watch: 00000000deadbeef' ],
			'another\'s watch removed'     => [ 'remove_watch', [ self::THEIRS ], 'no watch: ' . self::THEIRS ],
			'another\'s matches read'      => [ 'watch_matches', [ self::THEIRS ], 'no watch: ' . self::THEIRS ],
			'another\'s matches marked'    => [ 'mark_seen', [ self::THEIRS ], 'no watch: ' . self::THEIRS ],
			'an item it never matched'     => [ 'mark_seen', [ self::OWN, '--item=github:ledger-launch' ], 'no match: github:ledger-launch' ],
			'a match outside its tiers'    => [ 'mark_seen', [ self::OWN, '--item=' . self::DEAL ], 'no match: ' . self::DEAL ],
		];
	}

	/**
	 * A refusal throws naming its cause and writes nothing.
	 *
	 * @param string       $verb    The verb.
	 * @param list<string> $args    Its tokens.
	 * @param string       $message The refusal.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_refusal_names_its_cause_and_writes_nothing( string $verb, array $args, string $message ): void {
		$this->own_watches();
		$this->seed( self::matched( self::OWN, self::DEAL, 1, self::$oct_13 - 120 ) );

		try {
			$this->ci->dispatch( $verb, $args );
			$this->fail( "{$verb} was not refused" );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( $message, $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
		$this->assertSame( [], self::watches_events() );
	}

	/**
	 * A reader keeps at most MAX_PER_OWNER watches: a new one past them is
	 * refused, naming the cap and how to free one, and writes nothing.
	 */
	public function test_a_new_watch_past_the_cap_is_refused(): void {
		$this->assertSame( 25, Graph_Watches::MAX_PER_OWNER );
		$this->own_many( self::OWNER, 25 );

		try {
			$this->ci->dispatch( 'save_watch', [ 'One too many', '--entity=' . self::GAZETTE ] );
			$this->fail( 'the twenty-sixth watch was saved' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'you have 25 watches, and one reader may keep 25 at most; remove one first', $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/** At the cap a reader may still edit one of their own watches. */
	public function test_an_edit_at_the_cap_is_saved(): void {
		$this->own_many( self::OWNER, 25 );

		$answer = $this->ci->dispatch( 'save_watch', [ 'Renamed at the cap', '--entity=' . self::GAZETTE, '--key=' . self::many_key( self::OWNER, 7 ) ] );

		$this->assertSame( self::many_key( self::OWNER, 7 ), $answer['watch_key'] );
		$this->assertSame( [ 'watch_saved' ], \array_column( self::ontology_events(), 'event' ) );
	}

	/** Another reader's watches count toward their cap, not the caller's. */
	public function test_another_readers_watches_do_not_count(): void {
		$this->own_many( self::NEIGHBOR, 25 );
		$this->own_many( self::OWNER, 24 );

		$answer = $this->ci->dispatch( 'save_watch', [ 'The twenty-fifth', '--entity=' . self::GAZETTE ] );

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $answer['watch_key'] );
		$this->assertSame( [ 'watch_saved' ], \array_column( self::ontology_events(), 'event' ) );
	}

	/** Removing one's own watch writes `watch_removed`, and the list drops it. */
	public function test_a_watch_removes(): void {
		$this->own_watches();

		$answer = $this->ci->dispatch( 'remove_watch', [ self::OWN ] );

		$events = self::ontology_events();
		$this->assertSame( [ 'event' => 'watch_removed', 'watch_key' => self::OWN, 'owner' => self::OWNER ], \array_intersect_key( $events[0], [ 'event' => 1, 'watch_key' => 1, 'owner' => 1 ] ) );
		$this->assertSame( [ 'ok' => true, 'event_id' => $events[0]['event_id'] ], $answer );
		$this->seed( $events );
		$this->assertSame( [], $this->ci->dispatch( 'watches', [] )['watches'] );
	}

	/** The list holds the caller's own watches alone, by name. */
	public function test_the_list_is_the_callers_own(): void {
		$this->own_watches();

		$this->assertSame( [ self::OWN ], \array_column( $this->ci->dispatch( 'watches', [] )['watches'], 'watch_key' ) );
		$GLOBALS['_current_user_id'] = self::NEIGHBOR;
		$this->assertSame( [ self::THEIRS ], \array_column( $this->ci->dispatch( 'watches', [] )['watches'], 'watch_key' ) );
	}

	/**
	 * The unseen matches read newest matched first, each as search answers
	 * it plus when it matched, counted and paged; a match of a tier the
	 * caller does not hold now is left out of both.
	 */
	public function test_the_unseen_matches_read_newest_first_within_the_callers_tiers(): void {
		$this->seed(
			[ self::saved( self::OWN, self::HOLDER, 'Gazette', 'entity', self::GAZETTE ) ],
			self::matched( self::OWN, 'linear:joint-grant', 3, self::$oct_01, self::HOLDER ),
			self::matched( self::OWN, self::DEAL, 1, self::$oct_13 - 120, self::HOLDER ),
			self::matched( self::OWN, 'feed:gazette-award', 2, self::$oct_06, self::HOLDER )
		);
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$answer = $this->ci->dispatch( 'watch_matches', [ self::OWN ] );
		$this->assertSame( [ 3, true ], [ $answer['total'], $answer['available'] ] );
		$this->assertSame( [ [ 'linear:joint-grant', self::matched_at( 3 ) ], [ 'feed:gazette-award', self::matched_at( 2 ) ], [ self::DEAL, self::matched_at( 1 ) ] ], \array_map( static fn ( array $i ): array => [ $i['item_key'], $i['matched_ts'] ], $answer['items'] ) );
		$search = $this->ci->dispatch( 'search', [ '--entity=' . self::GAZETTE ] )['items'];
		$this->assertSame( \array_column( $search, null, 'item_key' )['feed:gazette-award'] + [ 'matched_ts' => self::matched_at( 2 ) ], $answer['items'][1] );
		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $this->ci->dispatch( 'watch_matches', [ self::OWN, '--limit=1', '--offset=1' ] )['items'], 'item_key' ) );
		$this->assertSame( 3, $this->ci->dispatch( 'watches', [] )['watches'][0]['unseen'] );

		self::strip_restricted( self::HOLDER );
		$answer = $this->ci->dispatch( 'watch_matches', [ self::OWN ] );
		$this->assertSame( [ 2, [ 'linear:joint-grant', 'feed:gazette-award' ] ], [ $answer['total'], \array_column( $answer['items'], 'item_key' ) ] );
		$this->assertSame( 2, $this->ci->dispatch( 'watches', [] )['watches'][0]['unseen'] );
	}

	/**
	 * Marking named items seen writes one `watch_seen` each, an item already
	 * seen not again; marking none marks every unseen match the caller reads.
	 */
	public function test_marking_seen_writes_one_event_per_unseen_match(): void {
		$this->own_watches();
		$this->seed(
			self::matched( self::OWN, 'linear:joint-grant', 3, self::$oct_01 ),
			self::matched( self::OWN, 'feed:gazette-award', 2, self::$oct_06 )
		);

		$this->assertSame( [ 'ok' => true, 'count' => 1 ], $this->ci->dispatch( 'mark_seen', [ self::OWN, '--item=feed:gazette-award' ] ) );
		$seen = self::watches_events();
		$this->assertSame( [ 'event' => 'watch_seen', 'actor' => 'user:quay-reader', 'watch_key' => self::OWN, 'owner' => self::OWNER, 'item_key' => 'feed:gazette-award', 'retrieved_ts' => self::$oct_06 + 600 ], \array_intersect_key( $seen[0], \array_flip( [ 'event', 'watch_key', 'owner', 'item_key', 'retrieved_ts', 'actor' ] ) ) );
		$this->seed( $seen );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'watch_matches', [ self::OWN ] )['items'], 'item_key' ) );

		$this->assertSame( [ 'ok' => true, 'count' => 0 ], $this->ci->dispatch( 'mark_seen', [ self::OWN, '--item=feed:gazette-award' ] ) );
		$this->assertSame( [ 'ok' => true, 'count' => 1 ], $this->ci->dispatch( 'mark_seen', [ self::OWN ] ) );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], \array_column( self::watches_events(), 'item_key' ) );
	}

	/** A purged item's match row stays, but the watch neither lists nor counts it. */
	public function test_a_purged_items_match_is_neither_listed_nor_counted(): void {
		$this->own_watches();
		$this->seed(
			self::matched( self::OWN, 'linear:joint-grant', 3, self::$oct_01 ),
			self::matched( self::OWN, 'feed:gazette-award', 2, self::$oct_06 ),
			[ Intel_Event::make( 'item_purged', [ 'item_key' => 'linear:joint-grant', 'reason' => 'another team' ], 'machine:purge@9.4.1', 'fixture-9' ) ]
		);

		$this->assertSame( [ [ 'feed:gazette-award' ], 1 ], [ \array_column( $this->ci->dispatch( 'watch_matches', [ self::OWN ] )['items'], 'item_key' ), $this->ci->dispatch( 'watch_matches', [ self::OWN ] )['total'] ] );
		$this->assertSame( 1, $this->ci->dispatch( 'watches', [] )['watches'][0]['unseen'] );
		$this->assertCount( 2, self::graph_rows( 'watch_matches' ), 'the watches stream\'s rows stay' );
	}

	/**
	 * A watch that does not ask for the inbox counts no unseen match and
	 * lists none, though the watcher records them for its email.
	 */
	public function test_a_watch_without_the_inbox_lists_nothing(): void {
		$this->seed(
			[ self::saved( self::OWN, self::OWNER, 'Gazette', 'entity', self::GAZETTE, false ) ],
			self::matched( self::OWN, 'feed:gazette-award', 2, self::$oct_06 )
		);

		$this->assertSame( [ [ self::OWN, false, 0 ] ], \array_map( static fn ( array $w ): array => [ $w['watch_key'], $w['inbox'], $w['unseen'] ], $this->ci->dispatch( 'watches', [] )['watches'] ) );
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => true ], $this->ci->dispatch( 'watch_matches', [ self::OWN ] ) );
	}

	/** With no projection to read, the list answers unavailable. */
	public function test_no_table_answers_no_watch_unavailable(): void {
		$this->activate();
		$this->capture_stderr();

		$this->assertSame( [ 'watches' => [], 'available' => false ], Graph_Watches::watches() );
	}

	/** The owner's watch over the Gazette and the neighbor's over the same entity, projected. */
	private function own_watches(): void {
		$this->seed(
			[
				self::saved( self::OWN, self::OWNER, 'Gazette', 'entity', self::GAZETTE ),
				self::saved( self::THEIRS, self::NEIGHBOR, 'Their Gazette', 'entity', self::GAZETTE ),
			]
		);
	}

	/**
	 * Project `$count` watches a reader saved over the Gazette.
	 *
	 * @param int $owner The reader.
	 * @param int $count How many.
	 */
	private function own_many( int $owner, int $count ): void {
		$events = [];
		for ( $n = 1; $n <= $count; $n++ ) {
			$events[] = self::saved( self::many_key( $owner, $n ), $owner, "Desk {$n}", 'entity', self::GAZETTE );
		}
		$this->seed( $events );
	}

	/**
	 * The key of a reader's n-th fixture watch.
	 *
	 * @param int $owner The reader.
	 * @param int $n     Which.
	 */
	private static function many_key( int $owner, int $n ): string {
		return \sprintf( '%08x%08x', $owner, $n );
	}

	/**
	 * A watch_saved, ticking the inbox alone, or nothing.
	 *
	 * @param string $key   The watch key.
	 * @param int    $owner Its owner.
	 * @param string $name  Its name.
	 * @param string $kind  Its target's kind.
	 * @param string $at    Its target's key.
	 * @param bool   $inbox Whether it lists its matches.
	 * @return array<string,mixed>
	 */
	private static function saved( string $key, int $owner, string $name, string $kind, string $at, bool $inbox = true ): array {
		return Intel_Event::make( 'watch_saved', [ 'watch_key' => $key, 'owner' => $owner, 'name' => $name, 'target_kind' => $kind, 'target_key' => $at, 'digest' => false, 'instant' => false, 'inbox' => $inbox ], 'user:fixture', 'fixture-9' );
	}

	/**
	 * The watcher's match of an item, the n-th minute past the fixture's tick.
	 *
	 * @param string $watch   The watch key.
	 * @param string $item    The item key.
	 * @param int    $minute  When it matched, in minutes past the tick.
	 * @param int    $item_ts The item's date.
	 * @param int    $owner   The watch's owner.
	 * @return list<array<string,mixed>>
	 */
	private static function matched( string $watch, string $item, int $minute, int $item_ts, int $owner = self::OWNER ): array {
		$event       = Intel_Event::make( 'watch_matched', [ 'watch_key' => $watch, 'owner' => $owner, 'item_key' => $item, 'at' => $item_ts + 600, 'retrieved_ts' => $item_ts + 600, 'quiet' => false ], 'machine:watcher@9.4.1', 'fixture-9' );
		$event['ts'] = self::matched_at( $minute );
		return [ $event ];
	}

	/**
	 * When the n-th minute's match was recorded.
	 *
	 * @param int $minute Minutes past the fixture's tick.
	 */
	private static function matched_at( int $minute ): float {
		return (float) ( self::$oct_20 + 60 * $minute );
	}

	/**
	 * Revoke a user's own grant of the restricted tier.
	 *
	 * @param int $user The user id.
	 */
	private static function strip_restricted( int $user ): void {
		unset( $GLOBALS['_wp_test_site_caps'][ \get_current_blog_id() ][ $user ]['newspack_intelligence_restricted'] );
	}
}

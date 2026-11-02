<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * A `publisher` link says the entity's own site published the item, not
 * that the item is about it. Every read meaning "about" leaves it out — the
 * search, lens, trend and signal filters, the timeline, related entities,
 * the review queue, an entity's item count and an item's entities — while
 * an entity answers how many items it published and the timeline pages
 * them on asking. A claim and an entity's visibility rest on any link.
 *
 * The Gazette, a client, published four items: one about the Ledger, one
 * about itself, and two about neither; a fifth, on another site, is about
 * the Gazette.
 */
final class PublisherLinkTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const LEDGER = 'organization:salem-ledger';

	/** A proposed entity no item cites but by publishing it. */
	private const TERN = 'organization:tern-weekly';

	/** Monday 2026-10-19 00:00 UTC, when the fixture's current week opened. */
	private const THIS_WEEK = 1792368000;

	private const WEEK = 604800;

	private const READER = 7731;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::READER, 'gull-reader', 'manage_options' );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed(
			self::entity( self::GAZETTE, 'Harbor Gazette', [ 'domain' => [ 'harborgazette.example' ] ] ),
			self::entity( self::LEDGER, 'Salem Ledger' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => self::GAZETTE, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ],
			self::published( 'feed:gazette-crater', 'A crater study from the university', 'research', self::$oct_13 ),
			self::published( 'feed:gazette-ferry', 'Salem Ledger joins the ferry inquiry', 'other', self::$oct_13 + 3600 ),
			self::link( 'link_asserted', 'feed:gazette-ferry', self::LEDGER, 'subject', 'name', 1.0, self::$oct_13 + 3600 ),
			self::published( 'feed:gazette-forty', 'Harbor Gazette turns forty', 'award', self::$oct_01 ),
			self::link( 'link_asserted', 'feed:gazette-forty', self::GAZETTE, 'subject', 'name', 1.0, self::$oct_01 ),
			self::item( 'feed:gazette-prize', 'Harbor Gazette wins state prize', 'feed', 'public', self::$oct_06 ),
			self::extraction( 'feed:gazette-prize', 'Judges honored the Gazette.', 'award', self::$oct_06, [] ),
			self::link( 'link_asserted', 'feed:gazette-prize', self::GAZETTE, 'subject', 'name', 1.0, self::$oct_06 )
		);
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_publisher_is_a_link_role_and_not_an_about_role(): void {
		$this->assertContains( 'publisher', Intel_Event::LINK_ROLES );
		$this->assertNotContains( 'publisher', Intel_Event::ABOUT_ROLES );
	}

	public function test_search_by_entity_leaves_out_what_it_published(): void {
		$this->assertSame( [ 'feed:gazette-prize', 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'search', [ '--entity=' . self::GAZETTE ] ) ) );
	}

	public function test_search_by_role_leaves_out_what_a_client_published(): void {
		$this->assertSame( [ 'feed:gazette-prize', 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'search', [ '--role=client' ] ) ) );
	}

	public function test_a_lens_role_filter_leaves_out_what_a_client_published(): void {
		$this->save( 'client-news', '--role=client' );

		$this->assertSame( [ 'feed:gazette-prize', 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=client-news' ] ) ) );
	}

	public function test_a_lens_entity_filter_leaves_out_what_it_published(): void {
		$this->save( 'gazette-desk', '--entity=' . self::GAZETTE );

		$this->assertSame( [ 'feed:gazette-prize', 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'search', [ '--lens=gazette-desk' ] ) ) );
	}

	public function test_the_timeline_reads_what_it_is_about_and_pages_what_it_published(): void {
		$about = $this->ci->dispatch( 'timeline', [ self::GAZETTE ] );
		$this->assertSame( [ [ 'feed:gazette-prize', 'feed:gazette-forty' ], 2 ], [ self::keys( $about ), $about['total'] ] );

		$published = $this->ci->dispatch( 'timeline', [ self::GAZETTE, '--published=true', '--limit=2' ] );
		$this->assertSame( [ [ 'feed:gazette-ferry', 'feed:gazette-crater' ], 3 ], [ self::keys( $published ), $published['total'] ] );
		$this->assertSame( [ 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'timeline', [ self::GAZETTE, '--published', '--limit=2', '--offset=2' ] ) ) );
	}

	public function test_an_entity_counts_what_it_is_about_and_what_it_published_apart(): void {
		$entity = $this->ci->dispatch( 'entity', [ self::GAZETTE ] );

		$this->assertSame( [ [ 'items' => 2, 'proposed_links' => 0 ], [ 'count' => 3 ] ], [ $entity['counts'], $entity['published'] ] );
		$this->assertSame( [ 'count' => 0 ], $this->ci->dispatch( 'entity', [ self::LEDGER ] )['published'] );
	}

	public function test_related_shares_no_item_through_its_publisher(): void {
		$this->assertSame( [], $this->ci->dispatch( 'related', [ self::GAZETTE ] )['entities'] );
		$this->assertSame( [], $this->ci->dispatch( 'related', [ self::LEDGER ] )['entities'] );
	}

	public function test_an_items_entities_leave_out_its_publisher(): void {
		$this->assertSame( [ self::LEDGER ], \array_column( $this->ci->dispatch( 'search', [ '--q=ferry' ] )['items'][0]['entities'], 'entity_key' ) );
	}

	public function test_trends_by_entity_and_by_role_count_what_it_is_about(): void {
		$this->assertSame( [ [ '2026-09-28', 'award', 1 ], [ '2026-10-05', 'award', 1 ] ], self::rows( $this->ci->dispatch( 'trends', [ 'kind', '--entity=' . self::GAZETTE ] ) ) );
		$this->assertSame( [ [ '2026-09-28', 'award', 1 ], [ '2026-10-05', 'award', 1 ] ], self::rows( $this->ci->dispatch( 'trends', [ 'kind', '--role=client' ] ) ) );
	}

	/** A burst of what a client published is no signal about it; a week of one item about it each is none either. */
	public function test_signals_count_what_it_is_about(): void {
		for ( $back = 1; $back <= 9; $back++ ) {
			$key = "feed:gazette-week-{$back}";
			$at  = self::THIS_WEEK - $back * self::WEEK + 2 * 86400;
			$this->seed( self::item( $key, "Harbor Gazette week {$back}", 'feed', 'public', $at ), self::extraction( $key, 'About the Gazette.', 'praise', $at, [] ), self::link( 'link_asserted', $key, self::GAZETTE, 'subject', 'name', 1.0, $at ) );
		}
		for ( $n = 1; $n <= 4; $n++ ) {
			$this->seed( self::published( "feed:gazette-launch-{$n}", "Launch {$n}", 'launch', self::THIS_WEEK - self::WEEK + 3 * 86400 + $n * 60 ) );
		}

		$this->assertSame( [], $this->ci->dispatch( 'signals', [ '--entity=' . self::GAZETTE ] )['signals'] );
	}

	public function test_review_queues_no_publisher_link_and_no_entity_only_publishing_cites(): void {
		$this->seed(
			self::proposed( self::TERN, 'Tern Weekly', [ 'domain' => [ 'ternweekly.example' ] ] ),
			self::item( 'feed:tern-ferry', 'Ferry timetable changes', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_proposed', 'feed:tern-ferry', self::TERN, 'publisher', 'identifier', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'feed:gazette-prize', self::LEDGER, 'mention', 'ner_fuzzy', 0.64, self::$oct_06 )
		);

		$this->assertSame( [ [ 'feed:gazette-prize', self::LEDGER ] ], \array_map( static fn ( array $link ): array => [ $link['item_key'], $link['entity_key'] ], $this->ci->dispatch( 'review', [ 'links' ] )['links'] ) );
		$this->assertSame( [], $this->ci->dispatch( 'review', [ 'entities' ] )['entities'] );
	}

	/** An entity a readable item cites only by publishing it is still visible, and answers what it published. */
	public function test_a_publisher_link_still_makes_its_entity_visible(): void {
		$this->seed(
			self::proposed( self::TERN, 'Tern Weekly', [ 'domain' => [ 'ternweekly.example' ] ] ),
			self::item( 'feed:tern-ferry', 'Ferry timetable changes', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_proposed', 'feed:tern-ferry', self::TERN, 'publisher', 'identifier', 1.0, self::$oct_13 )
		);

		$entity = $this->ci->dispatch( 'entity', [ self::TERN ] );
		$this->assertSame( [ 'proposed', [ 'items' => 0, 'proposed_links' => 0 ], [ 'count' => 1 ] ], [ $entity['state'], $entity['counts'], $entity['published'] ] );
	}

	/**
	 * A rejected about-link leaves the publisher link standing in the reads
	 * over every role: a proposed entity a readable item published stays
	 * visible, and that item's claim about it answers.
	 */
	public function test_a_rejected_about_link_leaves_its_publisher_visible_and_its_claim(): void {
		$this->seed(
			self::proposed( self::TERN, 'Tern Weekly', [ 'domain' => [ 'ternweekly.example' ] ] ),
			self::item( 'feed:tern-anniversary', 'Tern Weekly turns ten', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_proposed', 'feed:tern-anniversary', self::TERN, 'publisher', 'identifier', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'feed:tern-anniversary', self::TERN, 'subject', 'name', 1.0, self::$oct_13 ),
			self::judged( 'link_rejected', 'feed:tern-anniversary', self::TERN, 'user:58' ),
			self::claim( 'feed:tern-anniversary', 0, self::TERN, 'launched', '', 'a ferry newsletter', self::$oct_13 )
		);

		$entity = $this->ci->dispatch( 'entity', [ self::TERN ] );
		$this->assertSame( [ self::TERN, [ 'items' => 0, 'proposed_links' => 0 ], [ 'count' => 1 ] ], [ $entity['entity_key'], $entity['counts'], $entity['published'] ] );
		$this->assertSame( [ [ 'feed:tern-anniversary', 'launched', 'a ferry newsletter' ] ], \array_map( static fn ( array $c ): array => [ $c['item_key'], $c['predicate'], $c['object'] ], $this->ci->dispatch( 'claims', [ self::TERN ] )['claims'] ) );
	}

	/** A claim an item makes about its own publisher answers, as its subject is linked to the item. */
	public function test_a_claim_about_its_publisher_answers(): void {
		$this->seed( self::claim( 'feed:gazette-crater', 0, self::GAZETTE, 'launched', '', 'a membership drive', self::$oct_13 ) );

		$this->assertSame( [ [ 'feed:gazette-crater', 'launched' ] ], \array_map( static fn ( array $c ): array => [ $c['item_key'], $c['predicate'] ], $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] ) );
	}

	/** A curator rejecting what an item is about leaves who published it standing. */
	public function test_rejecting_the_about_link_leaves_what_it_published(): void {
		$this->seed( self::judged( 'link_rejected', 'feed:gazette-forty', self::GAZETTE, 'user:58' ) );

		$entity = $this->ci->dispatch( 'entity', [ self::GAZETTE ] );
		$this->assertSame( [ [ 'items' => 1, 'proposed_links' => 0 ], [ 'count' => 3 ] ], [ $entity['counts'], $entity['published'] ] );
		$this->assertSame( [ 'feed:gazette-ferry', 'feed:gazette-crater', 'feed:gazette-forty' ], self::keys( $this->ci->dispatch( 'timeline', [ self::GAZETTE, '--published' ] ) ) );
	}

	/**
	 * A publisher link is no judgment's to make: confirming or rejecting an
	 * item its entity only published is refused, and writes nothing.
	 *
	 * @param string $verb The judging verb.
	 */
	#[DataProvider( 'judging_verbs' )]
	public function test_judging_a_publisher_only_link_is_refused( string $verb ): void {
		try {
			$this->ci->dispatch( $verb, [ 'feed:gazette-crater', self::GAZETTE ] );
			$this->fail( "{$verb} was not refused" );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'no link: feed:gazette-crater ' . self::GAZETTE, $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/** @return array<string,array{0:string}> */
	public static function judging_verbs(): array {
		return [
			'confirm' => [ 'confirm_link' ],
			'reject'  => [ 'reject_link' ],
		];
	}

	/** An entity watch matches the items about its entity, never what it published. */
	public function test_an_entity_watch_matches_no_published_item(): void {
		$arrivals = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => Graph_Query::arrivals( $r, Graph_Query::graph( $r ), 'entity', self::GAZETTE, 'watch:gazette-desk-5', 0.0, 50 ) );

		$this->assertSame( [ 'feed:gazette-forty', 'feed:gazette-prize' ], \array_map( static fn ( array $arrival ): string => $arrival['item']['item_key'], $arrivals ) );
	}

	/**
	 * An item the Gazette's own site published, extracted, linked to the
	 * Gazette as its publisher.
	 *
	 * @param string $key   The item key.
	 * @param string $title Its title.
	 * @param string $kind  Its kind.
	 * @param int    $at    Its date.
	 * @return list<array<string,mixed>>
	 */
	private static function published( string $key, string $title, string $kind, int $at ): array {
		return [
			...self::item( $key, $title, 'feed', 'public', $at ),
			...self::extraction( $key, "A {$kind}.", $kind, $at, [] ),
			...self::link( 'link_asserted', $key, self::GAZETTE, 'publisher', 'identifier', 1.0, $at ),
		];
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
	 * The item keys an answer lists, in order.
	 *
	 * @param array<string,mixed> $answer A search or timeline answer.
	 * @return list<string>
	 */
	private static function keys( array $answer ): array {
		return \array_column( $answer['items'], 'item_key' );
	}

	/**
	 * Each trend row as its bucket, value and count, in order.
	 *
	 * @param array<string,mixed> $answer A trends answer.
	 * @return list<array{0:string,1:string,2:int}>
	 */
	private static function rows( array $answer ): array {
		return \array_map( static fn ( array $row ): array => [ $row['bucket'], $row['value'], $row['count'] ], $answer['rows'] );
	}
}

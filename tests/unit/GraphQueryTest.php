<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';

/**
 * Graph_Query answers each read verb from the projection: items with their
 * extraction and the entities they link, an entity's record, its timeline,
 * related entities, claims, trends, the review queue and collections. A link
 * counts by its effective state, a merged key reads as its survivor, and no
 * answer carries an item body.
 */
final class GraphQueryTest extends TestCase {
	use Seeds_Intel_Graph;

	private const GAZETTE = 'organization:harbor-gazette';

	private const LEDGER = 'organization:salem-ledger';

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_search_answers_items_with_their_extraction_and_entities_never_a_body(): void {
		$this->seed_world();

		$answer = self::search( [ 'q' => 'state award' ] );

		$this->assertSame(
			[
				'items'     => [
					[
						'item_key'   => 'feed:gazette-award',
						'title'      => 'Harbor Gazette wins state award',
						'url'        => 'https://news.example/feed/gazette-award',
						'source'     => 'feed',
						'tier'       => 'public',
						'item_ts'    => self::$oct_06,
						'summary'    => 'Judges honored the Gazette newsroom.',
						'kind'       => 'award',
						'relevance'  => 8.5,
						'importance' => 6.25,
						'entities'   => [ [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted' ] ],
					],
				],
				'total'     => 1,
				'available' => true,
			],
			$answer
		);
	}

	/** The body and the summary are searched; neither is ever answered. */
	public function test_search_matches_the_body_and_the_summary(): void {
		$this->seed_world();

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => 'investigative' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => 'newsroom' ] ) ) );
	}

	/** Each word of q is one quoted phrase, so FTS syntax in it is only text. */
	public function test_search_reads_q_as_quoted_phrases(): void {
		$this->seed_world();

		$this->assertSame( [], self::keys( self::search( [ 'q' => 'award OR launches' ] ) ) );
		$this->assertSame( [], self::keys( self::search( [ 'q' => 'NEAR(award launches)' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'q' => 'gazette"' ] ) ) );
		$this->assertSame( [], self::keys( self::search( [ 'q' => 'title:award' ] ) ) );
	}

	/** A word holding no letter or digit is no word: it neither narrows nor empties q. */
	public function test_search_drops_words_without_a_letter_or_digit(): void {
		$this->seed_world();

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => 'state - award' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => '"state" -- award' ] ) ) );
		$this->assertSame( [], self::keys( self::search( [ 'q' => '- ;' ] ) ) );
	}

	public function test_search_filters_by_entity_topic_kind_source_and_days(): void {
		$this->seed_world();

		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'entity' => self::GAZETTE ] ) ) );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'topic' => 'topic:awards' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::search( [ 'kind' => 'launch' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::search( [ 'source' => 'github' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'since' => '2026-10-05', 'until' => '2026-10-10' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], self::keys( self::search( [ 'since' => '2026-10-02' ] ) ) );
		$this->assertSame( [ 'linear:joint-grant' ], self::keys( self::search( [ 'until' => '2026-10-02' ] ) ) );
	}

	/** A topic filter reads topic links alone; an entity filter reads every link. */
	public function test_a_topic_filter_reads_topic_links_only(): void {
		$this->seed_world();
		$this->seed( self::link( 'link_asserted', 'github:ledger-launch', 'topic:awards', 'mention', 'name', 1.0, self::$oct_13 ) );

		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'topic' => 'topic:awards' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'entity' => 'topic:awards' ] ) ) );
	}

	public function test_search_pages_newest_first_and_counts_every_match(): void {
		$this->seed_world();

		$answer = self::search( [ 'limit' => 1, 'offset' => 1 ] );

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( $answer ) );
		$this->assertSame( 3, $answer['total'] );
		$this->assertSame( 3, self::search( [ 'offset' => 7 ] )['total'] );
	}

	/** An empty limit and a negative page are refused, by search and by related alike. */
	public function test_a_bad_limit_or_page_is_refused(): void {
		$reads = [
			'search limit'  => static fn (): array => self::search( [ 'limit' => 0 ] ),
			'search offset' => static fn (): array => self::search( [ 'offset' => -1 ] ),
			'related limit' => static fn (): array => self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 0 ] ),
		];
		foreach ( $reads as $what => $read ) {
			try {
				$read();
				$this->fail( "{$what} is refused" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringStartsWith( \explode( ' ', $what )[1], $e->getMessage() );
			}
		}
	}

	/** A limit past the cap answers the cap's worth of entities. */
	public function test_related_caps_its_limit(): void {
		$events = [ self::entity( self::GAZETTE, 'Harbor Gazette' ), self::item( 'feed:crowd', 'A crowded item', 'feed', 'public', self::$oct_06 ) ];
		for ( $n = 0; $n < 201; $n++ ) {
			$events[] = self::link( 'link_asserted', 'feed:crowd', "organization:crowd-{$n}", 'mention', 'name', 1.0, self::$oct_06 );
		}
		$events[] = self::link( 'link_asserted', 'feed:crowd', self::GAZETTE, 'subject', 'name', 1.0, self::$oct_06 );
		$this->seed( ...$events );

		$this->assertCount( 200, self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 900 ] )['entities'] );
	}

	public function test_review_refuses_an_unknown_queue(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'what must be one of: links, entities' );
		self::read( 'review', [ 'what' => 'claims' ] );
	}

	public function test_entity_answers_its_record_and_counts(): void {
		$this->seed_world();

		$this->assertSame(
			[
				'entity_key'  => self::GAZETTE,
				'type'        => 'organization',
				'name'        => 'Harbor Gazette',
				'state'       => 'accepted',
				'merged_from' => [],
				'names'       => [ [ 'kind' => 'alias', 'value' => 'HarbGaz' ] ],
				'identifiers' => [ [ 'kind' => 'domain', 'value' => 'harborgazette.example' ] ],
				'roles'       => [ [ 'role' => 'client', 'from_ts' => self::$jan_01, 'to_ts' => 0 ] ],
				'topics'      => [ [ 'topic_key' => 'topic:awards', 'name' => 'Awards' ] ],
				'counts'      => [ 'items' => 2, 'proposed_links' => 0 ],
				'available'   => true,
			],
			self::read( 'entity', [ 'key' => self::GAZETTE ] )
		);
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 1 ], self::read( 'entity', [ 'key' => self::LEDGER ] )['counts'] );
	}

	public function test_an_unknown_entity_is_refused(): void {
		$this->seed_world();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no entity: organization:ghost-gazette' );
		self::read( 'entity', [ 'key' => 'organization:ghost-gazette' ] );
	}

	public function test_timeline_answers_an_entitys_items_newest_first(): void {
		$this->seed_world();

		$answer = self::read( 'timeline', [ 'key' => self::LEDGER, 'limit' => 50, 'offset' => 0 ] );

		$this->assertSame( [ 'github:ledger-launch', 'linear:joint-grant' ], self::keys( $answer ) );
		$this->assertSame( 2, $answer['total'] );
		$this->assertSame( [ 'linear:joint-grant' ], self::keys( self::read( 'timeline', [ 'key' => self::LEDGER, 'until' => '2026-10-05', 'limit' => 50, 'offset' => 0 ] ) ) );
	}

	/** A timeline key that trims to nothing names no entity; it never reads as every item. */
	public function test_a_blank_timeline_key_is_refused(): void {
		$this->seed_world();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no entity:' );
		self::read( 'timeline', [ 'key' => " \t" ] );
	}

	/** A judgment recorded under a key since merged away judges the survivor's link. */
	public function test_a_judgment_under_a_merged_key_judges_the_survivors_link(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:old-ledger', 'Old Salem Ledger' ),
			self::merged( 'organization:old-ledger', self::LEDGER ),
			self::judged( 'link_confirmed', 'linear:joint-grant', 'organization:old-ledger', 'user:41' )
		);

		$this->assertSame( [ self::GAZETTE => 'asserted', self::LEDGER => 'confirmed' ], \array_column( self::search( [ 'q' => 'grant' ] )['items'][0]['entities'], 'state', 'entity_key' ) );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );
	}

	public function test_related_counts_shared_items_and_cites_them(): void {
		$this->seed_world();

		$this->assertSame(
			[
				'entities'  => [
					[ 'entity_key' => 'topic:awards', 'name' => 'Awards', 'type' => 'topic', 'shared_items' => 2, 'item_keys' => [ 'feed:gazette-award', 'linear:joint-grant' ] ],
					[ 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'type' => 'organization', 'shared_items' => 1, 'item_keys' => [ 'linear:joint-grant' ] ],
				],
				'available' => true,
			],
			self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] )
		);
		$this->assertSame( [ 'topic:awards' ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 1 ] )['entities'], 'entity_key' ) );
	}

	public function test_claims_answer_each_claim_with_its_item(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ),
			self::claim( 'linear:joint-grant', 0, self::GAZETTE, 'partners_with', self::LEDGER, 'Salem Ledger', self::$oct_01 )
		);

		$won      = [ 'subject' => self::GAZETTE, 'predicate' => 'won', 'object' => 'State Press Award', 'confidence' => 0.95, 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award' ];
		$partners = [ 'subject' => self::GAZETTE, 'predicate' => 'partners_with', 'object' => self::LEDGER, 'confidence' => 0.95, 'item_key' => 'linear:joint-grant', 'url' => 'https://news.example/linear/joint-grant' ];
		$this->assertSame( [ 'claims' => [ $won, $partners ], 'available' => true ], self::read( 'claims', [ 'key' => self::GAZETTE ] ) );
		$this->assertSame( [ $partners ], self::read( 'claims', [ 'key' => self::LEDGER ] )['claims'] );
		$this->assertSame( [ $won ], self::read( 'claims', [ 'key' => self::GAZETTE, 'predicate' => 'won' ] )['claims'] );
	}

	public function test_trends_count_kinds_and_topics_per_bucket(): void {
		$this->seed_world();

		$this->assertSame(
			[
				'rows'      => [
					[ 'bucket' => '2026-09-28', 'value' => 'funding', 'count' => 1, 'item_keys' => [ 'linear:joint-grant' ] ],
					[ 'bucket' => '2026-10-05', 'value' => 'award', 'count' => 1, 'item_keys' => [ 'feed:gazette-award' ] ],
					[ 'bucket' => '2026-10-12', 'value' => 'launch', 'count' => 1, 'item_keys' => [ 'github:ledger-launch' ] ],
				],
				'available' => true,
			],
			self::trends( [ 'by' => 'kind' ] )
		);
		$this->assertSame(
			[
				[ 'bucket' => '2026-10-01', 'value' => 'topic:awards', 'count' => 2, 'item_keys' => [ 'feed:gazette-award', 'linear:joint-grant' ] ],
				[ 'bucket' => '2026-10-01', 'value' => 'topic:ai', 'count' => 1, 'item_keys' => [ 'github:ledger-launch' ] ],
			],
			self::trends( [ 'by' => 'topic', 'bucket' => 'month' ] )['rows']
		);
		$this->assertSame( [ '2026-10-06', '2026-10-13' ], \array_column( self::trends( [ 'by' => 'kind', 'bucket' => 'day', 'since' => '2026-10-02' ] )['rows'], 'bucket' ) );
	}

	/** A role filter counts items linking an entity holding the role at the item's time. */
	public function test_trends_filter_by_role_and_entity(): void {
		$this->seed_world();
		$this->seed( [ Intel_Event::make( 'role_asserted', [ 'entity_key' => self::LEDGER, 'role' => 'client', 'from_ts' => self::$oct_06 ], 'user:41', 'fixture-9' ) ] );

		$this->assertSame( [ 'funding', 'award', 'launch' ], \array_column( self::trends( [ 'by' => 'kind', 'role' => 'client' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'funding', 'award' ], \array_column( self::trends( [ 'by' => 'kind', 'role' => 'client', 'until' => '2026-10-10' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'funding', 'launch' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => self::LEDGER ] )['rows'], 'value' ) );
		$this->assertSame( [], self::trends( [ 'by' => 'kind', 'role' => 'funder' ] )['rows'] );
	}

	public function test_a_trend_row_cites_its_five_newest_items(): void {
		$events = [];
		for ( $n = 1; $n <= 6; $n++ ) {
			$events[] = self::item( "feed:award-{$n}", "Award {$n}", 'feed', 'public', self::$oct_06 + $n );
			$events[] = self::extraction( "feed:award-{$n}", "Award {$n}.", 'award', self::$oct_06 + $n, [] );
		}
		$this->seed( ...$events );

		$this->assertSame(
			[ [ 'bucket' => '2026-10-05', 'value' => 'award', 'count' => 6, 'item_keys' => [ 'feed:award-6', 'feed:award-5', 'feed:award-4', 'feed:award-3', 'feed:award-2' ] ] ],
			self::trends( [ 'by' => 'kind' ] )['rows']
		);
	}

	public function test_trends_refuse_an_unknown_axis_or_bucket(): void {
		try {
			self::trends( [ 'by' => 'sentiment' ] );
			$this->fail( 'by is a closed set' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'kind, topic', $e->getMessage() );
		}
		$this->expectException( \InvalidArgumentException::class );
		self::trends( [ 'by' => 'kind', 'bucket' => 'fortnight' ] );
	}

	public function test_review_queues_proposed_links_and_entities_with_their_items(): void {
		$this->seed_world();
		$this->seed(
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ),
			self::link( 'link_proposed', 'github:ledger-launch', 'organization:tidewater-times', 'mention', 'ner_fuzzy', 0.62, self::$oct_13 )
		);

		$this->assertSame(
			[
				'links'     => [
					[ 'item_key' => 'github:ledger-launch', 'entity_key' => 'organization:tidewater-times', 'name' => 'Tidewater Times', 'type' => 'organization', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.62, 'title' => 'Salem Ledger launches a newsletter tool', 'url' => 'https://news.example/github/ledger-launch' ],
					[ 'item_key' => 'linear:joint-grant', 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'type' => 'organization', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'title' => 'Gazette and Ledger share a grant', 'url' => 'https://news.example/linear/joint-grant' ],
				],
				'available' => true,
			],
			self::read( 'review', [ 'what' => 'links' ] )
		);
		$this->assertSame(
			[
				'entities'  => [
					[
						'entity_key' => 'organization:tidewater-times',
						'type'       => 'organization',
						'name'       => 'Tidewater Times',
						'items'      => [
							[ 'item_key' => 'github:ledger-launch', 'url' => 'https://news.example/github/ledger-launch', 'title' => 'Salem Ledger launches a newsletter tool' ],
							[ 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award', 'title' => 'Harbor Gazette wins state award' ],
						],
					],
				],
				'available' => true,
			],
			self::read( 'review', [ 'what' => 'entities' ] )
		);
	}

	/** A machine's proposal answers as an entity only while a readable item cites it. */
	public function test_a_proposal_answers_only_through_a_readable_citation(): void {
		$this->seed_world();
		$this->seed(
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ),
			self::proposal( 'organization:orphan-weekly', 'Orphan Weekly', 'feed:never-recorded' )
		);

		$this->assertSame( [ 'Tidewater Times', 'proposed', [ 'items' => 0, 'proposed_links' => 0 ] ], \array_values( \array_intersect_key( self::read( 'entity', [ 'key' => 'organization:tidewater-times' ] ), [ 'name' => 1, 'state' => 1, 'counts' => 1 ] ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no entity: organization:orphan-weekly' );
		self::read( 'entity', [ 'key' => 'organization:orphan-weekly' ] );
	}

	public function test_collections_list_every_collection_and_one_with_its_items(): void {
		$this->seed_world();
		$this->seed(
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:october', 'item_key' => 'feed:gazette-award', 'url' => self::url( 'feed:gazette-award' ), 'title' => 'Harbor Gazette wins state award', 'tier' => 'public' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:empty', 'name' => 'Nothing yet' ], 'user:41', 'fixture-9' ),
			]
		);

		$list = self::read( 'collections', [] );
		$this->assertSame( [ 'col:empty' => 0, 'col:october' => 1 ], \array_column( $list['collections'], 'items', 'collection_key' ) );
		$this->assertSame(
			[
				'collection_key' => 'col:october',
				'name'           => 'October wins',
				'items'          => [ [ 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award', 'title' => 'Harbor Gazette wins state award', 'actor' => 'user:41' ] ],
				'available'      => true,
			],
			\array_diff_key( self::read( 'collections', [ 'key' => 'col:october' ] ), [ 'created_ts' => true ] )
		);
		$this->expectException( \InvalidArgumentException::class );
		self::read( 'collections', [ 'key' => 'col:missing' ] );
	}

	/** Review Focus 3: A into B into C reads as C everywhere, and links to A count for C. */
	public function test_a_merge_chain_reads_as_its_survivor(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:old-ledger', 'Old Salem Ledger' ),
			self::entity( 'organization:ledger-of-salem', 'Ledger of Salem', [ 'alias' => [ 'LoS' ] ] ),
			self::item( 'feed:old-ledger-hire', 'Old Salem Ledger hires an editor', 'feed', 'public', self::$oct_13 + 60 ),
			self::extraction( 'feed:old-ledger-hire', 'An editor joins.', 'hire', self::$oct_13 + 60, [] ),
			self::link( 'link_asserted', 'feed:old-ledger-hire', 'organization:old-ledger', 'subject', 'name', 1.0, self::$oct_13 + 60 ),
			self::link( 'link_asserted', 'feed:old-ledger-hire', self::GAZETTE, 'mention', 'name', 1.0, self::$oct_13 + 60 ),
			self::merged( 'organization:old-ledger', 'organization:ledger-of-salem' ),
			self::merged( 'organization:ledger-of-salem', self::LEDGER )
		);

		foreach ( [ 'organization:old-ledger', 'organization:ledger-of-salem' ] as $merged ) {
			$entity = self::read( 'entity', [ 'key' => $merged ] );
			$this->assertSame( self::LEDGER, $entity['entity_key'] );
			$this->assertSame( [ 'organization:ledger-of-salem', 'organization:old-ledger' ], $entity['merged_from'] );
			$this->assertSame( [ [ 'kind' => 'alias', 'value' => 'LoS' ] ], $entity['names'] );
			$this->assertSame( [ 'items' => 3, 'proposed_links' => 1 ], $entity['counts'] );
			$this->assertSame( [ 'feed:old-ledger-hire', 'github:ledger-launch', 'linear:joint-grant' ], self::keys( self::read( 'timeline', [ 'key' => $merged, 'limit' => 50, 'offset' => 0 ] ) ) );
		}
		$this->assertSame( [ 'feed:old-ledger-hire', 'github:ledger-launch', 'linear:joint-grant' ], self::keys( self::search( [ 'entity' => 'organization:old-ledger' ] ) ) );
		$this->assertSame( [ [ 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'state' => 'asserted' ], [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted' ] ], self::search( [ 'q' => 'editor' ] )['items'][0]['entities'] );
		$this->assertSame( [ 'feed:old-ledger-hire', 'linear:joint-grant' ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] )['entities'], 'item_keys', 'entity_key' )[ self::LEDGER ] );
		$this->assertSame( [ 'funding', 'hire', 'launch' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => 'organization:ledger-of-salem' ] )['rows'], 'value' ) );
	}

	/** A cycling merge chain answers no survivor, so a read naming it is refused. */
	public function test_a_broken_merge_chain_is_refused(): void {
		$this->seed(
			self::entity( 'organization:loop-a', 'Loop A' ),
			self::entity( 'organization:loop-b', 'Loop B' ),
			self::merged( 'organization:loop-a', 'organization:loop-b' ),
			self::merged( 'organization:loop-b', 'organization:loop-a' )
		);

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'organization:loop-a' );
		self::read( 'entity', [ 'key' => 'organization:loop-a' ] );
	}

	public function test_a_rejected_link_counts_nowhere(): void {
		$this->seed_world();
		$this->seed( self::judged( 'link_rejected', 'linear:joint-grant', self::LEDGER, 'user:41' ) );

		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::search( [ 'entity' => self::LEDGER ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::read( 'timeline', [ 'key' => self::LEDGER, 'limit' => 50, 'offset' => 0 ] ) ) );
		$this->assertSame( [ [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted' ] ], self::search( [ 'q' => 'grant' ] )['items'][0]['entities'] );
		$this->assertSame( [ 'items' => 1, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::LEDGER ] )['counts'] );
		$this->assertSame( [ 'topic:awards' ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] )['entities'], 'entity_key' ) );
		$this->assertSame( [ 'launch' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => self::LEDGER ] )['rows'], 'value' ) );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );
	}

	/** A rejected topic link drops the item from that topic's trend and filter. */
	public function test_a_rejected_topic_link_counts_nowhere(): void {
		$this->seed_world();
		$this->seed( self::judged( 'link_rejected', 'linear:joint-grant', 'topic:awards', 'user:41' ) );

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'topic' => 'topic:awards' ] ) ) );
		$this->assertSame( [ 1, 1 ], \array_column( self::trends( [ 'by' => 'topic', 'bucket' => 'month' ] )['rows'], 'count' ) );
	}

	public function test_a_persons_judgment_outranks_an_agents(): void {
		$this->seed_world();
		$state = fn (): string => \array_column( self::search( [ 'q' => 'grant' ] )['items'][0]['entities'], 'state', 'entity_key' )[ self::LEDGER ] ?? 'absent';

		$this->seed( self::judged( 'link_confirmed', 'linear:joint-grant', self::LEDGER, 'agent:41:claude' ) );
		$this->assertSame( 'confirmed', $state() );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );

		$this->seed( self::judged( 'link_rejected', 'linear:joint-grant', self::LEDGER, 'user:41' ) );
		$this->assertSame( 'absent', $state() );

		$this->seed( self::judged( 'link_confirmed', 'linear:joint-grant', self::LEDGER, 'agent:41:claude' ) );
		$this->assertSame( 'absent', $state(), 'a later agent never overturns a person' );

		$this->seed( self::judged( 'link_confirmed', 'linear:joint-grant', self::LEDGER, 'user:41' ) );
		$this->seed( self::judged( 'link_rejected', 'linear:joint-grant', self::LEDGER, 'agent:41:claude' ) );
		$this->assertSame( 'confirmed', $state() );
	}

	public function test_no_table_answers_unavailable_not_an_error(): void {
		$this->activate();
		$stderr = $this->capture_stderr();

		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => false ], self::search( [ 'q' => 'award' ] ) );
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => false ], self::read( 'timeline', [ 'key' => self::GAZETTE, 'limit' => 50, 'offset' => 0 ] ) );
		$this->assertFalse( self::read( 'entity', [ 'key' => self::GAZETTE ] )['available'] );
		$this->assertSame( [ 'entities' => [], 'available' => false ], self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] ) );
		$this->assertSame( [ 'claims' => [], 'available' => false ], self::read( 'claims', [ 'key' => self::GAZETTE ] ) );
		$this->assertSame( [ 'rows' => [], 'available' => false ], self::trends( [ 'by' => 'kind' ] ) );
		$this->assertSame( [ 'links' => [], 'available' => false ], self::read( 'review', [ 'what' => 'links' ] ) );
		$this->assertSame( [ 'collections' => [], 'available' => false ], self::read( 'collections', [] ) );
		$this->assertNotSame( [], self::stderr_lines( $stderr, 'intel-graph' ) );
	}

	/** A reader no partition file is attached to sees no entity. */
	public function test_no_entity_is_visible_before_any_partition_writes(): void {
		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => $this->make_temp_dir( 'intel-unwritten-' ) . '/intel-graph.p0.sqlite' ], Intel_Graph_Row_Schema::version() );

		$this->assertSame( [], $reader->aliases() );
		$this->assertNull( Graph_Query::visible( $reader, Graph_Query::graph( $reader ), 'organization:harbor-gazette' ) );
	}

	/** Before any partition has written a file, every read answers nothing, available. */
	public function test_an_unwritten_graph_answers_empty(): void {
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => true ], self::search( [ 'q' => 'award' ] ) );
		$this->assertSame( [ 'rows' => [], 'available' => true ], self::trends( [ 'by' => 'topic' ] ) );
	}

	/**
	 * A verb's read over the readable tiers, its defaults filled in.
	 *
	 * @param string              $verb The Graph_Query read.
	 * @param array<string,mixed> $args The verb's args.
	 * @return array<string,mixed>
	 */
	private static function read( string $verb, array $args ): array {
		$defaults = [
			'entity'      => [],
			'timeline'    => [ 'limit' => 50, 'offset' => 0 ],
			'related'     => [ 'limit' => 20 ],
			'claims'      => [ 'predicate' => null, 'limit' => 50 ],
			'review'      => [ 'limit' => 50 ],
			'collections' => [ 'key' => null ],
		];
		return Graph_Query::$verb( $args + $defaults[ $verb ] );
	}

	/**
	 * A search over the readable tiers, the verb's defaults filled in.
	 *
	 * @param array<string,mixed> $args The verb's args.
	 * @return array<string,mixed>
	 */
	private static function search( array $args ): array {
		return Graph_Query::search( $args + [ 'q' => null, 'entity' => null, 'kind' => null, 'topic' => null, 'source' => null, 'since' => null, 'until' => null, 'limit' => 50, 'offset' => 0 ] );
	}

	/**
	 * Trends over the readable tiers, the verb's defaults filled in.
	 *
	 * @param array<string,mixed> $args The verb's args.
	 * @return array<string,mixed>
	 */
	private static function trends( array $args ): array {
		return Graph_Query::trends( $args + [ 'role' => null, 'entity' => null, 'since' => null, 'until' => null, 'bucket' => 'week' ] );
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
}

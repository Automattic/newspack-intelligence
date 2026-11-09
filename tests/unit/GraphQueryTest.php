<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';

/**
 * Graph_Query answers each read command from the projection: items with their
 * extraction and the entities they link, an entity's record, its timeline,
 * related entities, claims, trends, the review queue and collections. A link
 * counts by its effective state, a merged key reads as its survivor, and an
 * answer never carries an item body.
 */
final class GraphQueryTest extends TestCase {
	use Seeds_Intel_Graph;

	private const GAZETTE = 'organization:harbor-gazette';

	private const LEDGER = 'organization:salem-ledger';

	private const HARBOR = 'organization:harbor-ledger';

	private const PRAIRIE = 'organization:prairie-signal';

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
						'excerpt'    => 'The Gazette took first place for investigative work.',
						'kind'       => 'award',
						'relevance'  => 8.5,
						'importance' => 6.25,
						'kind_check' => null,
						'entities'   => [ [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted', 'role' => 'subject', 'client' => true ] ],
					],
				],
				'total'     => 1,
				'available' => true,
				'sort'      => 'time',
			],
			$answer
		);
	}

	/**
	 * An item answers its extraction's kind check beside its kind: the kind
	 * checked, the verdict and when, in a list and in a theme group alike.
	 */
	public function test_an_item_answers_its_kind_check(): void {
		$this->seed_world();
		$this->seed( [ Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'github:ledger-launch', 'summary' => 'The Ledger shipped a newsletter tool.', 'kind' => 'launch', 'relevance' => 7.0, 'importance' => 5.5, 'sentiment' => 0.25, 'topics' => [], 'kind_check' => [ 'kind' => 'launch', 'verdict' => Intel_Event::PASSED, 'ts' => self::$oct_13 + 907 ], 'retrieved_ts' => self::$oct_13 + 600 ], 'machine:extract@9.4.1', 'fixture-9' ) ] );

		$check = [ 'kind' => 'launch', 'verdict' => 'passed', 'ts' => self::$oct_13 + 907 ];
		$item  = self::search( [ 'q' => 'newsletter' ] )['items'][0];
		$this->assertSame( [ 'launch', $check ], [ $item['kind'], $item['kind_check'] ] );
		$this->assertSame( $check, self::search( [ 'q' => 'newsletter', 'group' => 'theme', 'sort' => null ] )['groups'][0]['items'][0]['kind_check'] );
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

	/**
	 * A word holding no letter or digit is not a word: it neither narrows nor
	 * empties q.
	 */
	public function test_search_drops_words_without_a_letter_or_digit(): void {
		$this->seed_world();

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => 'state - award' ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'q' => '"state" -- award' ] ) ) );
		$this->assertSame( [], self::keys( self::search( [ 'q' => '- ;' ] ) ) );
	}

	public function test_search_filters_by_entity_topic_kind_source_and_days(): void {
		$this->seed_world();

		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'entity' => self::GAZETTE ] ) ) );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'topic' => [ 'topic:awards' ] ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::search( [ 'kind' => [ 'launch' ] ] ) ) );
		$this->assertSame( [ 'github:ledger-launch' ], self::keys( self::search( [ 'source' => [ 'github' ] ] ) ) );
		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'since' => '2026-10-05', 'until' => '2026-10-10' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], self::keys( self::search( [ 'since' => '2026-10-02' ] ) ) );
		$this->assertSame( [ 'linear:joint-grant' ], self::keys( self::search( [ 'until' => '2026-10-02' ] ) ) );
	}

	/** A topic filter reads topic links alone; an entity filter reads every link. */
	public function test_a_topic_filter_reads_topic_links_only(): void {
		$this->seed_world();
		$this->seed( self::link( 'link_asserted', 'github:ledger-launch', 'topic:awards', 'mention', 'name', 1.0, self::$oct_13 ) );

		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'topic' => [ 'topic:awards' ] ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], self::keys( self::search( [ 'entity' => 'topic:awards' ] ) ) );
	}

	/** By importance a churn signal scored 8 outranks a newer fundraising letter scored 7; by time the letter leads. */
	public function test_importance_ranks_a_churn_signal_over_a_newer_letter(): void {
		$this->seed(
			self::item( 'linear:quarry-cancels', 'Quarry Wendlemark cancels its plan', 'linear', 'internal', self::$oct_06 ),
			self::extraction( 'linear:quarry-cancels', 'The Wendlemark is leaving.', 'churn_signal', self::$oct_06, [], 3.0, 8.0 ),
			self::item( 'feed:kestrel-appeal', 'Kestrel Weekly asks readers to give', 'feed', 'public', self::$oct_13 ),
			self::extraction( 'feed:kestrel-appeal', 'A year-end appeal.', 'funding', self::$oct_13, [], 8.0, 7.0 )
		);

		$this->assertSame( [ 'linear:quarry-cancels', 'feed:kestrel-appeal' ], self::keys( self::search( [ 'sort' => 'importance' ] ) ) );
		$this->assertSame( [ 'feed:kestrel-appeal', 'linear:quarry-cancels' ], self::keys( self::search( [ 'sort' => 'time' ] ) ) );
	}

	/**
	 * Equal importance falls back to the newest, and an unscored item ranks
	 * last.
	 */
	public function test_importance_breaks_ties_by_time_and_ranks_the_unscored_last(): void {
		$this->seed(
			self::item( 'feed:heron-older', 'Heron Post older note', 'feed', 'public', self::$oct_01 ),
			self::extraction( 'feed:heron-older', 'Older.', 'other', self::$oct_01, [], 2.0, 4.0 ),
			self::item( 'feed:heron-newer', 'Heron Post newer note', 'feed', 'public', self::$oct_13 ),
			self::extraction( 'feed:heron-newer', 'Newer.', 'other', self::$oct_13, [], 2.0, 4.0 ),
			self::item( 'feed:heron-unscored', 'Heron Post unscored note', 'feed', 'public', self::$oct_20 )
		);

		$this->assertSame( [ 'feed:heron-newer', 'feed:heron-older', 'feed:heron-unscored' ], self::keys( self::search( [ 'sort' => 'importance' ] ) ) );
	}

	/** Equal importance ranks the more relevant first, however old it is. */
	public function test_importance_breaks_ties_by_relevance_before_time(): void {
		$this->seed(
			self::item( 'linear:osprey-churn', 'Osprey Ledger weighs leaving', 'linear', 'public', self::$oct_01 ),
			self::extraction( 'linear:osprey-churn', 'Osprey may leave.', 'churn_signal', self::$oct_01, [], 7.0, 8.0 ),
			self::item( 'github:osprey-bug', 'A plugin bug report', 'github', 'public', self::$oct_13 ),
			self::extraction( 'github:osprey-bug', 'A bug.', 'complaint', self::$oct_13, [], 2.0, 8.0 )
		);

		$this->assertSame( [ 'linear:osprey-churn', 'github:osprey-bug' ], self::keys( self::search( [ 'sort' => 'importance' ] ) ) );
	}

	/**
	 * With no sort named, words rank by time and a query without words by
	 * importance; the answer says which.
	 */
	public function test_search_defaults_to_time_with_words_and_importance_without(): void {
		$this->seed_world();

		$worded = self::search( [ 'q' => 'gazette', 'sort' => null ] );
		$bare   = self::search( [ 'sort' => null ] );

		$this->assertSame( [ 'time', [ 'feed:gazette-award', 'linear:joint-grant' ] ], [ $worded['sort'], self::keys( $worded ) ] );
		$this->assertSame( [ 'importance', [ 'feed:gazette-award', 'github:ledger-launch', 'linear:joint-grant' ] ], [ $bare['sort'], self::keys( $bare ) ] );
		$this->assertSame( 'importance', self::search( [ 'q' => 'gazette', 'sort' => 'importance' ] )['sort'] );
	}

	/** A sort other than importance or time is refused. */
	public function test_an_unknown_sort_is_refused(): void {
		$this->seed_world();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'sort must be one of: importance, time' );
		self::search( [ 'sort' => 'relevance' ] );
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
				'published'   => [ 'count' => 0 ],
				'status'      => [ 'client_since' => self::$jan_01, 'kinds' => [ 'churned' => 0, 'churn_signal' => 0, 'feature_request' => 0, 'complaint' => 0 ], 'last_item_ts' => self::$oct_06, 'churn_open' => false, 'churn_items' => [] ],
				'available'   => true,
			],
			self::read( 'entity', [ 'key' => self::GAZETTE ] )
		);
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 1 ], self::read( 'entity', [ 'key' => self::LEDGER ] )['counts'] );
	}

	/**
	 * A churn report counts against a client only as its item's subject: an
	 * item extracted churned that names Harbor Ledger as subject and Prairie
	 * Signal as a mention opens Harbor Ledger's churn and counts nothing for
	 * Prairie Signal.
	 */
	public function test_churn_open_reads_subject_links_only(): void {
		$this->seed_offboarding();

		$harbor  = self::read( 'entity', [ 'key' => self::HARBOR ] )['status'];
		$prairie = self::read( 'entity', [ 'key' => self::PRAIRIE ] )['status'];

		$this->assertSame( [ true, 3 ], [ $harbor['churn_open'], $harbor['kinds']['churned'] ] );
		$this->assertSame( [ false, 0 ], [ $prairie['churn_open'], $prairie['kinds']['churned'] ] );
	}

	/** A client's status names each churn report it rests on, the newest first. */
	public function test_status_names_its_churn_items(): void {
		$this->seed_offboarding();

		$this->assertSame(
			[
				[ 'item_key' => 'linear:harbor-exit-call', 'title' => 'Harbor Ledger exit call', 'url' => self::url( 'linear:harbor-exit-call' ), 'source' => 'linear', 'day' => '2026-10-13' ],
				[ 'item_key' => 'linear:harbor-offboard', 'title' => 'Harbor Ledger offboarding checklist', 'url' => self::url( 'linear:harbor-offboard' ), 'source' => 'linear', 'day' => '2026-10-06' ],
			],
			self::read( 'entity', [ 'key' => self::HARBOR ] )['status']['churn_items']
		);
		$this->assertSame( [], self::read( 'entity', [ 'key' => self::PRAIRIE ] )['status']['churn_items'] );
	}

	/**
	 * Every read shows an entity the roster created under one of its own
	 * domains by the newest canonical name added to it; an alias names
	 * nothing.
	 */
	public function test_an_entity_is_shown_by_its_newest_canonical_name(): void {
		$key = 'organization:site-880044';
		$this->seed(
			self::entity( $key, 'kestrelbay.example', [ 'domain' => [ 'kestrelbay.example' ], 'canonical' => [ 'Kestrel Bay Courier', 'Kestrel Bay Courier Cooperative' ], 'alias' => [ 'KBC' ] ] ),
			self::item( 'feed:kestrel-ferry', 'Ferry schedule set', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_asserted', 'feed:kestrel-ferry', $key, 'subject', 'identifier', 1.0, self::$oct_13 )
		);

		$this->assertSame( 'Kestrel Bay Courier Cooperative', self::read( 'entity', [ 'key' => $key ] )['name'] );
		$this->assertSame( [ [ 'entity_key' => $key, 'name' => 'Kestrel Bay Courier Cooperative', 'state' => 'asserted', 'role' => 'subject', 'client' => false ] ], self::search( [ 'q' => 'ferry' ] )['items'][0]['entities'] );
	}

	/** An entity created under a name of its own shows a canonical name added since, in its record and in what lists it. */
	public function test_an_entity_created_under_a_name_shows_a_canonical_added_since(): void {
		$key = 'organization:osprey-times';
		$this->seed(
			self::entity( $key, 'Osprey Times', [ 'domain' => [ 'ospreytimes.example' ], 'canonical' => [ 'Osprey Times Media Group' ] ] ),
			self::item( 'feed:osprey-tide', 'Osprey tide tables', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_asserted', 'feed:osprey-tide', $key, 'subject', 'identifier', 1.0, self::$oct_13 )
		);

		$this->assertSame( 'Osprey Times Media Group', self::read( 'entity', [ 'key' => $key ] )['name'] );
		$this->assertSame( 'Osprey Times Media Group', self::search( [ 'q' => 'tide' ] )['items'][0]['entities'][0]['name'] );
	}

	/**
	 * A listed item's excerpt is its body's opening as plain text.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function excerpts(): array {
		$words = \trim( \str_repeat( 'marsh heron ', 90 ) );
		return [
			'markup and entities'  => [ "<p>Tide &amp; marsh</p>\n\n<p>tables&nbsp;rise</p>", 'Tide & marsh tables rise' ],
			'an empty body'        => [ '', '' ],
			'markup alone'         => [ '<img src="https://heron.example/a.png"><br>', '' ],
			'just at the bound'    => [ \str_repeat( 'é', 800 ), \str_repeat( 'é', 800 ) ],
			'cut on a word'        => [ $words, \substr( $words, 0, 797 ) . '…' ],
			'one word past it'     => [ \str_repeat( 'é', 900 ), \str_repeat( 'é', 800 ) . '…' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'excerpts' )]
	public function test_a_listed_item_answers_its_excerpt( string $body, string $excerpt ): void {
		$this->seed( self::item( 'feed:osprey-excerpt', 'Osprey tide tables', 'feed', 'public', self::$oct_13, $body ) );

		$this->assertSame( 800, Graph_Query::EXCERPT_CHARS );
		$this->assertSame( $excerpt, self::search( [ 'q' => 'osprey' ] )['items'][0]['excerpt'] );
	}

	/**
	 * A read that stops inside a `<style>` or `<script>` shows none of its
	 * source: what precedes it stands whole, its last word kept, marked as
	 * cut. A closed one is stripped as any tag is.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function bodies_with_source(): array {
		return [
			'an open style'   => [ '<p>Tide report</p><style>' . \str_repeat( '.marsh { color: red; } ', 300 ), 'Tide report…' ],
			'an open script'  => [ '<p>Tide report</p><SCRIPT type="text/javascript">' . \str_repeat( 'var heron = 1; ', 400 ), 'Tide report…' ],
			'closed'          => [ '<style>.marsh { color: red; }</style><p>Tide report</p><script>var heron = 1;</script> whole', 'Tide report whole' ],
			'closed then open' => [ '<style>.a { }</style>Tide report<style>' . \str_repeat( '.marsh { color: red; } ', 300 ), 'Tide report…' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bodies_with_source' )]
	public function test_an_excerpt_shows_no_style_or_script_source( string $body, string $excerpt ): void {
		$this->seed( self::item( 'feed:osprey-source', 'Osprey tide tables', 'feed', 'public', self::$oct_13, $body ) );

		$this->assertSame( $excerpt, self::search( [ 'q' => 'osprey' ] )['items'][0]['excerpt'] );
	}

	/** A body far past what is read still ends its excerpt on a word, and an answer still carries no body. */
	public function test_an_excerpt_is_read_from_the_bodys_opening_alone(): void {
		$this->seed( self::item( 'feed:osprey-long', 'Osprey tide tables', 'feed', 'public', self::$oct_13, '<div class="wrap">' . \str_repeat( '<span class="x">a</span>', 2000 ) ) );

		$item = self::search( [ 'q' => 'osprey' ] )['items'][0];

		$this->assertStringEndsWith( '…', $item['excerpt'] );
		$this->assertLessThanOrEqual( 801, \mb_strlen( $item['excerpt'] ) );
		$this->assertArrayNotHasKey( 'body', $item );
	}

	/** A theme group's items answer the excerpt too. */
	public function test_a_grouped_item_answers_its_excerpt(): void {
		$this->seed(
			self::item( 'feed:osprey-group', 'Osprey tide tables', 'feed', 'public', self::$oct_13, '<p>Tide &amp; marsh</p>' ),
			self::extraction( 'feed:osprey-group', 'Tide tables move.', 'research', self::$oct_13, [], 5.0, 5.0, 'tide table changes' )
		);

		$this->assertSame( 'Tide & marsh', self::search( [ 'group' => 'theme', 'sort' => null ] )['groups'][0]['items'][0]['excerpt'] );
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
					[ 'entity_key' => 'topic:awards', 'name' => 'Awards', 'type' => 'topic', 'shared_items' => 2, 'items' => self::cited( 'feed:gazette-award', 'linear:joint-grant' ) ],
					[ 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'type' => 'organization', 'shared_items' => 1, 'items' => self::cited( 'linear:joint-grant' ) ],
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

		$won      = [ 'subject' => self::GAZETTE, 'subject_name' => 'Harbor Gazette', 'predicate' => 'won', 'object' => 'State Press Award', 'object_name' => null, 'confidence' => 0.95, 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award', 'title' => 'Harbor Gazette wins state award' ];
		$partners = [ 'subject' => self::GAZETTE, 'subject_name' => 'Harbor Gazette', 'predicate' => 'partners_with', 'object' => self::LEDGER, 'object_name' => 'Salem Ledger', 'confidence' => 0.95, 'item_key' => 'linear:joint-grant', 'url' => 'https://news.example/linear/joint-grant', 'title' => 'Gazette and Ledger share a grant' ];
		$this->assertSame( [ 'claims' => [ $won, $partners ], 'available' => true ], self::read( 'claims', [ 'key' => self::GAZETTE ] ) );
		$this->assertSame( [ $partners ], self::read( 'claims', [ 'key' => self::LEDGER ] )['claims'] );
		$this->assertSame( [ $won ], self::read( 'claims', [ 'key' => self::GAZETTE, 'predicate' => 'won' ] )['claims'] );
	}

	/**
	 * A subject, and an object the caller may see, that hold no name answer
	 * their keys as their names, so object_name is null for free text alone.
	 */
	public function test_a_claim_names_an_unnamed_entity_by_its_key(): void {
		$subject = 'organization:egret-unnamed-press';
		$object  = 'organization:egret-unnamed-fund';
		$this->seed_world();
		$this->seed(
			self::link( 'link_asserted', 'feed:gazette-award', $subject, 'mention', 'name', 1.0, self::$oct_06 ),
			[ Intel_Event::make( 'entity_accepted', [ 'entity_key' => $object ], 'user:41', 'fixture-9' ) ],
			self::claim( 'feed:gazette-award', 3, $subject, 'funds', $object, 'the Egret fund', self::$oct_06 )
		);

		$claim = self::read( 'claims', [ 'key' => $subject ] )['claims'][0];

		$this->assertSame( [ $subject, $subject, $object, $object ], [ $claim['subject'], $claim['subject_name'], $claim['object'], $claim['object_name'] ] );
	}

	public function test_trends_count_kinds_and_topics_per_bucket(): void {
		$this->seed_world();

		$this->assertSame(
			[
				'rows'      => [
					[ 'bucket' => '2026-10-12', 'value' => 'launch', 'count' => 1, 'items' => self::cited( 'github:ledger-launch' ) ],
					[ 'bucket' => '2026-10-05', 'value' => 'award', 'count' => 1, 'items' => self::cited( 'feed:gazette-award' ) ],
					[ 'bucket' => '2026-09-28', 'value' => 'funding', 'count' => 1, 'items' => self::cited( 'linear:joint-grant' ) ],
				],
				'available' => true,
			],
			self::trends( [ 'by' => 'kind' ] )
		);
		$this->assertSame(
			[
				[ 'bucket' => '2026-10-01', 'value' => 'topic:awards', 'count' => 2, 'items' => self::cited( 'feed:gazette-award', 'linear:joint-grant' ) ],
				[ 'bucket' => '2026-10-01', 'value' => 'topic:ai', 'count' => 1, 'items' => self::cited( 'github:ledger-launch' ) ],
			],
			self::trends( [ 'by' => 'topic', 'bucket' => 'month' ] )['rows']
		);
		$this->assertSame( [ '2026-10-13', '2026-10-06' ], \array_column( self::trends( [ 'by' => 'kind', 'bucket' => 'day', 'since' => '2026-10-02' ] )['rows'], 'bucket' ) );
	}

	/** An item two partitions both hold counts once, and is cited once. */
	public function test_an_item_two_partitions_hold_counts_once(): void {
		$copy = [ self::item( 'feed:egret-gala', 'Egret gala', 'feed', 'public', self::$oct_06 ), self::extraction( 'feed:egret-gala', 'A gala.', 'award', self::$oct_06, [] ) ];
		$this->seed( ...$copy );
		$this->second_partition( ...$copy );

		$this->assertSame(
			[ [ 'bucket' => '2026-10-05', 'value' => 'award', 'count' => 1, 'items' => [ [ 'item_key' => 'feed:egret-gala', 'url' => self::url( 'feed:egret-gala' ), 'title' => 'Egret gala' ] ] ] ],
			self::trends( [ 'by' => 'kind' ] )['rows']
		);
	}

	/**
	 * A story's canonical is the newest partition's; one the caller may not
	 * read leaves every copy counting as itself, never an older partition's
	 * readable canonical standing in.
	 */
	public function test_the_newest_partitions_canonical_names_the_story(): void {
		$copies = [ 'feed:egret-wire-1', 'feed:egret-wire-2', 'feed:egret-wire-3' ];
		$this->seed(
			self::item( $copies[0], 'Egret wire one', 'feed', 'public', self::$oct_06 ),
			self::extraction( $copies[0], 'A wire.', 'award', self::$oct_06, [] ),
			self::item( $copies[1], 'Egret wire two', 'feed', 'restricted', self::$oct_06 + 60 ),
			self::extraction( $copies[1], 'A wire.', 'award', self::$oct_06 + 60, [] ),
			self::item( $copies[2], 'Egret wire three', 'feed', 'public', self::$oct_06 + 120 ),
			self::extraction( $copies[2], 'A wire.', 'award', self::$oct_06 + 120, [] ),
			self::story( $copies[0], $copies, self::$oct_06 )
		);
		$this->assertSame( [ [ 1, [ $copies[0] ] ] ], self::counted( self::trends( [ 'by' => 'kind' ] ) ) );

		$this->second_partition( [ Intel_Event::make( 'cluster_superseded', [ 'cluster_key' => $copies[0], 'canonical_item_key' => $copies[1], 'previous_item_key' => $copies[0], 'rank' => 15.25, 'retrieved_ts' => self::$oct_06 + 600 ], 'machine:dedup@9.4.1', 'fixture-9' ) ] );

		$this->assertSame( [ [ 2, [ $copies[2], $copies[0] ] ] ], self::counted( self::trends( [ 'by' => 'kind' ] ) ) );
	}

	/**
	 * A story is dated by the earliest copy the days asked admit, and cited
	 * by its canonical, so a canonical before or after those days never
	 * moves it out of them.
	 */
	public function test_a_story_is_dated_by_its_earliest_admitted_copy(): void {
		$canonical = 'feed:heron-flood-0';
		$copies    = [ $canonical, 'feed:heron-flood-1', 'feed:heron-flood-2', 'feed:heron-flood-3' ];
		$events    = [];
		foreach ( $copies as $n => $copy ) {
			$at       = self::$oct_13 - 2 * 86400 + $n * 86400;
			$events[] = self::item( $copy, "Heron flood day {$n}", 'feed', 'public', $at );
			$events[] = self::extraction( $copy, 'A flood.', 'incident', $at, [] );
		}
		$this->seed( ...$events );
		$this->seed( self::story( $canonical, $copies, self::$oct_13 - 2 * 86400 ) );
		$cited = [ [ 'item_key' => $canonical, 'url' => self::url( $canonical ), 'title' => 'Heron flood day 0' ] ];

		$this->assertSame( [ [ 'bucket' => '2026-10-12', 'value' => 'incident', 'count' => 1, 'items' => $cited ] ], self::trends( [ 'by' => 'kind', 'since' => '2026-10-12' ] )['rows'] );
		$this->assertSame( [ [ 'bucket' => '2026-10-12', 'value' => 'incident', 'count' => 1, 'items' => $cited ] ], self::trends( [ 'by' => 'kind', 'bucket' => 'day', 'since' => '2026-10-12' ] )['rows'] );
		$this->assertSame( [ [ 'bucket' => '2026-10-05', 'value' => 'incident', 'count' => 1, 'items' => $cited ] ], self::trends( [ 'by' => 'kind' ] )['rows'] );

		$this->seed( self::story( 'feed:heron-flood-3', $copies, self::$oct_13 - 2 * 86400 ) );
		$cited = [ [ 'item_key' => 'feed:heron-flood-3', 'url' => self::url( 'feed:heron-flood-3' ), 'title' => 'Heron flood day 3' ] ];
		$this->assertSame( [ [ 'bucket' => '2026-10-12', 'value' => 'incident', 'count' => 1, 'items' => $cited ] ], self::trends( [ 'by' => 'kind', 'bucket' => 'day', 'since' => '2026-10-12', 'until' => '2026-10-13' ] )['rows'] );
	}

	/**
	 * A story counts once, as its cluster's canonical: its copies' rows
	 * collapse into one, cited by the canonical.
	 */
	public function test_trends_count_a_story_once_as_its_canonical(): void {
		$copies = [ 'feed:plover-wire-1', 'feed:plover-wire-2', 'feed:plover-wire-3', 'feed:plover-wire-4' ];
		$events = [];
		foreach ( $copies as $n => $copy ) {
			$events[] = self::item( $copy, "Plover wire copy {$n}", 'feed', 'public', self::$oct_06 + $n * 60 );
			$events[] = self::extraction( $copy, 'A syndicated award.', 'award', self::$oct_06 + $n * 60, [] );
		}
		$this->seed( ...$events );
		$this->seed( self::item( 'feed:osprey-medal', 'Osprey medal', 'feed', 'public', self::$oct_06 - 3600 ), self::extraction( 'feed:osprey-medal', 'A medal.', 'award', self::$oct_06 - 3600, [] ) );
		$this->assertSame( [ 5 ], \array_column( self::trends( [ 'by' => 'kind' ] )['rows'], 'count' ) );

		$this->seed( self::story( 'feed:plover-wire-3', $copies, self::$oct_06 ) );

		$this->assertSame(
			[
				[
					'bucket' => '2026-10-05',
					'value'  => 'award',
					'count'  => 2,
					'items'  => [
						[ 'item_key' => 'feed:plover-wire-3', 'url' => self::url( 'feed:plover-wire-3' ), 'title' => 'Plover wire copy 2' ],
						[ 'item_key' => 'feed:osprey-medal', 'url' => self::url( 'feed:osprey-medal' ), 'title' => 'Osprey medal' ],
					],
				],
			],
			self::trends( [ 'by' => 'kind' ] )['rows']
		);
	}

	/** A role filter counts items linking an entity holding the role at the item's time. */
	public function test_trends_filter_by_role_and_entity(): void {
		$this->seed_world();
		$this->seed( [ Intel_Event::make( 'role_asserted', [ 'entity_key' => self::LEDGER, 'role' => 'client', 'from_ts' => self::$oct_06 ], 'user:41', 'fixture-9' ) ] );

		$this->assertSame( [ 'launch', 'award', 'funding' ], \array_column( self::trends( [ 'by' => 'kind', 'role' => [ 'client' ] ] )['rows'], 'value' ) );
		$this->assertSame( [ 'award', 'funding' ], \array_column( self::trends( [ 'by' => 'kind', 'role' => [ 'client' ], 'until' => '2026-10-10' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'launch', 'funding' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => self::LEDGER ] )['rows'], 'value' ) );
		$this->assertSame( [], self::trends( [ 'by' => 'kind', 'role' => [ 'funder' ] ] )['rows'] );
	}

	/** A role filter over a window answers exactly the unwindowed answer's items inside it. */
	public function test_a_windowed_role_filter_answers_the_window_of_the_whole(): void {
		$this->seed_world();
		$this->seed(
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => self::LEDGER, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ],
			self::item( 'feed:ledger-hire', 'Salem Ledger hires an editor', 'feed', 'public', self::$oct_20 - 3600 ),
			self::extraction( 'feed:ledger-hire', 'An editor joins.', 'hire', self::$oct_20 - 3600, [] ),
			self::link( 'link_asserted', 'feed:ledger-hire', self::LEDGER, 'subject', 'name', 1.0, self::$oct_20 - 3600 )
		);
		$window = static fn ( array $items ): array => \array_values( \array_filter( $items, static fn ( array $item ): bool => $item['item_ts'] >= self::$oct_06 - 43200 && $item['item_ts'] < self::$oct_13 + 43200 ) );

		$whole = self::search( [ 'role' => [ 'client' ] ] )['items'];
		$this->assertSame( [ 'feed:ledger-hire', 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $whole, 'item_key' ) );
		$this->assertSame( $window( $whole ), self::search( [ 'role' => [ 'client' ], 'since' => '2026-10-06', 'until' => '2026-10-13' ] )['items'] );
		$this->assertSame( [ 'launch', 'award' ], \array_column( self::trends( [ 'by' => 'kind', 'role' => [ 'client' ], 'since' => '2026-10-06', 'until' => '2026-10-13' ] )['rows'], 'value' ) );
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
			\array_map( static fn ( array $row ): array => \array_diff_key( $row, [ 'items' => 1 ] ) + [ 'item_keys' => \array_column( $row['items'], 'item_key' ) ], self::trends( [ 'by' => 'kind' ] )['rows'] )
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
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award', 'Tidewater Times judged the state award.' ),
			self::link( 'link_proposed', 'github:ledger-launch', 'organization:tidewater-times', 'mention', 'ner_fuzzy', 0.62, self::$oct_13, 'Tidewater Times readers asked for the tool.' )
		);

		$this->assertSame(
			[
				'links'     => [
					[ 'item_key' => 'github:ledger-launch', 'entity_key' => 'organization:tidewater-times', 'name' => 'Tidewater Times', 'type' => 'organization', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.62, 'evidence' => 'Tidewater Times readers asked for the tool.', 'title' => 'Salem Ledger launches a newsletter tool', 'url' => 'https://news.example/github/ledger-launch' ],
					[ 'item_key' => 'linear:joint-grant', 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'type' => 'organization', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'evidence' => '', 'title' => 'Gazette and Ledger share a grant', 'url' => 'https://news.example/linear/joint-grant' ],
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
							[ 'item_key' => 'github:ledger-launch', 'url' => 'https://news.example/github/ledger-launch', 'title' => 'Salem Ledger launches a newsletter tool', 'evidence' => 'Tidewater Times readers asked for the tool.' ],
							[ 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award', 'title' => 'Harbor Gazette wins state award', 'evidence' => 'Tidewater Times judged the state award.' ],
						],
					],
				],
				'available' => true,
			],
			self::read( 'review', [ 'what' => 'entities' ] )
		);
	}

	/** A proposed link and a proposal written before proposals carried evidence reach the review rows with none. */
	public function test_a_proposal_written_before_evidence_reaches_review_with_none(): void {
		$this->seed_world();
		$this->seed( self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ) );

		$this->assertSame( [ [ 'linear:joint-grant', '' ] ], \array_map( static fn ( array $l ): array => [ $l['item_key'], $l['evidence'] ], self::read( 'review', [ 'what' => 'links' ] )['links'] ) );
		$this->assertSame( [ [ 'feed:gazette-award', '' ] ], \array_map( static fn ( array $i ): array => [ $i['item_key'], $i['evidence'] ], self::read( 'review', [ 'what' => 'entities' ] )['entities'][0]['items'] ) );
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
				'items'          => [ [ 'item_key' => 'feed:gazette-award', 'url' => 'https://news.example/feed/gazette-award', 'title' => 'Harbor Gazette wins state award', 'actor' => 'user:41', 'saved_ts' => self::$oct_20, 'marks' => [] ] ],
				'available'      => true,
			],
			\array_diff_key( self::read( 'collections', [ 'key' => 'col:october' ] ), [ 'created_ts' => true ] )
		);
		$this->expectException( \InvalidArgumentException::class );
		self::read( 'collections', [ 'key' => 'col:missing' ] );
	}

	/**
	 * Every read leaves a purged item out — search, an entity's counts, trends,
	 * related, claims or the review queue — though its judgment and a
	 * collection's saved copy stay, and the collection still lists the copy.
	 */
	public function test_a_purged_item_answers_no_read_but_a_collections_saved_copy(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'linear:joint-grant', 0, self::GAZETTE, 'partners_with', self::LEDGER, 'Salem Ledger', self::$oct_01 ),
			self::judged( 'link_confirmed', 'linear:joint-grant', self::LEDGER, 'user:41' ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:grants', 'name' => 'Grants' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:grants', 'item_key' => 'linear:joint-grant', 'url' => self::url( 'linear:joint-grant' ), 'title' => 'Gazette and Ledger share a grant', 'tier' => 'internal' ], 'user:41', 'fixture-9' ),
			]
		);
		$this->assertSame( [ 'linear:joint-grant' ], self::keys( self::search( [ 'q' => 'grant' ] ) ) );

		$this->seed( [ Intel_Event::make( 'item_purged', [ 'item_key' => 'linear:joint-grant', 'reason' => 'another team' ], 'machine:purge@9.4.1', 'fixture-9' ) ] );

		$this->assertSame( [], self::keys( self::search( [ 'q' => 'grant' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], self::keys( self::search( [] ) ) );
		$this->assertSame( [ 'items' => 1, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'items' => 1, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::LEDGER ] )['counts'] );
		$this->assertSame( [ 'launch', 'award' ], \array_column( self::trends( [ 'by' => 'kind' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'topic:awards' => 1 ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE ] )['entities'], 'shared_items', 'entity_key' ) );
		$this->assertSame( [], self::read( 'claims', [ 'key' => self::GAZETTE ] )['claims'] );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( self::read( 'collections', [ 'key' => 'col:grants' ] )['items'], 'item_key' ) );
	}

	/**
	 * Every read leaves an excluded item out — search, an entity's counts,
	 * trends, related, claims, the review queue and a collection holding
	 * its saved copy — and answers it again once it is included.
	 */
	public function test_an_excluded_item_answers_no_read_until_it_is_included(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'linear:joint-grant', 0, self::GAZETTE, 'partners_with', self::LEDGER, 'Salem Ledger', self::$oct_01 ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:grants', 'name' => 'Grants' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:grants', 'item_key' => 'linear:joint-grant', 'url' => self::url( 'linear:joint-grant' ), 'title' => 'Gazette and Ledger share a grant', 'tier' => 'internal' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'item_excluded', [ 'item_key' => 'linear:joint-grant', 'url' => self::url( 'linear:joint-grant' ), 'title' => 'Gazette and Ledger share a grant', 'tier' => 'internal', 'why' => 'A duplicate of the grant notice.' ], 'user:41', 'fixture-9' ),
			]
		);

		$this->assertSame( [], self::keys( self::search( [ 'q' => 'grant' ] ) ) );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], self::keys( self::search( [] ) ) );
		$this->assertSame( [ 'items' => 1, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'launch', 'award' ], \array_column( self::trends( [ 'by' => 'kind' ] )['rows'], 'value' ) );
		$this->assertSame( [ 'topic:awards' => 1 ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE ] )['entities'], 'shared_items', 'entity_key' ) );
		$this->assertSame( [], self::read( 'claims', [ 'key' => self::GAZETTE ] )['claims'] );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );
		$this->assertSame( [], self::read( 'collections', [ 'key' => 'col:grants' ] )['items'] );
		$this->assertSame( [ 'col:grants' => 0 ], \array_column( self::read( 'collections', [] )['collections'], 'items', 'collection_key' ) );

		$this->seed( [ Intel_Event::make( 'item_included', [ 'item_key' => 'linear:joint-grant' ], 'user:41', 'fixture-9' ) ] );

		$this->assertSame( [ 'linear:joint-grant' ], self::keys( self::search( [ 'q' => 'grant' ] ) ) );
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( self::read( 'collections', [ 'key' => 'col:grants' ] )['items'], 'item_key' ) );
	}

	/**
	 * The excluded list names each exclusion of a tier the caller reads,
	 * the latest first, with who excluded it, when and why, by the title its
	 * item reads now, and by the title captured at the exclusion for an item
	 * the graph no longer holds, and caps its rows at the limit.
	 */
	public function test_the_excluded_list_names_each_readable_exclusion_latest_first(): void {
		$this->seed_world();
		$excluded = static function ( string $key, string $tier, int $at, array $why ): array {
			$event       = Intel_Event::make( 'item_excluded', [ 'item_key' => $key, 'url' => self::url( $key ), 'title' => "Copy of {$key}", 'tier' => $tier ] + $why, 'user:kinsey-reed', 'fixture-9' );
			$event['ts'] = (float) $at;
			return $event;
		};
		$this->seed(
			[
				$excluded( 'feed:gazette-award', 'public', self::$oct_13, [ 'why' => 'Shown twice.' ] ),
				$excluded( 'linear:expired-9', 'internal', self::$oct_20, [] ),
				$excluded( 'hubspot:deal-9', 'restricted', self::$oct_20 + 60, [ 'why' => 'Restricted.' ] ),
			]
		);

		$this->assertSame(
			[
				'items'     => [
					[ 'item_key' => 'linear:expired-9', 'title' => 'Copy of linear:expired-9', 'url' => self::url( 'linear:expired-9' ), 'actor' => 'user:kinsey-reed', 'excluded_ts' => self::$oct_20, 'why' => '' ],
					[ 'item_key' => 'feed:gazette-award', 'title' => 'Harbor Gazette wins state award', 'url' => self::url( 'feed:gazette-award' ), 'actor' => 'user:kinsey-reed', 'excluded_ts' => self::$oct_13, 'why' => 'Shown twice.' ],
				],
				'available' => true,
			],
			Graph_Query::excluded( [ 'limit' => 50 ] )
		);
		$this->assertSame( [ 'linear:expired-9' ], \array_column( Graph_Query::excluded( [ 'limit' => 1 ] )['items'], 'item_key' ) );
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
		$this->assertSame( [ [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted', 'role' => 'mention', 'client' => true ], [ 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'state' => 'asserted', 'role' => 'subject', 'client' => false ] ], self::search( [ 'q' => 'editor' ] )['items'][0]['entities'] );
		$this->assertSame( [ 'feed:old-ledger-hire', 'linear:joint-grant' ], \array_column( \array_column( self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] )['entities'], 'items', 'entity_key' )[ self::LEDGER ], 'item_key' ) );
		$this->assertSame( [ 'hire', 'launch', 'funding' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => 'organization:ledger-of-salem' ] )['rows'], 'value' ) );
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
		$this->assertSame( [ [ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted', 'role' => 'subject', 'client' => true ] ], self::search( [ 'q' => 'grant' ] )['items'][0]['entities'] );
		$this->assertSame( [ 'items' => 1, 'proposed_links' => 0 ], self::read( 'entity', [ 'key' => self::LEDGER ] )['counts'] );
		$this->assertSame( [ 'topic:awards' ], \array_column( self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] )['entities'], 'entity_key' ) );
		$this->assertSame( [ 'launch' ], \array_column( self::trends( [ 'by' => 'kind', 'entity' => self::LEDGER ] )['rows'], 'value' ) );
		$this->assertSame( [], self::read( 'review', [ 'what' => 'links' ] )['links'] );
	}

	/**
	 * An item's entities each name the role their link holds and whether the
	 * entity is an open client, an open client first, then a subject, then a
	 * mention; a client whose role ended is a client no longer.
	 */
	public function test_an_items_entities_name_their_link_role_and_lead_with_a_client(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:auk-courier', 'Auk Courier' ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:auk-courier', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_ended', [ 'entity_key' => 'organization:auk-courier', 'role' => 'client', 'from_ts' => self::$jan_01, 'to_ts' => self::$oct_01 ], 'user:41', 'fixture-9' ),
			],
			self::item( 'feed:tern-ferry', 'Ferry pilots strike', 'feed', 'public', self::$oct_13 ),
			self::link( 'link_asserted', 'feed:tern-ferry', 'organization:auk-courier', 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_asserted', 'feed:tern-ferry', self::GAZETTE, 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_asserted', 'feed:tern-ferry', self::LEDGER, 'subject', 'name', 1.0, self::$oct_13 )
		);

		$this->assertSame(
			[
				[ 'entity_key' => self::GAZETTE, 'name' => 'Harbor Gazette', 'state' => 'asserted', 'role' => 'mention', 'client' => true ],
				[ 'entity_key' => self::LEDGER, 'name' => 'Salem Ledger', 'state' => 'asserted', 'role' => 'subject', 'client' => false ],
				[ 'entity_key' => 'organization:auk-courier', 'name' => 'Auk Courier', 'state' => 'asserted', 'role' => 'mention', 'client' => false ],
			],
			self::search( [ 'q' => 'ferry' ] )['items'][0]['entities']
		);
	}

	/**
	 * An item's entity is a client when the open client role sits on a key
	 * merged into it, read through the survivor map the statement binds, in
	 * a plain search and in a theme group alike.
	 */
	public function test_an_items_client_reads_through_a_key_merged_into_it(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:old-ledger', 'Old Salem Ledger' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:old-ledger', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ],
			self::merged( 'organization:old-ledger', self::LEDGER )
		);

		$client = static fn ( array $items ): array => \array_column( $items[0]['entities'], 'client', 'entity_key' );
		$this->assertSame( [ self::LEDGER => true ], $client( self::search( [ 'q' => 'newsletter tool' ] )['items'] ) );
		$this->assertSame( [ self::LEDGER => true ], $client( self::search( [ 'q' => 'newsletter tool', 'group' => 'theme', 'sort' => null ] )['groups'][0]['items'] ) );
	}

	/** No item shows an entity whose survivor is rejected: its link stays stored, and lists no chip. */
	public function test_entities_leave_out_a_rejected_survivor(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'product:tern-relay', 'Tern Relay' ),
			self::link( 'link_asserted', 'feed:gazette-award', 'product:tern-relay', 'mention', 'name', 1.0, self::$oct_06 ),
			[ Intel_Event::make( 'entity_rejected', [ 'entity_key' => 'product:tern-relay' ], 'user:41', 'fixture-9' ) ]
		);

		$this->assertSame( [ self::GAZETTE ], \array_column( self::search( [ 'q' => 'state award' ] )['items'][0]['entities'], 'entity_key' ) );
		$this->assertSame( [ self::GAZETTE ], \array_column( self::search( [ 'q' => 'state award', 'group' => 'theme', 'sort' => null ] )['groups'][0]['items'][0]['entities'], 'entity_key' ) );
	}

	/** A rejected topic link drops the item from that topic's trend and filter. */
	public function test_a_rejected_topic_link_counts_nowhere(): void {
		$this->seed_world();
		$this->seed( self::judged( 'link_rejected', 'linear:joint-grant', 'topic:awards', 'user:41' ) );

		$this->assertSame( [ 'feed:gazette-award' ], self::keys( self::search( [ 'topic' => [ 'topic:awards' ] ] ) ) );
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

		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => false, 'sort' => 'time' ], self::search( [ 'q' => 'award' ] ) );
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => false ], self::read( 'timeline', [ 'key' => self::GAZETTE, 'limit' => 50, 'offset' => 0 ] ) );
		$this->assertFalse( self::read( 'entity', [ 'key' => self::GAZETTE ] )['available'] );
		$this->assertSame( [ 'entities' => [], 'available' => false ], self::read( 'related', [ 'key' => self::GAZETTE, 'limit' => 20 ] ) );
		$this->assertSame( [ 'claims' => [], 'available' => false ], self::read( 'claims', [ 'key' => self::GAZETTE ] ) );
		$this->assertSame( [ 'rows' => [], 'available' => false ], self::trends( [ 'by' => 'kind' ] ) );
		$this->assertSame( [ 'links' => [], 'available' => false ], self::read( 'review', [ 'what' => 'links' ] ) );
		$this->assertSame( [ 'collections' => [], 'available' => false ], self::read( 'collections', [] ) );
		$this->assertNotSame( [], self::stderr_lines( $stderr, 'intel-graph' ) );
	}

	/** A reader that has no partition file attached sees no entity. */
	public function test_no_entity_is_visible_before_any_partition_writes(): void {
		$reader = \Newspack_Nodes\Sqlite_Arm::open_rows( [ 0 => $this->make_temp_dir( 'intel-unwritten-' ) . '/intel-graph.p0.sqlite' ], Intel_Graph_Row_Schema::version() );

		$this->assertSame( [], $reader->aliases() );
		$this->assertNull( Graph_Query::visible( $reader, Graph_Query::graph( $reader ), 'organization:harbor-gazette' ) );
	}

	/** Before any partition has written a file, every read answers nothing, available. */
	public function test_an_unwritten_graph_answers_empty(): void {
		$this->assertSame( [ 'items' => [], 'total' => 0, 'available' => true, 'sort' => 'time' ], self::search( [ 'q' => 'award' ] ) );
		$this->assertSame( [ 'rows' => [], 'available' => true ], self::trends( [ 'by' => 'topic' ] ) );
	}

	/**
	 * A command's read over the readable tiers, its defaults filled in.
	 *
	 * @param string              $verb The Graph_Query read.
	 * @param array<string,mixed> $args The command's args.
	 * @return array<string,mixed>
	 */
	private static function read( string $verb, array $args ): array {
		$defaults = [
			'entity'      => [],
			'timeline'    => [ 'published' => false, 'limit' => 50, 'offset' => 0 ],
			'related'     => [ 'limit' => 20 ],
			'claims'      => [ 'predicate' => null, 'limit' => 50 ],
			'review'      => [ 'limit' => 50 ],
			'collections' => [ 'key' => null ],
		];
		return Graph_Query::$verb( $args + $defaults[ $verb ] );
	}

	/**
	 * Harbor Ledger and Prairie Signal, both clients since 1 January, and two
	 * churn reports naming Harbor Ledger as subject, the first also naming
	 * Prairie Signal as a mention, with a churn report from before the year
	 * about Harbor Ledger, which names nothing.
	 */
	private function seed_offboarding(): void {
		$before = self::$jan_01 - 86400;
		$this->seed(
			self::entity( self::HARBOR, 'Harbor Ledger', [ 'domain' => [ 'harborledger.example' ] ] ),
			self::entity( self::PRAIRIE, 'Prairie Signal', [ 'domain' => [ 'prairiesignal.example' ] ] ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => self::HARBOR, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'role_asserted', [ 'entity_key' => self::PRAIRIE, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
			],
			self::item( 'linear:harbor-offboard', 'Harbor Ledger offboarding checklist', 'linear', 'internal', self::$oct_06 ),
			self::extraction( 'linear:harbor-offboard', 'Harbor Ledger is leaving.', 'churned', self::$oct_06, [] ),
			self::link( 'link_asserted', 'linear:harbor-offboard', self::HARBOR, 'subject', 'name', 1.0, self::$oct_06 ),
			self::link( 'link_asserted', 'linear:harbor-offboard', self::PRAIRIE, 'mention', 'name', 1.0, self::$oct_06 ),
			self::item( 'linear:harbor-exit-call', 'Harbor Ledger exit call', 'linear', 'internal', self::$oct_13 ),
			self::extraction( 'linear:harbor-exit-call', 'Harbor Ledger confirmed it leaves.', 'churned', self::$oct_13, [] ),
			self::link( 'link_asserted', 'linear:harbor-exit-call', self::HARBOR, 'subject', 'name', 1.0, self::$oct_13 ),
			self::item( 'linear:harbor-old-exit', 'Harbor Ledger left once', 'linear', 'internal', $before ),
			self::extraction( 'linear:harbor-old-exit', 'Harbor Ledger left.', 'churned', $before, [] ),
			self::link( 'link_asserted', 'linear:harbor-old-exit', self::HARBOR, 'subject', 'name', 1.0, $before )
		);
	}

	/**
	 * A search over the readable tiers, the command's defaults filled in, by
	 * time unless the args name a sort, so a filter's test reads one order.
	 *
	 * @param array<string,mixed> $args The command's args.
	 * @return array<string,mixed>
	 */
	private static function search( array $args ): array {
		return Graph_Query::search( $args + [ 'q' => null, 'sort' => 'time', 'entity' => null, 'lens' => null, 'since' => null, 'until' => null, 'limit' => 50, 'offset' => 0, 'kind' => [], 'topic' => [], 'role' => [], 'source' => [] ] );
	}

	/**
	 * Each trend row's count and the keys it cites.
	 *
	 * @param array<string,mixed> $answer A trends answer.
	 * @return list<array{0:int,1:list<string>}>
	 */
	private static function counted( array $answer ): array {
		return \array_map( static fn ( array $row ): array => [ $row['count'], \array_column( $row['items'], 'item_key' ) ], $answer['rows'] );
	}

	/**
	 * Trends over the readable tiers, the command's defaults filled in.
	 *
	 * @param array<string,mixed> $args The command's args.
	 * @return array<string,mixed>
	 */
	private static function trends( array $args ): array {
		return Graph_Query::trends( $args + [ 'entity' => null, 'lens' => null, 'since' => null, 'until' => null, 'bucket' => null, 'kind' => [], 'topic' => [], 'role' => [], 'source' => [] ] );
	}

	/**
	 * Fixture items as a citation lists them: key, URL and title.
	 *
	 * @param string ...$keys The item keys, in order.
	 * @return list<array{item_key:string,url:string,title:string}>
	 */
	private static function cited( string ...$keys ): array {
		$titles = [
			'feed:gazette-award'   => 'Harbor Gazette wins state award',
			'github:ledger-launch' => 'Salem Ledger launches a newsletter tool',
			'linear:joint-grant'   => 'Gazette and Ledger share a grant',
		];
		return \array_map( static fn ( string $key ): array => [ 'item_key' => $key, 'url' => self::url( $key ), 'title' => $titles[ $key ] ], $keys );
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

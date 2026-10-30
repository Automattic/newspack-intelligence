<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The seeded ontology: the topic vocabulary, the partners, funders and
 * industry bodies, and Newspack's products, as events keyed so a second run
 * writes the same set, and only for the keys the projection lacks.
 */
final class OntologySeedTest extends TestCase {
	use Seeds_Entity_Index;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_every_seed_event_validates(): void {
		$events = Ontology_Seed::events( [] );

		$this->assertNotSame( [], $events );
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
			$this->assertSame( Intel_Event::ONTOLOGY, Intel_Event::stream_of( $event['event'] ) );
			$this->assertSame( 'machine:ontology-seed@' . \NEWSPACK_INTELLIGENCE_VERSION, $event['actor'] );
		}
	}

	/** One event per fact, and a second run states the very same facts; every key it names it creates or saves once. */
	public function test_the_seed_is_idempotent_by_key(): void {
		Core::$now = 1795000000.0;
		$first     = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );
		Core::$now = 1795000600.0;
		$second    = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );

		$this->assertSame( $first, $second );
		$this->assertSame( \array_values( \array_unique( $first ) ), $first, 'no fact is stated twice' );
		$created = \array_filter( $first, static fn ( string $fact ): bool => \str_starts_with( $fact, 'entity_created|' ) || \str_starts_with( $fact, 'lens_saved|' ) );
		$this->assertCount( \count( \array_unique( \array_map( static fn ( string $fact ): string => \explode( '|', $fact )[1], $first ) ) ), $created, 'every key it names it creates, once' );
	}

	/** About thirty topics, each with a display name and at least two other names. */
	public function test_the_topics_carry_names_and_aliases(): void {
		$events = Ontology_Seed::events( [] );
		$topics = \array_column( \array_filter( $events, static fn ( array $e ): bool => 'entity_created' === $e['event'] && 'topic' === $e['type'] ), 'name', 'entity_key' );

		$this->assertCount( 30, $topics );
		$this->assertSame( 'Artificial intelligence', $topics['topic:ai'] );
		$this->assertSame( 'Reader revenue', $topics['topic:reader-revenue'] );
		foreach ( \array_keys( $topics ) as $key ) {
			$names = \array_filter( $events, static fn ( array $e ): bool => 'name_added' === $e['event'] && $key === $e['entity_key'] );
			$this->assertGreaterThanOrEqual( 2, \count( $names ), $key );
		}
	}

	/** The products carry their repositories and their roles; Newspack carries its Linear team. */
	public function test_the_products_carry_their_identifiers(): void {
		$facts = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );

		foreach ( [ 'newspack-plugin', 'newspack-ads', 'newspack-activecampaign', 'newspack-manager', 'newspack-network' ] as $repo ) {
			$this->assertContains( "identifier_added|product:{$repo}|github_repo|automattic/{$repo}", $facts );
			$this->assertContains( "role_asserted|product:{$repo}|newspack_product|0", $facts );
		}
		$this->assertContains( 'identifier_added|product:newspack|linear_team|NEWS', $facts );
		$this->assertContains( 'role_asserted|product:newspack|newspack_product|0', $facts );
	}

	/** The partners, funders and industry bodies are organizations with their roles and domains. */
	public function test_the_organizations_carry_their_roles(): void {
		$facts = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );

		$this->assertContains( 'role_asserted|organization:knight-foundation|funder|0', $facts );
		$this->assertContains( 'identifier_added|organization:institute-for-nonprofit-news|domain|inn.org', $facts );
		$this->assertContains( 'name_added|organization:institute-for-nonprofit-news|acronym|INN', $facts );
		$this->assertContains( 'role_asserted|organization:lion-publishers|industry_body|0', $facts );
	}

	/** NEWS-2574's partners, industry bodies, funders and award bodies, each with its domains, names and role. */
	public function test_the_news_2574_organizations_carry_their_roles(): void {
		$facts = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );

		foreach ( [ 'everlit', 'pugpig', 'bluelena', 'blue-engine-collaborative', 'local-media-association', 'news-revenue-hub', 'parse-ly' ] as $partner ) {
			$this->assertContains( "role_asserted|organization:{$partner}|partner|0", $facts );
		}
		foreach ( [ 'news-product-alliance', 'opennews', 'association-of-alternative-newsmedia', 'national-newspaper-association', 'americas-newspapers', 'online-news-association', 'television-academy', 'pulitzer-prizes', 'american-society-of-magazine-editors', 'investigative-reporters-and-editors', 'reporters-committee', 'nobel-prize' ] as $body ) {
			$this->assertContains( "role_asserted|organization:{$body}|industry_body|0", $facts );
		}
		foreach ( [ 'media-forward-fund', 'google-news-initiative' ] as $funder ) {
			$this->assertContains( "role_asserted|organization:{$funder}|funder|0", $facts );
		}
		$this->assertContains( 'identifier_added|organization:pugpig|domain|pugpig.com', $facts );
		$this->assertContains( 'identifier_added|organization:television-academy|domain|emmys.com', $facts );
		$this->assertContains( 'identifier_added|organization:television-academy|domain|televisionacademy.com', $facts );
		$this->assertContains( 'name_added|organization:television-academy|alias|Emmy Awards', $facts );
		$this->assertContains( 'name_added|organization:reporters-committee|acronym|RCFP', $facts );
		$this->assertContains( 'entity_created|organization:americas-newspapers|organization|America\'s Newspapers', $facts );
	}

	/** A seeded key a curator has since rejected is present, so nothing about it is written again. */
	public function test_a_key_the_projection_holds_is_not_reemitted(): void {
		$this->seed( self::entity( 'organization:knight-foundation', 'Knight Fund (curated)' ), self::rejected( 'organization:knight-foundation' ) );

		$present = Ontology_Seed::present();
		$events  = Ontology_Seed::events( $present );

		$this->assertSame( [ 'organization:knight-foundation' ], $present );
		$this->assertNotContains( 'organization:knight-foundation', \array_column( $events, 'entity_key' ) );
		$this->assertContains( 'organization:press-forward', \array_column( $events, 'entity_key' ) );
		$this->assertCount( 62, \array_filter( $events, static fn ( array $e ): bool => 'entity_created' === $e['event'] ) );
	}

	/** A machine's pending proposal of a seed key is not present, so the seed's creation replaces it. */
	public function test_a_machine_proposal_of_a_seed_key_is_upgraded(): void {
		$this->seed( self::proposed( 'organization:press-forward', 'Press Fwd Coalition' ), self::entity( 'organization:nieman-lab', 'Nieman Lab (curated)' ) );

		$present = Ontology_Seed::present();

		$this->assertSame( [ 'organization:nieman-lab' ], $present );
		$this->assertContains( 'entity_created|organization:press-forward|organization|Press Forward', \array_map( self::fact( ... ), Ontology_Seed::events( $present ) ) );
	}

	/** Once the first run is projected, a second run writes nothing. */
	public function test_a_second_run_emits_nothing(): void {
		$this->seed( Ontology_Seed::events( Ontology_Seed::present() ) );

		$this->assertCount( 66, Ontology_Seed::present() );
		$this->assertSame( [], Ontology_Seed::events( Ontology_Seed::present() ) );
	}

	/** The seed writes three lenses, each its audience's filters, every other filter empty. */
	public function test_the_seed_writes_three_lenses(): void {
		$lenses = \array_column( \array_filter( Ontology_Seed::events( [] ), static fn ( array $e ): bool => 'lens_saved' === $e['event'] ), null, 'lens_key' );
		$none   = [ 'kinds' => [], 'topics' => [], 'roles' => [], 'sources' => [], 'entity' => null, 'days' => null, 'tiers' => [], 'by' => null, 'bucket' => null ];

		$this->assertSame( [ 'leadership', 'support', 'developers' ], \array_keys( $lenses ) );
		$this->assertSame( [ 'Leadership', 'Support', 'Developers' ], \array_column( $lenses, 'name' ) );
		$this->assertEquals( [ 'roles' => [ 'client', 'former_client', 'prospect', 'competitor', 'partner', 'funder' ], 'kinds' => [ 'launch', 'funding', 'partnership', 'award', 'churn_signal', 'research' ], 'days' => 30, 'by' => 'topic', 'bucket' => 'week' ] + $none, $lenses['leadership']['filters'] );
		$this->assertEquals( [ 'roles' => [ 'client' ], 'kinds' => [ 'complaint', 'incident', 'feature_request', 'churn_signal', 'praise' ], 'days' => 14, 'by' => 'kind', 'bucket' => 'day' ] + $none, $lenses['support']['filters'] );
		$this->assertEquals( [ 'sources' => [ 'github', 'linear' ], 'kinds' => [ 'release', 'incident', 'feature_request' ], 'days' => 30, 'by' => 'kind', 'bucket' => 'week' ] + $none, $lenses['developers']['filters'] );
		foreach ( $lenses as $lens ) {
			$this->assertSame( \array_keys( Graph_Query::unfiltered() ), \array_keys( $lens['filters'] ), 'every lens has the saved shape' );
			$this->assertNotSame( '', $lens['description'] );
		}
	}

	/** A lens a curator has saved or removed is present, so the seed leaves it alone. */
	public function test_a_curated_lens_is_left_alone(): void {
		$this->seed(
			[
				Intel_Event::make( 'lens_saved', [ 'lens_key' => 'support', 'name' => 'Support desk', 'description' => 'Ours.', 'filters' => [ 'kinds' => [ 'complaint' ] ] + Graph_Query::unfiltered() ], 'user:41', 'fixture-7' ),
				Intel_Event::make( 'lens_removed', [ 'lens_key' => 'developers' ], 'user:41', 'fixture-7' ),
			]
		);

		$present = Ontology_Seed::present();
		$events  = Ontology_Seed::events( $present );

		$this->assertSame( [ 'support', 'developers' ], \array_values( \array_intersect( $present, [ 'leadership', 'support', 'developers' ] ) ) );
		$this->assertSame( [ 'leadership' ], \array_column( \array_filter( $events, static fn ( array $e ): bool => 'lens_saved' === $e['event'] ), 'lens_key' ) );
	}

	/** With no projection to read, the seed refuses rather than writing everything. */
	public function test_an_unavailable_projection_throws(): void {
		$this->activate();

		$this->expectException( Table_Unavailable::class );
		Ontology_Seed::present();
	}

	/**
	 * An event as the fact it states: its kind and every field but the envelope.
	 *
	 * @param array<string,mixed> $event The event.
	 */
	private static function fact( array $event ): string {
		return \implode( '|', \array_map( static fn ( mixed $value ): string => \is_array( $value ) ? (string) \wp_json_encode( $value ) : (string) $value, \array_values( \array_diff_key( $event, \array_flip( [ 'event_id', 'ts', 'actor', 'config_version' ] ) ) ) ) );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

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

	/** One event per fact, and a second run states the very same facts. */
	public function test_the_seed_is_idempotent_by_key(): void {
		Core::$now = 1795000000.0;
		$first     = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );
		Core::$now = 1795000600.0;
		$second    = \array_map( self::fact( ... ), Ontology_Seed::events( [] ) );

		$this->assertSame( $first, $second );
		$this->assertSame( \array_values( \array_unique( $first ) ), $first, 'no fact is stated twice' );
		$created = \array_filter( $first, static fn ( string $fact ): bool => \str_starts_with( $fact, 'entity_created|' ) );
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

	/** A seeded key a curator has since rejected is present, so nothing about it is written again. */
	public function test_a_key_the_projection_holds_is_not_reemitted(): void {
		$this->seed( self::entity( 'organization:knight-foundation', 'Knight Fund (curated)' ), self::rejected( 'organization:knight-foundation' ) );

		$present = Ontology_Seed::present();
		$events  = Ontology_Seed::events( $present );

		$this->assertSame( [ 'organization:knight-foundation' ], $present );
		$this->assertNotContains( 'organization:knight-foundation', \array_column( $events, 'entity_key' ) );
		$this->assertContains( 'organization:press-forward', \array_column( $events, 'entity_key' ) );
		$this->assertCount( 41, \array_filter( $events, static fn ( array $e ): bool => 'entity_created' === $e['event'] ) );
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

		$this->assertCount( 42, Ontology_Seed::present() );
		$this->assertSame( [], Ontology_Seed::events( Ontology_Seed::present() ) );
	}

	/** With no projection to read, the seed refuses rather than writing everything. */
	public function test_an_unavailable_projection_throws(): void {
		$this->activate();

		$this->expectException( Table_Unavailable::class );
		Ontology_Seed::present();
	}

	/** A cold WP-CLI process has no tick clock yet; the seed still stamps a real time. */
	public function test_a_cold_process_stamps_a_real_time(): void {
		Core::$now = 0.0;

		$this->assertGreaterThan( 0, Ontology_Seed::events( [] )[0]['ts'] );
	}

	/**
	 * An event as the fact it states: its kind and every field but the envelope.
	 *
	 * @param array<string,mixed> $event The event.
	 */
	private static function fact( array $event ): string {
		return \implode( '|', \array_map( 'strval', \array_values( \array_diff_key( $event, \array_flip( [ 'event_id', 'ts', 'actor', 'config_version' ] ) ) ) ) );
	}
}

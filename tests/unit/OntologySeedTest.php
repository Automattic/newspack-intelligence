<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Intelligence\Seed_Refused;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';

/**
 * The seeded ontology: the entities and lenses a seed file names, read from
 * the file the last registered topology dir holds, as events keyed so a
 * second run writes the same set, and only for the keys the projection lacks.
 */
final class OntologySeedTest extends TestCase {
	use Reads_Ontology_Log;
	use Seeds_Entity_Index;

	/** The fictional fixture dir, which shadows the plugin's example seed. */
	private const FIXTURES = __DIR__ . '/../fixtures/topologies';

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Topology_Registry::register_stock_dir( self::FIXTURES );
		$this->activate( 'newspack-intelligence-project' );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_every_seed_event_validates(): void {
		$events = Ontology_Seed::load()->events( [] );

		$this->assertNotSame( [], $events );
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
			$this->assertSame( Intel_Event::ONTOLOGY, Intel_Event::stream_of( $event['event'] ) );
			$this->assertSame( 'machine:ontology-seed@' . \NEWSPACK_INTELLIGENCE_VERSION, $event['actor'] );
		}
	}

	/** The file the last registered dir holds is the seed, so the fixture shadows the shipped example. */
	public function test_the_last_registered_dirs_file_is_the_seed(): void {
		$keys = \array_values( \array_filter( \array_column( Ontology_Seed::load()->events( [] ), 'entity_key' ) ) );

		$this->assertSame( [ 'topic:tide-tables', 'topic:kite-repair', 'organization:heron-trust', 'organization:quarry-guild', 'organization:marsh-press', 'product:kite-ledger' ], \array_values( \array_unique( $keys ) ) );
	}

	/** One event per fact, and a second run states the very same facts; every key it names it creates or saves once. */
	public function test_the_seed_is_idempotent_by_key(): void {
		Core::$now = 1795000000.0;
		$first     = \array_map( self::fact( ... ), Ontology_Seed::load()->events( [] ) );
		Core::$now = 1795000600.0;
		$second    = \array_map( self::fact( ... ), Ontology_Seed::load()->events( [] ) );

		$this->assertSame( $first, $second );
		$this->assertSame( \array_values( \array_unique( $first ) ), $first, 'no fact is stated twice' );
		$created = \array_filter( $first, static fn ( string $fact ): bool => \str_starts_with( $fact, 'entity_created|' ) || \str_starts_with( $fact, 'lens_saved|' ) );
		$this->assertCount( \count( \array_unique( \array_map( static fn ( string $fact ): string => \explode( '|', $fact )[1], $first ) ) ), $created, 'every key it names it creates, once' );
	}

	/** Each entity is its creation, then its names, then its identifiers, each in the file's order, then its roles from 0. */
	public function test_an_entity_is_written_in_the_files_order(): void {
		$facts = \array_map( self::fact( ... ), Ontology_Seed::load()->events( [] ) );
		$quarry = \array_values( \array_filter( $facts, static fn ( string $fact ): bool => \str_contains( $fact, '|organization:quarry-guild|' ) ) );
		$ledger = \array_values( \array_filter( $facts, static fn ( string $fact ): bool => \str_contains( $fact, '|product:kite-ledger|' ) ) );

		$this->assertSame(
			[
				'entity_created|organization:quarry-guild|organization|Quarry Guild',
				'name_added|organization:quarry-guild|acronym|QG',
				'identifier_added|organization:quarry-guild|domain|quarryguild.example',
				'identifier_added|organization:quarry-guild|domain|qg.example',
				'role_asserted|organization:quarry-guild|industry_body|0',
			],
			$quarry
		);
		$this->assertSame(
			[
				'entity_created|product:kite-ledger|product|Kite Ledger',
				'identifier_added|product:kite-ledger|github_repo|heron-example/kite-ledger',
				'identifier_added|product:kite-ledger|linear_team|KITE',
				'role_asserted|product:kite-ledger|newspack_product|0',
			],
			$ledger
		);
		$this->assertContains( 'role_asserted|organization:marsh-press|funder|0', $facts );
		$this->assertContains( 'name_added|topic:kite-repair|alias|Kite mending', $facts );
	}

	/** A seeded key a curator has since rejected is present, so nothing about it is written again. */
	public function test_a_key_the_projection_holds_is_not_reemitted(): void {
		$this->seed( self::entity( 'organization:heron-trust', 'Heron Fund (curated)' ), self::rejected( 'organization:heron-trust' ) );

		$seed    = Ontology_Seed::load();
		$present = $seed->present();
		$events  = $seed->events( $present );

		$this->assertSame( [ 'organization:heron-trust' ], $present );
		$this->assertNotContains( 'organization:heron-trust', \array_column( $events, 'entity_key' ) );
		$this->assertContains( 'organization:marsh-press', \array_column( $events, 'entity_key' ) );
		$this->assertCount( 5, \array_filter( $events, static fn ( array $e ): bool => 'entity_created' === $e['event'] ) );
	}

	/** A machine's pending proposal of a seed key is not present, so the seed's creation replaces it. */
	public function test_a_machine_proposal_of_a_seed_key_is_upgraded(): void {
		$this->seed( self::proposed( 'organization:marsh-press', 'Marsh Pr Coalition' ), self::entity( 'topic:tide-tables', 'Tide tables (curated)' ) );

		$seed    = Ontology_Seed::load();
		$present = $seed->present();

		$this->assertSame( [ 'topic:tide-tables' ], $present );
		$this->assertContains( 'entity_created|organization:marsh-press|organization|Marsh Press', \array_map( self::fact( ... ), $seed->events( $present ) ) );
	}

	/** Once the first run is projected, a second run writes nothing. */
	public function test_a_second_run_emits_nothing(): void {
		$seed = Ontology_Seed::load();
		$this->seed( $seed->events( $seed->present() ) );

		$this->assertCount( 8, $seed->present() );
		$this->assertSame( [], $seed->events( $seed->present() ) );
	}

	/** Each lens is written with the file's filters over every filter empty. */
	public function test_the_seed_writes_the_files_lenses(): void {
		$lenses = \array_column( \array_filter( Ontology_Seed::load()->events( [] ), static fn ( array $e ): bool => 'lens_saved' === $e['event'] ), null, 'lens_key' );
		$none   = [ 'kinds' => [], 'topics' => [], 'roles' => [], 'sources' => [], 'entity' => null, 'days' => null, 'tiers' => [], 'by' => null, 'bucket' => null ];

		$this->assertSame( [ 'harbor-desk', 'kite-watch' ], \array_keys( $lenses ) );
		$this->assertSame( [ 'Harbor desk', 'Kite watch' ], \array_column( $lenses, 'name' ) );
		$this->assertSame( 'What the harbor desk follows.', $lenses['harbor-desk']['description'] );
		$this->assertEquals( [ 'roles' => [ 'funder', 'partner' ], 'kinds' => [ 'funding', 'praise' ], 'days' => 21, 'by' => 'topic', 'bucket' => 'day' ] + $none, $lenses['harbor-desk']['filters'] );
		$this->assertEquals( [ 'sources' => [ 'github' ], 'kinds' => [ 'release', 'incident' ], 'days' => 7, 'by' => 'kind', 'bucket' => 'week' ] + $none, $lenses['kite-watch']['filters'] );
		foreach ( $lenses as $lens ) {
			$this->assertSame( \array_keys( Graph_Query::unfiltered() ), \array_keys( $lens['filters'] ), 'every lens has the saved shape' );
		}
	}

	/** A lens a curator has saved or removed is present, so the seed leaves it alone. */
	public function test_a_curated_lens_is_left_alone(): void {
		$this->seed(
			[
				Intel_Event::make( 'lens_saved', [ 'lens_key' => 'harbor-desk', 'name' => 'Harbor (ours)', 'description' => 'Ours.', 'filters' => [ 'kinds' => [ 'complaint' ] ] + Graph_Query::unfiltered() ], 'user:41', 'fixture-7' ),
			]
		);

		$seed    = Ontology_Seed::load();
		$present = $seed->present();
		$events  = $seed->events( $present );

		$this->assertSame( [ 'harbor-desk' ], \array_values( \array_intersect( $present, [ 'harbor-desk', 'kite-watch' ] ) ) );
		$this->assertSame( [ 'kite-watch' ], \array_column( \array_filter( $events, static fn ( array $e ): bool => 'lens_saved' === $e['event'] ), 'lens_key' ) );
	}

	/** A run writes the events for the keys the projection lacks, and counts them against the keys it holds. */
	public function test_seed_writes_only_missing_keys(): void {
		$held = [ 'organization:heron-trust', 'organization:marsh-press', 'topic:kite-repair' ];
		$this->seed(
			self::entity( $held[0], 'Heron Fund (curated)' ),
			self::rejected( $held[1] ),
			self::entity( $held[2], 'Kites (curated)' ),
			[ Intel_Event::make( 'lens_saved', [ 'lens_key' => 'kite-watch', 'name' => 'Kites (ours)', 'description' => 'Ours.', 'filters' => Graph_Query::unfiltered() ], 'user:41', 'fixture-7' ) ]
		);
		$seed     = Ontology_Seed::load();
		$expected = $seed->events( [ ...$held, 'kite-watch' ] );

		$answer = $seed->seed();

		$this->assertSame( [ 'events' => \count( $expected ), 'created' => 4, 'present' => 4 ], $answer );
		$this->assertSame( \array_map( self::fact( ... ), $expected ), \array_map( self::fact( ... ), self::ontology_events() ) );
	}

	/** Ontology changes the projector has not read yet refuse a seed, which writes nothing. */
	public function test_seed_refused_while_projector_behind(): void {
		Ontology_Seed::load()->seed();
		$written = \count( self::ontology_events() );

		try {
			Ontology_Seed::load()->seed();
			$this->fail( 'a second seed runs while the first is unprojected' );
		} catch ( Seed_Refused $e ) {
			$this->assertSame( 'Recent ontology changes are still being processed; try again in a minute.', $e->getMessage() );
		}
		$this->assertSame( $written, \count( self::ontology_events() ) );
	}

	/** With no projection to read, the seed refuses rather than writing everything. */
	public function test_an_unavailable_projection_throws(): void {
		$this->activate();

		$this->expectException( Table_Unavailable::class );
		Ontology_Seed::load()->present();
	}

	/** A path given is read in place of the resolved file. */
	public function test_a_given_path_is_read_in_place_of_the_resolved_file(): void {
		$path = $this->seed_file( [ 'entities' => [ 'topic' => [ 'salt-marsh' => [ 'name' => 'Salt marsh' ] ] ], 'lenses' => [] ] );

		$this->assertSame( [ 'entity_created|topic:salt-marsh|topic|Salt marsh' ], \array_map( self::fact( ... ), Ontology_Seed::load( $path )->events( [] ) ) );
	}

	/** The plugin's own seed, read with no deployment's dir registered, is a fictional example that validates. */
	public function test_the_shipped_example_seed_validates(): void {
		Topology_Registry::reset();
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );

		$events = Ontology_Seed::load()->events( [] );

		$this->assertNotSame( [], \array_filter( $events, static fn ( array $e ): bool => 'lens_saved' === $e['event'] ) );
		foreach ( \array_filter( $events, static fn ( array $e ): bool => 'identifier_added' === $e['event'] && 'domain' === $e['kind'] ) as $domain ) {
			$this->assertStringEndsWith( '.example', $domain['value'] );
		}
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
		}
	}

	public function test_no_seed_file_in_any_dir_is_refused(): void {
		Topology_Registry::reset();

		$this->expectException( Seed_Refused::class );
		$this->expectExceptionMessage( 'No newspack-intelligence-ontology-seed.json is in any topology dir.' );
		Ontology_Seed::load();
	}

	/**
	 * A file that will not parse, or names what the graph has no word for, is
	 * refused naming the field.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function bad_seeds(): array {
		$entity = static fn ( array $fields ): string => (string) \json_encode( [ 'entities' => [ 'organization' => [ 'gull-fund' => $fields + [ 'name' => 'Gull Fund' ] ] ], 'lenses' => [] ] );
		$lens   = static fn ( array $filters ): string => (string) \json_encode( [ 'entities' => [], 'lenses' => [ 'gull-desk' => [ 'name' => 'Gull desk', 'description' => 'Gulls.', 'filters' => $filters ] ] ] );
		$lens_fields = static fn ( array $fields ): string => (string) \json_encode( [ 'entities' => [], 'lenses' => [ 'gull-desk' => $fields + [ 'name' => 'Gull desk', 'description' => 'Gulls.', 'filters' => [] ] ] ] );
		$long        = \str_repeat( 'Gull ', 1000 );
		return [
			'not json'            => [ '{"entities":', 'not JSON: Syntax error' ],
			'not an object'       => [ '[1,2]', 'seed: not an object' ],
			'no lenses'           => [ '{"entities":{}}', 'lenses: missing' ],
			'stray top field'     => [ '{"entities":{},"lenses":{},"notes":"x"}', 'notes: not a field it takes' ],
			'unknown type'        => [ '{"entities":{"vessel":{"gull":{"name":"Gull"}}},"lenses":{}}', 'entities.vessel.gull: unknown entity type: vessel' ],
			'no name'             => [ $entity( [ 'name' => null ] ), 'entities.organization.gull-fund.name: not a string' ],
			'blank name'          => [ $entity( [ 'name' => '   ' ] ), 'entities.organization.gull-fund.name: name is blank' ],
			'name past its cap'   => [ $entity( [ 'name' => $long ] ), 'entities.organization.gull-fund.name: name runs past 256 bytes' ],
			'value past its cap'  => [ $entity( [ 'names' => [ 'alias' => [ $long ] ] ] ), 'entities.organization.gull-fund.names.alias: value runs past 256 bytes' ],
			'unknown role'        => [ $entity( [ 'roles' => [ 'patron' ] ] ), 'entities.organization.gull-fund.roles: role_asserted: field role is outside its set' ],
			'unknown name kind'   => [ $entity( [ 'names' => [ 'nickname' => [ 'Gulls' ] ] ] ), 'entities.organization.gull-fund.names.nickname: name_added: field kind is outside its set' ],
			'unknown identifier'  => [ $entity( [ 'identifiers' => [ 'website' => [ 'gull.example' ] ] ] ), 'entities.organization.gull-fund.identifiers.website: identifier_added: field kind is outside its set' ],
			'value not a string'  => [ $entity( [ 'identifiers' => [ 'domain' => [ 7 ] ] ] ), 'entities.organization.gull-fund.identifiers.domain: not a list of strings' ],
			'stray entity field'  => [ $entity( [ 'role' => [ 'funder' ] ] ), 'entities.organization.gull-fund.role: not a field it takes' ],
			'slug not a slug'     => [ '{"entities":{"topic":{"Salt Marsh":{"name":"Salt marsh"}}},"lenses":{}}', 'entities.topic.Salt Marsh: not a slug; the key it makes is topic:salt-marsh' ],
			'unknown lens filter' => [ $lens( [ 'audience' => [ 'gulls' ] ] ), 'lenses.gull-desk.filters.audience: not a field it takes' ],
			'lens role unknown'   => [ $lens( [ 'roles' => [ 'patron' ] ] ), 'lenses.gull-desk.filters: role must be one of: ' . \implode( ', ', Intel_Event::ROLES ) ],
			'lens tier unknown'   => [ $lens( [ 'tiers' => [ 'secret' ] ] ), 'lenses.gull-desk.filters: tier must be one of: public, internal, restricted' ],
			'lens by unknown'     => [ $lens( [ 'by' => 'colour' ] ), 'lenses.gull-desk.filters: by must be one of: ' . \implode( ', ', Graph_Query::trend_axes() ) ],
			'lens bucket unknown' => [ $lens( [ 'bucket' => 'fortnight' ] ), 'lenses.gull-desk.filters: bucket must be one of: ' . \implode( ', ', Graph_Query::buckets() ) ],
			'lens days past'      => [ $lens( [ 'days' => 731 ] ), 'lenses.gull-desk.filters: days must be between 1 and 730' ],
			'lens days not int'   => [ $lens( [ 'days' => '7' ] ), 'lenses.gull-desk.filters.days: not a whole number' ],
			'lens free list'      => [ $lens( [ 'sources' => [ '  ' ] ] ), 'lenses.gull-desk.filters: source is blank' ],
			'lens list not list'  => [ $lens( [ 'sources' => 'github' ] ), 'lenses.gull-desk.filters.sources: not a list of strings' ],
			'lens entity blank'   => [ $lens( [ 'entity' => ' ' ] ), 'lenses.gull-desk.filters: entity is blank' ],
			'lens past one line'  => [ $lens_fields( [ 'description' => \str_repeat( 'g', 5000 ) ] ), 'lenses.gull-desk: lens_saved: ' ],
			'lens key not a slug' => [ '{"entities":{},"lenses":{"Gull Desk":{"name":"Gull desk","description":"Gulls.","filters":{}}}}', 'lenses.Gull Desk: not a slug; the key it makes is gull-desk' ],
			'lens no description' => [ '{"entities":{},"lenses":{"gull-desk":{"name":"Gull desk","filters":{}}}}', 'lenses.gull-desk.description: missing' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_seeds' )]
	public function test_a_bad_seed_is_refused_naming_the_field_before_anything_is_written( string $json, string $field ): void {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/seed.json';
		\file_put_contents( $path, $json );

		try {
			Ontology_Seed::load( $path )->seed();
			$this->fail( 'the seed reads a bad file' );
		} catch ( Seed_Refused $e ) {
			$this->assertStringStartsWith( "{$path}: {$field}", $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	public function test_a_given_path_no_file_holds_is_refused(): void {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/absent-4471.json';

		$this->expectException( Seed_Refused::class );
		$this->expectExceptionMessage( "No seed file at {$path}." );
		Ontology_Seed::load( $path );
	}

	/**
	 * A seed file holding the table given.
	 *
	 * @param array<string,mixed> $seed The seed.
	 */
	private function seed_file( array $seed ): string {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/seed.json';
		\file_put_contents( $path, (string) \json_encode( $seed ) );
		return $path;
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

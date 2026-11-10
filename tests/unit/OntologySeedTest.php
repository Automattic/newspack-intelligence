<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intelligence_Blocks;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Intelligence\Resolver;
use Newspack_Intelligence\Seed_Refused;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/declares-intelligence-blocks.php';

/**
 * The seeded ontology: the entities and lenses a seed file names, read from
 * the site's Intelligence data dir beside the runtime base, as events keyed
 * so a second run writes the same set, and only for the keys the projection
 * lacks; and the layouts it names, each written only to a page that holds
 * none.
 */
final class OntologySeedTest extends TestCase {
	use Reads_Ontology_Log;
	use Seeds_Entity_Index;
	use Declares_Intelligence_Blocks;

	/** A fixture block the Briefing takes, whose heading tells two layouts apart. */
	private const PROBE = '<!-- wp:newspack-intelligence/probe {"heading":"%s"} /-->';

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		self::place_seed();
		$this->activate( 'newspack-intelligence-project' );
		$this->declare_intelligence_blocks();
		$this->declare_probe( 'newspack-intelligence/briefing' );
	}

	protected function tearDown(): void {
		$this->forget_compositions();
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** A seeded layout never replaces one a curator saved, and lays out a page that holds none. */
	public function test_seed_layouts_never_reset_a_curators(): void {
		Composition_Store::save( 'newspack-intelligence/briefing', \sprintf( self::PROBE, 'Curated desk' ) );
		$path = $this->seed_file(
			[
				'entities' => [],
				'lenses'   => [],
				'layouts'  => [
					'briefing' => \sprintf( self::PROBE, 'Seeded desk' ),
					'entity'   => '<!-- wp:newspack-intelligence/aside /-->',
				],
			]
		);

		Ontology_Seed::load( $path )->seed();

		$briefing = Composition_Store::read( 'newspack-intelligence/briefing' );
		$this->assertSame( 'Curated desk', $briefing['blocks'][0]['attributes']['heading'] );
		$entity = Composition_Store::read( 'newspack-intelligence/entity' );
		$this->assertSame( [ 'saved', [ 'newspack-intelligence/aside' ] ], [ $entity['source'], \array_column( $entity['blocks'], 'name' ) ] );
		$this->assertSame( 'default', Composition_Store::read( 'newspack-intelligence/lens' )['source'], 'a page the seed names no layout for keeps its default' );
	}

	/**
	 * A layout the composition layer refuses refuses the whole seed, naming
	 * its page, before any event or layout is written.
	 *
	 * @param mixed  $layouts The seed's `layouts`.
	 * @param string $refusal What the refusal names, after the file's name.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_layouts' )]
	public function test_seed_refuses_an_invalid_layout_before_writing_anything( mixed $layouts, string $refusal ): void {
		$path = $this->seed_file(
			[
				'entities' => [ 'topic' => [ 'salt-marsh' => [ 'name' => 'Salt marsh' ] ] ],
				'lenses'   => [ 'gull-desk' => [ 'name' => 'Gull desk', 'description' => 'Gulls.', 'filters' => [] ] ],
				'layouts'  => $layouts,
			]
		);

		try {
			Ontology_Seed::load( $path )->seed();
			$this->fail( 'the seed wrote past a layout it could not lay out' );
		} catch ( Seed_Refused $e ) {
			$this->assertSame( "seed.json: {$refusal}", $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
		foreach ( \array_keys( Intelligence_Blocks::CONTEXTS ) as $context ) {
			$this->assertFalse( \get_option( Composition_Store::OPTION_PREFIX . $context, false ), $context );
		}
	}

	/**
	 * Layouts a seed may not hold, beside the refusal of each.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public static function bad_layouts(): array {
		$probe = '<!-- wp:newspack-intelligence/probe {"heading":"Seeded desk"} /-->';
		return [
			'html in a layout'     => [ [ 'entity' => '<!-- wp:newspack-intelligence/aside /-->', 'briefing' => "{$probe}<p>Gulls</p>" ], 'layouts.briefing: composition newspack-intelligence/briefing: HTML outside a block; a layout holds no HTML' ],
			'a block off its page' => [ [ 'lens' => $probe ], 'layouts.lens: composition newspack-intelligence/lens: newspack-intelligence/probe does not stand on newspack-intelligence/lens' ],
			'a page not composed'  => [ [ 'folders' => $probe ], 'layouts.folders: not a field it takes' ],
			'markup not a string'  => [ [ 'briefing' => [ $probe ] ], 'layouts.briefing: not a string' ],
			'not an object'        => [ [ $probe ], 'layouts: not an object' ],
		];
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

	/** The seed sits in the Intelligence data dir beside the runtime base. */
	public function test_the_seed_path_is_the_data_dir_beside_the_base(): void {
		$this->assertSame( \dirname( Config::get_base_directory() ) . '/newspack-intelligence/newspack-intelligence-ontology-seed.json', Ontology_Seed::path() );
	}

	/** A data dir planted as a symlink is refused, as the runtime base is. */
	public function test_a_symlinked_data_dir_is_refused(): void {
		$dir       = \dirname( Ontology_Seed::path() );
		$elsewhere = $this->make_temp_dir( 'intel-plover-' );
		\rename( Ontology_Seed::path(), $elsewhere . '/' . Ontology_Seed::FILE );
		\rmdir( $dir );
		\symlink( $elsewhere, $dir );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'symlink or path traversal detected' );
		Ontology_Seed::load();
	}

	/** The data dir's file is the seed, so the fixture, not the shipped example, is read. */
	public function test_the_data_dirs_file_is_the_seed(): void {
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
		$this->assertSame( \array_values( \array_unique( $first ) ), $first, 'a fact is never stated twice' );
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
				'role_asserted|product:kite-ledger|operator_product|0',
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

	/** A path given is read in place of the one at `Ontology_Seed::path()`. */
	public function test_a_given_path_is_read_in_place_of_the_sites(): void {
		$path = $this->seed_file( [ 'entities' => [ 'topic' => [ 'salt-marsh' => [ 'name' => 'Salt marsh' ] ] ], 'lenses' => [] ] );

		$this->assertSame( [ 'entity_created|topic:salt-marsh|topic|Salt marsh' ], \array_map( self::fact( ... ), Ontology_Seed::load( $path )->events( [] ) ) );
	}

	/** An entity the seed declares rejected is created with its names, then rejected. */
	public function test_a_seed_entity_declared_rejected_is_created_then_rejected(): void {
		$path = $this->seed_file( [ 'entities' => [ 'organization' => [ 'tern-desk' => [ 'name' => 'Tern Desk', 'names' => [ 'alias' => [ 'Tern desk team' ] ], 'state' => 'rejected' ] ] ], 'lenses' => [] ] );

		$this->assertSame(
			[
				'entity_created|organization:tern-desk|organization|Tern Desk',
				'name_added|organization:tern-desk|alias|Tern desk team',
				'entity_rejected|organization:tern-desk',
			],
			\array_map( self::fact( ... ), Ontology_Seed::load( $path )->events( [] ) )
		);
	}

	/** Once a rejected seed entity is projected, a second seed writes nothing for it. */
	public function test_a_seed_rejected_key_is_not_written_twice(): void {
		$seed = Ontology_Seed::load( $this->seed_file( [ 'entities' => [ 'organization' => [ 'tern-desk' => [ 'name' => 'Tern Desk', 'state' => 'rejected' ] ] ], 'lenses' => [] ] ) );
		$this->seed( $seed->events( $seed->present() ) );

		$this->assertSame( [ 'organization:tern-desk' ], $seed->present() );
		$this->assertSame( [], $seed->events( $seed->present() ) );
	}

	/**
	 * The stoplist is the rejected entities: a name the seed rejects and a
	 * name a curator rejected are never proposed, and another still is.
	 */
	public function test_a_seed_rejected_name_and_a_curator_rejected_name_are_never_proposed(): void {
		$path = $this->seed_file( [ 'entities' => [ 'organization' => [ 'tern-desk' => [ 'name' => 'Tern Desk', 'names' => [ 'alias' => [ 'Tern desk team' ] ], 'state' => 'rejected' ] ] ], 'lenses' => [] ] );
		$index = $this->index_of(
			Ontology_Seed::load( $path )->events( [] ),
			self::proposed( 'organization:gull-rota', 'Gull Rota' ),
			self::rejected( 'organization:gull-rota' )
		);
		$ner   = self::named( [ 'Tern Desk', 'Tern desk team', 'Gull Rota', 'Plover Wharf Trust' ] );

		$proposals = ( new Resolver( $index ) )->resolve( [ 'item_key' => 'feed:stop-1', 'url' => 'https://elsewhere.example/a', 'title' => 'Wharf news', 'body' => '', 'retrieved_ts' => 1793000000 ], $ner, [] )['proposals'];

		$this->assertSame( [ 'Plover Wharf Trust' ], \array_column( $proposals, 'name' ) );
	}

	/** The example the plugin ships, outside every topology dir, is fictional and validates. */
	public function test_the_shipped_example_seed_validates(): void {
		$events = Ontology_Seed::load( \NEWSPACK_INTELLIGENCE_DIR . 'examples/' . Ontology_Seed::FILE )->events( [] );

		$this->assertNotSame( [], \array_filter( $events, static fn ( array $e ): bool => 'lens_saved' === $e['event'] ) );
		foreach ( \array_filter( $events, static fn ( array $e ): bool => 'identifier_added' === $e['event'] && 'domain' === $e['kind'] ) as $domain ) {
			$this->assertStringEndsWith( '.example', $domain['value'] );
		}
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
		}
	}

	/**
	 * The example loads, and its Briefing layout validates against its own
	 * lenses once they are written: a week filed under its four categories,
	 * the rest under "Industry news".
	 */
	public function test_the_example_seed_loads(): void {
		$path = \NEWSPACK_INTELLIGENCE_DIR . 'examples/' . Ontology_Seed::FILE;
		$this->seed( Ontology_Seed::load( $path )->events( [] ) );
		$markup = \json_decode( (string) \file_get_contents( $path ), true, 512, \JSON_THROW_ON_ERROR )['layouts']['briefing'];

		$week = \array_column( Composition::validate( 'newspack-intelligence/briefing', $markup ), 'attributes', 'name' )['newspack-intelligence/week'];

		$this->assertSame( [ [ 'product-updates', 'publisher-news', 'partner-news', 'publisher-awards' ], 'Industry news' ], [ $week['categories'], $week['otherwise'] ] );
	}

	/** A seed in a topology dir is not read: only the data dir's is. */
	public function test_a_seed_in_a_topology_dir_is_not_read(): void {
		$user = $this->make_temp_dir( 'intel-seed-user-' );
		\copy( Ontology_Seed::path(), "{$user}/" . Ontology_Seed::FILE );
		\unlink( Ontology_Seed::path() );
		Topology_Registry::register_user_dir( $user );

		$this->expectException( Seed_Refused::class );
		Ontology_Seed::load();
	}

	/**
	 * With no seed in the data dir, a caller below manage is told the file
	 * belongs in the site's Intelligence data dir and where the plugin keeps
	 * the example, and no absolute path.
	 */
	public function test_no_seed_file_in_the_data_dir_is_refused(): void {
		\unlink( Ontology_Seed::path() );

		$this->expectException( Seed_Refused::class );
		$this->expectExceptionMessage( "No newspack-intelligence-ontology-seed.json: write a seed for this site as newspack-intelligence-ontology-seed.json in its Intelligence data dir, starting from the example the plugin ships at examples/newspack-intelligence-ontology-seed.json." );
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
			'unknown state'       => [ $entity( [ 'state' => 'accepted' ] ), 'entities.organization.gull-fund.state: not one of: rejected' ],
			'slug not a slug'     => [ '{"entities":{"topic":{"Salt Marsh":{"name":"Salt marsh"}}},"lenses":{}}', 'entities.topic.Salt Marsh: not a slug; the key it makes is topic:salt-marsh' ],
			'unknown lens filter' => [ $lens( [ 'audience' => [ 'gulls' ] ] ), 'lenses.gull-desk.filters.audience: not a field it takes' ],
			'lens role unknown'   => [ $lens( [ 'roles' => [ 'patron' ] ] ), 'lenses.gull-desk.filters: role must be one of: ' . \implode( ', ', Intel_Event::ROLES ) ],
			'lens source unknown' => [ $lens( [ 'sources' => [ 'gullcast' ] ] ), 'lenses.gull-desk.filters: source must be one of: ' . \implode( ', ', Intel_Event::SOURCES ) ],
			'lens tier unknown'   => [ $lens( [ 'tiers' => [ 'secret' ] ] ), 'lenses.gull-desk.filters: tier must be one of: public, internal, restricted' ],
			'lens by unknown'     => [ $lens( [ 'by' => 'colour' ] ), 'lenses.gull-desk.filters: by must be one of: ' . \implode( ', ', Graph_Query::trend_axes() ) ],
			'lens bucket unknown' => [ $lens( [ 'bucket' => 'fortnight' ] ), 'lenses.gull-desk.filters: bucket must be one of: ' . \implode( ', ', Graph_Query::buckets() ) ],
			'lens days past'      => [ $lens( [ 'days' => 731 ] ), 'lenses.gull-desk.filters: days must be between 1 and 730' ],
			'lens days not int'   => [ $lens( [ 'days' => '7' ] ), 'lenses.gull-desk.filters.days: not a whole number' ],
			'lens free list'      => [ $lens( [ 'topics' => [ '  ' ] ] ), 'lenses.gull-desk.filters: topic is blank' ],
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
			$this->assertStringStartsWith( "seed.json: {$field}", $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	public function test_a_given_path_no_file_holds_is_refused(): void {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/absent-4471.json';

		$this->expectException( Seed_Refused::class );
		$this->expectExceptionMessage( 'No seed file at absent-4471.json.' );
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

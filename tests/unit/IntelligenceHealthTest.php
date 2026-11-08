<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Intelligence\Stream_Writer;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Vault;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/drives-curl.php';
require_once __DIR__ . '/../support/routes-to-workers.php';
require_once __DIR__ . '/../support/wp-cli-stub.php';

/**
 * The `health` read verb: four slices, each derived from durable state where
 * that can answer — the worker's lock, the topology, the Vault and the
 * projection — and from the snapshots the workers publish for the rest, a
 * snapshot that is not in any cache reading as unknown, never as healthy.
 */
final class IntelligenceHealthTest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;
	use Drives_Curl;
	use Routes_To_Workers;

	private Intelligence_CI_Node $ci;

	private InMemoryMemcached $memd;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		$this->activate( 'newspack-intelligence' );
		$GLOBALS['_wp_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_current_user_can']                           = true;
		self::sign_in( 3373, 'harbor-desk', 'manage_options' );
		$this->memd = new InMemoryMemcached();
		Core::$memd = $this->memd;
		$this->ci   = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		self::forget_users();
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;
		Intel_Graph_Row_Schema::$read_rows    = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/**
	 * Point the workstation's ContextA8C sources at `$dir`, as a site's own
	 * copy of their part, shadowing the shipped one, would; with
	 * `$linear_unbound`, its `linear` line names no token dir at all.
	 */
	private function relay_at( string $dir, bool $linear_unbound = false ): void {
		$parts   = $this->make_temp_dir( 'intelligence-health-relay-' );
		$shipped = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/topologies/newspack-intelligence-context-a8c.tsl.php' );
		$part    = (string) \preg_replace( '/^(make_node Context_A8C_\S+ \S+ \S+) \/mcp-auth /m', "\$1 {$dir} ", $shipped, -1, $count );
		$this->assertSame( 4, $count );
		if ( $linear_unbound ) {
			$part = (string) \preg_replace( '/^(make_node Context_A8C_Linear linear internal) .*$/m', '$1', $part, -1, $count );
			$this->assertSame( 1, $count );
		}
		\file_put_contents( "{$parts}/newspack-intelligence-context-a8c.tsl", $part );
		Topology_Registry::register_stock_dir( $parts );
		Topology_Analyzer::reset_caches();
	}

	public function test_health_is_a_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \Newspack_Nodes\Capabilities::READ, $roles['health'] );
	}

	/**
	 * While the active topologies declare no event writer,
	 * collection is off, offering every catalog composition that is not
	 * included by any other, by name: the
	 * shipped one and, its token dir reading, the workstation's.
	 */
	public function test_collection_inactive_when_topology_off(): void {
		$this->relay_at( $this->make_temp_dir( 'intelligence-health-relay-auth-' ) );
		$this->activate( 'topic-probe' );

		$this->assertSame( [ 'state' => 'inactive', 'topologies' => [ 'newspack-intelligence', 'newspack-intelligence-workstation' ] ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A composition whose ContextA8C token dir this host lacks is not offered. */
	public function test_collection_off_leaves_out_a_composition_that_cannot_collect_here(): void {
		$this->relay_at( '/tmp/no-relay-7719' );
		$this->activate( 'topic-probe' );

		$this->assertSame( [ 'state' => 'inactive', 'topologies' => [ 'newspack-intelligence' ] ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A composition whose source line will not bind refuses the offer, naming the composition, the source and why. */
	public function test_collection_off_refuses_a_source_line_that_will_not_bind(): void {
		$this->relay_at( $this->make_temp_dir( 'intelligence-health-relay-auth-' ), true );
		$this->activate( 'topic-probe' );

		try {
			$this->ci->dispatch( 'health', [ 'collection' ] );
			$this->fail( 'collection answered past a line that will not bind' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'newspack-intelligence-workstation: Context_A8C_Linear linear: missing required argument: auth_dir', $e->getMessage() );
			$this->assertSame( 'Context_A8C_Linear linear: missing required argument: auth_dir', $e->getPrevious()?->getMessage() );
			$this->assertSame( 'missing required argument: auth_dir', $e->getPrevious()->getPrevious()?->getMessage() );
		}
	}

	/** Off, a third composition in the catalog joins the offer in its sorted place. */
	public function test_collection_off_offers_every_composition_sorted(): void {
		$this->relay_at( $this->make_temp_dir( 'intelligence-health-relay-auth-' ) );
		$dir = $this->make_temp_dir( 'intelligence-health-sanderling-' );
		\file_put_contents( "{$dir}/sanderling-intel.tsl", "include newspack-intelligence-streams\n" );
		Topology_Registry::register_stock_dir( $dir );
		$this->activate( 'topic-probe' );

		$this->assertSame( [ 'state' => 'inactive', 'topologies' => [ 'newspack-intelligence', 'newspack-intelligence-workstation', 'sanderling-intel' ] ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/**
	 * Without a lists file in any topology dir, collection cannot run: the
	 * slice names where it belongs, whether or not anything is active.
	 */
	public function test_collection_without_a_lists_file_names_where_it_belongs(): void {
		$bare = $this->make_temp_dir( 'intelligence-health-bare-' );
		Topology_Registry::register_user_dir( $bare );
		Topology_Analyzer::reset_caches();

		$this->assertSame( [ 'state' => 'unconfigured', 'file' => "{$bare}/newspack-intelligence-lists.tsl" ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
		$this->activate( 'topic-probe' );
		$this->assertSame( 'unconfigured', $this->ci->dispatch( 'health', [ 'collection' ] )['state'] );
	}

	/**
	 * A stale active name that does not resolve to any `.tsl`, without an
	 * Intelligence topology, leaves collection off rather than refused.
	 */
	public function test_collection_inactive_beside_a_stale_active_name(): void {
		$this->relay_at( $this->make_temp_dir( 'intelligence-health-relay-auth-' ) );
		$this->activate( 'heron-old' );

		$this->assertSame( [ 'state' => 'inactive', 'topologies' => [ 'newspack-intelligence', 'newspack-intelligence-workstation' ] ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A live composition that includes no lists file reads its worker's state, whatever the topology dirs hold. */
	public function test_a_live_composition_without_a_lists_include_reads_its_state(): void {
		Topology_Registry::register_user_dir( $this->make_temp_dir( 'intelligence-health-phalarope-' ) );
		$this->activate();
		$this->run_intelligence_as( 'phalarope-intel' );
		self::live_worker( Config::get_base_directory(), 'phalarope-intel.p0' );
		\touch( Config::get_base_directory() . '/locks/phalarope-intel.p0.lock.d/heartbeat' );

		$this->assertSame( [ 'state' => 'live', 'topology' => 'phalarope-intel' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A reader below manage learns the lists file is missing, not where the user dir sits. */
	public function test_a_reader_below_manage_gets_no_lists_path(): void {
		Topology_Registry::register_user_dir( $this->make_temp_dir( 'intelligence-health-turnstone-' ) );
		Topology_Analyzer::reset_caches();
		\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::READ;

		$this->assertSame( [ 'state' => 'unconfigured' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/**
	 * `wp nodes doctor` opens with where the lists file belongs while every
	 * topology dir lacks one, and that empty will do.
	 */
	public function test_doctor_names_a_missing_lists_file(): void {
		$bare = $this->make_temp_dir( 'intelligence-health-doctor-' );
		\WP_CLI::reset();
		\Newspack_Intelligence\register_cli_commands();

		foreach ( \WP_CLI::$hooks['before_invoke:nodes doctor'] ?? [] as $hook ) {
			$hook( 'nodes doctor' );
		}
		$this->assertSame( [], \WP_CLI::$warnings, 'the fixture lists file resolves' );

		Topology_Registry::register_user_dir( $bare );
		foreach ( \WP_CLI::$hooks['before_invoke:nodes doctor'] ?? [] as $hook ) {
			$hook( 'nodes doctor' );
		}
		$this->assertSame( [ "No newspack-intelligence-lists.tsl in any topology dir, so every topology composing Intelligence will fail to load: create {$bare}/newspack-intelligence-lists.tsl, empty if this site has no settings of its own." ], \WP_CLI::$warnings );
		\WP_CLI::reset();
	}

	/** Two active topologies declaring the event writer are refused, naming both. */
	public function test_collection_with_two_intelligence_topologies_is_refused(): void {
		$this->run_intelligence_as( 'knot-intel' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Active topologies newspack-intelligence and knot-intel each declare an Intel_Event_Writer node; activate one.' );
		$this->ci->dispatch( 'health', [ 'collection' ] );
	}

	/**
	 * Two active compositions are refused naming both, even while the lists
	 * file does not resolve, never read as unconfigured.
	 */
	public function test_two_compositions_without_a_lists_file_are_refused_not_unconfigured(): void {
		Topology_Registry::register_user_dir( $this->make_temp_dir( 'intelligence-health-guillemot-' ) );
		$this->activate();
		$this->run_intelligence_as( 'auk-intel' );
		$this->run_intelligence_as( 'murre-intel' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Active topologies auk-intel and murre-intel each declare an Intel_Event_Writer node; activate one.' );
		$this->ci->dispatch( 'health', [ 'collection' ] );
	}

	/** An active worker whose heartbeat outran its timeout reads stale, as `wp nodes status` reads it. */
	public function test_collection_reports_stale_lock(): void {
		$lock = Config::get_base_directory() . '/locks/newspack-intelligence.p0.lock.d';
		\mkdir( $lock, 0777, true );
		\touch( "{$lock}/heartbeat", \time() - 7213 );
		\touch( $lock, \time() - 7213 );

		$this->assertSame( [ 'state' => 'stale', 'topology' => 'newspack-intelligence' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A worker holding a fresh lock is live, under whatever name its topology has. */
	public function test_collection_reports_a_live_worker(): void {
		$this->activate();
		$this->run_intelligence_as( 'dunlin-intel' );
		self::live_worker( Config::get_base_directory(), 'dunlin-intel.p0' );
		\touch( Config::get_base_directory() . '/locks/dunlin-intel.p0.lock.d/heartbeat' );

		$this->assertSame( [ 'state' => 'live', 'topology' => 'dunlin-intel' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/**
	 * With no entry of its group on the origin it fetches, a credentialed
	 * source is idle and names the url and group it needs; the Vault wins
	 * over a snapshot that still says ok.
	 */
	public function test_source_without_vault_entry_is_idle_with_credential(): void {
		$this->collect( 'make_node Linear_Source kestrel-linear internal linear-t7' );
		$this->lend( 'linear', 'https://api.linear.app' );
		$this->snapshot( 'kestrel-linear', [ 'state' => 'ok', 'last_ok_at' => 1792490111, 'failed' => 0, 'requests' => 4 ] );

		$this->assertSame(
			[
				[
					'name'       => 'kestrel-linear',
					'class'      => 'Linear_Source',
					'tier'       => 'internal',
					'state'      => 'idle',
					'credential' => [ 'url' => 'https://api.linear.app/graphql', 'group' => 'linear-t7' ],
				],
			],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
	}

	/** A source needing no credential, or one its group lends, answers its published snapshot. */
	public function test_source_snapshot_is_reported(): void {
		$this->collect( 'make_node Feed_Source grebe-feed public', 'make_node Linear_Source heron-linear internal heron-g4' );
		$this->lend( 'heron-g4', 'https://api.linear.app' );
		$failing = [ 'state' => 'failing', 'last_ok_at' => null, 'last_error' => 'HTTP 404 https://grebe-5519.example/feed/', 'failing_since' => 1792481234, 'failed' => 3, 'requests' => 3, 'reason' => null ];
		$ok      = [ 'state' => 'ok', 'last_ok_at' => 1792495678, 'last_error' => null, 'failing_since' => null, 'failed' => 2, 'requests' => 9, 'reason' => null ];
		$this->snapshot( 'grebe-feed', $failing );
		$this->snapshot( 'heron-linear', $ok );

		$this->assertSame(
			[
				[ 'name' => 'grebe-feed', 'class' => 'Feed_Source', 'tier' => 'public', ...$failing ],
				[ 'name' => 'heron-linear', 'class' => 'Linear_Source', 'tier' => 'internal', ...$ok ],
			],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
	}

	/** A running source whose line will not bind refuses the slice, naming the source and why. */
	public function test_sources_refuses_a_source_line_that_will_not_bind(): void {
		$this->relay_at( $this->make_temp_dir( 'intelligence-health-relay-auth-' ), true );
		$this->activate( 'newspack-intelligence-workstation' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Context_A8C_Linear linear: missing required argument: auth_dir' );
		$this->ci->dispatch( 'health', [ 'sources' ] );
	}

	/**
	 * Each line binds by its class's own arguments: a ContextA8C source's
	 * second positional is its token dir, not a Vault group, and the source
	 * answers the idle it published itself.
	 */
	public function test_a_context_a8c_source_reads_its_tier_by_name(): void {
		$this->collect( 'make_node Context_A8C_Linear cormorant-linear internal /tmp/mcp-auth-7413' );
		$idle = [ 'state' => 'idle', 'last_ok_at' => null, 'last_error' => null, 'failing_since' => null, 'failed' => null, 'requests' => null, 'reason' => 'not signed in to ContextA8C: no token in /tmp/mcp-auth-7413' ];
		$this->snapshot( 'cormorant-linear', $idle );

		$this->assertSame(
			[ [ 'name' => 'cormorant-linear', 'class' => 'Context_A8C_Linear', 'tier' => 'internal', ...$idle ] ],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
	}

	/** A source that has not reported yet is unknown, not ok. */
	public function test_source_with_no_snapshot_is_unknown(): void {
		$this->collect( 'make_node Github_Source egret-github public' );

		$this->assertSame(
			[ [ 'name' => 'egret-github', 'class' => 'Github_Source', 'tier' => 'public', 'state' => 'unknown' ] ],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
	}

	/**
	 * A client feed the feed source publishes dead names its client, as the
	 * survivor of the entity holding the domain, by key and by the name every
	 * read shows it by, beside the domain and the start of its run, oldest
	 * first; the feed's own row carries no list.
	 */
	public function test_a_client_feed_dead_three_passes_is_listed(): void {
		$this->seed(
			self::entity( 'organization:wren-ledger', 'Wren Ledger', [ 'domain' => [ 'wrenledger.example' ] ] ),
			self::entity( 'organization:wren-ledger-old', 'Wren Ledger Archive', [ 'domain' => [ 'wren-archive-4418.example' ] ] ),
			self::merged( 'organization:wren-ledger-old', 'organization:wren-ledger' ),
			self::entity( 'organization:shoal-crier', 'Shoal Crier', [ 'domain' => [ 'shoalcrier.example' ] ] ),
			self::client_role( 'organization:wren-ledger' ),
			self::client_role( 'organization:shoal-crier' )
		);
		$this->snapshot( 'feed', [ 'state' => 'ok', 'last_ok_at' => 1792495678, 'failed' => 3, 'requests' => 40, 'dead_clients' => [ 'wrenledger.example' => 1792461117, 'wren-archive-4418.example' => 1792388020, 'shoalcrier.example' => 1792402263 ] ] );

		$health = $this->ci->dispatch( 'health', [ 'sources' ] );

		$this->assertSame(
			[
				[ 'entity_key' => 'organization:wren-ledger', 'name' => 'Wren Ledger', 'domain' => 'wren-archive-4418.example', 'since' => 1792388020 ],
				[ 'entity_key' => 'organization:shoal-crier', 'name' => 'Shoal Crier', 'domain' => 'shoalcrier.example', 'since' => 1792402263 ],
				[ 'entity_key' => 'organization:wren-ledger', 'name' => 'Wren Ledger', 'domain' => 'wrenledger.example', 'since' => 1792461117 ],
			],
			$health['dead_clients']
		);
		$this->assertArrayNotHasKey( 'dead_clients', $health['sources'][1] );
		$this->assertSame( 'feed', $health['sources'][1]['name'] );
	}

	/**
	 * A dead feed does not list a client where the domain is not any entity's
	 * identifier, where a curator already ended the client role, or where the
	 * entity holding it was never a client.
	 */
	public function test_a_dead_feed_without_an_open_client_is_not_listed(): void {
		$this->seed(
			self::entity( 'organization:tern-weekly', 'Tern Weekly', [ 'domain' => [ 'ternweekly.example' ] ] ),
			self::entity( 'organization:gannet-post', 'Gannet Post', [ 'domain' => [ 'gannetpost.example' ] ] ),
			self::client_role( 'organization:tern-weekly' ),
			[ Intel_Event::make( 'role_ended', [ 'entity_key' => 'organization:tern-weekly', 'role' => 'client', 'from_ts' => self::$jan_01, 'to_ts' => self::$oct_13 ], 'user:41', 'fixture-9' ) ]
		);
		$this->snapshot( 'feed', [ 'state' => 'ok', 'dead_clients' => [ 'ternweekly.example' => 1792461117, 'gannetpost.example' => 1792402263, 'nobody-6620.example' => 1792388020 ] ] );

		$this->assertSame( [], $this->ci->dispatch( 'health', [ 'sources' ] )['dead_clients'] );
	}

	/**
	 * A client a reader cannot see, a proposal only a restricted item
	 * cites, is listed to a holder of the restricted tier alone.
	 */
	public function test_a_dead_client_the_reader_cannot_see_is_not_listed(): void {
		$this->seed(
			self::proposed( 'organization:petrel-gazette', 'Petrel Gazette', [ 'domain' => [ 'petrelgazette.example' ] ] ),
			self::client_role( 'organization:petrel-gazette' ),
			self::link( 'link_proposed', 'hubspot:petrel-renewal', 'organization:petrel-gazette', 'subject', 'name', 0.6, self::$oct_13 )
		);
		self::write_restricted_item( 'hubspot:petrel-renewal', 'Petrel Gazette renewal' );
		$this->snapshot( 'feed', [ 'state' => 'ok', 'dead_clients' => [ 'petrelgazette.example' => 1792461117 ] ] );

		$this->assertSame( [], $this->ci->dispatch( 'health', [ 'sources' ] )['dead_clients'] );

		self::sign_in( 6151, 'harbor-closer', 'manage_options', Intel_Tiers::CAPABILITY );

		$this->assertSame( [ [ 'entity_key' => 'organization:petrel-gazette', 'name' => 'Petrel Gazette', 'domain' => 'petrelgazette.example', 'since' => 1792461117 ] ], $this->ci->dispatch( 'health', [ 'sources' ] )['dead_clients'] );
	}

	/** Review Focus 4: the answer itself leaves out a source whose tier the reader cannot read. */
	public function test_restricted_sources_omitted_for_reader_without_capability(): void {
		$this->assertSame( [ 'github', 'feed', 'news', 'linear', 'slack', 'p2' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'name' ) );

		self::sign_in( 6151, 'harbor-closer', 'manage_options', Intel_Tiers::CAPABILITY );

		$this->assertSame( [ 'github', 'feed', 'news', 'unthread', 'linear', 'slack', 'p2', 'p2-restricted' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'name' ) );
	}

	/**
	 * A source reads at the tier its topology row names, so a restricted
	 * line set internal is shown to a reader without the capability.
	 */
	public function test_a_source_reads_at_its_rows_tier(): void {
		$this->collect(
			'make_node Feed_Source tern-feed public',
			'command_node tern-feed:config set_tier restricted',
			'make_node Feed_Source skua-feed restricted',
			'command_node skua-feed:config set_tier internal'
		);

		$this->assertSame( [ [ 'skua-feed', 'internal' ] ], \array_map( static fn ( array $row ): array => [ $row['name'], $row['tier'] ], $this->ci->dispatch( 'health', [ 'sources' ] )['sources'] ) );
	}

	/** The Vault is asked once per group and credential url in a call, however many sources share them. */
	public function test_the_vault_is_asked_once_per_group_and_url(): void {
		$this->collect(
			'make_node P2_Source kittiwake-p2 internal kittiwake-g2',
			'make_node P2_Source fulmar-p2 internal kittiwake-g2',
			'make_node Linear_Source shrike-linear internal kittiwake-g2'
		);
		$asked    = new \ArrayObject();
		$instance = new \ReflectionProperty( Vault::class, 'instance' );
		$instance->setValue(
			null,
			new class( $asked ) extends Vault {
				public function __construct( private \ArrayObject $asked ) {
					parent::__construct();
				}

				public function in_group( string $group ): array {
					$this->asked[] = $group;
					return parent::in_group( $group );
				}
			}
		);

		try {
			$this->assertSame( [ 'idle', 'idle', 'idle' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'state' ) );
		} finally {
			$instance->setValue( null, null );
		}

		$this->assertSame( [ 'kittiwake-g2', 'kittiwake-g2' ], $asked->getArrayCopy(), 'once for the P2 url, once for the Linear url' );
	}

	/** An empty index holds every item; the count is the later queue's depth, the start the resolver's. */
	public function test_backlog_reason_no_live_entity_with_empty_index(): void {
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400017 ] );
		$this->snapshot( 'intel:later', self::queue( 1352, null ) );

		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => 1352, 'since' => 1792400017, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** A resolver snapshot naming another reason, as a cleared hold before a new one, gives no start; the queue still counts. */
	public function test_backlog_since_unknown_when_snapshot_names_another_reason(): void {
		$dropped = [ 'first_day' => '2026-09-21', 'last_day' => '2026-09-23' ];
		$this->snapshot( 'resolver', [ 'reason' => '', 'since' => null ] );
		$this->snapshot( 'intel:later', self::queue( 29, $dropped ) );

		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => 29, 'since' => null, 'dropped' => $dropped ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** Review Focus 1: a flushed cache leaves the reason, derived here, and the count unknown, not zero. */
	public function test_backlog_reason_survives_cache_flush_with_null_count(): void {
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400017 ] );
		$this->snapshot( 'intel:later', self::queue( 1352, null ) );
		Core::$memd = new InMemoryMemcached();

		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => null, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** With entities to resolve against, an ontology event the projector has not read holds. */
	public function test_backlog_reason_behind_ontology_with_seeded_index(): void {
		$this->seed_world();
		Stream_Writer::append( self::entity( 'organization:mesa-courier', 'Mesa Courier' ) );

		$this->assertSame( 'behind_ontology', $this->ci->dispatch( 'health', [ 'backlog' ] )['reason'] );
	}

	/** Right after a seed, an empty index read behind the stream holds as the projector catching up. */
	public function test_backlog_reason_behind_ontology_right_after_a_seed(): void {
		Stream_Writer::append( self::entity( 'organization:mesa-courier', 'Mesa Courier' ) );

		$this->assertSame( 'behind_ontology', $this->ci->dispatch( 'health', [ 'backlog' ] )['reason'] );
	}

	/** A projected index with live entities holds nothing. */
	public function test_backlog_reason_is_blank_when_nothing_holds(): void {
		$this->seed_world();

		$this->assertSame( '', $this->ci->dispatch( 'health', [ 'backlog' ] )['reason'] );
	}

	/** A re-resolution whose range covers the dropped days clears them; one that does not, keeps them. */
	public function test_backlog_dropped_cleared_by_covering_range(): void {
		$this->seed_world();
		$dropped = [ 'first_day' => '2026-10-12', 'last_day' => '2026-10-14' ];
		$this->snapshot( 'resolver', [ 'reason' => '', 'since' => null ] );
		$this->snapshot( 'intel:later', self::queue( 3, $dropped ) );

		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'no re-resolution sent' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-13', 'until' => '2026-10-14' ] ] );
		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'a range short of the first day' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-12', 'until' => '2026-10-13' ] ] );
		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'a range short of the last day' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-11', 'until' => '2026-10-14' ] ] );
		$this->assertSame( [ 'reason' => '', 'held' => 3, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** The extractor's snapshot answers as published. */
	public function test_extraction_reports_the_snapshot(): void {
		$published = [ 'mode' => 'call', 'vault_group' => 'ai-proxy-k9', 'last_ok_at' => 1792491357, 'last_error' => 'HTTP 502 https://proxy.test/v1', 'failing_since' => 1792493000 ];
		$this->snapshot( 'extractor', $published );

		$this->assertSame( $published + [ 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** The model-call queue rides beside the extractor's snapshot, each priority class and the calls in flight. */
	public function test_extraction_carries_the_model_call_queue(): void {
		$published = [ 'mode' => 'call', 'vault_group' => 'ai-proxy-k9', 'last_ok_at' => 1792491357, 'last_error' => null, 'failing_since' => null ];
		$this->snapshot( 'extractor', $published );
		$this->snapshot( 'intel:llm', self::calls( 17, 5741, 3 ) );

		$this->assertSame( $published + [ 'queue' => [ 'new' => 17, 'backlog' => 5741, 'in_flight' => 3 ] ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** A snapshot whose counts the Table did not answer reads as an unknown queue, never zero. */
	public function test_an_uncounted_queue_reads_as_unknown(): void {
		$this->snapshot( 'intel:llm', [ 'queue' => null, 'at' => (int) Core::right_now() ] );

		$this->assertSame( [ 'mode' => 'unknown', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** A stopped worker's counts read as unknown once they are older than two heartbeats. */
	public function test_a_stopped_workers_queue_reads_as_unknown_after_two_heartbeats(): void {
		Core::$clock = static fn (): float => 1794200000.0;
		$beats       = 2 * LLM_Call_Node::STATUS_HEARTBEAT_S;
		$this->snapshot( 'intel:llm', self::calls( 3, 418, 2, $beats ) );
		$this->assertSame( [ 'new' => 3, 'backlog' => 418, 'in_flight' => 2 ], $this->ci->dispatch( 'health', [ 'extraction' ] )['queue'], 'two heartbeats old' );

		$this->snapshot( 'intel:llm', self::calls( 3, 418, 2, $beats + 1 ) );
		$this->assertNull( $this->ci->dispatch( 'health', [ 'extraction' ] )['queue'] );
	}

	/** With no extractor snapshot the queue still reads beside the unknown mode. */
	public function test_an_unknown_mode_still_carries_the_queue(): void {
		$this->snapshot( 'intel:llm', self::calls( 0, 29, 2 ) );

		$this->assertSame( [ 'mode' => 'unknown', 'queue' => [ 'new' => 0, 'backlog' => 29, 'in_flight' => 2 ] ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** With no snapshot the mode is unknown; the newest extraction's actor says only how the last one was made. */
	public function test_extraction_falls_back_to_newest_actor(): void {
		$this->seed_world();
		$this->seed(
			self::item( 'feed:quiet-note', 'A quiet note', 'feed', 'public', self::$oct_13 ),
			[ Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'feed:quiet-note', 'summary' => 'A quiet note.', 'kind' => 'other', 'relevance' => 0.0, 'importance' => 0.0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => self::$oct_13 + 600 ], Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ) . '9.4.1', 'fixture-9' ) ]
		);

		$this->assertSame( [ 'mode' => 'unknown', 'last_mode' => 'heuristic', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );

		$this->seed( self::extraction( 'feed:quiet-note', 'A quiet note, read.', 'launch', self::$oct_13, [] ) );

		$this->assertSame( [ 'mode' => 'unknown', 'last_mode' => 'call', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );

		$this->seed(
			self::item( 'feed:gated-note', 'A gated note', 'feed', 'public', self::$oct_13 ),
			[ Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'feed:gated-note', 'summary' => 'A gated note.', 'kind' => 'other', 'relevance' => 0.0, 'importance' => 0.0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => self::$oct_13 + 600 ], Intel_Event::machine_prefix( Extractor_Node::GATED_STAGE ) . '9.4.1', 'fixture-9' ) ]
		);

		$this->assertSame( [ 'mode' => 'unknown', 'last_mode' => 'heuristic', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ), 'a gated stand-in was made by no call' );
	}

	/** Review Focus 3: with no shared cache every published fact is unknown, the Vault still answers idle, and nothing throws. */
	public function test_no_shared_cache_reads_unknown_without_throwing(): void {
		Core::$memd = null;

		$this->assertSame( 'down', $this->ci->dispatch( 'health', [ 'collection' ] )['state'] );
		$this->assertSame( [ 'unknown', 'unknown', 'unknown', 'idle', 'idle', 'idle' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'state' ) );
		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => null, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
		$this->assertSame( [ 'mode' => 'unknown', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/**
	 * A reader below manage gets each source's and the extractor's state,
	 * times, counts and idle reason, and no credential, Vault group or error
	 * text.
	 */
	public function test_a_reader_below_manage_sees_no_credential_vault_group_or_error(): void {
		$this->collect( 'make_node Linear_Source kestrel-linear internal linear-t7', 'make_node Feed_Source grebe-feed public', 'make_node Context_A8C_Linear cormorant-linear internal /tmp/mcp-auth-7413' );
		$this->snapshot( 'grebe-feed', [ 'state' => 'failing', 'last_ok_at' => 1792470021, 'last_error' => 'HTTP 404 https://grebe-5519.example/feed/', 'failing_since' => 1792481234, 'failed' => 3, 'requests' => 5 ] );
		$this->snapshot( 'cormorant-linear', [ 'state' => 'idle', 'reason' => 'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host' ] );
		$this->snapshot( 'extractor', [ 'mode' => 'call', 'vault_group' => 'ai-proxy-k9', 'last_ok_at' => 1792491357, 'last_error' => 'HTTP 502 https://proxy.test/v1', 'failing_since' => 1792493000 ] );
		$this->snapshot( 'intel:llm', self::calls( 6, 811, 1 ) );
		\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::READ;

		$this->assertSame(
			[
				[ 'name' => 'kestrel-linear', 'class' => 'Linear_Source', 'tier' => 'internal', 'state' => 'idle' ],
				[ 'name' => 'grebe-feed', 'class' => 'Feed_Source', 'tier' => 'public', 'state' => 'failing', 'last_ok_at' => 1792470021, 'failing_since' => 1792481234, 'failed' => 3, 'requests' => 5 ],
				[ 'name' => 'cormorant-linear', 'class' => 'Context_A8C_Linear', 'tier' => 'internal', 'state' => 'idle', 'reason' => 'not signed in to ContextA8C: the refresh was refused (HTTP 400); sign in to ContextA8C again on the host' ],
			],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
		$this->assertSame( [ 'mode' => 'call', 'last_ok_at' => 1792491357, 'failing_since' => 1792493000, 'queue' => [ 'new' => 6, 'backlog' => 811, 'in_flight' => 1 ] ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** A manager sees the credential a source needs, the extractor's Vault group and every error text. */
	public function test_a_manager_sees_credential_vault_group_and_error(): void {
		$this->collect( 'make_node Linear_Source kestrel-linear internal linear-t7', 'make_node Feed_Source grebe-feed public' );
		$this->snapshot( 'grebe-feed', [ 'state' => 'failing', 'last_ok_at' => null, 'last_error' => 'HTTP 404 https://grebe-5519.example/feed/', 'failing_since' => 1792481234, 'failed' => 3, 'requests' => 3, 'reason' => null ] );
		$this->snapshot( 'extractor', [ 'mode' => 'call', 'vault_group' => 'ai-proxy-k9', 'last_ok_at' => 1792491357, 'last_error' => 'HTTP 502 https://proxy.test/v1', 'failing_since' => 1792493000 ] );
		\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::MANAGE;

		$sources = $this->ci->dispatch( 'health', [ 'sources' ] )['sources'];
		$this->assertSame( [ 'url' => 'https://api.linear.app/graphql', 'group' => 'linear-t7' ], $sources[0]['credential'] );
		$this->assertSame( 'HTTP 404 https://grebe-5519.example/feed/', $sources[1]['last_error'] );
		$this->assertSame( [ 'ai-proxy-k9', 'HTTP 502 https://proxy.test/v1' ], \array_values( \array_intersect_key( $this->ci->dispatch( 'health', [ 'extraction' ] ), [ 'vault_group' => 1, 'last_error' => 1 ] ) ) );
	}

	/** Each worker node's snapshot is read under the name the active topology gives it, the queue's under the one its resolver parks on. */
	public function test_snapshots_are_read_under_the_names_the_topology_gives(): void {
		$this->shadow( 'newspack-intelligence-resolve', 'make_node LLM_Call plover-llm ai-proxy', 'make_node Resolver plover-resolver intel:writer plover-later plover-llm', 'make_node Extractor plover-extractor intel:writer plover-later plover-llm' );
		$extraction = [ 'mode' => 'call', 'vault_group' => 'ai-proxy-p3', 'last_ok_at' => 1792477001, 'last_error' => null, 'failing_since' => null ];
		$this->snapshot( 'plover-extractor', $extraction );
		$this->snapshot( 'extractor', [ 'mode' => 'heuristic', 'vault_group' => 'ai-proxy-x1', 'last_ok_at' => null, 'last_error' => null, 'failing_since' => null ] );
		$this->snapshot( 'plover-resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400555 ] );
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400999 ] );
		$this->snapshot( 'plover-later', self::queue( 77, null ) );
		$this->snapshot( 'intel:later', self::queue( 12, null ) );
		$this->snapshot( 'plover-llm', self::calls( 4, 93, 2 ) );
		$this->snapshot( 'intel:llm', self::calls( 8, 5000, 3 ) );

		$this->assertSame( $extraction + [ 'queue' => [ 'new' => 4, 'backlog' => 93, 'in_flight' => 2 ] ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => 77, 'since' => 1792400555, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** A worker node the topology does not declare reads as unknown, not as an error. */
	public function test_a_node_the_topology_lacks_reads_unknown(): void {
		$this->shadow( 'newspack-intelligence-resolve', 'make_node Deduper deduper intel:writer' );
		$this->snapshot( 'extractor', [ 'mode' => 'call', 'vault_group' => 'ai-proxy-x1', 'last_ok_at' => 1792477001, 'last_error' => null, 'failing_since' => null ] );
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400999 ] );
		$this->snapshot( 'intel:llm', self::calls( 5, 61, 1 ) );

		$this->assertSame( [ 'mode' => 'unknown', 'queue' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => null, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** Dropped days clear by the range the topology's own reresolver last queued. */
	public function test_dropped_days_clear_by_the_named_reresolver(): void {
		$this->seed_world();
		$this->shadow(
			'newspack-intelligence',
			'include newspack-intelligence-streams',
			'include newspack-intelligence-project',
			'include newspack-intelligence-resolve',
			'make_node Reresolver avocet-reresolver intel:reresolve'
		);
		$dropped = [ 'first_day' => '2026-10-12', 'last_day' => '2026-10-14' ];
		$this->snapshot( 'intel:later', self::queue( 0, $dropped ) );
		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-11', 'until' => '2026-10-15' ] ] );

		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'a range another name queued' );

		$this->snapshot( 'avocet-reresolver', [ 'last_range' => [ 'since' => '2026-10-11', 'until' => '2026-10-15' ] ] );
		$this->assertNull( $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'] );
	}

	public function test_health_refuses_unknown_slice(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'what must be one of: collection, sources, backlog, extraction' );

		$this->ci->dispatch( 'health', [ 'brakes' ] );
	}

	/**
	 * Run Intelligence alone under a topology of another name, declaring
	 * these make_node lines after the streams.
	 *
	 * @param string ...$lines The topology's lines.
	 */
	private function collect( string ...$lines ): void {
		$this->activate();
		$this->run_intelligence_as( 'curlew-intel', 'include newspack-intelligence-resolve', ...$lines );
	}

	/**
	 * Shadow a shipped topology with these lines alone.
	 *
	 * @param string $topology The topology's name.
	 * @param string ...$lines The topology's lines.
	 */
	private function shadow( string $topology, string ...$lines ): void {
		$dir = $this->make_temp_dir( 'intelligence-health-' );
		\file_put_contents( "{$dir}/{$topology}.tsl", \implode( "\n", $lines ) . "\n" );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', $dir );
		Topology_Analyzer::reset_caches();
	}

	/**
	 * The later queue's snapshot, as the Delay publishes it each sweep.
	 *
	 * @param int                                          $depth   The entries it holds.
	 * @param array{first_day:string,last_day:string}|null $dropped The days of the entries it dropped.
	 * @return array<string,mixed>
	 */
	private static function queue( int $depth, ?array $dropped ): array {
		return [
			'depth'            => $depth,
			'oldest_parked_at' => 0 === $depth ? null : 1792399417,
			'dropped'          => $dropped,
			'dropped_count'    => null === $dropped ? 0 : 41,
		];
	}

	/**
	 * The model-call queue's snapshot, as `LLM_Call_Node` publishes it,
	 * written `$age` seconds ago.
	 *
	 * @return array<string,mixed>
	 */
	private static function calls( int $new, int $backlog, int $in_flight, int $age = 0 ): array {
		return [
			'queue' => [ 'new' => $new, 'backlog' => $backlog, 'in_flight' => $in_flight ],
			'at'    => (int) Core::right_now() - $age,
		];
	}

	/**
	 * A curator opening an entity's client role on New Year's Day.
	 *
	 * @param string $entity The entity key.
	 * @return list<array<string,mixed>>
	 */
	private static function client_role( string $entity ): array {
		return [ Intel_Event::make( 'role_asserted', [ 'entity_key' => $entity, 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ];
	}

	/**
	 * Hold a snapshot in the shared cache, as the named node published it at partition 0.
	 *
	 * @param string              $name   The node's name.
	 * @param array<string,mixed> $status The snapshot.
	 */
	private function snapshot( string $name, array $status ): void {
		$this->memd->set( Source_Node::status_key( $name, 0 ), $status, 600 );
	}
}

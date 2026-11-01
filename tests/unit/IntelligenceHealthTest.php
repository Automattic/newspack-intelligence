<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Intelligence\Source_Node;
use Newspack_Intelligence\Stream_Writer;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/drives-curl.php';
require_once __DIR__ . '/../support/routes-to-workers.php';

/**
 * The `health` read verb: four slices, each derived from durable state where
 * that can answer — the worker's lock, the topology, the Vault and the
 * projection — and from the snapshots the workers publish for the rest, a
 * snapshot no cache holds reading as unknown, never as healthy.
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

	public function test_health_is_a_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \Newspack_Nodes\Capabilities::READ, $roles['health'] );
	}

	/** A topology outside the active set, with no worker holding a lock, is off. */
	public function test_collection_inactive_when_topology_off(): void {
		$this->assertSame( [ 'state' => 'inactive', 'topology' => 'newspack-intelligence' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** An active worker whose heartbeat outran its timeout reads stale, as `wp nodes status` reads it. */
	public function test_collection_reports_stale_lock(): void {
		$this->activate( 'newspack-intelligence' );
		$lock = Config::get_base_directory() . '/locks/newspack-intelligence.p0.lock.d';
		\mkdir( $lock, 0777, true );
		\touch( "{$lock}/heartbeat", \time() - 7213 );
		\touch( $lock, \time() - 7213 );

		$this->assertSame( [ 'state' => 'stale', 'topology' => 'newspack-intelligence' ], $this->ci->dispatch( 'health', [ 'collection' ] ) );
	}

	/** A worker holding a fresh lock is live. */
	public function test_collection_reports_a_live_worker(): void {
		$this->activate( 'newspack-intelligence' );
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p0' );
		\touch( Config::get_base_directory() . '/locks/newspack-intelligence.p0.lock.d/heartbeat' );

		$this->assertSame( 'live', $this->ci->dispatch( 'health', [ 'collection' ] )['state'] );
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
		$this->collect( 'make_node Feed_Source publication4-feed public', 'make_node Linear_Source heron-linear internal heron-g4' );
		$this->lend( 'heron-g4', 'https://api.linear.app' );
		$failing = [ 'state' => 'failing', 'last_ok_at' => null, 'last_error' => 'HTTP 404 https://publication4.test/feed/', 'failing_since' => 1792481234, 'failed' => 3, 'requests' => 3, 'reason' => null ];
		$ok      = [ 'state' => 'ok', 'last_ok_at' => 1792495678, 'last_error' => null, 'failing_since' => null, 'failed' => 2, 'requests' => 9, 'reason' => null ];
		$this->snapshot( 'publication4-feed', $failing );
		$this->snapshot( 'heron-linear', $ok );

		$this->assertSame(
			[
				[ 'name' => 'publication4-feed', 'class' => 'Feed_Source', 'tier' => 'public', ...$failing ],
				[ 'name' => 'heron-linear', 'class' => 'Linear_Source', 'tier' => 'internal', ...$ok ],
			],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
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

	/** A source no pass has reported for is unknown, not ok. */
	public function test_source_with_no_snapshot_is_unknown(): void {
		$this->collect( 'make_node Github_Source egret-github public' );

		$this->assertSame(
			[ [ 'name' => 'egret-github', 'class' => 'Github_Source', 'tier' => 'public', 'state' => 'unknown' ] ],
			$this->ci->dispatch( 'health', [ 'sources' ] )['sources']
		);
	}

	/** Review Focus 4: the answer itself leaves out a source whose tier the reader cannot read. */
	public function test_restricted_sources_omitted_for_reader_without_capability(): void {
		$this->assertSame( [ 'github', 'feed', 'news', 'linear', 'slack', 'p2' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'name' ) );

		self::sign_in( 6151, 'harbor-closer', 'manage_options', Intel_Tiers::CAPABILITY );

		$this->assertSame( [ 'github', 'feed', 'news', 'unthread', 'linear', 'slack', 'p2', 'p2-restricted' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'name' ) );
	}

	/** An empty index holds every item; the count and start come from the resolver's snapshot. */
	public function test_backlog_reason_no_live_entity_with_empty_index(): void {
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400017, 'held' => 1352, 'dropped' => null ] );

		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => 1352, 'since' => 1792400017, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** Review Focus 1: a flushed cache leaves the reason, derived here, and the count unknown, not zero. */
	public function test_backlog_reason_survives_cache_flush_with_null_count(): void {
		$this->snapshot( 'resolver', [ 'reason' => 'no_live_entity', 'since' => 1792400017, 'held' => 1352, 'dropped' => null ] );
		Core::$memd = new InMemoryMemcached();

		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => null, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** With entities to resolve against, an ontology event the projector has not read holds. */
	public function test_backlog_reason_behind_ontology_with_seeded_index(): void {
		$this->seed_world();
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
		$this->snapshot( 'resolver', [ 'reason' => '', 'since' => null, 'held' => 0, 'dropped' => $dropped ] );

		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'no re-resolution sent' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-13', 'until' => '2026-10-14' ] ] );
		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'a range short of the first day' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-12', 'until' => '2026-10-13' ] ] );
		$this->assertSame( $dropped, $this->ci->dispatch( 'health', [ 'backlog' ] )['dropped'], 'a range short of the last day' );

		$this->snapshot( 'reresolver', [ 'last_range' => [ 'since' => '2026-10-11', 'until' => '2026-10-14' ] ] );
		$this->assertSame( [ 'reason' => '', 'held' => 0, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
	}

	/** The extractor's snapshot answers as published. */
	public function test_extraction_reports_the_snapshot(): void {
		$published = [ 'mode' => 'call', 'vault_id' => 'proxy-k9', 'last_ok_at' => 1792491357, 'last_error' => 'HTTP 502 https://proxy.test/v1', 'failing_since' => 1792493000 ];
		$this->snapshot( 'extractor', $published );

		$this->assertSame( $published, $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	/** With no snapshot, the newest extraction's actor says how the extractor last extracted. */
	public function test_extraction_falls_back_to_newest_actor(): void {
		$this->seed_world();
		$this->seed(
			self::item( 'feed:quiet-note', 'A quiet note', 'feed', 'public', self::$oct_13 ),
			[ Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'feed:quiet-note', 'summary' => 'A quiet note.', 'kind' => 'other', 'relevance' => 0.0, 'importance' => 0.0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => self::$oct_13 + 600 ], Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ) . '9.4.1', 'fixture-9' ) ]
		);

		$this->assertSame( [ 'mode' => 'heuristic', 'vault_id' => null, 'last_ok_at' => null, 'last_error' => null, 'failing_since' => null ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );

		$this->seed( self::extraction( 'feed:quiet-note', 'A quiet note, read.', 'launch', self::$oct_13, [] ) );

		$this->assertSame( 'call', $this->ci->dispatch( 'health', [ 'extraction' ] )['mode'] );
	}

	/** Review Focus 3: with no shared cache every published fact is unknown, the Vault still answers idle, and nothing throws. */
	public function test_no_shared_cache_reads_unknown_without_throwing(): void {
		Core::$memd = null;

		$this->assertSame( 'inactive', $this->ci->dispatch( 'health', [ 'collection' ] )['state'] );
		$this->assertSame( [ 'unknown', 'unknown', 'unknown', 'idle', 'idle', 'idle' ], \array_column( $this->ci->dispatch( 'health', [ 'sources' ] )['sources'], 'state' ) );
		$this->assertSame( [ 'reason' => 'no_live_entity', 'held' => null, 'since' => null, 'dropped' => null ], $this->ci->dispatch( 'health', [ 'backlog' ] ) );
		$this->assertSame( [ 'mode' => 'unknown' ], $this->ci->dispatch( 'health', [ 'extraction' ] ) );
	}

	public function test_health_refuses_unknown_slice(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'what must be one of: collection, sources, backlog, extraction' );

		$this->ci->dispatch( 'health', [ 'brakes' ] );
	}

	/**
	 * Shadow the collect topology with these make_node lines alone.
	 *
	 * @param string ...$lines The topology's lines.
	 */
	private function collect( string ...$lines ): void {
		$dir = $this->make_temp_dir( 'intelligence-health-' );
		\file_put_contents( "{$dir}/newspack-intelligence-collect.tsl", \implode( "\n", $lines ) . "\n" );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', $dir );
		Topology_Analyzer::reset_caches();
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

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Ontology_CLI_Command;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config;
use Newspack_Nodes\Message;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/routes-to-workers.php';

/**
 * `wp newspack-intelligence ontology seed` writes the seed keys the
 * projection lacks to the ontology stream and reports what it found.
 */
final class OntologyCliCommandTest extends TestCase {
	use Reads_Ontology_Log;
	use Routes_To_Workers;
	use Seeds_Entity_Index;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
	}

	protected function tearDown(): void {
		CLI::$uid_provider = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_seed_writes_the_keys_the_projection_lacks(): void {
		$this->seed( self::entity( 'product:newspack-ads', 'Ads (curated)' ) );

		( new Ontology_CLI_Command() )->seed( [], [] );

		$written = self::ontology_events();
		$this->assertSame( \array_column( Ontology_Seed::events( [ 'product:newspack-ads' ] ), 'entity_key' ), \array_column( $written, 'entity_key' ) );
		$this->assertNotContains( 'product:newspack-ads', \array_column( $written, 'entity_key' ) );
		$this->assertSame( [ \sprintf( 'Seeded %d events: 44 keys new, 1 present.', \count( $written ) ) ], \WP_CLI::$successes );
	}

	public function test_seed_fails_loud_without_a_projection(): void {
		$this->activate();

		try {
			( new Ontology_CLI_Command() )->seed( [], [] );
			$this->fail( 'the seed refuses' );
		} catch ( Table_Unavailable ) {
			$this->assertSame( [], self::ontology_events() );
			$this->assertSame( [], \WP_CLI::$successes );
		}
	}

	/** `reresolve` asks each live worker's reresolver to re-resolve a day range. */
	public function test_reresolve_routes_the_range_to_the_reresolver(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p3' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );

		$this->assertSame(
			[ [ Message::TM_REQUEST, 'reresolver', 'RERESOLVE 2026-03-09 2026-04-17' ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::TO ], $m[ Message::VALUE ] ], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p3' ) )
		);
		$this->assertSame( [ 'Asked 1 worker to re-resolve the items dated 2026-03-09 to 2026-04-17.' ], \WP_CLI::$successes );
	}

	/** The command reports the workers it reached, not the locks it found. */
	public function test_reresolve_reports_the_workers_it_reached(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p1' );
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p2' );
		\mkdir( Config::get_base_directory() . '/locks/newspack-intelligence.p4.lock.d', 0777, true );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-05-11', 'until' => '2026-05-12' ] );

		$this->assertSame( [ 'Asked 2 workers to re-resolve the items dated 2026-05-11 to 2026-05-12.' ], \WP_CLI::$successes );
	}

	/** With no --until the range runs through today, UTC. */
	public function test_reresolve_runs_through_today_by_default(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p0' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2025-11-30' ] );

		$today = \gmdate( 'Y-m-d' );
		$this->assertSame( [ "RERESOLVE 2025-11-30 {$today}" ], \array_column( self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p0' ), Message::VALUE ) );
	}

	/**
	 * A date that does not parse, or a range running backwards, is refused
	 * before anything is sent.
	 *
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function bad_ranges(): array {
		return [
			'no such day'   => [ [ 'since' => '2026-02-30', 'until' => '2026-03-01' ], 'not a Y-m-d date: 2026-02-30' ],
			'not a date'    => [ [ 'since' => 'last week' ], 'not a Y-m-d date: last week' ],
			'no since'      => [ [], 'not a Y-m-d date: ' ],
			'backwards'     => [ [ 'since' => '2026-03-02', 'until' => '2026-03-01' ], 'since 2026-03-02 is after until 2026-03-01' ],
		];
	}

	/**
	 * @param array<string,string> $flags   The flags.
	 * @param string               $refusal What the command says.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_ranges' )]
	public function test_reresolve_refuses_a_bad_range( array $flags, string $refusal ): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p0' );

		( new Ontology_CLI_Command() )->reresolve( [], $flags );

		$this->assertSame( [ $refusal ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p0' ) );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_reresolve_fails_loud_with_no_live_worker(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );
		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );
	}

	/**
	 * `reproject` holds the fleet, deletes the graph's file and the cursor of
	 * each Consumer the projector checkpoints with, then releases the hold;
	 * the streams and every other reader's cursor stay.
	 */
	public function test_reproject_deletes_the_graph_and_the_projectors_cursors_inside_a_hold(): void {
		$base    = Config::get_base_directory();
		$graph   = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		$cursors = [ "{$base}/offsets/newspack-intelligence-project.intel-items.p0", "{$base}/offsets/newspack-intelligence-project.intel-ontology.p0", "{$base}/offsets/newspack-intelligence-project.intel-watches.p0" ];
		$kept    = [ "{$base}/offsets/newspack-intelligence-project.intel-items-resolve.p0", "{$base}/logs/intel-ontology.p0" ];
		foreach ( [ ...$cursors, ...$kept ] as $dir ) {
			\mkdir( $dir, 0777, true );
			\file_put_contents( "{$dir}/0.log", "[1]\n" );
		}
		$this->seed( self::entity( 'organization:tidewater-ledger', 'Tidewater Ledger' ) );
		\file_put_contents( "{$graph}-wal", '' );
		$seen            = [];
		\WP_CLI::$on_run = static function ( string $command ) use ( &$seen, $graph, $cursors ): void {
			$seen[] = [ $command, \file_exists( $graph ), \is_dir( $cursors[0] ) ];
		};

		( new Ontology_CLI_Command() )->reproject( [], [] );

		$this->assertSame( [ [ 'nodes stop', true, true ], [ 'nodes start', false, false ] ], $seen );
		$this->assertSame( [ [ 'launch' => true, 'exit_error' => false, 'return' => 'all' ], [ 'launch' => true, 'exit_error' => true ] ], \array_column( \WP_CLI::$ran, 1 ), 'each runs in its own process, so a throw in it leaves this one\'s WP-CLI state alone' );
		$this->assertSame( [ false, false, false, false, false ], [ \file_exists( $graph ), \file_exists( "{$graph}-wal" ), \file_exists( "{$graph}-shm" ), \is_dir( $cursors[1] ), \is_dir( $cursors[2] ) ] );
		$this->assertSame( [ true, true ], \array_map( 'is_dir', $kept ) );
		$this->assertSame( [ 'Deleted the intel-graph projection and 3 projector cursors; the projector replays every stream.' ], \WP_CLI::$successes );
	}

	/** A fleet that will not stop deletes nothing, and the hold is released before the refusal. */
	public function test_reproject_deletes_nothing_when_the_fleet_will_not_stop(): void {
		$graph           = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		$cursor          = Config::get_base_directory() . '/offsets/newspack-intelligence-project.intel-items.p0';
		\mkdir( $cursor, 0777, true );
		$this->seed( self::entity( 'organization:tidewater-ledger', 'Tidewater Ledger' ) );
		\WP_CLI::$on_run = static fn ( string $command ): ?array => 'nodes stop' === $command ? [ 1, "Held the fleet; waiting for workers to exit.\n", "Error: Timed out after 90s; still up: newspack-intelligence.p0\n" ] : null;

		( new Ontology_CLI_Command() )->reproject( [], [] );

		$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
		$this->assertSame( [ true, true ], [ \file_exists( $graph ), \is_dir( $cursor ) ] );
		$this->assertSame( [ 'Held the fleet; waiting for workers to exit.' ], \WP_CLI::$logs );
		$this->assertSame( [ 'The fleet did not stop, so reproject deleted nothing; the hold is released. Error: Timed out after 90s; still up: newspack-intelligence.p0' ], \WP_CLI::$errors );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	/** A stop that throws, having perhaps set the hold, still releases it, and the throw surfaces. */
	public function test_reproject_releases_the_hold_when_the_stop_throws(): void {
		\WP_CLI::$on_run = static function ( string $command ): ?array {
			if ( 'nodes stop' === $command ) {
				throw new \RuntimeException( 'could not flag newspack-intelligence.p0 to stop' );
			}
			return null;
		};

		try {
			( new Ontology_CLI_Command() )->reproject( [], [] );
			$this->fail( 'the stop throws' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'could not flag newspack-intelligence.p0 to stop', $e->getMessage() );
			$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
		}
	}

	/** A delete that fails still releases the hold, and the failure surfaces. */
	public function test_reproject_releases_the_hold_when_a_delete_fails(): void {
		$graph = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		\mkdir( "{$graph}-wal", 0777, true );
		\file_put_contents( "{$graph}-wal/held", 'x' );

		try {
			( new Ontology_CLI_Command() )->reproject( [], [] );
			$this->fail( 'the delete throws' );
		} catch ( \UnexpectedValueException ) {
			$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
			$this->assertSame( [], \WP_CLI::$successes );
		}
	}

	/** Run as root, each verb refuses before it writes, so no root-owned segment, IPC file or hold is left for the workers. */
	public function test_every_verb_refuses_root_before_any_write(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p5' );
		CLI::$uid_provider = static fn (): int => 0;

		( new Ontology_CLI_Command() )->seed( [], [] );
		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );
		( new Ontology_CLI_Command() )->reproject( [], [] );

		$this->assertSame(
			[
				'wp newspack-intelligence ontology seed must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reresolve must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reproject must run as the same user as the workers, not root.',
			],
			\WP_CLI::$errors
		);
		$this->assertSame( [], \WP_CLI::$ran );
		$this->assertSame( 0, self::ontology_segments() );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p5' ) );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_the_ontology_command_is_registered(): void {
		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Ontology_CLI_Command', \WP_CLI::$commands['newspack-intelligence ontology'] ?? null );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Ontology_CLI_Command;
use Newspack_Intelligence\Ontology_Seed;
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
		$this->assertSame( [ \sprintf( 'Seeded %d events: 41 keys new, 1 present.', \count( $written ) ) ], \WP_CLI::$successes );
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

	/** Run as root, either verb refuses before it writes, so no root-owned segment or IPC file is left for the workers. */
	public function test_both_verbs_refuse_root_before_any_write(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p5' );
		CLI::$uid_provider = static fn (): int => 0;

		( new Ontology_CLI_Command() )->seed( [], [] );
		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );

		$this->assertSame(
			[
				'wp newspack-intelligence ontology seed must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reresolve must run as the same user as the workers, not root.',
			],
			\WP_CLI::$errors
		);
		$this->assertSame( 0, self::ontology_segments() );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p5' ) );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_the_ontology_command_is_registered(): void {
		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Ontology_CLI_Command', \WP_CLI::$commands['newspack-intelligence ontology'] ?? null );
	}
}

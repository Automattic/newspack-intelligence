<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\CLI\Clients_CLI_Command;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/fake-client-roster.php';
require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/seeds-entity-index.php';

final class ClientsCliCommandTest extends TestCase {
	use Reads_Ontology_Log;
	use Seeds_Entity_Index;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
	}

	protected function tearDown(): void {
		CLI::$uid_provider = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_import_reads_file_and_populates_repo(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n\"2\",\"2021-01-01\",\"b.com\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$repo    = new Fake_Client_Roster();
		$command = new Clients_CLI_Command( new Client_Importer( $repo ) );
		$command->import( [ $tmp ], [] );

		$this->assertCount( 2, $repo->statuses() );
		\unlink( $tmp );
	}

	public function test_import_reports_wp_cli_error_when_csv_unreadable(): void {
		$command = new Clients_CLI_Command( new Client_Importer( new Fake_Client_Roster() ) );
		$command->import( [ '/no/such/clients.csv' ], [] );

		$this->assertCount( 1, \WP_CLI::$errors );
		$this->assertStringContainsString( '/no/such/clients.csv', \WP_CLI::$errors[0] );
		$this->assertCount( 0, \WP_CLI::$successes );
	}

	public function test_import_reports_wp_cli_success_summary_with_counts(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"11\",\"2020-01-01\",\"aaa.example\"\n\"22\",\"2021-01-01\",\"bbb.example\"\n\"33\",\"2021-01-01\",\"ccc.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$command = new Clients_CLI_Command( new Client_Importer( new Fake_Client_Roster() ) );
		$command->import( [ $tmp ], [] );

		$this->assertCount( 1, \WP_CLI::$successes );
		$this->assertStringContainsString( 'Imported 3 rows: 3 created', \WP_CLI::$successes[0] );
		\unlink( $tmp );
	}

	/** The command imports into the ontology as the client-import machine. */
	public function test_default_constructor_imports_as_the_client_import_machine(): void {
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"91\",\"2023-03-03\",\"gallup.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		( new Clients_CLI_Command() )->import( [ $tmp ], [] );

		$events = self::ontology_events();
		$this->assertSame( [ 'organization:atomic-91' ], \array_values( \array_unique( \array_column( $events, 'entity_key' ) ) ) );
		$this->assertSame( [ 'machine:client-import@' . \NEWSPACK_INTELLIGENCE_VERSION ], \array_values( \array_unique( \array_column( $events, 'actor' ) ) ) );
		$this->assertSame( [ 'Imported 1 rows: 1 created, 0 updated, 0 reactivated, 0 churned.' ], \WP_CLI::$successes );
		\unlink( $tmp );
	}

	/** Each import builds its own roster, so a failed import's buffered events never reach the next. */
	public function test_a_failed_import_leaves_nothing_for_the_next(): void {
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
		$failing = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $failing, "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"990021\",\"2023-03-03\",\"cormorant.example\"\n\"\",\"2023-03-03\",\"blankpier.example\"\n" );
		$good = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $good, "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"990022\",\"2023-03-03\",\"pelican.example\"\n" );
		$command = new Clients_CLI_Command();

		try {
			$command->import( [ $failing ], [] );
			$this->fail( 'the import refuses a row with no atomic id' );
		} catch ( \InvalidArgumentException ) {
			$command->import( [ $good ], [] );
		}

		$this->assertSame( [ 'organization:atomic-990022' ], \array_values( \array_unique( \array_column( self::ontology_events(), 'entity_key' ) ) ) );
		\unlink( $failing );
		\unlink( $good );
	}

	/** Run as root, the import refuses before it reads the CSV or writes a segment the workers could not append to. */
	public function test_import_refuses_root_before_any_write(): void {
		CLI::$uid_provider = static fn (): int => 0;
		$csv               = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"57\",\"2023-03-03\",\"rootless.example\"\n";
		$tmp               = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );
		$roster = new Fake_Client_Roster();

		( new Clients_CLI_Command( new Client_Importer( $roster ) ) )->import( [ $tmp ], [] );

		$this->assertSame( [ 'wp newspack-intelligence clients import must run as the same user as the workers, not root.' ], \WP_CLI::$errors );
		$this->assertSame( [], $roster->store );
		$this->assertSame( [], \WP_CLI::$successes );
		\unlink( $tmp );
	}
}

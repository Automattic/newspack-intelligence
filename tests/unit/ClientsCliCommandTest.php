<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\CLI\Clients_CLI_Command;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/wp-post-stubs.php';
require_once __DIR__ . '/../support/wp-cli-stub.php';

final class ClientsCliCommandTest extends TestCase {

	protected function setUp(): void {
		\WP_CLI::reset();
	}

	public function test_import_reads_file_and_populates_repo(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n\"2\",\"2021-01-01\",\"b.com\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$repo    = new Fake_Publisher_Repository(); // from tests/support/fake-publisher-repository.php
		$command = new Clients_CLI_Command( new Client_Importer( $repo ) );
		$command->import( [ $tmp ], [] );

		$this->assertCount( 2, $repo->all_atomic_ids() );
		\unlink( $tmp );
	}

	public function test_import_reports_wp_cli_error_when_csv_unreadable(): void {
		$command = new Clients_CLI_Command( new Client_Importer( new Fake_Publisher_Repository() ) );
		$command->import( [ '/no/such/clients.csv' ], [] );

		$this->assertCount( 1, \WP_CLI::$errors );
		$this->assertStringContainsString( '/no/such/clients.csv', \WP_CLI::$errors[0] );
		$this->assertCount( 0, \WP_CLI::$successes );
	}

	public function test_import_reports_wp_cli_success_summary_with_counts(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"11\",\"2020-01-01\",\"aaa.example\"\n\"22\",\"2021-01-01\",\"bbb.example\"\n\"33\",\"2021-01-01\",\"ccc.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$command = new Clients_CLI_Command( new Client_Importer( new Fake_Publisher_Repository() ) );
		$command->import( [ $tmp ], [] );

		$this->assertCount( 1, \WP_CLI::$successes );
		$this->assertStringContainsString( 'Imported 3 rows: 3 created', \WP_CLI::$successes[0] );
		\unlink( $tmp );
	}

	public function test_default_constructor_builds_a_cpt_backed_importer(): void {
		\NPAINL_WP_Post_Store::reset();
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"91\",\"2023-03-03\",\"gallup.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$command = new Clients_CLI_Command();
		$command->import( [ $tmp ], [] );

		$this->assertCount( 1, \NPAINL_WP_Post_Store::$posts );
		$this->assertCount( 1, \WP_CLI::$successes );
		\unlink( $tmp );
	}
}

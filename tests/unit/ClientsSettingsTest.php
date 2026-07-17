<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\Clients_Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';

final class ClientsSettingsTest extends TestCase {

	public function test_import_path_parses_and_imports(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$repo     = new Fake_Publisher_Repository(); // from tests/support/fake-publisher-repository.php
		$settings = new Clients_Settings( new Client_Importer( $repo ) );
		$result   = $settings->import_path( $tmp );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['total_in_csv'] );
		\unlink( $tmp );
	}

	public function test_import_path_returns_zeroes_for_unreadable_file(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Publisher_Repository() ) );
		$result   = $settings->import_path( '/no/such/file.csv' );
		$this->assertSame( 0, $result['total_in_csv'] );
	}
}

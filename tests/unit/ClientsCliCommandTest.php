<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Client_Importer;
use Newspack_AI_Newsletter\CLI\Clients_CLI_Command;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';

final class ClientsCliCommandTest extends TestCase {

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
}

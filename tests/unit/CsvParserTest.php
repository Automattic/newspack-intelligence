<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\CSV_Parser;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase {

	private const CSV = <<<CSV
	"Atomic site ID","Created","Domain name"
	"149517526","2020-12-03 17:18:47","abq.news"
	"150645193","2024-05-09 19:04:02","amarillotribune.org"
	CSV;

	public function test_parses_rows_and_skips_header(): void {
		$rows = CSV_Parser::parse( self::CSV );
		$this->assertCount( 2, $rows );
		$this->assertSame(
			[ 'atomic_site_id' => '149517526', 'domain_name' => 'abq.news', 'created' => '2020-12-03 17:18:47' ],
			$rows[0]
		);
	}

	public function test_skips_blank_lines_and_short_rows(): void {
		$rows = CSV_Parser::parse( "\"Atomic site ID\",\"Created\",\"Domain name\"\n\n\"x\",\"y\"\n\"150792457\",\"2024-10-04 15:34:52\",\"enlace.org\"\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '150792457', $rows[0]['atomic_site_id'] );
	}

	public function test_parse_file_returns_null_for_unreadable_path(): void {
		$this->assertNull( CSV_Parser::parse_file( '/no/such/file.csv' ) );
	}

	public function test_parse_file_reads_and_parses_a_real_file(): void {
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n" );

		$rows = CSV_Parser::parse_file( $tmp );

		\unlink( $tmp );

		$this->assertIsArray( $rows );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]['atomic_site_id'] );
	}
}

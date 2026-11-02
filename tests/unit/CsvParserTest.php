<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;
use Newspack_Intelligence\CSV_Parser;

final class CsvParserTest extends TestCase {

	private const CSV = <<<CSV
	"Atomic site ID","Created","Domain name"
	"700100201","2021-03-14 09:26:53","heronpress.example"
	"700100344","2023-08-02 11:40:17","quarryledger.example"
	CSV;

	public function test_parses_rows_and_skips_header(): void {
		$rows = CSV_Parser::parse( self::CSV );
		$this->assertCount( 2, $rows );
		$this->assertSame(
			[ 'atomic_site_id' => '700100201', 'domain_name' => 'heronpress.example', 'created' => '2021-03-14 09:26:53' ],
			$rows[0]
		);
	}

	public function test_skips_blank_lines_and_short_rows(): void {
		$rows = CSV_Parser::parse( "\"Atomic site ID\",\"Created\",\"Domain name\"\n\n\"x\",\"y\"\n\"700100517\",\"2025-01-19 06:05:44\",\"tidewater.example\"\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '700100517', $rows[0]['atomic_site_id'] );
	}

	public function test_parse_returns_null_when_header_missing(): void {
		// A file whose first non-blank line isn't the "Atomic..." header is not a
		// clients export — reject it, don't silently treat row 1 as data.
		$csv = "\"999888777\",\"2019-05-05\",\"notaheader.example\"\n\"111222333\",\"2020-01-01\",\"y.example\"\n";
		$this->assertNull( CSV_Parser::parse( $csv ) );
	}

	public function test_parse_returns_null_when_no_valid_rows(): void {
		// A valid header with zero data rows is an empty snapshot — importing it
		// would (via reconciliation) churn every existing client. Reject it.
		$this->assertNull( CSV_Parser::parse( "\"Atomic site ID\",\"Created\",\"Domain name\"\n" ) );
	}

	public function test_parse_file_returns_null_for_malformed_readable_file(): void {
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, "garbage,without,the,right,header\nmore,junk,rows\n" );

		$rows = CSV_Parser::parse_file( $tmp );

		\unlink( $tmp );

		$this->assertNull( $rows );
	}

	public function test_parse_file_returns_null_for_unreadable_path(): void {
		$this->assertNull( CSV_Parser::parse_file( '/no/such/file.csv' ) );
	}

	public function test_parse_file_reads_and_parses_a_real_file(): void {
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.example\"\n" );

		$rows = CSV_Parser::parse_file( $tmp );

		\unlink( $tmp );

		$this->assertIsArray( $rows );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]['atomic_site_id'] );
	}
}

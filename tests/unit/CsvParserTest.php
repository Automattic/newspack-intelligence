<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;
use Newspack_Intelligence\CSV_Parser;

final class CsvParserTest extends TestCase {

	private const CSV = <<<CSV
	"Site ID","Created","Domain name"
	"700100201","2021-03-14 09:26:53","heronpress.example"
	"700100344","2023-08-02 11:40:17","quarryledger.example"
	CSV;

	public function test_parses_rows_and_skips_header(): void {
		$rows = CSV_Parser::parse( self::CSV );
		$this->assertCount( 2, $rows );
		$this->assertSame(
			[ 'site_id' => '700100201', 'domain_name' => 'heronpress.example', 'created' => '2021-03-14 09:26:53' ],
			$rows[0]
		);
	}

	public function test_skips_blank_lines_and_short_rows(): void {
		$rows = CSV_Parser::parse( "\"Site ID\",\"Created\",\"Domain name\"\n\n\"x\",\"y\"\n\"700100517\",\"2025-01-19 06:05:44\",\"tidewater.example\"\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '700100517', $rows[0]['site_id'] );
	}

	public function test_parse_returns_null_when_header_missing(): void {
		// A file whose first non-blank line isn't the "Site ID" header is not a
		// clients export — reject it, don't silently treat row 1 as data.
		$csv = "\"999888777\",\"2019-05-05\",\"notaheader.example\"\n\"111222333\",\"2020-01-01\",\"y.example\"\n";
		$this->assertNull( CSV_Parser::parse( $csv ) );
	}

	/** The first column is headed "Site ID", case aside; a file headed any other way is not a client export. */
	public function test_parse_returns_null_under_another_first_header(): void {
		$this->assertNull( CSV_Parser::parse( "\"Account ID\",\"Created\",\"Domain name\"\n\"700100201\",\"2021-03-14 09:26:53\",\"heronpress.example\"\n" ) );
		$this->assertCount( 1, (array) CSV_Parser::parse( "\"site id\",\"Created\",\"Domain name\"\n\"700100201\",\"2021-03-14 09:26:53\",\"heronpress.example\"\n" ) );
	}

	/**
	 * A CSV a spreadsheet re-saved leads with a UTF-8 byte order mark, its
	 * header quoted or bare, and still reads as a clients export.
	 */
	public function test_a_byte_order_mark_before_the_header_is_read_past(): void {
		$quoted = CSV_Parser::parse( "\xEF\xBB\xBF\"Site ID\",\"Created\",\"Domain name\"\n\"700100862\",\"2024-06-30 17:12:08\",\"saltmarsh.example\"\n" );
		$bare   = CSV_Parser::parse( "\xEF\xBB\xBFSite ID,Created,Domain name\r\n700100863,2024-07-01 08:00:41,kelpline.example\r\n" );

		$this->assertSame( [ [ 'site_id' => '700100862', 'domain_name' => 'saltmarsh.example', 'created' => '2024-06-30 17:12:08' ] ], $quoted );
		$this->assertSame( [ [ 'site_id' => '700100863', 'domain_name' => 'kelpline.example', 'created' => '2024-07-01 08:00:41' ] ], $bare );
	}

	public function test_parse_returns_null_when_no_valid_rows(): void {
		// A valid header with zero data rows is an empty snapshot — importing it
		// would (via reconciliation) churn every existing client. Reject it.
		$this->assertNull( CSV_Parser::parse( "\"Site ID\",\"Created\",\"Domain name\"\n" ) );
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
		\file_put_contents( $tmp, "\"Site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.example\"\n" );

		$rows = CSV_Parser::parse_file( $tmp );

		\unlink( $tmp );

		$this->assertIsArray( $rows );
		$this->assertCount( 1, $rows );
		$this->assertSame( '1', $rows[0]['site_id'] );
	}
}

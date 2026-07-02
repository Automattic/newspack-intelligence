<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\CSV_Parser;
use PHPUnit\Framework\TestCase;

final class CsvParserTest extends TestCase {

	private const CSV = <<<CSV
	"Atomic site ID","Created","Domain name"
	"700000001","2020-01-01 00:00:01","client-43.example"
	"700000002","2020-01-02 00:00:02","client-278.example"
	CSV;

	public function test_parses_rows_and_skips_header(): void {
		$rows = CSV_Parser::parse( self::CSV );
		$this->assertCount( 2, $rows );
		$this->assertSame(
			[ 'atomic_site_id' => '700000001', 'domain_name' => 'client-43.example', 'created' => '2020-01-01 00:00:01' ],
			$rows[0]
		);
	}

	public function test_skips_blank_lines_and_short_rows(): void {
		$rows = CSV_Parser::parse( "\"Atomic site ID\",\"Created\",\"Domain name\"\n\n\"x\",\"y\"\n\"700000003\",\"2020-01-03 00:00:03\",\"client-434.example\"\n" );
		$this->assertCount( 1, $rows );
		$this->assertSame( '700000003', $rows[0]['atomic_site_id'] );
	}
}

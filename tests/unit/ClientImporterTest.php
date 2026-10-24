<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;
use Newspack_Intelligence\Client_Importer;

require_once __DIR__ . '/../support/fake-client-roster.php';

final class ClientImporterTest extends TestCase {

	private function rows( array ...$triples ): array {
		return \array_map(
			static fn ( array $t ): array => [ 'atomic_site_id' => $t[0], 'domain_name' => $t[1], 'created' => $t[2] ],
			$triples
		);
	}

	/** An empty snapshot reconciles nothing and commits nothing, or every client would churn. */
	public function test_import_does_not_churn_on_empty_snapshot(): void {
		$roster                  = new Fake_Client_Roster();
		$roster->store['424242'] = [ 'domain_name' => 'stays.example', 'status' => 'active', 'since' => '2026-01-01' ];

		$result = ( new Client_Importer( $roster ) )->import( [], '2026-07-16' );

		$this->assertSame( 0, $result['churned'] );
		$this->assertSame( 'active', $roster->store['424242']['status'] );
		$this->assertSame( 0, $roster->commits );
	}

	public function test_creates_new_publishers(): void {
		$roster = new Fake_Client_Roster();
		$result = ( new Client_Importer( $roster ) )->import(
			$this->rows( [ '1', 'a.com', '2020-01-01' ], [ '2', 'b.com', '2021-01-01' ] ),
			'2026-06-30'
		);
		$this->assertSame( 2, $result['created'] );
		$this->assertSame( 'active', $roster->store['1']['status'] );
		$this->assertSame( '2026-06-30', $roster->store['1']['since'] );
	}

	public function test_upsert_refreshes_atomic_fields(): void {
		$roster               = new Fake_Client_Roster();
		$roster->store['1']   = [ 'domain_name' => 'old.com', 'status' => 'active', 'since' => '2026-01-01' ];
		$result               = ( new Client_Importer( $roster ) )->import( $this->rows( [ '1', 'new.com', '2020-01-01' ] ), '2026-06-30' );

		$this->assertSame( 1, $result['updated'] );
		$this->assertSame( 'new.com', $roster->store['1']['domain_name'] );
		$this->assertSame( '2026-01-01', $roster->store['1']['since'] );
	}

	public function test_marks_churned_when_absent_from_csv(): void {
		$roster             = new Fake_Client_Roster();
		$roster->store['9'] = [ 'domain_name' => 'gone.com', 'status' => 'active', 'since' => '2025-01-01' ];
		$result             = ( new Client_Importer( $roster ) )->import( $this->rows( [ '1', 'a.com', '2020-01-01' ] ), '2026-06-30' );

		$this->assertSame( 1, $result['churned'] );
		$this->assertSame( 'churned', $roster->store['9']['status'] );
		$this->assertSame( '2026-06-30', $roster->store['9']['since'] );
	}

	public function test_reactivates_returning_publisher(): void {
		$roster             = new Fake_Client_Roster();
		$roster->store['9'] = [ 'domain_name' => 'gone.com', 'status' => 'churned', 'since' => '2025-12-01' ];
		$result             = ( new Client_Importer( $roster ) )->import( $this->rows( [ '9', 'gone.com', '2019-01-01' ] ), '2026-06-30' );

		$this->assertSame( 1, $result['reactivated'] );
		$this->assertSame( 0, $result['updated'] ); // Disjoint from `updated`: reactivation is not also counted as an update.
		$this->assertSame( 'active', $roster->store['9']['status'] );
		$this->assertSame( '2026-06-30', $roster->store['9']['since'] );
	}

	/** The churn pass reads every held id's status at once, and finds only the rows the snapshot names. */
	public function test_the_churn_pass_reads_statuses_not_one_find_per_id(): void {
		$roster             = new Fake_Client_Roster();
		$roster->store['3'] = [ 'domain_name' => 'kept.example', 'status' => 'active', 'since' => '2025-01-01' ];
		$roster->store['8'] = [ 'domain_name' => 'gone.example', 'status' => 'active', 'since' => '2025-01-01' ];
		$roster->store['9'] = [ 'domain_name' => 'left.example', 'status' => 'churned', 'since' => '2025-01-01' ];

		$result = ( new Client_Importer( $roster ) )->import( $this->rows( [ '3', 'kept.example', '2020-01-01' ] ), '2026-06-30' );

		$this->assertSame( [ '3' ], $roster->finds );
		$this->assertSame( 1, $result['churned'] );
		$this->assertSame( [ 'active', 'churned', 'churned' ], \array_column( $roster->store, 'status' ) );
	}

	/** Each import commits its writes once, after the churn pass. */
	public function test_idempotent_second_run_marks_nothing_churned(): void {
		$roster   = new Fake_Client_Roster();
		$importer = new Client_Importer( $roster );
		$rows     = $this->rows( [ '1', 'a.com', '2020-01-01' ] );
		$importer->import( $rows, '2026-06-30' );
		$result = $importer->import( $rows, '2026-07-01' );
		$this->assertSame( 0, $result['churned'] );
		$this->assertSame( 0, $result['created'] );
		$this->assertSame( 2, $roster->commits );
	}
}

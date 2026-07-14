<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Client_Importer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';

final class ClientImporterTest extends TestCase {

	private function rows( array ...$triples ): array {
		return \array_map(
			static fn ( array $t ): array => [ 'atomic_site_id' => $t[0], 'domain_name' => $t[1], 'created' => $t[2] ],
			$triples
		);
	}

	public function test_creates_new_publishers(): void {
		$repo   = new Fake_Publisher_Repository();
		$result = ( new Client_Importer( $repo ) )->import(
			$this->rows( [ '1', 'a.com', '2020-01-01' ], [ '2', 'b.com', '2021-01-01' ] ),
			'2026-06-30'
		);
		$this->assertSame( 2, $result['created'] );
		$this->assertSame( 'active', $repo->store['1']['status'] );
		$this->assertSame( '2026-06-30', $repo->store['1']['first_seen'] );
	}

	public function test_upsert_preserves_enrichment_and_refreshes_atomic_fields(): void {
		$repo                = new Fake_Publisher_Repository();
		$repo->store['1']    = [
			'atomic_site_id' => '1', 'domain_name' => 'old.com', 'created' => '2020-01-01',
			'status' => 'active', 'first_seen' => '2026-01-01', 'last_seen' => '2026-01-01', 'churned_at' => '',
			'publisher_name' => 'Acme News', // enrichment
		];
		( new Client_Importer( $repo ) )->import( $this->rows( [ '1', 'new.com', '2020-01-01' ] ), '2026-06-30' );

		$this->assertSame( 'new.com', $repo->store['1']['domain_name'] );    // refreshed
		$this->assertSame( '2026-06-30', $repo->store['1']['last_seen'] );   // refreshed
		$this->assertSame( 'Acme News', $repo->store['1']['publisher_name'] ); // preserved
	}

	public function test_marks_churned_when_absent_from_csv(): void {
		$repo             = new Fake_Publisher_Repository();
		$repo->store['9'] = [ 'atomic_site_id' => '9', 'domain_name' => 'gone.com', 'created' => '2019-01-01', 'status' => 'active', 'first_seen' => '2025-01-01', 'last_seen' => '2025-01-01', 'churned_at' => '' ];
		$result           = ( new Client_Importer( $repo ) )->import( $this->rows( [ '1', 'a.com', '2020-01-01' ] ), '2026-06-30' );

		$this->assertSame( 1, $result['churned'] );
		$this->assertSame( 'churned', $repo->store['9']['status'] );
		$this->assertSame( '2026-06-30', $repo->store['9']['churned_at'] );
	}

	public function test_reactivates_returning_publisher(): void {
		$repo             = new Fake_Publisher_Repository();
		$repo->store['9'] = [ 'atomic_site_id' => '9', 'domain_name' => 'gone.com', 'created' => '2019-01-01', 'status' => 'churned', 'first_seen' => '2025-01-01', 'last_seen' => '2025-06-01', 'churned_at' => '2025-12-01' ];
		$result           = ( new Client_Importer( $repo ) )->import( $this->rows( [ '9', 'gone.com', '2019-01-01' ] ), '2026-06-30' );

		$this->assertSame( 1, $result['reactivated'] );
		$this->assertSame( 0, $result['updated'] ); // Disjoint from `updated`: reactivation is not also counted as an update.
		$this->assertSame( 'active', $repo->store['9']['status'] );
		$this->assertSame( '', $repo->store['9']['churned_at'] );
	}

	public function test_idempotent_second_run_marks_nothing_churned(): void {
		$repo     = new Fake_Publisher_Repository();
		$importer = new Client_Importer( $repo );
		$rows     = $this->rows( [ '1', 'a.com', '2020-01-01' ] );
		$importer->import( $rows, '2026-06-30' );
		$result = $importer->import( $rows, '2026-07-01' );
		$this->assertSame( 0, $result['churned'] );
		$this->assertSame( 0, $result['created'] );
	}
}

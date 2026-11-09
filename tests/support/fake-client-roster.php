<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Roster;

/** In-memory Client_Roster for testing the pure reconciliation. */
final class Fake_Client_Roster implements Client_Roster {
	/** @var array<string,array<string,string>> */
	public array $store = [];

	/** How many times the importer committed. */
	public int $commits = 0;

	/** @var list<string> Each site id the importer found, in order. */
	public array $finds = [];

	public function find_by_site_id( string $site_id ): ?array {
		$this->finds[] = $site_id;
		return isset( $this->store[ $site_id ] ) ? [ 'status' => $this->store[ $site_id ]['status'] ] : null;
	}
	public function statuses(): array {
		return \array_map( static fn ( array $client ): string => $client['status'], $this->store );
	}
	public function create( array $row, string $today ): void {
		$this->store[ $row['site_id'] ] = [
			'domain_name' => $row['domain_name'],
			'status'      => 'active',
			'since'       => $today,
		];
	}
	public function update_site_fields( string $site_id, array $row, string $today ): void {
		$this->store[ $site_id ]['domain_name'] = $row['domain_name'];
	}
	public function set_active( string $site_id, string $today ): void {
		$this->store[ $site_id ]['status'] = 'active';
		$this->store[ $site_id ]['since']  = $today;
	}
	public function mark_churned( string $site_id, string $today ): void {
		$this->store[ $site_id ]['status'] = 'churned';
		$this->store[ $site_id ]['since']  = $today;
	}
	public function commit(): void {
		++$this->commits;
	}
}

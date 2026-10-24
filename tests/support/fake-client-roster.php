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

	/** @var list<string> Each atomic id the importer found, in order. */
	public array $finds = [];

	public function find_by_atomic_id( string $atomic_id ): ?array {
		$this->finds[] = $atomic_id;
		return isset( $this->store[ $atomic_id ] ) ? [ 'status' => $this->store[ $atomic_id ]['status'] ] : null;
	}
	public function statuses(): array {
		return \array_map( static fn ( array $client ): string => $client['status'], $this->store );
	}
	public function create( array $row, string $today ): void {
		$this->store[ $row['atomic_site_id'] ] = [
			'domain_name' => $row['domain_name'],
			'status'      => 'active',
			'since'       => $today,
		];
	}
	public function update_atomic_fields( string $atomic_id, array $row, string $today ): void {
		$this->store[ $atomic_id ]['domain_name'] = $row['domain_name'];
	}
	public function set_active( string $atomic_id, string $today ): void {
		$this->store[ $atomic_id ]['status'] = 'active';
		$this->store[ $atomic_id ]['since']  = $today;
	}
	public function mark_churned( string $atomic_id, string $today ): void {
		$this->store[ $atomic_id ]['status'] = 'churned';
		$this->store[ $atomic_id ]['since']  = $today;
	}
	public function commit(): void {
		++$this->commits;
	}
}

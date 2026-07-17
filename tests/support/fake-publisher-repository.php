<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Publisher_Repository;

/** In-memory Publisher_Repository for testing the pure reconciliation. */
final class Fake_Publisher_Repository implements Publisher_Repository {
	/** @var array<string,array<string,mixed>> */
	public array $store = [];

	public function find_by_atomic_id( string $atomic_id ): ?array {
		return $this->store[ $atomic_id ] ?? null;
	}
	public function all_atomic_ids(): array {
		return \array_keys( $this->store );
	}
	public function all_with_enrichment(): array {
		$out = [];
		foreach ( $this->store as $id => $rec ) {
			$out[] = [
				'atomic_site_id' => (string) $id,
				'domain_name'    => (string) ( $rec['domain_name'] ?? '' ),
				'status'         => (string) ( $rec['status'] ?? '' ),
				'publisher_name' => (string) ( $rec['publisher_name'] ?? '' ),
				'aliases'        => (string) ( $rec['aliases'] ?? '' ),
			];
		}
		return $out;
	}
	public function create( array $atomic_fields, string $today ): void {
		$id                 = $atomic_fields['atomic_site_id'];
		$this->store[ $id ] = $atomic_fields + [
			'status'     => 'active',
			'first_seen' => $today,
			'last_seen'  => $today,
			'churned_at' => '',
		];
	}
	public function update_atomic_fields( string $atomic_id, array $atomic_fields, string $today ): void {
		$this->store[ $atomic_id ]['domain_name'] = $atomic_fields['domain_name'];
		$this->store[ $atomic_id ]['created']     = $atomic_fields['created'];
		$this->store[ $atomic_id ]['last_seen']   = $today;
	}
	public function set_active( string $atomic_id ): void {
		$this->store[ $atomic_id ]['status']     = 'active';
		$this->store[ $atomic_id ]['churned_at'] = '';
	}
	public function mark_churned( string $atomic_id, string $today ): void {
		$this->store[ $atomic_id ]['status']     = 'churned';
		$this->store[ $atomic_id ]['churned_at'] = $today;
	}
}

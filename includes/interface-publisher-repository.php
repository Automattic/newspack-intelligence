<?php
/**
 * Publisher_Repository: storage contract for publisher master records.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

interface Publisher_Repository {
	/**
	 * @param string $atomic_id
	 * @return array<string,mixed>|null Record incl. at least `status`, or null if absent.
	 */
	public function find_by_atomic_id( string $atomic_id ): ?array;

	/** @return array<int,string> Every stored atomic_site_id. */
	public function all_atomic_ids(): array;

	/** @param array{atomic_site_id:string,domain_name:string,created:string} $atomic_fields */
	public function create( array $atomic_fields, string $today ): void;

	/** @param array{atomic_site_id:string,domain_name:string,created:string} $atomic_fields */
	public function update_atomic_fields( string $atomic_id, array $atomic_fields, string $today ): void;

	public function set_active( string $atomic_id ): void;

	public function mark_churned( string $atomic_id, string $today ): void;
}

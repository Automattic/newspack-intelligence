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
	 * Look up the stored record for an atomic id.
	 *
	 * @param string $atomic_id
	 * @return array<string,mixed>|null Record incl. at least `status`, or null if absent.
	 */
	public function find_by_atomic_id( string $atomic_id ): ?array;

	/**
	 * List every atomic_site_id currently stored, regardless of status.
	 *
	 * @return array<int,string> Every stored atomic_site_id.
	 */
	public function all_atomic_ids(): array;

	/**
	 * Every stored publisher with the fields the intake Gate matches on.
	 *
	 * @return array<int,array{atomic_site_id:string,domain_name:string,status:string,publisher_name:string,aliases:string}>
	 */
	public function all_with_enrichment(): array;

	/**
	 * New record: status active, first_seen=last_seen=today, churned_at empty.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $atomic_fields
	 */
	public function create( array $atomic_fields, string $today ): void;

	/**
	 * Refresh the CSV-sourced fields + last_seen on an existing record; does not touch status/enrichment.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $atomic_fields
	 */
	public function update_atomic_fields( string $atomic_id, array $atomic_fields, string $today ): void;

	/** Set status active AND clear churned_at. */
	public function set_active( string $atomic_id ): void;

	/** Set status churned AND stamp churned_at with the given date. */
	public function mark_churned( string $atomic_id, string $today ): void;
}

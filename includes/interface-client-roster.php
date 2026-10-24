<?php
/**
 * Client_Roster: who is a Newspack client, as the client import reconciles it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

interface Client_Roster {
	/**
	 * The roster's record of an atomic id.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @return array{status:string}|null `active`, `churned`, or `refused` for a client whose entity is rejected or unresolvably merged, which the import counts as present and the roster leaves alone; null when the roster lacks it.
	 */
	public function find_by_atomic_id( string $atomic_id ): ?array;

	/**
	 * Every atomic id the roster holds, with its status as
	 * `find_by_atomic_id()` gives it.
	 *
	 * @return array<string,string> Atomic id => `active`, `churned` or `refused`.
	 */
	public function statuses(): array;

	/**
	 * A new client, active from today.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row   The CSV row.
	 * @param string                                                         $today YYYY-MM-DD, UTC.
	 */
	public function create( array $row, string $today ): void;

	/**
	 * Refresh a held client's CSV fields; its status is left alone.
	 *
	 * @param string                                                         $atomic_id The atomic site id.
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row       The CSV row.
	 * @param string                                                         $today     YYYY-MM-DD, UTC.
	 */
	public function update_atomic_fields( string $atomic_id, array $row, string $today ): void;

	/**
	 * A churned client returns, active from today.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function set_active( string $atomic_id, string $today ): void;

	/**
	 * A client churns, a former client from today.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function mark_churned( string $atomic_id, string $today ): void;

	/** Record the import's changes, once it has reconciled every row. */
	public function commit(): void;
}

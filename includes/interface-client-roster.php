<?php
/**
 * Client_Roster: who is a client, as the client import reconciles it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

interface Client_Roster {
	/**
	 * The roster's record of an site id.
	 *
	 * @param string $site_id The site id.
	 * @return array{status:string}|null `active`, `churned`, `held` for a client a curator ended, which the import leaves alone and counts apart, or `refused` for a client whose entity is rejected or unresolvably merged, which the import counts as present and the roster leaves alone; null when the roster lacks it.
	 */
	public function find_by_site_id( string $site_id ): ?array;

	/**
	 * Every site id the roster holds, with its status as
	 * `find_by_site_id()` gives it.
	 *
	 * @return array<string,string> Site id => `active`, `churned`, `held` or `refused`.
	 */
	public function statuses(): array;

	/**
	 * A new client, active from the day its site was Created.
	 *
	 * @param array{site_id:string,domain_name:string,created:string} $row   The CSV row.
	 * @param string                                                         $today YYYY-MM-DD, UTC.
	 */
	public function create( array $row, string $today ): void;

	/**
	 * Refresh a held client's CSV fields, its Created day among them; its
	 * status is left alone.
	 *
	 * @param string                                                         $site_id The site id.
	 * @param array{site_id:string,domain_name:string,created:string} $row       The CSV row.
	 * @param string                                                         $today     YYYY-MM-DD, UTC.
	 */
	public function update_site_fields( string $site_id, array $row, string $today ): void;

	/**
	 * A churned client returns, active from today.
	 *
	 * @param string $site_id The site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function set_active( string $site_id, string $today ): void;

	/**
	 * A client churns, a former client from today.
	 *
	 * @param string $site_id The site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function mark_churned( string $site_id, string $today ): void;

	/** Record the import's changes, once it has reconciled every row. */
	public function commit(): void;
}

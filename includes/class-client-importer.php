<?php
/**
 * Client_Importer: reconcile a parsed clients CSV against the client roster.
 *
 * Pure logic: depends only on a Client_Roster. Upserts by atomic_site_id,
 * marks churn for held ids absent from the CSV, reactivates returning
 * clients, then commits the roster's changes once.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Client_Importer {

	public function __construct( private Client_Roster $roster ) {}

	/**
	 * @param array<int,array{atomic_site_id:string,domain_name:string,created:string}> $rows
	 * @param string $today YYYY-MM-DD.
	 * @return array{created:int,updated:int,reactivated:int,churned:int,total_in_csv:int} Counts are
	 *         disjoint: an existing row that was churned is counted only in `reactivated`, never
	 *         also in `updated`.
	 */
	public function import( array $rows, string $today ): array {
		$created     = 0;
		$updated     = 0;
		$reactivated = 0;
		$churned     = 0;
		$csv_ids     = [];

		// Empty snapshot: the churn loop below would mark EVERY client churned.
		if ( [] === $rows ) {
			return [ 'created' => 0, 'updated' => 0, 'reactivated' => 0, 'churned' => 0, 'total_in_csv' => 0 ];
		}

		foreach ( $rows as $row ) {
			$id             = $row['atomic_site_id'];
			$csv_ids[ $id ] = true;
			$existing       = $this->roster->find_by_atomic_id( $id );
			if ( null === $existing ) {
				$this->roster->create( $row, $today );
				++$created;
				continue;
			}
			$this->roster->update_atomic_fields( $id, $row, $today );
			if ( 'churned' === $existing['status'] ) {
				$this->roster->set_active( $id, $today );
				++$reactivated;
			} else {
				++$updated;
			}
		}

		foreach ( $this->roster->statuses() as $id => $status ) {
			if ( 'active' === $status && ! isset( $csv_ids[ $id ] ) ) {
				$this->roster->mark_churned( $id, $today );
				++$churned;
			}
		}

		$this->roster->commit();

		return [
			'created'      => $created,
			'updated'      => $updated,
			'reactivated'  => $reactivated,
			'churned'      => $churned,
			'total_in_csv' => \count( $csv_ids ),
		];
	}
}

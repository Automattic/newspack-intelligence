<?php
/**
 * Client_Importer: reconcile a parsed clients CSV against the publisher store.
 *
 * Pure logic: depends only on a Publisher_Repository. Upserts by atomic_site_id
 * (never touches enrichment), marks churn for stored ids absent from the CSV,
 * and reactivates returning publishers.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

final class Client_Importer {

	public function __construct( private Publisher_Repository $repo ) {}

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

		foreach ( $rows as $row ) {
			$id             = $row['atomic_site_id'];
			$csv_ids[ $id ] = true;
			$existing       = $this->repo->find_by_atomic_id( $id );
			if ( null === $existing ) {
				$this->repo->create( $row, $today );
				++$created;
				continue;
			}
			$this->repo->update_atomic_fields( $id, $row, $today );
			if ( 'churned' === ( $existing['status'] ?? '' ) ) {
				$this->repo->set_active( $id );
				++$reactivated;
			} else {
				++$updated;
			}
		}

		foreach ( $this->repo->all_atomic_ids() as $id ) {
			if ( isset( $csv_ids[ $id ] ) ) {
				continue;
			}
			$existing = $this->repo->find_by_atomic_id( $id );
			if ( 'churned' !== ( $existing['status'] ?? '' ) ) {
				$this->repo->mark_churned( $id, $today );
				++$churned;
			}
		}

		return [
			'created'      => $created,
			'updated'      => $updated,
			'reactivated'  => $reactivated,
			'churned'      => $churned,
			'total_in_csv' => \count( $csv_ids ),
		];
	}
}

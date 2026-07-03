<?php
/**
 * CPT_Publisher_Repository: Publisher_Repository over the newspack_publisher CPT.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

final class CPT_Publisher_Repository implements Publisher_Repository {

	/** Locate the post id for an atomic id, or null. */
	private function post_id( string $atomic_id ): ?int {
		$ids = \get_posts(
			[
				'post_type'        => Publisher_CPT::POST_TYPE,
				'post_status'      => 'any',
				'meta_key'         => Publisher_CPT::META_ATOMIC_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- exact-match lookup keyed on the unique atomic_site_id meta; there is no faster path for this schema.
				'meta_value'       => $atomic_id,
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				// TODO(Gate): drop suppress_filters / add object-cache-backed lookup once the intake Gate queries this store per item.
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- internal admin-only lookup on a non-public CPT, not a front-end VIP request.
				'suppress_filters' => true,
			]
		);
		return empty( $ids ) ? null : $ids[0];
	}

	public function find_by_atomic_id( string $atomic_id ): ?array {
		$post_id = $this->post_id( $atomic_id );
		if ( null === $post_id ) {
			return null;
		}
		return [
			'atomic_site_id' => \get_post_meta( $post_id, Publisher_CPT::META_ATOMIC_ID, true ),
			'domain_name'    => \get_post_meta( $post_id, Publisher_CPT::META_DOMAIN, true ),
			'created'        => \get_post_meta( $post_id, Publisher_CPT::META_CREATED, true ),
			'status'         => \get_post_meta( $post_id, Publisher_CPT::META_STATUS, true ),
			'first_seen'     => \get_post_meta( $post_id, Publisher_CPT::META_FIRST_SEEN, true ),
			'last_seen'      => \get_post_meta( $post_id, Publisher_CPT::META_LAST_SEEN, true ),
			'churned_at'     => \get_post_meta( $post_id, Publisher_CPT::META_CHURNED_AT, true ),
		];
	}

	public function all_atomic_ids(): array {
		$ids = \get_posts(
			[
				'post_type'        => Publisher_CPT::POST_TYPE,
				'post_status'      => 'any',
				'fields'           => 'ids',
				'posts_per_page'   => -1,
				// TODO(Gate): drop suppress_filters / add object-cache-backed lookup once the intake Gate queries this store per item.
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- internal admin-only lookup on a non-public CPT, not a front-end VIP request.
				'suppress_filters' => true,
			]
		);
		$out = [];
		foreach ( $ids as $post_id ) {
			$atomic = \get_post_meta( $post_id, Publisher_CPT::META_ATOMIC_ID, true );
			if ( \is_string( $atomic ) && '' !== $atomic ) {
				$out[] = $atomic;
			}
		}
		return $out;
	}

	public function create( array $atomic_fields, string $today ): void {
		$post_id = \wp_insert_post(
			[
				'post_type'   => Publisher_CPT::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $atomic_fields['domain_name'],
			]
		);
		if ( $post_id <= 0 ) {
			return;
		}
		\update_post_meta( $post_id, Publisher_CPT::META_ATOMIC_ID, $atomic_fields['atomic_site_id'] );
		\update_post_meta( $post_id, Publisher_CPT::META_DOMAIN, $atomic_fields['domain_name'] );
		\update_post_meta( $post_id, Publisher_CPT::META_CREATED, $atomic_fields['created'] );
		\update_post_meta( $post_id, Publisher_CPT::META_STATUS, 'active' );
		\update_post_meta( $post_id, Publisher_CPT::META_FIRST_SEEN, $today );
		\update_post_meta( $post_id, Publisher_CPT::META_LAST_SEEN, $today );
		\update_post_meta( $post_id, Publisher_CPT::META_CHURNED_AT, '' );
	}

	public function update_atomic_fields( string $atomic_id, array $atomic_fields, string $today ): void {
		$post_id = $this->post_id( $atomic_id );
		if ( null === $post_id ) {
			return;
		}
		$previous_domain = \get_post_meta( $post_id, Publisher_CPT::META_DOMAIN, true );
		\update_post_meta( $post_id, Publisher_CPT::META_DOMAIN, $atomic_fields['domain_name'] );
		\update_post_meta( $post_id, Publisher_CPT::META_CREATED, $atomic_fields['created'] );
		\update_post_meta( $post_id, Publisher_CPT::META_LAST_SEEN, $today );

		if ( $previous_domain !== $atomic_fields['domain_name'] ) {
			\wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $atomic_fields['domain_name'],
				]
			);
		}
	}

	public function set_active( string $atomic_id ): void {
		$post_id = $this->post_id( $atomic_id );
		if ( null === $post_id ) {
			return;
		}
		\update_post_meta( $post_id, Publisher_CPT::META_STATUS, 'active' );
		\update_post_meta( $post_id, Publisher_CPT::META_CHURNED_AT, '' );
	}

	public function mark_churned( string $atomic_id, string $today ): void {
		$post_id = $this->post_id( $atomic_id );
		if ( null === $post_id ) {
			return;
		}
		\update_post_meta( $post_id, Publisher_CPT::META_STATUS, 'churned' );
		\update_post_meta( $post_id, Publisher_CPT::META_CHURNED_AT, $today );
	}
}

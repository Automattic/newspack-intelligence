<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\CPT_Publisher_Repository;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/wp-post-stubs.php';

final class CptPublisherRepositoryTest extends TestCase {

	protected function setUp(): void {
		\NPAINL_WP_Post_Store::reset();
	}

	public function test_create_then_find_roundtrips_fields(): void {
		$repo = new CPT_Publisher_Repository();
		$repo->create( [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-06-30' );

		$rec = $repo->find_by_atomic_id( '1' );
		$this->assertNotNull( $rec );
		$this->assertSame( 'a.com', $rec['domain_name'] );
		$this->assertSame( 'active', $rec['status'] );
		$this->assertSame( '2026-06-30', $rec['first_seen'] );
	}

	public function test_all_atomic_ids_lists_created(): void {
		$repo = new CPT_Publisher_Repository();
		$repo->create( [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-06-30' );
		$repo->create( [ 'atomic_site_id' => '2', 'domain_name' => 'b.com', 'created' => '2021-01-01' ], '2026-06-30' );
		$ids = $repo->all_atomic_ids();
		\sort( $ids );
		$this->assertSame( [ '1', '2' ], $ids );
	}

	public function test_mark_churned_sets_status_and_date(): void {
		$repo = new CPT_Publisher_Repository();
		$repo->create( [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-06-30' );
		$repo->mark_churned( '1', '2026-07-15' );
		$rec = $repo->find_by_atomic_id( '1' );
		$this->assertSame( 'churned', $rec['status'] );
		$this->assertSame( '2026-07-15', $rec['churned_at'] );
	}

	public function test_find_returns_null_when_absent(): void {
		$this->assertNull( ( new CPT_Publisher_Repository() )->find_by_atomic_id( 'nope' ) );
	}

	public function test_update_atomic_fields_syncs_post_title_when_domain_changes(): void {
		$repo = new CPT_Publisher_Repository();
		$repo->create( [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-06-30' );

		$repo->update_atomic_fields( '1', [ 'atomic_site_id' => '1', 'domain_name' => 'b.com', 'created' => '2020-01-01' ], '2026-07-01' );

		$post_id = null;
		foreach ( \NPAINL_WP_Post_Store::$posts as $id => $post ) {
			$post_id = $id;
		}
		$this->assertNotNull( $post_id );
		$this->assertSame( 'b.com', \NPAINL_WP_Post_Store::$posts[ $post_id ]['post_title'] );
		$this->assertSame( 1, \NPAINL_WP_Post_Store::$update_calls );
	}

	public function test_update_atomic_fields_skips_post_title_write_when_domain_unchanged(): void {
		$repo = new CPT_Publisher_Repository();
		$repo->create( [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-06-30' );

		$repo->update_atomic_fields( '1', [ 'atomic_site_id' => '1', 'domain_name' => 'a.com', 'created' => '2020-01-01' ], '2026-07-01' );

		$this->assertSame( 0, \NPAINL_WP_Post_Store::$update_calls );
	}
}

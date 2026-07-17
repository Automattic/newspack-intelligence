<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Publisher_CPT;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/wp-post-stubs.php';

final class PublisherCptTest extends TestCase {

	public function test_registers_admin_only_cpt(): void {
		\NPAINL_WP_Post_Store::reset();
		Publisher_CPT::register();

		$reg = \NPAINL_WP_Post_Store::$last_cpt;
		$this->assertNotNull( $reg );
		$this->assertSame( 'newspack_publisher', $reg['type'] );
		$this->assertFalse( $reg['args']['public'] );
		$this->assertTrue( $reg['args']['show_ui'] );
	}

	public function test_registers_manage_options_capabilities(): void {
		\NPAINL_WP_Post_Store::reset();
		Publisher_CPT::register();

		$reg = \NPAINL_WP_Post_Store::$last_cpt;
		$this->assertNotNull( $reg );
		$this->assertFalse( $reg['args']['map_meta_cap'] );
		$this->assertSame( 'manage_options', $reg['args']['capabilities']['edit_posts'] );
		$this->assertSame( 'manage_options', $reg['args']['capabilities']['create_posts'] );
	}

	public function test_admin_capability_is_not_registered_as_a_post_meta_capability(): void {
		\NPAINL_WP_Post_Store::reset();
		Publisher_CPT::register();

		$reg = \NPAINL_WP_Post_Store::$last_cpt;
		$this->assertNotNull( $reg );

		$meta_cap_map = [ 'existing-custom-capability-83' => 'existing-core-capability-47' ];
		if ( $reg['args']['map_meta_cap'] ) {
			foreach ( [ 'edit_post', 'read_post', 'delete_post' ] as $core_capability ) {
				$custom_capability                  = $reg['args']['capabilities'][ $core_capability ];
				$meta_cap_map[ $custom_capability ] = $core_capability;
			}
		}

		$this->assertSame(
			[ 'existing-custom-capability-83' => 'existing-core-capability-47' ],
			$meta_cap_map
		);
		$this->assertArrayNotHasKey( 'manage_options', $meta_cap_map );
	}

	public function test_post_type_constant(): void {
		$this->assertSame( 'newspack_publisher', Publisher_CPT::POST_TYPE );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Publisher_CPT;
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

	public function test_post_type_constant(): void {
		$this->assertSame( 'newspack_publisher', Publisher_CPT::POST_TYPE );
	}
}

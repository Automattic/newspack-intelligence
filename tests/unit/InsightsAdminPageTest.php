<?php
/**
 * InsightsAdminPageTest: the Publisher Insights page's server-side markup.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Tests\TestCase;
use function Newspack_Intelligence\register_insights_admin_page;
use const Newspack_Intelligence\INSIGHTS_MOUNT_ID;

final class InsightsAdminPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can'] = true;
		$GLOBALS['_admin_menu_pages'] = [];
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_current_user_can'], $GLOBALS['_admin_menu_pages'] );
		parent::tearDown();
	}

	public function test_the_page_anchors_notices_above_the_app(): void {
		// Without `.wp-header-end`, WordPress moves notices after the first
		// `.wrap h1` or `h2`, which on this page the React tree renders.
		register_insights_admin_page();
		$callback = $GLOBALS['_admin_menu_pages'][0][4];

		\ob_start();
		$callback();
		$html = (string) \ob_get_clean();

		$this->assertStringStartsWith( '<div class="wrap"><hr class="wp-header-end"><div id="' . INSIGHTS_MOUNT_ID . '"', $html );
	}
}

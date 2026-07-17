<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Tests\TestCase;
use const Newspack_Intelligence\INSIGHTS_MENU_SLUG;

/**
 * The Publisher Insights page mounts the substrate debug overlay, so the plugin
 * declares it on the substrate's `newspack_nodes/devtools_overlay_pages` registry
 * — that's how ELN's "Request" overlay tab appears on the Insights page too.
 */
final class OverlayPageTest extends TestCase {

	public function test_insights_page_is_registered_as_a_devtools_overlay_page(): void {
		require_once \dirname( __DIR__, 2 ) . '/newspack-intelligence.php';

		$pages = \apply_filters( 'newspack_nodes/devtools_overlay_pages', [] );

		$this->assertContains( INSIGHTS_MENU_SLUG, $pages );
	}
}

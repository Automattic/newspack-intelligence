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
use const Newspack_Intelligence\INSIGHTS_MENU_SLUG;
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

	/**
	 * The admin pages render classes Composer loads only past the substrate
	 * handshake, so the deferred loader registers them, never plugin-file scope.
	 */
	public function test_the_handshake_gated_loader_registers_the_admin_pages(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( 'Newspack_Intelligence\\register_clients_admin_page', $GLOBALS['_wp_actions']['admin_menu'] ?? [] );

		$loader();

		$this->assertContains( 'Newspack_Intelligence\\register_insights_admin_page', $GLOBALS['_wp_actions']['admin_menu'] ?? [] );
		$this->assertContains( 'Newspack_Intelligence\\register_clients_admin_page', $GLOBALS['_wp_actions']['admin_menu'] ?? [] );
		$this->assertContains( 'Newspack_Intelligence\\enqueue_insights_assets', $GLOBALS['_wp_actions']['admin_enqueue_scripts'] ?? [] );
	}

	/** The Insights page joins the substrate's overlay registry only past the handshake. */
	public function test_the_loader_declares_the_insights_page_an_overlay_page(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( INSIGHTS_MENU_SLUG, \apply_filters( 'newspack_nodes/overlay_pages', [] ) );

		$loader();

		$this->assertContains( INSIGHTS_MENU_SLUG, \apply_filters( 'newspack_nodes/overlay_pages', [] ) );
	}

	/** The `plugins_loaded` callback newspack-intelligence.php registers. */
	private function plugin_loader(): \Closure {
		$file = \dirname( __DIR__, 2 ) . '/newspack-intelligence.php';
		foreach ( $GLOBALS['_wp_actions']['plugins_loaded'] ?? [] as $callback ) {
			if ( $callback instanceof \Closure && ( new \ReflectionFunction( $callback ) )->getFileName() === $file ) {
				return $callback;
			}
		}
		$this->fail( 'newspack-intelligence.php registers no plugins_loaded loader' );
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

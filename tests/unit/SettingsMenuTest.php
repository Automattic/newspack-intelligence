<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_Nodes\Tests\TestCase;
use function Newspack_AI_Newsletter\register_settings_admin_page;
use const Newspack_AI_Newsletter\INSIGHTS_MENU_SLUG;

/**
 * The AI Newsletter settings page lives under the core WordPress "Settings"
 * menu (options-general.php), not nested under the Publisher Insights dashboard.
 */
final class SettingsMenuTest extends TestCase {

	public function test_settings_page_is_registered_under_the_wp_settings_menu(): void {
		require_once \dirname( __DIR__, 2 ) . '/newspack-ai-newsletter.php';

		$GLOBALS['_current_user_can']    = true;
		$GLOBALS['_admin_submenu_pages'] = [];

		register_settings_admin_page();

		$parents = \array_map( static fn ( array $a ): string => (string) $a[0], $GLOBALS['_admin_submenu_pages'] );
		$this->assertContains( 'options-general.php', $parents );
		$this->assertNotContains( INSIGHTS_MENU_SLUG, $parents );
	}
}

<?php
/**
 * EnqueueInsightsAssetsTest: Publisher Insights dashboard asset ownership.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Tests\TestCase;
use function Newspack_Intelligence\enqueue_insights_assets;
use const Newspack_Intelligence\INSIGHTS_MENU_SLUG;
use const Newspack_Intelligence\SETTINGS_MENU_SLUG;

final class EnqueueInsightsAssetsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can']    = true;
		$GLOBALS['_enqueued_scripts']    = [];
		$GLOBALS['_enqueued_styles']     = [];
		$GLOBALS['_localized_scripts']   = [];
		$GLOBALS['_style_data']          = [];
		$_GET                            = [];
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_current_user_can'],
			$GLOBALS['_enqueued_scripts'],
			$GLOBALS['_enqueued_styles'],
			$GLOBALS['_localized_scripts'],
			$GLOBALS['_style_data'],
			$_GET
		);
		parent::tearDown();
	}

	public function test_insights_dashboard_depends_on_the_shared_graph_asset(): void {
		$_GET = [ 'page' => INSIGHTS_MENU_SLUG ];

		enqueue_insights_assets();

		$this->assertSame(
			[ 'wp-components', 'newspack-nodes-graph' ],
			$GLOBALS['_enqueued_styles']['newspack-intelligence-insights']['deps']
		);
	}

	public function test_settings_page_does_not_enqueue_the_insights_dashboard_or_graph_asset(): void {
		$_GET = [ 'page' => SETTINGS_MENU_SLUG ];

		enqueue_insights_assets();

		$this->assertSame( [], $GLOBALS['_enqueued_scripts'] );
		$this->assertSame( [], $GLOBALS['_enqueued_styles'] );
	}
}

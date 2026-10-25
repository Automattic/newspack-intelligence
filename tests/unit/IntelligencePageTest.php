<?php
/**
 * IntelligencePageTest: the Intelligence admin page's menu, markup and bundle.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Tests\TestCase;
use function Newspack_Intelligence\enqueue_intelligence_assets;
use function Newspack_Intelligence\register_intelligence_admin_page;
use const Newspack_Intelligence\INTELLIGENCE_MENU_SLUG;
use const Newspack_Intelligence\INTELLIGENCE_MOUNT_ID;

final class IntelligencePageTest extends TestCase {

	/** The hook suffix WordPress gives the Intelligence page. */
	private const PAGE_HOOK = 'toplevel_page_newspack-intelligence';

	/** The capability the READ role maps to under the test's capability map. */
	private const READ_CAP = 'newspack_intel_test_read';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can']  = true;
		$GLOBALS['_admin_menu_pages']  = [];
		$GLOBALS['_enqueued_scripts']  = [];
		$GLOBALS['_enqueued_styles']   = [];
		$GLOBALS['_localized_scripts'] = [];
		$GLOBALS['_style_data']        = [];
		$_GET                          = [];
		\add_filter( 'newspack_nodes/capability_map', static fn ( array $map ): array => [ Capabilities::READ => self::READ_CAP ] + $map );
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_current_user_can'],
			$GLOBALS['_admin_menu_pages'],
			$GLOBALS['_enqueued_scripts'],
			$GLOBALS['_enqueued_styles'],
			$GLOBALS['_localized_scripts'],
			$GLOBALS['_style_data'],
			$_GET
		);
		parent::tearDown();
	}

	public function test_a_reader_gets_the_intelligence_menu_under_the_read_capability(): void {
		Capabilities::$session_scope = Capabilities::READ;

		register_intelligence_admin_page();

		$this->assertCount( 1, $GLOBALS['_admin_menu_pages'] );
		[ $page_title, $menu_title, $capability, $slug ] = $GLOBALS['_admin_menu_pages'][0];
		$this->assertSame( 'Intelligence', $page_title );
		$this->assertSame( 'Intelligence', $menu_title );
		$this->assertSame( self::READ_CAP, $capability );
		$this->assertSame( 'newspack-intelligence', $slug );
		$this->assertSame( INTELLIGENCE_MENU_SLUG, $slug );
	}

	/**
	 * The admin pages render classes Composer loads only past the substrate
	 * handshake, so the deferred loader registers them, never plugin-file
	 * scope.
	 */
	public function test_the_handshake_gated_loader_registers_the_admin_pages(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( 'Newspack_Intelligence\\register_clients_admin_page', $GLOBALS['_wp_actions']['admin_menu'] ?? [] );

		$loader();

		$this->assertSame(
			[ 'Newspack_Intelligence\\register_intelligence_admin_page', 'Newspack_Intelligence\\register_clients_admin_page' ],
			\array_values( \array_filter( $GLOBALS['_wp_actions']['admin_menu'] ?? [], 'is_string' ) )
		);
		$this->assertContains( 'Newspack_Intelligence\\enqueue_intelligence_assets', $GLOBALS['_wp_actions']['admin_enqueue_scripts'] ?? [] );
	}

	/** The Intelligence page joins the substrate's overlay registry only past the handshake. */
	public function test_the_loader_declares_the_intelligence_page_an_overlay_page(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( INTELLIGENCE_MENU_SLUG, \apply_filters( 'newspack_nodes/overlay_pages', [] ) );

		$loader();

		$this->assertSame( [ INTELLIGENCE_MENU_SLUG ], \apply_filters( 'newspack_nodes/overlay_pages', [] ) );
	}

	public function test_a_caller_without_read_gets_no_menu(): void {
		$GLOBALS['_current_user_can'] = false;

		register_intelligence_admin_page();

		$this->assertSame( [], $GLOBALS['_admin_menu_pages'] );
	}

	public function test_the_page_anchors_notices_above_the_app(): void {
		register_intelligence_admin_page();
		$callback = $GLOBALS['_admin_menu_pages'][0][4];

		\ob_start();
		$callback();
		$html = (string) \ob_get_clean();

		$this->assertStringStartsWith( '<div class="wrap"><hr class="wp-header-end"><div id="' . INTELLIGENCE_MOUNT_ID . '"', $html );
	}

	public function test_a_reader_gets_the_bundle_with_curation_hidden(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$data = $this->localized( 'newspack-intelligence' );
		$this->assertFalse( $data['canTune'] );
		$this->assertSame( \plugins_url( 'build/intelligence/index.js', \dirname( __DIR__, 2 ) . '/newspack-intelligence.php' ), $GLOBALS['_enqueued_scripts'][0][1] );
	}

	public function test_a_tuner_gets_curation_and_the_vocabulary(): void {
		Capabilities::$session_scope = Capabilities::TUNE;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$data = $this->localized( 'newspack-intelligence' );
		$this->assertTrue( $data['canTune'] );
		$this->assertSame(
			[
				'roles'           => Intel_Event::ROLES,
				'nameKinds'       => Intel_Event::NAME_KINDS,
				'identifierKinds' => Intel_Event::IDENTIFIER_KINDS,
				'itemKinds'       => Intel_Event::ITEM_KINDS,
			],
			$data['vocabulary']
		);
		$this->assertSame( [ 'wp-components', 'newspack-nodes-graph' ], $GLOBALS['_enqueued_styles']['newspack-intelligence']['deps'] );
	}

	public function test_the_bundle_stamps_this_plugins_version_in_the_header(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertSame( \NEWSPACK_INTELLIGENCE_VERSION, $this->localized( 'newspack-intelligence' )['version'] ?? null );
	}

	public function test_another_page_enqueues_nothing_and_reads_no_capability(): void {
		$reads = 0;
		\add_filter(
			'newspack_nodes/capability_map',
			static function ( array $map ) use ( &$reads ): array {
				++$reads;
				return $map;
			}
		);
		$_GET = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( 'settings_page_newspack-intelligence-settings' );

		$this->assertSame( [], $GLOBALS['_enqueued_scripts'] );
		$this->assertSame( [], $GLOBALS['_enqueued_styles'] );
		$this->assertSame( 0, $reads );
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

	/**
	 * The `NewspackNodesData` payload localized onto a handle.
	 *
	 * @param string $handle The script handle.
	 * @return array<string,mixed>
	 */
	private function localized( string $handle ): array {
		foreach ( $GLOBALS['_localized_scripts'] as [ $named, $object, $data ] ) {
			if ( $handle === $named && 'NewspackNodesData' === $object ) {
				return $data;
			}
		}
		$this->fail( "nothing localized onto {$handle}" );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\Clients_Settings;
use function Newspack_Intelligence\register_clients_admin_page;
use function Newspack_Intelligence\render_clients_page;
use const Newspack_Intelligence\SETTINGS_MENU_SLUG;

require_once __DIR__ . '/../support/fake-client-roster.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/seeds-entity-index.php';

final class ClientsSettingsTest extends TestCase {
	use Reads_Ontology_Log;
	use Seeds_Entity_Index;

	/** The capability the MANAGE role maps to under the test's capability map. */
	private const MANAGE_CAP = 'newspack_intel_test_manage';

	protected function setUp(): void {
		parent::setUp();
		\add_filter( 'newspack_nodes/capability_map', static fn ( array $map ): array => [ Capabilities::MANAGE => self::MANAGE_CAP ] + $map );
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		unset(
			$GLOBALS['_admin_submenu_pages'],
			$GLOBALS['_current_user_id'],
			$GLOBALS['_wp_test_user_logins'],
			$GLOBALS['_current_user_can'],
			$GLOBALS['_check_admin_referer'],
			$GLOBALS['_wp_referer'],
			$GLOBALS['_last_redirect'],
			$_GET,
			$_FILES
		);
		parent::tearDown();
	}

	public function test_import_path_parses_and_imports(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$repo     = new Fake_Client_Roster();
		$settings = new Clients_Settings( new Client_Importer( $repo ) );
		$result   = $settings->import_path( $tmp );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['total_in_csv'] );
		\unlink( $tmp );
	}

	public function test_import_path_returns_zeroes_for_unreadable_file(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Client_Roster() ) );
		$result   = $settings->import_path( '/no/such/file.csv' );
		$this->assertSame( 0, $result['total_in_csv'] );
	}

	/** An upload imports into the ontology as the signed-in person. */
	public function test_default_constructor_imports_as_the_signed_in_user(): void {
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
		$GLOBALS['_current_user_id']          = 37;
		$GLOBALS['_wp_test_user_logins'][37]  = 'tidewater-editor';
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"64\",\"2022-02-02\",\"tidewater.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$result = ( new Clients_Settings() )->import_path( $tmp );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( [ 'user:tidewater-editor' ], \array_values( \array_unique( \array_column( self::ontology_events(), 'actor' ) ) ) );
		\unlink( $tmp );
	}

	/** An unreadable file imports nothing, so it needs no signed-in person. */
	public function test_default_constructor_reports_zeroes_for_unreadable_file(): void {
		$result = ( new Clients_Settings() )->import_path( '/no/such/file.csv' );
		$this->assertSame( 0, $result['total_in_csv'] );
	}

	public function test_handle_admin_post_dies_when_not_allowed(): void {
		$GLOBALS['_current_user_can']   = false;
		$GLOBALS['_check_admin_referer'] = true;
		$settings                       = new Clients_Settings( new Client_Importer( new Fake_Client_Roster() ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		$settings->handle_admin_post();
	}

	/** The upload answers the MANAGE role, so a session scoped below it dies. */
	public function test_handle_admin_post_dies_for_a_caller_below_manage(): void {
		$GLOBALS['_current_user_can']    = true;
		$GLOBALS['_check_admin_referer'] = true;
		Capabilities::$session_scope     = Capabilities::TUNE;
		$repo                            = new Fake_Client_Roster();
		$settings                        = new Clients_Settings( new Client_Importer( $repo ) );

		$caught = $this->caught( fn () => $settings->handle_admin_post(), 'a caller below manage imports' );

		$this->assertStringStartsWith( 'wp_die', $caught->getMessage() );
		$this->assertSame( [], $repo->statuses() );
	}

	/** The Settings page registers under the capability MANAGE maps to. */
	public function test_the_settings_page_registers_under_the_manage_capability(): void {
		$GLOBALS['_current_user_can']    = true;
		$GLOBALS['_admin_submenu_pages'] = [];

		register_clients_admin_page();

		$this->assertCount( 1, $GLOBALS['_admin_submenu_pages'] );
		$this->assertSame( self::MANAGE_CAP, $GLOBALS['_admin_submenu_pages'][0][3] );
		$this->assertSame( SETTINGS_MENU_SLUG, $GLOBALS['_admin_submenu_pages'][0][4] );
	}

	/** The page renders nothing for a caller below MANAGE. */
	public function test_the_settings_page_renders_nothing_below_manage(): void {
		$GLOBALS['_current_user_can'] = true;
		Capabilities::$session_scope  = Capabilities::TUNE;

		\ob_start();
		render_clients_page();
		$this->assertSame( '', (string) \ob_get_clean() );

		Capabilities::$session_scope = Capabilities::MANAGE;
		\ob_start();
		render_clients_page();
		$this->assertStringContainsString( 'name="clients_csv"', (string) \ob_get_clean() );
	}

	public function test_handle_admin_post_imports_then_redirects_with_flag(): void {
		$GLOBALS['_current_user_can']   = true;
		$GLOBALS['_check_admin_referer'] = true;
		$GLOBALS['_wp_referer']         = 'http://localhost/wp-admin/options-general.php?page=x';

		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"7\",\"2022-02-02\",\"taos.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );
		$_FILES = [ 'clients_csv' => [ 'tmp_name' => $tmp, 'name' => 'clients.csv' ] ];

		$repo     = new Fake_Client_Roster();
		$settings = new Clients_Settings( new Client_Importer( $repo ) );

		$caught = $this->caught( fn () => $settings->handle_admin_post(), 'expected wp_safe_redirect to short-circuit via exception' );
		$this->assertStringContainsString( 'clients_imported=1', $caught->getMessage() );

		$this->assertCount( 1, $repo->statuses() );
		\unlink( $tmp );
		unset( $_FILES );
	}

	public function test_render_upload_section_outputs_a_csv_upload_form(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Client_Roster() ) );

		\ob_start();
		$settings->render_upload_section();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'name="clients_csv"', $html );
		$this->assertStringContainsString( Clients_Settings::ADMIN_POST_ACTION, $html );
	}

	public function test_render_import_notice_shows_success_only_when_flag_set(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Client_Roster() ) );

		$_GET = [ 'clients_imported' => '1' ];
		\ob_start();
		$settings->render_import_notice();
		$this->assertStringContainsString( 'notice-success', (string) \ob_get_clean() );

		$_GET = [];
		\ob_start();
		$settings->render_import_notice();
		$this->assertSame( '', (string) \ob_get_clean() );
		unset( $_GET );
	}
}

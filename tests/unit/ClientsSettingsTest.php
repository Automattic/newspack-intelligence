<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\Clients_Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/wp-post-stubs.php';

final class ClientsSettingsTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['_current_user_can'],
			$GLOBALS['_check_admin_referer'],
			$GLOBALS['_wp_referer'],
			$GLOBALS['_last_redirect'],
			$_GET,
			$_FILES
		);
	}

	public function test_import_path_parses_and_imports(): void {
		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"1\",\"2020-01-01\",\"a.com\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );

		$repo     = new Fake_Publisher_Repository(); // from tests/support/fake-publisher-repository.php
		$settings = new Clients_Settings( new Client_Importer( $repo ) );
		$result   = $settings->import_path( $tmp );

		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['total_in_csv'] );
		\unlink( $tmp );
	}

	public function test_import_path_returns_zeroes_for_unreadable_file(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Publisher_Repository() ) );
		$result   = $settings->import_path( '/no/such/file.csv' );
		$this->assertSame( 0, $result['total_in_csv'] );
	}

	public function test_default_constructor_builds_a_cpt_backed_importer(): void {
		\NPAINL_WP_Post_Store::reset();
		$settings = new Clients_Settings();
		$result   = $settings->import_path( '/no/such/file.csv' );
		$this->assertSame( 0, $result['total_in_csv'] );
	}

	public function test_handle_admin_post_dies_when_not_allowed(): void {
		$GLOBALS['_current_user_can']   = false;
		$GLOBALS['_check_admin_referer'] = true;
		$settings                       = new Clients_Settings( new Client_Importer( new Fake_Publisher_Repository() ) );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/wp_die/' );
		$settings->handle_admin_post();
	}

	public function test_handle_admin_post_imports_then_redirects_with_flag(): void {
		$GLOBALS['_current_user_can']   = true;
		$GLOBALS['_check_admin_referer'] = true;
		$GLOBALS['_wp_referer']         = 'http://localhost/wp-admin/options-general.php?page=x';

		$csv = "\"Atomic site ID\",\"Created\",\"Domain name\"\n\"7\",\"2022-02-02\",\"taos.example\"\n";
		$tmp = \tempnam( \sys_get_temp_dir(), 'clients' );
		\file_put_contents( $tmp, $csv );
		$_FILES = [ 'clients_csv' => [ 'tmp_name' => $tmp, 'name' => 'clients.csv' ] ];

		$repo     = new Fake_Publisher_Repository();
		$settings = new Clients_Settings( new Client_Importer( $repo ) );

		try {
			$settings->handle_admin_post();
			$this->fail( 'expected wp_safe_redirect to short-circuit via exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'clients_imported=1', $e->getMessage() );
		}

		$this->assertCount( 1, $repo->all_atomic_ids() );
		\unlink( $tmp );
		unset( $_FILES );
	}

	public function test_render_upload_section_outputs_a_csv_upload_form(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Publisher_Repository() ) );

		\ob_start();
		$settings->render_upload_section();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'name="clients_csv"', $html );
		$this->assertStringContainsString( Clients_Settings::ADMIN_POST_ACTION, $html );
	}

	public function test_render_import_notice_shows_success_only_when_flag_set(): void {
		$settings = new Clients_Settings( new Client_Importer( new Fake_Publisher_Repository() ) );

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

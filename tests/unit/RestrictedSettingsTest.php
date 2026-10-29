<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Restricted_Settings;
use Newspack_Nodes\Tests\TestCase;
use function Newspack_Intelligence\render_clients_page;

require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The Settings page's "Restricted access" section: the holders, a grant
 * by login and a revoke per holder, each an admin-post checked as the
 * client-CSV upload is, MANAGE and a nonce, and logged naming the operator.
 */
final class RestrictedSettingsTest extends TestCase {
	use Signs_In_Users;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_check_admin_referer_asked'] = [];
		self::sign_in( 8311, 'gull-ops', 'manage_options' );
		self::add_user( 8312, 'gull-analyst', 'newspack_intelligence_restricted' );
		self::add_user( 8313, 'gull-reader', 'read' );
		$GLOBALS['_wp_referer'] = 'http://localhost/wp-admin/options-general.php?page=newspack-intelligence-settings&from=8311';
	}

	protected function tearDown(): void {
		self::forget_users();
		unset( $GLOBALS['_check_admin_referer'], $GLOBALS['_check_admin_referer_asked'], $GLOBALS['_wp_referer'], $GLOBALS['_last_redirect'], $_POST );
		parent::tearDown();
	}

	/** A grant adds the capability, logs the operator, and returns to the page. */
	public function test_a_grant_acts_and_redirects(): void {
		$stderr = $this->capture_stderr();
		$_POST  = [ 'login' => 'gull-reader' ];

		$caught = $this->caught( Restricted_Settings::handle_grant( ... ), 'a grant did not redirect' );

		$this->assertSame( 'wp_safe_redirect: ' . $GLOBALS['_wp_referer'], $caught->getMessage() );
		$this->assertSame( [ Restricted_Settings::GRANT_ACTION ], $GLOBALS['_check_admin_referer_asked'] );
		$this->assertSame( [ 'gull-analyst', 'gull-reader' ], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access granted to gull-reader by user:gull-ops' ], self::access_log( $stderr ) );
	}

	/** A revoke removes it, logs the operator, and returns to the page. */
	public function test_a_revoke_acts_and_redirects(): void {
		$stderr = $this->capture_stderr();
		$_POST  = [ 'login' => 'gull-analyst' ];

		$caught = $this->caught( Restricted_Settings::handle_revoke( ... ), 'a revoke did not redirect' );

		$this->assertSame( 'wp_safe_redirect: ' . $GLOBALS['_wp_referer'], $caught->getMessage() );
		$this->assertSame( [ Restricted_Settings::REVOKE_ACTION ], $GLOBALS['_check_admin_referer_asked'] );
		$this->assertSame( [], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access revoked from gull-analyst by user:gull-ops' ], self::access_log( $stderr ) );
	}

	/** A failed nonce dies before anything changes. */
	public function test_a_bad_nonce_dies(): void {
		$GLOBALS['_check_admin_referer'] = false;
		$_POST                           = [ 'login' => 'gull-reader' ];

		foreach ( [ Restricted_Settings::handle_grant( ... ), Restricted_Settings::handle_revoke( ... ) ] as $handle ) {
			$this->assertSame( 'wp_die: Not allowed.', $this->caught( $handle, 'a bad nonce passed' )->getMessage() );
		}
		$this->assertSame( [ 'gull-analyst' ], Intel_Tiers::holders() );
	}

	/** A caller below MANAGE dies before anything changes. */
	public function test_a_caller_below_manage_dies(): void {
		self::sign_in( 8314, 'gull-desk', 'read' );
		$_POST = [ 'login' => 'gull-analyst' ];

		$caught = $this->caught( Restricted_Settings::handle_revoke( ... ), 'a caller below manage revoked' );

		$this->assertSame( 'wp_die: Not allowed.', $caught->getMessage() );
		$this->assertSame( [ 'gull-analyst' ], Intel_Tiers::holders() );
	}

	/** A refused change dies naming why, escaped once, as wp_die renders it. */
	public function test_a_refused_change_dies_naming_why(): void {
		$_POST = [ 'login' => 'ghost-gull' ];

		$caught = $this->caught( Restricted_Settings::handle_grant( ... ), 'an unknown login was granted' );

		$this->assertSame( 'wp_die: no user: ghost-gull', $caught->getMessage() );
	}

	/** With no referer, a change returns to the Settings page itself. */
	public function test_a_change_with_no_referer_returns_to_the_settings_page(): void {
		unset( $GLOBALS['_wp_referer'] );
		$_POST = [ 'login' => 'gull-reader' ];

		$caught = $this->caught( Restricted_Settings::handle_grant( ... ), 'a grant did not redirect' );

		$this->assertSame( 'wp_safe_redirect: ' . \admin_url( 'options-general.php?page=newspack-intelligence-settings' ), $caught->getMessage() );
	}

	/**
	 * The section lists each user's own grant with its revoke form, says who
	 * reads the tier without one, and offers a grant form; a role carrying it
	 * lists no one.
	 */
	public function test_the_section_lists_holders_and_offers_both_forms(): void {
		self::add_user( 8315, 'gull-editor', 'newspack_intelligence_restricted' );
		\add_role( 'gull_auditor', 'Gull Auditor', [ 'newspack_intelligence_restricted' => true ] );
		self::add_user( 8316, 'gull-auditor', 'gull_auditor' );

		\ob_start();
		Restricted_Settings::render_section();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( '<h2>Restricted access</h2>', $html );
		$this->assertSame( 2, \substr_count( $html, 'value="' . Restricted_Settings::REVOKE_ACTION . '"' ) );
		$this->assertSame( 2, \substr_count( $html, 'value="' . \wp_create_nonce( Restricted_Settings::REVOKE_ACTION ) . '"' ) );
		$this->assertStringContainsString( '<input type="hidden" name="login" value="gull-analyst" />', $html );
		$this->assertStringContainsString( '<input type="hidden" name="login" value="gull-editor" />', $html );
		$this->assertSame( 1, \substr_count( $html, 'value="' . Restricted_Settings::GRANT_ACTION . '"' ) );
		$this->assertStringContainsString( 'value="' . \wp_create_nonce( Restricted_Settings::GRANT_ACTION ) . '"', $html );
		$this->assertStringContainsString( '<input type="text" name="login"', $html );
		$this->assertStringNotContainsString( 'gull-auditor', $html );
		$this->assertStringContainsString( 'A role carrying the capability, and on a multisite a super admin, also reads restricted items without a grant.', $html );
	}

	/** With no holder, the section says so and still offers the grant. */
	public function test_the_section_with_no_holder_says_so(): void {
		Intel_Tiers::strip();

		\ob_start();
		Restricted_Settings::render_section();
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'No user holds a restricted access grant.', $html );
		$this->assertStringNotContainsString( Restricted_Settings::REVOKE_ACTION, $html );
		$this->assertStringContainsString( Restricted_Settings::GRANT_ACTION, $html );
	}

	/** The Settings page renders the section below the client upload. */
	public function test_the_settings_page_renders_the_section(): void {
		\ob_start();
		render_clients_page();
		$html = (string) \ob_get_clean();

		$this->assertGreaterThan( \strpos( $html, 'name="clients_csv"' ), \strpos( $html, '<h2>Restricted access</h2>' ) );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Restricted_CLI_Command;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * `wp newspack-intelligence restricted grant|revoke|list`: each names the
 * user it changed, refuses an unknown login, a grant to a holder and a
 * revoke from a user holding no grant, and refuses to run as root.
 */
final class RestrictedCliCommandTest extends TestCase {
	use Signs_In_Users;

	/** What `list` says after the holders. */
	private const UNGRANTED = 'A role carrying newspack_intelligence_restricted, and on a multisite a super admin, also reads restricted items without a grant.';

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		CLI::$uid_provider = static fn (): int => 1047;
		self::add_user( 7201, 'tidewater-analyst', 'newspack_intelligence_restricted' );
		self::add_user( 7202, 'tidewater-reader', 'read' );
	}

	protected function tearDown(): void {
		CLI::$uid_provider = null;
		self::forget_users();
		parent::tearDown();
	}

	/** The loader registers the verbs under `newspack-intelligence restricted`. */
	public function test_the_verbs_register_under_restricted(): void {
		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Restricted_CLI_Command', \WP_CLI::$commands['newspack-intelligence restricted'] );
	}

	/** A grant names the user's own login, and logs the wp-cli uid when no user is signed in. */
	public function test_grant_names_the_user_and_logs_the_cli_operator(): void {
		$stderr = $this->capture_stderr();

		( new Restricted_CLI_Command() )->grant( [ ' tidewater-reader ' ], [] );

		$this->assertSame( [ 'Granted restricted access to tidewater-reader.' ], \WP_CLI::$successes );
		$this->assertSame( [ 'tidewater-analyst', 'tidewater-reader' ], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access granted to tidewater-reader by wp-cli uid 1047' ], self::access_log( $stderr ) );
	}

	/** A revoke names the user, and logs the user `--user` signed in. */
	public function test_revoke_names_the_user_and_logs_the_signed_in_operator(): void {
		self::sign_in( 7203, 'tidewater-ops', 'manage_options' );
		$stderr = $this->capture_stderr();

		( new Restricted_CLI_Command() )->revoke( [ 'tidewater-analyst' ], [] );

		$this->assertSame( [ 'Revoked restricted access from tidewater-analyst.' ], \WP_CLI::$successes );
		$this->assertSame( [], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access revoked from tidewater-analyst by user:tidewater-ops' ], self::access_log( $stderr ) );
	}

	/** Each change that would change nothing is refused by name, and nothing changes. */
	public function test_a_change_that_changes_nothing_is_refused(): void {
		$command = new Restricted_CLI_Command();

		$command->grant( [ 'ghost-pier' ], [] );
		$command->revoke( [ 'ghost-pier' ], [] );
		$command->grant( [ 'tidewater-analyst' ], [] );
		$command->revoke( [ 'tidewater-reader' ], [] );

		$this->assertSame(
			[ 'no user: ghost-pier', 'no user: ghost-pier', 'tidewater-analyst already holds restricted access', 'tidewater-reader holds no restricted access grant' ],
			\WP_CLI::$errors
		);
		$this->assertSame( [], \WP_CLI::$successes );
		$this->assertSame( [ 'tidewater-analyst' ], Intel_Tiers::holders() );
	}

	/**
	 * `list` prints each user's own grant, one login a line, sorted, then
	 * says who reads the tier without one; a role carrying it lists no one.
	 */
	public function test_list_prints_each_grant_then_who_reads_without_one(): void {
		self::add_user( 7204, 'anchor-editor', 'newspack_intelligence_restricted' );
		\add_role( 'tide_auditor', 'Tide Auditor', [ 'newspack_intelligence_restricted' => true ] );
		self::add_user( 7205, 'tidewater-auditor', 'tide_auditor' );

		( new Restricted_CLI_Command() )->list_( [], [] );

		$this->assertSame( [ 'anchor-editor', 'tidewater-analyst', self::UNGRANTED ], \WP_CLI::$logs );
	}

	/** `list` with no grant says so. */
	public function test_list_with_no_grant_says_so(): void {
		Intel_Tiers::strip();

		( new Restricted_CLI_Command() )->list_( [], [] );

		$this->assertSame( [ 'No user holds a restricted access grant.', self::UNGRANTED ], \WP_CLI::$logs );
	}

	/** Run as root, every verb refuses before it reads or changes anything. */
	public function test_every_verb_refuses_root(): void {
		CLI::$uid_provider = static fn (): int => 0;
		$command           = new Restricted_CLI_Command();

		$command->grant( [ 'tidewater-reader' ], [] );
		$command->revoke( [ 'tidewater-analyst' ], [] );
		$command->list_( [], [] );

		$this->assertSame(
			[
				'wp newspack-intelligence restricted grant must run as the same user as the workers, not root.',
				'wp newspack-intelligence restricted revoke must run as the same user as the workers, not root.',
				'wp newspack-intelligence restricted list must run as the same user as the workers, not root.',
			],
			\WP_CLI::$errors
		);
		$this->assertSame( [], \WP_CLI::$logs );
		$this->assertSame( [ 'tidewater-analyst' ], Intel_Tiers::holders() );
	}
}

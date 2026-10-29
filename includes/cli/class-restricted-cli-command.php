<?php
/**
 * Restricted_CLI_Command: `wp newspack-intelligence restricted grant`,
 * `restricted revoke` and `restricted list`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Restricted_CLI_Command {
	use Refuses_Root;

	/**
	 * Grant a user restricted access on this site: the user then reads
	 * restricted items through every read and curation verb and the MCP
	 * door. Logged naming the operator and the user.
	 *
	 * ## OPTIONS
	 *
	 * <login>
	 * : The user's login.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence restricted grant kinsey
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function grant( array $args, array $assoc_args ): void {
		$this->change( 'grant', Intel_Tiers::grant( ... ), $args[0] ?? '', 'Granted restricted access to %s.' );
	}

	/**
	 * Revoke a user's restricted access on this site; their next read sees
	 * public and internal items alone. Logged naming the operator and the
	 * user.
	 *
	 * ## OPTIONS
	 *
	 * <login>
	 * : The user's login.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence restricted revoke kinsey
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function revoke( array $args, array $assoc_args ): void {
		$this->change( 'revoke', Intel_Tiers::revoke( ... ), $args[0] ?? '', 'Revoked restricted access from %s.' );
	}

	/**
	 * Run one grant or revoke as the operator, naming the user it changed by
	 * the user's own login, or the refusal.
	 *
	 * @param string                          $verb    The verb, for the root refusal.
	 * @param \Closure(string,string): string $change  `Intel_Tiers::grant()` or `revoke()`.
	 * @param string                          $login   The user's login, as typed.
	 * @param string                          $success The success line, the user's own login filled.
	 */
	private function change( string $verb, \Closure $change, string $login, string $success ): void {
		if ( self::refused_root( "restricted {$verb}" ) ) {
			return;
		}
		try {
			$changed = $change( $login, self::operator() );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		\WP_CLI::success( \sprintf( $success, $changed ) );
	}

	/** Who runs the command: the user `--user` signed in, else the process uid. */
	private static function operator(): string {
		return '' === Core::as_string( \wp_get_current_user()->user_login ) ? 'wp-cli uid ' . CLI::uid() : Intel_Event::caller();
	}

	/**
	 * List the logins holding their own grant of restricted access on this
	 * site, one a line, each one `revoke` takes; then say that a role
	 * carrying the capability, and on a multisite a super admin, also reads
	 * restricted items without a grant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence restricted list
	 *
	 * @subcommand list
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function list_( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'restricted list' ) ) {
			return;
		}
		$holders = Intel_Tiers::holders();
		foreach ( [] === $holders ? [ 'No user holds a restricted access grant.' ] : $holders as $line ) {
			\WP_CLI::log( $line );
		}
		\WP_CLI::log( 'A role carrying ' . Intel_Tiers::CAPABILITY . ', and on a multisite a super admin, also reads restricted items without a grant.' );
	}
}

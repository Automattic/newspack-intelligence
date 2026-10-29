<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

/**
 * Users the bootstrap's WordPress stubs answer for: each a login and the
 * capabilities it holds of its own on the current site, the one
 * `current_user_can()` then answers for while that user is signed in; and
 * the lines a grant or revoke of restricted access logs.
 */
trait Signs_In_Users {

	/**
	 * Add a user holding exactly `$caps` on the current site.
	 *
	 * @param int    $id    The user id.
	 * @param string $login The login.
	 * @param string ...$caps The capabilities it holds.
	 */
	private static function add_user( int $id, string $login, string ...$caps ): void {
		$GLOBALS['_wp_test_user_logins'][ $id ]                          = $login;
		$GLOBALS['_wp_test_site_caps'][ \get_current_blog_id() ][ $id ] = \array_fill_keys( $caps, true );
	}

	/**
	 * Add a user and sign it in.
	 *
	 * @param int    $id    The user id.
	 * @param string $login The login.
	 * @param string ...$caps The capabilities it holds.
	 */
	private static function sign_in( int $id, string $login, string ...$caps ): void {
		self::add_user( $id, $login, ...$caps );
		$GLOBALS['_current_user_id'] = $id;
	}

	/**
	 * The grant and revoke lines stderr captured, each from its text on.
	 *
	 * @param \ArrayObject<int,string> $captured What `capture_stderr()` collected.
	 * @return list<string>
	 */
	private static function access_log( \ArrayObject $captured ): array {
		$lines = [];
		foreach ( $captured as $line ) {
			$at = \strpos( $line, 'restricted access ' );
			if ( false !== $at ) {
				$lines[] = \rtrim( \substr( $line, $at ) );
			}
		}
		return $lines;
	}

	/** Forget every user, role, site and signed-in id the stubs hold. */
	private static function forget_users(): void {
		unset(
			$GLOBALS['_current_user_id'],
			$GLOBALS['_wp_test_user_logins'],
			$GLOBALS['_wp_test_site_caps'],
			$GLOBALS['_wp_test_blog_id'],
			$GLOBALS['_wp_test_blog_stack'],
			$GLOBALS['_wp_test_sites'],
			$GLOBALS['_wp_test_roles']
		);
		$GLOBALS['_wp_test_is_multisite'] = false;
	}
}

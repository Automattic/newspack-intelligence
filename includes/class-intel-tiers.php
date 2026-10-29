<?php
/**
 * Intel_Tiers: the item tiers a read caller may see, and who holds a grant
 * of the restricted one. Every caller past a verb's role gate reads
 * `public` and `internal`; a caller WordPress answers `CAPABILITY` for reads
 * `restricted` too, asked on each call. An operator grants and revokes that
 * capability per user, as the user's own on the current site, and each
 * change writes one stderr line, which reaches the PHP error log, naming the
 * operator and the user. A role carrying the capability, and on a multisite
 * a super admin, also reads the tier, but holds no grant to list or revoke.
 *
 * Uninstall reaches `strip()` with the substrate possibly absent, so it and
 * everything it calls stay free of the substrate's classes.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

final class Intel_Tiers {

	/** The user capability that adds `restricted` to a caller's tiers. */
	public const CAPABILITY = 'newspack_intelligence_restricted';

	/** The tiers every read caller holds: all but the restricted one. */
	public const UNRESTRICTED = [ 'public', 'internal' ];

	/** The tier the capability adds. */
	private const RESTRICTED = 'restricted';

	/**
	 * Grant a user the capability on the current site, and log it.
	 *
	 * @param string $login    The user's login, as typed.
	 * @param string $operator Who granted it, for the log.
	 * @return string The login of the user granted it.
	 * @throws \InvalidArgumentException As `user()`, or for a user already granted it.
	 */
	public static function grant( string $login, string $operator ): string {
		$user = self::user( $login );
		if ( self::granted( $user ) ) {
			throw new \InvalidArgumentException( "{$user->user_login} already holds restricted access" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text; each surface escapes it.
		}
		$user->add_cap( self::CAPABILITY );
		Core::stderr( "restricted access granted to {$user->user_login} by {$operator}" );
		return $user->user_login;
	}

	/**
	 * Revoke a user's grant of the capability on the current site, and log it.
	 *
	 * @param string $login    The user's login, as typed.
	 * @param string $operator Who revoked it, for the log.
	 * @return string The login of the user it was revoked from.
	 * @throws \InvalidArgumentException As `user()`, or for a user holding no grant.
	 */
	public static function revoke( string $login, string $operator ): string {
		$user = self::user( $login );
		if ( ! self::granted( $user ) ) {
			throw new \InvalidArgumentException( "{$user->user_login} holds no restricted access grant" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text; each surface escapes it.
		}
		$user->remove_cap( self::CAPABILITY );
		Core::stderr( "restricted access revoked from {$user->user_login} by {$operator}" );
		return $user->user_login;
	}

	/**
	 * The member of the current site a login names.
	 *
	 * @param string $login The login, as typed.
	 * @throws \InvalidArgumentException When no user has it, or on a multisite when that user is not a member of this site.
	 */
	private static function user( string $login ): \WP_User {
		$user = \get_user_by( 'login', $login ) ?: throw new \InvalidArgumentException( "no user: {$login}" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text; each surface escapes it.
		if ( \is_multisite() && ! \is_user_member_of_blog( $user->ID ) ) {
			throw new \InvalidArgumentException( "{$user->user_login} is not a member of this site" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain text; each surface escapes it.
		}
		return $user;
	}

	/** Remove every user's own grant of the capability on the current site. */
	public static function strip(): void {
		foreach ( self::grantees() as $user ) {
			$user->remove_cap( self::CAPABILITY );
		}
	}

	/**
	 * The logins holding their own grant of the capability on the current
	 * site, sorted: every one `revoke()` takes.
	 *
	 * @return list<string>
	 */
	public static function holders(): array {
		return \array_map( static fn ( \WP_User $user ): string => $user->user_login, self::grantees() );
	}

	/**
	 * The users holding their own grant of the capability on the current
	 * site, by login; a role carrying it grants no one.
	 *
	 * @return list<\WP_User>
	 */
	private static function grantees(): array {
		$users = [];
		foreach ( \get_users( [ 'capability' => self::CAPABILITY, 'orderby' => 'login' ] ) as $user ) {
			if ( $user instanceof \WP_User && self::granted( $user ) ) {
				$users[] = $user;
			}
		}
		return $users;
	}

	/**
	 * Whether a user holds its own grant of the capability on the current site.
	 *
	 * @param \WP_User $user The user.
	 */
	private static function granted( \WP_User $user ): bool {
		return ! empty( $user->caps[ self::CAPABILITY ] );
	}

	/**
	 * The tiers the current caller may read.
	 *
	 * @return list<string>
	 */
	public static function readable(): array {
		return \current_user_can( self::CAPABILITY ) ? [ ...self::UNRESTRICTED, self::RESTRICTED ] : self::UNRESTRICTED;
	}
}

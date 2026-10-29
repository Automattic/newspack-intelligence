<?php
/**
 * WordPress's users, sites and capabilities, as the plugin's tier reads,
 * grants and uninstall ask of them. Loaded by the bootstrap ahead of the
 * substrate's shared shims, so these win, and alone by the uninstall
 * fixture, which runs with no substrate.
 *
 * A user's own capability map lives per site, as its user meta does, in
 * `_wp_test_site_caps[ site ][ user id ]`: a key naming a role in
 * `_wp_test_roles` grants that role's capabilities, any other key is the
 * capability itself, and a row is membership of the site. Logins live in
 * `_wp_test_user_logins[ user id ]` and addresses in
 * `_wp_test_user_emails[ user id ]`; the sites in `_wp_test_sites`, the
 * current one in `_wp_test_blog_id`.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['_wp_test_blog_id'] ?? 1 );
	}
}
if ( ! function_exists( 'get_sites' ) ) {
	function get_sites( array $args = [] ): array {
		return $GLOBALS['_wp_test_sites'] ?? [ 1 ];
	}
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( int $site ): bool {
		$GLOBALS['_wp_test_blog_stack'][] = get_current_blog_id();
		$GLOBALS['_wp_test_blog_id']      = $site;
		return true;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog(): bool {
		$GLOBALS['_wp_test_blog_id'] = \array_pop( $GLOBALS['_wp_test_blog_stack'] ) ?? 1;
		return true;
	}
}
if ( ! function_exists( 'is_user_member_of_blog' ) ) {
	function is_user_member_of_blog( int $user_id = 0, int $blog_id = 0 ): bool {
		return isset( $GLOBALS['_wp_test_site_caps'][ 0 === $blog_id ? get_current_blog_id() : $blog_id ][ $user_id ] );
	}
}

// As WordPress: tags stripped, ends trimmed, whitespace runs collapsed.
if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( string $username, bool $strict = false ): string {
		return (string) \preg_replace( '|\s+|', ' ', \trim( \strip_tags( $username ) ) );
	}
}

/**
 * A user's capabilities on the current site: its own, and every one a role
 * it holds carries.
 *
 * @return array<string,bool>
 */
function intel_test_allcaps( int $user_id ): array {
	$own = $GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ][ $user_id ] ?? [];
	$all = $own;
	foreach ( \array_keys( $own ) as $key ) {
		$all += $GLOBALS['_wp_test_roles'][ $key ]['capabilities'] ?? [];
	}
	return $all;
}

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		/** @var array<string,bool> The user's own map on the current site, roles included. */
		public array $caps;

		/** The address in `_wp_test_user_emails`, '' when none. */
		public string $user_email;

		public function __construct( public int $ID, public string $user_login ) {
			$this->caps       = $GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ][ $ID ] ?? [];
			$this->user_email = $GLOBALS['_wp_test_user_emails'][ $ID ] ?? '';
		}

		public function add_cap( string $cap, bool $grant = true ): void {
			$GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ][ $this->ID ][ $cap ] = $grant;
			$this->caps[ $cap ] = $grant;
		}

		public function remove_cap( string $cap ): void {
			unset( $GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ][ $this->ID ][ $cap ], $this->caps[ $cap ] );
		}
	}
}

// As WordPress: a login is looked up as sanitize_user() spells it.
if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, $value ) {
		foreach ( $GLOBALS['_wp_test_user_logins'] ?? [] as $id => $login ) {
			if ( 'login' === $field ? sanitize_user( (string) $value ) === $login : $id === (int) $value ) {
				return new WP_User( $id, $login );
			}
		}
		return false;
	}
}

// Records each user whose cache is dropped, in `_wp_test_cleaned_users`.
if ( ! function_exists( 'clean_user_cache' ) ) {
	function clean_user_cache( $user ): void {
		$GLOBALS['_wp_test_cleaned_users'][] = $user instanceof WP_User ? $user->ID : (int) $user;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( string $email ): string|false {
		return false === \filter_var( $email, \FILTER_VALIDATE_EMAIL ) ? false : $email;
	}
}

// The members of the current site holding `capability`, own or by a role, by login.
if ( ! function_exists( 'get_users' ) ) {
	function get_users( array $args = [] ): array {
		$users = [];
		foreach ( \array_keys( $GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ] ?? [] ) as $id ) {
			if ( ! empty( intel_test_allcaps( $id )[ $args['capability'] ] ) ) {
				$users[] = new WP_User( $id, $GLOBALS['_wp_test_user_logins'][ $id ] );
			}
		}
		\usort( $users, static fn ( WP_User $a, WP_User $b ): int => \strcmp( $a->user_login, $b->user_login ) );
		return $users;
	}
}

// Overrides shared shim: a user with a row on the current site holds its
// capabilities alone; any other caller, the _current_user_can bool.
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $cap ): bool {
		$id = (int) ( $GLOBALS['_current_user_id'] ?? 0 );
		if ( ! isset( $GLOBALS['_wp_test_site_caps'][ get_current_blog_id() ][ $id ] ) ) {
			return (bool) ( $GLOBALS['_current_user_can'] ?? false );
		}
		return ! empty( intel_test_allcaps( $id )[ $cap ] );
	}
}

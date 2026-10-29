<?php
/**
 * Restricted_Settings: the "Restricted access" section of the Settings
 * page — each user's own grant of restricted access with a revoke for it,
 * and a grant by login — and the two admin-posts behind it, each admitted
 * by `admit_settings_post()`, as the client-CSV upload is.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Restricted_Settings {

	/** The admin-post action, and its nonce action, that grants. */
	public const GRANT_ACTION = 'newspack_intelligence_grant_restricted';

	/** The admin-post action, and its nonce action, that revokes. */
	public const REVOKE_ACTION = 'newspack_intelligence_revoke_restricted';

	/** admin-post handler for `GRANT_ACTION`: grant the posted login. */
	public static function handle_grant(): void {
		self::handle( self::GRANT_ACTION, Intel_Tiers::grant( ... ) );
	}

	/** admin-post handler for `REVOKE_ACTION`: revoke the posted login. */
	public static function handle_revoke(): void {
		self::handle( self::REVOKE_ACTION, Intel_Tiers::revoke( ... ) );
	}

	/**
	 * Check the MANAGE role and the action's nonce, then change the posted
	 * login as the signed-in user and return to the page; a refused change
	 * dies naming why.
	 *
	 * @param string                          $action The admin-post action, its nonce action too.
	 * @param \Closure(string,string): string $change `Intel_Tiers::grant()` or `revoke()`.
	 */
	private static function handle( string $action, \Closure $change ): void {
		admit_settings_post( $action );
		$login = isset( $_POST['login'] ) && \is_string( $_POST['login'] ) ? \sanitize_text_field( \wp_unslash( $_POST['login'] ) ) : '';
		try {
			$change( $login, Intel_Event::caller() );
		} catch ( \InvalidArgumentException $e ) {
			\wp_die( \esc_html( $e->getMessage() ) );
		}
		return_to_settings();
	}

	/** Render the section (called from the Settings page). */
	public static function render_section(): void {
		echo '<h2>' . \esc_html__( 'Restricted access', 'newspack-intelligence' ) . '</h2>';
		echo '<p>' . \esc_html__( 'A user granted restricted access reads restricted items, on the Intelligence page and through the MCP door, beside public and internal ones.', 'newspack-intelligence' ) . '</p>';
		$holders = Intel_Tiers::holders();
		if ( [] === $holders ) {
			echo '<p>' . \esc_html__( 'No user holds a restricted access grant.', 'newspack-intelligence' ) . '</p>';
		}
		foreach ( $holders as $login ) {
			self::form( self::REVOKE_ACTION, $login, \__( 'Revoke', 'newspack-intelligence' ) );
		}
		self::form( self::GRANT_ACTION, null, \__( 'Grant', 'newspack-intelligence' ) );
		echo '<p class="description">' . \esc_html__( 'A role carrying the capability, and on a multisite a super admin, also reads restricted items without a grant.', 'newspack-intelligence' ) . '</p>';
	}

	/**
	 * One admin-post form: a holder's login and its revoke, or a login field
	 * and the grant.
	 *
	 * @param string      $action The admin-post action.
	 * @param string|null $login  The holder the form revokes, or null for a login field.
	 * @param string      $button The button's label.
	 */
	private static function form( string $action, ?string $login, string $button ): void {
		open_settings_form( $action );
		echo null === $login
			? '<input type="text" name="login" required aria-label="' . \esc_attr__( 'Login', 'newspack-intelligence' ) . '" /> '
			: '<code>' . \esc_html( $login ) . '</code> <input type="hidden" name="login" value="' . \esc_attr( $login ) . '" /> ';
		\submit_button( $button, 'secondary', 'submit', false );
		echo '</form>';
	}
}

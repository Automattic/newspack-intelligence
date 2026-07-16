<?php
/**
 * Vault_Secret: resolves a substrate Vault entry ID to its stored auth_password.
 *
 * Shared by every node that stores a `vault_id` config verb (Linear_Source_Node,
 * Github_Source_Node, and the LLM_Config trait) instead of a raw secret. Resolves
 * to '' when the id is blank, the substrate Vault class isn't loaded, the id is
 * unknown, or the entry has no password.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

trait Vault_Secret {

	/** Resolve a Vault entry ID to its auth_password, or '' when unresolvable. */
	protected function resolve_vault_secret( string $vault_id ): string {
		if ( '' === $vault_id || ! \class_exists( '\\Newspack_Nodes\\Vault' ) ) {
			return '';
		}
		$entry    = \Newspack_Nodes\Vault::get_instance()->get( $vault_id );
		$password = ( null !== $entry ) ? ( $entry['auth_password'] ?? null ) : null;
		return ( \is_string( $password ) && '' !== $password ) ? $password : '';
	}
}

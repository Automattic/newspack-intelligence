<?php
/**
 * Vault_Secret: the `set_vault_id` verb and the secret its Vault entry holds.
 *
 * A node keeps only the substrate Vault entry id, set by a declarative
 * `setter` verb, never a raw secret; `vault_secret()` resolves the entry's
 * auth_password at use time, '' when the id is blank, the substrate Vault is
 * not loaded, the id is unknown, or the entry has no password. A using node
 * also uses Schema_Reflection, which synthesizes the verb and dumps it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

trait Vault_Secret {

	/** The Vault entry id the secret resolves from; '' until set. */
	protected string $vault_id = '';

	/**
	 * The `set_vault_id` verb's declaration, for a using node's commands.
	 *
	 * @param string $what What the entry holds, for the verb's description.
	 * @return array<string,mixed>
	 */
	protected static function vault_id_verb( string $what ): array {
		return [
			'name'        => 'set_vault_id',
			'description' => "Set the Vault entry ID to resolve {$what} from: <vault_id>.",
			'args'        => [
				[ 'name' => 'vault_id', 'type' => 'vault_id', 'required' => true ],
			],
			'setter'      => 'vault_id',
		];
	}

	/**
	 * The `set_vault_id` verb's entry point, which the declared setter calls.
	 *
	 * @param string $vault_id The Vault entry id.
	 */
	public function set_vault_id( string $vault_id ): void {
		$this->vault_id = $vault_id;
	}

	/** The entry's auth_password, or '' when it cannot be resolved. */
	protected function vault_secret(): string {
		if ( '' === $this->vault_id || ! \class_exists( '\\Newspack_Nodes\\Vault' ) ) {
			return '';
		}
		$entry    = \Newspack_Nodes\Vault::get_instance()->get( $this->vault_id );
		$password = ( null !== $entry ) ? ( $entry['auth_password'] ?? null ) : null;
		return ( \is_string( $password ) && '' !== $password ) ? $password : '';
	}
}

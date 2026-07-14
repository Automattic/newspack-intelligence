<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Vault_Secret;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;

final class VaultSecretTest extends TestCase {

	protected function tearDown(): void {
		delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
	}

	/** Anonymous fixture exposing the protected trait method publicly, mirroring SchemaReflectionTest's pattern. */
	private function fixture(): object {
		return new class() {
			use Vault_Secret;

			public function resolve( string $vault_id ): string {
				return $this->resolve_vault_secret( $vault_id );
			}
		};
	}

	public function test_resolves_seeded_entry_auth_password(): void {
		update_option(
			Vault::OPTION_KEY,
			[ 'austin' => [ 'id' => 'austin', 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'secret-value' ] ]
		);
		Vault::get_instance()->reset_cache();

		$this->assertSame( 'secret-value', $this->fixture()->resolve( 'austin' ) );
	}

	public function test_unknown_vault_id_returns_empty_string(): void {
		$this->assertSame( '', $this->fixture()->resolve( 'no-such-entry' ) );
	}

	public function test_empty_vault_id_returns_empty_string(): void {
		$this->assertSame( '', $this->fixture()->resolve( '' ) );
	}
}

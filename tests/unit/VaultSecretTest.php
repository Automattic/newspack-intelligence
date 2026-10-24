<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Vault_Secret;
use Newspack_Nodes\Node;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Vault;

final class VaultSecretTest extends TestCase {

	protected function tearDown(): void {
		delete_option( Vault::OPTION_KEY );
		Vault::get_instance()->reset_cache();
		parent::tearDown();
	}

	/** A node declaring the trait's verb, exposing the secret it resolves. */
	private function fixture(): object {
		return new class() extends Node {
			use Schema_Reflection;
			use Vault_Secret;

			public function __construct() {
				parent::__construct();
				$this->auto_wire_interpreter();
			}

			public static function node_schema(): array {
				return [ 'commands' => [ self::vault_id_verb( 'the Harbor Gazette token' ) ] ];
			}

			public function secret(): string {
				return $this->vault_secret();
			}

			public function dump(): string {
				return $this->dump_setters();
			}
		};
	}

	public function test_the_vault_id_verb_names_the_entry_the_secret_resolves_from(): void {
		$this->seed_vault( 'harbor-creds', [ 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => 'harbor-secret-7731' ] );
		$node = $this->fixture();
		$node->name( 'harbor-source' );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_vault_id', [ 'harbor-creds' ] ) );

		$this->assertSame( 'harbor-secret-7731', $node->secret() );
		$this->assertSame( "command_node harbor-source:config set_vault_id harbor-creds\n", $node->dump() );
		$verb = $node::node_schema()['commands'][0];
		$this->assertSame( 'vault_id', $verb['setter'] );
		$this->assertSame( 'vault_id', $verb['args'][0]['type'] );
		$this->assertStringContainsString( 'the Harbor Gazette token', $verb['description'] );
	}

	public function test_an_unknown_or_unset_vault_id_resolves_to_empty(): void {
		$node = $this->fixture();
		$node->name( 'harbor-source' );
		$this->assertSame( '', $node->secret(), 'unset' );

		$node->interpreter()->dispatch( 'set_vault_id', [ 'no-such-entry' ] );
		$this->assertSame( '', $node->secret(), 'unknown' );
		$this->assertSame( '', $node::node_schema()['commands'][0]['handler'] ?? '', 'the substrate synthesizes the handler' );
	}
}

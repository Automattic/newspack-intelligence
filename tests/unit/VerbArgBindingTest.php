<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Gate_Node;
use Newspack_Intelligence\Github_Source_Node;
use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Every schema-declared verb binds its tokens by position or by `--name=`
 * through the sibling `:config` interpreter, and reads them by name.
 */
final class VerbArgBindingTest extends TestCase {

	private function sibling( Node $node, string $name ): Command_Interpreter_Node {
		$node->name( $name );
		$sibling = Core::node( "{$name}:config" );
		$this->assertInstanceOf( Command_Interpreter_Node::class, $sibling );
		return $sibling;
	}

	private function prop( Node $node, string $property ): mixed {
		return ( new \ReflectionProperty( $node, $property ) )->getValue( $node );
	}

	public function test_add_url_binds_by_position_and_by_name(): void {
		$node    = new Feed_Source_Node();
		$sibling = $this->sibling( $node, 'feed' );

		$this->assertSame( 'ok', $sibling->dispatch( 'add_url', [ 'https://pos.test/a.xml' ] ) );
		$this->assertSame( 'ok', $sibling->dispatch( 'add_url', [ '--url=https://named.test/b.xml' ] ) );

		$this->assertSame(
			[ 'https://pos.test/a.xml', 'https://named.test/b.xml' ],
			( new \ReflectionMethod( $node, 'config' ) )->invoke( $node )['feeds']
		);
	}

	public function test_add_repo_and_github_vault_id_bind_both_ways(): void {
		$node    = new Github_Source_Node();
		$sibling = $this->sibling( $node, 'github' );

		$sibling->dispatch( 'add_repo', [ 'acme/pos-repo' ] );
		$sibling->dispatch( 'add_repo', [ '--repo=acme/named-repo' ] );
		$this->assertSame( [ 'acme/pos-repo', 'acme/named-repo' ], $this->prop( $node, 'repos' ) );

		$sibling->dispatch( 'set_vault_id', [ 'gh-pos' ] );
		$this->assertSame( 'gh-pos', $this->prop( $node, 'vault_id' ) );
		$sibling->dispatch( 'set_vault_id', [ '--vault_id=gh-named' ] );
		$this->assertSame( 'gh-named', $this->prop( $node, 'vault_id' ) );
	}

	public function test_linear_set_vault_id_binds_both_ways(): void {
		$node    = new Linear_Source_Node();
		$sibling = $this->sibling( $node, 'linear' );

		$sibling->dispatch( 'set_vault_id', [ 'lin-pos' ] );
		$this->assertSame( 'lin-pos', $this->prop( $node, 'vault_id' ) );
		$sibling->dispatch( 'set_vault_id', [ '--vault_id=lin-named' ] );
		$this->assertSame( 'lin-named', $this->prop( $node, 'vault_id' ) );
	}

	public function test_gate_verbs_bind_both_ways(): void {
		$node    = new Gate_Node();
		$sibling = $this->sibling( $node, 'gate' );

		$sibling->dispatch( 'set_config_version', [ 'csv-pos' ] );
		$this->assertSame( 'csv-pos', $this->prop( $node, 'config_version' ) );
		$sibling->dispatch( 'set_config_version', [ '--version=csv-named' ] );
		$this->assertSame( 'csv-named', $this->prop( $node, 'config_version' ) );
	}

	public function test_llm_config_verbs_bind_both_ways(): void {
		$node    = new Gate_Node();
		$sibling = $this->sibling( $node, 'gate' );

		$sibling->dispatch( 'set_api_url', [ 'https://pos.test/v9' ] );
		$this->assertSame( 'https://pos.test/v9', $this->prop( $node, 'api_url' ) );
		$sibling->dispatch( 'set_api_url', [ '--url=https://named.test/v8' ] );
		$this->assertSame( 'https://named.test/v8', $this->prop( $node, 'api_url' ) );

		$sibling->dispatch( 'set_vault_id', [ 'llm-pos' ] );
		$this->assertSame( 'llm-pos', $this->prop( $node, 'vault_id' ) );
		$sibling->dispatch( 'set_vault_id', [ '--vault_id=llm-named' ] );
		$this->assertSame( 'llm-named', $this->prop( $node, 'vault_id' ) );

		$sibling->dispatch( 'set_model', [ 'model-pos' ] );
		$this->assertSame( 'model-pos', $this->prop( $node, 'model' ) );
		$sibling->dispatch( 'set_model', [ '--model=model-named' ] );
		$this->assertSame( 'model-named', $this->prop( $node, 'model' ) );

		$sibling->dispatch( 'set_feature', [ 'feat-pos' ] );
		$this->assertSame( 'feat-pos', $this->prop( $node, 'feature' ) );
		$sibling->dispatch( 'set_feature', [ '--feature=feat-named' ] );
		$this->assertSame( 'feat-named', $this->prop( $node, 'feature' ) );
	}

	public function test_add_profile_joins_a_positional_tail_and_takes_it_by_name(): void {
		$node    = new Gate_Node();
		$sibling = $this->sibling( $node, 'gate' );

		$sibling->dispatch( 'add_profile', [ 'Cover', 'zebra', 'migrations.' ] );
		$sibling->dispatch( 'add_profile', [ '--text=Skip lunar trivia.' ] );

		$this->assertSame( [ 'Cover zebra migrations.', 'Skip lunar trivia.' ], $this->prop( $node, 'profiles' ) );
	}

	public function test_a_verb_missing_its_argument_is_refused_by_the_binder(): void {
		$node    = new Feed_Source_Node();
		$sibling = $this->sibling( $node, 'feed' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing required argument: url' );
		$sibling->dispatch( 'add_url', [] );
	}
}

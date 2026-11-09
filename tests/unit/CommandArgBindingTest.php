<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Feed_Source_Node;
use Newspack_Intelligence\Github_Source_Node;
use Newspack_Intelligence\Linear_Source_Node;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Every schema-declared command binds its tokens by position or by `--name=`
 * through the sibling `:config` interpreter, and reads them by name.
 */
final class CommandArgBindingTest extends TestCase {

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

		$this->assertSame( "ok\n", $sibling->dispatch( 'add_url', [ 'https://pos.test/a.xml' ] ) );
		$this->assertSame( "ok\n", $sibling->dispatch( 'add_url', [ '--url=https://named.test/b.xml' ] ) );

		$this->assertSame(
			[ 'https://pos.test/a.xml', 'https://named.test/b.xml' ],
			( new \ReflectionMethod( $node, 'config' ) )->invoke( $node )['feeds']
		);
	}

	public function test_add_repo_binds_by_position_and_by_name(): void {
		$node    = new Github_Source_Node();
		$sibling = $this->sibling( $node, 'github' );

		$sibling->dispatch( 'add_repo', [ 'acme/pos-repo' ] );
		$sibling->dispatch( 'add_repo', [ '--repo=acme/named-repo' ] );
		$this->assertSame( [ 'acme/pos-repo', 'acme/named-repo' ], $this->prop( $node, 'repos' ) );
	}

	public function test_add_team_binds_by_position_and_by_name(): void {
		$node    = new Linear_Source_Node();
		$sibling = $this->sibling( $node, 'linear' );

		$sibling->dispatch( 'add_team', [ 'POS7' ] );
		$sibling->dispatch( 'add_team', [ '--team=NAMED8' ] );
		$this->assertSame( [ 'POS7', 'NAMED8' ], $this->prop( $node, 'teams' ) );
	}

	public function test_the_model_call_commands_bind_both_ways(): void {
		$node    = new LLM_Call_Node();
		$sibling = $this->sibling( $node, 'intel:llm' );

		$sibling->dispatch( 'set_api_url', [ 'https://pos.example/v9' ] );
		$this->assertSame( 'https://pos.example/v9', $this->prop( $node, 'api_url' ) );
		$sibling->dispatch( 'set_api_url', [ '--url=https://named.example/v8/' ] );
		$this->assertSame( 'https://named.example/v8', $this->prop( $node, 'api_url' ) );

		$sibling->dispatch( 'set_model', [ 'model-pos' ] );
		$this->assertSame( 'model-pos', $this->prop( $node, 'model' ) );
		$sibling->dispatch( 'set_model', [ '--model=model-named' ] );
		$this->assertSame( 'model-named', $this->prop( $node, 'model' ) );

		$sibling->dispatch( 'set_feature', [ 'feat-pos' ] );
		$this->assertSame( 'feat-pos', $this->prop( $node, 'feature' ) );
		$sibling->dispatch( 'set_feature', [ '--feature=feat-named' ] );
		$this->assertSame( 'feat-named', $this->prop( $node, 'feature' ) );

		$sibling->dispatch( 'set_concurrency', [ '7' ] );
		$this->assertSame( 7, $this->prop( $node, 'concurrency' ) );
		$sibling->dispatch( 'set_concurrency', [ '--count=11' ] );
		$this->assertSame( 11, $this->prop( $node, 'concurrency' ) );
	}

	/** An api url that is not an absolute http(s) url is refused, and the one set stands. */
	public function test_a_bad_api_url_is_refused(): void {
		$node    = new LLM_Call_Node();
		$sibling = $this->sibling( $node, 'intel:llm' );
		$sibling->dispatch( 'set_api_url', [ 'https://kept.example/v1' ] );

		try {
			$sibling->dispatch( 'set_api_url', [ 'relay.example/v1' ] );
			$this->fail( 'a url with no scheme is refused' );
		} catch ( \UnexpectedValueException $e ) {
			$this->assertSame( 'invalid url relay.example/v1', $e->getMessage() );
		}
		$this->assertSame( 'https://kept.example/v1', $this->prop( $node, 'api_url' ) );
	}

	public function test_add_profile_joins_a_positional_tail_and_takes_it_by_name(): void {
		$node    = new Extractor_Node();
		$sibling = $this->sibling( $node, 'extractor' );

		$sibling->dispatch( 'add_profile', [ 'Cover', 'zebra', 'migrations.' ] );
		$sibling->dispatch( 'add_profile', [ '--text=Skip lunar trivia.' ] );

		$this->assertSame( [ 'Cover zebra migrations.', 'Skip lunar trivia.' ], $this->prop( $node, 'profiles' ) );
	}

	public function test_a_command_missing_its_argument_is_refused_by_the_binder(): void {
		$node    = new Feed_Source_Node();
		$sibling = $this->sibling( $node, 'feed' );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'missing required argument: url' );
		$sibling->dispatch( 'add_url', [] );
	}
}

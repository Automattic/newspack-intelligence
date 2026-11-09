<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Deduper_Node;
use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Intelligence\Prompts;
use Newspack_Intelligence\Resolver_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/llm-answering-sink.php';

/**
 * The extractor: one request to `intel:llm` per resolved item that links
 * something, its answer's extraction and the claims about entities the item
 * links written as events to the writer; a heuristic extraction when the
 * call cannot answer.
 */
final class ExtractorNodeTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1794100000.0;

	private const ITEM = 'feed:extract-5521';

	private const GAZETTE = 'organization:harbor-gazette';

	private const MESA = 'organization:mesa-courier';

	private const PEABODY = 'event:peabody-award';

	private const LANTERN = 'organization:kestrel-lantern-harbor';

	private const LANTERN_OLD = 'organization:lantern-harbor-weekly';

	/** The worker partition the extractor publishes its status from. */
	private const PARTITION = 4;

	private Extractor_Node $extractor;

	private Llm_Answering_Sink $out;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
		$this->out       = new Llm_Answering_Sink();
		$this->extractor = new Extractor_Node();
		$this->extractor->name( 'extractor' );
		$this->extractor->arguments( [ 'intel:writer', 'intel:later', 'intel:llm' ] );
		$this->extractor->target( 'deduper' );
		$this->extractor->sink( $this->out );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** The prompt commands are the extractor's alone: the resolver, which builds no extraction prompt, declares none. */
	public function test_only_the_extractor_declares_the_prompt_commands(): void {
		$prompt   = [ 'set_audience', 'add_profile', 'remove_profile', 'add_importance_floor', 'remove_importance_floor' ];
		$commands = \array_column( Extractor_Node::node_schema()['commands'], null, 'name' );

		$this->assertSame( $prompt, \array_keys( $commands ) );
		$this->assertSame( 'audience', $commands['set_audience']['setter'] );
		$this->assertTrue( $commands['add_profile']['multiple'] );
		$this->assertSame( 'profiles', $commands['remove_profile']['unlist'] );
		$this->assertTrue( $commands['add_importance_floor']['multiple'] );
		$this->assertTrue( $commands['remove_importance_floor']['action'] );
		$this->assertSame( [], \array_intersect( $prompt, \array_column( Resolver_Node::node_schema()['commands'], 'name' ) ) );
	}

	/** A floor lifts a kind's importance after the call; with none set the model's score stands. */
	public function test_an_importance_floor_lifts_the_kind_it_names(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->answer( '{"summary":"The Gazette cancels its plan.","kind":"churn_signal","relevance":3,"importance":2}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->assertSame( [ 2, 2 ], [ $this->written()[0]['importance'], $this->forwarded()[0]['importance'] ] );

		$this->out->captured = [];
		$this->assertSame( "ok\n", $this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'churn_signal', '8' ] ) );
		$this->assertSame( "ok\n", $this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'praise', '7' ] ) );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( [ 8, 8, 3 ], [ $this->written()[0]['importance'], $this->forwarded()[0]['importance'], $this->written()[0]['relevance'] ] );
	}

	/** A floor naming a relevance lifts only an extraction at least that relevant. */
	public function test_an_importance_floor_lifts_only_at_its_relevance(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->assertSame( "ok\n", $this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'churn_signal', '8', '5' ] ) );
		$scored = [];
		foreach ( [ 4, 5, 7 ] as $relevance ) {
			$this->out->captured = [];
			$this->answer( '{"summary":"The Gazette weighs leaving.","kind":"churn_signal","relevance":' . $relevance . ',"importance":2}' );
			$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
			$scored[] = [ $this->written()[0]['importance'], $this->forwarded()[0]['importance'] ];
		}

		$this->assertSame( [ [ 2, 2 ], [ 8, 8 ], [ 8, 8 ] ], $scored );
	}

	/** The heuristic stand-in scores relevance 0, so a floor naming a relevance leaves it. */
	public function test_the_heuristic_stand_in_misses_a_floor_naming_a_relevance(): void {
		$this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'other', '3', '1' ] );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( [ 'other', 0, 0 ], [ $this->written()[0]['kind'], $this->written()[0]['importance'], $this->forwarded()[0]['importance'] ] );
	}

	/** A floor never lowers a score above it. */
	public function test_an_importance_floor_keeps_a_higher_score(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'complaint', '6' ] );
		$this->answer( '{"summary":"Readers report a broken paywall.","kind":"complaint","importance":9}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( 9, $this->written()[0]['importance'] );
	}

	/** The heuristic stand-in, kind `other`, takes `other`'s floor too. */
	public function test_the_heuristic_stand_in_takes_its_kinds_floor(): void {
		$this->extractor->interpreter()?->dispatch( 'add_importance_floor', [ 'other', '3' ] );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( [ 'other', 3, 3 ], [ $this->written()[0]['kind'], $this->written()[0]['importance'], $this->forwarded()[0]['importance'] ] );
	}

	/** A second floor for a kind replaces the first; a removed floor no longer applies. */
	public function test_a_floor_is_replaced_and_removed_by_kind(): void {
		$node = $this->prompt_node();
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'complaint', '8' ] );
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'feature_request', '6' ] );
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'complaint', '5' ] );
		$this->assertSame( [ 'complaint' => [ 5, 0 ], 'feature_request' => [ 6, 0 ] ], $this->read_private( $node, 'importance_floors' ) );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_importance_floor', [ 'complaint' ] ) );

		$this->assertSame( [ 'feature_request' => [ 6, 0 ] ], $this->read_private( $node, 'importance_floors' ) );
	}

	/**
	 * A floor naming a kind outside `Intel_Event::ITEM_KINDS`, a min outside
	 * 0 to 9 or a relevance outside 0 to 10 is refused; so is removing a kind
	 * with no floor.
	 *
	 * @return array<string,array{0:string,1:list<string>,2:string}>
	 */
	public static function refused_floors(): array {
		return [
			'an unknown kind'      => [ 'add_importance_floor', [ 'gossip', '4' ], "add_importance_floor: 'gossip' is not an item kind" ],
			'a min above nine'     => [ 'add_importance_floor', [ 'complaint', '10' ], 'add_importance_floor: a floor is 0 to 9; got 10' ],
			'a relevance above ten' => [ 'add_importance_floor', [ 'complaint', '6', '11' ], 'add_importance_floor: a relevance is 0 to 10; got 11' ],
			'a kind with no floor' => [ 'remove_importance_floor', [ 'praise' ], "remove_importance_floor: 'praise' has no floor" ],
		];
	}

	/**
	 * @param string       $verb    The verb.
	 * @param list<string> $tokens  Its tokens.
	 * @param string       $refusal What it says.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_floors' )]
	public function test_a_bad_floor_is_refused( string $verb, array $tokens, string $refusal ): void {
		$node = $this->prompt_node();
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'launch', '4' ] );

		$caught = $this->caught_any( fn () => $node->interpreter()->dispatch( $verb, $tokens ) );

		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( $refusal, $caught->getMessage() );
		$this->assertSame( [ 'launch' => [ 4, 0 ] ], $this->read_private( $node, 'importance_floors' ) );
	}

	/** A negative min, which the command's int binding refuses first, is refused by the setter too. */
	public function test_a_negative_floor_is_refused(): void {
		$node = $this->prompt_node();

		$caught = $this->caught_any( fn () => $node->add_importance_floor( 'complaint', -1 ) );

		$this->assertSame( 'add_importance_floor: a floor is 0 to 9; got -1', $caught->getMessage() );
		$this->assertSame( [], $this->read_private( $node, 'importance_floors' ) );
	}

	/** A negative relevance, which the command's int binding refuses first, is refused by the setter too. */
	public function test_a_negative_floor_relevance_is_refused(): void {
		$node = $this->prompt_node();

		$caught = $this->caught_any( fn () => $node->add_importance_floor( 'complaint', 6, -1 ) );

		$this->assertSame( 'add_importance_floor: a relevance is 0 to 10; got -1', $caught->getMessage() );
		$this->assertSame( [], $this->read_private( $node, 'importance_floors' ) );
	}

	/** A floor may ask the highest relevance a call scores, 10, past the highest floor. */
	public function test_a_floor_may_ask_relevance_ten(): void {
		$node = $this->prompt_node();

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_importance_floor', [ 'praise', '7', '10' ] ) );

		$this->assertSame( [ 'praise' => [ 7, 10 ] ], $this->read_private( $node, 'importance_floors' ) );
	}

	/** Each floor dumps as one add line, its relevance only when not 0, and replays to the same floors. */
	public function test_floors_dump_and_replay(): void {
		$node = $this->prompt_node();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'churn_signal', '8', '5' ] );
		$node->interpreter()->dispatch( 'add_importance_floor', [ 'feature_request', '6' ] );

		$dump = $node->dump_config();

		$this->assertStringContainsString( "command_node llmnode:config add_importance_floor churn_signal 8 5\ncommand_node llmnode:config add_importance_floor feature_request 6\n", $dump );
		$replayed = $this->prompt_node();
		$this->replay_config( $dump, $replayed );
		$this->assertSame( [ 'churn_signal' => [ 8, 5 ], 'feature_request' => [ 6, 0 ] ], $this->read_private( $replayed, 'importance_floors' ) );
	}

	/** The audience a prompt is framed for is set in TSL, one quoted token, last write wins. */
	public function test_set_audience_sets_the_audience_last_write_wins(): void {
		$node = $this->prompt_node();

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_audience', [ 'the heron desk' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'set_audience', [ 'the Quarry Ledger kite desk' ] ) );

		$this->assertSame( 'the Quarry Ledger kite desk', $this->read_private( $node, 'audience' ) );
	}

	/** A set audience dumps as one quoted token and replays to the same audience. */
	public function test_a_set_audience_dumps_and_replays(): void {
		$node = $this->prompt_node();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'set_audience', [ 'the Quarry Ledger kite desk' ] );

		$dump = $node->dump_config();

		$this->assertStringContainsString( "command_node llmnode:config set_audience 'the Quarry Ledger kite desk'", $dump );
		$replayed = $this->prompt_node();
		$this->replay_config( $dump, $replayed );
		$this->assertSame( 'the Quarry Ledger kite desk', $this->read_private( $replayed, 'audience' ) );
	}

	public function test_add_profile_appends_and_joins_with_newline(): void {
		$node = $this->prompt_node();

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_profile', [ 'Engineering' ] ) );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_profile', [ 'Product' ] ) );
		$this->assertSame( "Engineering\nProduct", $this->profile_of( $node ) );
	}

	public function test_add_profile_rejects_blank_text(): void {
		$node = $this->prompt_node();

		$caught = $this->caught_any( fn () => $node->interpreter()->dispatch( 'add_profile', [ '   ' ] ) );
		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( 'usage: add_profile <text>', $caught->getMessage() );
		$this->assertSame( '', $this->profile_of( $node ) );
	}

	public function test_remove_profile_drops_it_from_the_relevance_profile(): void {
		$node = $this->prompt_node();
		$node->interpreter()->dispatch( 'add_profile', [ 'Cover', 'heron', 'migrations.' ] );
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );

		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'remove_profile', [ 'Cover', 'heron', 'migrations.' ] ) );

		$this->assertSame( 'Skip kite trivia.', $this->profile_of( $node ) );
	}

	public function test_remove_profile_naming_no_profile_is_refused_naming_it(): void {
		$node = $this->prompt_node();
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );

		$caught = $this->caught_any( fn () => $node->interpreter()->dispatch( 'remove_profile', [ '--text=Cover ghosts.' ] ) );

		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( "remove_profile 'Cover ghosts.' names a profile this node does not declare", $caught->getMessage() );
		$this->assertSame( 'Skip kite trivia.', $this->profile_of( $node ) );
	}

	public function test_a_removed_profile_leaves_the_dump_and_its_replay(): void {
		$node = $this->prompt_node();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'add_profile', [ 'Cover heron migrations.' ] );
		$node->interpreter()->dispatch( 'add_profile', [ 'Skip kite trivia.' ] );
		$node->interpreter()->dispatch( 'remove_profile', [ 'Cover heron migrations.' ] );

		$dump = $node->dump_config();
		$this->assertStringNotContainsString( 'heron', $dump );

		$replayed = $this->prompt_node();
		$this->replay_config( $dump, $replayed );
		$this->assertSame( 'Skip kite trivia.', $this->profile_of( $replayed ) );
	}

	public function test_the_add_profile_handler_delegates_to_patron(): void {
		$node = $this->prompt_node();

		$result = $node->interpreter()->commands()['add_profile']( $this->interpreter_for( $node ), [ 'text' => [ 'Engineering' ] ] );

		$this->assertSame( "ok\n", $result );
		$this->assertSame( 'Engineering', $this->profile_of( $node ) );
	}

	public function test_the_add_profile_handler_joins_all_positional_tokens(): void {
		$node = $this->prompt_node();
		$node->name( 'llmnode' );
		// An unquoted TSL line arrives as many tokens; the profile is all of them.
		$result = $node->interpreter()->commands()['add_profile'](
			$this->interpreter_for( $node ),
			[ 'text' => [ 'Do', 'not', 'produce', 'tables.' ] ]
		);
		$this->assertSame( "ok\n", $result );
		$this->assertSame( 'Do not produce tables.', $this->profile_of( $node ) );
	}

	public function test_dump_config_quotes_a_multi_word_profile(): void {
		$node = $this->prompt_node();
		$node->name( 'llmnode' );
		$node->interpreter()->dispatch( 'add_profile', [ 'Do not produce tables.' ] );
		$this->assertStringContainsString(
			"command_node llmnode:config add_profile 'Do not produce tables.'",
			$node->dump_config()
		);
	}

	public function test_the_add_profile_handler_refuses_blank_text(): void {
		$node = $this->prompt_node();

		$caught = $this->caught_any( fn () => $node->interpreter()->commands()['add_profile']( $this->interpreter_for( $node ), [ 'text' => [ '   ' ] ] ) );
		$this->assertInstanceOf( \InvalidArgumentException::class, $caught );
		$this->assertSame( 'usage: add_profile <text>', $caught->getMessage() );
		$this->assertSame( '', $this->profile_of( $node ) );
	}

	/** An extractor for the prompt commands, its interpreter wired. */
	private function prompt_node(): Extractor_Node {
		return new Extractor_Node();
	}

	/**
	 * The relevance profile an extractor holds, its lines joined.
	 *
	 * @param Extractor_Node $node The extractor.
	 */
	private function profile_of( Extractor_Node $node ): string {
		return \implode( "\n", Core::arr( $this->read_private( $node, 'profiles' ) ) );
	}

	/** What $fn threw, whatever its class; the test fails when it throws nothing. */
	private function caught_any( callable $fn ): \Throwable {
		try {
			$fn();
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( 'nothing was refused' );
	}

	/** Wire a `:config`-style interpreter whose patron is $node. */
	private function interpreter_for( Extractor_Node $node ): Command_Interpreter_Node {
		$interpreter = new Command_Interpreter_Node();
		$interpreter->patron( $node );
		return $interpreter;
	}

	/** An item with no link and no candidate costs no call and records nothing. */
	public function test_an_unlinked_item_is_not_extracted(): void {
		$this->answer( '{"summary":"Never asked."}' );

		$this->extractor->fill( self::resolved( [], [] ) );

		$this->assertSame( [], $this->out->requests );
		$this->assertSame( [], $this->out->captured );
	}

	/**
	 * The publisher an item names is the client the request asks for by
	 * name, as its survivor shows: a client lane item names its client, and
	 * one published by a key merged into a client names the survivor.
	 */
	public function test_a_client_publisher_is_named_in_the_request(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), self::entity( self::LANTERN_OLD, 'Lantern Harbor Weekly' ), self::merged( self::LANTERN_OLD, self::LANTERN ) );
		$this->answer( '{"summary":"A transit grant lands."}' );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN_OLD ) );

		$this->assertSame( [ 'Kestrel Lantern Harbor', 'Kestrel Lantern Harbor' ], \array_map( static fn ( array $request ): string => $request[ Message::VALUE ]['inputs']['publisher'], $this->out->requests ) );
	}

	/**
	 * An item a non-client published, one no entity published, as a Linear
	 * issue, and one a former client published name no publisher.
	 */
	public function test_an_item_no_open_client_published_names_no_publisher(): void {
		$this->seed(
			self::lantern(),
			self::client( self::LANTERN ),
			[ Intel_Event::make( 'role_ended', [ 'entity_key' => self::LANTERN, 'role' => 'client', 'from_ts' => 1790000000, 'to_ts' => 1793000000 ], 'user:41', 'fixture-7' ) ],
			self::entity( self::MESA, 'Mesa Courier', [ 'domain' => [ 'mesacourier.example' ] ] )
		);
		$this->answer( '{"summary":"A transit grant lands."}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'item_key' => 'feed:mesa-transit-1', 'url' => 'https://mesacourier.example/transit' ], publisher: self::MESA ) );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'item_key' => 'linear:SUP-412', 'source' => 'linear', 'url' => 'https://linear.example/SUP-412' ] ) );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ '', '', '' ], \array_map( static fn ( array $request ): string => $request[ Message::VALUE ]['inputs']['publisher'], $this->out->requests ) );
	}

	/**
	 * A client's own article the decision says is not about the client is
	 * written as other, taking no floor of the kind the model gave it; the
	 * decision is asked with the extraction's topics and publisher, no
	 * options and its backlog flag.
	 */
	public function test_a_client_article_the_decision_finds_about_someone_else_is_other(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->extractor->add_importance_floor( 'award', 7 );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","relevance":6,"importance":3}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), extract: true, publisher: self::LANTERN ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( [ true, true ], \array_column( $asked, 'backlog' ) );
		$this->assertSame( [ [], [ 'topics' => $asked[0]['inputs']['topics'], 'publisher' => 'Kestrel Lantern Harbor', 'themes' => [], 'summary' => 'Mesa Courier wins a transit grant.', 'kind' => 'award' ] ], [ $asked[1]['options'], $asked[1]['inputs'] ] );
		$this->assertSame( [ 'about_publisher' ], \array_keys( Prompts::questions( $asked[1]['inputs'] ) ), 'an item offered no theme is asked no theme question' );
		$this->assertSame( [ [ 'other', 3 ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['importance'] ], $this->extractions() ) );
		$this->assertSame( [ 3 ], \array_column( $this->forwarded(), 'importance' ) );
	}

	/** A client's article the decision says is about the client keeps its kind and that kind's floor. */
	public function test_a_client_article_the_decision_finds_about_itself_keeps_its_kind(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->extractor->add_importance_floor( 'award', 7 );
		$this->answer( '{"summary":"Kestrel Lantern Harbor wins a press award.","kind":"award","relevance":6,"importance":3}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.83 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ [ 'award', 7 ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['importance'] ], $this->extractions() ) );
		$this->assertSame( [ false, false ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'backlog' ) );
	}

	/**
	 * A decision that fails, the group lending nothing or a noul that does
	 * not read keeps the model's kind, logged, and parks no retry.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function failed_decisions(): array {
		return [
			'a bad gateway'      => [ [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] ],
			'an unlent group'    => [ [ 'unlent' => 'ai-proxy-test-9' ] ],
			'a noul not a float' => [ [ 'decision' => [ 'about_publisher' => 'high' ] ] ],
		];
	}

	/** @param array<string,mixed> $outcome What the decision answered. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failed_decisions' )]
	public function test_a_failed_decision_keeps_the_kind_and_parks_nothing( array $outcome ): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","relevance":6,"importance":3}' );
		$this->decide( $outcome );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'award' ], \array_column( $this->extractions(), 'kind' ) );
		$this->assertSame( [], $this->parked() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'extract review failed: ' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'feed:klharbor-transit-77' ) );
	}

	/** No decision is asked for an item no open client published, one extracted as other, or a call that failed. */
	public function test_no_decision_is_asked_without_a_client_publisher_or_a_kind_to_doubt(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );

		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'item_key' => 'linear:SUP-412', 'source' => 'linear', 'url' => 'https://linear.example/SUP-412' ] ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"other"}' );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->answer( 'throw' );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract', 'extract', 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'award', 'other', 'other' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/** A decision's answer whose extraction throws is logged and parks a retry, as any answer's does. */
	public function test_a_decision_answer_that_throws_parks_a_retry(): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );
		$this->writer_throws( new \RuntimeException( 'writer full 4471' ) );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), attempt: 2, publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 3 ], \array_column( $this->parked(), 'attempt' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'an answer failed for feed:klharbor-transit-77: writer full 4471' ) );
	}

	/**
	 * A theme choice, its confidence, and the offered theme it writes by its
	 * place, null for the reply's own.
	 *
	 * @return array<string,array{0:string,1:float,2:int|null}>
	 */
	public static function theme_choices(): array {
		return [
			'a theme chosen surely'   => [ 't2', 0.83, 1 ],
			'a theme chosen weakly'   => [ 't2', 0.41, null ],
			'none of the themes'      => [ 'none', 0.95, null ],
		];
	}

	/**
	 * An offered theme Lux chooses at THEME_CHOICE_MIN or more is the theme
	 * written; a weaker choice, or none, keeps the reply's own. An item
	 * without a client publisher is asked the theme choice alone.
	 *
	 * @param int|null $offered The offered theme written, by its place; null for the reply's theme.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'theme_choices' )]
	public function test_the_theme_choice_picks_an_offered_theme_when_sure( string $choice, float $confidence, ?int $offered ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","theme":"grant step checklist"}' );
		$this->decide( [ 'decision' => [ 'theme_choice' => [ 'choice' => $choice, 'confidence' => $confidence ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ] ) );

		$review = $this->out->requests[1][ Message::VALUE ]['inputs'];
		$this->assertSame( [ 'transit funding round', 'regional transit grant' ], \array_column( $review['themes'], 'theme' ), 'the offered themes, the best title match first' );
		$this->assertSame( [ 'theme_choice' ], \array_keys( Prompts::questions( $review ) ) );
		$this->assertSame( [ null === $offered ? 'grant step checklist' : $review['themes'][ $offered ]['theme'] ], \array_column( $this->extractions(), 'theme' ) );
		$this->assertSame( 0.6, Extractor_Node::THEME_CHOICE_MIN );
	}

	/** A client's article offered themes is asked both questions in one review, and both answers are taken. */
	public function test_one_review_asks_both_questions_of_a_client_article(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","theme":"grant step checklist"}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.2, 'theme_choice' => [ 'choice' => 't1', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'about_publisher', 'theme_choice' ], \array_keys( Prompts::questions( $this->out->requests[1][ Message::VALUE ]['inputs'] ) ) );
		$this->assertSame( [ [ 'other', 'transit funding round' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
	}

	/** A restricted item is never sent to the decision model, whoever published it and whatever themes are offered. */
	public function test_a_restricted_item_asks_no_decision(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","theme":"grant step checklist"}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.2, 'theme_choice' => [ 'choice' => 't1', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'tier' => 'restricted' ] + self::lane_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ [ 'award', 'grant step checklist' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
	}

	/**
	 * A client's own feed item the gate finds about someone else spends no
	 * chat call: one decision asks about_publisher of the item and its
	 * publisher alone, and the heuristic stand-in is written under the gated
	 * stage, naming no subjects.
	 */
	public function test_a_client_lane_item_about_others_spends_no_chat_call(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Never asked.","kind":"award","relevance":6,"importance":3}' );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.2 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), extract: true, publisher: self::LANTERN ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract_gate' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( [ [ 'publisher' => 'Kestrel Lantern Harbor' ], [], true ], [ $asked[0]['inputs'], $asked[0]['options'], $asked[0]['backlog'] ] );
		$this->assertSame( [ 'extraction_recorded' ], \array_column( $this->written(), 'event' ) );
		$this->assertSame( [ [ 'other', 0, 0, 'Mesa Courier wins a transit grant. The port series won.' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['relevance'], $e['importance'], $e['summary'] ], $this->extractions() ) );
		$this->assertSame( 'machine:extract-gated@', Intel_Event::machine_prefix( Extractor_Node::GATED_STAGE ) );
		$this->assertStringStartsWith( 'machine:extract-gated@', $this->extractions()[0]['actor'] );
		$this->assertSame( [ 0 ], \array_column( $this->forwarded(), 'importance' ) );
		$this->assertSame( [], $this->parked() );
	}

	/**
	 * One the gate finds about the publisher itself is extracted by the chat
	 * model, its prompt naming the publisher, and its review does not ask
	 * about_publisher again.
	 */
	public function test_a_client_lane_item_about_itself_is_extracted(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Kestrel Lantern Harbor launches its app.","kind":"launch","relevance":6,"importance":3}' );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.83 ] ] );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.91 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), publisher: self::LANTERN ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract_gate', 'extract', 'extract_review' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( 'Kestrel Lantern Harbor', $asked[1]['inputs']['publisher'] );
		$this->assertSame( [ 'fits_kind' ], \array_keys( Prompts::questions( $asked[2]['inputs'] ) ) );
		$this->assertSame( [ [ 'launch', 3 ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['importance'] ], $this->extractions() ) );
		$this->assertStringStartsWith( 'machine:extract@', $this->extractions()[0]['actor'] );
	}

	/**
	 * A client-lane item a call already extracted is not gated: it is
	 * extracted again by the chat model, its review asking about_publisher,
	 * so a stand-in never stands in for an extraction it holds.
	 */
	public function test_a_client_lane_item_a_call_extracted_is_not_gated(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Kestrel Lantern Harbor wins a press award.","kind":"award","relevance":6,"importance":4}' );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.2 ] ] );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.83 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), extracted: true, extract: true, publisher: self::LANTERN ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( [ 'about_publisher' ], \array_keys( Prompts::questions( $asked[1]['inputs'] ) ) );
		$this->assertSame( [ [ 'award', 6, 4 ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['relevance'], $e['importance'] ], $this->extractions() ) );
		$this->assertStringStartsWith( 'machine:extract@', $this->extractions()[0]['actor'] );
		$this->assertSame( [ [ 6, 4 ] ], \array_map( static fn ( array $f ): array => [ $f['relevance'], $f['importance'] ], $this->forwarded() ) );
	}

	/**
	 * A configured feed's item is never gated, a guid escaped because it
	 * reads as a lane included: its review asks about_publisher instead.
	 */
	public function test_a_configured_feed_item_is_not_gated(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.2 ] ] );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'item_key' => 'feed::klharbor.example:transit-79' ] + self::lane_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract', 'extract_review', 'extract', 'extract_review' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'other', 'other' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/** A restricted client-lane item is never gated, so it never reaches the decision model. */
	public function test_a_restricted_feed_item_is_not_gated_and_never_asked(): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.2 ] ] );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'tier' => 'restricted' ] + self::lane_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'award' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/**
	 * A gate that fails, the group lending nothing or a noul that does not
	 * read, and why it is logged.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function failed_gates(): array {
		return [
			'a bad gateway'      => [ [ 'error' => 'HTTP 502 https://proxy.example/systemone' ], 'HTTP 502 https://proxy.example/systemone' ],
			'an unlent group'    => [ [ 'unlent' => 'ai-proxy-test-9' ], 'the ai-proxy-test-9 Vault group lends no credential' ],
			'an unset transport' => [ [ 'unlent' => 'ai-proxy-test-9', 'unset' => [ 'set_decision_model' ] ], 'the model call lacks set_decision_model' ],
			'a noul not a float' => [ [ 'decision' => [ 'about_publisher' => 'high' ] ], 'it answers a question with nothing that reads' ],
		];
	}

	/**
	 * A gate that fails proceeds to the chat extraction, logged once, and
	 * the review then asks about_publisher itself.
	 *
	 * @param array<string,mixed> $outcome What the gate answered.
	 * @param string              $why     The failure logged.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failed_gates' )]
	public function test_a_failed_gate_proceeds_to_the_extraction( array $outcome, string $why ): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->gate( $outcome );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), publisher: self::LANTERN ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract_gate', 'extract', 'extract_review' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( [ 'about_publisher' ], \array_keys( Prompts::questions( $asked[2]['inputs'] ) ) );
		$this->assertSame( [ 'other' ], \array_column( $this->extractions(), 'kind' ) );
		$this->assertSame( [], $this->parked() );
		$this->assertCount( 1, self::stderr_lines( $stderr, "extract gate failed: {$why} for feed:klharbor.example:transit-77" ) );
	}

	/** A gate that fails publishes its text as last_error, as a review does, until a decision answers. */
	public function test_a_gate_outage_reads_as_failing_until_a_decision_answers(): void {
		$this->capture_stderr();
		$this->publishing( null );
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Kestrel Lantern Harbor names an editor.","kind":"other"}' );

		Core::$now = 8100.0;
		$this->gate( [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), publisher: self::LANTERN ) );
		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 8100, 'last_error' => 'HTTP 502 https://proxy.example/systemone', 'failing_since' => 8100 ] ), $this->published() );

		Core::$now = 8300.0;
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.83 ] ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'item_key' => 'feed:klharbor.example:transit-78' ] + self::lane_item(), publisher: self::LANTERN ) );
		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 8300 ] ), $this->published() );
	}

	/** A gated answer whose stand-in write throws is logged and parks a retry, as any answer's does. */
	public function test_a_gated_answer_that_throws_parks_a_retry(): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->gate( [ 'decision' => [ 'about_publisher' => 0.2 ] ] );
		$this->writer_throws( new \RuntimeException( 'writer full 4472' ) );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::lane_item(), attempt: 1, publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract_gate' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 2 ], \array_column( $this->parked(), 'attempt' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'an answer failed for feed:klharbor.example:transit-77: writer full 4472' ) );
	}

	/**
	 * A `fits_kind` below its kind's bar, and a kind of `launch` below it
	 * alone, writes the kind as `other`, the floor of `launch` not lifting
	 * it; at the bar or more the kind stands, with its floor.
	 *
	 * @return array<string,array{0:float,1:string,2:int}>
	 */
	public static function kind_fits(): array {
		return [
			'a checklist'    => [ 0.12, 'other', 3 ],
			'a real launch'  => [ 0.83, 'launch', 7 ],
			'on the boundary' => [ Prompts::KIND_CHECKS['launch']['min'], 'launch', 7 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'kind_fits' )]
	public function test_a_launch_stands_only_when_it_fits_kind( float $fits_kind, string $kind, int $importance ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->extractor->add_importance_floor( 'launch', 7 );
		$this->answer( '{"summary":"Mesa Courier site launch checklist.","kind":"launch","relevance":6,"importance":3,"theme":"community site launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => $fits_kind ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ [ $kind, $importance, 'community site launch' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['importance'], $e['theme'] ], $this->extractions() ) );
	}

	/**
	 * Each checked kind is held to its own bar, so a funding item reading
	 * as a reader appeal drops to `other` as a checklist does.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function checked_kinds(): array {
		return \array_map( static fn ( string $kind ): array => [ $kind ], \array_combine( \array_keys( Prompts::KIND_CHECKS ), \array_keys( Prompts::KIND_CHECKS ) ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'checked_kinds' )]
	public function test_each_checked_kind_falls_to_other_below_its_bar( string $kind ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier asks its readers for support.","kind":"' . $kind . '"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => Prompts::KIND_CHECKS[ $kind ]['min'] - 0.01 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ 'other' ], \array_column( $this->extractions(), 'kind' ) );
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'checked_kinds' )]
	public function test_each_checked_kind_stands_at_its_bar( string $kind ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a grant.","kind":"' . $kind . '"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => Prompts::KIND_CHECKS[ $kind ]['min'] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ $kind ], \array_column( $this->extractions(), 'kind' ) );
	}

	/** A release candidate is not a final release: its review answers low, and it writes `other`. */
	public function test_a_release_candidate_falls_to_other(): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"plover-sdk tags a release candidate.","kind":"release"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'source' => 'github', 'title' => 'plover-sdk 3.1.0-rc.2' ] ) );

		$this->assertSame( [ 'other' ], \array_column( $this->extractions(), 'kind' ) );
		$this->assertSame( [ 'fits_kind' ], \array_keys( Prompts::questions( $this->out->requests[1][ Message::VALUE ]['inputs'] ) ) );
	}

	/** A launch with no client publisher and no offered theme is reviewed all the same, asked `fits_kind` alone. */
	public function test_a_launch_owes_a_review_of_its_own(): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier goes live.","kind":"launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.91 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$asked = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( $asked, 'prompt' ) );
		$this->assertSame( [ 'fits_kind' ], \array_keys( Prompts::questions( $asked[1]['inputs'] ) ) );
	}

	/** A kind KIND_CHECKS does not name is not asked `fits_kind`, whatever else its review asks. */
	public function test_another_kind_is_not_asked_whether_it_fits_kind(): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->decide( [ 'decision' => [ 'theme_choice' => [ 'choice' => 'none', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ] ) );

		$this->assertSame( [ 'theme_choice' ], \array_keys( Prompts::questions( $this->out->requests[1][ Message::VALUE ]['inputs'] ) ) );
		$this->assertSame( [ 'award' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/**
	 * A client's launch offered themes asks all three questions in one
	 * request, and takes each answer: either noul below its bar writes
	 * `other`, and the theme choice stands either way.
	 *
	 * @return array<string,array{0:float,1:float,2:string}>
	 */
	public static function three_answers(): array {
		return [
			'not about the client' => [ 0.2, 0.9, 'other' ],
			'not gone live'        => [ 0.9, 0.2, 'other' ],
			'both clear'           => [ 0.9, 0.9, 'launch' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'three_answers' )]
	public function test_a_clients_launch_asks_three_questions_in_one_request( float $about, float $fits_kind, string $kind ): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"launch","theme":"grant step checklist"}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => $about, 'fits_kind' => $fits_kind, 'theme_choice' => [ 'choice' => 't1', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'about_publisher', 'fits_kind', 'theme_choice' ], \array_keys( Prompts::questions( $this->out->requests[1][ Message::VALUE ]['inputs'] ) ) );
		$this->assertSame( [ [ $kind, 'transit funding round' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
	}

	/**
	 * A `fits_kind` missing or not a number fails the review, which keeps the
	 * reply's kind and theme, logged, and parks no retry.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function bad_kind_fits(): array {
		return [
			'no answer'    => [ [] ],
			'not a number' => [ [ 'fits_kind' => 'high' ] ],
		];
	}

	/** @param array<string,mixed> $decision What the review answered. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_kind_fits' )]
	public function test_a_bad_fits_kind_fails_the_review_and_keeps_the_kind( array $decision ): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier site launch checklist.","kind":"launch","theme":"community site launch"}' );
		$this->decide( [ 'decision' => $decision ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ [ 'launch', 'community site launch' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
		$this->assertSame( [], $this->parked() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'extract review failed: ' ) );
	}

	/** A restricted launch asks no decision. */
	public function test_a_restricted_launch_asks_no_decision(): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier goes live.","kind":"launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'tier' => 'restricted' ] ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'launch' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/** A review that fails keeps both the reply's kind and its theme. */
	public function test_a_failed_review_keeps_the_kind_and_the_theme(): void {
		$this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","theme":"grant step checklist"}' );
		$this->decide( [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertSame( [ [ 'award', 'grant step checklist' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
	}

	/**
	 * A review that asks `fits_kind` records its answer on the extraction:
	 * the kind it checked, `passed` at its bar or more and `failed` below,
	 * and the second it answered.
	 *
	 * @return array<string,array{0:float,1:string,2:string}>
	 */
	public static function kind_checks(): array {
		return [
			'fits'         => [ 0.83, 'launch', Intel_Event::PASSED ],
			'on the bar'   => [ Prompts::KIND_CHECKS['launch']['min'], 'launch', Intel_Event::PASSED ],
			'does not fit' => [ 0.12, 'other', Intel_Event::FAILED ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'kind_checks' )]
	public function test_a_kind_check_records_the_kind_checked_its_verdict_and_when( float $fits_kind, string $kind, string $verdict ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier goes live.","kind":"launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => $fits_kind ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ [ $kind, [ 'kind' => 'launch', 'verdict' => $verdict, 'ts' => (int) self::NOW ] ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ], $this->extractions() ) );
	}

	/** An extraction its review never asked `fits_kind`, whose review failed, or that a stand-in records carries no kind check. */
	public function test_an_extraction_never_checked_carries_no_kind_check(): void {
		$this->capture_stderr();
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->decide( [ 'decision' => [ 'theme_choice' => [ 'choice' => 'none', 'confidence' => 0.9 ] ] ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ] ) );
		$this->answer( '{"summary":"Mesa Courier goes live.","kind":"launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 'high' ] ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );
		$this->answer( 'throw' );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [] ) );

		$this->assertSame( [ 'award', 'launch', 'other' ], \array_column( $this->extractions(), 'kind' ) );
		$this->assertSame( [], \array_column( $this->extractions(), 'kind_check' ) );
	}

	/**
	 * A re-extraction keeps the stored kind when that kind passed its check,
	 * carrying the stored check; a stored kind whose check of that very kind
	 * failed, one never checked, or one that is not the kind its check
	 * passed is replaced, so the verdict and the kind each refuse alone.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>|null,2:string,3:array<string,mixed>|null}>
	 */
	public static function stored_kinds(): array {
		$at = 1794060000;
		return [
			'passed'                  => [ 'release', [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => $at ], 'release', [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => $at ] ],
			'failed'                  => [ 'release', [ 'kind' => 'release', 'verdict' => Intel_Event::FAILED, 'ts' => $at ], 'research', null ],
			'never checked'           => [ 'award', null, 'research', null ],
			'passed, kind not stored' => [ 'other', [ 'kind' => 'launch', 'verdict' => Intel_Event::PASSED, 'ts' => $at ], 'research', null ],
		];
	}

	/**
	 * @param string                   $stored  The kind the projection holds.
	 * @param array<string,mixed>|null $check   Its stored kind check.
	 * @param string                   $kind    The kind written.
	 * @param array<string,mixed>|null $written The kind check written.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stored_kinds' )]
	public function test_a_reextraction_keeps_a_kind_that_passed_its_check( string $stored, ?array $check, string $kind, ?array $written ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( $stored, $check ) );
		$this->extractor->add_importance_floor( 'release', 6 );
		$this->answer( '{"summary":"Mesa Courier publishes a readership study.","kind":"research","relevance":5,"importance":2}' );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], extracted: true, extract: true ) );

		$this->assertSame( [ [ $kind, $written ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ?? null ], $this->extractions() ) );
		$this->assertSame( [ 'release' === $kind ? 6 : 2 ], \array_column( $this->forwarded(), 'importance' ), 'the floor is the kept kind\'s' );
	}

	/** A reply whose stored kind passed its check, owed no other question, sends no review, and the stored kind and check are recorded. */
	public function test_a_settled_kind_with_no_other_question_sends_no_review(): void {
		$stored = [ 'kind' => 'launch', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ];
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'launch', $stored ) );
		$this->answer( '{"summary":"Mesa Courier site launch checklist.","kind":"launch"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.12 ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], extracted: true, extract: true ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ [ 'launch', $stored ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ], $this->extractions() ) );
	}

	/**
	 * The accepted window: a stored check read as passed before the review
	 * is built skips its questions, and when the record-time read then
	 * finds no check, here a projection that will not read, the reply's
	 * own kind is recorded unreviewed and unchecked until the next
	 * re-extraction asks again.
	 */
	public function test_a_check_lost_between_the_reads_records_the_reply_unchecked(): void {
		$this->capture_stderr();
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'release', [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ] ) );
		Entity_Index::current();
		$reads                             = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$reads ): mixed {
			return 1 === ++$reads ? Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read ) : throw new Table_Unavailable( 'intel-graph p0 gone 6614' );
		};
		$this->answer( '{"summary":"Mesa Courier goes live.","kind":"launch"}' );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], extracted: true, extract: true ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ), 'the passed read skipped the review' );
		$this->assertSame( [ [ 'launch', null ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ?? null ], $this->extractions() ) );
	}

	/** A stored check the projection will not answer keeps nothing: the reply's kind is written, the read logged once. */
	public function test_a_stored_check_the_projection_will_not_read_is_replaced(): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'release', [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ] ) );
		Entity_Index::current();
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): never => throw new Table_Unavailable( 'intel-graph p0 gone 6613' );
		$this->answer( '{"summary":"Mesa Courier publishes a readership study.","kind":"research","relevance":5,"importance":2}' );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], extracted: true, extract: true ) );

		$this->assertSame( [ [ 'research', null ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ?? null ], $this->extractions() ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to read the stored kind check from; replacing the kind: intel-graph p0 gone 6613' ) );
	}

	/**
	 * A review of an item whose stored kind passed its check asks no
	 * `fits_kind`, since the keep decides the kind; without one stored it
	 * asks it as before, beside the theme choice.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:list<string>}>
	 */
	public static function settled_kinds(): array {
		return [
			'passed before' => [ [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ], [ 'theme_choice' ] ],
			'never checked' => [ null, [ 'fits_kind', 'theme_choice' ] ],
		];
	}

	/**
	 * @param array<string,mixed>|null $check The stored kind check.
	 * @param list<string>             $asked The questions the sent review asks.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'settled_kinds' )]
	public function test_a_kind_that_passed_its_check_is_not_asked_whether_it_fits( ?array $check, array $asked ): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'release', $check ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier ships plover-sdk 3.1 for its transit grant.","kind":"release"}' );
		$this->decide( [ 'decision' => [ 'fits_kind' => 0.91, 'theme_choice' => [ 'choice' => 'none', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ], extracted: true, extract: true ) );

		$sent = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( $sent, 'prompt' ) );
		$this->assertSame( $asked, \array_keys( Prompts::questions( $sent[1]['inputs'] ) ) );
		$this->assertSame( [ 'release' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/**
	 * A client's article whose stored kind passed its check is asked only
	 * the theme choice: `about_publisher` and `fits_kind` decide the kind
	 * alone, which the keep fixes; without one stored it is asked all three.
	 *
	 * @return array<string,array{0:array<string,mixed>|null,1:list<string>}>
	 */
	public static function settled_client_kinds(): array {
		return [
			'passed before' => [ [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ], [ 'theme_choice' ] ],
			'never checked' => [ null, [ 'about_publisher', 'fits_kind', 'theme_choice' ] ],
		];
	}

	/**
	 * @param array<string,mixed>|null $check The stored kind check.
	 * @param list<string>             $asked The questions the sent review asks.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'settled_client_kinds' )]
	public function test_a_client_article_whose_kind_passed_is_asked_only_its_theme( ?array $check, array $asked ): void {
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'release', $check, self::published_item() ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier ships plover-sdk 3.1 for its transit grant.","kind":"release"}' );
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.9, 'fits_kind' => 0.91, 'theme_choice' => [ 'choice' => 'none', 'confidence' => 0.9 ] ] ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), extracted: true, extract: true, publisher: self::LANTERN ) );

		$sent = \array_column( $this->out->requests, Message::VALUE );
		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( $sent, 'prompt' ) );
		$this->assertSame( $asked, \array_keys( Prompts::questions( $sent[1]['inputs'] ) ) );
		$this->assertSame( [ 'release' ], \array_column( $this->extractions(), 'kind' ) );
	}

	/** A review that fails, here its theme choice, keeps a stored kind that passed its check, with the stored check, as a review that answers does. */
	public function test_a_failed_review_keeps_a_kind_that_passed_its_check(): void {
		$this->capture_stderr();
		$stored = [ 'kind' => 'release', 'verdict' => Intel_Event::PASSED, 'ts' => 1794060000 ];
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), self::stored( 'release', $stored ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier site launch checklist.","kind":"launch"}' );
		$this->decide( [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ], extracted: true, extract: true ) );

		$this->assertSame( [ 'extract', 'extract_review' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ [ 'release', $stored ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['kind_check'] ], $this->extractions() ) );
	}

	/**
	 * The fixture item's record and a call's extraction of it, of `$kind`,
	 * carrying `$check` when one is given.
	 *
	 * @param string                   $kind  The stored kind.
	 * @param array<string,mixed>|null $check The stored kind check.
	 * @param array<string,mixed>      $over  Item fields to set.
	 * @return list<array<string,mixed>>
	 */
	private static function stored( string $kind, ?array $check, array $over = [] ): array {
		$item = \array_merge( self::item(), $over );
		return [
			Intel_Event::make( 'item_recorded', $item, 'machine:source:feed@9.4.1', '' ),
			Intel_Event::make( 'extraction_recorded', [ 'item_key' => $item['item_key'], 'summary' => 'Mesa Courier, earlier.', 'kind' => $kind, 'relevance' => 4, 'importance' => 3, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => $item['retrieved_ts'] ] + ( null === $check ? [] : [ 'kind_check' => $check ] ), 'machine:extract@9.4.1', '' ),
		];
	}

	/**
	 * A stop falling due while an answer finishes splits nothing: every write
	 * of the extraction lands, and the stop raises at the next check.
	 */
	public function test_a_stop_due_during_a_finish_lets_every_write_land(): void {
		$this->seed( self::entity( self::GAZETTE, 'Harbor Gazette' ), self::entity( self::PEABODY, 'Peabody Award' ) );
		$this->answer( '{"summary":"Harbor Gazette wins.","claims":[{"subject":"Harbor Gazette","predicate":"won","object":"Peabody Award"}]}' );
		$this->writes_check_for_a_stop();

		$stop = $this->with_stop_due(
			function (): void {
				$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
				Event_Framework::instance()->stop_check();
			}
		);

		$this->assertNotNull( $stop, 'the stop raises once the finish closes' );
		$this->assertSame( [ 'extraction_recorded', 'subjects_extracted', 'claim_asserted' ], \array_column( $this->written(), 'event' ) );
		$this->assertCount( 1, $this->forwarded() );
	}

	/**
	 * A read that throws starting the review, or a request that does, costs
	 * the review alone: the paid reply is recorded, logged, and no retry
	 * parks. The themes read alone throws, so the record reads as it would.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function review_starts(): array {
		return [
			'the themes read throws'   => [ 'read' ],
			'the review request throws' => [ 'enqueue' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'review_starts' )]
	public function test_a_review_that_will_not_start_records_the_paid_reply( string $fails ): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award","theme":"grant step checklist"}' );
		Entity_Index::current();
		if ( 'read' === $fails ) {
			Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ): never {
				Intel_Graph_Row_Schema::$read_rows = null;
				throw new \RuntimeException( 'graph file gone 7741' );
			};
		} else {
			$this->review_requests_throw( new \RuntimeException( 'frontier refused 7742' ) );
		}

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ] ) );

		$this->assertSame( [ [ 'award', 'grant step checklist' ] ], \array_map( static fn ( array $e ): array => [ $e['kind'], $e['theme'] ], $this->extractions() ) );
		$this->assertSame( [], $this->parked() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'extract review failed: ' . ( 'read' === $fails ? 'graph file gone 7741' : 'frontier refused 7742' ) ) );
	}

	/** A cooperative stop while a review starts propagates, and records nothing. */
	public function test_a_stop_while_a_review_starts_propagates(): void {
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ), ...self::themed_neighbours() );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->review_requests_throw( new Worker_Should_Stop( 'max_runtime 595 reached' ) );

		try {
			$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'title' => 'Mesa Courier wins a transit grant' ] ) );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( [], $this->extractions() );
	}

	/**
	 * A review that fails publishes its text as last_error, keeping the mode
	 * and last_ok_at; an extraction not followed by a review leaves it standing,
	 * and a review that answers clears it.
	 */
	public function test_a_review_outage_reads_as_failing_until_a_review_answers(): void {
		$this->publishing( null );
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$failing = self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 8100, 'last_error' => 'HTTP 502 https://proxy.example/systemone', 'failing_since' => 8100 ] );

		Core::$now = 8100.0;
		$this->decide( [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->assertSame( $failing, $this->published() );

		Core::$now = 8200.0;
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'item_key' => 'linear:SUP-413', 'source' => 'linear', 'url' => 'https://linear.example/SUP-413' ] ) );
		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 8200, 'last_error' => 'HTTP 502 https://proxy.example/systemone', 'failing_since' => 8100 ] ), $this->published(), 'a reply no review follows leaves the review failure standing' );

		Core::$now = 8300.0;
		$this->decide( [ 'decision' => [ 'about_publisher' => 0.83 ] ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 8300 ] ), $this->published() );
	}

	/** A review failure's log line is limited by the failure, so two items failing alike log once and a new failure logs again. */
	public function test_a_review_failure_logs_once_per_failure(): void {
		$stderr = $this->capture_stderr();
		$this->seed( self::lantern(), self::client( self::LANTERN ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer( '{"summary":"Mesa Courier wins a transit grant.","kind":"award"}' );
		$this->decide( [ 'error' => 'HTTP 502 https://proxy.example/systemone' ] );

		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], [ 'item_key' => 'feed:klharbor-transit-78' ] + self::published_item(), publisher: self::LANTERN ) );
		$this->decide( [ 'error' => 'HTTP 504 https://proxy.example/systemone' ] );
		$this->extractor->fill( self::resolved( [ self::link( self::MESA, 1.0 ) ], [], self::published_item(), publisher: self::LANTERN ) );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'extract review failed: HTTP 502' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'extract review failed: HTTP 504' ) );
	}

	/** An extraction is asked at temperature 0, so a re-run reads the item the same way. */
	public function test_extraction_asks_at_temperature_zero(): void {
		$this->answer( '{"summary":"Harbor Gazette wins a Peabody.","kind":"award"}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( 0.0, $this->out->requests[0][ Message::VALUE ]['options']['temperature'] );
	}

	/** A re-extraction asked for is never skipped: an item its links left is still called, and written. */
	public function test_an_unlinked_item_owed_an_extraction_is_asked(): void {
		$this->answer( '{"summary":"Tern Weekly closes its print edition.","kind":"hire"}' );

		$this->extractor->fill( self::resolved( [], [], extracted: true, extract: true ) );

		$this->assertSame( [ 'extract' ], \array_column( \array_column( $this->out->requests, Message::VALUE ), 'prompt' ) );
		$this->assertSame( [ 'hire' ], \array_column( \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'extraction_recorded' === $e['event'] ) ), 'kind' ) );
	}

	/** The extraction names topic keys; each claim's subject and object resolve against the index. */
	public function test_a_linked_item_records_its_extraction_and_resolved_claims(): void {
		$this->seed(
			self::entity( self::GAZETTE, 'The Harbor Gazette' ),
			self::entity( self::PEABODY, 'Peabody Award' ),
			self::entity( 'topic:awards', 'Awards', [ 'alias' => [ 'prizes' ] ] ),
			self::entity( 'topic:podcasts', 'Podcasts' )
		);
		$this->extractor->interpreter()?->dispatch( 'add_profile', [ 'Quarry Ledger readers care about prizes.' ] );
		$this->extractor->interpreter()?->dispatch( 'set_audience', [ 'the Quarry Ledger kite desk' ] );
		$this->answer(
			(string) \json_encode(
				[
					'summary'    => 'The Harbor Gazette won a Peabody for its port series.',
					'kind'       => 'award',
					'topics'     => [ 'Awards', 'Weather' ],
					'relevance'  => 8,
					'importance' => 6,
					'sentiment'  => 0.6,
					'claims'     => [
						[ 'subject' => 'Harbor Gazette', 'predicate' => 'won', 'object' => 'Peabody Award' ],
						[ 'subject' => 'The Harbor Gazette', 'predicate' => 'located_in', 'object' => 'Port Angeles' ],
					],
				]
			)
		);

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 0.9 ) ], [] ) );

		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		$events  = $this->written();
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
			$this->assertSame( self::ITEM, $event['item_key'] );
			$this->assertSame( "machine:extract@{$version}", $event['actor'] );
			$this->assertSame( 1794050000, $event['retrieved_ts'], 'an extraction and its claims carry the item\'s retrieval' );
			$this->assertSame( $version, $event['config_version'] );
		}
		$this->assertSame( [ 'extraction_recorded', 'subjects_extracted', 'claim_asserted', 'claim_asserted' ], \array_column( $events, 'event' ) );
		$this->assertSame( [ self::GAZETTE ], $events[1]['entity_keys'], 'the extraction names the entities it was given asserted' );
		$this->assertSame( [ [ 'event' => 'item_extracted', 'item' => self::item(), 'relevance' => 8, 'importance' => 6 ] ], $this->forwarded() );
		$this->assertSame(
			[ 'The Harbor Gazette won a Peabody for its port series.', 'award', 8, 6, 0.6, [ 'topic:awards' ] ],
			[ $events[0]['summary'], $events[0]['kind'], $events[0]['relevance'], $events[0]['importance'], $events[0]['sentiment'], $events[0]['topics'] ]
		);
		$this->assertSame(
			[
				[ 0, self::GAZETTE, 'won', self::PEABODY, 'Peabody Award', 0.9 ],
				[ 1, self::GAZETTE, 'located_in', '', 'Port Angeles', 0.9 ],
			],
			\array_map( static fn ( array $e ): array => [ $e['ord'], $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'], $e['confidence'] ], \array_slice( $events, 2 ) )
		);
		$this->assertCount( 1, $this->out->requests );
		$request = $this->out->requests[0];
		$this->assertSame( [ Message::TM_STRUCT, 'extractor', self::ITEM ], [ $request[ Message::TYPE ], $request[ Message::FROM ], $request[ Message::KEY ] ] );
		$this->assertSame( [ 'extract', self::item(), [ 'max_tokens' => 800, 'temperature' => 0.0 ], false ], [ $request[ Message::VALUE ]['prompt'], $request[ Message::VALUE ]['item'], $request[ Message::VALUE ]['options'], $request[ Message::VALUE ]['backlog'] ] );
		$this->assertArrayNotHasKey( 'item', $request[ Message::VALUE ]['carry']['resolution'], 'the item rides once, beside the carry' );
		$this->assertSame( [ 'Awards' => 'topic:awards', 'Podcasts' => 'topic:podcasts' ], $request[ Message::VALUE ]['inputs']['topics'] );
		$this->assertSame( [ 'resolution' ], \array_keys( $request[ Message::VALUE ]['carry'] ), 'the topics ride once, as an input' );
		$system = $this->out->asked()[0][0]['content'];
		$this->assertStringContainsString( '["Awards","Podcasts"]', $system );
		$this->assertStringContainsString( 'Quarry Ledger readers care about prizes.', $system );
		$this->assertStringStartsWith( Prompts::framed( 'extract' ) . ' You are an analyst reading one item for the Quarry Ledger kite desk. ', $system );
		$this->assertStringContainsString( 'Harbor Gazette wins a Peabody', $this->out->asked()[0][1]['content'] );
	}

	/** The model writes a claim's subject in any case, so a name led by "the" resolves however it is cased. */
	public function test_a_lowercase_claim_subject_led_by_the_article_resolves(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ), self::entity( self::PEABODY, 'Peabody Award' ) );
		$this->answer( '{"summary":"A win.","kind":"award","claims":[{"subject":"the harbor gazette","predicate":"won","object":"peabody award"}]}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 0.9 ) ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame( [ [ self::GAZETTE, 'won', self::PEABODY ] ], \array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'] ], $claims ) );
	}

	/** A claim about an entity the item does not link, or about no entity, is dropped. */
	public function test_a_claim_whose_subject_the_item_does_not_link_is_dropped(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'locality' => [ 'Port Angeles' ] ] ), self::entity( self::MESA, 'Mesa Courier' ) );
		$this->answer(
			(string) \json_encode(
				[
					'summary' => 'Mesa Courier partners with a Quarry paper.',
					'kind'    => 'partnership',
					'claims'  => [
						[ 'subject' => 'Mesa Courier', 'predicate' => 'partners_with', 'object' => 'Harbor Gazette' ],
						[ 'subject' => 'Quarry Ledger', 'predicate' => 'partners_with', 'object' => 'Mesa Courier' ],
						[ 'subject' => 'Port Angeles', 'predicate' => 'launched', 'object' => 'a newsletter' ],
						[ 'subject' => 'Harbor Gazette', 'predicate' => 'partners_with', 'object' => 'Mesa Courier' ],
					],
				]
			)
		);

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [ [ 'type' => 'organization', 'name' => 'Quarry Ledger' ] ] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame(
			[ [ 0, self::GAZETTE, 'partners_with', self::MESA, 'Mesa Courier' ] ],
			\array_map( static fn ( array $e ): array => [ $e['ord'], $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'] ], $claims )
		);
	}

	/** A claim object spelling a name a rejected entity of its type carries does not resolve to an entity of that type. */
	public function test_a_claim_object_spelling_a_stoplisted_name_carries_no_key(): void {
		$this->seed(
			self::entity( self::GAZETTE, 'The Harbor Gazette' ),
			self::entity( 'organization:site-5121', 'Site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' )
		);
		$this->answer( (string) \json_encode( [ 'summary' => 'Harbor Gazette partners.', 'kind' => 'partnership', 'claims' => [ [ 'subject' => 'Harbor Gazette', 'predicate' => 'partners_with', 'object' => 'Wharfside' ] ] ] ) );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame(
			[ [ self::GAZETTE, 'partners_with', '', 'Wharfside' ] ],
			\array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'] ], $claims )
		);
	}

	/** A client's own site naming it by a stoplisted name still asserts the claim, through its identifier link. */
	public function test_a_stoplisted_name_still_resolves_an_entity_the_item_links_by_identifier(): void {
		$this->seed(
			self::entity( 'organization:site-5121', 'Site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' ),
			self::entity( self::MESA, 'Mesa Courier' )
		);
		$this->answer( (string) \json_encode( [ 'summary' => 'Wharfside partners.', 'kind' => 'partnership', 'claims' => [ [ 'subject' => 'Wharfside', 'predicate' => 'partners_with', 'object' => 'Mesa Courier' ] ] ] ) );

		$this->extractor->fill( self::resolved( [ [ 'entity_key' => 'organization:site-5121', 'role' => 'publisher', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'asserted' ] ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame(
			[ [ 'organization:site-5121', 'partners_with', self::MESA ] ],
			\array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'] ], $claims )
		);
	}

	/**
	 * Two entities share a name: the subject is the one the item links, and an
	 * object naming two entities the item links neither of stays text.
	 */
	public function test_a_homonym_resolves_to_the_entity_the_item_links(): void {
		$this->seed(
			self::entity( 'organization:harbor-gazette-me', 'Harbor Gazette' ),
			self::entity( 'organization:harbor-gazette-wa', 'Harbor Gazette' ),
			self::entity( 'organization:mesa-courier-az', 'Mesa Courier' ),
			self::entity( 'organization:mesa-courier-nm', 'Mesa Courier' ),
			self::entity( self::PEABODY, 'Peabody Award' )
		);
		$this->answer(
			(string) \json_encode(
				[
					'summary' => 'Harbor Gazette wins and partners.',
					'claims'  => [
						[ 'subject' => 'Harbor Gazette', 'predicate' => 'won', 'object' => 'Peabody Award' ],
						[ 'subject' => 'Harbor Gazette', 'predicate' => 'partners_with', 'object' => 'Mesa Courier' ],
						[ 'subject' => 'Harbor Gazette', 'predicate' => 'competes_with', 'object' => 'Harbor Gazette' ],
					],
				]
			)
		);

		$this->extractor->fill( self::resolved( [ self::link( 'organization:harbor-gazette-wa', 0.9 ) ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame(
			[
				[ 'organization:harbor-gazette-wa', 'won', self::PEABODY, 'Peabody Award' ],
				[ 'organization:harbor-gazette-wa', 'partners_with', '', 'Mesa Courier' ],
				[ 'organization:harbor-gazette-wa', 'competes_with', 'organization:harbor-gazette-wa', 'Harbor Gazette' ],
			],
			\array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'] ], $claims )
		);
	}

	/**
	 * A subject the item only proposes a link to does not assert a claim and is
	 * not among the entities the extraction was given; the extraction is still
	 * recorded.
	 */
	public function test_a_claim_about_a_proposed_subject_is_dropped(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->answer( '{"summary":"Harbor Gazette wins.","kind":"award","claims":[{"subject":"Harbor Gazette","predicate":"won","object":"Peabody Award"}]}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 0.6, 'proposed' ) ], [] ) );

		$this->assertSame( [ [ 'extraction_recorded', null ], [ 'subjects_extracted', [] ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_keys'] ?? null ], $this->written() ) );
	}

	/**
	 * A missing client, a thrown call or an unparseable reply records one
	 * heuristic extraction, so the item is marked extracted rather than
	 * retried.
	 *
	 * @return array<string,array{0:?string}>
	 */
	public static function failures(): array {
		return [
			'no client'          => [ null ],
			'a thrown call'      => [ 'throw' ],
			'an unparseable one' => [ 'I would rather not.' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_a_failed_call_records_a_heuristic_extraction_once( ?string $reply ): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		if ( null !== $reply ) {
			$this->answer( $reply );
		}
		$stderr = $this->capture_stderr();

		$this->extractor->fill(
			self::resolved(
				[ self::link( self::GAZETTE, 1.0 ) ],
				[],
				[ 'title' => 'Harbor Gazette wins a state award', 'body' => "<p>The paper took first place.\n It beat 40 entries.</p>" ]
			)
		);

		$events = $this->written();
		$this->assertCount( 1, $events );
		Intel_Event::validate( $events[0] );
		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		$this->assertSame(
			[ 'extraction_recorded', 'Harbor Gazette wins a state award. The paper took first place.', 'other', [], 0, 0, 0.0, "machine:extract-heuristic@{$version}" ],
			[ $events[0]['event'], $events[0]['summary'], $events[0]['kind'], $events[0]['topics'], $events[0]['relevance'], $events[0]['importance'], $events[0]['sentiment'], $events[0]['actor'] ]
		);
		$this->assertSame( [ [ 0, 0 ] ], \array_map( static fn ( array $v ): array => [ $v['relevance'], $v['importance'] ], $this->forwarded() ) );
		$this->assertSame( 'Harbor Gazette wins a state award', $this->forwarded()[0]['item']['title'] );
		$this->assertCount( 1, $this->out->requests );
		$this->assertCount( 'throw' === $reply ? 1 : 0, self::stderr_lines( $stderr, 'extraction call failed: proxy 503' ) );
	}

	/** A cooperative stop raised while an answer is written stops the worker; nothing is parked. */
	public function test_a_cooperative_stop_while_writing_an_answer_propagates(): void {
		$this->writer_throws( new Worker_Should_Stop( 'max_runtime 595 reached' ) );
		$this->answer( '{"summary":"Harbor Gazette wins."}' );

		try {
			$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( [], $this->parked() );
	}

	/**
	 * An answer whose extraction throws is logged and parks the item's retry,
	 * as a failed call does, so nothing throws into the event loop.
	 */
	public function test_an_answer_that_throws_parks_a_retry(): void {
		$this->writer_throws( new \RuntimeException( 'writer refused 7731' ) );
		$this->answer( '{"summary":"Harbor Gazette wins."}' );
		$stderr = $this->capture_stderr();

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], false, 2, true ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => 0, 'attempt' => 3, 'extract' => true, 'not_before' => self::NOW + 1200 ] ],
			$this->parked()
		);
		$this->assertCount( 1, self::stderr_lines( $stderr, 'an answer failed for ' . self::ITEM . ': writer refused 7731' ) );
	}

	/**
	 * A failed call whose heuristic stand-in then fails to write parks one
	 * retry, not one for the call and another for the write.
	 */
	public function test_one_failure_parks_one_retry(): void {
		$this->writer_throws( new \RuntimeException( 'writer refused 7732' ) );
		$this->answer( 'throw' );
		$this->capture_stderr();

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], false, 1 ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => 0, 'attempt' => 2, 'not_before' => self::NOW + 600 ] ],
			$this->parked()
		);
	}

	/** An answer whose carry does not read is dropped with a line, never thrown. */
	public function test_an_answer_it_cannot_read_is_dropped(): void {
		$stderr                    = $this->capture_stderr();
		$answer                    = Message::new_message();
		$answer[ Message::TYPE ]   = Message::TM_STRUCT | Message::TM_RESPONSE;
		$answer[ Message::FROM ]   = 'intel:llm';
		$answer[ Message::KEY ]    = self::ITEM;
		$answer[ Message::VALUE ]  = [ 'content' => '{}', 'carry' => [ 'resolution' => [ 'item' => self::item() ] ] ];

		$this->extractor->fill( $answer );

		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping an answer it cannot read from intel:llm' ) );
	}

	/** A re-extraction's request waits behind new work; a first extraction's does not. */
	public function test_a_reextraction_asks_as_backlog(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], true, 0, true ) );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( [ true, false ], \array_map( static fn ( array $m ): bool => $m[ Message::VALUE ]['backlog'], $this->out->requests ) );
	}

	/**
	 * Claims resolve through the topics the request offered, though the
	 * index rotated before its answer came.
	 */
	public function test_an_answer_reads_the_topics_its_request_offered(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ), self::entity( 'topic:awards', 'Awards' ) );
		$deferred = new class() extends Llm_Answering_Sink {
			/** @var list<array<int,mixed>> Requests held back. */
			public array $held = [];

			public function fill( array $message ): void {
				if ( Llm_Answering_Sink::LLM === $message[ Message::TO ] ) {
					$this->held[] = $message;
					return;
				}
				parent::fill( $message );
			}
		};
		$this->extractor->sink( $deferred );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->activate();
		Core::$now = self::NOW + Entity_Index::TTL_S;

		$answer                   = Message::new_message();
		$answer[ Message::TYPE ]  = Message::TM_STRUCT | Message::TM_RESPONSE;
		$answer[ Message::FROM ]  = 'intel:llm';
		$answer[ Message::KEY ]   = self::ITEM;
		$answer[ Message::VALUE ] = [ 'content' => '{"summary":"Harbor Gazette wins.","topics":["Awards"]}', 'item' => $deferred->held[0][ Message::VALUE ]['item'], 'inputs' => $deferred->held[0][ Message::VALUE ]['inputs'], 'carry' => $deferred->held[0][ Message::VALUE ]['carry'] ];
		$this->extractor->fill( $answer );

		$this->assertSame( [ 'topic:awards' ], \array_values( \array_filter( \array_map( static fn ( array $m ): array => $m[ Message::VALUE ], $deferred->captured ), static fn ( array $e ): bool => 'extraction_recorded' === ( $e['event'] ?? null ) ) )[0]['topics'] );
	}

	/** The heuristic summary decodes entities and keeps a line break between the words it parted. */
	public function test_a_heuristic_summary_decodes_entities_and_spaces_a_break(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'title' => 'Tide &amp; ferry news', 'body' => 'The ferry<br>sailed late. Then docked.' ] ) );

		$this->assertSame( 'Tide & ferry news. The ferry sailed late.', $this->written()[0]['summary'] );
	}

	/** The heuristic summary keeps the summary's length, on a character boundary. */
	public function test_a_heuristic_summary_is_cut_to_the_summary_length(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'title' => \str_repeat( 'ü', 700 ), 'body' => '' ] ) );

		$this->assertSame( \str_repeat( 'ü', 600 ), $this->written()[0]['summary'] );
	}

	/**
	 * An item already extracted by a call keeps that extraction when the
	 * re-extraction's call fails: a heuristic stand-in never replaces it, and
	 * nothing is written or forwarded; a failed call parks its retry.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_a_failed_reextraction_keeps_the_earlier_extraction( ?string $reply ): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		if ( null !== $reply ) {
			$this->answer( $reply );
		}
		$stderr = $this->capture_stderr();

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], true ) );

		$this->assertSame( [ [], [] ], [ $this->written(), $this->forwarded() ] );
		$this->assertCount( null === $reply ? 0 : 1, $this->parked(), 'a failed call is retried; a group lending nothing is not' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'keeping the earlier extraction of ' . self::ITEM ) );
	}

	/** A resolution missing its item, whether it was extracted or its attempt, or an item missing a field, is dropped with a line, never thrown. */
	public function test_a_malformed_resolution_is_dropped(): void {
		$stderr = $this->capture_stderr();

		$this->extractor->fill( self::message( [ 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [] ] ) );
		$this->extractor->fill( self::message( [ 'item' => [ 'item_key' => self::ITEM ], 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [] ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => 'none', 'proposals' => [], 'extracted' => false ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [] ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => '2', 'extract' => false ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => 2 ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => 2, 'extract' => 'yes' ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => 2, 'extract' => false ] ) );
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => 2, 'extract' => false, 'publisher' => 7 ] ) );
		$this->extractor->fill( self::message( 'TICK' ) );

		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a resolution it cannot read from resolver' ) );
	}

	/** The index a resolver reloads for its signal is the one the extractor reads in the same tick. */
	public function test_the_extractor_reads_the_index_the_resolver_reloaded(): void {
		$this->answer( '{"summary":"Harbor Gazette wins.","claims":[{"subject":"Harbor Gazette","predicate":"won","object":"Peabody Award"}]}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->seed(
			self::entity( self::GAZETTE, 'The Harbor Gazette' ),
			self::entity( self::PEABODY, 'Peabody Award' ),
			[ Intel_Event::make( 'item_recorded', self::item(), 'machine:source:feed@9.4.1', '9.4.1' ) ]
		);
		Core::$now = self::NOW + 1;
		$resolver  = new Resolver_Node();
		$resolver->name( 'resolver' );
		$resolver->arguments( [ 'intel:writer', 'intel:later', 'intel:llm' ] );
		$resolver->target( 'extractor' );
		$resolver->sink( new Capture_Sink_Node() );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => self::NOW + 1 ];
		$resolver->fill( $message );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame( [ [ self::GAZETTE, 'won', self::PEABODY ] ], \array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'] ], $claims ) );
	}

	/**
	 * The theme an answer names is written normalized; an answer naming
	 * none, and the heuristic stand-in, write none. The request carries no
	 * themes: the prompt reads them as it is sent.
	 */
	public function test_an_extraction_records_the_theme_its_answer_names(): void {
		$this->answer( '{"summary":"Harbor Gazette cannot send mail.","kind":"bug","theme":"  Site Blocked   from Sending EMAIL "}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->answer( '{"summary":"Harbor Gazette cannot send mail.","kind":"bug","theme":" "}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->answer( '{"summary":"Harbor Gazette cannot send mail.","kind":"bug"}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->answer( 'throw' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$extractions = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'extraction_recorded' === $e['event'] ) );
		$this->assertSame( [ 'site blocked from sending email', null, null, null ], \array_map( static fn ( array $e ): ?string => $e['theme'] ?? null, $extractions ) );
		$this->assertSame( [ 'bug', 'bug', 'bug', 'other' ], \array_column( $extractions, 'kind' ) );
		$this->assertSame( [ 'topics', 'profile', 'audience', 'publisher' ], \array_keys( $this->out->requests[0][ Message::VALUE ]['inputs'] ) );
	}

	/** The index is held for its TTL, then read again. */
	public function test_the_topics_reload_after_the_index_ttl(): void {
		$this->answer( '{"summary":"Harbor Gazette wins.","topics":["Awards"]}' );
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		$this->seed( self::entity( 'topic:awards', 'Awards' ) );

		Core::$now = self::NOW + Entity_Index::TTL_S - 1;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( [ [], [], [ 'topic:awards' ] ], \array_column( $this->written(), 'topics' ) );
		$this->assertSame( 60, Entity_Index::TTL_S );
	}

	public function test_the_writer_and_the_later_queue_are_declared_as_its_destinations(): void {
		$this->assertSame( [ 'intel:writer', 'intel:later', 'intel:llm' ], $this->extractor->arguments() );
		$this->assertSame( [ 'deduper', 'intel:writer', 'intel:later', 'intel:llm' ], $this->extractor->display_targets() );
	}

	public function test_a_load_without_a_writer_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: writer' );
		( new Extractor_Node() )->arguments( [] );
	}

	public function test_a_load_without_a_later_queue_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: later' );
		( new Extractor_Node() )->arguments( [ 'intel:writer' ] );
	}

	public function test_a_load_without_a_model_call_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: llm' );
		( new Extractor_Node() )->arguments( [ 'intel:writer', 'intel:later' ] );
	}

	/**
	 * A call that fails parks the item on the later queue for a re-resolution
	 * one attempt on, backing off 300 seconds doubled per attempt to an hour,
	 * and still writes the stand-in or keeps the earlier extraction.
	 *
	 * @return array<string,array{0:string,1:int,2:bool,3:int}>
	 */
	public static function retries(): array {
		return [
			'a thrown call, first try'          => [ 'throw', 0, false, 300 ],
			'an unparseable reply, third try'   => [ 'I would rather not.', 2, false, 1200 ],
			'a thrown re-extraction, last try'  => [ 'throw', 4, true, 3600 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'retries' )]
	public function test_a_failed_call_parks_a_retry_with_backoff( string $reply, int $attempt, bool $extracted, int $backoff ): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->answer( $reply );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], $extracted, $attempt ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => 0, 'attempt' => $attempt + 1, 'not_before' => self::NOW + $backoff ] ],
			$this->parked()
		);
		$this->assertSame( $extracted ? [] : [ 'extraction_recorded' ], \array_column( $this->written(), 'event' ), 'the stand-in or kept extraction is written as without a retry' );
		$this->assertSame( [ 300, 3600, 5 ], [ Extractor_Node::RETRY_BASE_S, Extractor_Node::RETRY_MAX_S, Extractor_Node::MAX_ATTEMPTS ] );
	}

	/** A failed re-extraction's retry stays owed its extraction, so its re-resolution forwards it again. */
	public function test_a_failed_reextraction_parks_a_retry_still_owed_it(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->answer( 'throw' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], true, 1, true ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => 0, 'attempt' => 2, 'extract' => true, 'not_before' => self::NOW + 600 ] ],
			$this->parked()
		);
	}

	/**
	 * Where the group lends no credential no call is made, so nothing fails
	 * and nothing is retried.
	 */
	public function test_a_group_lending_nothing_parks_no_retry(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], false, 3 ) );

		$this->assertSame( [], $this->parked() );
		$this->assertSame( [ 'extraction_recorded' ], \array_column( $this->written(), 'event' ) );
	}

	/** A call that answers parks nothing. */
	public function test_an_answered_call_parks_no_retry(): void {
		$this->answer( '{"summary":"Harbor Gazette wins."}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], false, 2 ) );

		$this->assertSame( [], $this->parked() );
		$this->assertSame( [ 'extraction_recorded', 'subjects_extracted' ], \array_column( $this->written(), 'event' ) );
	}

	/** The last attempt's failure parks nothing more, logged. */
	public function test_a_failure_at_the_last_attempt_gives_up(): void {
		$this->answer( 'throw' );
		$stderr = $this->capture_stderr();

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [], false, Extractor_Node::MAX_ATTEMPTS ) );

		$this->assertSame( [], $this->parked() );
		$this->assertSame( [ 'extraction_recorded' ], \array_column( $this->written(), 'event' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'giving up extracting ' . self::ITEM . ' after 5 attempts' ) );
	}

	/**
	 * Where the group lends no credential the extractor publishes heuristic,
	 * naming the group, clearing a call's state.
	 */
	public function test_a_group_lending_nothing_publishes_heuristic_with_its_group(): void {
		$this->publishing( [ 'mode' => 'call', 'vault_group' => 'ai-proxy-old-2', 'last_ok_at' => 7000, 'last_error' => 'HTTP 500 https://proxy.example/v1', 'failing_since' => 6000 ] );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'heuristic', 'vault_group' => Llm_Answering_Sink::GROUP ] ), $this->published() );
	}

	/** A call that answers publishes call and the time, clearing the failure an earlier process published. */
	public function test_answered_call_publishes_call_and_clears_error(): void {
		$this->publishing( [ 'mode' => 'heuristic', 'vault_group' => 'ai-proxy-old-2', 'last_ok_at' => 7000, 'last_error' => 'HTTP 500 https://proxy.example/v1', 'failing_since' => 5100 ] );
		$this->answer( '{"summary":"Harbor Gazette wins."}' );
		Core::$now = 9200.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 9200 ] ), $this->published() );
	}

	/** A failed call keeps the last answer, and a later failure keeps the first one's failing_since. */
	public function test_failed_call_publishes_error_and_keeps_failing_since(): void {
		$this->publishing( [ 'mode' => 'call', 'last_ok_at' => 7000 ] );
		$this->out->outcome = [ 'error' => 'HTTP 502 https://proxy.example/v1/chat/completions' ];

		Core::$now = 8100.0;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		Core::$now = 8400.0;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 7000, 'last_error' => 'HTTP 502 https://proxy.example/v1/chat/completions', 'failing_since' => 8100 ] ), $this->published() );
	}

	/** A recycled worker's first failure keeps the failing_since its predecessor published, with the new error. */
	public function test_failing_since_survives_a_worker_recycle(): void {
		$this->publishing( [ 'mode' => 'call', 'last_ok_at' => 4800, 'last_error' => 'HTTP 504 https://proxy.example/v1/chat/completions', 'failing_since' => 5100 ] );
		$this->answer( 'throw' );
		Core::$now = 9300.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_ok_at' => 4800, 'last_error' => 'proxy 503', 'failing_since' => 5100 ] ), $this->published() );
	}

	/** Once the group lends a credential a call is made, so a failing call publishes call over a held heuristic. */
	public function test_a_failed_call_after_heuristic_publishes_call(): void {
		$this->publishing( [ 'mode' => 'heuristic', 'vault_group' => Llm_Answering_Sink::GROUP ] );
		$this->answer( 'throw' );
		Core::$now = 8900.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_error' => 'proxy 503', 'failing_since' => 8900 ] ), $this->published() );
	}

	/** An answer that does not parse is a failed call; with nothing published before, the mode is call. */
	public function test_an_unparsed_answer_publishes_failing(): void {
		$this->publishing( null );
		$this->answer( 'I would rather not.' );
		Core::$now = 8700.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'last_error' => 'the model\'s answer did not parse as an extraction', 'failing_since' => 8700 ] ), $this->published() );
	}

	/** A failure's text is scrubbed for the terminal and cut to 500 bytes on a character boundary. */
	public function test_a_failure_text_is_scrubbed_and_cut(): void {
		$this->publishing( null );
		$this->out->outcome = [ 'error' => "\e[2Jproxy said " . \str_repeat( 'ü', 400 ) ];
		$stderr             = $this->capture_stderr();

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$lines = self::stderr_lines( $stderr, 'extraction call failed: ' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\e", $lines[0] );

		$error = Core::as_string( $this->published()['last_error'] ?? null );
		$this->assertStringNotContainsString( "\e", $error );
		$this->assertStringContainsString( 'proxy said ü', $error );
		$this->assertGreaterThanOrEqual( 499, \strlen( $error ) );
		$this->assertLessThanOrEqual( 500, \strlen( $error ) );
		$this->assertTrue( \mb_check_encoding( $error, 'UTF-8' ) );
	}

	/** The resolve topology hands each resolution to the extractor, configured for the proxy. */
	public function test_the_resolve_topology_wires_the_extractor(): void {
		Core::reset();
		Core::$var['partition'] = '0';
		Core::$var['topology']  = 'newspack-intelligence-resolve';
		$router                 = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include newspack-intelligence-resolve\n" );

		$resolver = Core::node( 'resolver' );
		$this->assertInstanceOf( Resolver_Node::class, $resolver );
		$this->assertSame( [ 'extractor', 'intel:writer', 'intel:later', 'intel:llm' ], $resolver->display_targets() );
		$extractor = Core::node( 'extractor' );
		$this->assertInstanceOf( Extractor_Node::class, $extractor );
		$this->assertSame( [ 'deduper', 'intel:writer', 'intel:later', 'intel:llm' ], $extractor->display_targets() );
		$deduper = Core::node( 'deduper' );
		$this->assertInstanceOf( Deduper_Node::class, $deduper );
		$this->assertSame( [ 'intel:writer' ], $deduper->display_targets() );
		$this->assertSame( [ 'deduper' ], $this->read_private( Core::node( 'intel:items:resolve' ), 'snapshot_nodes' ) );
		$llm = Core::node( 'intel:llm' );
		$this->assertInstanceOf( LLM_Call_Node::class, $llm );
		$this->assertSame(
			[ 'ai-proxy', '', '', '', '', 4 ],
			[ $this->read_private( $llm, 'vault_group' ), $this->read_private( $llm, 'api_url' ), $this->read_private( $llm, 'model' ), $this->read_private( $llm, 'decision_model' ), $this->read_private( $llm, 'feature' ), $this->read_private( $llm, 'concurrency' ) ]
		);
		$this->assertSame( [ [], '' ], [ $this->read_private( $extractor, 'profiles' ), $this->read_private( $extractor, 'audience' ) ], 'a deployment supplies the profile and the audience' );
		$this->assertSame( [ '', '' ], [ $this->read_private( $llm, 'operator' ), $this->read_private( $llm, 'tracks' ) ], 'a deployment supplies the operator and its model transport' );
		$this->assertNull( Core::node( 'intel:resolved:null' ) );
	}

	/**
	 * Bind a worker partition and a shared cache, as a worker runs, holding
	 * the snapshot an earlier process published, if any.
	 *
	 * @param array<string,mixed>|null $earlier The earlier snapshot's keys.
	 */
	private function publishing( ?array $earlier ): void {
		$memd                   = new InMemoryMemcached();
		Core::$memd             = $memd;
		Core::$var['partition'] = (string) self::PARTITION;
		if ( null !== $earlier ) {
			$memd->set( Extractor_Node::status_key( 'extractor', self::PARTITION ), self::snapshot( $earlier ), 600 );
		}
	}

	/**
	 * The status the extractor published, its keys sorted.
	 *
	 * @return array<array-key,mixed>|null
	 */
	private function published(): ?array {
		$status = Extractor_Node::read_status( 'extractor', self::PARTITION );
		if ( null !== $status ) {
			\ksort( $status );
		}
		return $status;
	}

	/**
	 * A status as the extractor writes one, every key named, sorted.
	 *
	 * @param array<string,mixed> $fields The keys set.
	 * @return array<string,mixed>
	 */
	private static function snapshot( array $fields ): array {
		$status = [ ...Extractor_Node::BLANK_STATUS, ...$fields ];
		\ksort( $status );
		return $status;
	}

	/**
	 * Answer every request with one reply as its content, or with the error
	 * `proxy 503` when the reply is `throw`.
	 *
	 * @param string $reply The model's reply.
	 */
	private function answer( string $reply ): void {
		$this->out->outcome = 'throw' === $reply ? [ 'error' => 'proxy 503' ] : [ 'content' => $reply ];
	}

	/**
	 * Answer every extract review as $outcome says.
	 *
	 * @param array<string,mixed> $outcome `decision`, `error` or `unlent`.
	 */
	private function decide( array $outcome ): void {
		$this->out->outcomes['extract_review'] = $outcome;
	}

	/**
	 * Answer every extract gate as $outcome says.
	 *
	 * @param array<string,mixed> $outcome `decision`, `error` or `unlent`.
	 */
	private function gate( array $outcome ): void {
		$this->out->outcomes['extract_gate'] = $outcome;
	}

	/** @return list<array<string,mixed>> Each extraction written. */
	private function extractions(): array {
		return \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'extraction_recorded' === $e['event'] ) );
	}

	/** Make every write addressed to the writer check for a stop after it lands, as a Partition does. */
	private function writes_check_for_a_stop(): void {
		$sink = new class() extends Llm_Answering_Sink {
			public function fill( array $message ): void {
				parent::fill( $message );
				if ( 'intel:writer' === $message[ Message::TO ] ) {
					Event_Framework::instance()->stop_check();
				}
			}
		};
		$sink->outcome  = $this->out->outcome;
		$sink->outcomes = $this->out->outcomes;
		$this->out      = $sink;
		$this->extractor->sink( $sink );
	}

	/** Make every extract review request throw $e. */
	private function review_requests_throw( \Throwable $e ): void {
		$sink = new class( $e ) extends Llm_Answering_Sink {
			public function __construct( private \Throwable $e ) {
				parent::__construct();
			}

			public function fill( array $message ): void {
				if ( 'extract_review' === ( $message[ Message::VALUE ]['prompt'] ?? null ) ) {
					throw $this->e;
				}
				parent::fill( $message );
			}
		};
		$sink->outcome  = $this->out->outcome;
		$sink->outcomes = $this->out->outcomes;
		$this->out      = $sink;
		$this->extractor->sink( $sink );
	}

	/** Make every write addressed to the writer throw $e, and nothing else. */
	private function writer_throws( \Throwable $e ): void {
		$sink = new class( $e ) extends Llm_Answering_Sink {
			public function __construct( private \Throwable $e ) {
				parent::__construct();
			}

			public function fill( array $message ): void {
				if ( 'intel:writer' === $message[ Message::TO ] ) {
					throw $this->e;
				}
				parent::fill( $message );
			}
		};
		$sink->outcome  = $this->out->outcome;
		$sink->outcomes = $this->out->outcomes;
		$this->out      = $sink;
		$this->extractor->sink( $sink );
	}

	/** @return list<array<string,mixed>> The events addressed to the writer. */
	private function written(): array {
		$events = [];
		foreach ( $this->out->captured as $message ) {
			if ( 'deduper' === $message[ Message::TO ] || 'intel:later' === $message[ Message::TO ] ) {
				continue;
			}
			$this->assertSame( 'intel:writer', $message[ Message::TO ] );
			$this->assertSame( Message::TM_STRUCT, $message[ Message::TYPE ] );
			$this->assertSame( 'extractor', $message[ Message::FROM ] );
			$events[] = $message[ Message::VALUE ];
		}
		return $events;
	}

	/** @return list<array<string,mixed>> The records parked on the later queue. */
	private function parked(): array {
		$parked = [];
		foreach ( $this->out->captured as $message ) {
			if ( 'intel:later' === $message[ Message::TO ] ) {
				$this->assertSame( [ Message::TM_STRUCT, 'extractor' ], [ $message[ Message::TYPE ], $message[ Message::FROM ] ] );
				$this->assertSame( $message[ Message::VALUE ]['item_key'], $message[ Message::KEY ], 'a parked record is keyed by its item' );
				$parked[] = $message[ Message::VALUE ];
			}
		}
		return $parked;
	}

	/** @return list<array<string,mixed>> The structs forwarded to the target. */
	private function forwarded(): array {
		$forwarded = [];
		foreach ( $this->out->captured as $message ) {
			if ( 'deduper' === $message[ Message::TO ] ) {
				$this->assertSame( [ Message::TM_STRUCT, 'extractor' ], [ $message[ Message::TYPE ], $message[ Message::FROM ] ] );
				$forwarded[] = $message[ Message::VALUE ];
			}
		}
		return $forwarded;
	}

	/**
	 * A link as the resolver answers one.
	 *
	 * @param string $entity_key The entity linked.
	 * @param float  $confidence The link's confidence.
	 * @param string $state      Asserted or proposed.
	 * @return array<string,mixed>
	 */
	private static function link( string $entity_key, float $confidence, string $state = 'asserted' ): array {
		return [ 'entity_key' => $entity_key, 'role' => 'subject', 'method' => 'name', 'confidence' => $confidence, 'state' => $state ];
	}

	/**
	 * An item_resolved struct, as the resolver forwards one.
	 *
	 * @param list<array<string,mixed>> $links     The links.
	 * @param list<array<string,mixed>> $proposals The candidates.
	 * @param array<string,mixed>       $over      Item fields to set.
	 * @param bool                      $extracted Whether a call extracted the item before.
	 * @param int                       $attempt   The extraction attempts made so far.
	 * @param bool                      $extract   Whether it is owed an extraction whatever it holds.
	 * @param string                    $publisher The entity whose site published the item; '' for none.
	 * @return array<int,mixed>
	 */
	private static function resolved( array $links, array $proposals, array $over = [], bool $extracted = false, int $attempt = 0, bool $extract = false, string $publisher = '' ): array {
		return self::message( [ 'item' => \array_merge( self::item(), $over ), 'links' => $links, 'proposals' => $proposals, 'extracted' => $extracted, 'attempt' => $attempt, 'extract' => $extract, 'publisher' => $publisher ] );
	}

	/** @return list<list<array<string,mixed>>> Two public items like a transit-grant story, each themed, retrieved two days before it. */
	private static function themed_neighbours(): array {
		$at     = 1794050000 - 2 * 86400;
		$events = [];
		foreach ( [ 'feed:heron-transit-1' => [ 'Transit grant for Heron Weekly routes', 'regional transit grant' ], 'feed:gull-transit-2' => [ 'Gull Post transit grant renewed', 'transit funding round' ] ] as $key => [ $title, $theme ] ) {
			$events[] = [
				Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => 'feed', 'tier' => 'public', 'url' => "https://heron.example/{$key}", 'title' => $title, 'body' => 'Routes.', 'item_ts' => $at, 'retrieved_ts' => $at ], 'machine:source:feed@9.4.1', '' ),
				Intel_Event::make( 'extraction_recorded', [ 'item_key' => $key, 'summary' => "A report: {$title}.", 'kind' => 'funding', 'relevance' => 5, 'importance' => 4, 'sentiment' => 0.1, 'topics' => [], 'theme' => $theme, 'retrieved_ts' => $at ], 'machine:extract@9.4.1', '' ),
			];
		}
		return $events;
	}

	/** @return list<array<string,mixed>> The client newsroom: its name and its domain. */
	private static function lantern(): array {
		return self::entity( self::LANTERN, 'Kestrel Lantern Harbor', [ 'domain' => [ 'klharbor.example' ] ] );
	}

	/**
	 * An open client role, from before the item.
	 *
	 * @param string $key The entity holding it.
	 * @return list<array<string,mixed>>
	 */
	private static function client( string $key ): array {
		return [ Intel_Event::make( 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => 1790000000 ], 'user:41', 'fixture-7' ) ];
	}

	/** @return array<string,mixed> A client lane item's fields: its own article about someone else. */
	private static function lane_item(): array {
		return [ 'item_key' => 'feed:klharbor.example:transit-77', 'url' => 'https://klharbor.example/2026/11/transit-grant', 'title' => 'Mesa Courier wins a transit grant' ];
	}

	/** @return array<string,mixed> A configured feed's copy of the client's own article about someone else, at the client's own url. */
	private static function published_item(): array {
		return [ 'item_key' => 'feed:klharbor-transit-77' ] + self::lane_item();
	}

	/** @return array<string,mixed> The item every resolution carries. */
	private static function item(): array {
		return [ 'item_key' => self::ITEM, 'source' => 'feed', 'tier' => 'public', 'url' => 'https://harbor.example/peabody', 'title' => 'Harbor Gazette wins a Peabody', 'body' => 'The port series won.', 'item_ts' => 1794000000, 'retrieved_ts' => 1794050000 ];
	}

	/**
	 * A message from the resolver carrying a VALUE.
	 *
	 * @param mixed $value The VALUE.
	 * @return array<int,mixed>
	 */
	private static function message( mixed $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = \is_array( $value ) ? Message::TM_STRUCT : Message::TM_INFO;
		$message[ Message::FROM ]  = 'resolver';
		$message[ Message::VALUE ] = $value;
		return $message;
	}
}

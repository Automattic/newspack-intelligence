<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Deduper_Node;
use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\Proxy_LLM_Client;
use Newspack_Intelligence\Resolver_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The extractor: one LLM call per resolved item that links something, its
 * extraction and the claims about entities the item links written as events
 * to the writer; a heuristic extraction when the call cannot answer.
 */
final class ExtractorNodeTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1794100000.0;

	private const ITEM = 'feed:extract-5521';

	private const GAZETTE = 'organization:harbor-gazette';

	private const MESA = 'organization:mesa-courier';

	private const PEABODY = 'event:peabody-award';

	/** The worker partition the extractor publishes its status from. */
	private const PARTITION = 4;

	/** The Vault entry the extractor's token resolves from; none exists. */
	private const VAULT_ID = 'AI-proxy-test-9';

	private Extractor_Node $extractor;

	private Capture_Sink_Node $out;

	/** @var list<list<array{role:string,content:string}>> Each chat call's messages. */
	private array $asked = [];

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
		Extractor_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$this->out                   = new Capture_Sink_Node();
		$this->extractor             = new Extractor_Node();
		$this->extractor->name( 'extractor' );
		$this->extractor->arguments( [ 'intel:writer', 'intel:later' ] );
		$this->extractor->target( 'deduper' );
		$this->extractor->sink( $this->out );
	}

	protected function tearDown(): void {
		Extractor_Node::$llm_factory = null;
		Proxy_LLM_Client::$http_post = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** The prompt verbs are the extractor's alone: the resolver, which builds no extraction prompt, declares none. */
	public function test_only_the_extractor_declares_the_prompt_verbs(): void {
		$prompt   = [ 'set_audience', 'add_profile', 'remove_profile', 'add_importance_floor', 'remove_importance_floor' ];
		$commands = \array_column( Extractor_Node::node_schema()['commands'], null, 'name' );

		$this->assertSame( [ 'set_api_url', 'set_vault_id', 'set_model', 'set_feature', ...$prompt ], \array_keys( $commands ) );
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

	/** A negative min, which the verb's int binding refuses first, is refused by the setter too. */
	public function test_a_negative_floor_is_refused(): void {
		$node = $this->prompt_node();

		$caught = $this->caught_any( fn () => $node->add_importance_floor( 'complaint', -1 ) );

		$this->assertSame( 'add_importance_floor: a floor is 0 to 9; got -1', $caught->getMessage() );
		$this->assertSame( [], $this->read_private( $node, 'importance_floors' ) );
	}

	/** A negative relevance, which the verb's int binding refuses first, is refused by the setter too. */
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

	/** An extractor for the prompt verbs, its interpreter wired. */
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

		$this->assertSame( [], $this->asked );
		$this->assertSame( [], $this->out->captured );
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
		$this->assertCount( 1, $this->asked );
		$system = $this->asked[0][0]['content'];
		$this->assertStringContainsString( '["Awards","Podcasts"]', $system );
		$this->assertStringContainsString( 'Quarry Ledger readers care about prizes.', $system );
		$this->assertStringStartsWith( 'You are an analyst reading one item for the Quarry Ledger kite desk. ', $system );
		$this->assertStringContainsString( 'Harbor Gazette wins a Peabody', $this->asked[0][1]['content'] );
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

	/** A claim object spelling a name a rejected entity of its type carries resolves to no entity of that type. */
	public function test_a_claim_object_spelling_a_stoplisted_name_carries_no_key(): void {
		$this->seed(
			self::entity( self::GAZETTE, 'The Harbor Gazette' ),
			self::entity( 'organization:atomic-5121', 'Atomic site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
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
			self::entity( 'organization:atomic-5121', 'Atomic site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' ),
			self::entity( self::MESA, 'Mesa Courier' )
		);
		$this->answer( (string) \json_encode( [ 'summary' => 'Wharfside partners.', 'kind' => 'partnership', 'claims' => [ [ 'subject' => 'Wharfside', 'predicate' => 'partners_with', 'object' => 'Mesa Courier' ] ] ] ) );

		$this->extractor->fill( self::resolved( [ [ 'entity_key' => 'organization:atomic-5121', 'role' => 'publisher', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'asserted' ] ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame(
			[ [ 'organization:atomic-5121', 'partners_with', self::MESA ] ],
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
		$this->assertCount( null === $reply ? 0 : 1, $this->asked );
		$this->assertCount( 'throw' === $reply ? 1 : 0, self::stderr_lines( $stderr, 'extraction call failed: proxy 503' ) );
	}

	/** A cooperative stop raised inside the call stops the worker; nothing is recorded, so the item is extracted on replay. */
	public function test_a_cooperative_stop_inside_the_call_propagates(): void {
		Extractor_Node::$llm_factory = static fn (): LLM_Client => new class() implements LLM_Client {
				public function chat( array $messages, array $opts = [] ): string {
					throw new Worker_Should_Stop( 'max_runtime 595 reached' );
				}
			};

		try {
			$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( [], $this->out->captured );
	}

	/** The heuristic summary decodes entities and keeps a line break between the words it parted. */
	public function test_a_heuristic_summary_decodes_entities_and_spaces_a_break(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'title' => 'Tide &amp; ferry news', 'body' => 'The ferry<br>sailed late. Then docked.' ] ) );

		$this->assertSame( 'Tide & ferry news. The ferry sailed late.', $this->written()[0]['summary'] );
	}

	/** The heuristic summary keeps the summary's length, on a character boundary. */
	public function test_a_heuristic_summary_is_cut_to_the_summary_length(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'title' => \str_repeat( 'ü', 400 ), 'body' => '' ] ) );

		$this->assertSame( \str_repeat( 'ü', 280 ), $this->written()[0]['summary'] );
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
		$this->assertCount( null === $reply ? 0 : 1, $this->parked(), 'a failed call is retried; a missing call is not' );
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
		Resolver_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$resolver                   = new Resolver_Node();
		$resolver->name( 'resolver' );
		$resolver->arguments( [ 'intel:writer', 'intel:later' ] );
		$resolver->target( 'extractor' );
		$resolver->sink( new Capture_Sink_Node() );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => self::NOW + 1 ];
		$resolver->fill( $message );
		Resolver_Node::$llm_factory = null;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$claims = \array_values( \array_filter( $this->written(), static fn ( array $e ): bool => 'claim_asserted' === $e['event'] ) );
		$this->assertSame( [ [ self::GAZETTE, 'won', self::PEABODY ] ], \array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'] ], $claims ) );
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
		$this->assertSame( [ 'intel:writer', 'intel:later' ], $this->extractor->arguments() );
		$this->assertSame( [ 'deduper', 'intel:writer', 'intel:later' ], $this->extractor->display_targets() );
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
	 * Without a client the extractor makes no call, so nothing fails and
	 * nothing is retried.
	 */
	public function test_no_client_parks_no_retry(): void {
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
	 * Without a Vault entry the client is not built, so the extractor publishes
	 * heuristic, clearing a call's state.
	 */
	public function test_no_client_publishes_heuristic_with_vault_id(): void {
		$this->publishing( [ 'mode' => 'call', 'vault_id' => 'AI-proxy-old-2', 'last_ok_at' => 7000, 'last_error' => 'AI proxy returned HTTP 500', 'failing_since' => 6000 ] );
		Extractor_Node::$llm_factory = null;
		$this->extractor->interpreter()?->dispatch( 'set_vault_id', [ self::VAULT_ID ] );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'heuristic', 'vault_id' => self::VAULT_ID ] ), $this->published() );
	}

	/** A call that answers publishes call and the time, clearing the failure an earlier process published. */
	public function test_answered_call_publishes_call_and_clears_error(): void {
		$this->publishing( [ 'mode' => 'heuristic', 'vault_id' => 'AI-proxy-old-2', 'last_ok_at' => 7000, 'last_error' => 'AI proxy returned HTTP 500', 'failing_since' => 5100 ] );
		$this->extractor->interpreter()?->dispatch( 'set_vault_id', [ self::VAULT_ID ] );
		$this->answer( '{"summary":"Harbor Gazette wins."}' );
		Core::$now = 9200.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'vault_id' => self::VAULT_ID, 'last_ok_at' => 9200 ] ), $this->published() );
	}

	/** A call that throws keeps the last answer, and a later failure keeps the first one's failing_since. */
	public function test_failed_call_publishes_error_and_keeps_failing_since(): void {
		$this->publishing( [ 'mode' => 'call', 'vault_id' => self::VAULT_ID, 'last_ok_at' => 7000 ] );
		$this->extractor->interpreter()?->dispatch( 'set_vault_id', [ self::VAULT_ID ] );
		Proxy_LLM_Client::$http_post = static fn (): array => [ 'response' => [ 'code' => 502 ], 'body' => 'bad gateway' ];
		Extractor_Node::$llm_factory = static fn (): LLM_Client => new Proxy_LLM_Client( 'https://proxy.test/v1', 'SEKRET-71', 'gpt-oss-120b', 'newspack-intelligence' );

		Core::$now = 8100.0;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );
		Core::$now = 8400.0;
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'vault_id' => self::VAULT_ID, 'last_ok_at' => 7000, 'last_error' => 'AI proxy returned HTTP 502', 'failing_since' => 8100 ] ), $this->published() );
	}

	/** A recycled worker's first failure keeps the failing_since its predecessor published, with the new error. */
	public function test_failing_since_survives_a_worker_recycle(): void {
		$this->publishing( [ 'mode' => 'call', 'vault_id' => self::VAULT_ID, 'last_ok_at' => 4800, 'last_error' => 'AI proxy returned HTTP 504', 'failing_since' => 5100 ] );
		$this->answer( 'throw' );
		Core::$now = 9300.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'vault_id' => '', 'last_ok_at' => 4800, 'last_error' => 'proxy 503', 'failing_since' => 5100 ] ), $this->published() );
	}

	/** Once an entry exists a client is built, so a failing call publishes call over a held heuristic. */
	public function test_a_failed_call_after_heuristic_publishes_call(): void {
		$this->publishing( [ 'mode' => 'heuristic', 'vault_id' => self::VAULT_ID ] );
		$this->answer( 'throw' );
		Core::$now = 8900.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'vault_id' => '', 'last_error' => 'proxy 503', 'failing_since' => 8900 ] ), $this->published() );
	}

	/** An answer that does not parse is a failed call; with nothing published before, the mode is call. */
	public function test_an_unparsed_answer_publishes_failing(): void {
		$this->publishing( null );
		$this->answer( 'I would rather not.' );
		Core::$now = 8700.0;

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

		$this->assertSame( self::snapshot( [ 'mode' => 'call', 'vault_id' => '', 'last_error' => 'AI proxy answer did not parse as an extraction', 'failing_since' => 8700 ] ), $this->published() );
	}

	/** A failure's text is scrubbed for the terminal and cut to 500 bytes on a character boundary. */
	public function test_a_failure_text_is_scrubbed_and_cut(): void {
		$this->publishing( null );
		Extractor_Node::$llm_factory = static fn (): LLM_Client => new class() implements LLM_Client {
				public function chat( array $messages, array $opts = [] ): string {
					throw new \RuntimeException( "\e[2Jproxy said " . \str_repeat( 'ü', 400 ) );
				}
			};

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [] ) );

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
		$this->assertSame( [ 'extractor', 'intel:writer', 'intel:later' ], $resolver->display_targets() );
		$extractor = Core::node( 'extractor' );
		$this->assertInstanceOf( Extractor_Node::class, $extractor );
		$this->assertSame( [ 'deduper', 'intel:writer', 'intel:later' ], $extractor->display_targets() );
		$deduper = Core::node( 'deduper' );
		$this->assertInstanceOf( Deduper_Node::class, $deduper );
		$this->assertSame( [ 'intel:writer' ], $deduper->display_targets() );
		$this->assertSame( [ 'deduper' ], $this->read_private( Core::node( 'intel:items:resolve' ), 'snapshot_nodes' ) );
		$this->assertSame( 'AI-proxy', $this->read_private( $extractor, 'vault_id' ) );
		$this->assertSame( 'gpt-oss-120b', $this->read_private( $extractor, 'model' ) );
		$this->assertSame( 'newspack-intelligence', $this->read_private( $extractor, 'feature' ) );
		$this->assertSame( [ [], '' ], [ $this->read_private( $extractor, 'profiles' ), $this->read_private( $extractor, 'audience' ) ], 'a deployment supplies the profile and the audience' );
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
	 * Answer every chat call with one reply, or throw when the reply is `throw`.
	 *
	 * @param string $reply The model's reply.
	 */
	private function answer( string $reply ): void {
		$asked                       = &$this->asked;
		Extractor_Node::$llm_factory = static function () use ( $reply, &$asked ): LLM_Client {
			return new class( $reply, $asked ) implements LLM_Client {
			/**
			 * @param string                                          $reply The reply.
			 * @param list<list<array{role:string,content:string}>> $asked Each call's messages.
			 */
			public function __construct( private string $reply, private array &$asked ) {}

			public function chat( array $messages, array $opts = [] ): string {
				$this->asked[] = $messages;
				if ( 'throw' === $this->reply ) {
					throw new \RuntimeException( 'proxy 503' );
				}
				return $this->reply;
			}
			};
		};
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
	 * @return array<int,mixed>
	 */
	private static function resolved( array $links, array $proposals, array $over = [], bool $extracted = false, int $attempt = 0, bool $extract = false ): array {
		return self::message( [ 'item' => \array_merge( self::item(), $over ), 'links' => $links, 'proposals' => $proposals, 'extracted' => $extracted, 'attempt' => $attempt, 'extract' => $extract ] );
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

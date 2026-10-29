<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Deduper_Node;
use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\Resolver_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
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
		Topology_Registry::reset();
		parent::tearDown();
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
				[ 0, self::GAZETTE, 'won', self::PEABODY, '', 0.9 ],
				[ 1, self::GAZETTE, 'located_in', '', 'Port Angeles', 0.9 ],
			],
			\array_map( static fn ( array $e ): array => [ $e['ord'], $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'], $e['confidence'] ], \array_slice( $events, 2 ) )
		);
		$this->assertCount( 1, $this->asked );
		$system = $this->asked[0][0]['content'];
		$this->assertStringContainsString( '["Awards","Podcasts"]', $system );
		$this->assertStringContainsString( 'Quarry Ledger readers care about prizes.', $system );
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
			[ [ 0, self::GAZETTE, 'partners_with', self::MESA, '' ] ],
			\array_map( static fn ( array $e ): array => [ $e['ord'], $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'] ], $claims )
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
				[ 'organization:harbor-gazette-wa', 'won', self::PEABODY, '' ],
				[ 'organization:harbor-gazette-wa', 'partners_with', '', 'Mesa Courier' ],
				[ 'organization:harbor-gazette-wa', 'competes_with', 'organization:harbor-gazette-wa', '' ],
			],
			\array_map( static fn ( array $e ): array => [ $e['subject_key'], $e['predicate'], $e['object_key'], $e['object_text'] ], $claims )
		);
	}

	/** A subject the item only proposes a link to asserts no claim and is not among the entities the extraction was given; the extraction is still recorded. */
	public function test_a_claim_about_a_proposed_subject_is_dropped(): void {
		$this->seed( self::entity( self::GAZETTE, 'The Harbor Gazette' ) );
		$this->answer( '{"summary":"Harbor Gazette wins.","kind":"award","claims":[{"subject":"Harbor Gazette","predicate":"won","object":"Peabody Award"}]}' );

		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 0.6, 'proposed' ) ], [] ) );

		$this->assertSame( [ [ 'extraction_recorded', null ], [ 'subjects_extracted', [] ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_keys'] ?? null ], $this->written() ) );
	}

	/**
	 * No client, a thrown call or an unparseable reply records one heuristic
	 * extraction, so the item is marked extracted rather than retried.
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

	/** The heuristic summary keeps the summary's length, on a character boundary. */
	public function test_a_heuristic_summary_is_cut_to_the_summary_length(): void {
		$this->extractor->fill( self::resolved( [ self::link( self::GAZETTE, 1.0 ) ], [], [ 'title' => \str_repeat( 'ü', 400 ), 'body' => '' ] ) );

		$this->assertSame( \str_repeat( 'ü', 280 ), $this->written()[0]['summary'] );
	}

	/**
	 * An item already extracted by a call keeps that extraction when the
	 * re-extraction's call fails: no heuristic stand-in replaces it, and
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
		$this->assertCount( null === $reply ? 0 : 1, $this->parked(), 'a failed call is retried; no call is not' );
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
		$this->extractor->fill( self::message( [ 'item' => self::item(), 'links' => [ self::link( self::GAZETTE, 1.0 ) ], 'proposals' => [], 'extracted' => false, 'attempt' => '2' ] ) );
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

	/** No client makes no call, so nothing fails and nothing is retried. */
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

	/** The resolve topology hands each resolution to the extractor, configured for the proxy and the audience. */
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
		$this->assertSame(
			[
				'Newspack serves independent news publishers on WordPress.',
				"Relevant: what Newspack's clients launch, win, raise, struggle with or ask for; what Newspack ships; where the news industry is moving.",
				'Not relevant: dependency bumps, typo fixes, cosmetic changes.',
			],
			$this->read_private( $extractor, 'profiles' )
		);
		$this->assertNull( Core::node( 'intel:resolved:null' ) );
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
	 * @return array<int,mixed>
	 */
	private static function resolved( array $links, array $proposals, array $over = [], bool $extracted = false, int $attempt = 0 ): array {
		return self::message( [ 'item' => \array_merge( self::item(), $over ), 'links' => $links, 'proposals' => $proposals, 'extracted' => $extracted, 'attempt' => $attempt ] );
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

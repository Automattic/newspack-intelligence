<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\Proxy_LLM_Client;
use Newspack_Intelligence\Resolver_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The resolver node: each recorded item resolved against the projection,
 * every link and candidate written as an event to the writer, and the
 * resolution forwarded to its target. The index reloads after its TTL, or
 * when a re-resolution's signal settled after it was read, and reads as
 * empty while no graph table exists.
 */
final class ResolverNodeTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1793000000.0;

	private const ITEM = 'feed:resolve-4410';

	private const GAZETTE = 'organization:harbor-gazette';

	private const MESA = 'organization:mesa-courier';

	private Resolver_Node $resolver;

	private Capture_Sink_Node $out;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
		Resolver_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$this->out                  = new Capture_Sink_Node();
		$this->resolver             = new Resolver_Node();
		$this->resolver->name( 'resolver' );
		$this->resolver->arguments( [ 'intel:writer' ] );
		$this->resolver->target( 'intel:resolved:null' );
		$this->resolver->sink( $this->out );
	}

	protected function tearDown(): void {
		Resolver_Node::$llm_factory  = null;
		Proxy_LLM_Client::$http_post = null;
		\delete_option( \Newspack_Nodes\Vault::OPTION_KEY );
		\Newspack_Nodes\Vault::get_instance()->reset_cache();
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** Each link is an event addressed to the writer; the resolution goes to the target. */
	public function test_each_link_is_written_and_the_resolution_forwarded(): void {
		$this->seed( self::gazette(), self::entity( self::MESA, 'Mesa Courier' ) );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://www.harborgazette.org/2026/10/award', 'body' => 'Mesa Courier reprinted it.' ] ) );

		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		$events  = $this->written();
		$this->assertSame(
			[
				[ 'link_asserted', self::GAZETTE, 'subject', 'identifier', 1.0 ],
				[ 'link_asserted', self::MESA, 'mention', 'name', 1.0 ],
			],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['role'], $e['method'], $e['confidence'] ], $events )
		);
		foreach ( $events as $event ) {
			Intel_Event::validate( $event );
			$this->assertSame( self::ITEM, $event['item_key'] );
			$this->assertSame( "machine:resolve@{$version}", $event['actor'] );
			$this->assertSame( 1792950000, $event['retrieved_ts'], 'a link carries its item\'s retrieval' );
			$this->assertSame( $version, $event['config_version'] );
		}
		$this->assertSame(
			[
				'item'      => self::item( [ 'url' => 'https://www.harborgazette.org/2026/10/award', 'body' => 'Mesa Courier reprinted it.' ] ),
				'extracted' => false,
				'links'     => [
					[ 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'asserted' ],
					[ 'entity_key' => self::MESA, 'role' => 'mention', 'method' => 'name', 'confidence' => 1.0, 'state' => 'asserted' ],
				],
				'proposals' => [],
			],
			$this->resolved()
		);
	}

	/** A proposed link and a candidate entity are written as their own kinds. */
	public function test_a_proposed_link_and_a_candidate_entity_are_written(): void {
		$this->seed( self::gazette() );
		$this->answer( '{"orgs": ["Harbor Gazette Weekly", "Acme Widgets Corporation"], "people": [], "locations": []}' );

		$this->resolver->fill( self::recorded( [ 'title' => 'Regional publishers announce a merger' ] ) );

		$events = $this->written();
		$this->assertSame( [ 'link_proposed', 'entity_proposed' ], \array_column( $events, 'event' ) );
		$this->assertSame( [ self::GAZETTE, 'subject', 'ner_fuzzy', 0.8 ], [ $events[0]['entity_key'], $events[0]['role'], $events[0]['method'], $events[0]['confidence'] ] );
		$this->assertSame(
			[ 'organization:acme-widgets-corporation', 'organization', 'Acme Widgets Corporation', self::ITEM ],
			[ $events[1]['entity_key'], $events[1]['type'], $events[1]['name'], $events[1]['from_item_key'] ]
		);
		$this->assertSame( [ [ 'type' => 'organization', 'name' => 'Acme Widgets Corporation' ] ], $this->resolved()['proposals'] );
	}

	/** The Consumer reads the whole items stream; other kinds are skipped. */
	public function test_an_event_of_another_kind_is_skipped(): void {
		$this->seed( self::gazette() );
		$link = Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'retrieved_ts' => 1793000000 ], 'machine:resolve@9.4.1', '9.4.1' );

		$this->resolver->fill( self::message( $link ) );
		$this->resolver->fill( self::message( 'TICK' ) );

		$this->assertSame( [], $this->out->captured );
	}

	/** An item event it cannot read throws, so the Consumer dead-letters it. */
	public function test_an_unreadable_item_event_throws(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'item_recorded: missing field' );
		$this->resolver->fill( self::message( [ 'event' => 'item_recorded', 'item_key' => self::ITEM ] ) );
	}

	public function test_the_index_reloads_after_its_ttl(): void {
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		$this->seed( self::gazette() );

		Core::$now = self::NOW + Entity_Index::TTL_S - 1;
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		$this->assertSame( [], $this->resolved()['links'], 'the index is held until its TTL' );

		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		$this->assertSame( [ self::GAZETTE ], \array_column( $this->resolved()['links'], 'entity_key' ) );
		$this->assertSame( 60, Entity_Index::TTL_S );
	}

	/** A re-resolution reads its item from the projection and reloads an index read before its signal settled. */
	public function test_a_reresolve_reloads_an_index_older_than_its_signal(): void {
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		$this->seed( self::gazette(), [ Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ) ] );

		Core::$now = self::NOW + 1;
		$this->resolver->fill( self::reresolve( self::ITEM, self::NOW + 1 ) );

		$this->assertSame( [ 'link_asserted' ], \array_column( $this->written(), 'event' ) );
		$this->assertSame( [ self::GAZETTE ], \array_column( $this->resolved()['links'], 'entity_key' ) );
		$this->assertSame( self::item( [ 'url' => 'https://harborgazette.org/a' ] ), $this->resolved()['item'] );
		$this->assertArrayNotHasKey( 'reresolved', $this->resolved() );
	}

	/** Every record of one signal shares the index its first record loaded: one reload, not twenty. */
	public function test_one_signal_reloads_the_index_once(): void {
		$items = [];
		for ( $i = 0; $i < 20; $i++ ) {
			$items[] = Intel_Event::make( 'item_recorded', self::item( [ 'item_key' => "feed:signal-{$i}", 'url' => 'https://harborgazette.org/a', 'body' => 'Mesa Courier reprinted it.' ] ), 'machine:source:feed@9.4.1', '9.4.1' );
		}
		$this->resolver->fill( self::recorded( [] ) );
		$this->seed( self::gazette(), $items );
		Core::$now = self::NOW + 31;

		$this->resolver->fill( self::reresolve( 'feed:signal-0', self::NOW + 30 ) );
		$this->seed( self::entity( self::MESA, 'Mesa Courier' ) );
		for ( $i = 1; $i < 20; $i++ ) {
			$this->resolver->fill( self::reresolve( "feed:signal-{$i}", self::NOW + 30 ) );
		}

		$links = \array_map( static fn ( array $e ): array => [ $e['item_key'], $e['entity_key'] ], $this->written() );
		$this->assertSame( \array_map( static fn ( int $i ): array => [ "feed:signal-{$i}", self::GAZETTE ], \range( 0, 19 ) ), $links, 'the first record reloads; the nineteen after it never see Mesa' );
	}

	/** A re-resolution runs the identifier, name and stem steps alone: NER ran when the item was first resolved. */
	public function test_a_reresolve_skips_ner(): void {
		$this->seed( self::gazette(), [ Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ) ] );
		$asked = 0;
		Resolver_Node::$llm_factory = static function () use ( &$asked ): LLM_Client {
			return new class( $asked ) implements LLM_Client {
				public function __construct( private int &$asked ) {}

				public function chat( array $messages, array $opts = [] ): string {
					++$this->asked;
					return '{"orgs": ["Harbor Gazette Weekly", "Acme Widgets Corporation"]}';
				}
			};
		};

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( 0, $asked );
		$this->assertSame( [], $this->written() );
		$this->assertSame( [ [], [] ], [ $this->resolved()['links'], $this->resolved()['proposals'] ] );
	}

	/**
	 * A re-resolved item extracted since its links were asserted writes no
	 * link it already holds as it is, so a link's time stays the time it
	 * changed, and forwards nothing.
	 */
	public function test_a_reresolved_item_already_extracted_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], $this->written() );
		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/**
	 * A link asserted after the item's last extraction is not settled: that
	 * extraction ran without the subject, so the item is forwarded again.
	 */
	public function test_a_link_asserted_after_the_extraction_is_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				[ 'ts' => self::NOW - 600 ] + self::extracted(),
				self::link( 'link_asserted' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], $this->written() );
		$this->assertSame( [ [ self::GAZETTE, 'asserted' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'] ], $this->resolved()['links'] ) );
		$this->assertTrue( $this->resolved()['extracted'] );
	}

	/**
	 * A re-resolved item already extracted is forwarded when it now asserts a
	 * link it only proposed, so an accepted entity's claims are extracted.
	 */
	public function test_a_reresolved_item_asserting_a_link_it_only_proposed_is_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_proposed' ),
				self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ 'link_asserted', self::GAZETTE ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() ) );
		$this->assertSame( self::ITEM, $this->resolved()['item']['item_key'] );
		$this->assertSame( [ self::GAZETTE ], \array_column( $this->resolved()['links'], 'entity_key' ) );
	}

	/** A re-resolved item whose only extraction is heuristic is forwarded, so a proxy outage does not degrade it for good. */
	public function test_a_reresolved_item_with_only_a_heuristic_extraction_is_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'extraction_recorded', [ 'item_key' => self::ITEM, 'summary' => 'A local bake sale.', 'kind' => 'other', 'relevance' => 0, 'importance' => 0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => 1792950000 ], 'machine:extract-heuristic@9.4.1', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( self::ITEM, $this->resolved()['item']['item_key'] );
		$this->assertSame( [ self::GAZETTE ], \array_column( $this->resolved()['links'], 'entity_key' ) );
	}

	/** A link a curator rejected is not new when the resolver now asserts it: no call is spent on a removed subject. */
	public function test_a_reresolved_item_asserting_a_link_a_curator_rejected_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_proposed' ),
				self::extracted(),
				Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/** A person's confirmation outranks an agent's rejection, as Graph_Query ranks them, so the extractor is sent the link asserted. */
	public function test_a_rejection_a_person_overruled_is_sent_asserted(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_proposed' ),
				[ 'ts' => self::NOW - 600 ] + self::extracted(),
				Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'agent:curator-31:triage', '9.4.1' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ self::GAZETTE, 'asserted' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'] ], $this->resolved()['links'] ) );
	}

	/**
	 * A link a curator confirmed before the item's last extraction is
	 * settled: that extraction ran with it asserted, so its entity's
	 * acceptance later spends no second call.
	 */
	public function test_a_reresolved_item_asserting_a_link_a_curator_confirmed_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_proposed' ),
				[ 'ts' => self::NOW - 600 ] + Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/**
	 * A curator's confirmation after the item's last extraction sends it to
	 * the extractor with the link asserted, though the resolver still only
	 * proposes it.
	 */
	public function test_a_link_confirmed_after_the_extraction_is_forwarded_asserted(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_proposed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				[ 'ts' => self::NOW - 600 ] + self::extracted(),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ self::MESA, 'asserted' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'] ], $this->resolved()['links'] ) );
	}

	/** A survivor's state is its picked link's, subject over mention, as Graph_Query picks it: an asserted mention does not settle a proposed subject. */
	public function test_a_proposed_subject_beside_an_asserted_mention_is_not_settled(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_proposed', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:harbor-gazette-weekly', 'role' => 'subject', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				Intel_Event::make( 'entity_merged', [ 'entity_key' => 'organization:harbor-gazette-weekly', 'into_key' => self::GAZETTE ], 'user:curator-31', '9.4.1' ),
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'role' => 'mention', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( self::ITEM, $this->resolved()['item']['item_key'] );
	}

	/** A stored link to an entity merged away asserts its survivor, so asserting the survivor is not new. */
	public function test_a_reresolved_item_asserting_the_survivor_of_its_merged_link_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:harbor-gazette-weekly', 'role' => 'subject', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				Intel_Event::make( 'entity_merged', [ 'entity_key' => 'organization:harbor-gazette-weekly', 'into_key' => self::GAZETTE ], 'user:curator-31', '9.4.1' ),
				self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/** The NER client is built from the node's configuration at each item, so a runtime set_api_url reaches the next item. */
	public function test_a_runtime_api_url_change_reaches_the_next_items_ner_call(): void {
		Resolver_Node::$llm_factory = null;
		$this->seed_vault( 'ai-proxy-7', [ 'url' => 'https://proxy.test', 'auth_username' => 'u', 'auth_password' => 'proxy-token-7' ] );
		$urls                        = [];
		Proxy_LLM_Client::$http_post = static function ( string $url, array $args ) use ( &$urls ): array {
			$urls[] = $url;
			return [ 'response' => [ 'code' => 200 ], 'body' => (string) \json_encode( [ 'choices' => [ [ 'message' => [ 'content' => '{"orgs": []}' ] ] ] ] ) ];
		};
		$config = $this->resolver->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_vault_id', [ 'ai-proxy-7' ] );
		$config->dispatch( 'set_api_url', [ 'https://first-proxy.test/v1' ] );

		$this->resolver->fill( self::recorded( [ 'title' => 'Regional publishers announce a merger' ] ) );
		$config->dispatch( 'set_api_url', [ 'https://second-proxy.test/v1' ] );
		$this->resolver->fill( self::recorded( [ 'item_key' => 'feed:resolve-4412', 'title' => 'Regional publishers announce a merger' ] ) );

		$this->assertSame( [ 'https://first-proxy.test/v1/chat/completions', 'https://second-proxy.test/v1/chat/completions' ], $urls );
	}

	/** A re-resolution missing its item or its freshness, or naming one the projection no longer holds, is dropped with a line, never thrown. */
	public function test_a_reresolve_without_its_item_is_dropped(): void {
		$stderr = $this->capture_stderr();

		$this->resolver->fill( self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item' => self::item( [] ), 'fresh_after' => 0.0 ] ) );
		$this->resolver->fill( self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM ] ) );
		$this->resolver->fill( self::reresolve( 'feed:pruned-9001', 0.0 ) );

		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a re-resolution it cannot read from intel:items:resolve' ), 'one line, rate-limited' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no stored item to re-resolve: feed:pruned-9001' ) );
	}

	/** With no projection to read the item from, a re-resolution throws, so its Consumer dead-letters it. */
	public function test_a_reresolve_with_no_projection_throws(): void {
		$this->activate();
		$this->expectException( \Newspack_Nodes\Table_Unavailable::class );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );
	}

	/** With no intel-graph table yet, items resolve against an empty index, logged once. */
	public function test_with_no_graph_table_it_resolves_against_an_empty_index(): void {
		$this->seed( self::gazette() );
		$this->activate();
		$stderr = $this->capture_stderr();
		$this->answer( '{"orgs": ["Harbor Gazette"]}' );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame( [ [ 'type' => 'organization', 'name' => 'Harbor Gazette' ] ], $this->resolved()['proposals'] );
		$this->assertSame( [], $this->resolved()['links'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to read entities from' ) );
	}

	public function test_the_writer_is_declared_beside_the_target(): void {
		$this->assertSame( [ 'intel:writer' ], $this->resolver->arguments() );
		$this->assertSame( [ 'intel:resolved:null', 'intel:writer' ], $this->resolver->display_targets() );
	}

	public function test_a_load_without_a_writer_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: writer' );
		( new Resolver_Node() )->arguments( [] );
	}

	/** The resolve topology feeds the items stream through its own Consumer. */
	public function test_the_resolve_topology_wires_the_resolver(): void {
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
		$this->assertSame( 'AI-proxy', $this->read_private( $resolver, 'vault_id' ) );
		$consumer = Core::node( 'intel:items:resolve' );
		$this->assertInstanceOf( Consumer_Node::class, $consumer );
		$this->assertSame( 'resolver', $consumer->target() );
		$this->assertStringEndsWith( '/logs/intel-items.p0', $this->read_private( $consumer, 'source_dir' ) );
		$this->assertStringEndsWith( '/newspack-intelligence-resolve.intel-items-resolve.p0', $this->read_private( $consumer, 'offsetlog_dir' ) );
	}

	/** The composed graph resolves what it records. */
	public function test_the_composed_topology_includes_resolution(): void {
		$this->assertStringContainsString( "include newspack-intelligence-resolve\n", (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/topologies/newspack-intelligence.tsl' ) );
	}

	/**
	 * Answer every NER call with one reply.
	 *
	 * @param string $reply The model's reply.
	 */
	private function answer( string $reply ): void {
		Resolver_Node::$llm_factory = static fn (): LLM_Client => new class( $reply ) implements LLM_Client {
			public function __construct( private string $reply ) {}

			public function chat( array $messages, array $opts = [] ): string {
				return $this->reply;
			}
		};
	}

	/**
	 * The item's link to the Gazette, as the resolver once wrote it.
	 *
	 * @param string $kind `link_asserted` or `link_proposed`.
	 * @return array<string,mixed>
	 */
	private static function link( string $kind ): array {
		return Intel_Event::make( $kind, [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' );
	}

	/** @return array<string,mixed> The item's extraction, as a call once made it. */
	private static function extracted(): array {
		return Intel_Event::make( 'extraction_recorded', [ 'item_key' => self::ITEM, 'summary' => 'Extracted on first resolution.', 'kind' => 'award', 'relevance' => 7, 'importance' => 3, 'sentiment' => 0.2, 'topics' => [], 'retrieved_ts' => 1792950000 ], 'machine:extract@9.4.1', '9.4.1' );
	}

	/** @return list<array<string,mixed>> The events addressed to the writer. */
	private function written(): array {
		$events = [];
		foreach ( $this->out->captured as $message ) {
			if ( 'intel:writer' === $message[ Message::TO ] ) {
				$this->assertSame( Message::TM_STRUCT, $message[ Message::TYPE ] );
				$this->assertSame( 'resolver', $message[ Message::FROM ] );
				$events[] = $message[ Message::VALUE ];
			}
		}
		return $events;
	}

	/** @return array<string,mixed> The last resolution forwarded to the target. */
	private function resolved(): array {
		$forwarded = \array_values( \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
		$this->assertNotSame( [], $forwarded, 'a resolution was forwarded' );
		$last = \end( $forwarded );
		$this->assertSame( Message::TM_STRUCT, $last[ Message::TYPE ] );
		$this->assertSame( 'resolver', $last[ Message::FROM ] );
		return $last[ Message::VALUE ];
	}

	/** @return list<array<string,mixed>> The fixture client: a domain and an alias. */
	private static function gazette(): array {
		return self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'domain' => [ 'harborgazette.org' ] ] );
	}

	/**
	 * An item_recorded message, as the Consumer delivers it.
	 *
	 * @param array<string,mixed> $over Item fields to set.
	 * @return array<int,mixed>
	 */
	private static function recorded( array $over ): array {
		return self::message( Intel_Event::make( 'item_recorded', self::item( $over ), 'machine:source:feed@9.4.1', '9.4.1' ) );
	}

	/**
	 * The item's fields.
	 *
	 * @param array<string,mixed> $over Fields to set.
	 * @return array<string,mixed>
	 */
	private static function item( array $over ): array {
		return \array_merge(
			[ 'item_key' => self::ITEM, 'source' => 'feed', 'tier' => 'public', 'url' => 'https://random.example/x', 'title' => 'A local bake sale', 'body' => '', 'item_ts' => 1792900000, 'retrieved_ts' => 1792950000 ],
			$over
		);
	}

	/**
	 * An item_reresolve record, as the reresolver appends one.
	 *
	 * @param string $item_key    The stored item.
	 * @param float  $fresh_after The tick its signal's index must be read after.
	 * @return array<int,mixed>
	 */
	private static function reresolve( string $item_key, float $fresh_after ): array {
		return self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => $item_key, 'fresh_after' => $fresh_after ] );
	}

	/**
	 * A TM_STRUCT record carrying a VALUE.
	 *
	 * @param mixed $value The VALUE.
	 * @return array<int,mixed>
	 */
	private static function message( mixed $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = \is_array( $value ) ? Message::TM_STRUCT : Message::TM_INFO;
		$message[ Message::FROM ]  = 'intel:items:resolve';
		$message[ Message::VALUE ] = $value;
		return $message;
	}
}

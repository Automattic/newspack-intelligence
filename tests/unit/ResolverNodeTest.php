<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Ontology_Writer;
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
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
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
		$this->resolver->arguments( [ 'intel:writer', 'intel:later' ] );
		$this->resolver->target( 'intel:resolved:null' );
		$this->resolver->sink( $this->out );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
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
				'attempt'   => 0,
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
		$this->assertCount( 2, $this->parked(), 'the empty index is held until its TTL' );

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
		$this->answer( '{"orgs": ["Harbor Gazette Weekly", "Acme Widgets Corporation"]}', $asked );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( 0, $asked );
		$this->assertSame( [], $this->written() );
		$this->assertSame( [ [], [] ], [ $this->resolved()['links'], $this->resolved()['proposals'] ] );
	}

	/**
	 * A re-resolved item whose extraction was given its asserted links writes
	 * them again and forwards nothing.
	 */
	public function test_a_reresolved_item_already_extracted_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				...self::extracted( self::GAZETTE ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ 'link_asserted', self::GAZETTE ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() ) );
		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/**
	 * A link asserted that the item's last extraction was not given is not
	 * settled: that extraction ran without the subject, so the item is
	 * forwarded again, whenever the link's own event was written.
	 */
	public function test_a_link_its_extraction_was_not_given_is_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				...self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

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
				...self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

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
				...self::extracted(),
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
				...self::extracted(),
				Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'agent:curator-31:triage', '9.4.1' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ self::GAZETTE, 'asserted' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'] ], $this->resolved()['links'] ) );
	}

	/**
	 * A link a curator confirmed that the item's last extraction was given is
	 * settled, so its entity's acceptance later spends no second call.
	 */
	public function test_a_reresolved_item_asserting_a_link_a_curator_confirmed_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_proposed' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				...self::extracted( self::GAZETTE ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/**
	 * A confirmation the item's last extraction was not given sends the item
	 * to the extractor with the link asserted, though it was made before that
	 * extraction: one the projection had not yet recorded when the item was
	 * extracted is still owed its claims.
	 */
	public function test_a_confirmation_the_extraction_was_not_given_is_forwarded_asserted(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_proposed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				[ 'ts' => self::NOW - 900 ] + Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				...self::extracted(),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ self::MESA, 'asserted' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'] ], $this->resolved()['links'] ) );
	}

	/**
	 * An extraction given a key merged away since settles its survivor, on
	 * every later pass: a merge costs no call.
	 */
	public function test_a_reresolved_item_asserting_the_survivor_of_its_merged_link_is_not_forwarded(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:harbor-gazette-weekly', 'role' => 'subject', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				...self::extracted( 'organization:harbor-gazette-weekly' ),
				Intel_Event::make( 'entity_merged', [ 'entity_key' => 'organization:harbor-gazette-weekly', 'into_key' => self::GAZETTE ], 'user:curator-31', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );
		$this->seed( $this->written() );
		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/** The NER client is built from the node's configuration at each item, so a runtime set_api_url reaches the next item. */
	public function test_a_runtime_api_url_change_reaches_the_next_items_ner_call(): void {
		Resolver_Node::$llm_factory = null;
		$this->seed( self::gazette() );
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

	/**
	 * A re-resolution missing its item or its freshness, carrying a `ner`, an
	 * `attempt` or a `parked_at` that does not read, or naming one the
	 * projection no longer holds, is dropped with a line, never thrown.
	 */
	public function test_a_reresolve_without_its_item_is_dropped(): void {
		$this->seed( self::gazette() );
		$stderr = $this->capture_stderr();

		$this->resolver->fill( self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item' => self::item( [] ), 'fresh_after' => 0.0 ] ) );
		$this->resolver->fill( self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM ] ) );
		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'ner' => 'yes' ] ) );
		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'attempt' => '2' ] ) );
		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'parked_at' => 'yesterday' ] ) );
		$this->resolver->fill( self::reresolve( 'feed:pruned-9001', 0.0 ) );

		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a re-resolution it cannot read from intel:items:resolve' ), 'one line, rate-limited' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no stored item to re-resolve: feed:pruned-9001' ) );
	}

	/** With no projection to read the item from, a re-resolution against an index holding entities throws, so its Consumer dead-letters it. */
	public function test_a_reresolve_with_no_projection_throws(): void {
		$this->seed( self::gazette() );
		$this->resolver->fill( self::recorded( [] ) );
		$this->activate();
		$this->expectException( \Newspack_Nodes\Table_Unavailable::class );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );
	}

	/** With no intel-graph table yet, the index is empty, logged once, and every item is held. */
	public function test_with_no_graph_table_items_are_held(): void {
		$this->seed( self::gazette() );
		$this->activate();
		$stderr = $this->capture_stderr();
		$this->answer( '{"orgs": ["Harbor Gazette"]}' );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertCount( 2, $this->parked() );
		$this->assertSame( [], $this->written() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to read entities from' ) );
	}

	/**
	 * An index holding no live entity resolves nothing: a recorded item parks
	 * on the later queue as a re-resolution owed its NER step, a minute on,
	 * and a rate-limited line names the fix.
	 */
	public function test_an_empty_index_holds_a_recorded_item(): void {
		$stderr = $this->capture_stderr();
		$this->answer( '{"orgs": ["Harbor Gazette"]}' );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );
		$this->resolver->fill( self::recorded( [ 'item_key' => 'feed:resolve-4411' ] ) );

		$this->assertSame(
			[
				[ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => 0, 'ner' => true, 'not_before' => self::NOW + 60 ],
				[ 'event' => Resolver_Node::RERESOLVE, 'item_key' => 'feed:resolve-4411', 'fresh_after' => 0, 'ner' => true, 'not_before' => self::NOW + 60 ],
			],
			$this->parked()
		);
		$this->assertSame( [], $this->written() );
		$this->assertSame( 60, Resolver_Node::HOLD_S );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'wp newspack-intelligence ontology seed' ), 'one line, rate-limited' );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'ontology reresolve --since=<day> re-resolves it' ), 'the line names the recovery after an age-out' );
	}

	/**
	 * A re-resolution held by an empty index parks again unchanged but for its
	 * time, keeping when it was first parked, so the queue's age bounds the wait.
	 */
	public function test_an_empty_index_holds_a_reresolve_unchanged(): void {
		Core::$now = self::NOW + 7;

		$this->resolver->fill( self::reresolve( self::ITEM, self::NOW + 3, [ 'attempt' => 3, 'parked_at' => self::NOW - 500 ] ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => self::ITEM, 'fresh_after' => self::NOW + 3, 'attempt' => 3, 'parked_at' => self::NOW - 500, 'not_before' => self::NOW + 67 ] ],
			$this->parked()
		);
		$this->assertSame( [], $this->written() );
	}

	/** A held item's replay runs the NER step it was owed, and is sent for extraction as never attempted. */
	public function test_a_held_items_replay_runs_ner(): void {
		$this->seed( self::gazette(), [ Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ) ] );
		$this->answer( '{"orgs": ["Harbor Gazette Weekly", "Acme Widgets Corporation"], "people": [], "locations": []}' );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'ner' => true ] ) );

		$this->assertSame( [ 'link_proposed', 'entity_proposed' ], \array_column( $this->written(), 'event' ) );
		$this->assertSame( [ false, 0 ], [ $this->resolved()['extracted'], $this->resolved()['attempt'] ] );
		$this->assertSame( [], $this->parked() );
	}

	/** A replay owed NER whose item the projection does not hold yet parks again rather than dropping. */
	public function test_a_ner_replay_the_projection_does_not_hold_yet_parks_again(): void {
		$this->seed( self::gazette() );
		Core::$now = self::NOW + 11;
		$stderr    = $this->capture_stderr();

		$this->resolver->fill( self::reresolve( 'feed:not-yet-77', 0.0, [ 'ner' => true, 'attempt' => 2, 'parked_at' => 1792990000 ] ) );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => 'feed:not-yet-77', 'fresh_after' => 0.0, 'ner' => true, 'attempt' => 2, 'parked_at' => 1792990000, 'not_before' => self::NOW + 71 ] ],
			$this->parked()
		);
		$this->assertCount( 0, self::stderr_lines( $stderr, 'no stored item to re-resolve' ) );
	}

	/** A re-resolution carries its attempt on to the extractor. */
	public function test_a_reresolve_forwards_its_attempt(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'extraction_recorded', [ 'item_key' => self::ITEM, 'summary' => 'A local bake sale.', 'kind' => 'other', 'relevance' => 0, 'importance' => 0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => 1792950000 ], 'machine:extract-heuristic@9.4.1', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'attempt' => 4 ] ) );

		$this->assertSame( [ false, 4 ], [ $this->resolved()['extracted'], $this->resolved()['attempt'] ] );
	}

	/** A re-resolution retracts each stored link it no longer writes, by its raw key and role. */
	public function test_a_reresolve_retracts_a_link_it_no_longer_finds(): void {
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				self::stored_link( self::MESA, 'mention', 'name' ),
				self::stored_link( 'organization:harbor-gazette-weekly', 'subject', 'name' ),
				...self::extracted( self::GAZETTE ),
			],
			self::merged( 'organization:harbor-gazette-weekly', self::GAZETTE )
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		$this->assertSame(
			[
				[ 'link_asserted', self::GAZETTE, 'subject' ],
				[ 'link_retracted', 'organization:harbor-gazette-weekly', 'subject' ],
				[ 'link_retracted', self::MESA, 'mention' ],
			],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['role'] ], $this->written() )
		);
		$retraction = $this->written()[2];
		Intel_Event::validate( $retraction );
		$this->assertSame( [ self::ITEM, 1792950000, "machine:resolve@{$version}" ], [ $retraction['item_key'], $retraction['retrieved_ts'], $retraction['actor'] ] );
	}

	/** A link whose role the resolution changed is retracted in its old role. */
	public function test_a_link_whose_role_changed_is_retracted_in_its_old_role(): void {
		$this->seed(
			self::gazette(),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( self::GAZETTE, 'mention', 'name' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame(
			[ [ 'link_asserted', self::GAZETTE, 'subject' ], [ 'link_retracted', self::GAZETTE, 'mention' ] ],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['role'] ], $this->written() )
		);
	}

	/**
	 * A link a curator confirmed is never retracted, and a NER link stays when
	 * the resolution ran no NER step, since nothing looked for it again.
	 */
	public function test_a_confirmed_or_unsearched_link_is_not_retracted(): void {
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				self::stored_link( 'organization:mesa-courier-weekly', 'mention', 'name' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy', 'link_proposed' ),
				...self::extracted( self::GAZETTE, self::MESA ),
			],
			self::merged( 'organization:mesa-courier-weekly', self::MESA )
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ 'link_asserted' ], \array_column( $this->written(), 'event' ) );
	}

	/**
	 * A re-resolution that ran no NER step sends the extractor the item's
	 * stored NER links as they were stored, writing no event for them, so a
	 * retry reaches an item whose only links NER found.
	 */
	public function test_a_plain_reresolve_sends_the_stored_ner_links(): void {
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy' ),
				Intel_Event::make( 'extraction_recorded', [ 'item_key' => self::ITEM, 'summary' => 'Regional publishers announce a merger.', 'kind' => 'other', 'relevance' => 0, 'importance' => 0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => 1792950000 ], 'machine:extract-heuristic@9.4.1', '9.4.1' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'attempt' => 2 ] ) );

		$this->assertSame( [], $this->written(), 'a kept row is not written again' );
		$this->assertSame(
			[ [ 'entity_key' => 'organization:quarry-ledger', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.85, 'state' => 'asserted' ] ],
			$this->resolved()['links']
		);
		$this->assertSame( [ false, 2 ], [ $this->resolved()['extracted'], $this->resolved()['attempt'] ] );
	}

	/**
	 * A stored NER link a curator rejected stays out of what the extractor is
	 * sent, one a curator confirmed is sent asserted, and a link the
	 * resolution found itself wins over the stored row.
	 */
	public function test_the_stored_ner_links_sent_follow_the_judgments_and_the_resolution(): void {
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a', 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy' ),
				Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:quarry-ledger', 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				self::stored_link( self::MESA, 'mention', 'ner_fuzzy', 'link_proposed' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'item_url' => 'https://harborgazette.org/a' ], 'user:curator-31', '9.4.1' ),
				self::stored_link( self::GAZETTE, 'mention', 'ner_fuzzy', 'link_proposed' ),
			]
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame(
			[
				[ 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'asserted' ],
				[ 'entity_key' => self::MESA, 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.85, 'state' => 'asserted' ],
			],
			$this->resolved()['links']
		);
	}

	/** A NER call that failed answered nothing, so a stored NER link stays and is sent as stored. */
	public function test_a_failed_ner_call_keeps_and_sends_the_stored_ner_link(): void {
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'subject', 'ner_fuzzy' ),
			]
		);
		$this->answer( 'throw' );
		$stderr = $this->capture_stderr();

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'ner' => true ] ) );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'NER call failed: proxy 502' ) );
		$this->assertSame( [], $this->written() );
		$this->assertSame( [ [ 'organization:quarry-ledger', 'subject', 'ner_fuzzy', 0.85, 'asserted' ] ], \array_map( 'array_values', $this->resolved()['links'] ) );
	}

	/** NER skipped because an earlier step found a link looked for nothing, so a stored NER link stays and is sent. */
	public function test_a_skipped_ner_step_keeps_and_sends_the_stored_ner_link(): void {
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'url' => 'https://harborgazette.org/a' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy' ),
			]
		);
		$this->answer( '{"orgs": [], "people": [], "locations": []}' );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'ner' => true ] ) );

		$this->assertSame( [ [ 'link_asserted', self::GAZETTE ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() ) );
		$this->assertSame( [ self::GAZETTE, 'organization:quarry-ledger' ], \array_column( $this->resolved()['links'], 'entity_key' ) );
	}

	/** While the projector has not projected every ontology event, a recorded item parks; the hold says why. */
	public function test_a_projection_behind_the_ontology_holds_a_recorded_item(): void {
		$this->seed( self::gazette() );
		Ontology_Writer::append( self::entity( self::MESA, 'Mesa Courier' ) );
		$stderr = $this->capture_stderr();

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame( [ self::ITEM ], \array_column( $this->parked(), 'item_key' ) );
		$this->assertSame( [], $this->written() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'the projector has not caught up with the ontology stream' ) );
	}

	/** Once the projector's cursor stands at the ontology stream's end, an index holding entities resolves. */
	public function test_a_projection_caught_up_with_the_ontology_resolves(): void {
		$this->seed( self::gazette() );
		Ontology_Writer::append( self::entity( self::MESA, 'Mesa Courier' ) );
		self::commit_ontology_cursor();

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame( [], $this->parked() );
		$this->assertSame( [ self::GAZETTE ], \array_column( $this->resolved()['links'], 'entity_key' ) );
	}

	/**
	 * The cursor is read before the index: a flush and commit landing between
	 * the two reads cannot pass an index read before it, so the item holds.
	 */
	public function test_a_commit_between_the_cursor_and_the_index_reads_holds(): void {
		$this->seed( self::gazette() );
		Ontology_Writer::append( self::entity( self::MESA, 'Mesa Courier' ) );
		$committed                         = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$committed ): mixed {
			if ( 0 === $committed++ ) {
				self::commit_ontology_cursor();
			}
			return \Newspack_Nodes\Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame( [ self::ITEM ], \array_column( $this->parked(), 'item_key' ) );
		$this->assertSame( [], $this->written() );
	}

	/** A stored NER link in another partition's file is sent to the extractor, though this worker cannot retract it. */
	public function test_a_stored_ner_link_in_another_partition_is_sent_but_not_retracted(): void {
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[ Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ) ]
		);
		$other = $this->projector_graph( 'intel-graph-b', 'projector-b' );
		self::deliver( $other, self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy', 'link_proposed' ) );
		$other->save_state();
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( [ 0 => Table_Node::file( 'intel-graph', 0 ), 1 => Table_Node::file( 'intel-graph-b', 0 ) ], Intel_Graph_Row_Schema::version() ) );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [], $this->written() );
		$this->assertSame( [ [ 'organization:quarry-ledger', 'mention', 'ner_fuzzy', 0.85, 'proposed' ] ], \array_map( 'array_values', $this->resolved()['links'] ) );
	}

	/**
	 * A survivor's NER link is sent though its top stored row is a merged
	 * key's name link: the kept NER links are picked among NER rows alone.
	 */
	public function test_a_survivors_ner_link_is_sent_beneath_a_stronger_name_row(): void {
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:mesa-courier-weekly', 'role' => 'subject', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' ),
				self::stored_link( self::MESA, 'subject', 'ner_fuzzy' ),
			],
			self::merged( 'organization:mesa-courier-weekly', self::MESA )
		);

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0 ) );

		$this->assertSame( [ [ self::MESA, 'subject', 'ner_fuzzy', 0.85, 'asserted' ] ], \array_map( 'array_values', $this->resolved()['links'] ) );
	}

	/** Commit the project topology's ontology cursor at the stream's end, as the projector's Consumer does. */
	private static function commit_ontology_cursor(): void {
		$base     = \Newspack_Nodes\Config::get_base_directory();
		$consumer = new Consumer_Node();
		$consumer->name( 'intel:ontology:consumer' );
		$consumer->sink( new Capture_Sink_Node() );
		$consumer->arguments( [ "{$base}/logs/intel-ontology.p0", "{$base}/offsets/newspack-intelligence-project.intel-ontology.p0", "{$base}/deadletter/newspack-intelligence-project.intel-ontology.p0" ] );
		$consumer->drain();
		$consumer->checkpoint( true );
		$consumer->remove_node();
	}

	/** A retraction reads this worker's own partition alone: a row another partition's file holds is not its to delete. */
	public function test_a_link_in_another_partitions_file_is_not_retracted(): void {
		$this->seed( self::gazette(), self::entity( self::MESA, 'Mesa Courier' ), [ self::stored_link( 'organization:salem-ledger', 'mention', 'name' ) ] );
		$other = $this->projector_graph( 'intel-graph-b', 'projector-b' );
		self::deliver( $other, self::stored_link( self::MESA, 'mention', 'name' ) );
		$other->save_state();
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( [ 0 => Table_Node::file( 'intel-graph', 0 ), 1 => Table_Node::file( 'intel-graph-b', 0 ) ], Intel_Graph_Row_Schema::version() ) );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame(
			[ [ 'link_asserted', self::GAZETTE ], [ 'link_retracted', 'organization:salem-ledger' ] ],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() )
		);
	}

	/** A NER link a resolution's own NER step no longer finds is retracted. */
	public function test_a_ner_link_its_ner_step_no_longer_finds_is_retracted(): void {
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( [ 'title' => 'Regional publishers announce a merger' ] ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'ner_fuzzy', 'link_proposed' ),
			]
		);
		$this->answer( '{"orgs": [], "people": [], "locations": []}' );

		$this->resolver->fill( self::reresolve( self::ITEM, 0.0, [ 'ner' => true ] ) );

		$this->assertSame( [ [ 'link_retracted', 'organization:quarry-ledger', 'mention' ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['role'] ], $this->written() ) );
	}

	/** A recorded item retracts what the projection already holds of its links and it no longer writes. */
	public function test_a_recorded_item_retracts_a_stored_link_it_no_longer_finds(): void {
		$this->seed( self::gazette(), self::entity( self::MESA, 'Mesa Courier' ), [ self::stored_link( self::MESA, 'subject', 'name' ) ] );

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame(
			[ [ 'link_asserted', self::GAZETTE ], [ 'link_retracted', self::MESA ] ],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() )
		);
	}

	/** A recorded item whose stored links cannot be read retracts nothing, logged, and still resolves. */
	public function test_a_recorded_item_with_no_projection_retracts_nothing(): void {
		$this->seed( self::gazette(), [ self::stored_link( self::MESA, 'subject', 'name' ) ] );
		$this->resolver->fill( self::recorded( [ 'item_key' => 'feed:warm-1' ] ) );
		$this->activate();
		$stderr = $this->capture_stderr();

		$this->resolver->fill( self::recorded( [ 'url' => 'https://harborgazette.org/a' ] ) );

		$this->assertSame( [ [ 'link_asserted', self::ITEM ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['item_key'] ], $this->written() ) );
		$this->assertSame( self::ITEM, $this->resolved()['item']['item_key'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to read the stored item from; resolving it fresh, retracting none' ) );
	}

	/**
	 * An item recorded again, as a fresh worker records it, whose links its
	 * last extraction was given is a re-resolution: no NER call, nothing
	 * forwarded, so its extraction is not replaced.
	 */
	public function test_a_rerecorded_item_with_settled_links_is_not_forwarded_and_runs_no_ner(): void {
		$item = self::item( [ 'title' => 'Ferry operators publish a timetable' ] );
		$this->seed(
			self::gazette(),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', $item, 'machine:source:feed@9.4.1', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'subject', 'ner_fuzzy' ),
				...self::extracted( 'organization:quarry-ledger' ),
			]
		);
		$asked = 0;
		$this->answer( '{"orgs": ["Quarry Ledger Weekly"], "people": [], "locations": []}', $asked );

		$this->resolver->fill( self::recorded( [ 'title' => 'Ferry operators publish a timetable' ] ) );

		$this->assertSame( 0, $asked, 'no NER call' );
		$this->assertSame( [], $this->written(), 'the unsearched NER row is kept' );
		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	/**
	 * An item recorded again with an asserted link its last extraction was
	 * not given is forwarded as a re-resolution: the curators' verdicts in
	 * force, a rejected link left out and a confirmed one asserted.
	 */
	public function test_a_rerecorded_item_with_an_unsettled_link_is_forwarded_with_the_verdicts(): void {
		$over = [ 'url' => 'https://harborgazette.org/b', 'body' => 'Mesa Courier reprinted it.' ];
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			[
				Intel_Event::make( 'item_recorded', self::item( $over ), 'machine:source:feed@9.4.1', '9.4.1' ),
				Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::MESA, 'item_url' => 'https://harborgazette.org/b' ], 'user:curator-47', '9.4.1' ),
				self::stored_link( 'organization:quarry-ledger', 'mention', 'name', 'link_proposed' ),
				Intel_Event::make( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:quarry-ledger', 'item_url' => 'https://harborgazette.org/b' ], 'user:curator-47', '9.4.1' ),
				...self::extracted(),
			]
		);
		$asked = 0;
		$this->answer( '{"orgs": [], "people": [], "locations": []}', $asked );

		$this->resolver->fill( self::recorded( $over ) );

		$this->assertSame( 0, $asked );
		$this->assertSame(
			[
				'item'      => self::item( $over ),
				'extracted' => true,
				'attempt'   => 0,
				'links'     => [
					[ 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'asserted' ],
					[ 'entity_key' => 'organization:quarry-ledger', 'role' => 'mention', 'method' => 'name', 'confidence' => 0.85, 'state' => 'asserted' ],
				],
				'proposals' => [],
			],
			$this->resolved()
		);
	}

	/** A first recording, whose item the projection does not hold yet, runs NER and is sent for extraction as never attempted. */
	public function test_a_first_recording_runs_ner_and_forwards_unextracted(): void {
		$this->seed( self::gazette(), [ self::stored_link( self::MESA, 'mention', 'name' ) ] );
		$asked = 0;
		$this->answer( '{"orgs": ["Harbor Gazette Weekly"], "people": [], "locations": []}', $asked );

		$this->resolver->fill( self::recorded( [ 'title' => 'Ferry operators publish a timetable' ] ) );

		$this->assertSame( 1, $asked );
		$this->assertSame( [ [ 'link_proposed', self::GAZETTE ], [ 'link_retracted', self::MESA ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], $this->written() ) );
		$this->assertSame( [ false, 0 ], [ $this->resolved()['extracted'], $this->resolved()['attempt'] ] );
	}

	/**
	 * A first recording whose items row the projector flushed before the
	 * resolver reached it has not been resolved: NER runs, and the item is
	 * sent for extraction as never extracted.
	 */
	public function test_a_first_recording_already_projected_runs_ner(): void {
		$over = [ 'title' => 'Coastal publishers form a press alliance' ];
		$this->seed( self::gazette(), [ Intel_Event::make( 'item_recorded', self::item( $over ), 'machine:source:feed@9.4.1', '9.4.1' ) ] );
		$asked = 0;
		$this->answer( '{"orgs": ["Harbor Gazette Weekly"], "people": [], "locations": []}', $asked );

		$this->resolver->fill( self::recorded( $over ) );

		$this->assertSame( 1, $asked );
		$this->assertSame( [ [ 'link_proposed', self::GAZETTE, 'ner_fuzzy' ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['method'] ], $this->written() ) );
		$this->assertSame( [ false, 0, [ self::GAZETTE ] ], [ $this->resolved()['extracted'], $this->resolved()['attempt'], \array_column( $this->resolved()['links'], 'entity_key' ) ] );
	}

	/**
	 * A first recording whose item the projection does not hold yet, but
	 * whose rejected link the ontology stream already projected, as a replay
	 * can, leaves that link out of what it forwards.
	 */
	public function test_a_first_recording_not_projected_honours_a_projected_rejection(): void {
		$over = [ 'url' => 'https://harborgazette.org/d', 'body' => 'Mesa Courier reprinted it.' ];
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			[ Intel_Event::make( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.org/d' ], 'user:curator-58', '9.4.1' ) ]
		);

		$this->resolver->fill( self::recorded( $over ) );

		$this->assertSame( [ self::MESA ], \array_column( $this->resolved()['links'], 'entity_key' ) );
		$this->assertSame( [ false, 0, self::item( $over ) ], [ $this->resolved()['extracted'], $this->resolved()['attempt'], $this->resolved()['item'] ] );
	}

	/** A repeat of an item whose NER step named nobody, so nothing linked or extracted it, runs NER again. */
	public function test_a_repeat_of_an_item_never_linked_runs_ner_again(): void {
		$over = [ 'title' => 'Coastal publishers form a press alliance' ];
		$this->seed( self::gazette(), [ Intel_Event::make( 'item_recorded', self::item( $over ), 'machine:source:feed@9.4.1', '9.4.1' ) ] );
		$asked = 0;
		$this->answer( '{"orgs": [], "people": [], "locations": []}', $asked );

		$this->resolver->fill( self::recorded( $over ) );
		$this->resolver->fill( self::recorded( $over ) );

		$this->assertSame( 2, $asked );
		$this->assertSame( [], $this->written() );
	}

	/** An item recorded again still writes its link events and retracts a stored link it no longer finds. */
	public function test_a_rerecorded_item_writes_its_links_and_retractions(): void {
		$over = [ 'url' => 'https://harborgazette.org/c' ];
		$this->seed(
			self::gazette(),
			self::entity( self::MESA, 'Mesa Courier' ),
			[
				Intel_Event::make( 'item_recorded', self::item( $over ), 'machine:source:feed@9.4.1', '9.4.1' ),
				self::link( 'link_asserted' ),
				self::stored_link( self::MESA, 'mention', 'alias' ),
				...self::extracted( self::GAZETTE ),
			]
		);

		$this->resolver->fill( self::recorded( $over ) );

		$this->assertSame(
			[ [ 'link_asserted', self::GAZETTE, 'subject' ], [ 'link_retracted', self::MESA, 'mention' ] ],
			\array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['role'] ], $this->written() )
		);
		$this->assertSame( [], \array_filter( $this->out->captured, static fn ( array $m ): bool => 'intel:resolved:null' === $m[ Message::TO ] ) );
	}

	public function test_the_writer_and_the_later_queue_are_declared_beside_the_target(): void {
		$this->assertSame( [ 'intel:writer', 'intel:later' ], $this->resolver->arguments() );
		$this->assertSame( [ 'intel:resolved:null', 'intel:writer', 'intel:later' ], $this->resolver->display_targets() );
	}

	public function test_a_load_without_a_writer_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: writer' );
		( new Resolver_Node() )->arguments( [] );
	}

	public function test_a_load_without_a_later_queue_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: later' );
		( new Resolver_Node() )->arguments( [ 'intel:writer' ] );
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
	 * Answer every NER call with one reply, or throw when the reply is `throw`,
	 * counting each call.
	 *
	 * @param string $reply The model's reply.
	 * @param int    $asked The calls made, counted up.
	 */
	private function answer( string $reply, int &$asked = 0 ): void {
		Resolver_Node::$llm_factory = static function () use ( $reply, &$asked ): LLM_Client {
			return new class( $reply, $asked ) implements LLM_Client {
				public function __construct( private string $reply, private int &$asked ) {}

				public function chat( array $messages, array $opts = [] ): string {
					++$this->asked;
					if ( 'throw' === $this->reply ) {
						throw new \RuntimeException( 'proxy 502' );
					}
					return $this->reply;
				}
			};
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

	/**
	 * A stored link of the item to any entity, as the resolver once wrote it.
	 *
	 * @param string $entity The entity.
	 * @param string $role   Its role.
	 * @param string $method How it was found.
	 * @param string $kind   `link_asserted` or `link_proposed`.
	 * @return array<string,mixed>
	 */
	private static function stored_link( string $entity, string $role, string $method, string $kind = 'link_asserted' ): array {
		return Intel_Event::make( $kind, [ 'item_key' => self::ITEM, 'entity_key' => $entity, 'role' => $role, 'method' => $method, 'confidence' => 0.85, 'retrieved_ts' => 1792950000 ], 'machine:resolve@9.4.1', '9.4.1' );
	}

	/**
	 * The item's extraction, as a call once made it, and the entities it was
	 * given as asserted.
	 *
	 * @param string ...$subjects The entities the extraction was given asserted.
	 * @return list<array<string,mixed>>
	 */
	private static function extracted( string ...$subjects ): array {
		return [
			Intel_Event::make( 'extraction_recorded', [ 'item_key' => self::ITEM, 'summary' => 'Extracted on first resolution.', 'kind' => 'award', 'relevance' => 7, 'importance' => 3, 'sentiment' => 0.2, 'topics' => [], 'retrieved_ts' => 1792950000 ], 'machine:extract@9.4.1', '9.4.1' ),
			Intel_Event::make( 'subjects_extracted', [ 'item_key' => self::ITEM, 'entity_keys' => $subjects, 'retrieved_ts' => 1792950000 ], 'machine:extract@9.4.1', '9.4.1' ),
		];
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

	/** @return list<array<string,mixed>> The records parked on the later queue. */
	private function parked(): array {
		$parked = [];
		foreach ( $this->out->captured as $message ) {
			if ( 'intel:later' === $message[ Message::TO ] ) {
				$this->assertSame( [ Message::TM_STRUCT, 'resolver' ], [ $message[ Message::TYPE ], $message[ Message::FROM ] ] );
				$this->assertSame( $message[ Message::VALUE ]['item_key'], $message[ Message::KEY ], 'a parked record is keyed by its item' );
				$parked[] = $message[ Message::VALUE ];
			}
		}
		return $parked;
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
	 * @param string              $item_key    The stored item.
	 * @param float               $fresh_after The tick its signal's index must be read after.
	 * @param array<string,mixed> $more        Its `ner`, `attempt` and `parked_at`, when it carries them.
	 * @return array<int,mixed>
	 */
	private static function reresolve( string $item_key, float $fresh_after, array $more = [] ): array {
		return self::message( [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => $item_key, 'fresh_after' => $fresh_after ] + $more );
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

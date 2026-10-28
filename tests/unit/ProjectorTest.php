<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Projector_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/projects-intel-graph.php';

/**
 * The projector replays both event streams into the intel-graph Row_Table:
 * every op an `ignore` or a `set`, so a replay changes nothing and an entity
 * named before it is created ends as the created row.
 */
final class ProjectorTest extends TestCase {
	use Projects_Intel_Graph;

	private const NOW = 1793000000.25;

	private const ITEM = 'feed:abc-7731';

	private const GAZETTE = 'organization:harbor-gazette';

	private const MACHINE = 'machine:resolve@9.4.1';

	/** When the fixture item was retrieved: 700 days before NOW, inside its retention. */
	private const RETRIEVED = 1732520000;

	private Projector_Node $projector;

	/** The runtime base this test's files live under. */
	private string $base = '';

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->make_temp_dir( 'intel-projector-' );
		$this->use_base_dir( $this->base );
		Core::$now              = self::NOW;
		Core::$var['partition'] = '0';
		Topology_Registry::reset();
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		$this->projector = $this->projector_graph();
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_an_item_and_its_links_project_to_rows(): void {
		$this->project( [ $this->item(), $this->link( 'link_asserted' ), $this->extraction() ] );

		$day = \intdiv( self::RETRIEVED, 86400 );
		$this->assertSame(
			[ [ 1, self::ITEM, 'feed', 'public', 'https://www.HarborGazette.example/2026/10/award/?utm_source=np&b=2&a=1#top', 'harborgazette.example/2026/10/award?a=1&b=2', 'The Harbor Gazette Wins a State Award!', 'harbor gazette wins a state award', 'The Gazette took first place.', 1792900000, self::RETRIEVED, $day ] ],
			self::graph_rows( 'items' )
		);
		$this->assertSame(
			[
				[ self::ITEM, self::GAZETTE, 'subject', 'identifier', 1.0, 'asserted', self::MACHINE, self::NOW, $day ],
				[ self::ITEM, 'topic:awards', 'topic', 'extract', 1.0, 'asserted', 'machine:extract@9.4.1', self::NOW, $day ],
			],
			self::graph_rows( 'links', 'entity_key' )
		);
		$this->assertSame( [ [ 1, self::ITEM, 'Harbor Gazette won the state award.', 'award', 8.0, 6.0, 0.5, 'machine:extract@9.4.1', $day ] ], self::graph_rows( 'extractions' ) );
		$this->assertSame( [], self::graph_rows( 'entities' ), 'a link writes no entity row; readers join to one' );
		$this->assertSame( [], self::graph_rows( 'entity_states' ) );
		$this->assertSame( [], self::graph_rows( 'events' ), 'items-stream events are not kept in events' );
	}

	/**
	 * An extraction replaces the item's claims and topic links, so one
	 * yielding fewer leaves none of the earlier behind; a resolver's link
	 * stays.
	 */
	public function test_a_reextraction_replaces_the_items_claims_and_topics(): void {
		$claim = static fn ( int $ord, string $object ): array => Intel_Event::make( 'claim_asserted', [ 'item_key' => self::ITEM, 'ord' => $ord, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => $object, 'confidence' => 0.9, 'retrieved_ts' => self::RETRIEVED ], 'machine:extract@9.4.1', '' );
		$this->project(
			[
				$this->item(),
				$this->link( 'link_asserted' ),
				[ 'topics' => [ 'topic:awards', 'topic:local-news' ] ] + $this->extraction(),
				$claim( 0, 'Peabody Award' ),
				$claim( 1, 'State Press Award' ),
				$claim( 2, 'Murrow Award' ),
				[ 'topics' => [ 'topic:local-news' ] ] + $this->extraction(),
				$claim( 0, 'Pulitzer Prize' ),
			]
		);

		$this->assertSame( [ [ 0, 'Pulitzer Prize' ] ], self::graph_rows( 'claims', 'ord', 'ord, object_text' ) );
		$this->assertSame( [ [ self::GAZETTE, 'subject' ], [ 'topic:local-news', 'topic' ] ], self::graph_rows( 'links', 'entity_key', 'entity_key, role' ) );
	}

	/** An item is written once: its first retrieval is the provenance kept. */
	public function test_an_item_is_written_once(): void {
		$this->project( [ $this->item(), [ 'title' => 'Harbor Gazette award, updated', 'retrieved_ts' => self::NOW + 3600 ] + $this->item() ] );

		$this->assertSame( [ [ 'The Harbor Gazette Wins a State Award!', self::RETRIEVED ] ], self::graph_rows( 'items', '1', 'title, retrieved_ts' ) );
	}

	/** A link by name and a topic chosen by extraction are two rows for one entity. */
	public function test_a_resolver_link_and_a_topic_link_to_one_entity_both_survive(): void {
		$this->project(
			[
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => 'topic:ai', 'role' => 'mention', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => self::RETRIEVED ], self::MACHINE, '' ),
				[ 'topics' => [ 'topic:ai' ] ] + $this->extraction(),
			]
		);

		$this->assertSame(
			[ [ 'topic:ai', 'mention', 'name' ], [ 'topic:ai', 'topic', 'extract' ] ],
			self::graph_rows( 'links', 'role', 'entity_key, role, method' )
		);
	}

	public function test_replaying_the_same_events_twice_yields_identical_rows(): void {
		$events = $this->every_kind();
		$this->assertEqualsCanonicalizing( \array_keys( Intel_Event::KINDS ), \array_unique( \array_column( $events, 'event' ) ), 'the replay covers every kind' );

		$this->project( $events );
		$first = self::dump();
		$this->project( $events );

		$this->assertSame( $first, self::dump() );
		$this->assertNotSame( [], $first['events'] );
	}

	/**
	 * Review Focus 2: the two streams project to the same rows whichever
	 * arrives first, and replaying either changes nothing. Every table and
	 * every column is compared, autoincrement ids included: each table is
	 * written by one stream, whose order every run keeps.
	 */
	public function test_both_streams_converge_in_any_order(): void {
		$items    = [];
		$ontology = [];
		foreach ( $this->every_kind() as $event ) {
			if ( Intel_Event::ITEMS === Intel_Event::stream_of( $event['event'] ) ) {
				$items[] = $event;
			} else {
				$ontology[] = $event;
			}
		}
		$runs = [
			'items-first'    => [ $items, $ontology ],
			'ontology-first' => [ $ontology, $items ],
			'each-twice'     => [ $ontology, $items, $ontology, $items ],
		];

		$dumps = [];
		foreach ( $runs as $run => $passes ) {
			$projector = $this->projector_graph( "intel-graph-{$run}", "projector-{$run}" );
			foreach ( $passes as $pass ) {
				foreach ( $pass as $event ) {
					self::deliver( $projector, $event );
				}
				$projector->save_state();
			}
			$dumps[ $run ] = self::dump( "intel-graph-{$run}" );
		}

		$this->assertNotSame( [], $dumps['items-first']['links'] );
		$this->assertNotSame( [], $dumps['items-first']['entities'] );
		$this->assertSame( $dumps['items-first'], $dumps['ontology-first'] );
		$this->assertSame( $dumps['items-first'], $dumps['each-twice'] );
	}

	public function test_an_entity_named_before_it_is_created_is_completed_by_its_creation(): void {
		$this->project( [ $this->link( 'link_proposed' ) ] );
		$this->assertSame( [], self::graph_rows( 'entities' ), 'the link names an entity no row holds yet' );

		$this->project( [ $this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ) ] );

		$this->assertSame( [ [ self::GAZETTE, 'organization', 'Harbor Gazette', (int) self::NOW ] ], self::graph_rows( 'entities' ) );
		$this->assertSame( [ [ self::GAZETTE, 'accepted' ] ], self::graph_rows( 'entity_states', '1', 'entity_key, state' ) );
		$this->assertSame( [ [ self::ITEM, self::GAZETTE, 'proposed' ] ], self::graph_rows( 'links', '1', 'item_key, entity_key, state' ) );
	}

	/** A person's creation wins over a machine's proposal in either order. */
	public function test_proposed_and_created_converge_in_either_order(): void {
		$early = 'organization:salem-ledger';
		$late  = 'organization:bend-ledger';
		$alone = 'organization:gazette-harbor';
		$this->project(
			[
				$this->proposal( $early, 'salem ledger' ),
				$this->ontology( 'entity_created', [ 'entity_key' => $early, 'type' => 'organization', 'name' => 'Salem Ledger' ] ),
				$this->ontology( 'entity_created', [ 'entity_key' => $late, 'type' => 'organization', 'name' => 'Bend Ledger' ] ),
				$this->proposal( $late, 'bend ledger' ),
				$this->proposal( $alone, 'Gazette Harbor' ),
			]
		);

		$this->assertSame(
			[ [ $late, 'Bend Ledger', 'accepted', 'user:41' ], [ $alone, 'Gazette Harbor', 'proposed', self::MACHINE ], [ $early, 'Salem Ledger', 'accepted', 'user:41' ] ],
			self::graph_rows( 'entities AS e JOIN p0.entity_states AS s USING ( entity_key )', 'entity_key', 'entity_key, e.name, s.state, s.actor' )
		);
	}

	public function test_a_failed_batch_raises_a_stop_and_keeps_the_buffer(): void {
		self::deliver( $this->projector, $this->item() );
		self::deliver( $this->projector, $this->link( 'link_asserted' ) );
		$held = \count( $this->read_private( $this->projector, 'rows' ) );
		$this->break_items();

		$thrown = null;
		try {
			$this->projector->save_state();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'a refused batch stops the worker' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ), 'the cursor must replay the batch' );
		$this->assertStringContainsString( 'intel graph write failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'items', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertSame( 2, $held );
		$this->assertCount( $held, $this->read_private( $this->projector, 'rows' ), 'the buffer is kept' );

		$this->mend_items();
		$this->assertSame( [], $this->projector->save_state() );
		$this->assertCount( 1, self::graph_rows( 'items' ) );
		$this->assertSame( [], $this->read_private( $this->projector, 'rows' ) );
	}

	public function test_an_ontology_event_is_kept_in_events_with_its_detail(): void {
		$event = $this->ontology( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'harborgazette.example' ] );
		$this->project( [ $event ] );

		$this->assertSame(
			[ [ $event['event_id'], 'identifier_added', 'user:41', self::GAZETTE, '{"entity_key":"organization:harbor-gazette","kind":"domain","value":"harborgazette.example"}', 'clients-v3', self::NOW ] ],
			self::graph_rows( 'events' )
		);
		$this->assertSame( [ [ self::GAZETTE, 'domain', 'harborgazette.example' ] ], self::graph_rows( 'identifiers' ) );
	}

	public function test_merge_sets_merged_into_and_reject_sets_state(): void {
		$survivor = 'organization:harbor-gazette-co';
		$spurious = 'organization:gazette-harbor';
		$this->project(
			[
				$this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ),
				$this->ontology( 'entity_created', [ 'entity_key' => $survivor, 'type' => 'organization', 'name' => 'Harbor Gazette Co.' ] ),
				$this->proposal( $spurious, 'Gazette Harbor' ),
				$this->ontology( 'entity_merged', [ 'entity_key' => self::GAZETTE, 'into_key' => $survivor ] ),
				$this->ontology( 'entity_rejected', [ 'entity_key' => $spurious ] ),
			]
		);

		$this->assertSame( [ [ self::GAZETTE, $survivor, 'user:41', self::NOW ] ], self::graph_rows( 'entity_merges' ) );
		$this->assertSame( [ [ $spurious, 'rejected' ], [ self::GAZETTE, 'accepted' ], [ $survivor, 'accepted' ] ], self::graph_rows( 'entity_states', 'entity_key', 'entity_key, state' ), 'a merge leaves the state where it was' );
	}

	public function test_judgments_are_kept_per_actor_kind(): void {
		$this->project(
			[
				$this->ontology( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ], 'user:41' ),
				$this->ontology( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ], 'agent:41:Claude desktop' ),
			]
		);

		$this->assertSame(
			[ [ 'agent', 'rejected', 'agent:41:Claude desktop' ], [ 'user', 'confirmed', 'user:41' ] ],
			self::graph_rows( 'judgments', 'actor_kind', 'actor_kind, state, actor' )
		);
	}

	public function test_the_buffer_writes_at_its_op_cap(): void {
		$item = $this->item();
		for ( $i = 1; $i < Projector_Node::MAX_BUFFERED_OPS; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:cap-{$i}" ] + $item );
		}
		$this->assertSame( [], self::graph_rows( 'items' ), 'below the cap nothing is written' );

		self::deliver( $this->projector, [ 'item_key' => 'feed:cap-last' ] + $item );

		$this->assertCount( Projector_Node::MAX_BUFFERED_OPS, self::graph_rows( 'items' ) );
		$this->assertSame( [], $this->read_private( $this->projector, 'rows' ) );
	}

	public function test_the_buffer_writes_past_its_byte_budget(): void {
		$item  = [ 'body' => \str_repeat( 'g', 65536 ) ] + $this->item();
		$fits  = \intdiv( Projector_Node::MAX_BUFFERED_BYTES, 65536 + 512 );
		$crest = \intdiv( Projector_Node::MAX_BUFFERED_BYTES, 65536 ) + 1;
		for ( $i = 1; $i <= $fits; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:big-{$i}" ] + $item );
		}
		$this->assertSame( [], self::graph_rows( 'items' ), 'below the byte budget nothing is written' );

		for ( $i = $fits + 1; [] === self::graph_rows( 'items' ) && $i <= $crest; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:big-{$i}" ] + $item );
		}

		$written = \count( self::graph_rows( 'items' ) );
		$this->assertGreaterThan( $fits, $written );
		$this->assertLessThanOrEqual( $crest, $written, 'the event crossing 1 MiB writes the batch' );
		$this->assertLessThan( Projector_Node::MAX_BUFFERED_OPS, $written );
		$this->assertSame( 1048576, Projector_Node::MAX_BUFFERED_BYTES );
	}

	public function test_the_router_tick_writes_at_the_flush_cadence(): void {
		$at          = self::NOW;
		Core::$clock = static function () use ( &$at ): float {
			return $at;
		};
		$tick   = static function ( int $seconds ) use ( &$at ): void {
			$at       += $seconds;
			Core::$now = $at;
			Core::node( Node_Names::ROUTER )->fire_cb();
		};
		self::deliver( $this->projector, $this->item() );
		$tick( 0 );
		self::deliver( $this->projector, [ 'item_key' => 'feed:abc-7732' ] + $this->item() );
		$tick( Projector_Node::FLUSH_INTERVAL_S - 1 );
		$between = \count( self::graph_rows( 'items' ) );
		$tick( 1 );

		$this->assertSame( 5, Projector_Node::FLUSH_INTERVAL_S );
		$this->assertSame( 1, $between, 'a tick inside the interval writes nothing' );
		$this->assertCount( 2, self::graph_rows( 'items' ), 'the tick at the interval writes the rest' );
	}

	public function test_a_clean_stop_writes_the_buffer(): void {
		self::deliver( $this->projector, [ 'url' => 'urn:harbor-gazette:7731' ] + $this->item() );

		$this->projector->shutdown_sweep();

		$this->assertSame( [ [ 'urn:harbor-gazette:7731', 'urn:harbor-gazette:7731' ] ], self::graph_rows( 'items', '1', 'url, url_canonical' ), 'a URL naming no host is its own canonical' );
	}

	public function test_a_write_with_no_graph_named_stops_the_worker(): void {
		$this->projector->set_graph_target( '' );
		self::deliver( $this->projector, $this->item() );

		$this->expectException( Worker_Should_Stop::class );
		$this->projector->save_state();
	}

	public function test_the_graph_target_round_trips_and_draws_its_edge(): void {
		$this->assertStringContainsString( 'projector:config set_graph_target intel-graph', $this->projector->dump_config() );
		$this->assertContains( 'intel-graph', $this->projector->display_targets() );
	}

	/** An unreadable event dead-letters, and its Consumer reads on past it. */
	public function test_an_unreadable_event_is_dead_lettered_and_the_cursor_moves_on(): void {
		$tmp   = $this->base;
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/intel-items.p{partition}", '1', '1048576' ] );
		foreach ( [ [ 'event' => 'item_recorded', 'item_key' => 'feed:abc-7730' ], $this->item() ] as $value ) {
			$record                   = Message::new_message();
			$record[ Message::TYPE ]  = Message::TM_STRUCT;
			$record[ Message::VALUE ] = $value;
			$topic->fill( $record );
		}
		$topic->flush();
		$consumer = new Consumer_Node();
		$consumer->arguments( [ "{$tmp}/intel-items.p0", "{$tmp}/offsets/p0", "{$tmp}/deadletter/p0" ] );
		$consumer->name( 'intel:items:consumer' );
		$consumer->sink( $this->projector );
		$consumer->add_snapshot_node( 'projector' );

		$this->pump_consumer( $consumer );
		$consumer->checkpoint();

		$this->assertSame( 1, $consumer->list_deadletter( 10 )['total'], 'the event missing its fields is quarantined' );
		$this->assertSame( [ [ self::ITEM ] ], self::graph_rows( 'items', '1', 'item_key' ), 'the event after it projects' );
		$this->assertGreaterThan( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'the cursor commits past both' );
	}

	public function test_the_project_topology_feeds_both_streams_to_the_graph(): void {
		Core::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Core::$var['partition'] = '0';
		Core::$var['topology']  = 'newspack-intelligence-project';
		$router                 = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include newspack-intelligence-project\n" );

		$projector = Core::node( 'projector' );
		$this->assertInstanceOf( Projector_Node::class, $projector );
		$this->assertSame( 'intel-graph', $this->read_private( $projector, 'graph_target' ) );
		$this->assertInstanceOf( Row_Table_Node::class, Core::node( 'intel-graph' ) );
		foreach ( [ 'intel:items:consumer' => [ 'items', 'projector' ], 'intel:ontology:consumer' => [ 'ontology', 'intel:ontology:tee' ] ] as $name => [ $stream, $target ] ) {
			$consumer = Core::node( $name );
			$this->assertInstanceOf( Consumer_Node::class, $consumer, $name );
			$this->assertSame( $target, $consumer->target(), $name );
			$this->assertStringEndsWith( "/logs/intel-{$stream}.p0", $this->read_private( $consumer, 'source_dir' ), $name );
			$this->assertFalse( $this->read_private( $consumer, 'line_mode' ), "{$name} drains in batches" );
			$this->assertSame( [ 'projector' ], $this->read_private( $consumer, 'snapshot_nodes' ), $name );
		}
		$this->assertFalse( $this->read_private( Core::node( 'intel:items:consumer' ), 'multi_writer' ), 'the worker is the items stream\'s one writer' );
		$this->assertTrue( $this->read_private( Core::node( 'intel:ontology:consumer' ), 'multi_writer' ), 'the worker and request-side writers share the ontology stream' );
		$this->assertSame( [ 'projector' ], Core::node( 'intel:ontology:tee' )?->target(), 'the ontology reaches the projector through its tee' );
	}

	/**
	 * Fill each event, then checkpoint, as the Consumer does.
	 *
	 * @param list<array<string,mixed>> $events The events.
	 */
	private function project( array $events ): void {
		foreach ( $events as $event ) {
			self::deliver( $this->projector, $event );
		}
		$this->projector->save_state();
	}

	/** @return array<string,mixed> */
	private function item(): array {
		return Intel_Event::make(
			'item_recorded',
			[
				'item_key'     => self::ITEM,
				'source'       => 'feed',
				'tier'         => 'public',
				'url'          => 'https://www.HarborGazette.example/2026/10/award/?utm_source=np&b=2&a=1#top',
				'title'        => 'The Harbor Gazette Wins a State Award!',
				'body'         => 'The Gazette took first place.',
				'item_ts'      => 1792900000,
				'retrieved_ts' => self::RETRIEVED,
			],
			'machine:source:feed@9.4.1',
			''
		);
	}

	/**
	 * A machine link from the item to an entity.
	 *
	 * @param string $kind       link_asserted or link_proposed.
	 * @param string $entity_key The entity.
	 * @return array<string,mixed>
	 */
	private function link( string $kind, string $entity_key = self::GAZETTE ): array {
		return Intel_Event::make(
			$kind,
			[
				'item_key'   => self::ITEM,
				'entity_key' => $entity_key,
				'role'       => 'subject',
				'method'       => 'identifier',
				'confidence'   => 1.0,
				'retrieved_ts' => self::RETRIEVED,
			],
			self::MACHINE,
			''
		);
	}

	/** @return array<string,mixed> */
	private function extraction(): array {
		return Intel_Event::make(
			'extraction_recorded',
			[
				'item_key'   => self::ITEM,
				'summary'    => 'Harbor Gazette won the state award.',
				'kind'       => 'award',
				'relevance'  => 8,
				'importance' => 6,
				'sentiment'  => 0.5,
				'topics'       => [ 'topic:awards' ],
				'retrieved_ts' => self::RETRIEVED,
			],
			'machine:extract@9.4.1',
			''
		);
	}

	/**
	 * An ontology event by a person.
	 *
	 * @param string              $kind   The kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @param string              $actor  The actor.
	 * @return array<string,mixed>
	 */
	private function ontology( string $kind, array $fields, string $actor = 'user:41' ): array {
		return Intel_Event::make( $kind, $fields, $actor, 'clients-v3' );
	}

	/**
	 * A machine's proposal of an organization.
	 *
	 * @param string $entity_key The entity.
	 * @param string $name       The name it proposes.
	 * @return array<string,mixed>
	 */
	private function proposal( string $entity_key, string $name ): array {
		return Intel_Event::make( 'entity_proposed', [ 'entity_key' => $entity_key, 'type' => 'organization', 'name' => $name, 'from_item_key' => self::ITEM ], self::MACHINE, '' );
	}

	/** @return list<array<string,mixed>> One event of every kind. */
	private function every_kind(): array {
		$machine = static fn ( string $kind, array $fields ): array => Intel_Event::make( $kind, $fields, self::MACHINE, '' );
		return [
			$this->item(),
			$this->extraction(),
			$this->link( 'link_asserted' ),
			$this->link( 'link_proposed', 'organization:salem-ledger' ),
			$machine( 'claim_asserted', [ 'item_key' => self::ITEM, 'ord' => 0, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => 'Peabody Award', 'confidence' => 0.9, 'retrieved_ts' => self::RETRIEVED ] ),
			$machine( 'cluster_joined', [ 'item_key' => self::ITEM, 'cluster_key' => 'cluster:7731', 'retrieved_ts' => self::RETRIEVED ] ),
			$machine( 'cluster_superseded', [ 'cluster_key' => 'cluster:7731', 'canonical_item_key' => self::ITEM, 'previous_item_key' => 'feed:abc-7730', 'rank' => 14.0, 'retrieved_ts' => self::RETRIEVED ] ),
			$this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ),
			$this->proposal( 'organization:salem-ledger', 'Salem Ledger' ),
			$this->ontology( 'entity_accepted', [ 'entity_key' => 'organization:salem-ledger' ] ),
			$this->ontology( 'entity_rejected', [ 'entity_key' => 'organization:gazette-harbor' ] ),
			$this->ontology( 'entity_merged', [ 'entity_key' => 'organization:gazette-harbor', 'into_key' => self::GAZETTE ] ),
			$this->ontology( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'The Gazette' ] ),
			$this->ontology( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'harborgazette.example' ] ),
			$this->ontology( 'role_asserted', [ 'entity_key' => self::GAZETTE, 'role' => 'client', 'from_ts' => 1790000000 ] ),
			$this->ontology( 'role_ended', [ 'entity_key' => self::GAZETTE, 'role' => 'prospect', 'from_ts' => 1780000000, 'to_ts' => 1790000000 ] ),
			$this->ontology( 'topic_tagged', [ 'entity_key' => self::GAZETTE, 'topic_key' => 'topic:local-news' ] ),
			$this->ontology( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ] ),
			$this->ontology( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:salem-ledger', 'item_url' => 'https://harborgazette.example/a' ], 'agent:41:Claude desktop' ),
			$this->ontology( 'collection_created', [ 'collection_key' => 'awards-2026', 'name' => 'Awards 2026' ] ),
			$this->ontology( 'collected', [ 'collection_key' => 'awards-2026', 'item_key' => self::ITEM, 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'tier' => 'internal' ] ),
		];
	}

	/**
	 * Every declared table's rows, every column, in key order.
	 *
	 * @param string $graph The Row_Table.
	 * @return array<string,list<list<mixed>>>
	 */
	private static function dump( string $graph = 'intel-graph' ): array {
		$dump = [];
		foreach ( Intel_Graph_Row_Schema::tables() as $table => $spec ) {
			$dump[ $table ] = self::graph_rows( $table, \implode( ', ', $spec['key'] ?? [ '1' ] ), '*', $graph );
		}
		return $dump;
	}

	private function break_items(): void {
		( new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ) ) )->exec( 'ALTER TABLE items RENAME TO items_broken' );
	}

	private function mend_items(): void {
		( new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ) ) )->exec( 'ALTER TABLE items_broken RENAME TO items' );
	}
}

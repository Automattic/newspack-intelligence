<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The `intelligence` service CI: nine read verbs over the projection, each
 * gated at `read`, its args bound by the schema, answering only rows of the
 * tiers a read caller holds, every answer carrying its citations.
 */
final class IntelligenceCITest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const VERBS = [ 'search', 'entity', 'timeline', 'related', 'claims', 'trends', 'review', 'collections', 'lenses' ];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		$GLOBALS['_wp_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_current_user_can']                           = true;
		self::sign_in( 3373, 'harbor-desk', 'manage_options' );
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;
		Intel_Graph_Row_Schema::$read_rows    = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_every_read_verb_is_a_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \array_fill_keys( self::VERBS, Capabilities::READ ), \array_slice( $roles, 0, \count( self::VERBS ) ) );
	}

	public function test_a_caller_below_read_is_refused(): void {
		$GLOBALS['_current_user_id']          = 3371;
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;

		foreach ( self::VERBS as $verb ) {
			try {
				$this->ci->dispatch( $verb, [] );
				$this->fail( "{$verb} answers a caller below read" );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'permission denied: read capability required', $e->getMessage() );
			}
		}
	}

	/** The service CI mounts once per request graph, and only it. */
	public function test_the_service_cis_mount_once(): void {
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		$this->assertInstanceOf( Command_Interpreter_Node::class, $interpreter );

		\Newspack_Intelligence\mount_service_cis( $interpreter );
		$first = Core::node( 'intelligence' );
		\Newspack_Intelligence\mount_service_cis( $interpreter );

		$this->assertInstanceOf( Intelligence_CI_Node::class, $first );
		$this->assertSame( $first, Core::node( 'intelligence' ) );
	}

	/** Args bind by position or by name, as each verb declares them. */
	public function test_verbs_bind_their_args(): void {
		$this->seed_world();
		$this->seed( self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ) );

		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $this->ci->dispatch( 'search', [ '--q=state award', '--source=feed' ] )['items'], 'item_key' ) );
		$this->assertSame( self::GAZETTE, $this->ci->dispatch( 'entity', [ 'organization:harbor-gazette' ] )['entity_key'] );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE, '--until=2026-10-02' ] )['items'], 'item_key' ) );
		$this->assertCount( 1, $this->ci->dispatch( 'related', [ self::GAZETTE, '--limit=1' ] )['entities'] );
		$this->assertSame( [ 'won' ], \array_column( $this->ci->dispatch( 'claims', [ self::GAZETTE, '--predicate=won' ] )['claims'], 'predicate' ) );
		$this->assertSame( [ '2026-10-01' ], \array_values( \array_unique( \array_column( $this->ci->dispatch( 'trends', [ 'kind', '--bucket=month' ] )['rows'], 'bucket' ) ) ) );
		$this->assertCount( 1, $this->ci->dispatch( 'review', [ 'links' ] )['links'] );
		$this->assertSame( [], $this->ci->dispatch( 'collections', [] )['collections'] );
	}

	/** A blank optional arg is a value, read as given: it matches nothing. */
	public function test_a_blank_optional_arg_is_a_value(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ),
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ) ]
		);

		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE, '--predicate= ' ] )['claims'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--source=' ] )['items'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--q= ' ] )['items'] );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no collection:  ' );
		$this->ci->dispatch( 'collections', [ '--key= ' ] );
	}

	public function test_limit_is_capped_at_200(): void {
		$events = [];
		for ( $n = 0; $n < 201; $n++ ) {
			$events[] = self::item( "feed:bulk-{$n}", "Bulk item {$n}", 'feed', 'public', self::$oct_06 + $n );
		}
		$this->seed( ...$events );

		$answer = $this->ci->dispatch( 'search', [ '--limit=500' ] );

		$this->assertCount( 200, $answer['items'] );
		$this->assertSame( 201, $answer['total'] );
	}

	/** Review Focus 4: a restricted item never reaches a read caller, alone or through an aggregate. */
	public function test_a_restricted_row_never_reaches_a_read_caller(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:quiet-partner', 'Quiet Partner' ),
			self::extraction( 'hubspot:deal-9', 'A churn risk.', 'churn_signal', self::$oct_13, [ 'topic:awards' ] ),
			self::link( 'link_asserted', 'hubspot:deal-9', self::GAZETTE, 'subject', 'identifier', 1.0, self::$oct_13 ),
			self::link( 'link_asserted', 'hubspot:deal-9', 'organization:quiet-partner', 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'hubspot:deal-9', 'organization:salem-ledger', 'mention', 'ner_fuzzy', 0.6, self::$oct_13 ),
			self::claim( 'hubspot:deal-9', 0, self::GAZETTE, 'partners_with', 'organization:quiet-partner', 'Quiet Partner', self::$oct_13 ),
			self::proposal( 'organization:hidden-buyer', 'Hidden Buyer', 'hubspot:deal-9' ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:deals', 'name' => 'Deals' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:deals', 'item_key' => 'hubspot:deal-9', 'url' => self::url( 'hubspot:deal-9' ), 'title' => 'Harbor Gazette deal at risk', 'tier' => 'restricted' ], 'user:41', 'fixture-9' ),
			]
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );

		$this->assertNotContains( 'hubspot:deal-9', \array_column( $this->ci->dispatch( 'search', [] )['items'], 'item_key' ) );
		$this->assertSame( 3, $this->ci->dispatch( 'search', [] )['total'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--q=risk' ] )['items'] );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'], 'item_key' ) );
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 0 ], $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 1 ], $this->ci->dispatch( 'entity', [ 'organization:salem-ledger' ] )['counts'] );
		$related = $this->ci->dispatch( 'related', [ self::GAZETTE ] )['entities'];
		$this->assertSame( [ 'topic:awards' => 2, 'organization:salem-ledger' => 1 ], \array_column( $related, 'shared_items', 'entity_key' ) );
		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );
		$this->assertNotContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind' ] )['rows'], 'value' ) );
		$this->assertSame( [ 1, 1, 1 ], \array_column( $this->ci->dispatch( 'trends', [ 'topic', '--bucket=week' ] )['rows'], 'count' ) );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'review', [ 'links' ] )['links'], 'item_key' ) );
		$this->assertSame( [], $this->ci->dispatch( 'review', [ 'entities' ] )['entities'] );
		try {
			$this->ci->dispatch( 'entity', [ 'organization:hidden-buyer' ] );
			$this->fail( 'a proposal only a restricted item cites answers' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'no entity: organization:hidden-buyer', $e->getMessage() );
		}
		$this->assertSame( [ 0 ], \array_column( $this->ci->dispatch( 'collections', [] )['collections'], 'items' ) );
		$this->assertSame( [], $this->ci->dispatch( 'collections', [ 'col:deals' ] )['items'] );
	}

	/**
	 * Only an accepted entity answers without a readable citation; one a
	 * person rejected answers while a readable item cites it, and not
	 * when only a restricted one does.
	 */
	public function test_an_entity_short_of_accepted_needs_a_readable_citation(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:quiet-partner', 'Quiet Partner' ),
			self::proposal( 'organization:hidden-buyer', 'Hidden Buyer', 'hubspot:deal-9' ),
			self::rejected( 'organization:hidden-buyer' ),
			self::proposal( 'organization:open-buyer', 'Open Buyer', 'feed:gazette-award' ),
			self::rejected( 'organization:open-buyer' ),
			self::link( 'link_asserted', 'hubspot:deal-9', 'organization:quiet-partner', 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'hubspot:deal-9', 'organization:hidden-buyer', 'mention', 'ner_fuzzy', 0.6, self::$oct_13 )
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );

		$this->assertSame( [ 'Quiet Partner', 'accepted', [ 'items' => 0, 'proposed_links' => 0 ] ], \array_values( \array_intersect_key( $this->ci->dispatch( 'entity', [ 'organization:quiet-partner' ] ), [ 'name' => 1, 'state' => 1, 'counts' => 1 ] ) ) );
		$this->assertSame( [ 'Open Buyer', 'rejected' ], \array_values( \array_intersect_key( $this->ci->dispatch( 'entity', [ 'organization:open-buyer' ] ), [ 'name' => 1, 'state' => 1 ] ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no entity: organization:hidden-buyer' );
		$this->ci->dispatch( 'entity', [ 'organization:hidden-buyer' ] );
	}

	/** Every row an answer lists names the items it rests on, with their URLs where the shape carries one. */
	public function test_every_answer_carries_citations(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ),
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:october', 'item_key' => 'feed:gazette-award', 'url' => self::url( 'feed:gazette-award' ), 'title' => 'Harbor Gazette wins state award', 'tier' => 'public' ], 'user:41', 'fixture-9' ),
			]
		);
		$cited = function ( array $rows ): void {
			$this->assertNotSame( [], $rows );
			foreach ( $rows as $row ) {
				$this->assertStringStartsWith( 'https://news.example/', $row['url'] );
				$this->assertNotSame( '', $row['item_key'] );
			}
		};
		$keyed = function ( array $rows ) use ( $cited ): void {
			$this->assertNotSame( [], $rows );
			foreach ( $rows as $row ) {
				$this->assertNotSame( [], $row['items'] );
				$cited( $row['items'] );
			}
		};

		$cited( $this->ci->dispatch( 'search', [] )['items'] );
		$cited( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'] );
		$cited( $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );
		$cited( $this->ci->dispatch( 'review', [ 'links' ] )['links'] );
		$cited( \array_merge( ...\array_column( $this->ci->dispatch( 'review', [ 'entities' ] )['entities'], 'items' ) ) );
		$cited( $this->ci->dispatch( 'collections', [ 'col:october' ] )['items'] );
		$keyed( $this->ci->dispatch( 'related', [ self::GAZETTE ] )['entities'] );
		$keyed( $this->ci->dispatch( 'trends', [ 'topic' ] )['rows'] );
	}
}

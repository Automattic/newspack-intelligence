<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The `trace` read: one item's own decision record, laid out in the order
 * the pipeline wrote it, each step with its actor and time, under the
 * reader's tiers and exclusions.
 */
final class IntelligenceTraceTest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;

	private const ITEM = 'linear:harbor-renewal-418';

	private const COPY = 'feed:harbor-renewal-copy-419';

	private const HARBOR = 'organization:harbor-ledger';

	private const PRAIRIE = 'organization:prairie-signal';

	/** 2026-10-13 12:10 UTC, when the item was retrieved. */
	private const RETRIEVED = 1791893400;

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::sign_in( 37, 'kinsey-reed', 'manage_options' );
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_trace_is_a_read(): void {
		$this->assertSame( Capabilities::READ, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['trace'] );
	}

	/**
	 * The trace reads recorded, then each link by when it was written, with
	 * its method and its state and the judgment that set it, then the
	 * extraction, then its kind check, then its cluster, each step with its
	 * actor and time. A link to an entity whose merge chain does not resolve
	 * is left out, as `visible()` hides it.
	 */
	public function test_trace_lays_out_the_items_record_in_order(): void {
		$this->seed_record();

		$this->assertSame(
			[
				'item_key'  => self::ITEM,
				'title'     => 'Harbor Ledger asks for a renewal quote',
				'url'       => self::url( self::ITEM ),
				'steps'     => [
					[ 'step' => 'recorded', 'at' => self::RETRIEVED, 'actor' => 'machine:source:linear@9.4.1', 'source' => 'linear', 'tier' => 'internal' ],
					[ 'step' => 'linked', 'at' => self::RETRIEVED + 40, 'actor' => 'machine:resolve@9.4.1', 'entity_key' => self::HARBOR, 'name' => 'Harbor Ledger', 'role' => 'subject', 'method' => 'identifier', 'confidence' => 1.0, 'state' => 'confirmed', 'judged' => [ 'state' => 'confirmed', 'actor' => 'user:41', 'at' => self::RETRIEVED + 7200 ] ],
					[ 'step' => 'linked', 'at' => self::RETRIEVED + 95, 'actor' => 'machine:resolve@9.4.1', 'entity_key' => self::PRAIRIE, 'name' => 'Prairie Signal', 'role' => 'mention', 'method' => 'ner_fuzzy', 'confidence' => 0.6, 'state' => 'proposed', 'judged' => null ],
					[ 'step' => 'extracted', 'at' => self::RETRIEVED + 310, 'actor' => 'machine:extract@9.4.1', 'kind' => 'churn_signal', 'theme' => 'renewal at risk', 'rank_reason' => 'A client is weighing a cheaper host before its renewal.' ],
					[ 'step' => 'checked', 'at' => self::RETRIEVED + 330, 'actor' => 'machine:extract@9.4.1', 'question' => 'fits_kind', 'kind' => 'churn_signal', 'verdict' => Intel_Event::PASSED ],
					[ 'step' => 'clustered', 'at' => self::RETRIEVED + 900, 'actor' => 'machine:dedup@9.4.1', 'canonical' => true, 'copies' => 1 ],
				],
				'available' => true,
			],
			$this->ci->dispatch( 'trace', [ self::ITEM ] )
		);
	}

	/** An item the gate kept from the model reads gated, its stand-in's kind and no theme, and no check or cluster follows. */
	public function test_a_gated_item_reads_gated(): void {
		$this->seed(
			self::recorded( 'feed:harbor-masthead-77', 'feed', 'public' ),
			[ self::at( Intel_Event::make( 'extraction_recorded', [ 'item_key' => 'feed:harbor-masthead-77', 'summary' => 'Harbor Ledger names a new editor.', 'kind' => 'other', 'relevance' => 0.0, 'importance' => 0.0, 'sentiment' => 0.0, 'topics' => [], 'retrieved_ts' => self::RETRIEVED ], 'machine:extract-gated@9.4.1', 'fixture-9' ), self::RETRIEVED + 20 ) ]
		);

		$steps = $this->ci->dispatch( 'trace', [ 'feed:harbor-masthead-77' ] )['steps'];

		$this->assertSame( [ 'recorded', 'gated' ], \array_column( $steps, 'step' ) );
		$this->assertSame( [ 'step' => 'gated', 'at' => self::RETRIEVED + 20, 'actor' => 'machine:extract-gated@9.4.1', 'kind' => 'other', 'theme' => null, 'rank_reason' => '' ], $steps[1] );
	}

	/**
	 * The trace answers only what `ri` admits: a restricted item is refused
	 * as missing to a reader without the tier, and so is an item a curator
	 * excluded.
	 */
	public function test_trace_refuses_an_item_outside_the_readers_tiers(): void {
		$this->seed_record();
		self::write_restricted_item( 'hubspot:harbor-deal-31', 'Harbor Ledger deal at risk' );
		$this->seed( [ Intel_Event::make( 'item_excluded', [ 'item_key' => self::COPY, 'url' => self::url( self::COPY ), 'title' => 'Copy', 'tier' => 'public' ], 'user:41', 'fixture-9' ) ] );

		foreach ( [ 'hubspot:harbor-deal-31', self::COPY ] as $key ) {
			try {
				$this->ci->dispatch( 'trace', [ $key ] );
				$this->fail( "{$key} was traced" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "no item: {$key}", $e->getMessage() );
			}
		}
	}

	/**
	 * The renewal item and its record: recorded by its source, linked to
	 * Harbor Ledger by its identifier and confirmed by a curator, to Prairie
	 * Signal by a similar name and proposed, and to a key whose merge chain
	 * cycles; extracted with a theme and a rank reason, its kind checked;
	 * and the canonical of a cluster holding one public copy.
	 */
	private function seed_record(): void {
		$link = fn ( string $entity, string $role, string $method, float $confidence, string $kind, int $after ): array => self::at( self::link( $kind, self::ITEM, $entity, $role, $method, $confidence, self::RETRIEVED - 600 )[0], self::RETRIEVED + $after );
		$this->seed(
			self::entity( self::HARBOR, 'Harbor Ledger' ),
			self::proposal( self::PRAIRIE, 'Prairie Signal', self::ITEM ),
			self::entity( 'organization:loop-a', 'Loop A' ),
			self::entity( 'organization:loop-b', 'Loop B' ),
			self::merged( 'organization:loop-a', 'organization:loop-b' ),
			self::merged( 'organization:loop-b', 'organization:loop-a' ),
			self::recorded( self::ITEM, 'linear', 'internal', 'Harbor Ledger asks for a renewal quote' ),
			self::recorded( self::COPY, 'feed', 'public' ),
			[
				$link( self::PRAIRIE, 'mention', 'ner_fuzzy', 0.6, 'link_proposed', 95 ),
				$link( self::HARBOR, 'subject', 'identifier', 1.0, 'link_asserted', 40 ),
				$link( 'organization:loop-a', 'mention', 'name', 1.0, 'link_asserted', 60 ),
				self::at( self::judged( 'link_confirmed', self::ITEM, self::HARBOR, 'user:41' )[0], self::RETRIEVED + 7200 ),
				self::at(
					Intel_Event::make(
						'extraction_recorded',
						[ 'item_key' => self::ITEM, 'summary' => 'Harbor Ledger asked for a renewal quote.', 'kind' => 'churn_signal', 'relevance' => 7.0, 'importance' => 8.0, 'sentiment' => -0.25, 'topics' => [], 'retrieved_ts' => self::RETRIEVED, 'theme' => 'renewal at risk', 'rank_reason' => 'A client is weighing a cheaper host before its renewal.', 'kind_check' => [ 'kind' => 'churn_signal', 'verdict' => Intel_Event::PASSED, 'ts' => self::RETRIEVED + 330 ] ],
						'machine:extract@9.4.1',
						'fixture-9'
					),
					self::RETRIEVED + 310
				),
			],
			\array_map( static fn ( array $event ): array => self::at( $event, self::RETRIEVED + 900 ), self::story( self::ITEM, [ self::COPY, self::ITEM ], self::RETRIEVED - 600 ) )
		);
	}

	/**
	 * A source's record of an item, retrieved at RETRIEVED.
	 *
	 * @param string $key    The item key.
	 * @param string $source Its source.
	 * @param string $tier   Its tier.
	 * @param string $title  Its title.
	 * @return list<array<string,mixed>>
	 */
	private static function recorded( string $key, string $source, string $tier, string $title = 'A copy' ): array {
		return [ self::at( Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => $source, 'tier' => $tier, 'url' => self::url( $key ), 'title' => $title, 'body' => 'The renewal is under review.', 'item_ts' => self::RETRIEVED - 600, 'retrieved_ts' => self::RETRIEVED ], "machine:source:{$source}@9.4.1", 'fixture-9' ), self::RETRIEVED + 5 ) ];
	}

	/**
	 * An event stamped at a second of its own.
	 *
	 * @param array<string,mixed> $event The event.
	 * @param int                 $ts    When it was written.
	 * @return array<string,mixed>
	 */
	private static function at( array $event, int $ts ): array {
		return [ 'ts' => (float) $ts ] + $event;
	}
}

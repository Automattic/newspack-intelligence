<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

/**
 * The `trace` read: one item's own decision record, laid out in the order
 * the pipeline wrote it, each step with its actor and time, under the
 * reader's tiers and exclusions.
 */
final class IntelligenceTraceTest extends TestCase {
	use Counts_Statements;
	use Seeds_Intel_Graph;
	use Signs_In_Users;

	private const ITEM = 'linear:harbor-renewal-418';

	private const COPY = 'feed:harbor-renewal-copy-419';

	private const HARBOR = 'organization:harbor-ledger';

	private const PRAIRIE = 'organization:prairie-signal';

	private const LATE = 'feed:harbor-renewal-late-420';

	private const RESTRICTED = 'hubspot:harbor-renewal-deal-421';

	private const GONE = 'feed:harbor-renewal-gone-422';

	private const MOVED = 'feed:harbor-renewal-moved-423';

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

	public function test_copies_is_a_read(): void {
		$this->assertSame( Capabilities::READ, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['copies'] );
	}

	/**
	 * `copies` lists the other items of the item's cluster the reader reads
	 * through `ri`, each with its source and its UTC day, the newest first:
	 * a restricted copy is never listed for a reader without the tier, nor a
	 * copy a curator excluded, nor an item the newest partition moved to
	 * another cluster.
	 */
	public function test_copies_lists_the_clusters_other_copies_a_reader_reads(): void {
		$this->seed_copies();

		$this->assertSame(
			[
				'item_key'  => self::ITEM,
				'copies'    => [
					[ 'item_key' => self::LATE, 'url' => self::url( self::LATE ), 'title' => 'A later copy', 'source' => 'feed', 'day' => '2026-10-14' ],
					[ 'item_key' => self::COPY, 'url' => self::url( self::COPY ), 'title' => 'A copy', 'source' => 'feed', 'day' => '2026-10-13' ],
				],
				'available' => true,
			],
			$this->ci->dispatch( 'copies', [ self::ITEM ] )
		);
	}

	/** A holder of the restricted tier is listed the restricted copy too, a tie in time by key. */
	public function test_copies_lists_a_restricted_copy_to_its_holder(): void {
		$this->seed_copies();
		self::sign_in( 6153, 'quay-holder', 'manage_options', Intel_Tiers::CAPABILITY );

		$this->assertSame(
			[ self::LATE, self::COPY, self::RESTRICTED ],
			\array_column( $this->ci->dispatch( 'copies', [ self::ITEM ] )['copies'], 'item_key' )
		);
	}

	/**
	 * A listed item's `copies` count, its trace's `clustered` count and the
	 * `copies` list name one set: the cluster's other items the caller reads
	 * through `ri`, for a reader and for a holder of the restricted tier.
	 */
	public function test_the_copies_count_the_trace_and_the_list_name_one_set(): void {
		$this->seed_copies();
		$listed  = fn (): array => \array_column( $this->ci->dispatch( 'copies', [ self::ITEM ] )['copies'], 'item_key' );
		$counted = fn (): int => \array_column( $this->ci->dispatch( 'search', [ '--q=renewal' ] )['items'], 'copies', 'item_key' )[ self::ITEM ];
		$traced  = fn (): int => \array_column( $this->ci->dispatch( 'trace', [ self::ITEM ] )['steps'], 'copies', 'step' )['clustered'];

		$this->assertSame( [ self::LATE, self::COPY ], $listed() );
		$this->assertSame( 2, $counted() );
		$this->assertSame( 2, $traced() );

		self::sign_in( 6153, 'quay-holder', 'manage_options', Intel_Tiers::CAPABILITY );
		$this->assertSame( [ self::LATE, self::COPY, self::RESTRICTED ], $listed() );
		$this->assertSame( 3, $counted() );
		$this->assertSame( 3, $traced() );
	}

	/**
	 * `copies` and the trace's `clustered` step read the item's placement
	 * once: neither statement places a subquery over `clusters` beneath a
	 * correlated one, which would re-run the newest-placement window for
	 * every readable item.
	 */
	public function test_the_copies_reads_place_the_item_once(): void {
		$this->seed_copies();

		foreach ( [ 'copies', 'trace' ] as $command ) {
			$read = \array_values( \array_filter( $this->queries_of( fn () => $this->ci->dispatch( $command, [ self::ITEM ] ) ), static fn ( string $sql ): bool => \str_contains( $sql, 'mine AS' ) ) );
			$this->assertCount( 1, $read, $command );
			$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
			$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
			$plan    = $db->query( 'EXPLAIN QUERY PLAN ' . $read[0] )->fetchAll( \PDO::FETCH_ASSOC );
			$parents = \array_column( $plan, 'parent', 'id' );
			$details = \array_column( $plan, 'detail', 'id' );
			foreach ( $details as $id => $detail ) {
				if ( ! \str_contains( $detail, 'clusters' ) ) {
					continue;
				}
				for ( $at = $parents[ $id ]; isset( $details[ $at ] ); $at = $parents[ $at ] ) {
					$this->assertStringNotContainsString( 'CORRELATED', $details[ $at ], "{$command}: {$detail}\n" . \implode( "\n", $details ) );
				}
			}
		}
	}

	/** An item outside every cluster has no copies. */
	public function test_an_item_in_no_cluster_has_no_copies(): void {
		$this->seed( self::recorded( 'feed:harbor-alone-5', 'feed', 'public' ) );

		$this->assertSame( [], $this->ci->dispatch( 'copies', [ 'feed:harbor-alone-5' ] )['copies'] );
	}

	/**
	 * `copies` names no cluster of an item the reader cannot read: a
	 * restricted item and an excluded one are refused as missing.
	 */
	public function test_copies_refuses_an_item_outside_the_readers_tiers(): void {
		$this->seed_copies();

		foreach ( [ self::RESTRICTED, self::GONE ] as $key ) {
			try {
				$this->ci->dispatch( 'copies', [ $key ] );
				$this->fail( "{$key} answered its copies" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "no item: {$key}", $e->getMessage() );
			}
		}
	}

	/**
	 * The renewal cluster: the item, a public copy, a later public copy, a
	 * restricted copy, an excluded copy, and a copy the newest partition
	 * moved into a cluster of its own.
	 */
	private function seed_copies(): void {
		$this->seed_record();
		self::write_restricted_item( self::RESTRICTED, 'A restricted copy' );
		$this->seed(
			self::recorded( self::LATE, 'feed', 'public', 'A later copy', self::RETRIEVED + 86400 ),
			self::recorded( self::GONE, 'feed', 'public', 'An excluded copy' ),
			self::recorded( self::MOVED, 'feed', 'public', 'A moved copy' ),
			self::story( self::ITEM, [ self::COPY, self::ITEM, self::LATE, self::RESTRICTED, self::GONE, self::MOVED ], self::RETRIEVED - 600 ),
			[ Intel_Event::make( 'item_excluded', [ 'item_key' => self::GONE, 'url' => self::url( self::GONE ), 'title' => 'An excluded copy', 'tier' => 'public' ], 'user:41', 'fixture-9' ) ]
		);
		$this->second_partition( self::story( self::MOVED, [ self::MOVED ], self::RETRIEVED - 600 ) );
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
	 * @param int    $when   Its item time.
	 * @return list<array<string,mixed>>
	 */
	private static function recorded( string $key, string $source, string $tier, string $title = 'A copy', int $when = self::RETRIEVED - 600 ): array {
		return [ self::at( Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => $source, 'tier' => $tier, 'url' => self::url( $key ), 'title' => $title, 'body' => 'The renewal is under review.', 'item_ts' => $when, 'retrieved_ts' => self::RETRIEVED ], "machine:source:{$source}@9.4.1", 'fixture-9' ), self::RETRIEVED + 5 ) ];
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

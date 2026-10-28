<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\LLM_Client;
use Newspack_Intelligence\Reresolver_Node;
use Newspack_Intelligence\Resolver_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Tee_Node;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The reresolver: each ontology signal that can link stored items anew is
 * queued, swept once it is SWEEP_INTERVAL_S old, and the items it names are
 * sent to the resolver again, newest first and capped; a requested day range
 * sends every item it dates, one page a tick.
 */
final class ReresolverNodeTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1795200000.0;

	private const GAZETTE = 'organization:harbor-gazette';

	private Reresolver_Node $reresolver;

	private Capture_Sink_Node $out;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
		$this->out        = new Capture_Sink_Node();
		$this->reresolver = $this->reresolver();
	}

	protected function tearDown(): void {
		\Newspack_Intelligence\Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** A new alias sends the items naming it, as whole words, newest first. */
	public function test_a_new_alias_reresolves_the_items_that_name_it(): void {
		$this->seed(
			self::items(
				[ 'feed:alias-1', 'HarbGaz wins a state prize', '', 'https://news.example/a', 1793100000 ],
				[ 'feed:alias-2', 'Port news', 'Readers of harbgaz wrote in.', 'https://news.example/b', 1793300000 ],
				[ 'feed:alias-3', 'The Harbor Gazette expands', '', 'https://news.example/c', 1793400000 ],
				[ 'feed:alias-4', 'HarbGazette is not the alias', '', 'https://news.example/d', 1793500000 ]
			)
		);

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:alias-2', 'feed:alias-1' ], $this->sent_keys() );
		$this->sweep_at( self::NOW + 2 * Reresolver_Node::SWEEP_INTERVAL_S );
		$this->assertCount( 2, $this->sent_keys(), 'a swept signal leaves the queue' );
	}

	/** An entity's creation searches its name. */
	public function test_a_created_entity_searches_its_name(): void {
		$this->seed( self::items( [ 'feed:created-1', 'Mesa Courier hires an editor', '', 'https://news.example/m', 1793100000 ] ) );

		$this->signal( 'entity_created', [ 'entity_key' => 'organization:mesa-courier', 'type' => 'organization', 'name' => 'Mesa Courier' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:created-1' ], $this->sent_keys() );
	}

	/** A signal's record asks for an index read after it settled; a range's asks for none. */
	public function test_each_record_carries_when_its_index_is_fresh(): void {
		$this->seed(
			self::items(
				[ 'feed:fresh-1', 'Mesa Courier hires an editor', '', 'https://news.example/m', 1793100000 ],
				[ 'feed:fresh-2', 'Ranged', '', 'https://news.example/r', 1785715200 ]
			)
		);
		Core::$now = self::NOW + 7;
		$this->signal( 'entity_created', [ 'entity_key' => 'organization:mesa-courier', 'type' => 'organization', 'name' => 'Mesa Courier' ] );
		$this->request( 'RERESOLVE 2026-08-03 2026-08-03' );

		$this->sweep_at( self::NOW + 7 + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->sweep_at( self::NOW + 8 + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame(
			[ [ 'feed:fresh-1', self::NOW + 7 + Reresolver_Node::SWEEP_INTERVAL_S ], [ 'feed:fresh-2', 0.0 ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['item_key'], $m[ Message::VALUE ]['fresh_after'] ], $this->out->captured )
		);
	}

	/** A domain searches URLs, its LIKE wildcards escaped; a handle searches its site's URLs. */
	public function test_an_identifier_searches_urls(): void {
		$this->seed(
			self::items(
				[ 'feed:url-1', 'An award', '', 'https://www.harbor_gazette.org/2026/award', 1793100000 ],
				[ 'feed:url-2', 'A wildcard lookalike', '', 'https://harborxgazette.org/a', 1793200000 ],
				[ 'feed:url-3', 'Names harbor_gazette.org in text', 'harbor_gazette.org', 'https://news.example/x', 1793300000 ],
				[ 'feed:url-4', 'A post', '', 'https://twitter.com/HarbGazNews/status/41', 1793400000 ]
			)
		);

		$this->signal( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'WWW.Harbor_Gazette.org' ] );
		$this->signal( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'x_handle', 'value' => 'harbgaznews' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->assertSame( [ 'feed:url-1' ], $this->sent_keys() );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + 1 );
		$this->assertSame( [ 'feed:url-1', 'feed:url-4' ], $this->sent_keys() );
	}

	/** A name is one quoted phrase: its quotes, star and OR are text, never query syntax. */
	public function test_a_name_is_searched_as_one_phrase(): void {
		$this->seed(
			self::items(
				[ 'feed:phrase-1', 'Mesa Daily or Courier merges', '', 'https://news.example/p', 1793100000 ],
				[ 'feed:phrase-2', 'Mesa Courier', '', 'https://news.example/q', 1793200000 ],
				[ 'feed:phrase-3', 'Daily Mesa or Courier', '', 'https://news.example/r', 1793300000 ]
			)
		);

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'Mesa "Daily" * OR Courier' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:phrase-1' ], $this->sent_keys() );
	}

	/** An acronym matches only in its own case, as the resolver links it: `LION` never sweeps an item spelling only "lion". */
	public function test_an_acronym_sweeps_only_its_own_case(): void {
		$this->seed(
			self::items(
				[ 'feed:acronym-1', 'LION members meet', '', 'https://news.example/1', 1793100000 ],
				[ 'feed:acronym-2', 'A lion escapes the zoo', 'The Lion was found.', 'https://news.example/2', 1793200000 ],
				[ 'feed:acronym-3', 'Members meet', 'Hosted by LION in June.', 'https://news.example/3', 1793300000 ]
			)
		);

		$this->signal( 'name_added', [ 'entity_key' => 'organization:lion-publishers', 'kind' => 'acronym', 'value' => 'LION' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:acronym-3', 'feed:acronym-1' ], $this->sent_keys() );
	}

	/** A curator's confirmation re-resolves its one item, judged, once its interval passes; no search runs. */
	public function test_a_confirmed_link_reresolves_its_item_judged(): void {
		$this->signal( 'link_confirmed', [ 'item_key' => 'feed:judged-17', 'entity_key' => self::GAZETTE, 'item_url' => 'https://news.example/j' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S - 1 );
		$this->assertSame( [], $this->out->captured );

		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame(
			[ [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => 'feed:judged-17', 'fresh_after' => self::NOW + Reresolver_Node::SWEEP_INTERVAL_S, 'judged' => true ] ],
			\array_map( static fn ( array $m ): mixed => $m[ Message::VALUE ], $this->out->captured )
		);
	}

	/** A signal is swept only once it is SWEEP_INTERVAL_S old, after the projector's flushes. */
	public function test_the_sweep_waits_for_the_interval(): void {
		$this->seed( self::items( [ 'feed:wait-1', 'HarbGaz wins', '', 'https://news.example/w', 1793100000 ] ) );

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S - 1 );
		$this->assertSame( [], $this->sent_keys() );

		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->assertSame( [ 'feed:wait-1' ], $this->sent_keys() );
		$this->assertSame( 30, Reresolver_Node::SWEEP_INTERVAL_S );
	}

	/** A due signal appends every item it names in one tick, newest first, and leaves the queue. */
	public function test_a_due_signal_sends_every_hit_at_once(): void {
		$rows = [];
		for ( $i = 0; $i < 45; $i++ ) {
			$rows[] = [ \sprintf( 'feed:burst-%02d', $i ), "HarbGaz story {$i}", '', "https://news.example/{$i}", 1793000000 + $i ];
		}
		$this->seed( self::items( ...$rows ) );
		$stderr = $this->capture_stderr();

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( \array_map( static fn ( int $i ): string => \sprintf( 'feed:burst-%02d', $i ), \range( 44, 0 ) ), $this->sent_keys() );
		$this->assertSame( [], $this->reresolver->save_state()['queue'] );
		$this->assertSame( [], self::stderr_lines( $stderr, 'skipping the oldest' ) );
	}

	/** One signal re-resolves at most MAX_ITEMS_PER_SIGNAL items, the newest, in one tick, and logs how many it skipped. */
	public function test_overflow_is_capped_and_counted(): void {
		$rows = [];
		for ( $i = 0; $i < Reresolver_Node::MAX_ITEMS_PER_SIGNAL + 20; $i++ ) {
			$rows[] = [ "feed:many-{$i}", "HarbGaz story {$i}", '', "https://news.example/{$i}", 1793000000 + $i ];
		}
		$this->seed( self::items( ...$rows ) );
		$stderr = $this->capture_stderr();

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$keys = $this->sent_keys();
		$this->assertCount( 500, $keys );
		$this->assertSame( [ 'feed:many-519', 'feed:many-20' ], [ $keys[0], $keys[499] ] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'HarbGaz names 520 items; skipping the oldest 20' ) );
		$this->assertSame( [], $this->reresolver->save_state()['queue'] );
		$this->assertSame( 500, Reresolver_Node::MAX_ITEMS_PER_SIGNAL );
	}

	/** Accepting an entity searches every name and identifier the projection holds for it. */
	public function test_an_accepted_entity_reresolves_its_names_and_identifiers(): void {
		$this->seed(
			self::proposed( 'organization:quarry-ledger', 'Quarry Ledger', [ 'acronym' => [ 'QL' ], 'domain' => [ 'quarryledger.com' ] ] ),
			self::items(
				[ 'feed:accept-1', 'Quarry Ledger wins', '', 'https://news.example/1', 1793100000 ],
				[ 'feed:accept-2', 'QL hires', '', 'https://news.example/2', 1793200000 ],
				[ 'feed:accept-3', 'A story', '', 'https://quarryledger.com/3', 1793300000 ],
				[ 'feed:accept-4', 'Ledger of quarries', '', 'https://news.example/4', 1793400000 ],
				[ 'feed:accept-5', 'Notes on ql syntax', '', 'https://news.example/5', 1793500000 ]
			)
		);

		$this->signal( 'entity_accepted', [ 'entity_key' => 'organization:quarry-ledger' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:accept-3', 'feed:accept-2', 'feed:accept-1' ], $this->sent_keys() );
	}

	/** A new locality settles the entity's ambiguous names, so it searches those names, never the place alone. */
	public function test_a_new_locality_searches_the_entity_names(): void {
		$this->seed(
			self::entity( self::GAZETTE, 'Harbor Gazette', [ 'locality' => [ 'Port Angeles' ] ] ),
			self::items(
				[ 'feed:locality-1', 'Harbor Gazette wins in Port Angeles', '', 'https://news.example/1', 1793100000 ],
				[ 'feed:locality-2', 'Port Angeles opens a pier', '', 'https://news.example/2', 1793200000 ]
			)
		);

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'locality', 'value' => 'Port Angeles' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:locality-1' ], $this->sent_keys() );
	}

	/** A topic is matched by extraction, never in text, so its names are never searched. */
	public function test_a_topic_is_never_searched(): void {
		$this->seed( self::items( [ 'feed:topic-1', 'Prizes for local news', '', 'https://news.example/t', 1793100000 ] ) );

		$this->signal( 'name_added', [ 'entity_key' => 'topic:awards', 'kind' => 'alias', 'value' => 'prizes' ] );
		$this->signal( 'entity_created', [ 'entity_key' => 'topic:prizes', 'type' => 'topic', 'name' => 'Prizes' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + 1 );

		$this->assertSame( [], $this->sent_keys() );
	}

	/** Each tick sweeps one signal, so a burst of signals never lands in one fill(). */
	public function test_one_signal_is_swept_per_tick(): void {
		$this->seed(
			self::items(
				[ 'feed:tick-1', 'HarbGaz wins', '', 'https://news.example/1', 1793100000 ],
				[ 'feed:tick-2', 'Mesa Courier wins', '', 'https://news.example/2', 1793200000 ]
			)
		);

		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->signal( 'name_added', [ 'entity_key' => 'organization:mesa-courier', 'kind' => 'alias', 'value' => 'Mesa Courier' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->assertSame( [ 'feed:tick-1' ], $this->sent_keys() );

		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + 1 );
		$this->assertSame( [ 'feed:tick-1', 'feed:tick-2' ], $this->sent_keys() );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + 2 );
		$this->assertCount( 2, $this->sent_keys(), 'a repeated signal is queued once' );
	}

	/** A day range is answered, then sends every item it dates, in the order they were recorded, a page a tick. */
	public function test_a_range_reresolves_every_item_in_pages(): void {
		$since = 1785715200;
		$after = 1787270400;
		$rows  = [
			[ 'feed:range-before', 'Before', '', 'https://news.example/b', $since - 1 ],
			[ 'feed:range-first', 'First', '', 'https://news.example/f', $since ],
			[ 'feed:range-last', 'Last', '', 'https://news.example/l', $after - 1 ],
			[ 'feed:range-after', 'After', '', 'https://news.example/a', $after ],
			[ 'feed:range-undated', 'Undated', '', 'https://news.example/u', 0, $since + 50 ],
			[ 'feed:range-undated-late', 'Undated late', '', 'https://news.example/v', 0, $after + 50 ],
		];
		for ( $i = 0; $i < 42; $i++ ) {
			$rows[] = [ \sprintf( 'feed:range-%02d', $i ), "Story {$i}", '', "https://news.example/{$i}", $since + 100 ];
		}
		$this->seed( self::items( ...$rows ) );
		$asker = $this->request( 'RERESOLVE 2026-08-03 2026-08-20' );

		$this->assertSame(
			[ [ Message::TM_STRUCT | Message::TM_RESPONSE, 'reresolve-asker', [ 'verb' => 'RERESOLVE', 'data' => [ 'since' => '2026-08-03', 'until' => '2026-08-20' ] ] ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::TO ], $m[ Message::VALUE ] ], $asker )
		);
		$this->sweep_at( self::NOW + 1 );
		$keys = $this->sent_keys();
		$this->assertCount( 20, $keys );
		$this->assertSame( [ 'feed:range-first', 'feed:range-last', 'feed:range-undated', 'feed:range-00' ], \array_slice( $keys, 0, 4 ) );
		$this->assertSame( 'feed:range-16', $keys[19] );

		$this->sweep_at( self::NOW + 2 );
		$this->assertSame( [ 'feed:range-17', 'feed:range-36' ], [ $this->sent_keys()[20], $this->sent_keys()[39] ] );
		$this->sweep_at( self::NOW + 3 );
		$this->assertSame( [ 'feed:range-37', 'feed:range-38', 'feed:range-39', 'feed:range-40', 'feed:range-41' ], \array_slice( $this->sent_keys(), 40 ) );
		$this->sweep_at( self::NOW + 4 );
		$this->assertCount( 45, $this->sent_keys(), 'a finished range leaves the queue' );
	}

	/** A due signal goes before any range, so a backfill never delays a curator's change. */
	public function test_a_due_signal_goes_before_a_range(): void {
		$rows = [ [ 'feed:first-harbgaz', 'HarbGaz wins', '', 'https://news.example/h', 1793100000 ] ];
		for ( $i = 0; $i < 21; $i++ ) {
			$rows[] = [ \sprintf( 'feed:first-range-%02d', $i ), "Story {$i}", '', "https://news.example/{$i}", 1785715200 + $i ];
		}
		$this->seed( self::items( ...$rows ) );

		$this->request( 'RERESOLVE 2026-08-03 2026-08-03' );
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [ 'feed:first-harbgaz' ], $this->sent_keys() );
	}

	/**
	 * An identifier matches a whole host or a whole path segment, never a
	 * longer one or a query that merely spells it.
	 */
	public function test_identifiers_match_whole_hosts_and_path_segments(): void {
		$this->seed(
			self::items(
				[ 'feed:anchor-host', 'A', '', 'https://ap.org/news/1', 1793100001 ],
				[ 'feed:anchor-sub', 'B', '', 'https://apnews.ap.org', 1793100002 ],
				[ 'feed:anchor-snap', 'C', '', 'https://snap.org/news/1', 1793100003 ],
				[ 'feed:anchor-query', 'D', '', 'https://news.example/x?ref=ap.org', 1793100004 ],
				[ 'feed:anchor-handle', 'E', '', 'https://x.com/abc?s=20', 1793100005 ],
				[ 'feed:anchor-handle-longer', 'F', '', 'https://x.com/abcxyz', 1793100006 ],
				[ 'feed:anchor-org', 'G', '', 'https://github.com/newspack#readme', 1793100007 ],
				[ 'feed:anchor-org-longer', 'H', '', 'https://github.com/newspack-other/repo', 1793100008 ],
				[ 'feed:anchor-dropbox', 'I', '', 'https://dropbox.com/abc', 1793100009 ],
				[ 'feed:anchor-port', 'J', '', 'https://ap.org:8443/x', 1793100010 ],
				[ 'feed:anchor-host-query', 'K', '', 'https://ap.org?x', 1793100011 ],
				[ 'feed:anchor-host-fragment', 'L', '', 'https://ap.org#y', 1793100012 ]
			)
		);

		$this->signal( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'ap.org' ] );
		$this->signal( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'x_handle', 'value' => 'abc' ] );
		$this->signal( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'github_org', 'value' => 'newspack' ] );
		for ( $tick = 0; $tick < 3; $tick++ ) {
			$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + $tick );
		}

		$this->assertSame( [ 'feed:anchor-host-fragment', 'feed:anchor-host-query', 'feed:anchor-port', 'feed:anchor-sub', 'feed:anchor-host', 'feed:anchor-handle', 'feed:anchor-org' ], $this->sent_keys() );
	}

	/** A checkpoint entry it cannot read is dropped, and the drop logged once naming it. */
	public function test_a_dropped_checkpoint_entry_is_logged(): void {
		$stderr = $this->capture_stderr();

		$this->reresolver->restore_state(
			[
				'queue' => [
					'name|HarbGaz' => [ 'at' => self::NOW, 'after' => null ],
					'name|Quarry'  => [ 'after' => null ],
					'comet|Halley' => [ 'at' => self::NOW, 'after' => null ],
				],
			]
		);

		$this->assertSame( [ 'name|HarbGaz' ], \array_keys( $this->reresolver->save_state()['queue'] ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping checkpoint entries it cannot read: name|Quarry, comet|Halley' ) );
	}

	/** A checkpoint with no readable queue is dropped, and the drop logged once. */
	public function test_a_checkpoint_without_a_queue_is_logged(): void {
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$stderr = $this->capture_stderr();

		$this->reresolver->restore_state( [ 'queue' => 'name|HarbGaz' ] );

		$this->assertSame( [], $this->reresolver->save_state()['queue'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a re-resolution checkpoint it cannot read: {"queue":"name|HarbGaz"}' ) );
	}

	/**
	 * A unit whose projection read fails for any reason but an absent table
	 * leaves the queue with one line, so a respawn cannot crash-loop on it,
	 * and the next unit runs on the next tick.
	 */
	public function test_a_failing_read_drops_its_unit_and_the_next_runs(): void {
		$this->seed( self::items( [ 'feed:contain-1', 'Mesa Courier wins', '', 'https://news.example/m', 1793100000 ] ) );
		$calls                      = 0;
		\Newspack_Intelligence\Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$calls ): mixed {
			if ( 1 === ++$calls ) {
				throw new \PDOException( 'SQLSTATE[HY000]: database disk image is malformed' );
			}
			return \Newspack_Nodes\Row_Table_Node::read( \Newspack_Intelligence\Intel_Graph_Row_Schema::class, $read );
		};
		$stderr = $this->capture_stderr();
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->signal( 'name_added', [ 'entity_key' => 'organization:mesa-courier', 'kind' => 'alias', 'value' => 'Mesa Courier' ] );

		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$this->assertSame( [], $this->sent_keys() );
		$this->assertSame( [ 'name|Mesa Courier' ], \array_keys( $this->reresolver->save_state()['queue'] ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'dropping a re-resolution unit its read failed: name|HarbGaz: SQLSTATE[HY000]: database disk image is malformed' ) );

		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S + 1 );
		$this->assertSame( [ 'feed:contain-1' ], $this->sent_keys() );
	}

	/** A cooperative stop raised in a read stops the worker and keeps the unit. */
	public function test_a_stop_in_a_read_propagates_and_keeps_the_unit(): void {
		\Newspack_Intelligence\Intel_Graph_Row_Schema::$read_rows = static function (): mixed {
			throw new Worker_Should_Stop( 'max_runtime 595 reached' );
		};
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );

		try {
			$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( [ 'name|HarbGaz' ], \array_keys( $this->reresolver->save_state()['queue'] ) );
	}

	/** A record whose resolution throws downstream dead-letters alone, and the next is delivered. */
	public function test_a_poison_record_dead_letters_and_the_next_is_resolved(): void {
		$this->seed(
			self::items(
				[ 'feed:poison-1', 'HarbGaz poison', '', 'https://news.example/p', 1793200000 ],
				[ 'feed:poison-2', 'HarbGaz healthy', '', 'https://news.example/q', 1793100000 ]
			)
		);
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		\assert( $interpreter instanceof Command_Interpreter_Node );
		$dir    = \Newspack_Nodes\Config::get_base_directory() . '/reresolve-stream';
		$stream = new Partition_Node();
		$stream->name( 'intel:reresolve' );
		$stream->arguments( [ "{$dir}/intel-reresolve.p0" ] );
		$stream->sink( $interpreter );
		$this->reresolver->sink( $interpreter );
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );
		$stream->flush();

		Resolver_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$downstream                 = new class() extends Node {
			/** @var list<string> */
			public array $resolved = [];

			public function fill( array $message ): void {
				if ( 'extractor' !== $message[ Message::TO ] ) {
					return;
				}
				$key = $message[ Message::VALUE ]['item']['item_key'];
				if ( 'feed:poison-1' === $key ) {
					throw new \RuntimeException( 'extractor broke on feed:poison-1' );
				}
				$this->resolved[] = $key;
			}
		};
		$resolver = new Resolver_Node();
		$resolver->name( 'resolver' );
		$resolver->arguments( [ 'intel:writer' ] );
		$resolver->target( 'extractor' );
		$resolver->sink( $downstream );
		$consumer = new Consumer_Node();
		$consumer->arguments( [ "{$dir}/intel-reresolve.p0", "{$dir}/offsets/p0", "{$dir}/deadletter/p0" ] );
		$consumer->name( 'intel:reresolve:resolve' );
		$consumer->sink( $resolver );
		$this->pump_consumer( $consumer );
		$consumer->checkpoint();
		Resolver_Node::$llm_factory = null;

		$this->assertSame( 1, $consumer->list_deadletter( 10 )['total'] );
		$this->assertSame( [ 'feed:poison-2' ], $downstream->resolved );
	}

	/** A request it cannot read is answered with the refusal, logged, and queues nothing. */
	public function test_a_malformed_range_is_refused(): void {
		$this->seed( self::items( [ 'feed:bad-1', 'Story', '', 'https://news.example/s', 1785715200 ] ) );
		$stderr = $this->capture_stderr();

		$replies = [
			...$this->request( 'RERESOLVE 2026-02-30 2026-08-20' ),
			...$this->request( 'RERESOLVE 2026-08-20 2026-08-03' ),
			...$this->request( 'RERESOLVE 2026-08-03' ),
		];
		$this->sweep_at( self::NOW + 1 );

		$this->assertSame(
			[
				[ 'refused' => 'not a Y-m-d date: 2026-02-30' ],
				[ 'refused' => 'since 2026-08-20 is after until 2026-08-03' ],
				[ 'refused' => 'usage: RERESOLVE <since Y-m-d> <until Y-m-d>' ],
			],
			\array_map( static fn ( array $m ): mixed => $m[ Message::VALUE ]['data'], $replies )
		);
		$this->assertSame( [], $this->out->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'refusing a re-resolution: not a Y-m-d date: 2026-02-30' ) );
	}

	/** The queue and a range's place in it checkpoint with the ontology Consumer's cursor. */
	public function test_the_queue_survives_a_respawn_through_its_snapshot(): void {
		$this->seed(
			self::items(
				[ 'feed:respawn-1', 'HarbGaz wins', '', 'https://news.example/1', 1793100000 ],
				[ 'feed:respawn-2', 'Ranged', '', 'https://news.example/2', 1785715200 ]
			)
		);
		$this->signal( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'HarbGaz' ] );
		$this->request( 'RERESOLVE 2026-08-03 2026-08-03' );
		$saved = \json_decode( (string) \json_encode( $this->reresolver->save_state() ), true );
		\assert( \is_array( $saved ) );
		$this->assertSame(
			[
				'name|HarbGaz'                => [ 'at' => self::NOW, 'after' => null ],
				'range|1785715200 1785801600' => [ 'at' => self::NOW, 'after' => null ],
			],
			$this->reresolver->save_state()['queue'],
			'a unit checkpoints its key, when it was queued and its cursor alone'
		);

		$this->reresolver->remove_node();
		$this->out        = new Capture_Sink_Node();
		$successor        = $this->reresolver();
		$successor->restore_state( $saved );
		$this->sweep_at( self::NOW + 1, $successor );
		$this->assertSame( [ 'feed:respawn-2' ], $this->sent_keys() );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S, $successor );
		$this->assertSame( [ 'feed:respawn-2', 'feed:respawn-1' ], $this->sent_keys() );
	}

	/** An ontology event that does not validate is the projector's to dead-letter; this ignores it. */
	public function test_an_unreadable_event_is_ignored(): void {
		$this->seed( self::items( [ 'feed:ignored-1', 'HarbGaz wins', '', 'https://news.example/1', 1793100000 ] ) );

		$this->reresolver->fill( self::message( [ 'event' => 'name_added', 'entity_key' => self::GAZETTE, 'value' => 'HarbGaz' ] ) );
		$this->reresolver->fill( self::message( 'TICK' ) );
		$this->signal( 'entity_rejected', [ 'entity_key' => self::GAZETTE ] );
		$this->sweep_at( self::NOW + Reresolver_Node::SWEEP_INTERVAL_S );

		$this->assertSame( [], $this->out->captured );
	}

	public function test_the_stream_is_declared_as_its_destination(): void {
		$this->assertSame( [ 'intel:reresolve' ], $this->reresolver->arguments() );
		$this->assertSame( [ 'intel:reresolve' ], $this->reresolver->display_targets() );
	}

	public function test_a_load_without_a_stream_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: stream' );
		( new Reresolver_Node() )->arguments( [] );
	}

	/** The composed graph tees the ontology stream to the projector and the reresolver, which it checkpoints. */
	public function test_the_composed_topology_tees_the_ontology_to_the_reresolver(): void {
		Core::reset();
		Core::$var['partition'] = '0';
		Core::$var['topology']  = 'newspack-intelligence';
		$router                 = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include newspack-intelligence\n" );

		$consumer = Core::node( 'intel:ontology:consumer' );
		$this->assertInstanceOf( Consumer_Node::class, $consumer );
		$this->assertSame( 'intel:ontology:tee', $consumer->target() );
		$this->assertSame( [ 'projector', 'reresolver' ], $this->read_private( $consumer, 'snapshot_nodes' ) );
		$tee = Core::node( 'intel:ontology:tee' );
		$this->assertInstanceOf( Tee_Node::class, $tee );
		$this->assertSame( [ 'projector', 'reresolver' ], $tee->target() );
		$reresolver = Core::node( 'reresolver' );
		$this->assertInstanceOf( Reresolver_Node::class, $reresolver );
		$this->assertSame( [ 'intel:reresolve' ], $reresolver->display_targets() );
		$this->assertTrue( $reresolver->timer_is_active() );
		$stream = Core::node( 'intel:reresolve' );
		$this->assertInstanceOf( Partition_Node::class, $stream );
		$this->assertStringEndsWith( '/logs/intel-reresolve.p0', $stream->partition_dir() );
		$this->assertSame( [ 1048576, 2, 4, 86400, 86400, 0 ], [ $this->read_private( $stream, 'segment_size' ), $this->read_private( $stream, 'min_segments' ), $this->read_private( $stream, 'num_segments' ), $this->read_private( $stream, 'min_lifetime' ), $this->read_private( $stream, 'lifetime' ), $this->read_private( $stream, 'max_segments' ) - 8 ] );
		$consumer = Core::node( 'intel:reresolve:resolve' );
		$this->assertInstanceOf( Consumer_Node::class, $consumer );
		$this->assertSame( 'resolver', $consumer->target() );
		$this->assertStringEndsWith( '/newspack-intelligence.intel-reresolve-resolve.p0', $this->read_private( $consumer, 'offsetlog_dir' ) );
		foreach ( [ 'intel:items:resolve', 'intel:reresolve:resolve' ] as $name ) {
			$this->assertTrue( $this->read_private( Core::node( $name ), 'line_mode' ), "{$name} hands the resolver one item a poll" );
		}
	}

	/** A reresolver named `reresolver`, writing to the capture sink. */
	private function reresolver(): Reresolver_Node {
		$node = new Reresolver_Node();
		$node->name( 'reresolver' );
		$node->arguments( [ 'intel:reresolve' ] );
		$node->sink( $this->out );
		return $node;
	}

	/**
	 * Deliver an ontology event as the Tee does.
	 *
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 */
	private function signal( string $kind, array $fields ): void {
		$this->reresolver->fill( self::message( Intel_Event::make( $kind, $fields, 'user:41', 'fixture-7' ) ) );
	}

	/**
	 * Send a TM_REQUEST, as a worker's input reader routes one.
	 *
	 * @param string $value The request line.
	 * @return list<array<int,mixed>> The replies addressed to the asker.
	 */
	private function request( string $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'reresolve-asker';
		$message[ Message::VALUE ] = $value;
		$this->reresolver->fill( $message );
		$replies = \array_values( \array_filter( $this->out->captured, static fn ( array $m ): bool => 'reresolve-asker' === $m[ Message::TO ] ) );
		$this->out->captured = \array_values( \array_filter( $this->out->captured, static fn ( array $m ): bool => 'reresolve-asker' !== $m[ Message::TO ] ) );
		return $replies;
	}

	/**
	 * Run the timer at a moment.
	 *
	 * @param float                $at   The tick clock.
	 * @param Reresolver_Node|null $node The node, or the test's own.
	 */
	private function sweep_at( float $at, ?Reresolver_Node $node = null ): void {
		Core::$now = $at;
		( $node ?? $this->reresolver )->fire_cb();
	}

	/** @return list<string> Each item key appended to the reresolve stream, in order. */
	private function sent_keys(): array {
		$keys = [];
		foreach ( $this->out->captured as $message ) {
			$this->assertSame( [ Message::TM_STRUCT, 'reresolver', 'intel:reresolve' ], [ $message[ Message::TYPE ], $message[ Message::FROM ], $message[ Message::TO ] ] );
			$this->assertSame( [ 'event', 'item_key', 'fresh_after', 'judged' ], \array_keys( $message[ Message::VALUE ] ) );
			$this->assertSame( Resolver_Node::RERESOLVE, $message[ Message::VALUE ]['event'] );
			$this->assertFalse( $message[ Message::VALUE ]['judged'], 'a search judges nothing' );
			$keys[] = $message[ Message::VALUE ]['item_key'];
		}
		return $keys;
	}

	/**
	 * Recorded items: `[ key, title, body, url, item_ts, retrieved_ts? ]` each.
	 *
	 * @param array{0:string,1:string,2:string,3:string,4:int,5?:int} ...$rows The items.
	 * @return list<array<string,mixed>>
	 */
	private static function items( array ...$rows ): array {
		$events = [];
		foreach ( $rows as $row ) {
			$fields   = [ 'item_key' => $row[0], 'source' => 'feed', 'tier' => 'internal', 'url' => $row[3], 'title' => $row[1], 'body' => $row[2], 'item_ts' => $row[4], 'retrieved_ts' => $row[5] ?? 1793900000 ];
			$events[] = Intel_Event::make( 'item_recorded', $fields, 'machine:source:feed@9.4.1', '9.4.1' );
		}
		return $events;
	}

	/**
	 * A record from the ontology Tee carrying a VALUE.
	 *
	 * @param mixed $value The VALUE.
	 * @return array<int,mixed>
	 */
	private static function message( mixed $value ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = \is_array( $value ) ? Message::TM_STRUCT : Message::TM_INFO;
		$message[ Message::FROM ]  = 'intel:ontology:consumer';
		$message[ Message::VALUE ] = $value;
		return $message;
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Digest_Builder_Node;
use Newspack_Intelligence\LLM_Client;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Digest_Builder state contracts: id-dedup on accumulate, distinct-source progress
 * counting, RESET clearing, and the auto-compose that fires once every source has
 * reported DONE for the cycle (count(reported) === total, the make_node arg).
 */
final class DigestBuilderStateTest extends TestCase {

	protected function tearDown(): void {
		Digest_Builder_Node::$llm_factory = null;
		parent::tearDown();
	}

	/** @param array<string,mixed> $v */
	private function feed( Digest_Builder_Node $n, array $v ): void {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $v;
		$n->fill( $m );
	}

	/** Fire a TM_INFO DONE (what a source emits at the end of a TICK; FROM = its name, VALUE = DONE). */
	private function done( Digest_Builder_Node $n, string $source = 'github' ): void {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::FROM ]  = $source;
		$m[ Message::VALUE ] = "DONE\n";
		$n->fill( $m );
	}

	/** A full RESET: the request, then its fence arriving back in-band. */
	private function reset( Digest_Builder_Node $n ): void {
		$this->request( $n, 'RESET' );
		$this->fence( $n );
	}

	/** Deliver the RESET fence as scored:consumer hands it back down the pipeline. */
	private function fence( Digest_Builder_Node $n ): void {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::FROM ]  = 'scored:consumer/ingest:consumer/_repl/digest-under-test';
		$m[ Message::VALUE ] = "RESET\n";
		$n->fill( $m );
	}

	/**
	 * The drafts the node has emitted so far.
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function drafts( Capture_Sink_Node $sink ): array {
		return \array_values(
			\array_filter( $sink->captured, static fn ( array $m ): bool => Message::TM_BYTESTREAM === $m[ Message::TYPE ] )
		);
	}

	/** Fire a TM_REQUEST carrying `$verb`, from an asker the reply must find. */
	private function request( Digest_Builder_Node $n, string $verb ): void {
		$r                   = Message::new_message();
		$r[ Message::TYPE ]  = Message::TM_REQUEST;
		$r[ Message::FROM ]  = '_repl/insights-digest-asker';
		$r[ Message::ID ]    = '2:640:88';
		$r[ Message::KEY ]   = 'digest-key-17';
		$r[ Message::VALUE ] = $verb;
		$n->fill( $r );
	}

	/**
	 * Assert the last captured message answers `$verb` with `$data`, addressed
	 * back along the request's FROM with its ID and KEY echoed.
	 *
	 * @param array<string,mixed> $data
	 */
	private function assert_answered( Capture_Sink_Node $sink, string $verb, array $data ): void {
		$reply = \end( $sink->captured );
		$this->assertSame( Message::TM_STRUCT | Message::TM_RESPONSE, $reply[ Message::TYPE ] );
		$this->assertSame( 'digest-under-test', $reply[ Message::FROM ] );
		$this->assertSame( '_repl/insights-digest-asker', $reply[ Message::TO ] );
		$this->assertSame( '2:640:88', $reply[ Message::ID ] );
		$this->assertSame( 'digest-key-17', $reply[ Message::KEY ] );
		$this->assertSame( [ 'verb' => $verb, 'data' => $data ], $reply[ Message::VALUE ] );
	}

	public function test_reset_appends_a_fence_to_the_ingest_partition_and_clears_when_it_returns(): void {
		$node = new Digest_Builder_Node();
		$node->name( 'digest-under-test' );
		$node->arguments( [ 'ingest:sentinel-7', '3' ] );
		$sink = new Capture_Sink_Node();
		$node->sink( $sink );
		$this->feed( $node, [ 'id' => 'github:5', 'title' => 'five' ] );
		$this->feed( $node, [ 'id' => 'linear:6', 'title' => 'six' ] );
		$this->feed( $node, [ 'id' => 'feed:7', 'title' => 'seven' ] );

		$this->request( $node, 'RESET' );

		$fence = $sink->captured[0];
		$this->assertSame( Message::TM_INFO, $fence[ Message::TYPE ] );
		$this->assertSame( 'digest-under-test', $fence[ Message::FROM ] );
		$this->assertSame( 'ingest:sentinel-7', $fence[ Message::TO ] );
		$this->assertSame( "RESET\n", $fence[ Message::VALUE ] );
		$this->assert_answered( $sink, 'RESET', [ 'fence' => 'ingest:sentinel-7' ] );
		$this->assertCount( 3, $node->save_state()['items'], 'nothing clears until the fence returns' );

		$this->fence( $node );

		$this->assertCount( 0, $node->save_state()['items'] );
	}

	/**
	 * A DONE minted before RESET reaches the digest behind the RESET request but
	 * ahead of its fence, so it completes its own cycle and never the next one.
	 */
	public function test_a_done_in_flight_at_reset_counts_toward_its_own_cycle(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$node                             = new Digest_Builder_Node();
		$node->name( 'digest-under-test' );
		$node->arguments( [ 'ingest:partition', '3' ] );
		$sink = new Capture_Sink_Node();
		$node->sink( $sink );
		$this->feed( $node, [ 'id' => 'github:41', 'summary' => 'cycle one', 'score' => 2.0 ] );
		$this->done( $node, 'github' );

		$this->request( $node, 'RESET' );
		$this->done( $node, 'linear' );
		$this->done( $node, 'feed' );

		$this->assertCount( 1, $this->drafts( $sink ), 'the late DONEs complete the cycle they belong to' );
		$this->assertStringContainsString( '- cycle one', $this->drafts( $sink )[0][ Message::VALUE ] );

		$this->fence( $node );
		$this->done( $node, 'github' );
		$this->done( $node, 'linear' );

		$this->assertSame( 2, $node->save_state()['done'] );
		$this->assertCount( 1, $this->drafts( $sink ), 'two of three sources is not a complete cycle' );
	}

	public function test_a_done_after_completion_does_not_recompose(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$node                             = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '2' ] );
		$sink = new Capture_Sink_Node();
		$node->sink( $sink );
		$this->done( $node, 'github' );
		$this->done( $node, 'linear' );
		$this->assertCount( 1, $this->drafts( $sink ) );

		$this->done( $node, 'linear' );
		$this->done( $node, 'feed' );

		$this->assertCount( 1, $this->drafts( $sink ), 'a replayed or surplus DONE leaves the draft alone' );
	}

	public function test_regenerate_answers_with_the_count_it_composed_after_the_draft(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$node                             = new Digest_Builder_Node();
		$node->name( 'digest-under-test' );
		$sink = new Capture_Sink_Node();
		$node->sink( $sink );
		$this->feed( $node, [ 'id' => 'github:8', 'title' => 'eight', 'source' => 'github' ] );
		$this->feed( $node, [ 'id' => 'github:9', 'title' => 'nine', 'source' => 'github' ] );

		$this->request( $node, 'REGENERATE' );

		$this->assertCount( 2, $sink->captured, 'the draft, then the answer' );
		$this->assertSame( Message::TM_BYTESTREAM, $sink->captured[0][ Message::TYPE ] );
		$this->assert_answered( $sink, 'REGENERATE', [ 'composed' => 2 ] );
	}

	public function test_dedupes_accumulated_items_by_id(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->feed( $node, [ 'id' => 'github:x#1', 'summary' => 'a' ] );
		$this->feed( $node, [ 'id' => 'github:x#1', 'summary' => 'a-again' ] );
		$this->feed( $node, [ 'id' => 'github:y#2', 'summary' => 'b' ] );
		$this->assertCount( 2, $node->save_state()['items'] );
	}

	public function test_items_without_an_id_are_all_kept(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->feed( $node, [ 'summary' => 'a' ] );
		$this->feed( $node, [ 'summary' => 'b' ] );
		$this->assertCount( 2, $node->save_state()['items'] );
	}

	public function test_reset_clears_dedup_so_the_next_cycle_re_accepts_the_id(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->feed( $node, [ 'id' => 'github:x#1', 'summary' => 'a' ] );
		$this->reset( $node );
		$this->assertCount( 0, $node->save_state()['items'] );
		$this->feed( $node, [ 'id' => 'github:x#1', 'summary' => 'a' ] );
		$this->assertCount( 1, $node->save_state()['items'] );
	}

	public function test_restore_state_dedupes_a_dirty_snapshot(): void {
		$node = new Digest_Builder_Node();
		$node->restore_state(
			[
				'items' => [
					[ 'id' => 'a', 'summary' => '1' ],
					[ 'id' => 'a', 'summary' => '2' ],
					[ 'id' => 'b', 'summary' => '3' ],
				],
			]
		);
		$this->assertCount( 2, $node->save_state()['items'] );
	}

	public function test_composes_and_emits_the_draft_when_all_sources_report_done(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$sink                             = new Capture_Sink_Node();
		$node                             = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '2' ] );
		$node->sink( $sink );

		$this->feed( $node, [ 'summary' => 'shipped X', 'score' => 5.0 ] );
		$this->done( $node, 'github' );
		$this->assertCount( 0, $sink->captured, 'no compose until every source is in' );

		$this->done( $node, 'linear' );
		$this->assertCount( 1, $sink->captured, 'composes once the last source reports' );
		$this->assertSame( Message::TM_BYTESTREAM, $sink->captured[0][ Message::TYPE ] );
		$this->assertStringContainsString( '- shipped X', $sink->captured[0][ Message::VALUE ] );
	}

	public function test_arguments_round_trips_the_total(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '3' ] );
		$this->assertSame( [ 'ingest:partition', '3' ], $node->arguments() );
	}

	public function test_total_comes_from_args_and_reset_zeroes_done(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '3' ] );
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node );
		$this->reset( $node );
		$state = $node->save_state();
		$this->assertSame( 0, $state['done'] );
		$this->assertSame( 3, $state['total'] );
	}

	public function test_distinct_sources_advance_the_counter(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node, 'github' );
		$this->done( $node, 'linear' );
		$this->assertSame( 2, $node->save_state()['done'] );
	}

	public function test_a_repeated_source_counts_once(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node, 'github' );
		$this->done( $node, 'github' );
		$this->assertSame( 1, $node->save_state()['done'], 'a re-ticked source must not double-count' );
	}

	public function test_done_is_not_counted_as_an_item(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node );
		$this->assertCount( 0, $node->save_state()['items'] );
	}

	public function test_reset_zeroes_done_for_the_next_cycle(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '2' ] );
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node );
		$this->reset( $node );
		$state = $node->save_state();
		$this->assertSame( 0, $state['done'] );
		// total comes from args and is retained across RESET so the dashboard shows e.g. 0/2.
		$this->assertSame( 2, $state['total'] );
	}

	public function test_progress_round_trips_through_save_and_restore(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '3' ] );
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node, 'github' );
		$this->done( $node, 'linear' );

		$restored = new Digest_Builder_Node();
		$restored->arguments( [ 'ingest:partition', '3' ] );
		$restored->restore_state( $node->save_state() );

		$this->assertSame( [ 'github', 'linear' ], $restored->save_state()['reported'] );
		$this->assertSame( 2, $restored->save_state()['done'] );
	}

	public function test_the_configured_total_outranks_a_snapshot_total(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:partition', '4' ] );

		$node->restore_state( [ 'items' => [], 'reported' => [ 'github' ], 'total' => 3 ] );

		$this->assertSame( 4, $node->save_state()['total'] );
	}

	public function test_a_missing_total_fails_loud(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: total' );

		( new Digest_Builder_Node() )->arguments( [ 'ingest:partition' ] );
	}

	/** The fence's Partition is a destination the console must draw an edge to. */
	public function test_display_targets_include_the_fenced_partition(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( [ 'ingest:sentinel-7', '3' ] );
		$node->connect_node( 'digest:tee' );

		$this->assertSame( [ 'digest:tee', 'ingest:sentinel-7' ], $node->display_targets() );
	}

	public function test_restored_sources_stay_deduped_across_a_restart(): void {
		$node = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$node->restore_state( [ 'items' => [], 'reported' => [ 'github', 'linear' ], 'total' => 3 ] );
		// A re-delivered DONE for an already-counted source doesn't advance; a new one does.
		$this->done( $node, 'github' );
		$this->assertSame( 2, $node->save_state()['done'] );
		$this->done( $node, 'feed' );
		$this->assertSame( 3, $node->save_state()['done'] );
	}
}

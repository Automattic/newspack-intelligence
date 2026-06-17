<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\LLM_Client;
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
		$m[ Message::VALUE ] = 'DONE';
		$n->fill( $m );
	}

	/** Fire a RESET request (clears items + dedup + progress; total comes from the node's args). */
	private function reset( Digest_Builder_Node $n ): void {
		$r                   = Message::new_message();
		$r[ Message::TYPE ]  = Message::TM_REQUEST;
		$r[ Message::VALUE ] = 'RESET';
		$n->fill( $r );
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
		$node->arguments( '2' );
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
		$node->arguments( '3' );
		$this->assertSame( '3', $node->arguments() );
	}

	public function test_total_comes_from_args_and_reset_zeroes_done(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( '3' );
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
		$node->arguments( '2' );
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
		$node->restore_state( [ 'items' => [], 'reported' => [ 'github', 'linear' ], 'total' => 3 ] );
		$state = $node->save_state();
		$this->assertSame( 2, $state['done'] );
		$this->assertSame( 3, $state['total'] );
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

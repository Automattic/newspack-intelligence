<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\LLM_Client;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Digest_Builder state contracts: id-dedup on accumulate, and the FLUSH-time
 * "nudge" that advances scored:consumer so the emptied snapshot is persisted to
 * the offsetlog (otherwise a restart reloads the stale full items list and new
 * items append to it forever).
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

	private function flush( Digest_Builder_Node $n ): void {
		$r                   = Message::new_message();
		$r[ Message::TYPE ]  = Message::TM_REQUEST;
		$r[ Message::VALUE ] = 'FLUSH';
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

	public function test_flush_clears_dedup_so_the_next_cycle_re_accepts_the_id(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$node                             = new Digest_Builder_Node();
		$node->sink( new Capture_Sink_Node() );
		$this->feed( $node, [ 'id' => 'github:x#1', 'summary' => 'a' ] );
		$this->flush( $node );
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

	public function test_flush_nudges_the_configured_scored_partition(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$sink                             = new Capture_Sink_Node();
		$node                             = new Digest_Builder_Node();
		$node->name( 'digest' );
		$node->arguments( 'scored:partition' );
		$node->sink( $sink );

		$this->feed( $node, [ 'id' => 'a', 'summary' => 's' ] );
		$this->flush( $node );

		$nudge = null;
		foreach ( $sink->captured as $m ) {
			if ( 'scored:partition' === $m[ Message::TO ] ) {
				$nudge = $m;
			}
		}
		$this->assertNotNull( $nudge, 'FLUSH must nudge the scored partition' );
		$this->assertSame( 'RESET', $nudge[ Message::VALUE ] );
	}

	public function test_no_nudge_when_no_partition_is_configured(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$sink                             = new Capture_Sink_Node();
		$node                             = new Digest_Builder_Node();
		$node->sink( $sink );

		$this->feed( $node, [ 'id' => 'a', 'summary' => 's' ] );
		$this->flush( $node );

		foreach ( $sink->captured as $m ) {
			$this->assertNotSame( 'scored:partition', $m[ Message::TO ] );
		}
	}

	public function test_arguments_round_trips_the_partition_name(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( 'scored:partition' );
		$this->assertSame( 'scored:partition', $node->arguments() );
	}

	/** Fire a TM_INFO DONE (what a source emits at the end of a TICK; FROM = its name, VALUE = DONE). */
	private function done( Digest_Builder_Node $n, string $source = 'github' ): void {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::FROM ]  = $source;
		$m[ Message::VALUE ] = 'DONE';
		$n->fill( $m );
	}

	/** Fire a RESET request (zeroes the progress counter; total comes from the node's args). */
	private function reset( Digest_Builder_Node $n ): void {
		$r                   = Message::new_message();
		$r[ Message::TYPE ]  = Message::TM_REQUEST;
		$r[ Message::VALUE ] = 'RESET';
		$n->fill( $r );
	}

	public function test_total_comes_from_args_and_reset_zeroes_done(): void {
		$node = new Digest_Builder_Node();
		$node->arguments( 'scored:partition 3' );
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

	public function test_flush_resets_done_for_the_next_cycle(): void {
		Digest_Builder_Node::$llm_factory = static fn (): ?LLM_Client => null;
		$node                             = new Digest_Builder_Node();
		$node->arguments( 'scored:partition 2' );
		$node->sink( new Capture_Sink_Node() );
		$this->done( $node );
		$this->flush( $node );
		$state = $node->save_state();
		$this->assertSame( 0, $state['done'] );
		// total comes from args and is retained across FLUSH so the dashboard shows e.g. 0/2.
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

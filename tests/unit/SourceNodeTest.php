<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Source_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class SourceNodeTest extends TestCase {

	private function tick(): array {
		$request                  = Message::new_message();
		$request[ Message::TYPE ] = Message::TM_REQUEST;
		$request[ Message::KEY ]  = 'TICK';
		return $request;
	}

	/**
	 * The emitted item structs (a TICK also emits a trailing TM_INFO DONE, which
	 * these item-count assertions exclude).
	 *
	 * @param array<int,array<int,mixed>> $captured
	 * @return array<int,array<int,mixed>>
	 */
	private function structs( array $captured ): array {
		return \array_values(
			\array_filter( $captured, static fn ( $m ) => 0 !== ( $m[ Message::TYPE ] & Message::TM_STRUCT ) )
		);
	}

	public function test_tick_emits_each_fetched_item_as_struct(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->items = [
			[ 'source' => 'fake', 'id' => 'fake:1', 'title' => 'A' ],
			[ 'source' => 'fake', 'id' => 'fake:2', 'title' => 'B' ],
		];
		$node->sink( $sink );

		$req = $this->tick();
		$node->fill( $req );

		$structs = $this->structs( $sink->captured );
		$this->assertCount( 2, $structs );
		$this->assertSame( 'fake:1', $structs[0][ Message::VALUE ]['id'] );
	}

	public function test_tick_emits_a_done_signal_after_the_items(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->items = [ [ 'source' => 'fake', 'id' => 'fake:1', 'title' => 'A' ] ];
		$node->sink( $sink );

		$req = $this->tick();
		$node->fill( $req );

		$last = \end( $sink->captured );
		$this->assertSame( Message::TM_INFO, $last[ Message::TYPE ] & Message::TM_INFO );
		$this->assertSame( 'DONE', $last[ Message::VALUE ] );
	}

	public function test_dedups_by_id_across_ticks(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->items = [ [ 'source' => 'fake', 'id' => 'fake:1', 'title' => 'A' ] ];
		$node->sink( $sink );

		$a = $this->tick();
		$node->fill( $a );
		$b = $this->tick();
		$node->fill( $b );

		$this->assertCount( 1, $this->structs( $sink->captured ), 'a seen id must not be re-emitted on a later tick' );
	}

	public function test_skips_items_with_no_id(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->items = [ [ 'source' => 'fake', 'title' => 'no id' ] ];
		$node->sink( $sink );

		$req = $this->tick();
		$node->fill( $req );

		$this->assertCount( 0, $this->structs( $sink->captured ) );
	}

	public function test_non_request_message_is_ignored(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->items = [ [ 'source' => 'fake', 'id' => 'fake:1' ] ];
		$node->sink( $sink );

		$data                  = Message::new_message();
		$data[ Message::TYPE ] = Message::TM_STRUCT;
		$node->fill( $data );

		$this->assertCount( 0, $sink->captured );
	}

	public function test_seen_id_set_is_bounded_and_evicts_the_oldest_id(): void {
		$sink = new Capture_Sink_Node();
		$node = new Fake_Source_Node();
		$node->sink( $sink );
		for ( $i = 1; $i <= 2001; $i++ ) {
			$node->items[] = [ 'source' => 'fake', 'id' => "fake:$i", 'title' => "Item $i" ];
		}
		$first = $this->tick();
		$node->fill( $first );

		$node->items = [ [ 'source' => 'fake', 'id' => 'fake:1', 'title' => 'Item 1 again' ] ];
		$second = $this->tick();
		$node->fill( $second );

		$this->assertCount( 2002, $this->structs( $sink->captured ) );
	}

	public function test_source_schema_declares_the_shared_tick_contract(): void {
		$schema = Fake_Source_Node::expose_source_schema();

		$this->assertSame( 'Source', $schema['category'] );
		$this->assertFalse( $schema['accepts_fill'] );
		$this->assertSame( 'Fake source', $schema['description'] );
		$this->assertSame(
			[ [ 'name' => 'TICK', 'description' => 'Fetch fake items.' ] ],
			$schema['requests']
		);
	}

}

/**
 * Concrete Source_Node whose fetch() returns canned items, so the base's
 * fill()/dedup/emit/snapshot behavior can be tested without HTTP.
 */
class Fake_Source_Node extends Source_Node {

	/** @var array<int,array<string,mixed>> */
	public array $items = [];

	protected function config(): array {
		return [];
	}

	public function fetch( array $config ): array {
		return $this->items;
	}

	public static function expose_source_schema(): array {
		return self::source_schema( 'Fake source', 'Fetch fake items.' );
	}
}

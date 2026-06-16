<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Stub_Source_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

final class StubSourceTest extends TestCase {

	/** A fire-and-forget TICK request (TM_REQUEST) — the runtime trigger. */
	private function tick(): array {
		$request                  = Message::new_message();
		$request[ Message::TYPE ] = Message::TM_REQUEST;
		$request[ Message::KEY ]  = 'TICK';
		return $request;
	}

	public function test_tick_request_emits_three_normalized_struct_items(): void {
		$sink = new Capture_Sink_Node();
		$node = new Stub_Source_Node();
		$node->sink( $sink );

		$request = $this->tick();
		$node->fill( $request );

		$this->assertCount( 3, $sink->captured );

		$first = $sink->captured[0];
		$this->assertTrue( (bool) ( $first[ Message::TYPE ] & Message::TM_STRUCT ), 'emitted message is not TM_STRUCT' );

		$item = $first[ Message::VALUE ];
		foreach ( [ 'source', 'id', 'title', 'url', 'body', 'timestamp' ] as $key ) {
			$this->assertArrayHasKey( $key, $item, "emitted item missing '$key'" );
		}
		$this->assertSame( 'stub:1', $item['id'] );
		$this->assertIsInt( $item['timestamp'] );
		$this->assertGreaterThan( 0, $item['timestamp'] );
	}

	public function test_non_request_message_emits_nothing(): void {
		$sink = new Capture_Sink_Node();
		$node = new Stub_Source_Node();
		$node->sink( $sink );

		// A source mints, it doesn't consume: a data message must not trigger emission.
		$data                  = Message::new_message();
		$data[ Message::TYPE ] = Message::TM_STRUCT;
		$node->fill( $data );

		$this->assertCount( 0, $sink->captured );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Event_Writer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * The writer addresses each event by TO to the partition its make_node line
 * names for its kind's stream, and drops what names no kind rather than
 * throwing out of fill().
 */
final class IntelEventWriterTest extends TestCase {

	private Capture_Sink_Node $sink;

	private Intel_Event_Writer_Node $writer;

	protected function setUp(): void {
		parent::setUp();
		Core::$now        = 1793000000.25;
		Core::$recent_log = [];
		$this->sink       = new Capture_Sink_Node();
		$this->writer     = new Intel_Event_Writer_Node();
		$this->writer->name( 'intel:writer' );
		$this->writer->arguments( [ 'harbor:items', 'harbor:ontology' ] );
		$this->writer->sink( $this->sink );
	}

	/**
	 * A VALUE as a source delivers it, already addressed to the writer.
	 *
	 * @param mixed $value The VALUE.
	 * @param int   $type  The TYPE.
	 * @return array<int,mixed>
	 */
	private function delivered( mixed $value, int $type = Message::TM_STRUCT ): array {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = $type;
		$message[ Message::FROM ]  = 'feed';
		$message[ Message::TO ]    = 'intel:writer';
		$message[ Message::VALUE ] = $value;
		return $message;
	}

	public function test_the_writer_routes_each_kind_to_its_streams_partition(): void {
		$identifier = Intel_Event::make( 'identifier_added', [ 'entity_key' => 'organization:harbor-gazette', 'kind' => 'domain', 'value' => 'harborgazette.example' ], 'user:41', 'clients-v3' );
		$item       = Intel_Event::make(
			'item_recorded',
			[
				'item_key'     => 'feed:abc-7731',
				'source'       => 'feed',
				'tier'         => 'internal',
				'url'          => 'https://harborgazette.example/award',
				'title'        => 'Harbor Gazette wins state award',
				'body'         => 'The Gazette took first place.',
				'item_ts'      => 1792900000,
				'retrieved_ts' => 1793000000.25,
			],
			'machine:source:feed@9.4.1',
			'9.4.1'
		);

		$this->writer->fill( $this->delivered( $identifier ) );
		$this->writer->fill( $this->delivered( $item ) );

		$this->assertCount( 2, $this->sink->captured );
		$this->assertSame( 'harbor:ontology', $this->sink->captured[0][ Message::TO ] );
		$this->assertSame( $identifier, $this->sink->captured[0][ Message::VALUE ] );
		$this->assertSame( 'harbor:items', $this->sink->captured[1][ Message::TO ] );
		$this->assertSame( $item, $this->sink->captured[1][ Message::VALUE ] );
	}

	public function test_the_writer_drops_what_names_no_kind_without_throwing(): void {
		$this->writer->fill( $this->delivered( [ 'event' => 'item_seen', 'item_key' => 'feed:abc-7731' ] ) );
		$this->writer->fill( $this->delivered( 'item_recorded feed:abc-7731' ) );
		$this->writer->fill( $this->delivered( [ 'event' => 'item_recorded' ], Message::TM_BYTESTREAM ) );

		$this->assertSame( [], $this->sink->captured );
		$logged = \implode( "\n", Core::$recent_log );
		$this->assertStringContainsString( 'item_seen', $logged );
		$this->assertStringContainsString( 'not an event', $logged );
	}

	public function test_the_writer_declares_the_partitions_its_arguments_name(): void {
		$this->assertSame( [ 'harbor:items', 'harbor:ontology' ], Intel_Event_Writer_Node::declared_targets( [ 'harbor:items', 'harbor:ontology' ] ) );
		$this->assertSame( [ 'harbor:items', 'harbor:ontology' ], $this->writer->display_targets() );
	}

	public function test_the_writer_needs_both_partitions(): void {
		$writer = new Intel_Event_Writer_Node();
		$writer->name( 'intel:writer:bare' );

		$this->expectException( \InvalidArgumentException::class );
		$writer->arguments( [ 'harbor:items' ] );
	}
}

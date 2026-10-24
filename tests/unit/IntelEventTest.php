<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\TestCase;

final class IntelEventTest extends TestCase {

	public function test_make_fills_the_envelope_and_routes_by_kind(): void {
		Core::$now = 1234567.25;
		$event     = Intel_Event::make(
			'name_added',
			[
				'entity_key' => 'organization:atomic-149378595',
				'kind'       => 'alias',
				'value'      => 'Atomic Co',
			],
			'user:4217',
			'cfg-9'
		);

		$this->assertSame( 'name_added', $event['event'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $event['event_id'] );
		$this->assertSame( 1234567.25, $event['ts'] );
		$this->assertSame( 'user:4217', $event['actor'] );
		$this->assertSame( 'cfg-9', $event['config_version'] );
		$this->assertSame( 'Atomic Co', $event['value'] );
		$this->assertSame( Intel_Event::ONTOLOGY, Intel_Event::stream_of( 'name_added' ) );
		$this->assertSame( Intel_Event::ITEMS, Intel_Event::stream_of( 'item_recorded' ) );
	}

	public function test_validate_names_the_missing_field(): void {
		$event = $this->item_recorded();
		unset( $event['tier'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/item_recorded.*tier/' );
		Intel_Event::validate( $event );
	}

	public function test_validate_refuses_an_unknown_kind(): void {
		$event          = $this->item_recorded();
		$event['event'] = 'item_seen';

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/item_seen/' );
		Intel_Event::validate( $event );
	}

	public function test_validate_refuses_a_value_outside_its_set(): void {
		$event         = $this->item_recorded();
		$event['tier'] = 'secret';
		try {
			Intel_Event::validate( $event );
			$this->fail( 'tier secret accepted' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'item_recorded', $e->getMessage() );
			$this->assertStringContainsString( 'tier', $e->getMessage() );
		}

		$role = [
			'event'          => 'role_asserted',
			'event_id'       => str_repeat( 'a', 32 ),
			'ts'             => 1.0,
			'actor'          => 'user:7',
			'config_version' => 'c',
			'entity_key'     => 'organization:x',
			'role'           => 'sponsor',
			'from_ts'        => 5.0,
		];
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/role_asserted.*role/' );
		Intel_Event::validate( $role );
	}

	public function test_a_person_or_agent_never_writes_an_items_stream_event(): void {
		foreach ( [ 'user:4217', 'agent:4217:claude' ] as $actor ) {
			try {
				Intel_Event::make(
					'link_asserted',
					[
						'item_key'   => 'i1',
						'entity_key' => 'topic:ai',
						'role'       => 'topic',
						'method'     => 'name',
						'confidence' => 0.5,
					],
					$actor,
					'c'
				);
				$this->fail( "{$actor} wrote link_asserted" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( 'link_asserted', $e->getMessage() );
			}
		}
	}

	public function test_an_ontology_event_refuses_an_unknown_actor(): void {
		foreach ( [ 'robot:1', '', 'nobody' ] as $actor ) {
			try {
				Intel_Event::make( 'entity_merged', [ 'entity_key' => 'topic:a', 'into_key' => 'topic:b' ], $actor, 'c' );
				$this->fail( "actor '{$actor}' accepted" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $actor, $e->getMessage() );
			}
		}
	}

	public function test_validate_refuses_a_malformed_envelope(): void {
		$cases = [
			'event_id'       => [ 'event_id', 'ABCDEF' ],
			'ts'             => [ 'ts', '12:00' ],
			'config_version' => [ 'config_version', 7 ],
			'actor'          => [ 'actor', 42 ],
		];
		foreach ( $cases as $name => [ $field, $bad ] ) {
			$event           = $this->item_recorded();
			$event[ $field ] = $bad;
			try {
				Intel_Event::validate( $event );
				$this->fail( "bad {$name} accepted" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $name, $e->getMessage() );
			}
		}
	}

	public function test_validate_refuses_a_missing_envelope_field(): void {
		$event = $this->item_recorded();
		unset( $event['config_version'] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/config_version/' );
		Intel_Event::validate( $event );
	}

	public function test_validate_accepts_a_machine_item_recorded(): void {
		Intel_Event::validate( $this->item_recorded() );
		$this->addToAssertionCount( 1 );
	}

	/** An extraction's topic is a link the extractor made, by method `extract`. */
	public function test_extract_is_a_link_method(): void {
		$event = Intel_Event::make(
			'link_asserted',
			[
				'item_key'   => 'feed:abc-7731',
				'entity_key' => 'topic:awards',
				'role'       => 'topic',
				'method'     => 'extract',
				'confidence' => 1.0,
			],
			'machine:extract@9.4.1',
			''
		);

		$this->assertSame( 'extract', $event['method'] );
	}

	/** A machine actor names its stage and version. */
	public function test_machine_names_the_stage_and_its_version(): void {
		$this->assertSame( 'machine:extract@v3', Intel_Event::machine( 'extract', 'v3' ) );
	}

	/** Each kind carries its stream, its fields and the sets they are drawn from. */
	public function test_one_table_holds_each_kinds_stream_fields_and_sets(): void {
		$this->assertSame( [ 'event', 'event_id', 'ts', 'actor', 'config_version' ], Intel_Event::ENVELOPE );
		$this->assertSame(
			[ Intel_Event::ITEMS, [ 'item_key', 'entity_key', 'role', 'method', 'confidence' ], [ 'role' => Intel_Event::LINK_ROLES, 'method' => Intel_Event::METHODS ] ],
			Intel_Event::KINDS['link_asserted']
		);
		$this->assertSame( [ Intel_Event::ONTOLOGY, [ 'entity_key' ], [] ], Intel_Event::KINDS['entity_accepted'] );
	}

	/** An ontology record must fit one line: the stream takes no large writes. */
	public function test_make_refuses_an_ontology_event_past_one_line(): void {
		$name = \str_repeat( 'Gazette ', 625 );
		try {
			Intel_Event::make( 'entity_proposed', [ 'entity_key' => 'organization:harbor-gazette', 'type' => 'organization', 'name' => $name, 'from_item_key' => 'feed:abc-7731' ], 'machine:resolve@9.4.1', '' );
			$this->fail( 'an oversize ontology event was made' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertMatchesRegularExpression( '/entity_proposed.*\d{4} bytes.*4096/', $e->getMessage() );
		}

		$item = Intel_Event::make( 'item_recorded', [ 'body' => $name ] + \array_diff_key( $this->item_recorded(), \array_flip( Intel_Event::ENVELOPE ) ), 'machine:source:feed@9.4.1', '' );
		$this->assertSame( $name, $item['body'], 'the items stream takes a large record' );
	}

	public function test_from_message_reads_the_event_a_message_carries(): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = $this->item_recorded();

		$this->assertSame( $this->item_recorded(), Intel_Event::from_message( $message ) );
	}

	public function test_from_message_refuses_what_is_not_an_event(): void {
		$cases = [
			'a bytestream'      => [ Message::TM_BYTESTREAM, $this->item_recorded(), 'not a TM_STRUCT' ],
			'a string TYPE'     => [ (string) Message::TM_STRUCT, $this->item_recorded(), 'not a TM_STRUCT' ],
			'a scalar VALUE'    => [ Message::TM_STRUCT, 'item_recorded github:1', 'no array VALUE' ],
			'an invalid event'  => [ Message::TM_STRUCT, \array_diff_key( $this->item_recorded(), [ 'source' => true ] ), 'missing field source' ],
		];
		foreach ( $cases as $case => [ $type, $value, $why ] ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = $type;
			$message[ Message::VALUE ] = $value;
			try {
				Intel_Event::from_message( $message );
				$this->fail( "accepted {$case}" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertStringContainsString( $why, $e->getMessage(), $case );
			}
		}
	}

	public function test_stream_of_refuses_an_unknown_kind(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/item_seen/' );
		Intel_Event::stream_of( 'item_seen' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function item_recorded(): array {
		return [
			'event'          => 'item_recorded',
			'event_id'       => str_repeat( 'b', 32 ),
			'ts'             => 2.0,
			'actor'          => 'machine:ingest@v1',
			'config_version' => 'c',
			'item_key'       => 'github:1',
			'source'         => 'github',
			'tier'           => 'internal',
			'url'            => 'https://example.test/1',
			'title'          => 'T',
			'body'           => 'B',
			'item_ts'        => 1.0,
			'retrieved_ts'   => 2.0,
		];
	}
}

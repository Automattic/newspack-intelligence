<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Nodes\Capabilities;
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
						'retrieved_ts' => 1791200000,
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
				'retrieved_ts' => 1791300000,
			],
			'machine:extract@9.4.1',
			''
		);

		$this->assertSame( 'extract', $event['method'] );
	}

	/** A retraction names the link row it removes by its key, on the items stream, its role drawn from LINK_ROLES. */
	public function test_a_link_retraction_names_the_row_by_its_key(): void {
		$this->assertSame( [ Intel_Event::ITEMS, [ 'item_key', 'entity_key', 'role', 'retrieved_ts' ], [ 'role' => Intel_Event::LINK_ROLES ] ], Intel_Event::KINDS['link_retracted'] );
		$event = Intel_Event::machine_event( 'resolve', 'link_retracted', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:mesa-courier', 'role' => 'mention', 'retrieved_ts' => 1791300000 ] );
		$this->assertSame( Intel_Event::ITEMS, Intel_Event::stream_of( $event['event'] ) );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'link_retracted: field role is outside its set' );
		Intel_Event::machine_event( 'resolve', 'link_retracted', [ 'item_key' => 'feed:abc-7731', 'entity_key' => 'organization:mesa-courier', 'role' => 'byline', 'retrieved_ts' => 1791300000 ] );
	}

	/** A purge names its item and why, on the items stream, which only a machine writes. */
	public function test_a_purge_names_its_item_and_why(): void {
		$this->assertSame( [ Intel_Event::ITEMS, [ 'item_key', 'reason' ], [] ], Intel_Event::KINDS['item_purged'] );
		$event = Intel_Event::machine_event( 'purge', 'item_purged', [ 'item_key' => 'linear:WPCOM-4410', 'reason' => 'other team' ] );
		$this->assertSame( [ 'machine:purge@' . \NEWSPACK_INTELLIGENCE_VERSION, 'linear:WPCOM-4410', 'other team' ], [ $event['actor'], $event['item_key'], $event['reason'] ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'item_purged: only a machine writes the items stream' );
		Intel_Event::make( 'item_purged', [ 'item_key' => 'linear:WPCOM-4410', 'reason' => 'other team' ], 'user:4217', 'c' );
	}

	/** An entity key is its type and the slug of its name. */
	public function test_entity_key_slugs_the_name_under_its_type(): void {
		$this->assertSame( 'product:newspack-plugin', Intel_Event::entity_key( 'product', 'Newspack Plugin' ) );
		$this->assertSame( 'organization:elan-gazette', Intel_Event::entity_key( 'organization', 'Élan Gazette' ) );
	}

	public function test_entity_key_refuses_a_type_outside_types(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'vehicle' );
		Intel_Event::entity_key( 'vehicle', 'Harbor Gazette' );
	}

	public function test_entity_key_refuses_a_slug_that_sanitizes_to_nothing(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/organization.*!!!/' );
		Intel_Event::entity_key( 'organization', '!!!' );
	}

	/** A machine's event names its stage at the plugin version, and stamps that version as its config. */
	public function test_machine_event_stamps_the_stage_and_the_plugin_version(): void {
		$event = Intel_Event::machine_event( 'quarry-stage', 'cluster_joined', [ 'item_key' => 'feed:machine-62', 'cluster_key' => 'cluster:62', 'retrieved_ts' => 1791400000 ] );

		Intel_Event::validate( $event );
		$version = \NEWSPACK_INTELLIGENCE_VERSION;
		$this->assertSame( [ "machine:quarry-stage@{$version}", $version, 'feed:machine-62' ], [ $event['actor'], $event['config_version'], $event['item_key'] ] );
	}

	/** An entity and a collection are keyed by the slug of a name, and refused alike when it slugs to nothing. */
	public function test_entity_and_collection_keys_share_one_slug(): void {
		$this->assertSame( 'person:marisol-okafor', Intel_Event::entity_key( 'person', 'Marisol Okafor' ) );
		$this->assertSame( 'grant-watch', Intel_Event::collection_key( 'Grant Watch' ) );
		$refusals = [];
		foreach ( [ static fn (): string => Intel_Event::entity_key( 'person', '!?!' ), static fn (): string => Intel_Event::collection_key( '!?!' ) ] as $key ) {
			try {
				$key();
			} catch ( \InvalidArgumentException $e ) {
				$refusals[] = $e->getMessage();
			}
		}
		$this->assertSame( [ 'person name !?! sanitizes to nothing', 'collection name !?! sanitizes to nothing' ], $refusals );
	}

	/** A machine actor names its stage at the plugin version. */
	public function test_a_machine_actor_names_its_stage(): void {
		$this->assertSame( 'machine:ledger-sweep@' . \NEWSPACK_INTELLIGENCE_VERSION, Intel_Event::machine( 'ledger-sweep' ) );
	}

	/**
	 * The caller is the signed-in person, or the agent a labelled session
	 * names, its label kept whole; both validate.
	 */
	public function test_the_caller_is_the_person_or_the_agent_their_session_names(): void {
		$GLOBALS['_current_user_id']         = 4217;
		$GLOBALS['_wp_test_user_logins'][4217] = 'harbor-desk';
		$label                               = 'Research: weekly ' . \str_repeat( 'é', 70 );
		try {
			$person                      = Intel_Event::caller();
			Capabilities::$session_label = '';
			$unlabelled                  = Intel_Event::caller();
			Capabilities::$session_label = $label;
			$agent                       = Intel_Event::caller();
		} finally {
			unset( $GLOBALS['_current_user_id'], $GLOBALS['_wp_test_user_logins'] );
			Capabilities::$session_label = null;
		}

		$this->assertSame( [ 'user:harbor-desk', 'user:harbor-desk', "agent:harbor-desk:{$label}" ], [ $person, $unlabelled, $agent ] );
		foreach ( [ $person, $agent ] as $actor ) {
			Intel_Event::validate( Intel_Event::make( 'entity_accepted', [ 'entity_key' => 'organization:quarry-ledger' ], $actor, '6.1.4' ) );
		}
	}

	/** With no signed-in person there is no caller. */
	public function test_no_signed_in_person_is_no_caller(): void {
		Capabilities::$session_label = 'Research: weekly';
		try {
			$this->expectException( \InvalidArgumentException::class );
			$this->expectExceptionMessage( 'a user actor needs a login' );
			Intel_Event::caller();
		} finally {
			Capabilities::$session_label = null;
		}
	}

	/** A role is stamped with the tick clock's whole second; an event with the tick clock itself. */
	public function test_now_is_the_tick_clocks_second(): void {
		Core::$now = 1791904321.875;

		$event = Intel_Event::make( 'entity_accepted', [ 'entity_key' => 'organization:quarry-ledger' ], 'user:88', '6.1.4' );

		$this->assertSame( 1791904321, Intel_Event::now() );
		$this->assertSame( 1791904321.875, $event['ts'] );
	}

	/** Each kind carries its stream, its fields and the sets they are drawn from. */
	public function test_one_table_holds_each_kinds_stream_fields_and_sets(): void {
		$this->assertSame( [ 'event', 'event_id', 'ts', 'actor', 'config_version' ], Intel_Event::ENVELOPE );
		$this->assertSame(
			[ Intel_Event::ITEMS, [ 'item_key', 'entity_key', 'role', 'method', 'confidence', 'retrieved_ts' ], [ 'role' => Intel_Event::LINK_ROLES, 'method' => Intel_Event::METHODS ] ],
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

	/** A watch's definition rides the ontology stream; its matches, seen marks and deliveries ride the watches stream. */
	public function test_a_watch_is_defined_on_the_ontology_and_matched_on_the_watches_stream(): void {
		$this->assertSame(
			[ Intel_Event::ONTOLOGY, [ 'watch_key', 'owner', 'name', 'target_kind', 'target_key', 'digest', 'instant', 'inbox' ], [ 'target_kind' => [ 'lens', 'entity', 'collection' ], 'digest' => [ true, false ], 'instant' => [ true, false ], 'inbox' => [ true, false ] ] ],
			Intel_Event::KINDS['watch_saved']
		);
		$this->assertSame( [ Intel_Event::ONTOLOGY, [ 'watch_key', 'owner' ], [] ], Intel_Event::KINDS['watch_removed'] );
		$this->assertSame( [ Intel_Event::WATCHES, [ 'watch_key', 'owner', 'item_key', 'at', 'retrieved_ts', 'quiet' ], [ 'quiet' => [ true, false ] ] ], Intel_Event::KINDS['watch_matched'] );
		$this->assertSame( [ Intel_Event::WATCHES, [ 'watch_key', 'owner', 'item_key', 'retrieved_ts' ], [] ], Intel_Event::KINDS['watch_seen'] );
		$this->assertSame( [ Intel_Event::WATCHES, [ 'owner', 'mode', 'watch_key', 'through' ], [ 'mode' => [ 'scan', 'instant', 'digest' ] ] ], Intel_Event::KINDS['watch_delivered'] );
		$this->assertSame( 'watches', Intel_Event::WATCHES );
	}

	/** A watch's checkbox is a bool: any other value is outside its set. */
	public function test_a_watch_flag_is_a_bool(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'watch_saved: field instant is outside its set' );
		Intel_Event::make( 'watch_saved', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'name' => 'Gazette', 'target_kind' => 'entity', 'target_key' => 'organization:harbor-gazette', 'digest' => true, 'instant' => 1, 'inbox' => false ], 'user:harbor-reader', '9.4.1' );
	}

	/** A watches-stream record must fit one line, as an ontology record must. */
	public function test_make_refuses_a_watches_event_past_one_line(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessageMatches( '/watch_seen.*\d{4} bytes.*4096/' );
		Intel_Event::make( 'watch_seen', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'item_key' => 'feed:' . \str_repeat( 'x', 4200 ), 'retrieved_ts' => 1793000000 ], 'user:harbor-reader', '9.4.1' );
	}

	/** An item is exactly the item_recorded fields, in their order; one missing is none. */
	public function test_item_of_takes_exactly_the_item_fields(): void {
		$fields = [ 'retrieved_ts' => 1796000000, 'item_key' => 'feed:item-of-31', 'source' => 'feed', 'tier' => 'internal', 'url' => 'https://quarry.example/a', 'title' => 'Quarry Ledger', 'body' => 'b', 'item_ts' => 1795990000, 'event' => 'item_recorded', 'actor' => 'machine:x@1' ];

		$this->assertSame(
			[ 'item_key' => 'feed:item-of-31', 'source' => 'feed', 'tier' => 'internal', 'url' => 'https://quarry.example/a', 'title' => 'Quarry Ledger', 'body' => 'b', 'item_ts' => 1795990000, 'retrieved_ts' => 1796000000 ],
			Intel_Event::item_of( $fields )
		);
		unset( $fields['item_ts'] );
		$this->assertNull( Intel_Event::item_of( $fields ) );
	}
}

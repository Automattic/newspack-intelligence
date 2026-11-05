<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The index the resolver matches against: accepted and proposed entities by
 * name norm and identifier, a merged entity answering as its survivor, a
 * rejected one absent but remembered by name.
 */
final class EntityIndexTest extends TestCase {
	use Seeds_Entity_Index;

	private const GAZETTE = 'organization:harbor-gazette';

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/**
	 * Plain hostnames, and what is wrong with each value that is not one.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function hostnames(): array {
		$label = \str_repeat( 'a', 63 );
		return [
			'a hostname'               => [ 'harborgazette.example', null ],
			'hyphens and digits'       => [ 'tide-2201.news9.example', null ],
			'a 63-character label'     => [ "{$label}.example", null ],
			'253 characters'           => [ "{$label}.{$label}.{$label}." . \str_repeat( 'b', 58 ) . '.ab', null ],
			'254 characters'           => [ "{$label}.{$label}.{$label}." . \str_repeat( 'b', 59 ) . '.ab', 'it runs past 253 characters' ],
			'an IPv4 literal'          => [ '10.0.0.5', 'its top-level domain is not alphabetic' ],
			'an IPv6 literal'          => [ '[::1]', 'it holds a character other than a-z, 0-9, a hyphen or a dot' ],
			'a single label'           => [ 'localhost', 'it has a single label' ],
			'a port'                   => [ 'harborgazette.example:8080', 'it holds a character other than a-z, 0-9, a hyphen or a dot' ],
			'a path'                   => [ 'harborgazette.example/feed', 'it holds a character other than a-z, 0-9, a hyphen or a dot' ],
			'userinfo'                 => [ 'pier@harborgazette.example', 'it holds a character other than a-z, 0-9, a hyphen or a dot' ],
			'a trailing dot'           => [ 'harborgazette.example.', 'it has an empty label' ],
			'a 64-character label'     => [ "a{$label}.example", 'a label runs past 63 characters' ],
			'a leading hyphen'         => [ '-harborgazette.example', 'a label starts or ends with a hyphen' ],
			'a trailing hyphen'        => [ 'harborgazette-.example', 'a label starts or ends with a hyphen' ],
			'a numeric top level'      => [ 'harborgazette.2201', 'its top-level domain is not alphabetic' ],
			'nothing'                  => [ '', 'it has a single label' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'hostnames' )]
	public function test_a_domain_must_be_a_plain_hostname( string $domain, ?string $fault ): void {
		$this->assertSame( $fault, Entity_Index::hostname_fault( $domain ) );
	}

	public function test_names_answer_their_entity_with_kind_surface_and_state(): void {
		$index = $this->index_of( self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'locality' => [ 'Port Angeles' ] ] ) );

		$this->assertSame(
			[
				[ 'entity_key' => self::GAZETTE, 'kind' => 'canonical', 'surface' => 'The Harbor Gazette', 'state' => 'accepted', 'at' => 10, 'stoplisted' => false ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'canonical', 'surface' => 'Harbor Gazette', 'state' => 'accepted', 'at' => 14, 'stoplisted' => false ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'locality', 'surface' => 'Port Angeles', 'state' => 'accepted', 'at' => 32, 'stoplisted' => false ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'surface' => 'HarbGaz', 'state' => 'accepted', 'at' => 51, 'stoplisted' => false ],
			],
			$index->names_in( 'Award for The Harbor Gazette of Port Angeles, says HarbGaz.', true )
		);
	}

	/** Every overlapping hit comes back with its byte offset, the longer of two at one word first. */
	public function test_names_in_returns_every_overlapping_hit_at_its_offset(): void {
		$index = $this->index_of( self::entity( 'organization:times', 'Times' ), self::entity( 'organization:the-bayport-times', 'The Bayport Times' ) );

		$this->assertSame(
			[ [ 'organization:the-bayport-times', 'The Bayport Times', 6 ], [ 'organization:the-bayport-times', 'Bayport Times', 10 ], [ 'organization:times', 'Times', 18 ] ],
			\array_map( static fn ( array $hit ): array => [ $hit['entity_key'], $hit['surface'], $hit['at'] ], $index->names_in( 'Today The Bayport Times wins a Kestrel', true ) )
		);
	}

	/** A locality inside a longer name still counts as evidence. */
	public function test_a_locality_inside_a_longer_name_is_kept(): void {
		$index = $this->index_of(
			self::entity( 'organization:port-angeles-gazette', 'Port Angeles Gazette' ),
			self::entity( self::GAZETTE, 'Harbor Gazette', [ 'locality' => [ 'Port Angeles' ] ] )
		);

		$this->assertSame(
			[ [ 'organization:port-angeles-gazette', 'canonical' ], [ self::GAZETTE, 'locality' ] ],
			\array_map( static fn ( array $hit ): array => [ $hit['entity_key'], $hit['kind'] ], $index->names_in( 'Port Angeles Gazette wins', true ) )
		);
	}

	/** A name matches as whole words only, so it never matches inside a longer word. */
	public function test_a_name_matches_whole_words_only(): void {
		$index = $this->index_of( self::entity( self::GAZETTE, 'Harbor Gazette', [ 'alias' => [ 'HarbGaz' ] ] ) );

		$this->assertSame( [], $index->names_in( 'HarbGazette and HarborGazette launch', true ) );
	}

	/** A name of six words matches; the walk reaches no further. */
	public function test_a_name_matches_up_to_six_words(): void {
		$six   = 'organization:the-north-coast-public-media-cooperative';
		$seven = 'organization:north-coast-public-media-news-cooperative-trust';
		$index = $this->index_of(
			self::entity( $six, 'North Coast Public Media News Cooperative' ),
			self::entity( $seven, 'North Coast Public Media News Cooperative Trust' )
		);

		$this->assertSame( [ $six ], \array_column( $index->names_in( 'Today North Coast Public Media News Cooperative Trust grew.', true ), 'entity_key' ) );
		$this->assertSame( 6, Entity_Index::MAX_NAME_WORDS );
	}

	/** An acronym answers only the case it is written in. */
	public function test_an_acronym_answers_its_exact_case_only(): void {
		$index = $this->index_of( self::entity( 'organization:owl-publishers', 'Organized Writers League', [ 'acronym' => [ 'OWL' ] ] ) );

		$this->assertSame( [ [ 'entity_key' => 'organization:owl-publishers', 'kind' => 'acronym', 'surface' => 'OWL', 'state' => 'accepted', 'at' => 0, 'stoplisted' => false ] ], $index->names_in( 'OWL summit opens', true ) );
		$this->assertSame( [], $index->names_in( 'an owl escapes; Owl King', true ) );
	}

	public function test_a_folded_codepoint_keeps_the_surface_it_was_written_in(): void {
		$index = $this->index_of( self::entity( 'organization:istanbul-gazette', 'İstanbul Gazette' ) );

		$this->assertSame( [ 'İstanbul Gazette' ], \array_column( $index->names_in( 'Award: İstanbul Gazette wins', true ), 'surface' ) );
	}

	/** A merged entity's names and identifiers answer its survivor's key. */
	public function test_merged_names_and_identifiers_answer_the_survivor(): void {
		$index = $this->index_of(
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::entity( 'organization:gazette-harbor', 'Gazette of the Harbor', [ 'alias' => [ 'GH Daily' ], 'domain' => [ 'ghdaily.example' ] ] ),
			self::merged( 'organization:gazette-harbor', self::GAZETTE )
		);

		$this->assertSame( [ self::GAZETTE, self::GAZETTE ], \array_column( $index->names_in( 'Gazette of the Harbor, or GH Daily', true ), 'entity_key' ) );
		$this->assertSame( self::GAZETTE, $index->by_identifier( 'domain', 'ghdaily.example' ) );
		$this->assertSame( [ 'ghdaily.example' => self::GAZETTE ], $index->identifiers( 'domain' ) );
	}

	/** A chain of merges answers its last survivor. */
	public function test_a_merge_chain_answers_its_last_survivor(): void {
		$index = $this->index_of(
			self::entity( 'organization:salem-ledger', 'Salem Ledger' ),
			self::entity( 'organization:ledger-salem', 'Ledger of Salem' ),
			self::entity( 'organization:the-ledger', 'Old Salem Ledger' ),
			self::merged( 'organization:the-ledger', 'organization:ledger-salem' ),
			self::merged( 'organization:ledger-salem', 'organization:salem-ledger' )
		);

		$this->assertSame( [ 'organization:salem-ledger' ], \array_values( \array_unique( \array_column( $index->names_in( 'Old Salem Ledger', true ), 'entity_key' ) ) ) );
	}

	/** A merge cycle drops the entities on it, logged once, and loads the rest. */
	public function test_a_merge_cycle_drops_its_entities_and_loads_the_rest(): void {
		$stderr = $this->capture_stderr();
		$index  = $this->index_of(
			self::entity( 'organization:salem-ledger', 'Salem Ledger', [ 'domain' => [ 'salemledger.example' ] ] ),
			self::entity( 'organization:ledger-salem', 'Ledger of Salem' ),
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::merged( 'organization:ledger-salem', 'organization:salem-ledger' ),
			self::merged( 'organization:salem-ledger', 'organization:ledger-salem' )
		);

		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Salem Ledger, Ledger of Salem and Harbor Gazette', true ), 'entity_key' ) );
		$this->assertNull( $index->by_identifier( 'domain', 'salemledger.example' ) );
		$lines = self::stderr_lines( $stderr, 'entity merge cycle' );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'organization:ledger-salem → organization:salem-ledger → organization:ledger-salem', $lines[0] );
	}

	/** The survivor map names each merged key's survivor, '' on a broken chain, and logs each broken chain once. */
	public function test_survivors_map_every_merged_key(): void {
		$stderr = $this->capture_stderr();
		$this->seed(
			self::entity( 'organization:salem-ledger', 'Salem Ledger' ),
			self::merged( 'organization:the-ledger', 'organization:ledger-salem' ),
			self::merged( 'organization:ledger-salem', 'organization:salem-ledger' ),
			self::merged( 'organization:loop-a', 'organization:loop-b' ),
			self::merged( 'organization:loop-b', 'organization:loop-a' )
		);
		$reader = Sqlite_Arm::open_rows( [ 0 => Table_Node::file( 'intel-graph', 0 ) ], Intel_Graph_Row_Schema::version() );

		$this->assertSame(
			[ 'organization:ledger-salem' => 'organization:salem-ledger', 'organization:loop-a' => '', 'organization:loop-b' => '', 'organization:the-ledger' => 'organization:salem-ledger' ],
			Entity_Index::survivors( $reader )
		);
		Entity_Index::survivors( $reader );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'entity merge cycle' ) );
	}

	/** A chain of exactly ten hops still answers its survivor. */
	public function test_a_merge_chain_of_ten_hops_resolves(): void {
		$events = [ self::entity( 'organization:ledger-0', 'Ledger Zero' ), self::entity( 'organization:ledger-10', 'Ledger Ten' ) ];
		for ( $hop = 0; $hop < 10; $hop++ ) {
			$events[] = self::merged( "organization:ledger-{$hop}", 'organization:ledger-' . ( $hop + 1 ) );
		}
		$index = $this->index_of( ...$events );

		$this->assertSame( [ 'organization:ledger-10' ], \array_column( $index->names_in( 'Ledger Zero', true ), 'entity_key' ) );
	}

	/** A chain past ten hops answers no survivor; the rest loads. */
	public function test_a_merge_chain_past_ten_hops_is_dropped(): void {
		$stderr = $this->capture_stderr();
		$events = [ self::entity( 'organization:ledger-0', 'Ledger Zero' ), self::entity( self::GAZETTE, 'Harbor Gazette' ) ];
		for ( $hop = 0; $hop < 11; $hop++ ) {
			$events[] = self::merged( "organization:ledger-{$hop}", 'organization:ledger-' . ( $hop + 1 ) );
		}
		$index = $this->index_of( ...$events );

		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Ledger Zero and Harbor Gazette', true ), 'entity_key' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'past 10 hops' ) );
	}

	/** Each indexed entity answers its state; any other key answers none. */
	public function test_state_answers_each_indexed_entity(): void {
		$index = $this->index_of(
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::proposed( 'organization:salem-ledger', 'Salem Ledger' ),
			self::proposed( 'organization:gazette-harbor', 'Gazette of the Harbor' ),
			self::merged( 'organization:gazette-harbor', self::GAZETTE ),
			self::proposed( 'organization:faded-gazette', 'Faded Gazette' ),
			self::rejected( 'organization:faded-gazette' )
		);

		$this->assertSame( 'accepted', $index->state( self::GAZETTE ) );
		$this->assertSame( 'proposed', $index->state( 'organization:salem-ledger' ) );
		$this->assertNull( $index->state( 'organization:faded-gazette' ) );
		$this->assertNull( $index->state( 'organization:ghost-gazette' ) );
	}

	/** An index holds live entities once one is accepted or proposed and neither merged away nor rejected. */
	public function test_it_holds_live_entities_only_when_one_is_live(): void {
		$this->assertSame( 'no_live_entity', ( new Entity_Index() )->hold_cause() );
		$this->assertSame(
			'no_live_entity',
			$this->index_of(
				self::proposed( 'organization:faded-gazette', 'Faded Gazette' ),
				self::rejected( 'organization:faded-gazette' )
			)->hold_cause(),
			'a rejected entity is not live'
		);
		$this->assertSame( '', $this->index_of( self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) )->hold_cause() );
	}

	/**
	 * Projections and the hold each one makes, with what a second
	 * partition's file holds.
	 *
	 * @return array<string,array{0:list<list<array<string,mixed>>>,1:string,2?:list<list<array<string,mixed>>>}>
	 */
	public static function projections(): array {
		return [
			'nothing projected'            => [ [], 'no_live_entity' ],
			'a proposed entity'            => [ [ self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) ], '' ],
			'a rejected entity'            => [ [ self::proposed( 'organization:faded-gazette', 'Faded Gazette' ), self::rejected( 'organization:faded-gazette' ) ], 'no_live_entity' ],
			'an entity merged away alone'  => [ [ self::entity( 'organization:gazette-harbor', 'Gazette of the Harbor' ), self::merged( 'organization:gazette-harbor', 'organization:never-made' ) ], 'no_live_entity' ],
			'an entity merged into a live' => [ [ self::entity( 'organization:gazette-harbor', 'Gazette of the Harbor' ), self::entity( self::GAZETTE, 'Harbor Gazette' ), self::merged( 'organization:gazette-harbor', self::GAZETTE ) ], '' ],
			'a state with no entity row'   => [ [ [ Intel_Event::make( 'entity_accepted', [ 'entity_key' => 'organization:nowhere-courier' ], 'user:41', 'fixture-7' ) ] ], 'no_live_entity' ],
			'an accepted topic'            => [ [ self::entity( 'topic:tidal-reporting', 'Tidal reporting' ) ], '' ],
			'p0 accepted, p1 rejected'     => [ [ self::entity( 'organization:wren-dispatch', 'Wren Dispatch' ) ], 'no_live_entity', [ self::rejected( 'organization:wren-dispatch' ) ] ],
			'p0 rejected, p1 accepted'     => [ [ self::proposed( 'organization:wren-dispatch', 'Wren Dispatch' ), self::rejected( 'organization:wren-dispatch' ) ], '', [ [ Intel_Event::make( 'entity_accepted', [ 'entity_key' => 'organization:wren-dispatch' ], 'user:41', 'fixture-7' ) ] ] ],
		];
	}

	/**
	 * The cheap check answers what a loaded index answers, over the same
	 * projection, and loads no index to do it; across partitions, each
	 * key's state is the one the highest partition holds.
	 *
	 * @param list<list<array<string,mixed>>> $events The ontology events projected into p0.
	 * @param string                          $cause  The hold they make.
	 * @param list<list<array<string,mixed>>> $later  The ontology events projected into p1.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'projections' )]
	public function test_hold_cause_now_answers_as_a_loaded_index( array $events, string $cause, array $later = [] ): void {
		$this->seed( ...$events );
		$files = [ 0 => Table_Node::file( 'intel-graph', 0 ) ];
		if ( [] !== $later ) {
			$other = $this->projector_graph( 'intel-graph-b', 'projector-b' );
			foreach ( \array_merge( ...$later ) as $event ) {
				self::deliver( $other, $event );
			}
			$other->save_state();
			$files[1] = Table_Node::file( 'intel-graph-b', 0 );
		}
		$loaded = Entity_Index::load( Sqlite_Arm::open_rows( $files, Intel_Graph_Row_Schema::version() ) )->hold_cause();
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( $files, Intel_Graph_Row_Schema::version() ) );

		$this->assertSame( [ $cause, $cause ], [ $loaded, Entity_Index::hold_cause_now() ] );
		$this->assertNull( ( new \ReflectionProperty( Entity_Index::class, 'current' ) )->getValue(), 'the shared index is not loaded' );
	}

	/** An index read behind the ontology stream names that first, so a seed not yet projected never reads as an empty ontology. */
	public function test_hold_cause_names_why_an_index_holds(): void {
		$behind = new \ReflectionProperty( Entity_Index::class, 'behind' );
		$empty  = new Entity_Index();
		$live   = $this->index_of( self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) );

		$this->assertSame( [ 'no_live_entity', '' ], [ $empty->hold_cause(), $live->hold_cause() ] );

		$behind->setValue( $empty, true );
		$behind->setValue( $live, true );

		$this->assertSame( [ 'behind_ontology', 'behind_ontology' ], [ $empty->hold_cause(), $live->hold_cause() ], 'an index read behind names the projector first, empty or not' );
	}

	/** A rejected entity matches nothing, and its names stay known as rejected. */
	public function test_a_rejected_entity_is_absent_and_its_names_are_rejected(): void {
		$index = $this->index_of(
			self::proposed( 'organization:faded-gazette', 'Faded Gazette', [ 'alias' => [ 'FadeGaz' ], 'domain' => [ 'fadedgazette.example' ] ] ),
			self::rejected( 'organization:faded-gazette' )
		);

		$this->assertSame( [], $index->names_in( 'Faded Gazette and FadeGaz return', true ) );
		$this->assertNull( $index->by_identifier( 'domain', 'fadedgazette.example' ) );
		$this->assertSame( [], $index->identifiers( 'domain' ) );
		$this->assertSame( [ 'fadedgazette.example' => 'organization:faded-gazette' ], $index->refused( 'domain' ) );
		$this->assertTrue( $index->is_rejected_name( 'faded gazette' ) );
		$this->assertTrue( $index->is_rejected_name( 'fadegaz' ) );
		$this->assertFalse( $index->is_rejected_name( 'harbor gazette' ) );
	}

	/**
	 * Who holds an identifier: a live holder's survivor, else the key of a
	 * rejected or unresolvable holder, else none.
	 */
	public function test_holder_answers_a_live_survivor_else_a_refused_key(): void {
		$index = $this->index_of(
			self::entity( 'organization:quillmouth-herald', 'Quillmouth Herald', [ 'domain' => [ 'quillmouth.example' ] ] ),
			self::rejected( 'organization:quillmouth-herald' ),
			self::entity( 'organization:wren-desk-3381', 'Wren Desk', [ 'domain' => [ 'wrendesk-3381.example' ] ] ),
			self::entity( 'organization:wren-press-3382', 'Wren Press' ),
			self::merged( 'organization:wren-desk-3381', 'organization:wren-press-3382' )
		);

		$this->assertSame( 'organization:wren-press-3382', $index->holder( 'domain', 'WWW.WrenDesk-3381.example' ) );
		$this->assertSame( 'organization:quillmouth-herald', $index->holder( 'domain', 'quillmouth.example' ) );
		$this->assertNull( $index->holder( 'domain', 'nobody-3383.example' ) );
	}

	/** An identifier whose holder is rejected or unresolvable names its holding key; a live one names none. */
	public function test_held_by_refused_names_the_rejected_or_unresolvable_holder(): void {
		$index = $this->index_of(
			self::entity( 'organization:quillmouth-herald', 'Quillmouth Herald', [ 'domain' => [ 'quillmouth.example' ] ] ),
			self::rejected( 'organization:quillmouth-herald' ),
			self::entity( 'organization:ashby-post', 'Ashby Post', [ 'domain' => [ 'ashbypost.example' ] ] ),
			self::entity( 'organization:post-of-ashby', 'Post of Ashby' ),
			self::merged( 'organization:ashby-post', 'organization:post-of-ashby' ),
			self::merged( 'organization:post-of-ashby', 'organization:ashby-post' ),
			self::entity( self::GAZETTE, 'Harbor Gazette', [ 'domain' => [ 'harborgazette.example' ] ] )
		);

		$this->assertSame( 'organization:quillmouth-herald', $index->held_by_refused( 'domain', 'WWW.Quillmouth.example' ) );
		$this->assertSame( 'organization:ashby-post', $index->held_by_refused( 'domain', 'ashbypost.example' ) );
		$this->assertNull( $index->held_by_refused( 'domain', 'harborgazette.example' ) );
		$this->assertNull( $index->held_by_refused( 'domain', 'nobody.example' ) );
	}

	public function test_a_proposed_entity_answers_as_proposed(): void {
		$index = $this->index_of( self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) );

		$this->assertSame( [ [ 'entity_key' => 'organization:salem-ledger', 'kind' => 'canonical', 'surface' => 'Salem Ledger', 'state' => 'proposed', 'at' => 0, 'stoplisted' => false ] ], $index->names_in( 'Salem Ledger', true ) );
	}

	/**
	 * A name or identifier naming a key that is not in any entity row answers
	 * nothing.
	 */
	public function test_a_name_for_an_entity_that_does_not_exist_answers_nothing(): void {
		$index = $this->index_of( self::facts( 'organization:ghost-gazette', [ 'alias' => [ 'Ghost Gazette' ], 'domain' => [ 'ghost.example' ] ] ) );

		$this->assertSame( [], $index->names_in( 'Ghost Gazette', true ) );
		$this->assertNull( $index->by_identifier( 'domain', 'ghost.example' ) );
	}

	/** An identifier answers whatever case it is asked in; a domain without its www. */
	public function test_an_identifier_answers_case_insensitively(): void {
		$index = $this->index_of( self::entity( 'product:example-plugin', 'Example Plugin', [ 'github_repo' => [ 'Example/plugin' ], 'domain' => [ 'www.Harbor.example' ] ] ) );

		$this->assertSame( 'product:example-plugin', $index->by_identifier( 'github_repo', 'example/Plugin' ) );
		$this->assertSame( 'product:example-plugin', $index->by_identifier( 'domain', 'harbor.example' ) );
		$this->assertNull( $index->by_identifier( 'github_org', 'example' ) );
	}

	/** Every matchable name's norm, with the entities it names; localities excluded. */
	public function test_name_norms_lists_every_matchable_name(): void {
		$index = $this->index_of(
			self::entity( self::GAZETTE, 'Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'locality' => [ 'Salem' ] ] ),
			self::entity( 'organization:bend-gazette', 'Harbor Gazette' )
		);

		$this->assertEqualsCanonicalizing(
			[ 'harbor gazette' => [ self::GAZETTE, 'organization:bend-gazette' ], 'harbgaz' => [ self::GAZETTE ] ],
			$index->name_norms()
		);
	}

	/** A name a rejected entity carries is offered to the fuzzy step for entities of other types alone, and is rejected for proposals whatever the type. */
	public function test_name_norms_leaves_out_a_name_its_type_rejects(): void {
		$index = $this->index_of(
			self::entity( 'organization:atomic-5121', 'Atomic site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
			self::entity( 'product:wharfside', 'Wharfside' ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' )
		);

		$this->assertEqualsCanonicalizing( [ 'atomic site 5121' => [ 'organization:atomic-5121' ], 'wharfside' => [ 'product:wharfside' ] ], $index->name_norms() );
		$this->assertTrue( $index->is_rejected_name( 'wharfside' ) );
	}

	/** A hit whose name a rejected entity of its own type carries is marked stoplisted; another type's is not. */
	public function test_names_in_marks_a_hit_its_type_rejects(): void {
		$index = $this->index_of(
			self::entity( 'organization:atomic-5121', 'Atomic site 5121', [ 'canonical' => [ 'Wharfside' ] ] ),
			self::entity( 'product:wharfside', 'Wharfside' ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' )
		);

		$this->assertEqualsCanonicalizing(
			[ [ 'organization:atomic-5121', true ], [ 'product:wharfside', false ] ],
			\array_map( static fn ( array $hit ): array => [ $hit['entity_key'], $hit['stoplisted'] ], $index->names_in( 'Wharfside', true ) )
		);
	}

	/** A rejected entity's locality never enters the stoplist: it blocks neither a proposal nor a link. */
	public function test_a_rejected_entitys_locality_is_not_stoplisted(): void {
		$index = $this->index_of(
			self::proposed( 'organization:tolaga-ledger', 'Tolaga Ledger', [ 'locality' => [ 'Tolaga' ] ] ),
			self::rejected( 'organization:tolaga-ledger' ),
			self::entity( 'organization:tolaga', 'Tolaga' )
		);

		$this->assertFalse( $index->is_rejected_name( 'tolaga' ) );
		$this->assertTrue( $index->is_rejected_name( 'tolaga ledger' ) );
		$this->assertFalse( $index->stoplisted( 'tolaga', 'organization:tolaga' ) );
	}

	/** A name on a key merged into a rejected entity stoplists the survivor's type, not the merged key's. */
	public function test_a_name_merged_into_a_rejected_entity_stoplists_the_survivors_type(): void {
		$index = $this->index_of(
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' ),
			self::entity( 'product:wharf-board', 'Wharf Board' ),
			self::merged( 'product:wharf-board', 'organization:wharfside' ),
			self::entity( 'organization:harbour-trust', 'Harbour Trust', [ 'alias' => [ 'Wharf Board' ] ] ),
			self::entity( 'product:wharf-board-kit', 'Wharf Board' )
		);

		$this->assertTrue( $index->stoplisted( 'wharf board', 'organization:harbour-trust' ) );
		$this->assertFalse( $index->stoplisted( 'wharf board', 'product:wharf-board-kit' ) );
	}

	/** A key the index does not hold is never stoplisted, and asking raises no warning. */
	public function test_stoplisted_answers_false_for_a_key_it_does_not_hold(): void {
		$index = $this->index_of( self::entity( 'organization:wharfside', 'Wharfside' ), self::rejected( 'organization:wharfside' ) );

		$this->assertFalse( $index->stoplisted( 'wharfside', 'organization:atomic-7781' ) );
	}

	/** A topic is offered by its name for extraction and never found in text; a merged or rejected one is not offered. */
	public function test_topics_are_offered_by_name_and_never_matched_in_text(): void {
		$index = $this->index_of(
			self::entity( 'topic:tidal-reporting', 'Tidal reporting', [ 'alias' => [ 'Shoreline coverage' ], 'acronym' => [ 'TR' ] ] ),
			self::entity( 'topic:coastal-beats', 'Coastal beats' ),
			self::merged( 'topic:coastal-beats', 'topic:tidal-reporting' ),
			self::proposed( 'topic:fog-desks', 'Fog desks' ),
			self::proposed( 'topic:mist-desks', 'Mist desks' ),
			self::rejected( 'topic:mist-desks' ),
			self::entity( self::GAZETTE, 'Harbor Gazette' )
		);

		$this->assertSame( [ 'Fog desks' => 'topic:fog-desks', 'Tidal reporting' => 'topic:tidal-reporting' ], $index->topics() );
		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Harbor Gazette on Tidal reporting, Shoreline coverage, TR, Coastal beats and Fog desks', true ), 'entity_key' ) );
		$this->assertSame( [ 'harbor gazette' => [ self::GAZETTE ] ], $index->name_norms() );
	}

	public function test_an_empty_index_matches_nothing(): void {
		$index = new Entity_Index();

		$this->assertSame( [], $index->names_in( 'Harbor Gazette', true ) );
		$this->assertSame( [], $index->identifiers( 'domain' ) );
		$this->assertSame( [], $index->refused( 'domain' ) );
		$this->assertSame( [], $index->name_norms() );
		$this->assertFalse( $index->is_rejected_name( 'harbor gazette' ) );
	}

	public function test_norm_folds_case_punctuation_space_and_a_leading_the(): void {
		$this->assertSame( 'harbor gazette s award', Entity_Index::norm( "  The HARBOR\tGazette's   award! " ) );
		$this->assertSame( 'the', Entity_Index::norm( 'The' ) );
	}

	/** The keys an entity answers for: itself, then the keys merged into it, sorted; an unmerged key alone. */
	public function test_keys_of_answers_a_survivor_and_what_merged_into_it(): void {
		$index = $this->index_of(
			self::entity( 'organization:lantern-weekly', 'Lantern Weekly' ),
			self::entity( 'organization:lantern-daily', 'Lantern Daily' ),
			self::entity( 'organization:lantern-post', 'Lantern Post' ),
			self::merged( 'organization:lantern-weekly', 'organization:lantern-post' ),
			self::merged( 'organization:lantern-daily', 'organization:lantern-post' ),
			self::entity( 'organization:mesa-courier', 'Mesa Courier' )
		);

		$this->assertSame( [ 'organization:lantern-post', 'organization:lantern-daily', 'organization:lantern-weekly' ], $index->keys_of( 'organization:lantern-post' ) );
		$this->assertSame( [ 'organization:mesa-courier' ], $index->keys_of( 'organization:mesa-courier' ) );
	}

}

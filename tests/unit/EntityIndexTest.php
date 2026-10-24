<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
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
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_names_answer_their_entity_with_kind_surface_and_state(): void {
		$index = $this->index_of( self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'locality' => [ 'Port Angeles' ] ] ) );

		$this->assertSame(
			[
				[ 'entity_key' => self::GAZETTE, 'kind' => 'canonical', 'surface' => 'The Harbor Gazette', 'state' => 'accepted', 'at' => 10 ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'canonical', 'surface' => 'Harbor Gazette', 'state' => 'accepted', 'at' => 14 ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'locality', 'surface' => 'Port Angeles', 'state' => 'accepted', 'at' => 32 ],
				[ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'surface' => 'HarbGaz', 'state' => 'accepted', 'at' => 51 ],
			],
			$index->names_in( 'Award for The Harbor Gazette of Port Angeles, says HarbGaz.' )
		);
	}

	/** Every overlapping hit comes back with its byte offset, the longer of two at one word first. */
	public function test_names_in_returns_every_overlapping_hit_at_its_offset(): void {
		$index = $this->index_of( self::entity( 'organization:the-times', 'The Times' ), self::entity( 'organization:the-seattle-times', 'The Seattle Times' ) );

		$this->assertSame(
			[ [ 'organization:the-seattle-times', 'The Seattle Times', 6 ], [ 'organization:the-seattle-times', 'Seattle Times', 10 ], [ 'organization:the-times', 'Times', 18 ] ],
			\array_map( static fn ( array $hit ): array => [ $hit['entity_key'], $hit['surface'], $hit['at'] ], $index->names_in( 'Today The Seattle Times wins a Pulitzer' ) )
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
			\array_map( static fn ( array $hit ): array => [ $hit['entity_key'], $hit['kind'] ], $index->names_in( 'Port Angeles Gazette wins' ) )
		);
	}

	/** A name matches as whole words only, so it never matches inside a longer word. */
	public function test_a_name_matches_whole_words_only(): void {
		$index = $this->index_of( self::entity( self::GAZETTE, 'Harbor Gazette', [ 'alias' => [ 'HarbGaz' ] ] ) );

		$this->assertSame( [], $index->names_in( 'HarbGazette and HarborGazette launch' ) );
	}

	/** A name of six words matches; the walk reaches no further. */
	public function test_a_name_matches_up_to_six_words(): void {
		$six   = 'organization:the-north-coast-public-media-cooperative';
		$seven = 'organization:north-coast-public-media-news-cooperative-trust';
		$index = $this->index_of(
			self::entity( $six, 'North Coast Public Media News Cooperative' ),
			self::entity( $seven, 'North Coast Public Media News Cooperative Trust' )
		);

		$this->assertSame( [ $six ], \array_column( $index->names_in( 'Today North Coast Public Media News Cooperative Trust grew.' ), 'entity_key' ) );
		$this->assertSame( 6, Entity_Index::MAX_NAME_WORDS );
	}

	/** An acronym answers only the case it is written in. */
	public function test_an_acronym_answers_its_exact_case_only(): void {
		$index = $this->index_of( self::entity( 'organization:lion-publishers', 'Local Independent Online News Publishers', [ 'acronym' => [ 'LION' ] ] ) );

		$this->assertSame( [ [ 'entity_key' => 'organization:lion-publishers', 'kind' => 'acronym', 'surface' => 'LION', 'state' => 'accepted', 'at' => 0 ] ], $index->names_in( 'LION summit opens' ) );
		$this->assertSame( [], $index->names_in( 'a lion escapes; Lion King' ) );
	}

	public function test_a_folded_codepoint_keeps_the_surface_it_was_written_in(): void {
		$index = $this->index_of( self::entity( 'organization:istanbul-gazette', 'İstanbul Gazette' ) );

		$this->assertSame( [ 'İstanbul Gazette' ], \array_column( $index->names_in( 'Award: İstanbul Gazette wins' ), 'surface' ) );
	}

	/** A merged entity's names and identifiers answer its survivor's key. */
	public function test_merged_names_and_identifiers_answer_the_survivor(): void {
		$index = $this->index_of(
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::entity( 'organization:gazette-harbor', 'Gazette of the Harbor', [ 'alias' => [ 'GH Daily' ], 'domain' => [ 'ghdaily.example' ] ] ),
			self::merged( 'organization:gazette-harbor', self::GAZETTE )
		);

		$this->assertSame( [ self::GAZETTE, self::GAZETTE ], \array_column( $index->names_in( 'Gazette of the Harbor, or GH Daily' ), 'entity_key' ) );
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

		$this->assertSame( [ 'organization:salem-ledger' ], \array_values( \array_unique( \array_column( $index->names_in( 'Old Salem Ledger' ), 'entity_key' ) ) ) );
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

		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Salem Ledger, Ledger of Salem and Harbor Gazette' ), 'entity_key' ) );
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

		$this->assertSame( [ 'organization:ledger-10' ], \array_column( $index->names_in( 'Ledger Zero' ), 'entity_key' ) );
	}

	/** A chain past ten hops answers no survivor; the rest loads. */
	public function test_a_merge_chain_past_ten_hops_is_dropped(): void {
		$stderr = $this->capture_stderr();
		$events = [ self::entity( 'organization:ledger-0', 'Ledger Zero' ), self::entity( self::GAZETTE, 'Harbor Gazette' ) ];
		for ( $hop = 0; $hop < 11; $hop++ ) {
			$events[] = self::merged( "organization:ledger-{$hop}", 'organization:ledger-' . ( $hop + 1 ) );
		}
		$index = $this->index_of( ...$events );

		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Ledger Zero and Harbor Gazette' ), 'entity_key' ) );
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

	/** A rejected entity matches nothing, and its names stay known as rejected. */
	public function test_a_rejected_entity_is_absent_and_its_names_are_rejected(): void {
		$index = $this->index_of(
			self::proposed( 'organization:faded-gazette', 'Faded Gazette', [ 'alias' => [ 'FadeGaz' ], 'domain' => [ 'fadedgazette.example' ] ] ),
			self::rejected( 'organization:faded-gazette' )
		);

		$this->assertSame( [], $index->names_in( 'Faded Gazette and FadeGaz return' ) );
		$this->assertNull( $index->by_identifier( 'domain', 'fadedgazette.example' ) );
		$this->assertSame( [], $index->identifiers( 'domain' ) );
		$this->assertSame( [ 'fadedgazette.example' => 'organization:faded-gazette' ], $index->refused( 'domain' ) );
		$this->assertTrue( $index->is_rejected_name( 'faded gazette' ) );
		$this->assertTrue( $index->is_rejected_name( 'fadegaz' ) );
		$this->assertFalse( $index->is_rejected_name( 'harbor gazette' ) );
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

		$this->assertSame( [ [ 'entity_key' => 'organization:salem-ledger', 'kind' => 'canonical', 'surface' => 'Salem Ledger', 'state' => 'proposed', 'at' => 0 ] ], $index->names_in( 'Salem Ledger' ) );
	}

	/** A name or identifier naming a key no entity row holds answers nothing. */
	public function test_a_name_for_an_entity_that_does_not_exist_answers_nothing(): void {
		$index = $this->index_of( self::facts( 'organization:ghost-gazette', [ 'alias' => [ 'Ghost Gazette' ], 'domain' => [ 'ghost.example' ] ] ) );

		$this->assertSame( [], $index->names_in( 'Ghost Gazette' ) );
		$this->assertNull( $index->by_identifier( 'domain', 'ghost.example' ) );
	}

	/** An identifier answers whatever case it is asked in; a domain without its www. */
	public function test_an_identifier_answers_case_insensitively(): void {
		$index = $this->index_of( self::entity( 'product:newspack-plugin', 'Newspack Plugin', [ 'github_repo' => [ 'Automattic/newspack-plugin' ], 'domain' => [ 'www.Harbor.example' ] ] ) );

		$this->assertSame( 'product:newspack-plugin', $index->by_identifier( 'github_repo', 'automattic/Newspack-Plugin' ) );
		$this->assertSame( 'product:newspack-plugin', $index->by_identifier( 'domain', 'harbor.example' ) );
		$this->assertNull( $index->by_identifier( 'github_org', 'automattic' ) );
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
		$this->assertSame( [ self::GAZETTE ], \array_column( $index->names_in( 'Harbor Gazette on Tidal reporting, Shoreline coverage, TR, Coastal beats and Fog desks' ), 'entity_key' ) );
		$this->assertSame( [ 'harbor gazette' => [ self::GAZETTE ] ], $index->name_norms() );
	}

	public function test_an_empty_index_matches_nothing(): void {
		$index = new Entity_Index();

		$this->assertSame( [], $index->names_in( 'Harbor Gazette' ) );
		$this->assertSame( [], $index->identifiers( 'domain' ) );
		$this->assertSame( [], $index->refused( 'domain' ) );
		$this->assertSame( [], $index->name_norms() );
		$this->assertFalse( $index->is_rejected_name( 'harbor gazette' ) );
	}

	public function test_norm_folds_case_punctuation_space_and_a_leading_the(): void {
		$this->assertSame( 'harbor gazette s award', Entity_Index::norm( "  The HARBOR\tGazette's   award! " ) );
		$this->assertSame( 'the', Entity_Index::norm( 'The' ) );
	}
}

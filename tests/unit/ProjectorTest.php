<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Projector_Node;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Shell_Node;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topic_Node;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/projects-intel-graph.php';

/**
 * The projector replays both event streams into the intel-graph Row_Table:
 * every op an `ignore` or a `set`, so a replay changes nothing and an entity
 * named before it is created ends as the created row.
 */
final class ProjectorTest extends TestCase {
	use Projects_Intel_Graph;

	private const NOW = 1793000000.25;

	private const ITEM = 'feed:abc-7731';

	private const GAZETTE = 'organization:harbor-gazette';

	private const MACHINE = 'machine:resolve@9.4.1';

	/** An item recorded from another team, which the operator purges. */
	private const PURGED = 'linear:WREN-4410';

	/** When the fixture item was retrieved: 700 days before NOW, inside its retention. */
	private const RETRIEVED = 1732520000;

	private Projector_Node $projector;

	/** The runtime base this test's files live under. */
	private string $base = '';

	protected function setUp(): void {
		parent::setUp();
		$this->base = $this->make_temp_dir( 'intel-projector-' );
		$this->use_base_dir( $this->base );
		Core::$now              = self::NOW;
		Core::$var['partition'] = '0';
		Topology_Registry::reset();
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		$this->projector = $this->projector_graph();
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_an_item_and_its_links_project_to_rows(): void {
		$this->project( [ $this->item(), $this->link( 'link_asserted' ), $this->extraction() ] );

		$day = \intdiv( self::RETRIEVED, 86400 );
		$this->assertSame(
			[ [ 1, self::ITEM, 'feed', 'public', 'https://www.HarborGazette.example/2026/10/award/?utm_source=np&b=2&a=1#top', 'harborgazette.example/2026/10/award?a=1&b=2', 'The Harbor Gazette Wins a State Award!', 'harbor gazette wins a state award', 'The Gazette took first place.', 1792900000, self::RETRIEVED, $day, 1792900000 ] ],
			self::graph_rows( 'items' )
		);
		$this->assertSame(
			[
				[ self::ITEM, self::GAZETTE, 'subject', 'identifier', 1.0, 'asserted', '', self::MACHINE, self::NOW, $day ],
				[ self::ITEM, 'topic:awards', 'topic', 'extract', 1.0, 'asserted', '', 'machine:extract@9.4.1', self::NOW, $day ],
			],
			self::graph_rows( 'links', 'entity_key' )
		);
		$this->assertSame( [ [ 1, self::ITEM, 'Harbor Gazette won the state award.', 'award', 8.0, 6.0, 0.5, null, null, null, null, '', 'machine:extract@9.4.1', $day ] ], self::graph_rows( 'extractions' ) );
		$this->assertSame( [], self::graph_rows( 'entities' ), 'a link writes no entity row; readers join to one' );
		$this->assertSame( [], self::graph_rows( 'entity_states' ) );
		$this->assertSame( [], self::graph_rows( 'events' ), 'items-stream events are not kept in events' );
	}

	/**
	 * An extraction replaces the item's claims and topic links, so one
	 * yielding fewer leaves none of the earlier behind; a resolver's link
	 * stays.
	 */
	public function test_a_reextraction_replaces_the_items_claims_and_topics(): void {
		$claim = static fn ( int $ord, string $object ): array => Intel_Event::make( 'claim_asserted', [ 'item_key' => self::ITEM, 'ord' => $ord, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => $object, 'confidence' => 0.9, 'retrieved_ts' => self::RETRIEVED ], 'machine:extract@9.4.1', '' );
		$this->project(
			[
				$this->item(),
				$this->link( 'link_asserted' ),
				[ 'topics' => [ 'topic:awards', 'topic:local-news' ] ] + $this->extraction(),
				$claim( 0, 'Peabody Award' ),
				$claim( 1, 'State Press Award' ),
				$claim( 2, 'Murrow Award' ),
				[ 'topics' => [ 'topic:local-news' ] ] + $this->extraction(),
				$claim( 0, 'Kestrel Prize' ),
			]
		);

		$this->assertSame( [ [ 0, 'Kestrel Prize' ] ], self::graph_rows( 'claims', 'ord', 'ord, object_text' ) );
		$this->assertSame( [ [ self::GAZETTE, 'subject' ], [ 'topic:local-news', 'topic' ] ], self::graph_rows( 'links', 'entity_key', 'entity_key, role' ) );
	}

	/**
	 * An extraction naming a theme writes the item's one theme row, dated by
	 * its retrieval; a re-extraction replaces it, and one naming none leaves
	 * the item without a theme.
	 */
	public function test_an_extractions_theme_replaces_the_items_theme_row(): void {
		$this->project( [ $this->item(), [ 'theme' => 'site blocked from sending email' ] + $this->extraction() ] );

		$day = \intdiv( self::RETRIEVED, 86400 );
		$this->assertSame( [ [ self::ITEM, 'site blocked from sending email', $day ] ], self::graph_rows( 'themes' ) );

		$this->project( [ [ 'theme' => 'newsletter sender rejected' ] + $this->extraction() ] );

		$this->assertSame( [ [ self::ITEM, 'newsletter sender rejected', $day ] ], self::graph_rows( 'themes' ) );

		$this->project( [ $this->extraction() ] );

		$this->assertSame( [], self::graph_rows( 'themes' ) );
	}

	/**
	 * An extraction's kind check writes the kind checked, the verdict and
	 * when on the item's extraction row; a re-extraction checked of none
	 * leaves the row without one, as a stand-in's is.
	 */
	public function test_an_extractions_kind_check_rides_its_row(): void {
		$check = [ 'kind' => 'launch', 'verdict' => Intel_Event::PASSED, 'ts' => self::RETRIEVED + 615 ];
		$this->project( [ $this->item(), [ 'kind_check' => $check ] + $this->extraction() ] );

		$this->assertSame( [ [ 'award', 'launch', 'passed', self::RETRIEVED + 615 ] ], self::graph_rows( 'extractions', '1', 'kind, checked_kind, kind_verdict, checked_ts' ) );

		$this->project( [ $this->extraction() ] );

		$this->assertSame( [ [ 'award', null, null, null ] ], self::graph_rows( 'extractions', '1', 'kind, checked_kind, kind_verdict, checked_ts' ) );

		$this->project( [ $this->purge( self::ITEM ), $this->item(), $this->stand_in() ] );

		$this->assertSame( [ [ 'other', null, null, null ] ], self::graph_rows( 'extractions', '1', 'kind, checked_kind, kind_verdict, checked_ts' ) );
	}

	/**
	 * An extraction's kind subject and rank reason ride its row; one
	 * carrying neither, as one written before extractions named them,
	 * projects a null kind subject and a rank reason of ''.
	 */
	public function test_an_extractions_kind_subject_and_rank_reason_ride_its_row(): void {
		$this->project( [ $this->item(), [ 'kind_subject' => 'organization:tern-weekly', 'rank_reason' => 'A client is leaving and names price as the reason.' ] + $this->extraction() ] );

		$this->assertSame( [ [ 'organization:tern-weekly', 'A client is leaving and names price as the reason.' ] ], self::graph_rows( 'extractions', '1', 'kind_subject, rank_reason' ) );

		$this->project( [ $this->extraction() ] );

		$this->assertSame( [ [ null, '' ] ], self::graph_rows( 'extractions', '1', 'kind_subject, rank_reason' ) );
	}

	/** A heuristic stand-in names no theme and leaves a call's theme as it was. */
	public function test_a_heuristic_extraction_keeps_a_calls_theme(): void {
		$this->project( [ $this->item(), [ 'theme' => 'bot registrations' ] + $this->extraction(), $this->stand_in() ] );

		$this->assertSame( [ [ self::ITEM, 'bot registrations', \intdiv( self::RETRIEVED, 86400 ) ] ], self::graph_rows( 'themes' ) );
	}

	/**
	 * The entities an extraction was given as asserted replace the item's
	 * earlier set, so the resolver settles a link by what the last extraction
	 * saw.
	 */
	public function test_an_extractions_subjects_replace_the_items_earlier_set(): void {
		$subjects = fn ( string ...$keys ): array => Intel_Event::make( 'subjects_extracted', [ 'item_key' => self::ITEM, 'entity_keys' => $keys, 'retrieved_ts' => self::RETRIEVED ], 'machine:extract@9.4.1', '' );
		$this->project( [ $this->item(), $subjects( self::GAZETTE, 'organization:salem-ledger' ) ] );

		$this->assertSame( [ [ self::GAZETTE ], [ 'organization:salem-ledger' ] ], self::graph_rows( 'extracted_subjects', 'entity_key', 'entity_key' ) );

		$this->project( [ $subjects( self::GAZETTE ) ] );

		$this->assertSame( [ [ self::ITEM, self::GAZETTE, \intdiv( self::RETRIEVED, 86400 ) ] ], self::graph_rows( 'extracted_subjects' ) );
	}

	/**
	 * Each stand-in stage: the heuristic one a failed call writes, and the
	 * gated one a client's own article about someone else is given.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function stand_in_stages(): array {
		return [
			'heuristic' => [ 'extract-heuristic' ],
			'gated'     => [ 'extract-gated' ],
		];
	}

	/**
	 * A stand-in recorded after a call's extraction leaves the call's row,
	 * claims and topics as they were, whatever the projection held when the
	 * extractor chose the stand-in.
	 *
	 * @param string $stage The stand-in's stage.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stand_in_stages' )]
	public function test_a_stand_in_never_replaces_a_calls( string $stage ): void {
		$this->project(
			[
				$this->item(),
				$this->extraction(),
				Intel_Event::make( 'claim_asserted', [ 'item_key' => self::ITEM, 'ord' => 0, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => 'Peabody Award', 'confidence' => 0.9, 'retrieved_ts' => self::RETRIEVED ], 'machine:extract@9.4.1', '' ),
			]
		);
		$this->project( [ $this->stand_in( $stage ) ] );

		$this->assertSame( [ [ 'Harbor Gazette won the state award.', 'machine:extract@9.4.1' ] ], self::graph_rows( 'extractions', '1', 'summary, actor' ) );
		$this->assertSame( [ [ 0, 'Peabody Award' ] ], self::graph_rows( 'claims', 'ord', 'ord, object_text' ) );
		$this->assertSame( [ [ 'topic:awards', 'topic' ] ], self::graph_rows( 'links', 'entity_key', 'entity_key, role' ) );
	}

	/**
	 * An item that has not been extracted by any call records a stand-in,
	 * which a later call replaces.
	 *
	 * @param string $stage The stand-in's stage.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'stand_in_stages' )]
	public function test_a_stand_in_stands_until_a_call_replaces_it( string $stage ): void {
		$this->project( [ $this->item(), $this->stand_in( $stage ) ] );

		$this->assertSame( [ [ 'The Harbor Gazette Wins a State Award!', "machine:{$stage}@9.4.1" ] ], self::graph_rows( 'extractions', '1', 'summary, actor' ) );

		$this->project( [ $this->extraction() ] );

		$this->assertSame( [ [ 'Harbor Gazette won the state award.', 'machine:extract@9.4.1' ] ], self::graph_rows( 'extractions', '1', 'summary, actor' ) );
	}

	/** A retraction deletes the one link row its item, entity and role key, so a role it no longer holds goes and the rest stay. */
	public function test_a_retraction_deletes_its_link_row_alone(): void {
		$mention = [ 'role' => 'mention' ] + $this->link( 'link_asserted' );
		$this->project( [ $this->link( 'link_asserted' ), $mention, $this->link( 'link_proposed', 'organization:salem-ledger' ) ] );

		$this->project( [ Intel_Event::make( 'link_retracted', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'role' => 'mention', 'retrieved_ts' => self::RETRIEVED ], self::MACHINE, '' ) ] );

		$this->assertSame( [ [ self::GAZETTE, 'subject' ], [ 'organization:salem-ledger', 'subject' ] ], self::graph_rows( 'links', 'entity_key, role', 'entity_key, role' ) );
	}

	/** An item is written once: its first retrieval is the provenance kept. */
	public function test_an_item_is_written_once(): void {
		$this->project( [ $this->item(), [ 'title' => 'Harbor Gazette award, updated', 'retrieved_ts' => self::NOW + 3600 ] + $this->item() ] );

		$this->assertSame( [ [ 'The Harbor Gazette Wins a State Award!', self::RETRIEVED ] ], self::graph_rows( 'items', '1', 'title, retrieved_ts' ) );
	}

	/** A link by name and a topic chosen by extraction are two rows for one entity. */
	public function test_a_resolver_link_and_a_topic_link_to_one_entity_both_survive(): void {
		$this->project(
			[
				Intel_Event::make( 'link_asserted', [ 'item_key' => self::ITEM, 'entity_key' => 'topic:ai', 'role' => 'mention', 'method' => 'name', 'confidence' => 1.0, 'retrieved_ts' => self::RETRIEVED ], self::MACHINE, '' ),
				[ 'topics' => [ 'topic:ai' ] ] + $this->extraction(),
			]
		);

		$this->assertSame(
			[ [ 'topic:ai', 'mention', 'name' ], [ 'topic:ai', 'topic', 'extract' ] ],
			self::graph_rows( 'links', 'role', 'entity_key, role, method' )
		);
	}

	/** An item saved into two folders sits in both, and a move takes it out of the folder it leaves alone. */
	public function test_a_move_takes_an_item_out_of_the_folder_it_leaves_alone(): void {
		$copy = [ 'item_key' => self::ITEM, 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'tier' => 'internal' ];

		$this->project(
			[
				$this->ontology( 'collected', [ 'collection_key' => 'awards-longlist' ] + $copy ),
				$this->ontology( 'collected', [ 'collection_key' => 'awards-shortlist' ] + $copy ),
				$this->ontology( 'item_moved', [ 'collection_key' => 'awards-winners', 'item_key' => self::ITEM, 'from' => 'awards-longlist', 'tiers' => [ 'internal' ] ] ),
			]
		);

		$this->assertSame( [ [ 'awards-shortlist', self::ITEM ], [ 'awards-winners', self::ITEM ] ], self::graph_rows( 'collected', 'collection_key', 'collection_key, item_key' ) );
	}

	/**
	 * Each tier's saved copy of an item is a row of its own, its URL and
	 * title in `saved_copies` and its place in `collected`, so a save at one
	 * tier never overwrites another's. A move carries every tier it names
	 * and leaves the rest: each copy keeps its own title and marks, and a
	 * replay rebuilds the same rows.
	 */
	public function test_a_move_carries_each_tier_it_names_and_leaves_the_rest(): void {
		$copy   = static fn ( string $tier, string $title ): array => [ 'collection_key' => 'awards-longlist', 'item_key' => self::ITEM, 'url' => "https://harborgazette.example/{$tier}", 'title' => $title, 'tier' => $tier ];
		$events = [
			$this->ontology( 'collected', $copy( 'restricted', 'Award, held copy' ) ),
			$this->ontology( 'collected', $copy( 'internal', 'Award, open copy' ) ),
			$this->ontology( 'item_used', [ 'collection_key' => 'awards-longlist', 'item_key' => self::ITEM, 'tier' => 'restricted', 'mark' => 'site', 'day' => '2026-10-24' ] ),
			$this->ontology( 'item_used', [ 'collection_key' => 'awards-longlist', 'item_key' => self::ITEM, 'tier' => 'internal', 'mark' => 'linkedin', 'day' => '2026-10-22' ] ),
			$this->ontology( 'item_moved', [ 'collection_key' => 'awards-winners', 'item_key' => self::ITEM, 'from' => 'awards-longlist', 'tiers' => [ 'restricted', 'internal' ] ] ),
		];

		$this->project( $events );
		$this->assertSame( [ [ 'awards-winners', 'internal' ], [ 'awards-winners', 'restricted' ] ], self::graph_rows( 'collected', 'tier', 'collection_key, tier' ) );
		$this->project( [ $this->ontology( 'item_moved', [ 'collection_key' => 'awards-shortlist', 'item_key' => self::ITEM, 'from' => 'awards-winners', 'tiers' => [ 'internal' ] ] ) ] );

		$rows = [
			self::graph_rows( 'collected', 'tier', 'collection_key, tier' ),
			self::graph_rows( 'saved_copies', 'tier', 'tier, url, title' ),
			self::graph_rows( 'item_marks', 'tier', 'tier, mark, day' ),
		];
		$this->assertSame(
			[
				[ [ 'awards-shortlist', 'internal' ], [ 'awards-winners', 'restricted' ] ],
				[ [ 'internal', 'https://harborgazette.example/internal', 'Award, open copy' ], [ 'restricted', 'https://harborgazette.example/restricted', 'Award, held copy' ] ],
				[ [ 'internal', 'linkedin', '2026-10-22' ], [ 'restricted', 'site', '2026-10-24' ] ],
			],
			$rows
		);
		$this->project( $events );
		$this->project( [ $this->ontology( 'item_moved', [ 'collection_key' => 'awards-shortlist', 'item_key' => self::ITEM, 'from' => 'awards-winners', 'tiers' => [ 'internal' ] ] ) ] );
		$this->assertSame(
			$rows,
			[
				self::graph_rows( 'collected', 'tier', 'collection_key, tier' ),
				self::graph_rows( 'saved_copies', 'tier', 'tier, url, title' ),
				self::graph_rows( 'item_marks', 'tier', 'tier, mark, day' ),
			],
			'a replay rebuilds the same rows'
		);
	}

	public function test_replaying_the_same_events_twice_yields_identical_rows(): void {
		$events = $this->every_kind();
		$this->assertEqualsCanonicalizing( \array_keys( Intel_Event::KINDS ), \array_unique( \array_column( $events, 'event' ) ), 'the replay covers every kind' );

		$this->project( $events );
		$first = self::dump();
		$this->project( $events );

		$this->assertSame( $first, self::dump() );
		$this->assertNotSame( [], $first['events'] );
	}

	/**
	 * Review Focus 2: the two streams project to the same rows whichever
	 * arrives first, and replaying either changes nothing. Every table and
	 * every column is compared, autoincrement ids included: each table is
	 * written by one stream, whose order every run keeps.
	 */
	public function test_both_streams_converge_in_any_order(): void {
		$items    = [];
		$ontology = [];
		foreach ( $this->every_kind() as $event ) {
			if ( Intel_Event::ITEMS === Intel_Event::stream_of( $event['event'] ) ) {
				$items[] = $event;
			} else {
				$ontology[] = $event;
			}
		}
		$runs = [
			'items-first'    => [ $items, $ontology ],
			'ontology-first' => [ $ontology, $items ],
			'each-twice'     => [ $ontology, $items, $ontology, $items ],
		];

		$dumps = [];
		foreach ( $runs as $run => $passes ) {
			$projector = $this->projector_graph( "intel-graph-{$run}", "projector-{$run}" );
			foreach ( $passes as $pass ) {
				foreach ( $pass as $event ) {
					self::deliver( $projector, $event );
				}
				$projector->save_state();
			}
			$dumps[ $run ] = self::dump( "intel-graph-{$run}" );
		}

		$this->assertNotSame( [], $dumps['items-first']['links'] );
		$this->assertNotSame( [], $dumps['items-first']['entities'] );
		$this->assertSame( $dumps['items-first'], $dumps['ontology-first'] );
		$this->assertSame( $dumps['items-first'], $dumps['each-twice'] );
	}

	/**
	 * The projector writes an item's time once, on its row: its own date,
	 * else, for an undated item, when it was retrieved; a growth keeps it.
	 */
	public function test_an_items_time_is_written_on_its_row(): void {
		$undated = Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:undated-4417', 'source' => 'feed', 'tier' => 'public', 'url' => 'https://tern.example/undated', 'title' => 'An undated tern count', 'body' => 'No date.', 'item_ts' => 0, 'retrieved_ts' => 1792913333 ], 'machine:source:feed@9.4.1', '9.4.1' );
		$this->project( [ $this->item(), $undated ] );
		$this->project( [ $this->grown( 'The osprey wrack line report, whole.' ) ] );

		$this->assertSame( [ [ self::ITEM, 1792900000 ], [ 'feed:undated-4417', 1792913333 ] ], self::graph_rows( 'items', 'item_key', 'item_key, item_time' ) );
	}

	/** A grown item keeps its row and its id, every field but its body as recorded, and FTS follows the new text. */
	public function test_a_grown_item_replaces_its_body_in_its_own_row(): void {
		$this->project( [ $this->item() ] );
		$before = self::graph_rows( 'items', 'item_key', 'id, item_key, title, retrieved_day' );

		$this->project( [ $this->grown( 'The osprey wrack line report, whole.' ) ] );

		$this->assertSame( $before, self::graph_rows( 'items', 'item_key', 'id, item_key, title, retrieved_day' ) );
		$this->assertSame( [ [ 'The osprey wrack line report, whole.' ] ], self::graph_rows( 'items', 'item_key', 'body' ) );
		$fts = new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ) );
		$this->assertSame( [ $before[0][0] ], \array_column( $fts->query( "SELECT rowid FROM items_fts WHERE items_fts MATCH 'wrack'" )->fetchAll( \PDO::FETCH_NUM ), 0 ) );
		$this->assertSame( [], $fts->query( "SELECT rowid FROM items_fts WHERE items_fts MATCH 'first'" )->fetchAll( \PDO::FETCH_NUM ) );
	}

	/**
	 * Each distinct body line of an item is one `boilerplate` row, by its
	 * source and the hash of the line as prose reads it, lowercased: a footer
	 * three items carry counts three however each spells its markup, spacing
	 * and case, a line one item repeats counts once, and neither the title
	 * nor a line shorter than `Entity_Index::MIN_LINE_CHARS` counts at all.
	 */
	public function test_a_line_counts_once_per_item(): void {
		$footer = "\n\nGenerated with Quillwright Studio";
		$this->project(
			[
				$this->item( 'linear:WREN-5101', "Ferry timetable moved.{$footer}{$footer}\nThanks!" ),
				$this->item( 'linear:WREN-5102', "Dock lights fixed.<br>\n<p>Generated  with <b>Quillwright</b> STUDIO</p>" ),
				$this->item( 'linear:WREN-5103', "Tide gauge replaced.\r\nGenerated with Quillwright Studio\r\n" ),
			]
		);

		$hash  = static fn ( string $line ): string => \substr( \md5( $line ), 0, 16 );
		$day   = \intdiv( self::RETRIEVED, 86400 );
		$count = [];
		foreach ( self::graph_rows( 'boilerplate', 'line_hash, item_key' ) as [ $source, $line_hash, $item_key, $item_day ] ) {
			$this->assertSame( [ 'feed', $day ], [ $source, $item_day ], $item_key );
			$count[ $line_hash ] = ( $count[ $line_hash ] ?? 0 ) + 1;
		}
		\ksort( $count );
		$expected = [
			$hash( 'generated with quillwright studio' ) => 3,
			$hash( 'ferry timetable moved.' )            => 1,
			$hash( 'dock lights fixed.' )                => 1,
			$hash( 'tide gauge replaced.' )              => 1,
		];
		\ksort( $expected );
		$this->assertSame( $expected, $count );
	}

	/**
	 * A body counts at most `Entity_Index::MAX_LINES` distinct lines, its
	 * first half and its last, so a template's opening and a footer both
	 * count however long the body runs between them.
	 */
	public function test_a_long_body_counts_its_first_and_last_lines(): void {
		$line = static fn ( int $n ): string => \sprintf( 'Checklist step %03d: verify', $n );
		$this->project( [ $this->item( self::ITEM, \implode( "\n", \array_map( $line, \range( 1, 300 ) ) ) ) ] );

		$hash   = static fn ( int $n ): string => \substr( \md5( \strtolower( $line( $n ) ) ), 0, 16 );
		$hashes = \array_column( self::graph_rows( 'boilerplate', 'line_hash', 'line_hash' ), 0 );
		$this->assertSame( 200, Entity_Index::MAX_LINES );
		$this->assertCount( 200, $hashes );
		$this->assertEqualsCanonicalizing( \array_map( $hash, [ ...\range( 1, 100 ), ...\range( 201, 300 ) ] ), $hashes );
	}

	/** A grown item counts the lines its longer body adds, and each line it kept once. */
	public function test_a_grown_item_counts_its_new_lines(): void {
		$this->project( [ $this->item(), $this->grown( "The Gazette took first place.\nGenerated with Quillwright Studio" ) ] );

		$this->assertSame(
			[ [ \substr( \md5( 'generated with quillwright studio' ), 0, 16 ) ], [ \substr( \md5( 'the gazette took first place.' ), 0, 16 ) ] ],
			self::graph_rows( 'boilerplate', 'line_hash', 'line_hash' )
		);
	}

	/** A plain repeat of a record, whose body may be cut, leaves a grown body alone. */
	public function test_a_repeated_record_leaves_a_grown_body(): void {
		$this->project( [ $this->item(), $this->grown( 'The osprey wrack line report, whole.' ), $this->item() ] );

		$this->assertSame( [ [ 'The osprey wrack line report, whole.' ] ], self::graph_rows( 'items', 'item_key', 'body' ) );
	}

	/**
	 * A proposed link's evidence is projected onto its row; a proposal
	 * written before proposals carried one, an asserted link and a topic link
	 * an extraction writes project none.
	 */
	public function test_a_proposed_links_evidence_is_projected(): void {
		$this->project(
			[
				[ 'evidence' => 'Plover Wharf Trust bought the Gazette a ferry.' ] + $this->link( 'link_proposed' ),
				$this->link( 'link_proposed', 'organization:salem-ledger' ),
				$this->link( 'link_asserted', 'organization:tern-weekly' ),
				$this->extraction(),
			]
		);

		$this->assertSame(
			[ [ self::GAZETTE, 'Plover Wharf Trust bought the Gazette a ferry.' ], [ 'organization:salem-ledger', '' ], [ 'organization:tern-weekly', '' ], [ 'topic:awards', '' ] ],
			self::graph_rows( 'links', 'entity_key', 'entity_key, evidence' )
		);
	}

	public function test_an_entity_named_before_it_is_created_is_completed_by_its_creation(): void {
		$this->project( [ $this->link( 'link_proposed' ) ] );
		$this->assertSame( [], self::graph_rows( 'entities' ), 'the link names an entity that has no row yet' );

		$this->project( [ $this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ) ] );

		$this->assertSame( [ [ self::GAZETTE, 'organization', 'Harbor Gazette', 'harbor gazette', (int) self::NOW ] ], self::graph_rows( 'entities' ) );
		$this->assertSame( [ [ self::GAZETTE, 'accepted' ] ], self::graph_rows( 'entity_states', '1', 'entity_key, state' ) );
		$this->assertSame( [ [ self::ITEM, self::GAZETTE, 'proposed' ] ], self::graph_rows( 'links', '1', 'item_key, entity_key, state' ) );
	}

	/**
	 * An entity's created name and every name added to it are stored with
	 * their norm, and each norm with every start a word of it opens.
	 */
	public function test_names_store_their_norm_and_its_word_starts(): void {
		$this->project(
			[
				$this->ontology( 'entity_created', [ 'entity_key' => 'organization:quill-ledger', 'type' => 'organization', 'name' => 'The Quill-Ledger Weekly' ] ),
				$this->ontology( 'name_added', [ 'entity_key' => 'organization:quill-ledger', 'kind' => 'alias', 'value' => 'Q.L. Daily' ] ),
			]
		);

		$this->assertSame( [ [ 'organization:quill-ledger', 'quill ledger weekly' ] ], self::graph_rows( 'entities', '1', 'entity_key, norm' ) );
		$this->assertSame(
			[ [ 'daily', 'q l daily' ], [ 'l daily', 'q l daily' ], [ 'ledger weekly', 'quill ledger weekly' ], [ 'q l daily', 'q l daily' ], [ 'quill ledger weekly', 'quill ledger weekly' ], [ 'weekly', 'quill ledger weekly' ] ],
			self::graph_rows( 'name_starts', 'start, norm' )
		);
	}

	/** A person's creation wins over a machine's proposal in either order. */
	public function test_proposed_and_created_converge_in_either_order(): void {
		$early = 'organization:salem-ledger';
		$late  = 'organization:bend-ledger';
		$alone = 'organization:gazette-harbor';
		$this->project(
			[
				$this->proposal( $early, 'salem ledger' ),
				$this->ontology( 'entity_created', [ 'entity_key' => $early, 'type' => 'organization', 'name' => 'Salem Ledger' ] ),
				$this->ontology( 'entity_created', [ 'entity_key' => $late, 'type' => 'organization', 'name' => 'Bend Ledger' ] ),
				$this->proposal( $late, 'bend ledger' ),
				$this->proposal( $alone, 'Gazette Harbor' ),
			]
		);

		$this->assertSame(
			[ [ $late, 'Bend Ledger', 'accepted', 'user:41' ], [ $alone, 'Gazette Harbor', 'proposed', self::MACHINE ], [ $early, 'Salem Ledger', 'accepted', 'user:41' ] ],
			self::graph_rows( 'entities AS e JOIN p0.entity_states AS s USING ( entity_key )', 'entity_key', 'entity_key, e.name, s.state, s.actor' )
		);
	}

	public function test_a_failed_batch_raises_a_stop_and_keeps_the_buffer(): void {
		self::deliver( $this->projector, $this->item() );
		self::deliver( $this->projector, $this->link( 'link_asserted' ) );
		$held = \count( $this->read_private( $this->projector, 'rows' ) );
		$this->break_items();

		$thrown = null;
		try {
			$this->projector->save_state();
		} catch ( Worker_Should_Stop $e ) {
			$thrown = $e;
		}

		$this->assertNotNull( $thrown, 'a refused batch stops the worker' );
		$this->assertFalse( Worker_Should_Stop::is_clean( $thrown ), 'the cursor must replay the batch' );
		$this->assertStringContainsString( 'intel graph write failed', $thrown->getMessage() );
		$this->assertStringContainsString( 'items', (string) $thrown->getPrevious()?->getMessage() );
		$this->assertSame( 3, $held, 'the item, its one body line and the link' );
		$this->assertCount( $held, $this->read_private( $this->projector, 'rows' ), 'the buffer is kept' );

		$this->mend_items();
		$this->assertSame( [], $this->projector->save_state() );
		$this->assertCount( 1, self::graph_rows( 'items' ) );
		$this->assertSame( [], $this->read_private( $this->projector, 'rows' ) );
	}

	public function test_an_ontology_event_is_kept_in_events_with_its_detail(): void {
		$event = $this->ontology( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'harborgazette.example' ] );
		$this->project( [ $event ] );

		$this->assertSame(
			[ [ $event['event_id'], 'identifier_added', 'user:41', self::GAZETTE, '{"entity_key":"organization:harbor-gazette","kind":"domain","value":"harborgazette.example"}', 'clients-v3', self::NOW ] ],
			self::graph_rows( 'events' )
		);
		$this->assertSame( [ [ self::GAZETTE, 'domain', 'harborgazette.example' ] ], self::graph_rows( 'identifiers' ) );
	}

	/** A saved lens is one row holding its filters as JSON, replaced by its key; a removal deletes it. */
	public function test_a_lens_saves_replaces_and_removes_its_row(): void {
		$filters = [ 'kinds' => [ 'praise' ], 'days' => 21 ] + Graph_Query::unfiltered();
		$saved   = $this->ontology( 'lens_saved', [ 'lens_key' => 'harbor-desk', 'name' => 'Harbor desk', 'description' => 'Praise for the harbor.', 'filters' => $filters ] );
		$this->project(
			[
				$this->ontology( 'lens_saved', [ 'lens_key' => 'harbor-desk', 'name' => 'Harbor draft', 'description' => '', 'filters' => Graph_Query::unfiltered() ] ),
				$saved,
				$this->ontology( 'lens_saved', [ 'lens_key' => 'tide-desk', 'name' => 'Tide desk', 'description' => '', 'filters' => Graph_Query::unfiltered() ] ),
				$this->ontology( 'lens_removed', [ 'lens_key' => 'tide-desk' ] ),
			]
		);

		$this->assertSame( [ [ 'harbor-desk', 'Harbor desk', 'Praise for the harbor.', \json_encode( $filters ), 'user:41', self::NOW ] ], self::graph_rows( 'lenses' ) );
		$this->assertSame( [ [ 'lens_removed', 'tide-desk' ], [ 'lens_saved', 'harbor-desk' ], [ 'lens_saved', 'harbor-desk' ], [ 'lens_saved', 'tide-desk' ] ], self::graph_rows( 'events', 'kind, subject', 'kind, subject' ), 'each lens event is kept under its key' );
	}

	public function test_merge_sets_merged_into_and_reject_sets_state(): void {
		$survivor = 'organization:harbor-gazette-co';
		$spurious = 'organization:gazette-harbor';
		$this->project(
			[
				$this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ),
				$this->ontology( 'entity_created', [ 'entity_key' => $survivor, 'type' => 'organization', 'name' => 'Harbor Gazette Co.' ] ),
				$this->proposal( $spurious, 'Gazette Harbor' ),
				$this->ontology( 'entity_merged', [ 'entity_key' => self::GAZETTE, 'into_key' => $survivor ] ),
				$this->ontology( 'entity_rejected', [ 'entity_key' => $spurious ] ),
			]
		);

		$this->assertSame( [ [ self::GAZETTE, $survivor, 'user:41', self::NOW ] ], self::graph_rows( 'entity_merges' ) );
		$this->assertSame( [ [ $spurious, 'rejected' ], [ self::GAZETTE, 'accepted' ], [ $survivor, 'accepted' ] ], self::graph_rows( 'entity_states', 'entity_key', 'entity_key, state' ), 'a merge leaves the state where it was' );
	}

	/**
	 * An exclusion is one kept row per item, holding the URL, title and tier
	 * it was excluded at, who excluded it, when and why, '' for none; an
	 * inclusion deletes it, and each is an event row under the item's key.
	 */
	public function test_an_exclusion_is_one_kept_row_an_inclusion_deletes(): void {
		$wire = 'feed:heron-wire-9';
		$this->project(
			[
				$this->ontology( 'item_excluded', [ 'item_key' => self::ITEM, 'url' => 'https://harborgazette.example/a', 'title' => 'Gazette wins, wire copy', 'tier' => 'internal', 'why' => 'The wire copy of the award story.' ], 'agent:41:Claude desktop' ),
				$this->ontology( 'item_excluded', [ 'item_key' => $wire, 'url' => 'https://heron.example/w', 'title' => 'Heron wire', 'tier' => 'public' ] ),
				$this->ontology( 'item_excluded', [ 'item_key' => self::PURGED, 'url' => 'https://linear.example/WREN-4410', 'title' => 'Wren ticket', 'tier' => 'internal' ] ),
				$this->ontology( 'item_included', [ 'item_key' => self::PURGED ] ),
			]
		);

		$this->assertSame(
			[
				[ self::ITEM, 'internal', 'https://harborgazette.example/a', 'Gazette wins, wire copy', 'The wire copy of the award story.', 'agent:41:Claude desktop', self::NOW ],
				[ $wire, 'public', 'https://heron.example/w', 'Heron wire', '', 'user:41', self::NOW ],
			],
			self::graph_rows( 'excluded_items', 'item_key' )
		);
		$this->assertSame( [ [ 'item_excluded', self::ITEM ], [ 'item_excluded', $wire ], [ 'item_excluded', self::PURGED ], [ 'item_included', self::PURGED ] ], self::graph_rows( 'events', 'kind, subject', 'kind, subject' ) );
	}

	public function test_judgments_are_kept_per_actor_kind(): void {
		$this->project(
			[
				$this->ontology( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ], 'user:41' ),
				$this->ontology( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ], 'agent:41:Claude desktop' ),
			]
		);

		$this->assertSame(
			[ [ 'agent', 'rejected', 'agent:41:Claude desktop' ], [ 'user', 'confirmed', 'user:41' ] ],
			self::graph_rows( 'judgments', 'actor_kind', 'actor_kind, state, actor' )
		);
	}

	public function test_the_buffer_writes_at_its_op_cap(): void {
		$item = $this->item( self::ITEM, 'Won.' );
		for ( $i = 1; $i < Projector_Node::MAX_BUFFERED_OPS; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:cap-{$i}" ] + $item );
		}
		$this->assertSame( [], self::graph_rows( 'items' ), 'below the cap nothing is written' );

		self::deliver( $this->projector, [ 'item_key' => 'feed:cap-last' ] + $item );

		$this->assertCount( Projector_Node::MAX_BUFFERED_OPS, self::graph_rows( 'items' ) );
		$this->assertSame( [], $this->read_private( $this->projector, 'rows' ) );
	}

	public function test_the_buffer_writes_past_its_byte_budget(): void {
		$item  = [ 'body' => \str_repeat( 'g', 65536 ) ] + $this->item();
		$fits  = \intdiv( Projector_Node::MAX_BUFFERED_BYTES, 65536 + 512 );
		$crest = \intdiv( Projector_Node::MAX_BUFFERED_BYTES, 65536 ) + 1;
		for ( $i = 1; $i <= $fits; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:big-{$i}" ] + $item );
		}
		$this->assertSame( [], self::graph_rows( 'items' ), 'below the byte budget nothing is written' );

		for ( $i = $fits + 1; [] === self::graph_rows( 'items' ) && $i <= $crest; $i++ ) {
			self::deliver( $this->projector, [ 'item_key' => "feed:big-{$i}" ] + $item );
		}

		$written = \count( self::graph_rows( 'items' ) );
		$this->assertGreaterThan( $fits, $written );
		$this->assertLessThanOrEqual( $crest, $written, 'the event crossing 1 MiB writes the batch' );
		$this->assertLessThan( Projector_Node::MAX_BUFFERED_OPS, $written );
		$this->assertSame( 1048576, Projector_Node::MAX_BUFFERED_BYTES );
	}

	public function test_the_router_tick_writes_at_the_flush_cadence(): void {
		$at          = self::NOW;
		Core::$clock = static function () use ( &$at ): float {
			return $at;
		};
		$tick   = static function ( int $seconds ) use ( &$at ): void {
			$at       += $seconds;
			Core::$now = $at;
			Core::node( Node_Names::ROUTER )->fire_cb();
		};
		self::deliver( $this->projector, $this->item() );
		$tick( 0 );
		self::deliver( $this->projector, [ 'item_key' => 'feed:abc-7732' ] + $this->item() );
		$tick( Projector_Node::FLUSH_INTERVAL_S - 1 );
		$between = \count( self::graph_rows( 'items' ) );
		$tick( 1 );

		$this->assertSame( 5, Projector_Node::FLUSH_INTERVAL_S );
		$this->assertSame( 1, $between, 'a tick inside the interval writes nothing' );
		$this->assertCount( 2, self::graph_rows( 'items' ), 'the tick at the interval writes the rest' );
	}

	public function test_a_clean_stop_writes_the_buffer(): void {
		self::deliver( $this->projector, [ 'url' => 'urn:harbor-gazette:7731' ] + $this->item() );

		$this->projector->shutdown_sweep();

		$this->assertSame( [ [ 'urn:harbor-gazette:7731', 'urn:harbor-gazette:7731' ] ], self::graph_rows( 'items', '1', 'url, url_canonical' ), 'a URL that does not name a host is its own canonical' );
	}

	public function test_a_write_with_no_graph_named_stops_the_worker(): void {
		$this->projector->set_graph_target( '' );
		self::deliver( $this->projector, $this->item() );

		$this->expectException( Worker_Should_Stop::class );
		$this->projector->save_state();
	}

	public function test_the_graph_target_round_trips_and_draws_its_edge(): void {
		$this->assertStringContainsString( 'projector:config set_graph_target intel-graph', $this->projector->dump_config() );
		$this->assertContains( 'intel-graph', $this->projector->display_targets() );
	}

	/** An unreadable event dead-letters, and its Consumer reads on past it. */
	public function test_an_unreadable_event_is_dead_lettered_and_the_cursor_moves_on(): void {
		$tmp   = $this->base;
		$topic = new Topic_Node();
		$topic->arguments( [ "{$tmp}/intel-items.p{partition}", '1', '1048576' ] );
		foreach ( [ [ 'event' => 'item_recorded', 'item_key' => 'feed:abc-7730' ], $this->item() ] as $value ) {
			$record                   = Message::new_message();
			$record[ Message::TYPE ]  = Message::TM_STRUCT;
			$record[ Message::VALUE ] = $value;
			$topic->fill( $record );
		}
		$topic->flush();
		$consumer = new Consumer_Node();
		$consumer->arguments( [ "{$tmp}/intel-items.p0", "{$tmp}/offsets/p0", "{$tmp}/deadletter/p0" ] );
		$consumer->name( 'intel:items:consumer' );
		$consumer->sink( $this->projector );
		$consumer->add_snapshot_node( 'projector' );

		$this->pump_consumer( $consumer );
		$consumer->checkpoint();

		$this->assertSame( 1, $consumer->list_deadletter( 10 )['total'], 'the event missing its fields is quarantined' );
		$this->assertSame( [ [ self::ITEM ] ], self::graph_rows( 'items', '1', 'item_key' ), 'the event after it projects' );
		$this->assertGreaterThan( 0, $this->read_private( $consumer, 'checkpoint_offset' ), 'the cursor commits past both' );
	}

	public function test_the_project_topology_feeds_both_streams_to_the_graph(): void {
		Core::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Core::$var['partition'] = '0';
		Core::$var['topology']  = 'newspack-intelligence-project';
		$router                 = new Router_Node();
		$router->name( Node_Names::ROUTER );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->name( Node_Names::COMMAND_INTERPRETER );
		$interpreter->sink( $router );
		$shell = new Shell_Node();
		$shell->sink( $interpreter );
		$shell->want_reply( false );
		$shell->fatal_errors( true );
		$shell->eval_script( "include newspack-intelligence-project\n" );

		$projector = Core::node( 'projector' );
		$this->assertInstanceOf( Projector_Node::class, $projector );
		$this->assertSame( 'intel-graph', $this->read_private( $projector, 'graph_target' ) );
		$this->assertInstanceOf( Row_Table_Node::class, Core::node( 'intel-graph' ) );
		foreach ( [ 'intel:items:consumer' => [ 'items', 'projector' ], 'intel:ontology:consumer' => [ 'ontology', 'projector' ], 'intel:watches:consumer' => [ 'watches', 'projector' ] ] as $name => [ $stream, $target ] ) {
			$consumer = Core::node( $name );
			$this->assertInstanceOf( Consumer_Node::class, $consumer, $name );
			$this->assertSame( $target, $consumer->target(), $name );
			$this->assertStringEndsWith( "/logs/intel-{$stream}.p0", $this->read_private( $consumer, 'source_dir' ), $name );
			$this->assertFalse( $this->read_private( $consumer, 'line_mode' ), "{$name} drains in batches" );
			$this->assertSame( [ 'projector' ], $this->read_private( $consumer, 'snapshot_nodes' ), $name );
		}
		$this->assertFalse( $this->read_private( Core::node( 'intel:items:consumer' ), 'multi_writer' ), 'the worker is the items stream\'s one writer' );
		$this->assertTrue( $this->read_private( Core::node( 'intel:ontology:consumer' ), 'multi_writer' ), 'the worker and request-side writers share the ontology stream' );
		$this->assertTrue( $this->read_private( Core::node( 'intel:watches:consumer' ), 'multi_writer' ), 'the watcher and request-side writers share the watches stream' );
		$this->assertNull( Core::node( 'intel:ontology:tee' ), 'the ontology reaches the projector alone' );
	}

	/**
	 * Fill each event, then checkpoint, as the Consumer does.
	 *
	 * @param list<array<string,mixed>> $events The events.
	 */
	private function project( array $events ): void {
		foreach ( $events as $event ) {
			self::deliver( $this->projector, $event );
		}
		$this->projector->save_state();
	}

	/**
	 * The fixture item's record, under its own key or another.
	 *
	 * @param string $key  The item key.
	 * @param string $body Its body.
	 * @return array<string,mixed>
	 */
	private function item( string $key = self::ITEM, string $body = 'The Gazette took first place.' ): array {
		return Intel_Event::make(
			'item_recorded',
			[
				'item_key'     => $key,
				'source'       => 'feed',
				'tier'         => 'public',
				'url'          => 'https://www.HarborGazette.example/2026/10/award/?utm_source=np&b=2&a=1#top',
				'title'        => 'The Harbor Gazette Wins a State Award!',
				'body'         => $body,
				'item_ts'      => 1792900000,
				'retrieved_ts' => self::RETRIEVED,
			],
			'machine:source:feed@9.4.1',
			''
		);
	}

	/**
	 * A machine link from the item to an entity.
	 *
	 * @param string $kind       link_asserted or link_proposed.
	 * @param string $entity_key The entity.
	 * @return array<string,mixed>
	 */
	private function link( string $kind, string $entity_key = self::GAZETTE ): array {
		return Intel_Event::make(
			$kind,
			[
				'item_key'   => self::ITEM,
				'entity_key' => $entity_key,
				'role'       => 'subject',
				'method'       => 'identifier',
				'confidence'   => 1.0,
				'retrieved_ts' => self::RETRIEVED,
			],
			self::MACHINE,
			''
		);
	}

	/** @return array<string,mixed> */
	private function extraction(): array {
		return Intel_Event::make(
			'extraction_recorded',
			[
				'item_key'   => self::ITEM,
				'summary'    => 'Harbor Gazette won the state award.',
				'kind'       => 'award',
				'relevance'  => 8,
				'importance' => 6,
				'sentiment'  => 0.5,
				'topics'       => [ 'topic:awards' ],
				'retrieved_ts' => self::RETRIEVED,
			],
			'machine:extract@9.4.1',
			''
		);
	}

	/**
	 * A stand-in the extractor records in place of a call's extraction.
	 *
	 * @param string $stage Its stage: the heuristic one by default.
	 * @return array<string,mixed>
	 */
	private function stand_in( string $stage = 'extract-heuristic' ): array {
		return Intel_Event::make(
			'extraction_recorded',
			[
				'item_key'     => self::ITEM,
				'summary'      => 'The Harbor Gazette Wins a State Award!',
				'kind'         => 'other',
				'relevance'    => 0,
				'importance'   => 0,
				'sentiment'    => 0.0,
				'topics'       => [],
				'retrieved_ts' => self::RETRIEVED,
			],
			"machine:{$stage}@9.4.1",
			''
		);
	}

	/**
	 * An ontology event by a person.
	 *
	 * @param string              $kind   The kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @param string              $actor  The actor.
	 * @return array<string,mixed>
	 */
	private function ontology( string $kind, array $fields, string $actor = 'user:41' ): array {
		return Intel_Event::make( $kind, $fields, $actor, 'clients-v3' );
	}

	/**
	 * A machine's proposal of an organization.
	 *
	 * @param string $entity_key The entity.
	 * @param string $name       The name it proposes.
	 * @return array<string,mixed>
	 */
	private function proposal( string $entity_key, string $name ): array {
		return Intel_Event::make( 'entity_proposed', [ 'entity_key' => $entity_key, 'type' => 'organization', 'name' => $name, 'from_item_key' => self::ITEM ], self::MACHINE, '' );
	}

	/**
	 * An ask answered and another failed, as the command and the Ask node write them.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function ask_events(): array {
		return [
			Intel_Event::make( 'ask_asked', [ 'ask_key' => '6b1d3f5a7c9e2048', 'owner' => 4113, 'question' => 'Who won awards?' ], 'user:harbor-desk', '' ),
			Intel_Event::make( 'ask_answered', [ 'ask_key' => '6b1d3f5a7c9e2048', 'owner' => 4113, 'found' => 4, 'dropped' => 2, 'sentences' => [ [ 'text' => 'The Gazette won.', 'cites' => [ 1 ] ] ], 'items' => [ self::ITEM ], 'tiers' => [ 'internal' ], 'searches' => [ [ 'kinds' => [ 'award' ], 'days' => 30 ] ] ], 'machine:ask@9.4.1', '' ),
			Intel_Event::make( 'ask_asked', [ 'ask_key' => '8d2f4a6c1e3b5079', 'owner' => 4113, 'question' => 'Who left?' ], 'user:harbor-desk', '' ),
			Intel_Event::make( 'ask_failed', [ 'ask_key' => '8d2f4a6c1e3b5079', 'owner' => 4113, 'reason' => 'the model call failed', 'searches' => [] ], 'machine:ask@9.4.1', '' ),
		];
	}

	/**
	 * An ask's row reads its outcome whichever of the question and the
	 * outcome is projected first, and the question alone reads as asked.
	 */
	public function test_an_asks_outcome_stands_whichever_lands_first(): void {
		[ $asked, $answered, $waiting, $failed ] = self::ask_events();
		$this->project( [ $answered, $asked, $waiting, $failed, $waiting ] );
		$this->project( [ Intel_Event::make( 'ask_asked', [ 'ask_key' => '0a9b8c7d6e5f4132', 'owner' => 4117, 'question' => 'Any launches?' ], 'user:harbor-desk', '' ) ] );

		$this->assertSame(
			[
				[ '0a9b8c7d6e5f4132', 4117, 'asked', '', 0, 0, '[]', '[]', '[]', '[]' ],
				[ '6b1d3f5a7c9e2048', 4113, 'answered', '', 4, 2, '[{"text":"The Gazette won.","cites":[1]}]', '["' . self::ITEM . '"]', '["internal"]', '[{"kinds":["award"],"days":30}]' ],
				[ '8d2f4a6c1e3b5079', 4113, 'failed', 'the model call failed', 0, 0, '[]', '[]', '[]', '[]' ],
			],
			self::graph_rows( 'asks', 'ask_key', 'ask_key, owner, state, reason, found, dropped, sentences, items, tiers, searches' )
		);
	}

	/** @return list<array<string,mixed>> One event of every kind. */
	private function every_kind(): array {
		$machine = static fn ( string $kind, array $fields ): array => Intel_Event::make( $kind, $fields, self::MACHINE, '' );
		return [
			$this->item(),
			$this->extraction(),
			$machine( 'subjects_extracted', [ 'item_key' => self::ITEM, 'entity_keys' => [ self::GAZETTE ], 'retrieved_ts' => self::RETRIEVED ] ),
			$this->link( 'link_asserted' ),
			$this->link( 'link_proposed', 'organization:salem-ledger' ),
			$machine( 'link_retracted', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:gazette-harbor', 'role' => 'mention', 'retrieved_ts' => self::RETRIEVED ] ),
			$machine( 'claim_asserted', [ 'item_key' => self::ITEM, 'ord' => 0, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => 'Peabody Award', 'confidence' => 0.9, 'retrieved_ts' => self::RETRIEVED ] ),
			$machine( 'cluster_joined', [ 'item_key' => self::ITEM, 'cluster_key' => 'cluster:7731', 'retrieved_ts' => self::RETRIEVED ] ),
			$machine( 'cluster_superseded', [ 'cluster_key' => 'cluster:7731', 'canonical_item_key' => self::ITEM, 'previous_item_key' => 'feed:abc-7730', 'rank' => 14.0, 'retrieved_ts' => self::RETRIEVED ] ),
			$this->ontology( 'entity_created', [ 'entity_key' => self::GAZETTE, 'type' => 'organization', 'name' => 'Harbor Gazette' ] ),
			$this->proposal( 'organization:salem-ledger', 'Salem Ledger' ),
			$this->ontology( 'entity_accepted', [ 'entity_key' => 'organization:salem-ledger' ] ),
			$this->ontology( 'entity_rejected', [ 'entity_key' => 'organization:gazette-harbor' ] ),
			$this->ontology( 'entity_merged', [ 'entity_key' => 'organization:gazette-harbor', 'into_key' => self::GAZETTE ] ),
			$this->ontology( 'name_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'alias', 'value' => 'The Gazette' ] ),
			$this->ontology( 'identifier_added', [ 'entity_key' => self::GAZETTE, 'kind' => 'domain', 'value' => 'harborgazette.example' ] ),
			$this->ontology( 'role_asserted', [ 'entity_key' => self::GAZETTE, 'role' => 'client', 'from_ts' => 1790000000 ] ),
			$this->ontology( 'role_ended', [ 'entity_key' => self::GAZETTE, 'role' => 'prospect', 'from_ts' => 1780000000, 'to_ts' => 1790000000 ] ),
			$this->ontology( 'topic_tagged', [ 'entity_key' => self::GAZETTE, 'topic_key' => 'topic:local-news' ] ),
			$this->ontology( 'link_confirmed', [ 'item_key' => self::ITEM, 'entity_key' => self::GAZETTE, 'item_url' => 'https://harborgazette.example/a' ] ),
			$this->ontology( 'link_rejected', [ 'item_key' => self::ITEM, 'entity_key' => 'organization:salem-ledger', 'item_url' => 'https://harborgazette.example/a' ], 'agent:41:Claude desktop' ),
			$this->ontology( 'collection_created', [ 'collection_key' => 'awards-2026', 'name' => 'Awards 2026' ] ),
			$this->ontology( 'collected', [ 'collection_key' => 'awards-2026', 'item_key' => self::ITEM, 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'tier' => 'internal' ] ),
			$this->ontology( 'item_used', [ 'collection_key' => 'awards-2026', 'item_key' => self::ITEM, 'tier' => 'internal', 'mark' => 'site', 'day' => '2026-10-21' ] ),
			$this->ontology( 'collection_created', [ 'collection_key' => 'awards-shortlist', 'name' => 'Awards shortlist' ] ),
			$this->ontology( 'item_moved', [ 'collection_key' => 'awards-shortlist', 'item_key' => self::ITEM, 'from' => 'awards-2026', 'tiers' => [ 'internal' ] ] ),
			$this->ontology( 'lens_saved', [ 'lens_key' => 'board-desk', 'name' => 'Board desk', 'description' => 'For the board.', 'filters' => [ 'kinds' => [ 'funding' ] ] + Graph_Query::unfiltered() ] ),
			$this->ontology( 'lens_saved', [ 'lens_key' => 'old-desk', 'name' => 'Old desk', 'description' => '', 'filters' => Graph_Query::unfiltered() ] ),
			$this->ontology( 'lens_removed', [ 'lens_key' => 'old-desk' ] ),
			$this->ontology( 'theme_merged', [ 'from' => 'kiosk offboarding from platform', 'into' => 'kiosk offboarding' ] ),
			$this->ontology( 'item_excluded', [ 'item_key' => self::ITEM, 'url' => 'https://harborgazette.example/a', 'title' => 'Award', 'tier' => 'public', 'why' => 'Shown twice.' ] ),
			$this->ontology( 'item_excluded', [ 'item_key' => self::PURGED, 'url' => 'https://linear.example/WREN-4410', 'title' => 'Wren ticket', 'tier' => 'internal' ] ),
			$this->ontology( 'item_included', [ 'item_key' => self::PURGED ] ),
			...$this->watch_events(),
			...self::ask_events(),
			$this->grown( 'The Gazette took first place, and the judges said why.' ),
			$this->item( self::PURGED ),
			$this->purge( self::PURGED ),
		];
	}

	/**
	 * The fixture item again with a longer body, as a source grows it.
	 *
	 * @param string $body The body.
	 * @return array<string,mixed>
	 */
	private function grown( string $body ): array {
		return Intel_Event::make( 'item_grown', [ 'body' => $body ] + \array_intersect_key( $this->item(), \array_flip( Intel_Event::KINDS['item_grown'][1] ) ), 'machine:source:linear@9.4.1', '' );
	}

	/**
	 * The operator's purge of an item.
	 *
	 * @param string $key The item key.
	 * @return array<string,mixed>
	 */
	private function purge( string $key ): array {
		return Intel_Event::make( 'item_purged', [ 'item_key' => $key, 'reason' => 'another team\'s issue' ], 'machine:purge@9.4.1', '' );
	}

	/** @return list<array<string,mixed>> A watch saved, saved again and matched, one removed, a match seen and two deliveries. */
	private function watch_events(): array {
		$watcher = static fn ( string $kind, array $fields ): array => Intel_Event::make( $kind, $fields, 'machine:watcher@9.4.1', '' );
		return [
			$this->ontology( 'watch_saved', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'name' => 'Gazette draft', 'target_kind' => 'entity', 'target_key' => self::GAZETTE, 'digest' => false, 'instant' => false, 'inbox' => true ] ),
			$this->ontology( 'watch_saved', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'name' => 'Gazette', 'target_kind' => 'entity', 'target_key' => self::GAZETTE, 'digest' => true, 'instant' => false, 'inbox' => true ] ),
			$this->ontology( 'watch_saved', [ 'watch_key' => 'f0e1d2c3b4a59687', 'owner' => 3307, 'name' => 'Old desk', 'target_kind' => 'lens', 'target_key' => 'board-desk', 'digest' => false, 'instant' => true, 'inbox' => false ] ),
			$this->ontology( 'watch_removed', [ 'watch_key' => 'f0e1d2c3b4a59687', 'owner' => 3307 ] ),
			$watcher( 'watch_matched', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'item_key' => self::ITEM, 'at' => self::RETRIEVED, 'retrieved_ts' => self::RETRIEVED, 'quiet' => true ] ),
			$this->ontology( 'watch_seen', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'item_key' => self::ITEM, 'retrieved_ts' => self::RETRIEVED ] ),
			$watcher( 'watch_delivered', [ 'owner' => 3307, 'mode' => 'instant', 'watch_key' => 'a1b2c3d4e5f60718', 'through' => 1792990000.5 ] ),
			$watcher( 'watch_delivered', [ 'owner' => 3307, 'mode' => 'digest', 'watch_key' => '', 'through' => 1792995000.5 ] ),
			$watcher( 'watch_delivered', [ 'owner' => 3307, 'mode' => 'scan', 'watch_key' => 'a1b2c3d4e5f60718', 'through' => 1792980000.0 ] ),
		];
	}

	/**
	 * A saved watch is one row, replaced by its key and deleted by its
	 * removal, its checkboxes as integers; a match, a seen mark and a
	 * delivery are a row each, a match and a seen mark dated by their item's
	 * retrieval day.
	 */
	public function test_a_watch_and_its_matches_project_to_rows(): void {
		$this->project( $this->watch_events() );

		$day = \intdiv( self::RETRIEVED, Intel_Graph_Row_Schema::DAY_SECONDS );
		$this->assertSame( [ [ 'a1b2c3d4e5f60718', 3307, 'Gazette', 'entity', self::GAZETTE, 1, 0, 1, 'user:41', self::NOW ] ], self::graph_rows( 'watches' ) );
		$this->assertSame( [ [ 'a1b2c3d4e5f60718', 3307, self::ITEM, (float) self::RETRIEVED, self::NOW, 1, $day ] ], self::graph_rows( 'watch_matches' ) );
		$this->assertSame( [ [ 'a1b2c3d4e5f60718', 3307, self::ITEM, self::NOW, $day ] ], self::graph_rows( 'watch_seen' ) );
		$this->assertSame( [ [ 3307, 'digest', '', 1792995000.5, self::NOW ], [ 3307, 'instant', 'a1b2c3d4e5f60718', 1792990000.5, self::NOW ], [ 3307, 'scan', 'a1b2c3d4e5f60718', 1792980000.0, self::NOW ] ], self::graph_rows( 'watch_deliveries', 'mode' ) );
		$this->assertSame( [ [ 'watch_removed', 'f0e1d2c3b4a59687' ], [ 'watch_saved', 'a1b2c3d4e5f60718' ], [ 'watch_saved', 'a1b2c3d4e5f60718' ], [ 'watch_saved', 'f0e1d2c3b4a59687' ] ], self::graph_rows( 'events', 'kind, subject', 'kind, subject' ), 'each watch definition is kept under its key' );
	}

	/**
	 * A purge deletes every row about its item in each table the items
	 * stream writes, a cluster's canonical row naming it among them, and
	 * keeps the item's rows another stream writes and every other item's.
	 */
	public function test_a_purge_deletes_every_row_about_its_item_alone(): void {
		$machine = static fn ( string $kind, array $fields ): array => Intel_Event::make( $kind, [ 'retrieved_ts' => self::RETRIEVED ] + $fields, self::MACHINE, '' );
		$about   = static fn ( string $key ): array => [
			$machine( 'link_asserted', [ 'item_key' => $key, 'entity_key' => self::GAZETTE, 'role' => 'subject', 'method' => 'name', 'confidence' => 1.0 ] ),
			$machine( 'extraction_recorded', [ 'item_key' => $key, 'summary' => "Summary of {$key}", 'kind' => 'award', 'relevance' => 3, 'importance' => 4, 'sentiment' => 0.0, 'topics' => [ 'topic:awards' ], 'theme' => 'state press award' ] ),
			$machine( 'subjects_extracted', [ 'item_key' => $key, 'entity_keys' => [ self::GAZETTE ] ] ),
			$machine( 'claim_asserted', [ 'item_key' => $key, 'ord' => 0, 'subject_key' => self::GAZETTE, 'predicate' => 'won', 'object_key' => '', 'object_text' => 'A prize', 'confidence' => 0.9 ] ),
			$machine( 'cluster_joined', [ 'item_key' => $key, 'cluster_key' => self::PURGED ] ),
		];
		$this->project(
			[
				$this->item( self::PURGED ),
				$this->item(),
				...$about( self::PURGED ),
				...$about( self::ITEM ),
				$machine( 'cluster_superseded', [ 'cluster_key' => self::PURGED, 'canonical_item_key' => self::PURGED, 'previous_item_key' => self::PURGED, 'rank' => 7.0 ] ),
				$machine( 'cluster_superseded', [ 'cluster_key' => 'feed:abc-9001', 'canonical_item_key' => self::ITEM, 'previous_item_key' => 'feed:abc-9001', 'rank' => 9.0 ] ),
				$this->ontology( 'link_confirmed', [ 'item_key' => self::PURGED, 'entity_key' => self::GAZETTE, 'item_url' => 'https://linear.app/heron-example/issue/WREN-4410' ] ),
				$this->ontology( 'collected', [ 'collection_key' => 'awards-2026', 'item_key' => self::PURGED, 'url' => 'https://linear.app/heron-example/issue/WREN-4410', 'title' => 'WREN work', 'tier' => 'internal' ] ),
				Intel_Event::make( 'watch_matched', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'item_key' => self::PURGED, 'at' => self::RETRIEVED, 'retrieved_ts' => self::RETRIEVED, 'quiet' => false ], 'machine:watcher@9.4.1', '' ),
				Intel_Event::make( 'watch_seen', [ 'watch_key' => 'a1b2c3d4e5f60718', 'owner' => 3307, 'item_key' => self::PURGED, 'retrieved_ts' => self::RETRIEVED ], 'user:41', '' ),
			]
		);
		$before = self::dump();

		$this->project( [ $this->purge( self::PURGED ) ] );

		$after = self::dump();
		$kept  = static fn ( array $rows, int $column ): array => \array_values( \array_filter( $rows, static fn ( array $row ): bool => self::PURGED !== $row[ $column ] ) );
		foreach ( [ 'items' => 1, 'extractions' => 1, 'extracted_subjects' => 0, 'themes' => 0, 'links' => 0, 'claims' => 0, 'clusters' => 0, 'canonicals' => 1, 'boilerplate' => 2 ] as $table => $column ) {
			$this->assertNotSame( $kept( $before[ $table ], $column ), $before[ $table ], "{$table} held a row about the purged item" );
			$this->assertSame( $kept( $before[ $table ], $column ), $after[ $table ], $table );
		}
		$this->assertSame( [ [ self::ITEM ] ], self::graph_rows( 'clusters', '1', 'item_key' ), 'a kept member keeps the cluster the purged item founded' );
		foreach ( \array_diff_key( $before, \array_flip( [ 'items', 'extractions', 'extracted_subjects', 'themes', 'links', 'claims', 'clusters', 'canonicals', 'boilerplate' ] ) ) as $table => $rows ) {
			$this->assertSame( $rows, $after[ $table ], "{$table} is another stream's" );
		}
		$this->assertNotSame( [], $after['collected'] );
		$this->assertNotSame( [], $after['watch_matches'] );
		$index = Sqlite_Arm::open_rows( [ 0 => Table_Node::file( 'intel-graph', 0 ) ], Intel_Graph_Row_Schema::version() );
		$this->assertSame( [ [ 2 ] ], $index->rows( "SELECT rowid FROM p0.items_fts WHERE items_fts MATCH 'harbor'", [] ), 'the title index holds the kept item alone' );
		$this->assertSame( [], $index->rows( "SELECT rowid FROM p0.extractions_fts WHERE extractions_fts MATCH 'wren'", [] ), 'the summary index forgets the purged extraction' );
		$this->assertCount( 1, $index->rows( "SELECT rowid FROM p0.extractions_fts WHERE extractions_fts MATCH 'summary'", [] ) );
	}

	/**
	 * A replay applies the record then the purge in stream order and ends
	 * purged; a later recording of the same key, as a source recording it
	 * again writes, records it again.
	 */
	public function test_a_replayed_purge_ends_purged_and_a_later_recording_returns(): void {
		$this->project( [ $this->item( self::PURGED ), $this->purge( self::PURGED ) ] );
		$this->project( [ $this->item( self::PURGED ), $this->purge( self::PURGED ) ] );

		$this->assertSame( [], self::graph_rows( 'items' ) );

		$this->project( [ $this->item( self::PURGED ) ] );

		$this->assertSame( [ [ self::PURGED ] ], self::graph_rows( 'items', '1', 'item_key' ) );
	}

	/**
	 * Every declared table's rows, every column, in key order.
	 *
	 * @param string $graph The Row_Table.
	 * @return array<string,list<list<mixed>>>
	 */
	private static function dump( string $graph = 'intel-graph' ): array {
		$dump = [];
		foreach ( Intel_Graph_Row_Schema::tables() as $table => $spec ) {
			$dump[ $table ] = self::graph_rows( $table, \implode( ', ', $spec['key'] ?? [ '1' ] ), '*', $graph );
		}
		return $dump;
	}

	private function break_items(): void {
		( new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ) ) )->exec( 'ALTER TABLE items RENAME TO items_broken' );
	}

	private function mend_items(): void {
		( new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ) ) )->exec( 'ALTER TABLE items_broken RENAME TO items' );
	}

	/** ends() names each reader's stream end, its last segment and size, by the reader's cursor dir. */
	public function test_ends_name_each_readers_stream_end_by_its_cursor_dir(): void {
		$this->activate_project();
		self::append_items( 3 );

		$ends = Projector_Node::ends();

		$this->assertSame( [ 0, \filesize( "{$this->base}/logs/intel-items.p0/0.log" ) ], $ends[ self::items_cursor( $this->base ) ] );
		$this->assertSame( [ 0, 0 ], $ends[ "{$this->base}/offsets/newspack-intelligence-project.intel-ontology.p0" ], 'an empty stream ends at its start' );
	}

	/**
	 * A reader stands short of its mark until it commits it, and not of
	 * what is appended after; a reader the marks do not name is not
	 * waited for.
	 */
	public function test_short_of_waits_for_the_mark_alone(): void {
		$this->activate_project();
		self::append_items( 2 );
		$ends = Projector_Node::ends();

		$this->assertSame( "{$this->base}/logs/intel-items.p0", Projector_Node::short_of( $ends ) );
		$this->assertNull( Projector_Node::short_of( [] ), 'a reader the marks do not name is not waited for' );

		$this->commit_items();
		self::append_items( 4 );
		Core::$now += 1.0;
		$this->assertNull( Projector_Node::short_of( $ends ), 'what was appended after the mark is not waited for' );
	}

	/** A cursor in a segment past the mark's has reached it, whatever its offset. */
	public function test_a_cursor_in_a_later_segment_has_reached_the_mark(): void {
		$this->activate_project();
		self::append_items( 1, 64 );
		$ends = Projector_Node::ends();
		self::append_items( 3, 64 );
		Core::$now += 1.0;
		$this->assertGreaterThan( 0, Projector_Node::ends()[ self::items_cursor( $this->base ) ][0], 'the stream rotated' );

		$this->commit_items();
		Core::$now += 1.0;

		$this->assertNull( Projector_Node::short_of( $ends ) );
	}

	/**
	 * A stream that now ends before its mark was recreated, so its reader
	 * is judged by whether it has read the stream to its end now.
	 */
	public function test_a_recreated_stream_is_judged_by_its_end_now(): void {
		$this->activate_project();
		self::append_items( 2 );
		$ends = [ self::items_cursor( $this->base ) => [ 9, 4096 ] ];

		$this->assertSame( "{$this->base}/logs/intel-items.p0", Projector_Node::short_of( $ends ) );

		$this->commit_items();
		Core::$now += 1.0;
		$this->assertNull( Projector_Node::short_of( $ends ) );
	}

	/** The readers' lags are read once a tick, so a commit landing within it is read on the next. */
	public function test_the_readers_are_read_once_a_tick(): void {
		$this->activate_project();
		self::append_items( 2 );
		$ends = Projector_Node::ends();
		$this->assertNotNull( Projector_Node::short_of( $ends ) );

		$this->commit_items();
		$this->assertNotNull( Projector_Node::short_of( $ends ), 'the same tick reads what it read' );

		Core::$now += 1.0;
		$this->assertNull( Projector_Node::short_of( $ends ) );
	}

	/** The readers too are read once a tick, so one a topology change removes within it goes on the next. */
	public function test_the_readers_themselves_are_read_once_a_tick(): void {
		$this->activate_project();
		$this->assertArrayHasKey( self::items_cursor( $this->base ), Projector_Node::ends() );

		\update_option( 'newspack_nodes_topologies', [] );
		Config::reset();
		$this->assertArrayHasKey( self::items_cursor( $this->base ), Projector_Node::ends(), 'the same tick reads the readers it read' );

		Core::$now += 1.0;
		$this->assertSame( [], Projector_Node::ends() );
	}

	/** Activate the project topology, whose four Consumers checkpoint the projector. */
	private function activate_project(): void {
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		\update_option( 'newspack_nodes_topologies', [ 'newspack-intelligence-project' ] );
		Config::reset();
		Bootstrap::forget_node_tables();
	}

	/** The project topology's items cursor dir, for partition 0. */
	private static function items_cursor( string $base ): string {
		return "{$base}/offsets/newspack-intelligence-project.intel-items.p0";
	}

	/**
	 * Append item records to partition 0 of the items stream.
	 *
	 * @param int $count        How many.
	 * @param int $segment_size The stream's segment size, in bytes.
	 */
	private static function append_items( int $count, int $segment_size = 1048576 ): void {
		$topic = new Topic_Node();
		$topic->arguments( [ Config::get_base_directory() . '/logs/intel-items.p{partition}', '1', (string) $segment_size ] );
		for ( $i = 0; $i < $count; $i++ ) {
			$record                   = Message::new_message();
			$record[ Message::TYPE ]  = Message::TM_STRUCT;
			$record[ Message::VALUE ] = [ 'event' => 'item_recorded', 'item_key' => "feed:sandpiper-{$i}" ];
			$topic->fill( $record );
			$topic->flush();
		}
	}

	/** Read the items stream to its end and commit the project topology's cursor there. */
	private function commit_items(): void {
		$consumer = new Consumer_Node();
		$consumer->name( 'intel:items:probe-reader' );
		$consumer->sink( new Capture_Sink_Node() );
		$consumer->arguments( [ "{$this->base}/logs/intel-items.p0", self::items_cursor( $this->base ), "{$this->base}/deadletter/newspack-intelligence-project.intel-items.p0" ] );
		$this->pump_consumer( $consumer );
		$consumer->checkpoint( true );
		$consumer->remove_node();
	}
}

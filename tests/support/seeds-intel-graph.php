<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Core;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;

require_once __DIR__ . '/seeds-entity-index.php';

/**
 * The read-verb fixture: a small world of items, extractions, links, claims
 * and entities, every row written through the projector from fixture events.
 * Three items sit in three ISO weeks of October 2026 and two entities share
 * one of them.
 */
trait Seeds_Intel_Graph {
	use Seeds_Entity_Index;

	/** The machine every items-stream event is recorded as. */
	private static string $machine = 'machine:resolve@9.4.1';

	/** 2026-01-01 00:00 UTC, when the Gazette became a client. */
	private static int $jan_01 = 1767225600;

	/** 2026-10-01 12:00 UTC, a Thursday. */
	private static int $oct_01 = 1790856000;

	/** 2026-10-06 12:00 UTC, a Tuesday. */
	private static int $oct_06 = 1791288000;

	/** 2026-10-13 12:00 UTC, a Tuesday. */
	private static int $oct_13 = 1791892800;

	/** 2026-10-20 12:00 UTC, the tick the fixture runs at. */
	private static int $oct_20 = 1792497600;

	/** Start a graph on the project topology at the fixture's tick. */
	private function start_intel_graph(): void {
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
		Core::$now = (float) self::$oct_20;
	}

	/**
	 * The world most tests read: the Gazette, the Ledger and two topics, and
	 * three items linking them.
	 */
	private function seed_world(): void {
		$this->seed(
			self::entity( 'organization:harbor-gazette', 'Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'domain' => [ 'harborgazette.example' ] ] ),
			self::entity( 'organization:salem-ledger', 'Salem Ledger' ),
			self::entity( 'topic:awards', 'Awards' ),
			self::entity( 'topic:ai', 'Artificial Intelligence' ),
			[
				Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:harbor-gazette', 'role' => 'client', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'topic_tagged', [ 'entity_key' => 'organization:harbor-gazette', 'topic_key' => 'topic:awards' ], 'user:41', 'fixture-9' ),
			],
			self::item( 'feed:gazette-award', 'Harbor Gazette wins state award', 'feed', 'public', self::$oct_06, 'The Gazette took first place for investigative work.' ),
			self::extraction( 'feed:gazette-award', 'Judges honored the Gazette newsroom.', 'award', self::$oct_06, [ 'topic:awards' ], 8.5, 6.25 ),
			self::link( 'link_asserted', 'feed:gazette-award', 'organization:harbor-gazette', 'subject', 'identifier', 1.0, self::$oct_06 ),
			self::item( 'github:ledger-launch', 'Salem Ledger launches a newsletter tool', 'github', 'internal', self::$oct_13, 'A new tool for local newsletters.' ),
			self::extraction( 'github:ledger-launch', 'The Ledger shipped a newsletter tool.', 'launch', self::$oct_13, [ 'topic:ai' ], 7.0, 5.5 ),
			self::link( 'link_asserted', 'github:ledger-launch', 'organization:salem-ledger', 'subject', 'name', 1.0, self::$oct_13 ),
			self::item( 'linear:joint-grant', 'Gazette and Ledger share a grant', 'linear', 'internal', self::$oct_01, 'A joint grant for two newsrooms.' ),
			self::extraction( 'linear:joint-grant', 'Two newsrooms won funding together.', 'funding', self::$oct_01, [ 'topic:awards' ], 6.0, 4.75 ),
			self::link( 'link_asserted', 'linear:joint-grant', 'organization:harbor-gazette', 'subject', 'name', 1.0, self::$oct_01 ),
			self::link( 'link_proposed', 'linear:joint-grant', 'organization:salem-ledger', 'mention', 'ner_fuzzy', 0.6, self::$oct_01 )
		);
	}

	/**
	 * A machine's record of an item, retrieved ten minutes after its date.
	 *
	 * @param string $key     The item key.
	 * @param string $title   Its title.
	 * @param string $source  Its source.
	 * @param string $tier    Its tier.
	 * @param int    $item_ts Its date.
	 * @param string $body    Its body.
	 * @return list<array<string,mixed>>
	 */
	private static function item( string $key, string $title, string $source, string $tier, int $item_ts, string $body = '' ): array {
		return [ Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => $source, 'tier' => $tier, 'url' => self::url( $key ), 'title' => $title, 'body' => $body, 'item_ts' => $item_ts, 'retrieved_ts' => $item_ts + 600 ], self::$machine, 'fixture-9' ) ];
	}

	/**
	 * The URL a fixture item is recorded at.
	 *
	 * @param string $key The item key.
	 */
	private static function url( string $key ): string {
		return 'https://news.example/' . \str_replace( ':', '/', $key );
	}

	/**
	 * A machine's extraction of an item.
	 *
	 * @param string       $key        The item key.
	 * @param string       $summary    Its summary.
	 * @param string       $kind       Its kind.
	 * @param int          $item_ts    The item's date.
	 * @param list<string> $topics     Its topic keys.
	 * @param float        $relevance  Its relevance.
	 * @param float        $importance Its importance.
	 * @param string       $theme      Its theme, or '' for none.
	 * @return list<array<string,mixed>>
	 */
	private static function extraction( string $key, string $summary, string $kind, int $item_ts, array $topics, float $relevance = 5.0, float $importance = 5.0, string $theme = '' ): array {
		return [ Intel_Event::make( 'extraction_recorded', [ 'item_key' => $key, 'summary' => $summary, 'kind' => $kind, 'relevance' => $relevance, 'importance' => $importance, 'sentiment' => 0.25, 'topics' => $topics, 'retrieved_ts' => $item_ts + 600 ] + ( '' === $theme ? [] : [ 'theme' => $theme ] ), 'machine:extract@9.4.1', 'fixture-9' ) ];
	}

	/**
	 * A machine's link of an item to an entity.
	 *
	 * @param string $kind       `link_asserted` or `link_proposed`.
	 * @param string $item       The item key.
	 * @param string $entity     The entity key.
	 * @param string $role       `subject` or `mention`.
	 * @param string $method     How it was found.
	 * @param float  $confidence Its confidence.
	 * @param int    $item_ts    The item's date.
	 * @param string $evidence   The sentence it was found in, or '' for an event written before proposals carried one.
	 * @return list<array<string,mixed>>
	 */
	private static function link( string $kind, string $item, string $entity, string $role, string $method, float $confidence, int $item_ts, string $evidence = '' ): array {
		return [ Intel_Event::make( $kind, [ 'item_key' => $item, 'entity_key' => $entity, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'retrieved_ts' => $item_ts + 600 ] + self::evidence( $evidence ), self::$machine, 'fixture-9' ) ];
	}

	/**
	 * A judgment of a link by an actor.
	 *
	 * @param string $kind   `link_confirmed` or `link_rejected`.
	 * @param string $item   The item key.
	 * @param string $entity The entity key.
	 * @param string $actor  Who judged it.
	 * @return list<array<string,mixed>>
	 */
	private static function judged( string $kind, string $item, string $entity, string $actor ): array {
		return [ Intel_Event::make( $kind, [ 'item_key' => $item, 'entity_key' => $entity, 'item_url' => self::url( $item ) ], $actor, 'fixture-9' ) ];
	}

	/**
	 * A machine's claim an item states.
	 *
	 * @param string $item      The item key.
	 * @param int    $ord       Its order in the item.
	 * @param string $subject   The subject entity key.
	 * @param string $predicate The predicate.
	 * @param string $object    The object entity key, or '' for a literal.
	 * @param string $text      The object as the item wrote it.
	 * @param int    $item_ts   The item's date.
	 * @return list<array<string,mixed>>
	 */
	private static function claim( string $item, int $ord, string $subject, string $predicate, string $object, string $text, int $item_ts ): array {
		return [ Intel_Event::make( 'claim_asserted', [ 'item_key' => $item, 'ord' => $ord, 'subject_key' => $subject, 'predicate' => $predicate, 'object_key' => $object, 'object_text' => $text, 'confidence' => 0.95, 'retrieved_ts' => $item_ts + 600 ], 'machine:extract@9.4.1', 'fixture-9' ) ];
	}

	/**
	 * The deduper's placing of copies in one cluster, keyed by the first,
	 * and its naming of the canonical.
	 *
	 * @param string       $canonical The canonical item key.
	 * @param list<string> $copies    Every item in the cluster, the canonical among them.
	 * @param int          $item_ts   The items' date.
	 * @return list<array<string,mixed>>
	 */
	private static function story( string $canonical, array $copies, int $item_ts ): array {
		$events = [];
		foreach ( $copies as $copy ) {
			$events[] = Intel_Event::make( 'cluster_joined', [ 'item_key' => $copy, 'cluster_key' => $copies[0], 'retrieved_ts' => $item_ts + 600 ], 'machine:dedup@9.4.1', 'fixture-9' );
		}
		$events[] = Intel_Event::make( 'cluster_superseded', [ 'cluster_key' => $copies[0], 'canonical_item_key' => $canonical, 'previous_item_key' => $copies[0], 'rank' => 13.5, 'retrieved_ts' => $item_ts + 600 ], 'machine:dedup@9.4.1', 'fixture-9' );
		return $events;
	}

	/**
	 * A machine's proposal of an entity, naming the item it came from.
	 *
	 * @param string $key      The entity key.
	 * @param string $name     Its name.
	 * @param string $item     The item that proposed it.
	 * @param string $evidence The sentence that named it, or '' for an event written before proposals carried one.
	 * @return list<array<string,mixed>>
	 */
	private static function proposal( string $key, string $name, string $item, string $evidence = '' ): array {
		return [ Intel_Event::make( 'entity_proposed', [ 'entity_key' => $key, 'type' => \explode( ':', $key )[0], 'name' => $name, 'from_item_key' => $item ] + self::evidence( $evidence ), self::$machine, 'fixture-9' ) ];
	}

	/**
	 * A proposal's evidence field: none for '', as every proposal written
	 * before proposals carried one.
	 *
	 * @param string $evidence The sentence.
	 * @return array<string,string>
	 */
	private static function evidence( string $evidence ): array {
		return '' === $evidence ? [] : [ 'evidence' => $evidence ];
	}

	/**
	 * Project events into a second partition's file, and read both from
	 * here on, partition 1 the newer.
	 *
	 * @param list<array<string,mixed>> ...$events Events, in order.
	 */
	private function second_partition( array ...$events ): void {
		$other = $this->projector_graph( 'intel-graph-b', 'projector-b' );
		foreach ( \array_merge( ...$events ) as $event ) {
			self::deliver( $other, $event );
		}
		$other->save_state();
		$files                             = [ 0 => Table_Node::file( 'intel-graph', 0 ), 1 => Table_Node::file( 'intel-graph-b', 0 ) ];
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( $files, Intel_Graph_Row_Schema::version() ) );
	}

	/**
	 * Write a restricted item straight into partition 0's projection, as the
	 * phase-1 sources never record one.
	 *
	 * @param string $key   The item key.
	 * @param string $title Its title.
	 */
	private static function write_restricted_item( string $key, string $title ): void {
		$db = new \PDO( 'sqlite:' . Table_Node::file( 'intel-graph', 0 ), null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->prepare( 'INSERT INTO items ( item_key, source, tier, url, url_canonical, title, title_norm, body, item_ts, retrieved_ts, retrieved_day ) VALUES ( ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? )' )
			->execute( [ $key, 'hubspot', 'restricted', self::url( $key ), self::url( $key ), $title, \strtolower( $title ), 'The renewal is at risk.', self::$oct_13, self::$oct_13 + 600, \intdiv( self::$oct_13 + 600, 86400 ) ] );
	}
}

<?php
/**
 * Graph_Watches: the `intelligence` watch verbs, each at `read`, each
 * answering or changing the caller's own watches alone. A watch is one
 * person's standing question over exactly one lens, entity or collection,
 * saved and removed as ontology events under the caller's user id; the
 * matches the watcher appends and the marks a caller makes seen are
 * watches-stream events. A watch another user saved answers exactly as one
 * no one saved, `no watch: <key>`, so no verb tells the two apart. A match
 * reads through the readable items, so one of a tier the caller does not
 * hold now is neither listed, counted nor marked. Every refusal throws
 * before anything is written.
 *
 * The watcher reads here too: every standing watch with how far it has
 * matched and delivered, and the matches each owner is still owed.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Graph from Graph_Query
 * @phpstan-type Standing array{watch_key:string,owner:int,name:string,kind:string,key:string,digest:bool,instant:bool,saved:float,scan:?float,sent:?float}
 * @phpstan-import-type Item from Graph_Query
 * @phpstan-type Owed array{watch_key:string,matched_ts:float,item:Item}
 */
final class Graph_Watches {

	/** The most watches one reader keeps; an edit of one never counts against it. */
	public const MAX_PER_OWNER = 25;

	/** Random bytes a minted watch key spells in hex. */
	private const KEY_BYTES = 8;

	/** The verb args naming a target, one per kind. */
	private const TARGETS = Intel_Event::WATCH_TARGETS;

	/** The verb args naming a delivery box. */
	private const BOXES = [ 'digest', 'instant', 'inbox' ];

	/**
	 * The caller's own watches, by name, each with its unseen matches the
	 * caller may read now, none for a watch that does not ask for the inbox.
	 *
	 * @return array<string,mixed> `{ watches: [ { watch_key, name, target: { kind, key }, digest, instant, inbox, unseen } ], available }`.
	 */
	public static function watches(): array {
		$owner = \get_current_user_id();
		return Graph_Query::answer(
			[ 'watches' => [] ],
			static fn ( Row_Reader $r, array $g ): array => [
				'watches' => \array_map(
					static fn ( array $row ): array => [
						'watch_key' => $row[0],
						'name'      => $row[1],
						'target'    => [ 'kind' => $row[2], 'key' => $row[3] ],
						'digest'    => (bool) $row[4],
						'instant'   => (bool) $row[5],
						'inbox'     => (bool) $row[6],
						'unseen'    => Core::as_int( $row[7] ),
					],
					$r->rows(
						"{$g['with']} SELECT w.watch_key, w.name, w.target_kind, w.target_key, w.digest, w.instant, w.inbox, CASE WHEN w.inbox THEN ( SELECT COUNT(*) FROM " . self::unseen( $r )
						. ' u JOIN ri i ON i.item_key = u.item_key WHERE u.watch_key = w.watch_key ) ELSE 0 END FROM ' . Intel_Graph_Row_Schema::all( $r, 'watches' ) . ' w WHERE w.owner = ? ORDER BY w.name, w.watch_key',
						[ ...$g['binds'], $owner ]
					)
				),
			]
		);
	}

	/**
	 * Save a watch over exactly one target the caller may see, with at
	 * least one delivery ticked: under a key the server mints, or the key
	 * of one of the caller's own watches, which it replaces. A lens key is
	 * slugged as `save_lens` slugs it; an entity is stored as the survivor
	 * `Graph_Query::seen()` answers.
	 *
	 * A new watch is refused once the caller owns MAX_PER_OWNER, counted
	 * from the projection as every verb reads the caller's watches. A save
	 * the projector has not recorded counts only from its next flush, up to
	 * five seconds on, so a burst can pass the cap by the saves inside one
	 * flush window. That is accepted: the cap bounds what one reader's
	 * watches cost each pass, not a boundary of trust, the overshoot is a
	 * reader's own requests in seconds, and holding it exactly would need a
	 * second count of the ontology stream's unprojected tail.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `name`, `lens`, `entity`, `collection`, `digest`, `instant`, `inbox`, `key`.
	 * @return array<string,mixed> `{ ok, event_id, watch_key }`.
	 * @throws \InvalidArgumentException On a blank name, not exactly one target, no delivery, a target the caller cannot see, a key that is not one of the caller's watches, or a new watch past MAX_PER_OWNER.
	 */
	public static function save( array $args ): array {
		$name    = Graph_Curation::bounded( 'name', Core::as_string( $args['name'] ), Graph_Curation::NAME_BYTES );
		$targets = \array_filter( \array_intersect_key( $args, \array_flip( self::TARGETS ) ), static fn ( mixed $value ): bool => null !== $value );
		if ( 1 !== \count( $targets ) ) {
			throw new \InvalidArgumentException( \esc_html( 'a watch follows exactly one of ' . \implode( ', ', \array_slice( self::TARGETS, 0, -1 ) ) . ' or ' . self::TARGETS[ \count( self::TARGETS ) - 1 ] ) );
		}
		$boxes = [];
		foreach ( self::BOXES as $box ) {
			$boxes[ $box ] = true === $args[ $box ];
		}
		if ( ! \in_array( true, $boxes, true ) ) {
			throw new \InvalidArgumentException( 'a watch delivers by digest, instant email or inbox; tick one' );
		}
		$kind   = Core::as_string( \array_key_first( $targets ) );
		$given  = Graph_Curation::bounded( $kind, Core::as_string( $targets[ $kind ] ), Graph_Curation::KEY_BYTES );
		$owner  = \get_current_user_id();
		$key    = null === $args['key'] ? \bin2hex( \random_bytes( self::KEY_BYTES ) ) : Graph_Curation::bounded( 'key', Core::as_string( $args['key'] ), Graph_Curation::KEY_BYTES );
		$target = Graph_Curation::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $args, $kind, $given, $key, $owner ): array {
				if ( null !== $args['key'] ) {
					self::own( $r, $key, $owner );
				} else {
					self::room( $r, $owner );
				}
				return [ self::target( $r, $g, $kind, $given ) ];
			}
		)[0];
		$fields = [
			'watch_key'   => $key,
			'owner'       => $owner,
			'name'        => $name,
			'target_kind' => $kind,
			'target_key'  => $target,
		] + $boxes;
		return Graph_Curation::write( 'watch_saved', $fields ) + [ 'watch_key' => $key ];
	}

	/**
	 * The key a watch target is stored under: a lens's slug, a collection's
	 * key, or the survivor of an entity the caller may see.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The graph the read opened.
	 * @param string     $kind  `lens`, `entity` or `collection`.
	 * @param string     $given The key as given.
	 * @throws \InvalidArgumentException When no such target is, or the caller may not see the entity.
	 */
	private static function target( Row_Reader $r, array $g, string $kind, string $given ): string {
		if ( 'entity' === $kind ) {
			return Graph_Query::seen( $r, $g, $given );
		}
		if ( 'collection' === $kind ) {
			return null === Graph_Query::collection( $r, $given ) ? throw new \InvalidArgumentException( \esc_html( "no collection: {$given}" ) ) : $given;
		}
		$lens = Intel_Event::lens_key( $given );
		if ( [] === $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'lenses' ) . ' l WHERE lens_key = ? LIMIT 1', [ $lens ] ) ) {
			throw new \InvalidArgumentException( \esc_html( "no lens: {$lens}" ) );
		}
		return $lens;
	}

	/**
	 * Refuse a new watch when the owner already keeps MAX_PER_OWNER.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param int        $owner The caller's user id.
	 * @throws \InvalidArgumentException Naming the cap and how to free one.
	 */
	private static function room( Row_Reader $r, int $owner ): void {
		$kept = Core::as_int( $r->rows( 'SELECT COUNT(*) FROM ' . Intel_Graph_Row_Schema::all( $r, 'watches' ) . ' w WHERE owner = ?', [ $owner ] )[0][0] ?? 0 );
		if ( $kept >= self::MAX_PER_OWNER ) {
			throw new \InvalidArgumentException( \esc_html( "you have {$kept} watches, and one reader may keep " . self::MAX_PER_OWNER . ' at most; remove one first' ) );
		}
	}

	/**
	 * Remove one of the caller's own watches.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException When it is not one of the caller's watches.
	 */
	public static function remove( array $args ): array {
		$key   = Graph_Curation::bounded( 'key', Core::as_string( $args['key'] ), Graph_Curation::KEY_BYTES );
		$owner = \get_current_user_id();
		Graph_Curation::on_graph(
			static function ( Row_Reader $r ) use ( $key, $owner ): array {
				self::own( $r, $key, $owner );
				return [];
			}
		);
		return Graph_Curation::write( 'watch_removed', [ 'watch_key' => $key, 'owner' => $owner ] );
	}

	/**
	 * The unseen matches of one of the caller's own watches the caller may
	 * read now, the latest matched first, each as `search` answers it plus
	 * when it matched; none, `{ items: [], total: 0 }`, for a watch that does
	 * not ask for the inbox.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `limit`, `offset`.
	 * @return array<string,mixed> `{ items, total, available }`.
	 * @throws \InvalidArgumentException When it is not one of the caller's watches, or on a bad page.
	 */
	public static function matches( array $args ): array {
		$key   = Graph_Curation::bounded( 'key', Core::as_string( $args['key'] ), Graph_Curation::KEY_BYTES );
		$owner = \get_current_user_id();
		return Graph_Query::answer(
			Graph_Query::NO_ITEMS,
			static function ( Row_Reader $r, array $g ) use ( $key, $owner, $args ): array {
				if ( ! self::own( $r, $key, $owner ) ) {
					return Graph_Query::NO_ITEMS;
				}
				return Graph_Query::listed(
					$r,
					$g,
					[
						'join'   => 'JOIN ( SELECT u.item_key, u.matched_ts FROM ' . self::unseen( $r ) . ' u WHERE u.watch_key = ? ) w ON w.item_key = i.item_key',
						'binds'  => [ $key ],
						'fields' => [ 'matched_ts' => 'w.matched_ts' ],
						'order'  => 'w.matched_ts DESC, i.item_key DESC',
					],
					$args
				);
			}
		);
	}

	/**
	 * Every match no `watch_seen` marks, as a subquery over every partition.
	 *
	 * @param Row_Reader $r The reader.
	 */
	private static function unseen( Row_Reader $r ): string {
		return '( SELECT m.* FROM ' . Intel_Graph_Row_Schema::all( $r, 'watch_matches' ) . ' m WHERE NOT EXISTS ( SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'watch_seen' )
			. ' s WHERE s.watch_key = m.watch_key AND s.item_key = m.item_key ) )';
	}

	/**
	 * Mark seen the named matches of one of the caller's own watches, or
	 * with none named every unseen one the caller may read now: one
	 * `watch_seen` each, a match already seen not again.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `item`.
	 * @return array<string,mixed> `{ ok, count }`.
	 * @throws \InvalidArgumentException When it is not one of the caller's watches, or a named item is not a match of it the caller may read.
	 */
	public static function mark_seen( array $args ): array {
		$key   = Graph_Curation::bounded( 'key', Core::as_string( $args['key'] ), Graph_Curation::KEY_BYTES );
		$named = \array_values( \array_unique( \array_map( static fn ( mixed $item ): string => Graph_Curation::bounded( 'item', Core::as_string( $item ), Graph_Curation::ITEM_KEY_BYTES ), Core::arr( $args['item'] ) ) ) );
		$owner = \get_current_user_id();
		$owed  = Graph_Curation::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key, $named, $owner ): array {
				self::own( $r, $key, $owner );
				$only    = [] === $named ? '' : ' AND m.item_key IN ( ' . Intel_Graph_Row_Schema::marks( $named ) . ' )';
				$matched = [];
				foreach ( $r->rows( "{$g['with']} SELECT m.item_key, i.retrieved_ts, EXISTS ( SELECT 1 FROM " . Intel_Graph_Row_Schema::all( $r, 'watch_seen' ) . ' s WHERE s.watch_key = m.watch_key AND s.item_key = m.item_key ) FROM '
					. Intel_Graph_Row_Schema::all( $r, 'watch_matches' ) . " m JOIN ri i ON i.item_key = m.item_key WHERE m.watch_key = ?{$only}", [ ...$g['binds'], $key, ...$named ] ) as [ $item, $retrieved, $seen ] ) {
					$matched[ Core::as_string( $item ) ] = $seen ? null : Core::as_int( $retrieved );
				}
				foreach ( $named as $item ) {
					if ( ! \array_key_exists( $item, $matched ) ) {
						throw new \InvalidArgumentException( \esc_html( "no match: {$item}" ) );
					}
				}
				return \array_filter( $matched, static fn ( ?int $retrieved ): bool => null !== $retrieved );
			}
		);
		$actor  = Intel_Event::caller();
		$events = [];
		foreach ( $owed as $item => $retrieved ) {
			$events[] = Intel_Event::make( 'watch_seen', [ 'watch_key' => $key, 'owner' => $owner, 'item_key' => Core::as_string( $item ), 'retrieved_ts' => $retrieved ], $actor, \NEWSPACK_INTELLIGENCE_VERSION );
		}
		if ( [] !== $events ) {
			Stream_Writer::append( $events );
		}
		return [ 'ok' => true, 'count' => \count( $events ) ];
	}

	/**
	 * Whether one of the owner's own watches asks for the inbox, refusing a
	 * key that is not one of them.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param string     $key   The watch key.
	 * @param int        $owner The caller's user id.
	 * @throws \InvalidArgumentException As `no watch: <key>`, whoever else may own it.
	 */
	private static function own( Row_Reader $r, string $key, int $owner ): bool {
		$inbox = $r->rows( 'SELECT inbox FROM ' . Intel_Graph_Row_Schema::all( $r, 'watches' ) . ' w WHERE watch_key = ? AND owner = ? LIMIT 1', [ $key, $owner ] )[0][0] ?? null;
		if ( null === $inbox ) {
			throw new \InvalidArgumentException( \esc_html( "no watch: {$key}" ) );
		}
		return (bool) $inbox;
	}

	/**
	 * The matches the watcher recorded after a moment for each watch named,
	 * none quiet, of items the current caller may read now, the oldest
	 * recorded first, on a read already open.
	 *
	 * @api Read by the watcher, as each watch's owner.
	 * @param Row_Reader                    $r    The reader.
	 * @param Graph                         $g    The owner's graph.
	 * @param list<array{0:string,1:float}> $cuts Each watch key and the moment its owed matches follow.
	 * @return list<Owed>
	 */
	public static function owed( Row_Reader $r, array $g, array $cuts ): array {
		if ( [] === $cuts ) {
			return [];
		}
		$after = [];
		$binds = [];
		foreach ( $cuts as [ $key, $cut ] ) {
			$after[] = '( m.watch_key = ? AND m.matched_ts > ? )';
			\array_push( $binds, $key, $cut );
		}
		return \array_map(
			static fn ( array $row ): array => self::matched( Core::as_string( $row[6] ), Graph_Query::item_row( $row ), Core::as_float( $row[7] ) ),
			$r->rows(
				"{$g['with']} SELECT " . Graph_Query::ITEM_ROW . ', m.watch_key, m.matched_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'watch_matches' )
				. ' m JOIN ri i ON i.item_key = m.item_key WHERE 0 = m.quiet AND ( ' . \implode( ' OR ', $after ) . ' ) ORDER BY m.matched_ts, m.item_key',
				[ ...$g['binds'], ...$binds ]
			)
		);
	}

	/**
	 * A watch's match of an item: the one shape every match an email lists
	 * takes, recorded or this pass's.
	 *
	 * @api The watcher builds this pass's matches by it.
	 * @param string $watch      The watch key.
	 * @param Item   $item       The item.
	 * @param float  $matched_ts When it was matched.
	 * @return Owed
	 */
	public static function matched( string $watch, array $item, float $matched_ts ): array {
		return [ 'watch_key' => $watch, 'matched_ts' => $matched_ts, 'item' => $item ];
	}

	/**
	 * Every standing watch, by owner, each with when it was saved, its
	 * high-water mark and how far its instant email reached, each null where
	 * the watcher has written none since that saving, so a save starts a
	 * watch afresh; and each owner's last digest.
	 *
	 * @api Read by the watcher at each pass.
	 * @return array{owners:array<int,list<Standing>>,digests:array<int,float>}|null Null when no projection opens.
	 */
	public static function standing(): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ): array {
				$last    = static fn ( string $mode ): string => '( SELECT MAX( d.through ) FROM ' . Intel_Graph_Row_Schema::all( $r, 'watch_deliveries' ) . " d WHERE d.mode = '{$mode}' AND d.watch_key = w.watch_key AND d.ts >= w.ts )";
				$owners  = [];
				foreach ( $r->rows( 'SELECT w.watch_key, w.owner, w.name, w.target_kind, w.target_key, w.digest, w.instant, w.ts, ' . $last( 'scan' ) . ', ' . $last( 'instant' ) . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'watches' ) . ' w ORDER BY w.owner, w.name, w.watch_key', [] ) as $row ) {
					$owner              = Core::as_int( $row[1] );
					$owners[ $owner ][] = [
						'watch_key' => Core::as_string( $row[0] ),
						'owner'     => $owner,
						'name'      => Core::as_string( $row[2] ),
						'kind'      => Core::as_string( $row[3] ),
						'key'       => Core::as_string( $row[4] ),
						'digest'    => (bool) $row[5],
						'instant'   => (bool) $row[6],
						'saved'     => Core::as_float( $row[7] ),
						'scan'      => null === $row[8] ? null : Core::as_float( $row[8] ),
						'sent'      => null === $row[9] ? null : Core::as_float( $row[9] ),
					];
				}
				$digests = [];
				foreach ( $r->rows( 'SELECT owner, MAX( through ) FROM ' . Intel_Graph_Row_Schema::all( $r, 'watch_deliveries' ) . " d WHERE mode = 'digest' GROUP BY owner", [] ) as [ $owner, $through ] ) {
					$digests[ Core::as_int( $owner ) ] = Core::as_float( $through );
				}
				return [ 'owners' => $owners, 'digests' => $digests ];
			},
			'watcher: no intel-graph to read the watches from; waiting: '
		);
	}
}

<?php
/**
 * Graph_Curation: the `intelligence` curation verbs. Each writes its
 * judgment as one ontology event through `Ontology_Writer::append()`, under
 * `Intel_Event::caller()` — the agent a labelled session names, else the
 * person — and answers `{ ok, event_id }`. A refusal throws before anything
 * is written.
 *
 * Every string an event carries is bounded first, in bytes as the stream
 * writes it, JSON-escaped: a key, a name or a URL past its cap is refused,
 * and a collected title is cut. The caps hold every event inside one atomic
 * partition line whatever characters fill them. Each verb then reads the
 * projection once, on the graph `Graph_Query::graph()` opens: every entity
 * key it names answers as the survivor `Graph_Query::seen()` lets the caller
 * see, and an item is read through the readable items, so one outside them
 * is refused exactly as a missing one.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Graph from Graph_Query
 */
final class Graph_Curation {

	/** The longest entity or collection key, in written bytes. */
	public const KEY_BYTES = 320;

	/** The longest item key, in written bytes. */
	public const ITEM_KEY_BYTES = 512;

	/** The longest name, name value or identifier value, in written bytes. */
	public const NAME_BYTES = 256;

	/** The longest URL an event carries, in written bytes. */
	public const URL_BYTES = 1024;

	/** The bytes a collected title is cut to. */
	public const TITLE_BYTES = 512;

	/** The states an entity holds an identifier in. */
	private const HOLDING_STATES = [ 'accepted', 'proposed' ];

	/**
	 * Judge the link a readable item has to an entity, by any role and under
	 * any of the entity's keys: `link_confirmed` or `link_rejected`, carrying
	 * the item's URL.
	 *
	 * @param string                 $kind `link_confirmed` or `link_rejected`.
	 * @param array<array-key,mixed> $args The bound verb args: `item`, `entity`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad arg, an entity the caller cannot see, an item it cannot read, or no link between them.
	 */
	public static function judge_link( string $kind, array $args ): array {
		$entity_key        = self::key( $args, 'entity' );
		$item_key          = self::item_key( $args );
		[ $entity, $item ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $entity_key, $item_key ): array {
				$entity = Graph_Query::seen( $r, $g, $entity_key );
				$item   = self::item( $r, $g, $item_key );
				$keys   = Graph_Query::keys_of( $entity, $g );
				if ( [] === $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l WHERE item_key = ? AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) LIMIT 1', [ $item_key, ...$keys ] ) ) {
					throw new \InvalidArgumentException( \esc_html( "no link: {$item_key} {$entity}" ) );
				}
				return [ $entity, $item ];
			}
		);
		return self::write( $kind, [ 'item_key' => $item_key, 'entity_key' => $entity, 'item_url' => $item['url'] ] );
	}

	/**
	 * Judge an entity named by `key`: `entity_accepted` or `entity_rejected`.
	 * An accept is refused for an entity a caller without restricted access
	 * cannot see, since accepting it would show it to every reader.
	 *
	 * @param string                 $kind `entity_accepted` or `entity_rejected`.
	 * @param array<array-key,mixed> $args The bound verb args: `key`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad key, one the caller cannot see, or an accept that would declassify it.
	 */
	public static function judge_entity( string $kind, array $args ): array {
		$key    = self::key( $args, 'key' );
		$entity = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $kind, $key ): array {
				$entity = Graph_Query::seen( $r, $g, $key );
				if ( 'entity_accepted' === $kind && ! self::open( $r, $g, $entity ) ) {
					throw new \InvalidArgumentException( \esc_html( "{$entity} is cited by no item outside the restricted tier; accepting it would show it to every reader" ) );
				}
				return [ $entity ];
			}
		)[0];
		return self::write( $kind, [ 'entity_key' => $entity ] );
	}

	/**
	 * Add a name to an entity, its value bounded at NAME_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `kind`, `value`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad arg, an entity the caller cannot see, or a kind outside NAME_KINDS.
	 */
	public static function add_name( array $args ): array {
		$value = self::bounded( 'value', Core::as_string( $args['value'] ), self::NAME_BYTES );
		return self::write( 'name_added', [ 'entity_key' => self::entity( $args, 'key' ), 'kind' => Core::as_string( $args['kind'] ), 'value' => $value ] );
	}

	/**
	 * The survivor an entity key arg answers as, when the caller may see the
	 * entity it names.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @param string                 $name The arg.
	 * @throws \InvalidArgumentException On a bad key, or `no entity` for one the caller cannot see.
	 */
	private static function entity( array $args, string $name ): string {
		$key = self::key( $args, $name );
		return self::on_graph( static fn ( Row_Reader $r, array $g ): array => [ Graph_Query::seen( $r, $g, $key ) ] )[0];
	}

	/**
	 * Tag an entity with a topic, an entity of type `topic`, unless the
	 * entity is one every reader sees and the topic is not, which the tag
	 * would show on the entity's record.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `topic`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad key, one the caller cannot see, a topic that is not one, or a tag across the restricted line.
	 */
	public static function tag_topic( array $args ): array {
		$key                 = self::key( $args, 'key' );
		$topic               = self::key( $args, 'topic' );
		[ $entity, $tagged ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key, $topic ): array {
				$tagged = Graph_Query::seen( $r, $g, $topic );
				if ( 'topic' !== self::type_of( $r, $tagged ) ) {
					throw new \InvalidArgumentException( \esc_html( "{$tagged} is not a topic" ) );
				}
				$entity = Graph_Query::seen( $r, $g, $key );
				if ( self::open( $r, $g, $entity ) && ! self::open( $r, $g, $tagged ) ) {
					throw new \InvalidArgumentException( \esc_html( "tagging {$entity} with {$tagged} would show {$tagged} to every reader; no item outside the restricted tier cites it" ) );
				}
				return [ $entity, $tagged ];
			}
		);
		return self::write( 'topic_tagged', [ 'entity_key' => $entity, 'topic_key' => $tagged ] );
	}

	/**
	 * Merge the survivor one key answers as into another's of the same type,
	 * unless exactly one of the two is one a caller without restricted access
	 * sees, which the merge would show to every reader, or the merge would
	 * leave any key on a chain that cycles or runs past
	 * `Entity_Index::MAX_MERGE_HOPS` that resolved before it.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `from`, `into`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a merge into itself or across types, one naming an entity the caller cannot see, one across the restricted line, or one that breaks a chain, naming its first key.
	 */
	public static function merge( array $args ): array {
		$from_key = self::key( $args, 'from' );
		$into_key = self::key( $args, 'into' );
		[ $from, $into, $types, $open, $merges, $before ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $from_key, $into_key ): array {
				$from = Graph_Query::seen( $r, $g, $from_key );
				$into = Graph_Query::seen( $r, $g, $into_key );
				return [ $from, $into, [ self::type_of( $r, $from ), self::type_of( $r, $into ) ], [ self::open( $r, $g, $from ), self::open( $r, $g, $into ) ], Entity_Index::merges( $r ), $g['survivors'] ];
			}
		);
		if ( $from === $into ) {
			throw new \InvalidArgumentException( \esc_html( "cannot merge {$from} into itself" ) );
		}
		if ( $types[0] !== $types[1] ) {
			throw new \InvalidArgumentException( \esc_html( "cannot merge {$from} ({$types[0]}) into {$into} ({$types[1]})" ) );
		}
		if ( $open[0] !== $open[1] ) {
			$hidden = $open[0] ? $into : $from;
			throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would show {$hidden} to every reader; no item outside the restricted tier cites it" ) );
		}
		foreach ( Entity_Index::walk( [ $from => $into ] + $merges )[0] as $key => $survivor ) {
			if ( '' === $survivor && '' !== ( $before[ $key ] ?? $key ) ) {
				throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would leave {$key} on a chain that cycles or runs past " . Entity_Index::MAX_MERGE_HOPS . ' hops' ) );
			}
		}
		return self::write( 'entity_merged', [ 'entity_key' => $from, 'into_key' => $into ] );
	}

	/**
	 * Whether a caller without restricted access sees a survivor: accepted,
	 * or cited by an item of `Intel_Tiers::UNRESTRICTED` (`Graph_Query::visible()`).
	 *
	 * @param Row_Reader $r        The reader.
	 * @param Graph      $g        The graph the read opened.
	 * @param string     $survivor The survivor.
	 */
	private static function open( Row_Reader $r, array $g, string $survivor ): bool {
		return null !== Graph_Query::visible( $r, Graph_Query::unrestricted( $r, $g ), $survivor );
	}

	/**
	 * An entity's type: its `entities` row's, else its key's own `<type>:`.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The entity key.
	 */
	private static function type_of( Row_Reader $r, string $key ): string {
		$type = $r->rows( 'SELECT type FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' e WHERE entity_key = ? LIMIT 1', [ $key ] )[0][0] ?? null;
		return null === $type ? \explode( ':', $key, 2 )[0] : Core::as_string( $type );
	}

	/**
	 * Add an identifier to an entity, spelled as `Entity_Index` compares it,
	 * unless another entity holds it: the survivor of the key the
	 * identifiers row names, accepted or proposed, or that key itself when
	 * its survivor is rejected or its merge chain does not resolve.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `kind`, `value`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Naming the entity that holds it, or on a bad arg or an entity the caller cannot see.
	 */
	public static function add_identifier( array $args ): array {
		$key               = self::key( $args, 'key' );
		$kind              = Core::as_string( $args['kind'] );
		$value             = self::bounded( 'value', Entity_Index::identifier_value( $kind, Core::as_string( $args['value'] ) ), self::NAME_BYTES );
		[ $own, $held_by ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key, $kind, $value ): array {
				$own    = Graph_Query::seen( $r, $g, $key );
				$holder = $r->rows( 'SELECT entity_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'identifiers' ) . ' i WHERE kind = ? AND value = ? LIMIT 1', [ $kind, $value ] )[0][0] ?? null;
				if ( null === $holder ) {
					return [ $own, null ];
				}
				$holder   = Core::as_string( $holder );
				$survivor = $g['survivors'][ $holder ] ?? $holder;
				$state    = '' === $survivor || $own === $survivor ? null : Graph_Query::state_of( $r, $survivor );
				return [
					$own,
					match ( true ) {
						'' === $survivor, 'rejected' === $state => $holder,
						\in_array( $state, self::HOLDING_STATES, true ) => $survivor,
						default => null,
					},
				];
			}
		);
		if ( null !== $held_by ) {
			throw new \InvalidArgumentException( \esc_html( "{$kind}:{$value} is held by {$held_by}" ) );
		}
		return self::write( 'identifier_added', [ 'entity_key' => $own, 'kind' => $kind, 'value' => $value ] );
	}

	/**
	 * Open a role on an entity from now, unless a key it answers for already
	 * holds it open, or holds a row of it from this second, which a row
	 * keyed by the same second would overwrite.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `role`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the role is open or has a row from this second, or on a bad key or one the caller cannot see.
	 */
	public static function assert_role( array $args ): array {
		[ $key, $survivor, $role, $open, $this_second ] = self::open_role( $args );
		if ( null !== $open ) {
			throw new \InvalidArgumentException( \esc_html( "{$key} already holds {$role}" ) );
		}
		if ( $this_second ) {
			throw new \InvalidArgumentException( \esc_html( "{$key} already has a {$role} row from this second; try again in a second" ) );
		}
		return self::write( 'role_asserted', [ 'entity_key' => $survivor, 'role' => $role, 'from_ts' => Intel_Event::now() ] );
	}

	/**
	 * Close, now, the latest open row of a role a key the entity answers for
	 * holds, on the key that holds it.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `role`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When no such role is open, or on a bad key or one the caller cannot see.
	 */
	public static function end_role( array $args ): array {
		[ $key, , $role, $open ] = self::open_role( $args );
		if ( null === $open ) {
			throw new \InvalidArgumentException( \esc_html( "{$key} holds no open {$role} role" ) );
		}
		return self::write( 'role_ended', [ 'entity_key' => $open[0], 'role' => $role, 'from_ts' => $open[1], 'to_ts' => Intel_Event::now() ] );
	}

	/**
	 * The key and role args, the survivor the key answers as, the latest
	 * open row of that role held under any of the survivor's keys, as
	 * `[ held key, from_ts ]`, and whether any row of it there opened at
	 * `Intel_Event::now()`.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `key`, `role`.
	 * @return array{0:string,1:string,2:string,3:array{0:string,1:int}|null,4:bool}
	 * @throws \InvalidArgumentException On a bad key, or one the caller cannot see.
	 */
	private static function open_role( array $args ): array {
		$key                               = self::key( $args, 'key' );
		$role                              = Core::as_string( $args['role'] );
		[ $survivor, $open, $this_second ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key, $role ): array {
				$survivor = Graph_Query::seen( $r, $g, $key );
				$keys     = Graph_Query::keys_of( $survivor, $g );
				$now      = $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . ' t WHERE role = ? AND from_ts = ? AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) LIMIT 1', [ $role, Intel_Event::now(), ...$keys ] );
				return [ $survivor, Graph_Query::open_roles( $r, [ $survivor => $keys ], [ $role ] )[ $survivor ][ $role ][0] ?? null, [] !== $now ];
			}
		);
		return [ $key, $survivor, $role, $open, $this_second ];
	}

	/**
	 * Create an entity keyed by its type and name, unless the key is taken
	 * (`Graph_Query::taken()`). A creation over a key the caller cannot see,
	 * or over a proposal, replaces it.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `type`, `name`.
	 * @return array<string,mixed> `{ ok, event_id, entity_key }`.
	 * @throws \InvalidArgumentException When the key is taken, or as `Intel_Event::entity_key()`.
	 */
	public static function create_entity( array $args ): array {
		$type = Core::as_string( $args['type'] );
		$name = self::bounded( 'name', Core::as_string( $args['name'] ), self::NAME_BYTES );
		$key  = self::capped( 'key', Intel_Event::entity_key( $type, $name ), self::KEY_BYTES );
		if ( self::on_graph( static fn ( Row_Reader $r, array $g ): array => [ Graph_Query::taken( $r, $g, $key ) ] )[0] ) {
			throw new \InvalidArgumentException( \esc_html( "{$key} is taken" ) );
		}
		return self::write( 'entity_created', [ 'entity_key' => $key, 'type' => $type, 'name' => $name ] ) + [ 'entity_key' => $key ];
	}

	/**
	 * Create a collection keyed by its sanitized name, unless the key is taken.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `name`.
	 * @return array<string,mixed> `{ ok, event_id, collection_key }`.
	 * @throws \InvalidArgumentException When the name sanitizes to nothing or the key is taken.
	 */
	public static function create_collection( array $args ): array {
		$name = self::bounded( 'name', Core::as_string( $args['name'] ), self::NAME_BYTES );
		$key  = self::capped( 'key', Intel_Event::collection_key( $name ), self::KEY_BYTES );
		if ( null !== Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): ?array => Graph_Query::collection( $r, $key ) ) ) {
			throw new \InvalidArgumentException( \esc_html( "collection {$key} is taken" ) );
		}
		return self::write( 'collection_created', [ 'collection_key' => $key, 'name' => $name ] ) + [ 'collection_key' => $key ];
	}

	/**
	 * Save a readable item into a collection, with its URL, its title cut at
	 * TITLE_BYTES and its tier.
	 *
	 * @param array<array-key,mixed> $args The bound verb args: `collection`, `item`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When no collection has the key, or as `judge_link()`.
	 */
	public static function collect( array $args ): array {
		$collection = self::key( $args, 'collection' );
		$item_key   = self::item_key( $args );
		$item       = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $collection, $item_key ): array {
				if ( null === Graph_Query::collection( $r, $collection ) ) {
					throw new \InvalidArgumentException( \esc_html( "no collection: {$collection}" ) );
				}
				return self::item( $r, $g, $item_key );
			}
		);
		return self::write( 'collected', [ 'collection_key' => $collection, 'item_key' => $item_key, 'url' => $item['url'], 'title' => self::cut( $item['title'], self::TITLE_BYTES ), 'tier' => $item['tier'] ] );
	}

	/**
	 * Text cut to its longest prefix of whole characters whose written
	 * bytes fit the cap.
	 *
	 * @param string $text  The text.
	 * @param int    $bytes The cap.
	 */
	private static function cut( string $text, int $bytes ): string {
		[ $fits, $over ] = [ 0, \mb_strlen( $text ) + 1 ];
		while ( $over - $fits > 1 ) {
			$mid = \intdiv( $fits + $over, 2 );
			if ( self::written_bytes( \mb_substr( $text, 0, $mid ) ) <= $bytes ) {
				$fits = $mid;
			} else {
				$over = $mid;
			}
		}
		return \mb_substr( $text, 0, $fits );
	}

	/**
	 * Mint an event as the caller and append it to the ontology stream.
	 *
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @return array{ok:true,event_id:string}
	 * @throws \InvalidArgumentException As `Intel_Event::make()`.
	 */
	private static function write( string $kind, array $fields ): array {
		$event = Intel_Event::make( $kind, $fields, Intel_Event::caller(), \NEWSPACK_INTELLIGENCE_VERSION );
		Ontology_Writer::append( [ $event ] );
		return [ 'ok' => true, 'event_id' => Core::as_string( $event['event_id'] ) ];
	}

	/**
	 * A readable item, as `collected` and a judged link carry it.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key The item key.
	 * @return array{url:string,title:string,tier:string}
	 * @throws \InvalidArgumentException With its URL past URL_BYTES, or with no such item the caller may read.
	 */
	private static function item( Row_Reader $r, array $g, string $key ): array {
		$row = $r->rows( "{$g['with']} SELECT url, title, tier FROM ri WHERE item_key = ? LIMIT 1", [ ...$g['binds'], $key ] )[0] ?? throw new \InvalidArgumentException( \esc_html( "no item: {$key}" ) );
		return [ 'url' => self::capped( "{$key} url", Core::as_string( $row[0] ), self::URL_BYTES ), 'title' => Core::as_string( $row[1] ), 'tier' => Core::as_string( $row[2] ) ];
	}

	/**
	 * The verb's one read of the projection, on the graph it opens; one that
	 * will not open throws.
	 *
	 * @template T of array
	 * @param \Closure(Row_Reader,Graph): T $read The read.
	 * @return T
	 * @throws \Newspack_Nodes\Table_Unavailable When no projection opens.
	 */
	private static function on_graph( \Closure $read ): array {
		return Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => $read( $r, Graph_Query::graph( $r ) ) );
	}

	/**
	 * The `item` arg, bounded at ITEM_KEY_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound verb args, `item` among them.
	 * @throws \InvalidArgumentException When blank or past its cap.
	 */
	private static function item_key( array $args ): string {
		return self::bounded( 'item', Core::as_string( $args['item'] ), self::ITEM_KEY_BYTES );
	}

	/**
	 * A key arg, bounded at KEY_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @param string                 $name The arg.
	 * @throws \InvalidArgumentException When blank or past its cap.
	 */
	private static function key( array $args, string $name ): string {
		return self::bounded( $name, Core::as_string( $args[ $name ] ), self::KEY_BYTES );
	}

	/**
	 * A value, refused when it trims to nothing or runs past its cap.
	 *
	 * @param string $name  What the value is, for the refusal.
	 * @param string $value The value.
	 * @param int    $bytes The cap.
	 * @throws \InvalidArgumentException When blank or past the cap.
	 */
	private static function bounded( string $name, string $value, int $bytes ): string {
		if ( '' === \trim( $value ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} is blank" ) );
		}
		return self::capped( $name, $value, $bytes );
	}

	/**
	 * A value, refused past its cap in bytes as the stream writes it.
	 *
	 * @param string $name  What the value is, for the refusal.
	 * @param string $value The value.
	 * @param int    $bytes The cap.
	 * @throws \InvalidArgumentException Past the cap.
	 */
	private static function capped( string $name, string $value, int $bytes ): string {
		if ( self::written_bytes( $value ) > $bytes ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} runs past {$bytes} bytes" ) );
		}
		return $value;
	}

	/**
	 * The bytes text adds to a packed message, escaped as `Message::packed()`
	 * escapes it.
	 *
	 * @api Source_Node bounds a recorded item's key by it.
	 * @param string $text The text.
	 */
	public static function written_bytes( string $text ): int {
		$message = Message::new_message();
		$bare    = Message::packed_size( $message );
		$message[ Message::VALUE ] = $text;
		return Message::packed_size( $message ) - $bare;
	}
}

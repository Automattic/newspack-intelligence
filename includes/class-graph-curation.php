<?php
/**
 * Graph_Curation: the `intelligence` curation commands. Each writes its
 * judgment as one ontology event through `Stream_Writer::append()`, under
 * `Intel_Event::caller()` — the agent a labelled session names, else the
 * person — and answers `{ ok, event_id }`. A refusal throws before anything
 * is written.
 *
 * Every string an event carries is bounded first, in bytes as the stream
 * writes it, JSON-escaped: a key, a name or a URL past its cap is refused,
 * and a collected title is cut. The caps hold every event inside one
 * partition line whatever characters fill them, except a lens's event, whose
 * lists carry no cap, so `Intel_Event::make()` refuses one past the line. Each
 * command then reads the projection once at most, on the graph
 * `Graph_Query::graph()` opens: every entity key it names answers as the
 * survivor `Graph_Query::seen()` lets the caller see, and an item is read
 * through the readable items, so one outside them is refused exactly as a
 * missing one, and one a curator excluded is refused naming it.
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

	/** The longest churn note's text or next step, in written bytes. */
	public const NOTE_BYTES = 1000;

	/**
	 * Judge the link a readable item has to an entity, by any role saying
	 * the item is about it and under any of the entity's keys:
	 * `link_confirmed` or `link_rejected`, carrying the item's URL. A
	 * `publisher` link alone is not a link to judge.
	 *
	 * @param string                 $kind `link_confirmed` or `link_rejected`.
	 * @param array<array-key,mixed> $args The bound command args: `item`, `entity`.
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
				if ( [] === $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l WHERE item_key = ? AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) AND role IN ( ' . Intel_Graph_Row_Schema::marks( Intel_Event::ABOUT_ROLES ) . ' ) LIMIT 1', [ $item_key, ...$keys, ...Intel_Event::ABOUT_ROLES ] ) ) {
					throw new \InvalidArgumentException( \esc_html( "no link: {$item_key} {$entity}" ) );
				}
				return [ $entity, $item ];
			}
		);
		return self::write( $kind, [ 'item_key' => $item_key, 'entity_key' => $entity, 'item_url' => $item['url'] ] );
	}

	/**
	 * Link a readable item to an entity in a role, `subject` or `mention`,
	 * as the caller's confirmed link, whether or not a machine linked them:
	 * `link_added`, carrying the role and the item's URL, which the
	 * projector reads as a confirmation and the resolver writes as the
	 * item's link. Refused for a topic, which only an extraction tags an
	 * item with; for a link whose highest-ranked judgment under any of the
	 * entity's keys outranks the caller's, which the addition could neither
	 * confirm nor change the role of; for a link that judgment confirms
	 * that already stands in the role or a stronger one, so a confirmed
	 * mention is still added as subject; and for an item outside the
	 * restricted tier and an entity a caller without that tier cannot see,
	 * since the link would show it to every reader.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `item`, `entity`, `role`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException On a bad arg, an entity the caller cannot see, an item it cannot read, or a refusal above.
	 */
	public static function add_link( array $args ): array {
		$role              = Graph_Query::one_of( 'role', Core::as_string( $args['role'] ), Intel_Event::NAMING_ROLES );
		$entity_key        = self::key( $args, 'entity' );
		$item_key          = self::item_key( $args );
		$caller            = \explode( ':', Intel_Event::caller(), 2 )[0];
		[ $entity, $item ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $entity_key, $item_key, $role, $caller ): array {
				$entity = Graph_Query::seen( $r, $g, $entity_key );
				$item   = self::item( $r, $g, $item_key );
				if ( 'topic' === self::type_of( $r, $entity ) ) {
					throw new \InvalidArgumentException( \esc_html( "{$entity} is a topic; only an extraction tags an item with one" ) );
				}
				$keys = Graph_Query::keys_of( $entity, $g );
				[ $state, $judge, $outranks ] = self::verdict( $r, $item_key, $keys, $caller ) ?? [ null, null, false ];
				if ( $outranks ) {
					throw new \InvalidArgumentException( \esc_html( "a {$judge} judged {$item_key} {$entity}, and an addition by an {$caller} ranks below it" ) );
				}
				if ( 'confirmed' === $state && self::stands( $r, $item_key, $keys, $role ) ) {
					throw new \InvalidArgumentException( \esc_html( "already confirmed: {$item_key} {$entity}" ) );
				}
				if ( Intel_Tiers::unrestricted( $item ) && ! self::open( $r, $g, $entity ) ) {
					throw new \InvalidArgumentException( \esc_html( "linking {$item_key} to {$entity} would show {$entity} to every reader; it is not cited by any item outside the restricted tier" ) );
				}
				return [ $entity, $item ];
			}
		);
		return self::write( 'link_added', [ 'item_key' => $item_key, 'entity_key' => $entity, 'role' => $role, 'item_url' => $item['url'] ] );
	}

	/**
	 * Whether an item's link under any of an entity's keys stands in a role
	 * or a stronger one, `subject` over `mention`: a stored link or an
	 * addition.
	 *
	 * @param Row_Reader   $r        The reader.
	 * @param string       $item_key The item key.
	 * @param list<string> $keys     The entity's keys.
	 * @param string       $role     One of NAMING_ROLES.
	 */
	private static function stands( Row_Reader $r, string $item_key, array $keys, string $role ): bool {
		$roles = \array_slice( Intel_Event::NAMING_ROLES, 0, (int) \array_search( $role, Intel_Event::NAMING_ROLES, true ) + 1 );
		$among = 'item_key = ? AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) AND role IN ( ' . Intel_Graph_Row_Schema::marks( $roles ) . ' )';
		$binds = [ $item_key, ...$keys, ...$roles ];
		return [] !== $r->rows( 'SELECT 1 FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . " l WHERE {$among} UNION ALL SELECT 1 FROM " . Intel_Graph_Row_Schema::all( $r, 'added_links' ) . " a WHERE {$among} LIMIT 1", [ ...$binds, ...$binds ] );
	}

	/**
	 * The highest-ranked judgment of an item's link under any of an
	 * entity's keys, the latest among equals: its state, its actor kind and
	 * whether that kind outranks the caller's; null for none.
	 *
	 * @param Row_Reader   $r        The reader.
	 * @param string       $item_key The item key.
	 * @param list<string> $keys     The entity's keys.
	 * @param string       $caller   The caller's actor kind.
	 * @return array{0:string,1:string,2:bool}|null
	 */
	private static function verdict( Row_Reader $r, string $item_key, array $keys, string $caller ): ?array {
		$rank = Graph_Query::judge_rank( 'actor_kind' );
		$sql  = "SELECT state, actor_kind, {$rank} > " . Graph_Query::judge_rank( '?' ) . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j WHERE item_key = ? AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . " ) ORDER BY {$rank} DESC, ts DESC LIMIT 1";
		$row  = $r->rows( $sql, [ $caller, $item_key, ...$keys ] )[0] ?? null;
		return null === $row ? null : [ Core::as_string( $row[0] ), Core::as_string( $row[1] ), 1 === Core::as_int( $row[2] ) ];
	}

	/**
	 * Judge an entity named by `key`: `entity_accepted` or `entity_rejected`.
	 * An accept is refused for an entity a caller without restricted access
	 * cannot see, since accepting it would show it to every reader.
	 *
	 * @param string                 $kind `entity_accepted` or `entity_rejected`.
	 * @param array<array-key,mixed> $args The bound command args: `key`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a bad key, one the caller cannot see, or an accept that would declassify it.
	 */
	public static function judge_entity( string $kind, array $args ): array {
		$key    = self::key( $args, 'key' );
		$entity = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $kind, $key ): array {
				$entity = Graph_Query::seen( $r, $g, $key );
				if ( 'entity_accepted' === $kind && ! self::open( $r, $g, $entity ) ) {
					throw new \InvalidArgumentException( \esc_html( "{$entity} is not cited by any item outside the restricted tier; accepting it would show it to every reader" ) );
				}
				return [ $entity ];
			}
		)[0];
		return self::write( $kind, [ 'entity_key' => $entity ] );
	}

	/**
	 * Add a name to an entity, its value bounded at NAME_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`, `kind`, `value`.
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
	 * @param array<array-key,mixed> $args The bound command args.
	 * @param string                 $name The arg.
	 * @throws \InvalidArgumentException On a bad key, or `no entity` for one the caller cannot see.
	 */
	private static function entity( array $args, string $name ): string {
		$key = self::key( $args, $name );
		return self::on_graph( static fn ( Row_Reader $r, array $g ): array => [ Graph_Query::seen( $r, $g, $key ) ] )[0];
	}

	/**
	 * Tag an entity with a topic, an entity of type `topic`, unless every
	 * reader sees the entity but not every reader sees the topic, which the
	 * tag would show on the entity's record.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`, `topic`.
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
					throw new \InvalidArgumentException( \esc_html( "tagging {$entity} with {$tagged} would show {$tagged} to every reader; it is not cited by any item outside the restricted tier" ) );
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
	 * @param array<array-key,mixed> $args The bound command args: `from`, `into`.
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
			throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would show {$hidden} to every reader; it is not cited by any item outside the restricted tier" ) );
		}
		foreach ( Entity_Index::walk( [ $from => $into ] + $merges )[0] as $key => $survivor ) {
			if ( '' === $survivor && '' !== ( $before[ $key ] ?? $key ) ) {
				throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would leave {$key} on a chain that cycles or runs past " . Entity_Index::MAX_MERGE_HOPS . ' hops' ) );
			}
		}
		return self::write( 'entity_merged', [ 'entity_key' => $from, 'into_key' => $into ] );
	}

	/**
	 * Merge one theme into another, so every read of an item's theme reads
	 * the survivor and no item's row is rewritten: `theme_merged`. Refused
	 * for a merge into itself; one naming a theme the caller cannot see, as
	 * it is not the theme of any item the caller may read, as written or as
	 * its survivor, each item's theme the newest partition's; one after
	 * which `Entity_Index::walk()` over the projection's theme merges leaves
	 * a theme that resolved before on a chain that cycles or runs past
	 * `Entity_Index::MAX_MERGE_HOPS`, naming the first the caller can see,
	 * else naming none; one into a theme that itself merges away, naming its
	 * survivor, or whose own chain does not resolve; and one that would make
	 * `into` the theme of an item every reader reads for the first time,
	 * which only a holder of the restricted tier can ask. Both read every item
	 * the caller may read, an excluded one among them, since an inclusion
	 * undoes an exclusion.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `from`, `into`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a blank theme or one past `Intel_Event::THEME_BYTES`, or a merge refused as above.
	 */
	public static function merge_theme( array $args ): array {
		$from                = self::bounded( 'from', Core::as_string( $args['from'] ), Intel_Event::THEME_BYTES );
		$into                = self::bounded( 'into', Core::as_string( $args['into'] ), Intel_Event::THEME_BYTES );
		if ( $from === $into ) {
			throw new \InvalidArgumentException( \esc_html( "cannot merge theme {$from} into itself" ) );
		}
		[ $merges, $carried ] = self::on_graph(
			static function ( Row_Reader $r, array $g ): array {
				$carried = [];
				foreach ( $r->rows( "{$g['with']} SELECT DISTINCT t.theme, ti.tier IN ( " . Intel_Graph_Row_Schema::marks( Intel_Tiers::UNRESTRICTED ) . ' ) FROM ( ' . Intel_Graph_Row_Schema::newest( $r, 'themes', 'item_key', 'item_key, theme', '1' ) . ' ) t JOIN ti ON ti.item_key = t.item_key', [ ...$g['binds'], ...Intel_Tiers::UNRESTRICTED ] ) as [ $theme, $open ] ) {
					$theme             = Core::as_string( $theme );
					$carried[ $theme ] = ( $carried[ $theme ] ?? false ) || (bool) $open;
				}
				return [ Graph_Query::theme_merges( $r ), $carried ];
			}
		);
		$before = Entity_Index::walk( $merges )[0];
		$seen   = [];
		foreach ( \array_keys( $carried ) as $theme ) {
			$seen[ $theme ]                               = true;
			$seen[ $before[ $theme ] ?? $theme ] = true;
		}
		foreach ( [ $from, $into ] as $theme ) {
			if ( ! isset( $seen[ $theme ] ) ) {
				throw new \InvalidArgumentException( \esc_html( "no theme: {$theme}" ) );
			}
		}
		$after  = Entity_Index::walk( [ $from => $into ] + $merges )[0];
		$broken = [];
		foreach ( $after as $theme => $survivor ) {
			if ( '' === $survivor && '' !== ( $before[ $theme ] ?? $theme ) ) {
				$broken[] = $theme;
			}
		}
		if ( [] !== $broken ) {
			$shown = \array_values( \array_filter( $broken, static fn ( string $theme ): bool => isset( $seen[ $theme ] ) ) );
			throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would leave " . ( $shown[0] ?? 'a theme' ) . ' on a chain that cycles or runs past ' . Entity_Index::MAX_MERGE_HOPS . ' hops' ) );
		}
		$survivor = $before[ $into ] ?? $into;
		if ( '' === $survivor ) {
			throw new \InvalidArgumentException( \esc_html( "{$into} is on a merge chain that cycles or runs past " . Entity_Index::MAX_MERGE_HOPS . " hops; merge {$from} into a theme whose chain resolves instead" ) );
		}
		if ( $survivor !== $into ) {
			throw new \InvalidArgumentException( \esc_html( "{$into} merges into {$survivor}; merge {$from} into {$survivor} instead" ) );
		}
		$shows = static fn ( array $map ): bool => [] !== \array_filter( $carried, static fn ( bool $unrestricted, string $theme ): bool => $unrestricted && $into === ( $map[ $theme ] ?? $theme ), \ARRAY_FILTER_USE_BOTH );
		if ( $shows( $after ) && ! $shows( $before ) ) {
			throw new \InvalidArgumentException( \esc_html( "merging {$from} into {$into} would show {$into} to every reader; it is not the theme of any item outside the restricted tier" ) );
		}
		return self::write( 'theme_merged', [ 'from' => $from, 'into' => $into ] );
	}

	/**
	 * Add an identifier to an entity, spelled as `Entity_Index` compares it,
	 * unless it is a domain that is not a plain hostname
	 * (`Entity_Index::hostname_fault()`), or another entity holds it, as
	 * `Entity_Index::holder()` answers: the survivor of the key holding it,
	 * accepted or proposed, or that key itself when its survivor is rejected
	 * or its merge chain does not resolve.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`, `kind`, `value`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException Naming the entity that holds it, or on a bad arg or an entity the caller cannot see.
	 */
	public static function add_identifier( array $args ): array {
		$key               = self::key( $args, 'key' );
		$kind              = Core::as_string( $args['kind'] );
		$value             = self::bounded( 'value', Entity_Index::identifier_value( $kind, Core::as_string( $args['value'] ) ), self::NAME_BYTES );
		$fault             = 'domain' === $kind ? Entity_Index::hostname_fault( $value ) : null;
		if ( null !== $fault ) {
			throw new \InvalidArgumentException( \esc_html( "domain:{$value} is not a plain hostname: {$fault}" ) );
		}
		[ $own, $held_by ] = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key, $kind, $value ): array {
				$own    = Graph_Query::seen( $r, $g, $key );
				$holder = Entity_Index::load( $r )->holder( $kind, $value );
				return [ $own, null === $holder || $own === ( $g['survivors'][ $holder ] ?? $holder ) ? null : $holder ];
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
	 * @param array<array-key,mixed> $args The bound command args: `key`, `role`.
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
	 * @param array<array-key,mixed> $args The bound command args: `key`, `role`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the role is not open, or on a bad
	 *                                   key or one the caller cannot see.
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
	 * @param array<array-key,mixed> $args The bound command args: `key`, `role`.
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
	 * @param array<array-key,mixed> $args The bound command args: `type`, `name`.
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
	 * Create a collection keyed by its sanitized name, unless the key is
	 * taken, carrying the item it is created with, when the call names one,
	 * as `collect()` saves it: one event, so a refused item leaves no folder.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `name`, `item`.
	 * @return array<string,mixed> `{ ok, event_id, collection_key }`.
	 * @throws \InvalidArgumentException When the name sanitizes to nothing,
	 *                                   the key is taken, or as `item()`.
	 */
	public static function create_collection( array $args ): array {
		$name     = self::bounded( 'name', Core::as_string( $args['name'] ), self::NAME_BYTES );
		$key      = self::capped( 'key', Intel_Event::collection_key( $name ), self::KEY_BYTES );
		$item_key = null === $args['item'] ? null : self::item_key( $args );
		$item     = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $key, $item_key ): array {
				if ( null !== Graph_Query::collection( $r, $key ) ) {
					throw new \InvalidArgumentException( \esc_html( "collection {$key} is taken" ) );
				}
				return null === $item_key ? [] : [ 'item' => [ 'key' => $item_key ] + self::item( $r, Graph_Query::graph( $r ), $item_key ) ];
			}
		);
		return self::write( 'collection_created', [ 'collection_key' => $key, 'name' => $name ] + $item ) + [ 'collection_key' => $key ];
	}

	/**
	 * Save a readable item into a collection, with its URL, its title cut at
	 * TITLE_BYTES and its tier, unless the caller reads a copy of it saved
	 * there already. A copy saved at a tier the caller does not read is left
	 * as it is, and the save writes a copy of its own tier beside it.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `collection`, `item`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the key does not name a
	 *                                   collection, the caller reads the item
	 *                                   saved there, or as `judge_link()`.
	 */
	public static function collect( array $args ): array {
		$collection = self::key( $args, 'collection' );
		$item_key   = self::item_key( $args );
		$item       = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $collection, $item_key ): array {
				self::not_in( $r, self::folder( $r, $collection ), $collection, $item_key );
				return self::item( $r, $g, $item_key );
			}
		);
		return self::write( 'collected', [ 'collection_key' => $collection, 'item_key' => $item_key ] + $item );
	}

	/**
	 * Refuse an item the caller reads a copy of saved in a collection,
	 * naming the collection.
	 *
	 * @param Row_Reader $r          The reader.
	 * @param string     $name       The collection's name.
	 * @param string     $collection The collection key.
	 * @param string     $item_key   The item key.
	 * @throws \InvalidArgumentException When the caller reads one there.
	 */
	private static function not_in( Row_Reader $r, string $name, string $collection, string $item_key ): void {
		if ( [] !== Graph_Query::saved_tiers( $r, $collection, $item_key, Intel_Tiers::readable() ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$item_key} is already in {$name}" ) );
		}
	}

	/**
	 * Exclude a readable item from every view: `item_excluded`, carrying
	 * its URL, its title cut at TITLE_BYTES and its tier, so the excluded
	 * list outlives the item's own rows, and `why` when the call gives one,
	 * bounded at `Intel_Event::WHY_BYTES`. Refused, as `item()` refuses, for
	 * an item excluded already and for one the caller cannot read.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `item`, `why`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException On a bad arg, or as `item()`.
	 */
	public static function exclude_item( array $args ): array {
		$item_key = self::item_key( $args );
		$why      = null === $args['why'] ? [] : [ 'why' => self::bounded( 'why', Core::as_string( $args['why'] ), Intel_Event::WHY_BYTES ) ];
		$item     = self::on_graph( static fn ( Row_Reader $r, array $g ): array => self::item( $r, $g, $item_key ) );
		return self::write( 'item_excluded', [ 'item_key' => $item_key ] + $item + $why );
	}

	/**
	 * A readable item a curator has not excluded, as `collected`, a folder
	 * created with it, a judged link and an exclusion carry it, its title
	 * cut at TITLE_BYTES. It is read from `ti`, the readable items before
	 * the exclusion, so an excluded one is refused naming it.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key The item key.
	 * @return array{url:string,title:string,tier:string}
	 * @throws \InvalidArgumentException With its URL past URL_BYTES, when the
	 *                                   item is missing or the caller may not
	 *                                   read it, or when it is excluded.
	 */
	private static function item( Row_Reader $r, array $g, string $key ): array {
		$row = $r->rows( "{$g['with']} SELECT url, title, tier FROM ti WHERE item_key = ? LIMIT 1", [ ...$g['binds'], $key ] )[0] ?? throw new \InvalidArgumentException( \esc_html( "no item: {$key}" ) );
		self::unexcluded( $r, $key );
		return [ 'url' => self::capped( "{$key} url", Core::as_string( $row[0] ), self::URL_BYTES ), 'title' => self::cut( Core::as_string( $row[1] ), self::TITLE_BYTES ), 'tier' => Core::as_string( $row[2] ) ];
	}

	/**
	 * Text cut to its longest prefix of whole characters whose written
	 * bytes fit the cap.
	 *
	 * @api Resolver cuts an evidence sentence by it too.
	 * @param string $text  The text.
	 * @param int    $bytes The cap.
	 */
	public static function cut( string $text, int $bytes ): string {
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
	 * Set a client's churn-risk note on its survivor, replacing any note it
	 * carries: `churn_note_set`, its text and next step each trimmed and
	 * bounded at NOTE_BYTES, its owner at NAME_BYTES and its day a Y-m-d
	 * date, each '' when the call gives none. A text of '' clears the note.
	 * Refused for an entity the caller cannot see, and for one holding no
	 * open client role, whose profile shows no status to carry a note.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `entity`, `text`, `owner`, `next_step`, `day`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException On a value past its cap, a day that is not a date, or an entity that is not an open client the caller sees.
	 */
	public static function note_churn( array $args ): array {
		$key    = self::key( $args, 'entity' );
		$fields = [
			'text'      => self::capped( 'text', \trim( Core::as_string( $args['text'] ) ), self::NOTE_BYTES ),
			'owner'     => self::capped( 'owner', \trim( Core::as_string( $args['owner'] ) ), self::NAME_BYTES ),
			'next_step' => self::capped( 'next_step', \trim( Core::as_string( $args['next_step'] ) ), self::NOTE_BYTES ),
			'day'       => \trim( Core::as_string( $args['day'] ) ),
		];
		if ( '' !== $fields['day'] ) {
			Intel_Graph_Row_Schema::day( $fields['day'] );
		}
		$client = self::on_graph(
			static function ( Row_Reader $r, array $g ) use ( $key ): array {
				$survivor = Graph_Query::seen( $r, $g, $key );
				if ( ! isset( Graph_Query::open_roles( $r, [ $survivor => Graph_Query::keys_of( $survivor, $g ) ], [ Graph_Query::CLIENT ] )[ $survivor ] ) ) {
					throw new \InvalidArgumentException( \esc_html( "{$survivor} holds no open " . Graph_Query::CLIENT . ' role' ) );
				}
				return [ $survivor ];
			}
		)[0];
		return self::write( 'churn_note_set', [ 'entity_key' => $client ] + $fields );
	}

	/**
	 * Include an excluded item again: `item_included`. Refused unless the
	 * excluded list the caller reads holds it (`Graph_Query::exclusions()`),
	 * so an exclusion of a tier it does not read is refused exactly as none,
	 * and an exclusion outliving its item's rows is still included.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `item`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException When the item is not excluded, or on a bad arg.
	 */
	public static function include_item( array $args ): array {
		$item_key = self::item_key( $args );
		$tiers    = Intel_Tiers::readable();
		if ( ! Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): bool => [] !== $r->rows( 'SELECT 1 FROM ( ' . Graph_Query::exclusions( $r ) . ' ) WHERE item_key = ? AND tier IN ( ' . Intel_Graph_Row_Schema::marks( $tiers ) . ' ) LIMIT 1', [ $item_key, ...$tiers ] ) ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$item_key} is not excluded" ) );
		}
		return self::write( 'item_included', [ 'item_key' => $item_key ] );
	}

	/**
	 * Mark a saved item used in a newsletter, on LinkedIn or on the site, on
	 * a day, on the copy the caller reads, at that copy's tier; marking it so
	 * again replaces the day, and a mark is never taken back.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `collection`, `item`, `mark`, `day`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException On a mark outside `Intel_Event::MARKS`,
	 *                                   a day that is not a Y-m-d date, or as
	 *                                   `saved_in()`.
	 */
	public static function mark_used( array $args ): array {
		$collection = self::key( $args, 'collection' );
		$item_key   = self::item_key( $args );
		$mark       = Graph_Query::one_of( 'mark', Core::as_string( $args['mark'] ), Intel_Event::MARKS );
		$day        = Core::as_string( $args['day'] );
		Intel_Graph_Row_Schema::day( $day );
		$tiers      = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $collection, $item_key ): array {
				$tiers = self::saved_in( $r, $collection, $item_key );
				self::unexcluded( $r, $item_key );
				return $tiers;
			}
		);
		return self::write( 'item_used', [ 'collection_key' => $collection, 'item_key' => $item_key, 'tier' => $tiers[0], 'mark' => $mark, 'day' => $day ] );
	}

	/**
	 * Move every copy of a saved item the caller reads from its folder into
	 * another, naming each copy's tier, so the folder it leaves holds none of
	 * them and each keeps its own saved URL, title and marks. A copy of a
	 * tier the caller does not read stays where it is, and a placement `into`
	 * holds already keeps its actor and time.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `collection`, `item`, `into`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException As `saved_in()`, when `into` names no
	 *                                   collection, or when it is the folder
	 *                                   the item is in.
	 */
	public static function move_item( array $args ): array {
		$collection = self::key( $args, 'collection' );
		$item_key   = self::item_key( $args );
		$into       = self::key( $args, 'into' );
		$tiers      = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $collection, $item_key, $into ): array {
				$tiers = self::saved_in( $r, $collection, $item_key );
				self::unexcluded( $r, $item_key );
				$name  = self::folder( $r, $into );
				if ( $into === $collection ) {
					throw new \InvalidArgumentException( \esc_html( "{$item_key} is already in {$name}" ) );
				}
				return $tiers;
			}
		);
		return self::write( 'item_moved', [ 'collection_key' => $into, 'item_key' => $item_key, 'from' => $collection, 'tiers' => $tiers ] );
	}

	/**
	 * Take a saved item out of a folder: `item_uncollected`, naming every
	 * tier of its copies there the caller reads, so the folder holds none of
	 * them; a copy of a tier the caller does not read stays, and the item
	 * stays in every other folder, with its saved copies and marks.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `collection`, `item`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException As `saved_in()`, or for an excluded item.
	 */
	public static function uncollect( array $args ): array {
		$collection = self::key( $args, 'collection' );
		$item_key   = self::item_key( $args );
		$tiers      = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $collection, $item_key ): array {
				$tiers = self::saved_in( $r, $collection, $item_key );
				self::unexcluded( $r, $item_key );
				return $tiers;
			}
		);
		return self::write( 'item_uncollected', [ 'collection_key' => $collection, 'item_key' => $item_key, 'tiers' => $tiers ] );
	}

	/**
	 * Refuse an item a curator excluded, naming it, by the one rule every
	 * read leaves it out by (`Graph_Query::kept()`).
	 *
	 * @param Row_Reader $r        The reader.
	 * @param string     $item_key The item key.
	 * @throws \InvalidArgumentException When the item is excluded.
	 */
	private static function unexcluded( Row_Reader $r, string $item_key ): void {
		if ( [] === $r->rows( 'SELECT 1 WHERE ' . Graph_Query::kept( $r, '?' ), [ $item_key ] ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$item_key} is excluded from every view" ) );
		}
	}

	/**
	 * The tiers of the copies of an item saved in a collection the caller
	 * reads, the most restricted first, refused, naming the collection, when
	 * there is none: one saved at a tier the caller does not read alone is
	 * refused exactly as one never saved.
	 *
	 * @param Row_Reader $r          The reader.
	 * @param string     $collection The collection key.
	 * @param string     $item_key   The item key.
	 * @return non-empty-list<string>
	 * @throws \InvalidArgumentException When the key does not name a
	 *                                   collection, or the item is not saved
	 *                                   in it.
	 */
	private static function saved_in( Row_Reader $r, string $collection, string $item_key ): array {
		$name  = self::folder( $r, $collection );
		$tiers = Graph_Query::saved_tiers( $r, $collection, $item_key, Intel_Tiers::readable() );
		if ( [] === $tiers ) {
			throw new \InvalidArgumentException( \esc_html( "{$item_key} is not saved in {$name}" ) );
		}
		return $tiers;
	}

	/**
	 * A collection's name, refused when the key does not name a collection.
	 *
	 * @param Row_Reader $r          The reader.
	 * @param string     $collection The collection key.
	 * @throws \InvalidArgumentException When it names none.
	 */
	private static function folder( Row_Reader $r, string $collection ): string {
		return ( Graph_Query::collection( $r, $collection ) ?? throw new \InvalidArgumentException( \esc_html( "no collection: {$collection}" ) ) )[0];
	}

	/**
	 * The `item` arg, bounded at ITEM_KEY_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound command args, `item` among them.
	 * @throws \InvalidArgumentException When blank or past its cap.
	 */
	private static function item_key( array $args ): string {
		return self::bounded( 'item', Core::as_string( $args['item'] ), self::ITEM_KEY_BYTES );
	}

	/**
	 * Save a lens under its key, slugged, replacing any lens there: every
	 * filter in `Graph_Query::unfiltered()`'s shape, each list of
	 * `Graph_Query::LENS_LISTS` and each tier checked against its vocabulary,
	 * an entity or topic as the survivor `Graph_Query::seen()` lets the
	 * caller see, a topic of type `topic`, and refused when a caller without
	 * restricted access could not see it, since every reader lists every
	 * lens. An event past one ontology line is refused as
	 * `Intel_Event::make()` refuses it.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`, `name`, `description`, `entity`, `days`, `by`, `bucket`, and the lists `kind`, `topic`, `role`, `source`, `tier`.
	 * @return array<string,mixed> `{ ok, event_id, lens_key }`.
	 * @throws \InvalidArgumentException On a value outside its vocabulary, a bad key, an entity or topic the caller cannot see or every reader could not, a topic that is not one, or an event past one line.
	 */
	public static function save_lens( array $args ): array {
		$key     = self::capped( 'key', Intel_Event::lens_key( Core::as_string( $args['key'] ) ), self::KEY_BYTES );
		$name    = self::bounded( 'name', Core::as_string( $args['name'] ), self::NAME_BYTES );
		$filters = self::lens_filters( $args );
		$entity  = $filters['entity'];
		$topics  = \array_map( Core::as_string( ... ), Core::arr( $filters['topics'] ) );
		if ( null !== $entity || [] !== $topics ) {
			[ $filters['entity'], $filters['topics'] ] = self::on_graph(
				static fn ( Row_Reader $r, array $g ): array => [
					null === $entity ? null : self::shown( $r, $g, Core::as_string( $entity ) ),
					\array_values( \array_unique( \array_map( static fn ( string $topic ): string => self::topic( $r, $g, $topic ), $topics ) ) ),
				]
			);
		}
		$description = Core::as_string( $args['description'] );
		return self::write( 'lens_saved', [ 'lens_key' => $key, 'name' => $name, 'description' => $description, 'filters' => $filters ] ) + [ 'lens_key' => $key ];
	}

	/**
	 * A topic a lens names, as `shown()` answers it, refused when its
	 * survivor is not of type `topic`.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key A topic's entity key.
	 * @throws \InvalidArgumentException As `shown()`, or for an entity that is not a topic.
	 */
	private static function topic( Row_Reader $r, array $g, string $key ): string {
		$survivor = self::shown( $r, $g, $key );
		if ( 'topic' !== self::type_of( $r, $survivor ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$survivor} is not a topic" ) );
		}
		return $survivor;
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
	 * The survivor a key a lens names answers as, when the caller sees it
	 * and so would a caller without restricted access.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The graph the read opened.
	 * @param string     $key An entity key.
	 * @throws \InvalidArgumentException As `Graph_Query::seen()`, or for one only restricted items cite.
	 */
	private static function shown( Row_Reader $r, array $g, string $key ): string {
		$survivor = Graph_Query::seen( $r, $g, $key );
		if ( ! self::open( $r, $g, $survivor ) ) {
			throw new \InvalidArgumentException( \esc_html( "a lens naming {$survivor} would show it to every reader; it is not cited by any item outside the restricted tier" ) );
		}
		return $survivor;
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
	 * The command's one read of the projection, on the graph it opens; one that
	 * will not open throws.
	 *
	 * @api Graph_Watches reads through it too.
	 * @template T of array
	 * @param \Closure(Row_Reader,Graph): T $read The read.
	 * @return T
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection will not
	 *                                           open.
	 */
	public static function on_graph( \Closure $read ): array {
		return Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => $read( $r, Graph_Query::graph( $r ) ) );
	}

	/**
	 * A lens's filters in `Graph_Query::unfiltered()`'s shape, checked as far
	 * as it can be without reading the projection: `days` from 1 to
	 * `RETAIN_DAYS`, `by` and `bucket` against the trend sets, each
	 * `LENS_LISTS` list against its vocabulary or bounded where it has none,
	 * each tier one of `Intel_Event::TIERS`, and the entity bounded.
	 * `save_lens()` then reads the entity and topics through the graph; the
	 * ontology seed, which reads none, writes them as given.
	 *
	 * @api Ontology_Seed checks a seed's lenses by it.
	 * @param array<array-key,mixed> $args `save_lens`'s bound args: `entity`, `days`, `by`, `bucket`, the lists by their option and `tier`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException On a value outside its range or vocabulary, blank or past its cap.
	 */
	public static function lens_filters( array $args ): array {
		$days = null === $args['days'] ? null : Core::as_int( $args['days'] );
		if ( null !== $days && ( $days < 1 || $days > Intel_Graph_Row_Schema::RETAIN_DAYS ) ) {
			throw new \InvalidArgumentException( \esc_html( 'days must be between 1 and ' . Intel_Graph_Row_Schema::RETAIN_DAYS ) );
		}
		$by     = null === $args['by'] ? null : Core::as_string( $args['by'] );
		$bucket = null === $args['bucket'] ? null : Core::as_string( $args['bucket'] );
		Graph_Query::check_trend( $by, $bucket );
		return self::lists( $args ) + [
			'entity' => null === $args['entity'] ? null : self::key( $args, 'entity' ),
			'days'   => $days,
			'tiers'  => self::within( $args, 'tier', Intel_Event::TIERS ),
			'by'     => $by,
			'bucket' => $bucket,
		];
	}

	/**
	 * A key arg, bounded at KEY_BYTES.
	 *
	 * @param array<array-key,mixed> $args The bound command args.
	 * @param string                 $name The arg.
	 * @throws \InvalidArgumentException When blank or past its cap.
	 */
	private static function key( array $args, string $name ): string {
		return self::bounded( $name, Core::as_string( $args[ $name ] ), self::KEY_BYTES );
	}

	/**
	 * Each of `Graph_Query::LENS_LISTS` by its field, read from its arg:
	 * each value of its vocabulary, or bounded where it has none.
	 *
	 * @param array<array-key,mixed> $args The bound command args.
	 * @return array<string,list<string>>
	 * @throws \InvalidArgumentException On a value outside its vocabulary, blank or past its cap.
	 */
	private static function lists( array $args ): array {
		$lists = [];
		foreach ( Graph_Query::LENS_LISTS as $field => [ $option, $values ] ) {
			$lists[ $field ] = null === $values ? self::listed( $args, $option, self::KEY_BYTES ) : self::within( $args, $option, $values );
		}
		return $lists;
	}

	/**
	 * A list arg's values, each one of its vocabulary.
	 *
	 * @param array<array-key,mixed> $args The bound command args.
	 * @param string                 $name The arg.
	 * @param list<string>           $set  Its vocabulary.
	 * @return list<string>
	 * @throws \InvalidArgumentException On a value outside it.
	 */
	private static function within( array $args, string $name, array $set ): array {
		return \array_map( static fn ( string $value ): string => Graph_Query::one_of( $name, $value, $set ), self::listed( $args, $name, self::NAME_BYTES ) );
	}

	/**
	 * A list arg's values, each bounded, a value repeated kept once.
	 *
	 * @param array<array-key,mixed> $args  The bound command args.
	 * @param string                 $name  The arg.
	 * @param int                    $bytes Each value's cap.
	 * @return list<string>
	 * @throws \InvalidArgumentException On a value blank or past its cap.
	 */
	private static function listed( array $args, string $name, int $bytes ): array {
		$values = \array_map( static fn ( mixed $value ): string => self::bounded( $name, Core::as_string( $value ), $bytes ), Core::arr( $args[ $name ] ) );
		return \array_values( \array_unique( $values ) );
	}

	/**
	 * A value, refused when it trims to nothing or runs past its cap.
	 *
	 * @api Graph_Watches bounds its args by it too.
	 * @param string $name  What the value is, for the refusal.
	 * @param string $value The value.
	 * @param int    $bytes The cap.
	 * @throws \InvalidArgumentException When blank or past the cap.
	 */
	public static function bounded( string $name, string $value, int $bytes ): string {
		if ( '' === \trim( $value ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$name} is blank" ) );
		}
		return self::capped( $name, $value, $bytes );
	}

	/**
	 * Remove the lens a key names, slugged as `save_lens()` slugs it.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`.
	 * @return array<string,mixed> `{ ok, event_id }`.
	 * @throws \InvalidArgumentException When the key sanitizes to nothing, or
	 *                                   it does not name a lens.
	 */
	public static function remove_lens( array $args ): array {
		$key = self::capped( 'key', Intel_Event::lens_key( Core::as_string( $args['key'] ) ), self::KEY_BYTES );
		if ( null === Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): ?string => Graph_Query::saved_lens( $r, $key ) ) ) {
			throw new \InvalidArgumentException( \esc_html( "no lens: {$key}" ) );
		}
		return self::write( 'lens_removed', [ 'lens_key' => $key ] );
	}

	/**
	 * Mint an event as the caller and append it to its kind's stream.
	 *
	 * @api Graph_Watches writes through it too.
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @return array{ok:true,event_id:string}
	 * @throws \InvalidArgumentException As `Intel_Event::make()`.
	 */
	public static function write( string $kind, array $fields ): array {
		$event = Intel_Event::make( $kind, $fields, Intel_Event::caller(), \NEWSPACK_INTELLIGENCE_VERSION );
		Stream_Writer::append( [ $event ] );
		return [ 'ok' => true, 'event_id' => Core::as_string( $event['event_id'] ) ];
	}

	/**
	 * A value, refused past its cap in bytes as the stream writes it.
	 *
	 * @api Intel_Event bounds an optional field by it, and Prompts::parse_entities() a name.
	 * @param string $name  What the value is, for the refusal.
	 * @param string $value The value.
	 * @param int    $bytes The cap.
	 * @throws \InvalidArgumentException Past the cap.
	 */
	public static function capped( string $name, string $value, int $bytes ): string {
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

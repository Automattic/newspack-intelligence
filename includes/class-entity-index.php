<?php
/**
 * Entity_Index: the entities resolution matches against, read once from the
 * projection. It holds the accepted and proposed entities, each name by its
 * `norm()` and each identifier by `kind:value`; a merged entity's names and
 * identifiers answer its survivor's key, and a rejected entity's names are
 * kept only to refuse proposing them again. An identifier whose survivor is
 * rejected or unresolvable is left out too, and `held_by_refused()` names
 * the key holding it. An entity exists once it has
 * both an `entities` row and an `entity_states` row; a name or identifier
 * naming any other key is not indexed. A topic is matched by extraction,
 * never in text: it is offered by its canonical name through `topics()` and
 * none of its names is indexed.
 *
 * `norm()` is the one spelling a name is compared in, which the projection
 * stores beside every name and title.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

final class Entity_Index {

	/** The longest hostname, in characters. */
	private const HOSTNAME_BYTES = 253;

	/** The longest hostname label, in characters. */
	private const LABEL_BYTES = 63;

	/** Seconds the process uses one index before it reads the projection again. */
	public const TTL_S = 60;

	/** The most words a name can span and still be found in text. */
	public const MAX_NAME_WORDS = 6;

	/** The longest merge chain followed to its survivor. */
	public const MAX_MERGE_HOPS = 10;

	/** The states an entity is indexed in. */
	private const LIVE_STATES = [ 'accepted', 'proposed' ];

	/**
	 * Each matchable name by its norm: `entity|kind|value` => the name.
	 *
	 * @var array<string,array<string,array{entity_key:string,kind:string,value:string,state:string}>>
	 */
	private array $names = [];

	/** @var array<string,array<array-key,string>> Kind => value => the entity it names. */
	private array $identifiers = [];

	/** @var array<string,array<array-key,string>> Kind => value => the key holding it, whose survivor is rejected or unresolvable. */
	private array $refused = [];

	/** @var array<string,true> The norm of every rejected entity's names. */
	private array $rejected = [];

	/** @var array<string,string> Each indexed entity's state. */
	private array $states = [];

	/** @var array<string,string> Each live topic's canonical name => its key. */
	private array $topics = [];

	/** @var array<string,string> Each merged key's survivor, '' where it does not resolve. */
	private array $merged = [];

	/** Whether a projector's reader of the ontology stream stood short of its end just before this index was read. */
	private bool $behind = false;

	/** The index every reader in this process shares. */
	private static ?self $current = null;

	/** When the shared index was read, on the tick clock. */
	private static float $read_at = 0.0;

	/**
	 * The index this process shares, read again once TTL_S has passed or
	 * when it was read before `$fresh_after`, the tick by which the
	 * projection holds what a caller needs; an empty one, logged once, while
	 * no `intel-graph` table can be read. Each read first takes whether the
	 * projector stands behind the ontology stream, BEFORE the index, so a
	 * flush and commit landing between the two reads can only make the index
	 * fresher than the reading says, never staler.
	 *
	 * @param float $fresh_after The earliest read the caller accepts.
	 */
	public static function current( float $fresh_after = 0.0 ): self {
		if ( null === self::$current || self::$read_at < $fresh_after || Core::$now - self::$read_at >= self::TTL_S ) {
			$behind                = self::ontology_behind();
			self::$current         = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): self => self::load( $r ), 'no intel-graph to read entities from; reading none: ' ) ?? new self();
			self::$current->behind = $behind;
			self::$read_at         = Core::$now;
		}
		return self::$current;
	}

	/**
	 * Read the index from the projection: every attached partition's
	 * entities, states, merges, names and identifiers, each table one
	 * statement over every partition, a later partition's row winning. An
	 * entity whose merge chain cycles or runs past MAX_MERGE_HOPS answers no
	 * survivor, so its names and identifiers are left out, logged once
	 * naming each chain.
	 *
	 * @param Row_Reader $r The reader `Intel_Graph_Row_Schema::read()` hands over.
	 */
	public static function load( Row_Reader $r ): self {
		$states = [];
		foreach ( $r->rows( 'SELECT entity_key, state FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_states' ) . ' s', [] ) as [ $key, $state ] ) {
			$states[ Core::as_string( $key ) ] = Core::as_string( $state );
		}
		$index         = new self();
		$index->merged = self::survivors( $r );
		$entities      = $r->rows( 'SELECT entity_key, name, type FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' e', [] );
		$existing      = [];
		$topics        = [];
		foreach ( $entities as [ $key, $name, $type ] ) {
			$key = Core::as_string( $key );
			if ( isset( $states[ $key ] ) ) {
				$existing[ $key ] = $states[ $key ];
				if ( 'topic' === $type ) {
					$topics[ $key ] = Core::as_string( $name );
				}
			}
		}
		$survivor = static function ( string $key ) use ( $index ): ?string {
			$survivor = $index->merged[ $key ] ?? $key;
			return '' === $survivor ? null : $survivor;
		};
		$state_of = static fn ( string $key ): ?string => $existing[ $survivor( $key ) ?? '' ] ?? null;
		foreach ( $existing as $key => $state ) {
			if ( ! isset( $index->merged[ $key ] ) && \in_array( $state, self::LIVE_STATES, true ) ) {
				$index->states[ $key ] = $state;
				if ( isset( $topics[ $key ] ) ) {
					$index->topics[ $topics[ $key ] ] = $key;
				}
			}
		}
		\ksort( $index->topics );
		$add_name = static function ( string $key, string $kind, string $value, string $norm ) use ( $index, $topics, $survivor, $state_of ): void {
			if ( isset( $topics[ $key ] ) ) {
				return;
			}
			$state = $state_of( $key );
			if ( 'rejected' === $state ) {
				$index->rejected[ $norm ] = true;
			} elseif ( \in_array( $state, self::LIVE_STATES, true ) ) {
				$entity = Core::as_string( $survivor( $key ) );
				$index->names[ $norm ][ "{$entity}|{$kind}|{$value}" ] = [ 'entity_key' => $entity, 'kind' => $kind, 'value' => $value, 'state' => $state ];
			}
		};
		foreach ( $entities as [ $key, $canonical ] ) {
			$key = Core::as_string( $key );
			if ( isset( $existing[ $key ] ) ) {
				$add_name( $key, 'canonical', Core::as_string( $canonical ), self::norm( Core::as_string( $canonical ) ) );
			}
		}
		foreach ( $r->rows( 'SELECT entity_key, kind, value, norm FROM ' . Intel_Graph_Row_Schema::all( $r, 'names' ) . ' n ORDER BY 1', [] ) as [ $key, $kind, $value, $norm ] ) {
			$add_name( Core::as_string( $key ), Core::as_string( $kind ), Core::as_string( $value ), Core::as_string( $norm ) );
		}
		foreach ( $r->rows( 'SELECT entity_key, kind, value FROM ' . Intel_Graph_Row_Schema::all( $r, 'identifiers' ) . ' i', [] ) as [ $key, $kind, $value ] ) {
			$key   = Core::as_string( $key );
			$kind  = Core::as_string( $kind );
			$value = self::identifier_value( $kind, Core::as_string( $value ) );
			$state = $state_of( $key );
			if ( \in_array( $state, self::LIVE_STATES, true ) ) {
				$index->identifiers[ $kind ][ $value ] = Core::as_string( $survivor( $key ) );
			} elseif ( 'rejected' === $state || null === $survivor( $key ) ) {
				$index->refused[ $kind ][ $value ] = $key;
			}
		}
		return $index;
	}

	/**
	 * A name as matching compares it: lowercase, punctuation to one space,
	 * whitespace collapsed, a leading `the` dropped.
	 *
	 * @param string $s Any text.
	 */
	public static function norm( string $s ): string {
		$s = (string) \preg_replace( '/[^\p{L}\p{M}\p{N}\s]+/u', ' ', \mb_strtolower( $s ) );
		$s = \trim( (string) \preg_replace( '/\s+/u', ' ', $s ) );
		return (string) \preg_replace( '/^the /u', '', $s );
	}

	/**
	 * Each merged key's survivor across every attached partition, '' where
	 * its chain cycles or runs past MAX_MERGE_HOPS; every such chain is
	 * logged once. The one survivor map every reader of the graph shares.
	 *
	 * @param Row_Reader $r The reader `Intel_Graph_Row_Schema::read()` hands over.
	 * @return array<string,string>
	 */
	public static function survivors( Row_Reader $r ): array {
		[ $survivors, $broken ] = self::walk( self::merges( $r ) );
		if ( [] !== $broken ) {
			Core::print_less_often( 'leaving out entities whose merges do not resolve: ', \implode( '; ', \array_keys( $broken ) ) );
		}
		return $survivors;
	}

	/**
	 * Each merged key => the key it merged into, across every attached
	 * partition, a later partition's merge winning, by key.
	 *
	 * @param Row_Reader $r The reader `Intel_Graph_Row_Schema::read()` hands over.
	 * @return array<string,string>
	 */
	public static function merges( Row_Reader $r ): array {
		$merges = [];
		foreach ( $r->rows( 'SELECT entity_key, merged_into FROM ' . Intel_Graph_Row_Schema::all( $r, 'entity_merges' ) . ' m', [] ) as [ $key, $into ] ) {
			$merges[ Core::as_string( $key ) ] = Core::as_string( $into );
		}
		\ksort( $merges );
		return $merges;
	}

	/**
	 * Each merged key's survivor, '' where its chain cycles or runs past
	 * MAX_MERGE_HOPS, and each such chain, described: the one walker every
	 * survivor is read by.
	 *
	 * @param array<string,string> $merges Each merged key => the key it merged into.
	 * @return array{0:array<string,string>,1:array<string,true>}
	 */
	public static function walk( array $merges ): array {
		$survivors = [];
		$broken    = [];
		foreach ( \array_keys( $merges ) as $key ) {
			$survivors[ $key ] = self::survivor( $key, $merges, $broken ) ?? '';
		}
		return [ $survivors, $broken ];
	}

	/**
	 * The key an entity answers as: itself, or the end of its merge chain;
	 * none when the chain cycles or runs past MAX_MERGE_HOPS, which it
	 * records in `$broken`.
	 *
	 * @param string               $key    The entity key.
	 * @param array<string,string> $merges Each merged key => the key it merged into.
	 * @param array<string,true>   $broken Each unresolved chain, described.
	 */
	private static function survivor( string $key, array $merges, array &$broken ): ?string {
		$chain = [ $key ];
		while ( isset( $merges[ $key ] ) ) {
			$key = $merges[ $key ];
			if ( \in_array( $key, $chain, true ) ) {
				$broken[ 'entity merge cycle ' . \implode( ' → ', [ ...$chain, $key ] ) ] = true;
				return null;
			}
			$chain[] = $key;
			if ( \count( $chain ) > self::MAX_MERGE_HOPS + 1 ) {
				$broken[ "entity merge chain from {$chain[0]} past " . self::MAX_MERGE_HOPS . ' hops' ] = true;
				return null;
			}
		}
		return $key;
	}

	/**
	 * Whether a projector's reader of the ontology stream, the one
	 * Stream_Writer appends to, has not projected all of it yet.
	 */
	private static function ontology_behind(): bool {
		return Projector_Node::behind( Stream_Writer::stream_dir( Intel_Event::ONTOLOGY ) );
	}

	/**
	 * The entity an identifier names.
	 *
	 * @param string $kind  An identifier kind.
	 * @param string $value Its value, in any case.
	 */
	public function by_identifier( string $kind, string $value ): ?string {
		return $this->identifiers[ $kind ][ self::identifier_value( $kind, $value ) ] ?? null;
	}

	/**
	 * The key holding an identifier the index leaves out because its
	 * survivor is rejected, or its merge chain cycles or runs too long.
	 *
	 * @param string $kind  An identifier kind.
	 * @param string $value Its value, in any case.
	 */
	public function held_by_refused( string $kind, string $value ): ?string {
		return $this->refused[ $kind ][ self::identifier_value( $kind, $value ) ] ?? null;
	}

	/**
	 * An identifier value as it is compared: lowercase and trimmed, a domain
	 * without its `www.`.
	 *
	 * @param string $kind  The identifier kind.
	 * @param string $value The value.
	 */
	public static function identifier_value( string $kind, string $value ): string {
		$value = \strtolower( \trim( $value ) );
		return 'domain' === $kind ? Url_Canon::host( $value ) : $value;
	}

	/**
	 * Why a domain, as `identifier_value()` spells it, is not a plain
	 * hostname, or null when it is one: two or more labels of a-z, 0-9 and
	 * hyphens, none starting or ending with one, each at most 63
	 * characters, the last alphabetic, the whole at most 253. So no IP
	 * literal, port, path, userinfo or trailing dot passes.
	 *
	 * @param string $domain A normalised domain.
	 */
	public static function hostname_fault( string $domain ): ?string {
		$labels = \explode( '.', $domain );
		return match ( true ) {
			\strlen( $domain ) > self::HOSTNAME_BYTES => 'it runs past ' . self::HOSTNAME_BYTES . ' characters',
			1 === \preg_match( '/[^a-z0-9.-]/', $domain ) => 'it holds a character other than a-z, 0-9, a hyphen or a dot',
			\count( $labels ) < 2 => 'it has a single label',
			\in_array( '', $labels, true ) => 'it has an empty label',
			\max( \array_map( 'strlen', $labels ) ) > self::LABEL_BYTES => 'a label runs past ' . self::LABEL_BYTES . ' characters',
			1 === \preg_match( '/(^|\.)-|-(\.|$)/', $domain ) => 'a label starts or ends with a hyphen',
			1 !== \preg_match( '/^[a-z]+$/', \end( $labels ) ) => 'its top-level domain is not alphabetic',
			default => null,
		};
	}

	/**
	 * Every name the text spells as whole words, in one pass: each run of up
	 * to MAX_NAME_WORDS words is looked up by its norm. Each hit carries the
	 * byte offset its surface starts at, and overlapping hits all come back,
	 * for the caller to weigh. An acronym answers only the case it is
	 * written in. Hits come in text order, the longer of two at one word
	 * first.
	 *
	 * @param string $text Any text.
	 * @return list<array{entity_key:string,kind:string,surface:string,state:string,at:int}>
	 */
	public function names_in( string $text ): array {
		if ( [] === $this->names || 0 === \preg_match_all( '/[\p{L}\p{M}\p{N}]+/u', $text, $m, \PREG_OFFSET_CAPTURE ) ) {
			return [];
		}
		$words = $m[0];
		$lower = \array_map( static fn ( array $word ): string => \mb_strtolower( $word[0] ), $words );
		$found = [];
		foreach ( $words as $i => [ , $start ] ) {
			$run  = '';
			$ends = [];
			for ( $j = $i; $j < $i + self::MAX_NAME_WORDS && isset( $words[ $j ] ); $j++ ) {
				$run .= ( $j === $i ? '' : ' ' ) . $lower[ $j ];
				$norm = \str_starts_with( $run, 'the ' ) ? \substr( $run, 4 ) : $run;
				if ( ! isset( $this->names[ $norm ] ) ) {
					continue;
				}
				$surface = \substr( $text, $start, $words[ $j ][1] + \strlen( $words[ $j ][0] ) - $start );
				$hits    = [];
				foreach ( $this->names[ $norm ] as $name ) {
					if ( 'acronym' !== $name['kind'] || $surface === $name['value'] ) {
						$hits[ "{$name['entity_key']}|{$name['kind']}" ] = [ 'entity_key' => $name['entity_key'], 'kind' => $name['kind'], 'surface' => $surface, 'state' => $name['state'], 'at' => $start ];
					}
				}
				\array_unshift( $ends, \array_values( $hits ) );
			}
			\array_push( $found, ...\array_merge( ...$ends ) );
		}
		return $found;
	}

	/**
	 * Each identifier of a kind the index holds: value => the entity it
	 * names. A value of digits alone is an integer key.
	 *
	 * @param string $kind An identifier kind.
	 * @return array<array-key,string>
	 */
	public function identifiers( string $kind ): array {
		return $this->identifiers[ $kind ] ?? [];
	}

	/**
	 * Each identifier of a kind the index leaves out: value => the key
	 * holding it, whose survivor is rejected or unresolvable.
	 *
	 * @param string $kind An identifier kind.
	 * @return array<array-key,string>
	 */
	public function refused( string $kind ): array {
		return $this->refused[ $kind ] ?? [];
	}

	/**
	 * Every matchable name's norm with the entities it names, localities
	 * excluded, for the fuzzy step.
	 *
	 * @return array<string,list<string>>
	 */
	public function name_norms(): array {
		$norms = [];
		foreach ( $this->names as $norm => $names ) {
			foreach ( $names as $name ) {
				if ( 'locality' !== $name['kind'] ) {
					$norms[ $norm ][ $name['entity_key'] ] = $name['entity_key'];
				}
			}
		}
		return \array_map( 'array_values', $norms );
	}

	/**
	 * Each live topic's canonical name => its key, by name, for extraction.
	 *
	 * @return array<string,string>
	 */
	public function topics(): array {
		return $this->topics;
	}

	/** Whether it holds any live entity: one accepted or proposed, not merged away. */
	public function has_live_entities(): bool {
		return [] !== $this->states;
	}

	/**
	 * Whether a projector's reader of the ontology stream had not committed
	 * the stream's end just before this index was read, so the index may
	 * lack an ontology event; false for one current() did not read.
	 */
	public function behind_ontology(): bool {
		return $this->behind;
	}

	/**
	 * An indexed entity's state, accepted or proposed; none for any other key.
	 *
	 * @param string $entity_key The entity key.
	 */
	public function state( string $entity_key ): ?string {
		return $this->states[ $entity_key ] ?? null;
	}

	/**
	 * Each merged key's survivor as the index read it, '' where its chain
	 * cycles or runs past MAX_MERGE_HOPS.
	 *
	 * @return array<string,string>
	 */
	public function merged(): array {
		return $this->merged;
	}

	/**
	 * Whether a rejected entity carries this name.
	 *
	 * @param string $norm A name's norm.
	 */
	public function is_rejected_name( string $norm ): bool {
		return isset( $this->rejected[ $norm ] );
	}
}

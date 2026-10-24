<?php
/**
 * Entity_Index: the entities resolution matches against, read once from the
 * projection. It holds the accepted and proposed entities, each name by its
 * `norm()` and each identifier by `kind:value`; a merged entity's names and
 * identifiers answer its survivor's key, and a rejected entity's names are
 * kept only to refuse proposing them again. An entity exists once it has
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

	/** Seconds the process uses one index before it reads the projection again. */
	public const TTL_S = 60;

	/** The most words a name can span and still be found in text. */
	public const MAX_NAME_WORDS = 6;

	/** The longest merge chain followed to its survivor. */
	private const MAX_MERGE_HOPS = 10;

	/** The states an entity is indexed in. */
	private const LIVE_STATES = [ 'accepted', 'proposed' ];

	/**
	 * Each matchable name by its norm: `entity|kind|value` => the name.
	 *
	 * @var array<string,array<string,array{entity_key:string,kind:string,value:string,state:string}>>
	 */
	private array $names = [];

	/** @var array<string,string> `kind:value` => entity key. */
	private array $identifiers = [];

	/** @var list<array{entity_key:string,domain:string}> Each domain identifier. */
	private array $domains = [];

	/** @var array<string,true> The norm of every rejected entity's names. */
	private array $rejected = [];

	/** @var array<string,string> Each indexed entity's state. */
	private array $states = [];

	/** @var array<string,string> Each live topic's canonical name => its key. */
	private array $topics = [];

	/** The index every reader in this process shares. */
	private static ?self $current = null;

	/** When the shared index was read, on the tick clock. */
	private static float $read_at = 0.0;

	/**
	 * The index this process shares, read again once TTL_S has passed or
	 * when it was read before `$fresh_after`, the tick by which the
	 * projection holds what a caller needs; an empty one, logged once, while
	 * no `intel-graph` table can be read.
	 *
	 * @param float $fresh_after The earliest read the caller accepts.
	 */
	public static function current( float $fresh_after = 0.0 ): self {
		if ( null === self::$current || self::$read_at < $fresh_after || Core::$now - self::$read_at >= self::TTL_S ) {
			self::$current = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): self => self::load( $r ), 'no intel-graph to read entities from; reading none: ' ) ?? new self();
			self::$read_at = Core::$now;
		}
		return self::$current;
	}

	/**
	 * Read the index from the projection: every attached partition's
	 * entities, states, merges, names and identifiers. An entity whose merge
	 * chain cycles or runs past MAX_MERGE_HOPS answers no survivor, so its
	 * names and identifiers are left out, logged once naming each chain.
	 *
	 * @param Row_Reader $r The reader `Intel_Graph_Row_Schema::read()` hands over.
	 */
	public static function load( Row_Reader $r ): self {
		$rows   = static function ( string $columns, string $table ) use ( $r ): array {
			$all = [];
			foreach ( $r->aliases() as $alias ) {
				\array_push( $all, ...$r->rows( "SELECT {$columns} FROM {$alias}.{$table} ORDER BY 1", [] ) );
			}
			return $all;
		};
		$states = [];
		foreach ( $rows( 'entity_key, state', 'entity_states' ) as [ $key, $state ] ) {
			$states[ Core::as_string( $key ) ] = Core::as_string( $state );
		}
		$merges = [];
		foreach ( $rows( 'entity_key, merged_into', 'entity_merges' ) as [ $key, $into ] ) {
			$merges[ Core::as_string( $key ) ] = Core::as_string( $into );
		}
		$existing = [];
		$named    = [];
		$topics   = [];
		foreach ( $rows( 'entity_key, name, type', 'entities' ) as [ $key, $name, $type ] ) {
			$key  = Core::as_string( $key );
			$name = Core::as_string( $name );
			if ( ! isset( $states[ $key ] ) ) {
				continue;
			}
			$existing[ $key ] = $states[ $key ];
			if ( 'topic' === $type ) {
				$topics[ $key ] = $name;
				continue;
			}
			$named[] = [ $key, 'canonical', $name, self::norm( $name ) ];
		}
		$index    = new self();
		$broken   = [];
		$answers  = [];
		$survivor = static function ( string $key ) use ( $merges, &$broken, &$answers ): ?string {
			return \array_key_exists( $key, $answers ) ? $answers[ $key ] : $answers[ $key ] = self::survivor( $key, $merges, $broken );
		};
		$state_of = static fn ( string $key ): ?string => $existing[ $survivor( $key ) ?? '' ] ?? null;
		foreach ( $existing as $key => $state ) {
			if ( ! isset( $merges[ $key ] ) && \in_array( $state, self::LIVE_STATES, true ) ) {
				$index->states[ $key ] = $state;
				if ( isset( $topics[ $key ] ) ) {
					$index->topics[ $topics[ $key ] ] = $key;
				}
			}
		}
		\ksort( $index->topics );
		foreach ( [ ...$named, ...$rows( 'entity_key, kind, value, norm', 'names' ) ] as [ $key, $kind, $value, $norm ] ) {
			$key = Core::as_string( $key );
			if ( isset( $topics[ $key ] ) ) {
				continue;
			}
			$value = Core::as_string( $value );
			$state = $state_of( $key );
			$norm  = Core::as_string( $norm );
			if ( 'rejected' === $state ) {
				$index->rejected[ $norm ] = true;
			} elseif ( \in_array( $state, self::LIVE_STATES, true ) ) {
				$entity = Core::as_string( $survivor( $key ) );
				$kind   = Core::as_string( $kind );
				$index->names[ $norm ][ "{$entity}|{$kind}|{$value}" ] = [ 'entity_key' => $entity, 'kind' => $kind, 'value' => $value, 'state' => $state ];
			}
		}
		foreach ( $rows( 'entity_key, kind, value', 'identifiers' ) as [ $key, $kind, $value ] ) {
			$key = Core::as_string( $key );
			if ( ! \in_array( $state_of( $key ), self::LIVE_STATES, true ) ) {
				continue;
			}
			$entity = Core::as_string( $survivor( $key ) );
			$kind   = Core::as_string( $kind );
			$value  = self::identifier_value( $kind, Core::as_string( $value ) );
			$index->identifiers[ "{$kind}:{$value}" ] = $entity;
			if ( 'domain' === $kind ) {
				$index->domains[] = [ 'entity_key' => $entity, 'domain' => $value ];
			}
		}
		if ( [] !== $broken ) {
			Core::print_less_often( 'leaving out entities whose merges do not resolve: ', \implode( '; ', \array_keys( $broken ) ) );
		}
		return $index;
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
	 * The entity an identifier names.
	 *
	 * @param string $kind  An identifier kind.
	 * @param string $value Its value, in any case.
	 */
	public function by_identifier( string $kind, string $value ): ?string {
		return $this->identifiers[ $kind . ':' . self::identifier_value( $kind, $value ) ] ?? null;
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
	 * Each entity's domain identifiers, for the stem step.
	 *
	 * @return list<array{entity_key:string,domain:string}>
	 */
	public function with_domain(): array {
		return $this->domains;
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

	/**
	 * An indexed entity's state, accepted or proposed; none for any other key.
	 *
	 * @param string $entity_key The entity key.
	 */
	public function state( string $entity_key ): ?string {
		return $this->states[ $entity_key ] ?? null;
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

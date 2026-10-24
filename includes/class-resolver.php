<?php
/**
 * Resolver: links one item to the entities it is about, against an
 * Entity_Index, in cost order. (1) Identifiers in the item's URL — its host
 * by suffix walk, a GitHub org or repo, a LinkedIn company, an X handle, a
 * Linear issue's team. (2) Names and aliases as whole words in the title and
 * body, an acronym only in its own case. (3) A domain's stem in the title,
 * behind the guards that keep prose from reading as a masthead. (4) Only when
 * the first three found nothing, the organizations an Entity_Extractor names,
 * fuzzy-matched against every name: asserted at `assert_at`, proposed at
 * `propose_at`, and below that a candidate entity unless one was rejected
 * under that name. The caller hands each item its NER step, or none, which
 * runs the first three alone.
 *
 * Name and stem hits whose spans overlap in one text settle together: the
 * longest span wins, and equal spans naming several entities assert the one
 * whose locality the text also names, else propose each. An entity found in
 * the URL or the title is the item's subject; one found only in the body is
 * a mention. Each entity keeps its strongest link. Pure: it reads the index
 * and the extractor and writes nothing.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Link array{entity_key:string, role:string, method:string, confidence:float, state:string}
 * @phpstan-type Proposal array{type:string, name:string}
 */
final class Resolver {

	/** The most candidate entities one item proposes. */
	public const MAX_PROPOSALS = 3;

	/** An identifier, a name or an alias: the entity itself. */
	private const EXACT = 1.0;

	/** An acronym, a domain stem or a locality-settled name: guarded, so never exact. */
	private const GUARDED = 0.9;

	/** A surface naming two entities that nothing tells apart. */
	private const AMBIGUOUS = 0.5;

	/** Each kind a surface is found by: its link method and its confidence alone. */
	private const STRENGTHS = [
		'canonical'   => [ 'name', self::EXACT ],
		'alias'       => [ 'alias', self::EXACT ],
		'acronym'     => [ 'acronym', self::GUARDED ],
		'domain_stem' => [ 'domain_stem', self::GUARDED ],
	];

	/** Filler labels of second-level registries, dropped along with the TLD. */
	private const SECOND_LEVEL_LABELS = [ 'co', 'com', 'net', 'org', 'ac', 'gov', 'edu' ];

	/** Leading words a title capitalizes anyway, so they cannot vouch for a name. */
	private const ARTICLES = [ 'the', 'a', 'an' ];

	/** Below this length a domain stem ("abq", "kcur") collides with ordinary words. */
	private const MIN_STEM_LENGTH = 6;

	/**
	 * Similarity seam: replaces the one `similar_text()` call the fuzzy step
	 * scores a pair of norms with. Tests reassign it to count comparisons;
	 * null runs the real call.
	 * Signature: `function ( string $a, string $b ): float`, the percent.
	 *
	 * @var (\Closure(string, string): float)|null
	 */
	public static ?\Closure $similar_text = null;

	/**
	 * Each domain's stem with the entity it names, built once per index.
	 *
	 * @var list<array{0:string,1:string}>
	 */
	private array $stems = [];

	/**
	 * Every matchable name's norm with the entities it names, by its length
	 * in bytes, built once per index.
	 *
	 * @var array<int,array<string,list<string>>>
	 */
	private array $norms = [];

	/**
	 * The links found so far for the item being resolved, by entity key.
	 *
	 * @var array<string,Link>
	 */
	private array $links = [];

	/**
	 * Hold the index, and derive each domain's
	 * stem and the name norms the fuzzy step scores against, by length.
	 *
	 * @param Entity_Index $index      The entities it resolves against.
	 * @param float        $assert_at  The fuzzy score a link is asserted at.
	 * @param float        $propose_at The fuzzy score a link is proposed at.
	 */
	public function __construct(
		public readonly Entity_Index $index,
		private float $assert_at = 0.85,
		private float $propose_at = 0.60
	) {
		foreach ( $index->identifiers( 'domain' ) as $domain => $entity_key ) {
			$stem = self::domain_stem( (string) $domain );
			if ( '' !== $stem ) {
				$this->stems[] = [ $entity_key, $stem ];
			}
		}
		foreach ( $index->name_norms() as $norm => $entity_keys ) {
			$this->norms[ \strlen( $norm ) ][ $norm ] = $entity_keys;
		}
	}

	/**
	 * The item's links, each entity's strongest, and its candidate entities.
	 *
	 * @param array<string,mixed>   $item The `item_recorded` fields.
	 * @param Entity_Extractor|null $ner  The NER step; none skips it.
	 * @return array{links: list<Link>, proposals: list<Proposal>}
	 */
	public function resolve( array $item, ?Entity_Extractor $ner ): array {
		$this->links = [];
		$title       = Core::as_string( $item['title'] );
		foreach ( $this->identified( Core::as_string( $item['url'] ) ) as $entity_key ) {
			$this->link( $entity_key, 'subject', 'identifier', self::EXACT, 'asserted' );
		}
		$title_names = $this->index->names_in( $title );
		$body_names  = $this->index->names_in( Core::as_string( $item['body'] ) );
		$localities  = self::localities( [ ...$title_names, ...$body_names ] );
		$this->clustered( [ ...self::named( $title_names ), ...$this->stemmed( $title ) ], $localities, 'subject' );
		$this->clustered( self::named( $body_names ), $localities, 'mention' );
		$proposals = null !== $ner && [] === $this->links ? $this->recognized( $item, $ner ) : [];
		return [
			'links'     => \array_values( $this->links ),
			'proposals' => $proposals,
		];
	}

	/**
	 * The NER step: each subject organization the extractor names, scored
	 * against every name. Asserted at `assert_at` when one entity holds the
	 * best score; proposed at `propose_at`, or when several tie; below that, a
	 * candidate entity, at most MAX_PROPOSALS, never a name a rejected entity
	 * carries.
	 *
	 * @param array<string,mixed> $item The item.
	 * @param Entity_Extractor    $ner  The NER step.
	 * @return list<Proposal>
	 */
	private function recognized( array $item, Entity_Extractor $ner ): array {
		$proposals = [];
		foreach ( $ner->extract( $item )['orgs'] as $org ) {
			$name = Entity_Index::norm( $org );
			if ( '' === $name ) {
				continue;
			}
			[ $best, $winners ] = $this->best_norms( $name );
			$confidence = \round( $best, 4 );
			if ( $best >= $this->propose_at ) {
				$state = $best >= $this->assert_at && 1 === \count( $winners ) ? 'asserted' : 'proposed';
				foreach ( \array_keys( $winners ) as $entity_key ) {
					$this->link( $entity_key, 'subject', 'ner_fuzzy', $confidence, $state );
				}
			} elseif ( \count( $proposals ) < self::MAX_PROPOSALS && ! $this->index->is_rejected_name( $name ) ) {
				$proposals[ $name ] ??= [ 'type' => 'organization', 'name' => $org ];
			}
		}
		return \array_values( $proposals );
	}

	/**
	 * The best score any norm earns against a name, and the entities the
	 * norms earning it name. An exact norm scores 1.0 with no scan; else
	 * only norms whose length can reach `propose_at` are scored, because
	 * similar_text's ratio never passes 2·min(la, lb) / (la + lb).
	 *
	 * @param string $name A name's norm.
	 * @return array{0:float,1:array<string,int>} The score, and the winners as keys.
	 */
	private function best_norms( string $name ): array {
		$length = \strlen( $name );
		$exact  = $this->norms[ $length ][ $name ] ?? null;
		if ( null !== $exact ) {
			return [ 1.0, \array_flip( $exact ) ];
		}
		$best    = 0.0;
		$winners = [];
		foreach ( $this->norms as $other => $norms ) {
			if ( 2 * \min( $length, $other ) / ( $length + $other ) < $this->propose_at ) {
				continue;
			}
			foreach ( $norms as $norm => $entity_keys ) {
				$score = self::similarity( $name, $norm );
				if ( $score > $best ) {
					$best    = $score;
					$winners = [];
				}
				if ( $score === $best && $score > 0.0 ) {
					$winners += \array_flip( $entity_keys );
				}
			}
		}
		return [ $best, $winners ];
	}

	/**
	 * Similarity of two norms in [0, 1]: similar_text's ratio.
	 *
	 * @param string $a A norm.
	 * @param string $b A norm.
	 */
	private static function similarity( string $a, string $b ): float {
		$similar = self::$similar_text ?? static function ( string $a, string $b ): float {
			\similar_text( $a, $b, $percent );
			return $percent;
		};
		return $similar( $a, $b ) / 100.0;
	}

	/**
	 * Each domain stem the title spells, behind its guards: at least
	 * MIN_STEM_LENGTH characters, aligned to word boundaries, every word
	 * capitalized, and no article leading a welded span. Bodies are never
	 * read: they are raw markup, where an href spells a domain.
	 *
	 * @param string $title The item's title.
	 * @return list<array{entity_key:string,kind:string,surface:string,at:int}>
	 */
	private function stemmed( string $title ): array {
		$hits     = [];
		$haystack = [] === $this->stems ? [ '', [] ] : self::squash( $title );
		foreach ( $this->stems as [ $entity_key, $stem ] ) {
			$found = self::stem_hit( $title, $haystack, $stem );
			if ( null !== $found ) {
				$hits[] = [ 'entity_key' => $entity_key, 'kind' => 'domain_stem', 'surface' => $found[0], 'at' => $found[1] ];
			}
		}
		return $hits;
	}

	/**
	 * Find a stem in the text ignoring the spacing prose uses, so
	 * "bayshorereport" matches "Bay Shore Report". The offset map lets a hit
	 * be checked against word boundaries in the original text: without that
	 * check "lantern.com" matches "Lanternese".
	 *
	 * @param string                           $text     The title.
	 * @param array{0:string,1:array<int,int>} $haystack From squash( $text ).
	 * @param string                           $stem     The stem.
	 * @return array{0:string,1:int}|null The matched surface and its byte offset, or none.
	 */
	private static function stem_hit( string $text, array $haystack, string $stem ): ?array {
		[ $squashed, $map ] = $haystack;
		$offset             = 0;
		while ( false !== ( $pos = \mb_strpos( $squashed, $stem, $offset ) ) ) {
			$offset = $pos + 1;
			$start  = $map[ $pos ];
			$end    = $map[ $pos + \mb_strlen( $stem ) - 1 ];
			$before = $start > 0 ? \mb_substr( $text, $start - 1, 1 ) : '';
			$after  = \mb_substr( $text, $end + 1, 1 );
			if ( ! self::is_alnum( $before ) && ! self::is_alnum( $after ) ) {
				$surface = \mb_substr( $text, $start, $end - $start + 1 );
				if ( self::reads_as_name( $surface ) ) {
					return [ $surface, \strlen( \mb_substr( $text, 0, $start ) ) ];
				}
			}
		}
		return null;
	}

	/**
	 * The alphanumeric-only lowercase form of the text, and a map from each
	 * of its codepoint offsets back to the codepoint offset in the text.
	 *
	 * @param string $text The title.
	 * @return array{0:string,1:array<int,int>}
	 */
	private static function squash( string $text ): array {
		$squashed = '';
		$map      = [];
		$chars    = \preg_split( '//u', $text, -1, \PREG_SPLIT_NO_EMPTY );
		foreach ( \is_array( $chars ) ? $chars : [] as $i => $char ) {
			if ( ! self::is_alnum( $char ) ) {
				continue;
			}
			// Lowercasing can yield 2 codepoints (U+0130); index per codepoint.
			$lower     = \mb_strtolower( $char );
			$squashed .= $lower;
			for ( $n = \mb_strlen( $lower ); $n > 0; $n-- ) {
				$map[] = $i;
			}
		}
		return [ $squashed, $map ];
	}

	/**
	 * True when the character is a single letter or digit; '' is not.
	 *
	 * @param string $char One character, or '' at a string's edge.
	 */
	private static function is_alnum( string $char ): bool {
		return '' !== $char && 1 === \preg_match( '/[\p{L}\p{N}]/u', $char );
	}

	/**
	 * The hits that name an entity, its localities left out.
	 *
	 * @param list<array{entity_key:string,kind:string,surface:string,state:string,at:int}> $hits Name hits.
	 * @return list<array{entity_key:string,kind:string,surface:string,state:string,at:int}>
	 */
	private static function named( array $hits ): array {
		return \array_values( \array_filter( $hits, static fn ( array $hit ): bool => 'locality' !== $hit['kind'] ) );
	}

	/**
	 * Settle the name and stem hits one text holds. Hits whose spans overlap
	 * form one cluster, settled once by its longest hits: a shorter hit
	 * inside or across a longer one drops, so one span links one entity
	 * unless equal spans name several. Localities never join a cluster.
	 *
	 * @param list<array{entity_key:string,kind:string,surface:string,at:int}> $hits       The text's hits.
	 * @param array<string,true>                                               $localities The entities whose locality the item names.
	 * @param string                                                           $role       The link role the text gives.
	 */
	private function clustered( array $hits, array $localities, string $role ): void {
		\usort( $hits, static fn ( array $a, array $b ): int => $a['at'] <=> $b['at'] );
		$cluster = [];
		$end     = 0;
		foreach ( $hits as $hit ) {
			if ( [] !== $cluster && $hit['at'] >= $end ) {
				$this->settle( self::longest( $cluster ), $localities, $role );
				$cluster = [];
				$end     = 0;
			}
			$cluster[] = $hit;
			$end       = \max( $end, $hit['at'] + \strlen( $hit['surface'] ) );
		}
		if ( [] !== $cluster ) {
			$this->settle( self::longest( $cluster ), $localities, $role );
		}
	}

	/**
	 * The entities a cluster's longest hits name, each with the kind it was
	 * first found by.
	 *
	 * @param non-empty-list<array{entity_key:string,kind:string,surface:string,at:int}> $cluster Overlapping hits.
	 * @return array<string,string>
	 */
	private static function longest( array $cluster ): array {
		$words = \array_map( static fn ( array $hit ): int => (int) \preg_match_all( '/[\p{L}\p{M}\p{N}]+/u', $hit['surface'] ), $cluster );
		$most  = \max( $words );
		$kinds = [];
		foreach ( $cluster as $n => $hit ) {
			if ( $most === $words[ $n ] ) {
				$kinds[ $hit['entity_key'] ] ??= $hit['kind'];
			}
		}
		return $kinds;
	}

	/**
	 * Link the entities one surface names, whichever step found them: the
	 * only one at its own strength; of several, the one whose locality the
	 * text names, guarded; else each, proposed.
	 *
	 * @param array<string,string> $kinds      Entity key => the kind it was found by.
	 * @param array<string,true>   $localities The entities whose locality the text names.
	 * @param string               $role       The link role.
	 */
	private function settle( array $kinds, array $localities, string $role ): void {
		$settled = 1 === \count( $kinds ) ? $kinds : \array_intersect_key( $kinds, $localities );
		if ( 1 === \count( $settled ) ) {
			$entity_key              = \array_key_first( $settled );
			[ $method, $confidence ] = self::STRENGTHS[ $settled[ $entity_key ] ];
			$this->link( $entity_key, $role, $method, 1 === \count( $kinds ) ? $confidence : self::GUARDED, 'asserted' );
			return;
		}
		foreach ( $kinds as $entity_key => $kind ) {
			$this->link( $entity_key, $role, self::STRENGTHS[ $kind ][0], self::AMBIGUOUS, 'proposed' );
		}
	}

	/**
	 * Record a link, keeping each entity's strongest: asserted before
	 * proposed, then the higher confidence. A link to a proposed entity is
	 * proposed whatever its method earned, so a guess never asserts. Either
	 * link naming the entity as subject makes it the subject.
	 *
	 * @param string $entity_key The entity.
	 * @param string $role       subject or mention.
	 * @param string $method     How it was found.
	 * @param float  $confidence The confidence.
	 * @param string $state      asserted or proposed.
	 */
	private function link( string $entity_key, string $role, string $method, float $confidence, string $state ): void {
		$state = 'proposed' === $this->index->state( $entity_key ) ? 'proposed' : $state;
		$link  = [ 'entity_key' => $entity_key, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'state' => $state ];
		$held  = $this->links[ $entity_key ] ?? null;
		if ( null !== $held ) {
			if ( [ 'asserted' === $held['state'], $held['confidence'] ] >= [ 'asserted' === $state, $confidence ] ) {
				$link = $held;
			}
			$link['role'] = 'subject' === $held['role'] || 'subject' === $role ? 'subject' : 'mention';
		}
		$this->links[ $entity_key ] = $link;
	}

	/**
	 * The entities whose locality the hits name as a name is written:
	 * capitalized, so the prose word "bend" never settles toward Bend.
	 *
	 * @param list<array{entity_key:string,kind:string,surface:string,state:string}> $hits Name hits.
	 * @return array<string,true>
	 */
	private static function localities( array $hits ): array {
		$localities = [];
		foreach ( $hits as $hit ) {
			if ( 'locality' === $hit['kind'] && self::reads_as_name( $hit['surface'] ) ) {
				$localities[ $hit['entity_key'] ] = true;
			}
		}
		return $localities;
	}

	/**
	 * Whether a span reads as a name rather than ordinary prose: every word
	 * capitalized, single words included, and a welded span not opening with
	 * an article, because a title capitalizes its first word regardless.
	 *
	 * @param string $surface The span.
	 */
	private static function reads_as_name( string $surface ): bool {
		$words = \preg_split( '/[^\p{L}\p{N}]+/u', $surface, -1, \PREG_SPLIT_NO_EMPTY );
		if ( ! \is_array( $words ) || [] === $words ) {
			return false;
		}
		foreach ( $words as $word ) {
			if ( 1 !== \preg_match( '/^[\p{Lu}\p{N}]/u', $word ) ) {
				return false;
			}
		}
		return \count( $words ) < 2 || ! \in_array( \mb_strtolower( $words[0] ), self::ARTICLES, true );
	}

	/**
	 * The entities the URL names: its host by suffix walk, most specific
	 * first, then the account or project its path names on a known host.
	 *
	 * @param string $url The item's URL.
	 * @return list<string>
	 */
	private function identified( string $url ): array {
		$host = Url_Canon::host( Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) );
		if ( '' === $host ) {
			return [];
		}
		$found  = [];
		$labels = \explode( '.', $host );
		for ( $at = 0; $at < \count( $labels ) - 1; $at++ ) {
			$entity_key = $this->index->by_identifier( 'domain', \implode( '.', \array_slice( $labels, $at ) ) );
			if ( null !== $entity_key ) {
				$found[] = $entity_key;
				break;
			}
		}
		$path = \array_values( \array_filter( \explode( '/', Core::as_string( \wp_parse_url( $url, \PHP_URL_PATH ) ) ), static fn ( string $part ): bool => '' !== $part ) );
		foreach ( self::path_identifiers( $host, $path ) as [ $kind, $value ] ) {
			$entity_key = $this->index->by_identifier( $kind, $value );
			if ( null !== $entity_key ) {
				$found[] = $entity_key;
			}
		}
		return \array_values( \array_unique( $found ) );
	}

	/**
	 * The identifiers a known host's path spells, as `[ kind, value ]`.
	 *
	 * @param string       $host The host, without `www.`.
	 * @param list<string> $path The path's segments.
	 * @return list<array{0:string,1:string}>
	 */
	private static function path_identifiers( string $host, array $path ): array {
		$first = $path[0] ?? '';
		return match ( true ) {
			'' === $first                                 => [],
			'github.com' === $host                        => isset( $path[1] ) ? [ [ 'github_org', $first ], [ 'github_repo', "{$first}/{$path[1]}" ] ] : [ [ 'github_org', $first ] ],
			'linkedin.com' === $host                      => 'company' === $first && isset( $path[1] ) ? [ [ 'linkedin_company', $path[1] ] ] : [],
			\in_array( $host, [ 'x.com', 'twitter.com' ], true ) => [ [ 'x_handle', \ltrim( $first, '@' ) ] ],
			'linear.app' === $host                        => 'issue' === ( $path[1] ?? '' ) && 1 === \preg_match( '/^([A-Za-z0-9]+)-\d+$/', $path[2] ?? '', $team ) ? [ [ 'linear_team', $team[1] ] ] : [],
			default                                       => [],
		};
	}

	/**
	 * A domain's registrable label, alphanumerics only: "plainsfile.com" ->
	 * "plainsfile", "newsroom.example.co.nz" -> "example"; '' when shorter
	 * than MIN_STEM_LENGTH.
	 *
	 * @param string $domain A domain as the index holds it.
	 */
	private static function domain_stem( string $domain ): string {
		$labels = \explode( '.', $domain );
		\array_pop( $labels );
		if ( \count( $labels ) > 1 && \in_array( \end( $labels ), self::SECOND_LEVEL_LABELS, true ) ) {
			\array_pop( $labels );
		}
		$stem = [] === $labels ? '' : \end( $labels );
		$stem = (string) \preg_replace( '/[^\p{L}\p{N}]+/u', '', \mb_strtolower( $stem ) );
		return \mb_strlen( $stem ) >= self::MIN_STEM_LENGTH ? $stem : '';
	}
}

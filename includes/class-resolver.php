<?php
/**
 * Resolver: links one item to the entities it is about, against an
 * Entity_Index, in cost order. (1) Identifiers in the item's URL — its host
 * by suffix walk, the item's publisher, and a GitHub org or repo, a LinkedIn
 * company, an X handle or a Linear issue's team its path names. (2) Names
 * and aliases as whole words in the title and body, an acronym only in its
 * own case, a name led by "the" only as it spells its words past it. (3) A
 * domain's stem in the title, behind the guards that keep prose from
 * reading as a masthead. (4) Only when the first three found
 * nothing, the organizations and products a model names, fuzzy-matched
 * against every name: asserted at `assert_at`, proposed at `propose_at`,
 * and below that a candidate entity of the type it was named as, unless
 * one was rejected under that name. The caller hands each item the names
 * its NER step answered, owes it the step, or hands none, which runs the
 * first three alone; an owed step the first three leave to run answers
 * nothing, so the caller asks the model and resolves again with its
 * answer. `recognized` says whether that step ran and answered, so a
 * caller judging what it looked for tells a call that failed, or one
 * never made, from one that named nothing.
 *
 * A name, stem or fuzzy match never links an entity by a name a rejected
 * entity of its own type carries, a stem judged by its squashed spelling
 * too; an identifier still links it, and no candidate is proposed under
 * such a name whatever its type. A stoplisted hit still claims its span.
 * Name and stem hits whose spans overlap in one text settle together: the
 * longest span wins, and equal spans naming several entities assert the one
 * whose locality the text also names, else the only accepted one among
 * machine proposals, which link not at all, else propose each. The entity whose
 * domain the host is published the item, a `publisher` link apart from any
 * the text earns, so a site's own story is not about its site unless it
 * names it. An entity its path or the title names is the item's subject; one
 * found only in the body is a mention. Each entity keeps its strongest such
 * link. Every proposed link a name earns, and every candidate, carries as
 * evidence the first sentence of the title or the body, markup stripped,
 * that spells it, cut to `Intel_Event::EVIDENCE_BYTES` around the name; an
 * asserted link and one an identifier found carry none. Pure: it reads the
 * index and writes nothing.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Link array{entity_key:string, role:string, method:string, confidence:float, state:string, evidence?:string}
 * @phpstan-type Proposal array{type:string, name:string, evidence:string}
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

	/** Lowercase words a name may hold between two capitalized ones. */
	private const CONNECTORS = [ 'de', 'in', 'of', 'and', 'the' ];

	/** Articles a title capitalizes anyway, which no domain stem welds on. */
	private const ARTICLES = [ 'a', 'an' ];

	/** Below this length a domain stem ("abq", "kcur") collides with ordinary words. */
	private const MIN_STEM_LENGTH = 6;

	/**
	 * Words that say what kind of publication or organization a name is, not
	 * which one, and the small words between them: two names sharing only
	 * these are not likely the same, so the fuzzy step never pairs them.
	 */
	public const GENERIC_NAME_WORDS = [ 'a', 'an', 'and', 'at', 'by', 'for', 'in', 'of', 'on', 'the', 'association', 'bulletin', 'center', 'chronicle', 'community', 'company', 'courier', 'daily', 'dispatch', 'foundation', 'gazette', 'group', 'herald', 'inc', 'institute', 'journal', 'journalism', 'ledger', 'llc', 'local', 'magazine', 'media', 'monthly', 'network', 'news', 'newspaper', 'nonprofit', 'observer', 'online', 'post', 'press', 'project', 'public', 'reporter', 'review', 'school', 'sentinel', 'society', 'times', 'tribune', 'voice', 'weekly' ];

	/**
	 * A name that reads as code or a file rather than an organization: a
	 * `_`, a backtick, `::` or `()`; one lower-camelCase word (`apiFetch`);
	 * or a bare domain or file name. A word led by a capital passes whatever
	 * capitals it holds, because a real newsroom may be spelled so
	 * (`ProPublica`, `McClatchy`), and a curator rejects an identifier.
	 */
	private const CODE_SHAPED = '/[_`]|::|\(\)|^\p{Ll}[\p{L}\p{N}]*\p{Lu}[\p{L}\p{N}]*$|^[\p{L}\p{N}\/-]+(?:\.[\p{L}\p{N}-]+)*\.\p{L}[\p{L}\p{N}]+$/u';

	/** The characters of a long sentence kept ahead of the name it quotes. */
	private const EVIDENCE_LEAD = 60;

	/** What marks a sentence cut short. */
	private const ELLIPSIS = '…';

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
	 * Each domain's stem with the entity it names and whether the stem is
	 * stoplisted for it, built once per index.
	 *
	 * @var list<array{0:string,1:string,2:bool}>
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
	 * Each distinctive word => the norms holding it with the entities they
	 * name, the only norms the fuzzy step scores, built once per index.
	 *
	 * @var array<string,array<string,list<string>>>
	 */
	private array $by_word = [];

	/**
	 * The links found so far for the item being resolved, by entity key.
	 *
	 * @var array<string,Link>
	 */
	private array $links = [];

	/**
	 * The title and body of the item being resolved.
	 *
	 * @var array{0:string,1:string}
	 */
	private array $texts = [ '', '' ];

	/**
	 * Their sentences, the title's first, split when evidence first asks.
	 *
	 * @var list<string>|null
	 */
	private ?array $sentences = null;

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
				$this->stems[] = [ $entity_key, $stem, $index->stoplisted( $stem, $entity_key ) ];
			}
		}
		foreach ( $index->name_norms() as $norm => $entity_keys ) {
			$this->norms[ \strlen( $norm ) ][ $norm ] = $entity_keys;
			foreach ( self::distinctive( $norm ) as $word ) {
				$this->by_word[ $word ][ $norm ] = $entity_keys;
			}
		}
	}

	/**
	 * The item's links, its publisher's first, then each entity's strongest
	 * the URL's path or the text earns, but a body-only mention of its own
	 * publisher, which is not news about it; its candidate entities; and
	 * whether the NER step ran and answered. A publisher spares the NER step
	 * as any link does; an owed step that nothing spares answers none.
	 *
	 * @param array<string,mixed>                                                                                $item The `item_recorded` fields.
	 * @param array{orgs:list<string>,products:list<string>,people:list<string>,locations:list<string>}|bool $ner  The names the NER step answered; true when the step is owed; false for none.
	 * @return array{links: list<Link>, proposals: list<Proposal>, recognized: bool}|null
	 */
	public function resolve( array $item, array|bool $ner ): ?array {
		$this->links     = [];
		$title           = Core::as_string( $item['title'] );
		$body            = Core::as_string( $item['body'] );
		$this->texts     = [ $title, $body ];
		$this->sentences = null;
		$url             = Core::as_string( $item['url'] );
		$host            = Url_Canon::host( Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) );
		$publisher       = $this->index->by_host( $host );
		$published       = null === $publisher ? [] : [ $this->linked( $publisher, Intel_Event::PUBLISHER, 'identifier', self::EXACT, 'asserted', '' ) ];
		foreach ( $this->identified( $host, $url ) as $entity_key ) {
			$this->link( $entity_key, 'subject', 'identifier', self::EXACT, 'asserted', '' );
		}
		$title_names = $this->index->names_in( $title, true );
		$body_names  = $this->index->names_in( $body, true );
		$localities  = self::localities( [ ...$title_names, ...$body_names ] );
		$this->clustered( [ ...self::named( $title_names ), ...$this->stemmed( $title ) ], $localities, 'subject' );
		$this->clustered( self::named( $body_names ), $localities, 'mention' );
		if ( null !== $publisher && 'mention' === ( $this->links[ $publisher ]['role'] ?? null ) ) {
			// A site naming itself in its own body, as a feed footer does.
			unset( $this->links[ $publisher ] );
		}
		$named = \is_array( $ner ) && [] === $published && [] === $this->links ? $ner : null;
		if ( true === $ner && [] === $published && [] === $this->links ) {
			return null;
		}
		$proposals = null === $named ? [] : $this->recognized( [ 'organization' => $named['orgs'], 'product' => $named['products'] ] );
		return [
			'links'      => [ ...$published, ...\array_values( $this->links ) ],
			'proposals'  => $proposals,
			'recognized' => null !== $named,
		];
	}

	/**
	 * The NER step: each subject organization and product the extractor
	 * names, scored against every name. Asserted at `assert_at` when one
	 * entity holds the best score; proposed at `propose_at`, or when several
	 * tie; below that, a candidate entity of the type it was named as, at
	 * most MAX_PROPOSALS, never a name a rejected entity carries. A name
	 * shaped like code is left out, logged, unless an entity past proposal
	 * holds exactly it, as a lower-camelCase brand is spelled.
	 *
	 * @param array<string,array<int,string>> $named Each entity type => the names the extractor gave it.
	 * @return list<Proposal>
	 */
	private function recognized( array $named ): array {
		$proposals = [];
		foreach ( $named as $type => $names ) {
			foreach ( $names as $given ) {
				$name = Entity_Index::norm( $given );
				if ( '' === $name ) {
					continue;
				}
				if ( 1 === \preg_match( self::CODE_SHAPED, $given ) && ! $this->held_past_proposal( $name ) ) {
					Core::print_less_often( 'NER name left out, shaped like code: ', Core::terminal_safe( $given ) );
					continue;
				}
				[ $best, $winners ] = $this->best_norms( $name );
				$confidence = \round( $best, 4 );
				if ( $best >= $this->propose_at ) {
					$state   = $best >= $this->assert_at && 1 === \count( $winners ) ? 'asserted' : 'proposed';
					$winners = \array_filter( $winners, fn ( string $entity_key ): bool => ! $this->index->stoplisted( $name, $entity_key ), \ARRAY_FILTER_USE_KEY );
					foreach ( \array_keys( $winners ) as $entity_key ) {
						$this->link( $entity_key, 'subject', 'ner_fuzzy', $confidence, $state, $given );
					}
				} elseif ( \count( $proposals ) < self::MAX_PROPOSALS && ! $this->index->is_rejected_name( $name ) ) {
					$proposals[ $name ] ??= [ 'type' => $type, 'name' => $given, 'evidence' => $this->evidence( $given ) ];
				}
			}
		}
		return \array_values( $proposals );
	}

	/**
	 * The best score any norm earns against a name, and the entities the
	 * norms earning it name. An exact norm scores 1.0 with no scan; else
	 * only norms sharing a distinctive word are scored, so a name holding
	 * none cannot match, and of those only norms whose length can reach
	 * `propose_at`, because similar_text's ratio never passes
	 * 2·min(la, lb) / (la + lb).
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
		$sharing = [];
		foreach ( self::distinctive( $name ) as $word ) {
			$sharing += $this->by_word[ $word ] ?? [];
		}
		foreach ( $sharing as $norm => $entity_keys ) {
			$other = \strlen( $norm );
			if ( 2 * \min( $length, $other ) / ( $length + $other ) < $this->propose_at ) {
				continue;
			}
			$score = self::similarity( $name, $norm );
			if ( $score > $best ) {
				$best    = $score;
				$winners = [];
			}
			if ( $score === $best && $score > 0.0 ) {
				$winners += \array_flip( $entity_keys );
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
	 * A norm's words outside GENERIC_NAME_WORDS.
	 *
	 * @param string $norm A name's norm, lowercase words joined by one space.
	 * @return list<string>
	 */
	private static function distinctive( string $norm ): array {
		return \array_values( \array_diff( \array_unique( \explode( ' ', $norm ) ), self::GENERIC_NAME_WORDS, [ '' ] ) );
	}

	/**
	 * Whether an entity past proposal holds exactly this name.
	 *
	 * @param string $name A name's norm.
	 */
	private function held_past_proposal( string $name ): bool {
		foreach ( $this->norms[ \strlen( $name ) ][ $name ] ?? [] as $entity_key ) {
			if ( 'proposed' !== $this->index->state( $entity_key ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Each domain stem the title spells, behind its guards: at least
	 * MIN_STEM_LENGTH characters, aligned to word boundaries, and read as a
	 * name (`reads_as_name()`), so "The Citizen" reads as
	 * thecitizen's stem, whose label spells the article. Bodies are never
	 * read: they are raw markup, where an href spells a domain. A hit is
	 * stoplisted when its squashed stem or its surface's norm is.
	 *
	 * @param string $title The item's title.
	 * @return list<array{entity_key:string,kind:string,surface:string,at:int,stoplisted:bool}>
	 */
	private function stemmed( string $title ): array {
		$hits     = [];
		$haystack = [] === $this->stems ? [ '', [] ] : self::squash( $title );
		foreach ( $this->stems as [ $entity_key, $stem, $stoplisted ] ) {
			$found = self::stem_hit( $title, $haystack, $stem );
			if ( null !== $found ) {
				$stoplisted = $stoplisted || $this->index->stoplisted( Entity_Index::norm( $found[0] ), $entity_key );
				$hits[]     = [ 'entity_key' => $entity_key, 'kind' => 'domain_stem', 'surface' => $found[0], 'at' => $found[1], 'stoplisted' => $stoplisted ];
			}
		}
		return $hits;
	}

	/**
	 * The hits that name an entity, its localities left out.
	 *
	 * @param list<array{entity_key:string,kind:string,surface:string,state:string,at:int,stoplisted:bool}> $hits Name hits.
	 * @return list<array{entity_key:string,kind:string,surface:string,state:string,at:int,stoplisted:bool}>
	 */
	private static function named( array $hits ): array {
		return \array_values( \array_filter( $hits, static fn ( array $hit ): bool => 'locality' !== $hit['kind'] ) );
	}

	/**
	 * Settle the name and stem hits one text holds. Hits whose spans overlap
	 * form one cluster, settled once by its longest hits: a shorter hit
	 * inside or across a longer one drops, so one span links one entity
	 * unless equal spans name several. A stoplisted hit claims its span like
	 * any other, then links nothing. Localities never join a cluster.
	 *
	 * @param list<array{entity_key:string,kind:string,surface:string,at:int,stoplisted:bool}> $hits       The text's hits.
	 * @param array<string,true>                                                               $localities The entities whose locality the item names.
	 * @param string                                                                           $role       The link role the text gives.
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
	 * The entities a cluster's longest hits name, each with the hit it was
	 * first found by, one not stoplisted over one that is.
	 *
	 * @param non-empty-list<array{entity_key:string,kind:string,surface:string,at:int,stoplisted:bool}> $cluster Overlapping hits.
	 * @return array<string,array{entity_key:string,kind:string,surface:string,at:int,stoplisted:bool}>
	 */
	private static function longest( array $cluster ): array {
		$words = \array_map( static fn ( array $hit ): int => (int) \preg_match_all( '/[\p{L}\p{M}\p{N}]+/u', $hit['surface'] ), $cluster );
		$most  = \max( $words );
		$hits  = [];
		foreach ( $cluster as $n => $hit ) {
			$held = $hits[ $hit['entity_key'] ] ?? null;
			if ( $most === $words[ $n ] && ( null === $held || ( $held['stoplisted'] && ! $hit['stoplisted'] ) ) ) {
				$hits[ $hit['entity_key'] ] = $hit;
			}
		}
		return $hits;
	}

	/**
	 * Link the entities one surface names, whichever step found them, the
	 * stoplisted ones aside: the only one at its own strength; of several,
	 * the one whose locality the text names, guarded; else the only accepted
	 * one beside machine proposals, at its own strength; else each, proposed.
	 * A stoplisted co-winner leaves the rest ambiguous, each proposed.
	 *
	 * @param array<string,array{kind:string,surface:string,stoplisted:bool}> $hits       Entity key => the hit it was found by.
	 * @param array<string,true>                                              $localities The entities whose locality the text names.
	 * @param string                                                          $role       The link role.
	 */
	private function settle( array $hits, array $localities, string $role ): void {
		$live    = \array_filter( $hits, static fn ( array $hit ): bool => ! $hit['stoplisted'] );
		$settled = $hits;
		$guarded = false;
		if ( 1 !== \count( $settled ) ) {
			$settled = \array_intersect_key( $hits, $localities );
			$guarded = true;
		}
		if ( 1 !== \count( $settled ) ) {
			$settled = \array_filter( $hits, fn ( string $entity_key ): bool => 'accepted' === $this->index->state( $entity_key ), \ARRAY_FILTER_USE_KEY );
			$guarded = false;
		}
		if ( $live === $hits && 1 === \count( $settled ) ) {
			$entity_key              = \array_key_first( $settled );
			$hit                     = $settled[ $entity_key ];
			[ $method, $confidence ] = self::STRENGTHS[ $hit['kind'] ];
			$this->link( $entity_key, $role, $method, $guarded ? self::GUARDED : $confidence, 'asserted', $hit['surface'] );
			return;
		}
		foreach ( $live as $entity_key => $hit ) {
			$this->link( $entity_key, $role, self::STRENGTHS[ $hit['kind'] ][0], self::AMBIGUOUS, 'proposed', $hit['surface'] );
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
	 * @param string $name       The name it was found by, '' for an identifier.
	 */
	private function link( string $entity_key, string $role, string $method, float $confidence, string $state, string $name ): void {
		$link = $this->linked( $entity_key, $role, $method, $confidence, $state, $name );
		$held = $this->links[ $entity_key ] ?? null;
		if ( null !== $held ) {
			if ( [ 'asserted' === $held['state'], $held['confidence'] ] >= [ 'asserted' === $state, $confidence ] ) {
				$link = $held;
			}
			$link['role'] = 'subject' === $held['role'] || 'subject' === $role ? 'subject' : 'mention';
		}
		$this->links[ $entity_key ] = $link;
	}

	/**
	 * One link, proposed whatever its method earned when its entity is
	 * proposed, so a guess never asserts. A proposed link alone carries
	 * evidence, the sentence its name was found in; an asserted one has none.
	 *
	 * @param string $entity_key The entity.
	 * @param string $role       Its role.
	 * @param string $method     How it was found.
	 * @param float  $confidence The confidence.
	 * @param string $state      asserted or proposed.
	 * @param string $name       The name it was found by, '' for an identifier.
	 * @return Link
	 */
	private function linked( string $entity_key, string $role, string $method, float $confidence, string $state, string $name ): array {
		$state = 'proposed' === $this->index->state( $entity_key ) ? 'proposed' : $state;
		$link  = [ 'entity_key' => $entity_key, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'state' => $state ];
		return 'proposed' === $state ? $link + [ 'evidence' => $this->evidence( $name ) ] : $link;
	}

	/**
	 * The first of the item's sentences spelling a name, both read as
	 * `Entity_Index::plain()` reads text, case aside, cut to EVIDENCE_BYTES
	 * around it; '' when no sentence spells it, or for a name `plain()` reads
	 * as empty.
	 *
	 * @param string $name The name as the text or the extractor gave it.
	 */
	private function evidence( string $name ): string {
		$needle = Entity_Index::plain( $name );
		if ( '' === $needle ) {
			return '';
		}
		$this->sentences ??= [ ...Entity_Index::sentences( $this->texts[0] ), ...Entity_Index::sentences( $this->texts[1] ) ];
		foreach ( $this->sentences as $sentence ) {
			$at = \mb_stripos( $sentence, $needle );
			if ( false !== $at ) {
				return self::excerpt( $sentence, $at );
			}
		}
		return '';
	}

	/**
	 * A sentence whole when its written bytes fit EVIDENCE_BYTES; else cut
	 * on whole characters from EVIDENCE_LEAD before the name, each cut
	 * marked with an ellipsis.
	 *
	 * @param string $sentence The sentence.
	 * @param int    $at       The character the name starts at.
	 */
	private static function excerpt( string $sentence, int $at ): string {
		if ( Graph_Curation::written_bytes( $sentence ) <= Intel_Event::EVIDENCE_BYTES ) {
			return $sentence;
		}
		$from = \max( 0, $at - self::EVIDENCE_LEAD );
		$text = ( 0 === $from ? '' : self::ELLIPSIS ) . \mb_substr( $sentence, $from );
		$cut  = Graph_Curation::cut( $text, Intel_Event::EVIDENCE_BYTES - Graph_Curation::written_bytes( self::ELLIPSIS ) );
		return $cut === $text ? $cut : $cut . self::ELLIPSIS;
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
	 * The entities the URL's path names: the account or project on a known
	 * host.
	 *
	 * @param string $host The item's host, without `www.`.
	 * @param string $url  The item's URL.
	 * @return list<string>
	 */
	private function identified( string $host, string $url ): array {
		$found = [];
		$path  = \array_values( \array_filter( \explode( '/', Core::as_string( \wp_parse_url( $url, \PHP_URL_PATH ) ) ), static fn ( string $part ): bool => '' !== $part ) );
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
	 * Whether a text names a domain's site: as a title's stem match does
	 * (`stem_hit()`), its letters and digits, lowercased, spelling the
	 * domain's registrable label (`domain_stem()`), one at least
	 * MIN_STEM_LENGTH long, on word boundaries, read as a name
	 * (`reads_as_name()`); or through its words' initials
	 * (`initials_spell()`). Only naming takes initials: a site's own feed
	 * title vouches for its domain, while an item title read for a
	 * `domain_stem` link is any prose, whose leading initials spell a short
	 * label by chance.
	 *
	 * @api Feed_Source_Node names a client by a feed title only when it does.
	 * @param string $text   The text.
	 * @param string $domain A domain as the index holds it.
	 */
	public static function names_site( string $text, string $domain ): bool {
		$stem = self::domain_stem( $domain );
		return '' !== $stem && ( null !== self::stem_hit( $text, self::squash( $text ), $stem ) || self::initials_spell( $text, $stem ) );
	}

	/**
	 * Whether a text's words, read in order from the first, each spelling
	 * itself or its first letter, spell the stem exactly, every word up to
	 * the last one used taking part and only later ones left over, those
	 * words read as a name (`reads_as_name()`): "Plover Tern Courier" spells
	 * `ptcourier`.
	 *
	 * @param string $text The text.
	 * @param string $stem A domain's stem.
	 */
	private static function initials_spell( string $text, string $stem ): bool {
		$words   = \preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, \PREG_SPLIT_NO_EMPTY );
		$words   = \is_array( $words ) ? $words : [];
		$length  = \mb_strlen( $stem );
		$reached = [ 0 ];
		foreach ( $words as $n => $word ) {
			$lower = \mb_strtolower( $word );
			$next  = [];
			foreach ( $reached as $at ) {
				foreach ( \array_unique( [ $lower, \mb_substr( $lower, 0, 1 ) ] ) as $piece ) {
					if ( \mb_substr( $stem, $at, \mb_strlen( $piece ) ) === $piece ) {
						$next[ $at + \mb_strlen( $piece ) ] = true;
					}
				}
			}
			if ( isset( $next[ $length ] ) ) {
				return self::reads_as_name( \implode( ' ', \array_slice( $words, 0, $n + 1 ) ) );
			}
			$reached = \array_keys( $next );
			if ( [] === $reached ) {
				return false;
			}
		}
		return false;
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
	 * Whether a span reads as a name rather than ordinary prose: every word
	 * capitalized or a number, but a lowercase connecting word (`de`, `in`,
	 * `of`, `and`, `the`) between two that are, as in "Casa de Campo", and a
	 * span of several words not led by "A" or "An", which a title capitalizes
	 * regardless; "The" may lead one, since a domain may spell it.
	 *
	 * @param string $surface The span.
	 */
	private static function reads_as_name( string $surface ): bool {
		$words = \preg_split( '/[^\p{L}\p{N}]+/u', $surface, -1, \PREG_SPLIT_NO_EMPTY );
		if ( ! \is_array( $words ) || [] === $words ) {
			return false;
		}
		$last = \count( $words ) - 1;
		foreach ( $words as $n => $word ) {
			$inner = 0 !== $n && $last !== $n;
			if ( 1 !== \preg_match( '/^[\p{Lu}\p{N}]/u', $word ) && ! ( $inner && \in_array( $word, self::CONNECTORS, true ) ) ) {
				return false;
			}
		}
		return 0 === $last || ! \in_array( \mb_strtolower( $words[0] ), self::ARTICLES, true );
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

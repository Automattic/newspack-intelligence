<?php
/**
 * Publisher_Matcher: the intake Gate's deterministic hard-match layer.
 *
 * Answers "Is this item about a Newspack client?" using only the cheapest,
 * most-deterministic signals — URL domain, then exact publisher name/alias —
 * against the enriched publisher master store. No model call; this is step 1
 * of the resolution order (hard-match -> cheap LLM NER -> fuzzy DB match). Pure:
 * depends only on a Publisher_Repository and returns a replayable decision
 * record (persistence is a later slice).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Publisher_Matcher {

	/** A guard-approximated substring, so never the 1.0 an exact domain or name earns. */
	private const STEM_CONFIDENCE = 0.9;

	/** Filler labels of second-level registries, dropped along with the TLD. */
	private const SECOND_LEVEL_LABELS = [ 'co', 'com', 'net', 'org', 'ac', 'gov', 'edu' ];

	/** Leading words a title capitalizes anyway, so they cannot vouch for a name. */
	private const ARTICLES = [ 'the', 'a', 'an' ];

	/** Below this length a domain stem ("abq", "kcur") collides with ordinary words. */
	private const MIN_STEM_LENGTH = 6;

	/** Sources attributed structurally upstream, so they bypass the Gate entirely. */
	private const BYPASS_SOURCES = [ 'github', 'linear' ];

	/**
	 * Memoized active-publisher enrichment set (loaded once, reused across items).
	 *
	 * @var array<int,array{atomic_site_id:string,domain_name:string,status:string,publisher_name:string,aliases:string}>|null
	 */
	private ?array $publishers = null;

	public function __construct(
		private Publisher_Repository $repo,
		private string $config_version,
		private ?Entity_Extractor $extractor = null,
		private float $ner_pass_threshold = 0.85,
		private float $ner_ignore_threshold = 0.60
	) {}

	/**
	 * Resolve one normalized item to a gate decision.
	 *
	 * @param array<string,mixed> $item Normalized item {source,id,title,url,body,timestamp}.
	 * @return array{stage:string,item_id:string,decision:string,atomic_site_id:?string,matched_on:?string,confidence:?float,reason:string,config_version:string}
	 */
	public function match( array $item ): array {
		$source = \is_string( $item['source'] ?? null ) ? $item['source'] : '';
		$id     = \is_string( $item['id'] ?? null ) ? $item['id'] : '';

		if ( \in_array( $source, self::BYPASS_SOURCES, true ) ) {
			return $this->decision( $id, 'bypass', null, null, "source {$source} bypasses gate" );
		}

		// 1. Domain (strongest, unique).
		$host = $this->host( \is_string( $item['url'] ?? null ) ? $item['url'] : '' );
		if ( '' !== $host ) {
			foreach ( $this->active_publishers() as $pub ) {
				$domain = $this->normalize_domain( $pub['domain_name'] );
				if ( '' !== $domain && ( $host === $domain || \str_ends_with( $host, '.' . $domain ) ) ) {
					return $this->decision( $id, 'pass', $pub['atomic_site_id'], 'domain', "domain:{$host}->{$domain}", 1.0 );
				}
			}
		}

		// 2. Exact name / alias (whole-word, case-insensitive).
		$title = \is_string( $item['title'] ?? null ) ? $item['title'] : '';
		$body  = \is_string( $item['body'] ?? null ) ? $item['body'] : '';
		$text  = $title . ' ' . $body;

		// Matched publishers keyed by atomic_site_id; value = signal + term.
		$hits = [];
		foreach ( $this->active_publishers() as $pub ) {
			foreach ( $this->candidates( $pub ) as $on => $terms ) {
				foreach ( $terms as $term ) {
					if ( ! $this->contains_word( $text, $term ) ) {
						continue;
					}
					// Name hit beats an alias hit for the same publisher.
					if ( ! isset( $hits[ $pub['atomic_site_id'] ] ) || 'name' === $on ) {
						$hits[ $pub['atomic_site_id'] ] = [ 'on' => $on, 'term' => $term ];
					}
				}
			}
		}

		if ( 1 === \count( $hits ) ) {
			$aid = \array_key_first( $hits );
			$hit = $hits[ $aid ];
			return $this->decision( $id, 'pass', $aid, $hit['on'], "{$hit['on']}:{$hit['term']}", 1.0 );
		}
		if ( \count( $hits ) > 1 ) {
			$ids = \implode( ',', \array_keys( $hits ) );
			return $this->decision( $id, 'hold', null, null, 'ambiguous: ' . \count( $hits ) . " candidates ({$ids})" );
		}

		// 3. Domain stem, title only: bodies are unstripped RSS/Atom markup.
		$stems    = [];
		$haystack = $this->squash( $title );
		foreach ( $this->active_publishers() as $pub ) {
			$stem = $this->domain_stem( $pub['domain_name'] );
			if ( '' === $stem ) {
				continue;
			}
			$surface = $this->stem_hit( $title, $haystack, $stem );
			if ( '' !== $surface ) {
				$stems[ $pub['atomic_site_id'] ] = $surface;
			}
		}
		if ( 1 === \count( $stems ) ) {
			$aid = \array_key_first( $stems );
			return $this->decision( $id, 'pass', $aid, 'domain_stem', "domain_stem:{$stems[ $aid ]}", self::STEM_CONFIDENCE );
		}
		if ( \count( $stems ) > 1 ) {
			$ids = \implode( ',', \array_keys( $stems ) );
			return $this->decision( $id, 'hold', null, null, 'ambiguous stems: ' . \count( $stems ) . " candidates ({$ids})" );
		}

		// 4. Inconclusive: LLM NER + fuzzy match, else hold if no extractor.
		return $this->resolve_via_ner( $id, $item );
	}

	/**
	 * Step 2+3: extract subject orgs, fuzzy-match against active publishers, band the result.
	 *
	 * @param array<string,mixed> $item
	 * @return array{stage:string,item_id:string,decision:string,atomic_site_id:?string,matched_on:?string,confidence:?float,reason:string,config_version:string}
	 */
	private function resolve_via_ner( string $id, array $item ): array {
		if ( null === $this->extractor ) {
			return $this->decision( $id, 'hold', null, null, 'no deterministic signal' );
		}
		$orgs = $this->extractor->extract( $item )['orgs'];
		if ( [] === $orgs ) {
			return $this->decision( $id, 'hold', null, null, 'ner: no entities' );
		}

		$best_score = 0.0;
		$winners    = []; // atomic_site_id => score, for publishers at the running best.
		foreach ( $this->active_publishers() as $pub ) {
			$score = 0.0;
			foreach ( $this->candidates_flat( $pub ) as $cand ) {
				foreach ( $orgs as $org ) {
					$score = \max( $score, $this->similarity( $org, $cand ) );
				}
			}
			if ( $score > $best_score ) {
				$best_score = $score;
				$winners    = [ $pub['atomic_site_id'] => $score ];
			} elseif ( $score === $best_score && $score > 0.0 ) {
				$winners[ $pub['atomic_site_id'] ] = $score;
			}
		}

		$conf = \round( $best_score, 4 );
		if ( $best_score >= $this->ner_pass_threshold ) {
			if ( 1 === \count( $winners ) ) {
				$aid = \array_key_first( $winners );
				return $this->decision( $id, 'pass', $aid, 'ner', "ner:{$conf}", $conf );
			}
			$ids = \implode( ',', \array_keys( $winners ) );
			return $this->decision( $id, 'hold', null, null, "ner: ambiguous ({$ids})", $conf );
		}
		if ( $best_score < $this->ner_ignore_threshold ) {
			return $this->decision( $id, 'ignore', null, null, "ner: no client match ({$conf})", $conf );
		}
		return $this->decision( $id, 'hold', null, null, "ner: low confidence ({$conf})", $conf );
	}

	/** String similarity in [0,1]: normalized-equality short-circuit, else similar_text ratio. */
	private function similarity( string $a, string $b ): float {
		$na = $this->normalize_name( $a );
		$nb = $this->normalize_name( $b );
		if ( '' === $na || '' === $nb ) {
			return 0.0;
		}
		if ( $na === $nb ) {
			return 1.0;
		}
		$percent = 0.0;
		\similar_text( $na, $nb, $percent );
		return $percent / 100.0;
	}

	/** Normalize a name for fuzzy compare: lowercase, strip punctuation, drop leading "the", collapse ws. */
	private function normalize_name( string $s ): string {
		$s = \strtolower( \trim( $s ) );
		$s = (string) \preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $s );
		$s = (string) \preg_replace( '/^the\s+/', '', $s );
		return \trim( (string) \preg_replace( '/\s+/', ' ', $s ) );
	}

	/**
	 * Flat candidate list (publisher_name + aliases) for fuzzy scoring.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,status:string,publisher_name:string,aliases:string} $pub
	 * @return array<int,string>
	 */
	private function candidates_flat( array $pub ): array {
		$c = $this->candidates( $pub );
		return \array_merge( $c['name'], $c['alias'] );
	}

	/**
	 * Name + alias candidates for a publisher, keyed by signal.
	 *
	 * @param array{publisher_name:string,aliases:string} $pub
	 * @return array{name:array<int,string>,alias:array<int,string>}
	 */
	private function candidates( array $pub ): array {
		$name  = \trim( $pub['publisher_name'] );
		$alias = \array_values(
			\array_filter(
				\array_map( 'trim', \explode( '|', $pub['aliases'] ) ),
				static fn ( string $a ): bool => '' !== $a
			)
		);
		return [
			'name'  => '' !== $name ? [ $name ] : [],
			'alias' => $alias,
		];
	}

	/**
	 * Active-only enrichment set, memoized for the life of this matcher.
	 *
	 * @return array<int,array{atomic_site_id:string,domain_name:string,status:string,publisher_name:string,aliases:string}>
	 */
	private function active_publishers(): array {
		if ( null === $this->publishers ) {
			$this->publishers = \array_values(
				\array_filter(
					$this->repo->all_with_enrichment(),
					static fn ( array $p ): bool => 'active' === $p['status']
				)
			);
		}
		return $this->publishers;
	}

	/**
	 * @return array{stage:string,item_id:string,decision:string,atomic_site_id:?string,matched_on:?string,confidence:?float,reason:string,config_version:string}
	 */
	private function decision( string $item_id, string $decision, ?string $atomic_site_id, ?string $matched_on, string $reason, ?float $confidence = null ): array {
		return [
			'stage'          => 'gate',
			'item_id'        => $item_id,
			'decision'       => $decision,
			'atomic_site_id' => $atomic_site_id,
			'matched_on'     => $matched_on,
			'confidence'     => $confidence,
			'reason'         => $reason,
			'config_version' => $this->config_version,
		];
	}

	/** Whole-word (Unicode-aware) case-insensitive containment of $needle in $haystack. */
	private function contains_word( string $haystack, string $needle ): bool {
		if ( '' === $needle ) {
			return false;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . \preg_quote( $needle, '/' ) . '(?![\p{L}\p{N}])/iu';
		return 1 === \preg_match( $pattern, $haystack );
	}

	/**
	 * A match key derived from the stored domain: its registrable label, alphanumerics
	 * only. Gives every imported publisher a text-matchable name without waiting on
	 * enrichment. "wyofile.com" -> "wyofile"; "newsroom.example.co.nz" -> "example".
	 * Short stems collide with ordinary words, so they are dropped.
	 */
	private function domain_stem( string $domain ): string {
		$labels = \explode( '.', $this->normalize_domain( $domain ) );
		\array_pop( $labels );
		// Second-level registries leave a filler label behind.
		if ( \count( $labels ) > 1 && \in_array( \end( $labels ), self::SECOND_LEVEL_LABELS, true ) ) {
			\array_pop( $labels );
		}
		// The registrable label: a subdomain names its group.
		$stem = [] === $labels ? '' : (string) \end( $labels );
		$stem = (string) \preg_replace( '/[^\p{L}\p{N}]+/u', '', \mb_strtolower( $stem ) );
		return \mb_strlen( $stem ) >= self::MIN_STEM_LENGTH ? $stem : '';
	}

	/**
	 * Find a stem in the text ignoring the spacing prose uses, so
	 * "fortworthreport" matches "Fort Worth Report". The offset map exists so a
	 * hit can be checked against word boundaries in the original text: without
	 * that check "sentinel.com" matches "Sentinelese".
	 *
	 * @param array{0:string,1:array<int,int>} $haystack From squash( $text ).
	 * @return string The matched surface text, or '' when absent.
	 */
	private function stem_hit( string $text, array $haystack, string $stem ): string {
		[ $squashed, $map ] = $haystack;

		$offset = 0;
		while ( false !== ( $pos = \mb_strpos( $squashed, $stem, $offset ) ) ) {
			$offset = $pos + 1;
			$start  = $map[ $pos ];
			$end    = $map[ $pos + \mb_strlen( $stem ) - 1 ];

			$before = $start > 0 ? \mb_substr( $text, $start - 1, 1 ) : '';
			$after  = \mb_substr( $text, $end + 1, 1 );
			if ( ! $this->is_alnum( $before ) && ! $this->is_alnum( $after ) ) {
				$surface = \mb_substr( $text, $start, $end - $start + 1 );
				if ( $this->reads_as_name( $surface ) ) {
					return $surface;
				}
			}
		}
		return '';
	}

	/**
	 * The alphanumeric-only form of $text, plus a map from each of its offsets back to the
	 * offset in $text. Publisher-independent, so it is built once per item rather than once
	 * per publisher.
	 *
	 * @return array{0:string,1:array<int,int>}
	 */
	private function squash( string $text ): array {
		$squashed = '';
		$map      = [];
		$chars    = \preg_split( '//u', $text, -1, \PREG_SPLIT_NO_EMPTY );
		foreach ( \is_array( $chars ) ? $chars : [] as $i => $char ) {
			if ( 1 !== \preg_match( '/[\p{L}\p{N}]/u', $char ) ) {
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
	 * Whether a span reads as a publisher name rather than ordinary prose.
	 *
	 * Two rules, both required. Every word must be capitalized, single-word spans
	 * included: a lone lowercase "sentinel" is prose, not a masthead. And a welded
	 * multi-word span must not open with an article, because a title capitalizes
	 * its first word regardless — "The Reader Activation System" is spelled exactly
	 * like a mention of thereader.com. Rejecting it costs that publisher the stem
	 * signal (enrichment still matches it) and buys back a confident misattribution.
	 */
	private function reads_as_name( string $surface ): bool {
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

	/** True when $char is a single letter or digit; '' (string edge) is not. */
	private function is_alnum( string $char ): bool {
		return '' !== $char && 1 === \preg_match( '/[\p{L}\p{N}]/u', $char );
	}

	/** Normalize a stored domain the same way a host is normalized. */
	private function normalize_domain( string $domain ): string {
		return $this->strip_www( \strtolower( \trim( $domain ) ) );
	}

	/** Normalize a URL to its bare host: lowercase, no leading "www.". '' when none. */
	private function host( string $url ): string {
		$host = \wp_parse_url( $url, \PHP_URL_HOST );
		if ( ! \is_string( $host ) || '' === $host ) {
			return '';
		}
		return $this->strip_www( \strtolower( $host ) );
	}

	private function strip_www( string $host ): string {
		return \str_starts_with( $host, 'www.' ) ? \substr( $host, 4 ) : $host;
	}
}

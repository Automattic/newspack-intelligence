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

		// 3. Inconclusive: LLM NER + fuzzy match, else hold if no extractor.
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

	/** Normalize a URL to its bare host: lowercase, no leading "www.". '' when none. */
	private function host( string $url ): string {
		$host = \wp_parse_url( $url, \PHP_URL_HOST );
		if ( ! \is_string( $host ) || '' === $host ) {
			return '';
		}
		return $this->strip_www( \strtolower( $host ) );
	}

	/** Normalize a stored domain the same way a host is normalized. */
	private function normalize_domain( string $domain ): string {
		return $this->strip_www( \strtolower( \trim( $domain ) ) );
	}

	private function strip_www( string $host ): string {
		return \str_starts_with( $host, 'www.' ) ? \substr( $host, 4 ) : $host;
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
}

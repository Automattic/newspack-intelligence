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
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

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
		private string $config_version
	) {}

	/**
	 * Resolve one normalized item to a gate decision.
	 *
	 * @param array<string,mixed> $item Normalized item {source,id,title,url,body,timestamp}.
	 * @return array{stage:string,item_id:string,decision:string,atomic_site_id:?string,matched_on:?string,reason:string,config_version:string}
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
					return $this->decision( $id, 'pass', $pub['atomic_site_id'], 'domain', "domain:{$host}->{$domain}" );
				}
			}
		}

		// 2. Exact name / alias (whole-word, case-insensitive).
		$title = \is_string( $item['title'] ?? null ) ? $item['title'] : '';
		$body  = \is_string( $item['body'] ?? null ) ? $item['body'] : '';
		$text  = $title . ' ' . $body;

		// Distinct matched publishers, keyed by atomic_site_id; each value records the winning signal and term.
		$hits = [];
		foreach ( $this->active_publishers() as $pub ) {
			foreach ( $this->candidates( $pub ) as $on => $terms ) {
				foreach ( $terms as $term ) {
					if ( ! $this->contains_word( $text, $term ) ) {
						continue;
					}
					// Prefer a name hit over an alias hit for the same publisher.
					if ( ! isset( $hits[ $pub['atomic_site_id'] ] ) || 'name' === $on ) {
						$hits[ $pub['atomic_site_id'] ] = [ 'on' => $on, 'term' => $term ];
					}
				}
			}
		}

		if ( 1 === \count( $hits ) ) {
			$aid = \array_key_first( $hits );
			$hit = $hits[ $aid ];
			return $this->decision( $id, 'pass', $aid, $hit['on'], "{$hit['on']}:{$hit['term']}" );
		}
		if ( \count( $hits ) > 1 ) {
			$ids = \implode( ',', \array_keys( $hits ) );
			return $this->decision( $id, 'hold', null, null, 'ambiguous: ' . \count( $hits ) . " candidates ({$ids})" );
		}

		return $this->decision( $id, 'hold', null, null, 'no deterministic signal' );
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
	 * @return array{stage:string,item_id:string,decision:string,atomic_site_id:?string,matched_on:?string,reason:string,config_version:string}
	 */
	private function decision( string $item_id, string $decision, ?string $atomic_site_id, ?string $matched_on, string $reason ): array {
		return [
			'stage'          => 'gate',
			'item_id'        => $item_id,
			'decision'       => $decision,
			'atomic_site_id' => $atomic_site_id,
			'matched_on'     => $matched_on,
			'reason'         => $reason,
			'config_version' => $this->config_version,
		];
	}
}

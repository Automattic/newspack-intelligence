<?php
/**
 * Feed_Source_Node: pulls items from configured RSS 2.0 / Atom feeds, and
 * from each client's own feed, and normalizes them into recorded items.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. Its feeds name hosts no one vets, so WordPress checks each
 * url, a redirect's Location included, as it is sent. The feed sends no
 * credential, so it takes no vault group.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

class Feed_Source_Node extends Source_Node {
	private const ATOM_NS = 'http://www.w3.org/2005/Atom';
	private const DC_NS   = 'http://purl.org/dc/elements/1.1/';

	/** Days old a client feed's item may be and still record; an undated one never does. */
	public const CLIENT_FEED_DAYS = 7;

	/** Days past the fetch a client item may be dated, for a publisher's mislabelled zone or skewed clock. */
	private const CLIENT_FEED_AHEAD_DAYS = 1;

	/**
	 * URL-check seam: replaces the one `wp_http_validate_url()` call every
	 * feed URL and every redirect's Location passes as it is sent, which
	 * resolves the host and refuses a private, loopback or reserved address
	 * and a port other than 80, 443 and 8080. Tests reassign it to refuse
	 * chosen URLs without DNS; null calls WordPress.
	 * Signature: `function ( string $url ): string|false`.
	 *
	 * @var (\Closure(string): (string|false))|null
	 */
	public static ?\Closure $validate_url = null;

	/** @var list<string> Feed URLs the `add_url` verb registered and `remove_url` left, in call order. */
	protected array $urls = [];

	/** Whether a pass also fetches `https://<domain>/feed/` for each open client's domain. */
	protected bool $client_feeds = false;

	/** @var array<string,true> This pass's client feeds, by the url each is queued under. */
	private array $client_keys = [];

	/** Queue every configured feed, then every client's, noting the clients. */
	protected function begin(): void {
		$config            = $this->config();
		$this->client_keys = \array_fill_keys( $config['clients'], true );
		foreach ( [ ...$config['feeds'], ...$config['clients'] ] as $url ) {
			$this->queue( $url );
		}
	}

	/**
	 * A feed body's items; a client's, keyed under its domain, the host of
	 * the url it was queued under, only those in its lane.
	 *
	 * @param string $url  The url the feed was queued under.
	 * @param string $body The body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$domain = isset( $this->client_keys[ $url ] ) ? Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) : '';
		$items  = $this->parse( $body, $domain );
		return '' === $domain ? $items : $this->in_lane( $items, $domain );
	}

	/**
	 * A client's items in its lane: linking to its domain or a subdomain of
	 * it, and dated from CLIENT_FEED_DAYS before the worker's wall clock to
	 * CLIENT_FEED_AHEAD_DAYS after it. `item_ts` is the publisher's own
	 * stamp, so the day ahead allows for its zone or clock; an undated item
	 * never records.
	 *
	 * @param array<int,array<string,mixed>> $items  Normalized items.
	 * @param string                         $domain The client's domain.
	 * @return array<int,array<string,mixed>>
	 */
	private function in_lane( array $items, string $domain ): array {
		$since = Core::$now - self::CLIENT_FEED_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;
		$until = Core::$now + self::CLIENT_FEED_AHEAD_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;
		return \array_filter(
			$items,
			static function ( array $item ) use ( $since, $until, $domain ): bool {
				$host = Entity_Index::identifier_value( 'domain', Core::as_string( \wp_parse_url( Core::as_string( $item['url'] ), \PHP_URL_HOST ) ) );
				return 0 !== $item['item_ts'] && $item['item_ts'] >= $since && $item['item_ts'] <= $until
					&& ( $domain === $host || \str_ends_with( $host, ".{$domain}" ) );
			}
		);
	}

	/**
	 * Parse a feed body, supporting both RSS 2.0 (channel/item) and Atom (entry).
	 * libxml errors are suppressed and a parse failure yields []. A client's
	 * items are keyed `feed:<domain>:<id>`, a configured feed's `feed:<id>`.
	 *
	 * @param string $body   The body.
	 * @param string $domain The client's domain, '' for a configured feed.
	 * @return array<int,array<string,mixed>>
	 */
	private function parse( string $body, string $domain ): array {
		$prev = \libxml_use_internal_errors( true );
		// LIBXML_NONET: untrusted feed body — no SYSTEM ref may fetch a URL.
		$xml = \simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		\libxml_clear_errors();
		\libxml_use_internal_errors( $prev );
		if ( false === $xml ) {
			$this->print_less_often( 'Feed parse failed' );
			return [];
		}
		// Dispatch on document shape: RSS has <channel>; Atom's root is <feed>.
		$prefix = '' === $domain ? '' : "{$domain}:";
		return isset( $xml->channel ) ? $this->parse_rss( $xml, $prefix ) : $this->parse_atom( $xml, $prefix );
	}

	/**
	 * RSS 2.0: items at channel/item with title/link/description/guid/pubDate.
	 *
	 * @param \SimpleXMLElement $xml    The document.
	 * @param string            $prefix What leads each item's id.
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_rss( \SimpleXMLElement $xml, string $prefix ): array {
		$out = [];
		if ( ! isset( $xml->channel ) ) {
			return $out;
		}
		foreach ( $xml->channel->item as $item ) {
			$link = (string) $item->link;
			$guid = (string) $item->guid;
			$id   = '' !== $guid ? $guid : $link;
			if ( '' === $id ) {
				continue;
			}
			// RSS 1.0 / RDF feeds date via <dc:date>, not <pubDate>.
			$when = (string) $item->pubDate;
			if ( '' === $when ) {
				$when = (string) $item->children( self::DC_NS )->date;
			}
			$out[] = $this->normalize_item( 'feed', $prefix . $id, (string) $item->title, $link, (string) $item->description, $when );
		}
		return $out;
	}

	/**
	 * Atom: entries at entry with title, link[href], summary|content, id, updated.
	 *
	 * @param \SimpleXMLElement $xml    The document.
	 * @param string            $prefix What leads each item's id.
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_atom( \SimpleXMLElement $xml, string $prefix ): array {
		$out = [];
		foreach ( $xml->children( self::ATOM_NS )->entry as $entry ) {
			$link = $this->atom_link( $entry );
			$aid  = (string) $entry->id;
			$id   = '' !== $aid ? $aid : $link;
			if ( '' === $id ) {
				continue;
			}
			$summary = (string) $entry->summary;
			$body    = '' !== $summary ? $summary : (string) $entry->content;
			$out[]   = $this->normalize_item( 'feed', $prefix . $id, (string) $entry->title, $link, $body, (string) $entry->updated );
		}
		return $out;
	}

	/**
	 * The canonical href of an Atom entry: the rel="alternate" (or rel-less, since
	 * "alternate" is Atom's default) <link>. Falls back to the first other link only
	 * when no alternate exists, so a leading rel="self"/"edit" doesn't win. '' when none.
	 */
	private function atom_link( \SimpleXMLElement $entry ): string {
		$fallback = '';
		foreach ( $entry->children( self::ATOM_NS )->link as $link ) {
			$href = (string) ( $link->attributes()->href ?? '' );
			if ( '' === $href ) {
				continue;
			}
			$rel = (string) ( $link->attributes()->rel ?? '' );
			if ( '' === $rel || 'alternate' === $rel ) {
				return $href;
			}
			if ( '' === $fallback ) {
				$fallback = $href;
			}
		}
		return $fallback;
	}

	/**
	 * The configured feeds, and with client feeds on, each open client's
	 * feed no configured URL already names.
	 *
	 * @return array{feeds:list<string>,clients:list<string>}
	 */
	protected function config(): array {
		return [
			'feeds'   => $this->urls,
			'clients' => $this->client_feeds ? \array_values( \array_diff( $this->clients(), $this->urls ) ) : [],
		];
	}

	/**
	 * `https://<domain>/feed/` for each domain of every entity holding an
	 * open `client` role, read in one projection read; none, logged, when
	 * no projection opens. A domain that is not a plain hostname
	 * (`Entity_Index::hostname_fault()`), such as one a client CSV import
	 * wrote, is skipped and named.
	 *
	 * @return list<string>
	 */
	private function clients(): array {
		$clients = [];
		foreach ( $this->client_domains() as $domain ) {
			$fault = Entity_Index::hostname_fault( $domain );
			if ( null !== $fault ) {
				$this->print_less_often( 'skipping a client domain that is not a plain hostname: ' . Core::terminal_safe( $domain ) . ': ', $fault );
				continue;
			}
			$clients[] = "https://{$domain}/feed/";
		}
		return $clients;
	}

	/**
	 * Each domain of every entity holding an open `client` role, in one
	 * projection read; none, logged, when no projection opens.
	 *
	 * @return list<string>
	 */
	private function client_domains(): array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ): array {
				$index     = Entity_Index::load( $r );
				$keys      = Graph_Query::inverse( $index->merged() );
				$domains   = $index->identifiers( 'domain' );
				$survivors = [];
				foreach ( $domains as $entity ) {
					$survivors[ $entity ] = $keys[ $entity ] ?? [ $entity ];
				}
				$open    = Graph_Query::open_roles( $r, $survivors, [ 'client' ] );
				$clients = [];
				foreach ( $domains as $domain => $entity ) {
					if ( isset( $open[ $entity ]['client'] ) ) {
						$clients[] = (string) $domain;
					}
				}
				return $clients;
			},
			"{$this->name}: no intel-graph to read client domains from; fetching the configured feeds alone: "
		) ?? [];
	}

	/** Feeds on separate hosts go out together, up to Curl's cap. */
	protected function max_in_flight(): int {
		return Curl_Node::MAX_IN_FLIGHT;
	}

	/** No cap: a pass sends every configured feed and every client's. */
	protected function max_requests(): int {
		return \PHP_INT_MAX;
	}

	/** A redirect's Location is checked before the feed follows it, so Curl follows none. */
	protected function follows_redirects(): bool {
		return false;
	}

	/**
	 * Whether `wp_http_validate_url()` accepts the url; one it refuses is
	 * logged and records nothing.
	 *
	 * @param string $url The url about to be sent.
	 */
	protected function fetchable( string $url ): bool {
		$validate = self::$validate_url ?? static fn ( string $url ): string|false => \wp_http_validate_url( $url );
		if ( false !== $validate( $url ) ) {
			return true;
		}
		$this->print_less_often( 'refusing a feed URL WordPress will not fetch: ' . Core::terminal_safe( $url ) );
		return false;
	}

	/**
	 * The `set_client_feeds` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param bool $on Whether a pass fetches each open client's feed.
	 */
	public function set_client_feeds( bool $on ): void {
		$this->client_feeds = $on;
	}

	/** Emit the base config, one `add_url` line per registered URL, and the client-feeds toggle. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_lists() . $this->dump_toggles();
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches items from the configured RSS 2.0 / Atom feeds, and each open client\'s feed when asked, without blocking, on a TICK request (request_node feed TICK).',
			'Start a pass over the feeds; each body records its new items as it lands. Trigger with `request_node feed TICK`.',
			[
				...self::list_verbs( 'url', 'urls', [ 'name' => 'url', 'type' => 'string', 'required' => true ], 'Register a feed URL to fetch on each pass: <url>.' ),
				[
					'name'        => 'set_client_feeds',
					'description' => 'Also fetch https://<domain>/feed/ for each plain-hostname domain of every entity holding an open client role, keying its items feed:<domain>:<guid> and recording only those linking to that domain or a subdomain, dated within ' . self::CLIENT_FEED_DAYS . ' days before the fetch and one after.',
					'args'        => [
						[ 'name' => 'enabled', 'type' => 'bool', 'required' => false, 'description' => '1, true, yes or on enables; 0, false, no or off disables.' ],
					],
					'toggle'      => 'client_feeds',
				],
			],
			false
		);
	}
}

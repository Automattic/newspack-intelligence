<?php
/**
 * Feed_Source_Node: pulls items from configured RSS 2.0 / Atom feeds, and
 * from each client's own feed, and normalizes them into recorded items.
 *
 * Extends Source_Node, which owns the TICK/TM_REQUEST trigger, the grid and
 * the record path; its `tick()` starts a pass. A pass never blocks: it sends
 * each feed URL WordPress will fetch (`wp_http_validate_url()`), keyed by
 * itself, to the `{name}:curl` sibling, keeping at most
 * Curl_Node::MAX_IN_FLIGHT in flight and sending the next as each answers,
 * follows each redirect itself, checked the same way, and records each
 * body's items as it lands. A client's items keep to its lane: keyed by its
 * domain, linking to its own site, and recent. A due fetch while a pass is
 * in flight starts nothing.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Message;
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
	 * feed URL and every redirect's Location passes before it is sent, which
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

	/** The `{name}:curl` sibling that fetches; null only while the constructor runs. */
	private ?Curl_Node $curl = null;

	/** @var array<string,array{0:string,1:int}> Feeds this pass has yet to send: url => [ the client's domain, '' for a configured feed; redirects followed ]. */
	private array $queue = [];

	/** @var array<string,array{0:string,1:int}> Feeds sent and not yet answered, as the queue holds them. */
	private array $in_flight = [];

	/** True while send() runs; an answer arriving inside it does not re-enter. */
	private bool $sending = false;

	/** Build the Curl sibling, which answers back here through its target. */
	public function __construct() {
		parent::__construct();
		$curl = new Curl_Node();
		$curl->patron( $this );
		$curl->arguments( [] );
		$curl->follow_redirects( false );
		$this->curl = $curl;
		$this->publish_sibling( 'curl', $curl );
	}

	/**
	 * A TICK request is answered; anything else is Curl's answer for a feed.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		if ( ! $this->answer_request( $message ) ) {
			$this->answer( $message );
		}
	}

	/**
	 * Start a pass unless feeds are in flight: queue every feed the config
	 * names, replacing what a throw left queued, and send the first to Curl.
	 *
	 * @return array{fetching:int} How many feeds the pass fetches, 0 when one is still in flight.
	 */
	protected function tick(): array {
		if ( [] !== $this->in_flight ) {
			return [ 'fetching' => 0 ];
		}
		$config      = $this->config();
		$this->queue = \array_fill_keys( $config['feeds'], [ '', 0 ] ) + \array_map( static fn ( string $domain ): array => [ $domain, 0 ], $config['clients'] );
		$fetching    = \count( $this->queue );
		$this->send();
		return [ 'fetching' => $fetching ];
	}

	/**
	 * Take Curl's answer for a feed in flight: a body records its items, a
	 * client's only those in its lane; a redirect's Location goes to the
	 * head of the queue as the same feed, one hop on, unless it would pass
	 * MAX_REDIRECTS; a TM_ERROR is logged and records nothing. The next feed
	 * goes out whatever the record did, so a pass always ends. An answer for
	 * no feed in flight is dropped.
	 *
	 * @param array<int,mixed> $message The body as TM_BYTESTREAM, the Location as TM_RESPONSE, or the TM_ERROR, KEY the url.
	 */
	private function answer( array $message ): void {
		$url = Core::as_string( $message[ Message::KEY ] );
		if ( ! isset( $this->in_flight[ $url ] ) ) {
			$this->drop_message( $message, 'no feed in flight' );
			return;
		}
		[ $domain, $hops ] = $this->in_flight[ $url ];
		unset( $this->in_flight[ $url ] );
		try {
			$type  = Core::as_int( $message[ Message::TYPE ] );
			$value = Core::as_string( $message[ Message::VALUE ] );
			if ( 0 !== ( $type & Message::TM_ERROR ) ) {
				$this->print_less_often( 'Feed fetch failed: ', $value );
			} elseif ( 0 !== ( $type & Message::TM_RESPONSE ) ) {
				$this->redirected( $value, $domain, $hops + 1 );
			} else {
				$items = $this->parse( $value, $domain );
				$this->record( '' === $domain ? $items : $this->in_lane( $items, $domain ) );
			}
		} finally {
			$this->send();
		}
	}

	/**
	 * Queue a redirect's Location first, as the feed it redirects, unless
	 * that would pass MAX_REDIRECTS hops, which is logged.
	 *
	 * @param string $location The absolute Location.
	 * @param string $domain   The client's domain, '' for a configured feed.
	 * @param int    $hops     The hops the Location would make.
	 */
	private function redirected( string $location, string $domain, int $hops ): void {
		if ( $hops > Curl_Node::MAX_REDIRECTS ) {
			$this->print_less_often( 'a feed redirected past ' . Curl_Node::MAX_REDIRECTS . ' hops; recording nothing: ' . Core::terminal_safe( $location ) );
			return;
		}
		$this->queue = [ $location => [ $domain, $hops ] ] + $this->queue;
	}

	/**
	 * Send queued feeds to Curl while fewer than MAX_IN_FLIGHT are in
	 * flight, each only once `wp_http_validate_url()` accepts it; one it
	 * refuses is logged and records nothing. An answer Curl sends
	 * synchronously frees its slot for this same loop; a feed Curl throws on
	 * leaves flight, so the next pass starts.
	 *
	 * @throws \LogicException When no Curl sibling was built.
	 */
	private function send(): void {
		if ( $this->sending ) {
			return;
		}
		$curl          = $this->curl ?? throw new \LogicException( \esc_html( "{$this->name} has no Curl sibling" ) );
		$validate      = self::$validate_url ?? static fn ( string $url ): string|false => \wp_http_validate_url( $url );
		$this->sending = true;
		try {
			while ( [] !== $this->queue && \count( $this->in_flight ) < Curl_Node::MAX_IN_FLIGHT ) {
				$url  = \array_key_first( $this->queue );
				$feed = $this->queue[ $url ];
				unset( $this->queue[ $url ] );
				if ( false === $validate( $url ) ) {
					$this->print_less_often( 'refusing a feed URL WordPress will not fetch: ' . Core::terminal_safe( $url ) );
					continue;
				}
				$this->in_flight[ $url ] = $feed;
				$message                   = Message::new_message();
				$message[ Message::TYPE ]  = Message::TM_BYTESTREAM;
				$message[ Message::FROM ]  = $this->name;
				$message[ Message::KEY ]   = $url;
				$message[ Message::VALUE ] = $url;
				try {
					$curl->fill( $message );
				} catch ( \Throwable $e ) {
					unset( $this->in_flight[ $url ] );
					throw $e;
				}
			}
		} finally {
			$this->sending = false;
		}
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
	 * feed no configured URL already names, by its domain.
	 *
	 * @return array{feeds:list<string>,clients:array<string,string>}
	 */
	protected function config(): array {
		return [
			'feeds'   => $this->urls,
			'clients' => $this->client_feeds ? \array_diff_key( $this->clients(), \array_flip( $this->urls ) ) : [],
		];
	}

	/**
	 * `https://<domain>/feed/` => the domain, for each domain of every
	 * entity holding an open `client` role, read in one projection read;
	 * none, logged, when no projection opens. A domain that is not a plain
	 * hostname (`Entity_Index::hostname_fault()`), such as one a client
	 * CSV import wrote, is skipped and named.
	 *
	 * @return array<string,string>
	 */
	private function clients(): array {
		$clients = [];
		foreach ( $this->client_domains() as $domain ) {
			$fault = Entity_Index::hostname_fault( $domain );
			if ( null !== $fault ) {
				$this->print_less_often( 'skipping a client domain that is not a plain hostname: ' . Core::terminal_safe( $domain ) . ': ', $fault );
				continue;
			}
			$clients[ "https://{$domain}/feed/" ] = $domain;
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

	/**
	 * The `set_client_feeds` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param bool $on Whether a pass fetches each open client's feed.
	 */
	public function set_client_feeds( bool $on ): void {
		$this->client_feeds = $on;
	}

	/**
	 * Name the Curl sibling through `parent::`, and point its answers at
	 * this name.
	 *
	 * @api Used by substrate.
	 */
	protected function set_sibling_names(): void {
		parent::set_sibling_names();
		$this->curl?->target( $this->name );
	}

	/** Emit the base config, one `add_url` line per registered URL, and the client-feeds toggle. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_lists() . $this->dump_toggles();
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches items from the configured RSS 2.0 / Atom feeds, and each open client\'s feed when asked, without blocking, on a TICK request (request_node feed TICK).',
			'Start a pass over the feeds; each body records its new items as it lands. Trigger with `request_node feed TICK`.',
			'{ fetching }',
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
			]
		);
	}
}

<?php
/**
 * Feed_Source_Node: pulls items from configured RSS 2.0 / Atom feeds, and
 * from each client's own feed, and normalizes them into recorded items.
 *
 * It fetches each configured feed, each client's feed, and the feed a
 * client's home page advertises once its own is lost, remembering each client
 * domain's found feed and backoff in one row of its Table. A client still
 * shown by its domain is named once by its own feed's title.
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

	/** The namespace of `content:encoded`, an RSS item's full text. */
	private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

	/** Days old a client feed's item may be and still record; an undated one never does. */
	public const CLIENT_FEED_DAYS = 7;

	/** Days past the fetch a client item may be dated, for a publisher's mislabelled zone or skewed clock. */
	private const CLIENT_FEED_AHEAD_DAYS = 1;

	/** Seconds a feed's connection may take, within Curl's 30 for the whole transfer. */
	public const CONNECT_TIMEOUT = 10;

	/** What sets a feed title's tagline apart: ` | `, ` - `, ` – `, ` — ` or `: `. */
	private const TAGLINE = '/ [|\x{2013}\x{2014}-] |: /u';

	/** Passes a failing client domain sits out at most; each failure in a row doubles them up to it. */
	public const MAX_SKIP_PASSES = 48;

	/** Seconds a backoff counts a pass as while the interval is unset. */
	private const BACKOFF_PERIOD = 1800;

	/** The memory row holding every client domain's found feed and backoff. */
	private const DOMAINS = 'domains';

	/** Seconds the domains row lives past the pass that last wrote it, a week. */
	private const DOMAINS_TTL = 604800;

	/** The types a home page's alternate link names a feed by. */
	private const FEED_TYPES = [ 'application/rss+xml', 'application/atom+xml' ];

	/** Fetches of a client domain running, not passes, that did not resolve its host, past which its feed is dead. */
	public const DEAD_PASSES = 3;

	/** What a client domain's memory holds when nothing is remembered of it. */
	private const BLANK_DOMAIN = [
		'feed'             => '',
		'failures'         => 0,
		'retry_at'         => 0,
		'unresolved'       => 0,
		'unresolved_since' => 0,
	];

	/** @var list<string> Feed URLs the `add_url` command registered and `remove_url` left, in call order. */
	protected array $urls = [];

	/** Whether a pass also fetches `https://<domain>/feed/` for each open client's domain. */
	protected bool $client_feeds = false;

	/** Feeds in flight at once, each on its own host, from 1 to Curl_Node::MAX_IN_FLIGHT. */
	protected int $concurrency = Curl_Node::MAX_IN_FLIGHT;

	/** @var array<array-key,mixed>|null Each client domain's found feed and backoff, read at a pass's first use; null before. */
	private ?array $domains = null;

	/** Whether the domains changed since the row last took them. */
	private bool $dirty = false;

	/** Whether the row was read, so the domains may be written over it. */
	private bool $writable = false;

	/** @var array<string,string> Each client domain whose entity every read shows by a domain => that entity, read each pass. */
	private array $unnamed = [];

	/**
	 * @var array<string,true> Each entity this process named, so a later pass
	 *                         never names it again.
	 */
	private array $named = [];

	/** Write the domains a pass changed, at most once a tick, so a recycle keeps them. */
	protected function fire(): void {
		parent::fire();
		$this->write_domains();
	}

	/**
	 * On a clean stop, also write the domains changed since the last tick.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		parent::shutdown_sweep();
		$this->write_domains();
	}

	/**
	 * Queue every configured feed, then each client's feed, noting its domain
	 * and found feed.
	 */
	protected function begin(): void {
		$this->write_domains();
		$this->domains = null;
		$config        = $this->config();
		foreach ( $config['feeds'] as $url ) {
			$this->queue( $url );
		}
		foreach ( $config['clients'] as $domain => $url ) {
			$this->queue( $url, null, self::feed_of( $domain ), [ 'domain' => $domain, 'feed' => $this->remembered( $domain )['feed'] ] );
		}
	}

	/**
	 * A feed body's items; a client's only those in the lanes its note names,
	 * its unresolved run ended, its home page read for the feed it
	 * advertises, and its feed lost when the body is not a feed or the feed
	 * is off its lanes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$domain = Core::as_string( $this->note()['domain'] ?? '' );
		if ( '' === $domain ) {
			return $this->parse( $url, $body, '' )[0] ?? [];
		}
		$this->resolved( $domain );
		if ( self::home_of( $domain ) === $url ) {
			$this->discovered( $domain, $body );
			return [];
		}
		$lanes           = self::lanes( $domain, $this->found() );
		[ $items, $title ] = $this->parse( $url, $body, $domain ) ?? [ null, '' ];
		if ( null === $items || ! $this->landed( $lanes, $url, $items ) ) {
			$this->lost( $domain );
			return [];
		}
		$this->keep( $domain, [ 'feed' => $this->found() ] );
		$this->entitle( $domain, $title );
		return $this->in_lane( $items, $lanes );
	}

	/**
	 * Name a client every read still shows by a domain after its own feed's
	 * title, whitespace collapsed, as a canonical name, once; the part
	 * before its first TAGLINE separator alone when that part names the
	 * site, a tagline being no part of the name. A title that is blank, past
	 * Graph_Curation::NAME_BYTES, the domain again, or that does not spell
	 * the site (`Resolver::names_site()`) names no one.
	 */
	private function entitle( string $domain, string $title ): void {
		$entity = $this->unnamed[ $domain ] ?? null;
		$title  = \trim( (string) \preg_replace( '/\s+/u', ' ', $title ) );
		$parts  = \preg_split( self::TAGLINE, $title, 2 );
		$lead   = \is_array( $parts ) ? \trim( $parts[0] ) : '';
		$title  = '' !== $lead && Resolver::names_site( $lead, $domain ) ? $lead : $title;
		if ( null === $entity || isset( $this->named[ $entity ] ) || $domain === Entity_Index::identifier_value( 'domain', $title ) || ! Resolver::names_site( $title, $domain ) ) {
			return;
		}
		try {
			$title = Graph_Curation::bounded( 'title', $title, Graph_Curation::NAME_BYTES );
		} catch ( \InvalidArgumentException ) {
			return;
		}
		if ( $this->write( 'name_added', [ 'entity_key' => $entity, 'kind' => 'canonical', 'value' => $title ], $entity ) ) {
			$this->named[ $entity ] = true;
		}
	}

	/**
	 * Whether a client's feed was fetched from its lanes or lists an item
	 * linking into one, failing it otherwise.
	 *
	 * @param list<string>                   $lanes The client's lanes.
	 * @param array<int,array<string,mixed>> $items The feed's items, parsed.
	 */
	private function landed( array $lanes, string $subject, array $items ): bool {
		foreach ( [ $this->hop(), ...\array_column( $items, 'url' ) ] as $url ) {
			if ( self::in_lane_of( $lanes, Core::as_string( $url ) ) ) {
				return true;
			}
		}
		$this->fails( 'a client feed redirected off its domain: ' . Core::terminal_safe( $subject ) . ': ', $this->hop() );
		return false;
	}

	/**
	 * Back a client domain off on an unresolved host, one fetch longer in its
	 * unresolved run; any other failure ends the run, a 4xx also losing its
	 * feed, or backing it off when its home page answered it.
	 */
	protected function failed( string $subject, string $error ): void {
		$domain = Core::as_string( $this->note()['domain'] ?? '' );
		if ( '' === $domain ) {
			return;
		}
		if ( \str_starts_with( $error, 'curl error ' . \CURLE_COULDNT_RESOLVE_HOST . ' ' ) ) {
			$this->backs_off( $domain, $this->unresolved_again( $domain ) );
			return;
		}
		$this->resolved( $domain );
		if ( 1 !== \preg_match( '/^HTTP 4\d\d /', $error ) ) {
			return;
		}
		if ( self::home_of( $domain ) === $subject ) {
			$this->backs_off( $domain );
			return;
		}
		$this->lost( $domain );
	}

	/** End a client domain's unresolved run: its host answered. */
	private function resolved( string $domain ): void {
		$this->keep( $domain, \array_replace( $this->remembered( $domain ), [ 'unresolved' => 0, 'unresolved_since' => 0 ] ) );
	}

	/**
	 * A client domain's unresolved run one fetch longer, since its first fetch.
	 *
	 * @return array{unresolved:int,unresolved_since:int}
	 */
	private function unresolved_again( string $domain ): array {
		$memory = $this->remembered( $domain );
		$run    = Core::as_int( $memory['unresolved'] );
		return [
			'unresolved'       => $run + 1,
			'unresolved_since' => 0 === $run ? (int) Core::$now : Core::as_int( $memory['unresolved_since'] ),
		];
	}

	/**
	 * Ask a client's home page for its feed, or back the domain off when the
	 * feed lost is the one it named.
	 */
	private function lost( string $domain ): void {
		if ( '' !== $this->found() ) {
			$this->backs_off( $domain );
			return;
		}
		$this->queue( self::home_of( $domain ), null, null, [ 'domain' => $domain ] );
	}

	/** The feed the answer being taken's request notes its client's home page advertised; '' for none. */
	private function found(): string {
		return Core::as_string( $this->note()['feed'] ?? '' );
	}

	/**
	 * Fetch and remember the feed a client's home page advertises, or fail and
	 * back the domain off for none, the conventional one, or one refusal()
	 * refuses.
	 */
	private function discovered( string $domain, string $body ): void {
		$feed            = self::advertised( $body, $this->hop() );
		[ $what, $fault ] = '' === $feed || self::feed_of( $domain ) === $feed
			? [ 'no RSS or Atom feed is advertised at ', 'no alternate link names one' ]
			: [ 'refused a feed the home page advertised at ', self::refusal( $feed ) ];
		if ( null !== $fault ) {
			$this->fails( $what . Core::terminal_safe( $this->hop() ) . ': ', $fault );
			$this->backs_off( $domain );
			return;
		}
		$this->keep( $domain, [ 'feed' => $feed ] + $this->remembered( $domain ) );
		$this->queue( $feed, null, self::feed_of( $domain ), [ 'domain' => $domain, 'feed' => $feed ] );
	}

	/**
	 * The first feed a page's alternate links advertise, RSS or Atom,
	 * resolved against $base, the url the page was fetched from; '' for none.
	 */
	private static function advertised( string $html, string $base ): string {
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( [ 'tag_name' => 'link' ] ) ) {
			$rel  = $tags->get_attribute( 'rel' );
			$type = $tags->get_attribute( 'type' );
			$href = $tags->get_attribute( 'href' );
			if ( \is_string( $rel ) && \is_string( $type ) && \is_string( $href ) && '' !== \trim( $href )
				&& \in_array( 'alternate', \preg_split( '/\s+/', \strtolower( $rel ) ) ?: [], true )
				&& \in_array( \strtolower( \trim( $type ) ), self::FEED_TYPES, true ) ) {
				return \WP_Http::make_absolute_url( \trim( $href ), $base );
			}
		}
		return '';
	}

	/** Why a page's url is refused: not http or https free of userinfo, or not on a plain hostname; null for none. */
	private static function refusal( string $url ): ?string {
		try {
			Curl_Node::origin_of( $url );
		} catch ( \UnexpectedValueException $refused ) {
			return $refused->getMessage();
		}
		$host  = self::host( $url );
		$fault = Entity_Index::hostname_fault( $host );
		return null === $fault ? null : "{$host}: {$fault}";
	}

	/**
	 * Back a client domain off and forget its found feed: it sits out 1, 2, 4 …
	 * passes, up to MAX_SKIP_PASSES. Its unresolved run is $run, none unless
	 * its host did not resolve.
	 *
	 * @param array{unresolved:int,unresolved_since:int}|array{} $run The run.
	 */
	private function backs_off( string $domain, array $run = [] ): void {
		$failures = Core::as_int( $this->remembered( $domain )['failures'] ) + 1;
		$period   = $this->interval ?? self::BACKOFF_PERIOD;
		$skip     = (int) \min( 2 ** ( $failures - 1 ), self::MAX_SKIP_PASSES );
		$this->keep(
			$domain,
			[
				'feed'     => '',
				'failures' => $failures,
				'retry_at' => ( \intdiv( (int) Core::$now, $period ) + $skip + 1 ) * $period,
			] + $run
		);
	}

	/** A client domain's home page. */
	private static function home_of( string $domain ): string {
		return "https://{$domain}/";
	}

	/**
	 * Remember a client domain's entry, blank where it leaves a key out,
	 * forgetting one left blank.
	 *
	 * @param array<array-key,mixed> $entry Its feed, failures, retry_at and unresolved run.
	 */
	private function keep( string $domain, array $entry ): void {
		$entry   += self::BLANK_DOMAIN;
		$domains  = $this->domains();
		if ( self::BLANK_DOMAIN === $entry ) {
			unset( $domains[ $domain ] );
		} else {
			$domains[ $domain ] = $entry;
		}
		$this->set_domains( $domains );
	}

	/**
	 * A client's items in its lanes, dated from CLIENT_FEED_DAYS before the
	 * clock to CLIENT_FEED_AHEAD_DAYS after it, the day ahead for the
	 * publisher's zone; an undated item never records.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @param list<string>                   $lanes The client's lanes.
	 * @return array<int,array<string,mixed>>
	 */
	private function in_lane( array $items, array $lanes ): array {
		$since = Core::$now - self::CLIENT_FEED_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;
		$until = Core::$now + self::CLIENT_FEED_AHEAD_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;
		return \array_filter(
			$items,
			static fn ( array $item ): bool => 0 !== $item['item_ts'] && $item['item_ts'] >= $since && $item['item_ts'] <= $until
				&& self::in_lane_of( $lanes, Core::as_string( $item['url'] ) )
		);
	}

	/**
	 * A client's lanes, spelled as domain identifiers: its domain and its found
	 * feed's host.
	 *
	 * @return list<string>
	 */
	private static function lanes( string $domain, string $found ): array {
		return '' === $found ? [ $domain ] : [ $domain, Entity_Index::identifier_value( 'domain', self::host( $found ) ) ];
	}

	/**
	 * Whether a url's host, spelled as a domain identifier, is a lane or a
	 * subdomain of one.
	 *
	 * @param list<string> $lanes The client's lanes.
	 */
	private static function in_lane_of( array $lanes, string $url ): bool {
		$host = Entity_Index::identifier_value( 'domain', self::host( $url ) );
		foreach ( $lanes as $lane ) {
			if ( $lane === $host || \str_ends_with( $host, ".{$lane}" ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A feed body's items, RSS 2.0 or Atom, a client's keyed
	 * `feed:<domain>:<id>`, a configured feed's `feed:<id>` (keyed()), and the feed's
	 * own title; null, failing once under its url, for a body that is
	 * neither.
	 *
	 * @param string $url    The url the feed was queued under.
	 * @param string $body   The body.
	 * @param string $domain The client's domain, '' for a configured feed.
	 * @return array{0:array<int,array<string,mixed>>,1:string}|null
	 */
	private function parse( string $url, string $body, string $domain ): ?array {
		$prev = \libxml_use_internal_errors( true );
		// LIBXML_NONET: untrusted feed body — a SYSTEM ref may not fetch a URL.
		$xml   = \simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET );
		$error = \libxml_get_errors()[0] ?? null;
		\libxml_clear_errors();
		\libxml_use_internal_errors( $prev );
		$rss = false !== $xml && isset( $xml->channel );
		if ( false === $xml || ( ! $rss && 'feed' !== $xml->getName() ) ) {
			$why = false === $xml ? \trim( $error->message ?? 'the body will not parse' ) : "its root element is <{$xml->getName()}>";
			$this->fails( 'no RSS or Atom feed at ' . Core::terminal_safe( $url ) . ': ', $why );
			return null;
		}
		// Dispatch on document shape: RSS has <channel>; Atom's root is <feed>.
		$prefix = '' === $domain ? '' : "{$domain}:";
		return $rss
			? [ $this->parse_rss( $xml, $prefix ), (string) $xml->channel->title ]
			: [ $this->parse_atom( $xml, $prefix ), (string) $xml->children( self::ATOM_NS )->title ];
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
			$full  = (string) $item->children( self::CONTENT_NS )->encoded;
			$out[] = $this->normalize_item( 'feed', self::keyed( $prefix, $id ), self::unsourced( $item ), $link, '' !== \trim( $full ) ? $full : (string) $item->description, $when );
		}
		return $out;
	}

	/**
	 * An RSS item's title less the ` - <source>` an aggregator such as
	 * Google News appends, cut only when the item carries a `<source>` and
	 * its title ends with exactly that, so the copy dedups against the
	 * publisher's own.
	 *
	 * @param \SimpleXMLElement $item The item.
	 */
	private static function unsourced( \SimpleXMLElement $item ): string {
		$title  = \rtrim( (string) $item->title );
		$suffix = ' - ' . \trim( (string) $item->source );
		return isset( $item->source ) && ' - ' !== $suffix && \str_ends_with( $title, $suffix ) ? \substr( $title, 0, -\strlen( $suffix ) ) : (string) $item->title;
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
			$out[]   = $this->normalize_item( 'feed', self::keyed( $prefix, $id ), (string) $entry->title, $link, $body, (string) $entry->updated );
		}
		return $out;
	}

	/**
	 * An Atom entry's alternate (or rel-less) link's href, else its first other
	 * link's, so a leading self or edit link does not win; '' for none.
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
	 * The configured feeds, and with client feeds on each open client's found
	 * or conventional feed by domain, but one configured or sitting this pass
	 * out, forgetting a domain no longer a client.
	 *
	 * @return array{feeds:list<string>,clients:array<string,string>}
	 */
	protected function config(): array {
		$listed = $this->client_feeds ? $this->clients() : null;
		if ( null !== $listed ) {
			$this->set_domains( \array_intersect_key( $this->domains(), \array_flip( $listed ) ) );
		}
		$clients = [];
		foreach ( $listed ?? [] as $domain ) {
			$memory = $this->remembered( $domain );
			$url    = '' === $memory['feed'] ? self::feed_of( $domain ) : Core::as_string( $memory['feed'] );
			if ( Core::$now >= Core::as_int( $memory['retry_at'] ) && ! \in_array( $url, $this->urls, true ) ) {
				$clients[ $domain ] = $url;
			}
		}
		return [
			'feeds'   => $this->urls,
			'clients' => $clients,
		];
	}

	/**
	 * Hold the domains, dirty when they differ from those held.
	 *
	 * @param array<array-key,mixed> $domains Each client domain's entry.
	 */
	private function set_domains( array $domains ): void {
		$this->dirty   = $this->dirty || $domains !== $this->domains();
		$this->domains = $domains;
	}

	/** A client domain's conventional feed. */
	private static function feed_of( string $domain ): string {
		return "https://{$domain}/feed/";
	}

	/**
	 * What is remembered of a client domain, its feed '' and its backoff 0
	 * where nothing is.
	 *
	 * @return array<array-key,mixed>
	 */
	private function remembered( string $domain ): array {
		return Core::arr( $this->domains()[ $domain ] ?? null ) + self::BLANK_DOMAIN;
	}

	/**
	 * client_domains() less any that is not a plain hostname
	 * (`Entity_Index::hostname_fault()`), each named; null when the projection
	 * will not open. Holds the domains whose client is still shown by a domain.
	 *
	 * @return list<string>|null
	 */
	private function clients(): ?array {
		[ $domains, $this->unnamed ] = $this->client_domains() ?? [ null, [] ];
		if ( null === $domains ) {
			return null;
		}
		$clients = [];
		foreach ( $domains as $domain ) {
			$fault = Entity_Index::hostname_fault( $domain );
			if ( null !== $fault ) {
				$this->print_less_often( 'skipping a client domain that is not a plain hostname: ' . Core::terminal_safe( $domain ) . ': ', $fault );
				continue;
			}
			$clients[] = $domain;
		}
		return $clients;
	}

	/**
	 * Each domain of every entity holding an open `client` role, and each
	 * of those whose entity `Graph_Query::name_of()` shows by one of its
	 * domains => that entity, in one projection read; null, logged, when the
	 * projection will not open.
	 *
	 * @return array{0:list<string>,1:array<string,string>}|null
	 */
	private function client_domains(): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ): array {
				$index     = Entity_Index::load( $r );
				$domains   = $index->identifiers( 'domain' );
				$survivors = [];
				foreach ( $domains as $entity ) {
					$survivors[ $entity ] = $index->keys_of( $entity );
				}
				$open    = Graph_Query::open_roles( $r, $survivors, [ 'client' ] );
				$clients = [];
				foreach ( $domains as $domain => $entity ) {
					if ( isset( $open[ $entity ]['client'] ) ) {
						$clients[ (string) $domain ] = $entity;
					}
				}
				$entities = \array_values( \array_unique( $clients ) );
				$shown    = [];
				if ( [] !== $entities ) {
					foreach ( $r->rows( 'SELECT k.entity_key, ' . Graph_Query::name_of( $r, 'k.entity_key' ) . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' k WHERE k.entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $entities ) . ' )', $entities ) as [ $entity, $name ] ) {
						$shown[ Core::as_string( $entity ) ] = Entity_Index::identifier_value( 'domain', Core::as_string( $name ) );
					}
				}
				$unnamed = \array_filter( $clients, static fn ( string $entity ): bool => isset( $shown[ $entity ], $domains[ $shown[ $entity ] ] ) && $entity === $domains[ $shown[ $entity ] ] );
				return [ \array_map( 'strval', \array_keys( $clients ) ), $unnamed ];
			},
			"{$this->name}: no intel-graph to read client domains from; fetching the configured feeds alone: "
		);
	}

	/** Write the domains back as the pass ends. */
	protected function pass_ended(): void {
		$this->write_domains();
	}

	/**
	 * Every status also names each client domain whose host has not resolved
	 * DEAD_PASSES fetches running, by domain, with the start of its run;
	 * none while client feeds are off.
	 *
	 * @return array{dead_clients:array<string,int>}
	 */
	protected function status_facts(): array {
		$dead = [];
		foreach ( $this->client_feeds ? $this->domains() : [] as $domain => $memory ) {
			$memory = Core::arr( $memory ) + self::BLANK_DOMAIN;
			if ( Core::as_int( $memory['unresolved'] ) >= self::DEAD_PASSES ) {
				$dead[ (string) $domain ] = Core::as_int( $memory['unresolved_since'] );
			}
		}
		\ksort( $dead );
		return [ 'dead_clients' => $dead ];
	}

	/**
	 * Every remembered client domain, read from the row the first time a
	 * pass asks; a read the Table does not answer remembers none.
	 *
	 * @return array<array-key,mixed>
	 */
	private function domains(): array {
		if ( null === $this->domains ) {
			$this->domains  = Core::arr( $this->tables->get( $this->sibling_name( self::FRONTIER ), self::DOMAINS, $failed ) );
			$this->writable = ! $failed;
			$this->dirty    = false;
		}
		return $this->domains;
	}

	/** Write the domains over the row when they changed and the row was read, again until an ADD takes them; none left removes it. */
	private function write_domains(): void {
		if ( ! $this->dirty || ! $this->writable ) {
			return;
		}
		$table = $this->sibling_name( self::FRONTIER );
		$this->tables->remove( $table, [ self::DOMAINS ] );
		$this->dirty = [] !== $this->domains && [] === $this->tables->add_multi( $table, [ self::DOMAINS => [ $this->domains, self::DOMAINS_TTL ] ] );
	}

	/**
	 * A client-lane key's guid, past `feed:` and the lane's domain; null for
	 * a configured feed's key, judged by its key alone, since a configured
	 * feed is anyone's and its copy of a client's post must never stand in
	 * for the client's own.
	 *
	 * @param string $key An item key.
	 */
	protected function raw_id( string $key ): ?string {
		$id   = \substr( $key, (int) \strpos( $key, ':' ) + 1 );
		$lane = self::lane( $id );
		return null === $lane ? null : \substr( $id, \strlen( $lane ) + 1 );
	}

	/**
	 * An item's id past `feed:`: a client's lane leads its guid, and a
	 * configured feed's guid that would read as a lane is led by `:`, so no
	 * configured item takes a client's key or reads as its copy. A key that
	 * would pass Graph_Curation::ITEM_KEY_BYTES takes the guid's sha256 in
	 * its place, in its lane, so one guid always keys alike within the cap.
	 *
	 * @param string $prefix The lane's domain and `:`, or '' for a configured feed.
	 * @param string $id     The guid, else the link.
	 */
	private static function keyed( string $prefix, string $id ): string {
		$key = '' === $prefix && null !== self::lane( $id ) ? ":{$id}" : $prefix . $id;
		return Graph_Curation::written_bytes( "feed:{$key}" ) > Graph_Curation::ITEM_KEY_BYTES ? $prefix . \hash( 'sha256', $id ) : $key;
	}

	/**
	 * The client's domain whose lane an item key reads as,
	 * `feed:<domain>:<guid>`; null for a configured feed's `feed:<guid>`.
	 *
	 * @param string $item_key An item key.
	 */
	public static function lane_of( string $item_key ): ?string {
		return \str_starts_with( $item_key, 'feed:' ) ? self::lane( \substr( $item_key, \strlen( 'feed:' ) ) ) : null;
	}

	/**
	 * The plain hostname leading an id before its first `:`, which is how a
	 * client's lane is spelled; null when the id does not lead with one.
	 *
	 * @param string $id An id past `feed:`.
	 */
	private static function lane( string $id ): ?string {
		$lane = \strstr( $id, ':', true );
		return false === $lane || null !== Entity_Index::hostname_fault( $lane ) ? null : $lane;
	}

	/**
	 * Every feed request bounds its connection at CONNECT_TIMEOUT, so a host
	 * that never answers holds its slot a third as long.
	 *
	 * @param string $subject The request's subject.
	 * @return array<string,mixed>
	 */
	protected function request_options( string $subject ): array {
		return [ 'connect_timeout' => self::CONNECT_TIMEOUT ];
	}

	/** Feeds on separate hosts go out together, `concurrency` at a time. */
	protected function max_in_flight(): int {
		return $this->concurrency;
	}

	/** No cap: a pass sends every configured feed and every client's feed. */
	protected function max_requests(): int {
		return \PHP_INT_MAX;
	}

	/** Curl follows no redirect: the feed re-queues each Location under the feed it redirects, keeping its lane. */
	protected function follows_redirects(): bool {
		return false;
	}

	/**
	 * The `set_concurrency` command's entry point.
	 *
	 * @api Used by substrate.
	 * @throws \InvalidArgumentException Outside 1 to Curl_Node::MAX_IN_FLIGHT.
	 */
	public function set_concurrency( string $count ): void {
		$this->concurrency = Curl_Node::concurrency_of( (int) $count );
	}

	/**
	 * The `set_client_feeds` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param bool $on Whether a pass fetches each open client's feed.
	 */
	public function set_client_feeds( bool $on ): void {
		$this->client_feeds = $on;
	}

	/** Emit the base config, one `add_url` line per registered URL, and the client-feeds toggle. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_toggles();
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches items from the configured RSS 2.0 / Atom feeds, and each open client\'s feed when asked, without blocking, on a TICK request (request_node feed TICK).',
			'Start a pass over the feeds; each body records its new items as it lands. Trigger with `request_node feed TICK`.',
			[
				...self::list_commands( 'url', 'urls', [ 'name' => 'url', 'type' => 'string', 'required' => true ], 'Register a feed URL to fetch on each pass: <url>.' ),
				[
					'name'        => 'set_client_feeds',
					'description' => 'Also fetch https://<domain>/feed/ for each plain-hostname domain of every entity holding an open client role, keying its items feed:<domain>:<guid> and recording only those linking to that domain or a subdomain, dated within ' . self::CLIENT_FEED_DAYS . ' days before the fetch and one after.',
					'args'        => [
						[ 'name' => 'enabled', 'type' => 'bool', 'required' => false, 'description' => '1, true, yes or on enables; 0, false, no or off disables.' ],
					],
					'toggle'      => 'client_feeds',
				],
				[
					'name'        => 'set_concurrency',
					'description' => 'Fetch at most <count> feeds at once, each on its own host, from 1 to ' . Curl_Node::MAX_IN_FLIGHT . ', the default.',
					'args'        => [
						[ 'name' => 'count', 'type' => 'int', 'required' => true ],
					],
					'setter'      => 'concurrency',
				],
			],
			false
		);
	}
}

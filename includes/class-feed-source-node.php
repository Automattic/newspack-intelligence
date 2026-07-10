<?php
/**
 * Feed_Source_Node: pulls items from configured RSS 2.0 / Atom feeds and
 * normalizes them into digest items.
 *
 * Extends Source_Node, so the base owns the TICK/TM_REQUEST trigger, dedup, and
 * snapshot; this class supplies only the two seams: config() (Settings read) and
 * fetch() (the blocking feed GETs + XML parsing, behind the $http_get closure
 * seam).
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Command_Interpreter_Node;

\defined( 'ABSPATH' ) || exit;

class Feed_Source_Node extends Source_Node {
	private const ATOM_NS    = 'http://www.w3.org/2005/Atom';
	private const DC_NS      = 'http://purl.org/dc/elements/1.1/';

	private const USER_AGENT = 'newspack-ai-newsletter';

	/**
	 * wp_remote_get call seam. Null by default; the call site then invokes the real
	 * `wp_remote_get`. Tests reassign it (and reset to null in tearDown) to return a
	 * canned feed body WITHOUT short-circuiting the WP_Error / non-200 branches or
	 * the XML parse + normalization — so all of that runs as real, covered code.
	 *
	 * Signature: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var (\Closure( string, array<string,mixed> ): (array<string,mixed>|\WP_Error))|null
	 */
	public static ?\Closure $http_get = null;

	/** @var array<int,string> Feed URLs registered via the `add_url` verb, in call order. */
	protected array $urls = [];

	/**
	 * Fetch every configured feed, normalized to the item contract
	 * {source,id,title,url,body,timestamp}. A failed or unparseable feed contributes
	 * nothing and never throws — one bad feed can't sink the batch.
	 *
	 * @param array<string,mixed> $config {feeds: string[]}.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch( array $config ): array {
		$feeds = \is_array( $config['feeds'] ?? null ) ? $config['feeds'] : [];
		$items = [];
		foreach ( $feeds as $feed ) {
			if ( ! \is_string( $feed ) || '' === $feed ) {
				continue;
			}
			$items = \array_merge( $items, $this->fetch_feed( $feed ) );
		}
		return $items;
	}

	/**
	 * GET one feed and parse it. Returns [] on transport error, non-200, or a body
	 * that won't parse as XML.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fetch_feed( string $url ): array {
		$args     = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- connector fetches run in a background worker, not a web request.
			'timeout' => 15,
			'headers' => [ 'User-Agent' => self::USER_AGENT ],
		];
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- connector fetches run in a background worker, not a VIP web request; the closure seam covers tests.
		$response = null !== self::$http_get ? ( self::$http_get )( $url, $args ) : \wp_remote_get( $url, $args );
		if ( \is_wp_error( $response ) ) {
			$this->print_less_often( 'Feed fetch failed: ' . $response->get_error_message() );
			return [];
		}
		if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		return $this->parse( \wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Parse a feed body, supporting both RSS 2.0 (channel/item) and Atom (entry).
	 * libxml errors are suppressed and a parse failure yields [].
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse( string $body ): array {
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
		return isset( $xml->channel ) ? $this->parse_rss( $xml ) : $this->parse_atom( $xml );
	}

	/**
	 * RSS 2.0: items at channel/item with title/link/description/guid/pubDate.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_rss( \SimpleXMLElement $xml ): array {
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
			$out[] = $this->normalize_item( 'feed', $id, (string) $item->title, $link, (string) $item->description, $when );
		}
		return $out;
	}

	/**
	 * Atom: entries at entry with title, link[href], summary|content, id, updated.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function parse_atom( \SimpleXMLElement $xml ): array {
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
			$out[]   = $this->normalize_item( 'feed', $id, (string) $entry->title, $link, $body, (string) $entry->updated );
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

	/** @return array{feeds:array<int,string>} */
	protected function config(): array {
		return [ 'feeds' => $this->urls ];
	}

	/**
	 * `add_url` verb handler — appends one feed URL to the registered list.
	 *
	 * @param string $args The feed URL.
	 * @return string Result line.
	 */
	public function add_url( string $args ): string {
		$url = \trim( $args );
		if ( '' === $url ) {
			return 'error: add_url requires <url>';
		}
		$this->urls[] = $url;
		return 'ok';
	}

	/**
	 * `add_url` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param string                   $args        The feed URL.
	 * @return string Result line.
	 */
	public static function cmd_add_url( Command_Interpreter_Node $interpreter, string $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->add_url( $args );
	}

	/** Emit the base config plus one round-trippable `cmd {name}:config add_url …` per registered URL. */
	public function dump_config(): string {
		$out = parent::dump_config();
		foreach ( $this->urls as $url ) {
			$out .= "cmd {$this->name}:config add_url {$url}\n";
		}
		return $out;
	}

	public static function node_schema(): array {
		return \array_merge(
			self::source_schema(
				'Fetches items from the configured RSS 2.0 / Atom feeds on a TICK request (request_node feed TICK).',
				'Fetch + emit new feed items. Trigger with `request_node feed TICK`.'
			),
			[
				'commands' => [
					[
						'name'        => 'add_url',
						'description' => 'Register a feed URL to fetch on TICK: <url>.',
						'args'        => [
							[ 'name' => 'url', 'type' => 'string', 'required' => true ],
						],
						'handler'     => static fn ( Command_Interpreter_Node $interpreter, string $args ): string => self::cmd_add_url( $interpreter, $args ),
						'multiple'    => true,
					],
				],
			]
		);
	}
}

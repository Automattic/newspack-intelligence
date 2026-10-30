<?php
/**
 * P2_Source_Node: reads the posts and comments of the P2s the `add_site`
 * verb names through the WordPress.com REST API, and normalizes them into
 * recorded items.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. A pass asks each site's posts and then its comments over the
 * window; an answer's url names its endpoint and page, and a posts answer's
 * `meta.next_page`, or a full page of comments, names the next page.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class P2_Source_Node extends Source_Node {

	/** Seconds back from the clock every pass reads. */
	public const WINDOW_SECONDS = 7 * Intel_Graph_Row_Schema::DAY_SECONDS;

	private const API = 'https://public-api.wordpress.com/rest/v1.1/sites/';

	/** Posts or comments a page asks for. */
	private const PER_PAGE = 100;

	/** Each endpoint a pass reads per site, in order, with its query after the window's opening. */
	private const ENDPOINTS = [
		'posts'    => 'posts/?after=%s&number=' . self::PER_PAGE . '&order_by=date&fields=ID,site_ID,title,URL,content,date,author',
		'comments' => 'comments/?after=%s&number=' . self::PER_PAGE . '&fields=ID,post,content,date,author,URL',
	];

	/** @var list<string> Sites (`<name>.wordpress.com`) the `add_site` verb registered and `remove_site` left, in call order. */
	protected array $sites = [];

	/**
	 * One page's posts or comments as items, by the endpoint its url names,
	 * queuing the next page: a posts page's `meta.next_page` handle, or the
	 * next number after a full page of comments, which carry no handle. An
	 * answer carrying `error` logs its message and records nothing, and one
	 * naming no `site_ID` logs that under its site and records none of it.
	 *
	 * @param string $url  The endpoint's url, as it was queued.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$answer = $this->json_answer( 'WordPress.com', $url, $body, static fn ( array $answer ): ?string => isset( $answer['error'] ) ? Core::as_string( $answer['message'] ?? $answer['error'] ) : null );
		if ( null === $answer || 1 !== \preg_match( '#^' . \preg_quote( self::API, '#' ) . '([^/]+)/(posts|comments)/\?#', $url, $match ) ) {
			return [];
		}
		return 'posts' === $match[2] ? $this->posts( $match[1], $url, $answer ) : $this->comments( $match[1], $url, $answer );
	}

	/**
	 * A posts page's posts, each keyed by its own site and id.
	 *
	 * @param string                 $host   The site the page reads.
	 * @param string                 $url    The page's url.
	 * @param array<array-key,mixed> $answer The decoded page.
	 * @return array<int,array<string,mixed>>
	 */
	private function posts( string $host, string $url, array $answer ): array {
		$next = Core::str( Core::arr( $answer['meta'] ?? null )['next_page'] ?? null );
		if ( '' !== $next ) {
			$this->queue( \add_query_arg( 'page_handle', \rawurlencode( $next ), $url ) );
		}
		return self::items(
			$answer['posts'] ?? null,
			function ( array $post ) use ( $host, $url ): ?array {
				$site = Core::as_int( $post['site_ID'] ?? null );
				$id   = Core::as_int( $post['ID'] ?? null );
				if ( $site <= 0 ) {
					$this->unsited( $host, $url );
				}
				return $site > 0 && $id > 0 ? $this->item( "{$site}:{$id}", Core::str( $post['title'] ?? null ), $post ) : null;
			}
		);
	}

	/**
	 * A comments page's comments, each keyed by the site the page names and
	 * its post's key.
	 *
	 * @param string                 $host   The site the page reads.
	 * @param string                 $url    The page's url.
	 * @param array<array-key,mixed> $answer The decoded page.
	 * @return array<int,array<string,mixed>>
	 */
	private function comments( string $host, string $url, array $answer ): array {
		$comments = Core::arr( $answer['comments'] ?? null );
		if ( self::PER_PAGE === \count( $comments ) ) {
			\parse_str( Core::as_string( \wp_parse_url( $url, \PHP_URL_QUERY ) ), $query );
			$this->queue( \add_query_arg( 'page', (string) ( Core::as_int( $query['page'] ?? null, 1 ) + 1 ), $url ) );
		}
		$site = Core::as_int( $answer['site_ID'] ?? null );
		if ( $site <= 0 && [] !== $comments ) {
			$this->unsited( $host, $url );
			return [];
		}
		return self::items(
			$comments,
			function ( array $comment ) use ( $site ): ?array {
				$post    = Core::arr( $comment['post'] ?? null );
				$post_id = Core::as_int( $post['ID'] ?? null );
				$id      = Core::as_int( $comment['ID'] ?? null );
				return $post_id > 0 && $id > 0 ? $this->item( "{$site}:{$post_id}:c{$id}", 'Re: ' . Core::str( $post['title'] ?? null ), $comment ) : null;
			}
		);
	}

	/** Log, once a site, an answer for $host naming no site_ID to key its items by. */
	private function unsited( string $host, string $url ): void {
		$this->print_less_often( "WordPress.com named no site_ID for {$host}: ", Core::terminal_safe( $url ) );
	}

	/**
	 * A post or comment as an item: its title and its content stripped to
	 * text, entities decoded, its author's name leading the body.
	 *
	 * @param string                 $id    The item's id under `p2:`.
	 * @param string                 $title The title, as HTML.
	 * @param array<array-key,mixed> $row   The post or comment.
	 * @return array<string,mixed>
	 */
	private function item( string $id, string $title, array $row ): array {
		$author = Core::str( Core::arr( $row['author'] ?? null )['name'] ?? null );
		$text   = self::decoded( \wp_strip_all_tags( Core::str( $row['content'] ?? null ) ) );
		return $this->normalize_item( 'p2', $id, self::decoded( $title ), $row['URL'] ?? '', "{$author}: {$text}", $row['date'] ?? '' );
	}

	/** HTML's entities as the characters they spell. */
	private static function decoded( string $html ): string {
		return \html_entity_decode( $html, \ENT_QUOTES | \ENT_HTML5, 'UTF-8' );
	}

	/** Queue each site's posts, then its comments, since the window opened. */
	protected function begin(): void {
		$after = \gmdate( 'Y-m-d\TH:i:s\Z', (int) Core::$now - self::WINDOW_SECONDS );
		foreach ( $this->sites as $site ) {
			foreach ( self::ENDPOINTS as $query ) {
				$this->queue( self::API . "{$site}/" . \sprintf( $query, $after ) );
			}
		}
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Reads the posts and comments of the configured P2s over the last ' . ( self::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days through the WordPress.com REST API, without blocking, one request at a time, on a TICK request (request_node p2 TICK).',
			'Start a pass over the sites; each page records its new posts and comments as it lands. Trigger with `request_node p2 TICK`.',
			self::checked_list( 'site', 'sites', '/^[a-z0-9-]+\.wordpress\.com\z/', 'a wordpress.com site', 'Register a P2 to read on TICK by its host: <name>.wordpress.com.' )
		);
	}
}

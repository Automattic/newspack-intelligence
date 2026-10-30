<?php
/**
 * Context_A8C_P2_Node: reads the posts and comments of the P2s the
 * `add_site` verb names through ContextA8C's `wpcom` / `posts-text`, and
 * records each as P2_Source_Node records the same post or comment.
 *
 * Extends Context_A8C_Source_Node, which owns the token, the JSON-RPC
 * envelope and the answer. A pass asks each site's posts published over the
 * P2 source's window, with their comments, and every page up to the
 * answer's `max_pages`. Content arrives as text, and a comment names no
 * url, so it records with none, as the P2 source records one whose answer
 * names none. Only comments on the window's posts arrive, where the P2
 * source reads every comment of the window.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Context_A8C_P2_Node extends Context_A8C_Source_Node {

	/** Posts a page asks for, the most `posts-text` answers. */
	private const PER_PAGE = 100;

	/** Comments a post brings, the most `posts-text` answers. */
	private const COMMENTS_PER_POST = 500;

	/** @var list<string> Sites (`<name>.wordpress.com`) the `add_site` verb registered and `remove_site` left, in call order. */
	protected array $sites = [];

	/** Ask each site's first page since the window opened. */
	protected function calls(): void {
		foreach ( $this->sites as $site ) {
			$this->page( $site, 1, \gmdate( 'Y-m-d\TH:i:s\Z', (int) Core::$now - P2_Source_Node::WINDOW_SECONDS ) );
		}
	}

	/**
	 * A page's posts and their comments as items, through the P2 source's
	 * mappings, asking the next page while one is left; an answer naming no
	 * `blog_id` is logged under its site and records none of them.
	 *
	 * @param string                 $subtool The provider's tool, `posts-text`.
	 * @param array<array-key,mixed> $args    The call's arguments.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	protected function answered( string $subtool, array $args, array $answer ): array {
		$site = Core::as_string( $args['site'] ?? null );
		$page = Core::as_int( $args['page'] ?? null );
		if ( $page < Core::as_int( $answer['max_pages'] ?? null ) ) {
			$this->page( $site, $page + 1, Core::as_string( $args['after'] ?? null ) );
		}
		$blog  = Core::as_int( Core::arr( $answer['site'] ?? null )['blog_id'] ?? null );
		$posts = Core::arr( $answer['posts'] ?? null );
		if ( $blog <= 0 ) {
			if ( [] !== $posts ) {
				$this->print_less_often( "ContextA8C named no blog_id for {$site}: ", $site );
			}
			return [];
		}
		$items = [];
		foreach ( $posts as $post ) {
			\array_push( $items, ...$this->post_items( $blog, Core::arr( $post ) ) );
		}
		return $items;
	}

	/** Ask one page of a site's posts since $after, the pass's window, with their comments. */
	private function page( string $site, int $page, string $after ): void {
		$this->call(
			'wpcom',
			'posts-text',
			[
				'site'                  => $site,
				'after'                 => $after,
				'per_page'              => self::PER_PAGE,
				'page'                  => $page,
				'include_comments'      => true,
				'max_comments_per_post' => self::COMMENTS_PER_POST,
			]
		);
	}

	/**
	 * A post and each of its comments as items; a post or comment with no id
	 * records nothing.
	 *
	 * @param int                    $blog The site's id.
	 * @param array<array-key,mixed> $post The post, its comments inside it.
	 * @return list<array<string,mixed>>
	 */
	private function post_items( int $blog, array $post ): array {
		$id = Core::as_int( $post['id'] ?? null );
		if ( $id <= 0 ) {
			return [];
		}
		$title = Core::str( $post['title'] ?? null );
		return [
			$this->normalize_item( 'p2', ...P2_Source_Node::post_fields( $blog, $id, $title, self::name_of( $post['author'] ?? null ), Core::str( $post['content_text'] ?? null ), $post['link'] ?? '', $post['date'] ?? '' ) ),
			...self::items(
				$post['comments'] ?? null,
				function ( array $comment ) use ( $blog, $id, $title ): ?array {
					$comment_id = Core::as_int( $comment['id'] ?? null );
					return $comment_id > 0 ? $this->normalize_item( 'p2', ...P2_Source_Node::comment_fields( $blog, $id, $comment_id, $title, self::name_of( $comment['author'] ?? null ), Core::str( $comment['content_text'] ?? null ), '', $comment['date'] ?? '' ) ) : null;
				}
			),
		];
	}

	/**
	 * An author's name: the string given, or an object's `display_name`.
	 *
	 * @param mixed $author A name, or an author object.
	 */
	private static function name_of( mixed $author ): string {
		return \is_array( $author ) ? Core::str( $author['display_name'] ?? null ) : Core::str( $author );
	}

	public static function node_schema(): array {
		return self::context_schema(
			'Reads the posts and comments of the configured P2s over the last ' . ( P2_Source_Node::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days through ContextA8C, as the person signed in to it on the host, without blocking, on a TICK request (request_node p2 TICK). Local workstation only.',
			'Start a pass over the sites; each page records its new posts and comments as it lands. Trigger with `request_node p2 TICK`.',
			P2_Source_Node::site_verbs()
		);
	}
}

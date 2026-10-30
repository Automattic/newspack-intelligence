<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_P2_Node;
use Newspack_Intelligence\P2_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-context-a8c.php';

/**
 * The ContextA8C P2 node: each site's posts of the P2 source's window with
 * their comments, through `wpcom` / `posts-text`, page by page, each post and
 * comment recorded as the P2 source records it.
 */
final class ContextA8CP2Test extends TestCase {
	use Drives_Context_A8C;

	private const HERON = 'heronp2.wordpress.com';

	private const API = 'https://public-api.wordpress.com/rest/v1.1/sites/';

	protected function setUp(): void {
		parent::setUp();
		$this->install_context_a8c();
		$this->signed_in();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A ContextA8C P2 node as the local topology builds it.
	 *
	 * @param string ...$sites The sites it reads.
	 */
	private function p2( string $tier, string ...$sites ): Context_A8C_P2_Node {
		$node = $this->wire( new Context_A8C_P2_Node(), 'p2', [ $tier, $this->auth_dir ] );
		foreach ( $sites as $site ) {
			$node->interpreter()->dispatch( 'add_site', [ $site ] );
		}
		return $node;
	}

	/** The window's opening instant, as a pass asks for it. */
	private static function after(): string {
		return \gmdate( 'Y-m-d\TH:i:s\Z', (int) self::NOW - P2_Source_Node::WINDOW_SECONDS );
	}

	/**
	 * The arguments of one page's call.
	 *
	 * @return array<string,mixed>
	 */
	private static function page_args( string $site, int $page ): array {
		return [ 'site' => $site, 'after' => self::after(), 'per_page' => 100, 'page' => $page, 'include_comments' => true, 'max_comments_per_post' => 500 ];
	}

	/** A pass asks each site's first page of posts since the window opened, with their comments. */
	public function test_a_pass_asks_each_sites_posts_with_comments(): void {
		$this->tick( $this->p2( 'internal', self::HERON, 'kestrel-ops.wordpress.com' ) );
		$this->initialized();
		$this->answer( [ 'site' => [ 'blog_id' => 7781 ], 'posts' => [], 'max_pages' => 1 ] );
		$this->answer( [ 'site' => [ 'blog_id' => 7782 ], 'posts' => [], 'max_pages' => 0 ] );

		$this->assertSame(
			[
				[ 'provider' => 'wpcom', 'subtool' => 'posts-text', 'subtool_args' => self::page_args( self::HERON, 1 ) ],
				[ 'provider' => 'wpcom', 'subtool' => 'posts-text', 'subtool_args' => self::page_args( 'kestrel-ops.wordpress.com', 1 ) ],
			],
			$this->calls()
		);
	}

	/** A site with more pages than the one answered asks the next, until its last, each at the first page's window. */
	public function test_each_page_up_to_max_pages_is_asked(): void {
		$this->tick( $this->p2( 'internal', self::HERON ) );
		$this->initialized();
		$this->answer( [ 'site' => [ 'blog_id' => 7781 ], 'posts' => [], 'max_pages' => 3 ] );
		Core::$clock = static fn (): float => self::NOW + 7200;
		$this->answer( [ 'site' => [ 'blog_id' => 7781 ], 'posts' => [], 'max_pages' => 3 ] );
		$this->answer( [ 'site' => [ 'blog_id' => 7781 ], 'posts' => [], 'max_pages' => 3 ] );

		$this->assertSame( [ self::page_args( self::HERON, 1 ), self::page_args( self::HERON, 2 ), self::page_args( self::HERON, 3 ) ], \array_column( $this->calls(), 'subtool_args' ) );
	}

	/**
	 * Each post and comment records under the P2 source's key and item shape:
	 * the same post and comment the P2 source reads record the same, the
	 * comment's url falling back to none, as the P2 source's does when its
	 * answer names none.
	 */
	public function test_each_post_and_comment_records_as_the_p2_source_records_them(): void {
		$real = $this->wire( new P2_Source_Node(), 'p2-real', [ 'restricted' ] );
		$real->interpreter()->dispatch( 'add_site', [ self::HERON ] );
		$this->tick( $real );
		$posts_url = self::API . self::HERON . '/posts/?after=' . self::after() . '&number=100&order_by=date&fields=ID,site_ID,title,URL,content,date,author';
		$this->complete(
			$posts_url,
			(string) \json_encode(
				[
					'posts' => [
						[ 'ID' => 512, 'site_ID' => 7781, 'title' => 'Kestrel &amp; Heron launch', 'URL' => 'https://heronp2.wordpress.com/2026/10/28/kestrel/', 'content' => '<p>We ship Kestrel</p>', 'date' => '2026-10-28T09:00:00+00:00', 'author' => [ 'name' => 'Wren Osprey' ] ],
					],
					'meta'  => [],
				]
			)
		);
		$this->complete(
			self::API . self::HERON . '/comments/?after=' . self::after() . '&number=100&fields=ID,post,content,date,author,URL',
			(string) \json_encode(
				[
					'site_ID'  => 7781,
					'comments' => [
						[ 'ID' => 33, 'post' => [ 'ID' => 512, 'title' => 'Kestrel &amp; Heron launch' ], 'content' => "<p>Congrats\nall</p>", 'date' => '2026-10-28T10:30:00+00:00', 'author' => [ 'name' => 'Plover Kite' ] ],
					],
				]
			)
		);
		$expected               = $this->items_recorded();
		$this->writer->captured = [];

		$this->tick( $this->p2( 'restricted', self::HERON ) );
		$this->initialized();
		$this->answer(
			[
				'site'      => [ 'blog_id' => 7781, 'url' => 'https://heronp2.wordpress.com' ],
				'posts'     => [
					[
						'id'                 => 512,
						'link'               => 'https://heronp2.wordpress.com/2026/10/28/kestrel/',
						'date'               => '2026-10-28T09:00:00+00:00',
						'author'             => [ 'display_name' => 'Wren Osprey' ],
						'title'              => 'Kestrel &amp; Heron launch',
						'content_text'       => 'We ship Kestrel',
						'comments'           => [
							[ 'id' => 33, 'author' => 'Plover Kite', 'date' => '2026-10-28T10:30:00+00:00', 'content_text' => "Congrats\nall" ],
						],
						'comments_truncated' => false,
					],
				],
				'max_pages' => 1,
			]
		);

		$this->assertSame( [ 'p2:7781:512', 'p2:7781:512:c33' ], \array_column( $expected, 'item_key' ) );
		$this->assertSame( 'restricted', $expected[0]['tier'] );
		$this->assertSame( $expected, $this->items_recorded() );
	}

	/** A comment's author given as an object names its display name. */
	public function test_a_comment_author_object_names_its_display_name(): void {
		$this->tick( $this->p2( 'internal', self::HERON ) );
		$this->initialized();
		$this->answer(
			[
				'site'      => [ 'blog_id' => 7781 ],
				'posts'     => [
					[ 'id' => 512, 'title' => 'Kestrel', 'content_text' => 'Post', 'author' => [ 'display_name' => 'Wren Osprey' ], 'comments' => [ [ 'id' => 34, 'author' => [ 'display_name' => 'Dunlin Teal' ], 'content_text' => 'Agreed' ] ] ],
				],
				'max_pages' => 1,
			]
		);

		$this->assertSame( 'Dunlin Teal: Agreed', $this->recorded()['p2:7781:512:c34']['title'] );
	}

	/** An answer naming no blog_id is logged under its site and records nothing. */
	public function test_an_answer_naming_no_blog_id_records_nothing(): void {
		$stderr = $this->capture_stderr();
		$this->tick( $this->p2( 'internal', self::HERON ) );
		$this->initialized();
		$this->answer( [ 'site' => [], 'posts' => [ [ 'id' => 512, 'title' => 'Kestrel', 'content_text' => 'x' ] ], 'max_pages' => 1 ] );

		$this->assertSame( [], $this->writer->captured );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'p2: ContextA8C named no blog_id for ' . self::HERON ) );
	}

	/** A site is refused unless it is a wordpress.com host, as the P2 source refuses it. */
	public function test_add_site_refuses_anything_but_a_wordpress_com_host(): void {
		$node = new Context_A8C_P2_Node();
		$node->name( 'p2' );

		$this->assert_add_refuses( $node, 'site', 'sites', [ 'heronp2.example.com', 'https://heronp2.wordpress.com' ], 'a wordpress.com site' );
	}

	/** The sites round-trip through the dump. */
	public function test_sites_round_trip_through_the_dump(): void {
		$dump = $this->p2( 'internal', self::HERON )->dump_config();

		Core::reset();
		$replayed = new Context_A8C_P2_Node();
		$replayed->name( 'p2' );
		$this->replay_config( $dump, $replayed );

		$this->assertSame( [ self::HERON ], $this->read_private( $replayed, 'sites' ) );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\P2_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The P2 source on the chain: per site, the posts and the comments of the
 * last seven days through the WordPress.com REST API, page by page, one
 * request at a time.
 */
final class P2SourceTest extends TestCase {
	use Drives_Curl;

	private const API = 'https://public-api.wordpress.com/rest/v1.1/sites/';

	private const HERON = 'heronp2.wordpress.com';

	private const KESTREL = 'kestrel-ops.wordpress.com';

	/** The pinned clock: no default's, and a past instant, so the window arithmetic is fixed. */
	private const NOW = 1793088452.0;

	protected function setUp(): void {
		parent::setUp();
		Core::$now   = self::NOW;
		Core::$clock = static fn (): float => self::NOW;
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A P2 source as the collect topology builds it.
	 *
	 * @param string $name  The node's name.
	 * @param string $tier  Its tier.
	 * @param string ...$sites The sites it reads.
	 */
	private function p2( string $name, string $tier, string ...$sites ): P2_Source_Node {
		$node = $this->wire( new P2_Source_Node(), $name, [ $tier ] );
		foreach ( $sites as $site ) {
			$node->interpreter()->dispatch( 'add_site', [ $site ] );
		}
		return $node;
	}

	/** The window's opening instant, as a pass asks for it. */
	private static function after(): string {
		return \gmdate( 'Y-m-d\TH:i:s\Z', (int) Core::$now - P2_Source_Node::WINDOW_SECONDS );
	}

	private static function posts( string $site ): string {
		return self::API . "{$site}/posts/?after=" . self::after() . '&number=100&order_by=date&fields=ID,site_ID,title,URL,content,date,author';
	}

	private static function comments( string $site ): string {
		return self::API . "{$site}/comments/?after=" . self::after() . '&number=100&fields=ID,post,content,date,author,URL';
	}

	/**
	 * A later page's url, as WordPress's add_query_arg() writes it: the
	 * query it keeps form-encoded, the page's own value raw-encoded.
	 */
	private static function next_page( string $site, string $endpoint, string $param, string $value ): string {
		$fields = 'posts' === $endpoint ? '&number=100&order_by=date&fields=ID%2Csite_ID%2Ctitle%2CURL%2Ccontent%2Cdate%2Cauthor' : '&number=100&fields=ID%2Cpost%2Ccontent%2Cdate%2Cauthor%2CURL';
		return self::API . "{$site}/{$endpoint}/?after=" . \urlencode( self::after() ) . $fields . "&{$param}=" . \rawurlencode( $value );
	}

	/**
	 * A posts answer, as WordPress.com sends it.
	 *
	 * @param list<array<string,mixed>> $posts The posts.
	 * @param string|null               $next  The next page's handle, if any.
	 */
	private static function posts_answer( array $posts, ?string $next = null ): string {
		$meta = [ 'links' => [ 'counts' => self::API . '88120/post-counts/post' ], 'wpcom' => true ];
		if ( null !== $next ) {
			$meta['next_page'] = $next;
		}
		return (string) \json_encode( [ 'found' => \count( $posts ), 'posts' => $posts, 'meta' => $meta ] );
	}

	/**
	 * A comments answer, as WordPress.com sends it: the site's id beside the
	 * comments, none on each.
	 *
	 * @param list<array<string,mixed>> $comments The comments.
	 */
	private static function comments_answer( array $comments ): string {
		return (string) \json_encode( [ 'found' => -1, 'site_ID' => 88120, 'comments' => $comments ] );
	}

	/** A comment on post 4107. */
	private static function comment( int $id, string $content = '<p>noted</p>' ): array {
		return [
			'ID'      => $id,
			'post'    => [ 'ID' => 4107, 'title' => 'Heron&#8217;s Q4 plan', 'type' => 'post', 'link' => self::API . '88120/posts/4107' ],
			'content' => $content,
			'date'    => '2026-10-28T10:30:00+00:00',
			'author'  => [ 'ID' => 3301, 'name' => 'Wren Osprey' ],
			'URL'     => "https://heronp2.wordpress.com/2026/10/28/q4-plan/#comment-{$id}",
		];
	}

	/** A pass reads each site's posts, then its comments, over the last seven days, one request at a time. */
	public function test_a_pass_reads_each_sites_posts_then_comments_over_the_window(): void {
		$node = $this->p2( 'p2', 'internal', self::HERON, self::KESTREL );
		$urls = [ self::posts( self::HERON ), self::comments( self::HERON ), self::posts( self::KESTREL ), self::comments( self::KESTREL ) ];

		$this->tick( $node );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 4 ] ] ], $this->answers() );
		$this->complete( $urls[0], self::posts_answer( [] ) );
		$this->complete( $urls[1], self::comments_answer( [] ) );
		$this->complete( $urls[2], self::posts_answer( [] ) );

		$this->assertSame( $urls, $this->dispatched );
		$this->assertSame( 604800, P2_Source_Node::WINDOW_SECONDS );
		$this->assertTrue( $this->opts[ $urls[3] ][ \CURLOPT_HTTPGET ] );
	}

	/**
	 * A post keys under its site and id, a comment under its post's key and
	 * its own id; each is titled and stripped to text with entities decoded,
	 * its author leading its body.
	 */
	public function test_posts_and_comments_record_under_keys_apart(): void {
		$node = $this->p2( 'p2', 'internal', self::HERON );
		$this->tick( $node );

		$this->complete(
			self::posts( self::HERON ),
			self::posts_answer(
				[
					[
						'ID'      => 4107,
						'site_ID' => 88120,
						'title'   => 'Heron&#8217;s Q4 plan',
						'URL'     => 'https://heronp2.wordpress.com/2026/10/28/q4-plan/',
						'content' => '<p>Plan &amp; <strong>dates</strong></p>',
						'date'    => '2026-10-28T09:15:00+00:00',
						'author'  => [ 'ID' => 3302, 'name' => 'Dunlin Marsh' ],
					],
				]
			)
		);
		$this->complete( self::comments( self::HERON ), self::comments_answer( [ self::comment( 5502, '<p>Looks good &gt; ship</p>' ) ] ) );

		$by   = $this->recorded();
		$post = $by['p2:88120:4107'];
		$this->assertSame( [ 'p2:88120:4107', 'p2:88120:4107:c5502' ], \array_keys( $by ) );
		$this->assertSame( 'p2', $post['source'] );
		$this->assertSame( 'internal', $post['tier'] );
		$this->assertSame( 'Heron’s Q4 plan', $post['title'] );
		$this->assertSame( 'Dunlin Marsh: Plan & dates', $post['body'] );
		$this->assertSame( 'https://heronp2.wordpress.com/2026/10/28/q4-plan/', $post['url'] );
		$this->assertSame( \strtotime( '2026-10-28T09:15:00+00:00' ), $post['item_ts'] );
		$comment = $by['p2:88120:4107:c5502'];
		$this->assertSame( 'Re: Heron’s Q4 plan', $comment['title'] );
		$this->assertSame( 'Wren Osprey: Looks good > ship', $comment['body'] );
		$this->assertSame( 'https://heronp2.wordpress.com/2026/10/28/q4-plan/#comment-5502', $comment['url'] );
		$this->assertSame( \strtotime( '2026-10-28T10:30:00+00:00' ), $comment['item_ts'] );
	}

	/** A posts answer naming a next page queues the same request at its handle, replacing the one it was asked at. */
	public function test_posts_page_by_their_next_page_handle(): void {
		$node  = $this->p2( 'p2', 'internal', self::HERON );
		$first = 'value=2026-10-27T11%3A00%3A00%2B00%3A00&id=4099';
		$last  = 'value=2026-10-25T08%3A00%3A00%2B00%3A00&id=4051';
		$page2 = self::next_page( self::HERON, 'posts', 'page_handle', $first );
		$page3 = self::next_page( self::HERON, 'posts', 'page_handle', $last );
		$this->tick( $node );

		$this->complete( self::posts( self::HERON ), self::posts_answer( [], $first ) );
		$this->complete( self::comments( self::HERON ), self::comments_answer( [] ) );
		$this->complete( $page2, self::posts_answer( [], $last ) );
		$this->complete( $page3, self::posts_answer( [] ) );

		$this->assertSame( [ self::posts( self::HERON ), self::comments( self::HERON ), $page2, $page3 ], $this->dispatched );
	}

	/** Comments carry no next page: a full page queues the next by number, and a short one ends them. */
	public function test_a_full_page_of_comments_queues_the_next(): void {
		$node = $this->p2( 'p2', 'internal', self::HERON );
		$this->tick( $node );
		$page2 = self::next_page( self::HERON, 'comments', 'page', '2' );
		$page3 = self::next_page( self::HERON, 'comments', 'page', '3' );

		$this->complete( self::posts( self::HERON ), self::posts_answer( [] ) );
		$this->complete( self::comments( self::HERON ), self::comments_answer( \array_map( self::comment( ... ), \range( 6001, 6100 ) ) ) );
		$this->complete( $page2, self::comments_answer( \array_map( self::comment( ... ), \range( 6101, 6200 ) ) ) );
		$this->complete( $page3, self::comments_answer( [ self::comment( 6201 ) ] ) );

		$this->assertSame( [ self::posts( self::HERON ), self::comments( self::HERON ), $page2, $page3 ], $this->dispatched );
		$this->assertCount( 201, $this->recorded() );
	}

	/** An answer carrying `error` logs its message and records nothing. */
	public function test_an_error_answer_is_logged_and_records_nothing(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->p2( 'p2', 'internal', self::HERON );
		$this->tick( $node );

		$this->complete( self::posts( self::HERON ), '{"error":"unauthorized","message":"User cannot access this private blog 4471.","posts":[{"ID":1,"site_ID":2}]}' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'p2: WordPress.com answered an error for ' . self::posts( self::HERON ) . ': User cannot access this private blog 4471.' ) );
	}

	/** A 200 whose body is not JSON records nothing, logged. */
	public function test_a_body_that_is_not_json_is_logged(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->p2( 'p2', 'internal', self::HERON );
		$this->tick( $node );

		$this->complete( self::posts( self::HERON ), '<html>Kestrel maintenance 5531</html>' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'p2: WordPress.com answered an error for ' . self::posts( self::HERON ) . ': the body holds no JSON object or list' ) );
	}

	/** The restricted node records every item at the restricted tier. */
	public function test_the_restricted_node_records_at_its_tier(): void {
		$node = $this->p2( 'p2-restricted', 'restricted', self::KESTREL );
		$this->tick( $node );

		$this->complete( self::posts( self::KESTREL ), self::posts_answer( [] ) );
		$this->complete( self::comments( self::KESTREL ), self::comments_answer( [ self::comment( 7301 ) ] ) );

		$this->assertSame( 'restricted', $this->recorded()['p2:88120:4107:c7301']['tier'] );
	}

	/** An answer naming no site_ID records nothing for it, logged under its site. */
	public function test_an_answer_naming_no_site_id_is_logged_per_site(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->p2( 'p2', 'internal', self::HERON, self::KESTREL );
		$this->tick( $node );

		$this->complete( self::posts( self::HERON ), self::posts_answer( [] ) );
		$this->complete( self::comments( self::HERON ), (string) \json_encode( [ 'found' => -1, 'comments' => [ self::comment( 8801 ) ] ] ) );
		$this->complete( self::posts( self::KESTREL ), self::posts_answer( [ [ 'ID' => 4108, 'title' => 'No site', 'content' => '', 'date' => '2026-10-28T09:15:00+00:00', 'author' => [ 'name' => 'Dunlin Marsh' ] ] ] ) );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'p2: WordPress.com named no site_ID for ' . self::HERON . ': ' . self::comments( self::HERON ) ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'p2: WordPress.com named no site_ID for ' . self::KESTREL . ': ' . self::posts( self::KESTREL ) ) );
	}

	/** A site is refused unless it is a wordpress.com host, naming the value; nothing is added. */
	public function test_add_site_refuses_anything_but_a_wordpress_com_host(): void {
		$node = new P2_Source_Node();
		$node->name( 'p2' );

		$this->assert_add_refuses( $node, 'site', 'sites', [ 'heronp2', 'HeronP2.wordpress.com', 'heronp2.wordpress.com.evil.example', 'heronp2.wordpress.com/', 'https://heronp2.wordpress.com', 'heron_p2.wordpress.com', 'a.b.wordpress.com', "heronp2.wordpress.com\n" ], 'a wordpress.com site' );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_site', [ self::KESTREL ] ) );
		$this->assertSame( [ self::KESTREL ], $this->read_private( $node, 'sites' ) );
	}
}

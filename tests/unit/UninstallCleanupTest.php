<?php
/**
 * Tests for the uninstall option-cleanup seam.
 *
 * @package Newspack_Intelligence\Tests\Unit
 */

declare( strict_types = 1 );

namespace Newspack_Intelligence\Tests\Unit;

use Newspack_Intelligence\Tests\Signs_In_Users;
use PHPUnit\Framework\TestCase;

require_once \dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';
require_once __DIR__ . '/../support/signs-in-users.php';

final class UninstallCleanupTest extends TestCase {
	use Signs_In_Users;

	private mixed $prev_wpdb = null;

	/** Minimal wpdb double: get_col resolves a prefix LIKE against _wp_options. */
	private function wpdb(): object {
		return new class() {
			public string $options = 'wp_options';
			public function esc_like( string $text ): string {
				return $text;
			}
			public function prepare( string $query, mixed $arg ): string {
				return \str_replace( '%s', "'" . (string) $arg . "'", $query );
			}
			public function get_col( string $query ): array {
				\preg_match( "/LIKE '([^']*)'/", $query, $m );
				$prefix = \rtrim( $m[1] ?? '', '%' );
				return \array_values( \array_filter(
					\array_keys( $GLOBALS['_wp_options'] ),
					static fn ( string $name ): bool => \str_starts_with( $name, $prefix )
				) );
			}
		};
	}

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = [];
		$this->prev_wpdb        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']        = $this->wpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->prev_wpdb;
		self::forget_users();
	}

	/** Uninstall strips restricted access from every user, and leaves their other capabilities. */
	public function test_uninstall_strips_restricted_access_from_every_user(): void {
		self::add_user( 9101, 'osprey-analyst', 'newspack_intelligence_restricted', 'edit_posts' );
		self::add_user( 9103, 'osprey-closer', 'newspack_intelligence_restricted' );
		$GLOBALS['_wp_options'] = [ 'newspack_intelligence_vault_id' => 'osprey' ];

		\Newspack_Intelligence\uninstall_cleanup( 'newspack_intelligence_' );

		$this->assertSame( [ 9101 => [ 'edit_posts' => true ], 9103 => [] ], $GLOBALS['_wp_test_site_caps'][1] );
		$this->assertSame( [], $GLOBALS['_wp_options'] );
	}

	/**
	 * WordPress runs uninstall.php whether or not the substrate is active, so
	 * a process that loads none of its classes still strips restricted access.
	 */
	public function test_uninstall_strips_restricted_access_without_the_substrate(): void {
		$process = \proc_open( [ \PHP_BINARY, \dirname( __DIR__ ) . '/support/uninstall-without-substrate.php' ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes );
		$this->assertIsResource( $process );
		$out = (string) \stream_get_contents( $pipes[1] );
		$err = (string) \stream_get_contents( $pipes[2] );
		\fclose( $pipes[1] );
		\fclose( $pipes[2] );

		$this->assertSame( 0, \proc_close( $process ), $err . $out );
		$this->assertSame(
			[
				'substrate' => false,
				'caps'      => [ 1 => [ 9301 => [ 'edit_posts' => true ], 9302 => [ 'edit_posts' => true ] ] ],
			],
			\json_decode( $out, true )
		);
	}

	/** On a multisite, uninstall strips it on every site, and returns to the site it ran on. */
	public function test_uninstall_strips_restricted_access_on_every_site(): void {
		$GLOBALS['_wp_test_is_multisite'] = true;
		$GLOBALS['_wp_test_sites']        = [ 1, 7 ];
		self::add_user( 9101, 'osprey-analyst', 'newspack_intelligence_restricted', 'edit_posts' );
		\switch_to_blog( 7 );
		self::add_user( 9102, 'osprey-desk', 'newspack_intelligence_restricted' );
		\restore_current_blog();

		\Newspack_Intelligence\uninstall_cleanup( 'newspack_intelligence_' );

		$this->assertSame( [ 9101 => [ 'edit_posts' => true ] ], $GLOBALS['_wp_test_site_caps'][1] );
		$this->assertSame( [ 9102 => [] ], $GLOBALS['_wp_test_site_caps'][7] );
		$this->assertSame( 1, \get_current_blog_id() );
	}

	public function test_deletes_prefixed_options_and_their_transients_only(): void {
		$GLOBALS['_wp_options'] = [
			'newspack_intelligence_topologies'                    => [ 't' ],
			'newspack_intelligence_base_directory'                => '/tmp',
			'_transient_newspack_intelligence_lock'               => 1,
			'_transient_timeout_newspack_intelligence_lock'       => 123,
			'other_plugin_option'                          => 'keep',
			'siteurl'                                      => 'https://example.test',
		];

		$deleted = \Newspack_Intelligence\delete_prefixed_options( $this->wpdb(), 'newspack_intelligence_' );

		$this->assertSame( 4, $deleted );
		$this->assertArrayNotHasKey( 'newspack_intelligence_topologies', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( '_transient_newspack_intelligence_lock', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( '_transient_timeout_newspack_intelligence_lock', $GLOBALS['_wp_options'] );
		$this->assertSame( 'keep', $GLOBALS['_wp_options']['other_plugin_option'] );
		$this->assertSame( 'https://example.test', $GLOBALS['_wp_options']['siteurl'] );
	}

	public function test_returns_zero_when_nothing_matches(): void {
		$GLOBALS['_wp_options'] = [ 'siteurl' => 'https://example.test' ];

		$this->assertSame( 0, \Newspack_Intelligence\delete_prefixed_options( $this->wpdb(), 'newspack_intelligence_' ) );
		$this->assertSame( 'https://example.test', $GLOBALS['_wp_options']['siteurl'] );
	}
}

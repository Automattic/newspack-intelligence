<?php
/**
 * A test process removes the base it named when it exits.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;

class TestBaseDirCleanupTest extends TestCase {

	private string $child_tree = '';

	protected function tearDown(): void {
		if ( '' !== $this->child_tree ) {
			\exec( 'rm -rf ' . \escapeshellarg( $this->child_tree ) );
		}
		parent::tearDown();
	}

	public function test_a_run_leaves_no_tree_a_late_shutdown_writer_touched(): void {
		$script = 'require ' . \var_export( \dirname( __DIR__ ) . '/bootstrap.php', true ) . ';'
			. ' $base = (string) \getenv( "NEWSPACK_TEST_BASE_DIR" );'
			. ' \register_shutdown_function( static function () use ( $base ): void {'
			. ' @\mkdir( "{$base}/logs", 0700, true ); \touch( "{$base}/logs/kea-4471.log" ); } );'
			. ' echo $base;';
		$env = \getenv();
		unset( $env['NEWSPACK_TEST_BASE_DIR'], $env['LOCAL_NEWSPACK_NODES_CONF'] );
		$process = \proc_open( [ \PHP_BINARY, '-r', $script ], [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, null, $env );
		$this->assertIsResource( $process );
		$base = (string) \stream_get_contents( $pipes[1] );
		$err  = (string) \stream_get_contents( $pipes[2] );
		\fclose( $pipes[1] );
		\fclose( $pipes[2] );
		$this->assertSame( 0, \proc_close( $process ), $err );
		$this->child_tree = $base;

		$this->assertStringContainsString( '/newspack-intelligence-test-', $base );
		$this->assertDirectoryDoesNotExist( $base );
	}
}

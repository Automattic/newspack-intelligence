<?php
/**
 * Concurrent suites must not share a base directory.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;

class TestBaseDirectoryTest extends TestCase {

	public function test_base_carries_the_process_id(): void {
		$expected = \sys_get_temp_dir() . '/newspack-intelligence-test-' . \getmypid();

		$this->assertSame( $expected, \getenv( 'NEWSPACK_TEST_BASE_DIR' ) );
	}

	public function test_baseline_config_reads_the_base_from_the_env(): void {
		$config = require __DIR__ . '/../newspack-intelligence-test-config.php';

		$this->assertSame( \getenv( 'NEWSPACK_TEST_BASE_DIR' ), $config['base_directory'] );
	}
}

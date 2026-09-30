<?php
/**
 * Newspack Intelligence test configuration baseline.
 *
 * Loaded via LOCAL_NEWSPACK_NODES_CONF environment variable (set in
 * phpunit.xml and bootstrap.php). Tests that need a different
 * base_directory write their own per-test config file in setUp and
 * point LOCAL_NEWSPACK_NODES_CONF at it.
 *
 * @package Newspack_Intelligence
 */

$base_directory = \getenv( 'NEWSPACK_TEST_BASE_DIR' );
if ( false === $base_directory ) {
	throw new \RuntimeException( 'NEWSPACK_TEST_BASE_DIR is unset; tests/bootstrap.php sets it.' );
}

return [
	'base_directory'    => $base_directory,
	'num_partitions'    => 1,
	'segment_size'      => 1024,
	'min_segments'      => 2,
	'num_segments'      => 2,
	'min_lifetime'      => 0,
	'lifetime'          => 0,
	'memcache_servers'  => [],
	'enable_logging'    => false,
	'enable_jobs'       => false,
	'enable_aggregator' => false,
];

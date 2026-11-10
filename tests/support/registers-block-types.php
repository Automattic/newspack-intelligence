<?php
/**
 * `register_block_type()` as core registers a block, into the registry the
 * substrate's `WP_Block_Type_Registry` shim answers from.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

if ( ! \function_exists( 'register_block_type' ) ) {
	function register_block_type( string $name, array $args = [] ): bool {
		$GLOBALS['_wp_test_block_types'][ $name ] = $args;
		return true;
	}
}

<?php
/**
 * Runs the plugin's uninstall.php as WordPress does with the substrate
 * absent: no newspack-nodes class loaded, no test bootstrap. Prints, as
 * JSON, whether any substrate class loaded and the capability maps left.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

define( 'ABSPATH', '/' );
define( 'WP_UNINSTALL_PLUGIN', 'newspack-intelligence/newspack-intelligence.php' );
require __DIR__ . '/wp-user-stubs.php';

function is_multisite(): bool {
	return false;
}

function delete_option( string $name ): bool {
	return true;
}

$GLOBALS['wpdb'] = new class() {
	public string $options = 'wp_options';
	public function esc_like( string $text ): string {
		return $text;
	}
	public function get_col( string $query ): array {
		return [];
	}
};

$GLOBALS['_wp_test_user_logins']        = [ 9301 => 'tern-analyst', 9302 => 'tern-editor' ];
$GLOBALS['_wp_test_site_caps'][1][9301] = [ 'newspack_intelligence_restricted' => true, 'edit_posts' => true ];
$GLOBALS['_wp_test_site_caps'][1][9302] = [ 'edit_posts' => true ];

require \dirname( __DIR__, 2 ) . '/uninstall.php';

echo \json_encode( [ 'substrate' => \class_exists( 'Newspack_Nodes\\Core', false ), 'caps' => $GLOBALS['_wp_test_site_caps'] ] );

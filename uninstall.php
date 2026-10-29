<?php
/**
 * Newspack Intelligence uninstall cleanup.
 *
 * Runs ONLY on plugin delete (WordPress defines WP_UNINSTALL_PLUGIN), never on
 * deactivate. Removes every `newspack_intelligence_` option this plugin
 * created, the restricted-access capability from every user, and the files
 * of the `intel-graph` Row_Table its topologies declare.
 * The main file, which registers the autoloader, does not run here.
 *
 * @package Newspack_Intelligence
 */

if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/includes/uninstall-cleanup.php';

\Newspack_Intelligence\uninstall_cleanup( 'newspack_intelligence_' );
\Newspack_Intelligence\delete_graph_tables( __DIR__ . '/topologies' );

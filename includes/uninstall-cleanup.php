<?php
/**
 * Uninstall cleanup helpers: every option row the plugin wrote, the
 * restricted-access capability on every user, and the SQLite files of the
 * Row_Table its topologies declare with the Intel_Graph schema. The
 * substrate's uninstall removes the rest of the runtime tree.
 *
 * Loaded only from uninstall.php (plugin delete). Kept out of the autoloader so
 * it costs nothing at runtime.
 *
 * @package Newspack_Intelligence
 */

declare( strict_types = 1 );

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

/**
 * Delete every option row for a prefix, plus its transient variants (all are
 * option rows, so this stays options-only). Prefix-based so it stays complete
 * as options come and go and catches autoload=off rows a hardcoded list misses.
 *
 * @param \wpdb  $wpdb   WordPress database handle.
 * @param string $prefix Option-name prefix, e.g. `newspack_intelligence_`.
 * @return int Number of option rows deleted.
 */
function delete_prefixed_options( $wpdb, string $prefix ): int {
	$deleted = 0;
	// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup; the LIKE prefix is esc_like-escaped and contains no user input.
	foreach ( [ $prefix, '_transient_' . $prefix, '_transient_timeout_' . $prefix ] as $stub ) {
		$sql = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '" . $wpdb->esc_like( $stub ) . "%'";
		foreach ( $wpdb->get_col( $sql ) as $name ) {
			if ( \is_string( $name ) ) {
				\delete_option( $name );
				++$deleted;
			}
		}
	}
	// phpcs:enable
	return $deleted;
}

/**
 * Delete the SQLite files, WAL files included, of every Row_Table the
 * plugin's own topologies declare with the Intel_Graph schema, on every
 * partition a fleet can run. Only this plugin's topologies are read, inside
 * `Topology_Registry::with_stock_dir()`, so another plugin's broken one
 * cannot block the uninstall and the dir is left unregistered; a broken one
 * this plugin ships fails loud. Nothing is deleted under the default base
 * directory, which every unconfigured install on a host shares, nor where
 * the substrate is not loaded.
 *
 * @param string $topologies_dir The plugin's shipped `.tsl` files.
 * @return int Files deleted.
 */
function delete_graph_tables( string $topologies_dir ): int {
	if ( ! \class_exists( '\Newspack_Nodes\Table_Node' ) ) {
		return 0;
	}
	$base = \Newspack_Nodes\Config::configured_base_directory();
	if ( '' === $base || \Newspack_Nodes\Settings_Schema::get()->defaults()['base_directory'] === $base ) {
		return 0;
	}
	\Newspack_Nodes\Command_Interpreter_Node::register_namespace( __NAMESPACE__ . '\\' );
	$dir    = \rtrim( $topologies_dir, '/' );
	$tables = \Newspack_Nodes\Topology_Registry::with_stock_dir(
		$dir,
		static function () use ( $dir ): array {
			$shipped = [];
			foreach ( \Newspack_Nodes\Topology_Registry::describe() as $name => $sources ) {
				if ( $dir === \dirname( $sources['stock'][0] ?? '' ) ) {
					$shipped[] = $name;
				}
			}
			return \Newspack_Nodes\Bootstrap::row_tables_of( Intel_Graph_Row_Schema::class, ...$shipped );
		}
	);
	$deleted = 0;
	foreach ( $tables as $table ) {
		for ( $partition = 0; $partition < \Newspack_Nodes\Spawn_Coordinator::MAX_PARTITIONS; $partition++ ) {
			$file = \Newspack_Nodes\Table_Node::file( $table, $partition );
			foreach ( [ $file, "{$file}-wal", "{$file}-shm" ] as $path ) {
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink -- one-time uninstall of this plugin's own graph files.
				if ( \is_file( $path ) && \unlink( $path ) ) {
					++$deleted;
				}
			}
		}
	}
	return $deleted;
}

/**
 * Delete all prefixed options and strip restricted access from every user,
 * on every site of a multisite.
 *
 * @param string $prefix Option-name prefix.
 * @return void
 */
function uninstall_cleanup( string $prefix ): void {
	global $wpdb;
	/** @var \wpdb $wpdb */

	if ( \is_multisite() ) {
		foreach ( \get_sites( [ 'fields' => 'ids', 'number' => 0 ] ) as $site_id ) {
			\switch_to_blog( $site_id );
			delete_prefixed_options( $wpdb, $prefix );
			Intel_Tiers::strip();
			\restore_current_blog();
		}
		return;
	}
	delete_prefixed_options( $wpdb, $prefix );
	Intel_Tiers::strip();
}

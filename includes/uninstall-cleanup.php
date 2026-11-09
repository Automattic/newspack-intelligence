<?php
/**
 * Uninstall cleanup helpers: every option row the plugin wrote, the
 * restricted-access capability on every user, and the SQLite files of the
 * Row_Table its topologies declare with the Intel_Graph schema and of each
 * source's frontier Table. The substrate's uninstall removes the rest of
 * the runtime tree.
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
 * Delete, on every partition, the SQLite and WAL files of every Intel_Graph
 * Row_Table and frontier Table this plugin's own topologies declare, read
 * inside `Topology_Registry::with_stock_dir()` so another plugin's can never
 * block it; nothing under the default base every unconfigured install shares,
 * nor without the substrate. A composition that will not read only because a
 * part a site supplies (`Intelligence_Health::SITE_PARTS`) does not resolve is
 * skipped, because the parts it includes, each read on its own, declare its
 * tables; any other topology that will not read fails the uninstall.
 *
 * @param string $topologies_dir The plugin's shipped `.tsl` files.
 * @return int Files deleted.
 * @throws \Throwable What a shipped topology that will not read threw, but a missing site part's include.
 */
function delete_tables( string $topologies_dir ): int {
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
			$failed = \Newspack_Nodes\Worker_Should_Stop::attempt_each( \array_combine( $shipped, $shipped ), \Newspack_Nodes\Topology_Analyzer::graph_for( ... ) );
			$absent = \array_filter( Intelligence_Health::SITE_PARTS, static fn ( string $part ): bool => null === \Newspack_Nodes\Topology_Registry::resolve( $part ) );
			$skip   = static fn ( \Throwable $e ): bool => \in_array( $e->getMessage(), \array_map( static fn ( string $part ): string => "unknown topology in include: {$part}", $absent ), true );
			\Newspack_Nodes\Worker_Should_Stop::raise( \array_filter( $failed, static fn ( \Throwable $e ): bool => ! $skip( $e ) ) );
			$shipped = \array_values( \array_diff( $shipped, \array_keys( $failed ) ) );
			$tables  = \Newspack_Nodes\Bootstrap::row_tables_of( Intel_Graph_Row_Schema::class, ...$shipped );
			foreach ( $shipped as $name ) {
				foreach ( \array_keys( \Newspack_Nodes\Topology_Analyzer::declared_tables( $name ) ) as $table ) {
					if ( \str_ends_with( $table, ':' . Source_Node::FRONTIER ) ) {
						$tables[] = $table;
					}
				}
			}
			return \array_values( \array_unique( $tables ) );
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

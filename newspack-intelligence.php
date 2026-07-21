<?php
/**
 * Plugin Name: Newspack Intelligence
 * Description: AI-driven team intelligence digest built on the newspack-nodes substrate.
 * Version: 0.5.1
 * Requires Plugins: newspack-nodes
 * Text Domain: newspack-intelligence
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_INTELLIGENCE_VERSION' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_VERSION', '0.5.1' );
}
if ( ! \defined( 'NEWSPACK_INTELLIGENCE_DIR' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_INTELLIGENCE_URL' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_URL', \plugin_dir_url( __FILE__ ) );
}

const INSIGHTS_MENU_SLUG = 'newspack-intelligence-insights';
const INSIGHTS_MOUNT_ID  = 'newspack-intelligence-insights';
const SETTINGS_MENU_SLUG = 'newspack-intelligence-settings';

/**
 * Register the Publisher Insights dashboard as its own top-level admin menu. The
 * callback renders only the React mount point inside the standard `.wrap`; the
 * dashboard bundle takes over from there.
 */
function register_insights_admin_page(): void {
	if ( ! \function_exists( 'add_menu_page' ) || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	// Honor the substrate access gate (manage_options + allowed_users).
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}
	\add_menu_page(
		\__( 'Publisher Insights', 'newspack-intelligence' ),
		\__( 'Publisher Insights', 'newspack-intelligence' ),
		'manage_options',
		INSIGHTS_MENU_SLUG,
		static fn () => print( '<div class="wrap"><div id="' . \esc_attr( INSIGHTS_MOUNT_ID ) . '" class="newspack-intelligence-insights"></div></div>' ),
		'dashicons-email',
		58.7
	);
}

/**
 * Enqueue the Publisher Insights dashboard bundle on its own admin page.
 * `Admin::enqueue_react_page()` no-ops when `build/dashboard/index.js` is absent.
 */
function enqueue_insights_assets( string $hook = '' ): void {
	if ( ! \function_exists( 'wp_enqueue_script' ) || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}

	\Newspack_Nodes\Admin\Admin::enqueue_react_page(
		[
			'handle'           => 'newspack-intelligence-insights',
			'page'             => INSIGHTS_MENU_SLUG,
			'dir'              => __DIR__ . '/build/dashboard',
			'url'              => \plugins_url( 'build/dashboard', __FILE__ ),
			'version_fallback' => \NEWSPACK_INTELLIGENCE_VERSION,
			'style_deps'       => [],
		]
	);
}

/** Register the publisher CSV importer under the core Settings menu. */
function register_clients_admin_page(): void {
	if ( ! \function_exists( 'add_submenu_page' ) || ! \class_exists( '\\Newspack_Nodes\\Admin\\Admin' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}
	\add_submenu_page(
		'options-general.php',
		\__( 'Newspack Intelligence Settings', 'newspack-intelligence' ),
		\__( 'Newspack Intelligence', 'newspack-intelligence' ),
		'manage_options',
		SETTINGS_MENU_SLUG,
		__NAMESPACE__ . '\\render_clients_page'
	);
}

/** Render the publisher CSV import page. */
function render_clients_page(): void {
	if ( ! \current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . \esc_html__( 'Newspack Intelligence Settings', 'newspack-intelligence' ) . '</h1>';
	( new Clients_Settings() )->render_upload_section();
	echo '</div>';
}

if ( \is_admin() ) {
	\add_action( 'admin_menu', __NAMESPACE__ . '\\register_insights_admin_page', 11 );
	\add_action( 'admin_menu', __NAMESPACE__ . '\\register_clients_admin_page', 12 );
	\add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_insights_assets' );
}

// Declare this page on the substrate overlay-page registry (ELN overlay tab).
\add_filter(
	'newspack_nodes/devtools_overlay_pages',
	static fn ( $pages ): array => \array_merge( (array) $pages, [ INSIGHTS_MENU_SLUG ] )
);

/**
 * Mount the Publisher Insights service interpreter into the per-request graph, the
 * same way the substrate mounts its own CIs. Idempotent: a second call (same
 * request) no-ops rather than colliding on the 'insights' node name.
 */
function mount_insights_ci( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	if ( null !== \Newspack_Nodes\Core::node( 'insights' ) ) {
		return;
	}
	$base_interpreter->make_node( 'Insights_CI', 'insights' );
}

// Load after newspack-nodes (its deferred loader runs at plugins_loaded:11).
\add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! \class_exists( '\Newspack_Nodes\Topology_Registry' ) ) {
			return;
		}
		// Composer classmap autoload; dump-autoload -o after adding a node.
		require_once __DIR__ . '/vendor/autoload.php';

		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			\WP_CLI::add_command( 'newspack-intelligence clients', '\\Newspack_Intelligence\\CLI\\Clients_CLI_Command' );
		}

		// Register upload hooks only after Composer can load Clients_Settings.
		\add_action(
			'admin_post_' . Clients_Settings::ADMIN_POST_ACTION,
			static function (): void {
				( new Clients_Settings() )->handle_admin_post();
			}
		);
		\add_action(
			'admin_notices',
			static function (): void {
				( new Clients_Settings() )->render_import_notice();
			}
		);

		// Wire inside the gate; classes autoload only after the require above.
		\add_action( 'init', [ Publisher_CPT::class, 'register' ] );
		\add_action( 'add_meta_boxes', [ Publisher_Meta_Box::class, 'register' ] );
		\add_action( 'save_post', [ Publisher_Meta_Box::class, 'save' ] );

		// register_plugin: namespace + topologies/ dir + catalog.
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Intelligence\\',
			__DIR__ . '/topologies'
		);

		// Mount Insights CI into each request graph (dashboard polls it).
		\add_action( 'newspack_nodes/request_graph_ready', __NAMESPACE__ . '\\mount_insights_ci' );
	},
	12
);

<?php
/**
 * Plugin Name: Newspack AI Newsletter
 * Description: AI-driven team intelligence digest built on the newspack-nodes substrate.
 * Version: 0.2.6
 * Requires Plugins: newspack-nodes
 * Text Domain: newspack-ai-newsletter
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_AI_NEWSLETTER_VERSION' ) ) {
	\define( 'NEWSPACK_AI_NEWSLETTER_VERSION', '0.2.6' );
}
if ( ! \defined( 'NEWSPACK_AI_NEWSLETTER_DIR' ) ) {
	\define( 'NEWSPACK_AI_NEWSLETTER_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_AI_NEWSLETTER_URL' ) ) {
	\define( 'NEWSPACK_AI_NEWSLETTER_URL', \plugin_dir_url( __FILE__ ) );
}

const INSIGHTS_MENU_SLUG = 'newspack-ai-newsletter-insights';
const INSIGHTS_MOUNT_ID  = 'newspack-ai-newsletter-insights';

/**
 * Register the Publisher Insights dashboard as its own top-level admin menu. The
 * callback renders only the React mount point inside the standard `.wrap`; the
 * dashboard bundle takes over from there.
 */
function register_insights_admin_page(): void {
	if ( ! \function_exists( 'add_menu_page' ) || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	// Honor the substrate's access gate (manage_options + allowed_users whitelist).
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}
	\add_menu_page(
		\__( 'Publisher Insights', 'newspack-ai-newsletter' ),
		\__( 'Publisher Insights', 'newspack-ai-newsletter' ),
		'manage_options',
		INSIGHTS_MENU_SLUG,
		static fn () => print( '<div class="wrap"><div id="' . \esc_attr( INSIGHTS_MOUNT_ID ) . '" class="newspack-ai-newsletter-insights"></div></div>' ),
		'dashicons-email',
		58.7
	);
}

/**
 * Enqueue the Publisher Insights dashboard bundle on its own admin page. Guarded
 * behind the built bundle: the React dashboard ships in a later sub-project, so
 * this no-ops until `build/dashboard` exists.
 */
function enqueue_insights_assets( string $hook = '' ): void {
	if ( ! \function_exists( 'wp_enqueue_script' ) || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	if ( ! \is_dir( __DIR__ . '/build/dashboard' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}

	\Newspack_Nodes\Admin\Admin::enqueue_react_page(
		[
			'handle'           => 'newspack-ai-newsletter-insights',
			'page'             => INSIGHTS_MENU_SLUG,
			'dir'              => __DIR__ . '/build/dashboard',
			'url'              => \plugins_url( 'build/dashboard', __FILE__ ),
			'version_fallback' => \NEWSPACK_AI_NEWSLETTER_VERSION,
			'style_deps'       => [],
		]
	);
}

const SETTINGS_GROUP     = 'newspack_ai_newsletter';
const SETTINGS_MENU_SLUG = 'newspack-ai-newsletter-settings';

/**
 * Register the plugin's settings via the substrate Config_System Schema, the same
 * way newspack-event-logger-nodes' Admin::register_settings() does: register_options()
 * wires register_setting() for every sanitized Field, and register_sections_and_fields()
 * adds the rendered fields to the settings page (keyed by SETTINGS_MENU_SLUG, which
 * render_settings_page() then echoes via do_settings_sections()).
 */
function register_settings(): void {
	if ( ! \class_exists( '\Newspack_Nodes\Config_System\Schema' ) ) {
		return;
	}
	$schema = Settings::schema();
	$schema->register_options( SETTINGS_GROUP );
	$schema->register_sections_and_fields( SETTINGS_MENU_SLUG );
}

/**
 * Add the AI Newsletter settings page under the core WordPress "Settings" menu —
 * a classic Settings-API form for the AI proxy + connector credentials (the React
 * dashboard handles insights display separately). Honors the substrate access gate.
 */
function register_settings_admin_page(): void {
	if ( ! \function_exists( 'add_submenu_page' ) || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Admin\Admin::current_user_allowed() ) {
		return;
	}
	\add_submenu_page(
		'options-general.php',
		\__( 'AI Newsletter Settings', 'newspack-ai-newsletter' ),
		\__( 'AI Newsletter', 'newspack-ai-newsletter' ),
		'manage_options',
		SETTINGS_MENU_SLUG,
		__NAMESPACE__ . '\\render_settings_page'
	);
}

/** Render the Settings-API form: the registered sections/fields + a save button. */
function render_settings_page(): void {
	if ( ! \current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . \esc_html__( 'AI Newsletter Settings', 'newspack-ai-newsletter' ) . '</h1>';
	echo '<form method="post" action="options.php">';
	\settings_fields( SETTINGS_GROUP );
	\do_settings_sections( SETTINGS_MENU_SLUG );
	\submit_button();
	echo '</form></div>';
}

if ( \is_admin() ) {
	\add_action( 'admin_menu', __NAMESPACE__ . '\\register_insights_admin_page', 11 );
	\add_action( 'admin_menu', __NAMESPACE__ . '\\register_settings_admin_page', 12 );
	\add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_insights_assets' );
	\add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
}

// The Publisher Insights page mounts the substrate debug overlay, so declare it
// on the substrate's overlay-page registry — that's how ELN's "Request" overlay
// tab loads here too. Harmless if the substrate/ELN aren't active.
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

// Load after newspack-nodes (its own deferred loader runs at plugins_loaded:11).
\add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! \class_exists( '\Newspack_Nodes\Topology_Registry' ) ) {
			return;
		}
		// Composer classmap autoload (run `composer dump-autoload -o` after adding a
		// node). This is also what puts the node classes in the classmap that
		// Classes_CI scans, so their node_schema() verbs show up in the palette.
		require_once __DIR__ . '/vendor/autoload.php';

		// One call wires it all: the Newspack_AI_Newsletter\ namespace (so make_node
		// resolves *_Node classes), the topologies/ stock dir + a catalog entry for
		// every *.tsl in it, and a guarded spawn handler.
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_AI_Newsletter\\',
			__DIR__ . '/topologies'
		);

		// Mount the Insights service CI into each request graph so the dashboard can poll it.
		\add_action( 'newspack_nodes/request_graph_ready', __NAMESPACE__ . '\\mount_insights_ci' );
	},
	12
);

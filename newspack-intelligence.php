<?php
/**
 * Plugin Name: Newspack Intelligence
 * Description: Collects and curates intelligence as an entity graph, built on the newspack-nodes substrate.
 * Version: 0.27.0
 * Author: Automattic
 * Author URI: https://newspack.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP: 8.2
 * Requires Plugins: newspack-nodes
 * Text Domain: newspack-intelligence
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

if ( ! \defined( 'NEWSPACK_INTELLIGENCE_VERSION' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_VERSION', '0.27.0' );
}
if ( ! \defined( 'NEWSPACK_INTELLIGENCE_DIR' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_DIR', \plugin_dir_path( __FILE__ ) );
}
if ( ! \defined( 'NEWSPACK_INTELLIGENCE_URL' ) ) {
	\define( 'NEWSPACK_INTELLIGENCE_URL', \plugin_dir_url( __FILE__ ) );
}

const INTELLIGENCE_MENU_SLUG = 'newspack-intelligence';
const INTELLIGENCE_MOUNT_ID  = 'newspack-intelligence-app';
const SETTINGS_MENU_SLUG     = 'newspack-intelligence-settings';

/**
 * Register the Intelligence page — search, entity, review and collections —
 * as its own top-level menu for every reader of the graph. The callback
 * renders only the React mount point inside the standard `.wrap`.
 */
function register_intelligence_admin_page(): void {
	if ( ! \function_exists( 'add_menu_page' ) || ! \class_exists( '\Newspack_Nodes\Capabilities' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::READ ) ) {
		return;
	}
	\add_menu_page(
		\__( 'Intelligence', 'newspack-intelligence' ),
		\__( 'Intelligence', 'newspack-intelligence' ),
		\Newspack_Nodes\Capabilities::cap_for( \Newspack_Nodes\Capabilities::READ ),
		INTELLIGENCE_MENU_SLUG,
		// Notices go after `.wp-header-end`; without one, inside the app.
		static fn () => print( '<div class="wrap"><hr class="wp-header-end"><div id="' . \esc_attr( INTELLIGENCE_MOUNT_ID ) . '"></div></div>' ),
		'dashicons-search',
		58.6
	);
}

/**
 * Enqueue the Intelligence bundle on its own admin page, telling it the
 * plugin version the shared header stamps, whether to render curation
 * controls and the vocabulary those controls offer. The
 * server's role gate stays the authority; the flag only hides. WordPress
 * refuses the page to a caller its menu capability excludes before this
 * runs. `Admin::enqueue_react_page()` no-ops when
 * `build/intelligence/index.js` is absent.
 *
 * @param string $hook The admin page's hook suffix.
 */
function enqueue_intelligence_assets( string $hook ): void {
	if ( 'toplevel_page_' . INTELLIGENCE_MENU_SLUG !== $hook || ! \class_exists( '\Newspack_Nodes\Admin\Admin' ) ) {
		return;
	}
	\Newspack_Nodes\Admin\Admin::enqueue_react_page(
		[
			'handle'           => 'newspack-intelligence',
			'page'             => INTELLIGENCE_MENU_SLUG,
			'dir'              => __DIR__ . '/build/intelligence',
			'url'              => \plugins_url( 'build/intelligence', __FILE__ ),
			'version_fallback' => \NEWSPACK_INTELLIGENCE_VERSION,
			'style_deps'       => [ 'wp-components', 'newspack-nodes-graph' ],
			'localize'         => [
				'version'    => \NEWSPACK_INTELLIGENCE_VERSION,
				'canTune'    => \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::TUNE ) ? '1' : '',
				'vocabulary' => [
					'roles'           => Intel_Event::ROLES,
					'nameKinds'       => Intel_Event::NAME_KINDS,
					'identifierKinds' => Intel_Event::IDENTIFIER_KINDS,
					'itemKinds'       => Intel_Event::ITEM_KINDS,
					'tiers'           => Intel_Event::TIERS,
					'trendAxes'       => Graph_Query::trend_axes(),
					'buckets'         => Graph_Query::buckets(),
					'lensLists'       => \array_map(
						static fn ( string $field, array $list ): array => [
							'field'  => $field,
							'option' => $list[0],
							'values' => $list[1],
						],
						\array_keys( Graph_Query::LENS_LISTS ),
						Graph_Query::LENS_LISTS
					),
					'linkRoleWords'   => [
						'subject' => \__( 'The subject', 'newspack-intelligence' ),
						'mention' => \__( 'Mentioned', 'newspack-intelligence' ),
						'topic'   => \__( 'A topic', 'newspack-intelligence' ),
					],
					'methodWords'     => [
						'identifier'  => \__( 'its identifier', 'newspack-intelligence' ),
						'name'        => \__( 'its name', 'newspack-intelligence' ),
						'alias'       => \__( 'an alias', 'newspack-intelligence' ),
						'acronym'     => \__( 'its acronym', 'newspack-intelligence' ),
						'domain_stem' => \__( 'its domain', 'newspack-intelligence' ),
						'ner_fuzzy'   => \__( 'a similar name', 'newspack-intelligence' ),
						'human'       => \__( 'a person', 'newspack-intelligence' ),
						'extract'     => \__( 'extraction', 'newspack-intelligence' ),
					],
				],
			],
		]
	);
}

/** Register the client CSV importer under the core Settings menu. */
function register_clients_admin_page(): void {
	if ( ! \function_exists( 'add_submenu_page' ) || ! \class_exists( '\\Newspack_Nodes\\Capabilities' ) ) {
		return;
	}
	if ( ! \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::MANAGE ) ) {
		return;
	}
	\add_submenu_page(
		'options-general.php',
		\__( 'Newspack Intelligence Settings', 'newspack-intelligence' ),
		\__( 'Newspack Intelligence', 'newspack-intelligence' ),
		\Newspack_Nodes\Capabilities::cap_for( \Newspack_Nodes\Capabilities::MANAGE ),
		SETTINGS_MENU_SLUG,
		__NAMESPACE__ . '\\render_clients_page'
	);
}

/** Render the Settings page: the client CSV import, then restricted access. */
function render_clients_page(): void {
	if ( ! \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::MANAGE ) ) {
		return;
	}
	echo '<div class="wrap"><h1>' . \esc_html__( 'Newspack Intelligence Settings', 'newspack-intelligence' ) . '</h1>';
	( new Clients_Settings() )->render_upload_section();
	Restricted_Settings::render_section();
	echo '</div>';
}

/**
 * Refuse a Settings-page admin-post to a caller below MANAGE, or whose nonce
 * for `$action` fails; every handler on the page opens with it.
 *
 * @param string $action The admin-post action, its nonce action too.
 */
function admit_settings_post( string $action ): void {
	if ( ! \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::MANAGE ) || ! \check_admin_referer( $action ) ) {
		\wp_die( \esc_html__( 'Not allowed.', 'newspack-intelligence' ) );
	}
}

/**
 * Open a Settings-page form posting to admin-post as `$action`: the form
 * tag, the action's nonce field and the hidden action. The caller adds its
 * fields and closes the form.
 *
 * @param string $action      The admin-post action, its nonce action too.
 * @param bool   $uploads_file Whether the form posts a file.
 */
function open_settings_form( string $action, bool $uploads_file = false ): void {
	echo '<form method="post"' . ( $uploads_file ? ' enctype="multipart/form-data"' : '' ) . ' action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
	\wp_nonce_field( $action );
	echo '<input type="hidden" name="action" value="' . \esc_attr( $action ) . '" />';
}

/**
 * Return from a Settings-page admin-post to the page it came from, else
 * the Settings page, with `$args` added to its query.
 *
 * @param array<string,string> $args Query args to add.
 */
function return_to_settings( array $args = [] ): never {
	\wp_safe_redirect( \add_query_arg( $args, \wp_get_referer() ?: \admin_url( 'options-general.php?page=' . SETTINGS_MENU_SLUG ) ) );
	exit;
}

/**
 * Mount `intelligence`, the graph's read and curation verbs, into the
 * per-request graph, the same way the substrate mounts its own CIs.
 * Idempotent: a second call in one request finds it mounted.
 */
function mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	if ( null === \Newspack_Nodes\Core::node( 'intelligence' ) ) {
		$base_interpreter->make_node( 'Intelligence_CI', 'intelligence' );
	}
}

/**
 * Register the `wp newspack-intelligence` verbs.
 *
 * @api Called by the plugins_loaded bootstrap; tests drive it against a recorder.
 */
function register_cli_commands(): void {
	\WP_CLI::add_command( 'newspack-intelligence clients', '\\Newspack_Intelligence\\CLI\\Clients_CLI_Command' );
	\WP_CLI::add_command( 'newspack-intelligence items', '\\Newspack_Intelligence\\CLI\\Items_CLI_Command' );
	\WP_CLI::add_command( 'newspack-intelligence ontology', '\\Newspack_Intelligence\\CLI\\Ontology_CLI_Command' );
	\WP_CLI::add_command( 'newspack-intelligence restricted', '\\Newspack_Intelligence\\CLI\\Restricted_CLI_Command' );
}

// newspack-nodes loads after this file, so wiring waits for plugins_loaded.
\add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! \class_exists( '\Newspack_Nodes\Topology_Registry' ) ) {
			return;
		}
		// @longform Substrate handshake: dormant below the floor, which
		// scripts/check-substrate-floor.sh holds to the substrate APIs this
		// plugin calls; CHANGELOG.md records why each floor was raised. A
		// floor set too low does not degrade: it activates, then fatals on a
		// missing method.
		if ( ! \method_exists( '\\Newspack_Nodes\\Bootstrap', 'version_at_least' )
			|| ! \Newspack_Nodes\Bootstrap::version_at_least( '2.140.0', 'Newspack Intelligence' ) ) {
			return;
		}
		// Composer classmap autoload; dump-autoload -o after adding a node.
		require_once __DIR__ . '/vendor/autoload.php';

		if ( \defined( 'WP_CLI' ) && \WP_CLI ) {
			register_cli_commands();
		}

		// Admin pages and upload hooks need Composer to load Clients_Settings.
		\add_action( 'admin_menu', __NAMESPACE__ . '\\register_intelligence_admin_page', 10 );
		\add_action( 'admin_menu', __NAMESPACE__ . '\\register_clients_admin_page', 11 );
		\add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_intelligence_assets' );
		// Declare the page on the substrate overlay registry (ELN overlay tab).
		\add_filter(
			'newspack_nodes/overlay_pages',
			static fn ( $pages ): array => \array_merge( (array) $pages, [ INTELLIGENCE_MENU_SLUG ] )
		);
		\add_action(
			'admin_post_' . Clients_Settings::ADMIN_POST_ACTION,
			static function (): void {
				( new Clients_Settings() )->handle_admin_post();
			}
		);
		\add_action( 'admin_post_' . Restricted_Settings::GRANT_ACTION, static fn () => Restricted_Settings::handle_grant() );
		\add_action( 'admin_post_' . Restricted_Settings::REVOKE_ACTION, static fn () => Restricted_Settings::handle_revoke() );
		\add_action(
			'admin_notices',
			static function (): void {
				( new Clients_Settings() )->render_import_notice();
			}
		);

		// register_plugin: namespace + topologies/ dir + catalog.
		\Newspack_Nodes\Topology_Registry::register_plugin(
			'Newspack_Intelligence\\',
			__DIR__ . '/topologies'
		);

		// Mount the service CI into each request graph.
		\add_action( 'newspack_nodes/request_graph_ready', __NAMESPACE__ . '\\mount_service_cis' );

		// The MCP door agents read and curate the graph through.
		( new Intelligence_Mcp_Door() )->wire();
	},
	12
);

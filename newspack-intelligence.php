<?php
/**
 * Plugin Name: Newspack Intelligence
 * Description: Collects and curates intelligence as an entity graph, built on the newspack-nodes substrate.
 * Version: 0.39.4
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
	\define( 'NEWSPACK_INTELLIGENCE_VERSION', '0.39.4' );
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
const LAYOUT_EDITOR_HANDLE   = 'newspack-intelligence-layout-editor';

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
 * controls, the reader's user id, which keys what the page keeps in the
 * browser, the operator the running topology names (`Operator::configured()`),
 * which the page's copy names, as `operatorName` the name it configures, ''
 * for none, and the vocabulary: the closed lists those controls offer and
 * the word each key is shown as (`screen_words()`), and as `compositions`
 * the layout each composed page reads (`Composition_Store::page_data()`).
 * A load naming one of those pages as `edit` loads the layout editor for it
 * (`enqueue_layout_editor()`), which `Composition::editor()` loads for a
 * `tune` caller alone. The
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
	$operator = Operator::configured();
	$handle   = \Newspack_Nodes\Admin\Admin::enqueue_react_page(
		[
			'handle'           => 'newspack-intelligence',
			'page'             => INTELLIGENCE_MENU_SLUG,
			'dir'              => __DIR__ . '/build/intelligence',
			'url'              => \plugins_url( 'build/intelligence', __FILE__ ),
			'version_fallback' => \NEWSPACK_INTELLIGENCE_VERSION,
			'style_deps'       => [ 'wp-components', 'newspack-nodes-graph' ],
			'localize'         => [
				'version'      => \NEWSPACK_INTELLIGENCE_VERSION,
				'canTune'      => \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::TUNE ) ? '1' : '',
				'canManage'    => \Newspack_Nodes\Capabilities::can( \Newspack_Nodes\Capabilities::MANAGE ) ? '1' : '',
				'userId'       => (string) \get_current_user_id(),
				'operator'     => $operator->shown(),
				'operatorName' => $operator->named ? $operator->name : '',
				'vocabulary'   => [
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
					...screen_words( $operator ),
				],
				'compositions' => \Newspack_Nodes\Composition_Store::page_data( \array_keys( Intelligence_Blocks::CONTEXTS ) ),
			],
		]
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- names which page's editor to load; the editor's writes are command-gated.
	$edit = isset( $_GET['edit'] ) && \is_string( $_GET['edit'] ) ? \sanitize_text_field( \wp_unslash( $_GET['edit'] ) ) : '';
	if ( null !== $handle && \in_array( $edit, Intelligence_Blocks::pages(), true ) ) {
		enqueue_layout_editor( Intelligence_Blocks::NAMESPACE . "/{$edit}" );
	}
}

/**
 * Register the layout editor's bundle, without enqueueing it, and hand it to
 * `Composition::editor()`, which loads the canvas, the bundle and the
 * context's schemas for a `tune` caller and nothing for any other; its
 * stylesheet joins it once it loads. Nothing loads while the bundle is
 * unbuilt.
 *
 * @param string $context The context the editor lays out.
 */
function enqueue_layout_editor( string $context ): void {
	$dir = __DIR__ . '/build/layout-editor';
	if ( ! \file_exists( "{$dir}/layout-editor.js" ) ) {
		return;
	}
	$asset   = \Newspack_Nodes\Core::arr( require "{$dir}/layout-editor.asset.php" );
	$deps    = \array_map( \Newspack_Nodes\Core::as_string( ... ), \array_values( \Newspack_Nodes\Core::arr( $asset['dependencies'] ) ) );
	$version = \Newspack_Nodes\Core::as_string( $asset['version'] );
	\wp_register_script( LAYOUT_EDITOR_HANDLE, \plugins_url( 'build/layout-editor/layout-editor.js', __FILE__ ), $deps, $version, true );
	if ( \Newspack_Nodes\Composition::editor( $context, LAYOUT_EDITOR_HANDLE ) && \file_exists( "{$dir}/layout-editor.css" ) ) {
		\wp_enqueue_style( LAYOUT_EDITOR_HANDLE, \plugins_url( 'build/layout-editor/layout-editor.css', __FILE__ ), [ 'wp-components', 'wp-block-editor' ], $version );
		\wp_style_add_data( LAYOUT_EDITOR_HANDLE, 'rtl', 'replace' );
	}
}

/**
 * The word a reader sees for each stored key the page shows, one map per
 * closed list, each localized; the words that would name the operator name
 * it where the deployment does, and stand without a name where it does not.
 *
 * @param Operator $operator Who runs the deployment.
 * @return array<string,array<string,string>>
 */
function screen_words( Operator $operator ): array {
	$named = $operator->named;
	return [
		'kindWords'       => [
			'award'           => \__( 'Award', 'newspack-intelligence' ),
			'launch'          => \__( 'Launch', 'newspack-intelligence' ),
			'release'         => \__( 'Release', 'newspack-intelligence' ),
			'funding'         => \__( 'Funding', 'newspack-intelligence' ),
			'hire'            => \__( 'Hire', 'newspack-intelligence' ),
			'departure'       => \__( 'Departure', 'newspack-intelligence' ),
			'partnership'     => \__( 'Partnership', 'newspack-intelligence' ),
			'incident'        => \__( 'Incident', 'newspack-intelligence' ),
			'bug'             => \__( 'Bug', 'newspack-intelligence' ),
			'feature_request' => \__( 'Feature request', 'newspack-intelligence' ),
			'complaint'       => \__( 'Complaint', 'newspack-intelligence' ),
			'praise'          => \__( 'Praise', 'newspack-intelligence' ),
			/* translators: %s: who runs the deployment. */
			'churned'         => $named ? \sprintf( \__( 'Leaving %s', 'newspack-intelligence' ), $operator->name ) : \__( 'Leaving', 'newspack-intelligence' ),
			'churn_signal'    => \__( 'Churn risk', 'newspack-intelligence' ),
			'research'        => \__( 'Research', 'newspack-intelligence' ),
			'other'           => \__( 'Other', 'newspack-intelligence' ),
		],
		'roleWords'       => [
			'client'           => \__( 'Client', 'newspack-intelligence' ),
			'former_client'    => \__( 'Former client', 'newspack-intelligence' ),
			'prospect'         => \__( 'Prospect', 'newspack-intelligence' ),
			'competitor'       => \__( 'Competitor', 'newspack-intelligence' ),
			'partner'          => \__( 'Partner', 'newspack-intelligence' ),
			'funder'           => \__( 'Funder', 'newspack-intelligence' ),
			'industry_body'    => \__( 'Industry group', 'newspack-intelligence' ),
			/* translators: %s: who runs the deployment. */
			'operator_product' => $named ? \sprintf( \__( '%s product', 'newspack-intelligence' ), $operator->name ) : \__( 'Own product', 'newspack-intelligence' ),
		],
		'typeWords'       => [
			'organization' => \__( 'Company', 'newspack-intelligence' ),
			'person'       => \__( 'Person', 'newspack-intelligence' ),
			'place'        => \__( 'Place', 'newspack-intelligence' ),
			'product'      => \__( 'Product', 'newspack-intelligence' ),
			'topic'        => \__( 'Topic', 'newspack-intelligence' ),
			'event'        => \__( 'Event', 'newspack-intelligence' ),
		],
		'stateWords'      => [
			'accepted' => \__( 'Accepted', 'newspack-intelligence' ),
			'proposed' => \__( 'Suggested', 'newspack-intelligence' ),
			'rejected' => \__( 'Rejected', 'newspack-intelligence' ),
		],
		'nameKindWords'   => [
			'canonical' => \__( 'Official name', 'newspack-intelligence' ),
			'alias'     => \__( 'Also known as', 'newspack-intelligence' ),
			'acronym'   => \__( 'Short name', 'newspack-intelligence' ),
			'locality'  => \__( 'Place', 'newspack-intelligence' ),
		],
		'identifierWords' => [
			'domain'           => \__( 'Website', 'newspack-intelligence' ),
			'github_org'       => \__( 'GitHub organization', 'newspack-intelligence' ),
			'github_repo'      => \__( 'GitHub repository', 'newspack-intelligence' ),
			'linkedin_company' => \__( 'LinkedIn page', 'newspack-intelligence' ),
			'x_handle'         => \__( 'X account', 'newspack-intelligence' ),
			'site_id'          => \__( 'Site ID', 'newspack-intelligence' ),
			'linear_team'      => \__( 'Linear team', 'newspack-intelligence' ),
		],
		'predicateWords'  => [
			'works_for'     => \__( 'works for', 'newspack-intelligence' ),
			'part_of'       => \__( 'is part of', 'newspack-intelligence' ),
			'funds'         => \__( 'funds', 'newspack-intelligence' ),
			'partners_with' => \__( 'partners with', 'newspack-intelligence' ),
			'competes_with' => \__( 'competes with', 'newspack-intelligence' ),
			'uses_product'  => \__( 'uses', 'newspack-intelligence' ),
			'won'           => \__( 'won', 'newspack-intelligence' ),
			'launched'      => \__( 'launched', 'newspack-intelligence' ),
			'located_in'    => \__( 'is in', 'newspack-intelligence' ),
		],
		'sourceWords'     => [
			'github'   => \__( 'GitHub', 'newspack-intelligence' ),
			'feed'     => \__( 'RSS feed', 'newspack-intelligence' ),
			'p2'       => \__( 'P2', 'newspack-intelligence' ),
			'linear'   => \__( 'Linear', 'newspack-intelligence' ),
			'slack'    => \__( 'Slack', 'newspack-intelligence' ),
			'unthread' => \__( 'Unthread', 'newspack-intelligence' ),
		],
		'signalWords'     => [
			/* translators: %s: what jumped, a kind of news, a topic or a theme. */
			'spike'  => \__( '%s jumped', 'newspack-intelligence' ),
			'burst'  => \__( 'Published more than usual', 'newspack-intelligence' ),
			'drop'   => \__( 'Published less than usual', 'newspack-intelligence' ),
			'silent' => \__( 'Went quiet', 'newspack-intelligence' ),
		],
		'healthWords'     => [
			'collection' => \__( 'Collecting', 'newspack-intelligence' ),
			'backlog'    => \__( 'Matching items to clients', 'newspack-intelligence' ),
			'dropped'    => \__( 'Skipped', 'newspack-intelligence' ),
			'extraction' => \__( 'Summaries', 'newspack-intelligence' ),
			'calls'      => \__( 'AI requests waiting', 'newspack-intelligence' ),
		],
		'tierWords'       => [
			'public'     => \__( 'Public', 'newspack-intelligence' ),
			'internal'   => \__( 'Internal', 'newspack-intelligence' ),
			'restricted' => \__( 'Restricted', 'newspack-intelligence' ),
		],
		'spanWords'       => [
			'day'   => \__( 'Day', 'newspack-intelligence' ),
			'week'  => \__( 'Week', 'newspack-intelligence' ),
			'month' => \__( 'Month', 'newspack-intelligence' ),
		],
		'axisWords'       => [
			'kind'  => \__( 'Kind of news', 'newspack-intelligence' ),
			'topic' => \__( 'Topic', 'newspack-intelligence' ),
			'theme' => \__( 'Theme', 'newspack-intelligence' ),
		],
		'linkRoleWords'   => [
			'subject'   => \__( 'The subject', 'newspack-intelligence' ),
			'mention'   => \__( 'Mentioned', 'newspack-intelligence' ),
			'topic'     => \__( 'A topic', 'newspack-intelligence' ),
			'publisher' => \__( 'The publisher', 'newspack-intelligence' ),
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
		'markWords'       => [
			'newsletter' => \__( 'Newsletter', 'newspack-intelligence' ),
			'linkedin'   => \__( 'LinkedIn', 'newspack-intelligence' ),
			'site'       => \__( 'Site', 'newspack-intelligence' ),
		],
	];
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
 * Mount `intelligence`, the graph's read and curation commands, into the
 * per-request graph, the same way the substrate mounts its own CIs.
 * Idempotent: a second call in one request finds it mounted.
 */
function mount_service_cis( \Newspack_Nodes\Command_Interpreter_Node $base_interpreter ): void {
	if ( null === \Newspack_Nodes\Core::node( 'intelligence' ) ) {
		$base_interpreter->make_node( 'Intelligence_CI', 'intelligence' );
	}
}

/**
 * Register the `wp newspack-intelligence` commands, and the line `wp nodes
 * doctor` opens with while the lists file does not resolve.
 *
 * @api Called by the plugins_loaded bootstrap; tests drive it against a recorder.
 */
function register_cli_commands(): void {
	\WP_CLI::add_hook( 'before_invoke:nodes doctor', [ Intelligence_Health::class, 'warn_missing_lists' ] );
	\WP_CLI::add_command( 'newspack-intelligence clients', '\\Newspack_Intelligence\\CLI\\Clients_CLI_Command' );
	\WP_CLI::add_command( 'newspack-intelligence eval', '\\Newspack_Intelligence\\CLI\\Eval_CLI_Command' );
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
			|| ! \Newspack_Nodes\Bootstrap::version_at_least( '2.150.0', 'Newspack Intelligence' ) ) {
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

		// The blocks and contexts the page's layouts are composed of.
		\add_filter( \Newspack_Nodes\Composition::FILTER, Intelligence_Blocks::declare( ... ) );

		// The MCP door agents read and curate the graph through.
		( new Intelligence_Mcp_Door() )->wire();
	},
	12
);

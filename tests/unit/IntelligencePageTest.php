<?php
/**
 * IntelligencePageTest: the Intelligence admin page's menu, markup and bundle.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_P2_Node;
use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Intelligence\Intelligence_Health;
use Newspack_Intelligence\Prompts;
use Newspack_Intelligence\Restricted_Settings;
use Newspack_Intelligence\Source_Node;
use Newspack_Intelligence\Unthread_Source_Node;
use Newspack_Nodes\Admin\Admin;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Config;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use function Newspack_Intelligence\enqueue_intelligence_assets;
use function Newspack_Intelligence\register_intelligence_admin_page;
use const Newspack_Intelligence\INTELLIGENCE_MENU_SLUG;
use const Newspack_Intelligence\INTELLIGENCE_MOUNT_ID;
use const Newspack_Intelligence\LAYOUT_EDITOR_HANDLE;

require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/declares-intelligence-blocks.php';

final class IntelligencePageTest extends TestCase {
	use Signs_In_Users;
	use Declares_Intelligence_Blocks;

	/** The hook suffix WordPress gives the Intelligence page. */
	private const PAGE_HOOK = 'toplevel_page_newspack-intelligence';

	/** The capability the READ role maps to under the test's capability map. */
	private const READ_CAP = 'newspack_intel_test_read';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['_current_user_can']  = true;
		$GLOBALS['_admin_menu_pages']  = [];
		$GLOBALS['_enqueued_scripts']  = [];
		$GLOBALS['_enqueued_styles']   = [];
		$GLOBALS['_localized_scripts'] = [];
		$GLOBALS['_style_data']        = [];
		$_GET                          = [];
		( new Admin() )->register_dataviews();
		\add_filter( 'newspack_nodes/capability_map', static fn ( array $map ): array => [ Capabilities::READ => self::READ_CAP ] + $map );
		$this->declare_intelligence_blocks();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_current_user_can'],
			$GLOBALS['_admin_menu_pages'],
			$GLOBALS['_enqueued_scripts'],
			$GLOBALS['_enqueued_styles'],
			$GLOBALS['_localized_scripts'],
			$GLOBALS['_style_data'],
			$GLOBALS['_check_admin_referer_asked'],
			$_GET,
			$_POST
		);
		self::forget_users();
		$this->forget_compositions();
		\wp_shim_reset_block_editor();
		unset( $GLOBALS['_registered_scripts'] );
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/**
	 * A reader's page naming an edit loads no editor and is sent no schema,
	 * and reads the layout as every reader does; a curator's loads the
	 * editor for the page it names, its schemas localized on the editor's
	 * bundle; and the page's own bundle carries no block-editor script.
	 */
	public function test_a_readers_page_never_loads_the_editor(): void {
		$this->declare_probe( 'newspack-intelligence/briefing' );
		Composition_Store::save( 'newspack-intelligence/briefing', '<!-- wp:newspack-intelligence/probe {"heading":"Heron desk"} /-->' );
		$_GET = [
			'page' => INTELLIGENCE_MENU_SLUG,
			'edit' => 'briefing',
		];

		Capabilities::$session_scope = Capabilities::READ;
		enqueue_intelligence_assets( self::PAGE_HOOK );

		$handles = \array_column( $GLOBALS['_enqueued_scripts'], 0 );
		$this->assertNotContains( 'wp-block-editor', $handles );
		$this->assertNotContains( LAYOUT_EDITOR_HANDLE, $handles );
		$this->assertArrayNotHasKey( LAYOUT_EDITOR_HANDLE, $GLOBALS['_localized_scripts'] );
		$compositions = $this->localized( 'newspack-intelligence' )['compositions'];
		$this->assertSame( [ 'newspack-intelligence/briefing', 'newspack-intelligence/lens', 'newspack-intelligence/entity' ], \array_keys( $compositions ) );
		$this->assertSame( [ 'saved', 'Heron desk' ], [ $compositions['newspack-intelligence/briefing']['source'], $compositions['newspack-intelligence/briefing']['blocks'][0]['attributes']['heading'] ] );
		$this->assertSame( 'default', $compositions['newspack-intelligence/entity']['source'] );

		$GLOBALS['_enqueued_scripts'] = [];
		Capabilities::$session_scope  = Capabilities::TUNE;
		enqueue_intelligence_assets( self::PAGE_HOOK );

		$handles = \array_column( $GLOBALS['_enqueued_scripts'], 0 );
		$this->assertContains( 'wp-block-editor', $handles );
		$this->assertContains( LAYOUT_EDITOR_HANDLE, $handles );
		$editor = $GLOBALS['_localized_scripts'][ LAYOUT_EDITOR_HANDLE ];
		$this->assertSame( Composition::EDITOR_GLOBAL, $editor['object_name'] );
		$this->assertSame( 'newspack-intelligence/briefing', $editor['data']['context'] );
		$this->assertSame( \array_keys( Composition::schemas( 'newspack-intelligence/briefing' )['blocks'] ), \array_keys( $editor['data']['blocks'] ) );
		$this->assertContains( 'newspack-intelligence/probe', \array_keys( $editor['data']['blocks'] ) );
		$this->assertSame( \plugins_url( 'build/layout-editor/layout-editor.js', \dirname( __DIR__, 2 ) . '/newspack-intelligence.php' ), $GLOBALS['_registered_scripts'][ LAYOUT_EDITOR_HANDLE ]['src'] );
		$this->assertArrayHasKey( LAYOUT_EDITOR_HANDLE, $GLOBALS['_enqueued_styles'] );
		$this->assertSame( 'replace', $GLOBALS['_style_data'][ LAYOUT_EDITOR_HANDLE ]['rtl'] );

		$asset = require \dirname( __DIR__, 2 ) . '/build/intelligence/index.asset.php';
		$this->assertNotContains( 'wp-block-editor', $asset['dependencies'] );
		$this->assertNotContains( 'wp-blocks', $asset['dependencies'] );
	}

	/** An edit naming a page that is not composed loads no editor, for a curator either. */
	public function test_an_edit_of_a_page_not_composed_loads_nothing(): void {
		Capabilities::$session_scope = Capabilities::TUNE;
		$_GET                        = [
			'page' => INTELLIGENCE_MENU_SLUG,
			'edit' => 'folders',
		];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertNotContains( 'wp-block-editor', \array_column( $GLOBALS['_enqueued_scripts'], 0 ) );
		$this->assertArrayNotHasKey( LAYOUT_EDITOR_HANDLE, $GLOBALS['_registered_scripts'] ?? [] );
	}

	/**
	 * A stored layout that no longer reads stands where the layout would,
	 * and the page still renders for a signed-in user: a reader's page holds
	 * the class word, an operator's the refusal.
	 *
	 * @param string $scope The session scope the page renders under.
	 * @param string $error What the page holds for the lens layout.
	 */
	#[DataProvider( 'unread_layouts' )]
	public function test_a_layout_that_no_longer_reads_is_sent_as_its_refusal( string $scope, string $error ): void {
		\update_option( Composition_Store::OPTION_PREFIX . 'newspack-intelligence/lens', [ 'markup' => '<!-- wp:newspack-intelligence/retired /-->', 'actor' => 'heron-ops', 'ts' => 1792000000.5 ] );
		self::sign_in( 8402, 'heron-ops', 'manage_options' );
		Capabilities::$session_scope = $scope;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertSame(
			[
				'context' => 'newspack-intelligence/lens',
				'error'   => $error,
			],
			$this->localized( 'newspack-intelligence' )['compositions']['newspack-intelligence/lens']
		);
	}

	/**
	 * Each scope, and what its page holds for a layout that no longer reads.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function unread_layouts(): array {
		return [
			'a reader'    => [ Capabilities::READ, 'this layout could not be read' ],
			'an operator' => [ Capabilities::MANAGE, 'composition newspack-intelligence/lens: newspack-intelligence/retired is not a declared block' ],
		];
	}

	public function test_a_reader_gets_the_intelligence_menu_under_the_read_capability(): void {
		Capabilities::$session_scope = Capabilities::READ;

		register_intelligence_admin_page();

		$this->assertCount( 1, $GLOBALS['_admin_menu_pages'] );
		[ $page_title, $menu_title, $capability, $slug ] = $GLOBALS['_admin_menu_pages'][0];
		$this->assertSame( 'Intelligence', $page_title );
		$this->assertSame( 'Intelligence', $menu_title );
		$this->assertSame( self::READ_CAP, $capability );
		$this->assertSame( 'newspack-intelligence', $slug );
		$this->assertSame( INTELLIGENCE_MENU_SLUG, $slug );
	}

	/**
	 * The admin pages render classes Composer loads only past the substrate
	 * handshake, so the deferred loader registers them, never plugin-file
	 * scope.
	 */
	public function test_the_handshake_gated_loader_registers_the_admin_pages(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( 'Newspack_Intelligence\\register_clients_admin_page', $GLOBALS['_wp_actions']['admin_menu'] ?? [] );

		$loader();

		$this->assertSame(
			[ 'Newspack_Intelligence\\register_intelligence_admin_page', 'Newspack_Intelligence\\register_clients_admin_page' ],
			\array_values( \array_filter( $GLOBALS['_wp_actions']['admin_menu'] ?? [], 'is_string' ) )
		);
		$this->assertContains( 'Newspack_Intelligence\\enqueue_intelligence_assets', $GLOBALS['_wp_actions']['admin_enqueue_scripts'] ?? [] );
	}

	/** The loader wires each restricted-access admin-post to its own action. */
	public function test_the_loader_wires_the_restricted_access_admin_posts(): void {
		$this->plugin_loader()();
		self::sign_in( 8401, 'heron-ops', 'manage_options' );
		$_POST = [ 'login' => 'ghost-heron' ];

		foreach ( [ Restricted_Settings::GRANT_ACTION, Restricted_Settings::REVOKE_ACTION ] as $action ) {
			$GLOBALS['_check_admin_referer_asked'] = [];
			$caught                                = $this->caught( static fn () => \do_action( "admin_post_{$action}" ), "{$action} is not wired" );
			$this->assertSame( 'wp_die: no user: ghost-heron', $caught->getMessage(), $action );
			$this->assertSame( [ $action ], $GLOBALS['_check_admin_referer_asked'], $action );
		}
	}

	/** The Intelligence page joins the substrate's overlay registry only past the handshake. */
	public function test_the_loader_declares_the_intelligence_page_an_overlay_page(): void {
		$loader = $this->plugin_loader();
		$this->assertNotContains( INTELLIGENCE_MENU_SLUG, \apply_filters( 'newspack_nodes/overlay_pages', [] ) );

		$loader();

		$this->assertSame( [ INTELLIGENCE_MENU_SLUG ], \apply_filters( 'newspack_nodes/overlay_pages', [] ) );
	}

	public function test_a_caller_without_read_gets_no_menu(): void {
		$GLOBALS['_current_user_can'] = false;

		register_intelligence_admin_page();

		$this->assertSame( [], $GLOBALS['_admin_menu_pages'] );
	}

	public function test_the_page_anchors_notices_above_the_app(): void {
		register_intelligence_admin_page();
		$callback = $GLOBALS['_admin_menu_pages'][0][4];

		\ob_start();
		$callback();
		$html = (string) \ob_get_clean();

		$this->assertStringStartsWith( '<div class="wrap"><hr class="wp-header-end"><div id="' . INTELLIGENCE_MOUNT_ID . '"', $html );
	}

	public function test_a_reader_gets_the_bundle_with_curation_hidden(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$data = $this->localized( 'newspack-intelligence' );
		$this->assertSame( '', $data['canTune'], 'a reader is sent the empty string' );
		$this->assertSame( \plugins_url( 'build/intelligence/index.js', \dirname( __DIR__, 2 ) . '/newspack-intelligence.php' ), \array_column( $GLOBALS['_enqueued_scripts'], 1, 0 )['newspack-intelligence'] ?? null );
	}

	public function test_a_tuner_gets_curation_and_the_vocabulary(): void {
		Capabilities::$session_scope = Capabilities::TUNE;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$data = $this->localized( 'newspack-intelligence' );
		$this->assertSame( '1', $data['canTune'], 'a curator is sent the string 1' );
		$vocabulary = $data['vocabulary'];
		$this->assertSame(
			[
				'roles'           => Intel_Event::ROLES,
				'nameKinds'       => Intel_Event::NAME_KINDS,
				'identifierKinds' => Intel_Event::IDENTIFIER_KINDS,
				'itemKinds'       => Intel_Event::ITEM_KINDS,
				'tiers'           => Intel_Event::TIERS,
				'trendAxes'       => [ 'kind', 'topic', 'theme' ],
				'buckets'         => [ 'day', 'week', 'month' ],
				'lensLists'       => [
					[ 'field' => 'kinds', 'option' => 'kind', 'values' => Intel_Event::ITEM_KINDS ],
					[ 'field' => 'topics', 'option' => 'topic', 'values' => null ],
					[ 'field' => 'roles', 'option' => 'role', 'values' => Intel_Event::ROLES ],
					[ 'field' => 'sources', 'option' => 'source', 'values' => Intel_Event::SOURCES ],
				],
			],
			\array_filter( $vocabulary, static fn ( string $map ): bool => ! \str_ends_with( $map, 'Words' ), ARRAY_FILTER_USE_KEY )
		);
		$this->assertSame( [ 'wp-components', 'newspack-nodes-graph' ], $GLOBALS['_enqueued_styles']['newspack-intelligence']['deps'] );
	}

	/** The page learns whether its reader holds manage, as `canTune` says tune: the string 1 or the empty string. */
	public function test_page_localizes_can_manage(): void {
		$_GET = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		Capabilities::$session_scope = Capabilities::TUNE;
		enqueue_intelligence_assets( self::PAGE_HOOK );
		$curator = $this->localized( 'newspack-intelligence' );
		Capabilities::$session_scope = Capabilities::MANAGE;
		enqueue_intelligence_assets( self::PAGE_HOOK );
		$operator = $this->localized( 'newspack-intelligence' );

		$this->assertSame( [ '1', '' ], [ $curator['canTune'], $curator['canManage'] ], 'a curator is sent the empty string' );
		$this->assertSame( [ '1', '1' ], [ $operator['canTune'], $operator['canManage'] ], 'an operator is sent the string 1' );
	}

	/** The page learns its reader's user id, as a string, so what it keeps in the browser is the reader's own. */
	public function test_page_localizes_the_readers_user_id(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];
		$GLOBALS['_current_user_id'] = 6143;

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertSame( '6143', $this->localized( 'newspack-intelligence' )['userId'] );
	}

	public function test_the_vocabulary_words_every_link_role_method_and_mark(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$vocabulary = $this->localized( 'newspack-intelligence' )['vocabulary'];
		$this->assertSame( Intel_Event::LINK_ROLES, \array_keys( $vocabulary['linkRoleWords'] ) );
		$this->assertSame( Intel_Event::METHODS, \array_keys( $vocabulary['methodWords'] ) );
		$this->assertSame( 'Mentioned', $vocabulary['linkRoleWords']['mention'] );
		$this->assertSame( 'The publisher', $vocabulary['linkRoleWords']['publisher'] );
		$this->assertSame( 'a similar name', $vocabulary['methodWords']['ner_fuzzy'] );
		$this->assertSame( Intel_Event::MARKS, \array_keys( $vocabulary['markWords'] ) );
		$this->assertSame( Intel_Event::ROLES, \array_keys( $vocabulary['roleWords'] ) );
		$this->assertSame( Intel_Event::IDENTIFIER_KINDS, \array_keys( $vocabulary['identifierWords'] ) );
		$this->assertSame( 'Former client', $vocabulary['roleWords']['former_client'] );
		$this->assertSame( 'Site ID', $vocabulary['identifierWords']['site_id'] );
		$this->assertSame( 'LinkedIn', $vocabulary['markWords']['linkedin'] );
	}

	/** Every closed list a screen shows has a word for each of its values, none spelled as a key. */
	public function test_vocabulary_words_every_kind_role_type_and_identifier(): void {
		$vocabulary = $this->vocabulary();

		$this->assertSame( Intel_Event::ITEM_KINDS, \array_keys( $vocabulary['kindWords'] ) );
		$this->assertSame( Intel_Event::ROLES, \array_keys( $vocabulary['roleWords'] ) );
		$this->assertSame( Intel_Event::TYPES, \array_keys( $vocabulary['typeWords'] ) );
		$this->assertSame( Intel_Event::NAME_KINDS, \array_keys( $vocabulary['nameKindWords'] ) );
		$this->assertSame( Intel_Event::IDENTIFIER_KINDS, \array_keys( $vocabulary['identifierWords'] ) );
		$this->assertSame( Intel_Event::PREDICATES, \array_keys( $vocabulary['predicateWords'] ) );
		$this->assertSame( Intel_Event::TIERS, \array_keys( $vocabulary['tierWords'] ) );
		$this->assertSame( Graph_Query::buckets(), \array_keys( $vocabulary['spanWords'] ) );
		$this->assertSame( Graph_Query::trend_axes(), \array_keys( $vocabulary['axisWords'] ) );
		$this->assertSame( [ 'accepted', 'proposed', 'rejected' ], \array_keys( $vocabulary['stateWords'] ) );
		$this->assertSame( Intel_Event::SOURCES, \array_keys( $vocabulary['sourceWords'] ) );
		$this->assertSame( [ 'spike', 'burst', 'drop', 'silent' ], \array_keys( $vocabulary['signalWords'] ) );
		$this->assertSame( [ 'collection', 'backlog', 'dropped', 'extraction', 'calls' ], \array_keys( $vocabulary['healthWords'] ) );
		foreach ( $vocabulary as $map => $words ) {
			if ( \str_ends_with( $map, 'Words' ) ) {
				foreach ( $words as $key => $word ) {
					$this->assertDoesNotMatchRegularExpression( '/[_:]/', $word, "{$map}.{$key} is worded as a key" );
				}
			}
		}
		$this->assertSame( 'Churn risk', $vocabulary['kindWords']['churn_signal'] );
		$this->assertSame( 'Feature request', $vocabulary['kindWords']['feature_request'] );
		$this->assertSame( 'Industry group', $vocabulary['roleWords']['industry_body'] );
		$this->assertSame( 'Company', $vocabulary['typeWords']['organization'] );
		$this->assertSame( 'Also known as', $vocabulary['nameKindWords']['alias'] );
		$this->assertSame( 'Website', $vocabulary['identifierWords']['domain'] );
		$this->assertSame( 'is part of', $vocabulary['predicateWords']['part_of'] );
		$this->assertSame( 'RSS feed', $vocabulary['sourceWords']['feed'] );
		$this->assertSame( 'Suggested', $vocabulary['stateWords']['proposed'] );
		$this->assertSame( 'Went quiet', $vocabulary['signalWords']['silent'] );
		$this->assertSame( 'Matching items to clients', $vocabulary['healthWords']['backlog'] );
		$this->assertSame( 'Internal', $vocabulary['tierWords']['internal'] );
		$this->assertSame( 'Month', $vocabulary['spanWords']['month'] );
		$this->assertSame( 'Kind of news', $vocabulary['axisWords']['kind'] );
	}

	/**
	 * Every source a Source_Node class records is one of
	 * `Intel_Event::SOURCES`, every one of them is recorded by some class,
	 * and each list naming sources reads that one: the page's words, the
	 * lens list's vocabulary, the schema's source arg and every source a
	 * decision labels.
	 */
	public function test_every_source_a_source_node_records_has_a_word_and_the_lists_agree(): void {
		$includes = \realpath( \dirname( __DIR__, 2 ) . '/includes' );
		$recorded = [];
		foreach ( require \dirname( __DIR__, 2 ) . '/vendor/composer/autoload_classmap.php' as $class => $file ) {
			if ( \str_starts_with( (string) \realpath( $file ), (string) $includes ) && \is_subclass_of( $class, Source_Node::class ) && ! ( new \ReflectionClass( $class ) )->isAbstract() ) {
				$recorded[ $class ] = \constant( "{$class}::SOURCE" );
			}
		}
		$this->assertCount( 9, $recorded );
		$this->assertSame( [], \array_diff( $recorded, Intel_Event::SOURCES ), 'a source with no place in Intel_Event::SOURCES' );
		$this->assertSame( [], \array_values( \array_diff( Intel_Event::SOURCES, $recorded ) ), 'a source no class records' );
		$this->assertSame( 'unthread', Unthread_Source_Node::SOURCE );
		$this->assertSame( 'p2', Context_A8C_P2_Node::SOURCE );

		$this->assertSame( Intel_Event::SOURCES, \array_keys( $this->vocabulary()['sourceWords'] ) );
		$lists = \array_column( $this->vocabulary()['lensLists'], 'values', 'field' );
		$this->assertSame( Intel_Event::SOURCES, $lists['sources'] );
		$this->assertSame( Intel_Event::SOURCES, Graph_Query::LENS_LISTS['sources'][1] );
		$search = \array_column( \array_column( Intelligence_CI_Node::node_schema()['commands'], 'args', 'name' )['search'], 'description', 'name' );
		$this->assertStringEndsWith( ': ' . \implode( ', ', Intel_Event::SOURCES ) . '. Repeat it to match any.', $search['source'] );
		$labels = ( new \ReflectionClassConstant( Prompts::class, 'SOURCE_LABELS' ) )->getValue();
		$this->assertSame( [], \array_diff( \array_keys( $labels ), Intel_Event::SOURCES ) );
	}

	/** A deployment that names no operator words the kinds and roles that would name it without a name. */
	public function test_unnamed_operator_words(): void {
		$vocabulary = $this->vocabulary();

		$this->assertSame( '', $this->localized( 'newspack-intelligence' )['operatorName'] );
		$this->assertSame( 'Leaving', $vocabulary['kindWords']['churned'] );
		$this->assertSame( 'Own product', $vocabulary['roleWords']['operator_product'] );
	}

	/** A deployment naming its operator on its LLM_Call node words those kinds and roles with the name. */
	public function test_named_operator_words(): void {
		$this->run_operator( 'Lakeside Works' );

		$vocabulary = $this->vocabulary();

		$this->assertSame( 'Lakeside Works', $this->localized( 'newspack-intelligence' )['operatorName'] );
		$this->assertSame( 'Leaving Lakeside Works', $vocabulary['kindWords']['churned'] );
		$this->assertSame( 'Lakeside Works product', $vocabulary['roleWords']['operator_product'] );
	}

	/** Every word the page is sent passes through translation. */
	public function test_vocabulary_is_translated(): void {
		\add_filter( 'gettext', static fn ( string $text ): string => "⟦{$text}⟧" );

		$vocabulary = $this->vocabulary();

		foreach ( $vocabulary as $map => $words ) {
			if ( \str_ends_with( $map, 'Words' ) ) {
				foreach ( $words as $key => $word ) {
					$this->assertMatchesRegularExpression( '/^⟦.*⟧$/u', $word, "{$map}.{$key} is not translated" );
				}
			}
		}
		$this->assertSame( '⟦Leaving⟧', $vocabulary['kindWords']['churned'] );
	}

	/**
	 * The vocabulary a reader's page is sent.
	 *
	 * @return array<string,mixed>
	 */
	private function vocabulary(): array {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];
		enqueue_intelligence_assets( self::PAGE_HOOK );
		return $this->localized( 'newspack-intelligence' )['vocabulary'];
	}

	/**
	 * Run a topology whose LLM_Call node names `$name` its operator.
	 *
	 * @param string $name Who runs the deployment.
	 */
	private function run_operator( string $name ): void {
		$dir = $this->make_temp_dir( 'intel-page-operator-' );
		\file_put_contents( "{$dir}/heron-operator.tsl", "make_node Intel_Event_Writer intel:writer intel:items intel:ontology intel:watches intel:asks\nmake_node LLM_Call intel:llm ai-relay-4417\ncmd intel:llm:config set_operator \"{$name}\"\nsecure\n" );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		Topology_Registry::register_user_dir( $dir );
		\update_option( 'newspack_nodes_topologies', [ 'heron-operator' ] );
		Config::reset();
		Bootstrap::forget_node_tables();
	}

	/** The page names the operator the running topology's LLM_Call node names, else generic wording. */
	public function test_page_localizes_the_operator(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];
		enqueue_intelligence_assets( self::PAGE_HOOK );
		$this->assertSame( [ 'name' => 'the organization', 'heading' => 'The organization' ], $this->localized( 'newspack-intelligence' )['operator'], 'no topology runs' );

		$dir = $this->make_temp_dir( 'intel-page-topologies-' );
		\file_put_contents( "{$dir}/heron-page.tsl", "make_node Intel_Event_Writer intel:writer intel:items intel:ontology intel:watches intel:asks\nmake_node LLM_Call intel:llm ai-relay-4417\ncmd intel:llm:config set_operator \"Quillmere Cooperative\"\nsecure\n" );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		Topology_Registry::register_user_dir( $dir );
		\update_option( 'newspack_nodes_topologies', [ 'heron-page' ] );
		Config::reset();
		Bootstrap::forget_node_tables();
		enqueue_intelligence_assets( self::PAGE_HOOK );

		$data = $this->localized( 'newspack-intelligence' );
		$this->assertSame( [ 'name' => 'Quillmere Cooperative', 'heading' => 'Quillmere Cooperative' ], $data['operator'] );
		$this->assertSame( 'Quillmere Cooperative product', $data['vocabulary']['roleWords']['operator_product'] );
	}

	/**
	 * A topology whose graph will not read still renders the page, under the
	 * generic name, while the health strip's slices name the line at fault.
	 *
	 * @dataProvider unreadable_llm_lines
	 *
	 * @param string $line The LLM_Call config line that will not read.
	 * @param string $why  What the health strip names.
	 */
	public function test_a_graph_that_will_not_read_renders_the_page_and_the_strip_names_it( string $line, string $why ): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];
		$dir                         = $this->make_temp_dir( 'intel-page-unread-' );
		\file_put_contents( "{$dir}/heron-unread.tsl", "make_node Intel_Event_Writer intel:writer intel:items intel:ontology intel:watches intel:asks\nmake_node LLM_Call intel:llm ai-relay-4417\n{$line}\nsecure\n" );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		Topology_Registry::register_user_dir( $dir );
		\update_option( 'newspack_nodes_topologies', [ 'heron-unread' ] );
		Config::reset();
		Bootstrap::forget_node_tables();

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertSame( [ 'name' => 'the organization', 'heading' => 'The organization' ], $this->localized( 'newspack-intelligence' )['operator'] );
		try {
			Intelligence_Health::answer( [ 'what' => 'extraction' ] );
			$this->fail( 'the health strip answered past a line that will not read' );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'heron-unread will not read', $e->getMessage() );
			$this->assertStringContainsString( $why, $e->getMessage() );
		}
	}

	/**
	 * LLM_Call lines a graph cannot read, each beside what the strip names.
	 *
	 * @return array<string,array{string,string}>
	 */
	public static function unreadable_llm_lines(): array {
		return [
			'an operator with no name' => [ 'cmd intel:llm:config set_operator', 'missing required argument' ],
			'a non-http api url'       => [ 'cmd intel:llm:config set_api_url ftp://relay-4417.example', 'ftp://relay-4417.example' ],
		];
	}

	public function test_the_page_enqueues_the_dataviews_its_bundle_reads(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertContains( Admin::DATAVIEWS_HANDLE, \array_column( $GLOBALS['_enqueued_scripts'], 0 ) );
		$this->assertArrayHasKey( Admin::DATAVIEWS_HANDLE, $GLOBALS['_enqueued_styles'] );
	}

	public function test_the_bundle_stamps_this_plugins_version_in_the_header(): void {
		Capabilities::$session_scope = Capabilities::READ;
		$_GET                        = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( self::PAGE_HOOK );

		$this->assertSame( \NEWSPACK_INTELLIGENCE_VERSION, $this->localized( 'newspack-intelligence' )['version'] ?? null );
	}

	public function test_another_page_enqueues_nothing_and_reads_no_capability(): void {
		$reads = 0;
		\add_filter(
			'newspack_nodes/capability_map',
			static function ( array $map ) use ( &$reads ): array {
				++$reads;
				return $map;
			}
		);
		$_GET = [ 'page' => INTELLIGENCE_MENU_SLUG ];

		enqueue_intelligence_assets( 'settings_page_newspack-intelligence-settings' );

		$this->assertSame( [], $GLOBALS['_enqueued_scripts'] );
		$this->assertSame( [], $GLOBALS['_enqueued_styles'] );
		$this->assertSame( 0, $reads );
	}

	/** The `plugins_loaded` callback newspack-intelligence.php registers. */
	private function plugin_loader(): \Closure {
		$file = \dirname( __DIR__, 2 ) . '/newspack-intelligence.php';
		foreach ( $GLOBALS['_wp_actions']['plugins_loaded'] ?? [] as $callback ) {
			if ( $callback instanceof \Closure && ( new \ReflectionFunction( $callback ) )->getFileName() === $file ) {
				return $callback;
			}
		}
		$this->fail( 'newspack-intelligence.php registers no plugins_loaded loader' );
	}

	/**
	 * The `NewspackNodesData` payload localized onto a handle.
	 *
	 * @param string $handle The script handle.
	 * @return array<string,mixed>
	 */
	private function localized( string $handle ): array {
		$record = $GLOBALS['_localized_scripts'][ $handle ] ?? null;
		if ( null === $record || 'NewspackNodesData' !== $record['object_name'] ) {
			$this->fail( "nothing localized onto {$handle}" );
		}
		return $record['data'];
	}
}

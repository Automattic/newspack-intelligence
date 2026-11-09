<?php
/**
 * IntelligencePageTest: the Intelligence admin page's menu, markup and bundle.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intelligence_Health;
use Newspack_Intelligence\Restricted_Settings;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Config;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use function Newspack_Intelligence\enqueue_intelligence_assets;
use function Newspack_Intelligence\register_intelligence_admin_page;
use const Newspack_Intelligence\INTELLIGENCE_MENU_SLUG;
use const Newspack_Intelligence\INTELLIGENCE_MOUNT_ID;

require_once __DIR__ . '/../support/signs-in-users.php';

final class IntelligencePageTest extends TestCase {
	use Signs_In_Users;

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
		\add_filter( 'newspack_nodes/capability_map', static fn ( array $map ): array => [ Capabilities::READ => self::READ_CAP ] + $map );
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
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
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
		$this->assertSame( \plugins_url( 'build/intelligence/index.js', \dirname( __DIR__, 2 ) . '/newspack-intelligence.php' ), $GLOBALS['_enqueued_scripts'][0][1] );
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
					[ 'field' => 'sources', 'option' => 'source', 'values' => null ],
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
		$this->assertSame( [ 'github', 'feed', 'p2', 'linear', 'slack', 'unthread' ], \array_keys( $vocabulary['sourceWords'] ) );
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

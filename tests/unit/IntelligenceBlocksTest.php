<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_Blocks;
use Newspack_Intelligence\Intelligence_Briefing;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/declares-intelligence-blocks.php';

/**
 * Intelligence's blocks and the three contexts they compose, declared on
 * the substrate's composition layer: registered in the browser alone, each
 * context reading its default from `layouts/`, and every layout refused
 * when it names a lens never saved, or an entity or topic a reader without
 * the restricted tier could not see, because every reader reads it.
 */
final class IntelligenceBlocksTest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;
	use Declares_Intelligence_Blocks;

	/** A proposal only a restricted renewal cites. */
	private const QUIET = 'organization:quiet-buyer';

	private const HOLDER = 7311;

	/** A fixture block reading the keyed attributes the check reads. */
	private const PROBE = 'newspack-intelligence/probe';

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->declare_intelligence_blocks();
	}

	protected function tearDown(): void {
		$this->forget_compositions();
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** The loader declares the three contexts beside the substrate's aside. */
	public function test_the_declarations_pass_the_substrate(): void {
		$this->forget_compositions();
		$GLOBALS['_wp_actions'][ Composition::FILTER ] = [ Composition::declare_aside( ... ) ];
		$this->plugin_loader()();

		$this->assertSame( 24, Composition::schemas( 'newspack-intelligence/entity' )['max_blocks'] );
		foreach ( [ 'briefing', 'lens', 'entity' ] as $page ) {
			$this->assertSame( \dirname( __DIR__, 2 ) . "/layouts/{$page}.html", \realpath( Composition::context_of( "newspack-intelligence/{$page}" )['default'] ) );
		}
	}

	/** A team view's page and a profile admit the substrate's aside, and Intelligence declares no aside of its own. */
	public function test_the_team_view_and_profile_admit_the_substrate_aside(): void {
		foreach ( [ 'newspack-intelligence/lens', 'newspack-intelligence/entity' ] as $context ) {
			$this->assertSame( [ Composition::ASIDE ], Composition::context_of( $context )['admits'], $context );
			$this->assertSame( [ 'title' => 'Aside', 'attributes' => [], 'container' => true, 'multiple' => false ], Composition::schemas( $context )['blocks'][ Composition::ASIDE ], $context );
		}
		$this->assertArrayNotHasKey( 'newspack-intelligence/aside', Intelligence_Blocks::BLOCKS );
	}

	/** The Briefing admits no aside, so a layout naming one is refused. */
	public function test_the_briefing_refuses_the_substrate_aside(): void {
		$this->assertSame( [], Composition::context_of( 'newspack-intelligence/briefing' )['admits'] );
		$this->assertArrayNotHasKey( Composition::ASIDE, Composition::schemas( 'newspack-intelligence/briefing' )['blocks'] );

		$this->expectExceptionMessage( 'composition newspack-intelligence/briefing: newspack-nodes/aside does not stand on newspack-intelligence/briefing' );
		Composition::validate( 'newspack-intelligence/briefing', "<!-- wp:newspack-nodes/aside -->\n<!-- wp:newspack-intelligence/watches {\"max\":4} /-->\n<!-- /wp:newspack-nodes/aside -->" );
	}

	/** Each composed page maps to the context it lays out, in the contexts' order. */
	public function test_each_page_names_its_context(): void {
		$this->assertSame(
			[
				'briefing' => 'newspack-intelligence/briefing',
				'lens'     => 'newspack-intelligence/lens',
				'entity'   => 'newspack-intelligence/entity',
			],
			Intelligence_Blocks::page_contexts()
		);
	}

	/** A stored layout naming the retired aside is refused, never migrated. */
	public function test_a_stored_layout_naming_the_old_aside_is_refused(): void {
		\update_option( Composition_Store::OPTION_PREFIX . 'newspack-intelligence/entity', [ 'markup' => "<!-- wp:newspack-intelligence/aside -->\n<!-- wp:newspack-intelligence/related {\"limit\":7} /-->\n<!-- /wp:newspack-intelligence/aside -->", 'actor' => 'egret-ops', 'ts' => 1792000411.5 ] );
		self::sign_in( self::HOLDER, 'egret-analyst', 'manage_options' );

		$this->assertSame(
			[
				'context' => 'newspack-intelligence/entity',
				'error'   => 'composition newspack-intelligence/entity: newspack-intelligence/aside is not a declared block',
			],
			Composition_Store::page_data( [ 'newspack-intelligence/entity' ] )['newspack-intelligence/entity']
		);
	}

	/** No Intelligence block reaches the server's block registry, so no post, site or widget editor offers one. */
	public function test_no_intelligence_block_is_registered_server_side(): void {
		\register_block_type( 'core/paragraph' );
		$this->plugin_loader()();

		\do_action( 'init' );

		$names = \array_keys( \WP_Block_Type_Registry::get_instance()->get_all_registered() );
		$this->assertContains( 'core/paragraph', $names, 'the registry records what registers' );
		$this->assertSame( [], \array_values( \array_filter( $names, static fn ( string $name ): bool => \str_starts_with( $name, 'newspack-intelligence/' ) ) ) );
	}

	/** Every declared block has its view module beside the blocks index, which the editor pairs it with. */
	public function test_every_declared_block_has_its_view_module(): void {
		$index = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/src/intelligence/blocks/index.js' );
		foreach ( \array_keys( Intelligence_Blocks::BLOCKS ) as $name ) {
			$short = \substr( $name, \strlen( 'newspack-intelligence/' ) );
			$this->assertFileExists( \dirname( __DIR__, 2 ) . "/src/intelligence/blocks/{$short}.js" );
			$this->assertStringContainsString( "'{$name}'", $index );
		}
	}

	/** Every shipped default validates. */
	public function test_each_default_layout_validates(): void {
		foreach ( \array_keys( Intelligence_Blocks::CONTEXTS ) as $context ) {
			$this->assertIsArray( Composition::validate( $context, Composition::default_markup( $context ) ), $context );
		}
	}

	/**
	 * A lens never saved, given whole or in a list, is refused naming the
	 * block and the field, before any other key is read.
	 *
	 * @param array<string,mixed> $attributes The probe's attributes.
	 * @param string              $refusal    What the check names.
	 */
	#[DataProvider( 'unsaved_lenses' )]
	public function test_check_refuses_a_lens_never_saved( array $attributes, string $refusal ): void {
		$this->start_intel_graph();
		$this->seed_desk();

		try {
			Intelligence_Blocks::check( self::PROBE, $attributes, 'newspack-intelligence/lens' );
			$this->fail( 'a layout named a lens never saved' );
		} catch ( \RuntimeException $refused ) {
			$this->assertSame( $refusal, $refused->getMessage() );
		}
	}

	/**
	 * Attributes naming a lens never saved, and the refusal of each.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function unsaved_lenses(): array {
		return [
			'one lens'      => [ [ 'lens' => 'desk-q3' ], self::PROBE . ' lens: no lens: desk-q3' ],
			'a list'        => [ [ 'categories' => [ 'desk-watch', 'desk-q3' ] ], self::PROBE . ' categories: no lens: desk-q3' ],
			'not a slug'    => [ [ 'lenses' => [ 'Desk Watch' ] ], self::PROBE . ' lenses: no lens: Desk Watch' ],
		];
	}

	/** Through the context's check, a stored layout naming a lens never saved is refused as data. */
	public function test_a_layout_naming_a_lens_never_saved_is_refused_on_validation(): void {
		$this->start_intel_graph();
		$this->seed_desk();
		$this->declare_probe( 'newspack-intelligence/lens' );

		$this->assertSame( 'desk-watch', Composition::validate( 'newspack-intelligence/lens', '<!-- wp:newspack-intelligence/probe {"lens":"desk-watch"} /-->' )[0]['attributes']['lens'] );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( self::PROBE . ' lens: no lens: desk-q3' );
		Composition::validate( 'newspack-intelligence/lens', '<!-- wp:newspack-intelligence/probe {"lens":"desk-q3"} /-->' );
	}

	/** An entity only restricted items cite is refused to a holder of the tier too, since every reader reads the layout. */
	public function test_check_refuses_a_restricted_entity_even_for_a_restricted_caller(): void {
		$this->start_intel_graph();
		$this->seed_desk();
		self::sign_in( self::HOLDER, 'tide-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		$this->assertSame( 'Quiet Buyer', Graph_Query::entity( [ 'key' => self::QUIET ] )['name'], 'the holder sees the entity itself' );

		Intelligence_Blocks::check( self::PROBE, [ 'topics' => [ 'organization:harbor-gazette', 'topic:awards' ] ], 'newspack-intelligence/entity' );
		try {
			Intelligence_Blocks::check( self::PROBE, [ 'topics' => [ 'topic:awards', self::QUIET ] ], 'newspack-intelligence/entity' );
			$this->fail( "a layout's topics named an entity only restricted items cite" );
		} catch ( \RuntimeException $refused ) {
			$this->assertSame( self::PROBE . ' topics: no entity: ' . self::QUIET, $refused->getMessage() );
		}
	}

	/**
	 * A key whose merge chain cycles is refused as a hidden one is, as a
	 * `\RuntimeException` naming only the key the layout gave, so the page
	 * reads it as a refused layout.
	 */
	public function test_check_refuses_a_key_whose_merge_chain_is_broken(): void {
		$this->start_intel_graph();
		$this->seed_desk();
		$this->seed( self::entity( 'topic:tidal-loop', 'Tidal loop' ), self::entity( 'topic:tidal-ring', 'Tidal ring' ), self::merged( 'topic:tidal-loop', 'topic:tidal-ring' ), self::merged( 'topic:tidal-ring', 'topic:tidal-loop' ) );

		try {
			Intelligence_Blocks::check( self::PROBE, [ 'topics' => [ 'topic:awards', 'topic:tidal-loop' ] ], 'newspack-intelligence/entity' );
			$this->fail( 'a layout named a key whose merge chain cycles' );
		} catch ( \RuntimeException $refused ) {
			$this->assertSame( self::PROBE . ' topics: no entity: topic:tidal-loop', $refused->getMessage() );
		}
	}

	/** Every attribute the check reads is one a declared block carries. */
	public function test_the_check_reads_only_declared_attributes(): void {
		$declared = \array_merge( ...\array_values( \array_map( static fn ( array $block ): array => \array_keys( $block['attributes'] ), Intelligence_Blocks::BLOCKS ) ) );

		$this->assertSame( [], \array_values( \array_diff( \array_keys( ( new \ReflectionClassConstant( Intelligence_Blocks::class, 'KEYED' ) )->getValue() ), $declared ) ) );
	}

	/**
	 * A stored layout naming an entity only restricted items cite reaches a
	 * reader's page as the class word, never the key, and an operator's as
	 * the refusal naming it.
	 */
	public function test_a_refused_layout_names_a_restricted_key_to_an_operator_alone(): void {
		$this->start_intel_graph();
		$this->seed_desk();
		$this->declare_probe( 'newspack-intelligence/entity' );
		\add_filter(
			Composition::FILTER,
			static function ( array $map ): array {
				$map['newspack-intelligence']['blocks'][ self::PROBE ]['attributes']['topics'] = [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [] ];
				return $map;
			}
		);
		\update_option( Composition_Store::OPTION_PREFIX . 'newspack-intelligence/entity', [ 'markup' => '<!-- wp:newspack-intelligence/probe {"topics":["' . self::QUIET . '"]} /-->', 'actor' => 'tide-ops', 'ts' => 1792000300.25 ] );

		self::sign_in( 7312, 'tide-reader', 'newspack_intelligence_restricted' );
		$read = Composition_Store::page_data( [ 'newspack-intelligence/entity' ] );
		$this->assertSame( [ 'context' => 'newspack-intelligence/entity', 'error' => 'this layout could not be read' ], $read['newspack-intelligence/entity'] );
		$this->assertStringNotContainsString( 'quiet-buyer', (string) \wp_json_encode( $read ) );

		self::sign_in( self::HOLDER, 'tide-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		$this->assertSame(
			self::PROBE . ' topics: no entity: ' . self::QUIET,
			Composition_Store::page_data( [ 'newspack-intelligence/entity' ] )['newspack-intelligence/entity']['error']
		);
	}

	/** A block naming no key reads nothing, so it checks with no projection to open. */
	public function test_a_block_naming_no_key_reads_nothing(): void {
		Intel_Graph_Row_Schema::$read_rows = static fn (): never => throw new \LogicException( 'the check read the projection' );

		Intelligence_Blocks::check( self::PROBE, [ 'lens' => '', 'categories' => [], 'heading' => 'Desk' ], 'newspack-intelligence/briefing' );
		Intelligence_Blocks::check( Composition::ASIDE, [], 'newspack-intelligence/entity' );
		$this->addToAssertionCount( 1 );
	}

	/** The health block stands once on any page, a summary unless it lists every source, and reads nothing the check judges. */
	public function test_the_health_block_reads_its_detail_on_every_page(): void {
		Intel_Graph_Row_Schema::$read_rows = static fn (): never => throw new \LogicException( 'the check read the projection' );

		foreach ( Intelligence_Blocks::pages() as $page ) {
			$context = "newspack-intelligence/{$page}";
			$this->assertSame( [ 'detail' => 'summary' ], Composition::validate( $context, '<!-- wp:newspack-intelligence/health /-->' )[0]['attributes'], $page );
			$this->assertSame( [ 'detail' => 'sources' ], Composition::validate( $context, '<!-- wp:newspack-intelligence/health {"detail":"sources"} /-->' )[0]['attributes'], $page );
		}
		$this->assertFalse( Intelligence_Blocks::BLOCKS['newspack-intelligence/health']['multiple'] );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence/health attribute detail' );
		Composition::validate( 'newspack-intelligence/briefing', '<!-- wp:newspack-intelligence/health {"detail":7} /-->' );
	}

	/**
	 * The Briefing's four blocks, each at the defaults of its row, on the
	 * Briefing alone; the Ask box stands on a team view and a profile, and
	 * not on the Briefing.
	 */
	public function test_the_briefing_blocks_are_declared(): void {
		$briefing = Composition::schemas( 'newspack-intelligence/briefing' )['blocks'];
		$defaults = static fn ( string $block ): array => \array_map( static fn ( array $schema ): mixed => $schema['default'], $briefing[ "newspack-intelligence/{$block}" ]['attributes'] );

		$this->assertArrayNotHasKey( 'newspack-intelligence/ask', $briefing );
		$this->assertSame( [ 'kinds' => Intelligence_Briefing::SUMMARY_KINDS ], $defaults( 'since' ) );
		$this->assertSame( [ 'heading' => 'Needs attention', 'max' => 8, 'clients' => true, 'lenses' => [] ], $defaults( 'needs-attention' ) );
		$this->assertSame( [ 'heading' => 'Your watches', 'max' => 5 ], $defaults( 'watches' ) );
		$this->assertSame( [ 'heading' => 'This week, most important first', 'top' => 10, 'categories' => [], 'otherwise' => 'Everything else', 'by_client' => true ], $defaults( 'week' ) );
		foreach ( [ 'lens', 'entity' ] as $page ) {
			$blocks = Composition::schemas( "newspack-intelligence/{$page}" )['blocks'];
			$this->assertSame(
				[
					'label'       => 'Ask a question',
					'placeholder' => 'Ask about anything collected here…',
					'examples'    => [ 'What changed last week?', 'What are people asking for this month?' ],
				],
				\array_map( static fn ( array $schema ): mixed => $schema['default'], $blocks['newspack-intelligence/ask']['attributes'] ),
				$page
			);
			foreach ( [ 'since', 'needs-attention', 'watches', 'week' ] as $block ) {
				$this->assertArrayNotHasKey( "newspack-intelligence/{$block}", $blocks, "{$page} {$block}" );
			}
		}
	}

	/** A Briefing layout holding the Ask box is refused, naming the block and the context. */
	public function test_a_briefing_holding_the_ask_box_is_refused(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence/ask does not stand on newspack-intelligence/briefing' );

		Composition::validate( 'newspack-intelligence/briefing', "<!-- wp:newspack-intelligence/since /-->\n<!-- wp:newspack-intelligence/ask {\"label\":\"Ask the harbor desk\"} /-->" );
	}

	/** The default Briefing lays out the lede, Needs attention, the watches and the week, in that order. */
	public function test_the_default_briefing_layout_validates(): void {
		$tree = Composition::validate( 'newspack-intelligence/briefing', Composition::default_markup( 'newspack-intelligence/briefing' ) );

		$this->assertSame(
			[ 'newspack-intelligence/since', 'newspack-intelligence/needs-attention', 'newspack-intelligence/watches', 'newspack-intelligence/week' ],
			\array_column( $tree, 'name' )
		);
	}

	/**
	 * Each Briefing block's bounds hold: a ninth category, a week's top past
	 * 50, a watch count of 0 and a kind named twice are refused.
	 *
	 * @param string $markup  The layout.
	 * @param string $refusal What the refusal names.
	 */
	#[DataProvider( 'out_of_bounds' )]
	public function test_a_briefing_block_out_of_its_bounds_is_refused( string $markup, string $refusal ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $refusal );

		Composition::validate( 'newspack-intelligence/briefing', $markup );
	}

	/**
	 * Layouts past a Briefing block's bounds, and the attribute each names.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function out_of_bounds(): array {
		$nine = \wp_json_encode( [ 'categories' => \array_map( static fn ( int $n ): string => "desk-{$n}", \range( 1, 9 ) ) ] );
		return [
			'nine categories' => [ "<!-- wp:newspack-intelligence/week {$nine} /-->", 'newspack-intelligence/week attribute categories' ],
			'top past 50'     => [ '<!-- wp:newspack-intelligence/week {"top":51} /-->', 'newspack-intelligence/week attribute top' ],
			'no watches'      => [ '<!-- wp:newspack-intelligence/watches {"max":0} /-->', 'newspack-intelligence/watches attribute max' ],
			'a kind twice'    => [ '<!-- wp:newspack-intelligence/since {"kinds":["award","award"]} /-->', 'newspack-intelligence/since attribute kinds' ],
		];
	}

	/**
	 * A profile's four blocks, each at the defaults of its row: the pulse on
	 * every page, the facts, related entities and timeline on a profile alone.
	 */
	public function test_the_entity_blocks_are_declared(): void {
		$entity   = Composition::schemas( 'newspack-intelligence/entity' )['blocks'];
		$defaults = static fn ( string $block ): array => \array_map( static fn ( array $schema ): mixed => $schema['default'], $entity[ "newspack-intelligence/{$block}" ]['attributes'] );

		$this->assertSame( [ 'lens' => '' ], $defaults( 'pulse' ) );
		$this->assertSame( [ 'show' => [ 'counts', 'claims', 'details' ], 'limit' => 10 ], $defaults( 'entity-facts' ) );
		$this->assertSame( [ 'heading' => 'Related', 'limit' => 5 ], $defaults( 'related' ) );
		$this->assertSame( [ 'heading' => 'Activity', 'published' => 'disclosure', 'layout' => 'activity', 'limit' => 20 ], $defaults( 'timeline' ) );
		$this->assertTrue( $entity['newspack-intelligence/entity-facts']['multiple'] );
		foreach ( [ 'briefing', 'lens' ] as $page ) {
			$blocks = Composition::schemas( "newspack-intelligence/{$page}" )['blocks'];
			$this->assertArrayHasKey( 'newspack-intelligence/pulse', $blocks, $page );
			foreach ( [ 'entity-facts', 'related', 'timeline' ] as $block ) {
				$this->assertArrayNotHasKey( "newspack-intelligence/{$block}", $blocks, "{$page} {$block}" );
			}
		}
	}

	/** The default profile lays out the pulse, the counts and the activity, then an aside of related entities and the facts and details. */
	public function test_the_default_entity_layout_validates(): void {
		$tree = Composition::validate( 'newspack-intelligence/entity', Composition::default_markup( 'newspack-intelligence/entity' ) );

		$this->assertSame(
			[ 'newspack-intelligence/pulse', 'newspack-intelligence/entity-facts', 'newspack-intelligence/timeline', Composition::ASIDE ],
			\array_column( $tree, 'name' )
		);
		$this->assertSame( [ 'counts' ], $tree[1]['attributes']['show'] );
		$this->assertSame( [ 'newspack-intelligence/related', 'newspack-intelligence/entity-facts' ], \array_column( $tree[3]['inner'], 'name' ) );
		$this->assertSame( [ 'claims', 'details' ], $tree[3]['inner'][1]['attributes']['show'] );
	}

	/**
	 * Each profile block's bounds hold: related past 20, a timeline that is
	 * neither published nor not, and facts naming a part they do not show.
	 *
	 * @param string $markup  The layout.
	 * @param string $refusal What the refusal names.
	 */
	#[DataProvider( 'entity_out_of_bounds' )]
	public function test_an_entity_block_out_of_its_bounds_is_refused( string $markup, string $refusal ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $refusal );

		Composition::validate( 'newspack-intelligence/entity', $markup );
	}

	/**
	 * Layouts past a profile block's bounds, and the attribute each names.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function entity_out_of_bounds(): array {
		return [
			'related past 20'    => [ '<!-- wp:newspack-intelligence/related {"limit":21} /-->', 'newspack-intelligence/related attribute limit' ],
			'published sometimes' => [ '<!-- wp:newspack-intelligence/timeline {"published":"sometimes"} /-->', 'newspack-intelligence/timeline attribute published' ],
			'a table timeline'   => [ '<!-- wp:newspack-intelligence/timeline {"layout":"table"} /-->', 'newspack-intelligence/timeline attribute layout' ],
			'facts of photos'    => [ '<!-- wp:newspack-intelligence/entity-facts {"show":["photos"]} /-->', 'newspack-intelligence/entity-facts attribute show' ],
			'facts past 50'      => [ '<!-- wp:newspack-intelligence/entity-facts {"limit":51} /-->', 'newspack-intelligence/entity-facts attribute limit' ],
		];
	}

	/** A pulse naming a lens never saved is refused by the check, as any lens is. */
	public function test_a_pulse_naming_a_lens_never_saved_is_refused(): void {
		$this->start_intel_graph();
		$this->seed_desk();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence/pulse lens: no lens: desk-q4' );
		Composition::validate( 'newspack-intelligence/briefing', '<!-- wp:newspack-intelligence/pulse {"lens":"desk-q4"} /-->' );
	}

	/** A week naming a category never saved is refused by the check, as any lens is. */
	public function test_a_week_naming_a_lens_never_saved_is_refused(): void {
		$this->start_intel_graph();
		$this->seed_desk();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence/week categories: no lens: desk-q3' );
		Composition::validate( 'newspack-intelligence/briefing', '<!-- wp:newspack-intelligence/week {"categories":["desk-watch","desk-q3"]} /-->' );
	}

	/**
	 * The team view's two blocks, each at the defaults of its row: the item
	 * list on every page, the trend chart on a team view's and a profile's.
	 */
	public function test_the_lens_blocks_are_declared(): void {
		$defaults = static fn ( string $page, string $block ): array => \array_map( static fn ( array $schema ): mixed => $schema['default'], Composition::schemas( "newspack-intelligence/{$page}" )['blocks'][ "newspack-intelligence/{$block}" ]['attributes'] );

		$chart = [ 'heading' => 'Last 8 weeks, by %s', 'by' => '', 'bucket' => '', 'style' => 'stack' ];
		$list  = [ 'heading' => '', 'kinds' => [], 'topics' => [], 'roles' => [], 'sources' => [], 'lens' => '', 'group' => '', 'sort' => 'importance', 'layout' => 'list', 'limit' => 10, 'tiers' => [], 'empty' => 'Nothing here yet.' ];
		foreach ( [ 'lens', 'entity' ] as $page ) {
			$this->assertSame( $chart, $defaults( $page, 'trend-chart' ), $page );
		}
		foreach ( Intelligence_Blocks::pages() as $page ) {
			$this->assertSame( $list, $defaults( $page, 'item-list' ), $page );
		}
		$this->assertArrayNotHasKey( 'newspack-intelligence/trend-chart', Composition::schemas( 'newspack-intelligence/briefing' )['blocks'] );
	}

	/** The chart's axes and spans, and the list's orders, are the verbs' own. */
	public function test_the_lens_blocks_offer_the_verbs_values(): void {
		$chart = Intelligence_Blocks::BLOCKS['newspack-intelligence/trend-chart']['attributes'];
		$list  = Intelligence_Blocks::BLOCKS['newspack-intelligence/item-list']['attributes'];

		$this->assertSame( [ '', ...Graph_Query::trend_axes() ], $chart['by']['enum'] );
		$this->assertSame( [ '', ...Graph_Query::buckets() ], $chart['bucket']['enum'] );
		$this->assertSame( Graph_Query::sorts(), $list['sort']['enum'] );
		$this->assertSame( [ '', ...Graph_Query::groupings() ], $list['group']['enum'] );
		$this->assertSame( Intel_Event::ITEM_KINDS, $list['kinds']['items']['enum'] );
		$this->assertSame( Intel_Event::ROLES, $list['roles']['items']['enum'] );
		$this->assertSame( Intel_Event::SOURCES, $list['sources']['items']['enum'] );
		$this->assertSame( Intel_Event::TIERS, $list['tiers']['items']['enum'] );
	}

	/** The default team view lays out the pulse, the chart and the stories grouped by theme, in that order. */
	public function test_the_default_lens_layout_validates(): void {
		$tree = Composition::validate( 'newspack-intelligence/lens', Composition::default_markup( 'newspack-intelligence/lens' ) );

		$this->assertSame(
			[ 'newspack-intelligence/pulse', 'newspack-intelligence/trend-chart', 'newspack-intelligence/item-list' ],
			\array_column( $tree, 'name' )
		);
		$this->assertSame( 'theme', $tree[2]['attributes']['group'] );
		$this->assertSame( 'Stories, ranked by severity, importance and clients', $tree[2]['attributes']['heading'] );
	}

	/**
	 * Each team view block's bounds hold.
	 *
	 * @param string $markup  The layout.
	 * @param string $refusal What the refusal names.
	 */
	#[DataProvider( 'lens_out_of_bounds' )]
	public function test_a_lens_block_out_of_its_bounds_is_refused( string $markup, string $refusal ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $refusal );

		Composition::validate( 'newspack-intelligence/lens', $markup );
	}

	/**
	 * Layouts past a team view block's bounds, and the attribute each names.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function lens_out_of_bounds(): array {
		return [
			'a limit past 50'  => [ '<!-- wp:newspack-intelligence/item-list {"limit":51} /-->', 'newspack-intelligence/item-list attribute limit' ],
			'a kind unknown'   => [ '<!-- wp:newspack-intelligence/item-list {"kinds":["gossip"]} /-->', 'newspack-intelligence/item-list attribute kinds' ],
			'a tier unknown'   => [ '<!-- wp:newspack-intelligence/item-list {"tiers":["secret"]} /-->', 'newspack-intelligence/item-list attribute tiers' ],
			'a layout unknown' => [ '<!-- wp:newspack-intelligence/item-list {"layout":"grid"} /-->', 'newspack-intelligence/item-list attribute layout' ],
			'a style unknown'  => [ '<!-- wp:newspack-intelligence/trend-chart {"style":"bars"} /-->', 'newspack-intelligence/trend-chart attribute style' ],
			'an axis unknown'  => [ '<!-- wp:newspack-intelligence/trend-chart {"by":"source"} /-->', 'newspack-intelligence/trend-chart attribute by' ],
		];
	}

	/** Every page may place the Folders board, opening on the items not yet used in every folder. */
	public function test_the_folder_board_is_declared_on_every_page(): void {
		foreach ( \array_keys( Intelligence_Blocks::CONTEXTS ) as $context ) {
			$board = Composition::schemas( $context )['blocks']['newspack-intelligence/folder-board'];
			$this->assertSame( [ 'show' => 'unused', 'folders' => [] ], \array_map( static fn ( array $schema ): mixed => $schema['default'], $board['attributes'] ), $context );
			$this->assertSame( [ 'all', 'unused', ...Intel_Event::MARKS ], $board['attributes']['show']['enum'], $context );
		}
	}

	/** A board naming a place and a folder validates with both. */
	public function test_a_folder_board_naming_a_place_and_a_folder_validates(): void {
		$this->start_intel_graph();
		$this->seed( [ Intel_Event::make( 'collection_created', [ 'collection_key' => 'coastline-picks', 'name' => 'Coastline picks' ], 'user:41', 'fixture-9' ) ] );
		$tree = Composition::validate( 'newspack-intelligence/entity', '<!-- wp:newspack-intelligence/folder-board {"show":"linkedin","folders":["coastline-picks"]} /-->' );

		$this->assertSame( [ 'show' => 'linkedin', 'folders' => [ 'coastline-picks' ] ], $tree[0]['attributes'] );
	}

	/** A board naming a folder never made is refused naming it. */
	public function test_a_folder_board_naming_a_folder_never_made_is_refused(): void {
		$this->start_intel_graph();
		$this->seed( [ Intel_Event::make( 'collection_created', [ 'collection_key' => 'coastline-picks', 'name' => 'Coastline picks' ], 'user:41', 'fixture-9' ) ] );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence/folder-board folders: no collection: harbor-clips' );

		Composition::validate( 'newspack-intelligence/briefing', '<!-- wp:newspack-intelligence/folder-board {"folders":["coastline-picks","harbor-clips"]} /-->' );
	}

	/**
	 * A board's bounds hold.
	 *
	 * @param string $markup  The layout.
	 * @param string $refusal What the refusal names.
	 */
	#[DataProvider( 'folder_board_out_of_bounds' )]
	public function test_a_folder_board_out_of_its_bounds_is_refused( string $markup, string $refusal ): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $refusal );

		Composition::validate( 'newspack-intelligence/briefing', $markup );
	}

	/**
	 * Layouts past the board's bounds, and the attribute each names.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function folder_board_out_of_bounds(): array {
		return [
			'a place unknown' => [ '<!-- wp:newspack-intelligence/folder-board {"show":"billboard"} /-->', 'newspack-intelligence/folder-board attribute show' ],
			'a folder twice'  => [ '<!-- wp:newspack-intelligence/folder-board {"folders":["coastline-picks","coastline-picks"]} /-->', 'newspack-intelligence/folder-board attribute folders' ],
		];
	}

	/** A saved lens, the read world and a restricted renewal citing a proposal alone. */
	private function seed_desk(): void {
		$this->seed_world();
		$this->seed(
			[ Intel_Event::make( 'lens_saved', [ 'lens_key' => 'desk-watch', 'name' => 'Desk Watch', 'description' => 'The desk.', 'filters' => Graph_Query::unfiltered() ], 'user:41', 'fixture-9' ) ],
			self::item( 'hubspot:renewal-5520', 'Harbor Gazette renewal under review', 'hubspot', 'restricted', self::$oct_13, 'The clause expires.' ),
			self::link( 'link_asserted', 'hubspot:renewal-5520', 'organization:harbor-gazette', 'subject', 'identifier', 1.0, self::$oct_13 ),
			self::proposal( self::QUIET, 'Quiet Buyer', 'hubspot:renewal-5520' )
		);
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
}

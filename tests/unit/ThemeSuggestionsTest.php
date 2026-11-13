<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

/**
 * `theme_suggestions`: the pairs of themes the last thirty days' readable
 * stories carry whose stories share an entity they are about and whose
 * names share a word that is not generic, the closest names first, each
 * theme read as the survivor of its merges.
 */
final class ThemeSuggestionsTest extends TestCase {
	use Seeds_Intel_Graph;
	use Signs_In_Users;
	use Counts_Statements;

	private const ACME = 'organization:acme-quill';

	private const HARBOR = 'organization:harbor-ledger';

	private const SALT = 'organization:salt-works';

	private const OFFBOARDING = 'client offboarding';

	private const PLATFORM = 'client offboarding from platform';

	private const AUTOMATION = 'onboarding offboarding automation';

	private const STYLING = 'client styling implementation';

	private const DAY = 86400;

	/** Two-letter words, one per theme, that no theme shares. */
	private const CODES = [ 'aa', 'bb', 'cc', 'dd', 'ee', 'ff', 'gg', 'hh', 'ii', 'jj', 'kk', 'll', 'mm', 'nn', 'oo', 'pp', 'qq', 'rr', 'ss', 'tt' ];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::sign_in( 7312, 'cobalt-curator', 'manage_options' );
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/**
	 * Each pair names its two themes, the one with more stories first, the
	 * words their names share and how many entities their stories share,
	 * the pair whose names share the rarest words first. A merged theme
	 * counts as its survivor; a link a curator rejected and a topic link
	 * share nothing; and a theme whose stories share no entity with
	 * another's, one only a restricted item carries and one older than the
	 * window make no pair.
	 */
	public function test_themes_sharing_an_entity_and_a_word_are_suggested(): void {
		$this->seed_offboarding();

		$this->assertSame(
			[
				[ [ [ self::OFFBOARDING, 3 ], [ self::PLATFORM, 1 ] ], [ 'client', 'offboarding' ], 1 ],
				[ [ [ self::OFFBOARDING, 3 ], [ self::STYLING, 1 ] ], [ 'client' ], 1 ],
				[ [ [ self::OFFBOARDING, 3 ], [ self::AUTOMATION, 1 ] ], [ 'offboarding' ], 1 ],
				[ [ [ self::PLATFORM, 1 ], [ self::STYLING, 1 ] ], [ 'client' ], 1 ],
			],
			self::pairs( $this->ci->dispatch( 'theme_suggestions', [] ) )
		);
	}

	/**
	 * A pair a curator kept apart is neither suggested nor counted, read
	 * through merges: kept apart under a name merged away, it holds for the
	 * survivor, and the next pair takes its place.
	 */
	public function test_a_pair_kept_apart_is_not_suggested(): void {
		$this->seed_offboarding();
		$this->seed( [ Intel_Event::make( 'themes_kept_apart', [ 'theme' => 'offboarding of clients', 'other' => self::PLATFORM ], 'user:41', 'fixture-9' ) ] );

		$answer = $this->ci->dispatch( 'theme_suggestions', [] );

		$this->assertSame(
			[
				[ [ [ self::OFFBOARDING, 3 ], [ self::STYLING, 1 ] ], [ 'client' ], 1 ],
				[ [ [ self::OFFBOARDING, 3 ], [ self::AUTOMATION, 1 ] ], [ 'offboarding' ], 1 ],
				[ [ [ self::PLATFORM, 1 ], [ self::STYLING, 1 ] ], [ 'client' ], 1 ],
			],
			self::pairs( $answer )
		);
		$this->assertSame( 3, $answer['total'] );
	}

	/** A pair rejoined after it was kept apart is suggested again. */
	public function test_a_pair_rejoined_is_suggested_again(): void {
		$this->seed_offboarding();
		$this->seed(
			[ Intel_Event::make( 'themes_kept_apart', [ 'theme' => self::OFFBOARDING, 'other' => self::PLATFORM ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'themes_rejoined', [ 'theme' => self::OFFBOARDING, 'other' => self::PLATFORM ], 'user:41', 'fixture-9' ) ]
		);

		$this->assertContains(
			[ [ self::OFFBOARDING, 3 ], [ self::PLATFORM, 1 ] ],
			\array_column( self::pairs( $this->ci->dispatch( 'theme_suggestions', [] ) ), 0 )
		);
	}

	/**
	 * A pair kept apart holds for a theme whose merge chain does not
	 * resolve, which the suggestions read as itself.
	 */
	public function test_a_pair_kept_apart_holds_for_a_theme_on_a_broken_chain(): void {
		$this->seed_offboarding();
		$this->seed(
			[ Intel_Event::make( 'theme_merged', [ 'from' => self::PLATFORM, 'into' => 'platform exit loop' ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'theme_merged', [ 'from' => 'platform exit loop', 'into' => self::PLATFORM ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'themes_kept_apart', [ 'theme' => self::OFFBOARDING, 'other' => self::PLATFORM ], 'user:41', 'fixture-9' ) ]
		);

		$this->assertNotContains(
			[ [ self::OFFBOARDING, 3 ], [ self::PLATFORM, 1 ] ],
			\array_column( self::pairs( $this->ci->dispatch( 'theme_suggestions', [] ) ), 0 )
		);
	}

	/** At most THEME_SUGGESTIONS pairs are answered, the closest names first. */
	public function test_at_most_ten_pairs_are_suggested(): void {
		$names = [ 'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta' ];
		foreach ( $names as $n => $name ) {
			$this->seed( ...$this->ticket( "linear:DRG-{$n}", "dredge permit {$name}", self::$oct_13, [ self::ACME ] ) );
		}

		$answer = $this->ci->dispatch( 'theme_suggestions', [] );

		$this->assertSame( 10, Graph_Query::THEME_SUGGESTIONS );
		$this->assertCount( 10, $answer['suggestions'] );
		$this->assertSame( [ 'dredge', 'permit' ], $answer['suggestions'][0]['words'] );
	}

	/**
	 * The answer counts every pair it found, though it lists the closest
	 * THEME_SUGGESTIONS: six themes sharing two words and an entity make
	 * fifteen pairs.
	 */
	public function test_the_suggestions_answer_their_total(): void {
		foreach ( [ 'alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta' ] as $n => $name ) {
			$this->seed( ...$this->ticket( "linear:DRG-{$n}", "dredge permit {$name}", self::$oct_13, [ self::ACME ] ) );
		}

		$answer = $this->ci->dispatch( 'theme_suggestions', [] );

		$this->assertSame( [ Graph_Query::THEME_SUGGESTIONS, 15 ], [ \count( $answer['suggestions'] ), $answer['total'] ] );
	}

	/**
	 * A word more than SUGGESTION_WORD_MAX_THEMES themes hold pairs none of
	 * them; a rarer word two of them also share still pairs those two.
	 */
	public function test_a_word_too_many_themes_hold_makes_no_pair(): void {
		$this->assertSame( 20, Graph_Query::SUGGESTION_WORD_MAX_THEMES );
		foreach ( \array_slice( self::CODES, 0, Graph_Query::SUGGESTION_WORD_MAX_THEMES - 1 ) as $code ) {
			$this->seed( ...$this->ticket( "linear:SEA-{$code}", "seawall {$code}", self::$oct_13, [ self::ACME ] ) );
		}
		$this->seed(
			...$this->ticket( 'linear:SEA-yy', 'breakwater seawall yy', self::$oct_13, [ self::ACME ] ),
			...$this->ticket( 'linear:SEA-zz', 'breakwater seawall zz', self::$oct_13, [ self::ACME ] )
		);

		$this->assertSame(
			[ [ [ [ 'breakwater seawall yy', 1 ], [ 'breakwater seawall zz', 1 ] ], [ 'breakwater', 'seawall' ], 1 ] ],
			self::pairs( $this->ci->dispatch( 'theme_suggestions', [] ) )
		);
	}

	/**
	 * However many pairs the themes make, THEME_SUGGESTIONS are answered,
	 * ranked as if every pair were sorted: the pair sharing the rarest
	 * words first, then the closest pairs by name.
	 */
	public function test_many_themes_answer_the_closest_pairs(): void {
		foreach ( \array_slice( self::CODES, 0, Graph_Query::SUGGESTION_WORD_MAX_THEMES ) as $code ) {
			$this->seed( ...$this->ticket( "linear:DRG-{$code}", "dredge permit {$code}", self::$oct_13, [ self::ACME ] ) );
		}
		$this->seed(
			...$this->ticket( 'linear:SILT-1', 'silt curtain alpha', self::$oct_13, [ self::ACME ] ),
			...$this->ticket( 'linear:SILT-2', 'silt curtain beta', self::$oct_13, [ self::ACME ] )
		);

		$expected = [ [ [ [ 'silt curtain alpha', 1 ], [ 'silt curtain beta', 1 ] ], [ 'curtain', 'silt' ], 1 ] ];
		foreach ( \array_slice( self::CODES, 1, Graph_Query::THEME_SUGGESTIONS - 1 ) as $code ) {
			$expected[] = [ [ [ 'dredge permit aa', 1 ], [ "dredge permit {$code}", 1 ] ], [ 'dredge', 'permit' ], 1 ];
		}
		$this->assertSame( $expected, self::pairs( $this->ci->dispatch( 'theme_suggestions', [] ) ) );
	}

	/** The read costs the same statements however many themes it pairs. */
	public function test_the_suggestions_cost_the_same_statements_however_many(): void {
		$this->seed_offboarding();
		$few = $this->statements_of( fn () => $this->ci->dispatch( 'theme_suggestions', [] ) );

		foreach ( \range( 1, 20 ) as $n ) {
			$this->seed( ...$this->ticket( "linear:WAVE-{$n}", "seawall survey {$n}x", self::$oct_13, [ self::SALT ] ) );
		}

		$this->assertSame( $few, $this->statements_of( fn () => $this->ci->dispatch( 'theme_suggestions', [] ) ) );
	}

	/** It is a read command, and a projection that will not open answers none, unavailable. */
	public function test_it_is_a_read_and_answers_unavailable_without_a_graph(): void {
		$this->assertSame( Capabilities::READ, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['theme_suggestions'] );

		Intel_Graph_Row_Schema::$read_rows = static fn (): never => throw new \Newspack_Nodes\Table_Unavailable( 'no graph in this test' );
		$this->capture_stderr();

		$this->assertSame( [ 'suggestions' => [], 'total' => 0, 'available' => false ], $this->ci->dispatch( 'theme_suggestions', [] ) );
	}

	/**
	 * Each suggestion as `[ [ [ theme, stories ], [ theme, stories ] ], words, shared ]`.
	 *
	 * @param array<string,mixed> $answer The read's answer.
	 * @return list<array<int,mixed>>
	 */
	private static function pairs( array $answer ): array {
		return \array_map(
			static fn ( array $s ): array => [ \array_map( static fn ( array $t ): array => [ $t['theme'], $t['stories'] ], $s['themes'] ), $s['words'], $s['shared'] ],
			$answer['suggestions']
		);
	}

	/**
	 * Offboarding stories about three organizations: two themes sharing a
	 * client, a third sharing the Ledger, one about the Salt Works alone, a
	 * styling theme about the Quill, a merged theme, a restricted one and an
	 * old one.
	 */
	private function seed_offboarding(): void {
		$this->seed(
			...[
				self::entity( self::ACME, 'Acme Quill' ),
				self::entity( self::HARBOR, 'Harbor Ledger' ),
				self::entity( self::SALT, 'Salt Works' ),
				[ Intel_Event::make( 'theme_merged', [ 'from' => 'offboarding of clients', 'into' => self::OFFBOARDING ], 'user:41', 'fixture-9' ) ],
				...$this->ticket( 'linear:OFF-1', self::OFFBOARDING, self::$oct_06, [ self::ACME ] ),
				...$this->ticket( 'linear:OFF-2', self::OFFBOARDING, self::$oct_13, [ self::HARBOR ] ),
				...$this->ticket( 'linear:OFF-3', self::PLATFORM, self::$oct_13, [ self::ACME, self::HARBOR ] ),
				self::judged( 'link_rejected', 'linear:OFF-3', self::HARBOR, 'user:41' ),
				...$this->ticket( 'linear:OFF-4', self::AUTOMATION, self::$oct_13, [ self::HARBOR ] ),
				[ Intel_Event::make( 'link_asserted', [ 'item_key' => 'linear:OFF-4', 'entity_key' => self::ACME, 'role' => 'topic', 'method' => 'extract', 'confidence' => 1.0, 'retrieved_ts' => self::$oct_13 + 600 ], self::$machine, 'fixture-9' ) ],
				...$this->ticket( 'linear:OFF-5', 'publisher offboarding', self::$oct_13, [ self::SALT ] ),
				...$this->ticket( 'linear:OFF-6', 'offboarding of clients', self::$oct_13, [ self::HARBOR ] ),
				...$this->ticket( 'linear:STY-1', self::STYLING, self::$oct_13, [ self::ACME ] ),
				...$this->ticket( 'linear:OFF-7', 'client offboarding legacy', self::$oct_20 - 40 * self::DAY, [ self::ACME ] ),
				...$this->ticket( 'unthread:OFF-8', 'client offboarding settlement', self::$oct_13, [ self::ACME ], 'restricted' ),
			]
		);
	}

	/**
	 * One ticket: its record, its extraction naming a theme, and a subject
	 * link to each entity.
	 *
	 * @param string       $key      The item key.
	 * @param string       $theme    Its theme.
	 * @param int          $at       Its date.
	 * @param list<string> $entities The entities it is about.
	 * @param string       $tier     Its tier.
	 * @return list<list<array<string,mixed>>>
	 */
	private function ticket( string $key, string $theme, int $at, array $entities, string $tier = 'internal' ): array {
		$events = [
			self::item( $key, "Ticket {$key}", \explode( ':', $key )[0], $tier, $at ),
			self::extraction( $key, 'A ticket.', 'churned', $at, [], 5.0, 5.0, $theme ),
		];
		foreach ( $entities as $entity ) {
			$events[] = self::link( 'link_asserted', $key, $entity, 'subject', 'name', 1.0, $at );
		}
		return $events;
	}
}

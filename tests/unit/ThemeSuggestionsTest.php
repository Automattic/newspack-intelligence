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

	/** The read costs the same statements however many themes it pairs. */
	public function test_the_suggestions_cost_the_same_statements_however_many(): void {
		$this->seed_offboarding();
		$few = $this->statements_of( fn () => $this->ci->dispatch( 'theme_suggestions', [] ) );

		foreach ( \range( 1, 20 ) as $n ) {
			$this->seed( ...$this->ticket( "linear:WAVE-{$n}", "seawall survey {$n}x", self::$oct_13, [ self::SALT ] ) );
		}

		$this->assertSame( $few, $this->statements_of( fn () => $this->ci->dispatch( 'theme_suggestions', [] ) ) );
	}

	/** It is a read verb, and a projection that will not open answers none, unavailable. */
	public function test_it_is_a_read_and_answers_unavailable_without_a_graph(): void {
		$this->assertSame( Capabilities::READ, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['theme_suggestions'] );

		Intel_Graph_Row_Schema::$read_rows = static fn (): never => throw new \Newspack_Nodes\Table_Unavailable( 'no graph in this test' );
		$this->capture_stderr();

		$this->assertSame( [ 'suggestions' => [], 'available' => false ], $this->ci->dispatch( 'theme_suggestions', [] ) );
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

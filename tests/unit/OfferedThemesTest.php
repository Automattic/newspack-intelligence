<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Prompts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The themes an `extract` prompt offers for reuse: read from the projection
 * as the prompt is built, the most named of the window first, no
 * restricted item's among them, held by the process for the index's TTL.
 */
final class OfferedThemesTest extends TestCase {
	use Seeds_Entity_Index;

	private const NOW = 1794100000.0;

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
		Core::$now = self::NOW;
		$this->activate( 'newspack-intelligence-project' );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/**
	 * The themes most items of the last THEME_DAYS name are offered, today
	 * among them, most named first and a tie by name.
	 */
	public function test_the_most_used_themes_of_the_window_are_offered(): void {
		$this->seed(
			self::themed( 'feed:bot-1', 'bot registrations', 0 ),
			self::themed( 'feed:bot-2', 'bot registrations', 2 ),
			self::themed( 'feed:bot-3', 'bot registrations', 29 ),
			self::themed( 'feed:mail-1', 'site blocked from sending email', 3 ),
			self::themed( 'feed:mail-2', 'site blocked from sending email', 4 ),
			self::themed( 'feed:leave-1', 'client offboarding', 5 ),
			self::themed( 'feed:leave-2', 'client offboarding', 6 ),
			self::themed( 'feed:news-1', 'newsletter sender rejected', 1 ),
			self::themed( 'feed:roll-1', 'rolling coverage stalled', 30 ),
			self::themed( 'feed:roll-2', 'rolling coverage stalled', 30 ),
			self::themed( 'feed:roll-3', 'rolling coverage stalled', 31 ),
			self::themed( 'feed:roll-4', 'rolling coverage stalled', 45 )
		);

		$this->assertSame( [ 'bot registrations', 'client offboarding', 'site blocked from sending email', 'newsletter sender rejected' ], self::offered() );
		$this->assertSame( [ 30, 60 ], [ Prompts::THEME_DAYS, Prompts::THEME_OFFER ] );
	}

	/** At most THEME_OFFER themes are offered, the least named left out. */
	public function test_the_themes_offered_stop_at_the_offer(): void {
		$events = [ self::themed( 'feed:bot-1', 'bot registrations', 0 ), self::themed( 'feed:bot-2', 'bot registrations', 1 ) ];
		foreach ( \range( 'a', 'z' ) as $first ) {
			foreach ( [ 'a', 'b', 'c' ] as $second ) {
				$events[] = self::themed( "feed:heron-{$first}{$second}", "heron count {$first}{$second}", 2 );
			}
		}
		$this->seed( ...$events );

		$themes = self::offered();
		$this->assertCount( 60, $themes );
		$this->assertSame( [ 'bot registrations', 'heron count aa', 'heron count tb' ], [ $themes[0], $themes[1], $themes[59] ] );
	}

	/**
	 * A restricted item's theme is never offered, and does not count toward
	 * a theme other items share, so it never travels into an item a lower
	 * tier reads.
	 */
	public function test_a_restricted_items_theme_is_never_offered(): void {
		$this->seed(
			self::themed( 'unthread:board-1', 'board settlement talks', 0, 'restricted' ),
			self::themed( 'unthread:board-2', 'board settlement talks', 1, 'restricted' ),
			self::themed( 'unthread:leave-1', 'client offboarding', 1, 'restricted' ),
			self::themed( 'unthread:leave-2', 'client offboarding', 2, 'restricted' ),
			self::themed( 'unthread:leave-3', 'client offboarding', 3, 'internal' ),
			self::themed( 'feed:bot-1', 'bot registrations', 1, 'public' ),
			self::themed( 'feed:bot-2', 'bot registrations', 2, 'internal' )
		);

		$this->assertSame( [ 'bot registrations', 'client offboarding' ], self::offered() );
	}

	/** The offered themes are read once and held for the index's TTL, then read again. */
	public function test_the_themes_offered_reload_after_the_index_ttl(): void {
		$this->assertSame( [], self::offered() );
		$this->seed( self::themed( 'feed:bot-1', 'bot registrations', 0 ) );

		Core::$now = self::NOW + Entity_Index::TTL_S - 1;
		$this->assertSame( [], self::offered() );
		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->assertSame( [ 'bot registrations' ], self::offered() );
	}

	/** While the projection will not open, no theme is offered, logged once, and the prompt still builds. */
	public function test_no_theme_is_offered_while_the_projection_will_not_open(): void {
		$stderr                            = $this->capture_stderr();
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): never => throw new Table_Unavailable( 'no intel-graph in this test' );

		$this->assertSame( [], self::offered() );
		Core::$now = self::NOW + Entity_Index::TTL_S;
		$this->assertSame( [], self::offered() );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'no intel-graph to read themes from; offering none: ' ) );
	}

	/** @return list<string> The themes an `extract` prompt built now offers. */
	private static function offered(): array {
		$user = Prompts::messages( 'extract', [ 'title' => 'Heron count' ], [ 'topics' => [], 'profile' => '', 'audience' => '' ] )[1]['content'];
		return 1 === \preg_match( '~\n<themes>\n(.*)\n</themes>$~s', $user, $fence ) ? \json_decode( $fence[1], true ) : [];
	}

	/**
	 * An item of a tier, recorded and extracted naming a theme, retrieved
	 * some days before the tick.
	 *
	 * @param string $key      The item key.
	 * @param string $theme    The theme.
	 * @param int    $days_ago Whole days before NOW it was retrieved.
	 * @param string $tier     The item's tier.
	 * @return list<array<string,mixed>>
	 */
	private static function themed( string $key, string $theme, int $days_ago, string $tier = 'internal' ): array {
		$at = (int) self::NOW - $days_ago * 86400;
		return [
			Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => \explode( ':', $key )[0], 'tier' => $tier, 'url' => "https://heron.example/{$key}", 'title' => "A report about {$theme}", 'body' => 'Body.', 'item_ts' => $at, 'retrieved_ts' => $at ], 'machine:source:feed@9.4.1', '' ),
			Intel_Event::make( 'extraction_recorded', [ 'item_key' => $key, 'summary' => "A report about {$theme}.", 'kind' => 'bug', 'relevance' => 6, 'importance' => 5, 'sentiment' => -0.2, 'topics' => [], 'theme' => $theme, 'retrieved_ts' => $at ], 'machine:extract@9.4.1', '' ),
		];
	}
}

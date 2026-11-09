<?php
/**
 * OperatorTest: who runs a deployment and what it tracks is the
 * deployment's to say, through its topology's LLM_Call node.
 *
 * @package Newspack_Intelligence
 */

declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Operator;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';

final class OperatorTest extends TestCase {
	use Seeds_Intel_Graph;

	/** The name every case below configures, apart from every default. */
	private const NAME = 'Quillmere Cooperative';

	/** What every case below says it tracks, apart from every default. */
	private const TRACKS = 'the heron wire, the kite desks Quillmere supplies, and coastal birding';

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
	}

	protected function tearDown(): void {
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** With nothing configured, the operator is generic wording, and so is what it tracks. */
	public function test_an_unset_operator_is_generic(): void {
		$operator = new Operator( '', '' );

		$this->assertSame( 'the organization', $operator->name );
		$this->assertSame( 'the organization\'s own work, the organizations the organization serves as clients, and their industry', $operator->tracks );
	}

	/** A name configured alone tracks its own work and clients. */
	public function test_a_named_operator_tracks_its_own_work_until_told_otherwise(): void {
		$this->assertSame( self::NAME . '\'s own work, the organizations ' . self::NAME . ' serves as clients, and their industry', ( new Operator( self::NAME, '' ) )->tracks );
		$this->assertSame( self::TRACKS, ( new Operator( self::NAME, self::TRACKS ) )->tracks );
	}

	/** A template is read once, so a name or a tracking phrase that spells a placeholder stays as written. */
	public function test_a_template_is_spelled_once(): void {
		$operator = new Operator( 'Quill {tracks} Co', 'the {operator} wire' );

		$this->assertSame( 'Quill {tracks} Co tracks the {operator} wire', $operator->spelled( '{operator} tracks {tracks}' ) );
	}

	/** A template's `{operator}` and `{tracks}` read as the configured ones. */
	public function test_a_template_reads_as_the_operator(): void {
		$this->assertSame( 'a defect in ' . self::NAME . '\'s code, tracking ' . self::TRACKS, ( new Operator( self::NAME, self::TRACKS ) )->spelled( 'a defect in {operator}\'s code, tracking {tracks}' ) );
	}

	/**
	 * What the page shows: a configured name as given, its heading the same,
	 * and the generic one translated, its heading capitalized.
	 */
	public function test_the_page_shows_a_named_operator_as_given_and_the_default_translated(): void {
		$this->assertSame( [ 'name' => 'quillmere', 'heading' => 'quillmere' ], ( new Operator( 'quillmere', '' ) )->shown() );
		$this->assertSame( [ 'name' => 'the organization', 'heading' => 'The organization' ], ( new Operator( '', '' ) )->shown() );
	}

	/** The running topology's LLM_Call node names the operator the page and the prompts read. */
	public function test_the_running_topology_names_the_operator(): void {
		$this->run_topology( "cmd intel:llm:config set_operator \"" . self::NAME . "\"\ncmd intel:llm:config set_tracks \"" . self::TRACKS . "\"\n" );

		$operator = Operator::configured();

		$this->assertSame( [ self::NAME, self::TRACKS ], [ $operator->name, $operator->tracks ] );
	}

	/** A topology setting neither is generic, as is a site running no Intelligence topology. */
	public function test_an_unconfigured_or_absent_topology_is_generic(): void {
		$this->assertSame( 'the organization', Operator::configured()->name, 'no topology runs' );

		$this->run_topology( '' );

		$this->assertSame( 'the organization', Operator::configured()->name, 'the topology names none' );
	}

	/**
	 * Activate a topology composing the streams and one LLM_Call, its own
	 * config lines after it.
	 *
	 * @param string $lines Lines configuring the LLM_Call node.
	 */
	private function run_topology( string $lines ): void {
		$dir = $this->make_temp_dir( 'intel-operator-topologies-' );
		\file_put_contents( "{$dir}/heron-operator.tsl", "include newspack-intelligence-streams\nmake_node LLM_Call intel:llm ai-relay-4417\n{$lines}secure\n" );
		Topology_Registry::register_user_dir( $dir );
		$this->activate( 'heron-operator' );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Ontology_Writer;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Core;
use Newspack_Nodes\Log_Discovery;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/reads-ontology-log.php';

/**
 * The request-side ontology writer: a transient Partition built from the
 * streams topology's own `intel:ontology` declaration, its config lines
 * included, filled, flushed and removed whatever happens.
 */
final class OntologyWriterTest extends TestCase {
	use Reads_Ontology_Log;

	private string $fixtures = '';

	protected function setUp(): void {
		parent::setUp();
		$this->use_base_dir( $this->make_temp_dir( 'intel-ontology-writer-' ) );
		$this->fixtures = $this->make_temp_dir( 'intel-ontology-tsl-' );
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
	}

	protected function tearDown(): void {
		Capabilities::$session_scope = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** The events land in the directory the streams topology declares, and the writer leaves the registry. */
	public function test_events_land_in_the_declared_ontology_directory(): void {
		$events = [ self::created( 'organization:quarry-ledger', 'Quarry Ledger' ), self::created( 'organization:mesa-courier', 'Mesa Courier' ) ];

		Ontology_Writer::append( $events );

		$this->assertSame( $events, self::ontology_events() );
		$this->assertNull( Core::node( Ontology_Writer::WRITER ) );
	}

	/** A second write in one process reuses the name the first released. */
	public function test_a_second_write_does_not_collide_on_the_name(): void {
		Ontology_Writer::append( [ self::created( 'organization:quarry-ledger', 'Quarry Ledger' ) ] );
		Ontology_Writer::append( [ self::created( 'organization:mesa-courier', 'Mesa Courier' ) ] );

		$this->assertSame( [ 'organization:quarry-ledger', 'organization:mesa-courier' ], \array_column( self::ontology_events(), 'entity_key' ) );
	}

	/** The writer carries the topology's keep_everything, so its rotations prune nothing. */
	public function test_the_writer_carries_the_declared_keep_everything(): void {
		$this->shadow_streams( "command_node intel:ontology:config keep_everything true\n" );

		$this->append_one_by_one( 12 );

		$this->assertGreaterThan( 5, self::ontology_segments(), 'past the hard cap, nothing pruned' );
		$this->assertCount( 12, self::ontology_events() );
	}

	/** A tune-scoped caller appends too, and its writer still keeps everything: no role gate judges the caller. */
	public function test_a_tune_scoped_caller_appends_with_keep_everything(): void {
		$this->shadow_streams( "command_node intel:ontology:config keep_everything true\n" );
		Capabilities::$session_scope = Capabilities::TUNE;

		$this->append_one_by_one( 9 );

		$this->assertSame( 9, self::ontology_segments() );
	}

	/**
	 * The stream's raw-read gate, declared by position or by name as the
	 * substrate binds it, reaches the writer beside its keep_everything: the
	 * append lands, and its flush writes the read-capability marker.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function read_gates(): array {
		return [
			'by position' => [ 'set_read_capability quarry_ledger_reader', 'quarry_ledger_reader' ],
			'by name'     => [ 'set_read_capability --capability=mesa_courier_reader', 'mesa_courier_reader' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'read_gates' )]
	public function test_the_writer_carries_the_declared_read_capability( string $line, string $capability ): void {
		$this->shadow_streams( "command_node intel:ontology:config keep_everything true\ncommand_node intel:ontology:config {$line}\n" );

		$this->append_one_by_one( 7 );

		$this->assertSame( 7, self::ontology_segments() );
		$this->assertSame( "{$capability}\n", \file_get_contents( self::ontology_dir() . '/' . Log_Discovery::READ_MARKER ) );
	}

	/** A malformed declared capability fails the append before anything is written. */
	public function test_a_malformed_read_capability_fails_the_append(): void {
		$this->shadow_streams( "command_node intel:ontology:config set_read_capability Quarry-Ledger\n" );

		try {
			Ontology_Writer::append( [ self::created( 'organization:quarry-ledger', 'Quarry Ledger' ) ] );
			$this->fail( 'a malformed capability is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertStringContainsString( "'Quarry-Ledger'", $e->getMessage() );
		}
		$this->assertNull( Core::node( Ontology_Writer::WRITER ) );
		$this->assertSame( [], self::ontology_events() );
	}

	/** Without the declaration the same rotations prune to the count target, so the test above is not vacuous. */
	public function test_a_declaration_without_keep_everything_prunes(): void {
		$this->shadow_streams( '' );

		$this->append_one_by_one( 12 );

		$this->assertSame( 3, self::ontology_segments() );
	}

	/**
	 * A config line the writer does not honour fails the write by name, so a
	 * new line in the declaration is never skipped in silence.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refused_lines(): array {
		return [
			'another verb'          => [ 'void_warranty', 'intel:ontology:config void_warranty' ],
			'keep_everything false' => [ 'keep_everything off', 'intel:ontology:config keep_everything off' ],
			'a bare read gate'      => [ 'set_read_capability', 'intel:ontology:config set_read_capability' ],
			'an unknown option'     => [ 'set_read_capability --cap=quarry_ledger_reader', 'intel:ontology:config set_read_capability --cap=quarry_ledger_reader' ],
			'a surplus token'       => [ 'set_read_capability quarry_ledger_reader mesa_courier_reader', 'intel:ontology:config set_read_capability quarry_ledger_reader mesa_courier_reader' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_lines' )]
	public function test_a_config_line_it_does_not_honour_is_refused_by_name( string $line, string $named ): void {
		$this->shadow_streams( "command_node intel:ontology:config {$line}\n" );

		try {
			Ontology_Writer::append( [ self::created( 'organization:quarry-ledger', 'Quarry Ledger' ) ] );
			$this->fail( 'the line is refused' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( $named, $e->getMessage() );
		}
		$this->assertNull( Core::node( Ontology_Writer::WRITER ) );
		$this->assertSame( [], self::ontology_events() );
	}

	/** A streams topology that declares no ontology partition is refused by name. */
	public function test_a_topology_without_the_ontology_partition_is_refused(): void {
		\file_put_contents( "{$this->fixtures}/newspack-intelligence-streams.tsl", "make_node Partition intel:elsewhere <config:logs_dir>/elsewhere.p{partition}\n" );
		Topology_Registry::register_stock_dir( $this->fixtures );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'newspack-intelligence-streams declares no intel:ontology Partition' );
		Ontology_Writer::append( [ self::created( 'organization:quarry-ledger', 'Quarry Ledger' ) ] );
	}

	/**
	 * Shadow the streams topology with tiny segments, a count target of three
	 * and a hard cap of five.
	 *
	 * @param string $config The config lines after the Partition's.
	 */
	private function shadow_streams( string $config ): void {
		\file_put_contents( "{$this->fixtures}/newspack-intelligence-streams.tsl", "make_node Partition intel:ontology <config:logs_dir>/intel-ontology.p{partition} 256 2 3 5 0 0\n{$config}" );
		Topology_Registry::register_stock_dir( $this->fixtures );
	}

	/**
	 * One write per event, each opening the directory afresh.
	 *
	 * @param int $count How many.
	 */
	private function append_one_by_one( int $count ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			Ontology_Writer::append( [ self::created( "organization:ledger-{$i}", "Ledger number {$i} of the Quarry Valley press" ) ] );
		}
	}

	/**
	 * A person's entity_created.
	 *
	 * @param string $key  The entity key.
	 * @param string $name Its name.
	 * @return array<string,mixed>
	 */
	private static function created( string $key, string $name ): array {
		return Intel_Event::make( 'entity_created', [ 'entity_key' => $key, 'type' => 'organization', 'name' => $name ], 'user:73', '4.2.9' );
	}
}

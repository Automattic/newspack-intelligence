<?php
/**
 * Uninstall removes the files of every Row_Table the plugin's shipped
 * topologies declare with the Intel_Graph schema, and of every frontier
 * Table its sources own, and only this install's.
 *
 * @package Newspack_Intelligence\Tests\Unit
 */

declare( strict_types = 1 );

namespace Newspack_Intelligence\Tests\Unit;

use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;

require_once \dirname( __DIR__, 2 ) . '/includes/uninstall-cleanup.php';

final class UninstallGraphTablesTest extends TestCase {

	/** The shipped topologies include the substrate's own. */
	protected function setUp(): void {
		parent::setUp();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
	}

	/** Each case's own dirs leave the registry; the next setUp re-registers. */
	protected function tearDown(): void {
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_uninstall_deletes_every_partition_file_of_the_shipped_graph_table(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-graph-' ) );
		$doomed = self::partition_files( 'intel-graph' );
		$kept   = [
			Table_Node::file( 'flame-facts', 0 ),
			Table_Node::file( 'heron-ledger-4417', 3 ),
		];
		self::write( ...$doomed, ...$kept );

		$deleted = \Newspack_Intelligence\delete_tables( \dirname( __DIR__, 2 ) . '/topologies' );

		$this->assertSame( 3 * Spawn_Coordinator::MAX_PARTITIONS, $deleted, 'three files for each partition' );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'another Table\'s file stands' );
		}
	}

	/** Each source's frontier Table goes with the graph, on every partition. */
	public function test_uninstall_deletes_every_sources_frontier_table(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-frontier-' ) );
		$doomed = [];
		foreach ( [ 'github', 'feed', 'news', 'linear', 'slack', 'p2', 'p2-restricted', 'unthread' ] as $source ) {
			$doomed = [ ...$doomed, ...self::partition_files( "{$source}:frontier" ) ];
		}
		$kept = [ Table_Node::file( 'heron-ledger-4417', 3 ), Table_Node::file( 'egret:frontier', 2 ) ];
		self::write( ...self::partition_files( 'intel-graph' ), ...$doomed, ...$kept );

		$deleted = \Newspack_Intelligence\delete_tables( \dirname( __DIR__, 2 ) . '/topologies' );

		$this->assertSame( 3 * Spawn_Coordinator::MAX_PARTITIONS * 9, $deleted, 'the graph and eight frontiers' );
		foreach ( $doomed as $file ) {
			$this->assertFileDoesNotExist( $file );
		}
		foreach ( $kept as $file ) {
			$this->assertFileExists( $file, 'a Table no shipped source owns stands' );
		}
	}

	/** The table is found by its schema, whatever a topology names it. */
	public function test_uninstall_finds_the_graph_table_by_schema_whatever_its_name(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-graph-named-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-graph-shipped-' );
		\file_put_contents( "{$shipped}/heron-graph-5521.tsl", "make_node Row_Table heron-graph-5521 Intel_Graph\nmake_node Table heron-cache-5521 heron:p{partition} 60 sqlite\n" );
		$doomed = self::partition_files( 'heron-graph-5521' );
		$kept   = [ Table_Node::file( 'heron-cache-5521', 0 ) ];
		self::write( ...$doomed, ...$kept );

		$this->assertSame( 3 * Spawn_Coordinator::MAX_PARTITIONS, \Newspack_Intelligence\delete_tables( $shipped ) );
		$this->assertFileDoesNotExist( $doomed[0] );
		$this->assertFileExists( $kept[0], 'a keyed Table stands' );
		$this->assertNull( Topology_Registry::resolve( 'heron-graph-5521' ), 'the read leaves its dir unregistered' );
	}

	/** Another plugin's topologies are never read, broken or not. */
	public function test_uninstall_reads_its_own_topologies_and_no_others(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-graph-foreign-' ) );
		$foreign = $this->make_temp_dir( 'uninstall-graph-foreign-dir-' );
		\file_put_contents( "{$foreign}/heron-broken-6631.tsl", "include heron-nowhere-6631\n" );
		\file_put_contents( "{$foreign}/heron-other-6631.tsl", "make_node Row_Table heron-foreign-6631 Intel_Graph\n" );
		Topology_Registry::register_stock_dir( $foreign );
		$kept = Table_Node::file( 'heron-foreign-6631', 2 );
		self::write( ...self::partition_files( 'intel-graph' ), ...[ $kept ] );

		$this->assertSame( 3 * Spawn_Coordinator::MAX_PARTITIONS, \Newspack_Intelligence\delete_tables( \dirname( __DIR__, 2 ) . '/topologies' ) );
		$this->assertFileExists( $kept, 'another plugin\'s table is its own to clean up' );
	}

	/** A shipped part that will not read for any reason but the missing deployment file fails uninstall loud. */
	public function test_uninstall_refuses_a_shipped_part_broken_another_way(): void {
		$this->use_base_dir( $this->make_temp_dir( 'uninstall-graph-broken-' ) );
		$shipped = $this->make_temp_dir( 'uninstall-graph-broken-shipped-' );
		\file_put_contents( "{$shipped}/heron-broken-8841.tsl", "make_node Row_Table heron-graph-8841 Intel_Graph\ninclude heron-absent-8841\n" );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'unknown topology in include: heron-absent-8841' );
		\Newspack_Intelligence\delete_tables( $shipped );
	}

	/** Every unconfigured install shares the default base, and so its files. */
	public function test_uninstall_leaves_the_base_every_unconfigured_install_shares(): void {
		$default = \Newspack_Nodes\Settings_Schema::get()->defaults()['base_directory'];
		$dir     = $this->make_temp_dir( 'uninstall-graph-default-' );
		$shipped = $this->make_temp_dir( 'uninstall-graph-default-shipped-' );
		\file_put_contents( "{$shipped}/heron-default-7712.tsl", "make_node Row_Table heron-default-7712 Intel_Graph\n" );
		$this->use_base_dir( $dir );
		\file_put_contents( "{$dir}/test-config.php", "<?php\nreturn [ 'base_directory' => '{$default}' ];\n" );
		\Newspack_Nodes\Config::reset();
		$file = Table_Node::file( 'heron-default-7712', 0 );
		self::write( $file );
		try {
			$this->assertSame( 0, \Newspack_Intelligence\delete_tables( $shipped ) );
			$this->assertFileExists( $file );
		} finally {
			\is_file( $file ) && \unlink( $file );
		}
	}

	/**
	 * A Row_Table's file and its WAL files on every partition a fleet runs.
	 *
	 * @return list<string>
	 */
	private static function partition_files( string $table ): array {
		$files = [];
		for ( $partition = 0; $partition < Spawn_Coordinator::MAX_PARTITIONS; $partition++ ) {
			$file = Table_Node::file( $table, $partition );
			\array_push( $files, $file, "{$file}-wal", "{$file}-shm" );
		}
		return $files;
	}

	private static function write( string ...$files ): void {
		foreach ( $files as $file ) {
			\is_dir( \dirname( $file ) ) || \mkdir( \dirname( $file ), 0700, true );
			\file_put_contents( $file, 'heron-4417' );
		}
	}
}

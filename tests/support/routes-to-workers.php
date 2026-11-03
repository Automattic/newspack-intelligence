<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Tests\Capture_Sink_Node;

/**
 * The worker fixture: Intelligence composed under a topology name of the
 * test's own, a base dir holding live workers' lock and input IPC dirs, what
 * a worker's input partition holds on disk, and a worker reading it.
 */
trait Routes_To_Workers {

	/**
	 * Activate `$topology`, beside whatever is active already, as a stock
	 * `.tsl` that includes the streams, and so declares the event writer,
	 * then holds `$lines`; the plugin's and the substrate's dirs resolve
	 * its includes.
	 *
	 * @param string $topology The topology's name.
	 * @param string ...$lines Its lines after the streams.
	 */
	private function run_intelligence_as( string $topology, string ...$lines ): void {
		$dir = $this->make_temp_dir( "intelligence-as-{$topology}-" );
		\file_put_contents( "{$dir}/{$topology}.tsl", \implode( "\n", [ 'include newspack-intelligence-streams', ...$lines ] ) . "\n" );
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		Topology_Registry::register_stock_dir( $dir );
		Topology_Analyzer::reset_caches();
		\update_option( 'newspack_nodes_topologies', [ ...Bootstrap::active_names(), $topology ] );
		Config::reset();
	}

	/**
	 * Give a base dir one live worker: its lock dir and its IPC input dir.
	 *
	 * @param string $base      The base dir.
	 * @param string $worker_id The worker id.
	 */
	private static function live_worker( string $base, string $worker_id ): void {
		[ $type, $partition ] = CLI::parse_worker_id( $worker_id ) ?? throw new \LogicException( "not a worker id: {$worker_id}" );
		\mkdir( "{$base}/locks/{$worker_id}.lock.d", 0777, true );
		\mkdir( Worker_Base::ipc_dir( $base, $type, $partition, Worker_Base::IPC_INPUT ), 0777, true );
	}

	/**
	 * Read a worker's IPC input to its end and commit the cursor, as its
	 * input reader does.
	 *
	 * @param string $base      The base dir.
	 * @param string $worker_id The worker id.
	 */
	private static function read_input( string $base, string $worker_id ): void {
		[ $type, $partition ] = CLI::parse_worker_id( $worker_id ) ?? throw new \LogicException( "not a worker id: {$worker_id}" );
		$reader               = new Consumer_Node();
		$reader->arguments( [ Worker_Base::ipc_dir( $base, $type, $partition, Worker_Base::IPC_INPUT ), Worker_Base::ipc_dir( $base, $type, $partition, Worker_Base::IPC_INPUT_OFFSETS ) ] );
		$reader->sink( new Capture_Sink_Node() );
		$reader->drain();
		$reader->checkpoint();
	}

	/**
	 * Every message a worker's IPC input partition holds on disk, oldest first.
	 *
	 * @param string $base      The base dir.
	 * @param string $worker_id The worker id.
	 * @return array<int,array<int,mixed>>
	 */
	private static function worker_input( string $base, string $worker_id ): array {
		[ $type, $partition ] = CLI::parse_worker_id( $worker_id ) ?? throw new \LogicException( "not a worker id: {$worker_id}" );
		$segments             = \glob( Worker_Base::ipc_dir( $base, $type, $partition, Worker_Base::IPC_INPUT ) . '/*' ) ?: [];
		\natsort( $segments );
		$messages = [];
		foreach ( $segments as $segment ) {
			foreach ( \file( $segment, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
				$messages[] = Message::unpacked( $line );
			}
		}
		return $messages;
	}
}

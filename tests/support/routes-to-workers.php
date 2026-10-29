<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\CLI;
use Newspack_Nodes\Consumer_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Tests\Capture_Sink_Node;

/**
 * The worker fixture: a base dir holding live workers' lock and input IPC
 * dirs, what a worker's input partition holds on disk, and a worker reading
 * it.
 */
trait Routes_To_Workers {

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

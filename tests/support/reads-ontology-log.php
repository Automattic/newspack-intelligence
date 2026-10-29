<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Nodes\Config;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

/**
 * Reads back what landed in the ontology or watches stream's partition-0
 * directory.
 */
trait Reads_Ontology_Log {

	/** The ontology stream's partition-0 directory. */
	private static function ontology_dir(): string {
		return Config::get_logs_directory() . '/intel-ontology.p0';
	}

	/** The segments the ontology directory holds. */
	private static function ontology_segments(): int {
		$reader = new Partition_Node();
		$reader->arguments( [ self::ontology_dir() ] );
		return \count( $reader->get_segments( true ) );
	}

	/**
	 * Every event the ontology directory holds, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function ontology_events(): array {
		return self::events_in( self::ontology_dir() );
	}

	/**
	 * Every event the watches stream's partition-0 directory holds, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function watches_events(): array {
		return self::events_in( Config::get_logs_directory() . '/intel-watches.p0' );
	}

	/**
	 * Every event a log directory holds, oldest first.
	 *
	 * @param string $dir The directory.
	 * @return list<array<string,mixed>>
	 */
	private static function events_in( string $dir ): array {
		$reader = new Partition_Node();
		$reader->arguments( [ $dir ] );
		$events = [];
		foreach ( $reader->get_segments( true ) as $segment ) {
			foreach ( \file( $reader->get_segment_path( $segment['id'] ), \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES ) ?: [] as $line ) {
				$events[] = Message::unpacked( $line )[ Message::VALUE ];
			}
		}
		return $events;
	}
}

<?php
/**
 * Items_CLI_Command: `wp newspack-intelligence items purge`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Graph_Curation;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

class Items_CLI_Command {
	use Refuses_Root;

	/**
	 * Events one append carries: an item key and a reason each at its cap in
	 * the bytes the wire carries, this many times, pack under one IPC
	 * segment, so the unread batch never outruns the input partition's
	 * retention.
	 */
	public const BATCH = 500;

	/** Bytes a reason holds at most, as the stream writes it. */
	public const REASON_BYTES = 512;

	/** Seconds the command waits for the workers to read what their input holds. */
	public const READ_WAIT_S = 600;

	/** Seconds between looks at the workers' input cursors. */
	private const POLL_S = 5;

	/** The worker's node every purge is appended to. */
	private const WRITER = 'intel:writer';

	/**
	 * Wait seam: replaces the one `sleep()` between looks at the workers'
	 * input cursors. Tests reassign it to read a worker's input in the
	 * wait, or to count the seconds waited.
	 * Signature: `function ( int $seconds ): void`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $sleep = null;

	/**
	 * Purge a source's stored items: every one whose key matches none of the
	 * keep globs. Without --yes it counts them per key prefix, up to the
	 * first `-`, and prints the command that purges them; with --yes it
	 * appends one `item_purged` per item to every live worker's writer, a
	 * batch at a time, each once the workers have read everything their
	 * input held. Each worker's projector deletes the rows its items stream
	 * has written about the item; rows other streams wrote stay, and no read
	 * answers them. A source recording a purged key again records it again.
	 *
	 * ## OPTIONS
	 *
	 * --source=<name>
	 * : The source whose items are purged, as an item key begins.
	 *
	 * --keep=<globs>
	 * : Comma-separated globs, each matched against the whole item key in its own case; an item matching any is kept. Given twice, the command refuses, since WP-CLI keeps only the last.
	 *
	 * [--reason=<text>]
	 * : Why the items are purged, up to 512 bytes as the stream writes it; the selection by default.
	 *
	 * [--yes]
	 * : Purge; without it the command only counts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence items purge --source=linear --keep='linear:NEWS-*,linear:NPAI-*'
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>   $args       Positional args.
	 * @param array<string,mixed> $assoc_args Flags.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 * @throws \RuntimeException When no worker is live.
	 * @throws \LogicException When the process carries no argv.
	 */
	public function purge( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'items purge' ) ) {
			return;
		}
		$keeps_given = self::times_given( 'keep' );
		if ( $keeps_given > 1 ) {
			\WP_CLI::error( "--keep was given {$keeps_given} times, and WP-CLI keeps only the last; pass one comma-separated list: --keep='<glob>,<glob>'." );
			return;
		}
		$source = \trim( Core::as_string( $assoc_args['source'] ?? '' ) );
		$keep   = $assoc_args['keep'] ?? null;
		$reason = $assoc_args['reason'] ?? null;
		if ( '' === $source ) {
			\WP_CLI::error( '--source names the source whose items are purged.' );
			return;
		}
		if ( null === $keep ) {
			\WP_CLI::error( '--keep names the items to keep; a purge never takes every item of a source.' );
			return;
		}
		$keeps = \is_string( $keep ) ? \explode( ',', $keep ) : [ '' ];
		if ( \in_array( '', $keeps, true ) ) {
			\WP_CLI::error( '--keep holds an empty glob; separate the globs by single commas.' );
			return;
		}
		$selection = "{$source} items, keeping " . \implode( ', ', $keeps );
		$why       = null === $reason ? $selection : Core::as_string( $reason );
		if ( '' === $why || Graph_Curation::written_bytes( $why ) > self::REASON_BYTES ) {
			\WP_CLI::error( '--reason says why the items are purged, in 1 to ' . self::REASON_BYTES . ' bytes as the stream writes it.' );
			return;
		}
		$keys = self::selected( $source, $keeps );
		if ( [] === $keys ) {
			\WP_CLI::success( "Nothing to purge: {$selection}." );
			return;
		}
		$dry_run = 'wp newspack-intelligence items purge --source=' . \escapeshellarg( $source ) . ' --keep=' . \escapeshellarg( \implode( ',', $keeps ) );
		if ( ! (bool) ( $assoc_args['yes'] ?? false ) ) {
			self::count( $keys, $selection, $dry_run . ( null === $reason ? '' : ' --reason=' . \escapeshellarg( $why ) ) . ' --yes' );
			return;
		}
		self::append( $keys, $why, $dry_run );
	}

	/**
	 * Append one `item_purged` per key to every live worker's writer, a
	 * batch at a time, each once the workers have read their input through.
	 *
	 * @param list<string> $keys    The selected keys.
	 * @param string       $reason  Why.
	 * @param string       $dry_run The command that counts what is left.
	 * @throws \RuntimeException When no worker is live.
	 */
	private static function append( array $keys, string $reason, string $dry_run ): void {
		$events      = \array_map( static fn ( string $key ): array => Intel_Event::machine_event( 'purge', 'item_purged', [ 'item_key' => $key, 'reason' => $reason ] ), $keys );
		$interpreter = Bootstrap::mount_request_graph();
		$base        = Config::get_base_directory();
		$batches     = \array_chunk( $events, self::BATCH );
		$sent        = 0;
		$workers     = 0;
		foreach ( $batches as $batch ) {
			if ( ! self::read( $base ) ) {
				\WP_CLI::error( \sprintf( 'Sent %d of %d item_purged events; the workers did not read their input within %d seconds. Run the command again once they have: it selects only the items the projection still holds.', $sent, \count( $events ), self::READ_WAIT_S ) );
				return;
			}
			$workers = Worker_Route::append( $interpreter, $base, self::WRITER, $batch );
			$sent   += \count( $batch );
			\WP_CLI::log( \sprintf( 'Sent %d of %d.', $sent, \count( $events ) ) );
		}
		$one = 1 === $workers;
		\WP_CLI::success( \sprintf( 'Sent %d item_purged events in %d %s to %d %s. Once %s read them, run the dry run again to confirm nothing is left: %s', $sent, \count( $batches ), 1 === \count( $batches ) ? 'batch' : 'batches', $workers, $one ? 'worker' : 'workers', $one ? 'its projector has' : 'their projectors have', $dry_run ) );
	}

	/**
	 * Wait, up to READ_WAIT_S, for every live worker to read its input
	 * through.
	 *
	 * @param string $base The substrate base directory.
	 */
	private static function read( string $base ): bool {
		$sleep = self::$sleep ?? static function ( int $seconds ): void {
			\sleep( $seconds );
		};
		for ( $waited = 0; ! Worker_Route::read_through( $base ); $waited += self::POLL_S ) {
			if ( $waited >= self::READ_WAIT_S ) {
				return false;
			}
			$sleep( self::POLL_S );
		}
		return true;
	}

	/**
	 * Print the count per key prefix, its control characters as codepoints,
	 * the total, and the command that purges them.
	 *
	 * @param list<string> $keys      The selected keys.
	 * @param string       $selection The selection, as the operator reads it.
	 * @param string       $purge     The command that purges them.
	 */
	private static function count( array $keys, string $selection, string $purge ): void {
		$prefixes = \array_count_values( \array_map( static fn ( string $key ): string => \explode( '-', $key, 2 )[0], $keys ) );
		\ksort( $prefixes, \SORT_STRING );
		foreach ( $prefixes as $prefix => $count ) {
			\WP_CLI::log( "{$count}\t" . Core::terminal_safe( Core::as_string( $prefix ) ) );
		}
		\WP_CLI::log( \sprintf( 'Total: %d to purge of %s.', \count( $keys ), $selection ) );
		\WP_CLI::log( "Purge them with: {$purge}" );
	}

	/**
	 * A source's stored item keys, across every partition, that no keep glob
	 * matches, each once, in key order.
	 *
	 * @param string       $source The source.
	 * @param list<string> $keeps  The keep globs.
	 * @return list<string>
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 */
	private static function selected( string $source, array $keeps ): array {
		$keys = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $source ): array {
				$keys = [];
				foreach ( $r->aliases() as $a ) {
					foreach ( $r->rows( "SELECT item_key FROM {$a}.items WHERE source = ?", [ $source ] ) as [ $key ] ) {
						$keys[] = Core::as_string( $key );
					}
				}
				return $keys;
			}
		);
		$keys = \array_unique( $keys );
		\sort( $keys, \SORT_STRING );
		return \array_values( \array_filter( $keys, static fn ( string $key ): bool => [] === \array_filter( $keeps, static fn ( string $glob ): bool => \fnmatch( $glob, $key ) ) ) );
	}

	/**
	 * How many times the command line names a flag, `--<flag>=…` or a bare
	 * `--<flag>`, which WP-CLI folds into its last occurrence.
	 *
	 * @param string $flag The flag's name.
	 * @throws \LogicException When the process carries no argv.
	 */
	private static function times_given( string $flag ): int {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- counted, never output.
		$argv = $_SERVER['argv'] ?? throw new \LogicException( 'items purge reads its flags off argv, which this process does not carry' );
		return \count( \array_filter( Core::arr( $argv ), static fn ( mixed $token ): bool => "--{$flag}" === $token || \str_starts_with( Core::as_string( $token ), "--{$flag}=" ) ) );
	}
}

<?php
/**
 * Ontology_CLI_Command: `wp newspack-intelligence ontology seed`,
 * `wp newspack-intelligence ontology reresolve`,
 * `wp newspack-intelligence ontology reextract` and
 * `wp newspack-intelligence ontology reproject`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Intelligence\Projector_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Reresolver_Node;
use Newspack_Intelligence\Seed_Refused;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Sqlite_Arm;

\defined( 'ABSPATH' ) || exit;

class Ontology_CLI_Command {
	use Refuses_Root;

	/** The most extraction calls `reextract` makes unless its --limit says more. */
	public const REEXTRACT_LIMIT = 200;

	/**
	 * Re-resolve every stored item dated in a range of UTC days, an undated
	 * one by when it was retrieved, of one source when --source names it.
	 * Each live worker's reresolver is asked through its input partition and
	 * sends the range to the resolver a page at a time.
	 *
	 * ## OPTIONS
	 *
	 * --since=<Y-m-d>
	 * : The first day, UTC.
	 *
	 * [--until=<Y-m-d>]
	 * : The last day, UTC; today when omitted.
	 *
	 * [--source=<name>]
	 * : Only the items of this source, as `slack` or `linear`; every source when omitted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology reresolve --since=2026-09-01 --until=2026-09-30
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \RuntimeException When there is no live worker.
	 * @throws \Newspack_Nodes\Table_Unavailable When --source is given and the projection cannot be read.
	 */
	public function reresolve( array $args, array $assoc_args ): void {
		self::ask_range( 'reresolve', $assoc_args, static fn (): string => '' );
	}

	/**
	 * Re-extract every stored item dated in a range of UTC days, an undated one
	 * by when it was retrieved, of one source when --source names it. Each
	 * live worker's reresolver is asked through its input partition, as
	 * `reresolve` asks it, and sends each item to the
	 * resolver owed an extraction, so each worker's extractor calls the model
	 * for each item whatever it holds: the items times the live workers, and a
	 * call that fails keeps the item's earlier extraction and retries up to
	 * `Extractor_Node::MAX_ATTEMPTS` times. It counts those extraction calls
	 * first and refuses more than --limit, naming the items and the workers; a
	 * range that contains no item sends nothing. Each extraction can add one
	 * decision-model call, its review, which the count leaves out. With
	 * --dry-run it sends nothing and prints the calls, says so, and prints the
	 * command that runs them.
	 *
	 * ## OPTIONS
	 *
	 * --since=<Y-m-d>
	 * : The first day, UTC.
	 *
	 * [--until=<Y-m-d>]
	 * : The last day, UTC; today when omitted.
	 *
	 * [--source=<name>]
	 * : Only the items of this source, as `slack` or `linear`; every source when omitted.
	 *
	 * [--limit=<n>]
	 * : The most extraction calls it makes, retries and decision-model calls aside; 200 when omitted.
	 *
	 * [--dry-run]
	 * : Print the calls and the command, and send nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology reextract --since=2026-10-01 --until=2026-10-07 --dry-run
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \RuntimeException When --limit is not a positive whole number, or
	 *                           there is no live worker.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 */
	public function reextract( array $args, array $assoc_args ): void {
		self::ask_range(
			'reextract',
			$assoc_args,
			static function ( string $since, string $until, string $source ) use ( $assoc_args ): ?string {
				$limit   = CLI::require_flag_int( $assoc_args, 'limit', self::REEXTRACT_LIMIT, false );
				$count   = Reresolver_Node::count_dated( $since, $until, $source );
				$workers = \count( Worker_Route::live_workers( Config::get_base_directory() ) );
				$calls   = $count * $workers;
				$of      = '' === $source ? '' : "{$source} ";
				$range   = "dated {$since} to {$until}";
				$retries = 'a failed one retried up to ' . Extractor_Node::MAX_ATTEMPTS . ' times';
				if ( 0 === $count ) {
					\WP_CLI::success( "No {$of}item is {$range}; nothing to re-extract." );
					return null;
				}
				if ( isset( $assoc_args['dry-run'] ) ) {
					\WP_CLI::log( "{$count} {$of}items {$range} would be re-extracted by " . self::workers( $workers, 'live ' ) . ": {$calls} extraction calls, each of which can add one decision-model call, {$retries}." );
					\WP_CLI::log( "Re-extract them with: wp newspack-intelligence ontology reextract --since={$since} --until={$until}" . ( '' === $source ? '' : " --source={$source}" ) . ( $calls > self::REEXTRACT_LIMIT ? " --limit={$calls}" : '' ) );
					return null;
				}
				if ( $calls > $limit ) {
					\WP_CLI::error( "ontology reextract would make {$calls} extraction calls, {$count} {$of}items {$range} times " . self::workers( $workers, 'live ' ) . ", above --limit={$limit}, and a failed call may retry up to " . Extractor_Node::MAX_ATTEMPTS . ' times; raise --limit to run it.' );
					return null;
				}
				return ": {$calls} extraction calls, {$retries}";
			}
		);
	}

	/**
	 * Ask each live worker's reresolver for a range of UTC days, of one
	 * source or of every one, the request the verb names upper-cased, once
	 * root is refused and `$vet` passes it, and say how many workers it
	 * asked; a range that does not parse, or a source no stored item is
	 * recorded under, is refused naming why.
	 *
	 * @param string                                        $verb       `reresolve` or `reextract`.
	 * @param array<string,string>                          $assoc_args Flags: `since`, `until`, today when omitted, and `source`, every one when omitted.
	 * @param \Closure(string,string,string): (string|null) $vet        Given the days and the source, what the success line adds, or null to send nothing.
	 * @throws \RuntimeException When there is no live worker.
	 * @throws \Newspack_Nodes\Table_Unavailable When --source is given and the projection cannot be read.
	 */
	private static function ask_range( string $verb, array $assoc_args, \Closure $vet ): void {
		if ( self::refused_root( "ontology {$verb}" ) ) {
			return;
		}
		$since = $assoc_args['since'] ?? '';
		$until = $assoc_args['until'] ?? \gmdate( 'Y-m-d' );
		try {
			$source = self::source_of( $assoc_args );
			$tail   = $vet( $since, $until, $source );
			if ( null === $tail ) {
				return;
			}
			$workers = Reresolver_Node::ask( Bootstrap::mount_request_graph(), \strtoupper( $verb ), $since, $until, $source );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$of = '' === $source ? '' : "{$source} ";
		\WP_CLI::success( 'Asked ' . self::workers( $workers, '' ) . ' to ' . \substr_replace( $verb, 're-', 0, 2 ) . " the {$of}items dated {$since} to {$until}{$tail}." );
	}

	/**
	 * A count of workers in words: `1 worker`, `2 live workers`.
	 *
	 * @param int    $count How many.
	 * @param string $kind  A word before the noun, with its space, or ''.
	 */
	private static function workers( int $count, string $kind ): string {
		return "{$count} {$kind}" . ( 1 === $count ? 'worker' : 'workers' );
	}

	/**
	 * The source `--source` names, trimmed as `items purge` reads it, or ''
	 * for every source when the flag is left out; one given empty, or not
	 * among the sources stored items are recorded under
	 * (`Items_CLI_Command::stored_sources()`), is refused naming them.
	 *
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \InvalidArgumentException When the source is empty or no item is recorded under it.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 */
	private static function source_of( array $assoc_args ): string {
		if ( ! \array_key_exists( 'source', $assoc_args ) ) {
			return '';
		}
		$source = \trim( Core::as_string( $assoc_args['source'] ) );
		if ( '' === $source ) {
			throw new \InvalidArgumentException( '--source is empty; name a source, or leave --source out for every source.' );
		}
		$known = Items_CLI_Command::stored_sources();
		if ( ! \in_array( $source, $known, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( '--source must name a source items are recorded under, ' . ( [] === $known ? 'and no item is recorded yet' : 'one of: ' . \implode( ', ', $known ) ) . "; got {$source}." );
		}
		return $source;
	}

	/**
	 * Rebuild the graph projection from the event streams. Holds the fleet
	 * with `wp nodes stop`, deletes the `intel-graph` Row_Table's files and
	 * the cursor of every Consumer the projector checkpoints with, then
	 * releases the hold with `wp nodes start`, so the projector replays every
	 * stream from its oldest segment. Each verb runs in its own process,
	 * so whatever it throws reaches this one as an exit code and stderr. A
	 * fleet that does not stop has nothing deleted under it, and the hold is
	 * released all the same. The streams and every other
	 * reader's cursor stay. Run it after an upgrade that changes the graph
	 * schema's version, which leaves the projection empty until it runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology reproject
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \Newspack_Nodes\Table_Unavailable When the active topologies do
	 *                                           not declare the graph.
	 * @throws \UnexpectedValueException When a file will not unlink; the hold is released first.
	 */
	public function reproject( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'ontology reproject' ) ) {
			return;
		}
		$table   = Bootstrap::row_table( Intel_Graph_Row_Schema::class );
		$cursors = \array_values( \array_unique( \array_column( Projector_Node::readers(), 'offsets' ) ) );
		$base    = Config::get_base_directory();
		$stopped = false;
		$why     = '';
		try {
			$stop    = \WP_CLI::runcommand( 'nodes stop', [ 'launch' => true, 'exit_error' => false, 'return' => 'all' ] );
			$stop    = \is_object( $stop ) ? \get_object_vars( $stop ) : [];
			$said    = \trim( Core::as_string( $stop['stdout'] ?? '' ) );
			$stopped = 0 === ( $stop['return_code'] ?? null );
			$why     = \trim( Core::as_string( $stop['stderr'] ?? '' ) );
			if ( '' !== $said ) {
				\WP_CLI::log( $said );
			}
			foreach ( $stopped ? $table['files'] : [] as $file ) {
				Sqlite_Arm::remove_database( $file );
			}
			foreach ( $stopped ? $cursors : [] as $dir ) {
				Spawn_Coordinator::delete_directory_recursive( $dir, $base );
			}
		} finally {
			\WP_CLI::runcommand( 'nodes start', [ 'launch' => true, 'exit_error' => true ] );
		}
		if ( ! $stopped ) {
			\WP_CLI::error( \trim( "The fleet did not stop, so reproject deleted nothing; the hold is released. {$why}" ) );
			return;
		}
		\WP_CLI::success( \sprintf( 'Deleted the %s projection and %d projector cursors; the projector replays every stream.', $table['name'], \count( $cursors ) ) );
	}

	/**
	 * Write the seed ontology — the entities and lenses a seed file names —
	 * to the ontology stream, for the keys the projection lacks. The file is
	 * the one given, else the site's `newspack-intelligence-ontology-seed.json`
	 * in its Intelligence data dir, beside the runtime base. A
	 * file that does not parse, or names an unknown type, role, kind or lens
	 * filter, is refused naming the field. A key the projection already
	 * holds is left as curated, and a projection that will not open fails the
	 * command rather than writing everything. While the projector has not
	 * read every ontology change, it refuses and writes nothing.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : A seed file to read in place of the site's seed file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology seed
	 *     wp newspack-intelligence ontology seed /srv/deploy/ontology-seed.json
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 */
	public function seed( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'ontology seed' ) ) {
			return;
		}
		try {
			$seeded = Ontology_Seed::load( $args[0] ?? null )->seed();
		} catch ( Seed_Refused $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		\WP_CLI::success( \sprintf( 'Seeded %d events: %d keys new, %d present.', $seeded['events'], $seeded['created'], $seeded['present'] ) );
	}
}

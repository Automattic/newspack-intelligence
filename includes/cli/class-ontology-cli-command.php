<?php
/**
 * Ontology_CLI_Command: `wp newspack-intelligence ontology seed`,
 * `wp newspack-intelligence ontology reresolve` and
 * `wp newspack-intelligence ontology reproject`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Ontology_Seed;
use Newspack_Intelligence\Ontology_Writer;
use Newspack_Intelligence\Projector_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Spawn_Coordinator;
use Newspack_Nodes\Sqlite_Arm;

\defined( 'ABSPATH' ) || exit;

class Ontology_CLI_Command {
	use Refuses_Root;

	/**
	 * Rebuild the graph projection from the event streams. Holds the fleet
	 * with `wp nodes stop`, deletes the `intel-graph` Row_Table's files and
	 * the cursor of every Consumer the projector checkpoints with, then
	 * releases the hold with `wp nodes start`, so the projector replays both
	 * streams from their oldest segment. Each verb runs in its own process,
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
	 * @throws \Newspack_Nodes\Table_Unavailable When no active topology declares the graph.
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
		\WP_CLI::success( \sprintf( 'Deleted the %s projection and %d projector cursors; the projector replays both streams.', $table['name'], \count( $cursors ) ) );
	}

	/**
	 * Write the seed ontology — topics, partners, funders, industry bodies
	 * and Newspack's products — to the ontology stream, for the keys the
	 * projection lacks. A key it already holds is left as curated, and a
	 * projection that will not open fails the command rather than writing
	 * everything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology seed
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
		$present = Ontology_Seed::present();
		$events  = Ontology_Seed::events( $present );
		Ontology_Writer::append( $events );
		$created = \count( \array_filter( $events, static fn ( array $event ): bool => 'entity_created' === $event['event'] ) );
		\WP_CLI::success( \sprintf( 'Seeded %d events: %d keys new, %d present.', \count( $events ), $created, \count( $present ) ) );
	}

	/**
	 * Re-resolve every stored item dated in a range of UTC days, an undated
	 * one by when it was retrieved. Each live worker's reresolver is asked
	 * through its input partition and sends the range to the resolver a page
	 * at a time.
	 *
	 * ## OPTIONS
	 *
	 * --since=<Y-m-d>
	 * : The first day, UTC.
	 *
	 * [--until=<Y-m-d>]
	 * : The last day, UTC; today when omitted.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence ontology reresolve --since=2026-09-01 --until=2026-09-30
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \RuntimeException When no worker is live.
	 */
	public function reresolve( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'ontology reresolve' ) ) {
			return;
		}
		$since = $assoc_args['since'] ?? '';
		$until = $assoc_args['until'] ?? \gmdate( 'Y-m-d' );
		try {
			Intel_Graph_Row_Schema::range( $since, $until );
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
			return;
		}
		$workers = Worker_Route::send( Bootstrap::mount_request_graph(), Config::get_base_directory(), [ [ 'reresolver', "RERESOLVE {$since} {$until}" ] ] );
		\WP_CLI::success( \sprintf( 'Asked %d %s to re-resolve the items dated %s to %s.', $workers, 1 === $workers ? 'worker' : 'workers', $since, $until ) );
	}
}

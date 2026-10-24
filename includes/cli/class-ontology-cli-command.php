<?php
/**
 * Ontology_CLI_Command: `wp newspack-intelligence ontology seed` and
 * `wp newspack-intelligence ontology reresolve`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Ontology_Seed;
use Newspack_Intelligence\Ontology_Writer;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Config;

\defined( 'ABSPATH' ) || exit;

class Ontology_CLI_Command {
	use Refuses_Root;

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

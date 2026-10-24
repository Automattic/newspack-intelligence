<?php
/**
 * Clients_CLI_Command: `wp newspack-intelligence clients import <csv>`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\CSV_Parser;
use Newspack_Intelligence\Graph_Client_Roster;
use Newspack_Intelligence\Intel_Event;

\defined( 'ABSPATH' ) || exit;

class Clients_CLI_Command {
	use Refuses_Root;

	/**
	 * @param Client_Importer|null $importer The importer; null builds one per
	 *                                       import, over the graph roster.
	 */
	public function __construct( private ?Client_Importer $importer = null ) {}

	/**
	 * Import a Newspack-clients CSV as the client roster: each new client,
	 * churn, return and new domain becomes an ontology event, recorded as
	 * the client-import machine. A projection that will not open fails the
	 * command rather than re-creating every client.
	 *
	 * ## OPTIONS
	 *
	 * <csv>
	 * : Path to the CSV produced by fetch-newspack-clients.sh.
	 *
	 * ## EXAMPLES
	 *
	 *     wp newspack-intelligence clients import newspack_clients.csv
	 *
	 * @api WP-CLI dispatches this by reflection; nothing here calls it.
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection cannot be read.
	 */
	public function import( array $args, array $assoc_args ): void {
		if ( self::refused_root( 'clients import' ) ) {
			return;
		}
		$path = $args[0] ?? '';
		$rows = CSV_Parser::parse_file( $path );
		if ( null === $rows ) {
			if ( \class_exists( '\WP_CLI' ) ) {
				\WP_CLI::error( "Cannot read or parse clients CSV (missing header or no valid rows): {$path}" );
			}
			return;
		}
		$importer = $this->importer ?? new Client_Importer( new Graph_Client_Roster( Intel_Event::machine( 'client-import' ) ) );
		$result   = $importer->import( $rows, \gmdate( 'Y-m-d' ) );

		if ( \class_exists( '\WP_CLI' ) ) {
			\WP_CLI::success(
				\sprintf(
					'Imported %d rows: %d created, %d updated, %d reactivated, %d churned.',
					$result['total_in_csv'],
					$result['created'],
					$result['updated'],
					$result['reactivated'],
					$result['churned']
				)
			);
		}
	}
}

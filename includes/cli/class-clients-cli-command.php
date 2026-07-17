<?php
/**
 * Clients_CLI_Command: `wp newspack-intelligence clients import <csv>`.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\CLI;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\CPT_Publisher_Repository;
use Newspack_Intelligence\CSV_Parser;

\defined( 'ABSPATH' ) || exit;

class Clients_CLI_Command {

	private Client_Importer $importer;

	public function __construct( ?Client_Importer $importer = null ) {
		$this->importer = $importer ?? new Client_Importer( new CPT_Publisher_Repository() );
	}

	/**
	 * Import a Newspack-clients CSV into the publisher master store.
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
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = $args[0] ?? '';
		$rows = CSV_Parser::parse_file( $path );
		if ( null === $rows ) {
			if ( \class_exists( '\WP_CLI' ) ) {
				\WP_CLI::error( "Cannot read or parse clients CSV (missing header or no valid rows): {$path}" );
			}
			return;
		}
		$result = $this->importer->import( $rows, \gmdate( 'Y-m-d' ) );

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

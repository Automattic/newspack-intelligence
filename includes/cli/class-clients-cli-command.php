<?php
/**
 * Clients_CLI_Command: `wp newspack-ai-newsletter clients import <csv>`.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter\CLI;

use Newspack_AI_Newsletter\Client_Importer;
use Newspack_AI_Newsletter\CPT_Publisher_Repository;
use Newspack_AI_Newsletter\CSV_Parser;

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
	 *     wp newspack-ai-newsletter clients import newspack_clients.csv
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function import( array $args, array $assoc_args ): void {
		$path = $args[0] ?? '';
		if ( '' === $path || ! \is_readable( $path ) ) {
			if ( \class_exists( '\WP_CLI' ) ) {
				\WP_CLI::error( "Cannot read CSV file: {$path}" );
			}
			return;
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local CSV path supplied to the WP-CLI command, not a remote fetch.
		$rows   = CSV_Parser::parse( (string) \file_get_contents( $path ) );
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

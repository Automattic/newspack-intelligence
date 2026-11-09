<?php
/**
 * CSV_Parser: parse the client CSV into normalized rows.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class CSV_Parser {

	/** The UTF-8 byte order mark a spreadsheet re-saving the file leads it with. */
	private const BOM = "\xEF\xBB\xBF";

	/**
	 * Read + parse a clients CSV file at $path.
	 *
	 * @param string $path Path to the CSV file.
	 * @return array<int,array{site_id:string,domain_name:string,created:string}>|null Parsed rows, or null if the file cannot be read or is not a valid clients snapshot.
	 */
	public static function parse_file( string $path ): ?array {
		if ( '' === $path || ! \is_readable( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local CSV path (WP-CLI arg or Settings-page upload), not a remote fetch.
		return self::parse( (string) \file_get_contents( $path ) );
	}

	/**
	 * Parse a clients CSV (columns: Site ID, Created, Domain name).
	 *
	 * A leading UTF-8 byte order mark is read past. Returns null for input
	 * that is NOT a valid clients snapshot — a missing
	 * "Site ID" header (not this export) or zero valid data rows. Both would
	 * otherwise reconcile as an EMPTY snapshot and churn every existing client,
	 * so an unusable file must be rejected, never returned as [].
	 *
	 * @param string $csv Raw CSV text.
	 * @return array<int,array{site_id:string,domain_name:string,created:string}>|null Parsed rows, or null if the CSV is not a valid clients snapshot.
	 */
	public static function parse( string $csv ): ?array {
		$lines = \preg_split( '/\r\n|\r|\n/', \trim( \str_starts_with( $csv, self::BOM ) ? \substr( $csv, \strlen( self::BOM ) ) : $csv ) );
		$out   = [];
		$first = true;
		foreach ( (array) $lines as $line ) {
			if ( '' === \trim( (string) $line ) ) {
				continue;
			}
			$cols = \str_getcsv( (string) $line, ',', '"', '\\' );
			if ( $first ) {
				$first = false;
				if ( ! isset( $cols[0] ) || 0 !== \strcasecmp( \trim( $cols[0] ), 'Site ID' ) ) {
					return null; // First non-blank line isn't the header ⇒ not a clients export.
				}
				continue; // Header row consumed.
			}
			if ( \count( $cols ) < 3 ) {
				continue;
			}
			$out[] = [
				'site_id' => \trim( (string) $cols[0] ),
				'domain_name'    => \trim( (string) $cols[2] ),
				'created'        => \trim( (string) $cols[1] ),
			];
		}
		return [] === $out ? null : $out;
	}
}

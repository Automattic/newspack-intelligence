<?php
/**
 * CSV_Parser: parse the Step-1 Newspack-clients CSV into normalized rows.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

final class CSV_Parser {

	/**
	 * Parse a clients CSV (columns: Atomic site ID, Created, Domain name).
	 *
	 * @param string $csv Raw CSV text.
	 * @return array<int,array{atomic_site_id:string,domain_name:string,created:string}>
	 */
	public static function parse( string $csv ): array {
		$lines = \preg_split( '/\r\n|\r|\n/', \trim( $csv ) );
		$out   = [];
		$first = true;
		foreach ( (array) $lines as $line ) {
			if ( '' === \trim( (string) $line ) ) {
				continue;
			}
			$cols = \str_getcsv( (string) $line, ',', '"', '\\' );
			if ( $first ) {
				$first = false;
				if ( isset( $cols[0] ) && false !== \stripos( $cols[0], 'atomic' ) ) {
					continue; // Header row.
				}
			}
			if ( \count( $cols ) < 3 ) {
				continue;
			}
			$out[] = [
				'atomic_site_id' => \trim( (string) $cols[0] ),
				'domain_name'    => \trim( (string) $cols[2] ),
				'created'        => \trim( (string) $cols[1] ),
			];
		}
		return $out;
	}
}

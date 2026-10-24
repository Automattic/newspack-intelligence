<?php
/**
 * Url_Canon: the spelling two copies of one page share, so a repeat is found
 * by its URL whatever tracking a source added.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Url_Canon {

	/** Query keys that track a click and never name the page. */
	private const TRACKING = '/^(utm_.*|fbclid|gclid|mc_cid|mc_eid)$/D';

	/**
	 * The URL without its scheme, `www.`, fragment, tracking keys or trailing
	 * slash, its host lowercased and its query keys sorted; a URL naming no
	 * host is returned as given.
	 *
	 * @param string $url A URL.
	 */
	public static function of( string $url ): string {
		$parts = \wp_parse_url( $url );
		if ( ! \is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return $url;
		}
		$host  = self::host( $parts['host'] );
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$query = [];
		foreach ( \explode( '&', $parts['query'] ?? '' ) as $pair ) {
			if ( '' !== $pair && 1 !== \preg_match( self::TRACKING, \explode( '=', $pair, 2 )[0] ) ) {
				$query[] = $pair;
			}
		}
		\sort( $query );
		return $host . $port . \rtrim( $parts['path'] ?? '', '/' ) . ( [] === $query ? '' : '?' . \implode( '&', $query ) );
	}

	/**
	 * A host as every reader compares it: lowercase, its leading `www.`
	 * dropped.
	 *
	 * @param string $host A host.
	 */
	public static function host( string $host ): string {
		return (string) \preg_replace( '/^www\./', '', \strtolower( $host ) );
	}
}

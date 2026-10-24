<?php
/**
 * Entity_Index: the entity names as resolution matches them. `norm()` is the
 * one spelling a name is compared in, which the projection stores beside
 * every name and title.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Entity_Index {

	/**
	 * A name as matching compares it: lowercase, punctuation to one space,
	 * whitespace collapsed, a leading `the` dropped.
	 *
	 * @param string $s Any text.
	 */
	public static function norm( string $s ): string {
		$s = (string) \preg_replace( '/[^\p{L}\p{M}\p{N}\s]+/u', ' ', \mb_strtolower( $s ) );
		$s = \trim( (string) \preg_replace( '/\s+/u', ' ', $s ) );
		return (string) \preg_replace( '/^the /u', '', $s );
	}
}

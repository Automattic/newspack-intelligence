<?php
/**
 * Intel_Tiers: the item tiers a read caller may see. Every caller past a
 * read verb's `read` gate reads `public` and `internal`; nothing grants
 * `restricted` yet, so no read answers a row resting on it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Intel_Tiers {

	/** The tiers every read caller holds. */
	private const READABLE = [ 'public', 'internal' ];

	/**
	 * The tiers a caller who passed a verb's `read` gate may read.
	 *
	 * @return list<string>
	 */
	public static function readable(): array {
		return self::READABLE;
	}
}

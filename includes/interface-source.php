<?php
/**
 * Source: a connector that fetches normalized digest items.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

interface Source {
	/**
	 * Fetch and normalize items from the underlying connector.
	 *
	 * @param array<string,mixed> $config Connector configuration (tokens, feeds, filters).
	 * @return array<int,array<string,mixed>> Normalized items.
	 */
	public function fetch( array $config ): array;
}

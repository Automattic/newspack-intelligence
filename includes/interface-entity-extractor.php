<?php
/**
 * Entity_Extractor: extracts an item's subject entities (the resolver's NER seam).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

interface Entity_Extractor {
	/**
	 * Extract the organizations / people / locations the item is about.
	 *
	 * @param array<string,mixed> $item The item's fields (title, body, ...).
	 * @return array{orgs:array<int,string>,people:array<int,string>,locations:array<int,string>}
	 */
	public function extract( array $item ): array;
}

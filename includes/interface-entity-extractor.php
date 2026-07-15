<?php
/**
 * Entity_Extractor: extracts an item's subject entities (the Gate's NER seam).
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

interface Entity_Extractor {
	/**
	 * Extract the organizations / people / locations the item is about.
	 *
	 * @param array<string,mixed> $item Normalized item {source,id,title,url,body,...}.
	 * @return array{orgs:array<int,string>,people:array<int,string>,locations:array<int,string>}
	 */
	public function extract( array $item ): array;
}

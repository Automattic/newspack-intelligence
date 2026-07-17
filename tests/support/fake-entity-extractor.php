<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Extractor;

/** Canned, call-counting Entity_Extractor for matcher tests. */
final class Fake_Entity_Extractor implements Entity_Extractor {
	public int $calls = 0;

	/** @param array<int,string> $orgs */
	public function __construct( private array $orgs = [] ) {}

	public function extract( array $item ): array {
		++$this->calls;
		return [ 'orgs' => $this->orgs, 'people' => [], 'locations' => [] ];
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Extractor;

/** Canned, call-counting Entity_Extractor for matcher tests; null orgs is a failed call. */
final class Fake_Entity_Extractor implements Entity_Extractor {
	public int $calls = 0;

	/**
	 * @param array<int,string>|null $orgs     The organizations it names, or null for a failed call.
	 * @param array<int,string>      $products The products it names.
	 */
	public function __construct( private ?array $orgs = [], private array $products = [] ) {}

	public function extract( array $item ): ?array {
		++$this->calls;
		return null === $this->orgs ? null : [ 'orgs' => $this->orgs, 'products' => $this->products, 'people' => [], 'locations' => [] ];
	}
}

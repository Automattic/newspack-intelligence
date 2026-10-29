<?php
/**
 * LLM_Entity_Extractor: Entity_Extractor backed by an LLM_Client + Prompts::extract_entities.
 *
 * Keeps the model's job narrow (extract names) and the parse lenient: a
 * reply is an answer when its `orgs` is a list, and a missing `people` or
 * `locations` or a non-string entry reads as nothing. A failed call, or a
 * reply whose `orgs` is not a list — none at all, an error object, a scalar —
 * answers none, logged rate-limited, so the resolver tells a failure from
 * an answer naming nobody; it never throws but for a cooperative stop. Matching the names against the graph is deterministic
 * PHP in `Resolver`, not the model's job.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

final class LLM_Entity_Extractor implements Entity_Extractor {

	public function __construct( private LLM_Client $client ) {}

	public function extract( array $item ): ?array {
		try {
			$raw = $this->client->chat(
				Prompts::extract_entities( $item ),
				[ 'max_tokens' => 300, 'temperature' => 0.0 ]
			);
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \RuntimeException $e ) {
			Core::print_less_often( 'NER call failed: ', $e->getMessage() );
			return null;
		}
		return self::parse( $raw );
	}

	/**
	 * Lenient parse: pull the first {...} object, keep only string list
	 * entries; none, logged, unless its `orgs` is a list.
	 *
	 * @return array{orgs:array<int,string>,people:array<int,string>,locations:array<int,string>}|null
	 */
	private static function parse( string $raw ): ?array {
		$d    = Prompts::json_object( $raw );
		$orgs = $d['orgs'] ?? null;
		if ( ! \is_array( $orgs ) || ! \array_is_list( $orgs ) ) {
			Core::print_less_often( 'NER reply named no organization list' );
			return null;
		}
		return [
			'orgs'      => self::string_list( $orgs ),
			'people'    => self::string_list( $d['people'] ?? null ),
			'locations' => self::string_list( $d['locations'] ?? null ),
		];
	}

	/**
	 * @param mixed $value
	 * @return array<int,string>
	 */
	private static function string_list( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $entry ) {
			if ( \is_string( $entry ) && '' !== \trim( $entry ) ) {
				$out[] = \trim( $entry );
			}
		}
		return $out;
	}
}

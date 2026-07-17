<?php
/**
 * LLM_Entity_Extractor: Entity_Extractor backed by an LLM_Client + Prompts::extract_entities.
 *
 * Keeps the model's job narrow (extract names) and the parse lenient: any shortfall — bad JSON,
 * missing keys, HTTP error — degrades to an empty triple so the Gate falls back to `hold`, never
 * throws. The cross-referencing against the store is deterministic PHP in Publisher_Matcher, not
 * the model's job.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

final class LLM_Entity_Extractor implements Entity_Extractor {

	public function __construct( private LLM_Client $client ) {}

	public function extract( array $item ): array {
		try {
			$raw = $this->client->chat(
				Prompts::extract_entities( $item ),
				[ 'max_tokens' => 300, 'temperature' => 0.0 ]
			);
		} catch ( \RuntimeException $e ) {
			return self::empty_triple();
		}
		return self::parse( $raw );
	}

	/**
	 * Lenient parse: pull the first {...} object, keep only string list entries.
	 *
	 * @return array{orgs:array<int,string>,people:array<int,string>,locations:array<int,string>}
	 */
	private static function parse( string $raw ): array {
		$json = ( 1 === \preg_match( '/\{.*\}/s', $raw, $m ) ) ? $m[0] : $raw;
		$d    = \json_decode( $json, true );
		if ( ! \is_array( $d ) ) {
			return self::empty_triple();
		}
		return [
			'orgs'      => self::string_list( $d['orgs'] ?? null ),
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

	/** @return array{orgs:array<int,string>,people:array<int,string>,locations:array<int,string>} */
	private static function empty_triple(): array {
		return [ 'orgs' => [], 'people' => [], 'locations' => [] ];
	}
}

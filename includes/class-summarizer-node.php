<?php
/**
 * Summarizer_Node: turns one item into one summary. Knows nothing about sources.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;

\defined( 'ABSPATH' ) || exit;

class Summarizer_Node extends Node {

	/**
	 * LLM-client factory seam. Lazily-defaulted at the call site to
	 * `Settings::llm_client()` (null when no proxy token is configured). Tests
	 * reassign in setUp to inject a real `Proxy_LLM_Client` — faking only its
	 * `$http_post` seam — so prompt assembly, the client, and the JSON parse all
	 * run as real, covered production code; tearDown resets it to null.
	 *
	 * Signature: `function (): ?LLM_Client`.
	 *
	 * @var (\Closure(): ?LLM_Client)|null
	 */
	public static ?\Closure $llm_factory = null;

	/**
	 * The ONE seam a real summarizer replaces: item -> one-line summary. Heuristic = deterministic template.
	 *
	 * @param array<string,mixed> $item
	 */
	protected function summarize( array $item ): string {
		$title = \is_string( $item['title'] ?? null ) ? $item['title'] : '(untitled)';
		$body  = \is_string( $item['body'] ?? null ) ? $item['body'] : '';
		return $title . ' — ' . \mb_substr( $body, 0, 80 );
	}

	/**
	 * An item's id as a string ('' when absent/non-scalar) — for set_state payloads.
	 *
	 * @param array<string,mixed> $item
	 */
	private static function item_id( array $item ): string {
		$id = $item['id'] ?? null;
		return \is_scalar( $id ) ? (string) $id : '';
	}

	public function fill( array &$message ): void {
		/** @var int $type */
		$type = $message[ Message::TYPE ];
		// Forward control signals (e.g. a source's DONE) unchanged toward the digest.
		if ( $type & Message::TM_INFO ) {
			parent::fill( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$item = $message[ Message::VALUE ];
		if ( ! \is_array( $item ) ) {
			return;
		}
		/** @var array<string,mixed> $item */
		$client   = ( self::$llm_factory ?? static fn (): ?LLM_Client => Settings::llm_client() )();
		$enriched = null;
		if ( $client instanceof LLM_Client ) {
			try {
				$raw      = $client->chat(
					Prompts::enrich( $item, Settings::get_string( 'relevance_profile' ) ),
					[ 'max_tokens' => 200, 'temperature' => 0.3 ]
				);
				$enriched = self::parse_enrich( $raw );
			} catch ( \RuntimeException $e ) {
				// Rate-limited; an LLM failure NEVER throws out of fill().
				$this->print_less_often( 'AI enrich failed: ' . $e->getMessage() );
				// Observability: a traced node streams this to the REPL (see Node::set_state).
				$this->set_state( 'ENRICH_FAILED', [ 'id' => self::item_id( $item ), 'error' => $e->getMessage() ] );
			}
		}
		if ( null !== $enriched ) {
			$item['summary']         = $enriched['summary'];
			$item['relevance_score'] = $enriched['relevance_score'];
			$item['reason']          = $enriched['reason'];
		} else {
			$item['summary'] = $this->summarize( $item );
		}

		// Publish what we just did so a traced summarizer isn't a black box — id,
		// the summary, the score, and whether the LLM or the heuristic produced it.
		$this->set_state(
			'SUMMARIZED',
			[
				'id'              => self::item_id( $item ),
				'title'           => \is_string( $item['title'] ?? null ) ? $item['title'] : '',
				'summary'         => $item['summary'],
				'relevance_score' => $item['relevance_score'] ?? null,
				'via'             => null !== $enriched ? 'llm' : 'heuristic',
			]
		);

		$out                   = Message::new_message();
		$out[ Message::TYPE ]  = Message::TM_STRUCT;
		$out[ Message::FROM ]  = $this->name;
		$out[ Message::VALUE ] = $item;
		// parent::fill (base, not $this — would recurse) stamps TO from target, increments the counter, and forwards to sink.
		parent::fill( $out );
	}

	/**
	 * Lenient parse of the enrich reply: extract the first {...} object, require a
	 * string `summary`, clamp `relevance_score` to a 0-10 int. Returns null on any
	 * shortfall so the caller falls back to the heuristic.
	 *
	 * @return array{summary:string,relevance_score:int,reason:string}|null
	 */
	private static function parse_enrich( string $raw ): ?array {
		$json = ( 1 === \preg_match( '/\{.*\}/s', $raw, $m ) ) ? $m[0] : $raw;
		$d    = \json_decode( $json, true );
		if ( ! \is_array( $d ) || ! isset( $d['summary'] ) || ! \is_string( $d['summary'] ) ) {
			return null;
		}
		$score  = $d['relevance_score'] ?? 0;
		$reason = $d['reason'] ?? '';
		return [
			'summary'         => $d['summary'],
			'relevance_score' => \max( 0, \min( 10, \is_numeric( $score ) ? (int) $score : 0 ) ),
			'reason'          => \is_scalar( $reason ) ? (string) $reason : '',
		];
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Summarizes one item; emits the item plus a summary. Source-agnostic.',
			'arguments'    => [],
			'commands'     => [],
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

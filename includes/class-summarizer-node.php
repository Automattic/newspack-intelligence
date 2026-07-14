<?php
/**
 * Summarizer_Node: enriches one item via the LLM (summary + relevance_score + reason),
 * falling back to a deterministic summary template when no LLM is configured. Source-agnostic.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

class Summarizer_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;

	/**
	 * LLM-client factory seam. Lazily-defaulted at the call site to this node's
	 * own verb-configured `make_llm_client()` (null when no vault token resolves).
	 * Tests reassign in setUp to inject a real `Proxy_LLM_Client` — faking only its
	 * `$http_post` seam — so prompt assembly, the client, and the JSON parse all
	 * run as real, covered production code; tearDown resets it to null.
	 *
	 * Signature: `function (): ?LLM_Client`.
	 *
	 * @var (\Closure(): ?LLM_Client)|null
	 */
	public static ?\Closure $llm_factory = null;

	/** Tachikoma-parity: no-arg ctor. Wires the sibling :config interpreter from node_schema()['commands']. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	public function fill( array $message ): void {
		/** @var int $type */
		$type = $message[ Message::TYPE ];
		// Forward control signals (a source's DONE) unchanged to the digest.
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
		if ( ! \is_string( $item['title'] ?? null ) ) {
			$item['title'] = '(untitled)';
		}
		/** @var array<string,mixed> $item */
		$client   = self::$llm_factory ? ( self::$llm_factory )() : $this->make_llm_client();
		$enriched = null;
		if ( $client instanceof LLM_Client ) {
			try {
				$raw = $client->chat(
					Prompts::enrich( $item, $this->relevance_profile() ),
					[ 'max_tokens' => 500, 'temperature' => 0.3 ]
				);
				$enriched = self::parse_enrich( $raw );
			} catch ( \RuntimeException $e ) {
				$this->stderr( 'ERROR: ' . $e->getMessage() );
			}
		}
		if ( null !== $enriched ) {
			$item['summary']         = $enriched['summary'];
			$item['relevance_score'] = $enriched['relevance_score'];
			$item['reason']          = $enriched['reason'];
			$this->set_state( 'SUMMARIZED', Core::as_string( $item['title'] ) );
		} else {
			$item['summary'] = $this->summarize( $item );
			$this->set_state( 'FAILED', Core::as_string( $item['title'] ) );
		}

		// Body fed the summary; drop it to shrink the scored log + snapshot.
		unset( $item['body'] );

		$out                   = Message::new_message();
		$out[ Message::TYPE ]  = Message::TM_STRUCT;
		$out[ Message::FROM ]  = $this->name;
		$out[ Message::VALUE ] = $item;
		// parent::fill (base, not $this — would recurse) forwards to sink.
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
			'relevance_score' => \max( 0, \min( 10, Core::num_int( $score ) ) ),
			'reason'          => Core::as_string( $reason ),
		];
	}

	/**
	 * No-LLM fallback summary: a deterministic title + body-excerpt template, reached only
	 * when no LLM client is configured (or its reply fails to parse). The live path is the
	 * LLM enrich call in fill().
	 *
	 * @param array<string,mixed> $item
	 */
	private function summarize( array $item ): string {
		$title = \is_string( $item['title'] ?? null ) ? $item['title'] : '(untitled)';
		$body  = \is_string( $item['body'] ?? null ) ? $item['body'] : '';
		return $title . ' — ' . \mb_substr( $body, 0, 80 );
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Summarizes one item; emits the item plus a summary. Source-agnostic.',
			'arguments'    => [],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

<?php
/**
 * Stub_Source_Node: one canned source. Emits a fixed batch of normalized items on a
 * TICK request until the real connectors land. Stands in for the live
 * GitHub/Linear/feed sources so the spine runs end-to-end immediately.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Stub_Source_Node extends Node {

	/**
	 * The ONE seam a real source replaces: return normalized ingest items. Stub = canned.
	 * Shape per the item contract: {source,id,title,url,body,timestamp}.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function items(): array {
		// Substrate clock when pumped; epoch seconds otherwise (request scope, tests).
		$now = Core::$now > 0.0 ? (int) Core::$now : \time();
		return [
			[ 'source' => 'stub', 'id' => 'stub:1', 'title' => 'Roundup Block ships', 'url' => 'https://example.test/s1', 'body' => 'AI summarizes selected posts into a draft.', 'timestamp' => $now ],
			[ 'source' => 'stub', 'id' => 'stub:2', 'title' => 'Editorial Assistant GA', 'url' => 'https://example.test/s2', 'body' => 'Inline AI assistance in the editor.', 'timestamp' => $now ],
			[ 'source' => 'stub', 'id' => 'stub:3', 'title' => 'Reader forum hits 10k members', 'url' => 'https://example.test/s3', 'body' => 'The publisher community forum crossed ten thousand members this week.', 'timestamp' => $now ],
		];
	}

	/**
	 * TICK is a runtime trigger: a TM_REQUEST handled here in fill() (NOT a TM_COMMAND
	 * verb — that flag is for startup/admin). Any other type is ignored; a source
	 * mints, it doesn't consume.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	public function fill( array &$message ): void {
		$type = \is_numeric( $message[ Message::TYPE ] ) ? (int) $message[ Message::TYPE ] : 0;
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
		}
	}

	/**
	 * TICK handler: emit each item as a TM_STRUCT message, fire-and-forget.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_request( array $message ): void {
		foreach ( $this->items() as $item ) {
			$response                   = Message::new_message();
			$response[ Message::TYPE ]  = Message::TM_STRUCT;
			$response[ Message::FROM ]  = $this->name;
			$response[ Message::VALUE ] = $item;
			// parent::fill stamps TO from a connect_node-set target, then forwards to sink.
			parent::fill( $response );
		}
	}

	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'     => 'Source',
			'description'  => 'Emits a canned batch of normalized items on a TICK request (stand-in for live sources).',
			'arguments'    => [],
			'requests'     => [
				[
					'name'        => 'TICK',
					'description' => 'Emit the current batch of items. Trigger with `request_node source TICK`.',
				],
			],
			'accepts_fill' => true,
			'has_target'   => true,
		] );
	}
}

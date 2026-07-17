<?php
/**
 * Source_Node: abstract base for every connector node (GitHub, Linear, Feed).
 *
 * Holds the uniform connector behavior so each concrete source only supplies the
 * two seams that differ: `fetch( $config )` (the blocking-HTTP call, the Source
 * interface) and `config()` (the per-connector Settings read). On a TICK request
 * (TM_REQUEST — the runtime trigger, NOT a TM_COMMAND verb) the base fetches,
 * dedups by item `id` against the ids it has already emitted, and emits each NEW
 * item as a fire-and-forget TM_STRUCT. The emitted-id set is bounded and
 * round-trips through save_state/restore_state so a respawned worker doesn't
 * re-emit what the previous incarnation already sent.
 *
 * Abstract — never make_node'd directly (no node_schema here); each concrete
 * connector declares its own Source category + TICK request.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

abstract class Source_Node extends Node implements Source {
	use Schema_Reflection;

	/** Cap on the remembered emitted-id set — bounds memory on a long-lived worker. */
	private const MAX_SEEN = 2000;

	/** @var array<string,bool> Emitted item ids (insertion-ordered), for cross-tick dedup. */
	protected array $seen = [];

	/** Tachikoma-parity: no-arg ctor. Wires the sibling `:config` interpreter from node_schema()['commands']. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * TICK is a runtime trigger: a TM_REQUEST handled here in fill() (NOT a
	 * TM_COMMAND verb). Any other type is ignored; a source mints, it doesn't
	 * consume.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	public function fill( array $message ): void {
		$type = Core::num_int( $message[ Message::TYPE ] );
		if ( $type & Message::TM_REQUEST ) {
			$this->handle_request( $message );
		}
	}

	/**
	 * TICK handler: fetch, drop ids already emitted, emit each new item as a
	 * TM_STRUCT, then emit one TM_INFO DONE so the digest can count collection
	 * progress. Fire-and-forget. An item with no string `id` is skipped (no id =
	 * can't dedup, and the contract requires one). fetch() is synchronous, so DONE
	 * is correctly ordered after every item from this tick. DONE's FROM
	 * (breadcrumbed downstream) is the digest's distinct-source key; VALUE the marker.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	private function handle_request( array $message ): void {
		try {
			foreach ( $this->fetch( $this->config() ) as $item ) {
				$id = Core::str( $item['id'] ?? null );
				if ( '' === $id || isset( $this->seen[ $id ] ) ) {
					continue;
				}
				$this->remember( $id );
				$response                   = Message::new_message();
				$response[ Message::TYPE ]  = Message::TM_STRUCT;
				$response[ Message::FROM ]  = $this->name;
				$response[ Message::VALUE ] = $item;
				// parent::fill stamps TO from target, then forwards to sink.
				parent::fill( $response );
			}
		} finally {
			// DONE always fires even on throw, so progress can't stall.
			$done                   = Message::new_message();
			$done[ Message::TYPE ]  = Message::TM_INFO;
			$done[ Message::FROM ]  = $this->name;
			$done[ Message::VALUE ] = "DONE\n";
			parent::fill( $done );
		}
	}

	/**
	 * Per-connector configuration (Settings reads) passed to fetch(). The base
	 * keeps fetch() pure-ish — config resolution lives here so tests can drive
	 * fetch() directly with a canned config + the HTTP seam.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function config(): array;

	/** Record an emitted id, evicting the oldest once the set exceeds MAX_SEEN. */
	private function remember( string $id ): void {
		$this->seen[ $id ] = true;
		if ( \count( $this->seen ) > self::MAX_SEEN ) {
			$this->seen = \array_slice( $this->seen, -self::MAX_SEEN, null, true );
		}
	}

	/**
	 * Normalize one connector record into the digest item contract — every field
	 * coerced + guarded once here, so each connector only maps its raw fields to the
	 * args. The final id is namespaced `"$source:$id"`; the bare `$id` must already
	 * be stable per item (that's what dedup keys on).
	 *
	 * @param mixed $title
	 * @param mixed $url
	 * @param mixed $body
	 * @param mixed $when Date string (ISO-8601 / RFC-822); '' / unparseable → timestamp 0.
	 * @return array<string,mixed>
	 */
	protected function normalize_item( string $source, string $id, mixed $title, mixed $url, mixed $body, mixed $when ): array {
		$ts = \is_string( $when ) ? \strtotime( $when ) : false;
		return [
			'source'    => $source,
			'id'        => "$source:$id",
			'title'     => Core::str( $title ),
			'url'       => Core::str( $url ),
			'body'      => Core::str( $body ),
			'timestamp' => false !== $ts ? $ts : 0,
		];
	}

	/**
	 * Build a connector's node_schema from the shared Source shape — category Source
	 * plus one fire-and-forget TICK request — so the connectors don't each restate
	 * it. arguments / accepts_fill / has_target inherit Node's defaults ([]/true/true).
	 *
	 * @return array<string,mixed>
	 */
	protected static function source_schema( string $description, string $tick_description ): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Source',
			'description' => $description,
			'requests'    => [
				[
					'name'        => 'TICK',
					'description' => $tick_description,
				],
			],
			'accepts_fill' => false,
		] );
	}
}

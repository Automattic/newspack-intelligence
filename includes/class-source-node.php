<?php
/**
 * Source_Node: abstract base for every connector node (GitHub, Linear, Feed).
 *
 * Holds the uniform connector behavior so each concrete source only supplies the
 * two seams that differ: `fetch( $config )` (the blocking-HTTP call, the Source
 * interface) and `config()` (the per-connector Settings read). On a TICK request
 * (TM_REQUEST — the runtime trigger, NOT a TM_COMMAND verb) the base fetches,
 * dedups by `item_key` against the keys it has already recorded, emits one
 * `item_recorded` event per NEW item as a fire-and-forget TM_STRUCT, and answers
 * the request with the count it emitted and the count make() refused. A source records at the tier its
 * `make_node` line names, which `set_tier` replaces at runtime. The
 * recorded-key set is bounded (MAX_SEEN, oldest evicted first) and in-memory
 * only — it does not survive a respawn, so a fresh worker re-records whatever
 * its next fetch still returns.
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

	/** Bytes of body an item records; a longer body is cut on a character boundary. */
	public const MAX_BODY_BYTES = 65536;

	/** Cap on the remembered recorded-key set — bounds memory on a long-lived worker. */
	private const MAX_SEEN = 2000;

	/** @var array<string,bool> Recorded item keys (insertion-ordered), for cross-tick dedup. */
	protected array $seen = [];

	/** The access tier every item this source records carries. */
	protected string $tier = '';

	/** Tachikoma-parity: no-arg ctor. Wires the sibling `:config` interpreter from node_schema()['commands']. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * `<tier>`: the access tier, one of Intel_Event::TIERS, checked before any
	 * token is assigned.
	 *
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 * @throws \InvalidArgumentException With no tier, or one outside the set.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return parent::arguments();
		}
		$values = $this->schema_values( $args );
		$tier   = Core::as_string( $values['tier'] ?? '' );
		if ( ! \in_array( $tier, Intel_Event::TIERS, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "{$this->name}: tier {$tier} is not one of " . \implode( ', ', Intel_Event::TIERS ) ) );
		}
		$this->assign_schema_args( $args, $values );
		return parent::arguments( $args );
	}

	/**
	 * TICK is a runtime trigger: a TM_REQUEST answered from the declared
	 * `requests` handler (NOT a TM_COMMAND verb). Any other type is ignored; a
	 * source mints, it doesn't consume.
	 *
	 * @param array<int,mixed> $message Incoming request Message.
	 */
	public function fill( array $message ): void {
		$this->answer_request( $message );
	}

	/**
	 * TICK handler: fetch, drop item keys already recorded, and emit one
	 * `item_recorded` event per new item as a TM_STRUCT to the target. An item
	 * make() refuses is logged, counted and skipped unremembered, so it retries.
	 *
	 * @return array{emitted:int,refused:int} The reply data: how many new items went out, and how many make() refused.
	 */
	private function tick(): array {
		$emitted = 0;
		$refused = 0;
		$actor   = Intel_Event::machine( "source:{$this->name}", \NEWSPACK_INTELLIGENCE_VERSION );
		foreach ( $this->fetch( $this->config() ) as $item ) {
			$key = Core::as_string( $item['item_key'] );
			if ( isset( $this->seen[ $key ] ) ) {
				continue;
			}
			try {
				$value = Intel_Event::make( 'item_recorded', $item, $actor, \NEWSPACK_INTELLIGENCE_VERSION );
			} catch ( \InvalidArgumentException $invalid ) {
				$this->print_less_often( 'refused an item: ', "{$key}: {$invalid->getMessage()}" );
				++$refused;
				continue;
			}
			$event                   = Message::new_message();
			$event[ Message::TYPE ]  = Message::TM_STRUCT;
			$event[ Message::FROM ]  = $this->name;
			$event[ Message::VALUE ] = $value;
			// parent::fill stamps TO from target, then forwards to sink.
			parent::fill( $event );
			// Only a recorded key is remembered, so a refused item retries.
			$this->remember( $key );
			++$emitted;
		}
		return [
			'emitted' => $emitted,
			'refused' => $refused,
		];
	}

	/**
	 * Per-connector configuration (Settings reads) passed to fetch(). The base
	 * keeps fetch() pure-ish — config resolution lives here so tests can drive
	 * fetch() directly with a canned config + the HTTP seam.
	 *
	 * @return array<string,mixed>
	 */
	abstract protected function config(): array;

	/** Record a key, evicting the oldest once the set exceeds MAX_SEEN. */
	private function remember( string $key ): void {
		$this->seen[ $key ] = true;
		if ( \count( $this->seen ) > self::MAX_SEEN ) {
			$this->seen = \array_slice( $this->seen, -self::MAX_SEEN, null, true );
		}
	}

	/** Emit the base config plus each declared setter's line. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
	}

	/**
	 * Normalize one connector record into the `item_recorded` fields — every
	 * field coerced and guarded once here, so each connector only maps its raw
	 * fields to the args. The key is namespaced `"$source:$id"`; the bare `$id`
	 * must already be stable per item, because dedup keys on it.
	 *
	 * @param mixed $title
	 * @param mixed $url
	 * @param mixed $body  Cut to MAX_BODY_BYTES on a UTF-8 character boundary.
	 * @param mixed $when  Date string (ISO-8601 / RFC-822); '' / unparseable → item_ts 0.
	 * @return array<string,mixed>
	 */
	protected function normalize_item( string $source, string $id, mixed $title, mixed $url, mixed $body, mixed $when ): array {
		$ts = \is_string( $when ) ? \strtotime( $when ) : false;
		return [
			'item_key'     => "$source:$id",
			'source'       => $source,
			'tier'         => $this->tier,
			'url'          => Core::str( $url ),
			'title'        => Core::str( $title ),
			'body'         => \mb_strcut( Core::str( $body ), 0, self::MAX_BODY_BYTES, 'UTF-8' ),
			'item_ts'      => false !== $ts ? $ts : 0,
			'retrieved_ts' => Core::$now,
		];
	}

	/**
	 * Build a connector's node_schema from the shared Source shape — category
	 * Source, the `tier` positional, one TICK request and the `set_tier` verb
	 * ahead of the connector's own — so the connectors don't each restate it.
	 * has_target inherits Node's default (true).
	 *
	 * @param list<array<string,mixed>> $commands The connector's own verbs.
	 * @return array<string,mixed>
	 */
	protected static function source_schema( string $description, string $tick_description, array $commands = [] ): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Source',
			'description' => $description,
			'arguments'   => [
				[ 'name' => 'tier', 'type' => 'string', 'required' => true, 'description' => 'The access tier every recorded item carries: public, internal or restricted.' ],
			],
			'requests'    => [
				[
					'name'        => 'TICK',
					'description' => $tick_description,
					'reply_shape' => '{ emitted, refused }',
					'handler'     => static fn ( self $node ): array => $node->tick(),
				],
			],
			'commands'     => [
				[
					'name'        => 'set_tier',
					'description' => 'Replace the access tier the make_node line named: public, internal or restricted.',
					'args'        => [
						[ 'name' => 'tier', 'type' => 'string', 'required' => true ],
					],
					'setter'      => 'tier',
					// The make_node line carries the tier.
					'dump'        => false,
				],
				...$commands,
			],
			'accepts_fill' => false,
		] );
	}
}

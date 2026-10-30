<?php
/**
 * Source_Node: abstract base for every connector node (GitHub, Linear, Feed).
 *
 * Holds the uniform connector behavior so each concrete source supplies one
 * seam, `tick()`, which a TICK request and the grid both call: GitHub and
 * Linear fetch and hand the items to `record()`, while the feed starts a
 * pass whose bodies each reach `record()` as they land. A source fetches on a TICK
 * request (TM_REQUEST, the runtime trigger, NOT a TM_COMMAND verb) and, once
 * `set_interval` sets one, on the wall-clock grid, riding the Router's tick:
 * at each multiple of the interval since the epoch, never at a process's
 * first tick. Whatever a fetch finds goes through `record()`, the one record
 * path: it drops keys already recorded and keys the projection already
 * holds, emits one `item_recorded` event per new item as a fire-and-forget
 * TM_STRUCT, and counts what it emitted and what it refused. A source
 * records at the tier its `make_node` line names, which `set_tier` replaces
 * at runtime. The recorded-key set is bounded (MAX_SEEN, oldest evicted
 * first) and in-memory; a respawned worker reads the projection instead.
 *
 * Abstract — never make_node'd directly (no node_schema here); each concrete
 * connector declares its own Source category + TICK request.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

abstract class Source_Node extends Timer_Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** Bytes of body an item records; a longer body is cut on a character boundary. */
	public const MAX_BODY_BYTES = 65536;

	/** Bytes of a refused key its line shows. */
	private const KEY_SHOWN_BYTES = 64;

	/** Cap on the remembered recorded-key set — bounds memory on a long-lived worker. */
	private const MAX_SEEN = 2000;

	/** @var array<string,bool> Recorded item keys and keys refused for their size (insertion-ordered), for cross-tick dedup. */
	protected array $seen = [];

	/** The access tier every item this source records carries. */
	protected string $tier = '';

	/** Seconds between grid fetches, null for none: only TICK fetches. */
	protected ?int $interval = null;

	/** The tick clock, in whole seconds, at this process's last Router tick; null before the first. */
	private ?int $checked = null;

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
	 * On the Router's tick, fetch when the clock has crossed a multiple of
	 * the interval since this process's last tick; its first tick only
	 * marks the clock, so a respawned worker waits for the next boundary. A
	 * fetch that fails is logged and the next boundary tries again; a
	 * cooperative stop propagates (substrate ADR-14).
	 *
	 * @api Used by substrate.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	protected function fire(): void {
		$now           = (int) Core::$now;
		$last          = $this->checked;
		$this->checked = $now;
		if ( null === $last || null === $this->interval || \intdiv( $now, $this->interval ) <= \intdiv( $last, $this->interval ) ) {
			return;
		}
		try {
			$this->tick();
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'a scheduled fetch failed; trying again at the next boundary: ', $e->getMessage() );
		}
	}

	/**
	 * The one seam each source supplies, which a TICK request and the grid
	 * call: fetch, and record what arrives.
	 *
	 * @return array<string,int> The reply data.
	 */
	abstract protected function tick(): array;

	/**
	 * Record items: drop the keys already recorded, remember without
	 * recording those the projection already holds, and emit one
	 * `item_recorded` event per new item as a TM_STRUCT to the target. An
	 * item make() refuses is logged, counted and skipped unremembered, so it
	 * retries. One whose key runs past Graph_Curation::ITEM_KEY_BYTES as the
	 * stream writes it, so that a record naming it could outgrow one line, is
	 * logged naming the key cut to KEY_SHOWN_BYTES, counted and remembered,
	 * so it is refused once a process.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @return array{emitted:int,refused:int} How many new items went out, and how many were refused.
	 */
	protected function record( array $items ): array {
		$held    = $this->held( $items );
		$emitted = 0;
		$refused = 0;
		foreach ( $items as $item ) {
			$key = Core::as_string( $item['item_key'] );
			if ( isset( $this->seen[ $key ] ) ) {
				continue;
			}
			if ( isset( $held[ $key ] ) ) {
				$this->remember( $key );
				continue;
			}
			$bytes = Graph_Curation::written_bytes( $key );
			if ( $bytes > Graph_Curation::ITEM_KEY_BYTES ) {
				$this->print_less_often( 'refused an item whose key runs ', "{$bytes} bytes, past " . Graph_Curation::ITEM_KEY_BYTES . ': ' . Core::terminal_safe( \mb_strcut( $key, 0, self::KEY_SHOWN_BYTES ) ) );
				$this->remember( $key );
				++$refused;
				continue;
			}
			try {
				$value = Intel_Event::machine_event( "source:{$this->name}", 'item_recorded', $item );
			} catch ( \InvalidArgumentException $invalid ) {
				$this->print_less_often( 'refused an item: ', "{$key}: {$invalid->getMessage()}" );
				++$refused;
				continue;
			}
			$this->emit( '', $value );
			// Unlike a size refusal, a make() refusal is not kept: it retries.
			$this->remember( $key );
			++$emitted;
		}
		return [
			'emitted' => $emitted,
			'refused' => $refused,
		];
	}

	/**
	 * The keys of the items, not yet seen, that the projection's items hold
	 * in any partition, in one read; none when every key is seen or no
	 * projection opens, which is logged.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @return array<string,true>
	 */
	private function held( array $items ): array {
		$unseen = [];
		foreach ( $items as $item ) {
			$key = Core::as_string( $item['item_key'] );
			if ( ! isset( $this->seen[ $key ] ) ) {
				$unseen[ $key ] = true;
			}
		}
		if ( [] === $unseen ) {
			return [];
		}
		$keys = \array_map( 'strval', \array_keys( $unseen ) );
		$rows = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => $r->rows( 'SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' ) . ' i WHERE item_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' )', $keys ),
			"{$this->name}: no intel-graph to read recorded items from; recording every item fetched: "
		) ?? [];
		$held = [];
		foreach ( $rows as [ $key ] ) {
			$held[ Core::as_string( $key ) ] = true;
		}
		return $held;
	}

	/** Record a key, evicting the oldest once the set exceeds MAX_SEEN. */
	private function remember( string $key ): void {
		$this->seen[ $key ] = true;
		if ( \count( $this->seen ) > self::MAX_SEEN ) {
			$this->seen = \array_slice( $this->seen, -self::MAX_SEEN, null, true );
		}
	}

	/**
	 * The `set_interval` verb's entry point: fetch on the wall-clock grid
	 * every `$seconds`, riding the Router's tick.
	 *
	 * @api Used by substrate.
	 * @param string $seconds A whole number of seconds, at least 1.
	 * @throws \InvalidArgumentException Below one second.
	 */
	public function set_interval( string $seconds ): void {
		$interval = (int) $seconds;
		if ( $interval < 1 ) {
			throw new \InvalidArgumentException( \esc_html( "{$this->name}: set_interval wants a whole number of seconds from 1 up, got {$seconds}" ) );
		}
		$this->interval = $interval;
		if ( ! $this->timer_is_active() ) {
			$this->set_timer();
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
			'retrieved_ts' => Intel_Event::now(),
		];
	}

	/**
	 * Build a connector's node_schema from the shared Source shape — category
	 * Source, the `tier` positional, one TICK request and the `set_tier` verb
	 * ahead of the connector's own — so the connectors don't each restate it.
	 * has_target inherits Node's default (true).
	 *
	 * @param string                    $reply_shape What a TICK answers.
	 * @param list<array<string,mixed>> $commands    The connector's own verbs.
	 * @return array<string,mixed>
	 */
	protected static function source_schema( string $description, string $tick_description, string $reply_shape, array $commands = [] ): array {
		return \array_merge( Node::node_schema(), [
			'category'    => 'Source',
			'description' => $description,
			'arguments'   => [
				[ 'name' => 'tier', 'type' => 'string', 'required' => true, 'description' => 'The access tier every recorded item carries: public, internal or restricted.' ],
			],
			'requests'    => [
				[
					'name'        => 'TICK',
					'description' => $tick_description,
					'reply_shape' => $reply_shape,
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
				[
					'name'        => 'set_interval',
					'description' => 'Fetch every <seconds> on the wall-clock grid, at each multiple of it since the epoch, riding the Router\'s tick; with none set only TICK fetches.',
					'args'        => [
						[ 'name' => 'seconds', 'type' => 'int', 'required' => true ],
					],
					'setter'      => 'interval',
				],
				...$commands,
			],
			'accepts_fill' => false,
		] );
	}
}

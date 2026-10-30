<?php
/**
 * Source_Node: abstract base for every connector node.
 *
 * Owns the chain every source fetches on, so the worker never waits on a
 * request: a pass's requests go to the `{name}:curl` sibling, the
 * substrate's `Curl_Node`, under the vault group the `make_node` line names,
 * and each answer comes back here as a message. A request is keyed by its
 * subject, the url it was queued under, which rides in KEY while each hop's
 * url rides in VALUE. A source supplies `begin()` and `received()`, and
 * `record()` is the one path its items take into the stream.
 *
 * Abstract — never make_node'd directly (no node_schema here); each concrete
 * connector declares its own Source category + TICK request.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

abstract class Source_Node extends Timer_Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** Requests an API source sends in one pass; the next is dropped, logged. */
	public const MAX_REQUESTS_PER_PASS = 60;

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

	/** The Vault group whose entry on a request's origin lends it a credential; '' for none. */
	protected string $vault_group = '';

	/** The `{name}:curl` sibling that fetches. */
	private Curl_Node $curl;

	/** The tick clock, in whole seconds, at this process's last Router tick; null before the first. */
	private ?int $checked = null;

	/** @var array<string,array{0:string,1:string|null,2:int}> Requests this pass has yet to send, by subject: [ the hop's url, a POST's body or null, redirects followed ]. */
	private array $queue = [];

	/** @var array<string,array{0:string,1:string|null,2:int}> Requests sent and not yet answered, as the queue holds them. */
	private array $in_flight = [];

	/** Requests this pass has sent. */
	private int $sent = 0;

	/** True while send() runs; an answer arriving inside it does not re-enter. */
	private bool $sending = false;

	/**
	 * Tachikoma-parity: no-arg ctor. Builds the Curl sibling first, because
	 * naming the `:config` interpreter names every sibling.
	 */
	public function __construct() {
		parent::__construct();
		$this->attach_curl();
		$this->auto_wire_interpreter();
	}

	/**
	 * `<tier> [vault_group]`: the access tier, one of Intel_Event::TIERS,
	 * checked before any token is assigned, and the vault group the Curl
	 * sibling runs under, which a changed group rebuilds it for.
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
		$group = $this->vault_group;
		$this->assign_schema_args( $args, $values );
		if ( $group !== $this->vault_group ) {
			$this->retract_sibling( 'curl' );
			$this->attach_curl();
		}
		return parent::arguments( $args );
	}

	/**
	 * A TICK request is answered from the declared `requests` handler (NOT a
	 * TM_COMMAND verb); anything else is Curl's answer to a request.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		if ( ! $this->answer_request( $message ) ) {
			$this->answer( $message );
		}
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
	 * Start a pass, which a TICK request and the grid both call, unless
	 * requests are in flight: queue the source's opening requests, replacing
	 * what a throw left queued, and send.
	 *
	 * @return array{fetching:int} How many requests the pass opens on, 0 when one is still in flight.
	 */
	protected function tick(): array {
		if ( [] !== $this->in_flight ) {
			return [ 'fetching' => 0 ];
		}
		$this->queue = [];
		$this->sent  = 0;
		$this->begin();
		$fetching = \count( $this->queue );
		$this->send();
		return [ 'fetching' => $fetching ];
	}

	/** Queue the pass's opening requests through queue(). */
	abstract protected function begin(): void;

	/**
	 * Take Curl's answer to a request in flight: a body records what
	 * received() makes of it, a redirect re-queues the request at its
	 * Location, and a TM_ERROR is logged and ends its branch. An answer that
	 * throws is logged naming its subject; a cooperative stop propagates
	 * (substrate ADR-14). The next request goes out whatever happened, so a
	 * pass always ends. An answer for no request in flight is dropped.
	 *
	 * @param array<int,mixed> $message The body as TM_BYTESTREAM, the Location as TM_RESPONSE, or the TM_ERROR, KEY the subject.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answer( array $message ): void {
		$key     = Core::as_string( $message[ Message::KEY ] );
		$request = $this->in_flight[ $key ] ?? null;
		if ( null === $request ) {
			$this->drop_message( $message, 'no request in flight' );
			return;
		}
		unset( $this->in_flight[ $key ] );
		try {
			$type  = Core::as_int( $message[ Message::TYPE ] );
			$value = Core::as_string( $message[ Message::VALUE ] );
			if ( 0 !== ( $type & Message::TM_ERROR ) ) {
				$this->print_less_often( 'a fetch failed for ' . Core::terminal_safe( $key ) . ': ', Core::terminal_safe( $value ) );
			} elseif ( 0 !== ( $type & Message::TM_RESPONSE ) ) {
				$this->redirect( $key, $request, $value );
			} else {
				$this->record( $this->received( $key, $value ) );
			}
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'an answer failed for ' . Core::terminal_safe( $key ) . ': ', $e->getMessage() );
		} finally {
			$this->send();
		}
	}

	/**
	 * Send queued requests while fewer than max_in_flight(), clamped to
	 * Curl's own cap, are in flight, one per host at a time, until the pass
	 * has sent max_requests(); the one past that is logged and the queue
	 * dropped. A request fetchable() refuses ends its branch. An answer Curl
	 * sends synchronously frees its slot for this same loop.
	 */
	private function send(): void {
		if ( $this->sending ) {
			return;
		}
		$room          = \min( $this->max_in_flight(), Curl_Node::MAX_IN_FLIGHT );
		$this->sending = true;
		try {
			do {
				$sent = false;
				foreach ( $this->queue as $key => $request ) {
					if ( \count( $this->in_flight ) >= $room ) {
						break 2;
					}
					if ( isset( $this->busy_hosts()[ self::host( $request[0] ) ] ) ) {
						continue;
					}
					unset( $this->queue[ $key ] );
					if ( $this->sent >= $this->max_requests() ) {
						$this->print_less_often( 'a pass reached ' . $this->max_requests() . ' requests; sending no more: ', Core::terminal_safe( $request[0] ) );
						$this->queue = [];
						break 2;
					}
					if ( $this->fetchable( $request[0] ) ) {
						++$this->sent;
						$this->dispatch( $key, $request );
						$sent = true;
					}
				}
			} while ( $sent );
		} finally {
			$this->sending = false;
		}
	}

	/** Requests in flight at once: 1, so an API source's go strictly one after another. */
	protected function max_in_flight(): int {
		return 1;
	}

	/**
	 * The hosts a request in flight is fetching.
	 *
	 * @return array<string,true>
	 */
	private function busy_hosts(): array {
		$busy = [];
		foreach ( $this->in_flight as [ $url ] ) {
			$busy[ self::host( $url ) ] = true;
		}
		return $busy;
	}

	/** A url's host, lowercased; '' for none. */
	private static function host( string $url ): string {
		return \strtolower( Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) );
	}

	/** Requests a pass sends in all. */
	protected function max_requests(): int {
		return self::MAX_REQUESTS_PER_PASS;
	}

	/**
	 * Whether a url may be fetched, asked as it is sent; a source that
	 * refuses one logs why.
	 *
	 * @param string $url The hop's url.
	 */
	protected function fetchable( string $url ): bool {
		return true;
	}

	/**
	 * Send one request to Curl as a TM_STRUCT, its subject in KEY and its
	 * hop in VALUE; a request Curl throws on leaves flight, so the next
	 * pass starts.
	 *
	 * @param string                                $key     The request's subject.
	 * @param array{0:string,1:string|null,2:int} $request The hop's url, a POST's body or null, and its hops.
	 */
	private function dispatch( string $key, array $request ): void {
		[ $url, $body ]          = $request;
		$this->in_flight[ $key ] = $request;
		$value                   = [
			'url'    => $url,
			'method' => null === $body ? 'GET' : 'POST',
		];
		if ( null !== $body ) {
			$value['body'] = $body;
		}
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $value;
		try {
			$this->curl->fill( $message );
		} catch ( \Throwable $e ) {
			unset( $this->in_flight[ $key ] );
			throw $e;
		}
	}

	/**
	 * Re-queue a redirected request under its subject at the Location, one
	 * hop on, unless that would pass Curl_Node::MAX_REDIRECTS, which is
	 * logged. Only a source whose Curl follows no redirect receives one.
	 *
	 * @param string                                $key      The request's subject.
	 * @param array{0:string,1:string|null,2:int} $request  The request redirected.
	 * @param string                                $location The absolute Location.
	 */
	private function redirect( string $key, array $request, string $location ): void {
		[ , $body, $hops ] = $request;
		if ( $hops >= Curl_Node::MAX_REDIRECTS ) {
			$this->print_less_often( 'a request redirected past ' . Curl_Node::MAX_REDIRECTS . ' hops; recording nothing: ', Core::terminal_safe( $location ) );
			return;
		}
		$this->queue[ $key ] = [ $location, $body, $hops + 1 ];
	}

	/**
	 * What a body means: the items to record, normalized, queuing through
	 * queue() any request it names next.
	 *
	 * @param string $url  The subject the body answers, the url it was queued under.
	 * @param string $body The body.
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function received( string $url, string $body ): array;

	/**
	 * Record items: drop the keys already recorded, remember without
	 * recording those the projection already holds, and emit one
	 * `item_recorded` event per new item as a TM_STRUCT to the target. An
	 * item make() refuses is logged and skipped unremembered, so it retries.
	 * One whose key runs past Graph_Curation::ITEM_KEY_BYTES as the stream
	 * writes it, so that a record naming it could outgrow one line, is
	 * logged naming the key cut to KEY_SHOWN_BYTES and remembered, so it is
	 * refused once a process.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 */
	private function record( array $items ): void {
		$held = $this->held( $items );
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
				continue;
			}
			try {
				$value = Intel_Event::machine_event( "source:{$this->name}", 'item_recorded', $item );
			} catch ( \InvalidArgumentException $invalid ) {
				$this->print_less_often( 'refused an item: ', "{$key}: {$invalid->getMessage()}" );
				continue;
			}
			$this->emit( '', $value );
			// Unlike a size refusal, a make() refusal is not kept: it retries.
			$this->remember( $key );
		}
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
			unset( $this->seen[ \array_key_first( $this->seen ) ] );
		}
	}

	/**
	 * Build and publish the Curl sibling under the vault group, following
	 * redirects only where follows_redirects() says, its answers coming
	 * back here.
	 */
	private function attach_curl(): void {
		$curl = new Curl_Node();
		$curl->patron( $this );
		$curl->arguments( '' === $this->vault_group ? [] : [ $this->vault_group ] );
		$curl->follow_redirects( $this->follows_redirects() );
		$this->curl = $curl;
		$this->publish_sibling( 'curl', $curl );
		$curl->sink( $this->sink );
	}

	/** Whether Curl follows a redirect itself, or answers it for this source to re-queue. */
	protected function follows_redirects(): bool {
		return true;
	}

	/**
	 * A JSON answer, decoded, or null when it holds nothing to record: a
	 * body that is no JSON object or list, or one $error reads an error off,
	 * logged under the request's url so each failing request shows.
	 *
	 * @param string                                  $api   The service, as the line names it.
	 * @param string                                  $url   The request's url.
	 * @param string                                  $body  The body.
	 * @param \Closure(array<array-key,mixed>):?string $error The error the answer carries, or null for none.
	 * @return array<array-key,mixed>|null
	 */
	protected function json_answer( string $api, string $url, string $body, \Closure $error ): ?array {
		$answer = \json_decode( $body, true );
		$fault  = 'the body holds no JSON object or list';
		if ( \is_array( $answer ) ) {
			$fault = $error( $answer );
			if ( null === $fault ) {
				return $answer;
			}
		}
		$this->print_less_often( "{$api} answered an error for " . Core::terminal_safe( $url ) . ': ', Core::terminal_safe( $fault ) );
		return null;
	}

	/**
	 * Queue a request this pass sends, its url its subject: a GET, or with a
	 * body a POST of it as JSON. A url already queued is queued once, with
	 * the later body.
	 *
	 * @param string      $url  The url.
	 * @param string|null $body A POST's body, or null for a GET.
	 */
	protected function queue( string $url, ?string $body = null ): void {
		$this->queue[ $url ] = [ $url, $body, 0 ];
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

	/**
	 * Name the Curl sibling through `parent::`, and point its answers at
	 * this name.
	 *
	 * @api Used by substrate.
	 */
	protected function set_sibling_names(): void {
		parent::set_sibling_names();
		$this->curl->target( $this->name );
	}

	/** Emit the base config, each declared setter's line and one add line per list or checked list entry. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters() . $this->dump_lists() . $this->dump_declared( 'checked', static fn ( mixed $entries ): array => \array_map( Core::as_string( ... ), \array_values( Core::arr( $entries ) ) ) );
	}

	/**
	 * Each row mapped to its item, a row the map answers null for left out;
	 * a row that is no array reaches the map as an empty one.
	 *
	 * @param mixed                                                       $rows The rows; anything but an array holds none.
	 * @param \Closure(array<array-key,mixed>):(array<string,mixed>|null) $map  One row to its item, or null to leave it out.
	 * @return list<array<string,mixed>>
	 */
	protected static function items( mixed $rows, \Closure $map ): array {
		$items = [];
		foreach ( Core::arr( $rows ) as $row ) {
			$item = $map( Core::arr( $row ) );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}
		return $items;
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
	 * Source, the `tier` positional and, for a source that sends a
	 * credential, the optional `vault_group` after it, one TICK request and
	 * the `set_tier` verb ahead of the connector's own — so the connectors
	 * don't each restate it. has_target inherits Node's default (true).
	 *
	 * @param list<array<string,mixed>> $commands     The connector's own verbs.
	 * @param bool                      $credentialed Whether the source takes a vault group.
	 * @return array<string,mixed>
	 */
	protected static function source_schema( string $description, string $tick_description, array $commands = [], bool $credentialed = true ): array {
		$arguments = [
			[ 'name' => 'tier', 'type' => 'string', 'required' => true, 'description' => 'The access tier every recorded item carries: public, internal or restricted.' ],
		];
		if ( $credentialed ) {
			$arguments[] = [ 'name' => 'vault_group', 'type' => 'vault_group', 'default' => '', 'description' => 'Optional Vault group: a request to one entry\'s origin carries the credential header that entry names.' ];
		}
		return \array_merge( Node::node_schema(), [
			'category'    => 'Source',
			'description' => $description,
			'arguments'   => $arguments,
			'requests'    => [
				[
					'name'        => 'TICK',
					'description' => $tick_description,
					'reply_shape' => '{ fetching }',
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

	/**
	 * The `add_<noun>` and `remove_<noun>` verbs of a list every entry of
	 * which matches $pattern: list_verbs()'s pair, its add refusing a value
	 * that does not match, naming it as not $kind, and appending one that
	 * does unless an entry equals it. The add's `checked` key names the list
	 * dump_config() writes one add line per entry of.
	 *
	 * @param string $noun        The verbs' suffix, and the one arg they take.
	 * @param string $property    The list property, a `list<string>`.
	 * @param string $pattern     The regex every entry matches.
	 * @param string $kind        What an entry is, as a refusal names it.
	 * @param string $description The add verb's description.
	 * @return list<array<string,mixed>>
	 */
	protected static function checked_list( string $noun, string $property, string $pattern, string $kind, string $description ): array {
		[ $add, $remove ] = self::list_verbs( $noun, $property, [ 'name' => $noun, 'type' => 'string', 'required' => true ], $description );
		unset( $add['list'] );
		$add['checked'] = $property;
		$add['handler'] = static function ( Command_Interpreter_Node $interpreter, array $args ) use ( $noun, $property, $pattern, $kind ): string {
			$value = Core::as_string( $args[ $noun ] );
			if ( 1 !== \preg_match( $pattern, $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
				throw new \InvalidArgumentException( "add_{$noun}: '{$value}' is not {$kind}" );
			}
			$source  = $interpreter->patron();
			$entries = Core::arr( $source->{$property} );
			if ( ! \in_array( $value, $entries, true ) ) {
				$source->{$property} = [ ...$entries, $value ];
			}
			return "ok\n";
		};
		return [ $add, $remove ];
	}
}

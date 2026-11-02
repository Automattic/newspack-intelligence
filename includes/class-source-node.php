<?php
/**
 * Source_Node: abstract base for every connector node.
 *
 * Owns the chain every source fetches on, so the worker never waits on a
 * request: a pass's requests go to the `{name}:curl` sibling, the
 * substrate's `Curl_Node`, under the vault group the `make_node` line names,
 * and each answer comes back here as a message. A request is keyed by its
 * subject, the url it was queued under unless the source names another,
 * which rides in KEY while each hop's url rides in VALUE. A source supplies
 * `begin()` and `received()`, may name what each request adds, its header
 * lines or its proxy, through `request_options()`, and `record()` is the one
 * path its items take into the stream.
 *
 * Abstract — never make_node'd directly (no node_schema here); each concrete
 * connector declares its own Source category + TICK request.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Publishes_Status;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

abstract class Source_Node extends Timer_Node {
	use Schema_Reflection;
	use Emits_Struct;
	use Publishes_Status;

	/** Requests an API source sends in one pass; the next is dropped, logged. */
	public const MAX_REQUESTS_PER_PASS = 60;

	/** Bytes of body an item records; a longer body is cut on a character boundary. */
	public const MAX_BODY_BYTES = 65536;

	/** Characters of a text's first line a title keeps. */
	public const TITLE_CHARS = 120;

	/** The three escapes Slack's mrkdwn writes into message text, and what each spells. */
	private const MRKDWN_ESCAPES = [
		'&amp;' => '&',
		'&lt;'  => '<',
		'&gt;'  => '>',
	];

	/** Bytes of a refused key its line shows. */
	private const KEY_SHOWN_BYTES = 64;

	/** Cap on the remembered recorded-key set — bounds memory on a long-lived worker. */
	private const MAX_SEEN = 2000;

	/**
	 * The subjects of the requests that open or close a pass rather than
	 * fetch its data, which count in it only when they fail.
	 *
	 * @var list<string>
	 */
	protected const PROTOCOL_SUBJECTS = [];

	/** Every key a status carries, null, so each publish clears what its state leaves out. */
	public const BLANK_STATUS = [
		'state'         => null,
		'last_ok_at'    => null,
		'last_error'    => null,
		'failing_since' => null,
		'failed'        => null,
		'requests'      => null,
		'reason'        => null,
	];

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

	/** Answers this pass has taken. */
	private int $requests = 0;

	/** Answers this pass has taken that failed. */
	private int $failed = 0;

	/** The text of this pass's last failed answer; '' for none. */
	private string $last_error = '';

	/** The failure the answer being taken carries; null while it carries none. */
	private ?string $fault = null;

	/** Whether this pass published idle, which its end leaves standing. */
	private bool $idled = false;

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
	 * fetch that fails is logged and published failing, and the next boundary
	 * tries again; a cooperative stop propagates (substrate ADR-14).
	 *
	 * @api Used by substrate.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	protected function fire(): void {
		$now           = (int) Core::$now;
		$last          = $this->checked;
		$this->checked = $now;
		$resume        = $this->resumes();
		$boundary      = null !== $last && null !== $this->interval && \intdiv( $now, $this->interval ) > \intdiv( $last, $this->interval );
		if ( ! $resume && ! $boundary ) {
			return;
		}
		try {
			$this->tick();
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'a scheduled fetch failed; trying again at the next boundary: ', $e->getMessage() );
			$this->publish_failing( $e->getMessage() );
		}
	}

	/**
	 * Start a pass, which a TICK request and the grid both call, unless
	 * requests are in flight or the source needs a credential its vault
	 * group lends none on credential_url()'s origin, when it sits idle
	 * silently, publishing nothing, until the entry exists: queue the
	 * source's opening requests, replacing what a throw left queued, and
	 * send. A pass that queues nothing ends at once, as unqueued()
	 * describes; any other closes on its last answer.
	 *
	 * @return array{fetching:int} How many requests the pass opens on, 0 when one is still in flight or no credential is lent.
	 */
	protected function tick(): array {
		$credential = static::credential_url();
		if ( [] !== $this->in_flight || ( '' !== $credential && ! Curl_Node::lends_credential( $this->vault_group, $credential ) ) ) {
			return [ 'fetching' => 0 ];
		}
		$this->sent       = 0;
		$this->requests   = 0;
		$this->failed     = 0;
		$this->last_error = '';
		$this->idled      = false;
		$this->fault      = null;
		$this->drop_queue();
		$this->begin();
		$fetching = \count( $this->queue );
		$this->send();
		if ( 0 === $fetching ) {
			$this->unqueued();
		}
		return [ 'fetching' => $fetching ];
	}

	/**
	 * End a pass begin() queued nothing for: failing with the text begin()
	 * handed fails(), else nothing while it waits(), else a quiet ok.
	 */
	private function unqueued(): void {
		if ( null !== $this->fault ) {
			$this->publish_failing( $this->fault );
		} elseif ( ! $this->waits() ) {
			$this->close_pass();
		}
	}

	/**
	 * A url on the origin whose credential the source cannot fetch without,
	 * which its vault group must hold an entry for before a pass sends
	 * anything; '' for a source that needs none. A fact of the class, so a
	 * reader asks it with no instance.
	 */
	public static function credential_url(): string {
		return '';
	}

	/** Queue the pass's opening requests through queue(). */
	abstract protected function begin(): void;

	/**
	 * Take Curl's answer to a request in flight: a body records what
	 * received() makes of it, the response headers a request named reaching
	 * headers_received() first, a redirect re-queues the request at its
	 * Location, and a TM_ERROR is logged, handed to failed() and ends its
	 * branch. Once nothing is queued or in flight, pass_ended() runs, and
	 * anything it queues goes out. An answer that
	 * throws is logged naming its subject; a cooperative stop propagates
	 * (substrate ADR-14). Each answer counts toward the pass, failed or
	 * answered, but a redirect hop and a protocol request that did not fail.
	 * The next request goes out whatever happened, so a pass always ends. An
	 * answer for no request in flight is dropped.
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
		$this->fault = null;
		$hop         = false;
		try {
			$type  = Core::as_int( $message[ Message::TYPE ] );
			$value = $message[ Message::VALUE ];
			if ( 0 !== ( $type & Message::TM_STRUCT ) ) {
				$answer  = Core::arr( $value );
				$headers = [];
				foreach ( Core::arr( $answer['headers'] ?? null ) as $name => $header ) {
					$headers[ (string) $name ] = Core::as_string( $header );
				}
				$this->headers_received( $key, $headers );
				$value = $answer['body'] ?? '';
			}
			$value = Core::as_string( $value );
			if ( 0 !== ( $type & Message::TM_ERROR ) ) {
				$this->fault = Core::terminal_safe( $value );
				// Curl's text names the url, so the status takes it bare.
				$this->print_less_often( 'a fetch failed for ' . Core::terminal_safe( $key ) . ': ', $this->fault );
				$this->failed( $key, $value );
			} elseif ( 0 !== ( $type & Message::TM_RESPONSE ) ) {
				$hop = $this->redirect( $key, $request, $value );
			} else {
				$this->record( $this->received( $key, $value ) );
			}
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->fails( 'an answer failed for ' . Core::terminal_safe( $key ) . ': ', $e->getMessage() );
		} finally {
			if ( null !== $this->fault ) {
				++$this->requests;
				++$this->failed;
				$this->last_error = $this->fault;
			} elseif ( ! $hop && ! \in_array( $key, static::PROTOCOL_SUBJECTS, true ) ) {
				++$this->requests;
			}
			$this->send();
			if ( [] === $this->queue && [] === $this->in_flight ) {
				$this->close_pass();
			}
		}
	}

	/**
	 * End the pass through pass_ended(), then publish how it went, unless a
	 * closing request it sent is still to answer, whose answer ends it.
	 */
	private function close_pass(): void {
		try {
			$this->pass_ended();
		} finally {
			if ( [] === $this->in_flight ) {
				$this->publish_pass();
			}
		}
	}

	/**
	 * Publish the pass: ok when it counted no answer, a quiet pass being
	 * healthy, or any answer did not fail, else failing with the last
	 * failure's text; a pass that published idle publishes nothing.
	 */
	private function publish_pass(): void {
		if ( $this->idled ) {
			return;
		}
		if ( 0 === $this->requests || $this->failed < $this->requests ) {
			$this->publish_snapshot(
				[
					'state'      => 'ok',
					'last_ok_at' => (int) Core::$now,
					'failed'     => $this->failed,
					'requests'   => $this->requests,
				]
			);
			return;
		}
		$this->publish_failing( $this->last_error );
	}

	/**
	 * Publish failing with $error as `status_text()` renders it, since the
	 * failing_since already published, a recycled worker's included.
	 */
	private function publish_failing( string $error ): void {
		$this->publish_snapshot(
			[
				'state'         => 'failing',
				'last_error'    => self::status_text( $error ),
				'failing_since' => $this->status_since( 'failing_since' ),
				'failed'        => $this->failed,
				'requests'      => $this->requests,
			]
		);
	}

	/**
	 * Take the response headers a request named through request_options()'s
	 * `response_headers`, by lowercased name, before its body: none unless a
	 * source reads some.
	 *
	 * @param string                $subject The request's subject.
	 * @param array<string,string> $headers Each header the response sent.
	 */
	protected function headers_received( string $subject, array $headers ): void {
	}

	/**
	 * Take a pass's end, once nothing is queued or in flight: nothing unless
	 * a source closes something, through send_closing().
	 */
	protected function pass_ended(): void {
	}

	/**
	 * Send the request a pass closes with at once, outside the pass's request
	 * budget, so a pass the cap ended still closes; pass_ended() calls it,
	 * when nothing is queued or in flight.
	 *
	 * @param string      $url     The url.
	 * @param string|null $body    A POST's body, or null for a GET.
	 * @param string      $subject What the request asks.
	 */
	protected function send_closing( string $url, ?string $body, string $subject ): void {
		$this->dispatch( $subject, [ $url, $body, 0 ] );
	}

	/**
	 * Whether the source resumes a pass on this tick, off the grid, as a
	 * pass held back by something now done does; the grid's guard applies.
	 */
	protected function resumes(): bool {
		return false;
	}

	/**
	 * Whether a pass begin() queued nothing for is held back by something
	 * another node does, so it publishes nothing until it resumes.
	 */
	protected function waits(): bool {
		return false;
	}

	/**
	 * Take a request's failure after it is logged: a transport failure or a
	 * status of 400 or more, as Curl's TM_ERROR names it; nothing unless a
	 * source acts on one.
	 *
	 * @param string $subject The request's subject.
	 * @param string $error   The TM_ERROR's text, such as `HTTP 404 <url>`.
	 */
	protected function failed( string $subject, string $error ): void {
	}

	/**
	 * Send queued requests while fewer than max_in_flight(), clamped to
	 * Curl's own cap, are in flight, one per host at a time, until the pass
	 * has sent max_requests(); the one past that is logged and the queue
	 * dropped. An answer Curl sends synchronously frees its slot for this
	 * same loop.
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
						$this->abandoned( $key );
						$this->drop_queue();
						break 2;
					}
					++$this->sent;
					$this->dispatch( $key, $request );
					$sent = true;
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
	 * Send one request to Curl as a TM_STRUCT, its subject in KEY and its
	 * hop in VALUE, with the fields request_options() names for its subject
	 * as it goes out; a request Curl throws on leaves flight, so the next
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
		$value                     = [ ...$value, ...$this->request_options( $key ) ];
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $value;
		try {
			$this->curl->fill( $message );
		} catch ( \Throwable $e ) {
			unset( $this->in_flight[ $key ] );
			$this->abandoned( $key );
			throw $e;
		}
	}

	/**
	 * Re-queue a redirected request under its subject at the Location, one
	 * hop on, unless that would pass Curl_Node::MAX_REDIRECTS, or the request
	 * carries header lines of its own or a method other than GET, which the
	 * re-queue would carry to another url past Curl's own redirect guard;
	 * either is logged, records nothing and fails(). Only a source whose
	 * Curl follows no redirect receives one.
	 *
	 * @param string                                $key      The request's subject.
	 * @param array{0:string,1:string|null,2:int} $request  The request redirected.
	 * @param string                                $location The absolute Location.
	 * @return bool Whether it re-queued the request.
	 */
	private function redirect( string $key, array $request, string $location ): bool {
		[ , $body, $hops ] = $request;
		$options           = $this->request_options( $key );
		if ( null !== $body || [] !== Core::arr( $options['headers'] ?? null ) || 'GET' !== ( $options['method'] ?? 'GET' ) ) {
			$this->fails( 'a redirect of a request carrying its own headers or a method other than GET is not followed; recording nothing: ', $location );
			return false;
		}
		if ( $hops >= Curl_Node::MAX_REDIRECTS ) {
			$this->fails( 'a request redirected past ' . Curl_Node::MAX_REDIRECTS . ' hops; recording nothing: ', $location );
			return false;
		}
		$this->queue[ $key ] = [ $location, $body, $hops + 1 ];
		return true;
	}

	/**
	 * The fields a request adds to the TM_STRUCT Curl runs, read as it goes
	 * out: `headers`, a list of `Name: value` lines, `proxy`, the url it goes
	 * through, and `response_headers`, the names headers_received() is
	 * handed; none unless a source names some.
	 *
	 * @param string $subject The request's subject.
	 * @return array<string,mixed>
	 */
	protected function request_options( string $subject ): array {
		return [];
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

	/** End the pass: drop every request it has yet to send; those in flight still answer. */
	protected function end_pass(): void {
		$this->drop_queue();
	}

	/** Drop every queued request, each handed to abandoned(), since none will answer. */
	private function drop_queue(): void {
		$queued      = \array_keys( $this->queue );
		$this->queue = [];
		foreach ( $queued as $subject ) {
			$this->abandoned( $subject );
		}
	}

	/**
	 * Take a request that will never answer: dropped from the queue, or
	 * thrown on as it went out; nothing unless a source holds something for
	 * it.
	 *
	 * @param string $subject The request's subject.
	 */
	protected function abandoned( string $subject ): void {
	}

	/**
	 * Publish that the source cannot fetch, and why, which the pass's end
	 * leaves standing.
	 *
	 * @param string $reason The cause and its fix, as a reader acts on it.
	 */
	protected function idle( string $reason ): void {
		$this->idled = true;
		$this->publish_snapshot(
			[
				'state'  => 'idle',
				'reason' => $reason,
			]
		);
	}

	/**
	 * Publish a status of every key, those $fields leaves out null.
	 *
	 * @param array<string,mixed> $fields The keys the state carries.
	 */
	private function publish_snapshot( array $fields ): void {
		$this->publish_status( [ ...self::BLANK_STATUS, ...$fields ] );
	}

	/**
	 * A JSON answer, decoded, or null when it holds nothing to record: a
	 * body that is no JSON object or list, or one $error reads an error off,
	 * logged under the request's url so each failing request shows, which
	 * fails().
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
		$this->fails( "{$api} answered an error for " . Core::terminal_safe( $url ) . ': ', $fault );
		return null;
	}

	/**
	 * Log a failure of the answer being taken, rate-limited under $prefix,
	 * and count the answer as failed with the same text: what a source calls
	 * for an answer it drops whole. Called from a begin() that queues
	 * nothing, it fails the pass.
	 *
	 * @param string $prefix What failed, naming the request's url, terminal-safe.
	 * @param string $detail Why, made terminal-safe here.
	 */
	protected function fails( string $prefix, string $detail ): void {
		$detail = Core::terminal_safe( $detail );
		$this->print_less_often( $prefix, $detail );
		$this->fault = $prefix . $detail;
	}

	/**
	 * Seconds the cache keeps a status: twice the slowest source's interval,
	 * `news`'s 3,600.
	 *
	 * @api Used by substrate.
	 */
	protected function status_ttl(): int {
		return 7200;
	}

	/**
	 * Seconds after which an unchanged status is written again.
	 *
	 * @api Used by substrate.
	 */
	protected function status_heartbeat(): int {
		return 60;
	}

	/**
	 * Queue a request this pass sends, under its subject, the url unless one
	 * is named: a GET, or with a body a POST of it as JSON. A subject already
	 * queued is queued once, with the later url and body.
	 *
	 * @param string      $url     The url.
	 * @param string|null $body    A POST's body, or null for a GET.
	 * @param string|null $subject What the request asks, when several share one url.
	 */
	protected function queue( string $url, ?string $body = null, ?string $subject = null ): void {
		$this->queue[ $subject ?? $url ] = [ $url, $body, 0 ];
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

	/** A text's first line, cut to TITLE_CHARS characters. */
	protected static function first_line( string $text ): string {
		return \mb_substr( \explode( "\n", $text, 2 )[0], 0, self::TITLE_CHARS );
	}

	/** Mrkdwn text with its three escapes decoded in one pass, its mentions kept as written. */
	protected static function mrkdwn( string $text ): string {
		return \strtr( $text, self::MRKDWN_ESCAPES );
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
	 * A source's topology row names the tier it records at: the last
	 * `set_tier` its config is sent, bound by that verb's own args, else its
	 * make_node line's, the first positional every source declares.
	 *
	 * @api Read by the substrate's Topology_Analyzer::graph_for().
	 * @param list<string>                         $args     The `make_node` arguments, quotes stripped.
	 * @param list<array{0:string,1:list<string>}> $commands Each verb and its argument tokens, in the order sent.
	 * @return array{tier:string}
	 * @throws \InvalidArgumentException When a `set_tier` line does not bind.
	 */
	public static function graph_facts( array $args, array $commands ): array {
		$tier = $args[0] ?? '';
		foreach ( $commands as [ $verb, $tokens ] ) {
			if ( 'set_tier' === $verb ) {
				$tier = Core::as_string( Command_Args::bind( Core::arr( Command_Interpreter_Node::declared_verbs( static::class )['set_tier']['args'] ), $tokens )['tier'] );
			}
		}
		return [ 'tier' => $tier ];
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

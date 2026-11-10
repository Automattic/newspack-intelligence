<?php
/**
 * Source_Node: abstract base for every connector node.
 *
 * Owns the chain: a pass's requests wait in the `{name}:frontier` Table it
 * owns until its `{name}:curl` sibling answers each; a source supplies
 * `begin()` and `received()`, and `record()` takes its items to the stream.
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
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Request array{0:string,1:string|null,2:int,3:array<array-key,mixed>}
 */
abstract class Source_Node extends Timer_Node implements Shutdown_Sweeper {
	use Schema_Reflection;
	use Emits_Struct;
	use Publishes_Status;

	/** @use Owns_Curl_Frontier<Request> */
	use Owns_Curl_Frontier;

	/**
	 * The source its items are recorded under, one of Intel_Event::SOURCES.
	 *
	 * @var string
	 */
	public const SOURCE = '';

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

	/** Rooms of requests one take moves into the queue, so a busy host's leave room for others'. */
	private const TAKE_ROOMS = 4;

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

	/** The access tier positional every source opens its `make_node` line with. */
	protected const TIER_ARGUMENT = [ 'name' => 'tier', 'type' => 'string', 'required' => true, 'description' => 'The access tier every recorded item carries: public, internal or restricted.' ];

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

	/** @var array<string,bool> Each recorded item by copy_of(), and each key refused for its size (insertion-ordered), for cross-tick dedup. */
	protected array $seen = [];

	/** The access tier every item this source records carries. */
	protected string $tier = '';

	/** Seconds between grid fetches, null for none: only TICK fetches. */
	protected ?int $interval = null;

	/** The tick clock, in whole seconds, at this process's last Router tick; null before the first. */
	private ?int $checked = null;

	/** @var array<string,Request> Requests queued since the last send, by subject: [ the hop's url, a POST's body or null, redirects followed, the note ]. */
	private array $adds = [];

	/** @var array<string,Request> Requests taken to send, by subject, each waiting for a slot and its host. */
	private array $queue = [];

	/** Whether a pass is open: it took a request behind_projector() let through, and has not published. */
	private bool $open = false;

	/** @var Request The request whose answer is being taken. */
	private array $answering = [ '', null, 0, [] ];

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

	/** @var array<string,array{0:int,1:int}>|null The ends the next pass waits for the projector to read, by cursor dir, as behind_projector() takes them, [] for a wait that has taken none yet; null once it let one through, which alone lifts send()'s gate. A process starts waiting, so what a stopped one left sends only once behind_projector() lets it. */
	private ?array $awaiting = [];

	/** Whether a pass came due while behind_projector(), which fire() opens on the tick it lets it. */
	private bool $deferred = false;

	/**
	 * `<tier> [vault_group]`: the tier, refused outside Intel_Event::TIERS, and
	 * the group Curl runs under, building the owned Table once and Curl again,
	 * resending its flight, on a new group.
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
		if ( $this->adopt_group( $group ) ) {
			$this->queue = [];
		}
		return parent::arguments( $args );
	}

	/**
	 * A TICK request is answered from the declared `requests` handler (NOT a
	 * TM_COMMAND message); anything else is Curl's answer to a request.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		if ( $this->tables->accepts( $message ) ) {
			return;
		}
		if ( ! $this->answer_request( $message ) ) {
			$this->answer( $message );
		}
	}

	/**
	 * On the Router's tick, start a pass at each grid boundary, or the one
	 * that waits for the projector, else send what the frontier holds while
	 * nothing is in flight; a failure is logged and published (a stop
	 * propagates, ADR-14).
	 *
	 * @api Used by substrate.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	protected function fire(): void {
		$now           = (int) Core::$now;
		$last          = $this->checked;
		$this->checked = $now;
		$due           = $this->resumes() || $this->deferred || ( null !== $last && null !== $this->interval && \intdiv( $now, $this->interval ) > \intdiv( $last, $this->interval ) );
		if ( ! $due && [] !== $this->in_flight ) {
			return;
		}
		try {
			$due ? $this->tick() : $this->send();
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'a scheduled fetch failed; trying again at the next boundary: ', $e->getMessage() );
			$this->publish_failing( $e->getMessage() );
		}
	}

	/**
	 * Start a pass on a TICK or the grid, dropping what is still queued, unless
	 * requests are in flight, its group lends no credential on
	 * credential_url()'s origin, or behind_projector() holds it, when fire()
	 * opens it on the first Router tick behind_projector() lets it; either
	 * of the first two ends that deferral, so the next pass waits for the
	 * grid.
	 *
	 * @return array{fetching:int} How many requests the pass opens on, 0 when
	 *                             one is still in flight, its group lends no
	 *                             credential or it waits for the projector.
	 */
	protected function tick(): array {
		$credential     = static::credential_url();
		$this->deferred = false;
		if ( [] !== $this->in_flight || ( '' !== $credential && ! Curl_Node::lends_credential( $this->vault_group, $credential ) ) ) {
			return [ 'fetching' => 0 ];
		}
		$this->deferred = $this->behind_projector();
		if ( $this->deferred ) {
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
		$fetching = \count( $this->adds );
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
	 * A url on the origin whose credential the class cannot fetch without,
	 * which its vault group must lend before a pass sends anything; '' for
	 * none.
	 */
	public static function credential_url(): string {
		return '';
	}

	/** Queue the pass's opening requests through queue(). */
	abstract protected function begin(): void;

	/**
	 * Take Curl's answer, a body, a redirect or a TM_ERROR, count it toward the
	 * pass and send the next (a stop propagates, ADR-14); one that does not
	 * match a request in flight is dropped.
	 *
	 * @param array<int,mixed> $message The body as TM_BYTESTREAM, the Location as TM_RESPONSE, or the TM_ERROR, KEY the subject.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answer( array $message ): void {
		$key     = Core::as_string( $message[ Message::KEY ] );
		$request = $this->settled( $message );
		if ( null === $request ) {
			return;
		}
		$this->answering = $request;
		$this->fault     = null;
		$hop             = false;
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
				$this->fault = self::status_text( $value );
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
		}
	}

	/**
	 * Take the response headers request_options() named, by lowercased name,
	 * before the body; nothing unless a source reads some.
	 *
	 * @param string                $subject The request's subject.
	 * @param array<string,string> $headers Each header the response sent.
	 */
	protected function headers_received( string $subject, array $headers ): void {
	}

	/**
	 * Send at once, outside the request budget, the request pass_ended() closes
	 * a pass with.
	 *
	 * @param string      $url     The url.
	 * @param string|null $body    A POST's body, or null for a GET.
	 * @param string      $subject What the request asks.
	 */
	protected function send_closing( string $url, ?string $body, string $subject ): void {
		$this->dispatch( $subject, [ $url, $body, 0, [] ] );
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
	 * Send queued requests, one per host, while fewer than max_in_flight() are
	 * in flight and the pass has sent fewer than max_requests(), then close an
	 * open pass with nothing left.
	 */
	private function send(): void {
		if ( $this->sending ) {
			return;
		}
		$room          = \min( $this->max_in_flight(), Curl_Node::MAX_IN_FLIGHT );
		$busy          = [];
		$this->sending = true;
		try {
			foreach ( $this->in_flight as [ $url ] ) {
				$busy[ self::host( $url ) ] = true;
			}
			do {
				$took = $this->stage( $room );
				if ( $this->holds_leftover() ) {
					break;
				}
				$this->open = $this->open || [] !== $this->queue;
				$sent       = false;
				foreach ( $this->queue as $key => $request ) {
					if ( \count( $this->in_flight ) >= $room ) {
						break 2;
					}
					if ( isset( $busy[ self::host( $request[0] ) ] ) ) {
						continue;
					}
					unset( $this->queue[ $key ] );
					if ( $this->sent >= $this->max_requests() ) {
						$this->print_less_often( 'a pass reached ' . $this->max_requests() . ' requests; sending no more: ', Core::terminal_safe( $request[0] ) );
						$this->abandon( $key );
						$this->abandoned( $key, $request[3] );
						$this->drop_queue();
						break 2;
					}
					++$this->sent;
					$host          = self::host( $request[0] );
					$busy[ $host ] = true;
					$this->dispatch( $key, $request );
					// Curl answered it as it was handed, so its host is free.
					if ( ! isset( $this->in_flight[ $key ] ) ) {
						unset( $busy[ $host ] );
					}
					$sent = true;
				}
			} while ( $sent || $took );
		} finally {
			$this->sending = false;
		}
		if ( $this->open && [] === $this->queue && [] === $this->in_flight ) {
			$this->close_pass();
		}
	}

	/**
	 * Whether behind_projector() holds what the queue took with no pass open,
	 * as a stopped process or a refused TICK leaves a cut pass, which waits
	 * in the queue.
	 */
	private function holds_leftover(): bool {
		return ! $this->open && [] !== $this->queue && null !== $this->awaiting && $this->behind_projector();
	}

	/**
	 * Whether the next pass waits for the projector: while a reader has not
	 * committed the end its stream held when this wait first saw it
	 * (Projector_Node::short_of()), logged. A projection short of its
	 * streams, as a reproject leaves it, reads every item it lacks as new,
	 * so a pass begun or resumed on it would record them again. Once every
	 * reader has reached its end the wait is over, and the next takes ends
	 * of its own.
	 */
	private function behind_projector(): bool {
		$this->awaiting = ( $this->awaiting ?? [] ) + Projector_Node::ends();
		$short          = Projector_Node::short_of( $this->awaiting );
		if ( null === $short ) {
			$this->awaiting = null;
			return false;
		}
		$this->print_less_often( 'waiting for the projector to read its streams to the end; opening no pass: ', $short );
		return true;
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
				$this->open = false;
				$this->publish_pass();
			}
		}
	}

	/**
	 * Publish the pass ok unless every answer it counted failed, then failing
	 * with the last failure's text; nothing after an idle.
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
	 * Take a pass's end, once nothing is queued or in flight: nothing unless
	 * a source closes something, through send_closing().
	 */
	protected function pass_ended(): void {
	}

	/**
	 * Hand the frontier the requests queued since, and take TAKE_ROOMS of
	 * $room into the queue once half of it, or all, is free.
	 *
	 * @return bool Whether the take moved any.
	 */
	private function stage( int $room ): bool {
		if ( [] !== $this->adds && ! $this->frontier->add( $this->adds ) ) {
			$this->print_less_often( 'the Table took none of the requests queued; sending none of them: ', $this->sibling_name( self::FRONTIER ) );
		}
		$this->adds = [];
		$taken      = [];
		if ( [] === $this->in_flight || 2 * ( $room - \count( $this->in_flight ) ) >= $room ) {
			$taken = $this->frontier->take( self::TAKE_ROOMS * $room );
		}
		foreach ( $taken as [ $subject, $value ] ) {
			[ $url, $body, $hops, $note ] = Core::arr( $value );
			$this->queue[ $subject ]      = [ Core::as_string( $url ), \is_string( $body ) ? $body : null, Core::as_int( $hops ), Core::arr( $note ) ];
		}
		return [] !== $taken;
	}

	/** Requests in flight at once: 1, so an API source's go strictly one after another. */
	protected function max_in_flight(): int {
		return 1;
	}

	/** A url's host, lowercased; '' for none. */
	protected static function host( string $url ): string {
		return \strtolower( Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) );
	}

	/** Requests a pass sends in all. */
	protected function max_requests(): int {
		return self::MAX_REQUESTS_PER_PASS;
	}

	/**
	 * Send one request to Curl as a TM_STRUCT, KEY its subject, with the fields
	 * request_options() names; one Curl throws on leaves flight.
	 *
	 * @param string  $key     The request's subject.
	 * @param Request $request The hop's url, a POST's body or null, its hops and its note.
	 */
	private function dispatch( string $key, array $request ): void {
		[ $url, $body ] = $request;
		$value          = [
			'url'    => $url,
			'method' => null === $body ? 'GET' : 'POST',
		];
		if ( null !== $body ) {
			$value['body'] = $body;
		}
		$value = [ ...$value, ...$this->request_options( $key ), 'public_only' => $this->public_only() ];
		try {
			$this->send_curl( $key, $value, $request );
		} catch ( \Throwable $e ) {
			$this->abandon( $key );
			$this->abandoned( $key, $request[3] );
			throw $e;
		}
	}

	/** Whether every request reaches public addresses alone; a source through a tunnel answers false. */
	protected function public_only(): bool {
		return true;
	}

	/**
	 * Re-queue a redirected plain GET under its subject at the Location, one
	 * hop on, failing one past Curl_Node::MAX_REDIRECTS or carrying headers,
	 * a body or another method past Curl's own redirect guard.
	 *
	 * @param string  $key      The request's subject.
	 * @param Request $request  The request redirected.
	 * @param string  $location The absolute Location.
	 * @return bool Whether it re-queued the request.
	 */
	private function redirect( string $key, array $request, string $location ): bool {
		[ , $body, $hops, $note ] = $request;
		$options                  = $this->request_options( $key );
		if ( null !== $body || [] !== Core::arr( $options['headers'] ?? null ) || 'GET' !== ( $options['method'] ?? 'GET' ) ) {
			$this->fails( 'a redirect of a request carrying its own headers or a method other than GET is not followed; recording nothing: ', $location );
			return false;
		}
		if ( $hops >= Curl_Node::MAX_REDIRECTS ) {
			$this->fails( 'a request redirected past ' . Curl_Node::MAX_REDIRECTS . ' hops; recording nothing: ', $location );
			return false;
		}
		$this->adds[ $key ] = [ $location, $body, $hops + 1, $note ];
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
	 * Emit one `item_recorded` event per item neither seen nor held by the
	 * projection, by key or as a copy (copy_of()), remembering each but one
	 * make() refuses, so that one retries; a key past
	 * Graph_Curation::ITEM_KEY_BYTES is logged and refused once a process.
	 * An item dated after its retrieval is dated at its retrieval, because
	 * it cannot have happened after it was read: a clock or a zone the
	 * producer got wrong would otherwise date it in a bucket that every read leaves out.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 */
	protected function record( array $items ): void {
		$held = $this->held( $items );
		foreach ( $items as $item ) {
			$key  = Core::as_string( $item['item_key'] );
			$copy = $this->copy_of( $item );
			if ( isset( $this->seen[ $key ] ) || isset( $this->seen[ $copy ] ) ) {
				continue;
			}
			if ( isset( $held[ $key ] ) || isset( $held[ $copy ] ) ) {
				$this->remember( $copy );
				continue;
			}
			$bytes = Graph_Curation::written_bytes( $key );
			if ( $bytes > Graph_Curation::ITEM_KEY_BYTES ) {
				$this->print_less_often( 'refused an item whose key runs ', "{$bytes} bytes, past " . Graph_Curation::ITEM_KEY_BYTES . ': ' . Core::terminal_safe( \mb_strcut( $key, 0, self::KEY_SHOWN_BYTES ) ) );
				$this->remember( $key );
				continue;
			}
			if ( \is_int( $item['item_ts'] ?? null ) && \is_int( $item['retrieved_ts'] ?? null ) && $item['item_ts'] > $item['retrieved_ts'] ) {
				$item['item_ts'] = $item['retrieved_ts'];
			}
			// Unlike a size refusal, a make() refusal is not kept: it retries.
			if ( $this->write( 'item_recorded', $item, $key ) ) {
				$this->remember( $copy );
			}
		}
	}

	/**
	 * Send one event this source mints, as the stage `source:<name>`, to its
	 * target; one make() refuses is logged naming its subject, and not sent.
	 *
	 * @param string              $kind    The event kind.
	 * @param array<string,mixed> $fields  Its fields.
	 * @param string              $subject What the refusal names: an item key, an entity.
	 * @return bool Whether it was sent.
	 */
	protected function write( string $kind, array $fields, string $subject ): bool {
		try {
			$value = Intel_Event::machine_event( "source:{$this->name}", $kind, $fields );
		} catch ( \InvalidArgumentException $invalid ) {
			$this->print_less_often( "refused {$kind}: ", Core::terminal_safe( "{$subject}: {$invalid->getMessage()}" ) );
			return false;
		}
		$this->emit( '', $value );
		return true;
	}

	/**
	 * Of the items not yet seen, the keys the projection's items hold in any
	 * partition, and the copies, by copy(), its items of the same source
	 * hold at the same canonical URL, in one read; none when every item is
	 * seen or the projection will not open, which is logged.
	 *
	 * @param array<int,array<string,mixed>> $items Normalized items.
	 * @return array<string,true> Each held key and copy.
	 */
	private function held( array $items ): array {
		$keys = [];
		$urls = [];
		foreach ( $items as $item ) {
			$key  = Core::as_string( $item['item_key'] );
			$copy = $this->copy_of( $item );
			if ( isset( $this->seen[ $key ] ) || isset( $this->seen[ $copy ] ) ) {
				continue;
			}
			$keys[] = $key;
			if ( $copy !== $key ) {
				$urls[ Core::as_string( $item['source'] ) ][] = Url_Canon::of( Core::as_string( $item['url'] ) );
			}
		}
		if ( [] === $keys ) {
			return [];
		}
		$where = 'item_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' )';
		$binds = $keys;
		foreach ( $urls as $source => $canonicals ) {
			$where .= ' OR ( source = ? AND url_canonical IN ( ' . Intel_Graph_Row_Schema::marks( $canonicals ) . ' ) )';
			$binds  = [ ...$binds, $source, ...$canonicals ];
		}
		$rows = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => $r->rows( 'SELECT item_key, url_canonical FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' ) . " i WHERE {$where}", $binds ),
			"{$this->name}: no intel-graph to read recorded items from; recording every item fetched: "
		) ?? [];
		$held = [];
		foreach ( $rows as [ $key, $url ] ) {
			$key          = Core::as_string( $key );
			$held[ $key ] = true;
			$raw          = $this->raw_id( $key );
			if ( null !== $raw ) {
				$held[ self::copy( $raw, Core::as_string( $url ) ) ] = true;
			}
		}
		return $held;
	}

	/**
	 * What an item is remembered and matched by: as copy() spells its raw id
	 * and canonical URL, which every copy of it shares, when its source names
	 * a raw id and its URL names a host; else its key. A source's own copies
	 * alone meet, since held() reads the projection's by source and a
	 * node records one source.
	 *
	 * @param array<string,mixed> $item A normalized item.
	 */
	private function copy_of( array $item ): string {
		$key = Core::as_string( $item['item_key'] );
		$raw = $this->raw_id( $key );
		if ( null === $raw ) {
			return $key;
		}
		$url = Core::as_string( $item['url'] );
		return '' === Core::as_string( \wp_parse_url( $url, \PHP_URL_HOST ) ) ? $key : self::copy( $raw, Url_Canon::of( $url ) );
	}

	/**
	 * The id a key carries past its source and any lane, which a copy of its
	 * item listed in another lane carries too; null for a source that judges
	 * each item by its key alone, as one whose items share a canonical URL
	 * must, a P2 post's comments sharing its own.
	 *
	 * @param string $key An item key.
	 */
	protected function raw_id( string $key ): ?string {
		return null;
	}

	/** A copy's spelling: raw id and canonical URL, joined by NUL, which XML cannot carry and so no feed key holds. */
	private static function copy( string $raw, string $url ): string {
		return "{$raw}\0{$url}";
	}

	/** Record a key or a copy, evicting the oldest once the set exceeds MAX_SEEN. */
	private function remember( string $key ): void {
		$this->seen[ $key ] = true;
		if ( \count( $this->seen ) > self::MAX_SEEN ) {
			unset( $this->seen[ \array_key_first( $this->seen ) ] );
		}
	}

	/**
	 * Have Curl follow redirects as follows_redirects() says.
	 *
	 * @param Curl_Node $curl The sibling.
	 */
	protected function configure_curl( Curl_Node $curl ): void {
		$curl->follow_redirects( $this->follows_redirects() );
	}

	/** Whether Curl follows a redirect itself, or answers it for this source to re-queue. */
	protected function follows_redirects(): bool {
		return true;
	}

	/** End the pass: drop every request it has yet to send; those in flight still answer. */
	protected function end_pass(): void {
		$this->drop_queue();
	}

	/**
	 * Drop every request not in flight, each handed to abandoned() with its
	 * note, since it will not answer: those queued since the last send, and
	 * every one the frontier holds, taken first because `drain()` answers no
	 * values.
	 */
	private function drop_queue(): void {
		$notes = \array_map( static fn ( array $request ): array => $request[3], $this->queue + $this->adds );
		do {
			$page = $this->frontier->take( Table_Node::MAX_MEMBERS_LIMIT );
			foreach ( $page as [ $subject, $value ] ) {
				$notes[ $subject ] ??= Core::arr( Core::arr( $value )[3] ?? null );
			}
		} while ( Table_Node::MAX_MEMBERS_LIMIT === \count( $page ) );
		$dropped     = [ ...\array_keys( $this->adds ), ...\array_diff( $this->frontier->drain(), \array_keys( $this->in_flight ) ) ];
		$this->queue = [];
		$this->adds  = [];
		foreach ( \array_unique( \array_map( 'strval', $dropped ) ) as $subject ) {
			$this->abandoned( $subject, $notes[ $subject ] ?? [] );
		}
	}

	/**
	 * Take a request that will never answer; nothing unless a source holds
	 * something for it.
	 *
	 * @param string                 $subject The request's subject.
	 * @param array<array-key,mixed> $note    What it was queued with.
	 */
	protected function abandoned( string $subject, array $note ): void {
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
	 * Publish a status of every key, those $fields leaves out null, beside
	 * the facts the source adds to every status.
	 *
	 * @param array<string,mixed> $fields The keys the state carries.
	 */
	private function publish_snapshot( array $fields ): void {
		$this->publish_status( [ ...self::BLANK_STATUS, ...$this->status_facts(), ...$fields ] );
	}

	/**
	 * The keys a source adds to every status it publishes, whatever its
	 * state; none unless a source reports more.
	 *
	 * @return array<string,mixed>
	 */
	protected function status_facts(): array {
		return [];
	}

	/**
	 * A JSON object or list, decoded, when $error finds no error in it; null,
	 * logged under the url and failed, for any other body.
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
	 * Log, under $prefix, and count as failed the answer being taken, which a
	 * source drops whole; from a begin() queueing nothing, it fails the pass.
	 *
	 * @param string $prefix What failed, naming the request's url, terminal-safe.
	 * @param string $detail Why, made one line here (status_text()).
	 */
	protected function fails( string $prefix, string $detail ): void {
		$detail = self::status_text( $detail );
		$this->print_less_often( $prefix, $detail );
		$this->fault = $prefix . $detail;
	}

	/** Emit the base config, each declared setter's line and one add line per list or checked list entry. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters() . $this->dump_lists() . $this->dump_checked();
	}

	/** One add line per checked list entry, in order, a flagged entry's carrying its `--<flag>`. */
	private function dump_checked(): string {
		$out = '';
		foreach ( Command_Interpreter_Node::declared_commands( static::class ) as $name => $command ) {
			$property = Core::as_string( $command['checked'] ?? '' );
			if ( '' === $property ) {
				continue;
			}
			foreach ( Core::arr( $this->{$property} ) as $entry => $flagged ) {
				$out .= true === $flagged ? $this->config_line( $name, (string) $entry, '--' . Core::as_string( Core::arr( $command['flag'] )['name'] ) ) : $this->config_line( $name, (string) $entry );
			}
		}
		return $out;
	}

	/**
	 * The url the answer being taken was fetched from: its request's, or the
	 * last Location a redirect of it named.
	 */
	protected function hop(): string {
		return $this->answering[0];
	}

	/**
	 * The note the answer being taken's request was queued with.
	 *
	 * @return array<array-key,mixed>
	 */
	protected function note(): array {
		return $this->answering[3];
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
	 * Queue a GET, or with a body a POST of it as JSON, under its subject, the
	 * url unless one is named, with a note note() reads back; a subject queued
	 * again keeps the later.
	 *
	 * @param string                 $url     The url.
	 * @param string|null            $body    A POST's body, or null for a GET.
	 * @param string|null            $subject What the request asks, when several share one url.
	 * @param array<array-key,mixed> $note    What the source reads with its answer.
	 */
	protected function queue( string $url, ?string $body = null, ?string $subject = null, array $note = [] ): void {
		$this->adds[ $subject ?? $url ] = [ $url, $body, 0, $note ];
	}

	/**
	 * The `set_interval` command's entry point: fetch on the wall-clock grid
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
	 * Each row mapped to its item, a row the map answers null for left out;
	 * a row that is not an array reaches the map as an empty one.
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
	 * One connector record as the `item_recorded` fields, each coerced here,
	 * recorded under the class's SOURCE and keyed `"<source>:$id"`, so `$id`
	 * must be stable per item, as dedup keys on it.
	 *
	 * @param mixed $title
	 * @param mixed $url
	 * @param mixed $body  Cut to MAX_BODY_BYTES on a UTF-8 character boundary.
	 * @param mixed $when  Date string (ISO-8601 / RFC-822); '' / unparseable → item_ts 0.
	 * @return array<string,mixed>
	 */
	protected function normalize_item( string $id, mixed $title, mixed $url, mixed $body, mixed $when ): array {
		$ts = \is_string( $when ) ? \strtotime( $when ) : false;
		return [
			'item_key'     => static::SOURCE . ":{$id}",
			'source'       => static::SOURCE,
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
	 * `set_tier` its config is sent, bound by that command's own args, else its
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
				$tier = Core::as_string( Command_Args::bind( Core::arr( Command_Interpreter_Node::declared_commands( static::class )['set_tier']['args'] ), $tokens )['tier'] );
			}
		}
		return [ 'tier' => $tier ];
	}

	/**
	 * Whether a source its `make_node` line configures can collect on this
	 * host; one the host limits answers false where it cannot.
	 *
	 * @api Read by Worker_Route::candidates().
	 * @param array<string,mixed> $args The line's arguments, bound by this class's node_schema().
	 */
	public static function runs_here( array $args ): bool {
		return true;
	}

	/**
	 * A connector's node_schema from the shared Source shape: category Source,
	 * the frontier Table it owns, `tier` and, for a source sending a
	 * credential, `vault_group`, one TICK request, and `set_tier` and
	 * `set_interval` ahead of the connector's own commands.
	 *
	 * @param list<array<string,mixed>> $commands     The connector's own commands.
	 * @param bool                      $credentialed Whether the source takes a vault group.
	 * @return array<string,mixed>
	 */
	protected static function source_schema( string $description, string $tick_description, array $commands = [], bool $credentialed = true ): array {
		$arguments = [ self::TIER_ARGUMENT ];
		if ( $credentialed ) {
			$arguments[] = [ 'name' => 'vault_group', 'type' => 'vault_group', 'default' => '', 'description' => 'Optional Vault group: a request to one entry\'s origin carries the credential header that entry names.' ];
		}
		return \array_merge( Node::node_schema(), [
			'category'    => 'Source',
			'description' => $description,
			// The Table owned_table() declares, which the analyzer reads.
			'owns'        => [ self::FRONTIER => 'Table' ],
			'arguments'   => $arguments,
			'requests'    => [
				[
					'name'        => 'TICK',
					'description' => $tick_description,
					'reply_shape' => '{ fetching }',
					// A TICK waits, armed, for the ends its streams hold now.
					'handler'     => static function ( self $node ): array {
						$node->awaiting = [];
						return $node->tick();
					},
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
	 * list_commands()'s `add_<noun>` and `remove_<noun>` over a map from each
	 * entry, in the order first added, to whether it carries $flag, every
	 * entry matching $pattern: the add refusing a value that does not, naming
	 * it as not $kind, the remove refusing one the map does not hold, both
	 * reading the value as given, and dumped one add line per entry. A $flag
	 * adds the bool `--<name>` to the add, which sets the entry's flag, an add
	 * again replacing it in place; with none every entry maps to false.
	 *
	 * @param string                                  $noun        The commands' suffix, and the one arg they take.
	 * @param string                                  $property    The map property, an `array<string,bool>`.
	 * @param string                                  $pattern     The regex every entry matches.
	 * @param string                                  $kind        What an entry is, as a refusal names it.
	 * @param string                                  $description The add command's description.
	 * @param array{name:string,description:string}|null $flag        The add's flag, or null for none.
	 * @return list<array<string,mixed>>
	 */
	protected static function checked_list( string $noun, string $property, string $pattern, string $kind, string $description, ?array $flag = null ): array {
		$arg              = [ 'name' => $noun, 'type' => 'string', 'required' => true ];
		[ $add, $remove ] = self::list_commands( $noun, $property, $arg, $description );
		unset( $add['list'], $remove['unlist'] );
		$add['checked'] = $property;
		$add['handler'] = static function ( Command_Interpreter_Node $interpreter, array $args ) use ( $noun, $property, $pattern, $kind, $flag ): string {
			$value = Core::as_string( $args[ $noun ] );
			if ( 1 !== \preg_match( $pattern, $value ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
				throw new \InvalidArgumentException( "add_{$noun}: '{$value}' is not {$kind}" );
			}
			$source             = $interpreter->patron();
			$entries            = Core::arr( $source->{$property} );
			$entries[ $value ]  = null !== $flag && true === $args[ $flag['name'] ];
			$source->{$property} = $entries;
			return "ok\n";
		};
		$remove['handler'] = static function ( Command_Interpreter_Node $interpreter, array $args ) use ( $noun, $property ): string {
			$value   = Core::as_string( $args[ $noun ] );
			$source  = $interpreter->patron();
			$entries = Core::arr( $source->{$property} );
			if ( ! \array_key_exists( $value, $entries ) ) {
				throw Command_Args::undeclared( "remove_{$noun}", [ $value ], $noun, 'node' );
			}
			unset( $entries[ $value ] );
			$source->{$property} = $entries;
			return "ok\n";
		};
		if ( null !== $flag ) {
			$add['args'] = [ $arg, [ 'name' => $flag['name'], 'type' => 'bool', 'default' => false, 'description' => $flag['description'] ] ];
			$add['flag'] = $flag;
		}
		return [ $add, $remove ];
	}
}

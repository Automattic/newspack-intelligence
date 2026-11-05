<?php
/**
 * LLM_Call_Node: the model calls of every node that asks one, made without
 * blocking the worker.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Frontier;
use Newspack_Nodes\Message;
use Newspack_Nodes\Publishes_Status;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Shutdown_Sweeper;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * `make_node LLM_Call <name> <vault_group>`. A request is a TM_STRUCT whose
 * KEY names its subject and whose VALUE is `{ prompt, item, inputs,
 * options, carry, backlog }`, `prompt` one of `Prompts::PROMPTS` and
 * `options` only `max_tokens` and `temperature`, none for a decision; one
 * it cannot read is refused TO its FROM. It waits in the `{name}:frontier`
 * Table it owns, a backlog request behind every other and each in the
 * order it arrived, holding its item once, until its `{name}:curl` sibling
 * POSTs it under the vault group, at most `set_concurrency` at once: a chat
 * prompt as `{ model, messages, ...options }` to
 * `<api_url>/chat/completions`, the messages `Prompts::messages()` builds
 * as it is sent, and a decision prompt as `{ model, state, questions }` to
 * `<api_url>/systemone`, the decision model's, as `Prompts::decision()`
 * builds it. The answer goes TO the request's FROM, keyed as asked, a
 * TM_STRUCT|TM_RESPONSE whose VALUE is `{ content, item, inputs, carry }`
 * for a chat prompt, `{ decision: { <question>: <noul> | { choice,
 * confidence } }, … }` for a decision, `{ error, … }`, or, when no entry
 * of the group lends the api url's origin a credential, `{ unlent, … }` at
 * once, `unlent` the group. A stored request that will not send is settled and answered as an error. A
 * request in flight when the worker stops is sent again by the next one; a
 * clean stop sends none it answered again.
 */
final class LLM_Call_Node extends Timer_Node implements Shutdown_Sweeper {
	use Schema_Reflection;

	/** @use Owns_Curl_Frontier<list<mixed>> */
	use Owns_Curl_Frontier;

	use Publishes_Status;

	/**
	 * Largest request body Curl sends, its 1 MiB ceiling: an extraction
	 * request carries a 65,536-byte item body JSON-encoded twice, about 460 KB.
	 */
	private const BODY_CAP = 1048576;

	/** A request's member prefix: new work, which the Table hands out first. */
	private const NEW_WORK = '0';

	/** A request's member prefix: backlog, behind every new request. */
	private const BACKLOG = '1';

	/**
	 * Seconds after which an unmoving queue is counted and written again, so
	 * a reader can tell a live worker's snapshot from a stopped one's.
	 */
	public const STATUS_HEARTBEAT_S = 300;

	/**
	 * Least seconds between two publishes of a moving queue: the strip reads
	 * it only on a load or a Refresh, so five seconds stale is current, and
	 * a busy queue costs two counts and a cache write per five seconds.
	 */
	private const QUEUE_PUBLISH_S = 5;

	/** The error a body naming no assistant content answers. */
	private const NO_CONTENT = 'AI proxy answer names no choices[0].message.content';

	/** What a decision answer that does not read names no more of, by its question's type. */
	private const UNREAD = [
		'noul'   => 'no noul from 0 to 1',
		'choice' => 'no offered choice with a confidence from 0 to 1',
	];

	/** Each kind of call: the path it posts to under the api url, and the refusal of one asked before it is configured. */
	private const CALLS = [
		Prompts::CHAT     => [ 'chat/completions', 'set_api_url, set_model and set_feature before a call' ],
		Prompts::DECISION => [ 'systemone', 'set_api_url, set_decision_model and set_feature before a decision' ],
	];

	/** The AI API Proxy base url, set via set_api_url; '' until set. */
	protected string $api_url = '';

	/** The model id, set via set_model; '' until set. */
	protected string $model = '';

	/** The decision model id, set via set_decision_model; '' until set. */
	protected string $decision_model = '';

	/** The X-WPCOM-AI-Feature value, set via set_feature; '' until set. */
	protected string $feature = '';

	/** Most calls in flight at once, 1 to `Curl_Node::MAX_IN_FLIGHT`. */
	protected int $concurrency = 1;

	/** True while pump() runs; an answer arriving inside it does not re-enter. */
	private bool $pumping = false;

	/** The microsecond the last request arrived at, at least one past the one before. */
	private int $arrived = 0;

	/** Whether a request, take or settle came since the last publish landed; a new process owes one. */
	private bool $queue_moved = true;

	/** Wall-second the queue was last counted; 0 before the first. */
	private int $queue_counted_at = 0;

	/**
	 * `<vault_group>`: Curl built again under a new group, dropping what it
	 * had in flight for the frontier to send again, the frontier Table built
	 * once where the worker's partition is bound, and the Router's tick ridden.
	 *
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 * @throws \InvalidArgumentException With no group.
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return parent::arguments();
		}
		$group = $this->vault_group;
		$this->parse_schema_args( $args );
		$this->adopt_group( $group );
		if ( ! $this->timer_is_active() ) {
			$this->set_timer();
		}
		return $this->arguments;
	}

	/**
	 * A Table's reply goes to the client, a TM_STRUCT to `request()`, and
	 * anything else is Curl's answer.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		if ( $this->tables->accepts( $message ) ) {
			return;
		}
		$type = Core::int( $message[ Message::TYPE ] );
		if ( Message::TM_STRUCT === ( $type & ( Message::TM_STRUCT | Message::TM_ERROR | Message::TM_RESPONSE ) ) ) {
			$this->request( $message );
			return;
		}
		$this->answer( $message );
	}

	/**
	 * The Router's tick sends what the frontier holds, the first one after a
	 * restart what the stopped process left in flight, then publishes the
	 * queue.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$this->pump();
		$this->publish_queue();
	}

	/**
	 * Publish `{ queue, at }`: the calls waiting under each priority, as the
	 * Table counts them, and those in flight, or null when a count is not
	 * answered, and the second it was written. A moving queue publishes at
	 * most every QUEUE_PUBLISH_S, an unmoving one every STATUS_HEARTBEAT_S,
	 * and a clock stepped back counts as either having passed. A change is
	 * owed until a counted snapshot lands; a throw is logged and tried
	 * again at the next tick, never thrown into the loop, but for a
	 * cooperative stop (ADR-14).
	 *
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function publish_queue(): void {
		$now     = (int) Core::$now;
		$elapsed = $now - $this->queue_counted_at;
		if ( 0 <= $elapsed && $elapsed < ( $this->queue_moved ? self::QUEUE_PUBLISH_S : self::STATUS_HEARTBEAT_S ) ) {
			return;
		}
		try {
			$queue  = $this->queue_counts();
			$landed = $this->publish_status(
				[
					'queue' => $queue,
					'at'    => $now,
				]
			);
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'publishing the queue failed; trying again at the next tick: ', Core::terminal_safe( $e->getMessage() ) );
			return;
		}
		$this->queue_counted_at = $now;
		$this->queue_moved      = ! $landed || null === $queue;
	}

	/**
	 * The calls waiting under each priority, as the Table counts them, and
	 * those this process has in flight; null when either count is not
	 * answered.
	 *
	 * @return array{new:int,backlog:int,in_flight:int}|null
	 */
	private function queue_counts(): ?array {
		$new     = $this->frontier->count( Frontier::PENDING, self::NEW_WORK . '/' );
		$backlog = $this->frontier->count( Frontier::PENDING, self::BACKLOG . '/' );
		if ( null === $new || null === $backlog ) {
			return null;
		}
		return [
			'new'       => $new,
			'backlog'   => $backlog,
			'in_flight' => \count( $this->in_flight ),
		];
	}

	/**
	 * Queue a request and send what the window has room for; answer at once
	 * one that cannot be sent, and refuse one it cannot read TO its FROM,
	 * with whatever of its item, inputs and carry reads, so the caller's own
	 * finish runs; only one naming no FROM is logged and dropped.
	 *
	 * @param array<int,mixed> $message The request.
	 */
	private function request( array $message ): void {
		$from    = Core::as_string( $message[ Message::FROM ] );
		$key     = Core::as_string( $message[ Message::KEY ] );
		$request = Core::arr( $message[ Message::VALUE ] );
		$lacks   = self::lacks( $request );
		if ( null !== $lacks ) {
			if ( '' === $from ) {
				$this->print_less_often( 'dropping a request from no caller: ', Core::terminal_safe( $key ) );
				return;
			}
			$readable = \array_filter( [ 'item' => $request['item'] ?? null, 'inputs' => $request['inputs'] ?? null ], \is_array( ... ) ) + \array_intersect_key( $request, [ 'carry' => true ] );
			$this->answer_to( $from, $key, [ 'error' => "{$this->name}: a request it cannot read: it carries no {$lacks}" ] + $readable );
			return;
		}
		$value = [ $from, $key, $request['prompt'], $request['item'], $request['inputs'], $request['options'], $request['carry'] ];
		$kind  = Prompts::PROMPTS[ Core::as_string( $request['prompt'] ) ];
		if ( '' === $this->api_url || '' === $this->model_for( $kind ) || '' === $this->feature ) {
			$this->reply( $value, [ 'error' => "{$this->name}: " . self::CALLS[ $kind ][1] ] );
			return;
		}
		if ( ! Curl_Node::lends_credential( $this->vault_group, $this->api_url ) ) {
			$this->reply( $value, [ 'unlent' => $this->vault_group ] );
			return;
		}
		$member = ( $request['backlog'] ? self::BACKLOG : self::NEW_WORK ) . '/' . $this->arrival() . '/' . \rawurlencode( $from ) . '/' . \rawurlencode( $key );
		if ( ! $this->frontier->add( [ $member => $value ] ) ) {
			$this->reply( $value, [ 'error' => 'the Table took no request: ' . $this->sibling_name( self::FRONTIER ) ] );
			return;
		}
		$this->queue_moved = true;
		$this->pump();
	}

	/**
	 * What a request lacks, as its refusal names it; null for one it reads:
	 * a prompt `Prompts::PROMPTS` names, an item and inputs as maps, options
	 * `allowed()`, none for a decision, a carry and a backlog flag.
	 *
	 * @param array<array-key,mixed> $request The request's VALUE.
	 */
	private static function lacks( array $request ): ?string {
		$prompt = $request['prompt'] ?? null;
		return match ( true ) {
			! \is_string( $prompt ) || ! isset( Prompts::PROMPTS[ $prompt ] ) => 'a prompt it knows',
			! \is_array( $request['item'] ?? null ) => 'an item',
			! \is_array( $request['inputs'] ?? null ) => 'inputs',
			! self::allowed( $request['options'] ?? null ) => 'options of only an integer max_tokens and a numeric temperature',
			Prompts::DECISION === Prompts::PROMPTS[ $prompt ] && [] !== $request['options'] => 'empty options, as a decision takes none',
			! \array_key_exists( 'carry', $request ) => 'a carry',
			! \is_bool( $request['backlog'] ?? null ) => 'a backlog flag',
			default => null,
		};
	}

	/**
	 * Whether a request's options set nothing but a whole `max_tokens` and a
	 * numeric `temperature`, so a caller cannot set what the node owns.
	 *
	 * @param mixed $options The request's options.
	 */
	private static function allowed( mixed $options ): bool {
		if ( ! \is_array( $options ) ) {
			return false;
		}
		foreach ( $options as $name => $value ) {
			$typed = match ( $name ) {
				'max_tokens'  => \is_int( $value ),
				'temperature' => \is_int( $value ) || \is_float( $value ),
				default       => false,
			};
			if ( ! $typed ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A request's place in its priority: the microsecond it arrived, one past
	 * the last when the clock has not moved on, zero-padded so the Table's
	 * lowest-first order is arrival order, and later than any earlier
	 * process's while the clock does not step back.
	 */
	private function arrival(): string {
		$this->arrived = \max( (int) \round( Core::$now * 1000000 ), $this->arrived + 1 );
		return \sprintf( '%016d', $this->arrived );
	}

	/**
	 * Settle Curl's answer and send its outcome to the request's caller; a
	 * caller that throws on it is logged, never thrown into the event loop,
	 * and the window refills, but a cooperative stop propagates (ADR-14) and
	 * sends nothing more.
	 *
	 * @param array<int,mixed> $message The body as TM_BYTESTREAM, or the TM_ERROR, KEY the member.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answer( array $message ): void {
		$value = $this->settled( $message );
		if ( null === $value ) {
			return;
		}
		$this->queue_moved = true;
		$this->deliver( $value, self::outcome( $message, Core::as_string( $value[2] ?? null ), Core::arr( $value[4] ?? null ) ) );
		$this->pump();
	}

	/**
	 * Inside a running event loop, send as many requests as the window
	 * leaves room for, once half of it or all of it is free, taking again
	 * while a send freed the room it took; a take that throws is logged and
	 * left to the next tick, never thrown into the loop, but for a
	 * cooperative stop (ADR-14).
	 *
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function pump(): void {
		if ( $this->pumping || ! Event_Framework::instance()->is_running() ) {
			return;
		}
		$this->pumping = true;
		try {
			do {
				$free = $this->concurrency - \count( $this->in_flight );
				if ( [] !== $this->in_flight && 2 * $free < $this->concurrency ) {
					return;
				}
				$taken              = $this->frontier->take( $free );
				$this->queue_moved = $this->queue_moved || [] !== $taken;
				foreach ( $taken as [ $member, $value ] ) {
					$this->dispatch( $member, \array_values( Core::arr( $value ) ) );
				}
			} while ( [] !== $taken );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'a send failed; trying again at the next tick: ', Core::terminal_safe( $e->getMessage() ) );
		} finally {
			$this->pumping = false;
		}
	}

	/**
	 * Send one stored request; one that will not send, its prompt unknown,
	 * its body unencodable or Curl throwing on it, is settled and answered
	 * as an error, so no member stays in flight, but for a cooperative stop.
	 *
	 * @param string      $member The frontier member.
	 * @param list<mixed> $value  [ from, key, prompt, item, inputs, options, carry ].
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function dispatch( string $member, array $value ): void {
		try {
			$this->post( $member, $value );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->abandon( $member );
			$this->deliver( $value, [ 'error' => "{$this->name}: a stored request could not be sent: " . $e->getMessage() ] );
		}
	}

	/**
	 * Build one request's body as the kind of its prompt says, a chat
	 * prompt's messages or a decision's state and questions, and hand Curl
	 * the POST, KEY its member, so its answer names it; a prompt no kind
	 * names is refused as a chat prompt is.
	 *
	 * @param string      $member The frontier member.
	 * @param list<mixed> $value  [ from, key, prompt, item, inputs, options, carry ].
	 * @throws \InvalidArgumentException When the prompt is unknown.
	 * @throws \UnexpectedValueException When the body will not encode.
	 * @throws Worker_Should_Stop When Curl's refusal, answered at once, meets a stop.
	 */
	private function post( string $member, array $value ): void {
		$prompt = Core::as_string( $value[2] ?? null );
		$kind   = Prompts::PROMPTS[ $prompt ] ?? Prompts::CHAT;
		$item   = Core::arr( $value[3] ?? null );
		$inputs = Core::arr( $value[4] ?? null );
		$fields = Prompts::DECISION === $kind ? Prompts::decision( $prompt, $item, $inputs ) : [ 'messages' => Prompts::messages( $prompt, $item, $inputs ) ] + Core::arr( $value[5] ?? null );
		$body   = \wp_json_encode( [ 'model' => $this->model_for( $kind ) ] + $fields );
		if ( false === $body ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \UnexpectedValueException( 'the request body could not be encoded: ' . \json_last_error_msg() );
		}
		$request = [
			'url'     => "{$this->api_url}/" . self::CALLS[ $kind ][0],
			'method'  => 'POST',
			'body'    => $body,
			'headers' => [ "X-WPCOM-AI-Feature: {$this->feature}" ],
			'timeout' => Curl_Node::MAX_REQUEST_TIMEOUT,
		];
		$this->send_curl( $member, $request, $value );
	}

	/**
	 * The model a kind of call names: the decision model for a decision,
	 * else the chat model.
	 *
	 * @param string $kind A kind of call, as `Prompts::PROMPTS` names one.
	 */
	private function model_for( string $kind ): string {
		return Prompts::DECISION === $kind ? $this->decision_model : $this->model;
	}

	/**
	 * Answer a settled request from inside the event loop: a caller that
	 * throws on it is logged, never thrown into the loop, but for a
	 * cooperative stop (ADR-14).
	 *
	 * @param list<mixed>         $value   The request's stored value.
	 * @param array<string,mixed> $outcome `content`, `decision` or `error`.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function deliver( array $value, array $outcome ): void {
		try {
			$this->reply( $value, $outcome );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'answering ' . Core::terminal_safe( Core::as_string( $value[1] ?? '' ) ) . ' failed: ', Core::terminal_safe( $e->getMessage() ) );
		}
	}

	/**
	 * What Curl's answer says: its TM_ERROR text, else, for a decision, the
	 * answer to each question its inputs asked, else the content of the
	 * body's first choice, else that it names none.
	 *
	 * @param array<int,mixed>       $message Curl's answer.
	 * @param string                 $prompt  The prompt the request named.
	 * @param array<array-key,mixed> $inputs  The request's inputs.
	 * @return array{error:string}|array{content:string}|array{decision:array<string,float|array{choice:string,confidence:float}>}
	 */
	private static function outcome( array $message, string $prompt, array $inputs ): array {
		$value = Core::as_string( $message[ Message::VALUE ] );
		if ( 0 !== ( Core::int( $message[ Message::TYPE ] ) & Message::TM_ERROR ) ) {
			return [ 'error' => $value ];
		}
		$decoded = \json_decode( $value, true );
		if ( Prompts::DECISION === ( Prompts::PROMPTS[ $prompt ] ?? null ) ) {
			return self::decision_of( $decoded, Prompts::questions( $inputs ) );
		}
		$choices = \is_array( $decoded ) ? ( $decoded['choices'] ?? null ) : null;
		$choice  = \is_array( $choices ) ? ( $choices[0] ?? null ) : null;
		$said    = \is_array( $choice ) ? ( $choice['message'] ?? null ) : null;
		$content = \is_array( $said ) ? ( $said['content'] ?? null ) : null;
		return \is_string( $content ) ? [ 'content' => $content ] : [ 'error' => self::NO_CONTENT ];
	}

	/**
	 * A decision's answer: each question asked => what the body names for it
	 * under `answers`, a noul's probability, or a choice's key among its
	 * criteria with its confidence; or the error naming the first question
	 * whose answer is missing, out of range or not of its type.
	 *
	 * @param mixed                                                    $decoded   The decoded body.
	 * @param array<string,array{type:string,criteria?:array<string,string>}> $questions The questions asked.
	 * @return array{error:string}|array{decision:array<string,float|array{choice:string,confidence:float}>}
	 */
	private static function decision_of( mixed $decoded, array $questions ): array {
		$answers  = Core::arr( \is_array( $decoded ) ? ( $decoded['answers'] ?? null ) : null );
		$decision = [];
		foreach ( $questions as $name => $question ) {
			$answer     = Core::arr( $answers[ $name ] ?? null );
			$choice     = $answer['choice'] ?? null;
			$confidence = self::unit( $answer['confidence'] ?? null );
			$read       = 'choice' === $question['type']
				? ( \is_string( $choice ) && isset( $question['criteria'][ $choice ] ) && null !== $confidence ? [ 'choice' => $choice, 'confidence' => $confidence ] : null )
				: self::unit( $answer['noul'] ?? null );
			if ( null === $read ) {
				return [ 'error' => 'AI proxy decision names ' . self::UNREAD[ $question['type'] ] . " for {$name}" ];
			}
			$decision[ $name ] = $read;
		}
		return [ 'decision' => $decision ];
	}

	/**
	 * A number from 0 to 1 as a float; null for anything else.
	 *
	 * @param mixed $value The value an answer names.
	 */
	private static function unit( mixed $value ): ?float {
		return ( \is_int( $value ) || \is_float( $value ) ) && $value >= 0 && $value <= 1 ? (float) $value : null;
	}

	/**
	 * Answer a request TO its caller, keyed as asked, with its item, inputs
	 * and carry.
	 *
	 * @param list<mixed>         $value   [ from, key, prompt, item, inputs, options, carry ].
	 * @param array<string,mixed> $outcome `content`, `decision`, `error` or `unlent`.
	 */
	private function reply( array $value, array $outcome ): void {
		$answer = $outcome + [
			'item'   => $value[3] ?? null,
			'inputs' => $value[4] ?? null,
			'carry'  => $value[6] ?? null,
		];
		$this->answer_to( Core::as_string( $value[0] ?? null ), Core::as_string( $value[1] ?? null ), $answer );
	}

	/**
	 * Send an answer VALUE TO a caller as a TM_STRUCT|TM_RESPONSE, keyed as
	 * it asked.
	 *
	 * @param string                 $from   The caller.
	 * @param string                 $key    The request's KEY.
	 * @param array<array-key,mixed> $answer The answer.
	 */
	private function answer_to( string $from, string $key, array $answer ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT | Message::TM_RESPONSE;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::TO ]    = $from;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $answer;
		parent::fill( $message );
	}

	/**
	 * Raise Curl's body cap to BODY_CAP.
	 *
	 * @param Curl_Node $curl The sibling.
	 */
	protected function configure_curl( Curl_Node $curl ): void {
		$curl->set_body_cap( self::BODY_CAP );
	}

	/**
	 * The `set_api_url` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $url An absolute http(s) url, its trailing slash dropped.
	 * @throws \UnexpectedValueException When it is not one.
	 */
	public function set_api_url( string $url ): void {
		$url = \trim( $url );
		Curl_Node::origin_of( $url );
		$this->api_url = \rtrim( $url, '/' );
	}

	/**
	 * The `set_model` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $model The model id.
	 */
	public function set_model( string $model ): void {
		$this->model = \trim( $model );
	}

	/**
	 * The `set_decision_model` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $model The decision model id.
	 */
	public function set_decision_model( string $model ): void {
		$this->decision_model = \trim( $model );
	}

	/**
	 * The `set_feature` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $feature The X-WPCOM-AI-Feature value.
	 */
	public function set_feature( string $feature ): void {
		$this->feature = \trim( $feature );
	}

	/**
	 * The `set_concurrency` verb's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $count A whole number from 1 to Curl_Node::MAX_IN_FLIGHT.
	 * @throws \InvalidArgumentException Outside that range.
	 */
	public function set_concurrency( string $count ): void {
		$this->concurrency = Curl_Node::concurrency_of( (int) $count );
	}

	/**
	 * Seconds the cache keeps a status: three heartbeats, so a stopped
	 * worker's counts are gone soon after a reader stops trusting them.
	 *
	 * @api Used by substrate.
	 */
	protected function status_ttl(): int {
		return 3 * self::STATUS_HEARTBEAT_S;
	}

	/**
	 * Seconds after which an unchanged status is written again.
	 *
	 * @api Used by substrate.
	 */
	protected function status_heartbeat(): int {
		return self::STATUS_HEARTBEAT_S;
	}

	/** Emit the base config, then each verb's line. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		$verb = static fn ( string $name, string $description, string $arg, string $type, string $setter ): array => [
			'name'        => $name,
			'description' => $description,
			'args'        => [
				[ 'name' => $arg, 'type' => $type, 'required' => true ],
			],
			'setter'      => $setter,
		];
		return [
			'category'     => 'I/O',
			'description'  => 'Makes the model calls nodes ask for without blocking the worker: queues each request durably, new work ahead of a backlog, posts it to the AI API Proxy under its Vault group as a chat completion or a decision, and answers the caller with the content, the decision or the error and what the request carried.',
			// The Table owned_table() declares, which the analyzer reads.
			'owns'         => [ self::FRONTIER => 'Table' ],
			'arguments'    => [
				[ 'name' => 'vault_group', 'type' => 'vault_group', 'required' => true, 'description' => 'The Vault group whose entry on the api url\'s origin lends each call its credential: ai-proxy.' ],
			],
			'commands'     => [
				$verb( 'set_api_url', 'Set the AI API Proxy base url the calls post to: <url>.', 'url', 'string', 'api_url' ),
				$verb( 'set_model', 'Set the model id every chat call names: <model>.', 'model', 'string', 'model' ),
				$verb( 'set_decision_model', 'Set the model id every decision call names: <model>.', 'model', 'string', 'decision_model' ),
				$verb( 'set_feature', 'Set the X-WPCOM-AI-Feature value every call sends: <feature>.', 'feature', 'string', 'feature' ),
				$verb( 'set_concurrency', 'Make at most <count> calls at once, from 1 to ' . Curl_Node::MAX_IN_FLIGHT . '; 1 until set.', 'count', 'int', 'concurrency' ),
			],
			'accepts_fill' => true,
		];
	}
}

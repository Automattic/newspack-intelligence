<?php
/**
 * LLM_Call_Node: the model calls of every node that asks one, made without
 * blocking the worker.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
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
 * POSTs it under the vault group, at most `set_concurrency` at once, in
 * the wire `wires()` names for its route, built as it is sent: a chat prompt's
 * route is `set_protocol`'s, and a decision's is `systemone` under
 * `set_decision_protocol systemone`, else the chat protocol's, asked as
 * one structured-output call whose schema `Prompts::decision_schema()`
 * builds. The answer goes TO the request's FROM, keyed as asked, a
 * TM_STRUCT|TM_RESPONSE whose VALUE is `{ content, item, inputs, carry }`
 * for a chat prompt, `{ decision: { <question>: <noul> | { choice,
 * confidence } }, … }` for a decision, `{ error, … }`, or, when the group
 * holds no entry lending the api url's origin a credential, `{ unlent, … }` at
 * once, `unlent` the group; a transport whose setters are unset answers
 * the same at once, `unset` naming each setter the call's kind lacks, so no
 * caller retries a call that cannot be made. A stored request that will not send is settled and answered as an error. A
 * request in flight when the worker stops is sent again by the next one; a
 * clean stop sends none it answered again.
 *
 * @phpstan-type Wire array{path:string,needs:array<string,string>,headers:\Closure(array<array-key,mixed>):list<string>,body:\Closure(array<array-key,mixed>,string,string,Operator,array<array-key,mixed>,array<array-key,mixed>,array<array-key,mixed>):array<array-key,mixed>,read:\Closure(string):(array{content:string}|array{error:string}),prompts:array<string,array{max_tokens:int,effort:string}>}
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

	/** The error a chat-completions body naming no assistant content answers. */
	private const NO_CONTENT = 'the answer names no choices[0].message.content';

	/** The error a Messages body holding no text block answers. */
	private const NO_TEXT = 'the answer names no text content block';

	/** The Messages stops whose text is not an answer. */
	private const UNANSWERED_STOPS = [ 'refusal', 'max_tokens' ];

	/** What a decision answer that does not read names no more of, by its question's type. */
	private const UNREAD = [
		'noul'   => 'no noul from 0 to 1',
		'choice' => 'no offered choice with a confidence from 0 to 1',
	];

	/** The route a decision takes under `set_decision_protocol systemone`: the proxy's decision endpoint. */
	private const SYSTEMONE = 'systemone';

	/** Where `set_decision_protocol` sends decisions: systemone, or the chat protocol. */
	private const DECISION_PROTOCOLS = [ self::SYSTEMONE, 'chat' ];

	/** The Messages API efforts `set_effort` may name. */
	private const EFFORTS = [ 'low', 'medium', 'high', 'xhigh', 'max' ];

	/** `set_fallbacks`: Anthropic's refusal fallbacks, or none. */
	private const FALLBACKS = [ 'default', 'none' ];

	/** The current stable `anthropic-version`, sent while `set_api_version` names none. */
	public const ANTHROPIC_VERSION = '2026-10-21';

	/** The beta header `fallbacks: "default"` rides with. */
	private const FALLBACK_BETA = 'anthropic-beta: server-side-fallback-2026-07-01';

	/** The commands whose values its topology row names, each => the fact, and the property, it sets. */
	private const FACTS = [
		'set_protocol'          => 'protocol',
		'set_decision_protocol' => 'decision_protocol',
		'set_api_url'           => 'api_url',
		'set_model'             => 'model',
		'set_decision_model'    => 'decision_model',
		'set_feature'           => 'feature',
		'set_api_version'       => 'api_version',
		'set_effort'            => 'effort',
		'set_fallbacks'         => 'fallbacks',
		'set_operator'          => 'operator',
		'set_tracks'            => 'tracks',
	];

	/** The wire chat calls speak, a `wires()` route but systemone, set via set_protocol; '' until set. */
	protected string $protocol = '';

	/** Where decisions go, systemone or chat, set via set_decision_protocol; '' until set. */
	protected string $decision_protocol = '';

	/** The base url the calls post under, set via set_api_url; '' until set. */
	protected string $api_url = '';

	/** The model id, set via set_model; '' until set. */
	protected string $model = '';

	/** The decision model id, set via set_decision_model; '' until set. */
	protected string $decision_model = '';

	/** The X-WPCOM-AI-Feature value, set via set_feature; '' until set. */
	protected string $feature = '';

	/** The `anthropic-version` sent, set via set_api_version; '' sends ANTHROPIC_VERSION. */
	protected string $api_version = '';

	/** The Messages effort every prompt asks, set via set_effort; '' asks each prompt's own. */
	protected string $effort = '';

	/** Whether Messages calls ask refusal fallbacks, set via set_fallbacks; '' asks them. */
	protected string $fallbacks = '';

	/**
	 * The wire table `wires()` builds once a process.
	 *
	 * @var array<string,Wire>|null
	 */
	private static ?array $wires = null;

	/** Who runs the deployment, set via set_operator; '' names generic wording. */
	protected string $operator = '';

	/** What the deployment's graph tracks, set via set_tracks; '' for the operator's own work. */
	protected string $tracks = '';

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
		$unset = self::unset_of( $this->facts(), Prompts::PROMPTS[ Core::as_string( $request['prompt'] ) ] );
		if ( [] !== $unset ) {
			$this->reply(
				$value,
				[
					'unlent' => $this->vault_group,
					'unset'  => $unset,
				]
			);
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
	 * Settle Curl's answer and send its outcome to the request's caller, an
	 * error when the transport no longer names a wire to read it by; a
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
		try {
			$outcome = self::outcome( $message, $this->facts(), Core::as_string( $value[2] ?? null ), Core::arr( $value[4] ?? null ) );
		} catch ( \InvalidArgumentException $e ) {
			$outcome = [ 'error' => "{$this->name}: an answer could not be read: " . $e->getMessage() ];
		}
		$this->deliver( $value, $outcome );
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
	 * Hand Curl one stored request's POST, as `curl_request()` builds it,
	 * KEY its member, so its answer names it.
	 *
	 * @param string      $member The frontier member.
	 * @param list<mixed> $value  [ from, key, prompt, item, inputs, options, carry ].
	 * @throws \InvalidArgumentException When the prompt is unknown.
	 * @throws \UnexpectedValueException When the body will not encode.
	 * @throws Worker_Should_Stop When Curl's refusal, answered at once, meets a stop.
	 */
	private function post( string $member, array $value ): void {
		$this->send_curl( $member, self::curl_request( $this->facts(), Core::as_string( $value[2] ?? null ), Core::arr( $value[3] ?? null ), Core::arr( $value[4] ?? null ), Core::arr( $value[5] ?? null ) ), $value );
	}

	/**
	 * What the node posts with, as its topology row names it.
	 *
	 * @return array<string,string>
	 */
	private function facts(): array {
		$facts = [];
		foreach ( self::FACTS as $fact ) {
			$facts[ $fact ] = Core::as_string( $this->{$fact} );
		}
		return $facts;
	}

	/**
	 * The POST Curl runs for one call, in the wire its route names: the url
	 * its path under the api url, the headers and the body its methods
	 * build, the body naming the call's model, the decision model for a
	 * decision, and its prompt the operator `$facts` names; a prompt
	 * `Prompts::PROMPTS` does not list is refused as a chat prompt is.
	 *
	 * @api Read by Eval_CLI_Command, which asks the decision model as this node does.
	 * @param array<array-key,mixed> $facts   What the call posts with, as `graph_facts()` names it.
	 * @param string                 $prompt  The prompt.
	 * @param array<array-key,mixed> $item    The item.
	 * @param array<array-key,mixed> $inputs  The prompt's other inputs.
	 * @param array<array-key,mixed> $options A chat prompt's options, which only a chat-completions wire sends.
	 * @return array{url:string,method:string,body:string,headers:list<string>,timeout:int}
	 * @throws \InvalidArgumentException When the prompt is unknown, or the route names no wire.
	 * @throws \UnexpectedValueException When the body will not encode.
	 */
	public static function curl_request( array $facts, string $prompt, array $item, array $inputs, array $options ): array {
		$kind     = Prompts::PROMPTS[ $prompt ] ?? Prompts::CHAT;
		$wire     = self::wire( $facts, $kind );
		$operator = new Operator( Core::as_string( $facts['operator'] ), Core::as_string( $facts['tracks'] ) );
		$model    = Core::as_string( $facts[ Prompts::DECISION === $kind ? 'decision_model' : 'model' ] );
		$body     = \wp_json_encode( [ 'model' => $model ] + $wire['body']( $facts, $prompt, $kind, $operator, $item, $inputs, $options ) );
		if ( false === $body ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \UnexpectedValueException( 'the request body could not be encoded: ' . \json_last_error_msg() );
		}
		return [
			'url'     => Core::as_string( $facts['api_url'] ) . "/{$wire['path']}",
			'method'  => 'POST',
			'body'    => $body,
			'headers' => $wire['headers']( $facts ),
			'timeout' => Curl_Node::MAX_REQUEST_TIMEOUT,
		];
	}

	/**
	 * The setters a call of `$kind` lacks under `$facts`, in this order:
	 * where a decision goes, the chat protocol unless it goes to systemone,
	 * the api url, the call's model, then what its wire needs.
	 *
	 * @api Read by Eval_CLI_Command, which refuses a decision its LLM_Call cannot make.
	 * @param array<array-key,mixed> $facts What the call posts with, as `graph_facts()` names it.
	 * @param string                 $kind  A kind of call, as `Prompts::PROMPTS` names one.
	 * @return list<string>
	 */
	public static function unset_of( array $facts, string $kind ): array {
		$decision = Prompts::DECISION === $kind;
		$route    = self::route( $facts, $kind );
		$needs    = ( $decision ? [ 'set_decision_protocol' => 'decision_protocol' ] : [] )
			+ ( self::SYSTEMONE === $route ? [] : [ 'set_protocol' => 'protocol' ] )
			+ [ 'set_api_url' => 'api_url' ]
			+ ( $decision ? [ 'set_decision_model' => 'decision_model' ] : [ 'set_model' => 'model' ] )
			+ ( self::wires()[ $route ]['needs'] ?? [] );
		return \array_keys( \array_filter( $needs, static fn ( string $fact ): bool => '' === $facts[ $fact ] ) );
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
	 * What Curl's answer says: its TM_ERROR text, else the text its wire
	 * reads or the error it names, and for a decision the answer to each
	 * question its inputs asked, read from that text as JSON.
	 *
	 * @api Read by Eval_CLI_Command, which asks the decision model as this node does.
	 * @param array<int,mixed>       $message Curl's answer.
	 * @param array<array-key,mixed> $facts   What the call posted with, as `graph_facts()` names it.
	 * @param string                 $prompt  The prompt the request named.
	 * @param array<array-key,mixed> $inputs  The request's inputs.
	 * @return array{error:string}|array{content:string}|array{decision:array<string,float|array{choice:string,confidence:float}>}
	 * @throws \InvalidArgumentException When the route names no wire.
	 */
	public static function outcome( array $message, array $facts, string $prompt, array $inputs ): array {
		$value = Core::as_string( $message[ Message::VALUE ] );
		if ( 0 !== ( Core::int( $message[ Message::TYPE ] ) & Message::TM_ERROR ) ) {
			return [ 'error' => $value ];
		}
		$kind = Prompts::PROMPTS[ $prompt ] ?? Prompts::CHAT;
		$read = self::wire( $facts, $kind )['read']( $value );
		if ( Prompts::DECISION !== $kind || ! isset( $read['content'] ) ) {
			return $read;
		}
		return self::decision_of( Prompts::json_object( $read['content'] ), Prompts::questions( $inputs ) );
	}

	/**
	 * The `wires()` row a call of `$kind` posts in under `$facts`.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 * @param string                 $kind  A kind of call, as `Prompts::PROMPTS` names one.
	 * @return Wire
	 * @throws \InvalidArgumentException When the route names no wire.
	 */
	private static function wire( array $facts, string $kind ): array {
		$route = self::route( $facts, $kind );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
		return self::wires()[ $route ] ?? throw new \InvalidArgumentException( "no protocol: '{$route}'" );
	}

	/**
	 * The route a call of `$kind` takes: systemone for a decision under
	 * `set_decision_protocol systemone`, else the chat protocol.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 * @param string                 $kind  A kind of call.
	 */
	private static function route( array $facts, string $kind ): string {
		return Prompts::DECISION === $kind && self::SYSTEMONE === $facts['decision_protocol'] ? self::SYSTEMONE : Core::as_string( $facts['protocol'] );
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
				return [ 'error' => 'the decision names ' . self::UNREAD[ $question['type'] ] . " for {$name}" ];
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
	 * The `set_protocol` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $protocol The wire chat calls speak: ai-proxy, openai or anthropic.
	 * @throws \InvalidArgumentException When it names no other.
	 */
	public function set_protocol( string $protocol ): void {
		$this->protocol = self::setting( 'protocol', $protocol );
	}

	/**
	 * The `set_decision_protocol` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $protocol Where decisions go: systemone, or chat through the protocol.
	 * @throws \InvalidArgumentException When it names neither.
	 */
	public function set_decision_protocol( string $protocol ): void {
		$this->decision_protocol = self::setting( 'decision_protocol', $protocol );
	}

	/**
	 * The `set_api_url` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $url An absolute http(s) url, its trailing slash dropped.
	 * @throws \UnexpectedValueException When it is not one.
	 */
	public function set_api_url( string $url ): void {
		$this->api_url = self::setting( 'api_url', $url );
	}

	/**
	 * The `set_model` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $model The model id.
	 */
	public function set_model( string $model ): void {
		$this->model = self::setting( 'model', $model );
	}

	/**
	 * The `set_decision_model` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $model The decision model id.
	 */
	public function set_decision_model( string $model ): void {
		$this->decision_model = self::setting( 'decision_model', $model );
	}

	/**
	 * The `set_feature` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $feature The X-WPCOM-AI-Feature value.
	 */
	public function set_feature( string $feature ): void {
		$this->feature = self::setting( 'feature', $feature );
	}

	/**
	 * The `set_api_version` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $version The `anthropic-version`, a Y-m-d date.
	 * @throws \InvalidArgumentException When it is not one.
	 */
	public function set_api_version( string $version ): void {
		$this->api_version = self::setting( 'api_version', $version );
	}

	/**
	 * The `set_effort` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $effort The Messages effort every prompt asks, one of EFFORTS.
	 * @throws \InvalidArgumentException When it names none of them.
	 */
	public function set_effort( string $effort ): void {
		$this->effort = self::setting( 'effort', $effort );
	}

	/**
	 * The `set_fallbacks` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $fallbacks default to ask refusal fallbacks, none to ask none.
	 * @throws \InvalidArgumentException When it names neither.
	 */
	public function set_fallbacks( string $fallbacks ): void {
		$this->fallbacks = self::setting( 'fallbacks', $fallbacks );
	}

	/**
	 * The `set_operator` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $name Who runs the deployment.
	 */
	public function set_operator( string $name ): void {
		$this->operator = self::setting( 'operator', $name );
	}

	/**
	 * The `set_tracks` command's entry point.
	 *
	 * @api Used by substrate.
	 * @param string $tracks What the deployment's graph tracks, as one phrase.
	 */
	public function set_tracks( string $tracks ): void {
		$this->tracks = self::setting( 'tracks', $tracks );
	}

	/**
	 * The node's topology row names what its calls post with: each fact
	 * FACTS names, as its command's last line leaves it, bound by that
	 * command's own args and kept as its setter keeps it, '' while unset.
	 *
	 * @api Read by the substrate's Topology_Analyzer::graph_for(), for Eval_CLI_Command and Operator::configured().
	 * @param list<string>                         $args     The `make_node` arguments, quotes stripped.
	 * @param list<array{0:string,1:list<string>}> $commands Each verb and its argument tokens, in the order sent.
	 * @return array<string,string>
	 * @throws \InvalidArgumentException When one of those lines does not bind, or names a value outside its set.
	 * @throws \UnexpectedValueException When the api url is not an absolute http(s) url.
	 */
	public static function graph_facts( array $args, array $commands ): array {
		$facts    = \array_fill_keys( self::FACTS, '' );
		$declared = Command_Interpreter_Node::declared_commands( self::class );
		foreach ( $commands as [ $verb, $tokens ] ) {
			$fact = self::FACTS[ $verb ] ?? null;
			if ( null !== $fact ) {
				$facts[ $fact ] = self::setting( $fact, Core::as_string( \current( Command_Args::bind( Core::arr( $declared[ $verb ]['args'] ), $tokens ) ) ) );
			}
		}
		return $facts;
	}

	/**
	 * A fact as its setter keeps it, trimmed: an api url checked, and a
	 * protocol, a decision route, an effort, a fallback switch or a version
	 * held to its set, '' for unset.
	 *
	 * @param string $fact  One of FACTS.
	 * @param string $value The value given.
	 * @throws \InvalidArgumentException When it names a value outside its set.
	 * @throws \UnexpectedValueException When an api url is not an absolute http(s) url.
	 */
	private static function setting( string $fact, string $value ): string {
		$value = \trim( $value );
		return match ( $fact ) {
			'api_url'           => self::api_url_of( $value ),
			'protocol'          => self::one_of( $fact, $value, \array_values( \array_diff( \array_keys( self::wires() ), [ self::SYSTEMONE ] ) ) ),
			'decision_protocol' => self::one_of( $fact, $value, self::DECISION_PROTOCOLS ),
			'effort'            => self::one_of( $fact, $value, self::EFFORTS ),
			'fallbacks'         => self::one_of( $fact, $value, self::FALLBACKS ),
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			'api_version'       => '' === $value || 1 === \preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ? $value : throw new \InvalidArgumentException( "api_version must be a Y-m-d date, got '{$value}'" ),
			default             => $value,
		};
	}

	/**
	 * A value held to its set: '' or one of `$set`.
	 *
	 * @param string       $fact  The fact, as the refusal names it.
	 * @param string       $value The trimmed value.
	 * @param list<string> $set   What it may name.
	 * @throws \InvalidArgumentException When it names none of them.
	 */
	private static function one_of( string $fact, string $value, array $set ): string {
		if ( '' !== $value && ! \in_array( $value, $set, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "{$fact} must be one of: " . \implode( ', ', $set ) . ", got '{$value}'" );
		}
		return $value;
	}

	/**
	 * An api url as `set_api_url` keeps it: checked as an absolute http(s)
	 * url, its trailing slash dropped.
	 *
	 * @param string $url The trimmed url.
	 * @throws \UnexpectedValueException When it is not one.
	 */
	private static function api_url_of( string $url ): string {
		Curl_Node::origin_of( $url );
		return \rtrim( $url, '/' );
	}

	/**
	 * Each wire a call is posted in, by route, built once a process: the
	 * path under the api url, the setters it needs beside the url and the
	 * model, each => its fact, what builds its headers beside the
	 * credential and its body beside the model, what reads its answer's
	 * text, and each prompt's max_tokens and effort where the wire sends
	 * them: the Messages API's are roomy, because a current model thinks by
	 * default and its thinking counts against max_tokens, and low but for
	 * the Ask's answer, which writes for a reader.
	 *
	 * @return array<string,Wire>
	 */
	private static function wires(): array {
		$proxy = [ 'set_feature' => 'feature' ];
		return self::$wires ??= [
			'ai-proxy'      => [
				'path'    => 'chat/completions',
				'needs'   => $proxy,
				'headers' => self::feature_headers( ... ),
				'body'    => self::openai_body( ... ),
				'read'    => self::openai_text( ... ),
				'prompts' => [],
			],
			'openai'        => [
				'path'    => 'chat/completions',
				'needs'   => [],
				'headers' => self::no_headers( ... ),
				'body'    => self::openai_body( ... ),
				'read'    => self::openai_text( ... ),
				'prompts' => [],
			],
			'anthropic'     => [
				'path'    => 'v1/messages',
				'needs'   => [],
				'headers' => self::messages_headers( ... ),
				'body'    => self::messages_body( ... ),
				'read'    => self::messages_text( ... ),
				'prompts' => [
					'extract'               => [ 'max_tokens' => 8192, 'effort' => 'low' ],
					'extract_entities'      => [ 'max_tokens' => 4096, 'effort' => 'low' ],
					Prompts::EXTRACT_REVIEW => [ 'max_tokens' => 4096, 'effort' => 'low' ],
					Prompts::EXTRACT_GATE   => [ 'max_tokens' => 4096, 'effort' => 'low' ],
					Ask_Prompts::PLAN       => [ 'max_tokens' => 4096, 'effort' => 'low' ],
					Ask_Prompts::ANSWER     => [ 'max_tokens' => 8192, 'effort' => 'medium' ],
				],
			],
			self::SYSTEMONE => [
				'path'    => 'systemone',
				'needs'   => $proxy,
				'headers' => self::feature_headers( ... ),
				'body'    => self::systemone_body( ... ),
				'read'    => self::systemone_text( ... ),
				'prompts' => [],
			],
		];
	}

	/**
	 * A Messages body less its model: the prompt's system text as the one
	 * cached block, its user message as the one turn, its max_tokens and
	 * effort, the deployment's effort in place of the prompt's when it names
	 * one, and for a decision its questions' schema as the output format;
	 * refusal fallbacks unless the deployment turns them off. The caller's
	 * options are not sent: the API refuses temperature.
	 *
	 * @param array<array-key,mixed> $facts    What the call posts with.
	 * @param string                 $prompt   The prompt.
	 * @param string                 $kind     Its kind.
	 * @param Operator               $operator The deployment's operator.
	 * @param array<array-key,mixed> $item     The item.
	 * @param array<array-key,mixed> $inputs   The prompt's other inputs.
	 * @param array<array-key,mixed> $options  A chat prompt's options, unsent.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When the row budgets no such prompt.
	 */
	private static function messages_body( array $facts, string $prompt, string $kind, Operator $operator, array $item, array $inputs, array $options ): array {
		unset( $options );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
		$asks              = self::wires()['anthropic']['prompts'][ $prompt ] ?? throw new \InvalidArgumentException( 'the anthropic wire budgets no prompt: ' . \mb_strcut( $prompt, 0, Prompts::PROMPT_NAME_BYTES ) );
		$decision          = Prompts::DECISION === $kind;
		[ $system, $user ] = $decision ? Prompts::decision_messages( $prompt, $item, $inputs, $operator ) : Prompts::messages( $prompt, $item, $inputs, $operator );
		$effort            = Core::as_string( $facts['effort'] );
		$output            = [ 'effort' => '' === $effort ? $asks['effort'] : $effort ]
			+ ( $decision ? [ 'format' => [ 'type' => 'json_schema', 'schema' => Prompts::decision_schema( Prompts::questions( $inputs ) ) ] ] : [] );
		return [
			'max_tokens'    => $asks['max_tokens'],
			'system'        => [
				[
					'type'          => 'text',
					'text'          => $system['content'],
					'cache_control' => [ 'type' => 'ephemeral' ],
				],
			],
			'messages'      => [
				[
					'role'    => 'user',
					'content' => $user['content'],
				],
			],
			'output_config' => $output,
		] + ( self::fallbacks_asked( $facts ) ? [ 'fallbacks' => 'default' ] : [] );
	}

	/**
	 * The Messages API's headers beside the credential: the version the
	 * deployment names, else ANTHROPIC_VERSION, and the fallback beta
	 * while fallbacks are asked.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 * @return list<string>
	 */
	private static function messages_headers( array $facts ): array {
		$version = Core::as_string( $facts['api_version'] );
		return [ 'anthropic-version: ' . ( '' === $version ? self::ANTHROPIC_VERSION : $version ), ...( self::fallbacks_asked( $facts ) ? [ self::FALLBACK_BETA ] : [] ) ];
	}

	/**
	 * Whether a Messages call asks refusal fallbacks: unless `set_fallbacks`
	 * names none.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 */
	private static function fallbacks_asked( array $facts ): bool {
		return 'none' !== $facts['fallbacks'];
	}

	/**
	 * A chat-completions body less its model: a chat prompt's messages and
	 * options, or a decision's messages with its questions' schema as the
	 * response format.
	 *
	 * @param array<array-key,mixed> $facts    What the call posts with.
	 * @param string                 $prompt   The prompt.
	 * @param string                 $kind     Its kind.
	 * @param Operator               $operator The deployment's operator.
	 * @param array<array-key,mixed> $item     The item.
	 * @param array<array-key,mixed> $inputs   The prompt's other inputs.
	 * @param array<array-key,mixed> $options  A chat prompt's options.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When the prompt is unknown.
	 */
	private static function openai_body( array $facts, string $prompt, string $kind, Operator $operator, array $item, array $inputs, array $options ): array {
		if ( Prompts::DECISION !== $kind ) {
			return [ 'messages' => Prompts::messages( $prompt, $item, $inputs, $operator ) ] + $options;
		}
		return [
			'messages'        => Prompts::decision_messages( $prompt, $item, $inputs, $operator ),
			'response_format' => [
				'type'        => 'json_schema',
				'json_schema' => [
					'name'   => 'decision',
					'strict' => true,
					'schema' => Prompts::decision_schema( Prompts::questions( $inputs ) ),
				],
			],
		];
	}

	/**
	 * A systemone body less its model: the decision's state and questions,
	 * as `Prompts::decision()` builds them.
	 *
	 * @param array<array-key,mixed> $facts    What the call posts with.
	 * @param string                 $prompt   The prompt.
	 * @param string                 $kind     Its kind.
	 * @param Operator               $operator The deployment's operator.
	 * @param array<array-key,mixed> $item     The item.
	 * @param array<array-key,mixed> $inputs   The prompt's other inputs.
	 * @param array<array-key,mixed> $options  None, as a decision takes none.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When the prompt is not a decision prompt, or asks no question.
	 */
	private static function systemone_body( array $facts, string $prompt, string $kind, Operator $operator, array $item, array $inputs, array $options ): array {
		unset( $facts, $kind, $options );
		return Prompts::decision( $prompt, $item, $inputs, $operator );
	}

	/**
	 * The proxy's header beside the credential: the feature it bills.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 * @return list<string>
	 */
	private static function feature_headers( array $facts ): array {
		return [ 'X-WPCOM-AI-Feature: ' . Core::as_string( $facts['feature'] ) ];
	}

	/**
	 * A plain chat-completions server's headers beside the credential: none.
	 *
	 * @param array<array-key,mixed> $facts What the call posts with.
	 * @return list<string>
	 */
	private static function no_headers( array $facts ): array {
		unset( $facts );
		return [];
	}

	/**
	 * A chat-completions answer's text: its first choice's content.
	 *
	 * @param string $body The answer's body.
	 * @return array{content:string}|array{error:string}
	 */
	private static function openai_text( string $body ): array {
		$choice  = Core::arr( Core::arr( Core::arr( \json_decode( $body, true ) )['choices'] ?? null )[0] ?? null );
		$content = Core::arr( $choice['message'] ?? null )['content'] ?? null;
		return \is_string( $content ) ? [ 'content' => $content ] : [ 'error' => self::NO_CONTENT ];
	}

	/**
	 * A Messages answer's text: its text blocks joined, thinking skipped; a
	 * refusal or a max_tokens stop is the error naming it and the refusal's
	 * category, never an answer.
	 *
	 * @param string $body The answer's body.
	 * @return array{content:string}|array{error:string}
	 */
	private static function messages_text( string $body ): array {
		$decoded = Core::arr( \json_decode( $body, true ) );
		$stop    = $decoded['stop_reason'] ?? null;
		if ( \in_array( $stop, self::UNANSWERED_STOPS, true ) ) {
			$category = Core::arr( $decoded['stop_details'] ?? null )['category'] ?? null;
			return [ 'error' => "the answer stopped at {$stop}" . ( \is_string( $category ) ? " ({$category})" : '' ) ];
		}
		$text = null;
		foreach ( Core::arr( $decoded['content'] ?? null ) as $block ) {
			$block = Core::arr( $block );
			$said  = $block['text'] ?? null;
			if ( 'text' === ( $block['type'] ?? null ) && \is_string( $said ) ) {
				$text = ( $text ?? '' ) . $said;
			}
		}
		return null === $text ? [ 'error' => self::NO_TEXT ] : [ 'content' => $text ];
	}

	/**
	 * A systemone answer's text: the body, whose JSON is the decision.
	 *
	 * @param string $body The answer's body.
	 * @return array{content:string}
	 */
	private static function systemone_text( string $body ): array {
		return [ 'content' => $body ];
	}

	/**
	 * Why an `unlent` answer was not sent, as a caller logs it: the setters
	 * its call lacks when it names them, else its group lending nothing.
	 *
	 * @param array<array-key,mixed> $answer An answer carrying `unlent`.
	 */
	public static function unlent_why( array $answer ): string {
		$unset = Core::arr( $answer['unset'] ?? [] );
		return [] === $unset
			? 'the ' . Core::as_string( $answer['unlent'] ) . ' Vault group lends no credential'
			: 'the model call lacks ' . \implode( ', ', \array_map( Core::as_string( ... ), $unset ) );
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
	 * The `set_concurrency` command's entry point.
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

	/** Emit the base config, then each command's line. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_setters();
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		$command = static fn ( string $name, string $description, string $arg, string $type, string $setter ): array => [
			'name'        => $name,
			'description' => $description,
			'args'        => [
				[ 'name' => $arg, 'type' => $type, 'required' => true ],
			],
			'setter'      => $setter,
		];
		return [
			'category'     => 'I/O',
			'description'  => 'Makes the model calls nodes ask for without blocking the worker: queues each request durably, new work ahead of a backlog, posts it under its Vault group in the wire its protocol names, the AI API Proxy, plain chat completions or the Messages API, as a chat call or a decision, and answers the caller with the content, the decision or the error and what the request carried.',
			// The Table owned_table() declares, which the analyzer reads.
			'owns'         => [ self::FRONTIER => 'Table' ],
			'arguments'    => [
				[ 'name' => 'vault_group', 'type' => 'vault_group', 'required' => true, 'description' => 'The Vault group whose entry on the api url\'s origin lends each call its credential: ai-proxy.' ],
			],
			'commands'     => [
				$command( 'set_protocol', 'Set the wire every chat call speaks: ai-proxy, the AI API Proxy\'s chat completions with its feature header; openai, plain chat completions; or anthropic, the Messages API.', 'protocol', 'string', 'protocol' ),
				$command( 'set_decision_protocol', 'Set where every decision goes: systemone, the AI API Proxy\'s decision endpoint, or chat, one structured-output call through the protocol.', 'protocol', 'string', 'decision_protocol' ),
				$command( 'set_api_url', 'Set the base url the calls post under: <url>.', 'url', 'string', 'api_url' ),
				$command( 'set_model', 'Set the model id every chat call names: <model>.', 'model', 'string', 'model' ),
				$command( 'set_decision_model', 'Set the model id every decision call names: <model>.', 'model', 'string', 'decision_model' ),
				$command( 'set_feature', 'Set the X-WPCOM-AI-Feature value every call to the AI API Proxy sends: <feature>.', 'feature', 'string', 'feature' ),
				$command( 'set_api_version', 'Set the anthropic-version every Messages call sends: <version>, a Y-m-d date; ' . self::ANTHROPIC_VERSION . ' until set.', 'version', 'string', 'api_version' ),
				$command( 'set_effort', 'Set the effort every Messages call asks: <effort>, one of ' . \implode( ', ', self::EFFORTS ) . '; each prompt\'s own until set.', 'effort', 'string', 'effort' ),
				$command( 'set_fallbacks', 'Set whether Messages calls ask refusal fallbacks: default, as until set, or none, for a model that takes none.', 'fallbacks', 'string', 'fallbacks' ),
				$command( 'set_operator', 'Name who runs the deployment, whom every prompt saying whom the graph serves names: <name>; generic wording until set.', 'name', 'string', 'operator' ),
				$command( 'set_tracks', 'Say what the deployment\'s graph tracks, as one phrase every decision names: <tracks>; the operator\'s own work and clients until set.', 'tracks', 'string', 'tracks' ),
				$command( 'set_concurrency', 'Make at most <count> calls at once, from 1 to ' . Curl_Node::MAX_IN_FLIGHT . '; 1 until set.', 'count', 'int', 'concurrency' ),
			],
			'accepts_fill' => true,
		];
	}
}

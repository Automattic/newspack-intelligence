<?php
/**
 * Owns_Curl_Frontier: the `{name}:curl` and `{name}:frontier` siblings a
 * node fetches through and keeps its requests in until each is answered.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Frontier;
use Newspack_Nodes\Message;
use Newspack_Nodes\Table_Client;
use Newspack_Nodes\Table_Node;

\defined( 'ABSPATH' ) || exit;

/**
 * A using node, a `Timer_Node` that also uses `Schema_Reflection`, builds a
 * Curl sibling under its vault group, whose answers come back to it, and a
 * Frontier over the sqlite Table its schema `owns`, else in the process,
 * holding each request in flight by its subject: `send_curl()` records it
 * and hands Curl the request keyed by it, `settled()` takes the answer,
 * which Curl sends under the FROM the node minted, and `abandon()` lets go
 * of one that will not answer. A clean stop removes the answered requests
 * from the Table.
 *
 * Each owner keeps its own contract for a send Curl throws on, by design:
 * `Source_Node` abandons the request and rethrows, because its pass logs
 * and publishes the failure; `LLM_Call_Node` abandons it and answers its
 * caller an error, because a caller is waiting on that answer.
 *
 * @template TRequest
 */
trait Owns_Curl_Frontier {

	/** Sibling kind of the Table the requests wait in. */
	public const FRONTIER = 'frontier';

	/** The Vault group whose entry on a request's origin lends it a credential; '' for none. */
	protected string $vault_group = '';

	/** Asks the frontier Table. */
	protected Table_Client $tables;

	/** The requests waiting and in flight, in the node's Table, or in the process for a node owning none. */
	private Frontier $frontier;

	/** The `{name}:curl` sibling that fetches. */
	private Curl_Node $curl;

	/** @var array<string,TRequest> Each request sent and not yet answered, by subject. */
	private array $in_flight = [];

	/**
	 * Tachikoma-parity: no-arg ctor. Builds the Curl sibling first, because
	 * naming the `:config` interpreter names every sibling, and a Frontier
	 * over the Table the class's schema `owns`, else in the process.
	 */
	public function __construct() {
		parent::__construct();
		$this->tables   = new Table_Client( $this );
		$this->frontier = self::owns_frontier() ? new Frontier( $this->tables, fn (): string => $this->sibling_name( self::FRONTIER ) ) : new Frontier();
		$this->attach_curl();
		$this->auto_wire_interpreter();
	}

	/**
	 * On a clean stop, remove the answered requests from the Table, so the
	 * next process sends none of them again.
	 *
	 * @api Used by substrate.
	 */
	public function shutdown_sweep(): void {
		$this->frontier->flush();
	}

	/**
	 * Take the vault group the arguments just set: a new one builds Curl
	 * again under it, dropping what it had in flight for the frontier to send
	 * again, and the owned Table is built once, where the worker's partition
	 * is bound.
	 *
	 * @param string $previous The group before the arguments were set.
	 * @return bool Whether the group changed, so the node forgets what it queued.
	 * @throws \Throwable What the Table refused.
	 */
	private function adopt_group( string $previous ): bool {
		$changed = $previous !== $this->vault_group;
		if ( $changed ) {
			$this->retract_sibling( 'curl' );
			$this->attach_curl();
			$this->in_flight = [];
			$this->frontier->restart();
		}
		if ( self::owns_frontier() && ! isset( $this->siblings()[ self::FRONTIER ] ) ) {
			$this->own_frontier();
		}
		return $changed;
	}

	/**
	 * Build and publish the frontier Table, as owned_table() declares it,
	 * where the worker's partition is bound; one it refuses leaves none.
	 *
	 * @throws \Throwable What the Table refused.
	 */
	private function own_frontier(): void {
		$table = new Table_Node();
		$table->patron( $this );
		$this->publish_sibling( self::FRONTIER, $table );
		try {
			$table->arguments( \array_values( static::owned_table( self::FRONTIER, $this->name, [] ) ) );
		} catch ( \Throwable $e ) {
			$this->retract_sibling( self::FRONTIER );
			throw $e;
		}
		$table->sink( $this->sink );
	}

	/**
	 * The frontier Table a node named `$owner` builds, which the analyzer
	 * reads off its line: its own name as namespace, a year's TTL, sqlite.
	 *
	 * @param string       $suffix    The owned Table's suffix, `frontier`.
	 * @param string       $owner     The node's name.
	 * @param list<string> $arguments The node's `make_node` arguments.
	 * @return array{namespace: string, ttl: string, backend: string}
	 */
	public static function owned_table( string $suffix, string $owner, array $arguments ): array {
		return [
			'namespace' => $owner,
			'ttl'       => (string) Frontier::MEMBER_TTL,
			'backend'   => 'sqlite',
		];
	}

	/** Whether the class's schema `owns` a frontier Table. */
	private static function owns_frontier(): bool {
		return isset( Core::arr( static::node_schema()['owns'] ?? null )[ self::FRONTIER ] );
	}

	/**
	 * Build and publish the Curl sibling under the vault group, as
	 * configure_curl() sets it, its answers coming back here.
	 */
	private function attach_curl(): void {
		$curl = new Curl_Node();
		$curl->patron( $this );
		$curl->arguments( '' === $this->vault_group ? [] : [ $this->vault_group ] );
		$this->configure_curl( $curl );
		$this->curl = $curl;
		$this->publish_sibling( 'curl', $curl );
		$curl->sink( $this->sink );
	}

	/**
	 * Set what the node needs of its Curl sibling before it is published.
	 *
	 * @param Curl_Node $curl The sibling.
	 */
	abstract protected function configure_curl( Curl_Node $curl ): void;

	/**
	 * Record a request in flight under its subject, then hand Curl the
	 * TM_STRUCT `$request` keyed by it, so its answer names it.
	 *
	 * @param string                 $subject The request's subject, its member.
	 * @param array<string,mixed>    $request What Curl runs: `{ url, method, … }`.
	 * @param TRequest               $value   What the node holds for the answer.
	 */
	private function send_curl( string $subject, array $request, mixed $value ): void {
		$this->in_flight[ $subject ] = $value;
		$message                     = Message::new_message();
		$message[ Message::TYPE ]    = Message::TM_STRUCT;
		$message[ Message::FROM ]    = $this->name;
		$message[ Message::KEY ]     = $subject;
		$message[ Message::VALUE ]   = $request;
		$this->curl->fill( $message );
	}

	/**
	 * The request an answer from Curl settles, by its KEY, abandoned once
	 * taken; null, logged, for a message not under the FROM the node minted,
	 * which only its Curl copies back, or naming no request in flight.
	 *
	 * @param array<int,mixed> $message Curl's answer.
	 * @return TRequest|null
	 */
	private function settled( array $message ): mixed {
		$from = Core::as_string( $message[ Message::FROM ] );
		if ( $this->name !== $from ) {
			$this->print_less_often( 'dropping an answer that is not from its Curl: ', Core::terminal_safe( $from ) );
			return null;
		}
		$key     = Core::as_string( $message[ Message::KEY ] );
		$request = $this->in_flight[ $key ] ?? null;
		if ( null === $request ) {
			$this->drop_message( $message, 'no request in flight' );
			return null;
		}
		$this->abandon( $key );
		return $request;
	}

	/**
	 * Let go of a request that will not answer: out of flight, and settled
	 * in the frontier, so no process sends it again.
	 *
	 * @param string $subject The request's subject.
	 */
	private function abandon( string $subject ): void {
		unset( $this->in_flight[ $subject ] );
		$this->frontier->settle( [ $subject ] );
	}

	/**
	 * Name the siblings through `parent::`, and point Curl's answers at this
	 * name.
	 *
	 * @api Used by substrate.
	 */
	protected function set_sibling_names(): void {
		parent::set_sibling_names();
		$this->curl->target( $this->name );
	}
}

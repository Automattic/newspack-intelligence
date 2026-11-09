<?php
/**
 * Ask_Node: answers each question a reader asks, as a worker job, because
 * its two model calls block. Its Consumer hands it each `ask_asked` from the
 * asks stream; it plans, reads and answers, and writes the outcome, an
 * `ask_answered` or an `ask_failed`, to the writer its `make_node` line names.
 *
 * Plan: one chat call (`Ask_Prompts::PLAN`) turns the question into one to
 * three searches, refused whole when one names anything but the `search`
 * filters `Ask_Prompts::SEARCH_FIELDS` lists. Read: signed in as the asker,
 * exactly as the watcher signs in each owner, each search binds against the
 * `search` command's own declared args and runs through `Graph_Query::search()`,
 * so every item read is one of the asker's readable tiers now; their union,
 * interleaved, holds at most MAX_ITEMS. Nothing found answers so, with no
 * second call. Answer: one chat call (`Ask_Prompts::ANSWER`) given those
 * items alone, numbered; a sentence not ending in citations, or citing a
 * number past them, is dropped, and only the items the kept sentences cite
 * are recorded, numbered again in citation order. Both calls go to the
 * model-call node as new work, never backlog, and neither is a decision, so
 * a restricted item reaches the chat model alone.
 * `Graph_Asks` holds the `ask` and `ask_result` commands.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Args;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * Plans, reads and answers each asked question as its asker.
 *
 * @phpstan-import-type Graph from Graph_Query
 * @phpstan-import-type Search from Ask_Prompts
 * @phpstan-import-type Sentence from Ask_Prompts
 */
class Ask_Node extends Node {
	use Schema_Reflection;
	use Emits_Struct;
	use Asks_Model;

	/** The most items an answer is written from. */
	public const MAX_ITEMS = 40;

	/** Seconds an ask may wait for the node before it fails unasked. */
	public const MAX_WAIT_S = 900;

	/** Days the asks stream and the `asks` table keep an ask. */
	public const KEEP_DAYS = 7;

	/** The longest reason a failure records. */
	private const REASON_BYTES = 512;

	/** The pipeline stage every outcome is recorded as. */
	private const STAGE = 'ask';

	/** The options of the plan call. */
	private const PLAN_OPTIONS = [
		'max_tokens'  => 600,
		'temperature' => 0,
	];

	/** The options of the answer call. */
	private const ANSWER_OPTIONS = [
		'max_tokens'  => 1500,
		'temperature' => 0,
	];

	/** The reason an ask fails with when a model call does. */
	private const CALL_FAILED = 'the model call failed';

	/** The reason an ask fails with when finishing it throws. */
	private const UNFINISHED = 'the ask could not be finished';

	/** The writer every outcome is addressed to. */
	protected string $writer = '';

	/**
	 * Take a model call's answer, an asked question, or, dropped with a
	 * line, anything else; the asks stream's own outcomes pass by.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 * @throws \InvalidArgumentException When an asks-stream event does not validate, so its Consumer dead-letters it.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	public function fill( array $message ): void {
		if ( self::is_answer( $message ) ) {
			$this->answered( $message );
			return;
		}
		$value = $message[ Message::VALUE ];
		$kind  = \is_array( $value ) ? ( $value['event'] ?? null ) : null;
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_string( $kind ) || Intel_Event::ASKS !== ( Intel_Event::KINDS[ $kind ][0] ?? null ) ) {
			$this->print_less_often( 'dropping a message that is not an ask from ', Core::terminal_safe( Core::as_string( $message[ Message::FROM ] ) ) );
			return;
		}
		$event = Intel_Event::from_message( $message );
		if ( 'ask_asked' === $kind ) {
			++$this->counter;
			$this->intake( $event );
		}
	}

	/**
	 * A model call's answer, the plan's or the answer's, finished as one
	 * unit; one landing after its ask has waited past MAX_WAIT_S is dropped
	 * and writes nothing; one whose finish throws fails the ask.
	 *
	 * @param array<int,mixed> $message The answer.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answered( array $message ): void {
		$answer = $this->answer_of( $message );
		if ( null === $answer ) {
			return;
		}
		[ $value, $item, , $carry ] = $answer;
		$key                        = Core::as_string( $item['item_key'] ?? null );
		$owner                      = $carry['owner'] ?? null;
		$step                       = $carry['step'] ?? null;
		$asked                      = $carry['asked'] ?? null;
		if ( ! \is_int( $owner ) || ! \in_array( $step, [ Ask_Prompts::PLAN, Ask_Prompts::ANSWER ], true ) || ! ( \is_int( $asked ) || \is_float( $asked ) ) ) {
			$this->unreadable( $message );
			return;
		}
		if ( $this->stale( $key, (float) $asked ) ) {
			return;
		}
		$this->finish_answer(
			$key,
			fn () => $this->settled( $key, $owner, Core::as_string( $step ), $value, $item, $carry ),
			fn () => $this->fail( $key, $owner, self::UNFINISHED, Core::arr( $carry['searches'] ?? null ) )
		);
	}

	/**
	 * Take an asked question: drop one that waited past MAX_WAIT_S, logged,
	 * writing nothing, so a replayed question never overwrites its answer;
	 * fail one whose asker cannot be signed in; else read the lenses as the
	 * asker and ask for the plan. A failure is logged under the ask and
	 * fails it.
	 *
	 * @param array<array-key,mixed> $event The `ask_asked`.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function intake( array $event ): void {
		$key   = Core::as_string( $event['ask_key'] );
		$owner = Core::as_int( $event['owner'] );
		$asked = Core::as_float( $event['ts'] );
		if ( $this->stale( $key, $asked ) ) {
			return;
		}
		try {
			$lenses = $this->as_asker( $key, $owner, [], static fn (): array => self::lenses() );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( "ask {$key} failed: ", Core::terminal_safe( $e->getMessage() ) );
			$this->fail( $key, $owner, self::UNFINISHED, [] );
			return;
		}
		if ( null !== $lenses ) {
			$question = [ 'item_key' => $key, 'question' => Core::as_string( $event['question'] ) ];
			$this->ask_model( Ask_Prompts::PLAN, $question, [ 'today' => \gmdate( 'Y-m-d', Intel_Event::now() ), 'lenses' => $lenses ], self::PLAN_OPTIONS, [ 'owner' => $owner, 'step' => Ask_Prompts::PLAN, 'asked' => $asked ], false );
		}
	}

	/**
	 * Whether an ask has waited past MAX_WAIT_S, logged rate-limited when it
	 * has: at intake, and as each model call's reply lands, so one taken in
	 * just inside the wait never writes past it and its row keeps reading
	 * failed.
	 *
	 * @param string $key   The ask.
	 * @param float  $asked When it was asked.
	 */
	private function stale( string $key, float $asked ): bool {
		if ( Core::$now - $asked <= self::MAX_WAIT_S ) {
			return false;
		}
		$this->print_less_often( 'dropping an ask that waited past ' . self::MAX_WAIT_S . ' seconds: ', Core::terminal_safe( $key ) );
		return true;
	}

	/**
	 * Every lens a search may read through, as the plan offers them.
	 *
	 * @return list<array{lens_key:mixed,name:mixed,description:mixed}>
	 */
	private static function lenses(): array {
		return \array_map(
			static fn ( mixed $lens ): array => [
				'lens_key'    => Core::arr( $lens )['lens_key'] ?? null,
				'name'        => Core::arr( $lens )['name'] ?? null,
				'description' => Core::arr( $lens )['description'] ?? null,
			],
			\array_values( Core::arr( Graph_Query::lenses()['lenses'] ) )
		);
	}

	/**
	 * Finish a model call's answer: a failed call fails the ask, logged with
	 * why; a plan goes on to the read, and an answer is recorded.
	 *
	 * @param string                 $key   The ask.
	 * @param int                    $owner The asker.
	 * @param string                 $step  `Ask_Prompts::PLAN` or `ANSWER`.
	 * @param array<array-key,mixed> $value The answer's VALUE.
	 * @param array<array-key,mixed> $item  The ask as the call carried it.
	 * @param array<array-key,mixed> $carry The carry.
	 */
	private function settled( string $key, int $owner, string $step, array $value, array $item, array $carry ): void {
		$content = $value['content'] ?? null;
		if ( ! \is_string( $content ) ) {
			$why = \is_string( $value['unlent'] ?? null ) ? LLM_Call_Node::unlent_why( $value ) : Core::as_string( $value['error'] ?? null );
			$this->print_less_often( "ask {$key} failed: ", Core::terminal_safe( $why ) );
			$this->fail( $key, $owner, self::CALL_FAILED, Core::arr( $carry['searches'] ?? null ) );
			return;
		}
		if ( Ask_Prompts::PLAN === $step ) {
			$this->planned( $key, $owner, $item, $content, Core::as_float( $carry['asked'] ) );
		} else {
			$this->record( $key, $owner, $carry, $content );
		}
	}

	/**
	 * Read the plan's searches as the asker and ask for the answer from what
	 * they find; a plan or a search refused fails the ask naming why, and
	 * nothing found is answered at once.
	 *
	 * @param string                 $key     The ask.
	 * @param int                    $owner   The asker.
	 * @param array<array-key,mixed> $item    The ask as the call carried it.
	 * @param string                 $content The plan call's reply.
	 * @param float                  $asked   When it was asked, which the answer call carries.
	 */
	private function planned( string $key, int $owner, array $item, string $content, float $asked ): void {
		try {
			$searches = Ask_Prompts::parse_plan( $content );
		} catch ( \InvalidArgumentException $e ) {
			$this->fail( $key, $owner, "the plan was refused: {$e->getMessage()}", [] );
			return;
		}
		try {
			$read = $this->as_asker( $key, $owner, $searches, static fn (): array => self::read( $searches ) );
		} catch ( \InvalidArgumentException $e ) {
			$this->fail( $key, $owner, "a search was refused: {$e->getMessage()}", $searches );
			return;
		}
		if ( null === $read ) {
			return;
		}
		[ $keys, $given, $tiers ] = $read;
		if ( [] === $keys ) {
			$this->emit( $this->writer, Intel_Event::machine_event( self::STAGE, 'ask_answered', self::answer_fields( $key, $owner, 0, 0, [], [], [], $searches ) ) );
			return;
		}
		$this->ask_model( Ask_Prompts::ANSWER, [ 'item_key' => $key, 'question' => Core::as_string( $item['question'] ?? null ) ], [ 'items' => $given ], self::ANSWER_OPTIONS, [ 'owner' => $owner, 'step' => Ask_Prompts::ANSWER, 'keys' => $keys, 'tiers' => $tiers, 'searches' => $searches, 'asked' => $asked ], false );
	}

	/**
	 * Run a read signed in as the asker, and sign back in as whoever was
	 * signed in before whatever happens, the sign-in included; an asker who
	 * no longer exists, or no longer reads, fails the ask instead.
	 *
	 * @template T of array
	 * @param string                 $key      The ask.
	 * @param int                    $owner    The asker's user id.
	 * @param array<array-key,mixed> $searches The searches its plan names, none before a plan.
	 * @param \Closure(): T          $read     The read.
	 * @return T|null Null when the asker cannot be signed in.
	 */
	private function as_asker( string $key, int $owner, array $searches, \Closure $read ): ?array {
		\clean_user_cache( $owner );
		$user = \get_user_by( 'id', $owner );
		if ( ! $user instanceof \WP_User ) {
			$this->fail( $key, $owner, 'the asker no longer exists', $searches );
			return null;
		}
		$prior = \get_current_user_id();
		try {
			\wp_set_current_user( $user->ID );
			if ( ! Capabilities::can( Capabilities::READ ) ) {
				$this->fail( $key, $owner, 'the asker no longer reads', $searches );
				return null;
			}
			return $read();
		} finally {
			\wp_set_current_user( $prior );
		}
	}

	/**
	 * Each search's items, as `search` answers them to the signed-in user,
	 * interleaved, each once, at most MAX_ITEMS: their keys, each as the
	 * answer prompt numbers it, and their tiers, in `Intel_Event::TIERS`
	 * order.
	 *
	 * @param list<Search> $searches The plan's searches.
	 * @return array{0:list<string>,1:list<array<string,mixed>>,2:list<string>}
	 * @throws \InvalidArgumentException When the search command refuses one.
	 */
	private static function read( array $searches ): array {
		$args  = Core::arr( Command_Interpreter_Node::declared_commands( Intelligence_CI_Node::class )['search']['args'] ?? null );
		$found = [];
		foreach ( $searches as $search ) {
			$found[] = \array_values( Core::arr( Graph_Query::search( Command_Args::bind( $args, self::tokens( $search ) ) )['items'] ) );
		}
		$keys    = [];
		$given   = [];
		$longest = \max( 0, ...\array_map( 'count', $found ) );
		for ( $at = 0; $at < $longest && \count( $keys ) < self::MAX_ITEMS; $at++ ) {
			foreach ( $found as $items ) {
				$item = Core::arr( $items[ $at ] ?? null );
				$key  = Core::as_string( $item['item_key'] ?? null );
				if ( [] !== $item && ! \in_array( $key, $keys, true ) && \count( $keys ) < self::MAX_ITEMS ) {
					$keys[]  = $key;
					$given[] = self::numbered( $item, \count( $keys ) );
				}
			}
		}
		$tiers = \array_values( \array_intersect( Intel_Event::TIERS, \array_map( static fn ( array $item ): string => Core::as_string( $item['tier'] ), $given ) ) );
		return [ $keys, $given, $tiers ];
	}

	/**
	 * A planned search as the `search` command's tokens: each field as the arg
	 * SEARCH_FIELDS names, an entity named by its key, days as the first of
	 * that many UTC days ending today, and a page of MAX_ITEMS.
	 *
	 * @param Search $search The search.
	 * @return list<string>
	 */
	private static function tokens( array $search ): array {
		$tokens = [ '--limit=' . self::MAX_ITEMS ];
		foreach ( $search as $field => $value ) {
			$arg    = Ask_Prompts::SEARCH_FIELDS[ $field ];
			$values = match ( $field ) {
				'entity' => [ self::entity_key( Core::as_string( $value ) ) ],
				'days'   => [ \gmdate( 'Y-m-d', Graph_Query::last_days( Core::as_int( $value ) )[0] ) ],
				default  => \array_map( Core::as_string( ... ), (array) $value ),
			};
			foreach ( $values as $one ) {
				$tokens[] = "--{$arg}={$one}";
			}
		}
		return $tokens;
	}

	/**
	 * The key of the entity a name names, read as `names` reads it for the
	 * signed-in user: one whose shown name is the whole of it, case and
	 * punctuation aside; else the name as given, which matches no entity
	 * the caller cannot see, so the search narrows to nothing.
	 *
	 * @param string $name The name the plan gave.
	 */
	private static function entity_key( string $name ): string {
		try {
			$named = Core::arr( Graph_Query::names( [ 'prefix' => $name ] )['entities'] );
		} catch ( \InvalidArgumentException ) {
			return $name;
		}
		$norm = Entity_Index::norm( $name );
		foreach ( $named as $entity ) {
			$entity = Core::arr( $entity );
			if ( Entity_Index::norm( Core::as_string( $entity['name'] ?? null ) ) === $norm ) {
				return Core::as_string( $entity['entity_key'] ?? null );
			}
		}
		return $name;
	}

	/**
	 * An item as the answer prompt is given it: its number, what it is
	 * (`Prompts::source_label()`) and its tier, then what it says, and the
	 * entities it is about, an open client marked so.
	 *
	 * @param array<array-key,mixed> $item An item as `search` answers it.
	 * @param int                    $n    Its number.
	 * @return array<string,mixed>
	 */
	private static function numbered( array $item, int $n ): array {
		$about = [];
		foreach ( Core::arr( $item['entities'] ?? null ) as $entity ) {
			$entity  = Core::arr( $entity );
			$about[] = Core::as_string( $entity['name'] ?? $entity['entity_key'] ?? null ) . ( true === ( $entity['client'] ?? null ) ? ' (client)' : '' );
		}
		$day = Core::as_int( $item['item_ts'] ?? null );
		return [
			'n'       => $n,
			'what'    => Prompts::source_label( Core::as_string( $item['source'] ?? null ) ),
			'tier'    => Core::as_string( $item['tier'] ?? null ),
			'source'  => Core::as_string( $item['source'] ?? null ),
			'day'     => 0 < $day ? \gmdate( 'Y-m-d', $day ) : '',
			'kind'    => Core::as_string( $item['kind'] ?? null ),
			'title'   => Core::as_string( $item['title'] ?? null ),
			'summary' => Core::as_string( $item['summary'] ?? null ),
			'excerpt' => Core::as_string( $item['excerpt'] ?? null ),
			'about'   => $about,
		];
	}

	/**
	 * Record an answer: its sentences that cite only the items given, those
	 * items numbered again in citation order, the tiers of every item given
	 * and the searches run, the trailing sentences dropped until the event
	 * fits one line, the searches kept.
	 *
	 * @param string                 $key     The ask.
	 * @param int                    $owner   The asker.
	 * @param array<array-key,mixed> $carry   The answer call's carry: the items given, in their numbers' order, their tiers and the searches.
	 * @param string                 $content The answer call's reply.
	 */
	private function record( string $key, int $owner, array $carry, string $content ): void {
		$keys      = \array_values( \array_map( Core::as_string( ... ), Core::arr( $carry['keys'] ?? null ) ) );
		$tiers     = \array_values( \array_map( Core::as_string( ... ), Core::arr( $carry['tiers'] ?? null ) ) );
		$searches  = Core::arr( $carry['searches'] ?? null );
		$parsed    = Ask_Prompts::parse_answer( $content, \count( $keys ) );
		$sentences = $parsed['sentences'];
		$dropped   = $parsed['dropped'];
		$always    = static fn (): bool => true;
		do {
			[ $said, $items ] = Graph_Asks::cited( $sentences, $keys, $always );
			$fields           = self::answer_fields( $key, $owner, \count( $keys ), $dropped, $said, $items, $tiers, $searches );
			$fits             = [] === $sentences || Intel_Event::fits( 'ask_answered', $fields, Intel_Event::machine( self::STAGE ), \NEWSPACK_INTELLIGENCE_VERSION );
			if ( ! $fits ) {
				\array_pop( $sentences );
				++$dropped;
			}
		} while ( ! $fits );
		$this->emit( $this->writer, Intel_Event::machine_event( self::STAGE, 'ask_answered', $fields ) );
	}

	/**
	 * An `ask_answered` event's fields.
	 *
	 * @param string         $key       The ask.
	 * @param int            $owner     The asker.
	 * @param int            $found     The items the answer was given.
	 * @param int            $dropped   The sentences left out.
	 * @param list<Sentence>         $sentences The sentences kept.
	 * @param list<string>           $items     The items they cite.
	 * @param list<string>           $tiers     The tiers of the items it was given.
	 * @param array<array-key,mixed> $searches  The searches its plan ran.
	 * @return array<string,mixed>
	 */
	private static function answer_fields( string $key, int $owner, int $found, int $dropped, array $sentences, array $items, array $tiers, array $searches ): array {
		return [ 'ask_key' => $key, 'owner' => $owner, 'found' => $found, 'dropped' => $dropped, 'sentences' => $sentences, 'items' => $items, 'tiers' => $tiers, 'searches' => $searches ];
	}

	/**
	 * Record an ask's failure, its reason terminal-safe and bounded, and the
	 * searches its plan ran, none before a plan.
	 *
	 * @param string                 $key      The ask.
	 * @param int                    $owner    The asker.
	 * @param string                 $reason   Why.
	 * @param array<array-key,mixed> $searches The searches.
	 */
	private function fail( string $key, int $owner, string $reason, array $searches ): void {
		$this->emit( $this->writer, Intel_Event::machine_event( self::STAGE, 'ask_failed', [ 'ask_key' => $key, 'owner' => $owner, 'reason' => \mb_strcut( Core::terminal_safe( $reason ), 0, self::REASON_BYTES ), 'searches' => $searches ] ) );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Answers each asked question as its asker: plans searches, reads them under the asker\'s tiers and writes an answer whose every sentence cites the items it was given.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The writer every outcome is addressed to: intel:writer.' ],
				[ 'name' => 'llm', 'type' => 'node_name', 'required' => true, 'description' => 'The model-call node both calls are asked of: intel:llm.' ],
			],
			'commands'     => [],
			'accepts_fill' => true,
			'has_target'   => false,
		];
	}
}

<?php
/**
 * Extractor_Node: extracts each resolved item that links something. Fed the
 * `{ item, links, proposals }` resolution the Resolver forwards, it skips an
 * item with no link and no candidate, and asks any other's extraction of
 * the model-call node its `make_node` line names, `intel:llm`, naming the
 * `extract` prompt, its inputs the topics it offers by name and key, the
 * profile, the audience and the name of the open client that published
 * the item, '' for none, and carrying the resolution less its item, so
 * the answer finishes it (`Asks_Model`); the prompt offers the themes of
 * the items most like it as the projection holds them when it is sent.
 * It writes an `extraction_recorded` naming topic keys plus a
 * `claim_asserted` per claim whose subject is an entity the item links,
 * addressed by TO to the writer its `make_node` line names (substrate
 * ADR-19). A call's extraction also writes a `subjects_extracted` naming
 * the entities it was given as asserted, the set the Resolver settles links
 * by. Each extraction then forwards an `item_extracted` struct `{ item,
 * relevance, importance }` to its target, the deduper. Its importance is at
 * least the floor `add_importance_floor` sets for its kind when its relevance
 * is at least the floor's; the heuristic stand-in, kind `other` at relevance
 * 0, takes only a floor that names no relevance. The Resolver forwards an
 * item the projection holds an extraction of only when it asserts a link
 * that extraction was not given, or when its record is owed an extraction
 * (`extract`), so an item is extracted again to record claims about a
 * subject accepted or confirmed since, or when `ontology reextract` asks;
 * the projector replaces the item's claims and topics with the new
 * extraction's.
 *
 * A claim's subject and object resolve through the entities whose names
 * spell the whole of them. The subject is the one of those the item links
 * by an asserted link, so a claim about a merely proposed subject asserts
 * nothing; the object is the only one, or the only one the item links, and
 * otherwise stays text. A claim takes the confidence of its subject's link.
 * When the vault group lends no credential, the call fails or its reply does
 * not parse, an item that has not been extracted by any call records a
 * heuristic extraction under its own actor, so it is marked extracted rather
 * than retried in a loop. A call made that fails, or an answer whose
 * extraction throws, also parks a re-resolution of the item on the Delay its
 * `make_node` line names after the writer, with exponential backoff, until
 * MAX_ATTEMPTS; the struct it reads carries the `attempt` that counts them, and
 * the retry stays owed its extraction when the struct was (`extract`), whose
 * request waits behind new work. The topics offered come from the process's
 * shared `Entity_Index::current()`, the one the Resolver reads; an
 * extraction's theme is written only when its answer names one; and the
 * names
 * claims resolve through from the one current when the answer lands. Each
 * extraction publishes its status: heuristic, naming the group, a call that
 * answered, or the failure of one that did not.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Publishes_Status;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

class Extractor_Node extends Node {
	use Schema_Reflection;
	use Emits_Struct;
	use Asks_Model;
	use Publishes_Status;

	/** The struct kind forwarded to the target once an item is extracted. */
	public const EXTRACTED = 'item_extracted';

	/** The stage a heuristic extraction is recorded under, standing in for a call. */
	public const HEURISTIC_STAGE = 'extract-heuristic';

	/** Seconds before the first retry of a failed call; each later one doubles it. */
	public const RETRY_BASE_S = 300;

	/** The longest wait before a retry. */
	public const RETRY_MAX_S = 3600;

	/** The attempts after which a failed call is no longer retried. */
	public const MAX_ATTEMPTS = 5;

	/** The highest importance floor a kind takes, one below the highest score. */
	public const MAX_FLOOR = Prompts::MAX_SCORE - 1;

	/** Every key a status carries, null, so each publish clears what its outcome leaves out. */
	public const BLANK_STATUS = [
		'mode'          => null,
		'vault_group'   => null,
		'last_ok_at'    => null,
		'last_error'    => null,
		'failing_since' => null,
	];

	/** The writer every extraction and claim is addressed to. */
	protected string $writer = '';

	/** The Delay a failed call's retry parks on. */
	protected string $later = '';

	/** Who the prompt is framed for, set via set_audience; '' names no one. */
	protected string $audience = '';

	/** @var list<string> Relevance-profile lines add_profile registered and remove_profile left, in call order. */
	protected array $profiles = [];

	/** @var array<string,array{0:int,1:int}> Each item kind's least importance and the relevance it asks, by add_importance_floor, in call order. */
	protected array $importance_floors = [];

	/** Wire the sibling `:config` interpreter for the prompt verbs. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Ask for one resolution's extraction, or finish it with the answer
	 * `intel:llm` sends back. A resolution or an answer it cannot read is
	 * dropped with a rate-limited line; an item linking nothing is skipped.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	public function fill( array $message ): void {
		if ( self::is_answer( $message ) ) {
			$this->answered( $message );
			return;
		}
		$value = $message[ Message::VALUE ];
		$read      = 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) ? null : self::resolution_of( $value );
		$publisher = Core::arr( $value )['publisher'] ?? null;
		if ( null === $read || ! \is_string( $publisher ) ) {
			$this->print_less_often( 'dropping a resolution it cannot read from ', Core::terminal_safe( Core::as_string( $message[ Message::FROM ] ) ) );
			return;
		}
		[ $item, $linked, , , $owed ] = $read;
		if ( [] === $linked && [] === Core::arr( Core::arr( $value )['proposals'] ) ) {
			return;
		}
		$inputs = [
			'topics'    => Entity_Index::current()->topics(),
			'profile'   => \implode( "\n", $this->profiles ),
			'audience'  => $this->audience,
			'publisher' => $this->client_name( $publisher ),
		];
		$this->ask_model( 'extract', $item, $inputs, [ 'max_tokens' => 800, 'temperature' => 0.2 ], [ 'resolution' => \array_diff_key( Core::arr( $value ), [ 'item' => true ] ) ], $owed );
	}

	/**
	 * The name of the open client whose site published the item, read as
	 * the publisher's survivor in the shared index; '' when no entity
	 * published it, its survivor holds no open `client` role, or the
	 * projection will not open, logged.
	 *
	 * @param string $publisher The publisher's entity key; '' for none.
	 */
	private function client_name( string $publisher ): string {
		$merged   = Entity_Index::current()->merged();
		$survivor = '' === $publisher ? '' : ( $merged[ $publisher ] ?? $publisher );
		if ( '' === $survivor ) {
			return '';
		}
		$keys = Graph_Query::inverse( $merged )[ $survivor ] ?? [ $survivor ];
		return Core::as_string(
			Intel_Graph_Row_Schema::read(
				static function ( Row_Reader $r ) use ( $survivor, $keys ): string {
					if ( ! isset( Graph_Query::open_roles( $r, [ $survivor => $keys ], [ Graph_Query::CLIENT ] )[ $survivor ] ) ) {
						return '';
					}
					return Core::as_string( $r->rows( 'SELECT ' . Graph_Query::name_of( $r, 'k.entity_key' ) . ' FROM ' . Intel_Graph_Row_Schema::all( $r, 'entities' ) . ' k WHERE k.entity_key = ? LIMIT 1', [ $survivor ] )[0][0] ?? null );
				},
				"{$this->name}: no intel-graph to read the publisher from; naming none: "
			)
		);
	}

	/**
	 * Finish the extraction an answer carries the resolution of; one whose
	 * extraction throws is logged and parks the item's retry, so nothing
	 * throws into the event loop but a cooperative stop (ADR-14).
	 *
	 * @param array<int,mixed> $message The answer: `{ content | error | unlent, item, inputs, carry }`.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answered( array $message ): void {
		$answer = $this->answer_of( $message );
		$read   = null === $answer ? null : self::resolution_of( [ 'item' => $answer[1] ] + Core::arr( $answer[3]['resolution'] ?? null ) );
		$topics = $answer[2]['topics'] ?? null;
		if ( null === $answer || null === $read || ! \is_array( $topics ) ) {
			if ( null !== $answer ) {
				$this->unreadable( $message );
			}
			return;
		}
		[ $item, $linked, $extracted, $attempt, $owed ] = $read;
		$item_key                                        = Core::as_string( $item['item_key'] );
		$retry = fn () => $this->retry( $item_key, $attempt, $owed );
		$this->finish_answer(
			$item_key,
			function () use ( $item, $linked, $extracted, $owed, $attempt, $topics, $answer, $retry ): void {
				if ( $this->extract( $item, $linked, $extracted, $owed, $attempt, \array_map( Core::as_string( ... ), $topics ), $answer[0] ) ) {
					$retry();
				}
			},
			$retry
		);
	}

	/**
	 * A resolution as the Resolver forwards it: the item, each linked entity
	 * => its link's confidence, whether it is asserted, and whether by an
	 * identifier, whether a call extracted the item before, the failed calls
	 * made for it, and whether it is owed an extraction whatever it holds;
	 * null when any of them does not read.
	 *
	 * @param mixed $value The resolution.
	 * @return array{0:array<string,mixed>,1:array<string,array{0:float,1:bool,2:bool}>,2:bool,3:int,4:bool}|null
	 */
	private static function resolution_of( mixed $value ): ?array {
		$value     = Core::arr( $value );
		$item      = Intel_Event::item_of( Core::arr( $value['item'] ?? null ) );
		$links     = $value['links'] ?? null;
		$extracted = $value['extracted'] ?? null;
		$attempt   = $value['attempt'] ?? null;
		$owed      = $value['extract'] ?? null;
		if ( null === $item || ! \is_array( $links ) || ! \is_array( $value['proposals'] ?? null ) || ! \is_bool( $extracted ) || ! \is_int( $attempt ) || ! \is_bool( $owed ) ) {
			return null;
		}
		$linked = [];
		foreach ( $links as $link ) {
			$link           = Core::arr( $link );
			$key            = Core::as_string( $link['entity_key'] ?? null );
			$asserted       = 'asserted' === ( $link['state'] ?? null );
			$linked[ $key ] = [ Core::as_float( $link['confidence'] ?? null ), $asserted, $asserted && 'identifier' === ( $link['method'] ?? null ) ];
		}
		return [ $item, $linked, $extracted, $attempt, $owed ];
	}

	/**
	 * Write the item's extraction from the answer, or its heuristic stand-in
	 * under its own stage, dated by the item's retrieval; an item a call
	 * extracted before keeps that extraction when this call fails, which was
	 * not given the link that sent it, so its next re-resolution tries again.
	 * A call made that fails owes the item's retry, which the caller parks
	 * once, after the write.
	 *
	 * @param array<string,mixed>                        $item      The item.
	 * @param array<string,array{0:float,1:bool,2:bool}> $linked    Each linked entity => its link's confidence, whether it is asserted, and whether by an identifier.
	 * @param bool                                       $extracted Whether a call extracted the item before.
	 * @param bool                                       $owed      Whether it was sent to be extracted whatever it holds.
	 * @param int                                        $attempt   The failed calls made for the item so far.
	 * @param array<array-key,string>                    $topics    Each topic name the request offered => its key.
	 * @param array<array-key,mixed>                     $answer    The answer: its `content`, `error` or `unlent`.
	 * @return bool Whether a call was made and failed, so a retry is owed.
	 */
	private function extract( array $item, array $linked, bool $extracted, bool $owed, int $attempt, array $topics, array $answer ): bool {
		$content = $answer['content'] ?? null;
		$unlent  = $answer['unlent'] ?? null;
		$parsed  = \is_string( $content ) ? Prompts::parse_extract( $content, \array_map( 'strval', \array_keys( $topics ) ) ) : null;
		$fault   = Core::as_string( $answer['error'] ?? 'AI proxy answer did not parse as an extraction' );
		if ( isset( $answer['error'] ) ) {
			$this->print_less_often( 'extraction call failed: ', Core::terminal_safe( $fault ) );
		}
		$item_key = Core::as_string( $item['item_key'] );
		$failed   = ! \is_string( $unlent ) && null === $parsed;
		if ( \is_string( $unlent ) ) {
			$this->publish_snapshot(
				[
					'mode'        => 'heuristic',
					'vault_group' => $unlent,
				]
			);
		} elseif ( $failed ) {
			$this->publish_failing( $fault );
		} else {
			$this->publish_snapshot( [ 'mode' => 'call', 'last_ok_at' => (int) Core::$now ] );
		}
		if ( null === $parsed && $extracted ) {
			$this->print_less_often( 'keeping the earlier extraction of ', Core::terminal_safe( $item_key ) . ': no call answered' );
			return $failed;
		}
		$index    = Entity_Index::current();
		$stage    = null === $parsed ? self::HEURISTIC_STAGE : 'extract';
		$parsed ??= [ 'summary' => self::heuristic_summary( $item ), 'kind' => 'other', 'topics' => [], 'relevance' => 0, 'importance' => 0, 'sentiment' => 0.0, 'claims' => [] ];
		[ $floor, $needs ] = $this->importance_floors[ $parsed['kind'] ] ?? [ 0, 0 ];
		if ( $parsed['relevance'] >= $needs ) {
			$parsed['importance'] = \max( $parsed['importance'], $floor );
		}
		$fields   = [
			'item_key'     => $item_key,
			'summary'      => $parsed['summary'],
			'kind'         => $parsed['kind'],
			'relevance'    => $parsed['relevance'],
			'importance'   => $parsed['importance'],
			'sentiment'    => $parsed['sentiment'],
			'topics'       => \array_map( static fn ( string $name ): string => $topics[ $name ], $parsed['topics'] ),
			'retrieved_ts' => $item['retrieved_ts'],
		] + \array_intersect_key( $parsed, [ 'theme' => true ] );
		$this->emit( $this->writer, Intel_Event::machine_event( $stage, 'extraction_recorded', $fields ) );
		if ( self::HEURISTIC_STAGE !== $stage ) {
			$given = \array_keys( \array_filter( $linked, static fn ( array $link ): bool => $link[1] ) );
			$this->emit( $this->writer, Intel_Event::machine_event( $stage, 'subjects_extracted', [ 'item_key' => $item_key, 'entity_keys' => $given, 'retrieved_ts' => $item['retrieved_ts'] ] ) );
		}
		$this->emit( '', [ 'event' => self::EXTRACTED, 'item' => $item, 'relevance' => $parsed['relevance'], 'importance' => $parsed['importance'] ] );
		$ord = 0;
		foreach ( $parsed['claims'] as $claim ) {
			$subjects = \array_values( \array_filter( self::entities_named( $index, $claim['subject'], $linked ), static fn ( string $key ): bool => $linked[ $key ][1] ?? false ) );
			if ( 1 !== \count( $subjects ) ) {
				continue;
			}
			$subject = $subjects[0];
			$objects = self::entities_named( $index, $claim['object'], $linked );
			$among   = \array_values( \array_filter( $objects, static fn ( string $key ): bool => isset( $linked[ $key ] ) ) );
			$object  = 1 === \count( $objects ) ? $objects[0] : ( 1 === \count( $among ) ? $among[0] : null );
			$fields  = [
				'item_key'     => $item_key,
				'ord'          => $ord++,
				'subject_key'  => $subject,
				'predicate'    => $claim['predicate'],
				'object_key'   => $object ?? '',
				'object_text'  => $claim['object'],
				'confidence'   => $linked[ $subject ][0],
				'retrieved_ts' => $item['retrieved_ts'],
			];
			$this->emit( $this->writer, Intel_Event::machine_event( 'extract', 'claim_asserted', $fields ) );
		}
		return $failed;
	}

	/**
	 * Publish a failed call: call, the last answer held, $fault as
	 * `status_text()` renders it, and the failing_since already published.
	 *
	 * @param string $fault Why the call failed.
	 */
	private function publish_failing( string $fault ): void {
		$this->publish_snapshot(
			[
				'mode'          => 'call',
				'last_ok_at'    => $this->last_status()['last_ok_at'] ?? null,
				'last_error'    => self::status_text( $fault ),
				'failing_since' => $this->status_since( 'failing_since' ),
			]
		);
	}

	/**
	 * Publish a status of every key, those $fields leaves out null.
	 *
	 * @param array<string,mixed> $fields The keys the outcome carries.
	 */
	private function publish_snapshot( array $fields ): void {
		$this->publish_status( [ ...self::BLANK_STATUS, ...$fields ] );
	}

	/**
	 * Park a failed call's retry on the later queue, keyed by its item: a
	 * re-resolution one attempt on, due `RETRY_BASE_S * 2 ** $attempt`
	 * seconds from now, at most RETRY_MAX_S, so every retry lands inside the
	 * day the queue keeps. The replay is a re-resolution, which forwards the
	 * item for extraction only while it is still owed one; an item sent to be
	 * extracted whatever it holds stays so. From MAX_ATTEMPTS on it parks
	 * nothing.
	 *
	 * @param string $item_key The item.
	 * @param int    $attempt  The failed calls made for it before this one.
	 * @param bool   $owed     Whether it was sent to be extracted whatever it holds.
	 */
	private function retry( string $item_key, int $attempt, bool $owed ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->print_less_often( 'giving up extracting ', Core::terminal_safe( $item_key ) . " after {$attempt} attempts" );
			return;
		}
		$backoff = \min( self::RETRY_MAX_S, self::RETRY_BASE_S * ( 2 ** $attempt ) );
		$record  = Resolver_Node::reresolve( $item_key, 0, [ 'attempt' => $attempt + 1 ] + ( $owed ? [ 'extract' => true ] : [] ) ) + [ 'not_before' => Core::$now + $backoff ];
		$this->emit( $this->later, $record, $item_key );
	}

	/**
	 * The heuristic summary: the first sentence of the title, then of the
	 * body, each as `Entity_Index::sentences()` reads it, cut to the summary
	 * length.
	 *
	 * @param array<string,mixed> $item The item.
	 */
	private static function heuristic_summary( array $item ): string {
		$sentences = [];
		foreach ( [ 'title', 'body' ] as $field ) {
			$sentence = Entity_Index::sentences( Core::as_string( $item[ $field ] ) )[0] ?? null;
			if ( null !== $sentence ) {
				$sentences[] = 1 === \preg_match( '/[.!?]$/u', $sentence ) ? $sentence : "{$sentence}.";
			}
		}
		return \mb_substr( \implode( ' ', $sentences ), 0, Prompts::SUMMARY_CHARS );
	}

	/**
	 * Every entity a name spells whole, each once: localities aside, and a
	 * stoplisted name unless the item asserts its entity by an identifier.
	 *
	 * @param Entity_Index                               $index  The entities.
	 * @param string                                     $text   A claim's subject or object.
	 * @param array<string,array{0:float,1:bool,2:bool}> $linked The item's links, as `extract()` takes them.
	 * @return list<string>
	 */
	private static function entities_named( Entity_Index $index, string $text, array $linked ): array {
		$norm = Entity_Index::norm( $text );
		$keys = [];
		foreach ( $index->names_in( $text, false ) as $hit ) {
			if ( 'locality' !== $hit['kind'] && ( ! $hit['stoplisted'] || ( $linked[ $hit['entity_key'] ][2] ?? false ) ) && Entity_Index::norm( $hit['surface'] ) === $norm ) {
				$keys[ $hit['entity_key'] ] = $hit['entity_key'];
			}
		}
		return \array_values( $keys );
	}

	/**
	 * Seconds the cache keeps a status: a day, since the extractor runs only
	 * when an item reaches it.
	 *
	 * @api Used by substrate.
	 */
	protected function status_ttl(): int {
		return 86400;
	}

	/**
	 * Seconds after which an unchanged status is written again.
	 *
	 * @api Used by substrate.
	 */
	protected function status_heartbeat(): int {
		return 300;
	}

	/**
	 * The `set_audience` verb's entry point, which the declared setter calls.
	 *
	 * @param string $audience Who the prompt is framed for.
	 */
	public function set_audience( string $audience ): void {
		$this->audience = $audience;
	}

	/**
	 * Replay the prompt verbs, then one `add_importance_floor` line per floor,
	 * in the order they were set, its relevance when not 0.
	 */
	public function dump_config(): string {
		$out = parent::dump_config() . $this->dump_setters() . $this->dump_lists();
		foreach ( $this->importance_floors as $kind => [ $min, $relevance ] ) {
			$out .= $this->config_line( 'add_importance_floor', $kind, (string) $min, ...( 0 === $relevance ? [] : [ (string) $relevance ] ) );
		}
		return $out;
	}

	/**
	 * Set a kind's floor, replacing any it had: every extraction of that
	 * kind at least $relevance relevant scores at least $min. The heuristic
	 * stand-in, kind `other` at relevance 0, takes it only when $relevance
	 * is 0.
	 *
	 * @param string $kind      A kind from `Intel_Event::ITEM_KINDS`.
	 * @param int    $min       From 0 to MAX_FLOOR.
	 * @param int    $relevance From 0 to `Prompts::MAX_SCORE`.
	 * @throws \InvalidArgumentException On any other kind, min or relevance.
	 */
	public function add_importance_floor( string $kind, int $min, int $relevance = 0 ): void {
		if ( ! \in_array( $kind, Intel_Event::ITEM_KINDS, true ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "add_importance_floor: '{$kind}' is not an item kind" );
		}
		if ( $min < 0 || $min > self::MAX_FLOOR ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( 'add_importance_floor: a floor is 0 to ' . self::MAX_FLOOR . "; got {$min}" );
		}
		if ( $relevance < 0 || $relevance > Prompts::MAX_SCORE ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( 'add_importance_floor: a relevance is 0 to ' . Prompts::MAX_SCORE . "; got {$relevance}" );
		}
		$this->importance_floors[ $kind ] = [ $min, $relevance ];
	}

	/**
	 * Drop a kind's floor.
	 *
	 * @param string $kind The kind.
	 * @throws \InvalidArgumentException When the kind has none.
	 */
	public function remove_importance_floor( string $kind ): void {
		if ( ! isset( $this->importance_floors[ $kind ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "remove_importance_floor: '{$kind}' has no floor" );
		}
		unset( $this->importance_floors[ $kind ] );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		$kind = [ 'name' => 'kind', 'type' => 'string', 'required' => true, 'description' => 'An item kind: ' . \implode( ', ', Intel_Event::ITEM_KINDS ) . '.' ];
		return [
			'category'     => 'Transform',
			'description'  => 'Extracts each resolved item that links something: its summary, kind, topics, scores and claims; forwards the item and its scores.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The event writer every extraction and claim is addressed to: intel:writer.' ],
				[ 'name' => 'later', 'type' => 'node_name', 'required' => true, 'description' => 'The Delay a failed call\'s retry parks on: intel:later.' ],
				[ 'name' => 'llm', 'type' => 'node_name', 'required' => true, 'description' => 'The model-call node every extraction is asked of: intel:llm.' ],
			],
			'commands'     => [
				[
					'name'        => 'set_audience',
					'description' => 'Set who the prompt is framed for: <audience>.',
					'args'        => [
						[ 'name' => 'audience', 'type' => 'string', 'required' => true ],
					],
					'setter'      => 'audience',
				],
				...self::list_verbs( 'profile', 'profiles', [ 'name' => 'text', 'type' => 'string', 'required' => true, 'variadic' => true ], 'Add a relevance-profile line used to score items: <text>.' ),
				[
					'name'        => 'add_importance_floor',
					'description' => 'Score every extraction of a kind at least <min>, 0 to ' . self::MAX_FLOOR . ', when its relevance is at least <relevance>, 0 to ' . Prompts::MAX_SCORE . ' and 0 when omitted, replacing its floor: <kind> <min> [<relevance>].',
					'args'        => [ $kind, [ 'name' => 'min', 'type' => 'int', 'required' => true ], [ 'name' => 'relevance', 'type' => 'int', 'required' => false, 'default' => 0 ] ],
					'multiple'    => true,
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $extractor */
						$extractor = $interpreter->patron();
						$extractor->add_importance_floor( Core::as_string( $args['kind'] ), Core::as_int( $args['min'] ), Core::as_int( $args['relevance'] ) );
						return "ok\n";
					},
				],
				[
					'name'        => 'remove_importance_floor',
					'description' => 'Undo add_importance_floor: drop the floor of <kind>. Refused when it has none.',
					'args'        => [ $kind ],
					'action'      => true,
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						/** @var self $extractor */
						$extractor = $interpreter->patron();
						$extractor->remove_importance_floor( Core::as_string( $args['kind'] ) );
						return "ok\n";
					},
				],
			],
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

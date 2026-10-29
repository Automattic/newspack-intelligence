<?php
/**
 * Extractor_Node: extracts each resolved item that links something. Fed the
 * `{ item, links, proposals }` resolution the Resolver forwards, it skips an
 * item with no link and no candidate, and makes one LLM call for any other.
 * It writes an `extraction_recorded` naming topic keys plus a
 * `claim_asserted` per claim whose subject is an entity the item links,
 * addressed by TO to the writer its `make_node` line names (substrate
 * ADR-19). A call's extraction also writes a `subjects_extracted` naming
 * the entities it was given as asserted, the set the Resolver settles links
 * by. Each extraction then forwards an `item_extracted` struct `{ item,
 * relevance, importance }` to its target, the deduper. The Resolver forwards
 * an item the projection holds an extraction of only when it asserts a link
 * that extraction was not given, so an item is extracted again only to record
 * claims about a subject accepted or confirmed since; the projector replaces
 * the item's claims and topics with the new extraction's.
 *
 * A claim's subject and object resolve through the entities whose names
 * spell the whole of them. The subject is the one of those the item links
 * by an asserted link, so a claim about a merely proposed subject asserts
 * nothing; the object is the only one, or the only one the item links, and
 * otherwise stays text. A claim takes the confidence of its subject's link.
 * With no client, a thrown call or a reply that does not parse, an item no
 * call has extracted records a heuristic extraction under its own actor, so
 * it is marked extracted rather than retried in a loop. A call made that
 * fails also parks a re-resolution of the item on the Delay its `make_node`
 * line names after the writer, with exponential backoff, until MAX_ATTEMPTS;
 * the struct it reads carries the `attempt` that counts them. The topics
 * offered and the names claims resolve through come from the process's
 * shared `Entity_Index::current()`, the one the Resolver reads.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

class Extractor_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;
	use Emits_Struct;

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

	/** The writer every extraction and claim is addressed to. */
	protected string $writer = '';

	/** The Delay a failed call's retry parks on. */
	protected string $later = '';

	/** Wire the sibling `:config` interpreter for the LLM verbs. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Extract one resolution. A resolution it cannot read is dropped with a
	 * rate-limited line; an item linking nothing is skipped.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		$value     = Core::arr( $message[ Message::VALUE ] );
		$item      = Intel_Event::item_of( Core::arr( $value['item'] ?? null ) );
		$links     = $value['links'] ?? null;
		$proposals = $value['proposals'] ?? null;
		$extracted = $value['extracted'] ?? null;
		$attempt   = $value['attempt'] ?? null;
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || null === $item || ! \is_array( $links ) || ! \is_array( $proposals ) || ! \is_bool( $extracted ) || ! \is_int( $attempt ) ) {
			$this->print_less_often( 'dropping a resolution it cannot read from ', Core::as_string( $message[ Message::FROM ] ) );
			return;
		}
		if ( [] === $links && [] === $proposals ) {
			return;
		}
		$linked = [];
		foreach ( $links as $link ) {
			$link = Core::arr( $link );
			$key  = Core::as_string( $link['entity_key'] ?? null );
			$linked[ $key ] = [ Core::as_float( $link['confidence'] ?? null ), 'asserted' === ( $link['state'] ?? null ) ];
		}
		$this->extract( $item, $linked, $extracted, $attempt, Entity_Index::current() );
	}

	/**
	 * Ask for the item's extraction and write it, or its heuristic stand-in
	 * under its own stage, dated by the item's retrieval; an item a call
	 * extracted before keeps that extraction when this call fails, which was
	 * not given the link that sent it, so its next re-resolution tries again.
	 * A call made that fails also parks the item's retry.
	 *
	 * @param array<string,mixed>                 $item      The item.
	 * @param array<string,array{0:float,1:bool}> $linked    Each linked entity => its link's confidence and whether it is asserted.
	 * @param bool                                $extracted Whether a call extracted the item before.
	 * @param int                                 $attempt   The failed calls made for the item so far.
	 * @param Entity_Index                        $index     The entities claims resolve through.
	 */
	private function extract( array $item, array $linked, bool $extracted, int $attempt, Entity_Index $index ): void {
		$topics = $index->topics();
		$client = $this->make_llm_client();
		$parsed = null;
		if ( null !== $client ) {
			try {
				$names  = \array_keys( $topics );
				$parsed = Prompts::parse_extract( $client->chat( Prompts::extract( $item, $names, $this->relevance_profile() ), [ 'max_tokens' => 800, 'temperature' => 0.2 ] ), $names );
			} catch ( Worker_Should_Stop $stop ) {
				throw $stop;
			} catch ( \RuntimeException $e ) {
				$this->print_less_often( 'extraction call failed: ', $e->getMessage() );
			}
		}
		$item_key = Core::as_string( $item['item_key'] );
		if ( null !== $client && null === $parsed ) {
			$this->retry( $item_key, $attempt );
		}
		if ( null === $parsed && $extracted ) {
			$this->print_less_often( 'keeping the earlier extraction of ', "{$item_key}: no call answered" );
			return;
		}
		$stage    = null === $parsed ? self::HEURISTIC_STAGE : 'extract';
		$parsed ??= [ 'summary' => self::heuristic_summary( $item ), 'kind' => 'other', 'topics' => [], 'relevance' => 0, 'importance' => 0, 'sentiment' => 0.0, 'claims' => [] ];
		$fields   = [
			'item_key'     => $item_key,
			'summary'      => $parsed['summary'],
			'kind'         => $parsed['kind'],
			'relevance'    => $parsed['relevance'],
			'importance'   => $parsed['importance'],
			'sentiment'    => $parsed['sentiment'],
			'topics'       => \array_map( static fn ( string $name ): string => $topics[ $name ], $parsed['topics'] ),
			'retrieved_ts' => $item['retrieved_ts'],
		];
		$this->emit( $this->writer, Intel_Event::machine_event( $stage, 'extraction_recorded', $fields ) );
		if ( self::HEURISTIC_STAGE !== $stage ) {
			$given = \array_keys( \array_filter( $linked, static fn ( array $link ): bool => $link[1] ) );
			$this->emit( $this->writer, Intel_Event::machine_event( $stage, 'subjects_extracted', [ 'item_key' => $item_key, 'entity_keys' => $given, 'retrieved_ts' => $item['retrieved_ts'] ] ) );
		}
		$this->emit( '', [ 'event' => self::EXTRACTED, 'item' => $item, 'relevance' => $parsed['relevance'], 'importance' => $parsed['importance'] ] );
		$ord = 0;
		foreach ( $parsed['claims'] as $claim ) {
			$subjects = \array_values( \array_filter( self::entities_named( $index, $claim['subject'] ), static fn ( string $key ): bool => $linked[ $key ][1] ?? false ) );
			if ( 1 !== \count( $subjects ) ) {
				continue;
			}
			$subject = $subjects[0];
			$objects = self::entities_named( $index, $claim['object'] );
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
	}

	/**
	 * Park a failed call's retry on the later queue, keyed by its item: a
	 * re-resolution one attempt on, due `RETRY_BASE_S * 2 ** $attempt`
	 * seconds from now, at most RETRY_MAX_S, so every retry lands inside the
	 * day the queue keeps. The replay is a re-resolution, which forwards the
	 * item for extraction only while it is still owed one. From MAX_ATTEMPTS
	 * on it parks nothing.
	 *
	 * @param string $item_key The item.
	 * @param int    $attempt  The failed calls made for it before this one.
	 */
	private function retry( string $item_key, int $attempt ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->print_less_often( 'giving up extracting ', "{$item_key} after {$attempt} attempts" );
			return;
		}
		$backoff = \min( self::RETRY_MAX_S, self::RETRY_BASE_S * ( 2 ** $attempt ) );
		$record  = Resolver_Node::reresolve( $item_key, 0, [ 'attempt' => $attempt + 1 ] ) + [ 'not_before' => Core::$now + $backoff ];
		$this->emit( $this->later, $record, $item_key );
	}

	/**
	 * The heuristic summary: the first sentence of the title, then of the
	 * body with its tags stripped, cut to the summary length.
	 *
	 * @param array<string,mixed> $item The item.
	 */
	private static function heuristic_summary( array $item ): string {
		$sentences = [];
		foreach ( [ 'title', 'body' ] as $field ) {
			$text = \trim( (string) \preg_replace( '/\s+/u', ' ', \wp_strip_all_tags( Core::as_string( $item[ $field ] ) ) ) );
			if ( '' === $text ) {
				continue;
			}
			$sentence    = 1 === \preg_match( '/^.+?[.!?](?=\s|$)/u', $text, $m ) ? $m[0] : $text;
			$sentences[] = 1 === \preg_match( '/[.!?]$/u', $sentence ) ? $sentence : "{$sentence}.";
		}
		return \mb_substr( \implode( ' ', $sentences ), 0, Prompts::SUMMARY_CHARS );
	}

	/**
	 * Every entity a name spells whole, localities aside, each once.
	 *
	 * @param Entity_Index $index The entities.
	 * @param string       $text  A claim's subject or object.
	 * @return list<string>
	 */
	private static function entities_named( Entity_Index $index, string $text ): array {
		$norm = Entity_Index::norm( $text );
		$keys = [];
		foreach ( $index->names_in( $text ) as $hit ) {
			if ( 'locality' !== $hit['kind'] && Entity_Index::norm( $hit['surface'] ) === $norm ) {
				$keys[ $hit['entity_key'] ] = $hit['entity_key'];
			}
		}
		return \array_values( $keys );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Extracts each resolved item that links something: its summary, kind, topics, scores and claims; forwards the item and its scores.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The event writer every extraction and claim is addressed to: intel:writer.' ],
				[ 'name' => 'later', 'type' => 'node_name', 'required' => true, 'description' => 'The Delay a failed call\'s retry parks on: intel:later.' ],
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

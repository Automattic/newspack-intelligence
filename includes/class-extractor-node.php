<?php
/**
 * Extractor_Node: extracts each resolved item that links something. Fed the
 * `{ item, links, proposals }` resolution the Resolver forwards, it skips an
 * item with no link and no candidate, and makes one LLM call for any other.
 * It writes an `extraction_recorded` naming topic keys plus a
 * `claim_asserted` per claim whose subject is an entity the item links,
 * addressed by TO to the writer its `make_node` line names (substrate
 * ADR-19). Each extraction then forwards an `item_extracted` struct `{ item,
 * relevance, importance }` to its target, the deduper. The Resolver forwards
 * an item the projection holds an extraction of only when it newly asserts a
 * link or a curator confirmed one, so an item is extracted again only to
 * record claims about a subject accepted or confirmed since; the projector
 * replaces the item's claims and topics with the new extraction's.
 *
 * A claim's subject and object resolve through the entities whose names
 * spell the whole of them. The subject is the one of those the item links
 * by an asserted link, so a claim about a merely proposed subject asserts
 * nothing; the object is the only one, or the only one the item links, and
 * otherwise stays text. A claim takes the confidence of its subject's link. With no client, a thrown call or a reply
 * that does not parse, the item still records a heuristic extraction under
 * its own actor, so it is marked extracted rather than retried. The topics
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

	/** The writer every extraction and claim is addressed to. */
	protected string $writer = '';

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
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || null === $item || ! \is_array( $links ) || ! \is_array( $proposals ) || ! \is_bool( $extracted ) ) {
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
		$this->extract( $item, $linked, $extracted, Entity_Index::current() );
	}

	/**
	 * Ask for the item's extraction and write it, or its heuristic stand-in
	 * under its own stage, dated by the item's retrieval; an item a call
	 * extracted before keeps that extraction when this call fails, since the
	 * stand-in would replace its claims and topics with none.
	 *
	 * @param array<string,mixed>                 $item      The item.
	 * @param array<string,array{0:float,1:bool}> $linked    Each linked entity => its link's confidence and whether it is asserted.
	 * @param bool                                $extracted Whether a call extracted the item before.
	 * @param Entity_Index                        $index     The entities claims resolve through.
	 */
	private function extract( array $item, array $linked, bool $extracted, Entity_Index $index ): void {
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
				'object_text'  => null === $object ? $claim['object'] : '',
				'confidence'   => $linked[ $subject ][0],
				'retrieved_ts' => $item['retrieved_ts'],
			];
			$this->emit( $this->writer, Intel_Event::machine_event( 'extract', 'claim_asserted', $fields ) );
		}
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
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

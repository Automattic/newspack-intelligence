<?php
/**
 * Resolver_Node: resolves each recorded item to the entities it is about.
 * Fed `item_recorded` events by `intel:items:resolve`, a Consumer over the
 * items stream, and `item_reresolve` records naming a stored item by
 * `intel:reresolve:resolve`, a Consumer over the stream the Reresolver
 * appends to. For each item it writes a `link_asserted` or `link_proposed`
 * per link and an `entity_proposed` per candidate, addressed by TO to the
 * writer its `make_node` line names, then forwards an `item_resolved` struct
 * `{ item, links, proposals }` to its target. Both leave through its sink,
 * so the writer is a declared destination (substrate ADR-19), as the
 * Intel_Event_Writer's two partitions are.
 *
 * A re-resolution runs the resolver's identifier, name and stem steps alone,
 * because NER ran when the item was first resolved, and forwards nothing for
 * an item the projection holds an extraction of, so an item is extracted at
 * most once. It reads the process's shared `Entity_Index::current()`, again
 * once a re-resolution's signal settled after the shared index was read.
 * While no active topology declares the `intel-graph` table, items resolve
 * against an empty index. The `LLM_Config` verbs configure the NER client,
 * built from them for each recorded item, so a change reaches the next one;
 * with none resolvable the NER step is skipped.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Table_Unavailable;

\defined( 'ABSPATH' ) || exit;

class Resolver_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;
	use Emits_Struct;

	/** The record kind the Reresolver appends, naming a stored item to resolve again. */
	public const RERESOLVE = 'item_reresolve';

	/** The writer every link and candidate event is addressed to. */
	protected string $writer = '';

	/** The resolver over the shared index it last read. */
	private ?Resolver $resolver = null;

	/** Wire the sibling `:config` interpreter for the LLM verbs. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	/**
	 * Resolve one item: an `item_recorded` event, or an `item_reresolve`
	 * record naming a stored item, which it reads from the projection.
	 * Anything else on the items stream is skipped. An `item_recorded` that
	 * does not validate throws, as does a re-resolution while the projection
	 * will not open, so its Consumer dead-letters it (ADR-12); a
	 * re-resolution it cannot read, or naming an item the projection no
	 * longer holds, is dropped with a rate-limited line.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 * @throws \InvalidArgumentException When an item_recorded event does not validate.
	 * @throws Table_Unavailable When a re-resolution's item cannot be read.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_array( $value ) ) {
			return;
		}
		$kind = $value['event'] ?? null;
		if ( 'item_recorded' === $kind ) {
			$item = Intel_Event::item_of( Intel_Event::from_message( $message ) ) ?? throw new \InvalidArgumentException( 'item_recorded: missing an item field' );
			$client = $this->make_llm_client();
			$this->resolved( $item, $this->resolver( 0.0 )->resolve( $item, null !== $client ? new LLM_Entity_Extractor( $client ) : null ), true );
			return;
		}
		if ( self::RERESOLVE !== $kind ) {
			return;
		}
		$item_key    = $value['item_key'] ?? null;
		$fresh_after = $value['fresh_after'] ?? null;
		if ( ! \is_string( $item_key ) || ! ( \is_int( $fresh_after ) || \is_float( $fresh_after ) ) ) {
			$this->print_less_often( 'dropping a re-resolution it cannot read from ', Core::as_string( $message[ Message::FROM ] ) );
			return;
		}
		$stored = self::stored( $item_key );
		if ( null === $stored ) {
			$this->print_less_often( 'no stored item to re-resolve: ', $item_key );
			return;
		}
		[ $item, $extracted ] = $stored;
		$this->resolved( $item, $this->resolver( (float) $fresh_after )->resolve( $item, null ), ! $extracted );
	}

	/**
	 * The resolver over the shared index, built again when that index is.
	 *
	 * @param float $fresh_after The earliest index read the item accepts.
	 */
	private function resolver( float $fresh_after ): Resolver {
		$index = Entity_Index::current( $fresh_after );
		if ( $index !== $this->resolver?->index ) {
			$this->resolver = new Resolver( $index );
		}
		return $this->resolver;
	}

	/**
	 * A stored item's `item_recorded` fields, as the projection holds them,
	 * and whether it holds an extraction of the item a call made, a heuristic
	 * stand-in not counting; null when no partition's file holds the item.
	 *
	 * @param string $item_key The item.
	 * @return array{0:array<string,mixed>,1:bool}|null
	 * @throws Table_Unavailable When the projection will not open.
	 */
	private static function stored( string $item_key ): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $item_key ): ?array {
				foreach ( $r->aliases() as $a ) {
					$sql = "SELECT i.item_key, i.source, i.tier, i.url, i.title, i.body, i.item_ts, i.retrieved_ts, EXISTS ( SELECT 1 FROM {$a}.extractions e WHERE e.item_key = i.item_key AND INSTR( e.actor, ? ) <> 1 ) FROM {$a}.items i WHERE i.item_key = ?";
					foreach ( $r->rows( $sql, [ Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ), $item_key ] ) as [ $key, $source, $tier, $url, $title, $body, $item_ts, $retrieved_ts, $extracted ] ) {
						$item = [
							'item_key'     => Core::as_string( $key ),
							'source'       => Core::as_string( $source ),
							'tier'         => Core::as_string( $tier ),
							'url'          => Core::as_string( $url ),
							'title'        => Core::as_string( $title ),
							'body'         => Core::as_string( $body ),
							'item_ts'      => Core::as_int( $item_ts ),
							'retrieved_ts' => Core::as_int( $retrieved_ts ),
						];
						return [ $item, 0 !== Core::as_int( $extracted ) ];
					}
				}
				return null;
			}
		);
	}

	/**
	 * Write each link and candidate to the writer, then forward the
	 * resolution to the target when asked to.
	 *
	 * @param array<string,mixed>                                                                                                        $item       The item.
	 * @param array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string}>} $resolution What resolve() answered.
	 * @param bool                                                                                                                       $forward    Whether the extractor is sent it.
	 */
	private function resolved( array $item, array $resolution, bool $forward ): void {
		$item_key = Core::as_string( $item['item_key'] );
		foreach ( $resolution['links'] as $link ) {
			$kind = 'asserted' === $link['state'] ? 'link_asserted' : 'link_proposed';
			$this->emit( $this->writer, Intel_Event::machine_event( 'resolve', $kind, [ 'item_key' => $item_key ] + \array_diff_key( $link, [ 'state' => true ] ) + [ 'retrieved_ts' => $item['retrieved_ts'] ] ) );
		}
		foreach ( $resolution['proposals'] as $proposal ) {
			$fields = [
				'entity_key'    => Intel_Event::entity_key( $proposal['type'], $proposal['name'] ),
				'type'          => $proposal['type'],
				'name'          => $proposal['name'],
				'from_item_key' => $item_key,
			];
			$this->emit( $this->writer, Intel_Event::machine_event( 'resolve', 'entity_proposed', $fields ) );
		}
		if ( $forward ) {
			$this->emit( '', [ 'item' => $item ] + $resolution );
		}
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Resolves each recorded item to the entities it is about; writes its links and candidates and forwards the resolution.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The event writer every link and candidate is addressed to: intel:writer.' ],
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

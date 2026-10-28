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
 * because NER ran when the item was first resolved, and writes only the
 * links that changed, so a link's time is when it last changed. It forwards
 * an item a call has extracted only when, with the curators' judgments in
 * force, it asserts a link to an entity, compared by survivor, whose link to
 * the item is not settled: asserted or confirmed no later than the item's
 * last extraction, or rejected. An extraction older than the link ran
 * without that subject's claims. What it forwards carries the judgments, a
 * confirmed link asserted and a rejected one left out, and whether a call
 * extracted the item before. It reads the process's shared `Entity_Index::current()`, again
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

/**
 * @phpstan-type Judgment array{known: array<string,true>, verdicts: array<string,string>, stored: array<string,array{role:string,method:string,confidence:float}>, held: array<string,array{0:string,1:string,2:float}>}
 */
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
			$resolution = $this->resolver( 0.0 )->resolve( $item, null !== $client ? new LLM_Entity_Extractor( $client ) : null );
			$this->resolved( $item, $resolution, [ 'extracted' => false ] + $resolution );
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
		$resolver = $this->resolver( (float) $fresh_after );
		$stored   = self::stored( $item_key, $resolver->index->merged() );
		if ( null === $stored ) {
			$this->print_less_often( 'no stored item to re-resolve: ', $item_key );
			return;
		}
		[ $item, $extracted, $judgment ] = $stored;
		$resolution                      = $resolver->resolve( $item, null );
		$sent                            = self::judged( $resolution, $judgment );
		$forward                         = ! $extracted || self::asserts_anew( $sent['links'], $judgment['known'] );
		$this->resolved( $item, $resolution, $forward ? [ 'extracted' => $extracted ] + $sent : null, $judgment['held'] );
	}

	/**
	 * What the extractor is sent: the resolution with the curators'
	 * judgments in force — a confirmed link asserted, from its stored row
	 * where the resolution did not find it again, and a rejected one left
	 * out — so its claims follow what a curator decided.
	 *
	 * @param array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string}>} $resolution What resolve() answered.
	 * @param Judgment                                                                                                                                              $judgment   What settled() read.
	 * @return array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string}>}
	 */
	private static function judged( array $resolution, array $judgment ): array {
		$links = [];
		foreach ( $resolution['links'] as $link ) {
			$verdict = $judgment['verdicts'][ $link['entity_key'] ] ?? null;
			if ( 'rejected' !== $verdict ) {
				$links[ $link['entity_key'] ] = 'confirmed' === $verdict ? [ 'state' => 'asserted' ] + $link : $link;
			}
		}
		foreach ( $judgment['verdicts'] as $entity => $verdict ) {
			if ( 'confirmed' === $verdict && ! isset( $links[ $entity ] ) && isset( $judgment['stored'][ $entity ] ) ) {
				$links[ $entity ] = [ 'entity_key' => $entity ] + $judgment['stored'][ $entity ] + [ 'state' => 'asserted' ];
			}
		}
		return [ 'links' => \array_values( $links ) ] + $resolution;
	}

	/**
	 * Whether what the extractor would be sent asserts a link to an entity
	 * whose link to the item is not settled, as one asserted or confirmed
	 * since the item was extracted is: the extraction then ran without that
	 * subject's claims.
	 *
	 * @param list<array{entity_key:string,state:string}> $links The links, keyed by survivor.
	 * @param array<string,true>                          $known The survivors whose links are settled.
	 */
	private static function asserts_anew( array $links, array $known ): bool {
		foreach ( $links as $link ) {
			if ( 'asserted' === $link['state'] && ! isset( $known[ $link['entity_key'] ] ) ) {
				return true;
			}
		}
		return false;
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
	 * whether it holds an extraction of the item a call made, a heuristic
	 * stand-in not counting, and what `settled()` reads of its links and
	 * judgments against that extraction's time, each entity as its survivor
	 * in the resolver's own index; null when no partition's file holds the
	 * item.
	 *
	 * @param string               $item_key The item.
	 * @param array<string,string> $merged   Each merged key's survivor, as the index read it.
	 * @return array{0:array<string,mixed>,1:bool,2:Judgment}|null
	 * @throws Table_Unavailable When the projection will not open.
	 */
	private static function stored( string $item_key, array $merged ): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $item_key, $merged ): ?array {
				foreach ( $r->aliases() as $a ) {
					$sql = "SELECT i.item_key, i.source, i.tier, i.url, i.title, i.body, i.item_ts, i.retrieved_ts, ( SELECT e.ts FROM {$a}.extractions e WHERE e.item_key = i.item_key AND INSTR( e.actor, ? ) <> 1 ) FROM {$a}.items i WHERE i.item_key = ?";
					foreach ( $r->rows( $sql, [ Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE ), $item_key ] ) as [ $key, $source, $tier, $url, $title, $body, $item_ts, $retrieved_ts, $extracted_ts ] ) {
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
						$extracted = null !== $extracted_ts;
						return [ $item, $extracted, self::settled( $r, $item_key, $merged, $extracted ? Core::as_float( $extracted_ts ) : -\INF ) ];
					}
				}
				return null;
			}
		);
	}

	/**
	 * What the item's links and judgments say, each entity by its survivor,
	 * a key whose merge chain is broken counting nowhere, as Graph_Query
	 * reads them: `stored`, each linked survivor's one link, picked subject
	 * over mention, then by confidence and key; `held`, each stored link's
	 * state, method and confidence by its own key and role; `known`, the
	 * survivors whose picked link was asserted, or whose verdict confirmed
	 * it, no later than `$extracted_ts`, and those whose verdict rejects it;
	 * and `verdicts`, the state of each judged survivor's highest-ranked
	 * judgment, the latest among equals.
	 *
	 * @param Row_Reader           $r            The reader.
	 * @param string               $item_key     The item.
	 * @param array<string,string> $merged       Each merged key's survivor; '' where its chain is broken.
	 * @param float                $extracted_ts When a call last extracted the item; -INF if never.
	 * @return Judgment
	 */
	private static function settled( Row_Reader $r, string $item_key, array $merged, float $extracted_ts ): array {
		$survivor = static fn ( mixed $key ): string => $merged[ Core::as_string( $key ) ] ?? Core::as_string( $key );
		$known    = [];
		$stored   = [];
		$held     = [];
		foreach ( $r->rows( 'SELECT entity_key, role, method, confidence, state, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . " l WHERE item_key = ? AND role <> 'topic' ORDER BY role = 'subject' DESC, confidence DESC, entity_key", [ $item_key ] ) as [ $key, $role, $method, $confidence, $state, $ts ] ) {
			$held[ Core::as_string( $key ) . '|' . Core::as_string( $role ) ] = [ Core::as_string( $state ), Core::as_string( $method ), Core::as_float( $confidence ) ];
			$entity = $survivor( $key );
			if ( '' === $entity || isset( $stored[ $entity ] ) ) {
				continue;
			}
			$stored[ $entity ] = [ 'role' => Core::as_string( $role ), 'method' => Core::as_string( $method ), 'confidence' => Core::as_float( $confidence ) ];
			if ( 'asserted' === $state && Core::as_float( $ts ) <= $extracted_ts ) {
				$known[ $entity ] = true;
			}
		}
		$verdicts = [];
		foreach ( $r->rows( 'SELECT entity_key, state, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j WHERE item_key = ? ORDER BY ' . Graph_Query::judge_rank( 'actor_kind' ) . ' DESC, ts DESC', [ $item_key ] ) as [ $key, $state, $ts ] ) {
			$entity = $survivor( $key );
			if ( '' === $entity || isset( $verdicts[ $entity ] ) ) {
				continue;
			}
			$verdicts[ $entity ] = Core::as_string( $state );
			if ( 'rejected' === $verdicts[ $entity ] || Core::as_float( $ts ) <= $extracted_ts ) {
				$known[ $entity ] = true;
			}
		}
		return [
			'known'    => $known,
			'verdicts' => $verdicts,
			'stored'   => $stored,
			'held'     => $held,
		];
	}

	/**
	 * Write each link the projection does not already hold as it is, and
	 * each candidate, to the writer, then forward what the extractor is
	 * sent, when there is anything.
	 *
	 * @param array<string,mixed>                                                                                                        $item       The item.
	 * @param array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string}>} $resolution What resolve() answered.
	 * @param array<string,mixed>|null                                                                                                   $forwarded  What the extractor is sent, or null for nothing.
	 * @param array<string,array{0:string,1:string,2:float}>                                                                             $held       Each stored link's state, method and confidence by key and role.
	 */
	private function resolved( array $item, array $resolution, ?array $forwarded, array $held = [] ): void {
		$item_key = Core::as_string( $item['item_key'] );
		foreach ( $resolution['links'] as $link ) {
			if ( [ $link['state'], $link['method'], $link['confidence'] ] === ( $held[ "{$link['entity_key']}|{$link['role']}" ] ?? null ) ) {
				continue;
			}
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
		if ( null !== $forwarded ) {
			$this->emit( '', [ 'item' => $item ] + $forwarded );
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

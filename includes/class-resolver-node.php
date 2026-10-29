<?php
/**
 * Resolver_Node: resolves each recorded item to the entities it is about.
 * Fed `item_recorded` events by `intel:items:resolve`, a Consumer over the
 * items stream, and `item_reresolve` records naming a stored item by
 * `intel:reresolve:resolve`, a Consumer over the stream the Reresolver
 * appends to. For each item it writes a `link_asserted` or `link_proposed`
 * per link, a `link_retracted` per stored link row it no longer writes and
 * an `entity_proposed` per candidate, addressed by TO to the writer its
 * `make_node` line names, then forwards an `item_resolved` struct `{ item,
 * links, proposals, extracted, attempt }` to its target. A record it cannot
 * resolve yet parks on the Delay its `make_node` line names after the
 * writer. All of it leaves through its sink, so the writer and the Delay are
 * declared destinations (substrate ADR-19), as the Intel_Event_Writer's two
 * partitions are.
 *
 * While its index holds no live entity, or while the projector's reader of
 * the ontology stream had not committed its end just before that index was
 * read (`Entity_Index::behind_ontology()`, taken first so a commit landing
 * between the two reads cannot pass a stale index), it resolves nothing: an `item_recorded` parks as an `item_reresolve` owed
 * its NER step (`ner`), and an `item_reresolve` parks again unchanged, each
 * due HOLD_S on and keyed by its item. A false hold only parks a record a
 * minute; one the queue ages out after a day is recovered by `ontology
 * reresolve`, without its NER step.
 *
 * A re-resolution runs the resolver's identifier, name and stem steps alone,
 * because NER ran when the item was first resolved, unless the record is
 * owed NER. It forwards an item a call has extracted only when, with the
 * curators' judgments in force, it asserts a link to an entity, compared by
 * survivor, whose link to the item is not settled, one the item's last
 * extraction was given as asserted; a link a curator rejected is not sent
 * at all. An extraction not given the subject ran without its claims,
 * whenever the link or the confirmation was written. What it forwards
 * carries the judgments, a confirmed link asserted and a rejected one left
 * out, and, when its NER step did not run and answer, each stored NER link
 * it did not find again, as stored; whether a call extracted the item
 * before; and the record's extraction `attempt`, 0 when it carries none.
 * Only the links it found itself are written as events.
 *
 * A resolution retracts each stored non-topic link row of the item in this
 * worker's own partition's file, the one its projector deletes in, by its
 * raw entity key and role, that it does not write again, but a row whose
 * survivor a curator confirmed, or a NER row unless the NER step ran and
 * answered. It reads the process's shared `Entity_Index::current()`,
 * again once a re-resolution's signal settled after the shared index was
 * read; while no active topology declares the `intel-graph` table that index
 * is empty. The `LLM_Config` verbs configure the NER client, built from them
 * for each item owed NER, so a change reaches the next one; with none
 * resolvable the NER step is skipped.
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
 * @phpstan-type Row array{entity_key:string, role:string, method:string, survivor:string}
 * @phpstan-type Own_Links array{verdicts: array<string,string>, rows: array<string,Row>}
 * @phpstan-type Judgment array{known: array<string,true>, stored: array<string,array{role:string,method:string,confidence:float,state:string}>, ner: array<string,array{role:string,method:string,confidence:float,state:string}>, verdicts: array<string,string>, rows: array<string,Row>}
 * @phpstan-type Reresolve array{event:string, item_key:string, fresh_after:int|float, ner?:bool, attempt?:int, parked_at?:int|float}
 * @phpstan-type Resolution array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string}>, recognized: bool}
 */
class Resolver_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;
	use Emits_Struct;

	/** The record kind the Reresolver appends, naming a stored item to resolve again. */
	public const RERESOLVE = 'item_reresolve';

	/** Seconds a held record waits on the later queue. */
	public const HOLD_S = 60;

	/** The item's non-topic links, subject before mention, then by confidence and key. */
	private const LINKS_OF_ITEM = "item_key = ? AND role <> 'topic' ORDER BY role = 'subject' DESC, confidence DESC, entity_key";

	/** What a hold line says of a record the later queue ages out. */
	private const AGED_OUT = 'A record parked past a day drops, keyed by its item; wp newspack-intelligence ontology reresolve --since=<day> re-resolves it without its NER step, after ontology seed on a fresh install.';

	/** The writer every link and candidate event is addressed to. */
	protected string $writer = '';

	/** The Delay a record parks on to be resolved later. */
	protected string $later = '';

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
	 * Anything else on the items stream is skipped. While the index holds no
	 * live entity, or the projector stood behind the ontology stream when the
	 * index was read, it resolves nothing and parks the record on the later
	 * queue: a recorded item as a re-resolution owed its NER step, a
	 * re-resolution unchanged, its `parked_at` too. A re-resolution owed NER
	 * runs it, and parks again while the projection does not hold its item
	 * yet. A recorded item whose stored links will not read retracts nothing,
	 * logged. An `item_recorded` that does not validate throws, as does a
	 * re-resolution while the projection will not open, so its Consumer
	 * dead-letters it (ADR-12); a re-resolution it cannot read, or naming an
	 * item the projection no longer holds, is dropped with a rate-limited
	 * line.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 * @throws \InvalidArgumentException When an item_recorded event does not validate.
	 * @throws Table_Unavailable When a re-resolution's item cannot be read.
	 * @throws \LogicException When no partition is bound.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_array( $value ) ) {
			return;
		}
		$kind = $value['event'] ?? null;
		if ( 'item_recorded' === $kind ) {
			$item     = Intel_Event::item_of( Intel_Event::from_message( $message ) ) ?? throw new \InvalidArgumentException( 'item_recorded: missing an item field' );
			$item_key = Core::as_string( $item['item_key'] );
			$resolver = $this->resolver( 0.0 );
			if ( $this->holds( $resolver ) ) {
				$this->hold( self::reresolve( $item_key, 0, [ 'ner' => true ] ) );
				return;
			}
			$own        = self::own_alias();
			$resolution = $resolver->resolve( $item, $this->ner() );
			$merged     = $resolver->index->merged();
			$links      = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => self::own_links( $r, $item_key, $merged, $own ), 'no intel-graph to read stored links from; retracting none: ' );
			$forwarded  = [
				'extracted' => false,
				'attempt'   => 0,
				'links'     => $resolution['links'],
				'proposals' => $resolution['proposals'],
			];
			$this->resolved( $item, $resolution, null === $links ? [] : self::retractable( $links, $resolution['recognized'] ), $forwarded );
			return;
		}
		if ( self::RERESOLVE !== $kind ) {
			return;
		}
		$record = self::reresolve_of( $value );
		if ( null === $record ) {
			$this->print_less_often( 'dropping a re-resolution it cannot read from ', Core::as_string( $message[ Message::FROM ] ) );
			return;
		}
		$item_key = $record['item_key'];
		$owes_ner = $record['ner'] ?? false;
		$resolver = $this->resolver( (float) $record['fresh_after'] );
		if ( $this->holds( $resolver ) ) {
			$this->hold( $record );
			return;
		}
		$stored = self::stored( $item_key, $resolver->index->merged(), self::own_alias() );
		if ( null === $stored && $owes_ner ) {
			$this->print_less_often( 'holding a re-resolution the projection does not hold yet: ', $item_key );
			$this->hold( $record );
			return;
		}
		if ( null === $stored ) {
			$this->print_less_often( 'no stored item to re-resolve: ', $item_key );
			return;
		}
		[ $item, $extracted, $judgment ] = $stored;
		$resolution                      = $resolver->resolve( $item, $owes_ner ? $this->ner() : null );
		$sent                            = self::judged( $resolution, $judgment );
		$forward                         = ! $extracted || self::asserts_anew( $sent['links'], $judgment['known'] );
		$forwarded                       = [
			'extracted' => $extracted,
			'attempt'   => $record['attempt'] ?? 0,
		] + $sent;
		$this->resolved( $item, $resolution, self::retractable( $judgment, $resolution['recognized'] ), $forward ? $forwarded : null );
	}

	/**
	 * The `item_reresolve` record a VALUE carries, each field read strictly
	 * and each optional one kept only when present; none when one does not
	 * read.
	 *
	 * @param array<array-key,mixed> $value The VALUE.
	 * @return Reresolve|null
	 */
	private static function reresolve_of( array $value ): ?array {
		$item_key    = $value['item_key'] ?? null;
		$fresh_after = $value['fresh_after'] ?? null;
		$ner         = $value['ner'] ?? false;
		$attempt     = $value['attempt'] ?? 0;
		$parked_at   = $value['parked_at'] ?? 0;
		if ( ! \is_string( $item_key ) || ! self::is_number( $fresh_after ) || ! \is_bool( $ner ) || ! \is_int( $attempt ) || ! self::is_number( $parked_at ) ) {
			return null;
		}
		$more = [];
		if ( isset( $value['ner'] ) ) {
			$more['ner'] = $ner;
		}
		if ( isset( $value['attempt'] ) ) {
			$more['attempt'] = $attempt;
		}
		if ( isset( $value['parked_at'] ) ) {
			$more['parked_at'] = $parked_at;
		}
		return self::reresolve( $item_key, $fresh_after, $more );
	}

	/**
	 * An `item_reresolve` record naming a stored item: the one shape the
	 * resolver's hold, the extractor's retry and the reresolver mint, which
	 * reresolve_of() reads back. `ner` marks one owed its NER step, `attempt`
	 * counts the failed extraction calls behind it, and `parked_at` is the
	 * later queue's own stamp, carried through a re-park.
	 *
	 * @param string                                          $item_key    The stored item.
	 * @param int|float                                       $fresh_after The tick its index must be read after; 0 for any.
	 * @param array{ner?:bool,attempt?:int,parked_at?:int|float} $more     The optional fields it carries.
	 * @return Reresolve
	 */
	public static function reresolve( string $item_key, int|float $fresh_after, array $more = [] ): array {
		return [
			'event'       => self::RERESOLVE,
			'item_key'    => $item_key,
			'fresh_after' => $fresh_after,
		] + $more;
	}

	/**
	 * Whether a record field reads as a number of seconds.
	 *
	 * @param mixed $value The field.
	 * @phpstan-assert-if-true int|float $value
	 */
	private static function is_number( mixed $value ): bool {
		return \is_int( $value ) || \is_float( $value );
	}

	/**
	 * The NER step, built from the node's configuration at each call, so a
	 * runtime `set_api_url` reaches the next item; none without a client.
	 */
	private function ner(): ?LLM_Entity_Extractor {
		$client = $this->make_llm_client();
		return null === $client ? null : new LLM_Entity_Extractor( $client );
	}

	/**
	 * Whether to hold the record: while the index holds no live entity, or
	 * while the projector stood behind the ontology stream just before the
	 * index was read, each logged naming its cause and the recovery from an
	 * age-out. A false hold, from a curation the projector has not flushed
	 * yet, only parks a record a minute. The resolver's own `entity_proposed`
	 * events leave the projector's committed ontology cursor short until its
	 * next checkpoint, up to 30 seconds, so items following a proposal may
	 * park a minute; nothing is lost and no LLM call is spent.
	 *
	 * @param Resolver $resolver The resolver over the shared index.
	 */
	private function holds( Resolver $resolver ): bool {
		if ( ! $resolver->index->has_live_entities() ) {
			$this->print_less_often( 'the entity index holds no live entity; holding items on ', "{$this->later} until wp newspack-intelligence ontology seed runs, or ontology reproject after an upgrade. " . self::AGED_OUT );
			return true;
		}
		if ( $resolver->index->behind_ontology() ) {
			$this->print_less_often( 'the projector has not caught up with the ontology stream; holding items on ', "{$this->later}. " . self::AGED_OUT );
			return true;
		}
		return false;
	}

	/**
	 * Park a record on the later queue, due HOLD_S from now and keyed by its
	 * item, so an age-out names it, keeping the `parked_at` a replayed record
	 * carries, so the queue's age bounds the wait.
	 *
	 * @param Reresolve $record The `item_reresolve` record.
	 */
	private function hold( array $record ): void {
		$this->emit( $this->later, $record + [ 'not_before' => Core::$now + self::HOLD_S ], $record['item_key'] );
	}

	/**
	 * The stored links a resolution may retract: every one in this worker's
	 * own partition but a link whose survivor a curator confirmed, and a NER
	 * link unless the NER step ran and answered, since nothing else looked
	 * for it again.
	 *
	 * @param Own_Links|Judgment $links      What own_links() read.
	 * @param bool               $recognized Whether the NER step ran and answered.
	 * @return array<string,array{entity_key:string,role:string}>
	 */
	private static function retractable( array $links, bool $recognized ): array {
		$rows = [];
		foreach ( $links['rows'] as $at => $row ) {
			if ( 'confirmed' !== ( $links['verdicts'][ $row['survivor'] ] ?? null ) && ( $recognized || 'ner_fuzzy' !== $row['method'] ) ) {
				$rows[ $at ] = [ 'entity_key' => $row['entity_key'], 'role' => $row['role'] ];
			}
		}
		return $rows;
	}

	/**
	 * What the extractor is sent: the resolution with the curators'
	 * judgments in force — a confirmed link asserted, from its stored row
	 * where the resolution did not find it again, and a rejected one left
	 * out — so its claims follow what a curator decided. A resolution whose
	 * NER step did not run and answer also sends each stored NER link it did
	 * not find, by survivor, its strongest NER row in any partition as
	 * stored, so a retry reaches an item only NER linked; only the own
	 * partition's rows are retracted.
	 *
	 * @param Resolution $resolution What resolve() answered.
	 * @param Judgment   $judgment   What settled() read.
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
		foreach ( $resolution['recognized'] ? [] : $judgment['ner'] as $entity => $link ) {
			$verdict = $judgment['verdicts'][ $entity ] ?? null;
			if ( 'rejected' !== $verdict && ! isset( $links[ $entity ] ) ) {
				$links[ $entity ] = [ 'entity_key' => $entity ] + \array_merge( $link, 'confirmed' === $verdict ? [ 'state' => 'asserted' ] : [] );
			}
		}
		foreach ( $judgment['verdicts'] as $entity => $verdict ) {
			if ( 'confirmed' === $verdict && ! isset( $links[ $entity ] ) && isset( $judgment['stored'][ $entity ] ) ) {
				$links[ $entity ] = [ 'entity_key' => $entity ] + \array_merge( $judgment['stored'][ $entity ], [ 'state' => 'asserted' ] );
			}
		}
		return [
			'links'     => \array_values( $links ),
			'proposals' => $resolution['proposals'],
		];
	}

	/**
	 * Whether what the extractor would be sent asserts a link to an entity
	 * whose link to the item is not settled, as one the item's last
	 * extraction was not given is: that extraction ran without the subject's
	 * claims.
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
	 * The alias this worker's own partition's file is attached as, the one
	 * file its projector writes, so the only one a retraction reaches.
	 *
	 * @throws \LogicException When no partition is bound.
	 */
	private static function own_alias(): string {
		return 'p' . ( Core::bound_partition() ?? throw new \LogicException( 'Resolver: no partition is bound' ) );
	}

	/**
	 * A stored item's `item_recorded` fields, as the projection holds them,
	 * whether it holds an extraction of the item a call made, a heuristic
	 * stand-in not counting, and what `settled()` reads of its links,
	 * judgments and the entities its last extraction was given, each entity
	 * as its survivor in the resolver's own index; null when no partition's
	 * file holds the item.
	 *
	 * @param string               $item_key The item.
	 * @param array<string,string> $merged   Each merged key's survivor, as the index read it.
	 * @param string               $own      This worker's own partition's alias.
	 * @return array{0:array<string,mixed>,1:bool,2:Judgment}|null
	 * @throws Table_Unavailable When the projection will not open.
	 */
	private static function stored( string $item_key, array $merged, string $own ): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $item_key, $merged, $own ): ?array {
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
						return [ $item, 0 !== Core::as_int( $extracted ), self::settled( $r, $item_key, $merged, $own ) ];
					}
				}
				return null;
			}
		);
	}

	/**
	 * What the item's links and judgments say, each entity by its survivor,
	 * a key whose merge chain is broken counting nowhere, as Graph_Query
	 * reads them: `known`, the survivors the item's last extraction was
	 * given as asserted; `stored`, each linked survivor's one link across
	 * every partition, picked subject over mention, then by confidence and
	 * key, with its method and state; `ner`, the same pick among the NER
	 * links alone, so a stronger link of another method hides none; and what
	 * own_links() reads.
	 *
	 * @param Row_Reader           $r        The reader.
	 * @param string               $item_key The item.
	 * @param array<string,string> $merged   Each merged key's survivor; '' where its chain is broken.
	 * @param string               $own      This worker's own partition's alias.
	 * @return Judgment
	 */
	private static function settled( Row_Reader $r, string $item_key, array $merged, string $own ): array {
		$survivor = self::survivor_in( $merged );
		$known    = [];
		foreach ( $r->rows( 'SELECT entity_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'extracted_subjects' ) . ' s WHERE item_key = ?', [ $item_key ] ) as [ $key ] ) {
			if ( '' !== $survivor( $key ) ) {
				$known[ $survivor( $key ) ] = true;
			}
		}
		$stored = [];
		$ner    = [];
		foreach ( $r->rows( 'SELECT entity_key, role, method, confidence, state FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l WHERE ' . self::LINKS_OF_ITEM, [ $item_key ] ) as [ $key, $role, $method, $confidence, $state ] ) {
			$entity = $survivor( $key );
			if ( '' === $entity ) {
				continue;
			}
			$link                = [
				'role'       => Core::as_string( $role ),
				'method'     => Core::as_string( $method ),
				'confidence' => Core::as_float( $confidence ),
				'state'      => Core::as_string( $state ),
			];
			$stored[ $entity ] ??= $link;
			if ( 'ner_fuzzy' === $link['method'] ) {
				$ner[ $entity ] ??= $link;
			}
		}
		return [
			'known'  => $known,
			'stored' => $stored,
			'ner'    => $ner,
		] + self::own_links( $r, $item_key, $merged, $own );
	}

	/**
	 * What a resolution may retract by: `rows`, every non-topic link row of
	 * the item in this worker's own partition's file, by its raw key and
	 * role, with its method and survivor, since the projector deletes in
	 * that file alone; and `verdicts`, the state of each
	 * judged survivor's highest-ranked judgment across every partition, the
	 * latest among equals.
	 *
	 * @param Row_Reader           $r        The reader.
	 * @param string               $item_key The item.
	 * @param array<string,string> $merged   Each merged key's survivor; '' where its chain is broken.
	 * @param string               $own      This worker's own partition's alias.
	 * @return Own_Links
	 */
	private static function own_links( Row_Reader $r, string $item_key, array $merged, string $own ): array {
		$survivor = self::survivor_in( $merged );
		$rows     = [];
		$read     = \in_array( $own, $r->aliases(), true ) ? $r->rows( "SELECT entity_key, role, method FROM {$own}.links WHERE " . self::LINKS_OF_ITEM, [ $item_key ] ) : [];
		foreach ( $read as [ $key, $role, $method ] ) {
			$entity = $survivor( $key );
			if ( '' !== $entity ) {
				$rows[ Core::as_string( $key ) . '|' . Core::as_string( $role ) ] = [
					'entity_key' => Core::as_string( $key ),
					'role'       => Core::as_string( $role ),
					'method'     => Core::as_string( $method ),
					'survivor'   => $entity,
				];
			}
		}
		$verdicts = [];
		foreach ( $r->rows( 'SELECT entity_key, state FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j WHERE item_key = ? ORDER BY ' . Graph_Query::judge_rank( 'actor_kind' ) . ' DESC, ts DESC', [ $item_key ] ) as [ $key, $state ] ) {
			$entity = $survivor( $key );
			if ( '' !== $entity ) {
				$verdicts[ $entity ] ??= Core::as_string( $state );
			}
		}
		return [
			'verdicts' => $verdicts,
			'rows'     => $rows,
		];
	}

	/**
	 * A key's survivor by a merge map, '' where its chain is broken.
	 *
	 * @param array<string,string> $merged Each merged key's survivor.
	 * @return \Closure(mixed): string
	 */
	private static function survivor_in( array $merged ): \Closure {
		return static fn ( mixed $key ): string => $merged[ Core::as_string( $key ) ] ?? Core::as_string( $key );
	}

	/**
	 * Write each link to the writer, then a retraction of each retractable
	 * stored link the resolution does not write by the same key and role,
	 * then each candidate; then forward what the extractor is sent, when
	 * there is anything.
	 *
	 * @param array<string,mixed>                                                                                                        $item        The item.
	 * @param Resolution                                                                                                                 $resolution  What resolve() answered.
	 * @param array<string,array{entity_key:string,role:string}>                                                                         $retractable The stored links it may retract, keyed `entity|role`.
	 * @param array<string,mixed>|null                                                                                                   $forwarded   What the extractor is sent, or null for nothing.
	 */
	private function resolved( array $item, array $resolution, array $retractable, ?array $forwarded ): void {
		$item_key = Core::as_string( $item['item_key'] );
		foreach ( $resolution['links'] as $link ) {
			$kind = 'asserted' === $link['state'] ? 'link_asserted' : 'link_proposed';
			$this->emit( $this->writer, Intel_Event::machine_event( 'resolve', $kind, [ 'item_key' => $item_key ] + \array_diff_key( $link, [ 'state' => true ] ) + [ 'retrieved_ts' => $item['retrieved_ts'] ] ) );
			unset( $retractable[ "{$link['entity_key']}|{$link['role']}" ] );
		}
		foreach ( $retractable as $row ) {
			$this->emit( $this->writer, Intel_Event::machine_event( 'resolve', 'link_retracted', [ 'item_key' => $item_key ] + $row + [ 'retrieved_ts' => $item['retrieved_ts'] ] ) );
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
				[ 'name' => 'later', 'type' => 'node_name', 'required' => true, 'description' => 'The Delay a record parks on while the index holds no live entity: intel:later.' ],
			],
			'commands'     => self::llm_config_commands(),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

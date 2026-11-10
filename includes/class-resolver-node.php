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
 * links, proposals, extracted, attempt, extract }` to its target. A record it cannot
 * resolve yet parks on the Delay its `make_node` line names after the
 * writer. All of it leaves through its sink, so the writer and the Delay are
 * declared destinations (substrate ADR-19), as the Intel_Event_Writer's two
 * partitions are.
 *
 * While the projector's reader of the ontology stream had not committed its
 * end just before the index was read, or while that index holds no live
 * entity (`Entity_Index::hold_cause()`, the cursor taken first so a commit
 * landing between the two reads cannot pass a stale index), it resolves
 * nothing: an `item_recorded` parks as an `item_reresolve` owed
 * its NER step (`ner`), and an `item_reresolve` parks again unchanged, each
 * due HOLD_S on and keyed by its item. A false hold only parks a record a
 * minute; one the queue ages out after a day is recovered by `ontology
 * reresolve`, without its NER step.
 *
 * It publishes each record's hold through `Publishes_Status`, as
 * publish_hold() describes.
 *
 * A re-resolution runs the resolver's identifier, name and stem steps alone,
 * because NER ran when the item was first resolved, unless the record is
 * owed NER. It forwards an item a call has extracted only when the record is
 * owed an extraction (`extract`), or when, with the
 * curators' judgments in force, it asserts a link to an entity, compared by
 * survivor, whose link to the item is not settled, one the item's last
 * extraction was given as asserted; a link a curator rejected is not sent
 * at all. An extraction not given the subject ran without its claims,
 * whenever the link or the confirmation was written. What it forwards
 * carries the judgments, a confirmed link asserted and a rejected one left
 * out, and, when its NER step did not run and answer, each stored NER link
 * it did not find again, as stored; whether a call extracted the item
 * before; the record's extraction `attempt`, 0 when it carries none; and
 * whether it was owed an extraction, false when it carries none.
 * Only the links it found itself, and those a person added, are written as
 * events. An `item_recorded`
 * for an item the projection already holds is that same re-resolution, with
 * an `attempt` of 0; it runs the NER step unless the item was resolved
 * before, the projection holding an extraction of it from any actor or a
 * non-topic link, because the projector writes the `items` row whether or
 * not any resolution ran. So a fresh worker's repeat of a resolved item
 * runs no NER, while a first recording the projector flushed before the
 * resolver reached it, or an item that was never linked by any step, runs
 * it. One the
 * projection does not hold yet takes the same path from the event's item,
 * NER included, forwarded as never extracted, with the judgments the
 * projection already holds of it, since a replay can project the ontology
 * stream ahead of the items stream; one read while the projection will not
 * open takes it with no judgments and retracts nothing. The accepted cost: a crash after the projector flushed an
 * item's extraction, before the resolve Consumer committed its cursor, up
 * to 30 seconds, replays the `item_recorded` as a settled repeat, which is
 * not forwarded, so the deduper never places the item in a cluster; the
 * repeat path saves the NER and extraction calls every respawn would spend.
 *
 * A link a person added (`link_added`) and still confirms is written with
 * the links it finds, in the role it was added in and by `human`, on every
 * resolution, so none retracts it.
 *
 * A resolution retracts each stored non-topic link row of the item in this
 * worker's own partition's file, the one its projector deletes in, by its raw
 * entity key and role, that it does not write again, but a row whose survivor a
 * curator confirmed, or a NER row unless the NER step ran and answered. It
 * reads the process's shared `Entity_Index::current()`, again once a
 * re-resolution's signal settled after the shared index was read; while the
 * active topologies do not declare the `intel-graph` table, that index is
 * empty. An item its NER step is owed, which has no other link, is asked of
 * the model-call node its `make_node` line names, `intel:llm`, naming the
 * `extract_entities` prompt with the item beside the record it came in,
 * which rides as the carry without the item's fields, a re-extraction's
 * request as backlog; the answer runs the same path again with the names
 * in hand, a failed call or an unlent
 * group as a step that did not answer. An answer whose resolution throws is
 * logged and parks the item, owed its NER step, on the later queue.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Publishes_Status;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Row array{entity_key:string, role:string, method:string, survivor:string}
 * @phpstan-type Own_Links array{verdicts: array<string,string>, rows: array<string,Row>}
 * @phpstan-type Judgment array{known: array<string,true>, stored: array<string,array{role:string,method:string,confidence:float,state:string}>, ner: array<string,array{role:string,method:string,confidence:float,state:string}>, added: array<string,array{entity_key:string,role:string}>, verdicts: array<string,string>, rows: array<string,Row>}
 * @phpstan-type Reresolve array{event:string, item_key:string, fresh_after:int|float, ner?:bool, extract?:bool, attempt?:int, parked_at?:int|float}
 * @phpstan-type Resolution array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string,evidence?:string}>, proposals: list<array{type:string,name:string,evidence:string}>, recognized: bool}
 */
class Resolver_Node extends Node {
	use Schema_Reflection;
	use Emits_Struct;
	use Asks_Model;
	use Publishes_Status;

	/** The record kind the Reresolver appends, naming a stored item to resolve again. */
	public const RERESOLVE = 'item_reresolve';

	/** Seconds a held record waits on the later queue. */
	public const HOLD_S = 60;

	/** Seconds a hold outlives its last publish. */
	public const STATUS_TTL_S = 30 * DAY_IN_SECONDS;

	/** The item's non-topic links: subject, then mention, then publisher, each by confidence and key. */
	private const LINKS_OF_ITEM = "item_key = ? AND role <> 'topic' ORDER BY role = 'subject' DESC, role = 'publisher', confidence DESC, entity_key";

	/** What stored() reads of an item where the projection will not open. */
	private const NOTHING_STORED = [
		null,
		false,
		[
			'known'    => [],
			'stored'   => [],
			'ner'      => [],
			'added'    => [],
			'verdicts' => [],
			'rows'     => [],
		],
		false,
		[],
	];

	/** What a hold line says of a record the later queue ages out. */
	private const AGED_OUT = 'A record parked past a day drops, keyed by its item; wp newspack-intelligence ontology reresolve --since=<day> re-resolves it without its NER step, after ontology seed on a fresh install.';

	/** Each hold's log line by reason, `%s` the queue it parks on. */
	private const HOLD_LINES = [
		'no_live_entity'  => 'the entity index holds no live entity; holding items on %s until wp newspack-intelligence ontology seed runs, or ontology reproject after an upgrade.',
		'behind_ontology' => 'the projector has not caught up with the ontology stream; holding items on %s.',
	];

	/** The writer every link and candidate event is addressed to. */
	protected string $writer = '';

	/** The Delay a record parks on to be resolved later. */
	protected string $later = '';

	/** The resolver over the shared index it last read. */
	private ?Resolver $resolver = null;

	/** Whether the clear is published and nothing is held since. */
	private bool $cleared = false;

	/**
	 * Resolve one item: an `item_recorded` event, or an `item_reresolve`
	 * record naming a stored item, which it reads from the projection.
	 * Anything else on the items stream is skipped. While the index holds no
	 * live entity, or the projector stood behind the ontology stream when the
	 * index was read, it resolves nothing and parks the record on the later
	 * queue: a recorded item as a re-resolution owed its NER step, a
	 * re-resolution unchanged, its `parked_at` too. A re-resolution owed NER
	 * runs it, and parks again while the projection does not hold its item
	 * yet. A recorded item the projection already holds is resolved as a
	 * re-resolution, owed NER until the item was resolved before, an
	 * extraction or a non-topic link projected; one it does not hold, or
	 * read while the projection will not open, runs NER on the event's item,
	 * the latter retracting nothing, logged. An `item_recorded` that
	 * does not validate throws, as does a re-resolution while the projection
	 * will not open, so its Consumer dead-letters it (ADR-12); a
	 * re-resolution it cannot read, or naming an item the projection no
	 * longer holds, is dropped with a rate-limited line. An answer from
	 * `intel:llm` resolves the record it carries again.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 * @throws \InvalidArgumentException When an item_recorded event does not validate.
	 * @throws Table_Unavailable When a re-resolution's item cannot be read.
	 * @throws \LogicException When the process has no bound partition.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_array( $value ) ) {
			return;
		}
		if ( self::is_answer( $message ) ) {
			$this->answered( $message );
			return;
		}
		$this->resolve_record( $message, $value, null );
	}

	/**
	 * Resolve again the record an answer carries, the carry with the item's
	 * fields laid back on it, with the names it gave, or none when the call
	 * failed or no credential was lent; a resolution that throws parks the
	 * record as the resolver's hold parks it, its own `attempt`,
	 * `parked_at` and `fresh_after` kept (`Asks_Model`).
	 *
	 * @param array<int,mixed> $message The answer.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function answered( array $message ): void {
		$read = $this->answer_of( $message );
		if ( null === $read ) {
			return;
		}
		[ $answer, $item, , $carry ] = $read;
		if ( \is_string( $answer['error'] ?? null ) ) {
			$this->print_less_often( 'NER call failed: ', Core::terminal_safe( $answer['error'] ) );
		}
		$content                   = $answer['content'] ?? null;
		$record                    = $carry + $item;
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::VALUE ] = $record;
		$this->finish_answer(
			Core::as_string( $item['item_key'] ?? null ),
			fn () => $this->resolve_record( $message, $record, ( \is_string( $content ) ? Prompts::parse_entities( $content ) : null ) ?? false ),
			fn () => $this->park( $record, true )
		);
	}

	/**
	 * Resolve one record, its NER step answered by `$answered` when an
	 * answer carried it back, else owed as the record says.
	 *
	 * @param array<int,mixed>                                                                                    $message  The record's message.
	 * @param array<array-key,mixed>                                                                              $value    Its VALUE.
	 * @param array{orgs:list<string>,products:list<string>,people:list<string>,locations:list<string>}|false|null $answered The names its NER step answered, false for none, null when it was not asked.
	 * @throws \InvalidArgumentException When an item_recorded event does not validate.
	 * @throws Table_Unavailable When a re-resolution's item cannot be read.
	 * @throws \LogicException When the process has no bound partition.
	 */
	private function resolve_record( array $message, array $value, array|false|null $answered ): void {
		$kind = $value['event'] ?? null;
		if ( 'item_recorded' === $kind ) {
			$item     = Intel_Event::item_of( Intel_Event::from_message( $message ) ) ?? throw new \InvalidArgumentException( 'item_recorded: missing an item field' );
			$item_key = Core::as_string( $item['item_key'] );
			$resolver = $this->resolver( 0.0 );
			$reason   = $this->hold_reason( $resolver );
			$this->publish_hold( $reason );
			if ( '' !== $reason ) {
				$this->park( $value );
				return;
			}
			$stored      = self::stored( $item_key, $item, $resolver->index->merged(), self::own_alias(), 'no intel-graph to read the stored item from; resolving it fresh, retracting none: ' ) ?? self::NOTHING_STORED;
			$stored[0] ??= $item;
			$this->resolve_again( $resolver, $stored, $stored[3] ? false : ( $answered ?? true ), 0, false, $value );
			return;
		}
		if ( self::RERESOLVE !== $kind ) {
			return;
		}
		$record = self::reresolve_of( $value );
		if ( null === $record ) {
			$this->print_less_often( 'dropping a re-resolution it cannot read from ', Core::terminal_safe( Core::as_string( $message[ Message::FROM ] ) ) );
			return;
		}
		$item_key = $record['item_key'];
		$owes_ner = $record['ner'] ?? false;
		$resolver = $this->resolver( (float) $record['fresh_after'] );
		$reason   = $this->hold_reason( $resolver );
		$this->publish_hold( $reason );
		if ( '' !== $reason ) {
			$this->hold( $record );
			return;
		}
		$stored = self::stored( $item_key, null, $resolver->index->merged(), self::own_alias(), null );
		if ( isset( $stored[0] ) ) {
			$this->resolve_again( $resolver, $stored, $owes_ner ? ( $answered ?? true ) : false, $record['attempt'] ?? 0, $record['extract'] ?? false, $value );
			return;
		}
		if ( $owes_ner ) {
			$this->print_less_often( 'holding a re-resolution the projection does not hold yet: ', Core::terminal_safe( $item_key ) );
			$this->hold( $record );
			return;
		}
		$this->print_less_often( 'no stored item to re-resolve: ', Core::terminal_safe( $item_key ) );
	}

	/**
	 * Park a record on the later queue, as the hold parks it: an
	 * `item_recorded` as a re-resolution owed its NER step, an
	 * `item_reresolve` as it was read; one that reads as neither is dropped,
	 * logged. A record parked because finishing its answer failed counts the
	 * failure in its `attempt`, and from `Extractor_Node::MAX_ATTEMPTS` on
	 * parks nothing, logged, so a poisoned item stops asking the model.
	 *
	 * @param array<array-key,mixed> $value  The record.
	 * @param bool                   $failed Whether its answer failed to resolve.
	 */
	private function park( array $value, bool $failed = false ): void {
		$item_key = $value['item_key'] ?? null;
		$record   = 'item_recorded' === ( $value['event'] ?? null ) && \is_string( $item_key ) ? self::reresolve( $item_key, 0, [ 'ner' => true ] ) : self::reresolve_of( $value );
		if ( null === $record ) {
			$this->print_less_often( 'dropping a record it cannot park: ', Core::terminal_safe( Core::as_string( $item_key ) ) );
			return;
		}
		if ( $failed ) {
			$attempt = $record['attempt'] ?? 0;
			if ( $attempt >= Extractor_Node::MAX_ATTEMPTS ) {
				$this->print_less_often( 'giving up resolving ', Core::terminal_safe( $record['item_key'] ) . " after {$attempt} attempts" );
				return;
			}
			$record['attempt'] = $attempt + 1;
		}
		$this->hold( $record );
	}

	/**
	 * Resolve an item against what the projection holds of it: NER only when
	 * handed the step, the curators' judgments in force, forwarded only while
	 * still owed an extraction — asked for one, with no call extraction
	 * projected, or a link asserted anew. Its body lines the source's items
	 * share are left out of what resolution reads. A NER step owed that
	 * nothing spares is asked of `intel:llm`, the item beside the record
	 * less its fields, the hashes of those lines as its inputs, and nothing
	 * is written.
	 *
	 * @param Resolver                                                                                         $resolver The resolver over the shared index.
	 * @param array{0:array<string,mixed>,1:bool,2:Judgment,3:bool,4:list<string>}                             $stored   The item, whether a call extracted it, what settled() read, whether it was resolved before, and its boilerplate.
	 * @param array{orgs:list<string>,products:list<string>,people:list<string>,locations:list<string>}|bool $ner      The names the NER step answered, true when it is owed, false for none.
	 * @param int                                                                                              $attempt  The failed extraction calls behind it.
	 * @param bool                                                                                             $extract  Whether the record asks for an extraction whatever the item holds.
	 * @param array<array-key,mixed>                                                                           $value    The record, which a NER request carries.
	 */
	private function resolve_again( Resolver $resolver, array $stored, array|bool $ner, int $attempt, bool $extract, array $value ): void {
		[ $item, $extracted, $judgment, , $boilerplate ] = $stored;
		$resolution                                      = $resolver->resolve( $item, $ner, $boilerplate );
		if ( null === $resolution ) {
			$this->ask_model( 'extract_entities', $item, [ 'boilerplate' => $boilerplate ], [ 'max_tokens' => 300, 'temperature' => 0.0 ], \array_diff_key( $value, $item ), $extract );
			return;
		}
		$resolution                      = self::with_added( $resolution, $judgment );
		$sent                            = self::judged( $resolution, $judgment );
		$forward                         = $extract || ! $extracted || self::asserts_anew( $sent['links'], $judgment['known'] );
		$forwarded                       = [
			'extracted' => $extracted,
			'attempt'   => $attempt,
			'extract'   => $extract,
		] + $sent;
		$this->resolved( $item, $resolution, self::retractable( $judgment, $resolution ), $forward ? $forwarded : null );
	}

	/**
	 * A resolution with each link a person added and still confirms, under
	 * its survivor, in the role it was added in, asserted by `human` at full
	 * confidence, unless it holds that link already: written on every resolution, so none
	 * retracts it, while a rejection leaves it to be retracted.
	 *
	 * @param Resolution $resolution What resolve() answered.
	 * @param Judgment   $judgment   What settled() read.
	 * @return Resolution
	 */
	private static function with_added( array $resolution, array $judgment ): array {
		$held = [];
		foreach ( $resolution['links'] as $link ) {
			$held[ "{$link['entity_key']}|{$link['role']}" ] = true;
		}
		foreach ( $judgment['added'] as $survivor => $added ) {
			if ( 'confirmed' === ( $judgment['verdicts'][ $survivor ] ?? null ) && ! isset( $held[ "{$added['entity_key']}|{$added['role']}" ] ) ) {
				$resolution['links'][] = $added + [
					'method'     => 'human',
					'confidence' => 1.0,
					'state'      => 'asserted',
				];
			}
		}
		return $resolution;
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
		$extract     = $value['extract'] ?? false;
		$attempt     = $value['attempt'] ?? 0;
		$parked_at   = $value['parked_at'] ?? 0;
		if ( ! \is_string( $item_key ) || ! self::is_number( $fresh_after ) || ! \is_bool( $ner ) || ! \is_bool( $extract ) || ! \is_int( $attempt ) || ! self::is_number( $parked_at ) ) {
			return null;
		}
		$more = [];
		if ( isset( $value['ner'] ) ) {
			$more['ner'] = $ner;
		}
		if ( isset( $value['extract'] ) ) {
			$more['extract'] = $extract;
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
	 * reresolve_of() reads back. `ner` marks one owed its NER step, `extract`
	 * one owed an extraction call whatever the item holds, `attempt` counts
	 * the failed extraction calls behind it, and `parked_at` is the later
	 * queue's own stamp, carried through a re-park.
	 *
	 * @param string                                          $item_key    The stored item.
	 * @param int|float                                       $fresh_after The tick its index must be read after; 0 for any.
	 * @param array{ner?:bool,extract?:bool,attempt?:int,parked_at?:int|float} $more The optional fields it carries.
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
	 * Why to hold the record, '' when not: while the projector stood behind
	 * the ontology stream just before the index was read, or while the
	 * index holds no live entity, each logged naming its cause and the
	 * recovery from an age-out. A false hold, from a curation the projector
	 * has not flushed yet, only parks a record a minute. The resolver's own
	 * `entity_proposed` events leave the projector's committed ontology
	 * cursor short until its next checkpoint, up to 30 seconds, so items
	 * following a proposal may park a minute; nothing is lost. Parking
	 * before the NER step was asked spends no call, but a hold met when a
	 * NER answer comes back parks the record `{ ner: true }`, its answer
	 * unused, so that item costs one call more.
	 *
	 * @param Resolver $resolver The resolver over the shared index.
	 */
	private function hold_reason( Resolver $resolver ): string {
		$reason = $resolver->index->hold_cause();
		if ( '' !== $reason ) {
			$this->print_less_often( \sprintf( self::HOLD_LINES[ $reason ], $this->later ), ' ', self::AGED_OUT );
		}
		return $reason;
	}

	/**
	 * Publish the hold a record leaves: `reason`, '' when nothing holds, and
	 * `since`, the first hold of the standing reason, read back by a
	 * recycled worker and restarted when the reason changes, null once
	 * nothing holds. Once the clear is published, a passing record publishes
	 * nothing until the next hold.
	 *
	 * @param string $reason Why the record is held, or '' when it passed.
	 */
	private function publish_hold( string $reason ): void {
		if ( '' === $reason && $this->cleared ) {
			return;
		}
		$this->cleared = '' === $reason;
		$standing      = $reason === ( $this->last_status()['reason'] ?? null );
		$this->publish_status(
			[
				'reason' => $reason,
				'since'  => match ( true ) {
					'' === $reason => null,
					$standing => $this->status_since( 'since' ),
					default => (int) Core::$now,
				},
			]
		);
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
	 * own partition but an about-link whose survivor a curator confirmed and
	 * the resolution did not find again by the same method in another
	 * about-role, and a NER link unless the NER step ran and answered, since
	 * nothing else looked for it again. A verdict judges what the item is
	 * about, so it keeps no `publisher` link, and a confirmed link the
	 * resolver now finds in another about-role is replaced by that role.
	 *
	 * @param Judgment   $links      What settled() read.
	 * @param Resolution $resolution What resolve() answered.
	 * @return array<string,array{entity_key:string,role:string}>
	 */
	private static function retractable( array $links, array $resolution ): array {
		$refound = self::refound( $resolution );
		$rows    = [];
		foreach ( $links['rows'] as $at => $row ) {
			$kept = Intel_Event::PUBLISHER !== $row['role'] && 'confirmed' === ( $links['verdicts'][ $row['survivor'] ] ?? null ) && ! isset( $refound[ "{$row['survivor']}|{$row['method']}" ] );
			if ( ! $kept && ( $resolution['recognized'] || 'ner_fuzzy' !== $row['method'] ) ) {
				$rows[ $at ] = [ 'entity_key' => $row['entity_key'], 'role' => $row['role'] ];
			}
		}
		return $rows;
	}

	/**
	 * What the extractor is sent: the resolution with the curators'
	 * judgments in force — a confirmed link asserted, from its stored row
	 * where the resolution did not find it again, and a rejected one left
	 * out — so its claims follow what a curator decided. A verdict judges an
	 * about-link alone, never a `publisher` one. A resolution whose NER step
	 * did not run and answer also sends each stored NER link it did not
	 * find, by survivor, its strongest NER row in any partition as stored,
	 * so a retry reaches an item only NER linked; only the own partition's
	 * rows are retracted. One link is sent per entity, an about-link over a
	 * publisher one whatever their order, so a publisher the text also names
	 * is sent as what the item is about, and the entity whose site
	 * published the item is sent apart, '' for none, so an extraction
	 * knows its publisher whatever its links say. A link's evidence stays
	 * on its event: the extractor reads the item itself.
	 *
	 * @param Resolution $resolution What resolve() answered.
	 * @param Judgment   $judgment   What settled() read.
	 * @return array{links: list<array{entity_key:string,role:string,method:string,confidence:float,state:string}>, proposals: list<array{type:string,name:string,evidence:string}>, publisher: string}
	 */
	private static function judged( array $resolution, array $judgment ): array {
		$links     = [];
		$publisher = '';
		foreach ( $resolution['links'] as $link ) {
			unset( $link['evidence'] );
			$entity    = $link['entity_key'];
			$published = Intel_Event::PUBLISHER === $link['role'];
			$publisher = $published ? $entity : $publisher;
			$verdict   = $published ? null : ( $judgment['verdicts'][ $entity ] ?? null );
			if ( 'rejected' !== $verdict && ! ( $published && isset( $links[ $entity ] ) ) ) {
				$links[ $entity ] = 'confirmed' === $verdict ? [ 'state' => 'asserted' ] + $link : $link;
			}
		}
		foreach ( $resolution['recognized'] ? [] : $judgment['ner'] as $entity => $link ) {
			$verdict = $judgment['verdicts'][ $entity ] ?? null;
			if ( 'rejected' !== $verdict && ! self::about_held( $links, $entity ) ) {
				$links[ $entity ] = [ 'entity_key' => $entity ] + \array_merge( $link, 'confirmed' === $verdict ? [ 'state' => 'asserted' ] : [] );
			}
		}
		$refound = self::refound( $resolution );
		foreach ( $judgment['verdicts'] as $entity => $verdict ) {
			$stored = $judgment['stored'][ $entity ] ?? null;
			if ( 'confirmed' === $verdict && null !== $stored && Intel_Event::PUBLISHER !== $stored['role'] && ! self::about_held( $links, $entity ) && ! isset( $refound[ "{$entity}|{$stored['method']}" ] ) ) {
				$links[ $entity ] = [ 'entity_key' => $entity ] + \array_merge( $stored, [ 'state' => 'asserted' ] );
			}
		}
		return [
			'links'     => \array_values( $links ),
			'proposals' => $resolution['proposals'],
			'publisher' => $publisher,
		];
	}

	/**
	 * Each entity and method a resolution links by in an about-role, as
	 * `<entity>|<method>`; a `publisher` link judges nothing, so it replaces
	 * no confirmed about-link.
	 *
	 * @param Resolution $resolution What resolve() answered.
	 * @return array<string,true>
	 */
	private static function refound( array $resolution ): array {
		$refound = [];
		foreach ( $resolution['links'] as $link ) {
			if ( Intel_Event::PUBLISHER !== $link['role'] ) {
				$refound[ "{$link['entity_key']}|{$link['method']}" ] = true;
			}
		}
		return $refound;
	}

	/**
	 * Whether the links to send hold an about-link to the entity.
	 *
	 * @param array<string,array{role:string}> $links  The links, by entity.
	 * @param string                           $entity The entity.
	 */
	private static function about_held( array $links, string $entity ): bool {
		return isset( $links[ $entity ] ) && Intel_Event::PUBLISHER !== $links[ $entity ]['role'];
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
	 * @throws \LogicException When the process has no bound partition.
	 */
	private static function own_alias(): string {
		return 'p' . ( Core::bound_partition() ?? throw new \LogicException( 'Resolver: no partition is bound' ) );
	}

	/**
	 * A stored item's `item_recorded` fields, as the projection holds them,
	 * null when the item is not in any partition's file; whether it holds an
	 * extraction of the item a call made, a stand-in not counting;
	 * what `settled()` reads of its links, judgments and the entities its
	 * last extraction was given, each entity as its survivor in the
	 * resolver's own index; and whether the item was resolved before, an
	 * extraction from any actor or a non-topic link in any partition, false
	 * when it is not in any file; and the hashes of the body lines of that
	 * item, else of the recorded one, that `boilerplate()` counts. A
	 * projection that will not open answers null, logged, given a line, and
	 * throws given none.
	 *
	 * @param string                   $item_key    The item.
	 * @param array<string,mixed>|null $recorded    The item as its record carries it, or null for a re-resolution.
	 * @param array<string,string>     $merged      Each merged key's survivor, as the index read it.
	 * @param string                   $own         This worker's own partition's alias.
	 * @param string|null              $unavailable The line logging a projection that will not open, or null to throw.
	 * @return array{0:array<string,mixed>|null,1:bool,2:Judgment,3:bool,4:list<string>}|null
	 * @throws Table_Unavailable When the projection will not open and the
	 *                           caller gives no line.
	 */
	private static function stored( string $item_key, ?array $recorded, array $merged, string $own, ?string $unavailable ): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $item_key, $recorded, $merged, $own ): array {
				$links    = Intel_Graph_Row_Schema::all( $r, 'links' );
				$stand_in = \implode( ' AND ', \array_fill( 0, \count( Extractor_Node::STAND_IN_STAGES ), 'INSTR( e.actor, ? ) <> 1' ) );
				$prefixes = \array_map( Intel_Event::machine_prefix( ... ), Extractor_Node::STAND_IN_STAGES );
				foreach ( $r->aliases() as $a ) {
					$sql = "SELECT i.item_key, i.source, i.tier, i.url, i.title, i.body, i.item_ts, i.retrieved_ts, EXISTS ( SELECT 1 FROM {$a}.extractions e WHERE e.item_key = i.item_key AND {$stand_in} ), EXISTS ( SELECT 1 FROM {$a}.extractions e WHERE e.item_key = i.item_key ) OR EXISTS ( SELECT 1 FROM {$links} l WHERE l.item_key = i.item_key AND l.role <> 'topic' ) FROM {$a}.items i WHERE i.item_key = ?";
					foreach ( $r->rows( $sql, [ ...$prefixes, $item_key ] ) as [ $key, $source, $tier, $url, $title, $body, $item_ts, $retrieved_ts, $extracted, $resolved ] ) {
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
						return [ $item, 0 !== Core::as_int( $extracted ), self::settled( $r, $item_key, $merged, $own ), 0 !== Core::as_int( $resolved ), self::boilerplate( $r, $item ) ];
					}
				}
				return [ null, false, self::settled( $r, $item_key, $merged, $own ), false, null === $recorded ? [] : self::boilerplate( $r, $recorded ) ];
			},
			$unavailable
		);
	}

	/**
	 * The hashes of an item's body lines that at least
	 * `Resolver::BOILERPLATE_MIN_ITEMS` items of its source carry, in body
	 * order and each a string, json_each's value cast back, the item
	 * counted once whether or not it is projected yet, so the answer never
	 * waits on the projector's flush: one statement whatever the body's line
	 * count, none for a body with no line to count or no partition holding
	 * the table. Each hash reads one arm per partition, a search of the key
	 * over `( source, line_hash )` stopping at the other items the floor
	 * needs, so a line every item carries costs no more than one at the
	 * floor; the arms' union counts an item two partitions hold once.
	 *
	 * @param Row_Reader          $r    The reader.
	 * @param array<string,mixed> $item The item's fields.
	 * @return list<string>
	 */
	private static function boilerplate( Row_Reader $r, array $item ): array {
		$lines   = Entity_Index::line_hashes( Core::as_string( $item['body'] ) );
		$holding = Intel_Graph_Row_Schema::holding( $r, 'boilerplate' );
		if ( [] === $lines || [] === $holding ) {
			return [];
		}
		$others = Resolver::BOILERPLATE_MIN_ITEMS - 1;
		$arms   = [];
		$params = [ (string) \wp_json_encode( $lines, \JSON_THROW_ON_ERROR ) ];
		foreach ( $holding as $alias ) {
			$arms[] = "SELECT item_key FROM ( SELECT item_key FROM {$alias}.boilerplate WHERE source = ? AND line_hash = j.value AND item_key <> ? LIMIT ? )";
			\array_push( $params, Core::as_string( $item['source'] ), Core::as_string( $item['item_key'] ), $others );
		}
		$params[] = $others;
		$sql      = 'SELECT j.value FROM json_each( ? ) j WHERE ( SELECT COUNT(*) FROM ( ' . \implode( ' UNION ', $arms ) . ' ) ) >= ? ORDER BY j.key';
		return \array_map( static fn ( array $row ): string => Core::as_string( $row[0] ), $r->rows( $sql, $params ) );
	}

	/**
	 * What the item's links and judgments say, each entity by its survivor,
	 * a key whose merge chain is broken counting nowhere, as Graph_Query
	 * reads them: `known`, the survivors the item's last extraction was
	 * given as asserted; `stored`, each linked survivor's one link across
	 * every partition, picked subject over mention over publisher, then by
	 * confidence and key, with its method and state; `ner`, the same pick among the NER
	 * links alone, so a stronger link of another method hides none; `added`,
	 * each survivor a person added a link to, as the raw key and the role of
	 * its latest addition; and what own_links() reads.
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
		$added = [];
		foreach ( $r->rows( 'SELECT entity_key, role FROM ' . Intel_Graph_Row_Schema::all( $r, 'added_links' ) . ' a WHERE item_key = ? ORDER BY ts DESC', [ $item_key ] ) as [ $key, $role ] ) {
			$entity = $survivor( $key );
			if ( '' !== $entity ) {
				$added[ $entity ] ??= [
					'entity_key' => $entity,
					'role'       => Core::as_string( $role ),
				];
			}
		}
		return [
			'known'  => $known,
			'stored' => $stored,
			'ner'    => $ner,
			'added'  => $added,
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
				'evidence'      => $proposal['evidence'],
			];
			$this->emit( $this->writer, Intel_Event::machine_event( 'resolve', 'entity_proposed', $fields ) );
		}
		if ( null !== $forwarded ) {
			$this->emit( '', [ 'item' => $item ] + $forwarded );
		}
	}

	/**
	 * Seconds after which an unchanged hold is written again.
	 *
	 * @api Used by substrate.
	 */
	protected function status_heartbeat(): int {
		return 60;
	}

	/**
	 * Seconds the cache keeps a hold that is not rewritten.
	 *
	 * @api Used by substrate.
	 */
	protected function status_ttl(): int {
		return self::STATUS_TTL_S;
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Resolves each recorded item to the entities it is about; writes its links and candidates and forwards the resolution.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The event writer every link and candidate is addressed to: intel:writer.' ],
				[ 'name' => 'later', 'type' => 'node_name', 'required' => true, 'description' => 'The Delay a record parks on while the index holds no live entity: intel:later.' ],
				[ 'name' => 'llm', 'type' => 'node_name', 'required' => true, 'description' => 'The model-call node a NER step is asked of: intel:llm.' ],
			],
			'commands'     => [],
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

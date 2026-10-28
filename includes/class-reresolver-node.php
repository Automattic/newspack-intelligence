<?php
/**
 * Reresolver_Node: re-resolves stored items when the ontology grows. Fed the
 * ontology stream by `intel:ontology:tee`, it queues a signal for each
 * `entity_created` or `name_added` (the name), each `identifier_added` (the
 * URLs the identifier spells) and each `entity_accepted` (every name and
 * identifier the projection holds for the entity). A new locality queues its
 * entity, because a locality settles an ambiguous name and links nothing
 * alone; a topic queues nothing, because a topic is matched by extraction. A
 * `link_confirmed` queues its one item with no search, so the resolver
 * extracts it again with the curator's confirmation in force. A repeated
 * signal is queued once.
 *
 * It ticks with the Router and appends `item_reresolve` records, each naming
 * a stored item, to the stream its `make_node` line names (substrate
 * ADR-19), whose own Consumer feeds the resolver one record a poll: so no
 * fill() makes more than one item's blocking LLM calls, and an item whose
 * resolution throws dead-letters alone (ADR-12). Each tick takes the oldest
 * signal at least SWEEP_INTERVAL_S old, long after the projector has flushed
 * what it signalled, and only with none due, the next RERESOLVE_PAGE items
 * of the oldest requested range. A signal searches `items_fts` for each name
 * as one quoted phrase, an acronym in its own case too, and `url` for each
 * identifier with LIKE, anchored to a whole host or path segment and its
 * wildcards escaped, and appends its newest MAX_ITEMS_PER_SIGNAL hits at
 * once, newest first; reaching that cap, it counts the rest and logs how many
 * it skipped. A `RERESOLVE <since>
 * <until>` request queues every item dated in those UTC days, an undated one
 * by its retrieval, sent in the order the projection recorded them, by a
 * cursor on each partition's row id.
 *
 * The queue checkpoints with the ontology Consumer's cursor, each unit by its
 * key, `<kind>|<value>`, with when it was queued and a range's cursor, so a
 * respawned reresolver resumes the signals that cursor consumed; an entry the
 * checkpoint cannot read is dropped and logged. A signal leaves the queue
 * once swept, a range once a page comes back short; while the projection
 * will not open, a unit waits.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;
use Newspack_Nodes\Timer_Node;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * Re-resolves the stored items an ontology change can link anew.
 *
 * @phpstan-type Work array{at:float,after:array{0:int,1:string}|null}
 */
class Reresolver_Node extends Timer_Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** Seconds a signal waits before its sweep, well past the projector's flush. */
	public const SWEEP_INTERVAL_S = 30;

	/** Items one signal re-resolves at most, the newest. */
	public const MAX_ITEMS_PER_SIGNAL = 500;

	/** Items a range sends a tick. */
	public const RERESOLVE_PAGE = 20;

	/** The ontology kinds that can link stored items anew. */
	private const SIGNALS = [ 'entity_created', 'entity_accepted', 'name_added', 'identifier_added', 'link_confirmed' ];

	/** The kinds of queued work. */
	private const KINDS = [ 'name', 'acronym', 'identifier', 'entity', 'range', 'item' ];

	/** Each identifier kind's URL spellings: a template, its value at `%s`, and its anchor. */
	private const IDENTIFIER_URLS = [
		'domain'           => [ [ '%s', 'host' ] ],
		'github_org'       => [ [ 'github.com/%s', 'path' ] ],
		'github_repo'      => [ [ 'github.com/%s', 'path' ] ],
		'linkedin_company' => [ [ 'linkedin.com/company/%s', 'path' ] ],
		'x_handle'         => [ [ 'x.com/%s', 'path' ], [ 'twitter.com/%s', 'path' ] ],
		'linear_team'      => [ [ '/issue/%s-', 'inner' ] ],
		'atomic_site_id'   => [],
	];

	/**
	 * What may precede and what may follow a spelling in a URL, every pair
	 * of the two: a host stands after `://` or a `.` and before a `/`, a `:`
	 * port, a `?`, a `#` or the end; a host-and-path before any of those but
	 * a port; an inner spelling carries its own bounds.
	 */
	private const ANCHORS = [
		'host'  => [ [ '://', '.' ], [ '/%', ':%', '?%', '#%', '' ] ],
		'path'  => [ [ '://', '.' ], [ '/%', '?%', '#%', '' ] ],
		'inner' => [ [ '' ], [ '%' ] ],
	];

	/** Bytes of an unreadable checkpoint the drop's log line quotes. */
	private const DROPPED_EXCERPT = 200;

	/** The stream every `item_reresolve` record is appended to. */
	protected string $stream = '';

	/**
	 * Each queued unit by its key, oldest first.
	 *
	 * @var array<string,Work>
	 */
	private array $queue = [];

	/** The line of the request being answered. */
	private string $asked = '';

	/**
	 * Store the stream's name and tick with the Router.
	 *
	 * @api Used by substrate.
	 * @param list<string>|null $args Positional tokens, or null to read them back.
	 * @return list<string>
	 */
	public function arguments( ?array $args = null ): array {
		if ( null === $args ) {
			return $this->arguments;
		}
		$this->parse_schema_args( $args );
		$this->set_timer();
		return $this->arguments;
	}

	/**
	 * Answer a RERESOLVE request, or queue the signal an ontology event
	 * carries. An event that does not validate is the projector's to
	 * dead-letter.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		++$this->counter;
		$type = Core::int( $message[ Message::TYPE ] );
		if ( 0 !== ( $type & Message::TM_REQUEST ) ) {
			$line        = $message[ Message::VALUE ];
			$this->asked = \is_string( $line ) ? $line : '';
			$this->answer_request( $message );
			return;
		}
		$event = $message[ Message::VALUE ];
		if ( 0 === ( $type & Message::TM_STRUCT ) || ! \is_array( $event ) || ! \in_array( $event['event'] ?? null, self::SIGNALS, true ) ) {
			return;
		}
		try {
			Intel_Event::validate( $event );
		} catch ( \InvalidArgumentException ) {
			return;
		}
		$this->signal( $event );
	}

	/**
	 * Router tick: the oldest due signal, else a page of the oldest range.
	 *
	 * @api Used by substrate.
	 */
	protected function fire(): void {
		$range = null;
		foreach ( $this->queue as $key => $work ) {
			if ( \str_starts_with( $key, 'range|' ) ) {
				$range ??= $key;
			} elseif ( $work['at'] <= Core::$now - self::SWEEP_INTERVAL_S ) {
				$this->sweep( $key, $work );
				return;
			}
		}
		if ( null !== $range ) {
			$this->page( $range, $this->queue[ $range ] );
		}
	}

	/**
	 * Queue what an ontology event can link anew.
	 *
	 * @param array<array-key,mixed> $event A validated signal event.
	 */
	private function signal( array $event ): void {
		$key = Core::as_string( $event['entity_key'] );
		if ( \str_starts_with( $key, 'topic:' ) ) {
			return;
		}
		$kind = Core::as_string( $event['kind'] ?? null );
		[ $queued, $value ] = match ( $event['event'] ) {
			'entity_created'   => [ 'name', Core::as_string( $event['name'] ) ],
			'name_added'       => match ( $kind ) {
				'locality' => [ 'entity', $key ],
				'acronym'  => [ 'acronym', Core::as_string( $event['value'] ) ],
				default    => [ 'name', Core::as_string( $event['value'] ) ],
			},
			'identifier_added' => [ 'identifier', $kind . ':' . Entity_Index::identifier_value( $kind, Core::as_string( $event['value'] ) ) ],
			'link_confirmed'   => [ 'item', Core::as_string( $event['item_key'] ) ],
			default            => [ 'entity', $key ],
		};
		if ( ( \in_array( $queued, [ 'name', 'acronym' ], true ) && [] === self::name_phrases( $value ) ) || ( 'identifier' === $queued && [] === self::url_patterns( $value ) ) ) {
			return;
		}
		$this->queue_up( $queued, $value );
	}

	/**
	 * Send every item a signal names, at most MAX_ITEMS_PER_SIGNAL, the
	 * newest first, and drop the signal; then log how many past that cap it
	 * skipped.
	 *
	 * @param string $key  The signal's queue key.
	 * @param Work   $work The signal.
	 */
	private function sweep( string $key, array $work ): void {
		[ $kind, $value ] = \explode( '|', $key, 2 );
		if ( 'item' === $kind ) {
			unset( $this->queue[ $key ] );
			$this->send( [ $value ], $work['at'] + self::SWEEP_INTERVAL_S );
			return;
		}
		$found            = $this->read(
			$key,
			static function ( Row_Reader $r ) use ( $kind, $value ): array {
				[ $names, $acronyms, $urls ] = match ( $kind ) {
					'entity'     => self::spellings_of( $r, $value ),
					'identifier' => [ [], [], self::url_patterns( $value ) ],
					'acronym'    => [ [], self::name_phrases( $value ), [] ],
					default      => [ self::name_phrases( $value ), [], [] ],
				};
				return self::matched( $r, $names, $acronyms, $urls );
			}
		);
		if ( null === $found ) {
			return;
		}
		unset( $this->queue[ $key ] );
		[ $item_keys, $total ] = $found;
		$this->send( $item_keys, $work['at'] + self::SWEEP_INTERVAL_S );
		if ( $total > \count( $item_keys ) ) {
			$this->print_less_often( 're-resolving only the newest ' . self::MAX_ITEMS_PER_SIGNAL . ' items a signal names: ', \sprintf( '%s names %d items; skipping the oldest %d', $value, $total, $total - \count( $item_keys ) ) );
		}
	}

	/**
	 * Every name, its acronyms apart and localities aside, and every
	 * identifier's URL patterns the projection holds for an entity, across
	 * every partition's file.
	 *
	 * @param Row_Reader $r      The reader.
	 * @param string     $entity The entity key.
	 * @return array{0:list<string>,1:list<string>,2:list<string>} The names, the acronyms and the patterns.
	 */
	private static function spellings_of( Row_Reader $r, string $entity ): array {
		$names    = [];
		$acronyms = [];
		$urls     = [];
		foreach ( $r->aliases() as $a ) {
			foreach ( $r->rows( "SELECT name, 'canonical' FROM {$a}.entities WHERE entity_key = ? UNION SELECT value, kind FROM {$a}.names WHERE entity_key = ? AND kind <> 'locality'", [ $entity, $entity ] ) as [ $name, $kind ] ) {
				if ( 'acronym' === $kind ) {
					\array_push( $acronyms, ...self::name_phrases( Core::as_string( $name ) ) );
				} else {
					\array_push( $names, ...self::name_phrases( Core::as_string( $name ) ) );
				}
			}
			foreach ( $r->rows( "SELECT kind, value FROM {$a}.identifiers WHERE entity_key = ?", [ $entity ] ) as [ $kind, $value ] ) {
				$kind = Core::as_string( $kind );
				\array_push( $urls, ...self::url_patterns( $kind . ':' . Entity_Index::identifier_value( $kind, Core::as_string( $value ) ) ) );
			}
		}
		return [ \array_values( \array_unique( $names ) ), \array_values( \array_unique( $acronyms ) ), \array_values( \array_unique( $urls ) ) ];
	}

	/**
	 * The phrases a name searches: itself, or none when it holds no letter
	 * or digit.
	 *
	 * @param string $name The name.
	 * @return list<string>
	 */
	private static function name_phrases( string $name ): array {
		return '' === Entity_Index::norm( $name ) ? [] : [ $name ];
	}

	/**
	 * The LIKE patterns an identifier searches: its value, wildcards
	 * escaped, in each URL spelling of its kind under each of that
	 * spelling's anchors; none for a kind no URL spells.
	 *
	 * @param string $identifier The identifier, as `kind:value`.
	 * @return list<string>
	 */
	private static function url_patterns( string $identifier ): array {
		[ $kind, $value ] = \explode( ':', $identifier, 2 ) + [ '', '' ];
		$escaped          = \strtr( $value, [ '\\' => '\\\\', '%' => '\\%', '_' => '\\_' ] );
		$patterns         = [];
		foreach ( self::IDENTIFIER_URLS[ $kind ] ?? [] as [ $template, $anchor ] ) {
			[ $befores, $afters ] = self::ANCHORS[ $anchor ];
			foreach ( $befores as $before ) {
				foreach ( $afters as $after ) {
					$patterns[] = '%' . $before . \sprintf( $template, $escaped ) . $after;
				}
			}
		}
		return $patterns;
	}

	/**
	 * The newest MAX_ITEMS_PER_SIGNAL items, across every partition's file,
	 * whose title or body holds a name as a phrase, or an acronym as a
	 * phrase and in its own case, or whose URL matches a pattern, newest
	 * first, and how many match in all: counted only when the cap is reached.
	 * Each name is one `Intel_Graph_Row_Schema::fts_phrase()`, so it is never
	 * read as query syntax; FTS folds case, so an acronym's case is held by a
	 * GLOB over the title or the body, its wildcards bracketed.
	 *
	 * @param Row_Reader   $r        The reader.
	 * @param list<string> $names    The names.
	 * @param list<string> $acronyms The acronyms.
	 * @param list<string> $urls     The LIKE patterns.
	 * @return array{0:list<string>,1:int} The item keys, and the count.
	 */
	private static function matched( Row_Reader $r, array $names, array $acronyms, array $urls ): array {
		$match  = [];
		$binds  = [];
		$phrase = Intel_Graph_Row_Schema::fts_phrase( ... );
		if ( [] !== $names ) {
			$match[] = 'i.id IN ( SELECT rowid FROM {a}.items_fts WHERE items_fts MATCH ? )';
			$binds[] = \implode( ' OR ', \array_map( $phrase, $names ) );
		}
		foreach ( $acronyms as $acronym ) {
			$glob    = '*' . \strtr( $acronym, [ '*' => '[*]', '?' => '[?]', '[' => '[[]' ] ) . '*';
			$match[] = '( i.id IN ( SELECT rowid FROM {a}.items_fts WHERE items_fts MATCH ? ) AND ( i.title GLOB ? OR i.body GLOB ? ) )';
			\array_push( $binds, $phrase( $acronym ), $glob, $glob );
		}
		foreach ( $urls as $url ) {
			$match[] = "i.url LIKE ? ESCAPE '\\'";
			$binds[] = $url;
		}
		if ( [] === $match ) {
			return [ [], 0 ];
		}
		$when = Intel_Graph_Row_Schema::ITEM_TIME;
		$cond = '( ' . \implode( ' OR ', $match ) . ' )';
		$cap  = self::MAX_ITEMS_PER_SIGNAL;
		$hits = [];
		foreach ( $r->aliases() as $a ) {
			foreach ( $r->rows( "SELECT {$when}, i.item_key FROM {$a}.items i WHERE " . \str_replace( '{a}', $a, $cond ) . " ORDER BY 1 DESC, 2 DESC LIMIT {$cap}", $binds ) as [ $ts, $item_key ] ) {
				$hits[] = [ Core::as_int( $ts ), Core::as_string( $item_key ) ];
			}
		}
		\usort( $hits, static fn ( array $x, array $y ): int => $y <=> $x );
		$item_keys = \array_column( \array_slice( $hits, 0, $cap ), 1 );
		if ( \count( $hits ) < $cap ) {
			return [ $item_keys, \count( $hits ) ];
		}
		$total = 0;
		foreach ( $r->aliases() as $a ) {
			foreach ( $r->rows( "SELECT COUNT(*) FROM {$a}.items i WHERE " . \str_replace( '{a}', $a, $cond ), $binds ) as [ $count ] ) {
				$total += Core::as_int( $count );
			}
		}
		return [ $item_keys, $total ];
	}

	/**
	 * Send the next page of a range, and drop the range once it has none.
	 *
	 * @param string $key  The range's queue key.
	 * @param Work   $work The range.
	 */
	private function page( string $key, array $work ): void {
		[ $since, $until ] = \array_map( 'intval', \explode( ' ', \explode( '|', $key, 2 )[1] ) );
		$found             = $this->read( $key, static fn ( Row_Reader $r ): array => self::dated( $r, $since, $until, $work['after'] ) );
		if ( null === $found ) {
			return;
		}
		[ $keys, $after ] = $found;
		$this->send( $keys, 0.0 );
		if ( null === $after ) {
			unset( $this->queue[ $key ] );
			return;
		}
		$this->queue[ $key ] = [ 'after' => $after ] + $work;
	}

	/**
	 * The next page of a range: the items dated in it, partition by
	 * partition in row-id order past the cursor, and the cursor after them;
	 * none once the last partition is exhausted.
	 *
	 * @param Row_Reader                 $r     The reader.
	 * @param int                        $since The range's first second.
	 * @param int                        $until The second after its last.
	 * @param array{0:int,1:string}|null $after The row id and partition alias the last page ended at.
	 * @return array{0:list<string>,1:array{0:int,1:string}|null} The item keys, and the cursor.
	 */
	private static function dated( Row_Reader $r, int $since, int $until, ?array $after ): array {
		$when    = Intel_Graph_Row_Schema::ITEM_TIME;
		$aliases          = $r->aliases();
		[ $last, $alias ] = $after ?? [ 0, '' ];
		$at               = \array_search( $alias, $aliases, true );
		if ( false === $at ) {
			[ $at, $last ] = [ 0, 0 ];
		}
		$keys = [];
		for ( ; $at < \count( $aliases ); $at++ ) {
			$a    = $aliases[ $at ];
			$room = self::RERESOLVE_PAGE - \count( $keys );
			foreach ( $r->rows( "SELECT i.id, i.item_key FROM {$a}.items i WHERE i.id > ? AND {$when} >= ? AND {$when} < ? ORDER BY i.id LIMIT {$room}", [ $last, $since, $until ] ) as [ $id, $item_key ] ) {
				$keys[] = Core::as_string( $item_key );
				$last   = Core::as_int( $id );
			}
			if ( self::RERESOLVE_PAGE === \count( $keys ) ) {
				return [ $keys, [ $last, $a ] ];
			}
			$last = 0;
		}
		return [ $keys, null ];
	}

	/**
	 * Run a unit's read against the projection; null when it will not open,
	 * which leaves the unit to wait. Any other failure drops the unit with a
	 * line naming it and the cause, so a respawn does not restore the same
	 * head and fail on it again. A cooperative stop propagates first (ADR-14).
	 *
	 * @template T
	 * @param string                 $key  The unit's queue key.
	 * @param \Closure(Row_Reader): T $read The read.
	 * @return T|null
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function read( string $key, \Closure $read ): mixed {
		try {
			return Intel_Graph_Row_Schema::read( $read, 'no intel-graph to re-resolve from; waiting: ' );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			unset( $this->queue[ $key ] );
			$this->print_less_often( 'dropping a re-resolution unit its read failed: ', "{$key}: {$e->getMessage()}" );
		}
		return null;
	}

	/**
	 * Append an `item_reresolve` record naming each item to the stream, with
	 * the tick its index must be read after: a signal's settling, else 0.
	 *
	 * @param list<string> $item_keys   The items.
	 * @param float        $fresh_after The tick by which the projection holds the signal.
	 */
	private function send( array $item_keys, float $fresh_after ): void {
		foreach ( $item_keys as $item_key ) {
			$this->emit( $this->stream, [ 'event' => Resolver_Node::RERESOLVE, 'item_key' => $item_key, 'fresh_after' => $fresh_after ] );
		}
	}

	/**
	 * Answer RERESOLVE: queue the day range the request names, or answer and
	 * log why it cannot.
	 *
	 * @return array{since:string,until:string}|array{refused:string}
	 */
	private function queue_range(): array {
		$words = \preg_split( '/\s+/', \trim( $this->asked ) );
		try {
			if ( false === $words || 3 !== \count( $words ) ) {
				throw new \InvalidArgumentException( 'usage: RERESOLVE <since Y-m-d> <until Y-m-d>' );
			}
			[ , $since, $until ] = $words;
			[ $from, $to ]       = Intel_Graph_Row_Schema::range( $since, $until );
		} catch ( \InvalidArgumentException $e ) {
			$this->print_less_often( 'refusing a re-resolution: ', $e->getMessage() );
			return [ 'refused' => $e->getMessage() ];
		}
		$this->queue_up( 'range', "{$from} {$to}" );
		return [ 'since' => $since, 'until' => $until ];
	}

	/**
	 * Queue one unit of work, once, keyed `<kind>|<value>`.
	 *
	 * @param string $kind  The unit's kind.
	 * @param string $value What it searches, or a range's bounds.
	 */
	private function queue_up( string $kind, string $value ): void {
		$this->queue[ "{$kind}|{$value}" ] ??= [ 'at' => Core::$now, 'after' => null ];
	}

	/**
	 * Restore the queue the Consumer checkpointed with the cursor it resumes;
	 * an entry that does not read is dropped, the drop logged naming it, and
	 * a checkpoint holding no queue is logged with its start.
	 *
	 * @api Called by the substrate's Consumer at its first poll.
	 * @param array<array-key,mixed> $saved What save_state() answered.
	 */
	public function restore_state( array $saved ): void {
		$this->queue = [];
		$queue       = $saved['queue'] ?? null;
		if ( ! \is_array( $queue ) ) {
			$this->print_less_often( 'dropping a re-resolution checkpoint it cannot read: ', \substr( (string) \wp_json_encode( $saved ), 0, self::DROPPED_EXCERPT ) );
			return;
		}
		$dropped = [];
		foreach ( $queue as $key => $work ) {
			$work = self::work_of( (string) $key, $work );
			if ( null === $work ) {
				$dropped[] = (string) $key;
				continue;
			}
			$this->queue[ (string) $key ] = $work;
		}
		if ( [] !== $dropped ) {
			$this->print_less_often( 'dropping checkpoint entries it cannot read: ', \implode( ', ', $dropped ) );
		}
	}

	/**
	 * A checkpointed unit of work, typed; null when its key names no kind or
	 * the entry is misshapen.
	 *
	 * @param string $key  Its queue key.
	 * @param mixed  $work What the checkpoint holds.
	 * @return Work|null
	 */
	private static function work_of( string $key, mixed $work ): ?array {
		if ( ! \str_contains( $key, '|' ) || ! \in_array( \explode( '|', $key, 2 )[0], self::KINDS, true ) || ! \is_array( $work ) || ! \is_numeric( $work['at'] ?? null ) ) {
			return null;
		}
		$after = $work['after'] ?? null;
		if ( null !== $after && ! ( \is_array( $after ) && \is_int( $after[0] ?? null ) && \is_string( $after[1] ?? null ) ) ) {
			return null;
		}
		return [ 'at' => (float) $work['at'], 'after' => null === $after ? null : [ $after[0], $after[1] ] ];
	}

	/**
	 * The queue, which the ontology Consumer co-commits with its cursor.
	 *
	 * @api Called by the substrate's Consumer at each checkpoint.
	 * @return array{queue:array<string,Work>}
	 */
	public function save_state(): array {
		return [ 'queue' => $this->queue ];
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Re-resolves the stored items an ontology change can link anew, and every item in a requested day range.',
			'arguments'    => [
				[ 'name' => 'stream', 'type' => 'node_name', 'required' => true, 'description' => 'The stream every item_reresolve record is appended to: intel:reresolve.' ],
			],
			'requests'     => [
				[
					'name'        => 'RERESOLVE',
					'description' => 'Re-resolve every item dated in a range of UTC days: RERESOLVE <since Y-m-d> <until Y-m-d>.',
					'reply_shape' => '{ since, until } or { refused }',
					'handler'     => static fn ( self $node ): array => $node->queue_range(),
				],
			],
			'accepts_fill' => true,
			'has_target'   => false,
		];
	}
}

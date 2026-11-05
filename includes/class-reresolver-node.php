<?php
/**
 * Reresolver_Node: re-resolves stored items when the ontology grows. Fed the
 * ontology stream by its own Consumer, `intel:ontology:reresolve`, apart from
 * the projector's, it queues a signal for each
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
 * ADR-19), whose own Consumer feeds the resolver one record a poll: so a
 * fill() never makes more than one item's blocking LLM calls, and an item whose
 * resolution throws dead-letters alone (ADR-12). Each tick takes the oldest
 * signal at least SWEEP_INTERVAL_S old, long after the projector has flushed
 * what it signalled, and only with none due, the next RERESOLVE_PAGE items
 * of the oldest requested range. A signal searches `items_fts` for each name
 * as one quoted phrase, an acronym in its own case too, and `url` for each
 * identifier with LIKE, anchored to a whole host or path segment and its
 * wildcards escaped, and appends its newest MAX_ITEMS_PER_SIGNAL hits at
 * once, newest first; reaching that cap, it counts the rest and logs how many
 * it skipped. A `RERESOLVE <since>
 * <until> [<source>]` request queues every item dated in those UTC days, an
 * undated one by its retrieval, of the source it names or of every source,
 * sent in the order the projection recorded them, by a cursor on each
 * partition's row id, and publishes a range naming no source as
 * `last_range`; a `REEXTRACT` request queues the same range with each record
 * owed its extraction, so the extractor calls the model whatever the item
 * holds.
 *
 * The queue checkpoints with its Consumer's cursor, each unit by its
 * key, `<kind>|<value>`, with when it was queued and a range's cursor, so a
 * respawned reresolver resumes the signals that cursor consumed; an entry the
 * checkpoint cannot read is dropped and logged. A signal leaves the queue
 * once swept, a range once a page comes back short; while the projection
 * will not open, a unit waits.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Publishes_Status;
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
	use Publishes_Status;

	/** Seconds a signal waits before its sweep, well past the projector's flush. */
	public const SWEEP_INTERVAL_S = 30;

	/** Seconds the last range outlives its publish: a year, past any dropped days it covers. */
	private const STATUS_TTL_S = 365 * DAY_IN_SECONDS;

	/** Items one signal re-resolves at most, the newest. */
	public const MAX_ITEMS_PER_SIGNAL = 500;

	/** Items a range sends a tick. */
	public const RERESOLVE_PAGE = 20;

	/** The ontology kinds that can link stored items anew. */
	private const SIGNALS = [ 'entity_created', 'entity_accepted', 'name_added', 'identifier_added', 'link_confirmed' ];

	/** Each range request by the kind of work it queues; a `reextract` range owes each item its extraction. */
	public const RANGES = [
		'RERESOLVE' => 'range',
		'REEXTRACT' => 'reextract',
	];

	/** The kinds of queued work. */
	private const KINDS = [ 'name', 'acronym', 'identifier', 'entity', 'item', ...self::RANGES ];

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
			if ( \in_array( \explode( '|', $key, 2 )[0], self::RANGES, true ) ) {
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
			$this->send( [ $value ], $work['at'] + self::SWEEP_INTERVAL_S, [] );
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
		$this->send( $item_keys, $work['at'] + self::SWEEP_INTERVAL_S, [] );
		if ( $total > \count( $item_keys ) ) {
			$this->print_less_often( 're-resolving only the newest ' . self::MAX_ITEMS_PER_SIGNAL . ' items a signal names: ', \sprintf( '%s names %d items; skipping the oldest %d', Core::terminal_safe( $value ), $total, $total - \count( $item_keys ) ) );
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
	 * spelling's anchors; none for a kind without a URL spelling.
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
	 * Send the next page of a range, each record owed its extraction from a
	 * `reextract` range, and drop the range once it has none.
	 *
	 * @param string $key  The range's queue key.
	 * @param Work   $work The range.
	 */
	private function page( string $key, array $work ): void {
		[ $kind, $bounds ]           = \explode( '|', $key, 2 );
		[ $since, $until, $source ] = \explode( ' ', $bounds, 3 ) + [ 2 => '' ];
		$found                       = $this->read( $key, static fn ( Row_Reader $r ): array => self::dated( $r, (int) $since, (int) $until, $source, $work['after'] ) );
		if ( null === $found ) {
			return;
		}
		[ $keys, $after ] = $found;
		$this->send( $keys, 0.0, 'reextract' === $kind ? [ 'extract' => true ] : [] );
		if ( null === $after ) {
			unset( $this->queue[ $key ] );
			return;
		}
		$this->queue[ $key ] = [ 'after' => $after ] + $work;
	}

	/**
	 * The next page of a range: the items dated in it, of its source when
	 * it names one, partition by partition in row-id order past the cursor,
	 * and the cursor after them; none once the last partition is exhausted.
	 *
	 * @param Row_Reader                 $r      The reader.
	 * @param int                        $since  The range's first second.
	 * @param int                        $until  The second after its last.
	 * @param string                     $source The source, or '' for every one.
	 * @param array{0:int,1:string}|null $after  The row id and partition alias the last page ended at.
	 * @return array{0:list<string>,1:array{0:int,1:string}|null} The item keys, and the cursor.
	 */
	private static function dated( Row_Reader $r, int $since, int $until, string $source, ?array $after ): array {
		[ $of, $of_binds ] = self::of_source( $source );
		$when             = Intel_Graph_Row_Schema::ITEM_TIME;
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
			foreach ( $r->rows( "SELECT i.id, i.item_key FROM {$a}.items i WHERE i.id > ? AND {$when} >= ? AND {$when} < ?{$of} ORDER BY i.id LIMIT {$room}", [ $last, $since, $until, ...$of_binds ] ) as [ $id, $item_key ] ) {
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
			$this->print_less_often( 'dropping a re-resolution unit its read failed: ', Core::terminal_safe( "{$key}: {$e->getMessage()}" ) );
		}
		return null;
	}

	/**
	 * Append an `item_reresolve` record naming each item to the stream, with
	 * the tick its index must be read after: a signal's settling, else 0.
	 *
	 * @param list<string>         $item_keys   The items.
	 * @param float                $fresh_after The tick by which the projection holds the signal.
	 * @param array{extract?:bool} $more        What each record carries besides.
	 */
	private function send( array $item_keys, float $fresh_after, array $more ): void {
		foreach ( $item_keys as $item_key ) {
			$this->emit( $this->stream, Resolver_Node::reresolve( $item_key, $fresh_after, $more ) );
		}
	}

	/**
	 * Answer a range request: queue the day range it names, of the source
	 * it names or of every source, as the kind RANGES gives it, or answer
	 * and log why it cannot. A range of every source is published as the
	 * last range; one of a source covers only some of its days' items.
	 *
	 * @return array{since:string,until:string,source?:string}|array{refused:string}
	 */
	private function queue_range(): array {
		$words   = \preg_split( '/\s+/', \trim( $this->asked ) ) ?: [ '' ];
		$request = \strtoupper( $words[0] );
		try {
			if ( 3 !== \count( $words ) && 4 !== \count( $words ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
				throw new \InvalidArgumentException( "usage: {$request} <since Y-m-d> <until Y-m-d> [<source>]" );
			}
			[ , $since, $until, $source ] = $words + [ 3 => '' ];
			[ $from, $to ]                = Intel_Graph_Row_Schema::range( $since, $until );
		} catch ( \InvalidArgumentException $e ) {
			$this->print_less_often( 'refusing a re-resolution: ', $e->getMessage() );
			return [ 'refused' => $e->getMessage() ];
		}
		$range = [ 'since' => $since, 'until' => $until ];
		if ( '' === $source ) {
			$this->publish_status( [ 'last_range' => $range ] );
		}
		$this->queue_up( self::RANGES[ $request ], \rtrim( "{$from} {$to} {$source}" ) );
		return $range + ( '' === $source ? [] : [ 'source' => $source ] );
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
	 * A checkpointed unit of work, typed; null when its key does not name a
	 * kind or the entry is misshapen.
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
	 * Ask each live worker's reresolver to re-resolve, or with REEXTRACT
	 * re-extract, every item dated in a range of UTC days, of one source or
	 * of every one: the one request `wp newspack-intelligence ontology
	 * reresolve` and `ontology reextract` and the `reresolve` verb send. A
	 * range that does not parse, or a source of more than one word, is
	 * refused before anything is sent.
	 *
	 * @param Command_Interpreter_Node $graph   The caller's interpreter; each request carries its name as FROM.
	 * @param string                   $request A key of RANGES.
	 * @param string                   $since   The first day, Y-m-d.
	 * @param string                   $until   The last day, Y-m-d.
	 * @param string                   $source  The source, or '' for every one.
	 * @return int The workers asked.
	 * @throws \InvalidArgumentException When the request is not a key of
	 *                                   RANGES, a day does not parse, the
	 *                                   range runs backwards or the source
	 *                                   is not one word.
	 * @throws \RuntimeException When the topology has no live worker.
	 */
	public static function ask( Command_Interpreter_Node $graph, string $request, string $since, string $until, string $source ): int {
		Graph_Query::one_of( 'request', $request, \array_keys( self::RANGES ) );
		Intel_Graph_Row_Schema::range( $since, $until );
		return Worker_Route::send( $graph, Config::get_base_directory(), [ [ 'reresolver', \rtrim( "{$request} {$since} {$until} " . self::one_word( $source ) ) ] ] );
	}

	/**
	 * How many stored items a range of UTC days dates, an undated one by its
	 * retrieval, of one source or of every one, across every partition: what
	 * a range request sends.
	 *
	 * @param string $since  The first day, Y-m-d.
	 * @param string $until  The last day, Y-m-d.
	 * @param string $source The source, or '' for every one.
	 * @throws \InvalidArgumentException When a day does not parse or the range runs backwards.
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection will not open.
	 */
	public static function count_dated( string $since, string $until, string $source ): int {
		[ $from, $to ]     = Intel_Graph_Row_Schema::range( $since, $until );
		[ $of, $of_binds ] = self::of_source( $source );
		$when              = Intel_Graph_Row_Schema::ITEM_TIME;
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $from, $to, $of, $of_binds, $when ): int {
				$count = 0;
				foreach ( $r->aliases() as $a ) {
					$count += Core::as_int( $r->rows( "SELECT COUNT(*) FROM {$a}.items i WHERE {$when} >= ? AND {$when} < ?{$of}", [ $from, $to, ...$of_binds ] )[0][0] ?? 0 );
				}
				return $count;
			}
		);
	}

	/**
	 * The condition, after an `AND`, keeping `items i` to one source, and
	 * its bind; none for every source.
	 *
	 * @param string $source The source, or ''.
	 * @return array{0:string,1:list<string>}
	 */
	private static function of_source( string $source ): array {
		return '' === $source ? [ '', [] ] : [ ' AND i.source = ?', [ $source ] ];
	}

	/**
	 * A source as a request line carries it, one word or ''.
	 *
	 * @param string $source The source.
	 * @throws \InvalidArgumentException When it holds whitespace.
	 */
	private static function one_word( string $source ): string {
		if ( 1 === \preg_match( '/\s/', $source ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "a source is one word: {$source}" );
		}
		return $source;
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

	/**
	 * Seconds the cache keeps the last range, a historical fact.
	 *
	 * @api Used by substrate.
	 */
	protected function status_ttl(): int {
		return self::STATUS_TTL_S;
	}

	/**
	 * None: it publishes only when a RERESOLVE arrives, and writes each one.
	 *
	 * @api Used by substrate.
	 */
	protected function status_heartbeat(): int {
		return 0;
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Re-resolves the stored items an ontology change can link anew, and every item in a requested day range.',
			'arguments'    => [
				[ 'name' => 'stream', 'type' => 'node_name', 'required' => true, 'description' => 'The stream every item_reresolve record is appended to: intel:reresolve.' ],
			],
			'requests'     => \array_map(
				static fn ( string $request, string $kind ): array => [
					'name'        => $request,
					'description' => 'Re-resolve every item dated in a range of UTC days, of one source when it names one' . ( 'reextract' === $kind ? ', each owed an extraction call' : '' ) . ": {$request} <since Y-m-d> <until Y-m-d> [<source>].",
					'reply_shape' => '{ since, until, source? } or { refused }',
					'handler'     => static fn ( self $node ): array => $node->queue_range(),
				],
				\array_keys( self::RANGES ),
				self::RANGES
			),
			'accepts_fill' => true,
			'has_target'   => false,
		];
	}
}

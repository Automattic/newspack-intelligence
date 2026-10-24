<?php
/**
 * Deduper_Node: clusters repeated items. Fed each `item_extracted` struct the
 * Extractor forwards, it looks for an earlier copy of the item: the same
 * `Url_Canon::of()` URL, or else the same `Entity_Index::norm()` title when
 * that runs at least MIN_TITLE_NORM characters, within WINDOW_DAYS of the
 * earlier copy. An item's time is its item_ts, or its retrieved_ts when
 * undated. A newcomer meeting a singleton founds a cluster keyed by the
 * earlier item: a `cluster_joined` for each, then a `cluster_superseded`
 * naming the higher-ranked as canonical, a tie keeping the earlier. A
 * newcomer meeting a cluster joins it, and supersedes its canonical only
 * when it outranks it; while the cluster's canonical is unknown it only
 * joins. An item already placed is never placed again; it only supersedes
 * its own cluster's canonical once it outranks it, and a canonical
 * re-delivered at a new rank supersedes itself to record that rank. Rank is relevance plus
 * importance. Every decision is a new event addressed by TO to the writer
 * its `make_node` line names (substrate ADR-19), so nothing edits an earlier
 * row.
 *
 * Lookup reads a bounded LRU first: each spelling, keyed by a hash of it,
 * names an item, its time and its cluster, and each cluster its canonical,
 * both as positional entries so the checkpoint stays small. A spelling it misses reads the
 * `intel-graph` projection, as does a cluster it no longer holds. The LRU
 * checkpoints with the resolve Consumer's cursor (`save_state()`), so a
 * respawned deduper decides from what it had decided, and a miss reaches only
 * items older than the LRU's horizon, which the projection has long caught
 * up with. While no active topology declares the table, a miss finds nothing.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\LRU_Cache;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

/**
 * Clusters repeated items and records each cluster's canonical by event.
 *
 * @phpstan-type Spelt array{item_key:string,item_ts:int,cluster_key:string,retrieved_ts:int}
 * @phpstan-type Cluster array{canonical_item_key:string,rank:float,founded:bool}
 */
class Deduper_Node extends Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** Characters a title norm needs before it matches on its own. */
	public const MIN_TITLE_NORM = 24;

	/** Days apart two copies may be and still meet. */
	public const WINDOW_DAYS = 14;

	/** Seconds apart two copies may be and still meet. */
	private const WINDOW_S = self::WINDOW_DAYS * Intel_Graph_Row_Schema::DAY_SECONDS;

	/** Entries the lookup holds: spellings and clusters. */
	public const LRU_SIZE = 5000;

	/** Buckets the LRU rotates through. */
	private const LRU_BUCKETS = 5;

	/**
	 * A spelling entry's slots: the item, its time and its cluster.
	 *
	 * @ordered
	 */
	private const SPELT_ITEM    = 0;
	private const SPELT_TS      = 1;
	private const SPELT_CLUSTER = 2;
	private const SPELT_RETRIEVED = 3;

	/**
	 * A cluster entry's slots: its canonical, that rank, and if founded.
	 *
	 * @ordered
	 */
	private const HELD_CANONICAL = 0;
	private const HELD_RANK      = 1;
	private const HELD_FOUNDED   = 2;

	/** Bytes of an unreadable checkpoint the drop's log line quotes. */
	private const DROPPED_EXCERPT = 200;

	/** Hex digits of a spelling's hash that key its LRU entry. */
	private const SPELLING_KEY_HEX = 16;

	/** The line a read logs while no projection opens. */
	private const UNAVAILABLE = 'no intel-graph to find copies in; reading none: ';

	/** The writer every cluster event is addressed to. */
	protected string $writer = '';

	/** Each spelling's item and each cluster's canonical. */
	private LRU_Cache $seen;

	/** Build the lookup. */
	public function __construct() {
		parent::__construct();
		$this->seen = new LRU_Cache( \intdiv( self::LRU_SIZE, self::LRU_BUCKETS ), self::LRU_BUCKETS );
	}

	/**
	 * Cluster one extracted item. Anything but an `item_extracted` struct is
	 * ignored; one that does not read is dropped with a rate-limited line.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		$value = $message[ Message::VALUE ];
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_array( $value ) || Extractor_Node::EXTRACTED !== ( $value['event'] ?? null ) ) {
			return;
		}
		$item       = Intel_Event::item_of( Core::arr( $value['item'] ?? null ) );
		$relevance  = $value['relevance'] ?? null;
		$importance = $value['importance'] ?? null;
		if ( null === $item || ! \is_int( $item['item_ts'] ) || ! \is_int( $item['retrieved_ts'] ) || ! ( \is_int( $relevance ) || \is_float( $relevance ) ) || ! ( \is_int( $importance ) || \is_float( $importance ) ) ) {
			$this->print_less_often( 'dropping an extracted item it cannot read from ', Core::as_string( $message[ Message::FROM ] ) );
			return;
		}
		$this->place( $item, (float) $relevance + (float) $importance );
	}

	/**
	 * Re-rank an item already placed, join it to an earlier copy's cluster,
	 * or let it stand alone; then remember it under each of its spellings.
	 *
	 * @param array<string,mixed> $item The item.
	 * @param float               $rank Its rank.
	 */
	private function place( array $item, float $rank ): void {
		$spellings = self::spellings( $item );
		if ( [] === $spellings ) {
			return;
		}
		$key       = Core::as_string( $item['item_key'] );
		$retrieved = Core::int( $item['retrieved_ts'] );
		$ts        = Intel_Graph_Row_Schema::item_time( Core::int( $item['item_ts'] ), $retrieved );
		$found     = $this->remembered( $key, $ts, $spellings );
		if ( null === $found ) {
			$found = $this->projected( $key, $ts, $retrieved, $spellings );
		} elseif ( $key !== $found['item_key'] ) {
			$found = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): ?array => self::own( $r, $key, $ts, $retrieved ), self::UNAVAILABLE ) ?? $found;
		}
		if ( null === $found ) {
			$found = [ 'item_key' => $key, 'item_ts' => $ts, 'cluster_key' => $key, 'retrieved_ts' => $retrieved ];
			$this->hold( $key, [ 'canonical_item_key' => $key, 'rank' => $rank, 'founded' => false ] );
		} elseif ( $key === $found['item_key'] ) {
			$this->rerank( $key, $rank, $retrieved, $found['cluster_key'] );
		} else {
			$this->join( $key, $rank, $retrieved, $found );
		}
		foreach ( $spellings as $column => $spelling ) {
			$this->seen->set( self::spelling_key( $column, $spelling ), [ self::SPELT_ITEM => $key, self::SPELT_TS => $ts, self::SPELT_CLUSTER => $key === $found['cluster_key'] ? null : $found['cluster_key'], self::SPELT_RETRIEVED => $retrieved ] );
		}
	}

	/**
	 * The item's spellings a copy is found by, URL first: its canonical URL,
	 * and its title norm when long enough.
	 *
	 * @param array<string,mixed> $item The item.
	 * @return array<string,string> Column => spelling.
	 */
	private static function spellings( array $item ): array {
		$spellings = [
			'url_canonical' => Url_Canon::of( Core::as_string( $item['url'] ) ),
			'title_norm'    => Entity_Index::norm( Core::as_string( $item['title'] ) ),
		];
		return \array_filter( $spellings, static fn ( string $spelling, string $column ): bool => \mb_strlen( $spelling ) >= ( 'title_norm' === $column ? self::MIN_TITLE_NORM : 1 ), \ARRAY_FILTER_USE_BOTH );
	}

	/**
	 * The item itself, if the LRU holds it under a spelling; else the earlier
	 * copy it holds under one, URL first, within the window.
	 *
	 * @param string               $key       The newcomer's key.
	 * @param int                  $ts        The newcomer's time.
	 * @param array<string,string> $spellings Column => spelling.
	 * @return Spelt|null
	 */
	private function remembered( string $key, int $ts, array $spellings ): ?array {
		$copies = [];
		foreach ( $spellings as $column => $spelling ) {
			$copy = self::spelt( $this->seen->get( self::spelling_key( $column, $spelling ) ) );
			if ( null !== $copy && $key === $copy['item_key'] ) {
				return $copy;
			}
			$copies[] = $copy;
		}
		foreach ( $copies as $copy ) {
			if ( null !== $copy && \abs( $ts - $copy['item_ts'] ) <= self::WINDOW_S ) {
				return $copy;
			}
		}
		return null;
	}

	/**
	 * The LRU key a spelling is held under: a fixed-length hash of it, so a
	 * checkpoint carries no URL or title.
	 *
	 * @param string $column   The spelling's column.
	 * @param string $spelling The spelling.
	 */
	private static function spelling_key( string $column, string $spelling ): string {
		return \substr( \md5( "{$column}:{$spelling}" ), 0, self::SPELLING_KEY_HEX );
	}

	/**
	 * An LRU spelling entry, typed; null when absent or misshapen. A null
	 * cluster slot is the item's own key, a singleton's or a founder's.
	 *
	 * @param mixed $entry What the LRU holds.
	 * @return Spelt|null
	 */
	private static function spelt( mixed $entry ): ?array {
		if ( ! \is_array( $entry ) || ! isset( $entry[ self::SPELT_ITEM ], $entry[ self::SPELT_TS ], $entry[ self::SPELT_RETRIEVED ] ) || ! \array_key_exists( self::SPELT_CLUSTER, $entry ) ) {
			return null;
		}
		$key = Core::as_string( $entry[ self::SPELT_ITEM ] );
		return [ 'item_key' => $key, 'item_ts' => Core::as_int( $entry[ self::SPELT_TS ] ), 'cluster_key' => Core::as_string( $entry[ self::SPELT_CLUSTER ] ?? $key ), 'retrieved_ts' => Core::as_int( $entry[ self::SPELT_RETRIEVED ] ) ];
	}

	/**
	 * The item itself, if the projection holds its own cluster row; else the
	 * extracted copy nearest it. None when the table will not open.
	 *
	 * @param string               $key       The newcomer's key.
	 * @param int                  $ts        The newcomer's time.
	 * @param int                  $retrieved The newcomer's retrieval.
	 * @param array<string,string> $spellings Column => spelling.
	 * @return Spelt|null
	 */
	private function projected( string $key, int $ts, int $retrieved, array $spellings ): ?array {
		return Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): ?array => self::own( $r, $key, $ts, $retrieved ) ?? self::nearest( $r, $key, $ts, $spellings ), self::UNAVAILABLE );
	}

	/**
	 * The item's own cluster row, as a spelling entry.
	 *
	 * @param Row_Reader $r         The reader.
	 * @param string     $key       The item's key.
	 * @param int        $ts        The item's time.
	 * @param int        $retrieved The item's retrieval.
	 * @return Spelt|null
	 */
	private static function own( Row_Reader $r, string $key, int $ts, int $retrieved ): ?array {
		foreach ( $r->aliases() as $a ) {
			foreach ( $r->rows( "SELECT cluster_key FROM {$a}.clusters WHERE item_key = ?", [ $key ] ) as [ $cluster_key ] ) {
				return [ 'item_key' => $key, 'item_ts' => $ts, 'cluster_key' => Core::as_string( $cluster_key ), 'retrieved_ts' => $retrieved ];
			}
		}
		return null;
	}

	/**
	 * The extracted copy across every partition's file sharing the URL, else
	 * the title, nearest the newcomer in time within the window.
	 *
	 * @param Row_Reader           $r         The reader.
	 * @param string               $key       The newcomer's key.
	 * @param int                  $ts        The newcomer's time.
	 * @param array<string,string> $spellings Column => spelling.
	 * @return Spelt|null
	 */
	private static function nearest( Row_Reader $r, string $key, int $ts, array $spellings ): ?array {
		$when  = Intel_Graph_Row_Schema::ITEM_TIME;
		$match = \implode( ' OR ', \array_map( static fn ( string $column ): string => "i.{$column} = ?", \array_keys( $spellings ) ) );
		$binds = [ $spellings['url_canonical'] ?? null, ...\array_values( $spellings ), $key, $ts - self::WINDOW_S, $ts + self::WINDOW_S, $ts ];
		$best  = null;
		$order = null;
		foreach ( $r->aliases() as $a ) {
			$sql = "SELECT i.item_key, {$when}, COALESCE( c.cluster_key, i.item_key ), COALESCE( i.url_canonical = ?, 0 ), i.retrieved_ts FROM {$a}.items i JOIN {$a}.extractions e ON e.item_key = i.item_key LEFT JOIN {$a}.clusters c ON c.item_key = i.item_key WHERE ( {$match} ) AND i.item_key <> ? AND {$when} BETWEEN ? AND ? ORDER BY 4 DESC, ABS( {$when} - ? ) LIMIT 1";
			foreach ( $r->rows( $sql, $binds ) as [ $item_key, $item_ts, $cluster_key, $by_url, $retrieved ] ) {
				$item_ts = Core::as_int( $item_ts );
				$rank    = [ -Core::as_int( $by_url ), \abs( $ts - $item_ts ) ];
				if ( null === $order || $rank < $order ) {
					$order = $rank;
					$best  = [ 'item_key' => Core::as_string( $item_key ), 'item_ts' => $item_ts, 'cluster_key' => Core::as_string( $cluster_key ), 'retrieved_ts' => Core::as_int( $retrieved ) ];
				}
			}
		}
		return $best;
	}

	/**
	 * Write the newcomer's join to a cluster: founding it when its earlier
	 * copy stands alone, superseding its canonical when the newcomer
	 * outranks it, and the join alone while its canonical is unknown.
	 *
	 * A cluster stands unfounded only while its one item is the copy found,
	 * so the founder's retrieval is the found copy's.
	 *
	 * @param string $key       The newcomer's key.
	 * @param float  $rank      The newcomer's rank.
	 * @param int    $retrieved The newcomer's retrieval.
	 * @param Spelt  $found     The earlier copy.
	 */
	private function join( string $key, float $rank, int $retrieved, array $found ): void {
		$cluster = $found['cluster_key'];
		$state   = $this->cluster( $cluster );
		if ( null !== $state && ! $state['founded'] ) {
			$this->write( 'cluster_joined', [ 'item_key' => $cluster, 'cluster_key' => $cluster, 'retrieved_ts' => $found['retrieved_ts'] ] );
		}
		$this->write( 'cluster_joined', [ 'item_key' => $key, 'cluster_key' => $cluster, 'retrieved_ts' => $retrieved ] );
		if ( null === $state ) {
			return;
		}
		if ( $rank > $state['rank'] ) {
			$this->supersede( $cluster, $key, $retrieved, $rank, $state['canonical_item_key'] );
		} elseif ( ! $state['founded'] ) {
			$this->supersede( $cluster, $cluster, $found['retrieved_ts'], $state['rank'], $cluster );
		}
	}

	/**
	 * Re-rank an item already placed: a singleton takes its new rank, a
	 * canonical that rises records it by superseding itself, one that falls
	 * yields to its best-ranked member, and a member that now outranks its
	 * cluster's canonical supersedes it.
	 *
	 * @param string $key       The item's key.
	 * @param float  $rank      Its rank.
	 * @param int    $retrieved Its retrieval.
	 * @param string $cluster   Its cluster key.
	 */
	private function rerank( string $key, float $rank, int $retrieved, string $cluster ): void {
		$state = $this->cluster( $cluster );
		if ( null === $state ) {
			return;
		}
		if ( ! $state['founded'] ) {
			$this->hold( $cluster, [ 'canonical_item_key' => $key, 'rank' => $rank, 'founded' => false ] );
		} elseif ( $key === $state['canonical_item_key'] && $rank < $state['rank'] ) {
			[ $best, $best_rank, $best_retrieved ] = $this->best_member( $cluster, $key, $rank, $retrieved );
			$this->supersede( $cluster, $best, $best_retrieved, $best_rank, $key );
		} elseif ( $rank > $state['rank'] ) {
			$this->supersede( $cluster, $key, $retrieved, $rank, $state['canonical_item_key'] );
		}
	}

	/**
	 * The best-ranked member of a cluster as the projection holds its
	 * members, the re-ranked item at its new rank; a tie keeps the earlier.
	 *
	 * @param string $cluster   The cluster key.
	 * @param string $key       The re-ranked item.
	 * @param float  $rank      Its new rank.
	 * @param int    $retrieved Its retrieval.
	 * @return array{0:string,1:float,2:int} The member, its rank and its retrieval.
	 */
	private function best_member( string $cluster, string $key, float $rank, int $retrieved ): array {
		$members = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => self::members_of( $r, $cluster ), self::UNAVAILABLE ) ?? [];
		$best    = [ $key, $rank, $members[ $key ][1] ?? 0, $retrieved ];
		foreach ( $members as $member => [ $member_rank, $member_ts, $member_retrieved ] ) {
			if ( $member !== $key && ( $member_rank > $best[1] || ( $member_rank === $best[1] && $member_ts < $best[2] ) ) ) {
				$best = [ $member, $member_rank, $member_ts, $member_retrieved ];
			}
		}
		return [ $best[0], $best[1], $best[3] ];
	}

	/**
	 * Each extracted member of a cluster, across every partition's file.
	 *
	 * @param Row_Reader $r       The reader.
	 * @param string     $cluster The cluster key.
	 * @return array<string,array{0:float,1:int,2:int}> Member => its rank, time and retrieval.
	 */
	private static function members_of( Row_Reader $r, string $cluster ): array {
		$when    = Intel_Graph_Row_Schema::ITEM_TIME;
		$members = [];
		foreach ( $r->aliases() as $a ) {
			$sql = "SELECT c.item_key, e.relevance + e.importance, {$when}, i.retrieved_ts FROM {$a}.clusters c JOIN {$a}.extractions e ON e.item_key = c.item_key JOIN {$a}.items i ON i.item_key = c.item_key WHERE c.cluster_key = ?";
			foreach ( $r->rows( $sql, [ $cluster ] ) as [ $member, $rank, $ts, $retrieved ] ) {
				$members[ Core::as_string( $member ) ] = [ Core::as_float( $rank ), Core::as_int( $ts ), Core::as_int( $retrieved ) ];
			}
		}
		return $members;
	}

	/**
	 * A cluster's canonical: the LRU's, else the projection's, read again and
	 * remembered. A cluster with no canonical row is a singleton when no
	 * member row names it either; null when its canonical is unknown.
	 *
	 * @param string $cluster The cluster key.
	 * @return Cluster|null
	 */
	private function cluster( string $cluster ): ?array {
		$held = $this->seen->get( "cluster:{$cluster}" );
		if ( \is_array( $held ) && isset( $held[ self::HELD_CANONICAL ], $held[ self::HELD_RANK ], $held[ self::HELD_FOUNDED ] ) ) {
			return [ 'canonical_item_key' => Core::as_string( $held[ self::HELD_CANONICAL ] ), 'rank' => Core::as_float( $held[ self::HELD_RANK ] ), 'founded' => true === $held[ self::HELD_FOUNDED ] ];
		}
		$state = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): ?array => self::state_of( $r, $cluster ), self::UNAVAILABLE );
		if ( null !== $state ) {
			$this->hold( $cluster, $state );
		}
		return $state;
	}

	/**
	 * A cluster's state as the projection holds it, across every partition.
	 *
	 * @param Row_Reader $r       The reader.
	 * @param string     $cluster The cluster key, which is its founding item's.
	 * @return Cluster|null
	 */
	private static function state_of( Row_Reader $r, string $cluster ): ?array {
		$members = false;
		$alone   = null;
		foreach ( $r->aliases() as $a ) {
			$sql = "SELECT ( SELECT canonical_item_key FROM {$a}.canonicals WHERE cluster_key = ? ), ( SELECT rank FROM {$a}.canonicals WHERE cluster_key = ? ), EXISTS ( SELECT 1 FROM {$a}.clusters WHERE cluster_key = ? ), ( SELECT relevance + importance FROM {$a}.extractions WHERE item_key = ? )";
			foreach ( $r->rows( $sql, [ $cluster, $cluster, $cluster, $cluster ] ) as [ $canonical, $rank, $member, $own_rank ] ) {
				if ( null !== $canonical ) {
					return [ 'canonical_item_key' => Core::as_string( $canonical ), 'rank' => Core::as_float( $rank ), 'founded' => true ];
				}
				$members = $members || 0 !== Core::as_int( $member );
				$alone ??= null === $own_rank ? null : Core::as_float( $own_rank );
			}
		}
		return $members || null === $alone ? null : [ 'canonical_item_key' => $cluster, 'rank' => $alone, 'founded' => false ];
	}

	/**
	 * Record a cluster's canonical, by event and in the LRU.
	 *
	 * @param string $cluster   The cluster key.
	 * @param string $canonical The canonical.
	 * @param int    $retrieved The canonical's retrieval, which dates the row.
	 * @param float  $rank      Its rank.
	 * @param string $previous  The canonical it replaces.
	 */
	private function supersede( string $cluster, string $canonical, int $retrieved, float $rank, string $previous ): void {
		$this->write( 'cluster_superseded', [ 'cluster_key' => $cluster, 'canonical_item_key' => $canonical, 'previous_item_key' => $previous, 'rank' => $rank, 'retrieved_ts' => $retrieved ] );
		$this->hold( $cluster, [ 'canonical_item_key' => $canonical, 'rank' => $rank, 'founded' => true ] );
	}

	/**
	 * Remember a cluster's state as one positional `cluster:<key>` entry,
	 * replacing any copy an older bucket holds.
	 *
	 * @param string  $cluster The cluster key.
	 * @param Cluster $state   Its state.
	 */
	private function hold( string $cluster, array $state ): void {
		$this->seen->delete( "cluster:{$cluster}" );
		$this->seen->set( "cluster:{$cluster}", [ self::HELD_CANONICAL => $state['canonical_item_key'], self::HELD_RANK => $state['rank'], self::HELD_FOUNDED => $state['founded'] ] );
	}

	/**
	 * Write one cluster event to the writer.
	 *
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 */
	private function write( string $kind, array $fields ): void {
		$this->emit( $this->writer, Intel_Event::machine_event( 'dedup', $kind, $fields ) );
	}

	/**
	 * The lookup, which the resolve Consumer co-commits with its cursor.
	 *
	 * @api Called by the substrate's Consumer at each checkpoint.
	 * @return array{seen:array<string,mixed>}
	 */
	public function save_state(): array {
		return [ 'seen' => $this->seen->get_state() ];
	}

	/**
	 * Restore the lookup the Consumer checkpointed with the cursor it resumes;
	 * one it cannot read is dropped, the drop logged with its start.
	 *
	 * @api Called by the substrate's Consumer at its first poll.
	 * @param array<array-key,mixed> $saved What save_state() answered.
	 */
	public function restore_state( array $saved ): void {
		$seen = $saved['seen'] ?? null;
		if ( ! \is_array( $seen ) || ! \is_array( $seen['buckets'] ?? null ) || ! \is_int( $seen['current'] ?? null ) ) {
			$this->print_less_often( 'dropping a lookup checkpoint it cannot read: ', \substr( (string) \wp_json_encode( $seen ), 0, self::DROPPED_EXCERPT ) );
			return;
		}
		/** @var array<string,mixed> $seen */
		$this->seen->restore_state( $seen );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Clusters repeated items by canonical URL or long title within ' . self::WINDOW_DAYS . ' days, and records each cluster\'s best-ranked item as its canonical.',
			'arguments'    => [
				[ 'name' => 'writer', 'type' => 'node_name', 'required' => true, 'description' => 'The event writer every cluster event is addressed to: intel:writer.' ],
			],
			'accepts_fill' => true,
			'has_target'   => false,
		];
	}
}

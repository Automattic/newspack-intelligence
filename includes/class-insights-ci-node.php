<?php
/**
 * Insights_CI_Node: the dashboard's server-side read, decomposed into three slice
 * verbs — `counts`/`top`/`accumulated` — built via Service_CI_Node::slice_verb()
 * over ONE memoized scored-snapshot read (mirroring the de-godded teaching example).
 * The `accumulated` slice also carries the rendered digest (latest `digest:log`
 * segment) and collection progress (done/total). It keeps the action verbs
 * `generate`/`collect`, which route Regenerate / Collect to the worker's nodes over
 * the input IPC partition — durable, synchronous, no live-worker dependency on read.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Service_CI_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Worker_Base;

\defined( 'ABSPATH' ) || exit;

class Insights_CI_Node extends Service_CI_Node {

	/** The source node names Collect ticks; their count MUST equal the digest's `total` make_node arg in newspack-intelligence-digest.tsl. */
	private const SOURCE_NODES = [ 'github', 'linear', 'feed' ];

	/** The worker topology whose sources Collect ticks; also the worker-id prefix. */
	private const TOPOLOGY = 'newspack-intelligence';

	private const TOP_N = 10;

	/**
	 * Scored-snapshot read seam. Lazily-defaulted to read_snapshot(); tests reassign it
	 * to count reads without short-circuiting the real glob/merge path. The memoized
	 * snapshot() resolves and invokes it at most once per request.
	 *
	 * Signature: `function ( string $offsets_dir ): array{items: array<int,array<array-key,mixed>>, done: int, total: int}`.
	 *
	 * @var \Closure|null
	 */
	public static ?\Closure $read_items = null;

	/**
	 * Per-request memo of the scored snapshot; null until snapshot() reads once.
	 *
	 * @var array{items: array<int,array<array-key,mixed>>, done: int, total: int}|null
	 */
	private ?array $snapshot_cache = null;

	/**
	 * The memoized scored items (shared by every slice).
	 *
	 * @return array<int,array<array-key,mixed>>
	 */
	private function items(): array {
		return $this->snapshot()['items'];
	}

	/**
	 * Trigger a collection cycle: reset the digest's progress counter, then TICK every
	 * source, all routed into each live worker's input IPC partition
	 * (the only transport from the request graph to a worker's nodes — the same one
	 * `wp nodes cli` uses). Fire-and-forget; the dashboard polls progress separately.
	 *
	 * @param Command_Interpreter_Node $interpreter The request-graph interpreter (this CI).
	 * @param string                   $base_dir    The substrate base directory (ipc/locks live under it).
	 */
	public static function collect( Command_Interpreter_Node $interpreter, string $base_dir ): string {
		$workers = self::live_workers( $base_dir );
		if ( [] === $workers ) {
			return (string) \wp_json_encode(
				[ 'error' => 'No live ' . self::TOPOLOGY . ' worker — start the workers first.' ]
			);
		}
		$total = \count( self::SOURCE_NODES );
		foreach ( $workers as $worker_id ) {
			$out = self::ipc_out( $interpreter, $worker_id, $base_dir );
			if ( null === $out ) {
				continue;
			}
			self::route_to_worker( $interpreter, $worker_id, 'digest', 'RESET' );
			foreach ( self::SOURCE_NODES as $source ) {
				self::route_to_worker( $interpreter, $worker_id, $source, 'TICK' );
			}
			$out->flush();
		}
		return (string) \wp_json_encode( [ 'collecting' => $total, 'workers' => \count( $workers ) ] );
	}

	/**
	 * Ask the worker to recompose: route a single TM_REQUEST REGENERATE to its
	 * `digest` node (the same request graph → worker IPC transport `collect` uses).
	 * The worker's Digest_Builder composes from its live items and writes digest:log;
	 * the dashboard's poll surfaces the new digest. Fire-and-forget — no markdown here.
	 *
	 * @param Command_Interpreter_Node $interpreter The request-graph interpreter (this CI).
	 * @param string                   $base_dir    The substrate base directory (ipc/locks live under it).
	 */
	public static function regenerate( Command_Interpreter_Node $interpreter, string $base_dir ): string {
		$workers = self::live_workers( $base_dir );
		if ( [] === $workers ) {
			return (string) \wp_json_encode(
				[ 'error' => 'No live ' . self::TOPOLOGY . ' worker — start the workers first.' ]
			);
		}
		foreach ( $workers as $worker_id ) {
			$out = self::ipc_out( $interpreter, $worker_id, $base_dir );
			if ( null === $out ) {
				continue;
			}
			self::route_to_worker( $interpreter, $worker_id, 'digest', 'REGENERATE' );
			$out->flush();
		}
		return (string) \wp_json_encode( [ 'regenerating' => true, 'workers' => \count( $workers ) ] );
	}

	/**
	 * Live worker ids of this topology — those with a `{base}/locks/{id}.lock.d` dir.
	 *
	 * @return array<int,string>
	 */
	public static function live_workers( string $base_dir ): array {
		$locks = \glob( \rtrim( $base_dir, '/' ) . '/locks/' . self::TOPOLOGY . '.p*.lock.d', \GLOB_ONLYDIR );
		if ( false === $locks ) {
			return [];
		}
		$ids = [];
		foreach ( $locks as $lock ) {
			$ids[] = \substr( \basename( $lock ), 0, -\strlen( '.lock.d' ) );
		}
		return $ids;
	}

	/**
	 * Mount (idempotently) the outbound Partition that appends to a worker's input
	 * IPC log; null if the name is already taken by a non-Partition node.
	 */
	private static function ipc_out( Command_Interpreter_Node $interpreter, string $worker_id, string $base_dir ): ?Partition_Node {
		$existing = Core::node( $worker_id );
		if ( $existing instanceof Partition_Node ) {
			return $existing;
		}
		if ( null !== $existing ) {
			return null;
		}
		$input = \rtrim( $base_dir, '/' ) . '/ipc/' . $worker_id . '/input';
		$node  = $interpreter->make_node( 'Partition', $worker_id, ...Worker_Base::ipc_partition_args( $input ) );
		if ( ! $node instanceof Partition_Node ) {
			return null;
		}
		// Unbuffered so the worker sees the appended TICK/RESET immediately.
		$node->void_warranty();
		return $node;
	}

	/**
	 * Route a TM_REQUEST to a node inside a worker: TO=`{worker_id}/{node}` so the
	 * request router peels the worker id to its IPC-out Partition (which appends the
	 * message for the worker to read and re-route to `{node}`). The verb rides in
	 * VALUE — matching `request_node <path> <verb>` — so the digest reads it there.
	 *
	 * @param string $verb The request verb in VALUE (e.g. RESET for the digest, TICK for a source).
	 */
	private static function route_to_worker( Command_Interpreter_Node $interpreter, string $worker_id, string $node, string $verb ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'insights';
		$message[ Message::TO ]    = $worker_id . '/' . $node;
		$message[ Message::VALUE ] = $verb;
		$interpreter->fill( $message );
	}

	/**
	 * The accumulated slice: total item count, collection progress, and the rendered digest.
	 * Reads the shared memoized snapshot for accumulated/done/total; the digest is a separate
	 * file (only this slice needs it), read inline.
	 *
	 * @return array{accumulated:int, done:int, total:int, digest:string}
	 */
	private function accumulated_slice(): array {
		$snapshot = $this->snapshot();
		return [
			'accumulated' => \count( $snapshot['items'] ),
			'done'        => $snapshot['done'],
			'total'       => $snapshot['total'],
			'digest'      => self::read_latest_digest( Digest_Builder_Node::DIGEST_PATH ),
		];
	}

	/**
	 * Read the scored offsetlog snapshot ONCE per request and memoize it, so the three
	 * batched slice verbs share a single glob + unpack instead of reading thrice.
	 *
	 * @return array{items: array<int,array<array-key,mixed>>, done: int, total: int}
	 */
	private function snapshot(): array {
		if ( null !== $this->snapshot_cache ) {
			return $this->snapshot_cache;
		}
		$read = self::$read_items ?? static fn ( string $dir ): array => self::read_snapshot( $dir );
		$raw  = $read( Config::get_offsets_directory() );
		$raw  = Core::arr( $raw );

		$items = [];
		foreach ( ( \is_array( $raw['items'] ?? null ) ? $raw['items'] : [] ) as $item ) {
			if ( \is_array( $item ) ) {
				$items[] = $item;
			}
		}
		$this->snapshot_cache = [
			'items' => $items,
			'done'  => Core::num_int( $raw['done'] ?? null ),
			'total' => Core::num_int( $raw['total'] ?? null ),
		];
		return $this->snapshot_cache;
	}

	/**
	 * Read every `scored.p*` offset dir's latest snapshot and merge into one
	 * `{ items, done, total }`: items are flattened (the substrate's
	 * Partition_Node::read_latest_snapshot_cache), done/total are summed across
	 * partitions (the digest's save_state progress the dashboard gates buttons on).
	 *
	 * @return array{items: array<int,array<array-key,mixed>>, done: int, total: int}
	 */
	public static function read_snapshot( string $offsets_dir ): array {
		$items = Partition_Node::read_latest_snapshot_cache( $offsets_dir, 'scored.p*' );
		$done  = 0;
		$total = 0;
		foreach ( self::scored_dirs( $offsets_dir ) as $dir ) {
			$cache  = self::read_cache( $dir );
			$done  += Core::num_int( $cache['done'] ?? null );
			$total += Core::num_int( $cache['total'] ?? null );
		}
		return [ 'items' => $items, 'done' => $done, 'total' => $total ];
	}

	/**
	 * The latest rendered digest: the newest `{path}.{seg}` segment the digest:log
	 * Node writes (Log lays segments out as `{file}.0`, `{file}.1`, …; segment_size=1
	 * gives one digest per segment, so the highest suffix is the most recent).
	 * '' when nothing has been flushed yet.
	 */
	public static function read_latest_digest( string $path ): string {
		$segments = \glob( $path . '.*' );
		if ( false === $segments || [] === $segments ) {
			return '';
		}
		$newest = '';
		$best   = -1;
		foreach ( $segments as $segment ) {
			$suffix = \substr( $segment, \strlen( $path ) + 1 );
			if ( ! \ctype_digit( $suffix ) ) {
				continue;
			}
			$seg = (int) $suffix;
			if ( $seg > $best ) {
				$best   = $seg;
				$newest = $segment;
			}
		}
		if ( '' === $newest ) {
			return '';
		}
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local log segment, not a remote fetch.
		$content = \file_get_contents( $newest );
		return Core::str( $content );
	}

	/**
	 * The `scored.p*` offset dirs under the offsets directory (empty on glob failure).
	 *
	 * @return array<int,string>
	 */
	private static function scored_dirs( string $offsets_dir ): array {
		$dirs = \glob( \rtrim( $offsets_dir, '/' ) . '/scored.p*', \GLOB_ONLYDIR );
		return false === $dirs ? [] : $dirs;
	}

	/**
	 * The digest snapshot cache co-committed into one offset dir's latest record
	 * (the digest's save_state: items + done + total). Mirrors CLI::read_offsetlog_entry.
	 *
	 * @return array<array-key,mixed>
	 */
	private static function read_cache( string $offsetlog_dir ): array {
		$value = Partition_Node::read_latest_value_at( $offsetlog_dir );
		return \is_array( $value ) && \is_array( $value['cache'] ?? null ) ? $value['cache'] : [];
	}

	/**
	 * Group items into a per-source top-10, each source's list sorted by score desc — so the
	 * dashboard shows the top github items, top linear items, etc. separately rather than one
	 * global list a single high-scoring source can dominate. Keyed by source, first-seen order.
	 *
	 * @param array<int,array<array-key,mixed>> $items
	 * @return array<string,array<int,array{title:string,score:float}>>
	 */
	public static function top_by_source( array $items ): array {
		$by_source = [];
		foreach ( $items as $item ) {
			$source                 = \is_string( $item['source'] ?? null ) ? $item['source'] : '?';
			$by_source[ $source ][] = [
				'title' => \is_string( $item['title'] ?? null ) ? $item['title'] : '',
				'score' => Core::num_float( $item['score'] ?? null ),
			];
		}
		foreach ( $by_source as &$list ) {
			\usort( $list, static fn ( array $a, array $b ): int => $b['score'] <=> $a['score'] );
			$list = \array_slice( $list, 0, self::TOP_N );
		}
		unset( $list );
		return $by_source;
	}

	/**
	 * Count items per source.
	 *
	 * @param array<int,array<array-key,mixed>> $items
	 * @return array<string,int>
	 */
	private static function shape_sources( array $items ): array {
		$sources = [];
		foreach ( $items as $item ) {
			$source             = \is_string( $item['source'] ?? null ) ? $item['source'] : '?';
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
		}
		return $sources;
	}

	public static function node_schema(): array {
		// slice_verb wraps each slice; gate lives in commands_from_schema().
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Reads the scored-pipeline offsetlog snapshot + rendered digest; serves the dashboard insights slices and recomposes on demand.',
			'commands'    => [
				[
					'name'        => 'counts',
					'description' => 'Return per-source item counts: { sources: { source: count } }.',
					'args'        => [],
					'handler'     => self::slice_verb( static fn ( self $ci ): array => [ 'sources' => self::shape_sources( $ci->items() ) ] ),
				],
				[
					'name'        => 'top',
					'description' => 'Return the per-source top-10 items by score: { top: { source: [ { title, score } ] } }.',
					'args'        => [],
					'handler'     => self::slice_verb( static fn ( self $ci ): array => [ 'top' => self::top_by_source( $ci->items() ) ] ),
				],
				[
					'name'        => 'accumulated',
					'description' => 'Return the total item count, collection progress, and rendered digest: { accumulated, done, total, digest }.',
					'args'        => [],
					'handler'     => self::slice_verb( static fn ( self $ci ): array => $ci->accumulated_slice() ),
				],
				[
					'name'        => 'generate',
					'description' => 'Ask the worker to recompose the digest (TM_REQUEST REGENERATE to its digest node); the dashboard polls for the result.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						self::require_manage_options();
						return self::regenerate( $interpreter, Config::get_base_directory() );
					},
				],
				[
					'name'        => 'collect',
					'description' => 'Reset the digest counter and TICK every source to collect — the dashboard Collect button.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, array $args ): string {
						self::require_manage_options();
						return self::collect( $interpreter, Config::get_base_directory() );
					},
				],
			],
		] );
	}
}

<?php
/**
 * Insights_CI_Node: the dashboard's server-side read. Its `insights` verb reads the
 * latest offsetlog snapshot the Consumer co-commits (the digest's save_state cache)
 * and returns a shaped model — durable, synchronous, no live-worker dependency.
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

	private const TOP_N = 10;

	/** The worker topology whose sources Collect ticks; also the worker-id prefix. */
	private const TOPOLOGY = 'newspack-ai-newsletter';

	/** The source node names Collect ticks; their count is the digest's `total`. */
	private const SOURCE_NODES = [ 'github', 'linear', 'feed' ];

	/** Coerce an untrusted (JSON-sourced) score to float; non-numeric → 0.0. */
	private static function to_float( mixed $value ): float {
		return \is_numeric( $value ) ? (float) $value : 0.0;
	}

	/** JSON model for the `insights` verb; resolves the live offsets dir + digest path. */
	public function build_insights_json(): string {
		$model = self::read_insights_model( Config::get_offsets_directory(), Settings::DIGEST_PATH );
		return (string) \wp_json_encode( $model );
	}

	/**
	 * Testable core: merge every `scored.p*` snapshot into { sources:{name:count},
	 * top:[{source,title,score}], accumulated:N }, attach `digest` (the latest
	 * rendered digest:log segment), and the collection progress `done`/`total`
	 * (summed across partitions) the dashboard gates its buttons on.
	 *
	 * @return array{sources: array<string,int>, top: array<int,array<string,mixed>>, accumulated: int, digest: string, done: int, total: int}
	 */
	public static function read_insights_model( string $offsets_dir, string $digest_path ): array {
		$digest = self::read_latest_digest( $digest_path );
		$items  = [];
		$done   = 0;
		$total  = 0;
		foreach ( self::scored_dirs( $offsets_dir ) as $dir ) {
			$cache = self::read_cache( $dir );
			foreach ( self::cache_items( $cache ) as $item ) {
				$items[] = $item;
			}
			$done  += self::int_of( $cache['done'] ?? null );
			$total += self::int_of( $cache['total'] ?? null );
		}
		$progress = [ 'digest' => $digest, 'done' => $done, 'total' => $total ];

		if ( [] === $items ) {
			return \array_merge( [ 'sources' => [], 'top' => [], 'accumulated' => 0 ], $progress );
		}

		$sources = [];
		foreach ( $items as $item ) {
			$source             = \is_string( $item['source'] ?? null ) ? $item['source'] : '?';
			$sources[ $source ] = ( $sources[ $source ] ?? 0 ) + 1;
		}

		\usort(
			$items,
			static fn ( array $a, array $b ): int => self::to_float( $b['score'] ?? null ) <=> self::to_float( $a['score'] ?? null )
		);
		$top = [];
		foreach ( \array_slice( $items, 0, self::TOP_N ) as $item ) {
			$top[] = [
				'source' => $item['source'] ?? '?',
				'title'  => $item['title'] ?? '',
				'score'  => self::to_float( $item['score'] ?? null ),
			];
		}

		return \array_merge( [ 'sources' => $sources, 'top' => $top, 'accumulated' => \count( $items ) ], $progress );
	}

	/**
	 * Merge every `scored.p*` snapshot's accumulated items into one list (the full
	 * item objects, not the trimmed top) — the input the `generate` recompose reads.
	 *
	 * @return array<int,array<array-key,mixed>>
	 */
	public static function read_snapshot_items( string $offsets_dir ): array {
		$items = [];
		foreach ( self::scored_dirs( $offsets_dir ) as $dir ) {
			foreach ( self::cache_items( self::read_cache( $dir ) ) as $item ) {
				$items[] = $item;
			}
		}
		return $items;
	}

	/**
	 * Trigger a collection cycle: reset the digest counter (total = the source count)
	 * then TICK every source, all routed into each live worker's input IPC partition
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
			self::route_to_worker( $interpreter, $worker_id, 'digest', 'RESET', $total );
			foreach ( self::SOURCE_NODES as $source ) {
				self::route_to_worker( $interpreter, $worker_id, $source, 'TICK', '' );
			}
			$out->flush();
		}
		return (string) \wp_json_encode( [ 'collecting' => $total, 'workers' => \count( $workers ) ] );
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
		$node  = $interpreter->make_node( 'Partition', $worker_id, $input, Worker_Base::IPC_SEGMENT_SIZE, Worker_Base::IPC_NUM_SEGMENTS );
		if ( ! $node instanceof Partition_Node ) {
			return null;
		}
		// Unbuffered: a separate reader (the worker's input consumer) must see the
		// appended TICK/RESET immediately, not only when this request process exits.
		$node->void_warranty();
		return $node;
	}

	/**
	 * Route a TM_REQUEST to a node inside a worker: TO=`{worker_id}/{node}` so the
	 * request router peels the worker id to its IPC-out Partition (which appends the
	 * message for the worker to read and re-route to `{node}`).
	 *
	 * @param mixed $value The request VALUE (RESET's total, or '' for TICK).
	 */
	private static function route_to_worker( Command_Interpreter_Node $interpreter, string $worker_id, string $node, string $key, mixed $value ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_REQUEST;
		$message[ Message::FROM ]  = 'insights';
		$message[ Message::TO ]    = $worker_id . '/' . $node;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $value;
		$interpreter->fill( $message );
	}

	/**
	 * The latest rendered digest: the newest `{path}.{seg}` segment the digest:log
	 * Node writes (Log lays segments out as `{file}.0`, `{file}.1`, …; segment_size=1
	 * gives one digest per segment, so the highest suffix is the most recent FLUSH).
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
		return \is_string( $content ) ? $content : '';
	}

	/**
	 * Recompose a fresh digest from the given items via the shared composer (LLM,
	 * ranked-list fallback) and return it as `{ digest: markdown }` JSON.
	 *
	 * @param array<int,array<array-key,mixed>> $items
	 */
	public static function generate_json( array $items ): string {
		$draft = Digest_Composer::compose( $items, Settings::llm_client(), Settings::get_string( 'relevance_profile' ) );
		return (string) \wp_json_encode( [ 'digest' => $draft ] );
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
	private static function read_cache( string $offset_dir ): array {
		$value = Partition_Node::read_latest_value_at( $offset_dir );
		return \is_array( $value ) && \is_array( $value['cache'] ?? null ) ? $value['cache'] : [];
	}

	/**
	 * The array items of a snapshot cache (drops non-array entries).
	 *
	 * @param array<array-key,mixed> $cache
	 * @return array<int,array<array-key,mixed>>
	 */
	private static function cache_items( array $cache ): array {
		$items = $cache['items'] ?? null;
		if ( ! \is_array( $items ) ) {
			return [];
		}
		$out = [];
		foreach ( $items as $item ) {
			if ( \is_array( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/** Coerce an untrusted (JSON-sourced) value to int; non-numeric → 0. */
	private static function int_of( mixed $value ): int {
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	public static function node_schema(): array {
		return \array_merge( parent::node_schema(), [
			'category'    => 'Service',
			'description' => 'Reads the scored-pipeline snapshot + rendered digest; serves the dashboard insights model and recomposes on demand.',
			'commands'    => [
				[
					'name'        => 'insights',
					'description' => 'Return the current Publisher Insights model (sources, top, accumulated, digest).',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						self::require_manage_options();
						// A Service_CI verb runs on the CI itself — the interpreter IS this node.
						/** @var self $ci */
						$ci = $interpreter;
						return $ci->build_insights_json();
					},
				],
				[
					'name'        => 'generate',
					'description' => 'Recompose a fresh digest from the current items via the LLM; returns its markdown.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						self::require_manage_options();
						return self::generate_json( self::read_snapshot_items( Config::get_offsets_directory() ) );
					},
				],
				[
					'name'        => 'collect',
					'description' => 'Reset the digest counter and TICK every source to collect — the dashboard Collect button.',
					'args'        => [],
					'handler'     => static function ( Command_Interpreter_Node $interpreter, string $args ): string {
						self::require_manage_options();
						return self::collect( $interpreter, Config::get_base_directory() );
					},
				],
			],
		] );
	}
}

<?php
/**
 * Graph_Trace: the `trace` read, how one item was read, and the `copies`
 * read, the other items its cluster holds. The trace lays out the
 * rows the pipeline's events already wrote about the item, in the order the
 * pipeline writes them: its record, each link with its method and state and
 * the judgment that set it, its extraction or the stand-in written in its
 * place, its kind check and its cluster, each step with its actor and the
 * second it was written. Both read under the caller's `ri`, so an item of a
 * tier the caller does not hold, or one a curator excluded, is refused as
 * missing and never listed as a copy, and a linked entity
 * `Graph_Query::visible_of()` hides is left out.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Graph from Graph_Query
 */
final class Graph_Trace {

	/** The question an extraction's kind check asked. */
	private const KIND_QUESTION = 'fits_kind';

	/** What an extraction step reads as, by the stage whose actor wrote it; any other stage extracted it. */
	private const STAND_INS = [
		Extractor_Node::GATED_STAGE     => 'gated',
		Extractor_Node::HEURISTIC_STAGE => 'heuristic',
	];

	/**
	 * The `trace` command: one readable item's decision record, `{ item_key,
	 * title, url, steps, available }`, each step `{ step, at, actor, … }`:
	 * `recorded`, then each `linked` by when it was written, then
	 * `extracted`, `gated` or `heuristic`, then `checked`, then `clustered`,
	 * a step its item never took left out.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `item`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `no item: <key>` for an item the caller's `ri` does not hold, or on a bad key.
	 */
	public static function trace( array $args ): array {
		$key   = Graph_Curation::bounded( 'item', Core::as_string( $args['item'] ), Graph_Curation::ITEM_KEY_BYTES );
		$empty = [ 'item_key' => $key, 'title' => null, 'url' => null, 'steps' => [] ];
		return Graph_Query::answer( $empty, static fn ( Row_Reader $r, array $g ): array => self::traced( $r, $g, $key ) );
	}

	/**
	 * One item's record, read on an open graph.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The caller's graph.
	 * @param string     $key The item.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `no item: <key>`.
	 */
	private static function traced( Row_Reader $r, array $g, string $key ): array {
		$items = Intel_Graph_Row_Schema::all( $r, 'items' );
		$item  = $r->rows( "{$g['with']} SELECT ri.title, ri.url, ri.source, ri.tier, ri.retrieved_ts, a.actor FROM ri JOIN {$items} a ON a.item_key = ri.item_key WHERE ri.item_key = ? LIMIT 1", [ ...$g['binds'], $key ] )[0]
			?? throw new \InvalidArgumentException( \esc_html( "no item: {$key}" ) );
		return [
			'item_key' => $key,
			'title'    => Core::as_string( $item[0] ),
			'url'      => Core::as_string( $item[1] ),
			'steps'    => [
				[ 'step' => 'recorded', 'at' => Core::as_int( $item[4] ), 'actor' => Core::as_string( $item[5] ), 'source' => Core::as_string( $item[2] ), 'tier' => Core::as_string( $item[3] ) ],
				...self::links( $r, $g, $key ),
				...self::extraction( $r, $key ),
				...self::cluster( $r, $g, $key ),
			],
		];
	}

	/**
	 * The item's place in a cluster, the newest partition's: who placed it
	 * and when, whether it is the cluster's canonical, and how many of the
	 * cluster's other items the caller may read. The cluster's key, another
	 * item's, is left out, since the caller may not read that item.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The caller's graph.
	 * @param string     $key The item.
	 * @return list<array<string,mixed>>
	 */
	private static function cluster( Row_Reader $r, array $g, string $key ): array {
		$row = $r->rows(
			"{$g['with']}, " . self::mine( $r ) . ' SELECT mine.actor, mine.ts, ( SELECT canonical_item_key FROM ( '
			. Intel_Graph_Row_Schema::newest( $r, 'canonicals', 'cluster_key', 'cluster_key, canonical_item_key', 'cluster_key = ( SELECT cluster_key FROM mine )' ) . ' ) ), ( SELECT COUNT(*) FROM ( '
			. self::others( $r ) . ' ) ) FROM mine',
			[ ...$g['binds'], $key, $key ]
		)[0] ?? null;
		if ( null === $row ) {
			return [];
		}
		return [ [ 'step' => 'clustered', 'at' => (int) Core::as_float( $row[1] ), 'actor' => Core::as_string( $row[0] ), 'canonical' => $key === $row[2], 'copies' => Core::as_int( $row[3] ) ] ];
	}

	/**
	 * The item's extraction, the newest partition's, as the step its
	 * actor's stage reads as, with its theme as its survivor, null for none,
	 * and its kind check when it carries one.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param string     $key The item.
	 * @return list<array<string,mixed>>
	 */
	private static function extraction( Row_Reader $r, string $key ): array {
		[ $merged, $themes ] = Graph_Query::theme_survivors( $r );
		$row                 = $r->rows(
			"WITH {$merged} SELECT x.kind, x.theme, x.rank_reason, x.actor, x.ts, x.checked_kind, x.kind_verdict, x.checked_ts FROM ( SELECT e.*, ( SELECT COALESCE( tm.survivor, t.theme ) FROM ( "
			. Intel_Graph_Row_Schema::newest( $r, 'themes', 'item_key', 'item_key, theme', 'item_key = ?' ) . ' ) t LEFT JOIN tm ON tm.theme = t.theme ) AS theme FROM '
			. Intel_Graph_Row_Schema::all( $r, 'extractions', true ) . ' e WHERE e.item_key = ? ORDER BY e.partition_ordinal DESC LIMIT 1 ) x',
			[ $themes, $key, $key ]
		)[0] ?? null;
		if ( null === $row ) {
			return [];
		}
		[ $kind, $theme, $reason, $actor, $ts, $checked, $verdict, $checked_ts ] = $row;
		$actor = Core::as_string( $actor );
		$steps = [
			[
				'step'        => self::step_of( $actor ),
				'at'          => (int) Core::as_float( $ts ),
				'actor'       => $actor,
				'kind'        => Core::as_string( $kind ),
				'theme'       => null === $theme ? null : Core::as_string( $theme ),
				'rank_reason' => Core::as_string( $reason ),
			],
		];
		if ( null !== $verdict ) {
			$steps[] = [ 'step' => 'checked', 'at' => Core::as_int( $checked_ts ), 'actor' => $actor, 'question' => self::KIND_QUESTION, 'kind' => Core::as_string( $checked ), 'verdict' => Core::as_string( $verdict ) ];
		}
		return $steps;
	}

	/**
	 * What an extraction written by an actor reads as: the stand-in its
	 * stage names, else extracted.
	 *
	 * @param string $actor The extraction's actor.
	 */
	private static function step_of( string $actor ): string {
		foreach ( self::STAND_INS as $stage => $step ) {
			if ( \str_starts_with( $actor, Intel_Event::machine_prefix( $stage ) ) ) {
				return $step;
			}
		}
		return 'extracted';
	}

	/**
	 * The item's links the caller may see, each as its entity's survivor, by
	 * when it was written, its state the highest-ranked judgment's of its
	 * entity, a `publisher` link aside, which judgments do not govern, else the
	 * machine's; a key whose merge chain does not resolve is left out.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The caller's graph.
	 * @param string     $key The item.
	 * @return list<array<string,mixed>>
	 */
	private static function links( Row_Reader $r, array $g, string $key ): array {
		$survivor = static fn ( mixed $entity ): string => $g['survivors'][ Core::as_string( $entity ) ] ?? Core::as_string( $entity );
		$judged   = [];
		foreach ( $r->rows( 'SELECT entity_key, state, actor, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'judgments' ) . ' j WHERE item_key = ? ORDER BY ' . Graph_Query::judge_rank( 'actor_kind' ) . ' DESC, ts DESC', [ $key ] ) as [ $entity, $state, $actor, $ts ] ) {
			$judged[ $survivor( $entity ) ] ??= [ 'state' => Core::as_string( $state ), 'actor' => Core::as_string( $actor ), 'at' => (int) Core::as_float( $ts ) ];
		}
		$rows      = $r->rows( 'SELECT entity_key, role, method, confidence, state, actor, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'links' ) . ' l WHERE item_key = ? ORDER BY ts, entity_key, role', [ $key ] );
		$survivors = \array_values( \array_unique( \array_filter( \array_map( static fn ( array $row ): string => $survivor( $row[0] ), $rows ), static fn ( string $shown ): bool => '' !== $shown ) ) );
		$seen      = Graph_Query::visible_of( $r, $g, $survivors );
		$names     = Graph_Query::names_of( $r, $survivors );
		$links     = [];
		foreach ( $rows as [ $entity, $role, $method, $confidence, $state, $actor, $ts ] ) {
			$shown = $survivor( $entity );
			if ( ! isset( $seen[ $shown ] ) ) {
				continue;
			}
			$judgment = Intel_Event::PUBLISHER === $role ? null : $judged[ $shown ] ?? null;
			$links[]  = [
				'step'       => 'linked',
				'at'         => (int) Core::as_float( $ts ),
				'actor'      => Core::as_string( $actor ),
				'entity_key' => $shown,
				'name'       => $names[ $shown ] ?? $shown,
				'role'       => Core::as_string( $role ),
				'method'     => Core::as_string( $method ),
				'confidence' => Core::as_float( $confidence ),
				'state'      => $judgment['state'] ?? Core::as_string( $state ),
				'judged'     => $judgment,
			];
		}
		return $links;
	}

	/**
	 * The `copies` command: the other items of one readable item's cluster
	 * the caller reads, `{ item_key, copies, available }`, each copy `{
	 * item_key, url, title, source, day }`, `day` its UTC `Y-m-d`, the newest
	 * first, then by key; none for an item outside every cluster.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `item`.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `no item: <key>` for an item the caller's `ri` does not hold, or on a bad key.
	 */
	public static function copies( array $args ): array {
		$key = Graph_Curation::bounded( 'item', Core::as_string( $args['item'] ), Graph_Curation::ITEM_KEY_BYTES );
		return Graph_Query::answer( [ 'item_key' => $key, 'copies' => [] ], static fn ( Row_Reader $r, array $g ): array => self::copied( $r, $g, $key ) );
	}

	/**
	 * One item's copies, read on an open graph.
	 *
	 * @param Row_Reader $r   The reader.
	 * @param Graph      $g   The caller's graph.
	 * @param string     $key The item.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `no item: <key>`.
	 */
	private static function copied( Row_Reader $r, array $g, string $key ): array {
		if ( [] === $r->rows( "{$g['with']} SELECT 1 FROM ri WHERE item_key = ? LIMIT 1", [ ...$g['binds'], $key ] ) ) {
			throw new \InvalidArgumentException( \esc_html( "no item: {$key}" ) );
		}
		$time = Intel_Graph_Row_Schema::ITEM_TIME;
		$rows = $r->rows(
			"{$g['with']}, " . self::mine( $r ) . " SELECT i.item_key, MAX( i.url ), MAX( i.title ), MAX( i.source ), date( MIN( {$time} ), 'unixepoch' ) FROM ri i WHERE i.item_key IN ( "
			. self::others( $r ) . " ) GROUP BY i.item_key ORDER BY MIN( {$time} ) DESC, i.item_key",
			[ ...$g['binds'], $key, $key ]
		);
		return [
			'item_key' => $key,
			'copies'   => \array_map(
				static fn ( array $row ): array => [
					'item_key' => Core::as_string( $row[0] ),
					'url'      => Core::as_string( $row[1] ),
					'title'    => Core::as_string( $row[2] ),
					'source'   => Core::as_string( $row[3] ),
					'day'      => Core::as_string( $row[4] ),
				],
				$rows
			),
		];
	}

	/**
	 * The keys of the cluster's other items `ri` holds, each placed by the
	 * newest partition's `clusters` row, over `mine`, the item's key bound
	 * again.
	 *
	 * @param Row_Reader $r The reader.
	 */
	private static function others( Row_Reader $r ): string {
		return 'SELECT cp.item_key FROM ( ' . Intel_Graph_Row_Schema::newest( $r, 'clusters', 'item_key', 'item_key, cluster_key', 'item_key IN ( SELECT item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'clusters' ) . ' m WHERE cluster_key = ( SELECT cluster_key FROM mine ) )' )
			. ' ) cp WHERE cp.cluster_key = ( SELECT cluster_key FROM mine ) AND cp.item_key <> ? AND cp.item_key IN ( SELECT item_key FROM ri )';
	}

	/**
	 * The `mine` clause: the item's placement, the newest partition's, its
	 * key bound first.
	 *
	 * @param Row_Reader $r The reader.
	 */
	private static function mine( Row_Reader $r ): string {
		return 'mine AS ( SELECT cluster_key, actor, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'clusters', true ) . ' c WHERE item_key = ? ORDER BY partition_ordinal DESC LIMIT 1 )';
	}
}

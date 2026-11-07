<?php
/**
 * Intelligence_Briefing: the `briefing` read verb's two slices, each what
 * changed in the last DAYS UTC days, through the reads that already answer
 * it. `changes` ranks the window's readable items naming any entity by
 * importance and groups them under the clients each is about, else under
 * Newspack's own work, else under no one; `signals` answers every client's
 * signals on both axes and every lens's, each whose bucket closed inside
 * the window.
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
final class Intelligence_Briefing {

	/** The slices `what` names, each answered by the method of its name. */
	public const SLICES = [ 'changes', 'signals' ];

	/** The UTC days the briefing reads, today the last. */
	public const DAYS = 7;

	/**
	 * The slice `what` names.
	 *
	 * @param array<array-key,mixed> $args The bound verb args.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When `what` does not name a slice.
	 */
	public static function answer( array $args ): array {
		$what = Graph_Query::one_of( 'what', Core::as_string( $args['what'] ), self::SLICES );
		return self::$what();
	}

	/**
	 * The window's `Graph_Query::DEFAULT_LIMIT` most important items naming
	 * any entity as subject or mention, in three kinds of group: under the
	 * first open client an item is about, role `client`, so each item is
	 * listed once; one about no client, or about more than
	 * `Graph_Query::MAX_ITEM_CLIENTS`, but
	 * about an entity holding an open `newspack_product` role under one
	 * group of that role; and any other under one group of role null. Each
	 * group sits in the order of its most important item, and its items as
	 * they rank.
	 *
	 * @return array<string,mixed> `{ groups: [ { entity_key, name, role, items } ], total, available }`.
	 */
	private static function changes(): array {
		return Graph_Query::answer(
			[
				'groups' => [],
				'total'  => 0,
			],
			static function ( Row_Reader $r, array $g ): array {
				$read     = Graph_Query::most_important( $r, $g, self::DAYS, Graph_Query::DEFAULT_LIMIT );
				$holders  = self::holders( $r, $g );
				$clients  = $holders[ Graph_Query::CLIENT ];
				$products = $holders[ Graph_Query::NEWSPACK_PRODUCT ];
				$groups   = [];
				foreach ( Core::arr( $read['items'] ) as $item ) {
					$about = \array_map( Core::as_string( ... ), \array_column( Core::arr( Core::arr( $item )['entities'] ), 'entity_key' ) );
					$named = \array_flip( $about );
					$under = \array_map( 'strval', \array_keys( \array_intersect_key( $named, $clients ) ) );
					$key   = [] === $under || \count( $under ) > Graph_Query::MAX_ITEM_CLIENTS
						? ( [] === \array_intersect_key( $named, $products ) ? '' : Graph_Query::NEWSPACK_PRODUCT )
						: $under[0];
					$groups[ $key ]          ??= isset( $clients[ $key ] )
						? [ 'entity_key' => $key, 'name' => $clients[ $key ], 'role' => Graph_Query::CLIENT, 'items' => [] ]
						: [ 'entity_key' => null, 'name' => null, 'role' => '' === $key ? null : $key, 'items' => [] ];
					$groups[ $key ]['items'][] = $item;
				}
				return [
					'groups' => \array_values( $groups ),
					'total'  => $read['total'],
				];
			}
		);
	}

	/**
	 * Each client and each lens with a signal whose bucket closed inside the
	 * window, by name, its signals as the signals read answers them: every
	 * client's in one `Graph_Query::signals_of()`, so the roster's size adds
	 * no statement, and each lens's through `spikes()`.
	 *
	 * @return array<string,mixed> `{ clients: [ { entity_key, name, signals } ], lenses: [ { lens_key, name, signals } ], available }`.
	 */
	private static function signals(): array {
		return Graph_Query::answer(
			[
				'clients' => [],
				'lenses'  => [],
			],
			static function ( Row_Reader $r, array $g ): array {
				// A bucket closing as the window opens counts.
				$after   = (float) ( Graph_Query::last_days( self::DAYS )[0] - 1 );
				$clients = self::holders( $r, $g )[ Graph_Query::CLIENT ];
				$signals = Graph_Query::signals_of( $r, $g, \array_map( 'strval', \array_keys( $clients ) ), $after );
				$lenses  = static fn ( string $key ): array => Graph_Query::spikes( $r, $g, [ 'lens' => $key ], $after );
				return [
					'clients' => self::signaling( $clients, 'entity_key', static fn ( string $key ): array => $signals[ $key ] ),
					'lenses'  => self::signaling( self::lenses( $r ), 'lens_key', $lenses ),
				];
			}
		);
	}

	/**
	 * Every lens, by name.
	 *
	 * @param Row_Reader $r The reader.
	 * @return array<string,string> Each lens key => its name.
	 */
	private static function lenses( Row_Reader $r ): array {
		return self::named( $r->rows( 'SELECT lens_key, name FROM ' . Intel_Graph_Row_Schema::all( $r, 'lenses' ) . ' l ORDER BY name, lens_key', [] ) );
	}

	/**
	 * Rows of a key and a name, as each key => its name.
	 *
	 * @param list<list<mixed>> $rows The rows.
	 * @return array<string,string>
	 */
	private static function named( array $rows ): array {
		$named = [];
		foreach ( $rows as [ $key, $name ] ) {
			$named[ Core::as_string( $key ) ] = Core::as_string( $name );
		}
		return $named;
	}

	/**
	 * Each subject whose read answers a signal, with its signals.
	 *
	 * @param array<string,string>                        $subjects Each key => its name.
	 * @param string                                      $field    The field a row names its key under.
	 * @param \Closure(string): list<array<string,mixed>> $read     A subject's signals.
	 * @return list<array<string,mixed>>
	 */
	private static function signaling( array $subjects, string $field, \Closure $read ): array {
		$rows = [];
		foreach ( $subjects as $key => $name ) {
			$signals = $read( $key );
			if ( [] !== $signals ) {
				$rows[] = [
					$field    => $key,
					'name'    => $name,
					'signals' => $signals,
				];
			}
		}
		return $rows;
	}

	/**
	 * Every entity holding an open client or `newspack_product` role, as its
	 * survivor, in one statement: each client by name, ordered by it, and
	 * each product with no name read.
	 *
	 * @param Row_Reader $r The reader.
	 * @param Graph      $g The graph the read opened.
	 * @return array<string,array<string,string>> Each role => each survivor => its name, '' for a product.
	 */
	private static function holders( Row_Reader $r, array $g ): array {
		$held = [
			Graph_Query::CLIENT           => [],
			Graph_Query::NEWSPACK_PRODUCT => [],
		];
		$rows = $r->rows(
			"{$g['with']} SELECT c.entity_key, c.role, CASE WHEN c.role = ? THEN " . Graph_Query::name_of( $r, 'c.entity_key' ) . ' END FROM ( SELECT DISTINCT COALESCE( mg.value, t.entity_key ) AS entity_key, t.role FROM '
			. Intel_Graph_Row_Schema::all( $r, 'roles' ) . " t LEFT JOIN mg ON mg.key = t.entity_key WHERE t.role IN ( ?, ? ) AND t.to_ts = 0 ) c WHERE c.entity_key <> '' ORDER BY 3, 1",
			[ ...$g['binds'], Graph_Query::CLIENT, Graph_Query::CLIENT, Graph_Query::NEWSPACK_PRODUCT ]
		);
		foreach ( $rows as [ $key, $role, $name ] ) {
			$held[ Core::as_string( $role ) ][ Core::as_string( $key ) ] = Core::as_string( $name );
		}
		return $held;
	}

}

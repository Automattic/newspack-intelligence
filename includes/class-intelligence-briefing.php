<?php
/**
 * Intelligence_Briefing: the `briefing` read command's two slices, each what
 * changed in the last DAYS UTC days, through the reads that already answer
 * it. `changes` ranks the window's readable items naming any entity by
 * importance, files each under the client it is about, else under the first
 * category lens the caller names that admits it, else under `otherwise`, and
 * sums up what of them arrived since an instant the caller names; `signals`
 * answers every client's signals on both axes and every lens's, each whose
 * bucket closed inside the window, and whether any subject was judged.
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

	/** The most category lenses `changes` files the week under. */
	public const CATEGORY_MAX = 8;

	/** The order `changes` ranks its items in. */
	public const ORDER = 'importance';

	/** The item kinds the summary counts, in the order it answers them. */
	public const SUMMARY_KINDS = [ Graph_Query::CHURNED, 'churn_signal', 'incident', 'bug', 'launch', 'funding', 'award', 'feature_request', 'complaint' ];

	/**
	 * The slice `what` names.
	 *
	 * @param array<array-key,mixed> $args The bound command args.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException When `what` does not name a slice, `since` or `lenses` is given to `signals`, or `lenses` names more than CATEGORY_MAX.
	 */
	public static function answer( array $args ): array {
		$lenses = \array_values( \array_filter( \array_map( 'trim', \explode( ',', Core::as_string( $args['lenses'] ) ) ), static fn ( string $key ): bool => '' !== $key ) );
		return match ( Graph_Query::one_of( 'what', Core::as_string( $args['what'] ), self::SLICES ) ) {
			'changes' => self::changes( Core::as_int( $args['since'] ), self::categories( $lenses ) ),
			'signals' => match ( true ) {
				0 !== Core::as_int( $args['since'] ) => throw new \InvalidArgumentException( 'since applies to changes' ),
				[] !== $lenses => throw new \InvalidArgumentException( 'lenses apply to changes' ),
				default => self::signals(),
			},
		};
	}

	/**
	 * Each client the caller may see and each lens with a signal whose
	 * bucket closed inside the window, by name, its signals as the signals read answers them: every
	 * client's in one `Graph_Query::signals_of()`, so the roster's size adds
	 * no statement, and each lens's through `spikes()`. A client carrying a
	 * churn note needs attention whatever it signals, so it is listed too,
	 * and every client row answers its note, every client's read in one
	 * `Graph_Query::churn_notes()`.
	 *
	 * `judged` is whether any client or lens held the history its rules
	 * judge by, so an empty answer tells quiet from too new.
	 *
	 * @return array<string,mixed> `{ clients: [ { entity_key, name, signals, churn_note } ], lenses: [ { lens_key, name, signals } ], judged, available }`.
	 */
	private static function signals(): array {
		return Graph_Query::answer(
			[
				'clients' => [],
				'lenses'  => [],
				'judged'  => false,
			],
			static function ( Row_Reader $r, array $g ): array {
				// A bucket closing as the window opens counts.
				$after   = (float) ( Graph_Query::last_days( self::DAYS )[0] - 1 );
				$clients              = self::clients( $r, $g );
				$keys                 = \array_map( 'strval', \array_keys( $clients ) );
				[ $signals, $judged ] = Graph_Query::signals_of( $r, $g, $keys, $after );
				$notes                = Graph_Query::churn_notes( $r, \array_combine( $keys, \array_map( static fn ( string $key ): array => Graph_Query::keys_of( $key, $g ), $keys ) ) );
				$lenses               = static function ( string $key ) use ( $r, $g, $after, &$judged ): array {
					[ $spikes, $lens_judged ] = Graph_Query::judged_spikes( $r, $g, [ 'lens' => $key ], $after );
					$judged                   = $judged || $lens_judged;
					return $spikes;
				};
				return [
					'clients' => self::signaling( $clients, 'entity_key', static fn ( string $key ): array => $signals[ $key ], $notes ),
					'lenses'  => self::signaling( self::lenses( $r ), 'lens_key', $lenses ),
					'judged'  => $judged,
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
	 * Each subject whose read answers a signal, with its signals; given the
	 * subjects' churn notes, also each subject carrying one, every row then
	 * answering its `churn_note`, null for none.
	 *
	 * @param array<string,string>                        $subjects Each key => its name.
	 * @param string                                      $field    The field a row names its key under.
	 * @param \Closure(string): list<array<string,mixed>> $read     A subject's signals.
	 * @param array<string,array<string,string>>|null     $notes    Each noted subject => its churn note; null for subjects that carry none.
	 * @return list<array<string,mixed>>
	 */
	private static function signaling( array $subjects, string $field, \Closure $read, ?array $notes = null ): array {
		$rows = [];
		foreach ( $subjects as $key => $name ) {
			$signals = $read( $key );
			$note    = $notes[ $key ] ?? null;
			if ( [] !== $signals || null !== $note ) {
				$rows[] = [
					$field    => $key,
					'name'    => $name,
					'signals' => $signals,
				] + ( null === $notes ? [] : [ 'churn_note' => $note ] );
			}
		}
		return $rows;
	}

	/**
	 * The category lenses named, each once, at most CATEGORY_MAX.
	 *
	 * @param list<string> $lenses The keys given.
	 * @return list<string>
	 * @throws \InvalidArgumentException When they name more than CATEGORY_MAX.
	 */
	private static function categories( array $lenses ): array {
		$lenses = \array_values( \array_unique( $lenses ) );
		if ( \count( $lenses ) > self::CATEGORY_MAX ) {
			throw new \InvalidArgumentException( 'lenses names at most ' . self::CATEGORY_MAX . ' lenses' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- a constant.
		}
		return $lenses;
	}

	/**
	 * The window's `Graph_Query::DEFAULT_LIMIT` most important items naming
	 * any entity as subject or mention, in ORDER, each answering
	 * `categories`, the named lenses admitting it in the order named
	 * (`Graph_Query::admitted()`, one statement a lens), and `otherwise`,
	 * whether none does. Each is filed once, by key: under the first open
	 * client it is about, a churn kind's the one its kind claims
	 * (`Graph_Query::claimed()`), unless it is about more than
	 * `Graph_Query::MAX_ITEM_CLIENTS`; else under the first category
	 * admitting it; else under `otherwise`. The clients' groups come first,
	 * each in the order of its most important item, then each category's in
	 * the order named, then `otherwise`; a group holding nothing is left
	 * out. Each category answers how many of the window's items it admits,
	 * and `otherwise` how many no category does, as `total` counts every
	 * one. Its summary counts what of the window is
	 * dated at or after `since`, the window's first instant at the latest:
	 * the items, the open clients they are about, and each of SUMMARY_KINDS
	 * with any item, in that order, with its items and the open clients they
	 * name as their subject, so a churn report counts only the client that
	 * is leaving.
	 *
	 * @param int          $asked  The instant `since` names, UTC seconds.
	 * @param list<string> $lenses The category lenses, each refused as `no lens: <key>` when never saved.
	 * @return array<string,mixed> `{ order, items, groups: [ { role, key, name, items } ], categories: [ { lens_key, name, count } ], otherwise, total, summary: { since, items, clients, kinds: [ { kind, items, clients } ] }, available }`.
	 */
	private static function changes( int $asked, array $lenses ): array {
		$since = \max( $asked, Graph_Query::last_days( self::DAYS )[0] );
		return Graph_Query::answer(
			[
				'order'      => self::ORDER,
				'items'      => [],
				'groups'     => [],
				'categories' => [],
				'otherwise'  => 0,
				'total'      => 0,
				'summary'    => [
					'since'   => $since,
					'items'   => 0,
					'clients' => 0,
					'kinds'   => [],
				],
			],
			static function ( Row_Reader $r, array $g ) use ( $since, $lenses ): array {
				$read     = Graph_Query::most_important( $r, $g, self::DAYS, Graph_Query::DEFAULT_LIMIT, $since );
				$items    = \array_map( Core::arr( ... ), Core::arr( $read['items'] ) );
				$keys     = \array_values( \array_map( static fn ( array $item ): string => Core::as_string( $item['item_key'] ), $items ) );
				$admitted = [];
				foreach ( $lenses as $lens ) {
					$admitted[ $lens ] = \array_flip( Graph_Query::admitted( $r, $g, $lens, self::DAYS ) );
				}
				$claimed  = Graph_Query::claimed( $r, $g, $keys );
				$clients  = self::clients( $r, $g );
				$names    = self::names_of( $r, $lenses );
				$filed    = [];
				$listed   = [];
				foreach ( $items as $item ) {
					$key        = Core::as_string( $item['item_key'] );
					$categories = \array_values( \array_filter( $lenses, static fn ( string $lens ): bool => isset( $admitted[ $lens ][ $key ] ) ) );
					$about      = \in_array( $item['kind'], Intel_Event::CHURN_KINDS, true ) ? $claimed[ $key ] ?? [] : \array_map( Core::as_string( ... ), \array_column( Core::arr( $item['entities'] ), 'entity_key' ) );
					$under      = \array_values( \array_intersect( $about, \array_keys( $clients ) ) );
					$group      = match ( true ) {
						[] !== $under && \count( $under ) <= Graph_Query::MAX_ITEM_CLIENTS => [ Graph_Query::CLIENT, $under[0], $clients[ $under[0] ] ],
						[] !== $categories => [ 'category', $categories[0], $names[ $categories[0] ] ],
						default => [ 'otherwise', null, null ],
					};
					$filed[ $group[0] ][ (string) $group[1] ] ??= [ 'role' => $group[0], 'key' => $group[1], 'name' => $group[2], 'items' => [] ];
					$filed[ $group[0] ][ (string) $group[1] ]['items'][] = $key;
					$listed[] = $item + [ 'categories' => $categories, 'otherwise' => [] === $categories ];
				}
				$counted = [];
				foreach ( $lenses as $lens ) {
					$counted[] = [ 'lens_key' => $lens, 'name' => $names[ $lens ], 'count' => \count( $admitted[ $lens ] ) ];
				}
				return [
					'order'      => self::ORDER,
					'items'      => $listed,
					'groups'     => [ ...\array_values( $filed[ Graph_Query::CLIENT ] ?? [] ), ...\array_values( \array_filter( \array_map( static fn ( string $lens ): ?array => $filed['category'][ $lens ] ?? null, $lenses ) ) ), ...\array_values( $filed['otherwise'] ?? [] ) ],
					'categories' => $counted,
					'otherwise'  => Core::as_int( $read['total'] ) - \count( \array_replace( [], ...\array_values( $admitted ) ) ),
					'total'      => $read['total'],
					'summary'    => self::summary( $since, Core::arr( $read['summary'] ) ),
				];
			}
		);
	}

	/**
	 * A summary as the briefing answers it: from when it counts, then each
	 * of SUMMARY_KINDS the read counted any item of, in that order.
	 *
	 * @param int                    $since   The first instant counted.
	 * @param array<array-key,mixed> $counted The read's summary, each kind by name.
	 * @return array<string,mixed>
	 */
	private static function summary( int $since, array $counted ): array {
		$kinds = [];
		foreach ( self::SUMMARY_KINDS as $kind ) {
			$count = Core::arr( Core::arr( $counted['kinds'] )[ $kind ] ?? [ 'items' => 0 ] );
			if ( 0 < Core::as_int( $count['items'] ) ) {
				$kinds[] = [ 'kind' => $kind ] + $count;
			}
		}
		return [
			'since'   => $since,
			'items'   => $counted['items'],
			'clients' => $counted['clients'],
			'kinds'   => $kinds,
		];
	}

	/**
	 * Each named lens's name, in the order named.
	 *
	 * @param Row_Reader   $r    The reader.
	 * @param list<string> $keys The lens keys, each saved.
	 * @return array<string,string> Each lens key => its name.
	 */
	private static function names_of( Row_Reader $r, array $keys ): array {
		$named = [] === $keys ? [] : self::named( $r->rows( 'SELECT lens_key, name FROM ' . Intel_Graph_Row_Schema::all( $r, 'lenses' ) . ' l WHERE lens_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' )', $keys ) );
		return \array_merge( \array_fill_keys( $keys, '' ), $named );
	}

	/**
	 * Every entity holding an open client role, as its survivor, by name, in
	 * one statement, that the caller may see (`Graph_Query::visible_of()`).
	 *
	 * @param Row_Reader $r The reader.
	 * @param Graph      $g The graph the read opened.
	 * @return array<string,string> Each survivor => its name.
	 */
	private static function clients( Row_Reader $r, array $g ): array {
		$open = self::named(
			$r->rows(
				"{$g['with']} SELECT c.entity_key, " . Graph_Query::name_of( $r, 'c.entity_key' ) . ' FROM ( SELECT DISTINCT COALESCE( mg.value, t.entity_key ) AS entity_key FROM '
				. Intel_Graph_Row_Schema::all( $r, 'roles' ) . " t LEFT JOIN mg ON mg.key = t.entity_key WHERE t.role = ? AND t.to_ts = 0 ) c WHERE c.entity_key <> '' ORDER BY 2, 1",
				[ ...$g['binds'], Graph_Query::CLIENT ]
			)
		);
		return \array_intersect_key( $open, Graph_Query::visible_of( $r, $g, \array_map( 'strval', \array_keys( $open ) ) ) );
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
}

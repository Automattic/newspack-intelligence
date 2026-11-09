<?php
/**
 * Graph_Asks: the Ask's two commands, each at `read`, each answering the
 * caller's own asks alone. `ask` appends a question to the asks stream for
 * `Ask_Node` to answer as a worker job, and `ask_result` reads the caller's
 * ask back from the projection, every cited item re-read under the caller's
 * tiers now. An ask another user made answers exactly as one never made,
 * `no ask: <key>`, so the commands never tell the two apart.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Graph from Graph_Query
 * @phpstan-import-type Sentence from Ask_Prompts
 */
final class Graph_Asks {

	/** The longest question, in the bytes the stream writes it in. */
	public const QUESTION_BYTES = 1000;

	/** An ask still owed its answer. */
	public const ASKED = 'asked';

	/** An ask answered. */
	public const ANSWERED = 'answered';

	/** An ask that failed. */
	public const FAILED = 'failed';

	/** An answer written from items of a tier the caller no longer reads. */
	public const WITHHELD = 'withheld';

	/** Random bytes a minted ask key spells in hex. */
	private const KEY_BYTES = 8;

	/** The line logged when `ask_result` finds no projection. */
	private const UNAVAILABLE = 'ask: no intel-graph to read an ask from; answering none: ';

	/**
	 * The `ask_result` command: the caller's own ask, with the searches it ran
	 * and how long an ask may wait, `wait_s`. An answer written from an item
	 * of a tier the caller does not read now is withheld whole, since the
	 * model wrote every sentence from every item it was given; else its
	 * sentences are kept only while the caller may read every item each
	 * cites, as one expired or purged since may not be, those items as
	 * `search` answers them, numbered again in citation order. An ask still
	 * owed its answer past `Ask_Node::MAX_WAIT_S` reads as failed. A key the
	 * projection does not hold reads as asked while the projector has not
	 * read the asks stream to its end, else is refused as `no ask: <key>`,
	 * whoever else may have asked it.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `key`.
	 * @return array<string,mixed> `{ ask_key, state, reason, found, dropped, sentences, items, searches, wait_s, available }`.
	 * @throws \InvalidArgumentException When the key is not one of the caller's asks.
	 */
	public static function result( array $args ): array {
		$key    = Graph_Curation::bounded( 'key', Core::as_string( $args['key'] ), Graph_Curation::KEY_BYTES );
		$owner  = \get_current_user_id();
		$answer = Intel_Graph_Row_Schema::read( static fn ( Row_Reader $r ): array => self::result_of( $r, Graph_Query::graph( $r ), $key, $owner ), self::UNAVAILABLE );
		return null === $answer ? self::waiting( $key ) + [ 'available' => false ] : $answer + [ 'available' => true ];
	}

	/**
	 * One ask of an owner, read on an open graph.
	 *
	 * @param Row_Reader $r     The reader.
	 * @param Graph      $g     The caller's graph.
	 * @param string     $key   The ask.
	 * @param int        $owner The caller.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As `no ask: <key>`.
	 */
	private static function result_of( Row_Reader $r, array $g, string $key, int $owner ): array {
		$row = $r->rows( 'SELECT state, reason, found, dropped, sentences, items, tiers, searches, ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'asks' ) . ' a WHERE ask_key = ? AND owner = ? ORDER BY ts DESC LIMIT 1', [ $key, $owner ] )[0] ?? null;
		if ( null === $row ) {
			if ( Projector_Node::behind( Stream_Writer::stream_dir( Intel_Event::ASKS ) ) ) {
				return self::waiting( $key );
			}
			throw new \InvalidArgumentException( \esc_html( "no ask: {$key}" ) );
		}
		$searches = Core::arr( \json_decode( Core::as_string( $row[7] ), true ) );
		$state    = Core::as_string( $row[0] );
		if ( self::ASKED === $state && Core::$now - Core::as_float( $row[8] ) > Ask_Node::MAX_WAIT_S ) {
			return [ 'state' => self::FAILED, 'reason' => 'the ask waited past ' . Ask_Node::MAX_WAIT_S . ' seconds; ask again', 'searches' => $searches ] + self::waiting( $key );
		}
		$tiers = \array_map( Core::as_string( ... ), Core::arr( \json_decode( Core::as_string( $row[6] ), true ) ) );
		if ( [] !== \array_diff( $tiers, Intel_Tiers::readable() ) ) {
			return [ 'state' => self::WITHHELD, 'reason' => 'the answer was written from items you can no longer read', 'searches' => $searches ] + self::waiting( $key );
		}
		$keys      = \array_values( \array_map( Core::as_string( ... ), Core::arr( \json_decode( Core::as_string( $row[5] ), true ) ) ) );
		$readable  = [];
		$sentences = [];
		foreach ( Core::arr( \json_decode( Core::as_string( $row[4] ), true ) ) as $sentence ) {
			$sentence    = Core::arr( $sentence );
			$sentences[] = [ 'text' => Core::as_string( $sentence['text'] ?? null ), 'cites' => \array_values( \array_map( Core::as_int( ... ), Core::arr( $sentence['cites'] ?? null ) ) ) ];
		}
		if ( [] !== $keys ) {
			$join = [
				'join'   => 'JOIN json_each( ? ) c ON c.value = i.item_key',
				'binds'  => [ \wp_json_encode( $keys, \JSON_THROW_ON_ERROR ) ],
				'fields' => [],
				'order'  => 'i.item_key',
			];
			foreach ( Core::arr( Graph_Query::listed( $r, $g, $join, [ 'limit' => Ask_Node::MAX_ITEMS, 'offset' => 0 ] )['items'] ) as $item ) {
				$readable[ Core::as_string( Core::arr( $item )['item_key'] ?? null ) ] = $item;
			}
		}
		[ $kept, $items ] = self::cited( $sentences, $keys, static fn ( string $item ): bool => isset( $readable[ $item ] ) );
		return [
			'ask_key'   => $key,
			'state'     => $state,
			'reason'    => Core::as_string( $row[1] ),
			'found'     => Core::as_int( $row[2] ),
			'dropped'   => Core::as_int( $row[3] ),
			'sentences' => $kept,
			'items'     => \array_map( static fn ( string $item ): mixed => $readable[ $item ], $items ),
			'searches'  => $searches,
			'wait_s'    => Ask_Node::MAX_WAIT_S,
		];
	}

	/**
	 * The sentences every item of which `$keep` keeps, each cite an item's
	 * place in `$keys` from 1, numbered again from 1 in the order first
	 * cited, and the items they cite in that order.
	 *
	 * @api The Ask node numbers an answer's items by it as it records one.
	 * @param list<Sentence>          $sentences The sentences.
	 * @param list<string>            $keys      The items their cites number.
	 * @param \Closure(string): bool  $keep      Whether an item may be cited.
	 * @return array{0:list<Sentence>,1:list<string>}
	 */
	public static function cited( array $sentences, array $keys, \Closure $keep ): array {
		$kept  = [];
		$items = [];
		foreach ( $sentences as $sentence ) {
			$cited = [];
			foreach ( $sentence['cites'] as $n ) {
				$item = $keys[ $n - 1 ] ?? null;
				if ( null === $item || ! $keep( $item ) ) {
					continue 2;
				}
				$cited[] = $item;
			}
			$cites = [];
			foreach ( $cited as $item ) {
				$at = \array_search( $item, $items, true );
				if ( false === $at ) {
					$items[] = $item;
					$at      = \count( $items ) - 1;
				}
				$cites[] = $at + 1;
			}
			\sort( $cites );
			$kept[] = [ 'text' => $sentence['text'], 'cites' => \array_values( \array_unique( $cites ) ) ];
		}
		return [ $kept, $items ];
	}

	/**
	 * An ask still owed its answer, with nothing in it.
	 *
	 * @param string $key The ask.
	 * @return array<string,mixed>
	 */
	private static function waiting( string $key ): array {
		return [ 'ask_key' => $key, 'state' => self::ASKED, 'reason' => '', 'found' => 0, 'dropped' => 0, 'sentences' => [], 'items' => [], 'searches' => [], 'wait_s' => Ask_Node::MAX_WAIT_S ];
	}

	/**
	 * The `ask` command: append a question under a key it mints, its owner the
	 * caller, and answer the key.
	 *
	 * @param array<array-key,mixed> $args The bound command args: `question`.
	 * @return array{ask_key:string}
	 * @throws \InvalidArgumentException On a blank question, or one past QUESTION_BYTES.
	 */
	public static function ask( array $args ): array {
		$question = Graph_Curation::bounded( 'question', Core::as_string( $args['question'] ), self::QUESTION_BYTES );
		$key      = \bin2hex( \random_bytes( self::KEY_BYTES ) );
		Graph_Curation::write( 'ask_asked', [ 'ask_key' => $key, 'owner' => \get_current_user_id(), 'question' => $question ] );
		return [ 'ask_key' => $key ];
	}
}

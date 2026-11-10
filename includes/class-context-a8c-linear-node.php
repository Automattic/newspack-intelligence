<?php
/**
 * Context_A8C_Linear_Node: reads the newest issues of the Linear teams the
 * `add_team` commands name through ContextA8C's `linear` / `team-issues`, and
 * records each as Linear_Source_Node records the same issue.
 *
 * Extends Context_A8C_Source_Node, which owns the token, the JSON-RPC
 * envelope and the answer. A pass asks each team's newest page, LIMIT
 * issues, and pages no further, as the Linear source reads one page a pass.
 * The answer groups its issues by status; every group's issues record. Its
 * `description` is cut at CUT_CHARS, so an issue that moved, cut short, is
 * asked of `issue`, which answers it whole, and recorded from that, as Slack
 * reads only the threads that moved; any other records as the page cut it.
 * The item a page cut rides as the `issue` request's note, so a call that
 * does not answer records it as cut. Once a pass's team pages are in, it
 * walks the stored items of the last BACKFILL_DAYS still cut short, drawn at
 * random with the room the request cap leaves, so a row that never grows
 * cannot pin the walk. What `issue` answers of a stored item is written as an
 * `item_grown`, the row's own fields with the longer body, only when it is
 * longer than what is stored and than what this process has written; a walk's
 * answer for a row since purged writes nothing.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

class Context_A8C_Linear_Node extends Context_A8C_Source_Node {

	public const SOURCE = 'linear';

	/** Issues a team's page asks for, as many as the Linear source's page holds. */
	private const LIMIT = 30;

	/** The characters `team-issues` cuts a description to, its ellipsis among them. */
	private const CUT_CHARS = 300;

	/** What ends a description `team-issues` cut. */
	private const CUT_TAIL = '...';

	/** The days back the walk reads stored items cut short. */
	private const BACKFILL_DAYS = 30;

	/** The requests a pass spends besides its calls: a refresh, `initialize` and its notification. */
	private const PASS_OVERHEAD = 3;

	/** @var list<string> Team keys the `add_team` commands registered and the `remove_team` commands left, in call order. */
	protected array $teams = [];

	/** @var array<string,int> Each item key's longest body this process has written, in bytes. */
	private array $written = [];

	/** Team pages this pass has yet to answer. */
	private int $pages = 0;

	/** `issue` calls this pass may still make. */
	private int $room = 0;

	/**
	 * What a call answered: a team page, or the whole of one issue.
	 *
	 * @param string                 $subtool The provider's tool, `team-issues` or `issue`.
	 * @param array<array-key,mixed> $args    The call's arguments.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	protected function answered( string $subtool, array $args, array $answer ): array {
		return 'issue' === $subtool ? $this->whole( Core::as_string( $args['id'] ?? null ), $answer ) : $this->page( $answer );
	}

	/**
	 * Every status group's issues as items, through the Linear source's
	 * mapping, recorded here, but an issue that moved, cut short, which rides
	 * as its `issue` request's note while the pass has room; an issue with no
	 * string identifier is skipped. The last page then opens the walk, whose
	 * failure, logged, costs nothing the page held, a stop propagating.
	 *
	 * @param array<array-key,mixed> $answer The provider's answer.
	 * @return array<int,array<string,mixed>> Nothing: the page's items are recorded.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function page( array $answer ): array {
		$issues = [];
		foreach ( Core::arr( $answer['groups'] ?? null ) as $group ) {
			\array_push( $issues, ...\array_values( Core::arr( $group ) ) );
		}
		$moved = Slack_Source_Node::moved_after( $this->interval );
		$items = self::items(
			$issues,
			function ( array $issue ) use ( $moved ): ?array {
				$identifier = $issue['identifier'] ?? null;
				if ( ! \is_string( $identifier ) || '' === $identifier ) {
					return null;
				}
				$item = $this->normalize_item( $identifier, $issue['title'] ?? '', $issue['url'] ?? '', $issue['description'] ?? '', $issue['updated_at'] ?? '' );
				if ( 0 < $this->room && self::cut_short( Core::as_string( $issue['description'] ?? null ) ) && \strtotime( Core::as_string( $issue['updated_at'] ?? null ) ) >= $moved ) {
					$this->ask( $identifier, [ 'item' => $item ] );
					return null;
				}
				return $item;
			}
		);
		$this->record( $items );
		try {
			if ( 0 === --$this->pages ) {
				$this->walk();
			}
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'the walk failed: ', Core::terminal_safe( $e->getMessage() ) );
		}
		return [];
	}

	/**
	 * Whether a description is as `team-issues` cuts one.
	 *
	 * @param string $text The description.
	 */
	private static function cut_short( string $text ): bool {
		return self::CUT_CHARS === \mb_strlen( $text ) && \str_ends_with( $text, self::CUT_TAIL );
	}

	/**
	 * Ask `issue` for stored items of the last BACKFILL_DAYS still cut short,
	 * as many as the pass's room allows, drawn at random: a worker's memory
	 * lasts less than a pass's interval, so an order would let rows that
	 * never grow, a deleted issue or a genuine 300 characters, take the room
	 * every pass. None, logged, while the projection will not open.
	 */
	private function walk(): void {
		$day   = \intdiv( (int) Core::$now, Intel_Graph_Row_Schema::DAY_SECONDS ) - self::BACKFILL_DAYS;
		$limit = $this->room;
		$keys  = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => \array_column(
				$r->rows(
					'SELECT DISTINCT i.item_key FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' ) . " i WHERE i.source = ? AND i.retrieved_day >= ? AND length( i.body ) = ? AND substr( i.body, -3 ) = ? ORDER BY random() LIMIT ?",
					[ self::SOURCE, $day, self::CUT_CHARS, self::CUT_TAIL, $limit ]
				),
				0
			),
			"{$this->name}: no intel-graph to read the issues cut short from; walking none: "
		);
		foreach ( $keys ?? [] as $key ) {
			$this->ask( \substr( Core::as_string( $key ), \strlen( self::SOURCE . ':' ) ), [] );
		}
	}

	/**
	 * Ask `issue` for one issue, spending the pass's room.
	 *
	 * @param string                 $identifier The issue.
	 * @param array<array-key,mixed> $note       The item a page held back, under `item`.
	 */
	private function ask( string $identifier, array $note ): void {
		--$this->room;
		$this->call( 'linear', 'issue', [ 'id' => $identifier ], $note );
	}

	/**
	 * The whole of one issue: a page's new item to record, or nothing, its
	 * stored row grown when this body is longer. A walk's answer for a row no
	 * longer stored writes nothing, a purge having taken it; an answer naming
	 * no description leaves a page's item as its page cut it.
	 *
	 * @param string                 $identifier The issue.
	 * @param array<array-key,mixed> $issue      The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	private function whole( string $identifier, array $issue ): array {
		if ( ! \is_string( $issue['description'] ?? null ) ) {
			return $this->as_cut( $this->note() );
		}
		$item   = $this->normalize_item( $identifier, $issue['title'] ?? '', $issue['url'] ?? '', $issue['description'], $issue['updated_at'] ?? '' );
		$key    = Core::as_string( $item['item_key'] );
		$stored = self::stored( $key, $this->name );
		if ( null === $stored ) {
			return isset( $this->note()['item'] ) ? [ $item ] : [];
		}
		$bytes = \strlen( Core::as_string( $item['body'] ) );
		if ( $bytes > \max( $stored['bytes'], $this->written[ $key ] ?? 0 ) && $this->write( 'item_grown', [ 'body' => $item['body'] ] + $stored['fields'], $key ) ) {
			$this->written[ $key ] = $bytes;
		}
		return [];
	}

	/**
	 * A stored item's `item_recorded` fields but its body, and that body's
	 * length in bytes; null for an item the projection does not hold, or
	 * while it will not open, logged, when a repeat of the record is harmless.
	 *
	 * @param string $key  The item key.
	 * @param string $name This node's name.
	 * @return array{fields:array<string,mixed>,bytes:int}|null
	 */
	private static function stored( string $key, string $name ): ?array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $key ): ?array {
				$row = $r->rows( 'SELECT i.source, i.tier, i.url, i.title, i.item_ts, i.retrieved_ts, length( CAST( i.body AS BLOB ) ) FROM ' . Intel_Graph_Row_Schema::all( $r, 'items', true ) . ' i WHERE i.item_key = ? ORDER BY i.partition_ordinal DESC LIMIT 1', [ $key ] )[0] ?? null;
				if ( null === $row ) {
					return null;
				}
				[ $source, $tier, $url, $title, $item_ts, $retrieved_ts, $bytes ] = $row;
				return [
					'fields' => [
						'item_key'     => $key,
						'source'       => $source,
						'tier'         => $tier,
						'url'          => $url,
						'title'        => $title,
						'item_ts'      => Core::as_int( $item_ts ),
						'retrieved_ts' => Core::as_int( $retrieved_ts ),
					],
					'bytes'  => Core::as_int( $bytes ),
				];
			},
			"{$name}: no intel-graph to read a stored issue from; recording it: "
		);
	}

	/** A call that failed in transport records its held item as cut. */
	protected function failed( string $subject, string $error ): void {
		parent::failed( $subject, $error );
		$this->record( $this->as_cut( $this->note() ) );
	}

	/**
	 * A call whose answer did not read records its held item as cut.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function unanswered(): array {
		return $this->as_cut( $this->note() );
	}

	/**
	 * A request that will never answer records its held item as cut.
	 *
	 * @param string                 $subject The request's subject.
	 * @param array<array-key,mixed> $note    What it was queued with.
	 */
	protected function abandoned( string $subject, array $note ): void {
		parent::abandoned( $subject, $note );
		$this->record( $this->as_cut( $note ) );
	}

	/**
	 * The item a request's note holds, logged, as its page cut it; none for a
	 * request the walk made.
	 *
	 * @param array<array-key,mixed> $note The request's note.
	 * @return array<int,array<string,mixed>>
	 */
	private function as_cut( array $note ): array {
		$item = Intel_Event::item_of( Core::arr( $note['item'] ?? null ) );
		if ( null === $item ) {
			return [];
		}
		$this->print_less_often( 'recording an issue as team-issues cut it: ', Core::terminal_safe( Core::as_string( $item['item_key'] ?? null ) ) );
		return [ $item ];
	}

	/** Ask each team's newest page, leaving the rest of the request cap to `issue`. */
	protected function calls(): void {
		$this->pages = \count( $this->teams );
		$this->room  = self::MAX_REQUESTS_PER_PASS - self::PASS_OVERHEAD - $this->pages;
		foreach ( $this->teams as $team ) {
			$this->call(
				'linear',
				'team-issues',
				[
					'team'  => $team,
					'limit' => self::LIMIT,
				]
			);
		}
	}

	public static function node_schema(): array {
		return self::context_schema(
			'Reads the newest issues of the configured Linear teams through ContextA8C, as the person signed in to it on the host, one call a team, without blocking, on a TICK request (request_node linear TICK). Local workstation only.',
			'Start a pass over the teams; each page records its new issues as it lands. Trigger with `request_node linear TICK`.',
			Linear_Source_Node::team_commands()
		);
	}
}

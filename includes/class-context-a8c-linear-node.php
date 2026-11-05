<?php
/**
 * Context_A8C_Linear_Node: reads the newest issues of the Linear teams the
 * `add_team` verb names through ContextA8C's `linear` / `team-issues`, and
 * records each as Linear_Source_Node records the same issue.
 *
 * Extends Context_A8C_Source_Node, which owns the token, the JSON-RPC
 * envelope and the answer. A pass asks each team's newest page, LIMIT
 * issues, and pages no further, as the Linear source reads one page a pass.
 * The answer groups its issues by status; every group's issues record. Its
 * `description` is cut at CUT_CHARS, so an issue that moved, cut short, is
 * asked of `issue`, which answers it whole, and recorded from that, as Slack
 * reads only the threads that moved; any other records as the page cut it.
 * Once a pass's team pages are in, it walks the stored items of the last
 * BACKFILL_DAYS still cut short, with the room the request cap leaves, a
 * process asking each once. What `issue` answers of a stored item is written
 * as an `item_grown`, the row's own fields with the longer body, only when it
 * is longer than what is stored and than what this process has written.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

class Context_A8C_Linear_Node extends Context_A8C_Source_Node {

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

	/** @var list<string> Team keys the `add_team` verb registered and `remove_team` left, in call order. */
	protected array $teams = [];

	/** @var array<string,array<string,mixed>> Each identifier asked of `issue` => the item as its page cut it, until it answers. */
	private array $awaited = [];

	/** @var array<string,true> Each identifier this process has asked of `issue`, which the walk asks no more. */
	private array $asked = [];

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
	 * mapping, but an issue that moved, cut short, which waits for `issue`
	 * while the pass has room; an issue with no string identifier is
	 * skipped. The last page opens the walk.
	 *
	 * @param array<array-key,mixed> $answer The provider's answer.
	 * @return array<int,array<string,mixed>>
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
				$item = $this->normalize_item( 'linear', $identifier, $issue['title'] ?? '', $issue['url'] ?? '', $issue['description'] ?? '', $issue['updated_at'] ?? '' );
				if ( 0 < $this->room && self::cut_short( Core::as_string( $issue['description'] ?? null ) ) && \strtotime( Core::as_string( $issue['updated_at'] ?? null ) ) >= $moved ) {
					$this->awaited[ $identifier ] = $item;
					$this->ask( $identifier );
					return null;
				}
				return $item;
			}
		);
		if ( 0 === --$this->pages ) {
			$this->walk();
		}
		return $items;
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
	 * Ask `issue` for the stored items of the last BACKFILL_DAYS still cut
	 * short that this process has not asked, as the pass's room allows, so a
	 * backlog drains over passes; none, logged, while the projection will not
	 * open.
	 */
	private function walk(): void {
		$day   = \intdiv( (int) Core::$now, Intel_Graph_Row_Schema::DAY_SECONDS ) - self::BACKFILL_DAYS;
		$limit = $this->room + \count( $this->asked );
		$keys  = Intel_Graph_Row_Schema::read(
			static fn ( Row_Reader $r ): array => \array_column(
				$r->rows(
					'SELECT DISTINCT i.item_key, i.retrieved_day FROM ' . Intel_Graph_Row_Schema::all( $r, 'items' ) . " i WHERE i.source = 'linear' AND i.retrieved_day >= ? AND length( i.body ) = ? AND substr( i.body, -3 ) = ? ORDER BY i.retrieved_day DESC, i.item_key LIMIT ?",
					[ $day, self::CUT_CHARS, self::CUT_TAIL, $limit ]
				),
				0
			),
			"{$this->name}: no intel-graph to read the issues cut short from; walking none: "
		);
		foreach ( $keys ?? [] as $key ) {
			$identifier = \substr( Core::as_string( $key ), \strlen( 'linear:' ) );
			if ( 0 < $this->room && ! isset( $this->asked[ $identifier ] ) && ! isset( $this->awaited[ $identifier ] ) ) {
				$this->ask( $identifier );
			}
		}
	}

	/**
	 * Ask `issue` for one issue, spending the pass's room.
	 *
	 * @param string $identifier The issue.
	 */
	private function ask( string $identifier ): void {
		--$this->room;
		$this->asked[ $identifier ] = true;
		$this->call( 'linear', 'issue', [ 'id' => $identifier ] );
	}

	/**
	 * The whole of one issue: an item that is new to record, or nothing, its
	 * stored row grown when this body is longer; an answer naming no
	 * description leaves it to `pass_ended()`.
	 *
	 * @param string                 $identifier The issue.
	 * @param array<array-key,mixed> $issue      The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	private function whole( string $identifier, array $issue ): array {
		if ( ! \is_string( $issue['description'] ?? null ) ) {
			return [];
		}
		unset( $this->awaited[ $identifier ] );
		$item   = $this->normalize_item( 'linear', $identifier, $issue['title'] ?? '', $issue['url'] ?? '', $issue['description'], $issue['updated_at'] ?? '' );
		$key    = Core::as_string( $item['item_key'] );
		$stored = self::stored( $key, $this->name );
		if ( null === $stored ) {
			return [ $item ];
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

	/** Record, logged, what `issue` did not answer, as its page cut it, then close the session. */
	protected function pass_ended(): void {
		if ( [] !== $this->awaited ) {
			$this->print_less_often( 'recording ' . \count( $this->awaited ) . ' issues as team-issues cut them: ', 'issue did not answer them' );
			$this->record( \array_values( $this->awaited ) );
			$this->awaited = [];
		}
		parent::pass_ended();
	}

	public static function node_schema(): array {
		return self::context_schema(
			'Reads the newest issues of the configured Linear teams through ContextA8C, as the person signed in to it on the host, one call a team, without blocking, on a TICK request (request_node linear TICK). Local workstation only.',
			'Start a pass over the teams; each page records its new issues as it lands. Trigger with `request_node linear TICK`.',
			Linear_Source_Node::team_verbs()
		);
	}
}

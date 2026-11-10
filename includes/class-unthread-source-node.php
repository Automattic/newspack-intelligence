<?php
/**
 * Unthread_Source_Node: reads the Unthread support conversations updated
 * since the pass before last, and each one's messages, and normalizes every
 * message into a recorded item.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. A pass POSTs `conversations/list`, the newest update first, and
 * reads each page until a conversation falls outside two intervals, or
 * WINDOW_SECONDS while the interval is unset, asking the messages of each one
 * inside; an answer's url names its endpoint and conversation, and its
 * `cursors` the next page. The published API reference documents a `where`
 * clause of `{ field, operator, value }` with `>` among its operators but does
 * not name a field it accepts, so the edge is read here, client-side, until a
 * live call shows whether `updatedAt` filters. Written against that reference
 * and not yet called live.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Unthread_Source_Node extends Source_Node {

	public const SOURCE = 'unthread';

	/** Seconds back from the clock a pass reads while the interval is unset. */
	public const WINDOW_SECONDS = 2 * Intel_Graph_Row_Schema::DAY_SECONDS;

	private const CONVERSATIONS = 'https://api.unthread.io/api/conversations/';

	/** Conversations or messages a page asks for, Unthread's most. */
	private const PAGE = 100;

	/** What a conversations page asks for, its cursor added after the first. */
	private const LIST_BODY = [
		'order'      => [ 'updatedAt' ],
		'descending' => true,
		'limit'      => self::PAGE,
	];

	/** What a messages page asks for, its cursor added after the first. */
	private const MESSAGES_BODY = [ 'limit' => self::PAGE ];

	/** A conversation id, which every url naming it carries. */
	private const UUID = '/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i';

	/** Queue the first page of conversations. */
	protected function begin(): void {
		$this->page( self::CONVERSATIONS . 'list', self::LIST_BODY );
	}

	/**
	 * What one answer means, by the endpoint its url names: a conversations
	 * page queues the messages of each conversation in the window, and a
	 * messages page records its messages; either queues its next page while
	 * `cursors.hasNext` is true. An answer with no `data` list logs Unthread's
	 * `message` and records nothing.
	 *
	 * @param string $url  The endpoint's url, as it was queued.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$answer = $this->json_answer( 'Unthread', $url, $body, static fn ( array $answer ): ?string => \is_array( $answer['data'] ?? null ) && \array_is_list( $answer['data'] ) ? null : Core::as_string( $answer['message'] ?? null, 'no data list' ) );
		if ( null === $answer ) {
			return [];
		}
		$cursors = Core::arr( $answer['cursors'] ?? null );
		$next    = true === ( $cursors['hasNext'] ?? null ) ? Core::str( $cursors['next'] ?? null ) : '';
		$rows    = Core::arr( $answer['data'] );
		if ( self::CONVERSATIONS . 'list' === $url ) {
			$this->open_page( $rows, $next );
			return [];
		}
		if ( '' !== $next ) {
			$this->page( $url, self::MESSAGES_BODY, $next, $this->note() );
		}
		return $this->messages( \explode( '/', \substr( $url, \strlen( self::CONVERSATIONS ) ), 2 )[0], $url, $rows );
	}

	/**
	 * Queue the messages of each conversation updated within two intervals,
	 * or WINDOW_SECONDS while the interval is unset, so a pass re-reads only
	 * the conversations that moved since the pass before last; then, when
	 * the page's last conversation with an `updatedAt` that parses is inside
	 * that edge too, queue the next page, behind the first page of each
	 * conversation's messages. A conversation whose `updatedAt` does not
	 * parse is not read and leaves the edge as it was.
	 *
	 * @param array<mixed> $rows The page's conversations, newest update first.
	 * @param string       $next The next page's cursor; '' for none.
	 */
	private function open_page( array $rows, string $next ): void {
		$moved = Core::$now - ( null === $this->interval ? self::WINDOW_SECONDS : 2 * $this->interval );
		$last  = null;
		foreach ( $rows as $row ) {
			$row     = Core::arr( $row );
			$updated = \strtotime( Core::str( $row['updatedAt'] ?? null ) );
			if ( false === $updated ) {
				continue;
			}
			$last = $updated;
			if ( $updated > $moved ) {
				$this->open( $row );
			}
		}
		if ( null !== $last && $last > $moved && '' !== $next ) {
			$this->page( self::CONVERSATIONS . 'list', self::LIST_BODY, $next );
		}
	}

	/**
	 * Queue a conversation's messages, noting its friendly id and context
	 * line, unless its id is not a uuid, which is logged.
	 *
	 * @param array<array-key,mixed> $conversation The conversation.
	 */
	private function open( array $conversation ): void {
		$id = Core::str( $conversation['id'] ?? null );
		if ( 1 !== \preg_match( self::UUID, $id ) ) {
			$this->print_less_often( 'Unthread named a conversation whose id is not a uuid: ', Core::terminal_safe( $id ) );
			return;
		}
		$note = [
			'ticket'  => '#' . Core::as_string( $conversation['friendlyId'] ?? null ),
			'context' => self::context( $conversation ),
		];
		$this->page( self::CONVERSATIONS . "{$id}/messages/list", self::MESSAGES_BODY, '', $note );
	}

	/**
	 * The line every message's body opens with, `[<status>] <customer>:
	 * <subject>`: the customer's name, else Unthread; the conversation's
	 * title, else its initial message's first line, its mrkdwn decoded, else
	 * its friendly id.
	 *
	 * @param array<array-key,mixed> $conversation The conversation.
	 */
	private static function context( array $conversation ): string {
		$customer = self::first( Core::arr( $conversation['customer'] ?? null )['name'] ?? null, 'Unthread' );
		$subject  = self::first( $conversation['title'] ?? null, self::first_line( self::mrkdwn( Core::str( Core::arr( $conversation['initialMessage'] ?? null )['text'] ?? null ) ) ), '#' . Core::as_string( $conversation['friendlyId'] ?? null ) );
		return '[' . Core::str( $conversation['status'] ?? null ) . "] {$customer}: {$subject}";
	}

	/**
	 * A messages page's messages as items, their mrkdwn decoded, each titled by
	 * the friendly id its page notes and its own first line, so two messages
	 * never share a title, its body its conversation's context line, then its
	 * poster's name and its text, and dated by its Slack-style ts; one missing
	 * its ts or text is logged and left out.
	 *
	 * @param string       $id   The conversation the page reads.
	 * @param string       $url  The page's url.
	 * @param array<mixed> $rows The page's messages.
	 * @return array<int,array<string,mixed>>
	 */
	private function messages( string $id, string $url, array $rows ): array {
		$ticket  = Core::as_string( $this->note()['ticket'] ?? '' );
		$context = Core::as_string( $this->note()['context'] ?? '' );
		return self::items(
			$rows,
			function ( array $message ) use ( $id, $url, $ticket, $context ): ?array {
				$ts = Core::str( $message['ts'] ?? null );
				if ( '' === $ts || ! \is_string( $message['text'] ?? null ) ) {
					$this->print_less_often( 'Unthread sent a message with no ts or text in ' . Core::terminal_safe( $url ) );
					return null;
				}
				$text   = self::mrkdwn( $message['text'] );
				$line   = self::first_line( $text );
				$poster = self::first( Core::arr( $message['user'] ?? null )['name'] ?? null, $message['userId'] ?? null );
				return $this->normalize_item( "{$id}:{$ts}", '' === $line ? $ticket : "{$ticket} {$line}", '', "{$context}\n{$poster}: {$text}", '@' . (int) $ts );
			}
		);
	}

	/** The first of the values that is a string other than '', else ''. */
	private static function first( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( \is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Queue a page of a list endpoint: its body, with the cursor after the
	 * first page, and the note its answer reads.
	 *
	 * @param string                 $url    The endpoint's url.
	 * @param array<string,mixed>    $body   The first page's body.
	 * @param string                 $cursor The page's cursor; '' for the first.
	 * @param array<array-key,mixed> $note   What the answer reads back.
	 */
	private function page( string $url, array $body, string $cursor = '', array $note = [] ): void {
		if ( '' !== $cursor ) {
			$body['cursor'] = $cursor;
		}
		$this->queue( $url, (string) \wp_json_encode( $body ), null, $note );
	}

	/** Unthread answers nothing without a credential, so a pass waits for one. */
	public static function credential_url(): string {
		return self::CONVERSATIONS;
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Reads the Unthread conversations updated within two intervals, or the last ' . ( self::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days while the interval is unset, and each one\'s messages, without blocking, one request at a time, on a TICK request (request_node unthread TICK).',
			'Start a pass over the conversations; each page records its new messages as it lands. Trigger with `request_node unthread TICK`.'
		);
	}
}

<?php
/**
 * Slack_Source_Node: reads the messages of the Slack channels the
 * `add_channel` verb names, and the replies of each thread active in its
 * window, and normalizes them into recorded items.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. A pass asks `auth.test` for the workspace's url while the
 * node holds none, then each channel's `conversations.history` over the
 * window; an answer's url names its endpoint, channel, thread and cursor,
 * which say what it is and what to read next.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Slack_Source_Node extends Source_Node {

	/** Seconds back from the clock every pass reads. */
	public const WINDOW_SECONDS = 2 * Intel_Graph_Row_Schema::DAY_SECONDS;

	private const API = 'https://slack.com/api/';

	/** Messages a page asks for. */
	private const PAGE = 200;

	/** Characters of a message's first line its title keeps. */
	private const TITLE_CHARS = 120;

	/** The three escapes Slack writes into message text, and what each spells. */
	private const ESCAPES = [
		'&amp;' => '&',
		'&lt;'  => '<',
		'&gt;'  => '>',
	];

	/** Notices of membership and channel settings, which record nothing. */
	private const SKIPPED = [ 'channel_join', 'channel_leave', 'channel_topic', 'channel_purpose' ];

	/** @var list<string> Channel ids the `add_channel` verb registered and `remove_channel` left, in call order. */
	protected array $channels = [];

	/** The workspace's url, `https://<team>.slack.com/`, as auth.test answers it; '' until it has. */
	private string $workspace = '';

	/** Queue auth.test while the workspace is unknown, then each channel's history over the window. */
	protected function begin(): void {
		if ( '' === $this->workspace ) {
			$this->queue( self::API . 'auth.test' );
		}
		$oldest = (string) ( (int) Core::$now - self::WINDOW_SECONDS );
		foreach ( $this->channels as $channel ) {
			$this->queue( self::page( 'history', $channel, $oldest ) );
		}
	}

	/**
	 * What one answer means, by the endpoint its url names: auth.test the
	 * workspace's url, kept only when it is https and names a host, logged
	 * otherwise; a history or replies page its messages and the next
	 * page its cursor names. An answer Slack refuses, with `ok` false and a
	 * 200, logs Slack's error and records nothing.
	 *
	 * @param string $url  The endpoint's url, as it was queued.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$answer = $this->json_answer( 'Slack', $url, $body, static fn ( array $answer ): ?string => true === ( $answer['ok'] ?? null ) ? null : Core::as_string( $answer['error'] ?? null, 'no ok answer' ) );
		if ( null === $answer ) {
			return [];
		}
		if ( self::API . 'auth.test' === $url ) {
			$workspace = Core::str( $answer['url'] ?? null );
			if ( 'https' === \wp_parse_url( $workspace, \PHP_URL_SCHEME ) && '' !== Core::as_string( \wp_parse_url( $workspace, \PHP_URL_HOST ) ) ) {
				$this->workspace = \trailingslashit( $workspace );
			} else {
				$this->print_less_often( "Slack's auth.test named no https workspace url: ", Core::terminal_safe( $workspace ) );
			}
			return [];
		}
		$cursor = Core::str( Core::arr( $answer['response_metadata'] ?? null )['next_cursor'] ?? null );
		if ( '' !== $cursor ) {
			$this->queue( \add_query_arg( 'cursor', \rawurlencode( $cursor ), $url ) );
		}
		\parse_str( Core::as_string( \wp_parse_url( $url, \PHP_URL_QUERY ) ), $query );
		return $this->messages( Core::as_string( $query['channel'] ?? null ), Core::as_string( $query['ts'] ?? null ), Core::as_string( $query['oldest'] ?? null ), Core::arr( $answer['messages'] ?? null ) );
	}

	/**
	 * A page's messages as items, Slack's three escapes decoded in one pass
	 * and its mentions kept; from a history page, each thread whose latest
	 * reply is newer than two intervals, or the window with no interval set,
	 * has its replies queued, so a pass reads only the threads that moved
	 * since the last but one. A replies page repeats its thread's parent,
	 * which record() drops as the history page's.
	 *
	 * @param string       $channel  The channel the page reads.
	 * @param string       $thread   The thread a replies page reads; '' for history.
	 * @param string       $oldest   The window's opening second.
	 * @param array<mixed> $messages The page's messages.
	 * @return array<int,array<string,mixed>>
	 */
	private function messages( string $channel, string $thread, string $oldest, array $messages ): array {
		$moved = Core::$now - ( null === $this->interval ? self::WINDOW_SECONDS : 2 * $this->interval );
		return self::items(
			$messages,
			function ( array $message ) use ( $channel, $thread, $oldest, $moved ): ?array {
				$ts = Core::str( $message['ts'] ?? null );
				if ( 1 !== \preg_match( '/^\d+\.\d+$/', $ts ) || \in_array( $message['subtype'] ?? null, self::SKIPPED, true ) ) {
					return null;
				}
				if ( '' === $thread && Core::as_int( $message['reply_count'] ?? null ) > 0 && (float) Core::as_string( $message['latest_reply'] ?? null ) > $moved ) {
					$this->queue( self::page( 'replies', $channel, $oldest, $ts ) );
				}
				$text = \strtr( Core::str( $message['text'] ?? null ), self::ESCAPES );
				return $this->normalize_item(
					'slack',
					"{$channel}:{$ts}",
					\mb_substr( \explode( "\n", $text, 2 )[0], 0, self::TITLE_CHARS ),
					'' === $this->workspace ? '' : "{$this->workspace}archives/{$channel}/p" . \str_replace( '.', '', $ts ),
					self::poster( $message ) . ": {$text}",
					'@' . (int) $ts
				);
			}
		);
	}

	/** The first page of a channel's history, or with $thread the replies of that thread, since $oldest. */
	private static function page( string $method, string $channel, string $oldest, string $thread = '' ): string {
		$ts = '' === $thread ? '' : "&ts={$thread}";
		return self::API . "conversations.{$method}?channel={$channel}{$ts}&oldest={$oldest}&limit=" . self::PAGE;
	}

	/**
	 * Who posted a message: their profile's real name, else the bot's name,
	 * else the username, else the user id.
	 *
	 * @param array<array-key,mixed> $message The message.
	 */
	private static function poster( array $message ): string {
		foreach ( [ Core::arr( $message['user_profile'] ?? null )['real_name'] ?? null, Core::arr( $message['bot_profile'] ?? null )['name'] ?? null, $message['username'] ?? null, $message['user'] ?? null ] as $name ) {
			if ( \is_string( $name ) && '' !== $name ) {
				return $name;
			}
		}
		return '';
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Reads the messages of the configured Slack channels over the last ' . ( self::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days, and the replies of each thread active in them, without blocking, one request at a time, on a TICK request (request_node slack TICK).',
			'Start a pass over the channels; each page records its new messages as it lands. Trigger with `request_node slack TICK`.',
			self::checked_list( 'channel', 'channels', '/^[CG][A-Z0-9]{8,}\z/', 'a Slack channel id', 'Register a channel to read on TICK by its id: <C… or G…>.' )
		);
	}
}

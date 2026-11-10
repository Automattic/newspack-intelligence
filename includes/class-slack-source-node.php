<?php
/**
 * Slack_Source_Node: reads the messages of the Slack channels the
 * `add_channel` commands name, and the replies of each thread active in its
 * window, and normalizes them into recorded items.
 *
 * A pass asks `auth.test` for the workspace's url while it holds none, then
 * each channel's `conversations.history`, every page noting the workspace;
 * an answer's url names its endpoint, channel, thread and cursor. A channel
 * added with `--skip-bots` records no bot post, though a bot's thread is
 * still read for its people's replies.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Slack_Source_Node extends Source_Node {

	public const SOURCE = 'slack';

	/** Seconds back from the clock every pass reads. */
	public const WINDOW_SECONDS = 2 * Intel_Graph_Row_Schema::DAY_SECONDS;

	private const API = 'https://slack.com/api/';

	/** auth.test names the workspace, not data. */
	protected const PROTOCOL_SUBJECTS = [ self::API . 'auth.test' ];

	/** Messages a page asks for. */
	private const PAGE = 200;

	/** Notices of membership and channel settings, which record nothing. */
	private const SKIPPED = [ 'channel_join', 'channel_leave', 'channel_topic', 'channel_purpose' ];

	/** @var list<string> Channel ids the `add_channel` commands registered and the `remove_channel` commands left, in call order. */
	protected array $channels = [];

	/** @var list<string> Channel ids added with `--skip-bots`, whose bot posts record nothing. */
	protected array $skip_bots = [];

	/** The workspace's url, `https://<team>.slack.com/`, as auth.test answers it; '' until it has. */
	private string $workspace = '';

	/** Queue auth.test while the workspace is unknown, else each channel's history over the window. */
	protected function begin(): void {
		if ( '' === $this->workspace ) {
			$this->queue( self::API . 'auth.test' );
			return;
		}
		$this->histories();
	}

	/**
	 * One answer by the endpoint its url names: auth.test the workspace's url,
	 * kept only when https, then the histories; a history or replies page its
	 * messages and the next page; an answer Slack refuses logs its error and
	 * records nothing.
	 *
	 * @param string $url  The endpoint's url, as it was queued.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$answer = $this->json_answer( 'Slack', $url, $body, static fn ( array $answer ): ?string => true === ( $answer['ok'] ?? null ) ? null : Core::as_string( $answer['error'] ?? null, 'no ok answer' ) );
		if ( self::API . 'auth.test' === $url ) {
			$workspace = Core::str( $answer['url'] ?? null );
			if ( 'https' === \wp_parse_url( $workspace, \PHP_URL_SCHEME ) && '' !== Core::as_string( \wp_parse_url( $workspace, \PHP_URL_HOST ) ) ) {
				$this->workspace = \trailingslashit( $workspace );
			} elseif ( null !== $answer ) {
				$this->fails( "Slack's auth.test returned no https workspace url: ", $workspace );
			}
			$this->histories();
			return [];
		}
		if ( null === $answer ) {
			return [];
		}
		$cursor = Core::str( Core::arr( $answer['response_metadata'] ?? null )['next_cursor'] ?? null );
		if ( '' !== $cursor ) {
			$this->queue( \add_query_arg( 'cursor', \rawurlencode( $cursor ), $url ), null, null, $this->note() );
		}
		\parse_str( Core::as_string( \wp_parse_url( $url, \PHP_URL_QUERY ) ), $query );
		return $this->messages( Core::as_string( $query['channel'] ?? null ), Core::as_string( $query['ts'] ?? null ), Core::as_string( $query['oldest'] ?? null ), Core::arr( $answer['messages'] ?? null ) );
	}

	/** An auth.test that fails in transport still reads the histories, as one Slack refuses does. */
	protected function failed( string $subject, string $error ): void {
		if ( self::API . 'auth.test' === $subject ) {
			$this->histories();
		}
	}

	/** Queue each channel's history over the window, noting the workspace, '' while unknown. */
	private function histories(): void {
		$oldest = (string) ( (int) Core::$now - self::WINDOW_SECONDS );
		foreach ( $this->channels as $channel ) {
			$this->queue( self::page( 'history', $channel, $oldest ), null, null, [ 'workspace' => $this->workspace ] );
		}
	}

	/**
	 * A page's messages as items, queuing from a history page the replies of
	 * each thread that moved within two intervals, or the window with none set;
	 * record() drops the parent a replies page repeats.
	 *
	 * @param string       $channel  The channel the page reads.
	 * @param string       $thread   The thread a replies page reads; '' for history.
	 * @param string       $oldest   The window's opening second.
	 * @param array<mixed> $messages The page's messages.
	 * @return array<int,array<string,mixed>>
	 */
	private function messages( string $channel, string $thread, string $oldest, array $messages ): array {
		$moved     = self::moved_after( $this->interval );
		$note      = $this->note();
		$workspace = Core::as_string( $note['workspace'] ?? '' );
		return self::items(
			$messages,
			function ( array $message ) use ( $channel, $thread, $oldest, $moved, $note, $workspace ): ?array {
				$fields = self::fields( $channel, $message, $workspace, \in_array( $channel, $this->skip_bots, true ) );
				if ( '' === $thread && self::moved( $message, $moved ) ) {
					$this->queue( self::page( 'replies', $channel, $oldest, Core::as_string( $message['ts'] ) ), null, null, $note );
				}
				return null === $fields ? null : $this->normalize_item( ...$fields );
			}
		);
	}

	/**
	 * The second a thread's latest reply must pass for its replies to be
	 * read: two intervals back, or the window's opening with no interval.
	 *
	 * @api Context_A8C_Slack_Node
	 * @param int|null $interval The source's interval in seconds, or null for none.
	 */
	public static function moved_after( ?int $interval ): float {
		return Core::$now - ( null === $interval ? self::WINDOW_SECONDS : 2 * $interval );
	}

	/**
	 * Whether a message, a notice aside, heads a thread whose latest reply is
	 * newer than $after; a bot's post heads one as any other does.
	 *
	 * @api Context_A8C_Slack_Node
	 * @param array<array-key,mixed> $message The message.
	 * @param float                  $after   What moved_after() answers.
	 */
	public static function moved( array $message, float $after ): bool {
		return '' !== self::posted_ts( $message ) && Core::as_int( $message['reply_count'] ?? null ) > 0 && (float) Core::as_string( $message['latest_reply'] ?? null ) > $after;
	}

	/**
	 * A message's item fields after the source, as normalize_item() takes
	 * them: its id `<channel>:<ts>`, its first line as title, its permalink on
	 * the workspace, '' for none, its poster leading its text, dated by its
	 * ts; null for a notice, a ts that is not Slack's, or with $skip_bots a
	 * bot's post: one carrying a `bot_id`, or a `bot_message`.
	 *
	 * @api Context_A8C_Slack_Node
	 * @param string                 $channel   The channel the message is in.
	 * @param array<array-key,mixed> $message   The message.
	 * @param string                 $workspace The workspace's url with its trailing slash, or ''.
	 * @param bool                   $skip_bots Whether the channel records no bot post.
	 * @return array{0:string,1:string,2:string,3:string,4:string}|null
	 */
	public static function fields( string $channel, array $message, string $workspace, bool $skip_bots ): ?array {
		$ts = self::posted_ts( $message );
		if ( '' === $ts || ( $skip_bots && ( '' !== Core::str( $message['bot_id'] ?? null ) || 'bot_message' === ( $message['subtype'] ?? null ) ) ) ) {
			return null;
		}
		$text = self::mrkdwn( Core::str( $message['text'] ?? null ) );
		return [
			"{$channel}:{$ts}",
			self::first_line( $text ),
			'' === $workspace ? '' : "{$workspace}archives/{$channel}/p" . \str_replace( '.', '', $ts ),
			self::poster( $message ) . ": {$text}",
			'@' . (int) $ts,
		];
	}

	/**
	 * A message's ts; '' for a notice, or a ts that is not Slack's.
	 *
	 * @param array<array-key,mixed> $message The message.
	 */
	private static function posted_ts( array $message ): string {
		$ts = Core::str( $message['ts'] ?? null );
		return 1 === \preg_match( '/^\d+\.\d+$/', $ts ) && ! \in_array( $message['subtype'] ?? null, self::SKIPPED, true ) ? $ts : '';
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

	/** Slack answers nothing without a credential, so a pass waits for one. */
	public static function credential_url(): string {
		return self::API;
	}

	/**
	 * The `add_channel` and `remove_channel` commands, the add refusing anything
	 * but a Slack channel id, and with `--skip-bots` recording no bot post.
	 *
	 * @api Context_A8C_Slack_Node
	 * @return list<array<string,mixed>>
	 */
	public static function channel_commands(): array {
		return self::checked_list(
			'channel',
			'channels',
			'/^[CG][A-Z0-9]{8,}\z/',
			'a Slack channel id',
			'Register a channel to read on TICK by its id: <C… or G…>. Adding it again replaces its --skip-bots.',
			[
				'name'        => 'skip-bots',
				'property'    => 'skip_bots',
				'description' => 'Record none of the channel\'s bot posts: a message carrying a bot_id, or a bot_message.',
			]
		);
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Reads the messages of the configured Slack channels over the last ' . ( self::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days, and the replies of each thread active in them, without blocking, one request at a time, on a TICK request (request_node slack TICK).',
			'Start a pass over the channels; each page records its new messages as it lands. Trigger with `request_node slack TICK`.',
			self::channel_commands()
		);
	}
}

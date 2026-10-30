<?php
/**
 * Context_A8C_Slack_Node: reads the messages of the Slack channels the
 * `add_channel` verb names, and the replies of each thread that moved,
 * through ContextA8C's `slack` provider, and records each as
 * Slack_Source_Node records the same message.
 *
 * Extends Context_A8C_Source_Node, which owns the token, the JSON-RPC
 * envelope and the answer. A pass asks each channel's `messages` over the
 * Slack source's window, newest first, and the next page older than an
 * answer's `next_latest` while it `has_more`; a message whose thread moved,
 * by the Slack source's rule, has its `thread` read, paged by `next_cursor`.
 * Each message's url is the Slack source's permalink, on the workspace its
 * own `permalink` names, which stands in for auth.test's.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Context_A8C_Slack_Node extends Context_A8C_Source_Node {

	/** Messages a page asks for, the most `messages` answers. */
	private const PAGE = 100;

	/** @var list<string> Channel ids the `add_channel` verb registered and `remove_channel` left, in call order. */
	protected array $channels = [];

	/**
	 * A page's messages as items, through the Slack source's mapping, asking
	 * the next page it names; from a `messages` page, each thread that moved
	 * has its replies asked. A `thread` page repeats its parent, which
	 * record() drops as the `messages` page's.
	 *
	 * @param string                 $subtool `messages` or `thread`.
	 * @param array<array-key,mixed> $args    The call's arguments.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	protected function answered( string $subtool, array $args, array $answer ): array {
		$channel = Core::as_string( $args['channel'] ?? null );
		$this->next_page( $subtool, $args, $answer );
		$moved = Slack_Source_Node::moved_after( $this->interval );
		return self::items(
			$answer['messages'] ?? null,
			function ( array $message ) use ( $subtool, $channel, $moved ): ?array {
				$fields = Slack_Source_Node::fields( $channel, $message, self::workspace( $message ) );
				if ( null !== $fields && 'messages' === $subtool && Slack_Source_Node::moved( $message, $moved ) ) {
					$this->call(
						'slack',
						'thread',
						[
							'channel'   => $channel,
							'thread_ts' => Core::as_string( $message['ts'] ),
						]
					);
				}
				return null === $fields ? null : $this->normalize_item( 'slack', ...$fields );
			}
		);
	}

	/**
	 * Ask the page after this one: a `messages` page that has more, older
	 * than its `next_latest`; a `thread` page naming a `next_cursor`, at it.
	 *
	 * @param string                 $subtool `messages` or `thread`.
	 * @param array<array-key,mixed> $args    The call's arguments, as the subject spells them.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 */
	private function next_page( string $subtool, array $args, array $answer ): void {
		$channel = Core::as_string( $args['channel'] ?? null );
		$latest  = true === ( $answer['has_more'] ?? null ) ? Core::str( $answer['next_latest'] ?? null ) : '';
		$cursor  = Core::str( $answer['next_cursor'] ?? null );
		if ( 'messages' === $subtool && '' !== $latest ) {
			$this->call(
				'slack',
				'messages',
				[
					'channel' => $channel,
					'oldest'  => Core::as_string( $args['oldest'] ?? null ),
					'limit'   => self::PAGE,
					'latest'  => $latest,
				]
			);
		}
		if ( 'thread' === $subtool && '' !== $cursor ) {
			$this->call(
				'slack',
				'thread',
				[
					'channel'   => $channel,
					'thread_ts' => Core::as_string( $args['thread_ts'] ?? null ),
					'cursor'    => $cursor,
				]
			);
		}
	}

	/**
	 * The workspace a message's permalink names, `https://<host>/`; '' when
	 * the permalink is not https or names no host.
	 *
	 * @param array<array-key,mixed> $message The message.
	 */
	private static function workspace( array $message ): string {
		$permalink = Core::str( $message['permalink'] ?? null );
		$host      = Core::as_string( \wp_parse_url( $permalink, \PHP_URL_HOST ) );
		return 'https' === \wp_parse_url( $permalink, \PHP_URL_SCHEME ) && '' !== $host ? "https://{$host}/" : '';
	}

	/** Ask each channel's messages over the window. */
	protected function calls(): void {
		$oldest = (string) ( (int) Core::$now - Slack_Source_Node::WINDOW_SECONDS );
		foreach ( $this->channels as $channel ) {
			$this->call(
				'slack',
				'messages',
				[
					'channel' => $channel,
					'oldest'  => $oldest,
					'limit'   => self::PAGE,
				]
			);
		}
	}

	public static function node_schema(): array {
		return self::context_schema(
			'Reads the messages of the configured Slack channels over the last ' . ( Slack_Source_Node::WINDOW_SECONDS / Intel_Graph_Row_Schema::DAY_SECONDS ) . ' days, and the replies of each thread that moved, through ContextA8C, as the person signed in to it on the host, without blocking, on a TICK request (request_node slack TICK). Local workstation only.',
			'Start a pass over the channels; each page records its new messages as it lands. Trigger with `request_node slack TICK`.',
			Slack_Source_Node::channel_verbs()
		);
	}
}

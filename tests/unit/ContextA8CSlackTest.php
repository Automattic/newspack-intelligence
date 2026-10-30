<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Context_A8C_Slack_Node;
use Newspack_Intelligence\Slack_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-context-a8c.php';

/**
 * The ContextA8C Slack node: each channel's messages over the Slack
 * source's window, page by page, and the replies of each thread that moved,
 * each recorded as the Slack source records the same message.
 */
final class ContextA8CSlackTest extends TestCase {
	use Drives_Context_A8C;

	private const WORKSPACE = 'https://heron-4410.slack.com/';

	protected function setUp(): void {
		parent::setUp();
		$this->install_context_a8c();
		$this->signed_in();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A ContextA8C Slack node as the local topology builds it.
	 *
	 * @param string ...$channels The channels it reads.
	 */
	private function slack( string ...$channels ): Context_A8C_Slack_Node {
		$node = $this->wire( new Context_A8C_Slack_Node(), 'slack', [ 'internal', $this->auth_dir ] );
		foreach ( $channels as $channel ) {
			$node->interpreter()->dispatch( 'add_channel', [ $channel ] );
		}
		return $node;
	}

	/** The window's opening second, as a pass asks for it. */
	private static function oldest(): string {
		return (string) ( (int) self::NOW - Slack_Source_Node::WINDOW_SECONDS );
	}

	/** A Slack ts $ago seconds before the clock. */
	private static function ts( int $ago, string $micro = '000200' ): string {
		return ( (int) self::NOW - $ago ) . ".{$micro}";
	}

	/**
	 * A message as `messages` answers it, its permalink on the workspace.
	 *
	 * @param array<string,mixed> $fields Fields over the defaults.
	 * @return array<string,mixed>
	 */
	private static function message( string $channel, string $ts, string $text, array $fields = [] ): array {
		return [
			'ts'        => $ts,
			'date'      => '2026-10-28 09:00:00',
			'user'      => 'U0WREN',
			'username'  => 'wren.osprey',
			'text'      => $text,
			'permalink' => self::WORKSPACE . "archives/{$channel}/p" . \str_replace( '.', '', $ts ),
			...$fields,
		];
	}

	/** A pass asks each channel's messages since the window opened, one call at a time. */
	public function test_a_pass_asks_each_channels_messages_over_the_window(): void {
		$this->tick( $this->slack( 'C0HERON11', 'G0KESTREL7' ) );
		$this->initialized();
		$this->answer( [ 'channel' => 'heron', 'messages' => [], 'has_more' => false ] );
		$this->answer( [ 'channel' => 'kestrel', 'messages' => [], 'has_more' => false ] );

		$this->assertSame(
			[
				[ 'provider' => 'slack', 'subtool' => 'messages', 'subtool_args' => [ 'channel' => 'C0HERON11', 'oldest' => self::oldest(), 'limit' => 100 ] ],
				[ 'provider' => 'slack', 'subtool' => 'messages', 'subtool_args' => [ 'channel' => 'G0KESTREL7', 'oldest' => self::oldest(), 'limit' => 100 ] ],
			],
			$this->calls()
		);
	}

	/**
	 * Each message records under the Slack source's key and item shape: the
	 * same message the Slack source reads records the same, its permalink's
	 * workspace standing in for auth.test's.
	 */
	public function test_each_message_records_as_the_slack_source_records_it(): void {
		$messages = [
			self::message( 'C0HERON11', self::ts( 3600, '004100' ), "Kestrel launch &amp; rollout\nmoved to <@U0PLOVER>" ),
			self::message( 'C0HERON11', self::ts( 1800 ), 'Released 4.2', [ 'subtype' => 'bot_message', 'bot_id' => 'B0KITE', 'username' => 'releases' ] ),
			self::message( 'C0HERON11', self::ts( 900 ), 'joined', [ 'subtype' => 'channel_join' ] ),
		];
		$real     = $this->wire( new Slack_Source_Node(), 'slack-real', [ 'internal' ] );
		$real->interpreter()->dispatch( 'add_channel', [ 'C0HERON11' ] );
		$this->tick( $real );
		$this->complete( 'https://slack.com/api/auth.test', (string) \json_encode( [ 'ok' => true, 'url' => self::WORKSPACE ] ) );
		$this->complete( 'https://slack.com/api/conversations.history?channel=C0HERON11&oldest=' . self::oldest() . '&limit=200', (string) \json_encode( [ 'ok' => true, 'messages' => $messages ] ) );
		$expected               = $this->items_recorded();
		$this->writer->captured = [];

		$this->tick( $this->slack( 'C0HERON11' ) );
		$this->initialized();
		$this->answer( [ 'channel' => 'heron', 'messages' => $messages, 'has_more' => false ] );

		$this->assertCount( 2, $expected );
		$this->assertSame( 'slack:C0HERON11:' . self::ts( 3600, '004100' ), $expected[0]['item_key'] );
		$this->assertSame( self::WORKSPACE . 'archives/C0HERON11/p' . \str_replace( '.', '', self::ts( 3600, '004100' ) ), $expected[0]['url'] );
		$this->assertSame( $expected, $this->items_recorded() );
	}

	/** A permalink that is not https leaves the message with no url, as the Slack source leaves one before auth.test. */
	public function test_a_permalink_that_is_not_https_leaves_no_url(): void {
		$this->tick( $this->slack( 'C0HERON11' ) );
		$this->initialized();
		$this->answer( [ 'messages' => [ self::message( 'C0HERON11', self::ts( 100 ), 'unlinked', [ 'permalink' => 'http://heron-4410.slack.com/archives/C0HERON11/p1' ] ) ] ] );

		$this->assertSame( '', $this->items_recorded()[0]['url'] );
	}

	/** An answer that has more asks the next page, older than its `next_latest`. */
	public function test_a_page_with_more_asks_the_next_one(): void {
		$this->tick( $this->slack( 'C0HERON11' ) );
		$this->initialized();
		$this->answer( [ 'messages' => [ self::message( 'C0HERON11', self::ts( 100 ), 'first page' ) ], 'has_more' => true, 'next_latest' => self::ts( 100 ) ] );
		$this->answer( [ 'messages' => [ self::message( 'C0HERON11', self::ts( 90000 ), 'second page' ) ], 'has_more' => false, 'next_latest' => self::ts( 90000 ) ] );

		$this->assertSame( [ 'channel' => 'C0HERON11', 'oldest' => self::oldest(), 'limit' => 100, 'latest' => self::ts( 100 ) ], $this->calls()[1]['subtool_args'] );
		$this->assertCount( 2, $this->calls() );
		$this->assertSame( [ 'slack:C0HERON11:' . self::ts( 100 ), 'slack:C0HERON11:' . self::ts( 90000 ) ], $this->recorded_keys() );
	}

	/**
	 * With an interval set, a thread whose latest reply is newer than two
	 * intervals has its replies read, paged by cursor, and each reply
	 * records; the parent is not recorded twice, and a thread that did not
	 * move is not read.
	 */
	public function test_replies_are_read_only_for_threads_that_moved(): void {
		$node = $this->slack( 'C0HERON11' );
		$node->interpreter()->dispatch( 'set_interval', [ '1300' ] );
		$moved  = self::ts( 5000, '000700' );
		$still  = self::ts( 6000, '000800' );
		$reply1 = self::ts( 2500, '000900' );
		$reply2 = self::ts( 2400, '001000' );

		$this->tick( $node );
		$this->initialized();
		$this->answer(
			[
				'messages' => [
					self::message( 'C0HERON11', $moved, 'Moved thread', [ 'reply_count' => 2, 'latest_reply' => $reply2 ] ),
					self::message( 'C0HERON11', $still, 'Still thread', [ 'reply_count' => 1, 'latest_reply' => self::ts( 2700 ) ] ),
				],
			]
		);
		$this->answer( [ 'messages' => [ self::message( 'C0HERON11', $moved, 'Moved thread' ), self::message( 'C0HERON11', $reply1, 'first reply' ) ], 'next_cursor' => 'cmVwbGllczoy' ] );
		$this->answer( [ 'messages' => [ self::message( 'C0HERON11', $reply2, 'second reply' ) ], 'next_cursor' => '' ] );

		$this->assertSame(
			[
				[ 'channel' => 'C0HERON11', 'thread_ts' => $moved ],
				[ 'channel' => 'C0HERON11', 'thread_ts' => $moved, 'cursor' => 'cmVwbGllczoy' ],
			],
			\array_column( \array_slice( $this->calls(), 1 ), 'subtool_args' )
		);
		$this->assertSame( 'thread', $this->calls()[1]['subtool'] );
		$this->assertSame( [ "slack:C0HERON11:{$moved}", "slack:C0HERON11:{$still}", "slack:C0HERON11:{$reply1}", "slack:C0HERON11:{$reply2}" ], $this->recorded_keys() );
	}

	/** A channel is refused unless it is a Slack channel id, as the Slack source refuses it. */
	public function test_add_channel_refuses_anything_but_a_channel_id(): void {
		$node = new Context_A8C_Slack_Node();
		$node->name( 'slack' );

		$this->assert_add_refuses( $node, 'channel', 'channels', [ '#newspack-product', 'c0heron11', 'C0HERON11&cursor=x' ], 'a Slack channel id' );
	}

	/** The channels round-trip through the dump. */
	public function test_channels_round_trip_through_the_dump(): void {
		$dump = $this->slack( 'C0HERON11', 'G0KESTREL7' )->dump_config();

		Core::reset();
		$replayed = new Context_A8C_Slack_Node();
		$replayed->name( 'slack' );
		$this->replay_config( $dump, $replayed );

		$this->assertSame( [ 'C0HERON11', 'G0KESTREL7' ], $this->read_private( $replayed, 'channels' ) );
	}
}

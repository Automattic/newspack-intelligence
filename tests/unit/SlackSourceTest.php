<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Slack_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The Slack source on the chain: auth.test once for the workspace's url,
 * then each channel's history over the window, page by page, and the
 * replies of each thread active in it, one request at a time.
 */
final class SlackSourceTest extends TestCase {
	use Drives_Curl;

	private const API = 'https://slack.com/api/';

	private const AUTH = self::API . 'auth.test';

	private const WORKSPACE = 'https://heron-4410.slack.com/';

	/** The pinned clock: no default's, and a past instant, so the window arithmetic is fixed. */
	private const NOW = 1792999817.0;

	protected function setUp(): void {
		parent::setUp();
		Core::$now   = self::NOW;
		Core::$clock = static fn (): float => self::NOW;
		$this->install_curl();
	}

	protected function tearDown(): void {
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * A Slack source as the collect topology builds it.
	 *
	 * @param string ...$channels The channels it reads.
	 */
	private function slack( string ...$channels ): Slack_Source_Node {
		$node = $this->wire( new Slack_Source_Node(), 'slack', [ 'internal' ] );
		foreach ( $channels as $channel ) {
			$node->interpreter()->dispatch( 'add_channel', [ $channel ] );
		}
		return $node;
	}

	/** The window's opening second, as a pass asks for it. */
	private static function oldest(): int {
		return (int) Core::$now - Slack_Source_Node::WINDOW_SECONDS;
	}

	private static function history( string $channel ): string {
		return self::API . "conversations.history?channel={$channel}&oldest=" . self::oldest() . '&limit=200';
	}

	private static function replies( string $channel, string $ts ): string {
		return self::API . "conversations.replies?channel={$channel}&ts={$ts}&oldest=" . self::oldest() . '&limit=200';
	}

	/** A Slack ts $ago seconds before the clock. */
	private static function ts( int $ago, string $micro = '000200' ): string {
		return ( (int) Core::$now - $ago ) . ".{$micro}";
	}

	/**
	 * A successful answer.
	 *
	 * @param array<string,mixed> $fields Its fields beside `ok`.
	 */
	private static function ok( array $fields ): string {
		return (string) \json_encode( [ 'ok' => true, ...$fields ] );
	}

	/** Tick and answer auth.test with the workspace's url. */
	private function authed( Slack_Source_Node $node ): void {
		$this->tick( $node );
		$this->complete( self::AUTH, self::ok( [ 'url' => self::WORKSPACE, 'team' => 'Heron', 'user' => 'intel-bot' ] ) );
	}

	/** A pass asks auth.test first, then each channel's history over the last two days, one at a time. */
	public function test_a_pass_reads_auth_test_then_each_channels_history_over_the_window(): void {
		$node = $this->slack( 'C0HERON11', 'G0KESTREL7' );

		$this->tick( $node );
		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 3 ] ] ], $this->answers() );
		$this->assertSame( [ self::AUTH ], $this->dispatched );
		$this->complete( self::AUTH, self::ok( [ 'url' => self::WORKSPACE ] ) );
		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ) ], $this->dispatched );
		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [] ] ) );

		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ), self::history( 'G0KESTREL7' ) ], $this->dispatched );
		$this->assertSame( 172800, Slack_Source_Node::WINDOW_SECONDS );
		$this->assertTrue( $this->opts[ self::history( 'G0KESTREL7' ) ][ \CURLOPT_HTTPGET ] );
	}

	/** The workspace's url outlives the pass, so the next pass asks auth.test no more. */
	public function test_auth_test_is_asked_once_a_worker(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [] ] ) );

		$this->tick( $node );

		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ), self::history( 'C0HERON11' ) ], $this->dispatched );
	}

	/**
	 * Each message records under its channel and ts: titled by its first
	 * line cut to 120 characters, its poster's name leading its text, its
	 * permalink on the workspace, dated by its ts.
	 */
	public function test_history_records_each_message(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$ts   = self::ts( 3600, '004100' );
		$long = \str_repeat( 'é', 130 );

		$this->complete(
			self::history( 'C0HERON11' ),
			self::ok(
				[
					'messages' => [
						[ 'type' => 'message', 'user' => 'U0WREN', 'ts' => $ts, 'text' => "Kestrel launch moved\nto Thursday", 'user_profile' => [ 'real_name' => 'Wren Osprey' ] ],
						[ 'type' => 'message', 'user' => 'U0WREN', 'ts' => self::ts( 1800 ), 'text' => $long, 'user_profile' => [ 'real_name' => 'Wren Osprey' ] ],
					],
				]
			)
		);

		$by   = $this->recorded();
		$key  = "slack:C0HERON11:{$ts}";
		$item = $by[ $key ];
		$this->assertSame( 'slack', $item['source'] );
		$this->assertSame( 'internal', $item['tier'] );
		$this->assertSame( 'Kestrel launch moved', $item['title'] );
		$this->assertSame( "Wren Osprey: Kestrel launch moved\nto Thursday", $item['body'] );
		$this->assertSame( self::WORKSPACE . 'archives/C0HERON11/p' . \str_replace( '.', '', $ts ), $item['url'] );
		$this->assertSame( (int) Core::$now - 3600, $item['item_ts'] );
		$this->assertSame( \str_repeat( 'é', 120 ), $by[ 'slack:C0HERON11:' . self::ts( 1800 ) ]['title'] );
	}

	/**
	 * Slack's three escapes decode once in the title and the body; its
	 * mentions, and any other entity, stay as Slack wrote them.
	 */
	public function test_slacks_escapes_decode_and_its_mentions_stay(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$text = "Ship &lt;Heron&gt; &amp; Kestrel, <@U0WREN>\nin <#C0HERON11|newspack-product>: &amp;lt;raw&amp;gt; &quot;as is&quot;";

		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [ [ 'ts' => self::ts( 100 ), 'text' => $text, 'user' => 'U0WREN', 'user_profile' => [ 'real_name' => 'Wren Osprey' ] ] ] ] ) );

		$item = $this->recorded()[ 'slack:C0HERON11:' . self::ts( 100 ) ];
		$this->assertSame( 'Ship <Heron> & Kestrel, <@U0WREN>', $item['title'] );
		$this->assertSame( "Wren Osprey: Ship <Heron> & Kestrel, <@U0WREN>\nin <#C0HERON11|newspack-product>: &lt;raw&gt; &quot;as is&quot;", $item['body'] );
	}

	/** The poster is named by their profile's real name, else the bot's name, else the username, else the user id. */
	public function test_the_poster_is_named_in_order(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$profile = [ 'real_name' => 'Wren Osprey' ];
		$bot     = [ 'name' => 'Release Kite' ];

		$this->complete(
			self::history( 'C0HERON11' ),
			self::ok(
				[
					'messages' => [
						[ 'ts' => self::ts( 100 ), 'text' => 'one', 'user' => 'U0A', 'username' => 'wren-u', 'user_profile' => $profile, 'bot_profile' => $bot ],
						[ 'ts' => self::ts( 200 ), 'text' => 'two', 'user' => 'U0B', 'username' => 'kite-u', 'bot_profile' => $bot ],
						[ 'ts' => self::ts( 300 ), 'text' => 'three', 'user' => 'U0C', 'username' => 'plover-u', 'user_profile' => [ 'real_name' => '' ] ],
						[ 'ts' => self::ts( 400 ), 'text' => 'four', 'user' => 'U0DUNLIN' ],
					],
				]
			)
		);

		$this->assertSame( [ 'Wren Osprey: one', 'Release Kite: two', 'plover-u: three', 'U0DUNLIN: four' ], \array_column( \array_values( $this->recorded() ), 'body' ) );
	}

	/** Channel joins, leaves, topics and purposes record nothing. */
	public function test_membership_and_topic_notices_are_skipped(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );

		$this->complete(
			self::history( 'C0HERON11' ),
			self::ok(
				[
					'messages' => [
						[ 'ts' => self::ts( 100 ), 'subtype' => 'channel_join', 'text' => 'joined', 'user' => 'U0A' ],
						[ 'ts' => self::ts( 200 ), 'subtype' => 'channel_leave', 'text' => 'left', 'user' => 'U0A' ],
						[ 'ts' => self::ts( 300 ), 'subtype' => 'channel_topic', 'text' => 'topic', 'user' => 'U0A' ],
						[ 'ts' => self::ts( 400 ), 'subtype' => 'channel_purpose', 'text' => 'purpose', 'user' => 'U0A' ],
						[ 'ts' => self::ts( 500 ), 'subtype' => 'bot_message', 'text' => 'Released 4.2', 'username' => 'releases' ],
					],
				]
			)
		);

		$this->assertSame( [ 'slack:C0HERON11:' . self::ts( 500 ) ], \array_keys( $this->recorded() ) );
	}

	/** A history answer naming a next cursor queues the same request at it. */
	public function test_a_next_cursor_queues_page_two(): void {
		$node = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$page2 = self::history( 'C0HERON11' ) . '&cursor=' . \rawurlencode( 'bmV4dF90czoxNTEy=' );
		$page3 = self::history( 'C0HERON11' ) . '&cursor=' . \rawurlencode( 'bmV4dF90czo5OTk=' );

		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [ [ 'ts' => self::ts( 100 ), 'text' => 'first page', 'user' => 'U0A' ] ], 'has_more' => true, 'response_metadata' => [ 'next_cursor' => 'bmV4dF90czoxNTEy=' ] ] ) );
		$this->complete( $page2, self::ok( [ 'messages' => [ [ 'ts' => self::ts( 90000 ), 'text' => 'second page', 'user' => 'U0A' ] ], 'response_metadata' => [ 'next_cursor' => 'bmV4dF90czo5OTk=' ] ] ) );
		$this->complete( $page3, self::ok( [ 'messages' => [], 'response_metadata' => [ 'next_cursor' => '' ] ] ) );

		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ), $page2, $page3 ], $this->dispatched );
		$this->assertSame( [ 'slack:C0HERON11:' . self::ts( 100 ), 'slack:C0HERON11:' . self::ts( 90000 ) ], \array_keys( $this->recorded() ) );
	}

	/**
	 * A thread whose latest reply falls in the window has its replies read,
	 * paged as history is, and every reply records; the parent the history
	 * recorded is not recorded twice, and a thread quiet since the window
	 * opened is not read.
	 */
	public function test_replies_are_read_only_for_threads_active_in_the_window(): void {
		$node   = $this->slack( 'C0HERON11' );
		$this->authed( $node );
		$active = self::ts( 7200, '000300' );
		$quiet  = self::ts( 3000, '000400' );
		$reply1 = self::ts( 600, '000500' );
		$reply2 = self::ts( 300, '000600' );
		$page2  = self::replies( 'C0HERON11', $active ) . '&cursor=' . \rawurlencode( 'cmVwbGllczoy' );

		$this->complete(
			self::history( 'C0HERON11' ),
			self::ok(
				[
					'messages' => [
						[ 'ts' => $active, 'thread_ts' => $active, 'text' => 'Plover rollout thread', 'user' => 'U0A', 'reply_count' => 2, 'latest_reply' => $reply2 ],
						[ 'ts' => $quiet, 'thread_ts' => $quiet, 'text' => 'Old thread', 'user' => 'U0A', 'reply_count' => 4, 'latest_reply' => self::ts( Slack_Source_Node::WINDOW_SECONDS + 60 ) ],
					],
				]
			)
		);
		$this->complete(
			self::replies( 'C0HERON11', $active ),
			self::ok(
				[
					'messages'          => [
						[ 'ts' => $active, 'thread_ts' => $active, 'text' => 'Plover rollout thread', 'user' => 'U0A', 'reply_count' => 2, 'latest_reply' => $reply2 ],
						[ 'ts' => $reply1, 'thread_ts' => $active, 'text' => 'first reply', 'user' => 'U0B' ],
					],
					'response_metadata' => [ 'next_cursor' => 'cmVwbGllczoy' ],
				]
			)
		);
		$this->complete( $page2, self::ok( [ 'messages' => [ [ 'ts' => $reply2, 'thread_ts' => $active, 'text' => 'second reply', 'user' => 'U0C' ] ] ] ) );

		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ), self::replies( 'C0HERON11', $active ), $page2 ], $this->dispatched );
		$this->assertSame(
			[ "slack:C0HERON11:{$active}", "slack:C0HERON11:{$quiet}", "slack:C0HERON11:{$reply1}", "slack:C0HERON11:{$reply2}" ],
			$this->recorded_keys()
		);
	}

	/**
	 * With an interval set, a thread has its replies read only when its
	 * latest reply is newer than two intervals, though both threads fall
	 * inside the two-day window.
	 */
	public function test_replies_are_read_only_for_threads_that_moved_within_two_intervals(): void {
		$node = $this->slack( 'C0HERON11' );
		$node->interpreter()->dispatch( 'set_interval', [ '1300' ] );
		$this->authed( $node );
		$moved = self::ts( 5000, '000700' );
		$still = self::ts( 6000, '000800' );

		$this->complete(
			self::history( 'C0HERON11' ),
			self::ok(
				[
					'messages' => [
						[ 'ts' => $moved, 'text' => 'Moved thread', 'user' => 'U0A', 'reply_count' => 1, 'latest_reply' => self::ts( 2500 ) ],
						[ 'ts' => $still, 'text' => 'Still thread', 'user' => 'U0A', 'reply_count' => 1, 'latest_reply' => self::ts( 2700 ) ],
					],
				]
			)
		);
		$this->complete( self::replies( 'C0HERON11', $moved ), self::ok( [ 'messages' => [] ] ) );

		$this->assertSame( [ self::AUTH, self::history( 'C0HERON11' ), self::replies( 'C0HERON11', $moved ) ], $this->dispatched );
	}

	/** Slack answers a refusal with a 200 and `ok` false: it is logged and nothing records. */
	public function test_an_answer_that_is_not_ok_logs_slacks_error_and_records_nothing(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->slack( 'C0HERON11' );
		$this->authed( $node );

		$this->complete( self::history( 'C0HERON11' ), '{"ok":false,"error":"not_in_channel-7720","messages":[{"ts":"' . self::ts( 100 ) . '","text":"x"}]}' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'slack: Slack answered an error for ' . self::history( 'C0HERON11' ) . ': not_in_channel-7720' ) );
	}

	/** Each refusal is logged under its own url, so a second channel refused in one window still shows. */
	public function test_each_refused_channel_is_logged(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->slack( 'C0HERON11', 'G0KESTREL7' );
		$this->authed( $node );

		$this->complete( self::history( 'C0HERON11' ), '{"ok":false,"error":"not_in_channel-7720"}' );
		$this->complete( self::history( 'G0KESTREL7' ), '{"ok":false,"error":"channel_not_found-7721"}' );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'slack: Slack answered an error for ' . self::history( 'C0HERON11' ) . ': not_in_channel-7720' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'slack: Slack answered an error for ' . self::history( 'G0KESTREL7' ) . ': channel_not_found-7721' ) );
	}

	/** Until auth.test answers, a message carries no permalink, and the refusal is logged. */
	public function test_a_message_before_the_workspace_is_known_has_no_url(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->slack( 'C0HERON11' );
		$this->tick( $node );

		$this->complete( self::AUTH, '{"ok":false,"error":"not_authed-3391"}' );
		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [ [ 'ts' => self::ts( 100 ), 'text' => 'unlinked', 'user' => 'U0A' ] ] ] ) );

		$this->assertSame( '', $this->recorded()[ 'slack:C0HERON11:' . self::ts( 100 ) ]['url'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'slack: Slack answered an error for https://slack.com/api/auth.test: not_authed-3391' ) );
	}

	/** An auth.test url that is not https is refused, logged, and leaves every permalink empty. */
	public function test_a_workspace_url_that_is_not_https_is_refused(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->slack( 'C0HERON11' );
		$this->tick( $node );

		$this->complete( self::AUTH, self::ok( [ 'url' => 'http://heron-4410.slack.com/' ] ) );
		$this->complete( self::history( 'C0HERON11' ), self::ok( [ 'messages' => [ [ 'ts' => self::ts( 100 ), 'text' => 'unlinked', 'user' => 'U0A' ] ] ] ) );

		$this->assertSame( '', $this->recorded()[ 'slack:C0HERON11:' . self::ts( 100 ) ]['url'] );
		$this->assertCount( 1, self::stderr_lines( $stderr, "slack: Slack's auth.test named no https workspace url: http://heron-4410.slack.com/" ) );
	}

	/** A channel is refused unless it is a Slack channel id, naming the value; nothing is added. */
	public function test_add_channel_refuses_anything_but_a_channel_id(): void {
		$node = new Slack_Source_Node();
		$node->name( 'slack' );

		$this->assert_add_refuses( $node, 'channel', 'channels', [ '#newspack-product', 'c0heron11', 'C0HERON1', 'D0HERON11', 'C0HERON11&cursor=x', ' C0HERON11', "C0HERON11\n" ], 'a Slack channel id' );
		$this->assertSame( "ok\n", $node->interpreter()->dispatch( 'add_channel', [ 'G0KESTREL7' ] ) );
		$this->assertSame( [ 'G0KESTREL7' ], $this->read_private( $node, 'channels' ) );
	}

	/** The channels round-trip through the dump, a removed one left out. */
	public function test_channels_round_trip_through_the_dump(): void {
		$node = $this->slack( 'C0HERON11', 'G0KESTREL7', 'C0PLOVER22' );
		$node->interpreter()->dispatch( 'remove_channel', [ 'G0KESTREL7' ] );
		$dump = $node->dump_config();

		Core::reset();
		$replayed = new Slack_Source_Node();
		$replayed->name( 'slack' );
		$this->replay_config( $dump, $replayed );

		$this->assertStringContainsString( 'command_node slack:config add_channel C0HERON11', $dump );
		$this->assertSame( [ 'C0HERON11', 'C0PLOVER22' ], $this->read_private( $replayed, 'channels' ) );
	}
}

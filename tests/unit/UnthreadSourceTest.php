<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Deduper_Node;
use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Unthread_Source_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The Unthread source on the chain: the conversations updated over the last
 * two days, newest first, page by page until one falls outside the window,
 * then each one's messages, one request at a time.
 */
final class UnthreadSourceTest extends TestCase {
	use Drives_Curl;

	private const API = 'https://api.unthread.io/api/';

	private const LIST = self::API . 'conversations/list';

	private const HERON = '3f6c2a10-7d4e-4b1a-9c55-1e2f3a4b5c6d';

	private const KESTREL = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

	private const PLOVER = 'c0ffee00-1234-4cde-8f90-abcdef012345';

	/** The context line every HERON message's body opens with. */
	private const CONTEXT = '[in_progress] Heron Gazette: Heron Gazette paywall loop';

	/** The pinned clock: no default's, and a past instant, so the window arithmetic is fixed. */
	private const NOW = 1793177263.0;

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

	/** An Unthread source as the collect topology builds it. */
	private function unthread(): Unthread_Source_Node {
		return $this->wire( new Unthread_Source_Node(), 'unthread', [ 'restricted', 'unthread' ] );
	}

	private static function messages_url( string $id ): string {
		return self::API . "conversations/{$id}/messages/list";
	}

	/** An ISO instant $ago seconds before the clock. */
	private static function iso( int $ago ): string {
		return \gmdate( 'Y-m-d\TH:i:s.000\Z', (int) Core::$now - $ago );
	}

	/**
	 * A conversation updated $ago seconds before the clock.
	 *
	 * @param array<string,mixed> $fields Fields that replace the defaults here.
	 * @return array<string,mixed>
	 */
	private static function conversation( string $id, int $ago, array $fields = [] ): array {
		return [
			'id'             => $id,
			'friendlyId'     => 4417,
			'title'          => 'Heron Gazette paywall loop',
			'status'         => 'in_progress',
			'createdAt'      => self::iso( $ago + 9000 ),
			'updatedAt'      => self::iso( $ago ),
			'customer'       => [ 'name' => 'Heron Gazette', 'slackChannelId' => 'C0HERONSUP' ],
			'customerId'     => 'cust-heron-8812',
			'initialMessage' => [ 'ts' => '1793100000.000100', 'text' => "Readers loop at the paywall\nafter login" ],
			...$fields,
		];
	}

	/**
	 * A list answer.
	 *
	 * @param list<array<string,mixed>> $data The rows.
	 */
	private static function page( array $data, string $next = '' ): string {
		$cursors = [ 'hasNext' => '' !== $next, 'hasPrevious' => false ];
		if ( '' !== $next ) {
			$cursors['next'] = $next;
		}
		return (string) \json_encode( [ 'data' => $data, 'totalCount' => \count( $data ), 'cursors' => $cursors ] );
	}

	/**
	 * A message.
	 *
	 * @param array<string,mixed> $fields Fields that replace the defaults here.
	 * @return array<string,mixed>
	 */
	private static function message( string $conversation, string $ts, int $ago, array $fields = [] ): array {
		return [
			'ts'             => $ts,
			'text'           => 'Cleared the session cache for Heron',
			'timestamp'      => self::iso( $ago ),
			'user'           => [ 'id' => 'U0WREN', 'name' => 'Wren Osprey', 'email' => 'wren@heron.example' ],
			'userId'         => 'U0WREN',
			'threadTs'       => '1793100000.000100',
			'conversationId' => $conversation,
			...$fields,
		];
	}

	/** @return array<string,mixed> The JSON body the last request to $url carried. */
	private function body_of( string $url ): array {
		return \json_decode( (string) $this->opts[ $url ][ \CURLOPT_POSTFIELDS ], true );
	}

	/** A pass POSTs one conversations list, newest update first, 100 a page, with no filter. */
	public function test_a_pass_posts_one_conversations_list_newest_first(): void {
		$node = $this->unthread();

		$this->tick( $node );

		$this->assertSame( [ [ 'verb' => 'TICK', 'data' => [ 'fetching' => 1 ] ] ], $this->answers() );
		$this->assertSame( [ self::LIST ], $this->dispatched );
		$this->assertTrue( $this->opts[ self::LIST ][ \CURLOPT_POST ] );
		$this->assertSame( [ 'order' => [ 'updatedAt' ], 'descending' => true, 'limit' => 100 ], $this->body_of( self::LIST ) );
		$this->assertSame( 172800, Unthread_Source_Node::WINDOW_SECONDS );
	}

	/** A conversation updated inside the window has its messages read; one updated before it does not. */
	public function test_only_conversations_updated_in_the_window_have_their_messages_read(): void {
		$node = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 3600 ), self::conversation( self::KESTREL, Unthread_Source_Node::WINDOW_SECONDS + 60 ) ] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ) ], $this->dispatched );
		$this->assertSame( [ 'limit' => 100 ], $this->body_of( self::messages_url( self::HERON ) ) );
	}

	/**
	 * A page naming a next cursor, whose last conversation is in the window,
	 * queues the next page behind the first page of each of its
	 * conversations' messages.
	 */
	public function test_a_page_ending_in_the_window_queues_the_next_behind_its_messages(): void {
		$node = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ), self::conversation( self::KESTREL, 7200 ) ], 'Y3Vyc29yOmhlcm9uLTI=' ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [] ) );
		$this->complete( self::messages_url( self::KESTREL ), self::page( [] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ), self::messages_url( self::KESTREL ), self::LIST ], $this->dispatched );
		$this->assertSame( [ 'order' => [ 'updatedAt' ], 'descending' => true, 'limit' => 100, 'cursor' => 'Y3Vyc29yOmhlcm9uLTI=' ], $this->body_of( self::LIST ) );
	}

	/** A page whose last conversation falls before the window asks for no next page, whatever its cursor says. */
	public function test_paging_stops_at_the_window_edge(): void {
		$node = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ), self::conversation( self::KESTREL, Unthread_Source_Node::WINDOW_SECONDS + 5 ) ], 'Y3Vyc29yOnN0YWxl' ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ) ], $this->dispatched );
	}

	/** A page saying it has no next asks for none, though its last conversation is in the window. */
	public function test_paging_stops_when_has_next_is_false(): void {
		$node = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ) ], $this->dispatched );
	}

	/**
	 * With an interval set, only conversations updated within two intervals
	 * have their messages read, though both fall inside the two-day window,
	 * and paging stops at that edge, whatever the cursor says.
	 */
	public function test_with_an_interval_only_conversations_that_moved_within_two_intervals_are_read(): void {
		$node = $this->unthread();
		$node->interpreter()->dispatch( 'set_interval', [ '1300' ] );
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 2500 ), self::conversation( self::KESTREL, 2700 ) ], 'Y3Vyc29yOm1vdmVk' ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ) ], $this->dispatched );
	}

	/**
	 * A conversation whose updatedAt does not parse is not read and does not
	 * move the paging edge: the page's last parseable conversation decides.
	 */
	public function test_an_unparseable_update_neither_opens_nor_stops_paging(): void {
		$node = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ), self::conversation( self::KESTREL, 700, [ 'updatedAt' => 'yesterday-ish-3318' ] ) ], 'Y3Vyc29yOnBhcnNl' ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ), self::LIST ], $this->dispatched );
		$this->assertSame( 'Y3Vyc29yOnBhcnNl', $this->body_of( self::LIST )['cursor'] );
	}

	/**
	 * Each message records under its conversation and ts, at the restricted
	 * tier, titled by its conversation's friendly id and its own first line;
	 * its body opens with its conversation's status, customer and title,
	 * then its poster's name and its text; dated by its ts, not its
	 * timestamp, with no url.
	 */
	public function test_each_message_records_one_item(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete(
			self::messages_url( self::HERON ),
			self::page(
				[
					self::message( self::HERON, '1793170000.000200', 50000 ),
					self::message( self::HERON, '1793171000.000300', 6263, [ 'text' => "Still looping\non Safari", 'user' => [ 'id' => 'U0KITE', 'name' => 'Kite Plover' ], 'userId' => 'U0KITE' ] ),
				]
			)
		);

		$by   = $this->recorded();
		$item = $by[ 'unthread:' . self::HERON . ':1793170000.000200' ];
		$this->assertSame( 'unthread', $item['source'] );
		$this->assertSame( 'restricted', $item['tier'] );
		$this->assertSame( '#4417 Cleared the session cache for Heron', $item['title'] );
		$this->assertSame( self::CONTEXT . "\nWren Osprey: Cleared the session cache for Heron", $item['body'] );
		$this->assertSame( 1793170000, $item['item_ts'] );
		$this->assertSame( '', $item['url'] );
		$this->assertSame( '#4417 Still looping', $by[ 'unthread:' . self::HERON . ':1793171000.000300' ]['title'] );
		$this->assertSame( self::CONTEXT . "\nKite Plover: Still looping\non Safari", $by[ 'unthread:' . self::HERON . ':1793171000.000300' ]['body'] );
		$this->assertCount( 2, $by );
	}

	/** A message whose user is null is posted by its userId. */
	public function test_a_message_with_no_user_is_posted_by_its_user_id(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.000400', 900, [ 'user' => null, 'userId' => 'U0DUNLIN' ] ) ] ) );

		$this->assertSame( self::CONTEXT . "\nU0DUNLIN: Cleared the session cache for Heron", $this->recorded()[ 'unthread:' . self::HERON . ':1793170000.000400' ]['body'] );
	}

	/**
	 * The body's context line names Unthread with no customer; with no
	 * conversation title, the first line of the initial message cut to 120
	 * characters; with neither, the friendly id.
	 */
	public function test_the_context_line_falls_back_in_order(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$long = \str_repeat( 'ü', 130 );
		$this->complete(
			self::LIST,
			self::page(
				[
					self::conversation( self::HERON, 600, [ 'customer' => null, 'status' => 'open' ] ),
					self::conversation( self::KESTREL, 700, [ 'title' => null, 'status' => 'on_hold', 'initialMessage' => [ 'ts' => '1', 'text' => "{$long}\nsecond line" ] ] ),
					self::conversation( self::PLOVER, 800, [ 'title' => '', 'status' => 'closed', 'friendlyId' => 9031, 'initialMessage' => null ] ),
				]
			)
		);

		foreach ( [ self::HERON, self::KESTREL, self::PLOVER ] as $id ) {
			$this->complete( self::messages_url( $id ), self::page( [ self::message( $id, '1793170000.000500', 900 ) ] ) );
		}

		$by = $this->recorded();
		$poster = "\nWren Osprey: Cleared the session cache for Heron";
		$this->assertSame( "[open] Unthread: Heron Gazette paywall loop{$poster}", $by[ 'unthread:' . self::HERON . ':1793170000.000500' ]['body'] );
		$this->assertSame( '[on_hold] Heron Gazette: ' . \str_repeat( 'ü', 120 ) . $poster, $by[ 'unthread:' . self::KESTREL . ':1793170000.000500' ]['body'] );
		$this->assertSame( "[closed] Heron Gazette: #9031{$poster}", $by[ 'unthread:' . self::PLOVER . ':1793170000.000500' ]['body'] );
	}

	/** A message whose text is empty is titled by its conversation's friendly id. */
	public function test_a_message_with_empty_text_is_titled_by_the_friendly_id(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600, [ 'friendlyId' => 6650 ] ) ] ) );

		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.001100', 900, [ 'text' => '' ] ) ] ) );

		$item = $this->recorded()[ 'unthread:' . self::HERON . ':1793170000.001100' ];
		$this->assertSame( '#6650', $item['title'] );
		$this->assertSame( self::CONTEXT . "\nWren Osprey: ", $item['body'] );
	}

	/**
	 * Two long messages of one conversation carry titles of their own, each
	 * its first line cut to 120 characters after the friendly id, so the
	 * deduper's title norm never folds one into the other.
	 */
	public function test_two_messages_of_one_conversation_are_titled_apart(): void {
		$node   = $this->unthread();
		$this->tick( $node );
		$first  = 'The paywall redirect loops for subscribers who sign in from the Heron Gazette newsletter link on Safari 18, every time, since Tuesday';
		$second = 'Cleared the Heron session cache and rotated the paywall cookie domain to heron.example; the loop no longer reproduces on Safari 18';
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete(
			self::messages_url( self::HERON ),
			self::page(
				[
					self::message( self::HERON, '1793170000.001200', 900, [ 'text' => "{$first}\nSteps attached." ] ),
					self::message( self::HERON, '1793170000.001300', 800, [ 'text' => $second ] ),
				]
			)
		);

		$by = $this->recorded();
		$a  = $by[ 'unthread:' . self::HERON . ':1793170000.001200' ]['title'];
		$b  = $by[ 'unthread:' . self::HERON . ':1793170000.001300' ]['title'];
		$this->assertSame( '#4417 ' . \mb_substr( $first, 0, 120 ), $a );
		$this->assertSame( '#4417 ' . \mb_substr( $second, 0, 120 ), $b );
		$this->assertSame( Entity_Index::norm( '#4417 ' . \mb_substr( $first, 0, 120 ) ), Entity_Index::norm( $a ) );
		$this->assertNotSame( Entity_Index::norm( $a ), Entity_Index::norm( $b ) );
		$this->assertGreaterThanOrEqual( Deduper_Node::MIN_TITLE_NORM, \mb_strlen( Entity_Index::norm( $b ) ) );
	}

	/**
	 * The same macro reply in two tickets is titled by each ticket's
	 * friendly id, so the deduper never folds one ticket into another.
	 */
	public function test_one_macro_in_two_tickets_is_titled_apart(): void {
		$node  = $this->unthread();
		$this->tick( $node );
		$macro = 'Thanks for reaching out to Newspack support! An engineer will reply within one business day.';
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ), self::conversation( self::KESTREL, 700, [ 'friendlyId' => 5120 ] ) ] ) );

		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.001600', 900, [ 'text' => $macro ] ) ] ) );
		$this->complete( self::messages_url( self::KESTREL ), self::page( [ self::message( self::KESTREL, '1793170000.001700', 900, [ 'text' => $macro ] ) ] ) );

		$by = $this->recorded();
		$a  = $by[ 'unthread:' . self::HERON . ':1793170000.001600' ]['title'];
		$b  = $by[ 'unthread:' . self::KESTREL . ':1793170000.001700' ]['title'];
		$this->assertSame( "#4417 {$macro}", $a );
		$this->assertSame( "#5120 {$macro}", $b );
		$this->assertNotSame( Entity_Index::norm( $a ), Entity_Index::norm( $b ) );
	}

	/**
	 * Unthread's three mrkdwn escapes decode once, as Slack's do, in the
	 * title, the body and an initial message the context line falls back to.
	 */
	public function test_mrkdwn_escapes_decode_once(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600, [ 'title' => null, 'initialMessage' => [ 'ts' => '1', 'text' => 'Paywall &amp; login &lt;loop&gt;' ] ] ) ] ) );

		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.001800', 900, [ 'text' => 'Ship &lt;Heron&gt; &amp; Kestrel &amp;lt;raw&amp;gt;' ] ) ] ) );

		$item = $this->recorded()[ 'unthread:' . self::HERON . ':1793170000.001800' ];
		$this->assertSame( '#4417 Ship <Heron> & Kestrel &lt;raw&gt;', $item['title'] );
		$this->assertSame( "[in_progress] Heron Gazette: Paywall & login <loop>\nWren Osprey: Ship <Heron> & Kestrel &lt;raw&gt;", $item['body'] );
	}

	/** A messages answer naming a next cursor asks for the next page with it, until hasNext is false. */
	public function test_a_messages_answer_pages_by_its_cursor(): void {
		$node = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.000600', 900 ) ], 'bXNnOmhlcm9uLTI=' ) );
		$this->assertSame( [ 'limit' => 100, 'cursor' => 'bXNnOmhlcm9uLTI=' ], $this->body_of( self::messages_url( self::HERON ) ) );
		$this->complete( self::messages_url( self::HERON ), self::page( [ self::message( self::HERON, '1793170000.000700', 800 ) ] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::HERON ), self::messages_url( self::HERON ) ], $this->dispatched );
		$this->assertSame( [ 'unthread:' . self::HERON . ':1793170000.000600', 'unthread:' . self::HERON . ':1793170000.000700' ], $this->recorded_keys() );
	}

	/** An answer with no data list logs Unthread's message under its url and records nothing. */
	public function test_an_error_body_logs_its_url_and_records_nothing(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete( self::messages_url( self::HERON ), '{"message":"Unauthorized-6142"}' );

		$this->assertSame( [], $this->recorded() );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'unthread: Unthread answered an error for ' . self::messages_url( self::HERON ) . ': Unauthorized-6142' ) );
	}

	/** A conversations answer with no data list queues nothing and is logged. */
	public function test_an_error_body_on_the_list_queues_nothing(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, '{"data":{"id":"' . self::HERON . '"},"cursors":{"hasNext":true,"next":"eA=="}}' );

		$this->assertSame( [ self::LIST ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'unthread: Unthread answered an error for ' . self::LIST . ': no data list' ) );
	}

	/**
	 * A message missing its ts or text is logged and skipped; one whose
	 * timestamp is missing or a number records, dated by its ts.
	 */
	public function test_a_message_missing_a_field_is_logged_and_skipped(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->unthread();
		$this->tick( $node );
		$this->complete( self::LIST, self::page( [ self::conversation( self::HERON, 600 ) ] ) );

		$this->complete(
			self::messages_url( self::HERON ),
			self::page(
				[
					self::message( self::HERON, '', 900 ),
					self::message( self::HERON, '1793170000.000800', 900, [ 'text' => null ] ),
					self::message( self::HERON, '1793170000.000900', 900, [ 'timestamp' => null ] ),
					self::message( self::HERON, '1793170050.001000', 900, [ 'timestamp' => 1793170050123 ] ),
				]
			)
		);

		$this->assertSame( [ 'unthread:' . self::HERON . ':1793170000.000900', 'unthread:' . self::HERON . ':1793170050.001000' ], $this->recorded_keys() );
		$this->assertSame( [ 1793170000, 1793170050 ], \array_column( \array_values( $this->recorded() ), 'item_ts' ) );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'unthread: Unthread sent a message with no ts or text in ' . self::messages_url( self::HERON ) ) );
	}

	/** A conversation whose id is not a uuid is logged and its messages are not asked for. */
	public function test_a_conversation_whose_id_is_not_a_uuid_is_skipped(): void {
		$stderr = $this->capture_stderr();
		$node   = $this->unthread();
		$this->tick( $node );

		$this->complete( self::LIST, self::page( [ self::conversation( '../users/list', 600 ), self::conversation( self::KESTREL, 700 ) ] ) );

		$this->assertSame( [ self::LIST, self::messages_url( self::KESTREL ) ], $this->dispatched );
		$this->assertCount( 1, self::stderr_lines( $stderr, 'unthread: Unthread named a conversation whose id is not a uuid: ../users/list' ) );
	}

	/** The source declares no verbs beyond the base's. */
	public function test_it_declares_only_the_base_verbs(): void {
		$this->assertSame( [ 'set_tier', 'set_interval' ], \array_column( Unthread_Source_Node::node_schema()['commands'], 'name' ) );
	}
}

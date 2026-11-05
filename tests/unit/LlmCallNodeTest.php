<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Intelligence\Prompts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Should_Stop;

require_once __DIR__ . '/../support/drives-curl.php';

/**
 * The model call: a request queued in the node's durable frontier, sent
 * through its Curl sibling under its vault group as one chat-completions
 * POST, and answered TO its FROM with the content or the error and the
 * carry it came with.
 */
final class LlmCallNodeTest extends TestCase {
	use Drives_Curl;

	private const NAME = 'intel:llm-test';

	private const GROUP = 'ai-relay-4417';

	private const ORIGIN = 'https://relay.example';

	private const API = 'https://relay.example/wpcom/v2/ai-relay/v1';

	private const COMPLETIONS = self::API . '/chat/completions';

	private const MODEL = 'kestrel-model-9';

	private const FEATURE = 'intel-test-feature';

	private const TOKEN = 'relay-token-8821';

	private const CALLER = 'caller-7730';

	/** The invented second a stepped clock starts at. */
	private const START = 1792511137;

	private Capture_Sink_Node $caller;

	protected function setUp(): void {
		parent::setUp();
		$this->install_curl();
		$this->caller = new Capture_Sink_Node();
		$this->caller->name( self::CALLER );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		$this->uninstall_curl();
		parent::tearDown();
	}

	/**
	 * The node as the resolve topology builds it, its four verbs set.
	 *
	 * @param int $concurrency Requests in flight at once.
	 */
	private function llm( int $concurrency = 3 ): LLM_Call_Node {
		$node = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$config = $node->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_api_url', [ self::API ] );
		$config->dispatch( 'set_model', [ self::MODEL ] );
		$config->dispatch( 'set_feature', [ self::FEATURE ] );
		$config->dispatch( 'set_concurrency', [ (string) $concurrency ] );
		return $node;
	}

	/**
	 * A request as a caller sends one, filled inside a drain.
	 *
	 * @param array<string,mixed> $carry   What the answer brings back.
	 * @param bool                $backlog Whether it waits behind new work.
	 */
	private function ask( LLM_Call_Node $node, string $key, array $carry = [], bool $backlog = false, string $from = self::CALLER ): void {
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = $from;
		$request[ Message::KEY ]   = $key;
		$request[ Message::VALUE ] = [
			'prompt'  => 'extract_entities',
			'item'    => self::item( $key ),
			'inputs'  => [],
			'options' => [ 'max_tokens' => 333, 'temperature' => 0.4 ],
			'carry'   => [ 'item_key' => $key ] + $carry,
			'backlog' => $backlog,
		];
		$this->in_drain( static fn () => $node->fill( $request ) );
	}

	/**
	 * The item a test request names.
	 *
	 * @return array<string,string>
	 */
	private static function item( string $key, string $body = 'A body.' ): array {
		return [ 'item_key' => $key, 'title' => "Item {$key}", 'body' => $body ];
	}

	/** A chat-completions body whose first choice says $content. */
	private static function completion( string $content ): string {
		return (string) \json_encode( [ 'choices' => [ [ 'message' => [ 'role' => 'assistant', 'content' => $content ] ] ] ] );
	}

	/** @return list<array{0:int,1:string,2:string,3:array<string,mixed>}> Each answer the caller heard: TYPE, FROM, KEY, VALUE. */
	private function heard(): array {
		return \array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::FROM ], $m[ Message::KEY ], $m[ Message::VALUE ] ], $this->caller->captured );
	}

	/** @return list<string> The title each POST's prompt names, in dispatch order. */
	private function posted(): array {
		return \array_map( static fn ( array $opts ): string => \substr( \explode( "\n", \json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true )['messages'][1]['content'], 2 )[0], \strlen( 'Title: ' ) ), $this->sent );
	}

	/**
	 * Replace what the frontier stores for the member naming $key, as an
	 * earlier release or a corrupted write could leave it.
	 *
	 * @param list<mixed> $value The value to store.
	 */
	private function plant( string $key, array $value ): void {
		$encoded = 'igbinary' === \Newspack_Nodes\Durable_Arm::serializer() ? 'i' . \igbinary_serialize( $value ) : 's' . \serialize( $value );
		$planted = 0;
		foreach ( \glob( \Newspack_Nodes\Bootstrap::base_dir() . '/tables/*.sqlite' ) ?: [] as $file ) {
			$db   = new \PDO( "sqlite:{$file}" );
			$rows = $db->query( 'SELECT member FROM members' );
			foreach ( false === $rows ? [] : $rows->fetchAll( \PDO::FETCH_COLUMN ) as $member ) {
				if ( \str_ends_with( (string) $member, '/' . \rawurlencode( $key ) ) ) {
					$update = $db->prepare( 'UPDATE members SET "value" = ? WHERE member = ?' );
					$update->execute( [ $encoded, $member ] );
					$planted += $update->rowCount();
				}
			}
		}
		$this->assertSame( 1, $planted, "one member names {$key}" );
	}

	/**
	 * Every frontier member naming $key.
	 *
	 * @return list<string>
	 */
	private function members( string $key ): array {
		$members = [];
		foreach ( \glob( \Newspack_Nodes\Bootstrap::base_dir() . '/tables/*.sqlite' ) ?: [] as $file ) {
			$rows = ( new \PDO( "sqlite:{$file}" ) )->query( 'SELECT member FROM members' );
			foreach ( false === $rows ? [] : $rows->fetchAll( \PDO::FETCH_COLUMN ) as $member ) {
				if ( \str_ends_with( (string) $member, '/' . \rawurlencode( $key ) ) ) {
					$members[] = (string) $member;
				}
			}
		}
		return $members;
	}

	/**
	 * The value each frontier member stores whose member names $key, raw.
	 *
	 * @return list<string>
	 */
	private function stored( string $key ): array {
		$values = [];
		foreach ( \glob( \Newspack_Nodes\Bootstrap::base_dir() . '/tables/*.sqlite' ) ?: [] as $file ) {
			$rows = ( new \PDO( "sqlite:{$file}" ) )->query( 'SELECT member, "value" FROM members' );
			foreach ( false === $rows ? [] : $rows->fetchAll( \PDO::FETCH_NUM ) as [ $member, $value ] ) {
				if ( \str_ends_with( (string) $member, '/' . \rawurlencode( $key ) ) ) {
					$values[] = (string) $value;
				}
			}
		}
		return $values;
	}

	/** One POST to the completions url: model, messages and options, the lent bearer, the feature header and a 120-second deadline. */
	public function test_a_request_posts_one_completion_under_the_lent_bearer(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();

		$this->ask( $node, 'feed:wharf-31' );

		$this->assertSame( [ self::COMPLETIONS ], $this->dispatched );
		$opts = $this->sent[0];
		$this->assertTrue( $opts[ \CURLOPT_POST ] );
		$this->assertSame(
			[
				'model'       => self::MODEL,
				'messages'    => Prompts::extract_entities( self::item( 'feed:wharf-31' ) ),
				'max_tokens'  => 333,
				'temperature' => 0.4,
			],
			\json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true )
		);
		$this->assertSame( [ 'Content-Type: application/json', 'X-WPCOM-AI-Feature: ' . self::FEATURE, 'Authorization: Bearer ' . self::TOKEN ], $opts[ \CURLOPT_HTTPHEADER ] );
		$this->assertGreaterThan( 119000, $opts[ \CURLOPT_TIMEOUT_MS ] );
		$this->assertLessThanOrEqual( 120000, $opts[ \CURLOPT_TIMEOUT_MS ] );
		$this->assertSame( [], $this->caller->captured, 'nothing is answered before the model is' );
	}

	/** The content of the first choice answers TO the caller, keyed as asked, with the carry. */
	public function test_an_answer_carries_the_content_back_to_its_caller(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask( $node, 'feed:wharf-32', [ 'resolution' => [ 'attempt' => 3 ] ] );

		$this->complete_nth( 0, self::completion( '{"orgs":["Plover Wharf Trust"]}' ) );

		$this->assertSame(
			[ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-32', [ 'content' => '{"orgs":["Plover Wharf Trust"]}', 'item' => self::item( 'feed:wharf-32' ), 'inputs' => [], 'carry' => [ 'item_key' => 'feed:wharf-32', 'resolution' => [ 'attempt' => 3 ] ] ] ] ],
			$this->heard()
		);
	}

	/**
	 * A failed transfer, a status of 400 or more and a body naming no content
	 * each answer an error with the carry.
	 *
	 * @return array<string,array{0:string,1:int,2:int,3:string}>
	 */
	public static function failures(): array {
		return [
			'a bad gateway'      => [ 'bad gateway', 502, \CURLE_OK, 'HTTP 502 ' . self::COMPLETIONS ],
			'a timeout'          => [ '', 200, \CURLE_OPERATION_TIMEDOUT, 'curl error 28 (Timeout was reached) ' . self::COMPLETIONS ],
			'no content'         => [ '{"choices":[{"message":{"role":"assistant"}}]}', 200, \CURLE_OK, 'AI proxy answer names no choices[0].message.content' ],
			'not JSON at all'    => [ '<html>busy</html>', 200, \CURLE_OK, 'AI proxy answer names no choices[0].message.content' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_a_failed_call_answers_its_error( string $body, int $code, int $result, string $error ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask( $node, 'feed:wharf-33' );

		$this->complete_nth( 0, $body, $code, $result );

		$this->assertSame( [ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-33', [ 'error' => $error, 'item' => self::item( 'feed:wharf-33' ), 'inputs' => [], 'carry' => [ 'item_key' => 'feed:wharf-33' ] ] ] ], $this->heard() );
	}

	/** With no entry of its group on the api url's origin it calls nothing and answers at once that the group lends none. */
	public function test_a_group_lending_nothing_answers_unlent_at_once(): void {
		$this->lend( self::GROUP, 'https://elsewhere.example', 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();

		$this->ask( $node, 'feed:wharf-34', [ 'note' => 'kept' ] );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-34', [ 'unlent' => self::GROUP, 'item' => self::item( 'feed:wharf-34' ), 'inputs' => [], 'carry' => [ 'item_key' => 'feed:wharf-34', 'note' => 'kept' ] ] ] ], $this->heard() );
	}

	/** A request it cannot read is dropped with a line, never thrown and never answered. */
	/**
	 * A request it cannot read is refused TO its caller with whatever of its
	 * item, inputs and carry it could read, so the caller's own finish runs;
	 * none is sent.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:list<string>}>
	 */
	public static function unreadable_requests(): array {
		$good = [ 'prompt' => 'extract_entities', 'item' => self::item( 'feed:wharf-35' ), 'inputs' => [], 'options' => [], 'carry' => [ 'note' => 'n-35' ], 'backlog' => false ];
		return [
			'an unknown prompt'   => [ [ 'prompt' => 'summarize' ] + $good, 'a prompt it knows', [ 'item', 'inputs', 'carry' ] ],
			'an item not a map'   => [ [ 'item' => 'hi' ] + $good, 'an item', [ 'inputs', 'carry' ] ],
			'inputs not a map'    => [ [ 'inputs' => 'none' ] + $good, 'inputs', [ 'item', 'carry' ] ],
			'no carry'            => [ \array_diff_key( $good, [ 'carry' => true ] ), 'a carry', [ 'item', 'inputs' ] ],
			'no backlog flag'     => [ [ 'backlog' => 'no' ] + $good, 'a backlog flag', [ 'item', 'inputs', 'carry' ] ],
			'options not a map'   => [ [ 'options' => 3 ] + $good, 'options of only an integer max_tokens and a numeric temperature', [ 'item', 'inputs', 'carry' ] ],
			'a model override'    => [ [ 'options' => [ 'model' => 'other-model-2' ] ] + $good, 'options of only an integer max_tokens and a numeric temperature', [ 'item', 'inputs', 'carry' ] ],
			'a string max_tokens' => [ [ 'options' => [ 'max_tokens' => '300' ] ] + $good, 'options of only an integer max_tokens and a numeric temperature', [ 'item', 'inputs', 'carry' ] ],
			'a string temperature' => [ [ 'options' => [ 'temperature' => 'warm' ] ] + $good, 'options of only an integer max_tokens and a numeric temperature', [ 'item', 'inputs', 'carry' ] ],
			'a stream option'     => [ [ 'options' => [ 'stream' => true ] ] + $good, 'options of only an integer max_tokens and a numeric temperature', [ 'item', 'inputs', 'carry' ] ],
		];
	}

	/**
	 * @param array<string,mixed> $value The request's VALUE.
	 * @param string              $lacks What the refusal says it lacks.
	 * @param list<string>        $kept  What the refusal hands back.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unreadable_requests' )]
	public function test_a_request_it_cannot_read_is_refused_to_its_caller( array $value, string $lacks, array $kept ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node                      = $this->llm();
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = self::CALLER;
		$request[ Message::KEY ]   = 'feed:wharf-35';
		$request[ Message::VALUE ] = $value;

		$this->in_drain( static fn () => $node->fill( $request ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertCount( 1, $this->caller->captured );
		$answer = $this->caller->captured[0];
		$this->assertSame( [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-35' ], [ $answer[ Message::TYPE ], $answer[ Message::FROM ], $answer[ Message::KEY ] ] );
		$this->assertSame( self::NAME . ': a request it cannot read: it carries no ' . $lacks, $answer[ Message::VALUE ]['error'] );
		$this->assertSame( [ 'error', ...$kept ], \array_keys( $answer[ Message::VALUE ] ) );
	}

	/** A request whose FROM names no caller cannot be refused to anyone, so it is logged and dropped. */
	public function test_a_request_from_no_caller_is_logged(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node                      = $this->llm();
		$stderr                    = $this->capture_stderr();
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::KEY ]   = "feed:\e[2Jno-from";
		$request[ Message::VALUE ] = [ 'prompt' => 'summarize' ];

		$this->in_drain( static fn () => $node->fill( $request ) );

		$this->assertSame( [ [], [] ], [ $this->dispatched, $this->caller->captured ] );
		$lines = self::stderr_lines( $stderr, 'dropping a request from no caller: ' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\e", $lines[0] );
	}

	/**
	 * A real extractor whose request the node refuses, here for an option it
	 * does not allow, hears the refusal as a failed call and parks its retry.
	 */
	public function test_a_refused_request_is_parked_by_its_caller(): void {
		[ $extractor ] = $this->extractor_on_llm( 3 );
		$later         = new Capture_Sink_Node();
		$later->name( 'intel:later' );
		$resolution                = self::resolution( 'feed:heron-6', 'Heron Dispatch closes', '' );
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = 'extractor';
		$request[ Message::KEY ]   = 'feed:heron-6';
		$request[ Message::VALUE ] = [
			'prompt'  => 'extract',
			'item'    => $resolution[ Message::VALUE ]['item'],
			'inputs'  => [ 'topics' => [], 'profile' => '', 'audience' => '' ],
			'options' => [ 'max_tokens' => 800, 'stream' => true ],
			'carry'   => [ 'resolution' => \array_diff_key( $resolution[ Message::VALUE ], [ 'item' => true ] ) ],
			'backlog' => false,
		];
		$node = Core::node( self::NAME );
		\assert( null !== $node );

		$this->in_drain( static fn () => $node->fill( $request ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ 'feed:heron-6', 1 ] ], \array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['item_key'], $m[ Message::VALUE ]['attempt'] ], $later->captured ) );
	}

	/** A forged answer from another node, keyed as a member in flight, settles nothing; Curl's own answer still lands. */
	public function test_a_forged_answer_settles_nothing(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask( $node, 'feed:forge-1' );
		$members = $this->members( 'feed:forge-1' );
		$this->assertCount( 1, $members );
		$stderr                   = $this->capture_stderr();
		$forged                   = Message::new_message();
		$forged[ Message::TYPE ]  = Message::TM_ERROR;
		$forged[ Message::FROM ]  = "forger-\e[2J9";
		$forged[ Message::KEY ]   = $members[0];
		$forged[ Message::VALUE ] = 'HTTP 500 forged';

		$this->in_drain( static fn () => $node->fill( $forged ) );

		$this->assertSame( [], $this->caller->captured, 'the forgery settles and answers nothing' );
		$lines = self::stderr_lines( $stderr, 'dropping an answer that is not from its Curl: ' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\e", $lines[0] );
		$this->complete_nth( 0, self::completion( 'the real one' ) );
		$this->assertSame( [ 'the real one' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['content'], $this->caller->captured ) );
	}

	/** At most `set_concurrency` calls run at once, and the window refills once half of it is free. */
	public function test_the_window_refills_once_half_is_free(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 4 );
		foreach ( [ 'a', 'b', 'c', 'd', 'e', 'f' ] as $key ) {
			$this->ask( $node, "feed:window-{$key}" );
		}
		$this->assertSame( [ 'Item feed:window-a', 'Item feed:window-b', 'Item feed:window-c' ], $this->posted(), 'with three in flight one free slot of four is not half' );

		$this->complete_nth( 0, self::completion( 'one' ) );
		$this->assertSame( [ 'Item feed:window-d', 'Item feed:window-e' ], \array_slice( $this->posted(), 3 ) );

		$this->complete_nth( 1, self::completion( 'two' ) );
		$this->assertCount( 5, $this->sent );
		$this->complete_nth( 2, self::completion( 'three' ) );
		$this->assertSame( [ 'Item feed:window-f' ], \array_slice( $this->posted(), 5 ) );
	}

	/** A request recorded now goes ahead of every backlog request queued before it. */
	public function test_new_work_is_sent_ahead_of_a_backlog(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:old-1', [], true );
		$this->ask( $node, 'feed:old-2', [], true );
		$this->ask( $node, 'feed:old-3', [], true );
		$this->ask( $node, 'feed:new-1' );

		$this->complete_nth( 0, self::completion( 'x' ) );
		$this->complete_nth( 1, self::completion( 'y' ) );

		$this->assertSame( [ 'Item feed:old-1', 'Item feed:new-1', 'Item feed:old-2' ], $this->posted() );
	}

	/** Within a priority, requests go out in the order they arrived, whatever their caller or key. */
	public function test_requests_go_out_in_arrival_order(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$other = new Capture_Sink_Node();
		$other->name( 'caller-7731' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:first-0' );
		$this->ask( $node, 'feed:zebra-1' );
		$this->ask( $node, 'feed:alpha-2', [], false, 'caller-7731' );
		$this->ask( $node, 'feed:mid-3' );

		foreach ( [ 0, 1, 2 ] as $n ) {
			$this->complete_nth( $n, self::completion( 'ok' ) );
		}

		$this->assertSame( [ 'Item feed:first-0', 'Item feed:zebra-1', 'Item feed:alpha-2', 'Item feed:mid-3' ], $this->posted() );
	}

	/** Two requests for one item in flight at once each get their own answer. */
	public function test_two_requests_for_one_item_are_each_answered(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 2 );
		$this->ask( $node, 'feed:twice-1', [ 'who' => 'first' ] );
		$this->ask( $node, 'feed:twice-1', [ 'who' => 'second' ] );
		$this->assertCount( 2, $this->sent );

		$this->complete_nth( 1, self::completion( 'answer two' ) );
		$this->complete_nth( 0, self::completion( 'answer one' ) );

		$this->assertSame(
			[ [ 'answer two', 'second' ], [ 'answer one', 'first' ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['content'], $m[ Message::VALUE ]['carry']['who'] ], $this->caller->captured )
		);
	}

	/** A request Curl throws on is settled and answered as an error, and the next one still goes out. */
	public function test_a_request_curl_throws_on_is_answered_and_the_next_goes_out(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node                                = $this->llm( 1 );
		$this->refusing[ self::COMPLETIONS ] = true;

		$this->ask( $node, 'feed:refused-1' );
		$this->ask( $node, 'feed:after-2' );

		$this->assertSame( [ 'error' => self::NAME . ': a stored request could not be sent: dispatch-down-5108 ' . self::COMPLETIONS ], \array_intersect_key( $this->caller->captured[0][ Message::VALUE ], [ 'error' => true ] ) );
		$this->assertSame( [ self::COMPLETIONS, self::COMPLETIONS ], $this->dispatched );
		$this->assertSame( [], $this->stored( 'feed:refused-1' ), 'it is settled, not left in flight' );
	}

	/**
	 * A stored member that will not build, an unknown prompt or a row of an
	 * older shape, is settled and answered as an error, and the one behind it
	 * goes out.
	 *
	 * @return array<string,array{0:list<mixed>,1:string}>
	 */
	public static function poison(): array {
		return [
			'an unknown prompt' => [ [ self::CALLER, 'feed:poison-2', 'summarize', self::item( 'feed:poison-2' ), [], [], [] ], 'no prompt: summarize' ],
			'an old-shape row'  => [ [ self::CALLER, 'feed:poison-2', '{"model":"kestrel-old"}', [ 'item_key' => 'feed:poison-2' ] ], 'no prompt: {"model":"kestrel-old"}' ],
		];
	}

	/**
	 * @param list<mixed> $row  The stored value planted.
	 * @param string      $why  What the answer's error ends with.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'poison' )]
	public function test_a_poison_member_is_answered_and_the_next_goes_out( array $row, string $why ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:ahead-1' );
		$this->ask( $node, 'feed:poison-2' );
		$this->ask( $node, 'feed:good-3' );
		$this->plant( 'feed:poison-2', $row );
		$this->capture_stderr();

		$this->complete_nth( 0, self::completion( 'ok' ) );

		$this->assertSame( [ 'Item feed:ahead-1', 'Item feed:good-3' ], $this->posted() );
		$this->assertSame( [ 'feed:ahead-1', 'feed:poison-2' ], \array_column( $this->caller->captured, Message::KEY ) );
		$this->assertSame( self::NAME . ': a stored request could not be sent: ' . $why, $this->caller->captured[1][ Message::VALUE ]['error'] );
		$this->assertSame( [], $this->stored( 'feed:poison-2' ) );
	}

	/** A Table that will not answer as the tick sends is logged, never thrown into the loop; the next tick sends. */
	public function test_a_send_that_throws_on_the_tick_is_logged_and_retried(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:ahead-1' );
		$this->ask( $node, 'feed:behind-2' );
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		\assert( null !== $interpreter );
		$broken = new class( $interpreter ) extends Node {
			public function __construct( private Node $next ) {
				parent::__construct();
			}

			public function fill( array $message ): void {
				if ( \str_contains( (string) $message[ Message::TO ], ':frontier' ) ) {
					throw new \RuntimeException( 'table down 4471' );
				}
				$this->next->fill( $message );
			}
		};
		$node->sink( $broken );
		$stderr = $this->capture_stderr();

		$this->complete_nth( 0, self::completion( 'ok' ) );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'a send failed; trying again at the next tick: table down 4471' ) );
		$node->sink( $interpreter );
		$this->in_drain( static fn () => $node->fire_cb() );
		$this->assertSame( [ 'Item feed:ahead-1', 'Item feed:behind-2' ], $this->posted() );
	}

	/** Two callers asking about one key are two requests, each answered to its own caller. */
	public function test_two_callers_on_one_key_are_answered_apart(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$other = new Capture_Sink_Node();
		$other->name( 'caller-7731' );
		$node = $this->llm();
		$this->ask( $node, 'feed:shared-1', [ 'who' => 'first' ] );
		$this->ask( $node, 'feed:shared-1', [ 'who' => 'second' ], false, 'caller-7731' );

		$this->complete_nth( 1, self::completion( 'for the second' ) );
		$this->complete_nth( 0, self::completion( 'for the first' ) );

		$this->assertSame( [ 'for the first', 'first' ], [ $this->caller->captured[0][ Message::VALUE ]['content'], $this->caller->captured[0][ Message::VALUE ]['carry']['who'] ] );
		$this->assertSame( [ 'for the second', 'second' ], [ $other->captured[0][ Message::VALUE ]['content'], $other->captured[0][ Message::VALUE ]['carry']['who'] ] );
	}

	/** A caller whose answer throws is logged, and the node goes on sending the next request. */
	public function test_a_caller_that_throws_on_its_answer_stops_nothing(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$broken = new class() extends Node {
			public function fill( array $message ): void {
				throw new \RuntimeException( "caller broke \e[2Jon its answer" );
			}
		};
		$broken->name( 'caller-broken' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:break-1', [], false, 'caller-broken' );
		$this->ask( $node, 'feed:break-2' );
		$stderr = $this->capture_stderr();

		$this->complete_nth( 0, self::completion( 'lost' ) );

		$this->assertSame( [ 'Item feed:break-1', 'Item feed:break-2' ], $this->posted() );
		$lines = self::stderr_lines( $stderr, 'answering feed:break-1 failed: caller broke' );
		$this->assertCount( 1, $lines );
		$this->assertStringNotContainsString( "\e", $lines[0] );
	}

	/** A cooperative stop raised by a caller's answer propagates, and sends nothing more. */
	public function test_a_cooperative_stop_on_an_answer_propagates(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$stopping = new class() extends Node {
			public function fill( array $message ): void {
				throw new Worker_Should_Stop( 'max_runtime 595 reached' );
			}
		};
		$stopping->name( 'caller-stopping' );
		$node = $this->llm( 1 );
		$this->ask( $node, 'feed:stop-1', [], false, 'caller-stopping' );
		$this->ask( $node, 'feed:stop-2' );

		try {
			$this->complete_nth( 0, self::completion( 'late' ) );
			$this->fail( 'the stop propagates' );
		} catch ( Worker_Should_Stop $stop ) {
			$this->assertSame( 'max_runtime 595 reached', $stop->getMessage() );
		}
		$this->assertSame( [ 'Item feed:stop-1' ], $this->posted(), 'a stop starts no new POST' );
	}

	/** What a stopped process left in flight is sent again by the next one; what it answered and swept is not. */
	public function test_a_successor_resends_only_what_was_never_answered(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 2 );
		$this->ask( $node, 'feed:keep-1' );
		$this->ask( $node, 'feed:keep-2' );
		$this->complete_nth( 0, self::completion( 'done' ) );
		$node->shutdown_sweep();
		$node->remove_node();

		$successor = $this->llm( 2 );
		$this->in_drain( static fn () => $successor->fire_cb() );

		$this->assertSame( [ 'Item feed:keep-1', 'Item feed:keep-2', 'Item feed:keep-2' ], $this->posted() );
	}

	/** A body past the substrate's default cap still goes, up to the node's raised one. */
	public function test_a_body_past_64_kib_is_sent(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node                      = $this->llm();
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = self::CALLER;
		$request[ Message::KEY ]   = 'feed:long-1';
		$request[ Message::VALUE ] = [ 'prompt' => 'extract_entities', 'item' => self::item( 'feed:long-1', \str_repeat( 'Kestrel ', 60000 ) ), 'inputs' => [], 'options' => [], 'carry' => [], 'backlog' => false ];

		$this->in_drain( static fn () => $node->fill( $request ) );

		$this->assertGreaterThan( 400000, \strlen( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ] ) );
		$this->assertSame( [], $this->caller->captured );
	}

	/** A runtime set_api_url reaches the next request. */
	public function test_a_runtime_api_url_change_reaches_the_next_request(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask( $node, 'feed:url-1' );
		$node->interpreter()?->dispatch( 'set_api_url', [ self::ORIGIN . '/second/v2' ] );
		$this->ask( $node, 'feed:url-2' );

		$this->assertSame( [ self::COMPLETIONS, self::ORIGIN . '/second/v2/chat/completions' ], $this->dispatched );
	}

	/** The verbs replay through dump_config; a concurrency outside Curl's window is refused. */
	public function test_the_verbs_dump_and_a_bad_concurrency_is_refused(): void {
		$node = $this->llm( 5 );

		$this->assertSame(
			"make_node LLM_Call intel:llm-test ai-relay-4417\n"
			. 'command_node intel:llm-test:config set_api_url ' . self::API . "\n"
			. 'command_node intel:llm-test:config set_model ' . self::MODEL . "\n"
			. 'command_node intel:llm-test:config set_feature ' . self::FEATURE . "\n"
			. "command_node intel:llm-test:config set_concurrency 5\n",
			$node->dump_config()
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "concurrency wants a whole number from 1 to 16, got '17'" );
		$node->interpreter()?->dispatch( 'set_concurrency', [ '17' ] );
	}

	/** A request made before set_api_url, set_model and set_feature answers an error naming them. */
	public function test_an_unconfigured_node_answers_an_error(): void {
		$node = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );

		$this->ask( $node, 'feed:bare-1' );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ 'error' => self::NAME . ': set_api_url, set_model and set_feature before a call', 'item' => self::item( 'feed:bare-1' ), 'inputs' => [], 'carry' => [ 'item_key' => 'feed:bare-1' ] ], $this->caller->captured[0][ Message::VALUE ] );
	}

	/**
	 * An extractor's request travels through the Router to the node, which
	 * answers in a later drain, once the proxy does, and the extraction lands
	 * on the writer then.
	 */
	public function test_an_extraction_is_finished_by_the_answer_a_later_drain_brings(): void {
		[ $extractor, $writer, $deduper ] = $this->extractor_on_llm( 3 );
		$resolution                       = self::resolution( 'feed:heron-4', 'Heron Dispatch opens a bureau', '' );

		$this->in_drain( static fn () => $extractor->fill( $resolution ) );
		$this->assertSame( [ [], [ self::COMPLETIONS ] ], [ $writer->captured, $this->dispatched ], 'asked, and nothing written before the answer' );
		$this->assertStringContainsString( 'Heron Dispatch opens a bureau', (string) $this->sent[0][ \CURLOPT_POSTFIELDS ] );

		$this->complete_nth( 0, self::completion( '{"summary":"Heron Dispatch opens a coastal bureau.","kind":"launch","relevance":6,"importance":4}' ) );

		$this->assertSame( [ [ 'extraction_recorded', 'Heron Dispatch opens a coastal bureau.', 'launch' ], [ 'subjects_extracted', null, null ] ], \array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['event'], $m[ Message::VALUE ]['summary'] ?? null, $m[ Message::VALUE ]['kind'] ?? null ], $writer->captured ) );
		$this->assertSame( [ 'feed:heron-4' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item']['item_key'], $deduper->captured ) );
	}

	/**
	 * An extraction waiting its turn stores the item's body once, as the
	 * item, never again in the carry or a built prompt; the POST is built as
	 * it is sent.
	 */
	public function test_a_queued_extraction_stores_the_item_body_once(): void {
		[ $extractor ] = $this->extractor_on_llm( 1 );
		$this->ask( Core::node( self::NAME ), 'feed:ahead-1' );
		$resolution = self::resolution( 'feed:heron-5', 'Heron Dispatch hires', 'Plover wharf ledger body 5501.' );

		$this->in_drain( static fn () => $extractor->fill( $resolution ) );

		$this->assertCount( 1, $this->sent, 'it waits behind the call in flight' );
		$stored = $this->stored( 'feed:heron-5' );
		$this->assertCount( 1, $stored );
		$this->assertSame( 1, \substr_count( $stored[0], 'Plover wharf ledger body 5501.' ) );

		$this->complete_nth( 0, self::completion( 'done' ) );

		$this->assertStringContainsString( 'Plover wharf ledger body 5501.', (string) $this->sent[1][ \CURLOPT_POSTFIELDS ] );
	}

	/**
	 * A queued extraction stores no themes and offers its neighbours' as the
	 * projection holds them when it is sent, so a request queued before a
	 * similar item was themed offers that theme when it goes out.
	 */
	public function test_a_queued_extraction_offers_the_themes_current_when_it_is_sent(): void {
		[ $extractor ] = $this->extractor_on_llm( 1 );
		$this->ask( Core::node( self::NAME ), 'feed:ahead-2' );

		$this->in_drain( static fn () => $extractor->fill( self::resolution( 'feed:heron-6', 'Heron Dispatch mail stops', 'Mail stopped at the wharf.' ) ) );

		$this->assertCount( 1, $this->sent, 'it waits behind the call in flight' );
		$this->assertStringNotContainsString( 'themes', $this->stored( 'feed:heron-6' )[0] );
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): array => [ [ 'theme' => 'heron dispatch mail halted', 'example' => 'Heron Dispatch mail halts' ] ];

		$this->complete_nth( 0, self::completion( 'done' ) );

		$messages = \json_decode( (string) $this->sent[1][ \CURLOPT_POSTFIELDS ], true )['messages'];
		$this->assertStringEndsWith( "\n</item>\n<themes>\n[{\"theme\":\"heron dispatch mail halted\",\"example\":\"Heron Dispatch mail halts\"}]\n</themes>", $messages[1]['content'] );
		$this->assertStringNotContainsString( 'heron dispatch mail halted', $messages[0]['content'] );
	}

	/**
	 * A real extractor asking this node through the Router, with a writer and
	 * a deduper to capture what it sends.
	 *
	 * @return array{0:Extractor_Node,1:Capture_Sink_Node,2:Capture_Sink_Node}
	 */
	private function extractor_on_llm( int $concurrency ): array {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		Topology_Registry::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		( new \ReflectionProperty( Entity_Index::class, 'current' ) )->setValue( null, null );
		$writer = new Capture_Sink_Node();
		$writer->name( 'intel:writer' );
		$deduper = new Capture_Sink_Node();
		$deduper->name( 'deduper' );
		$this->llm( $concurrency );
		$extractor = new Extractor_Node();
		$extractor->name( 'extractor' );
		$extractor->arguments( [ 'intel:writer', 'intel:later', self::NAME ] );
		$extractor->target( 'deduper' );
		$extractor->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$this->capture_stderr();
		return [ $extractor, $writer, $deduper ];
	}

	/**
	 * A resolution as the resolver forwards one, linking one entity.
	 *
	 * @return array<int,mixed>
	 */
	private static function resolution( string $key, string $title, string $body ): array {
		$resolution                   = Message::new_message();
		$resolution[ Message::TYPE ]  = Message::TM_STRUCT;
		$resolution[ Message::FROM ]  = 'resolver';
		$resolution[ Message::VALUE ] = [
			'item'      => [ 'item_key' => $key, 'source' => 'feed', 'tier' => 'public', 'url' => 'https://heron.example/a', 'title' => $title, 'body' => $body, 'item_ts' => 1794000000, 'retrieved_ts' => 1794000500 ],
			'links'     => [ [ 'entity_key' => 'organization:heron-dispatch', 'role' => 'subject', 'method' => 'name', 'confidence' => 1.0, 'state' => 'asserted' ] ],
			'proposals' => [],
			'extracted' => false,
			'attempt'   => 0,
			'extract'   => false,
		];
		return $resolution;
	}

	/**
	 * A clock the test moves, from START; the loop reads it as it drains.
	 *
	 * @return \Closure(float): void Sets the clock to that many seconds past START.
	 */
	private function stepped_clock(): \Closure {
		$at          = (float) self::START;
		Core::$clock = static function () use ( &$at ): float {
			return $at;
		};
		return static function ( float $seconds ) use ( &$at ): void {
			$at = self::START + $seconds;
		};
	}

	/** One Router tick of the node, inside a drain. */
	private function tick( LLM_Call_Node $node ): void {
		$this->in_drain( static fn () => $node->fire_cb() );
	}

	/** What the node last published at the worker partition. */
	private function queue_status(): ?array {
		return LLM_Call_Node::read_status( self::NAME, self::PARTITION );
	}

	/**
	 * The snapshot the node publishes: its queue, or null, and the second it
	 * was written, `$seconds` past START.
	 *
	 * @param array{0:int,1:int,2:int}|null $queue [ new, backlog, in_flight ].
	 * @return array<string,mixed>
	 */
	private static function snap( ?array $queue, int $seconds ): array {
		return [
			'queue' => null === $queue ? null : [ 'new' => $queue[0], 'backlog' => $queue[1], 'in_flight' => $queue[2] ],
			'at'    => self::START + $seconds,
		];
	}

	/**
	 * Four backlog requests and two new, one window: one backlog request in
	 * flight, three backlog and two new waiting.
	 */
	private function queued_llm(): LLM_Call_Node {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 1 );
		foreach ( [ 'b1', 'b2', 'b3', 'b4' ] as $key ) {
			$this->ask( $node, "feed:old-{$key}", [], true );
		}
		$this->ask( $node, 'feed:new-n1' );
		$this->ask( $node, 'feed:new-n2' );
		return $node;
	}

	/**
	 * A sink in front of the interpreter that drops every string request
	 * starting with `$verb`, as a Table that never answers it.
	 */
	private static function dropping( string $verb ): Node {
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		\assert( null !== $interpreter );
		return new class( $interpreter, $verb ) extends Node {
			public function __construct( private Node $next, private string $verb ) {
				parent::__construct();
			}

			public function fill( array $message ): void {
				if ( \is_string( $message[ Message::VALUE ] ) && \str_starts_with( $message[ Message::VALUE ], "{$this->verb} " ) ) {
					return;
				}
				$this->next->fill( $message );
			}
		};
	}

	/** The tick publishes the calls waiting by priority class and those in flight, and when it wrote them. */
	public function test_the_tick_publishes_the_queue_by_priority_and_in_flight(): void {
		$this->stepped_clock();
		$this->publishing();
		$node = $this->queued_llm();
		$this->assertNull( $this->queue_status(), 'nothing publishes on a request' );

		$this->tick( $node );

		$this->assertSame( self::snap( [ 2, 3, 1 ], 0 ), $this->queue_status() );
	}

	/** A settle and the take it frees are counted at the next tick past the interval. */
	public function test_the_counts_follow_a_settle_and_a_take(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node = $this->queued_llm();
		$this->tick( $node );

		$this->complete_nth( 0, self::completion( 'settled' ) );
		$step( 5 );
		$this->tick( $node );

		$this->assertSame( [ 'Item feed:old-b1', 'Item feed:new-n1' ], $this->posted() );
		$this->assertSame( self::snap( [ 1, 3, 1 ], 5 ), $this->queue_status() );

		$this->ask( $node, 'feed:new-n3' );
		$step( 10 );
		$this->tick( $node );

		$this->assertSame( self::snap( [ 2, 3, 1 ], 10 ), $this->queue_status() );
	}

	/** A settle alone, with nothing left to take, is counted at the next tick past the interval. */
	public function test_a_settle_alone_is_published(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm( 2 );
		$this->ask( $node, 'feed:lone-7' );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 0, 0, 1 ], 0 ), $this->queue_status() );

		$this->complete_nth( 0, self::completion( 'alone' ) );
		$step( 6 );
		$this->tick( $node );

		$this->assertSame( self::snap( [ 0, 0, 0 ], 6 ), $this->queue_status() );
	}

	/** A take the tick retries, after the Table refused the request's own, is counted at the next tick past the interval. */
	public function test_a_take_alone_is_published(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node        = $this->llm( 2 );
		$interpreter = $node->sink();
		$this->ask( $node, 'feed:sent-2' );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 0, 0, 1 ], 0 ), $this->queue_status() );

		$node->sink( self::dropping( 'SMOVE' ) );
		$this->ask( $node, 'feed:held-3', [], true );
		$step( 5 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 0, 1, 1 ], 5 ), $this->queue_status() );

		$node->sink( $interpreter );
		$step( 11 );
		$this->tick( $node );

		$this->assertSame( [ 'Item feed:sent-2', 'Item feed:held-3' ], $this->posted() );
		$this->assertSame( self::snap( [ 0, 0, 2 ], 11 ), $this->queue_status() );
	}

	/** With nothing queued, taken or settled, a tick inside the heartbeat counts and writes nothing. */
	public function test_a_tick_with_no_change_publishes_nothing_inside_the_heartbeat(): void {
		$step = $this->stepped_clock();
		$memd = $this->publishing();
		$node = $this->queued_llm();
		$this->tick( $node );
		$memd->set( LLM_Call_Node::status_key( self::NAME, self::PARTITION ), [ 'sentinel' => 4471 ], 600 );

		$step( LLM_Call_Node::STATUS_HEARTBEAT_S - 1 );
		$this->tick( $node );

		$this->assertSame( [ 'sentinel' => 4471 ], $this->queue_status() );
	}

	/** An unmoving queue is counted and written again once a heartbeat has passed, so its `at` stays fresh while the worker lives. */
	public function test_an_unmoving_queue_is_republished_on_the_heartbeat(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node = $this->queued_llm();
		$this->tick( $node );

		$step( LLM_Call_Node::STATUS_HEARTBEAT_S );
		$this->tick( $node );

		$this->assertSame( self::snap( [ 2, 3, 1 ], LLM_Call_Node::STATUS_HEARTBEAT_S ), $this->queue_status() );
	}

	/** The snapshot outlives a worker that stopped by a few heartbeats, not by days. */
	public function test_the_snapshot_lives_three_heartbeats(): void {
		$this->stepped_clock();
		$memd = $this->publishing();
		$node = $this->queued_llm();

		$this->tick( $node );

		$this->assertSame( 3 * LLM_Call_Node::STATUS_HEARTBEAT_S, \array_column( $memd->sets, 'ttl', 'key' )[ LLM_Call_Node::status_key( self::NAME, self::PARTITION ) ] );
	}

	/** A change inside the interval waits for the tick past it. */
	public function test_a_change_inside_the_interval_waits_for_the_next(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node = $this->queued_llm();
		$this->tick( $node );

		$this->ask( $node, 'feed:old-b5', [], true );
		$step( 4 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 2, 3, 1 ], 0 ), $this->queue_status(), 'inside the interval' );

		$step( 5 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 2, 4, 1 ], 5 ), $this->queue_status() );
	}

	/** A clock stepped back counts as the interval having passed, so the throttle never stalls. */
	public function test_a_clock_stepped_back_does_not_stall_the_throttle(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node = $this->queued_llm();
		$this->tick( $node );

		$this->ask( $node, 'feed:new-n4' );
		$step( -90 );
		$this->tick( $node );

		$this->assertSame( self::snap( [ 3, 3, 1 ], -90 ), $this->queue_status() );
	}

	/** A count the Table does not answer publishes a null queue, never zero, and is counted again at the next tick past the interval. */
	public function test_a_count_the_table_does_not_answer_publishes_null(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node        = $this->queued_llm();
		$interpreter = $node->sink();
		$node->sink( self::dropping( 'SCARD' ) );

		$this->tick( $node );
		$this->assertSame( self::snap( null, 0 ), $this->queue_status() );

		$node->sink( $interpreter );
		$step( 5 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 2, 3, 1 ], 5 ), $this->queue_status() );
	}

	/** A write that did not land, the cache down, keeps the change owed, so a later tick publishes it with nothing new. */
	public function test_a_publish_with_the_cache_down_is_tried_again(): void {
		$step = $this->stepped_clock();
		$memd = $this->publishing();
		$node = $this->queued_llm();
		Core::$memd = null;

		$this->tick( $node );

		Core::$memd = $memd;
		$this->assertNull( $this->queue_status() );
		$step( 5 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 2, 3, 1 ], 5 ), $this->queue_status() );
	}

	/** A count that throws is logged and tried again at the next tick, never thrown into the loop. */
	public function test_a_count_that_throws_is_logged_and_tried_at_the_next_tick(): void {
		$step = $this->stepped_clock();
		$this->publishing();
		$node        = $this->queued_llm();
		$interpreter = $node->sink();
		\assert( null !== $interpreter );
		$node->sink(
			new class( $interpreter ) extends Node {
				public function __construct( private Node $next ) {
					parent::__construct();
				}

				public function fill( array $message ): void {
					if ( \is_string( $message[ Message::VALUE ] ) && \str_starts_with( $message[ Message::VALUE ], 'SCARD ' ) ) {
						throw new \RuntimeException( 'table down 5583' );
					}
					$this->next->fill( $message );
				}
			}
		);
		$stderr = $this->capture_stderr();

		$this->tick( $node );

		$this->assertCount( 1, self::stderr_lines( $stderr, 'publishing the queue failed; trying again at the next tick: table down 5583' ) );
		$this->assertNull( $this->queue_status() );
		$node->sink( $interpreter );
		$step( 1 );
		$this->tick( $node );
		$this->assertSame( self::snap( [ 2, 3, 1 ], 1 ), $this->queue_status() );
	}

	/** A cooperative stop met while counting propagates out of the tick. */
	public function test_a_stop_while_counting_propagates(): void {
		$this->stepped_clock();
		$this->publishing();
		$node        = $this->queued_llm();
		$interpreter = $node->sink();
		\assert( null !== $interpreter );
		$node->sink(
			new class( $interpreter ) extends Node {
				public function __construct( private Node $next ) {
					parent::__construct();
				}

				public function fill( array $message ): void {
					if ( \is_string( $message[ Message::VALUE ] ) && \str_starts_with( $message[ Message::VALUE ], 'SCARD ' ) ) {
						throw new Worker_Should_Stop( 'max_runtime 595 reached' );
					}
					$this->next->fill( $message );
				}
			}
		);

		$this->expectException( Worker_Should_Stop::class );
		$this->tick( $node );
	}

	/** Its group is required, and it declares the frontier Table it owns. */
	public function test_a_line_without_a_group_is_refused_and_the_frontier_is_declared(): void {
		$this->assertSame( [ 'frontier' => 'Table' ], LLM_Call_Node::node_schema()['owns'] );
		$this->assertSame( [ 'namespace' => 'intel:llm', 'ttl' => '31536000', 'backend' => 'sqlite' ], LLM_Call_Node::owned_table( 'frontier', 'intel:llm', [ 'ai-proxy' ] ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Missing required argument: vault_group' );
		( new LLM_Call_Node() )->arguments( [] );
	}
}

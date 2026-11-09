<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Extractor_Node;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\LLM_Call_Node;
use Newspack_Intelligence\Operator;
use Newspack_Intelligence\Prompts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\Helpers\InMemoryMemcached;
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

	private const DECISION_MODEL = 'heron-decide-3';

	private const SYSTEMONE = self::API . '/systemone';

	private const FEATURE = 'intel-test-feature';

	private const TOKEN = 'relay-token-8821';

	/** The operator the cases configure, apart from the generic default. */
	private const OPERATOR = 'Quillmere Cooperative';

	/** What the cases say it tracks, apart from the generic default. */
	private const TRACKS = 'the heron wire, the kite desks Quillmere supplies, and coastal birding';

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
	 * The node as the resolve topology builds it, its transport set.
	 *
	 * @param int    $concurrency Requests in flight at once.
	 * @param string $protocol    The wire its chat calls speak.
	 * @param string $decisions   Where its decisions go: systemone or chat.
	 */
	private function llm( int $concurrency = 3, string $protocol = 'ai-proxy', string $decisions = 'systemone' ): LLM_Call_Node {
		$node = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$config = $node->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_protocol', [ $protocol ] );
		$config->dispatch( 'set_decision_protocol', [ $decisions ] );
		$config->dispatch( 'set_api_url', [ self::API ] );
		$config->dispatch( 'set_model', [ self::MODEL ] );
		$config->dispatch( 'set_decision_model', [ self::DECISION_MODEL ] );
		$config->dispatch( 'set_feature', [ self::FEATURE ] );
		$config->dispatch( 'set_concurrency', [ (string) $concurrency ] );
		return $node;
	}

	/**
	 * An extract review as the extractor asks one, filled inside a drain.
	 *
	 * @param array<string,mixed> $inputs  The review's inputs.
	 * @param array<string,mixed> $item    The item.
	 * @param array<string,mixed> $options The request's options.
	 */
	private function ask_decision( LLM_Call_Node $node, string $key, array $inputs, array $item, array $options = [] ): void {
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = self::CALLER;
		$request[ Message::KEY ]   = $key;
		$request[ Message::VALUE ] = [
			'prompt'  => Prompts::EXTRACT_REVIEW,
			'item'    => $item,
			'inputs'  => $inputs,
			'options' => $options,
			'carry'   => [ 'content' => 'reply-' . $key ],
			'backlog' => true,
		];
		$this->in_drain( static fn () => $node->fill( $request ) );
	}

	/** Two themes a review offers. */
	private const OFFERED = [ [ 'theme' => 'regional transit grant', 'example' => 'Heron Weekly wins a transit grant' ], [ 'theme' => 'transit funding round', 'example' => 'Gull Post funds two routes' ] ];

	/**
	 * A systemone body answering a review: the publisher's noul when given,
	 * and the theme choice.
	 */
	private static function reviewed( ?float $noul, mixed $choice, mixed $confidence ): string {
		$answers = null === $noul ? [] : [ 'about_publisher' => [ 'type' => 'noul', 'noul' => $noul ] ];
		$answers['theme_choice'] = \array_filter( [ 'type' => 'choice', 'probabilities' => [ 't1' => 0.05, 't2' => 0.88, 'none' => 0.07 ], 'confidence' => $confidence, 'choice' => $choice ], static fn ( mixed $v ): bool => null !== $v );
		return (string) \json_encode( [ 'model' => self::DECISION_MODEL, 'answers' => $answers, 'usage' => [ 'tokens' => 530 ] ] );
	}

	/** A systemone body answering the publisher question with $noul. */
	private static function decided( mixed $noul ): string {
		return (string) \json_encode( [ 'model' => self::DECISION_MODEL, 'answers' => [ 'about_publisher' => [ 'type' => 'noul', 'noul' => $noul ] ], 'usage' => [ 'tokens' => 412 ] ] );
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
			'inputs'  => [ 'boilerplate' => [] ],
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
				'messages'    => Prompts::extract_entities( self::item( 'feed:wharf-31' ), [] ),
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
			[ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-32', [ 'content' => '{"orgs":["Plover Wharf Trust"]}', 'item' => self::item( 'feed:wharf-32' ), 'inputs' => [ 'boilerplate' => [] ], 'carry' => [ 'item_key' => 'feed:wharf-32', 'resolution' => [ 'attempt' => 3 ] ] ] ] ],
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
			'no content'         => [ '{"choices":[{"message":{"role":"assistant"}}]}', 200, \CURLE_OK, 'the answer names no choices[0].message.content' ],
			'not JSON at all'    => [ '<html>busy</html>', 200, \CURLE_OK, 'the answer names no choices[0].message.content' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'failures' )]
	public function test_a_failed_call_answers_its_error( string $body, int $code, int $result, string $error ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask( $node, 'feed:wharf-33' );

		$this->complete_nth( 0, $body, $code, $result );

		$this->assertSame( [ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-33', [ 'error' => $error, 'item' => self::item( 'feed:wharf-33' ), 'inputs' => [ 'boilerplate' => [] ], 'carry' => [ 'item_key' => 'feed:wharf-33' ] ] ] ], $this->heard() );
	}

	/** With no entry of its group on the api url's origin it calls nothing and answers at once that the group lends none. */
	public function test_a_group_lending_nothing_answers_unlent_at_once(): void {
		$this->lend( self::GROUP, 'https://elsewhere.example', 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();

		$this->ask( $node, 'feed:wharf-34', [ 'note' => 'kept' ] );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-34', [ 'unlent' => self::GROUP, 'item' => self::item( 'feed:wharf-34' ), 'inputs' => [ 'boilerplate' => [] ], 'carry' => [ 'item_key' => 'feed:wharf-34', 'note' => 'kept' ] ] ] ], $this->heard() );
	}

	/**
	 * A request it cannot read is refused TO its caller with whatever of its
	 * item, inputs and carry it could read, so the caller's own finish runs;
	 * none is sent.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string,2:list<string>}>
	 */
	public static function unreadable_requests(): array {
		$good = [ 'prompt' => 'extract_entities', 'item' => self::item( 'feed:wharf-35' ), 'inputs' => [ 'boilerplate' => [] ], 'options' => [], 'carry' => [ 'note' => 'n-35' ], 'backlog' => false ];
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
			'a decision\'s options' => [ [ 'prompt' => 'extract_review', 'options' => [ 'max_tokens' => 50 ] ] + $good, 'empty options, as a decision takes none', [ 'item', 'inputs', 'carry' ] ],
		];
	}

	/**
	 * A request it cannot read is refused TO its caller, naming what it
	 * lacks and handing back what it could read; nothing is thrown or sent.
	 *
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
		[ $extractor, , , $later ] = $this->extractor_on_llm( 3 );
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
		$request[ Message::VALUE ] = [ 'prompt' => 'extract_entities', 'item' => self::item( 'feed:long-1', \str_repeat( 'Kestrel ', 60000 ) ), 'inputs' => [ 'boilerplate' => [] ], 'options' => [], 'carry' => [], 'backlog' => false ];

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

	/** The topology row names what a decision posts with: each setter's last value, bound by name or position and kept as the setter keeps it. */
	public function test_the_topology_row_names_the_decision_config(): void {
		$commands = [
			[ 'set_api_url', [ 'https://stale.example/v0' ] ],
			[ 'set_model', [ self::MODEL ] ],
			[ 'set_api_url', [ '--url= ' . self::API . '/ ' ] ],
			[ 'set_decision_model', [ ' ' . self::DECISION_MODEL . ' ' ] ],
			[ 'set_feature', [ self::FEATURE ] ],
			[ 'set_concurrency', [ '4' ] ],
		];

		$commands = [
			[ 'set_protocol', [ 'openai' ] ],
			...$commands,
			[ 'set_protocol', [ '--protocol= anthropic ' ] ],
			[ 'set_decision_protocol', [ 'chat' ] ],
			[ 'set_api_version', [ '2027-03-14' ] ],
			[ 'set_effort', [ 'medium' ] ],
			[ 'set_fallbacks', [ 'none' ] ],
			[ 'set_operator', [ ' ' . self::OPERATOR . ' ' ] ],
			[ 'set_tracks', [ '--tracks=' . self::TRACKS ] ],
		];

		$this->assertSame(
			[
				'protocol'          => 'anthropic',
				'decision_protocol' => 'chat',
				'api_url'           => self::API,
				'model'             => self::MODEL,
				'decision_model'    => self::DECISION_MODEL,
				'feature'           => self::FEATURE,
				'api_version'       => '2027-03-14',
				'effort'            => 'medium',
				'fallbacks'         => 'none',
				'operator'          => self::OPERATOR,
				'tracks'            => self::TRACKS,
			],
			LLM_Call_Node::graph_facts( [ self::GROUP ], $commands )
		);
		$this->assertSame( [ 'protocol' => '', 'decision_protocol' => '', 'api_url' => '', 'model' => '', 'decision_model' => '', 'feature' => '', 'api_version' => '', 'effort' => '', 'fallbacks' => '', 'operator' => '', 'tracks' => '' ], LLM_Call_Node::graph_facts( [ self::GROUP ], [] ) );
	}

	/**
	 * Every prompt the node sends names the operator its `set_operator` and
	 * `set_tracks` lines configure: a decision's last line what it tracks,
	 * and a chat prompt saying whom the graph serves its name.
	 */
	public function test_the_prompts_sent_name_the_configured_operator(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node   = $this->llm();
		$config = $node->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_operator', [ self::OPERATOR ] );
		$config->dispatch( 'set_tracks', [ self::TRACKS ] );
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = self::CALLER;
		$request[ Message::KEY ]   = 'ask-5d1e';
		$request[ Message::VALUE ] = [
			'prompt'  => 'ask_answer',
			'item'    => [ 'item_key' => 'ask-5d1e', 'question' => 'Who won a prize?' ],
			'inputs'  => [ 'items' => [] ],
			'options' => [],
			'carry'   => [],
			'backlog' => false,
		];

		$this->ask_decision( $node, 'feed:wharf-74', [ 'publisher' => 'Kestrel Lantern Harbor' ], [ 'source' => 'feed', 'title' => 'Grant lands', 'body' => 'Routes.' ] );
		$this->in_drain( static fn () => $node->fill( $request ) );

		$this->assertStringEndsWith( "\nAsked by: Newspack Intelligence, a plugin built on newspack-nodes, which tracks " . self::TRACKS . '.', \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true, 512, \JSON_THROW_ON_ERROR )['state'] );
		$this->assertStringContainsString( 'You answer for someone on ' . self::OPERATOR . '\'s team.', \json_decode( (string) $this->sent[1][ \CURLOPT_POSTFIELDS ], true, 512, \JSON_THROW_ON_ERROR )['messages'][0]['content'] );
		$this->assertStringContainsString( 'command_node ' . self::NAME . ':config set_operator', $node->dump_config() );
	}

	/** The commands replay through dump_config; a concurrency outside Curl's window is refused. */
	public function test_the_commands_dump_and_a_bad_concurrency_is_refused(): void {
		$node = $this->llm( 5 );

		$this->assertSame(
			"make_node LLM_Call intel:llm-test ai-relay-4417\n"
			. "command_node intel:llm-test:config set_protocol ai-proxy\n"
			. "command_node intel:llm-test:config set_decision_protocol systemone\n"
			. 'command_node intel:llm-test:config set_api_url ' . self::API . "\n"
			. 'command_node intel:llm-test:config set_model ' . self::MODEL . "\n"
			. 'command_node intel:llm-test:config set_decision_model ' . self::DECISION_MODEL . "\n"
			. 'command_node intel:llm-test:config set_feature ' . self::FEATURE . "\n"
			. "command_node intel:llm-test:config set_concurrency 5\n",
			$node->dump_config()
		);
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( "concurrency wants a whole number from 1 to 16, got '17'" );
		$node->interpreter()?->dispatch( 'set_concurrency', [ '17' ] );
	}

	/** A decision posts its model, state and question to systemone under the same bearer, feature and deadline as a chat call. */
	public function test_a_decision_posts_its_state_and_question_to_systemone(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$item = [ 'item_key' => 'feed:klharbor.example:transit-77', 'title' => 'Mesa Courier wins a transit grant', 'body' => '<p>Two routes open.</p>' ];

		$this->ask_decision( $node, 'feed:klharbor.example:transit-77', [ 'publisher' => 'Kestrel Lantern Harbor' ], $item );

		$this->assertSame( [ self::SYSTEMONE ], $this->dispatched );
		$opts = $this->sent[0];
		$this->assertSame( [ 'model' => self::DECISION_MODEL ] + Prompts::decision( 'extract_review', $item, [ 'publisher' => 'Kestrel Lantern Harbor' ], new Operator( '', '' ) ), \json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true ) );
		$this->assertSame( [ 'Content-Type: application/json', 'X-WPCOM-AI-Feature: ' . self::FEATURE, 'Authorization: Bearer ' . self::TOKEN ], $opts[ \CURLOPT_HTTPHEADER ] );
		$this->assertGreaterThan( 119000, $opts[ \CURLOPT_TIMEOUT_MS ] );
	}

	/** A decision's noul answers TO the caller under its question, with the item, the inputs and the carry. */
	public function test_a_decision_answers_its_noul(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$item = self::item( 'feed:wharf-71' );
		$this->ask_decision( $node, 'feed:wharf-71', [ 'publisher' => 'Kestrel Lantern Harbor' ], $item );

		$this->complete_nth( 0, self::decided( 0.95 ) );

		$this->assertSame( [ [ Message::TM_STRUCT | Message::TM_RESPONSE, self::NAME, 'feed:wharf-71', [ 'decision' => [ 'about_publisher' => 0.95 ], 'item' => $item, 'inputs' => [ 'publisher' => 'Kestrel Lantern Harbor' ], 'carry' => [ 'content' => 'reply-feed:wharf-71' ] ] ] ], $this->heard() );
	}

	/**
	 * A failed transfer, and an answer whose noul is missing, out of range or
	 * not a number, each answer an error with the carry.
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public static function decision_failures(): array {
		$no_noul = 'the decision names no noul from 0 to 1 for about_publisher';
		return [
			'a bad gateway'   => [ 'bad gateway', 502, 'HTTP 502 ' . self::SYSTEMONE ],
			'no noul'         => [ (string) \json_encode( [ 'answers' => [ 'about_publisher' => [ 'type' => 'noul' ] ] ] ), 200, $no_noul ],
			'a noul past one' => [ self::decided( 1.7 ), 200, $no_noul ],
			'a noul string'   => [ self::decided( '0.4' ), 200, $no_noul ],
			'not JSON at all' => [ '<html>busy</html>', 200, $no_noul ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'decision_failures' )]
	public function test_a_failed_decision_answers_its_error( string $body, int $code, string $error ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask_decision( $node, 'feed:wharf-72', [ 'publisher' => 'Kestrel Lantern Harbor' ], self::item( 'feed:wharf-72' ) );

		$this->complete_nth( 0, $body, $code );

		$this->assertSame( [ 'error' => $error, 'item' => self::item( 'feed:wharf-72' ), 'inputs' => [ 'publisher' => 'Kestrel Lantern Harbor' ], 'carry' => [ 'content' => 'reply-feed:wharf-72' ] ], $this->caller->captured[0][ Message::VALUE ] );
	}

	/** A review of a client's article offered themes asks both questions in one request, and both answers come back together. */
	public function test_a_review_asks_both_questions_at_once_and_answers_both(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node   = $this->llm();
		$inputs = [ 'publisher' => 'Kestrel Lantern Harbor', 'themes' => self::OFFERED, 'summary' => 'Mesa Courier wins a grant.' ];
		$item   = self::item( 'feed:wharf-74' );
		$this->ask_decision( $node, 'feed:wharf-74', $inputs, $item );

		$this->assertSame( [ self::SYSTEMONE ], $this->dispatched );
		$this->assertSame( [ 'model' => self::DECISION_MODEL ] + Prompts::decision( 'extract_review', $item, $inputs, new Operator( '', '' ) ), \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true ) );
		$this->assertSame( [ 'about_publisher', 'theme_choice' ], \array_keys( \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true )['questions'] ) );

		$this->complete_nth( 0, self::reviewed( 0.31, 't2', 0.88 ) );

		$this->assertSame( [ 'about_publisher' => 0.31, 'theme_choice' => [ 'choice' => 't2', 'confidence' => 0.88 ] ], $this->caller->captured[0][ Message::VALUE ]['decision'] );
	}

	/** A review of a client's launch offered themes asks all three questions in one request, and all three answers come back together. */
	public function test_a_review_of_a_launch_asks_every_question_in_one_request(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask_decision( $node, 'feed:wharf-77', [ 'publisher' => 'Kestrel Lantern Harbor', 'themes' => self::OFFERED, 'summary' => 'Heron Weekly goes live.', 'kind' => 'launch' ], self::item( 'feed:wharf-77' ) );

		$this->assertSame( [ self::SYSTEMONE ], $this->dispatched );
		$this->assertSame( [ 'about_publisher', 'fits_kind', 'theme_choice' ], \array_keys( \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true )['questions'] ) );

		$body                                       = \json_decode( self::reviewed( 0.74, 't1', 0.92 ), true );
		$body['answers']['fits_kind']               = [ 'type' => 'noul', 'noul' => 0.13 ];
		$this->complete_nth( 0, (string) \json_encode( $body ) );

		$this->assertSame( [ 'about_publisher' => 0.74, 'fits_kind' => 0.13, 'theme_choice' => [ 'choice' => 't1', 'confidence' => 0.92 ] ], $this->caller->captured[0][ Message::VALUE ]['decision'] );
	}

	/**
	 * A `fits_kind` missing or out of range answers an error naming it.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public static function bad_kind_fits(): array {
		return [
			'no answer'       => [ [] ],
			'a noul past one' => [ [ 'fits_kind' => [ 'type' => 'noul', 'noul' => 1.7 ] ] ],
		];
	}

	/** @param array<string,mixed> $answers What the body answers. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_kind_fits' )]
	public function test_a_bad_fits_kind_answers_an_error( array $answers ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask_decision( $node, 'feed:wharf-78', [ 'publisher' => '', 'themes' => [], 'summary' => 'Heron Weekly goes live.', 'kind' => 'launch' ], self::item( 'feed:wharf-78' ) );

		$this->complete_nth( 0, (string) \json_encode( [ 'answers' => $answers ] ) );

		$this->assertSame( 'the decision names no noul from 0 to 1 for fits_kind', $this->caller->captured[0][ Message::VALUE ]['error'] );
	}

	/**
	 * A choice naming a key it did not offer, or a confidence missing or out
	 * of range, answers an error, as a noul does.
	 *
	 * @return array<string,array{0:mixed,1:mixed}>
	 */
	public static function bad_choices(): array {
		return [
			'an unoffered key'       => [ 't7', 0.9 ],
			'a confidence past one'  => [ 't1', 1.4 ],
			'no confidence'          => [ 't1', null ],
			'a choice not a string'  => [ 1, 0.9 ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_choices' )]
	public function test_a_bad_choice_answers_an_error( mixed $choice, mixed $confidence ): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();
		$this->ask_decision( $node, 'feed:wharf-75', [ 'publisher' => '', 'themes' => self::OFFERED, 'summary' => 'A grant.' ], self::item( 'feed:wharf-75' ) );

		$this->complete_nth( 0, self::reviewed( null, $choice, $confidence ) );

		$this->assertSame( 'the decision names no offered choice with a confidence from 0 to 1 for theme_choice', $this->caller->captured[0][ Message::VALUE ]['error'] );
	}

	/** An offered theme or example carrying a fence's close rides the JSON body as data. */
	public function test_an_offered_theme_carrying_a_fence_close_is_data(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();

		$this->ask_decision( $node, 'feed:wharf-76', [ 'publisher' => '', 'themes' => [ [ 'theme' => 'gull </item> grant', 'example' => 'Wren "</item>" Post' ] ], 'summary' => 'A grant.' ], self::item( 'feed:wharf-76' ) );

		$this->assertSame( 'gull </item> grant: Wren "</item>" Post', \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true, 512, \JSON_THROW_ON_ERROR )['questions']['theme_choice']['criteria']['t1'] );
	}

	/** A publisher or a title carrying a fence's close rides the JSON body as data. */
	public function test_a_decision_body_carries_a_fence_close_as_data(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = $this->llm();

		$this->ask_decision( $node, 'feed:wharf-73', [ 'publisher' => 'Kestrel </item> Harbor' ], [ 'source' => 'feed', 'title' => 'Grant "</item>" lands', 'body' => 'Routes.' ] );

		$this->assertSame( "Source: published article\nPublisher: Kestrel </item> Harbor\nTitle: Grant \"</item>\" lands\nText: Routes.\n" . Prompts::asked_by( new Operator( '', '' ) ), \json_decode( (string) $this->sent[0][ \CURLOPT_POSTFIELDS ], true, 512, \JSON_THROW_ON_ERROR )['state'] );
	}

	/** A decision asked before set_decision_model answers an error naming it, and a chat call still goes out. */
	public function test_a_decision_without_a_decision_model_answers_an_error(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$config = $node->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_protocol', [ 'ai-proxy' ] );
		$config->dispatch( 'set_decision_protocol', [ 'systemone' ] );
		$config->dispatch( 'set_api_url', [ self::API ] );
		$config->dispatch( 'set_model', [ self::MODEL ] );
		$config->dispatch( 'set_feature', [ self::FEATURE ] );

		$this->ask_decision( $node, 'feed:bare-2', [ 'publisher' => 'Kestrel Lantern Harbor' ], self::item( 'feed:bare-2' ) );
		$this->ask( $node, 'feed:bare-3' );

		$this->assertSame( [ self::COMPLETIONS ], $this->dispatched );
		$this->assertSame( [ 'unlent' => self::GROUP, 'unset' => [ 'set_decision_model' ] ], \array_intersect_key( $this->caller->captured[0][ Message::VALUE ], [ 'unlent' => true, 'unset' => true ] ) );
	}

	/**
	 * A request made before set_protocol, set_api_url and set_model answers
	 * unlent, naming the setters it lacks, so a caller treats a transport
	 * whose setters are unset as one its group lends nothing to: it retries
	 * nothing.
	 */
	public function test_an_unconfigured_node_answers_unlent_naming_its_unset_setters(): void {
		$node = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );

		$this->ask( $node, 'feed:bare-1' );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ 'unlent' => self::GROUP, 'unset' => [ 'set_protocol', 'set_api_url', 'set_model' ], 'item' => self::item( 'feed:bare-1' ), 'inputs' => [ 'boilerplate' => [] ], 'carry' => [ 'item_key' => 'feed:bare-1' ] ], $this->caller->captured[0][ Message::VALUE ] );
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

		$this->complete_nth( 0, self::completion( '{"summary":"Heron Dispatch opens a coastal bureau.","kind":"partnership","relevance":6,"importance":4}' ) );

		$this->assertSame( [ [ 'extraction_recorded', 'Heron Dispatch opens a coastal bureau.', 'partnership' ], [ 'subjects_extracted', null, null ] ], \array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['event'], $m[ Message::VALUE ]['summary'] ?? null, $m[ Message::VALUE ]['kind'] ?? null ], $writer->captured ) );
		$this->assertSame( [ 'feed:heron-4' ], \array_map( static fn ( array $m ): string => $m[ Message::VALUE ]['item']['item_key'], $deduper->captured ) );
	}

	/**
	 * An extractor whose model-call node has its setters unset records the
	 * heuristic stand-in and parks no retry, because a retry could never be
	 * answered, and publishes heuristic naming the setters left unset.
	 */
	public function test_an_unconfigured_transport_writes_the_stand_in_and_parks_nothing(): void {
		[ $extractor, $writer, , $later ] = $this->extractor_on_llm( 1, false );
		Core::$memd             = new InMemoryMemcached();
		Core::$var['partition'] = '0';

		$this->in_drain( static fn () => $extractor->fill( self::resolution( 'feed:heron-8', 'Heron Dispatch closes a bureau', 'The wharf office shuts.' ) ) );

		$this->assertSame( [], $this->dispatched );
		$this->assertSame( [ [ 'extraction_recorded', 'machine:extract-heuristic@' . \NEWSPACK_INTELLIGENCE_VERSION, 'other' ] ], \array_map( static fn ( array $m ): array => [ $m[ Message::VALUE ]['event'], $m[ Message::VALUE ]['actor'], $m[ Message::VALUE ]['kind'] ], $writer->captured ) );
		$this->assertSame( [], $later->captured, 'no retry is parked' );
		$this->assertSame( [ 'heuristic', self::GROUP, [ 'set_protocol', 'set_api_url', 'set_model' ] ], \array_values( \array_intersect_key( Extractor_Node::read_status( 'extractor', 0 ) ?? [], [ 'mode' => true, 'vault_group' => true, 'unset' => true ] ) ) );
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
	 * @return array{0:Extractor_Node,1:Capture_Sink_Node,2:Capture_Sink_Node,3:Capture_Sink_Node}
	 */
	private function extractor_on_llm( int $concurrency, bool $configured = true ): array {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		Topology_Registry::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		( new \ReflectionProperty( Entity_Index::class, 'current' ) )->setValue( null, null );
		$writer = new Capture_Sink_Node();
		$writer->name( 'intel:writer' );
		$deduper = new Capture_Sink_Node();
		$deduper->name( 'deduper' );
		$later = new Capture_Sink_Node();
		$later->name( 'intel:later' );
		if ( $configured ) {
			$this->llm( $concurrency );
		} else {
			$bare = new LLM_Call_Node();
			$bare->name( self::NAME );
			$this->bound( static fn () => $bare->arguments( [ self::GROUP ] ) );
			$bare->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		}
		$extractor = new Extractor_Node();
		$extractor->name( 'extractor' );
		$extractor->arguments( [ 'intel:writer', 'intel:later', self::NAME ] );
		$extractor->target( 'deduper' );
		$extractor->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$this->capture_stderr();
		return [ $extractor, $writer, $deduper, $later ];
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
			'publisher' => '',
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

	/** The Messages API url under the api url. */
	private const MESSAGES = self::API . '/v1/messages';

	/** The current stable `anthropic-version` a deployment naming none sends. */
	private const STABLE_VERSION = '2026-10-21';

	/** The header `fallbacks: "default"` rides with. */
	private const FALLBACK_BETA = 'anthropic-beta: server-side-fallback-2026-07-01';

	/** The node speaking the Messages API, its credential lent as `x-api-key`. */
	private function claude( string $decisions = 'chat' ): LLM_Call_Node {
		$this->lend( self::GROUP, self::ORIGIN, 'x-api-key', self::TOKEN );
		return $this->llm( 3, 'anthropic', $decisions );
	}

	/**
	 * A Messages API body holding `$content`, stopped at `$stop`.
	 *
	 * @param list<array<string,mixed>> $content The content blocks.
	 * @param array<string,mixed>       $extra   Any other top-level field.
	 */
	private static function message( array $content, string $stop = 'end_turn', array $extra = [] ): string {
		return (string) \json_encode(
			[
				'id'          => 'msg_heron01',
				'type'        => 'message',
				'role'        => 'assistant',
				'model'       => self::MODEL,
				'content'     => $content,
				'stop_reason' => $stop,
				'usage'       => [ 'input_tokens' => 914, 'cache_read_input_tokens' => 812, 'output_tokens' => 41 ],
			] + $extra
		);
	}

	/** @return array<string,mixed> The n-th POST's body, decoded. */
	private function body( int $n = 0 ): array {
		return \json_decode( (string) $this->sent[ $n ][ \CURLOPT_POSTFIELDS ], true, 512, \JSON_THROW_ON_ERROR );
	}

	/**
	 * A chat call over the Messages API posts to `v1/messages` with the
	 * version, the fallback beta and the lent `x-api-key`, never the
	 * feature header: the system text, its one cache-control block, the
	 * item in the user turn, the prompt's own max_tokens and low effort in
	 * place of the caller's options, and fallbacks by default.
	 */
	public function test_a_messages_call_caches_the_system_text_and_carries_the_item_in_the_user_turn(): void {
		$node     = $this->claude();
		$messages = Prompts::extract_entities( self::item( 'feed:wharf-81' ), [] );

		$this->ask( $node, 'feed:wharf-81' );

		$this->assertSame( [ self::MESSAGES ], $this->dispatched );
		$this->assertSame(
			[
				'model'         => self::MODEL,
				'max_tokens'    => 4096,
				'system'        => [ [ 'type' => 'text', 'text' => $messages[0]['content'], 'cache_control' => [ 'type' => 'ephemeral' ] ] ],
				'messages'      => [ [ 'role' => 'user', 'content' => $messages[1]['content'] ] ],
				'output_config' => [ 'effort' => 'low' ],
				'fallbacks'     => 'default',
			],
			$this->body()
		);
		$this->assertSame( [ 'Content-Type: application/json', 'anthropic-version: ' . self::STABLE_VERSION, self::FALLBACK_BETA, 'x-api-key: ' . self::TOKEN ], $this->sent[0][ \CURLOPT_HTTPHEADER ] );
	}

	/**
	 * Every prompt over the Messages API sends no sampling parameter, which
	 * the API refuses, caches the system text alone and asks its own effort:
	 * a chat prompt and a decision alike.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string}>
	 */
	public static function messages_prompts(): array {
		return [
			'the NER prompt'     => [ 'extract_entities', self::item( 'feed:wharf-82' ), [ 'boilerplate' => [] ], 'low' ],
			'the extraction'     => [ 'extract', [ 'item_key' => 'feed:wharf-91', 'title' => 'Of the', 'body' => 'A body.', 'retrieved_ts' => self::START ], [ 'topics' => [ 'Awards' => 'topic:awards' ], 'profile' => 'Score kite news highest.', 'audience' => 'a heron desk', 'publisher' => '' ], 'low' ],
			'the ask plan'       => [ 'ask_plan', [ 'item_key' => 'ask-7c21', 'question' => 'Who launched?' ], [ 'today' => '2026-11-08', 'lenses' => [] ], 'low' ],
			'the ask answer'     => [ 'ask_answer', [ 'item_key' => 'ask-7c22', 'question' => 'Who launched?' ], [ 'items' => [] ], 'medium' ],
			'an extract review'  => [ Prompts::EXTRACT_REVIEW, self::item( 'feed:wharf-83' ), [ 'kind' => 'launch', 'summary' => 'Heron Weekly goes live.' ], 'low' ],
			'an extract gate'    => [ Prompts::EXTRACT_GATE, self::item( 'feed:wharf-84' ), [ 'publisher' => 'Kestrel Lantern Harbor' ], 'low' ],
		];
	}

	/**
	 * @param array<string,mixed> $item   The item.
	 * @param array<string,mixed> $inputs The prompt's inputs.
	 * @param string              $effort The effort the prompt asks.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'messages_prompts' )]
	public function test_no_messages_call_sends_a_sampling_parameter( string $prompt, array $item, array $inputs, string $effort ): void {
		$node                      = $this->claude();
		$request                   = Message::new_message();
		$request[ Message::TYPE ]  = Message::TM_STRUCT;
		$request[ Message::FROM ]  = self::CALLER;
		$request[ Message::KEY ]   = Core::as_string( $item['item_key'] );
		$request[ Message::VALUE ] = [
			'prompt'  => $prompt,
			'item'    => $item,
			'inputs'  => $inputs,
			'options' => Prompts::DECISION === Prompts::PROMPTS[ $prompt ] ? [] : [ 'max_tokens' => 333, 'temperature' => 0.4 ],
			'carry'   => [],
			'backlog' => false,
		];

		$this->in_drain( static fn () => $node->fill( $request ) );

		$raw = (string) $this->sent[0][ \CURLOPT_POSTFIELDS ];
		foreach ( [ 'temperature', 'top_p', 'top_k' ] as $sampling ) {
			$this->assertStringNotContainsString( "\"{$sampling}\"", $raw );
		}
		$this->assertSame( 1, \substr_count( $raw, 'cache_control' ) );
		$this->assertArrayHasKey( 'cache_control', $this->body()['system'][0] );
		$this->assertSame( [ 'user' ], \array_column( $this->body()['messages'], 'role' ) );
		$this->assertSame( $effort, $this->body()['output_config']['effort'] );
		$this->assertGreaterThan( 1000, $this->body()['max_tokens'], 'room for the thinking a current model does by default' );
	}

	/**
	 * The Messages cases send every prompt `Prompts::PROMPTS` names, each
	 * building a real body, so a prompt the row does not budget fails there.
	 */
	public function test_the_messages_cases_cover_every_prompt(): void {
		$sent = \array_column( self::messages_prompts(), 0 );
		\sort( $sent );
		$named = \array_keys( Prompts::PROMPTS );
		\sort( $named );

		$this->assertSame( $named, $sent );
	}

	/** A prompt the Messages row does not budget is refused by name, never sent without a max_tokens. */
	public function test_a_prompt_the_messages_row_does_not_budget_is_refused(): void {
		$facts = LLM_Call_Node::graph_facts( [ self::GROUP ], [ [ 'set_protocol', [ 'anthropic' ] ], [ 'set_api_url', [ self::API ] ], [ 'set_model', [ self::MODEL ] ] ] );

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'the anthropic wire budgets no prompt: summarize' );
		LLM_Call_Node::curl_request( $facts, 'summarize', self::item( 'feed:wharf-93' ), [], [] );
	}

	/** A deployment's version, effort and fallback switch replace the shipped defaults: no fallback beta, no fallbacks. */
	public function test_a_deployment_names_the_version_the_effort_and_no_fallbacks(): void {
		$node   = $this->claude();
		$config = $node->interpreter();
		\assert( null !== $config );
		$config->dispatch( 'set_api_version', [ '2027-03-14' ] );
		$config->dispatch( 'set_effort', [ 'high' ] );
		$config->dispatch( 'set_fallbacks', [ 'none' ] );

		$this->ask( $node, 'feed:wharf-85' );

		$this->assertSame( [ 'effort' => 'high' ], $this->body()['output_config'] );
		$this->assertArrayNotHasKey( 'fallbacks', $this->body() );
		$this->assertSame( [ 'Content-Type: application/json', 'anthropic-version: 2027-03-14', 'x-api-key: ' . self::TOKEN ], $this->sent[0][ \CURLOPT_HTTPHEADER ] );
		$this->assertStringContainsString( "command_node intel:llm-test:config set_api_version 2027-03-14\ncommand_node intel:llm-test:config set_effort high\ncommand_node intel:llm-test:config set_fallbacks none\n", $node->dump_config() );
	}

	/**
	 * A Messages answer is read from its text blocks: thinking skipped and
	 * text joined; a refusal or a max_tokens stop is an error naming it,
	 * never an answer, as is a body holding no text.
	 *
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public static function messages_answers(): array {
		$orgs     = '{"orgs":["Plover Wharf Trust"]}';
		$thinking = [ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig-heron-1' ];
		return [
			'one text block'       => [ self::message( [ [ 'type' => 'text', 'text' => $orgs ] ] ), [ 'content' => $orgs ] ],
			'thinking then text'   => [ self::message( [ $thinking, [ 'type' => 'text', 'text' => $orgs ] ] ), [ 'content' => $orgs ] ],
			'two text blocks'      => [ self::message( [ [ 'type' => 'text', 'text' => '{"orgs":' ], [ 'type' => 'text', 'text' => '["Plover Wharf Trust"]}' ] ] ), [ 'content' => $orgs ] ],
			'a refusal'            => [ self::message( [], 'refusal', [ 'stop_details' => [ 'type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.' ] ] ), [ 'error' => 'the answer stopped at refusal (cyber)' ] ],
			'a refusal, no detail' => [ self::message( [], 'refusal' ), [ 'error' => 'the answer stopped at refusal' ] ],
			'a max_tokens stop'    => [ self::message( [ $thinking, [ 'type' => 'text', 'text' => '{"orgs":["Plo' ] ], 'max_tokens' ), [ 'error' => 'the answer stopped at max_tokens' ] ],
			'thinking alone'       => [ self::message( [ $thinking ] ), [ 'error' => 'the answer names no text content block' ] ],
			'not JSON at all'      => [ '<html>busy</html>', [ 'error' => 'the answer names no text content block' ] ],
		];
	}

	/** @param array<string,string> $outcome What the caller hears. */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'messages_answers' )]
	public function test_a_messages_answer_reads_its_text_blocks( string $body, array $outcome ): void {
		$node = $this->claude();
		$this->ask( $node, 'feed:wharf-86' );

		$this->complete_nth( 0, $body );

		$this->assertSame( $outcome + [ 'item' => self::item( 'feed:wharf-86' ), 'inputs' => [ 'boilerplate' => [] ], 'carry' => [ 'item_key' => 'feed:wharf-86' ] ], $this->caller->captured[0][ Message::VALUE ] );
	}

	/**
	 * Under `chat` decisions over the Messages API a decision is one
	 * structured-output call to the decision model: its messages
	 * `Prompts::decision_messages()` builds, the system text cached, and
	 * the questions' schema as the output format beside the effort; the
	 * JSON it answers is read to the decision a systemone body gives.
	 */
	public function test_a_chat_decision_over_messages_asks_one_schema_and_answers_the_decision(): void {
		$node      = $this->claude();
		$inputs    = [ 'publisher' => 'Kestrel Lantern Harbor', 'themes' => self::OFFERED, 'summary' => 'Mesa Courier wins a grant.' ];
		$item      = self::item( 'feed:wharf-87' );
		$messages  = Prompts::decision_messages( Prompts::EXTRACT_REVIEW, $item, $inputs, new Operator( '', '' ) );
		$questions = Prompts::questions( $inputs );

		$this->ask_decision( $node, 'feed:wharf-87', $inputs, $item );

		$this->assertSame( [ self::MESSAGES ], $this->dispatched );
		$this->assertSame(
			[
				'model'         => self::DECISION_MODEL,
				'max_tokens'    => 4096,
				'system'        => [ [ 'type' => 'text', 'text' => $messages[0]['content'], 'cache_control' => [ 'type' => 'ephemeral' ] ] ],
				'messages'      => [ [ 'role' => 'user', 'content' => $messages[1]['content'] ] ],
				'output_config' => [
					'effort' => 'low',
					'format' => [ 'type' => 'json_schema', 'schema' => Prompts::decision_schema( $questions ) ],
				],
				'fallbacks'     => 'default',
			],
			$this->body()
		);

		$this->complete_nth( 0, self::message( [ [ 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig-heron-2' ], [ 'type' => 'text', 'text' => '{"answers":{"about_publisher":{"noul":0.31},"theme_choice":{"choice":"t2","confidence":0.88}}}' ] ] ) );

		$this->assertSame( [ 'about_publisher' => 0.31, 'theme_choice' => [ 'choice' => 't2', 'confidence' => 0.88 ] ], $this->caller->captured[0][ Message::VALUE ]['decision'] );
	}

	/**
	 * A chat decision whose text is not the JSON asked for, or that stops
	 * early, answers the error a systemone body that will not read does.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function chat_decision_failures(): array {
		return [
			'malformed JSON'   => [ self::message( [ [ 'type' => 'text', 'text' => '{"answers":{"about_publisher":' ] ] ), 'the decision names no noul from 0 to 1 for about_publisher' ],
			'a noul past one'  => [ self::message( [ [ 'type' => 'text', 'text' => '{"answers":{"about_publisher":{"noul":1.6}}}' ] ] ), 'the decision names no noul from 0 to 1 for about_publisher' ],
			'a refusal'        => [ self::message( [], 'refusal' ), 'the answer stopped at refusal' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'chat_decision_failures' )]
	public function test_a_chat_decision_that_will_not_read_answers_an_error( string $body, string $error ): void {
		$node = $this->claude();
		$this->ask_decision( $node, 'feed:wharf-88', [ 'publisher' => 'Kestrel Lantern Harbor' ], self::item( 'feed:wharf-88' ) );

		$this->complete_nth( 0, $body );

		$this->assertSame( $error, $this->caller->captured[0][ Message::VALUE ]['error'] );
	}

	/**
	 * Plain `openai` is today's chat shape without the proxy's feature
	 * header, which it neither sends nor needs; its `chat` decision is one
	 * call carrying the questions' schema as its response format, read to
	 * the same decision.
	 */
	public function test_plain_openai_sends_no_feature_and_asks_a_decision_as_a_response_format(): void {
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', self::TOKEN, 'Bearer' );
		$node   = new LLM_Call_Node();
		$node->name( self::NAME );
		$this->bound( static fn () => $node->arguments( [ self::GROUP ] ) );
		$node->sink( Core::node( Node_Names::COMMAND_INTERPRETER ) );
		$config = $node->interpreter();
		\assert( null !== $config );
		foreach ( [ 'set_protocol' => 'openai', 'set_decision_protocol' => 'chat', 'set_api_url' => self::API, 'set_model' => self::MODEL, 'set_decision_model' => self::DECISION_MODEL, 'set_concurrency' => '3' ] as $verb => $value ) {
			$config->dispatch( $verb, [ $value ] );
		}
		$inputs = [ 'publisher' => 'Kestrel Lantern Harbor' ];
		$item   = self::item( 'feed:wharf-89' );

		$this->ask( $node, 'feed:wharf-90' );
		$this->ask_decision( $node, 'feed:wharf-89', $inputs, $item );

		$this->assertSame( [ self::COMPLETIONS, self::COMPLETIONS ], $this->dispatched );
		$this->assertSame( [ 'model' => self::MODEL, 'messages' => Prompts::extract_entities( self::item( 'feed:wharf-90' ), [] ), 'max_tokens' => 333, 'temperature' => 0.4 ], $this->body( 0 ) );
		$this->assertSame( [ 'Content-Type: application/json', 'Authorization: Bearer ' . self::TOKEN ], $this->sent[0][ \CURLOPT_HTTPHEADER ] );
		$this->assertSame(
			[
				'model'           => self::DECISION_MODEL,
				'messages'        => Prompts::decision_messages( Prompts::EXTRACT_REVIEW, $item, $inputs, new Operator( '', '' ) ),
				'response_format' => [
					'type'        => 'json_schema',
					'json_schema' => [ 'name' => 'decision', 'strict' => true, 'schema' => Prompts::decision_schema( Prompts::questions( $inputs ) ) ],
				],
			],
			$this->body( 1 )
		);

		$this->complete_nth( 1, self::completion( '{"answers":{"about_publisher":{"noul":0.64}}}' ) );

		$this->assertSame( [ 'about_publisher' => 0.64 ], $this->caller->captured[0][ Message::VALUE ]['decision'] );
	}

	/**
	 * Each call's kind lacks what its transport needs: a protocol and, for a
	 * decision, where decisions go first, the feature header only on a
	 * route that sends it, so the Messages API asks no feature while
	 * systemone still does.
	 *
	 * @return array<string,array{0:array<string,string>,1:list<string>,2:list<string>}>
	 */
	public static function unset_transports(): array {
		return [
			'nothing set'                   => [ [], [ 'set_protocol', 'set_api_url', 'set_model' ], [ 'set_decision_protocol', 'set_protocol', 'set_api_url', 'set_decision_model' ] ],
			'the proxy, no feature'         => [ [ 'set_protocol' => 'ai-proxy', 'set_decision_protocol' => 'systemone', 'set_api_url' => self::API, 'set_model' => self::MODEL, 'set_decision_model' => self::DECISION_MODEL ], [ 'set_feature' ], [ 'set_feature' ] ],
			'messages, systemone decisions' => [ [ 'set_protocol' => 'anthropic', 'set_decision_protocol' => 'systemone', 'set_api_url' => self::API, 'set_model' => self::MODEL, 'set_decision_model' => self::DECISION_MODEL ], [], [ 'set_feature' ] ],
			'systemone, no protocol'        => [ [ 'set_decision_protocol' => 'systemone', 'set_api_url' => self::API, 'set_decision_model' => self::DECISION_MODEL, 'set_feature' => self::FEATURE ], [ 'set_protocol', 'set_model' ], [] ],
		];
	}

	/**
	 * @param array<string,string> $set      The setters dispatched.
	 * @param list<string>         $chat     What a chat call lacks.
	 * @param list<string>         $decision What a decision lacks.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unset_transports' )]
	public function test_each_kind_names_the_setters_its_route_lacks( array $set, array $chat, array $decision ): void {
		$facts = [];
		foreach ( $set as $verb => $value ) {
			$facts[] = [ $verb, [ $value ] ];
		}
		$wire = LLM_Call_Node::graph_facts( [ self::GROUP ], $facts );

		$this->assertSame( [ $chat, $decision ], [ LLM_Call_Node::unset_of( $wire, Prompts::CHAT ), LLM_Call_Node::unset_of( $wire, Prompts::DECISION ) ] );
	}

	/** An answer landing after the protocol is cleared answers an error naming why, never thrown into the loop. */
	public function test_an_answer_with_no_wire_left_to_read_it_answers_an_error(): void {
		$node = $this->claude();
		$this->ask( $node, 'feed:wharf-92' );
		$node->set_protocol( '' );

		$this->complete_nth( 0, self::message( [ [ 'type' => 'text', 'text' => '{"orgs":[]}' ] ] ) );

		$this->assertSame( self::NAME . ": an answer could not be read: no protocol: ''", $this->caller->captured[0][ Message::VALUE ]['error'] );
	}

	/**
	 * A protocol, a decision route, an effort, a fallback switch or a
	 * version outside its set is refused naming the set, by the command and
	 * by the topology row alike.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function refused_settings(): array {
		return [
			'a protocol'          => [ 'set_protocol', 'gull-wire', "protocol must be one of: ai-proxy, openai, anthropic, got 'gull-wire'" ],
			'a decision protocol' => [ 'set_decision_protocol', 'oracle', "decision_protocol must be one of: systemone, chat, got 'oracle'" ],
			'an effort'           => [ 'set_effort', 'turbo', "effort must be one of: low, medium, high, xhigh, max, got 'turbo'" ],
			'a fallback switch'   => [ 'set_fallbacks', 'always', "fallbacks must be one of: default, none, got 'always'" ],
			'a version'           => [ 'set_api_version', '21-10-2026', "api_version must be a Y-m-d date, got '21-10-2026'" ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_settings' )]
	public function test_a_setting_outside_its_set_is_refused( string $verb, string $value, string $error ): void {
		$node = $this->llm();
		try {
			$node->interpreter()?->dispatch( $verb, [ $value ] );
			$this->fail( 'the command refuses it' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( $error, $e->getMessage() );
		}
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $error );
		LLM_Call_Node::graph_facts( [ self::GROUP ], [ [ $verb, [ $value ] ] ] );
	}
}

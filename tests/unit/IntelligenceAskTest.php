<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Ask_Node;
use Newspack_Intelligence\Graph_Asks;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The Ask's two commands, each at `read`: `ask` appends one question to the
 * asks stream and answers its key, and `ask_result` reads the caller's own
 * ask back, re-reading every item its answer cites under the caller's tiers
 * now, so a sentence resting on an item the caller cannot read is not shown.
 */
final class IntelligenceAskTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const READER = 7401;

	private const NEIGHBOR = 7402;

	private const HOLDER = 7403;

	private const KEY = '3e7a1c9b5d2f4068';

	private const TICKET = 'unthread:quay-5512';

	/** The searches every fixture answer ran. */
	private const SEARCHES = [ [ 'kinds' => [ 'launch' ], 'days' => 21 ] ];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::READER, 'quay-asker', 'manage_options' );
		self::add_user( self::NEIGHBOR, 'quay-neighbor', 'manage_options' );
		self::add_user( self::HOLDER, 'quay-analyst', 'manage_options', Intel_Tiers::CAPABILITY );
		$GLOBALS['_current_user_id'] = self::READER;
		$this->ci                    = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
		$this->seed_world();
		$this->seed( self::item( self::TICKET, 'Harbor Gazette weighs leaving', 'unthread', 'restricted', self::$oct_13 ) );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** Anyone who reads may ask: both commands declare read. */
	public function test_both_commands_declare_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( [ Capabilities::READ, Capabilities::READ ], [ $roles['ask'], $roles['ask_result'] ] );
	}

	/** An ask appends one `ask_asked` under a key it mints, its owner and actor the caller, and answers the key alone. */
	public function test_an_ask_appends_its_question_and_answers_its_key(): void {
		$answer = $this->ci->dispatch( 'ask', [ 'Which clients launched a newsletter?' ] );

		$events = self::asks_events();
		$this->assertCount( 1, $events );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $answer['ask_key'] );
		$this->assertSame( [ 'ask_key' => $answer['ask_key'] ], $answer );
		$this->assertSame(
			[ 'event' => 'ask_asked', 'actor' => 'user:quay-asker', 'ask_key' => $answer['ask_key'], 'owner' => self::READER, 'question' => 'Which clients launched a newsletter?' ],
			\array_intersect_key( $events[0], \array_flip( [ 'event', 'actor', 'ask_key', 'owner', 'question' ] ) )
		);
		$this->assertSame( [], self::ontology_events() );
		$this->assertSame( [], self::watches_events() );
	}

	/** An ask polled before the projector has read the asks stream reads as owed, not refused. */
	public function test_an_ask_not_yet_projected_reads_as_asked(): void {
		$key = $this->ci->dispatch( 'ask', [ 'Which clients won awards?' ] )['ask_key'];

		$answer = $this->ci->dispatch( 'ask_result', [ $key ] );

		$this->assertSame( [ $key, 'asked', Ask_Node::MAX_WAIT_S ], [ $answer['ask_key'], $answer['state'], $answer['wait_s'] ] );
	}

	/**
	 * Each question an ask refuses, and why.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refused_questions(): array {
		return [
			'a blank question'     => [ "  \n ", 'question is blank' ],
			'a question too long'  => [ \str_repeat( 'é', Graph_Asks::QUESTION_BYTES ), 'question runs past ' . Graph_Asks::QUESTION_BYTES . ' bytes' ],
		];
	}

	/**
	 * A blank question, or one past its cap as the stream writes it, is
	 * refused before anything is written.
	 *
	 * @param string $question The question.
	 * @param string $message  The refusal.
	 */
	#[DataProvider( 'refused_questions' )]
	public function test_a_refused_question_writes_nothing( string $question, string $message ): void {
		try {
			$this->ci->dispatch( 'ask', [ $question ] );
			$this->fail( 'the question is refused' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( $message, $e->getMessage() );
		}
		$this->assertSame( [], self::asks_events() );
	}

	/**
	 * The `ask_result` description, which the MCP door hands an agent, names
	 * every key an answer carries and every state, so an agent learns that
	 * an answer can be withheld and how long to poll; `ask`'s names no cap.
	 */
	public function test_the_command_descriptions_name_every_key_and_state(): void {
		$this->seed( self::asked( self::KEY, self::READER ), self::answered( self::KEY, self::READER, [ [ 'The Gazette won a state award.', [ 1 ] ] ], [ 'feed:gazette-award' ] ) );
		$described = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'description', 'name' );

		foreach ( \array_keys( $this->ci->dispatch( 'ask_result', [ self::KEY ] ) ) as $key ) {
			$this->assertMatchesRegularExpression( '/\b' . \preg_quote( $key, '/' ) . '\b/', $described['ask_result'], $key );
		}
		foreach ( [ Graph_Asks::ASKED, Graph_Asks::PLANNED, Graph_Asks::READ, Graph_Asks::ANSWERED, Graph_Asks::FAILED, Graph_Asks::WITHHELD ] as $state ) {
			$this->assertStringContainsString( $state, $described['ask_result'], $state );
		}
		$this->assertStringContainsString( (string) Ask_Node::MAX_WAIT_S, $described['ask_result'] );
		$this->assertDoesNotMatchRegularExpression( '/\b(cap|limit|per hour|throttl)/i', $described['ask'] );
	}

	/** An ask still owed its answer reads as asked, with nothing in it. */
	public function test_a_waiting_ask_reads_as_asked(): void {
		$this->seed( self::asked( self::KEY, self::READER ) );

		$this->assertSame(
			[ 'ask_key' => self::KEY, 'state' => 'asked', 'reason' => '', 'found' => 0, 'dropped' => 0, 'hidden' => 0, 'sentences' => [], 'items' => [], 'searches' => [], 'wait_s' => Ask_Node::MAX_WAIT_S, 'available' => true ],
			$this->ci->dispatch( 'ask_result', [ self::KEY ] )
		);
	}

	/**
	 * An ask reads planned once its plan is written, with the searches it
	 * names, then read once its searches are, with how many items they found,
	 * then answered.
	 */
	public function test_each_step_reads_its_state(): void {
		$searches = [ [ 'words' => 'newsletter', 'days' => 30 ], [ 'kinds' => [ 'feature_request' ], 'days' => 30 ] ];
		$this->seed( self::asked( self::KEY, self::READER ), [ Intel_Event::machine_event( 'ask', 'ask_planned', [ 'ask_key' => self::KEY, 'owner' => self::READER, 'searches' => $searches ] ) ] );
		$planned = $this->ci->dispatch( 'ask_result', [ self::KEY ] );
		$this->seed( [ Intel_Event::machine_event( 'ask', 'ask_read', [ 'ask_key' => self::KEY, 'owner' => self::READER, 'found' => 23 ] ) ] );
		$read = $this->ci->dispatch( 'ask_result', [ self::KEY ] );
		$this->seed( self::answered( self::KEY, self::READER, [ [ 'The Gazette won a state award.', [ 1 ] ] ], [ 'feed:gazette-award' ] ) );

		$this->assertSame( [ 'planned', 0, $searches ], [ $planned['state'], $planned['found'], $planned['searches'] ] );
		$this->assertSame( [ 'read', 23, $searches ], [ $read['state'], $read['found'], $read['searches'] ] );
		$this->assertSame( 'answered', $this->ci->dispatch( 'ask_result', [ self::KEY ] )['state'] );
	}

	/** An ask that stopped after its plan reads as failed once it has waited past MAX_WAIT_S, its searches kept. */
	public function test_a_planned_ask_waiting_past_its_wait_reads_failed(): void {
		$planned          = Intel_Event::machine_event( 'ask', 'ask_planned', [ 'ask_key' => self::KEY, 'owner' => self::READER, 'searches' => self::SEARCHES ] );
		$planned['ts']    = (float) ( self::$oct_20 - Ask_Node::MAX_WAIT_S - 20 );
		$this->seed( self::asked( self::KEY, self::READER, self::$oct_20 - Ask_Node::MAX_WAIT_S - 30 ), [ $planned ] );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ 'failed', 'the ask waited past ' . Ask_Node::MAX_WAIT_S . ' seconds; ask again', self::SEARCHES ], [ $answer['state'], $answer['reason'], $answer['searches'] ] );
	}

	/**
	 * A sentence whose source the reader can no longer read, as one a
	 * curator excluded since, is not shown and is counted hidden.
	 */
	public function test_a_sentence_whose_source_the_reader_lost_is_counted_hidden(): void {
		$this->seed(
			self::asked( self::KEY, self::READER ),
			self::answered( self::KEY, self::READER, [ [ 'The Ledger launched a newsletter tool.', [ 1 ] ], [ 'The Gazette won a state award.', [ 2 ] ] ], [ 'github:ledger-launch', 'feed:gazette-award' ] ),
			[ Intel_Event::make( 'item_excluded', [ 'item_key' => 'github:ledger-launch', 'url' => self::url( 'github:ledger-launch' ), 'title' => 'Salem Ledger launches a newsletter tool', 'tier' => 'internal' ], 'user:41', 'fixture-9' ) ]
		);

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ [ 'text' => 'The Gazette won a state award.', 'cites' => [ 1 ] ] ], $answer['sentences'] );
		$this->assertSame( [ 1, 1 ], [ $answer['hidden'], $answer['dropped'] ] );
	}

	/** A failed ask reads as failed, with its reason. */
	public function test_a_failed_ask_reads_its_reason(): void {
		$this->seed( self::asked( self::KEY, self::READER ), [ Intel_Event::machine_event( 'ask', 'ask_failed', [ 'ask_key' => self::KEY, 'owner' => self::READER, 'reason' => 'the model call failed', 'searches' => [ [ 'words' => 'quay' ] ] ] ) ] );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ 'failed', 'the model call failed', [], [], [ [ 'words' => 'quay' ] ] ], [ $answer['state'], $answer['reason'], $answer['sentences'], $answer['items'], $answer['searches'] ] );
	}

	/** An answered ask reads its sentences, and each item they cite as search answers it, in citation order. */
	public function test_an_answer_reads_its_cited_items(): void {
		$this->seed( self::asked( self::KEY, self::READER ), self::answered( self::KEY, self::READER, [ [ 'The Ledger launched a newsletter tool.', [ 1 ] ], [ 'Both newsrooms had a strong month.', [ 1, 2 ] ] ], [ 'github:ledger-launch', 'feed:gazette-award' ] ) );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ 'answered', 3, 1, self::SEARCHES ], [ $answer['state'], $answer['found'], $answer['dropped'], $answer['searches'] ] );
		$this->assertSame( [ [ 'text' => 'The Ledger launched a newsletter tool.', 'cites' => [ 1 ] ], [ 'text' => 'Both newsrooms had a strong month.', 'cites' => [ 1, 2 ] ] ], $answer['sentences'] );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award' ], \array_column( $answer['items'], 'item_key' ) );
		$this->assertSame( [ 'Salem Ledger launches a newsletter tool', 'The Ledger shipped a newsletter tool.', 'launch' ], [ $answer['items'][0]['title'], $answer['items'][0]['summary'], $answer['items'][0]['kind'] ] );
	}

	/**
	 * Another reader's ask is refused exactly as one never asked, so the command
	 * never tells the two apart.
	 */
	public function test_ask_result_answers_only_the_callers_own_ask(): void {
		$this->seed( self::asked( self::KEY, self::NEIGHBOR ), self::answered( self::KEY, self::NEIGHBOR, [ [ 'The Gazette won.', [ 1 ] ] ], [ 'feed:gazette-award' ] ) );

		foreach ( [ self::KEY, '0f1e2d3c4b5a6978' ] as $key ) {
			try {
				$this->ci->dispatch( 'ask_result', [ $key ] );
				$this->fail( "{$key} is refused" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( "no ask: {$key}", $e->getMessage() );
			}
		}
	}

	/**
	 * An answer written from a restricted item is withheld whole once the
	 * caller no longer reads that tier, since the model wrote every sentence
	 * from every item it was given: a sentence citing only a public item
	 * may carry what the restricted one said.
	 */
	public function test_a_revoked_grant_withholds_an_answer_written_from_restricted_items(): void {
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->seed(
			self::asked( self::KEY, self::HOLDER ),
			self::answered( self::KEY, self::HOLDER, [ [ 'The Gazette won a state award while it weighs leaving.', [ 1 ] ] ], [ 'feed:gazette-award' ], [ 'public', 'restricted' ] )
		);
		$this->assertSame( 'The Gazette won a state award while it weighs leaving.', $this->ci->dispatch( 'ask_result', [ self::KEY ] )['sentences'][0]['text'] );

		Intel_Tiers::revoke( 'quay-analyst', 'user:quay-reader' );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );
		$this->assertSame(
			[ 'withheld', 'the answer was written from items you can no longer read', [], [], 0, 0 ],
			[ $answer['state'], $answer['reason'], $answer['sentences'], $answer['items'], $answer['found'], $answer['dropped'] ]
		);
		$this->assertStringNotContainsString( 'leaving', (string) \wp_json_encode( $answer ) );
	}

	/**
	 * A sentence citing an item the caller cannot read now, as one expired
	 * or purged, is not shown, and the rest are numbered again from 1.
	 */
	public function test_a_sentence_citing_an_item_gone_since_is_not_shown(): void {
		$this->seed( self::asked( self::KEY, self::READER ), self::answered( self::KEY, self::READER, [ [ 'An old story ran.', [ 1 ] ], [ 'The Gazette won a state award.', [ 2 ] ] ], [ 'feed:gazette-expired', 'feed:gazette-award' ] ) );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ [ 'text' => 'The Gazette won a state award.', 'cites' => [ 1 ] ] ], $answer['sentences'] );
		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $answer['items'], 'item_key' ) );
	}

	/** A sentence citing an item a curator excluded since is not shown, and the rest are numbered again from 1. */
	public function test_a_sentence_citing_an_excluded_item_is_not_shown(): void {
		$this->seed(
			self::asked( self::KEY, self::READER ),
			self::answered( self::KEY, self::READER, [ [ 'The Ledger launched a newsletter tool.', [ 1 ] ], [ 'The Gazette won a state award.', [ 2 ] ] ], [ 'github:ledger-launch', 'feed:gazette-award' ] ),
			[ Intel_Event::make( 'item_excluded', [ 'item_key' => 'github:ledger-launch', 'url' => self::url( 'github:ledger-launch' ), 'title' => 'Salem Ledger launches a newsletter tool', 'tier' => 'internal' ], 'user:41', 'fixture-9' ) ]
		);

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ [ 'text' => 'The Gazette won a state award.', 'cites' => [ 1 ] ] ], $answer['sentences'] );
		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $answer['items'], 'item_key' ) );
	}

	/** An ask the node never answered reads as failed once it has waited past MAX_WAIT_S, so nothing polls it forever. */
	public function test_an_ask_waiting_past_its_wait_reads_failed(): void {
		$this->seed( self::asked( self::KEY, self::READER, self::$oct_20 - Ask_Node::MAX_WAIT_S - 30 ) );

		$answer = $this->ci->dispatch( 'ask_result', [ self::KEY ] );

		$this->assertSame( [ 'failed', 'the ask waited past ' . Ask_Node::MAX_WAIT_S . ' seconds; ask again' ], [ $answer['state'], $answer['reason'] ] );
	}

	/** A question replayed long after it was answered leaves the answer standing. */
	public function test_an_answer_survives_its_question_replayed(): void {
		$this->seed(
			self::asked( self::KEY, self::READER, self::$oct_20 - 60 ),
			self::answered( self::KEY, self::READER, [ [ 'The Gazette won a state award.', [ 1 ] ] ], [ 'feed:gazette-award' ] ),
			self::asked( self::KEY, self::READER, self::$oct_20 - 60 )
		);
		Core::$now = (float) ( self::$oct_20 + Ask_Node::MAX_WAIT_S * 4 );

		$this->assertSame( 'answered', $this->ci->dispatch( 'ask_result', [ self::KEY ] )['state'] );
	}

	/** A projection that will not open answers the ask as unavailable. */
	public function test_no_projection_answers_unavailable(): void {
		Intel_Graph_Row_Schema::$read_rows = static fn (): mixed => throw new \Newspack_Nodes\Table_Unavailable( 'intel-graph is not open' );

		$this->assertFalse( $this->ci->dispatch( 'ask_result', [ self::KEY ] )['available'] );
	}

	/**
	 * An `ask_asked` as the command writes it.
	 *
	 * @param string   $key   The ask's key.
	 * @param int      $owner Who asked.
	 * @param int|null $ts    When it was asked; the tick when null.
	 * @return list<array<string,mixed>>
	 */
	private static function asked( string $key, int $owner, ?int $ts = null ): array {
		$event = Intel_Event::make( 'ask_asked', [ 'ask_key' => $key, 'owner' => $owner, 'question' => 'How did the month go?' ], 'user:quay-asker', 'fixture-9' );
		if ( null !== $ts ) {
			$event['ts'] = (float) $ts;
		}
		return [ $event ];
	}

	/**
	 * An `ask_answered` as the Ask node writes it, three items found and one sentence dropped.
	 *
	 * @param string                                  $key       The ask's key.
	 * @param int                                     $owner     Who asked.
	 * @param list<array{0:string,1:list<int>}>       $sentences Each sentence and the items it cites.
	 * @param list<string>                            $items     The items cited, in citation order.
	 * @param list<string>                            $tiers     The tiers of the items it was given.
	 * @return list<array<string,mixed>>
	 */
	private static function answered( string $key, int $owner, array $sentences, array $items, array $tiers = [ 'public', 'internal' ] ): array {
		$said = \array_map( static fn ( array $sentence ): array => [ 'text' => $sentence[0], 'cites' => $sentence[1] ], $sentences );
		return [ Intel_Event::machine_event( 'ask', 'ask_answered', [ 'ask_key' => $key, 'owner' => $owner, 'found' => 3, 'dropped' => 1, 'sentences' => $said, 'items' => $items, 'tiers' => $tiers, 'searches' => self::SEARCHES ] ) ];
	}

	/**
	 * Every event the asks stream's partition-0 directory holds, oldest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function asks_events(): array {
		return self::events_in( Config::get_logs_directory() . '/intel-asks.p0' );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Items_CLI_Command;
use Newspack_Intelligence\Graph_Curation;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Worker_Route;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config;
use Newspack_Nodes\Message;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Worker_Base;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/routes-to-workers.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * `wp newspack-intelligence items purge` selects a source's stored items its
 * keep globs leave and appends one `item_purged` each to every live
 * worker's writer, a batch at a time, each only once the workers read the
 * one before; with `--dry-run` it only counts them per key prefix.
 */
final class ItemsCliCommandTest extends TestCase {
	use Routes_To_Workers;
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const WORKER = 'newspack-intelligence.p0';

	private const KEEP = 'linear:HERON-*,linear:PLOVER-*';

	/** The dry run a purge names to confirm nothing of the KEEP selection is left. */
	private const CONFIRM = "wp newspack-intelligence items purge --source='linear' --keep='linear:HERON-*,linear:PLOVER-*' --dry-run";

	/** The process's argv, which a test replaces and tearDown puts back. */
	private mixed $argv = null;

	protected function setUp(): void {
		parent::setUp();
		$this->argv = $_SERVER['argv'];
		\WP_CLI::reset();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		$this->activate( 'newspack-intelligence' );
		self::live_worker( Config::get_base_directory(), self::WORKER );
	}

	protected function tearDown(): void {
		$_SERVER['argv']          = $this->argv;
		self::forget_users();
		$GLOBALS['_current_user_can'] = false;
		Items_CLI_Command::$sleep = null;
		CLI::$uid_provider        = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** A dry run counts the selection per key prefix, names the command that purges it, and writes nothing. */
	public function test_a_dry_run_counts_each_prefix_and_writes_nothing(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP, 'dry-run' => true ] );

		$this->assertSame(
			[
				"1\tlinear:EGRET",
				"2\tlinear:WREN",
				'Total: 3 to purge of linear items, keeping linear:HERON-*, linear:PLOVER-*.',
				"Purge them with: wp newspack-intelligence items purge --source='linear' --keep='linear:HERON-*,linear:PLOVER-*'",
			],
			\WP_CLI::$logs
		);
		$this->assertSame( [ [], [] ], [ \WP_CLI::$errors, \WP_CLI::$successes ] );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), self::WORKER ) );
	}

	/** The command a dry run names carries the reason it was given. */
	public function test_a_dry_run_names_the_reason_it_was_given(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP, 'reason' => "Not our team's", 'dry-run' => true ] );

		$this->assertSame( "Purge them with: wp newspack-intelligence items purge --source='linear' --keep='linear:HERON-*,linear:PLOVER-*' --reason='Not our team'\\''s'", \WP_CLI::$logs[3] );
	}

	/** A purge appends one machine `item_purged` per selected item to the writer of every live worker. */
	public function test_a_purge_appends_one_purge_per_selected_item_to_the_writer(): void {
		$this->seed_teams();
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p1' );

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP, 'reason' => 'other teams, before 0.22.0' ] );

		foreach ( [ self::WORKER, 'newspack-intelligence.p1' ] as $worker_id ) {
			$written = self::worker_input( Config::get_base_directory(), $worker_id );
			$this->assertSame( [ Message::TM_STRUCT ], \array_values( \array_unique( \array_column( $written, Message::TYPE ) ) ) );
			$this->assertSame( [ 'intel:writer' ], \array_values( \array_unique( \array_column( $written, Message::TO ) ) ) );
			$events = \array_column( $written, Message::VALUE );
			foreach ( $events as $event ) {
				Intel_Event::validate( $event );
			}
			$this->assertSame(
				[
					[ 'item_purged', 'machine:purge@' . \NEWSPACK_INTELLIGENCE_VERSION, 'linear:EGRET-9', 'other teams, before 0.22.0' ],
					[ 'item_purged', 'machine:purge@' . \NEWSPACK_INTELLIGENCE_VERSION, 'linear:WREN-1', 'other teams, before 0.22.0' ],
					[ 'item_purged', 'machine:purge@' . \NEWSPACK_INTELLIGENCE_VERSION, 'linear:WREN-2', 'other teams, before 0.22.0' ],
				],
				\array_map( static fn ( array $e ): array => [ $e['event'], $e['actor'], $e['item_key'], $e['reason'] ], $events ),
				$worker_id
			);
		}
		$this->assertSame( [ 'Sent 3 of 3.' ], \WP_CLI::$logs );
		$this->assertSame( [ 'Sent 3 item_purged events in 1 batch to 2 workers. Once their projectors have read them, confirm nothing is left with the dry run, which then answers Nothing to purge: ' . self::CONFIRM ], \WP_CLI::$successes );
	}

	/** With no `--reason`, each purge names the selection it was made by. */
	public function test_a_purge_names_its_selection_without_a_reason(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP ] );

		$this->assertSame( [ 'linear items, keeping linear:HERON-*, linear:PLOVER-*' ], \array_values( \array_unique( \array_column( \array_column( self::worker_input( Config::get_base_directory(), self::WORKER ), Message::VALUE ), 'reason' ) ) ) );
	}

	/** Each batch is appended only once every worker has read the one before. */
	public function test_each_batch_waits_for_the_workers_to_read_the_last(): void {
		$events = [];
		for ( $n = 1; $n <= Items_CLI_Command::BATCH + 1; $n++ ) {
			$events[] = self::item( "linear:WREN-{$n}", "WREN issue {$n}", 'linear', 'internal', self::$oct_06 );
		}
		$this->seed( ...$events );
		$base                     = Config::get_base_directory();
		$waits                    = [];
		Items_CLI_Command::$sleep = static function ( int $seconds ) use ( &$waits, $base ): void {
			$waits[] = [ $seconds, \count( self::worker_input( $base, self::WORKER ) ) ];
			self::read_input( $base, self::WORKER );
		};

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => 'linear:HERON-*' ] );

		$this->assertSame( [ [ 5, Items_CLI_Command::BATCH ] ], $waits, 'one wait, after the first batch alone' );
		$this->assertCount( Items_CLI_Command::BATCH + 1, self::worker_input( $base, self::WORKER ) );
		$this->assertSame( [ 'Sent 500 of 501.', 'Sent 501 of 501.' ], \WP_CLI::$logs );
		$this->assertSame( [ "Sent 501 item_purged events in 2 batches to 1 worker. Once its projector has read them, confirm nothing is left with the dry run, which then answers Nothing to purge: wp newspack-intelligence items purge --source='linear' --keep='linear:HERON-*' --dry-run" ], \WP_CLI::$successes );
	}

	/** A batch the workers do not read in time stops the purge, saying how far it got and how to finish. */
	public function test_a_batch_left_unread_stops_the_purge(): void {
		$events = [];
		for ( $n = 1; $n <= Items_CLI_Command::BATCH + 1; $n++ ) {
			$events[] = self::item( "linear:WREN-{$n}", "WREN issue {$n}", 'linear', 'internal', self::$oct_06 );
		}
		$this->seed( ...$events );
		$waited                   = 0;
		Items_CLI_Command::$sleep = static function ( int $seconds ) use ( &$waited ): void {
			$waited += $seconds;
		};

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => 'linear:HERON-*' ] );

		$this->assertSame( Items_CLI_Command::READ_WAIT_S, $waited );
		$this->assertCount( Items_CLI_Command::BATCH, self::worker_input( Config::get_base_directory(), self::WORKER ) );
		$this->assertSame( [ 'Sent 500 of 501 item_purged events; the workers did not read their input within 600 seconds. Run the command again once they have: it selects only the items the projection still holds.' ], \WP_CLI::$errors );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	/** The first batch, too, waits until every worker has read what its input already held. */
	public function test_the_first_batch_waits_for_a_backlog_the_workers_have_not_read(): void {
		$this->seed_teams();
		$base = Config::get_base_directory();
		Worker_Route::send( Bootstrap::mount_request_graph(), $base, [ [ 'reresolver', 'RERESOLVE 2026-02-03 2026-02-04' ] ] );
		$waits                    = [];
		Items_CLI_Command::$sleep = static function ( int $seconds ) use ( &$waits, $base ): void {
			$waits[] = \array_column( self::worker_input( $base, self::WORKER ), Message::VALUE );
			self::read_input( $base, self::WORKER );
		};

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP ] );

		$this->assertSame( [ [ 'RERESOLVE 2026-02-03 2026-02-04' ] ], $waits, 'one wait, before any purge was sent' );
		$this->assertCount( 4, self::worker_input( $base, self::WORKER ) );
	}

	/**
	 * The worst batch — BATCH events whose item key and reason each fill
	 * their cap in the bytes the wire carries, every character escaped as
	 * far as JSON escapes one — packs under one IPC segment, so none
	 * outruns the input's retention.
	 */
	public function test_the_largest_batch_packs_under_one_ipc_segment(): void {
		$fill                      = static function ( int $cap ): string {
			$text = '';
			for ( $n = 0; ; $n++ ) {
				$next = $text . ( 0 === $n % 2 ? "\x01" : "\u{1F600}" );
				if ( Graph_Curation::written_bytes( $next ) > $cap ) {
					return $text;
				}
				$text = $next;
			}
		};
		$event                     = Intel_Event::machine_event( 'purge', 'item_purged', [ 'item_key' => $fill( Graph_Curation::ITEM_KEY_BYTES ), 'reason' => $fill( Items_CLI_Command::REASON_BYTES ) ] );
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = '_command_interpreter';
		$message[ Message::TO ]    = 'intel:writer';
		$message[ Message::VALUE ] = $event;

		$this->assertGreaterThan( Items_CLI_Command::REASON_BYTES - 12, Graph_Curation::written_bytes( $event['reason'] ) );
		$this->assertLessThan( Worker_Base::IPC_SEGMENT_SIZE, Items_CLI_Command::BATCH * ( Message::packed_size( $message ) + 1 ) );
	}

	/** A repeated `--keep`, of which WP-CLI keeps only the last, is refused and nothing is sent. */
	public function test_a_repeated_keep_is_refused(): void {
		$this->seed_teams();
		$_SERVER['argv'] = [ 'wp', 'newspack-intelligence', 'items', 'purge', '--source=linear', '--keep=linear:HERON-*', '--keep', '--keep=linear:PLOVER-*' ];

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => 'linear:PLOVER-*' ] );

		$this->assertSame( [ "--keep was given 3 times, and WP-CLI keeps only the last; pass one comma-separated list: --keep='<glob>,<glob>'." ], \WP_CLI::$errors );
		$this->assertSame( [ [], [] ], [ \WP_CLI::$logs, \WP_CLI::$successes ] );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), self::WORKER ) );
	}

	/** A key prefix prints its control characters as codepoints, never as raw bytes. */
	public function test_a_prefix_prints_its_control_characters_as_codepoints(): void {
		$this->seed( self::item( "linear:WP\x1b[2JCOM-5", 'Escape', 'linear', 'internal', self::$oct_06 ) );

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP, 'dry-run' => true ] );

		$this->assertSame( "1\tlinear:WP<1B>[2JCOM", \WP_CLI::$logs[0] );
	}

	/** A glob matches the whole key, in its own case. */
	public function test_a_glob_matches_the_whole_key_in_its_own_case(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => 'linear:heron-*,PLOVER-*,linear:WREN-?', 'dry-run' => true ] );

		$this->assertSame( [ "1\tlinear:EGRET", "1\tlinear:HERON", "1\tlinear:PLOVER" ], \array_slice( \WP_CLI::$logs, 0, 3 ) );
	}

	/** A selection the keep globs leave empty writes nothing, purged or dry run. */
	public function test_an_empty_selection_writes_nothing(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => 'linear:*' ] );
		( new Items_CLI_Command() )->purge( [], [ 'source' => 'hubspot', 'keep' => 'hubspot:deal-*', 'dry-run' => true ] );

		$this->assertSame( [ 'Nothing to purge: linear items, keeping linear:*.', 'Nothing to purge: hubspot items, keeping hubspot:deal-*.' ], \WP_CLI::$successes );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), self::WORKER ) );
	}

	/**
	 * A selection or a reason that does not read is refused before anything
	 * is read or written.
	 *
	 * @return array<string,array{0:array<string,mixed>,1:string}>
	 */
	public static function refusals(): array {
		return [
			'no source'        => [ [ 'keep' => self::KEEP ], '--source names the source whose items are purged.' ],
			'a blank source'   => [ [ 'source' => ' ' ], '--source names the source whose items are purged.' ],
			'an empty glob'    => [ [ 'source' => 'linear', 'keep' => 'linear:HERON-*,' ], '--keep holds an empty glob; separate the globs by single commas.' ],
			'a bare keep'      => [ [ 'source' => 'linear', 'keep' => true ], '--keep holds an empty glob; separate the globs by single commas.' ],
			'no keep'          => [ [ 'source' => 'linear' ], '--keep names the items to keep; a purge never takes every item of a source.' ],
			'a blank reason'   => [ [ 'source' => 'linear', 'keep' => self::KEEP, 'reason' => '' ], '--reason says why the items are purged, in 1 to 512 bytes as the stream writes it.' ],
			'a long reason'    => [ [ 'source' => 'linear', 'keep' => self::KEEP, 'reason' => \str_repeat( 'r', 513 ) ], '--reason says why the items are purged, in 1 to 512 bytes as the stream writes it.' ],
			'an escaped reason' => [ [ 'source' => 'linear', 'keep' => self::KEEP, 'reason' => \str_repeat( "\x01", 100 ) ], '--reason says why the items are purged, in 1 to 512 bytes as the stream writes it.' ],
			'a long selection' => [ [ 'source' => 'linear', 'keep' => \implode( ',', \array_fill( 0, 60, 'linear:HERON-*' ) ) ], '--reason says why the items are purged, in 1 to 512 bytes as the stream writes it.' ],
		];
	}

	/**
	 * @param array<string,mixed> $flags   The flags.
	 * @param string              $refusal What the command says.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_bad_selection_or_reason_is_refused( array $flags, string $refusal ): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->purge( [], $flags );

		$this->assertSame( [ $refusal ], \WP_CLI::$errors );
		$this->assertSame( [ [], [] ], [ \WP_CLI::$logs, \WP_CLI::$successes ] );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), self::WORKER ) );
	}

	/** Run as root, the purge refuses before it reads or writes anything. */
	public function test_the_purge_refuses_root(): void {
		$this->seed_teams();
		CLI::$uid_provider = static fn (): int => 0;

		( new Items_CLI_Command() )->purge( [], [ 'source' => 'linear', 'keep' => self::KEEP ] );

		$this->assertSame( [ 'wp newspack-intelligence items purge must run as the same user as the workers, not root.' ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), self::WORKER ) );
	}

	/** A purge runs when invoked; its synopsis declares no confirmation flag. */
	public function test_purge_declares_no_yes_option(): void {
		$doc = (string) ( new \ReflectionMethod( Items_CLI_Command::class, 'purge' ) )->getDocComment();
		$this->assertStringNotContainsString( '--yes', $doc );
	}

	/** WP-CLI refuses a flag the synopsis does not declare, so `--dry-run` is declared. */
	public function test_purge_declares_a_dry_run_option(): void {
		$doc = (string) ( new \ReflectionMethod( Items_CLI_Command::class, 'purge' ) )->getDocComment();
		$this->assertStringContainsString( '[--dry-run]', $doc );
	}

	public function test_the_items_command_is_registered(): void {
		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Items_CLI_Command', \WP_CLI::$commands['newspack-intelligence items'] ?? null );
	}

	/**
	 * `items exclude`, `items include` and `items excluded` run the
	 * `intelligence` CI's own commands, as the person `--user` signs in, and
	 * `excluded` prints the item's current title terminal-safe.
	 */
	public function test_exclude_include_and_excluded_run_the_curation_commands(): void {
		$this->seed_teams();
		self::sign_in( 52, 'heron-curator', 'manage_options' );
		$GLOBALS['_current_user_can'] = true;
		$cli                          = new Items_CLI_Command();

		$cli->exclude_item( [ 'linear:EGRET-9' ], [ 'why' => 'Belongs to another desk.' ] );
		$cli->include_item( [ 'linear:WREN-2' ], [] );
		$this->seed(
			[
				Intel_Event::make( 'item_grown', [ 'item_key' => 'linear:HERON-7', 'source' => 'linear', 'tier' => 'internal', 'url' => self::url( 'linear:HERON-7' ), 'title' => "Newsletter\tblock\e[2J", 'body' => '', 'item_ts' => self::$oct_06, 'retrieved_ts' => self::$oct_06 + 600 ], self::$machine, 'fixture-9' ),
				Intel_Event::make( 'item_excluded', [ 'item_key' => 'linear:HERON-7', 'url' => self::url( 'linear:HERON-7' ), 'title' => 'Newsletter block', 'tier' => 'internal', 'why' => 'A duplicate.' ], 'user:heron-curator', 'fixture-9' ),
				Intel_Event::make( 'item_excluded', [ 'item_key' => 'linear:WREN-2', 'url' => self::url( 'linear:WREN-2' ), 'title' => 'Checkout flow', 'tier' => 'internal' ], 'user:heron-curator', 'fixture-9' ),
			]
		);
		$cli->excluded( [], [ 'limit' => '1' ] );

		$events = self::ontology_events();
		$this->assertSame( [ [ 'item_excluded', 'linear:EGRET-9', 'Belongs to another desk.', 'user:heron-curator' ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['item_key'], $e['why'], $e['actor'] ], \array_slice( $events, 0, 1 ) ) );
		$this->assertSame( [ 'Excluded linear:EGRET-9 from every view; bring it back with: wp newspack-intelligence items include \'linear:EGRET-9\'' ], \WP_CLI::$successes );
		$this->assertSame( [ 'linear:WREN-2 is not excluded' ], \WP_CLI::$errors );
		$this->assertSame( [ "linear:HERON-7\tNewsletter block<1B>[2J\t" . self::url( 'linear:HERON-7' ) . "\tuser:heron-curator\t2026-10-20 12:00\tA duplicate." ], \WP_CLI::$logs );
	}

	/**
	 * An exclusion whose item's rows are gone prints the copy it stored,
	 * its title terminal-safe and its URL as stored.
	 */
	public function test_excluded_prints_the_stored_copy_of_a_purged_item(): void {
		$this->seed_teams();
		self::sign_in( 52, 'heron-curator', 'manage_options' );
		$GLOBALS['_current_user_can'] = true;
		$this->seed(
			[
				Intel_Event::make( 'item_excluded', [ 'item_key' => 'linear:PLOVER-3', 'url' => 'https://plover.example/stored-3', 'title' => "Stored\e[1m summaries", 'tier' => 'internal', 'why' => 'Gone for good.' ], 'user:heron-curator', 'fixture-9' ),
				Intel_Event::make( 'item_purged', [ 'item_key' => 'linear:PLOVER-3', 'reason' => 'plover desk retired' ], 'machine:purge@9.4.1', 'fixture-9' ),
			]
		);

		( new Items_CLI_Command() )->excluded( [], [ 'limit' => '5' ] );

		$this->assertSame( [ "linear:PLOVER-3\tStored<1B>[1m summaries\thttps://plover.example/stored-3\tuser:heron-curator\t2026-10-20 12:00\tGone for good." ], \WP_CLI::$logs );
	}

	/** An exclusion records who made it, so a run with no `--user` is refused before it reads. */
	public function test_an_exclusion_without_a_user_is_refused(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->exclude_item( [ 'linear:EGRET-9' ], [] );

		$this->assertSame( [ 'wp newspack-intelligence items exclude records who excluded the item; run it with --user=<login>.' ], \WP_CLI::$errors );
		$this->assertSame( [], self::ontology_events() );
	}

	/** With nothing excluded, the list says so. */
	public function test_an_empty_excluded_list_says_so(): void {
		$this->seed_teams();

		( new Items_CLI_Command() )->excluded( [], [] );

		$this->assertSame( [ 'No item is excluded.' ], \WP_CLI::$successes );
	}

	/** Linear issues of two kept teams and two others, and a GitHub item. */
	private function seed_teams(): void {
		$this->seed(
			self::item( 'linear:WREN-2', 'Checkout flow', 'linear', 'internal', self::$oct_13 ),
			self::item( 'linear:HERON-7', 'Newsletter block', 'linear', 'internal', self::$oct_06 ),
			self::item( 'linear:EGRET-9', 'Domain search', 'linear', 'internal', self::$oct_06 ),
			self::item( 'linear:WREN-1', 'Plans page', 'linear', 'internal', self::$oct_01 ),
			self::item( 'linear:PLOVER-3', 'Summaries', 'linear', 'internal', self::$oct_01 ),
			self::item( 'github:ledger-launch', 'Salem Ledger launches a newsletter tool', 'github', 'internal', self::$oct_13 )
		);
	}
}

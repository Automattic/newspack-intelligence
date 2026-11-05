<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Ontology_CLI_Command;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Intelligence\Ontology_Seed;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Config;
use Newspack_Nodes\Message;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/routes-to-workers.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * `wp newspack-intelligence ontology seed` writes the seed keys the
 * projection lacks to the ontology stream and reports what it found.
 */
final class OntologyCliCommandTest extends TestCase {
	use Reads_Ontology_Log;
	use Routes_To_Workers;
	use Seeds_Entity_Index;
	use Signs_In_Users;

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		self::place_seed();
		$this->activate( 'newspack-intelligence-project' );
		$this->run_intelligence_as( 'skua-intel' );
	}

	protected function tearDown(): void {
		self::forget_users();
		unset( $GLOBALS['_current_user_can'] );
		CLI::$uid_provider = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_seed_writes_the_keys_the_projection_lacks(): void {
		$this->seed( self::entity( 'product:kite-ledger', 'Ledger (curated)' ) );

		( new Ontology_CLI_Command() )->seed( [], [] );

		$written = self::ontology_events();
		$this->assertSame( \array_column( Ontology_Seed::load()->events( [ 'product:kite-ledger' ] ), 'entity_key' ), \array_column( $written, 'entity_key' ) );
		$this->assertNotContains( 'product:kite-ledger', \array_column( $written, 'entity_key' ) );
		$this->assertSame( [ \sprintf( 'Seeded %d events: 7 keys new, 1 present.', \count( $written ) ) ], \WP_CLI::$successes );
	}

	/** A path given reads that file in place of the data dir's. */
	public function test_seed_reads_a_path_given(): void {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/heron-seed.json';
		\file_put_contents( $path, '{"entities":{"topic":{"salt-marsh":{"name":"Salt marsh"}}},"lenses":{}}' );

		( new Ontology_CLI_Command() )->seed( [ $path ], [] );

		$this->assertSame( [ 'topic:salt-marsh' ], \array_column( self::ontology_events(), 'entity_key' ) );
		$this->assertSame( [ 'Seeded 1 events: 1 keys new, 0 present.' ], \WP_CLI::$successes );
	}

	/**
	 * With no seed in the data dir, the command and the `seed_ontology` verb
	 * each refuse naming, to a manager, the path it belongs at, and write
	 * nothing.
	 */
	public function test_seed_with_no_seed_file_refuses_naming_where_it_belongs(): void {
		\unlink( Ontology_Seed::path() );
		self::sign_in( 5167, 'shrike-curator', 'manage_options' );
		$GLOBALS['_current_user_can'] = true;
		$ci                           = new Intelligence_CI_Node();
		$ci->name( 'intelligence' );

		( new Ontology_CLI_Command() )->seed( [], [] );
		try {
			$ci->dispatch( 'seed_ontology', [] );
			$this->fail( 'the verb seeds with no seed file' );
		} catch ( \Newspack_Intelligence\Seed_Refused $e ) {
			$verb = $e->getMessage();
		}

		$this->assertCount( 1, \WP_CLI::$errors );
		$this->assertSame( 'No newspack-intelligence-ontology-seed.json: write a seed for this site to ' . Ontology_Seed::path() . ', starting from the example the plugin ships at examples/newspack-intelligence-ontology-seed.json.', \WP_CLI::$errors[0] );
		$this->assertSame( \WP_CLI::$errors[0], $verb );
		$this->assertSame( [], self::ontology_events() );
	}

	/** A tune caller's `seed_ontology` refusal names no absolute path, neither the data dir nor the plugin's. */
	public function test_a_tune_callers_seed_refusal_names_no_absolute_path(): void {
		\unlink( Ontology_Seed::path() );
		self::sign_in( 5291, 'dipper-curator', 'manage_options' );
		$GLOBALS['_current_user_can']                = true;
		\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::TUNE;
		$ci                                          = new Intelligence_CI_Node();
		$ci->name( 'intelligence' );

		try {
			$ci->dispatch( 'seed_ontology', [] );
			$this->fail( 'the verb seeds with no seed file' );
		} catch ( \Newspack_Intelligence\Seed_Refused $e ) {
			$refusal = $e->getMessage();
		}

		$this->assertStringNotContainsString( \dirname( Ontology_Seed::path() ), $refusal );
		$this->assertStringNotContainsString( \NEWSPACK_INTELLIGENCE_DIR, $refusal );
		$this->assertStringContainsString( 'in its Intelligence data dir, starting from the example the plugin ships at examples/newspack-intelligence-ontology-seed.json.', $refusal );
	}

	/** A tune caller's `seed_ontology` refusal of a bad seed names the file by its name alone, and nothing is written. */
	public function test_a_tune_callers_bad_seed_refusal_names_no_absolute_path(): void {
		\file_put_contents( Ontology_Seed::path(), '{"entities":{"organization":{"gull-fund":{"name":"Gull Fund","roles":["patron"]}}},"lenses":{}}' );
		self::sign_in( 5303, 'phalarope-curator', 'manage_options' );
		$GLOBALS['_current_user_can']                = true;
		\Newspack_Nodes\Capabilities::$session_scope = \Newspack_Nodes\Capabilities::TUNE;
		$ci                                          = new Intelligence_CI_Node();
		$ci->name( 'intelligence' );

		try {
			$ci->dispatch( 'seed_ontology', [] );
			$this->fail( 'the verb seeds a bad file' );
		} catch ( \Newspack_Intelligence\Seed_Refused $e ) {
			$refusal = $e->getMessage();
		}

		$this->assertSame( 'newspack-intelligence-ontology-seed.json: entities.organization.gull-fund.roles: role_asserted: field role is outside its set', $refusal );
		$this->assertSame( [], self::ontology_events() );
	}

	/** A file the seed refuses reaches WP-CLI's error naming the field, and nothing is written. */
	public function test_seed_refuses_a_bad_file_naming_the_field(): void {
		$path = $this->make_temp_dir( 'intel-seed-' ) . '/heron-seed.json';
		\file_put_contents( $path, '{"entities":{"organization":{"gull-fund":{"name":"Gull Fund","roles":["patron"]}}},"lenses":{}}' );

		( new Ontology_CLI_Command() )->seed( [ $path ], [] );

		$this->assertSame( [ 'heron-seed.json: entities.organization.gull-fund.roles: role_asserted: field role is outside its set' ], \WP_CLI::$errors );
		$this->assertSame( [], self::ontology_events() );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	/** The CLI and the `seed_ontology` verb run one seed, so each reports the same counts. */
	public function test_cli_seed_and_verb_share_seed(): void {
		$this->seed( self::entity( 'product:kite-ledger', 'Ledger (curated)' ), self::entity( 'organization:quarry-guild', 'Quarry (curated)' ) );
		self::sign_in( 5153, 'tern-curator', 'manage_options' );
		$GLOBALS['_current_user_can'] = true;
		$ci                           = new Intelligence_CI_Node();
		$ci->name( 'intelligence' );

		$answer = $ci->dispatch( 'seed_ontology', [] );
		self::commit_ontology_cursor();
		( new Ontology_CLI_Command() )->seed( [], [] );

		$this->assertSame( [ 'ok' => true, 'events' => \count( Ontology_Seed::load()->events( [ 'organization:quarry-guild', 'product:kite-ledger' ] ) ), 'created' => 6, 'present' => 2 ], $answer );
		$this->assertSame( [ \sprintf( 'Seeded %d events: %d keys new, %d present.', $answer['events'], $answer['created'], $answer['present'] ) ], \WP_CLI::$successes );
	}

	/** Ontology changes the projector has not read yet refuse a seed through WP-CLI's error, writing nothing. */
	public function test_seed_refuses_while_the_last_is_unprojected(): void {
		( new Ontology_CLI_Command() )->seed( [], [] );
		$written = \count( self::ontology_events() );

		( new Ontology_CLI_Command() )->seed( [], [] );

		$this->assertSame( [ 'Recent ontology changes are still being processed; try again in a minute.' ], \WP_CLI::$errors );
		$this->assertSame( $written, \count( self::ontology_events() ) );
		$this->assertCount( 1, \WP_CLI::$successes );
	}

	public function test_seed_fails_loud_without_a_projection(): void {
		$this->activate();

		try {
			( new Ontology_CLI_Command() )->seed( [], [] );
			$this->fail( 'the seed refuses' );
		} catch ( Table_Unavailable ) {
			$this->assertSame( [], self::ontology_events() );
			$this->assertSame( [], \WP_CLI::$successes );
		}
	}

	/** `reresolve` asks each live worker's reresolver to re-resolve a day range. */
	public function test_reresolve_routes_the_range_to_the_reresolver(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );

		$this->assertSame(
			[ [ Message::TM_REQUEST, 'reresolver', 'RERESOLVE 2026-03-09 2026-04-17' ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::TO ], $m[ Message::VALUE ] ], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) )
		);
		$this->assertSame( [ 'Asked 1 worker to re-resolve the items dated 2026-03-09 to 2026-04-17.' ], \WP_CLI::$successes );
	}

	/** The command reports the workers it reached, not the locks it found. */
	public function test_reresolve_reports_the_workers_it_reached(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p1' );
		self::live_worker( Config::get_base_directory(), 'skua-intel.p2' );
		\mkdir( Config::get_base_directory() . '/locks/skua-intel.p4.lock.d', 0777, true );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-05-11', 'until' => '2026-05-12' ] );

		$this->assertSame( [ 'Asked 2 workers to re-resolve the items dated 2026-05-11 to 2026-05-12.' ], \WP_CLI::$successes );
	}

	/** With no --until the range runs through today, UTC. */
	public function test_reresolve_runs_through_today_by_default(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p0' );

		$days = self::days_around( static fn () => ( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2025-11-30' ] ) );

		$sent = \array_column( self::worker_input( Config::get_base_directory(), 'skua-intel.p0' ), Message::VALUE );
		$this->assertCount( 1, $sent );
		$this->assertContains( $sent[0], \array_map( static fn ( string $day ): string => "RERESOLVE 2025-11-30 {$day}", $days ) );
	}

	/**
	 * Run a command whose range ends today, and answer the UTC days it can
	 * have read as today, since a midnight may fall inside it.
	 *
	 * @param \Closure $command Runs the command.
	 * @return list<string>
	 */
	private static function days_around( \Closure $command ): array {
		$before = \gmdate( 'Y-m-d' );
		$command();
		return \array_values( \array_unique( [ $before, \gmdate( 'Y-m-d' ) ] ) );
	}

	/**
	 * A date that does not parse, or a range running backwards, is refused
	 * before anything is sent.
	 *
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function bad_ranges(): array {
		return [
			'no such day'   => [ [ 'since' => '2026-02-30', 'until' => '2026-03-01' ], 'not a Y-m-d date: 2026-02-30' ],
			'not a date'    => [ [ 'since' => 'last week' ], 'not a Y-m-d date: last week' ],
			'no since'      => [ [], 'not a Y-m-d date: ' ],
			'backwards'     => [ [ 'since' => '2026-03-02', 'until' => '2026-03-01' ], 'since 2026-03-02 is after until 2026-03-01' ],
		];
	}

	/**
	 * @param array<string,string> $flags   The flags.
	 * @param string               $refusal What the command says.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'bad_ranges' )]
	public function test_reresolve_refuses_a_bad_range( array $flags, string $refusal ): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p0' );

		( new Ontology_CLI_Command() )->reresolve( [], $flags );

		$this->assertSame( [ $refusal ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p0' ) );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_reresolve_fails_loud_with_no_live_worker(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No skua-intel worker could be reached' );
		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );
	}

	/** `reextract` sends a REEXTRACT range when its calls fit its limit, naming how many. */
	public function test_reextract_asks_for_a_range_within_its_limit(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'feed:kestrel-1', 'feed:kestrel-2', 'feed:kestrel-3' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-04', 'limit' => '3' ] );

		$this->assertSame(
			[ [ Message::TM_REQUEST, 'reresolver', 'REEXTRACT 2026-08-03 2026-08-04' ] ],
			\array_map( static fn ( array $m ): array => [ $m[ Message::TYPE ], $m[ Message::TO ], $m[ Message::VALUE ] ], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) )
		);
		$this->assertSame( [ 'Asked 1 worker to re-extract the items dated 2026-08-03 to 2026-08-04: 3 extraction calls, a failed one retried up to 5 times.' ], \WP_CLI::$successes );
	}

	/** Every live worker re-extracts the whole range, so its calls are the items times the workers. */
	public function test_reextract_counts_a_call_per_item_per_live_worker(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p1' );
		self::live_worker( Config::get_base_directory(), 'skua-intel.p2' );
		$this->seed_dated( 'feed:kestrel-1', 'feed:kestrel-2', 'feed:kestrel-3' );

		$days = self::days_around( static fn () => ( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'limit' => '5' ] ) );
		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'limit' => '6' ] );

		$this->assertCount( 1, \WP_CLI::$errors );
		$this->assertContains( \WP_CLI::$errors[0], \array_map( static fn ( string $day ): string => "ontology reextract would make 6 extraction calls, 3 items dated 2026-08-03 to {$day} times 2 live workers, above --limit=5, and a failed call may retry up to 5 times; raise --limit to run it.", $days ) );
		$this->assertSame( [ 'Asked 2 workers to re-extract the items dated 2026-08-03 to 2026-08-03: 6 extraction calls, a failed one retried up to 5 times.' ], \WP_CLI::$successes );
		$this->assertSame( [ 'REEXTRACT 2026-08-03 2026-08-03' ], \array_column( self::worker_input( Config::get_base_directory(), 'skua-intel.p2' ), Message::VALUE ) );
	}

	/** Above its limit `reextract` refuses, naming the calls, and sends nothing. */
	public function test_reextract_refuses_above_its_limit_naming_the_count(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'feed:kestrel-1', 'feed:kestrel-2', 'feed:kestrel-3' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-04', 'limit' => '2' ] );

		$this->assertSame( [ 'ontology reextract would make 3 extraction calls, 3 items dated 2026-08-03 to 2026-08-04 times 1 live worker, above --limit=2, and a failed call may retry up to 5 times; raise --limit to run it.' ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) );
		$this->assertSame( [ 200, [] ], [ Ontology_CLI_Command::REEXTRACT_LIMIT, \WP_CLI::$successes ] );
	}

	/** A range that contains no item sends nothing and says so. */
	public function test_reextract_sends_nothing_for_a_range_dating_no_item(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'feed:kestrel-1' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-05', 'until' => '2026-08-06' ] );

		$this->assertSame( [ 'No item is dated 2026-08-05 to 2026-08-06; nothing to re-extract.' ], \WP_CLI::$successes );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) );
	}

	/**
	 * `--dry-run` sends nothing and prints the calls and the command that
	 * runs them, carrying `--limit` only when the calls pass the default.
	 */
	public function test_reextract_dry_run_prints_the_count_and_the_command(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'feed:kestrel-1', 'feed:kestrel-2' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'limit' => '1', 'dry-run' => true ] );

		$this->assertSame(
			[
				'2 items dated 2026-08-03 to 2026-08-03 would be re-extracted by 1 live worker: 2 extraction calls, each of which can add one decision-model call, a failed one retried up to 5 times.',
				'Re-extract them with: wp newspack-intelligence ontology reextract --since=2026-08-03 --until=2026-08-03',
			],
			\WP_CLI::$logs
		);
		$this->assertSame( [ [], [], [] ], [ self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ), \WP_CLI::$successes, \WP_CLI::$errors ] );
	}

	/** A dry run past the default limit prints the command with the limit its calls need. */
	public function test_reextract_dry_run_names_the_limit_past_the_default(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p1' );
		self::live_worker( Config::get_base_directory(), 'skua-intel.p2' );
		$this->seed_dated( ...\array_map( static fn ( int $i ): string => "feed:heron-{$i}", \range( 1, 101 ) ) );

		$days = self::days_around( static fn () => ( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'dry-run' => true ] ) );

		$this->assertContains( \WP_CLI::$logs[1], \array_map( static fn ( string $day ): string => "Re-extract them with: wp newspack-intelligence ontology reextract --since=2026-08-03 --until={$day} --limit=202", $days ) );
	}

	/** A bad range is refused before anything is read or sent. */
	public function test_reextract_refuses_a_bad_range(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-04', 'until' => '2026-08-03' ] );

		$this->assertSame( [ 'since 2026-08-04 is after until 2026-08-03' ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) );
	}

	/** `--source` sends a range naming the source, and the success line names it. */
	public function test_reresolve_names_the_source_it_is_given(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'linear:KITE-4' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17', 'source' => 'linear' ] );

		$this->assertSame( [ 'RERESOLVE 2026-03-09 2026-04-17 linear' ], \array_column( self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ), Message::VALUE ) );
		$this->assertSame( [ 'Asked 1 worker to re-resolve the linear items dated 2026-03-09 to 2026-04-17.' ], \WP_CLI::$successes );
	}

	/**
	 * A source no stored item is recorded under, in its own case or another,
	 * a node's name that records under another source among them, or an
	 * empty one, is refused before anything is counted or sent, listing the
	 * sources items are recorded under, sorted; an empty one never widens to
	 * every source.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function refused_sources(): array {
		$known = 'one of: feed, slack';
		return [
			'reresolve, a node name' => [ 'reresolve', 'news', "--source must name a source items are recorded under, {$known}; got news." ],
			'reextract, a node name' => [ 'reextract', 'news', "--source must name a source items are recorded under, {$known}; got news." ],
			'reresolve, mis-cased'   => [ 'reresolve', 'Slack', "--source must name a source items are recorded under, {$known}; got Slack." ],
			'reextract, mis-cased'   => [ 'reextract', 'Slack', "--source must name a source items are recorded under, {$known}; got Slack." ],
			'reresolve, unknown'     => [ 'reresolve', 'kite feed', "--source must name a source items are recorded under, {$known}; got kite feed." ],
			'reresolve, empty'       => [ 'reresolve', '', '--source is empty; name a source, or leave --source out for every source.' ],
			'reextract, blank'       => [ 'reextract', '  ', '--source is empty; name a source, or leave --source out for every source.' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_sources' )]
	public function test_a_source_no_item_is_recorded_under_is_refused( string $verb, string $source, string $refusal ): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p0' );
		$this->seed_dated( 'feed:kestrel-1', 'slack:C0SKUA01:1785758400.0001' );

		( new Ontology_CLI_Command() )->$verb( [], [ 'since' => '2026-08-03', 'source' => $source ] );

		$this->assertSame( [ [ $refusal ], [], [] ], [ \WP_CLI::$errors, \WP_CLI::$successes, \WP_CLI::$logs ] );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p0' ) );
	}

	/** With no item stored, any source is refused, saying none is recorded. */
	public function test_a_source_is_refused_while_no_item_is_stored(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p0' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'source' => 'feed' ] );

		$this->assertSame( [ '--source must name a source items are recorded under, and no item is recorded yet; got feed.' ], \WP_CLI::$errors );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p0' ) );
	}

	/** A source items are recorded under is accepted on both verbs, read trimmed as `items purge` reads it. */
	public function test_a_source_items_are_recorded_under_is_accepted_trimmed(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p0' );
		$this->seed_dated( 'feed:kestrel-1', 'slack:C0SKUA01:1785758400.0001' );

		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-03-10', 'source' => ' feed ' ] );
		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'source' => 'feed' ] );

		$this->assertSame( [], \WP_CLI::$errors );
		$this->assertSame( [ 'RERESOLVE 2026-03-09 2026-03-10 feed', 'REEXTRACT 2026-08-03 2026-08-03 feed' ], \array_column( self::worker_input( Config::get_base_directory(), 'skua-intel.p0' ), Message::VALUE ) );
	}

	/**
	 * With `--source`, `reextract` counts only that source's items in the
	 * range, refuses past its limit by that count, and sends the range
	 * naming the source.
	 */
	public function test_reextract_counts_and_sends_only_the_source_named(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p1' );
		self::live_worker( Config::get_base_directory(), 'skua-intel.p2' );
		$this->seed_dated( 'feed:kestrel-1', 'feed:kestrel-2', 'feed:kestrel-3', 'slack:C0SKUA01:1785758400.0001', 'slack:C0SKUA01:1785758400.0002' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'source' => 'slack', 'limit' => '3' ] );
		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'source' => 'slack', 'limit' => '4' ] );

		$this->assertSame( [ 'ontology reextract would make 4 extraction calls, 2 slack items dated 2026-08-03 to 2026-08-03 times 2 live workers, above --limit=3, and a failed call may retry up to 5 times; raise --limit to run it.' ], \WP_CLI::$errors );
		$this->assertSame( [ 'Asked 2 workers to re-extract the slack items dated 2026-08-03 to 2026-08-03: 4 extraction calls, a failed one retried up to 5 times.' ], \WP_CLI::$successes );
		$this->assertSame( [ 'REEXTRACT 2026-08-03 2026-08-03 slack' ], \array_column( self::worker_input( Config::get_base_directory(), 'skua-intel.p2' ), Message::VALUE ) );
	}

	/** A source with no item in the range sends nothing, and a dry run's command carries the source. */
	public function test_reextract_with_a_source_reports_and_prints_it(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p3' );
		$this->seed_dated( 'feed:kestrel-1', 'linear:KITE-7' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-04', 'until' => '2026-08-04', 'source' => 'feed' ] );
		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'until' => '2026-08-03', 'source' => 'linear', 'dry-run' => true ] );

		$this->assertSame( [ 'No feed item is dated 2026-08-04 to 2026-08-04; nothing to re-extract.' ], \WP_CLI::$successes );
		$this->assertSame(
			[
				'1 linear items dated 2026-08-03 to 2026-08-03 would be re-extracted by 1 live worker: 1 extraction calls, each of which can add one decision-model call, a failed one retried up to 5 times.',
				'Re-extract them with: wp newspack-intelligence ontology reextract --since=2026-08-03 --until=2026-08-03 --source=linear',
			],
			\WP_CLI::$logs
		);
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p3' ) );
	}

	/** A limit that is not a positive whole number is refused. */
	public function test_reextract_refuses_a_bad_limit(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'invalid --limit' );

		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-08-03', 'limit' => '0' ] );
	}

	/**
	 * Record items dated 2026-08-03 12:00 UTC.
	 *
	 * @param string ...$keys The item keys.
	 */
	private function seed_dated( string ...$keys ): void {
		$this->seed(
			\array_map(
				static fn ( string $key ): array => Intel_Event::make( 'item_recorded', [ 'item_key' => $key, 'source' => \explode( ':', $key )[0], 'tier' => 'public', 'url' => "https://news.example/{$key}", 'title' => $key, 'body' => '', 'item_ts' => 1785758400, 'retrieved_ts' => 1785758400 ], 'machine:source:feed@9.4.1', '9.4.1' ),
				$keys
			)
		);
	}

	/**
	 * `reproject` holds the fleet, deletes the graph's file and the cursor of
	 * each Consumer the projector checkpoints with, then releases the hold;
	 * the streams and every other reader's cursor stay.
	 */
	public function test_reproject_deletes_the_graph_and_the_projectors_cursors_inside_a_hold(): void {
		$base    = Config::get_base_directory();
		$graph   = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		$cursors = [ "{$base}/offsets/newspack-intelligence-project.intel-items.p0", "{$base}/offsets/newspack-intelligence-project.intel-ontology.p0", "{$base}/offsets/newspack-intelligence-project.intel-watches.p0" ];
		$kept    = [ "{$base}/offsets/newspack-intelligence-project.intel-items-resolve.p0", "{$base}/logs/intel-ontology.p0" ];
		foreach ( [ ...$cursors, ...$kept ] as $dir ) {
			\mkdir( $dir, 0777, true );
			\file_put_contents( "{$dir}/0.log", "[1]\n" );
		}
		$this->seed( self::entity( 'organization:tidewater-ledger', 'Tidewater Ledger' ) );
		\file_put_contents( "{$graph}-wal", '' );
		$seen            = [];
		\WP_CLI::$on_run = static function ( string $command ) use ( &$seen, $graph, $cursors ): void {
			$seen[] = [ $command, \file_exists( $graph ), \is_dir( $cursors[0] ) ];
		};

		( new Ontology_CLI_Command() )->reproject( [], [] );

		$this->assertSame( [ [ 'nodes stop', true, true ], [ 'nodes start', false, false ] ], $seen );
		$this->assertSame( [ [ 'launch' => true, 'exit_error' => false, 'return' => 'all' ], [ 'launch' => true, 'exit_error' => true ] ], \array_column( \WP_CLI::$ran, 1 ), 'each runs in its own process, so a throw in it leaves this one\'s WP-CLI state alone' );
		$this->assertSame( [ false, false, false, false, false ], [ \file_exists( $graph ), \file_exists( "{$graph}-wal" ), \file_exists( "{$graph}-shm" ), \is_dir( $cursors[1] ), \is_dir( $cursors[2] ) ] );
		$this->assertSame( [ true, true ], \array_map( 'is_dir', $kept ) );
		$this->assertSame( [ 'Deleted the intel-graph projection and 3 projector cursors; the projector replays every stream.' ], \WP_CLI::$successes );
	}

	/** A fleet that will not stop deletes nothing, and the hold is released before the refusal. */
	public function test_reproject_deletes_nothing_when_the_fleet_will_not_stop(): void {
		$graph           = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		$cursor          = Config::get_base_directory() . '/offsets/newspack-intelligence-project.intel-items.p0';
		\mkdir( $cursor, 0777, true );
		$this->seed( self::entity( 'organization:tidewater-ledger', 'Tidewater Ledger' ) );
		\WP_CLI::$on_run = static fn ( string $command ): ?array => 'nodes stop' === $command ? [ 1, "Held the fleet; waiting for workers to exit.\n", "Error: Timed out after 90s; still up: newspack-intelligence.p0\n" ] : null;

		( new Ontology_CLI_Command() )->reproject( [], [] );

		$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
		$this->assertSame( [ true, true ], [ \file_exists( $graph ), \is_dir( $cursor ) ] );
		$this->assertSame( [ 'Held the fleet; waiting for workers to exit.' ], \WP_CLI::$logs );
		$this->assertSame( [ 'The fleet did not stop, so reproject deleted nothing; the hold is released. Error: Timed out after 90s; still up: newspack-intelligence.p0' ], \WP_CLI::$errors );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	/** A stop that throws, having perhaps set the hold, still releases it, and the throw surfaces. */
	public function test_reproject_releases_the_hold_when_the_stop_throws(): void {
		\WP_CLI::$on_run = static function ( string $command ): ?array {
			if ( 'nodes stop' === $command ) {
				throw new \RuntimeException( 'could not flag newspack-intelligence.p0 to stop' );
			}
			return null;
		};

		try {
			( new Ontology_CLI_Command() )->reproject( [], [] );
			$this->fail( 'the stop throws' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'could not flag newspack-intelligence.p0 to stop', $e->getMessage() );
			$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
		}
	}

	/** A delete that fails still releases the hold, and the failure surfaces. */
	public function test_reproject_releases_the_hold_when_a_delete_fails(): void {
		$graph = Bootstrap::row_table( Intel_Graph_Row_Schema::class )['files'][0];
		\mkdir( "{$graph}-wal", 0777, true );
		\file_put_contents( "{$graph}-wal/held", 'x' );

		try {
			( new Ontology_CLI_Command() )->reproject( [], [] );
			$this->fail( 'the delete throws' );
		} catch ( \UnexpectedValueException ) {
			$this->assertSame( [ 'nodes stop', 'nodes start' ], \array_column( \WP_CLI::$ran, 0 ) );
			$this->assertSame( [], \WP_CLI::$successes );
		}
	}

	/**
	 * Run as root, each verb refuses before it writes, so it leaves the workers
	 * no root-owned segment, IPC file or hold.
	 */
	public function test_every_verb_refuses_root_before_any_write(): void {
		self::live_worker( Config::get_base_directory(), 'skua-intel.p5' );
		CLI::$uid_provider = static fn (): int => 0;

		( new Ontology_CLI_Command() )->seed( [], [] );
		( new Ontology_CLI_Command() )->reresolve( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );
		( new Ontology_CLI_Command() )->reproject( [], [] );
		( new Ontology_CLI_Command() )->reextract( [], [ 'since' => '2026-03-09', 'until' => '2026-04-17' ] );

		$this->assertSame(
			[
				'wp newspack-intelligence ontology seed must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reresolve must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reproject must run as the same user as the workers, not root.',
				'wp newspack-intelligence ontology reextract must run as the same user as the workers, not root.',
			],
			\WP_CLI::$errors
		);
		$this->assertSame( [], \WP_CLI::$ran );
		$this->assertSame( 0, self::ontology_segments() );
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'skua-intel.p5' ) );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_the_ontology_command_is_registered(): void {
		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Ontology_CLI_Command', \WP_CLI::$commands['newspack-intelligence ontology'] ?? null );
	}
}

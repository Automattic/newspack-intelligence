<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\CLI\Eval_CLI_Command;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Prompts;
use Newspack_Nodes\CLI;
use Newspack_Nodes\Curl_Node;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/wp-cli-stub.php';
require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/drives-curl.php';

/**
 * `wp newspack-intelligence eval <file>` asks the decision model each
 * labelled item's checks, through the LLM_Call node Intelligence's topology
 * configures, and scores each check's precision and recall against the
 * labels at its bar, naming every miss. A restricted item is never sent.
 */
final class EvalCliCommandTest extends TestCase {
	use Seeds_Intel_Graph;
	use Drives_Curl;

	private const GROUP = 'ai-relay-4417';

	private const ORIGIN = 'https://relay.example';

	private const API = 'https://relay.example/wpcom/v2/ai-relay/v1';

	private const SYSTEMONE = self::API . '/systemone';

	private const DECISION_MODEL = 'heron-decide-3';

	private const FEATURE = 'intel-eval-feature';

	/** The topology the eval reads its LLM_Call from: streams, the projection and one LLM_Call. */
	private const TOPOLOGY = "include newspack-intelligence-streams\n"
		. "include newspack-intelligence-project\n"
		. "make_node LLM_Call intel:llm " . self::GROUP . "\n"
		. "cmd intel:llm:config set_api_url https://relay.example/wpcom/v2/ai-relay/v1/\n"
		. "cmd intel:llm:config set_model kestrel-model-9\n"
		. "cmd intel:llm:config set_decision_model " . self::DECISION_MODEL . "\n"
		. "cmd intel:llm:config set_feature " . self::FEATURE . "\n"
		. "secure\n";

	/** @var array<string,float|null> The noul each title is answered, null for a 502. */
	private array $nouls = [];

	/** @var array<int,int> Each handle's dispatch, by spl_object_id. */
	private array $index_of = [];

	/** @var list<\CurlHandle> Handles dispatched and not yet reported done. */
	private array $pending = [];

	protected function setUp(): void {
		parent::setUp();
		\WP_CLI::reset();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		$dir = $this->make_temp_dir( 'intel-eval-topologies-' );
		\file_put_contents( "{$dir}/heron-eval.tsl", self::TOPOLOGY );
		Topology_Registry::register_user_dir( $dir );
		$this->activate( 'heron-eval' );
		$this->seed(
			self::item( 'linear:HERON-41', 'Kite Ledger opens its reader app', 'linear', 'internal', self::$oct_06, 'The app is out for every reader.' ),
			self::extraction( 'linear:HERON-41', 'The Ledger made its reader app available.', 'launch', self::$oct_06, [] ),
			self::item( 'linear:HERON-42', 'Quarry Wire goes live for members', 'linear', 'internal', self::$oct_06, 'Members can read it now.' ),
			self::extraction( 'linear:HERON-42', 'The Wire opened to its members.', 'launch', self::$oct_06, [] ),
			self::item( 'p2:kite-7', 'Launch checklist for the Heron site', 'p2', 'internal', self::$oct_13, 'Steps left before launch.' ),
			self::item( 'slack:G0KITE-5', 'Heron board launches a quiet fund', 'slack', 'restricted', self::$oct_13, 'Only the board knows.' )
		);
		$this->nouls = [
			'Kite Ledger opens its reader app'    => 0.92,
			'Quarry Wire goes live for members'   => 0.31,
			'Launch checklist for the Heron site' => 0.12,
		];
		$this->answer_systemone();
		$this->lend( self::GROUP, self::ORIGIN, 'Authorization', 'relay-token-6620', 'Bearer' );
	}

	protected function tearDown(): void {
		Event_Framework::$curl_poll = null;
		$this->uninstall_curl();
		Intel_Graph_Row_Schema::$read_rows = null;
		CLI::$uid_provider                 = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/**
	 * Stand in for libcurl: every transfer completes on the next poll, and
	 * answers each question its body asks the noul its title is given.
	 */
	private function answer_systemone(): void {
		Event_Framework::$curl_dispatch = function ( array $opts ): \CurlHandle {
			$this->sent[] = $opts;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
			$easy                                      = \curl_init();
			$this->pending[]                           = $easy;
			$this->index_of[ \spl_object_id( $easy ) ] = \count( $this->sent ) - 1;
			return $easy;
		};
		Event_Framework::$curl_poll = function (): array {
			$done          = \array_map( static fn ( \CurlHandle $easy ): array => [ 'msg' => \CURLMSG_DONE, 'handle' => $easy, 'result' => \CURLE_OK ], $this->pending );
			$this->pending = [];
			return $done;
		};
		Curl_Node::$curl_result = function ( \CurlHandle $easy ): array {
			$body  = \json_decode( (string) $this->sent[ $this->index_of[ \spl_object_id( $easy ) ] ][ \CURLOPT_POSTFIELDS ], true );
			$title = self::state_line( $body['state'], 'Title: ' );
			$noul  = $this->nouls[ $title ];
			if ( null === $noul ) {
				return [ 'code' => 502, 'body' => 'relay down', 'redirect' => '', 'ip' => self::CONNECTED ];
			}
			$answers = [];
			foreach ( \array_keys( $body['questions'] ) as $question ) {
				$answers[ $question ] = [ 'type' => 'noul', 'noul' => $noul ];
			}
			return [ 'code' => 200, 'body' => (string) \json_encode( [ 'model' => self::DECISION_MODEL, 'answers' => $answers ] ), 'redirect' => '', 'ip' => self::CONNECTED ];
		};
	}

	/** The rest of the state's line opening with $prefix. */
	private static function state_line( string $state, string $prefix ): string {
		foreach ( \explode( "\n", $state ) as $line ) {
			if ( \str_starts_with( $line, $prefix ) ) {
				return \substr( $line, \strlen( $prefix ) );
			}
		}
		return '';
	}

	/**
	 * A labels file of $labels, one JSON object a line.
	 *
	 * @param list<array<string,mixed>|string> $labels Each label, or a raw line.
	 */
	private function labels( array $labels ): string {
		$path = $this->make_temp_dir( 'intel-eval-labels-' ) . '/heron-labels.jsonl';
		\file_put_contents( $path, \implode( "\n", \array_map( static fn ( array|string $label ): string => \is_string( $label ) ? $label : (string) \json_encode( $label ), $labels ) ) . "\n" );
		return $path;
	}

	/** @return list<array<string,mixed>> Each POST's decoded body, in order. */
	private function bodies(): array {
		return \array_map( static fn ( array $opts ): array => \json_decode( (string) $opts[ \CURLOPT_POSTFIELDS ], true ), $this->sent );
	}

	/** The three launch labels: two that fit, one that does not. */
	private const LAUNCHES = [
		[ 'item_key' => 'linear:HERON-41', 'kind' => 'launch', 'fits_kind' => true ],
		[ 'item_key' => 'linear:HERON-42', 'kind' => 'launch', 'fits_kind' => true ],
		[ 'item_key' => 'p2:kite-7', 'kind' => 'launch', 'fits_kind' => false ],
	];

	public function test_eval_scores_each_kind_check(): void {
		$this->nouls['Quarry Wire goes live for members'] = 0.695;

		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [] );

		$this->assertSame( [], \WP_CLI::$errors );
		$this->assertSame(
			[
				'launch: 3 labels, precision 1.00, recall 0.50 at the bar 0.7',
				'linear:HERON-42 launch answered 0.695 wanted keep',
			],
			\WP_CLI::$logs
		);
		$this->assertSame( [ 'Scored 3 labels in 3 decision calls.' ], \WP_CLI::$successes );
		$first = $this->sent[0];
		$this->assertSame( self::SYSTEMONE, $first[ \CURLOPT_URL ] );
		$this->assertContains( 'X-WPCOM-AI-Feature: ' . self::FEATURE, $first[ \CURLOPT_HTTPHEADER ] );
		$this->assertContains( 'Authorization: Bearer relay-token-6620', $first[ \CURLOPT_HTTPHEADER ] );
		$body = $this->bodies()[0];
		$this->assertSame( self::DECISION_MODEL, $body['model'] );
		$this->assertSame( [ Prompts::FITS_KIND ], \array_keys( $body['questions'] ) );
		$this->assertSame( Prompts::KIND_CHECKS['launch']['asks'], $body['questions'][ Prompts::FITS_KIND ]['instructions'] );
		$this->assertSame( 'The Ledger made its reader app available.', self::state_line( $body['state'], 'Summary: ' ) );
	}

	/** The eval states what the topology's LLM_Call says the deployment tracks, as the node's own decisions do. */
	public function test_eval_states_what_the_topology_says_it_tracks(): void {
		$tracks = 'the heron wire, the kite desks Quillmere supplies, and coastal birding';
		\file_put_contents( Topology_Registry::user_dir() . '/heron-eval.tsl', \str_replace( "secure\n", "cmd intel:llm:config set_tracks \"{$tracks}\"\nsecure\n", self::TOPOLOGY ) );
		Topology_Analyzer::reset_caches();

		( new Eval_CLI_Command() )( [ $this->labels( [ self::LAUNCHES[0] ] ) ], [] );

		$this->assertStringEndsWith( "\nAsked by: Newspack Intelligence, a plugin built on newspack-nodes, which tracks {$tracks}.", $this->bodies()[0]['state'] );
	}

	public function test_eval_refuses_a_label_naming_no_item(): void {
		$labels = [ self::LAUNCHES[0], [ 'item_key' => 'linear:HERON-99', 'kind' => 'launch', 'fits_kind' => true ] ];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [] );

		$this->assertSame( [ 'The projection holds no item for linear:HERON-99 (line 2).' ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
		$this->assertSame( [], \WP_CLI::$successes );
	}

	public function test_eval_sends_no_restricted_item_to_the_decision_model(): void {
		$labels = [ [ 'item_key' => 'slack:G0KITE-5', 'kind' => 'funding', 'fits_kind' => true ], self::LAUNCHES[2] ];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [] );

		$this->assertSame( [ 'Launch checklist for the Heron site' ], \array_map( static fn ( array $body ): string => self::state_line( $body['state'], 'Title: ' ), $this->bodies() ) );
		$this->assertSame(
			[
				'Skipped 1 restricted label: the decision model is never sent a restricted item.',
				'launch: 1 label, precision n/a, recall n/a at the bar 0.7',
			],
			\WP_CLI::$logs
		);
		$this->assertSame( [ 'Scored 1 label in 1 decision call.' ], \WP_CLI::$successes );
	}

	/** An item any partition holds as restricted is restricted, whichever partition reads last. */
	public function test_an_item_restricted_in_any_partition_is_never_sent(): void {
		$this->seed(
			self::item( 'linear:HERON-50', 'Heron board weighs a merger', 'linear', 'restricted', self::$oct_13, 'Board minutes.' ),
			self::extraction( 'linear:HERON-50', 'The board discussed a merger in private.', 'launch', self::$oct_13, [] )
		);
		$this->second_partition(
			self::item( 'linear:HERON-50', 'Heron board weighs a merger', 'linear', 'internal', self::$oct_13, 'Board minutes.' ),
			self::extraction( 'linear:HERON-50', 'A merger is under discussion.', 'launch', self::$oct_13, [] )
		);
		$this->nouls['Heron board weighs a merger'] = 0.81;
		$labels = [ [ 'item_key' => 'linear:HERON-50', 'kind' => 'launch', 'fits_kind' => true ], self::LAUNCHES[0] ];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [] );

		$this->assertSame( [ 'Kite Ledger opens its reader app' ], \array_map( static fn ( array $body ): string => self::state_line( $body['state'], 'Title: ' ), $this->bodies() ) );
		$this->assertSame( 'Skipped 1 restricted label: the decision model is never sent a restricted item.', \WP_CLI::$logs[0] );
		$this->assertSame( [ 'Scored 1 label in 1 decision call.' ], \WP_CLI::$successes );
	}

	/** One label with a publisher asks both questions in one call; `--check` keeps one. */
	public function test_a_check_limits_the_eval_to_its_own_question(): void {
		$labels = [
			[ 'item_key' => 'linear:HERON-41', 'kind' => 'launch', 'fits_kind' => true, 'about_publisher' => false, 'publisher' => 'Kite Ledger' ],
			[ 'item_key' => 'linear:HERON-42', 'kind' => 'release', 'fits_kind' => false ],
		];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [ 'check' => Prompts::ABOUT_PUBLISHER ] );

		$this->assertCount( 1, $this->sent );
		$body = $this->bodies()[0];
		$this->assertSame( [ Prompts::ABOUT_PUBLISHER ], \array_keys( $body['questions'] ) );
		$this->assertSame( 'Kite Ledger', self::state_line( $body['state'], 'Publisher: ' ) );
		$this->assertSame(
			[
				'about_publisher: 1 label, precision 0.00, recall n/a at the bar 0.5',
				'linear:HERON-41 about_publisher answered 0.920 wanted drop',
			],
			\WP_CLI::$logs
		);
	}

	public function test_without_a_check_a_label_asks_every_question_it_names(): void {
		$labels = [ [ 'item_key' => 'linear:HERON-42', 'kind' => 'release', 'fits_kind' => false, 'about_publisher' => true, 'publisher' => 'Quarry Wire' ] ];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [] );

		$this->assertSame( [ Prompts::ABOUT_PUBLISHER, Prompts::FITS_KIND ], \array_keys( $this->bodies()[0]['questions'] ) );
		$this->assertSame(
			[
				'release: 1 label, precision n/a, recall n/a at the bar 0.5',
				'about_publisher: 1 label, precision n/a, recall 0.00 at the bar 0.5',
				'linear:HERON-42 about_publisher answered 0.310 wanted keep',
			],
			\WP_CLI::$logs
		);
	}

	public function test_a_dry_run_counts_the_calls_and_makes_none(): void {
		$labels = [ ...self::LAUNCHES, [ 'item_key' => 'slack:G0KITE-5', 'kind' => 'funding', 'fits_kind' => true ] ];

		( new Eval_CLI_Command() )( [ $this->labels( $labels ) ], [ 'dry-run' => true ] );

		$this->assertSame( [], $this->sent );
		$this->assertSame( [ 'Skipped 1 restricted label: the decision model is never sent a restricted item.' ], \WP_CLI::$logs );
		$this->assertSame( [ 'Would make 3 decision calls; made none.' ], \WP_CLI::$successes );
	}

	/** A call that fails is named and left out of the scores, and the run then fails. */
	public function test_a_failed_call_is_named_and_left_out(): void {
		$this->nouls['Quarry Wire goes live for members'] = null;

		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [] );

		$this->assertSame( [ 'linear:HERON-42 (line 2): HTTP 502 ' . self::SYSTEMONE ], \WP_CLI::$warnings );
		$this->assertSame( [ 'launch: 2 labels, precision 1.00, recall 1.00 at the bar 0.7' ], \WP_CLI::$logs );
		$this->assertSame( [ '1 decision call failed; the scores leave it out.' ], \WP_CLI::$errors );
	}

	public function test_a_group_lending_nothing_is_refused_before_any_call(): void {
		\delete_option( \Newspack_Nodes\Vault::OPTION_KEY );
		\Newspack_Nodes\Vault::get_instance()->reset_cache();

		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [] );

		$this->assertSame( [ 'The ' . self::GROUP . ' Vault group lends no credential on ' . self::API . ': lend one before an eval.' ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function unready_topologies(): array {
		return [
			'no LLM_Call'          => [ "include newspack-intelligence-streams\ninclude newspack-intelligence-project\nsecure\n", 'heron-eval declares no LLM_Call node to ask the decision model through.' ],
			'no decision model'    => [ \str_replace( 'cmd intel:llm:config set_decision_model ' . self::DECISION_MODEL . "\n", '', self::TOPOLOGY ), 'intel:llm: set_api_url, set_decision_model and set_feature before a decision.' ],
			'no api url'           => [ \str_replace( "cmd intel:llm:config set_api_url https://relay.example/wpcom/v2/ai-relay/v1/\n", '', self::TOPOLOGY ), 'intel:llm: set_api_url, set_decision_model and set_feature before a decision.' ],
			'no feature'           => [ \str_replace( 'cmd intel:llm:config set_feature ' . self::FEATURE . "\n", '', self::TOPOLOGY ), 'intel:llm: set_api_url, set_decision_model and set_feature before a decision.' ],
		];
	}

	/** @dataProvider unready_topologies */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'unready_topologies' )]
	public function test_an_llm_call_that_cannot_decide_is_refused_before_any_call( string $topology, string $error ): void {
		\file_put_contents( Topology_Registry::user_dir() . '/heron-eval.tsl', $topology );
		Topology_Analyzer::reset_caches();

		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [] );

		$this->assertSame( [ $error ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function refused_lines(): array {
		$fits = '"item_key":"linear:HERON-41","kind":"launch","fits_kind":true';
		return [
			'an unknown field'         => [ "{{$fits},\"theme\":\"reader apps\"}", 'line 1: unknown field theme; a label names item_key, kind, fits_kind, about_publisher and publisher.' ],
			'not an object'            => [ '["linear:HERON-41"]', 'line 1: not a JSON object.' ],
			'no item key'              => [ '{"kind":"launch","fits_kind":true}', 'line 1: item_key is not a string naming an item.' ],
			'a kind no check holds'    => [ '{"item_key":"linear:HERON-41","kind":"award","fits_kind":true}', 'line 1: kind award is not one of launch, release, funding, research, feature_request, bug, incident.' ],
			'fits_kind not a boolean'  => [ '{"item_key":"linear:HERON-41","kind":"launch","fits_kind":"yes"}', 'line 1: fits_kind is not true or false.' ],
			'a publisher alone'        => [ "{{$fits},\"publisher\":\"Kite Ledger\"}", 'line 1: about_publisher and publisher come together: about_publisher true or false, publisher a name.' ],
			'about_publisher alone'    => [ "{{$fits},\"about_publisher\":true}", 'line 1: about_publisher and publisher come together: about_publisher true or false, publisher a name.' ],
		];
	}

	/** @dataProvider refused_lines */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'refused_lines' )]
	public function test_a_label_it_cannot_read_is_refused( string $line, string $error ): void {
		( new Eval_CLI_Command() )( [ $this->labels( [ $line ] ) ], [] );

		$this->assertSame( [ $error ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
	}

	public function test_a_check_no_label_can_ask_is_refused(): void {
		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [ 'check' => 'award' ] );

		$this->assertSame( [ '--check=award is not one of launch, release, funding, research, feature_request, bug, incident, about_publisher.' ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
	}

	public function test_a_file_holding_no_label_is_refused(): void {
		$path = $this->make_temp_dir( 'intel-eval-labels-' ) . '/empty.jsonl';
		\file_put_contents( $path, "\n\n" );

		( new Eval_CLI_Command() )( [ $path ], [] );

		$this->assertSame( [ "{$path} holds no label." ], \WP_CLI::$errors );
	}

	public function test_a_missing_file_is_refused(): void {
		$path = $this->make_temp_dir( 'intel-eval-labels-' ) . '/absent.jsonl';

		( new Eval_CLI_Command() )( [ $path ], [] );

		$this->assertSame( [ "Cannot read {$path}." ], \WP_CLI::$errors );
	}

	public function test_the_command_registers_as_eval(): void {
		\WP_CLI::reset();

		\Newspack_Intelligence\register_cli_commands();

		$this->assertSame( '\\Newspack_Intelligence\\CLI\\Eval_CLI_Command', \WP_CLI::$commands['newspack-intelligence eval'] ?? null );
	}

	public function test_root_is_refused(): void {
		CLI::$uid_provider = static fn (): int => 0;

		( new Eval_CLI_Command() )( [ $this->labels( self::LAUNCHES ) ], [] );

		$this->assertSame( [ 'wp newspack-intelligence eval must run as the same user as the workers, not root.' ], \WP_CLI::$errors );
		$this->assertSame( [], $this->sent );
	}
}

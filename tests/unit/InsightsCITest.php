<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Digest_Builder_Node;
use Newspack_AI_Newsletter\Insights_CI_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Insights_CI is the dashboard's server read, decomposed into three slice verbs —
 * `counts`/`top`/`accumulated` — over ONE memoized scored-snapshot read (mirroring
 * the de-godded example). The `accumulated` slice also carries the rendered digest
 * (latest `digest:log` segment) and collection progress (done/total). It keeps
 * `generate`/`collect`, which route Collect / Regenerate to the worker's nodes over
 * the input IPC partition (the request graph never composes itself).
 */
final class InsightsCITest extends TestCase {

	/** @var string[] make_temp_dir() doesn't self-register for cleanup, so track + remove here. */
	private array $created = [];

	/** Per-test digest temp dir, lazily created by digest_path(). */
	private ?string $digest_dir = null;

	protected function setUp(): void {
		parent::setUp();
		// Service_CI verbs are gated by default; these tests dispatch them, so grant the cap.
		$GLOBALS['_wp_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_current_user_can']                           = true;
		// The accumulated slice reads the fixed Digest_Builder_Node::DIGEST_PATH constant — clear any
		// leftover segments so an empty-snapshot test sees an empty digest.
		$this->clear_digest_segments();
	}

	protected function tearDown(): void {
		Insights_CI_Node::$read_items = null;
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;
		foreach ( $this->created as $dir ) {
			$this->rmdir_recursive( $dir );
		}
		$this->created    = [];
		$this->digest_dir = null;
		$this->clear_digest_segments();
		parent::tearDown();
	}

	/** Remove every `{DIGEST_PATH}.{seg}` segment so the fixed-path digest read is deterministic per test. */
	private function clear_digest_segments(): void {
		\is_dir( \dirname( Digest_Builder_Node::DIGEST_PATH ) ) || \mkdir( \dirname( Digest_Builder_Node::DIGEST_PATH ), 0777, true );
		foreach ( (array) \glob( Digest_Builder_Node::DIGEST_PATH . '.*' ) as $segment ) {
			\is_file( $segment ) && \unlink( $segment );
		}
	}

	private const SEED = [
		[ 'source' => 'github', 'title' => 'Roundup Block ships', 'summary' => 's1', 'score' => 6.0 ],
		[ 'source' => 'linear', 'title' => 'Reader forum hits 10k', 'summary' => 's2', 'score' => 4.0 ],
		[ 'source' => 'github', 'title' => 'Minor fix', 'summary' => 's3', 'score' => 5.0 ],
	];

	/** Write one offsetlog-shaped snapshot record (seg/off + cache) into $offsets/scored.p$n. */
	private function write_scored_cache( string $offsets, int $partition, array $cache ): void {
		$ol = new Partition_Node();
		$ol->name( "t:ol:$partition" );
		$ol->arguments( "$offsets/scored.p$partition" );
		$ol->void_warranty();
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = [ 'seg' => 0, 'off' => 0, 'cache' => $cache ];
		$ol->fill( $m );
		$ol->flush();
	}

	/** Point Config's offsets dir at a fresh temp base seeded with $cache, and return the CI bound to it. */
	private function ci_with_cache( array $cache ): Insights_CI_Node {
		$base            = $this->make_temp_dir( 'insights-ci-base-' );
		$this->created[] = $base;
		$this->use_base_dir( $base );
		$this->write_scored_cache( Config::get_offsets_directory(), 0, $cache );
		$ci = new Insights_CI_Node();
		$ci->name( 'insights' );
		return $ci;
	}

	/** A snapshot of just items (no progress), for the slice shape tests. */
	private function ci_with_items( array $items ): Insights_CI_Node {
		return $this->ci_with_cache( [ 'items' => $items ] );
	}

	public function test_counts_verb_returns_sources_slice_only(): void {
		$ci      = $this->ci_with_items( self::SEED );
		$decoded = \json_decode( (string) $ci->dispatch( 'counts' ), true );
		$this->assertSame( [ 'sources' => [ 'github' => 2, 'linear' => 1 ] ], $decoded );
	}

	public function test_top_verb_returns_per_source_top_slice_sorted_desc(): void {
		$ci      = $this->ci_with_items( self::SEED );
		$decoded = \json_decode( (string) $ci->dispatch( 'top' ), true );
		$this->assertSame( [ 'top' ], \array_keys( $decoded ) );
		// Per-source: github's two items, ranked desc by score.
		$this->assertSame( [ 'github', 'linear' ], \array_keys( $decoded['top'] ) );
		$this->assertEquals( 6.0, $decoded['top']['github'][0]['score'] );
		$this->assertSame( 'Roundup Block ships', $decoded['top']['github'][0]['title'] );
		$this->assertEquals( 5.0, $decoded['top']['github'][1]['score'] );
		$this->assertEquals( 4.0, $decoded['top']['linear'][0]['score'] );
	}

	public function test_top_verb_caps_each_source_at_ten(): void {
		$items = [];
		for ( $i = 0; $i < 25; $i++ ) {
			$items[] = [ 'source' => 'github', 'title' => "Item $i", 'summary' => 's', 'score' => (float) $i ];
		}
		$ci      = $this->ci_with_items( $items );
		$decoded = \json_decode( (string) $ci->dispatch( 'top' ), true );
		$this->assertCount( 10, $decoded['top']['github'] );
		$this->assertEquals( 24.0, $decoded['top']['github'][0]['score'] );
	}

	public function test_accumulated_verb_returns_count_progress_and_digest(): void {
		$base            = $this->make_temp_dir( 'insights-ci-base-' );
		$this->created[] = $base;
		$this->use_base_dir( $base );
		$this->write_scored_cache(
			Config::get_offsets_directory(),
			0,
			[ 'items' => self::SEED, 'done' => '2', 'total' => '3' ]
		);
		\file_put_contents( Digest_Builder_Node::DIGEST_PATH . '.0', '## Real digest' );
		$ci = new Insights_CI_Node();
		$ci->name( 'insights' );

		$decoded = \json_decode( (string) $ci->dispatch( 'accumulated' ), true );
		$this->assertSame( 3, $decoded['accumulated'] );
		$this->assertSame( 2, $decoded['done'] );
		$this->assertSame( 3, $decoded['total'] );
		$this->assertSame( '## Real digest', $decoded['digest'] );
	}

	public function test_empty_snapshot_yields_empty_slices(): void {
		$ci = $this->ci_with_items( [] );
		$this->assertSame( [ 'sources' => [] ], \json_decode( (string) $ci->dispatch( 'counts' ), true ) );
		$this->assertSame( [ 'top' => [] ], \json_decode( (string) $ci->dispatch( 'top' ), true ) );
		$acc = \json_decode( (string) $ci->dispatch( 'accumulated' ), true );
		$this->assertSame( 0, $acc['accumulated'] );
		$this->assertSame( 0, $acc['done'] );
		$this->assertSame( 0, $acc['total'] );
		$this->assertSame( '', $acc['digest'] );
	}

	public function test_verbs_read_a_snapshot_over_pipe_buf(): void {
		// 60 padded items pack to well over PIPE_BUF (4096B) as one offsetlog line.
		$items = [];
		for ( $i = 0; $i < 60; $i++ ) {
			$items[] = [ 'source' => 'github', 'title' => "Item $i " . \str_repeat( 'x', 80 ), 'summary' => 's', 'score' => (float) $i ];
		}
		$ci = $this->ci_with_items( $items );
		$this->assertSame( 60, \json_decode( (string) $ci->dispatch( 'accumulated' ), true )['accumulated'] );
		$this->assertEquals( 59.0, \json_decode( (string) $ci->dispatch( 'top' ), true )['top']['github'][0]['score'] );
	}

	public function test_three_verbs_share_one_memoized_read(): void {
		$ci    = $this->ci_with_items( self::SEED );
		$reads = 0;
		$default = Insights_CI_Node::$read_items
			?? static fn ( string $dir ): array => Insights_CI_Node::read_snapshot_items( $dir );
		Insights_CI_Node::$read_items = static function ( string $dir ) use ( &$reads, $default ): array {
			$reads++;
			return $default( $dir );
		};

		$ci->dispatch( 'counts' );
		$ci->dispatch( 'top' );
		$ci->dispatch( 'accumulated' );

		$this->assertSame( 1, $reads, 'the three batched verbs must read the offsetlog exactly once' );
	}

	public function test_slice_verbs_are_refused_without_manage_options(): void {
		$ci = $this->ci_with_items( self::SEED );
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;
		foreach ( [ 'counts', 'top', 'accumulated' ] as $verb ) {
			try {
				$ci->dispatch( $verb );
				$this->fail( "verb '$verb' should be refused without manage_options" );
			} catch ( \RuntimeException $e ) {
				$this->assertStringContainsString( 'permission denied', $e->getMessage() );
			}
		}
	}

	public function test_insights_god_verb_is_gone_and_slice_verbs_registered(): void {
		$ci = new Insights_CI_Node();
		$ci->name( 'insights' );
		$commands = $ci->commands();
		$this->assertArrayNotHasKey( 'insights', $commands );
		$this->assertArrayHasKey( 'counts', $commands );
		$this->assertArrayHasKey( 'top', $commands );
		$this->assertArrayHasKey( 'accumulated', $commands );
		// generate / collect are kept.
		$this->assertArrayHasKey( 'generate', $commands );
		$this->assertArrayHasKey( 'collect', $commands );
	}

	public function test_node_schema_declares_slice_and_action_commands(): void {
		$schema = Insights_CI_Node::node_schema();
		$this->assertSame( 'Service', $schema['category'] );
		$this->assertSame(
			[ 'counts', 'top', 'accumulated', 'generate', 'collect' ],
			\array_column( $schema['commands'], 'name' )
		);
		foreach ( $schema['commands'] as $command ) {
			$this->assertSame( [], $command['args'] );
			$this->assertIsCallable( $command['handler'] );
		}
	}

	public function test_top_by_source_groups_into_per_source_top_10_sorted_by_score(): void {
		$items = [];
		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = [ 'source' => 'github', 'title' => "g{$i}", 'score' => (float) $i ];
		}
		$items[] = [ 'source' => 'linear', 'title' => 'l-lo', 'score' => 3.0 ];
		$items[] = [ 'source' => 'linear', 'title' => 'l-hi', 'score' => 9.0 ];

		$top = Insights_CI_Node::top_by_source( $items );

		$this->assertSame( [ 'github', 'linear' ], \array_keys( $top ) );
		$this->assertCount( 10, $top['github'] );
		$this->assertSame( 12.0, $top['github'][0]['score'] );
		$this->assertSame( 'g12', $top['github'][0]['title'] );
		$this->assertSame( 3.0, $top['github'][9]['score'], '10th is score 3; scores 1-2 are cut' );
		$this->assertSame( [ 'l-hi', 'l-lo' ], \array_column( $top['linear'], 'title' ) );
	}

	public function test_read_latest_digest_returns_newest_segment(): void {
		$path = $this->digest_path();
		\file_put_contents( $path . '.0', 'old digest' );
		\file_put_contents( $path . '.1', 'new digest' );
		$this->assertSame( 'new digest', Insights_CI_Node::read_latest_digest( $path ) );
	}

	public function test_read_latest_digest_missing_file_is_empty_string(): void {
		$this->assertSame( '', Insights_CI_Node::read_latest_digest( $this->digest_path() . '-none' ) );
	}

	public function test_read_latest_digest_ignores_non_numeric_segments(): void {
		$path = $this->digest_path();
		\file_put_contents( $path . '.tmp', 'not a segment' );
		$this->assertSame( '', Insights_CI_Node::read_latest_digest( $path ) );
	}

	public function test_live_workers_lists_topology_workers_from_lock_dirs(): void {
		$base            = $this->make_temp_dir( 'insights-ci-workers-' );
		$this->created[] = $base;
		\mkdir( $base . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		\mkdir( $base . '/locks/newspack-ai-newsletter.p1.lock.d', 0777, true );
		\mkdir( $base . '/locks/other.p0.lock.d', 0777, true );

		$workers = Insights_CI_Node::live_workers( $base );
		\sort( $workers );
		$this->assertSame(
			[ 'newspack-ai-newsletter.p0', 'newspack-ai-newsletter.p1' ],
			$workers
		);
	}

	public function test_collect_errors_when_no_worker_is_live(): void {
		$base            = $this->make_temp_dir( 'insights-ci-nocollect-' );
		$this->created[] = $base;
		$result          = Insights_CI_Node::collect( new Command_Interpreter_Node(), $base );
		$parsed          = \json_decode( $result, true );
		$this->assertIsArray( $parsed );
		$this->assertStringContainsString( 'No live', (string) $parsed['error'] );
	}

	public function test_collect_routes_reset_and_tick_requests_to_each_live_worker(): void {
		$base            = $this->make_temp_dir( 'insights-ci-collect-' );
		$this->created[] = $base;
		\mkdir( $base . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		$interpreter = new Capturing_Interpreter();

		$result = Insights_CI_Node::collect( $interpreter, $base );
		$parsed = \json_decode( $result, true );

		$this->assertSame( [ 'collecting' => 3, 'workers' => 1 ], $parsed );
		$this->assertNotNull( $interpreter->partition );
		$this->assertTrue( $interpreter->partition->voided );
		$this->assertSame( 1, $interpreter->partition->flushes );
		$this->assertSame( 'Partition', $interpreter->made_type );
		$this->assertSame( 'newspack-ai-newsletter.p0', $interpreter->made_name );
		$this->assertSame( $base . '/ipc/newspack-ai-newsletter.p0/input', $interpreter->made_args[0] );
		$this->assertSame(
			[
				'newspack-ai-newsletter.p0/digest',
				'newspack-ai-newsletter.p0/github',
				'newspack-ai-newsletter.p0/linear',
				'newspack-ai-newsletter.p0/feed',
			],
			\array_column( $interpreter->messages, Message::TO )
		);
		$this->assertSame( [ 'RESET', 'TICK', 'TICK', 'TICK' ], \array_column( $interpreter->messages, Message::VALUE ) );
	}

	public function test_regenerate_errors_when_no_worker_is_live(): void {
		$base            = $this->make_temp_dir( 'insights-ci-noregen-' );
		$this->created[] = $base;
		$result          = Insights_CI_Node::regenerate( new Command_Interpreter_Node(), $base );
		$parsed          = \json_decode( $result, true );
		$this->assertIsArray( $parsed );
		$this->assertStringContainsString( 'No live', (string) $parsed['error'] );
	}

	public function test_regenerate_routes_one_request_to_the_digest_node(): void {
		$base            = $this->make_temp_dir( 'insights-ci-regen-' );
		$this->created[] = $base;
		\mkdir( $base . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		$interpreter = new Capturing_Interpreter();

		$result = Insights_CI_Node::regenerate( $interpreter, $base );
		$parsed = \json_decode( $result, true );

		$this->assertSame( [ 'regenerating' => true, 'workers' => 1 ], $parsed );
		$this->assertCount( 1, $interpreter->messages );
		$this->assertSame( 'newspack-ai-newsletter.p0/digest', $interpreter->messages[0][ Message::TO ] );
		$this->assertSame( 'REGENERATE', $interpreter->messages[0][ Message::VALUE ] );
	}

	/** A digest path under a fresh tracked temp dir (one per test). */
	private function digest_path(): string {
		if ( null === $this->digest_dir ) {
			$this->digest_dir = $this->make_temp_dir( 'insights-ci-digest-' );
			$this->created[]  = $this->digest_dir;
		}
		return $this->digest_dir . '/digest.md';
	}

	/**
	 * DIGEST_PATH moved from the retired Settings singleton onto Digest_Builder_Node
	 * (Task B of the topology-config migration). Structural guard: the production
	 * source must read the relocated constant, not the old one.
	 */
	public function test_reads_digest_path_from_digest_builder_node_not_settings(): void {
		$source = (string) \file_get_contents( \dirname( __DIR__, 2 ) . '/includes/class-insights-ci-node.php' );
		$this->assertStringNotContainsString( 'Settings::DIGEST_PATH', $source );
		$this->assertStringContainsString( 'Digest_Builder_Node::DIGEST_PATH', $source );
	}
}

class Capturing_Interpreter extends Command_Interpreter_Node {
	public ?Inspectable_Partition_Node $partition = null;
	public ?string $made_type = null;
	public ?string $made_name = null;
	/** @var array<int,mixed> */
	public array $made_args = [];
	/** @var array<int,array<int,mixed>> */
	public array $messages = [];

	public function make_node( string $type, string $name, ...$args ): ?Node {
		$this->made_type = $type;
		$this->made_name = $name;
		$this->made_args = $args;
		$this->partition = new Inspectable_Partition_Node();
		return $this->partition;
	}

	public function fill( array &$message ): void {
		$this->messages[] = $message;
	}
}

class Inspectable_Partition_Node extends Partition_Node {
	public bool $voided = false;
	public int $flushes = 0;

	public function void_warranty(): Partition_Node {
		$this->voided = true;
		return $this;
	}

	public function flush(): void {
		++$this->flushes;
	}
}

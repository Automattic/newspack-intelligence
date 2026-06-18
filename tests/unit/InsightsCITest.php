<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Insights_CI_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Insights_CI is the dashboard's server read. Beyond the scored-pipeline model
 * (sources/top/accumulated) it surfaces the REAL rendered digest — the latest
 * `digest:log` segment — and routes Collect / Regenerate to the worker's nodes
 * over the input IPC partition (the request graph never composes itself).
 */
final class InsightsCITest extends TestCase {

	private string $tmp = '';

	protected function setUp(): void {
		parent::setUp();
		$this->tmp = \sys_get_temp_dir() . '/insights-ci-' . \uniqid();
		\mkdir( $this->tmp, 0777, true );
	}

	protected function tearDown(): void {
		self::rrmdir( $this->tmp );
		parent::tearDown();
	}

	/** Recursively remove a temp dir (handles the nested lock dirs collect tests create). */
	private static function rrmdir( string $dir ): void {
		if ( ! \is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) \glob( $dir . '/*' ) as $path ) {
			\is_dir( $path ) ? self::rrmdir( $path ) : \unlink( $path );
		}
		\rmdir( $dir );
	}

	public function test_read_latest_digest_returns_newest_segment(): void {
		$path = $this->tmp . '/digest.md';
		\file_put_contents( $path . '.0', 'old digest' );
		\file_put_contents( $path . '.1', 'new digest' );
		$this->assertSame( 'new digest', Insights_CI_Node::read_latest_digest( $path ) );
	}

	public function test_read_latest_digest_missing_file_is_empty_string(): void {
		$this->assertSame( '', Insights_CI_Node::read_latest_digest( $this->tmp . '/none.md' ) );
	}

	public function test_read_latest_digest_ignores_non_numeric_segments(): void {
		$path = $this->tmp . '/digest.md';
		\file_put_contents( $path . '.tmp', 'not a segment' );
		$this->assertSame( '', Insights_CI_Node::read_latest_digest( $path ) );
	}

	public function test_insights_model_carries_the_digest(): void {
		$path = $this->tmp . '/digest.md';
		\file_put_contents( $path . '.0', '## Real digest' );
		// No scored offsetlogs in $this->tmp, so the pipeline model is empty, but the digest is present.
		$model = Insights_CI_Node::read_insights_model( $this->tmp, $path );
		$this->assertSame( '## Real digest', $model['digest'] );
		$this->assertSame( 0, $model['accumulated'] );
	}

	public function test_build_insights_json_returns_an_encoded_model(): void {
		$node = new Insights_CI_Node();
		$json = $node->build_insights_json();
		$model = \json_decode( $json, true );

		$this->assertIsArray( $model );
		$this->assertArrayHasKey( 'sources', $model );
		$this->assertArrayHasKey( 'digest', $model );
		$this->assertArrayHasKey( 'accumulated', $model );
	}

	public function test_top_by_source_groups_into_per_source_top_10_sorted_by_score(): void {
		$items = [];
		// github: 12 items (scores 1..12) — its top 10 must be 12..3, desc.
		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = [ 'source' => 'github', 'title' => "g{$i}", 'score' => (float) $i ];
		}
		// linear: 2 items, out of order.
		$items[] = [ 'source' => 'linear', 'title' => 'l-lo', 'score' => 3.0 ];
		$items[] = [ 'source' => 'linear', 'title' => 'l-hi', 'score' => 9.0 ];

		$top = Insights_CI_Node::top_by_source( $items );

		// Keyed per source, first-seen order.
		$this->assertSame( [ 'github', 'linear' ], \array_keys( $top ) );
		// Capped at TOP_N (10), sorted by score desc.
		$this->assertCount( 10, $top['github'] );
		$this->assertSame( 12.0, $top['github'][0]['score'] );
		$this->assertSame( 'g12', $top['github'][0]['title'] );
		$this->assertSame( 3.0, $top['github'][9]['score'], '10th is score 3; scores 1-2 are cut' );
		$this->assertSame( [ 'l-hi', 'l-lo' ], \array_column( $top['linear'], 'title' ) );
	}

	public function test_model_carries_collection_progress_keys(): void {
		// No snapshots → progress is zeroed but always present (the dashboard gates on it).
		$model = Insights_CI_Node::read_insights_model( $this->tmp, $this->tmp . '/none.md' );
		$this->assertSame( 0, $model['done'] );
		$this->assertSame( 0, $model['total'] );
	}

	public function test_insights_model_reads_scored_cache_items_and_progress(): void {
		$path = $this->tmp . '/digest.md';
		\file_put_contents( $path . '.0', '# Digest' );
		$this->write_scored_cache(
			'scored.p0',
			[
				'items' => [
					[ 'source' => 'github', 'title' => 'Release', 'score' => '8.5' ],
					[ 'source' => 'linear', 'title' => 'Issue', 'score' => 3 ],
					'not-an-item',
				],
				'done'  => '2',
				'total' => '3',
			]
		);

		$model = Insights_CI_Node::read_insights_model( $this->tmp, $path );

		$this->assertSame( [ 'github' => 1, 'linear' => 1 ], $model['sources'] );
		$this->assertSame( 2, $model['accumulated'] );
		$this->assertSame( '# Digest', $model['digest'] );
		$this->assertSame( 2, $model['done'] );
		$this->assertSame( 3, $model['total'] );
		$this->assertSame( 8.5, $model['top']['github'][0]['score'] );
		$this->assertSame( 'Issue', $model['top']['linear'][0]['title'] );
	}

	public function test_live_workers_lists_topology_workers_from_lock_dirs(): void {
		\mkdir( $this->tmp . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		\mkdir( $this->tmp . '/locks/newspack-ai-newsletter.p1.lock.d', 0777, true );
		\mkdir( $this->tmp . '/locks/other.p0.lock.d', 0777, true );

		$workers = Insights_CI_Node::live_workers( $this->tmp );
		\sort( $workers );
		$this->assertSame(
			[ 'newspack-ai-newsletter.p0', 'newspack-ai-newsletter.p1' ],
			$workers
		);
	}

	public function test_collect_errors_when_no_worker_is_live(): void {
		$result = Insights_CI_Node::collect( new Command_Interpreter_Node(), $this->tmp );
		$parsed = \json_decode( $result, true );
		$this->assertIsArray( $parsed );
		$this->assertStringContainsString( 'No live', (string) $parsed['error'] );
	}

	public function test_collect_routes_reset_and_tick_requests_to_each_live_worker(): void {
		\mkdir( $this->tmp . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		$interpreter = new Capturing_Interpreter();

		$result = Insights_CI_Node::collect( $interpreter, $this->tmp );
		$parsed = \json_decode( $result, true );

		$this->assertSame( [ 'collecting' => 3, 'workers' => 1 ], $parsed );
		$this->assertNotNull( $interpreter->partition );
		$this->assertTrue( $interpreter->partition->voided );
		$this->assertSame( 1, $interpreter->partition->flushes );
		$this->assertSame( 'Partition', $interpreter->made_type );
		$this->assertSame( 'newspack-ai-newsletter.p0', $interpreter->made_name );
		$this->assertSame( $this->tmp . '/ipc/newspack-ai-newsletter.p0/input', $interpreter->made_args[0] );
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
		$result = Insights_CI_Node::regenerate( new Command_Interpreter_Node(), $this->tmp );
		$parsed = \json_decode( $result, true );
		$this->assertIsArray( $parsed );
		$this->assertStringContainsString( 'No live', (string) $parsed['error'] );
	}

	public function test_regenerate_routes_one_request_to_the_digest_node(): void {
		\mkdir( $this->tmp . '/locks/newspack-ai-newsletter.p0.lock.d', 0777, true );
		$interpreter = new Capturing_Interpreter();

		$result = Insights_CI_Node::regenerate( $interpreter, $this->tmp );
		$parsed = \json_decode( $result, true );

		$this->assertSame( [ 'regenerating' => true, 'workers' => 1 ], $parsed );
		$this->assertNotNull( $interpreter->partition );
		$this->assertTrue( $interpreter->partition->voided );
		$this->assertSame( 1, $interpreter->partition->flushes );
		$this->assertCount( 1, $interpreter->messages );
		$this->assertSame( 'newspack-ai-newsletter.p0/digest', $interpreter->messages[0][ Message::TO ] );
		$this->assertSame( 'REGENERATE', $interpreter->messages[0][ Message::VALUE ] );
	}

	public function test_node_schema_declares_dashboard_commands(): void {
		$schema = Insights_CI_Node::node_schema();

		$this->assertSame( 'Service', $schema['category'] );
		$this->assertSame(
			[ 'insights', 'generate', 'collect' ],
			\array_column( $schema['commands'], 'name' )
		);
		foreach ( $schema['commands'] as $command ) {
			$this->assertSame( [], $command['args'] );
			$this->assertIsCallable( $command['handler'] );
		}
	}

	/** @param array<string,mixed> $cache */
	private function write_scored_cache( string $dir, array $cache ): void {
		$offset_dir = $this->tmp . '/' . $dir;
		\mkdir( $offset_dir, 0777, true );
		$message                   = Message::new_message();
		$message[ Message::VALUE ] = [ 'cache' => $cache ];
		\file_put_contents( $offset_dir . '/0.log', Message::packed( $message ) . "\n" );
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

<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Insights_CI_Node;
use Newspack_Nodes\Command_Interpreter_Node;
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

	public function test_insights_model_carries_the_digest(): void {
		$path = $this->tmp . '/digest.md';
		\file_put_contents( $path . '.0', '## Real digest' );
		// No scored offsetlogs in $this->tmp, so the pipeline model is empty, but the digest is present.
		$model = Insights_CI_Node::read_insights_model( $this->tmp, $path );
		$this->assertSame( '## Real digest', $model['digest'] );
		$this->assertSame( 0, $model['accumulated'] );
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

	public function test_regenerate_errors_when_no_worker_is_live(): void {
		$result = Insights_CI_Node::regenerate( new Command_Interpreter_Node(), $this->tmp );
		$parsed = \json_decode( $result, true );
		$this->assertIsArray( $parsed );
		$this->assertStringContainsString( 'No live', (string) $parsed['error'] );
	}
}

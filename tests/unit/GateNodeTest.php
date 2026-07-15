<?php
declare(strict_types=1);

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Gate_Node;
use Newspack_AI_Newsletter\Publisher_Matcher;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/fake-entity-extractor.php';

final class GateNodeTest extends TestCase {

	protected function tearDown(): void {
		Gate_Node::$matcher_factory = null;
		parent::tearDown();
	}

	private function repo(): Fake_Publisher_Repository {
		$repo             = new Fake_Publisher_Repository();
		$repo->store['1'] = [ 'atomic_site_id' => '1', 'domain_name' => 'abq.news', 'status' => 'active', 'publisher_name' => 'ABQ News', 'aliases' => '' ];
		return $repo;
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array<int,mixed>
	 */
	private function struct( array $value ): array {
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = $value;
		return $m;
	}

	private function gate_with( Publisher_Matcher $matcher, Capture_Sink_Node $sink ): Gate_Node {
		Gate_Node::$matcher_factory = static fn (): Publisher_Matcher => $matcher;
		$node                       = new Gate_Node();
		$node->sink( $sink );
		return $node;
	}

	public function test_domain_pass_emits_decision_with_timestamp(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->gate_with( new Publisher_Matcher( $this->repo(), 'csv-v1' ), $sink );
		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'i1', 'title' => 'T', 'url' => 'https://abq.news/x', 'body' => '' ] ) );

		$out = $sink->captured[0][ Message::VALUE ];
		$this->assertSame( 'pass', $out['decision'] );
		$this->assertSame( '1', $out['atomic_site_id'] );
		$this->assertSame( 'domain', $out['matched_on'] );
		$this->assertSame( 'csv-v1', $out['config_version'] );
		$this->assertNotEmpty( $out['ts'] );
	}

	public function test_deterministic_miss_without_extractor_holds(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->gate_with( new Publisher_Matcher( $this->repo(), 'csv-v1' ), $sink );
		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'i2', 'title' => 'Bake sale', 'url' => 'https://random.example/x', 'body' => '' ] ) );
		$this->assertSame( 'hold', $sink->captured[0][ Message::VALUE ]['decision'] );
	}

	public function test_ner_ignore_via_extractor(): void {
		$sink    = new Capture_Sink_Node();
		$matcher = new Publisher_Matcher( $this->repo(), 'csv-v1', new Fake_Entity_Extractor( [ 'Acme Widgets Corporation' ] ) );
		$node    = $this->gate_with( $matcher, $sink );
		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'i3', 'title' => 'Factory', 'url' => 'https://random.example/x', 'body' => '' ] ) );
		$this->assertSame( 'ignore', $sink->captured[0][ Message::VALUE ]['decision'] );
	}

	public function test_forwards_done_signal_unchanged(): void {
		$sink                = new Capture_Sink_Node();
		$node                = $this->gate_with( new Publisher_Matcher( $this->repo(), 'csv-v1' ), $sink );
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_INFO;
		$m[ Message::VALUE ] = "DONE\n";
		$node->fill( $m );
		$this->assertCount( 1, $sink->captured );
		$this->assertSame( "DONE\n", $sink->captured[0][ Message::VALUE ] );
	}

	public function test_node_schema_declares_transform_and_verbs(): void {
		$schema = Gate_Node::node_schema();
		$this->assertSame( 'Transform', $schema['category'] );
		$this->assertContains( 'set_config_version', \array_column( $schema['commands'], 'name' ) );
		$this->assertContains( 'set_vault_id', \array_column( $schema['commands'], 'name' ) );
		$this->assertTrue( $schema['accepts_fill'] );
		$this->assertTrue( $schema['has_target'] );
	}
}

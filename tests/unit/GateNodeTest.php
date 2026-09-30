<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Gate_Node;
use Newspack_Intelligence\Publisher_Matcher;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/fake-entity-extractor.php';
require_once __DIR__ . '/../support/wp-post-stubs.php';

final class GateNodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\NPAINL_WP_Post_Store::reset();
	}

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

	public function test_production_matcher_holds_unknown_item_with_default_config_version(): void {
		$sink = new Capture_Sink_Node();
		$node = new Gate_Node();
		$node->sink( $sink );

		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'p1', 'title' => 'Bake sale', 'url' => 'https://random.example/x', 'body' => '' ] ) );

		$out = $sink->captured[0][ Message::VALUE ];
		$this->assertSame( 'hold', $out['decision'] );
		$this->assertSame( '', $out['config_version'] );
	}

	public function test_set_config_version_stamps_every_decision(): void {
		$sink = new Capture_Sink_Node();
		$node = new Gate_Node();
		$node->sink( $sink );

		$this->assertSame( 'ok', $node->set_config_version( '  csv-v42  ' ) );
		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'p2', 'title' => 'T', 'url' => 'https://random.example/x', 'body' => '' ] ) );

		$this->assertSame( 'csv-v42', $sink->captured[0][ Message::VALUE ]['config_version'] );
	}

	public function test_cmd_set_config_version_delegates_to_patron(): void {
		$sink        = new Capture_Sink_Node();
		$node        = new Gate_Node();
		$node->sink( $sink );
		$interpreter = new Command_Interpreter_Node();
		$interpreter->patron( $node );

		$this->assertSame( 'ok', Gate_Node::cmd_set_config_version( $interpreter, [ 'version' => 'csv-v99' ] ) );

		$node->fill( $this->struct( [ 'source' => 'feed', 'id' => 'p3', 'title' => 'T', 'url' => 'https://random.example/x', 'body' => '' ] ) );
		$this->assertSame( 'csv-v99', $sink->captured[0][ Message::VALUE ]['config_version'] );
	}

	public function test_ignores_bytestream_messages(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->gate_with( new Publisher_Matcher( $this->repo(), 'csv-v1' ), $sink );
		$m                  = Message::new_message();
		$m[ Message::TYPE ] = Message::TM_BYTESTREAM;
		$m[ Message::VALUE ] = "raw\n";

		$node->fill( $m );

		$this->assertCount( 0, $sink->captured );
	}

	public function test_ignores_struct_with_non_array_value(): void {
		$sink = new Capture_Sink_Node();
		$node = $this->gate_with( new Publisher_Matcher( $this->repo(), 'csv-v1' ), $sink );
		$m                   = Message::new_message();
		$m[ Message::TYPE ]  = Message::TM_STRUCT;
		$m[ Message::VALUE ] = 'not-an-array';

		$node->fill( $m );

		$this->assertCount( 0, $sink->captured );
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

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/routes-to-workers.php';
require_once __DIR__ . '/../support/signs-in-users.php';
require_once __DIR__ . '/../support/counts-statements.php';

/**
 * The `intelligence` service CI: nine read commands over the projection, each
 * gated at `read`, its args bound by the schema, answering only rows of the
 * tiers a read caller holds, every answer carrying its citations.
 */
final class IntelligenceCITest extends TestCase {
	use Counts_Statements;
	use Routes_To_Workers;
	use Seeds_Intel_Graph;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const VERBS = [ 'search', 'entity', 'names', 'timeline', 'related', 'claims', 'trends', 'signals', 'review', 'theme_suggestions', 'collections', 'lenses', 'watches', 'save_watch', 'remove_watch', 'watch_matches', 'mark_seen', 'briefing', 'ask', 'ask_result', 'trace', 'health' ];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		$this->activate( 'newspack-intelligence' );
		$GLOBALS['_wp_test_current_user_can']['manage_options'] = true;
		$GLOBALS['_current_user_can']                           = true;
		self::sign_in( 3373, 'harbor-desk', 'manage_options' );
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;
		Intel_Graph_Row_Schema::$read_rows    = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_every_read_command_is_a_read(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \array_fill_keys( self::VERBS, Capabilities::READ ), \array_slice( $roles, 0, \count( self::VERBS ) ) );
	}

	/** The entity, claims, trends and signals commands describe the answer they give, field for field and in its order. */
	public function test_reads_describe_their_answers(): void {
		$described = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'description', 'name' );

		$this->assertStringContainsString( 'kinds: { churned, churn_signal, feature_request, complaint }', $described['entity'] );
		$this->assertStringContainsString( 'churn_items: [ { item_key, title, url, source, day } ], churn_note: { text, owner, next_step, day, by } } over the items naming it as a subject or a mention, each kind counting the items whose kind claims it, a churn kind\'s the client its kind subject names, last_item_ts the newest naming it as their subject', $described['entity'] );
		$this->assertStringContainsString( 'under the first open client it is about, a churn kind\'s the client its kind subject names', $described['briefing'] );
		$this->assertStringContainsString( 'categories counting the items of the window each lens admits, otherwise those none admits, as total counts every such item', $described['briefing'] );
		$this->assertStringContainsString( 'signals { clients: [ { entity_key, name, signals, churn_note } ], lenses: [ { lens_key, name, signals } ], judged }, each client the caller may see', $described['briefing'] );
		$this->assertStringContainsString( 'for an entity named with no lens, only the items naming it as their subject', $described['signals'] );
		$this->assertStringContainsString( 'other never signals on the about axis', $described['signals'] );
		$this->assertStringContainsString( 'only a value counted before those buckets', $described['signals'] );
		$this->assertStringContainsString( 'a kind it never put out before included', $described['signals'] );
		$this->assertStringContainsString( 'a story counted once', $described['signals'] );

		$this->assertStringContainsString( '{ claims: [ { subject, subject_name, predicate, object, object_name, confidence, item_key, url, title } ] }', $described['claims'] );
		$this->assertStringContainsString( 'the newest bucket first', $described['trends'] );
		$this->assertStringContainsString( 'a story counted once', $described['trends'] );
	}

	public function test_a_caller_below_read_is_refused(): void {
		$GLOBALS['_current_user_id']          = 3371;
		$GLOBALS['_wp_test_current_user_can'] = [];
		$GLOBALS['_current_user_can']         = false;

		foreach ( self::VERBS as $verb ) {
			try {
				$this->ci->dispatch( $verb, [] );
				$this->fail( "{$verb} answers a caller below read" );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'permission denied: read capability required', $e->getMessage() );
			}
		}
	}

	public function test_seed_ontology_refused_below_tune(): void {
		Capabilities::$session_scope = Capabilities::READ;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'permission denied: tune capability required' );
		$this->ci->dispatch( 'seed_ontology', [] );
	}

	public function test_reresolve_refused_below_manage(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p2' );
		Capabilities::$session_scope = Capabilities::TUNE;

		try {
			$this->ci->dispatch( 'reresolve', [ '2026-08-03', '2026-08-19' ] );
			$this->fail( 'reresolve answers a tune caller' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'permission denied: manage capability required', $e->getMessage() );
		}
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p2' ) );
		$this->assertSame( Capabilities::MANAGE, \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' )['reresolve'] ?? null, 'reresolve declares manage' );
	}

	/** `reresolve` asks each live worker's reresolver for the range, answering how many it asked. */
	public function test_reresolve_asks_every_live_worker(): void {
		$base = Config::get_base_directory();
		self::live_worker( $base, 'newspack-intelligence.p1' );
		self::live_worker( $base, 'newspack-intelligence.p4' );
		$router = Core::node( Node_Names::ROUTER );
		$this->assertNotNull( $router );
		$this->ci->sink( $router );

		$answer = $this->ci->dispatch( 'reresolve', [ '--since=2026-08-03', '--until=2026-08-19' ] );

		$this->assertSame( [ 'ok' => true, 'workers' => 2 ], $answer );
		foreach ( [ 'newspack-intelligence.p1', 'newspack-intelligence.p4' ] as $worker ) {
			$this->assertSame( [ [ 'reresolver', 'RERESOLVE 2026-08-03 2026-08-19' ] ], \array_map( static fn ( array $m ): array => [ $m[ Message::TO ], $m[ Message::VALUE ] ], self::worker_input( $base, $worker ) ), $worker );
		}
	}

	/** A day that does not parse is refused before anything is sent. */
	public function test_reresolve_rejects_bad_range(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p3' );

		try {
			$this->ci->dispatch( 'reresolve', [ '2026-13-01', '2026-13-09' ] );
			$this->fail( 'reresolve sends a month that does not exist' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'not a Y-m-d date: 2026-13-01', $e->getMessage() );
		}
		$this->assertSame( [], self::worker_input( Config::get_base_directory(), 'newspack-intelligence.p3' ) );
	}

	/** While there is no live worker, `reresolve` refuses naming the fix. */
	public function test_reresolve_refuses_with_no_live_worker(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No newspack-intelligence worker could be reached' );
		$this->ci->dispatch( 'reresolve', [ '2026-08-03', '2026-08-19' ] );
	}

	/** The service CI mounts once per request graph, and only it. */
	public function test_the_service_cis_mount_once(): void {
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		$this->assertInstanceOf( Command_Interpreter_Node::class, $interpreter );

		\Newspack_Intelligence\mount_service_cis( $interpreter );
		$first = Core::node( 'intelligence' );
		\Newspack_Intelligence\mount_service_cis( $interpreter );

		$this->assertInstanceOf( Intelligence_CI_Node::class, $first );
		$this->assertSame( $first, Core::node( 'intelligence' ) );
	}

	/** Args bind by position or by name, as each command declares them. */
	public function test_commands_bind_their_args(): void {
		$this->seed_world();
		$this->seed( self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ) );

		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $this->ci->dispatch( 'search', [ '--q=state award', '--source=feed' ] )['items'], 'item_key' ) );
		$this->assertSame( self::GAZETTE, $this->ci->dispatch( 'entity', [ 'organization:harbor-gazette' ] )['entity_key'] );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE, '--until=2026-10-02' ] )['items'], 'item_key' ) );
		$this->assertCount( 1, $this->ci->dispatch( 'related', [ self::GAZETTE, '--limit=1' ] )['entities'] );
		$this->assertSame( [ 'won' ], \array_column( $this->ci->dispatch( 'claims', [ self::GAZETTE, '--predicate=won' ] )['claims'], 'predicate' ) );
		$this->assertSame( [ '2026-10-01' ], \array_values( \array_unique( \array_column( $this->ci->dispatch( 'trends', [ 'kind', '--bucket=month' ] )['rows'], 'bucket' ) ) ) );
		$this->assertCount( 1, $this->ci->dispatch( 'review', [ 'links' ] )['links'] );
		$this->assertSame( [], $this->ci->dispatch( 'collections', [] )['collections'] );
	}

	/** A blank optional arg is a value, read as given: it matches nothing. */
	public function test_a_blank_optional_arg_is_a_value(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ),
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ) ]
		);

		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE, '--predicate= ' ] )['claims'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--source=' ] )['items'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--q= ' ] )['items'] );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no collection:  ' );
		$this->ci->dispatch( 'collections', [ '--key= ' ] );
	}

	public function test_limit_is_capped_at_200(): void {
		$events = [];
		for ( $n = 0; $n < 201; $n++ ) {
			$events[] = self::item( "feed:bulk-{$n}", "Bulk item {$n}", 'feed', 'public', self::$oct_06 + $n );
		}
		$this->seed( ...$events );

		$answer = $this->ci->dispatch( 'search', [ '--limit=500' ] );

		$this->assertCount( 200, $answer['items'] );
		$this->assertSame( 201, $answer['total'] );
	}

	/** `names` answers at most NAMES_LIMIT entities, the first by name. */
	public function test_names_answers_at_most_the_cap(): void {
		$events = [];
		for ( $n = 1; $n <= Graph_Query::NAMES_LIMIT + 3; $n++ ) {
			$events[] = self::entity( \sprintf( 'organization:gull-%02d', $n ), \sprintf( 'Gull Wire %02d', $n ) );
		}
		$this->seed( ...$events );

		$answer = $this->ci->dispatch( 'names', [ '--prefix=gull w' ] )['entities'];

		$this->assertSame( \array_map( static fn ( int $n ): string => \sprintf( 'organization:gull-%02d', $n ), \range( 1, Graph_Query::NAMES_LIMIT ) ), \array_column( $answer, 'entity_key' ) );
	}

	/** A prefix whose norm holds fewer than two characters is refused, punctuation and spaces aside. */
	public function test_names_refuses_a_prefix_under_two_characters(): void {
		$this->seed_world();

		foreach ( [ ' h. ', ' -- ' ] as $prefix ) {
			try {
				$this->ci->dispatch( 'names', [ "--prefix={$prefix}" ] );
				$this->fail( "names answers the prefix '{$prefix}'" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'prefix must hold at least 2 letters or digits', $e->getMessage() );
			}
		}
		$this->assertSame( [ 'organization:harbor-gazette' ], \array_column( $this->ci->dispatch( 'names', [ 'ha' ] )['entities'], 'entity_key' ) );
	}

	/**
	 * `names` reads the names a prefix leads through the stored word starts,
	 * searched by their key, and the norms they name through each table's
	 * norm index, and reads no identifier, so it never loads the index whole.
	 */
	public function test_names_reads_by_stored_starts_and_never_loads_the_index(): void {
		$this->seed_world();
		$this->seed( self::entity( 'organization:kestrel-post', 'Old Kestrel Post' ) );

		$queries = $this->queries_of( fn () => $this->ci->dispatch( 'names', [ 'kestrel' ] ) );

		$this->assertSame( [], \array_values( \array_filter( $queries, static fn ( string $sql ): bool => \str_contains( $sql, '.identifiers' ) ) ) );
		$starts = \array_values( \array_filter( $queries, static fn ( string $sql ): bool => \str_contains( $sql, '.name_starts' ) ) );
		$this->assertCount( 1, $starts );
		$db   = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
		$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $starts[0] )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
		$this->assertMatchesRegularExpression( '/SEARCH p0\.name_starts USING COVERING INDEX sqlite_autoindex_name_starts_1 \(start>\? AND start<\?\)/', $plan );
		$this->assertMatchesRegularExpression( '/SEARCH p0\.entities USING INDEX entities_norm \(norm=\?\)/', $plan );
		$this->assertMatchesRegularExpression( '/SEARCH p0\.names USING INDEX names_norm \(norm=\?\)/', $plan );
		$this->assertDoesNotMatchRegularExpression( '/SCAN p0\.(name_starts|entities|names)\b/', $plan );
		$this->assertSame( [ 'organization:kestrel-post' ], \array_column( $this->ci->dispatch( 'names', [ 'kestrel' ] )['entities'], 'entity_key' ) );
	}

	/**
	 * The folder list reads each folder's recency from `events` through its
	 * subject and kind, and its count from `collected` through its key, so
	 * neither scans its table.
	 */
	public function test_the_folder_list_searches_events_by_subject_and_kind(): void {
		$this->seed(
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'tern-desk', 'name' => 'Tern desk' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'tern-desk', 'item_key' => 'feed:tern-17', 'url' => 'https://tern.example/17', 'title' => 'Tern', 'tier' => 'public' ], 'user:41', 'fixture-9' ),
			]
		);

		$queries = $this->queries_of( fn () => $this->ci->dispatch( 'collections', [] ) );

		$listed = \array_values( \array_filter( $queries, static fn ( string $sql ): bool => \str_contains( $sql, '.events' ) ) );
		$this->assertCount( 1, $listed );
		$db = new \PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
		$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $listed[0] )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
		$this->assertMatchesRegularExpression( '/SEARCH p0\.events USING INDEX events_subject_kind \(subject=\? AND kind=\?\)/', $plan );
		$this->assertMatchesRegularExpression( '/SEARCH p0\.collected USING (COVERING )?INDEX sqlite_autoindex_collected_1 \(collection_key=\?\)/', $plan );
		$this->assertDoesNotMatchRegularExpression( '/SCAN p0\.(events|collected)\b/', $plan );
	}

	/** A start a proposal's name left behind, once a person's creation renamed the entity, matches nothing. */
	public function test_names_finds_nothing_by_a_start_its_entity_no_longer_spells(): void {
		$this->seed(
			[ Intel_Event::make( 'entity_proposed', [ 'entity_key' => 'organization:quill-ledger', 'type' => 'organization', 'name' => 'Quill Co', 'from_item_key' => 'feed:seed-1' ], 'machine:resolve@0.0.1', 'fixture-7' ) ],
			self::entity( 'organization:quill-ledger', 'Quill Ledger' )
		);

		$this->assertSame( [], $this->ci->dispatch( 'names', [ 'quill c' ] )['entities'] );
		$this->assertSame( [ 'organization:quill-ledger=Quill Ledger' ], \array_map( static fn ( array $e ): string => "{$e['entity_key']}={$e['name']}", $this->ci->dispatch( 'names', [ 'quill l' ] )['entities'] ) );
	}

	/** A prefix matches every norm it leads, a character past the BMP after it included, and nothing past its range. */
	public function test_names_matches_a_prefix_exactly_at_its_range_bound(): void {
		$this->seed(
			self::entity( 'organization:quill-fraktur', "Quill \u{1D50F}edger" ),
			self::entity( 'organization:quillon', 'Quillon Desk' ),
			self::entity( 'organization:quilm-post', 'Quilm Post' ),
			self::entity( 'organization:quilk-post', 'Quilk Post' )
		);

		$this->assertSame( [ 'organization:quill-fraktur', 'organization:quillon' ], \array_column( $this->ci->dispatch( 'names', [ 'quill' ] )['entities'], 'entity_key' ) );
		$this->assertSame( [ 'organization:quill-fraktur' ], \array_column( $this->ci->dispatch( 'names', [ "quill \u{1D50F}" ] )['entities'], 'entity_key' ) );
	}

	/**
	 * With two partitions attached, so `all()` reads each table as a
	 * compound, every arm still searches its own index.
	 */
	public function test_names_searches_every_partitions_indexes(): void {
		$this->seed_world();
		$this->seed( self::entity( 'organization:kestrel-post', 'Old Kestrel Post' ) );
		$p0 = Table_Node::file( 'intel-graph', 0 );
		$p1 = Table_Node::file( 'intel-graph', 1 );
		( new \PDO( "sqlite:{$p0}" ) )->exec( "VACUUM INTO '{$p1}'" );
		$db = new Counting_PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( $p0 ) . ' AS p0' );
		$db->exec( 'ATTACH DATABASE ' . $db->quote( $p1 ) . ' AS p1' );
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( new \Newspack_Nodes\Row_Reader( $db, [ 'p0', 'p1' ] ) );

		$this->assertSame( [ 'organization:kestrel-post' ], \array_column( $this->ci->dispatch( 'names', [ 'kestrel' ] )['entities'], 'entity_key' ) );
		$starts = \array_values( \array_filter( $db->queries, static fn ( string $sql ): bool => \str_contains( $sql, '.name_starts' ) ) );
		$this->assertCount( 1, $starts );
		$plan = \implode( "\n", \array_column( $db->query( 'EXPLAIN QUERY PLAN ' . $starts[0] )->fetchAll( \PDO::FETCH_ASSOC ), 'detail' ) );
		$this->assertSame( 2, \preg_match_all( '/SEARCH p[01]\.name_starts USING COVERING INDEX sqlite_autoindex_name_starts_1 \(start>\? AND start<\?\)/', $plan ), $plan );
		$this->assertSame( 2, \preg_match_all( '/SEARCH p[01]\.entities USING INDEX entities_norm \(norm=\?\)/', $plan ), $plan );
		$this->assertSame( 2, \preg_match_all( '/SEARCH p[01]\.names USING INDEX names_norm \(norm=\?\)/', $plan ), $plan );
		$this->assertDoesNotMatchRegularExpression( '/SCAN p[01]\.(name_starts|entities|names)\b/', $plan );
	}

	/** `names` reads the same number of statements whatever the number of names it weighs. */
	public function test_names_statements_hold_whatever_the_candidates(): void {
		$few = [];
		for ( $n = 1; $n <= 2; $n++ ) {
			$few[] = self::proposal( "organization:tern-{$n}", "Tern Post {$n}", 'feed:gazette-award' );
		}
		$this->seed_world();
		$this->seed( ...$few );
		$two = $this->statements_of( fn () => $this->ci->dispatch( 'names', [ 'tern' ] ) );

		$many = [];
		for ( $n = 3; $n <= Graph_Query::NAMES_LIMIT + 4; $n++ ) {
			$many[] = self::proposal( "organization:tern-{$n}", "Tern Post {$n}", 'feed:gazette-award' );
			$many[] = self::entity( "organization:tern-desk-{$n}", "Tern Desk {$n}" );
		}
		$this->seed( ...$many );

		$this->assertCount( Graph_Query::NAMES_LIMIT, $this->ci->dispatch( 'names', [ 'tern' ] )['entities'] );
		$this->assertSame( $two, $this->statements_of( fn () => $this->ci->dispatch( 'names', [ 'tern' ] ) ) );
	}

	/** Review Focus 4: a restricted item never reaches a read caller, alone or through an aggregate. */
	public function test_a_restricted_row_never_reaches_a_read_caller(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:quiet-partner', 'Quiet Partner' ),
			self::extraction( 'hubspot:deal-9', 'A churn risk.', 'churn_signal', self::$oct_13, [ 'topic:awards' ] ),
			self::link( 'link_asserted', 'hubspot:deal-9', self::GAZETTE, 'subject', 'identifier', 1.0, self::$oct_13 ),
			self::link( 'link_asserted', 'hubspot:deal-9', 'organization:quiet-partner', 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'hubspot:deal-9', 'organization:salem-ledger', 'mention', 'ner_fuzzy', 0.6, self::$oct_13 ),
			self::claim( 'hubspot:deal-9', 0, self::GAZETTE, 'partners_with', 'organization:quiet-partner', 'Quiet Partner', self::$oct_13 ),
			self::proposal( 'organization:hidden-buyer', 'Hidden Buyer', 'hubspot:deal-9' ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:deals', 'name' => 'Deals' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:deals', 'item_key' => 'hubspot:deal-9', 'url' => self::url( 'hubspot:deal-9' ), 'title' => 'Harbor Gazette deal at risk', 'tier' => 'restricted' ], 'user:41', 'fixture-9' ),
			]
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );

		$this->assertNotContains( 'hubspot:deal-9', \array_column( $this->ci->dispatch( 'search', [] )['items'], 'item_key' ) );
		$this->assertSame( 3, $this->ci->dispatch( 'search', [] )['total'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--q=risk' ] )['items'] );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'], 'item_key' ) );
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 0 ], $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 1 ], $this->ci->dispatch( 'entity', [ 'organization:salem-ledger' ] )['counts'] );
		$related = $this->ci->dispatch( 'related', [ self::GAZETTE ] )['entities'];
		$this->assertSame( [ 'topic:awards' => 2, 'organization:salem-ledger' => 1 ], \array_column( $related, 'shared_items', 'entity_key' ) );
		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );
		$this->assertNotContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind' ] )['rows'], 'value' ) );
		$this->assertSame( [ 1, 1, 1 ], \array_column( $this->ci->dispatch( 'trends', [ 'topic', '--bucket=week' ] )['rows'], 'count' ) );
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'review', [ 'links' ] )['links'], 'item_key' ) );
		$this->assertSame( [], $this->ci->dispatch( 'review', [ 'entities' ] )['entities'] );
		try {
			$this->ci->dispatch( 'entity', [ 'organization:hidden-buyer' ] );
			$this->fail( 'a proposal only a restricted item cites answers' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'no entity: organization:hidden-buyer', $e->getMessage() );
		}
		$this->assertSame( [ 0 ], \array_column( $this->ci->dispatch( 'collections', [] )['collections'], 'items' ) );
		$this->assertSame( [], $this->ci->dispatch( 'collections', [ 'col:deals' ] )['items'] );
	}

	/**
	 * Only an accepted entity answers without a readable citation; one a
	 * person rejected answers while a readable item cites it, and not
	 * when only a restricted one does.
	 */
	public function test_an_entity_short_of_accepted_needs_a_readable_citation(): void {
		$this->seed_world();
		$this->seed(
			self::entity( 'organization:quiet-partner', 'Quiet Partner' ),
			self::proposal( 'organization:hidden-buyer', 'Hidden Buyer', 'hubspot:deal-9' ),
			self::rejected( 'organization:hidden-buyer' ),
			self::proposal( 'organization:open-buyer', 'Open Buyer', 'feed:gazette-award' ),
			self::rejected( 'organization:open-buyer' ),
			self::link( 'link_asserted', 'hubspot:deal-9', 'organization:quiet-partner', 'mention', 'name', 1.0, self::$oct_13 ),
			self::link( 'link_proposed', 'hubspot:deal-9', 'organization:hidden-buyer', 'mention', 'ner_fuzzy', 0.6, self::$oct_13 )
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );

		$this->assertSame( [ 'Quiet Partner', 'accepted', [ 'items' => 0, 'proposed_links' => 0 ] ], \array_values( \array_intersect_key( $this->ci->dispatch( 'entity', [ 'organization:quiet-partner' ] ), [ 'name' => 1, 'state' => 1, 'counts' => 1 ] ) ) );
		$this->assertSame( [ 'Open Buyer', 'rejected' ], \array_values( \array_intersect_key( $this->ci->dispatch( 'entity', [ 'organization:open-buyer' ] ), [ 'name' => 1, 'state' => 1 ] ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no entity: organization:hidden-buyer' );
		$this->ci->dispatch( 'entity', [ 'organization:hidden-buyer' ] );
	}

	/** A proposal's sentence answers only through an item the reader may read. */
	public function test_a_restricted_items_sentence_never_answers(): void {
		$this->seed_world();
		$this->seed(
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'hubspot:deal-9', 'Tidewater Times will not renew next quarter.' ),
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award', 'Tidewater Times judged the state award.' )
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );

		$this->assertSame(
			[ [ 'feed:gazette-award', 'Tidewater Times judged the state award.' ] ],
			\array_map( static fn ( array $item ): array => [ $item['item_key'], $item['evidence'] ], $this->ci->dispatch( 'review', [ 'entities' ] )['entities'][0]['items'] )
		);
	}

	/** Every row an answer lists names the items it rests on, with their URLs where the shape carries one. */
	public function test_every_answer_carries_citations(): void {
		$this->seed_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 0, self::GAZETTE, 'won', '', 'State Press Award', self::$oct_06 ),
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ),
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col:october', 'item_key' => 'feed:gazette-award', 'url' => self::url( 'feed:gazette-award' ), 'title' => 'Harbor Gazette wins state award', 'tier' => 'public' ], 'user:41', 'fixture-9' ),
			]
		);
		$cited = function ( array $rows ): void {
			$this->assertNotSame( [], $rows );
			foreach ( $rows as $row ) {
				$this->assertStringStartsWith( 'https://news.example/', $row['url'] );
				$this->assertNotSame( '', $row['item_key'] );
			}
		};
		$keyed = function ( array $rows ) use ( $cited ): void {
			$this->assertNotSame( [], $rows );
			foreach ( $rows as $row ) {
				$this->assertNotSame( [], $row['items'] );
				$cited( $row['items'] );
			}
		};

		$cited( $this->ci->dispatch( 'search', [] )['items'] );
		$cited( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'] );
		$cited( $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );
		$cited( $this->ci->dispatch( 'review', [ 'links' ] )['links'] );
		$cited( \array_merge( ...\array_column( $this->ci->dispatch( 'review', [ 'entities' ] )['entities'], 'items' ) ) );
		$cited( $this->ci->dispatch( 'collections', [ 'col:october' ] )['items'] );
		$keyed( $this->ci->dispatch( 'related', [ self::GAZETTE ] )['entities'] );
		$keyed( $this->ci->dispatch( 'trends', [ 'topic' ] )['rows'] );
	}

	/** A link to an entity a curator rejected leaves the review, as does one to a key merged into it; a link to a live entity stays. */
	public function test_review_leaves_out_links_to_a_rejected_entity(): void {
		$this->seed_world();
		$this->seed(
			self::proposed( 'organization:gull-press', 'Gull Press' ),
			self::proposed( 'organization:gull-pres', 'Gull Pres' ),
			self::merged( 'organization:gull-pres', 'organization:gull-press' ),
			self::rejected( 'organization:gull-press' ),
			self::proposed( 'organization:tern-wire', 'Tern Wire' ),
			self::link( 'link_proposed', 'feed:gazette-award', 'organization:gull-press', 'mention', 'ner_fuzzy', 0.7, self::$oct_06 ),
			self::link( 'link_proposed', 'github:ledger-launch', 'organization:gull-pres', 'mention', 'ner_fuzzy', 0.7, self::$oct_13 ),
			self::link( 'link_proposed', 'feed:gazette-award', 'organization:tern-wire', 'mention', 'ner_fuzzy', 0.7, self::$oct_06 )
		);

		$this->assertSame( [ 'organization:tern-wire', 'organization:salem-ledger' ], \array_column( $this->ci->dispatch( 'review', [ 'links' ] )['links'], 'entity_key' ) );
	}

	/** An entity's state in the review is the highest partition's holding one. */
	public function test_review_reads_a_rejection_from_the_highest_partition(): void {
		$this->seed_world();
		$this->seed(
			self::proposed( 'organization:gull-press', 'Gull Press' ),
			self::link( 'link_proposed', 'feed:gazette-award', 'organization:gull-press', 'mention', 'ner_fuzzy', 0.7, self::$oct_06 )
		);
		$pending = Table_Node::file( 'intel-graph', 0 );
		$copy    = $this->make_temp_dir( 'intel-p1-' ) . '/intel-graph.p1.sqlite';
		( new \PDO( 'sqlite:' . $pending ) )->exec( 'VACUUM INTO ' . ( new \PDO( 'sqlite::memory:' ) )->quote( $copy ) );
		$this->seed( self::rejected( 'organization:gull-press' ) );
		$read = static fn ( array $files ): \Closure => static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( $files, Intel_Graph_Row_Schema::version() ) );

		Intel_Graph_Row_Schema::$read_rows = $read( [ 0 => $copy, 1 => $pending ] );
		$rejected_last                     = \array_column( $this->ci->dispatch( 'review', [ 'links' ] )['links'], 'entity_key' );
		Intel_Graph_Row_Schema::$read_rows = $read( [ 0 => $pending, 1 => $copy ] );
		$proposed_last                     = \array_column( $this->ci->dispatch( 'review', [ 'links' ] )['links'], 'entity_key' );
		Intel_Graph_Row_Schema::$read_rows = null;

		$this->assertNotContains( 'organization:gull-press', $rejected_last );
		$this->assertContains( 'organization:gull-press', $proposed_last );
	}

	/**
	 * The newest canonical name wins whatever the entity was created as, a
	 * machine's proposal, a person's name or one of its own domains; with
	 * none it keeps its created name.
	 */
	public function test_an_entity_shows_its_newest_canonical_name(): void {
		$this->seed_world();
		$this->seed(
			self::proposed( 'organization:kestrelweekly', 'Kestrelweekly', [ 'canonical' => [ 'Kestrel Weekly' ] ] ),
			self::link( 'link_proposed', 'feed:gazette-award', 'organization:kestrelweekly', 'mention', 'ner_fuzzy', 0.7, self::$oct_06 ),
			self::entity( 'organization:tern-desk', 'Tern Desk', [ 'canonical' => [ 'Tern Desk Weekly', 'Tern Desk Daily' ] ] ),
			self::entity( 'organization:wrenpost', 'wrenpost.example', [ 'domain' => [ 'wrenpost.example' ], 'canonical' => [ 'Wren Post' ] ] ),
			self::entity( 'organization:gull-post', 'Gull Post', [ 'alias' => [ 'The Gull' ] ] )
		);

		$this->assertSame( 'Kestrel Weekly', $this->ci->dispatch( 'entity', [ 'organization:kestrelweekly' ] )['name'] );
		$this->assertSame( 'Tern Desk Daily', $this->ci->dispatch( 'entity', [ 'organization:tern-desk' ] )['name'] );
		$this->assertSame( 'Wren Post', $this->ci->dispatch( 'entity', [ 'organization:wrenpost' ] )['name'] );
		$this->assertSame( 'Gull Post', $this->ci->dispatch( 'entity', [ 'organization:gull-post' ] )['name'] );
	}

}

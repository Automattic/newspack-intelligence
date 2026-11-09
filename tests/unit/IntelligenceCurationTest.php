<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Graph_Curation;
use Newspack_Intelligence\Graph_Query;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Sessions;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/routes-to-workers.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The `intelligence` curation commands: each writes its judgment as one
 * ontology event under the caller's actor, a person or the agent a labelled
 * session names, and answers `{ ok, event_id }`. Each refusal throws before
 * anything is written, and every event fits one atomic partition line at
 * the caps its args are bounded by.
 */
final class IntelligenceCurationTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Routes_To_Workers;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const LEDGER = 'organization:salem-ledger';

	private const LOGIN = 'kinsey-reed';

	private const OFFBOARDING = 'client offboarding';

	private const PLATFORM = 'client offboarding from platform';

	private const AUTOMATION = 'offboarding automation';

	/** The title the grant was saved under, apart from its item row's. */
	private const SAVED_TITLE = 'Grant for two newsrooms, as saved';

	/** A theme only a restricted item is extracted with. */
	private const SETTLEMENT = 'board settlement talks';

	/** A proposal only a restricted item cites. */
	private const HIDDEN = 'organization:hidden-buyer';

	/** A rejected proposal only a restricted item cites. */
	private const SHELVED = 'organization:shelved-buyer';

	private const CURATION = [ 'confirm_link', 'reject_link', 'accept_entity', 'reject_entity', 'merge_entities', 'add_name', 'add_identifier', 'assert_role', 'end_role', 'tag_topic', 'create_entity', 'create_collection', 'collect', 'mark_used', 'move_item', 'save_lens', 'remove_lens', 'merge_theme' ];

	/** Each curation event kind, by the command that writes it. */
	private const VERB_OF = [
		'link_confirmed'     => 'confirm_link',
		'link_rejected'      => 'reject_link',
		'entity_accepted'    => 'accept_entity',
		'entity_rejected'    => 'reject_entity',
		'entity_merged'      => 'merge_entities',
		'name_added'         => 'add_name',
		'identifier_added'   => 'add_identifier',
		'role_ended'         => 'end_role',
		'role_asserted'      => 'assert_role',
		'topic_tagged'       => 'tag_topic',
		'entity_created'     => 'create_entity',
		'collection_created' => 'create_collection',
		'collected'          => 'collect',
		'item_used'          => 'mark_used',
		'item_moved'         => 'move_item',
		'theme_merged'       => 'merge_theme',
	];

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::sign_in( 37, self::LOGIN, 'manage_options' );
		$GLOBALS['_current_user_can'] = true;
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		$GLOBALS['_current_user_can']      = false;
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** Every curation command declares tune; fetch_now mounts a worker's input, so it declares manage. */
	public function test_each_curation_command_declares_its_role(): void {
		$roles = \array_column( Intelligence_CI_Node::node_schema()['commands'], 'capability', 'name' );

		$this->assertSame( \array_fill_keys( self::CURATION, Capabilities::TUNE ), \array_intersect_key( $roles, \array_flip( self::CURATION ) ) );
		$this->assertSame( Capabilities::MANAGE, $roles['fetch_now'] );
	}

	/**
	 * Each verb, its args, and the fields of the one event it writes.
	 *
	 * @return array<string,array{0:string,1:list<string>,2:array<string,mixed>}>
	 */
	public static function commands(): array {
		return [
			'confirm_link'      => [ 'confirm_link', [ 'linear:joint-grant', self::LEDGER ], [ 'event' => 'link_confirmed', 'item_key' => 'linear:joint-grant', 'entity_key' => self::LEDGER, 'item_url' => 'https://news.example/linear/joint-grant' ] ],
			'reject_link'       => [ 'reject_link', [ '--item=linear:joint-grant', '--entity=' . self::LEDGER ], [ 'event' => 'link_rejected', 'item_key' => 'linear:joint-grant', 'entity_key' => self::LEDGER, 'item_url' => 'https://news.example/linear/joint-grant' ] ],
			'accept_entity'     => [ 'accept_entity', [ self::LEDGER ], [ 'event' => 'entity_accepted', 'entity_key' => self::LEDGER ] ],
			'reject_entity'     => [ 'reject_entity', [ self::LEDGER ], [ 'event' => 'entity_rejected', 'entity_key' => self::LEDGER ] ],
			'merge_entities'    => [ 'merge_entities', [ self::LEDGER, self::GAZETTE ], [ 'event' => 'entity_merged', 'entity_key' => self::LEDGER, 'into_key' => self::GAZETTE ] ],
			'add_name'          => [ 'add_name', [ self::LEDGER, 'acronym', 'SALED' ], [ 'event' => 'name_added', 'entity_key' => self::LEDGER, 'kind' => 'acronym', 'value' => 'SALED' ] ],
			'add_identifier'    => [ 'add_identifier', [ self::LEDGER, 'domain', 'www.SalemLedger.example' ], [ 'event' => 'identifier_added', 'entity_key' => self::LEDGER, 'kind' => 'domain', 'value' => 'salemledger.example' ] ],
			'assert_role'       => [ 'assert_role', [ self::LEDGER, 'partner' ], [ 'event' => 'role_asserted', 'entity_key' => self::LEDGER, 'role' => 'partner', 'from_ts' => 1792497600 ] ],
			'end_role'          => [ 'end_role', [ self::GAZETTE, 'client' ], [ 'event' => 'role_ended', 'entity_key' => self::GAZETTE, 'role' => 'client', 'from_ts' => 1767225600, 'to_ts' => 1792497600 ] ],
			'tag_topic'         => [ 'tag_topic', [ self::LEDGER, 'topic:ai' ], [ 'event' => 'topic_tagged', 'entity_key' => self::LEDGER, 'topic_key' => 'topic:ai' ] ],
			'create_entity'     => [ 'create_entity', [ 'person', 'Marisol Okafor' ], [ 'event' => 'entity_created', 'entity_key' => 'person:marisol-okafor', 'type' => 'person', 'name' => 'Marisol Okafor' ] ],
			'create_collection' => [ 'create_collection', [ 'Grant Watch' ], [ 'event' => 'collection_created', 'collection_key' => 'grant-watch', 'name' => 'Grant Watch' ] ],
			'accept a proposal' => [ 'accept_entity', [ 'organization:tidewater-times' ], [ 'event' => 'entity_accepted', 'entity_key' => 'organization:tidewater-times' ] ],
			'end a merged role' => [ 'end_role', [ self::GAZETTE, 'partner' ], [ 'event' => 'role_ended', 'entity_key' => 'organization:gazette-daily', 'role' => 'partner', 'from_ts' => 1790856000, 'to_ts' => 1792497600 ] ],
			'create over one'   => [ 'create_entity', [ 'organization', 'Hidden Buyer' ], [ 'event' => 'entity_created', 'entity_key' => self::HIDDEN, 'type' => 'organization', 'name' => 'Hidden Buyer' ] ],
			'create over a hidden rejection' => [ 'create_entity', [ 'organization', 'Shelved Buyer' ], [ 'event' => 'entity_created', 'entity_key' => self::SHELVED, 'type' => 'organization', 'name' => 'Shelved Buyer' ] ],
			'accept a cited rejection'       => [ 'accept_entity', [ 'organization:open-buyer' ], [ 'event' => 'entity_accepted', 'entity_key' => 'organization:open-buyer' ] ],
			'collect'           => [ 'collect', [ 'col:october', 'github:ledger-launch' ], [ 'event' => 'collected', 'collection_key' => 'col:october', 'item_key' => 'github:ledger-launch', 'url' => 'https://news.example/github/ledger-launch', 'title' => 'Salem Ledger launches a newsletter tool', 'tier' => 'internal' ] ],
			'mark_used'         => [ 'mark_used', [ 'col-october', 'linear:joint-grant', 'newsletter', '2026-10-19' ], [ 'event' => 'item_used', 'collection_key' => 'col-october', 'item_key' => 'linear:joint-grant', 'tier' => 'internal', 'mark' => 'newsletter', 'day' => '2026-10-19' ] ],
			'collect into a second folder' => [ 'collect', [ 'col:october', 'linear:joint-grant' ], [ 'event' => 'collected', 'collection_key' => 'col:october', 'item_key' => 'linear:joint-grant', 'url' => 'https://news.example/linear/joint-grant', 'title' => 'Gazette and Ledger share a grant', 'tier' => 'internal' ] ],
			'move_item'         => [ 'move_item', [ 'col-october', 'linear:joint-grant', 'col:october' ], [ 'event' => 'item_moved', 'collection_key' => 'col:october', 'item_key' => 'linear:joint-grant', 'from' => 'col-october', 'tiers' => [ 'internal' ] ] ],
			'save_lens'         => [ 'save_lens', [ 'Ledger Watch', 'Ledger watch', '--entity=organization:gazette-weekly', '--kind=launch' ], [ 'event' => 'lens_saved', 'lens_key' => 'ledger-watch', 'name' => 'Ledger watch', 'description' => '' ] ],
			'remove_lens'       => [ 'remove_lens', [ 'harbor-desk' ], [ 'event' => 'lens_removed', 'lens_key' => 'harbor-desk' ] ],
			'merge_theme'       => [ 'merge_theme', [ self::PLATFORM, self::OFFBOARDING ], [ 'event' => 'theme_merged', 'from' => self::PLATFORM, 'into' => self::OFFBOARDING ] ],
			'merge a theme anew' => [ 'merge_theme', [ self::AUTOMATION, self::PLATFORM ], [ 'event' => 'theme_merged', 'from' => self::AUTOMATION, 'into' => self::PLATFORM ] ],
		];
	}

	/**
	 * Each command writes exactly one event with its fields, as the person
	 * calling, and answers its id.
	 *
	 * @param string              $verb   The verb.
	 * @param list<string>        $args   Its tokens.
	 * @param array<string,mixed> $fields The event's fields.
	 */
	#[DataProvider( 'commands' )]
	public function test_each_command_writes_one_event( string $verb, array $args, array $fields ): void {
		$this->seed_curation_world();

		$answer = $this->ci->dispatch( $verb, $args );

		$events = self::ontology_events();
		$this->assertCount( 1, $events );
		$this->assertSame( $fields, \array_intersect_key( $events[0], $fields ) );
		$this->assertSame( 'user:' . self::LOGIN, $events[0]['actor'] );
		$this->assertSame( \NEWSPACK_INTELLIGENCE_VERSION, $events[0]['config_version'] );
		$this->assertTrue( $answer['ok'] );
		$this->assertSame( $events[0]['event_id'], $answer['event_id'] );
	}

	/**
	 * Each command reads the projection once, however many keys and items it
	 * names.
	 *
	 * @param string       $verb The verb.
	 * @param list<string> $args Its tokens.
	 */
	#[DataProvider( 'commands' )]
	public function test_each_command_reads_the_projection_once( string $verb, array $args ): void {
		$this->seed_curation_world();
		$reads                             = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$reads ): mixed {
			++$reads;
			return Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};

		$this->ci->dispatch( $verb, $args );

		$this->assertSame( 1, $reads );
	}

	/** Each creation answers the key it minted, which every later command names. */
	public function test_a_creation_answers_its_key(): void {
		$this->seed_curation_world();

		$this->assertSame( 'person:marisol-okafor', $this->ci->dispatch( 'create_entity', [ 'person', 'Marisol Okafor' ] )['entity_key'] );
		$this->assertSame( 'grant-watch', $this->ci->dispatch( 'create_collection', [ 'Grant Watch' ] )['collection_key'] );
	}

	/** A call through an MCP connection records the agent the session names, and the projector ranks it an agent. */
	public function test_an_mcp_call_records_the_agent_actor(): void {
		$this->seed_curation_world();
		Capabilities::$session_label = "Kinsey's Claude";

		$this->ci->dispatch( 'confirm_link', [ 'linear:joint-grant', self::LEDGER ] );

		$events = self::ontology_events();
		$this->assertSame( "agent:kinsey-reed:Kinsey's Claude", $events[0]['actor'] );
		$this->seed( $events );
		$this->assertSame( [ [ 'agent', 'confirmed', "agent:kinsey-reed:Kinsey's Claude" ] ], self::graph_rows( 'judgments', '1', 'actor_kind, state, actor' ) );
	}

	/** A call from wp-admin, or through a session minted without a label, records the person. */
	public function test_a_wp_admin_call_records_the_person(): void {
		$this->seed_curation_world();

		$this->ci->dispatch( 'accept_entity', [ self::LEDGER ] );
		Capabilities::$session_label = '';
		$this->ci->dispatch( 'reject_entity', [ self::LEDGER ] );

		$this->assertSame( [ 'user:kinsey-reed', 'user:kinsey-reed' ], \array_column( self::ontology_events(), 'actor' ) );
	}

	/** With no signed-in user there is no actor, so the command throws before it appends. */
	public function test_a_call_with_no_user_is_refused_before_it_writes(): void {
		$this->seed_curation_world();
		unset( $GLOBALS['_current_user_id'] );

		try {
			$this->ci->dispatch( 'accept_entity', [ self::LEDGER ] );
			$this->fail( 'a call with no user wrote' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'a user actor needs a login', $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * Each refusal, and what it names. None writes anything.
	 *
	 * @return array<string,array{0:string,1:list<string>,2:string}>
	 */
	public static function refusals(): array {
		$long = \str_repeat( 'n', 257 );
		return [
			'merge into itself'           => [ 'merge_entities', [ self::LEDGER, self::LEDGER ], 'cannot merge organization:salem-ledger into itself' ],
			'merge into its own merge'    => [ 'merge_entities', [ self::GAZETTE, 'organization:gazette-weekly' ], 'cannot merge organization:harbor-gazette into itself' ],
			'merge past the hop limit'    => [ 'merge_entities', [ 'organization:chain-10', self::LEDGER ], 'merging organization:chain-10 into organization:salem-ledger would leave organization:chain-0 on a chain that cycles or runs past 10 hops' ],
			'merge into no entity'        => [ 'merge_entities', [ self::LEDGER, 'organization:nowhere' ], 'no entity: organization:nowhere' ],
			'merge from no entity'        => [ 'merge_entities', [ 'organization:nowhere', self::LEDGER ], 'no entity: organization:nowhere' ],
			'merge into a hidden one'     => [ 'merge_entities', [ self::LEDGER, self::HIDDEN ], 'no entity: ' . self::HIDDEN ],
			'accept a hidden proposal'    => [ 'accept_entity', [ self::HIDDEN ], 'no entity: ' . self::HIDDEN ],
			'link a hidden proposal'      => [ 'confirm_link', [ 'feed:gazette-award', self::HIDDEN ], 'no entity: ' . self::HIDDEN ],
			'tag with a hidden topic'     => [ 'tag_topic', [ self::LEDGER, self::HIDDEN ], 'no entity: ' . self::HIDDEN ],
			'name a hidden proposal'      => [ 'add_name', [ self::HIDDEN, 'alias', 'Buyer' ], 'no entity: ' . self::HIDDEN ],
			'blank key'                   => [ 'accept_entity', [ ' ' ], 'key is blank' ],
			'blank name value'            => [ 'add_name', [ self::LEDGER, 'alias', ' ' ], 'value is blank' ],
			'blank topic'                 => [ 'tag_topic', [ self::LEDGER, ' ' ], 'topic is blank' ],
			'assert a merged key\'s role' => [ 'assert_role', [ 'organization:gazette-weekly', 'client' ], 'organization:gazette-weekly already holds client' ],
			'assert a role held merged'   => [ 'assert_role', [ self::GAZETTE, 'partner' ], 'organization:harbor-gazette already holds partner' ],
			'identifier held by another'  => [ 'add_identifier', [ self::LEDGER, 'domain', 'harborgazette.example' ], 'domain:harborgazette.example is held by organization:harbor-gazette' ],
			'domain an address'           => [ 'add_identifier', [ self::LEDGER, 'domain', '10.0.0.5' ], 'domain:10.0.0.5 is not a plain hostname: its top-level domain is not alphabetic' ],
			'domain a single label'       => [ 'add_identifier', [ self::LEDGER, 'domain', 'Localhost' ], 'domain:localhost is not a plain hostname: it has a single label' ],
			'domain with a port'          => [ 'add_identifier', [ self::LEDGER, 'domain', 'salemledger.example:8443' ], 'domain:salemledger.example:8443 is not a plain hostname: it holds a character other than a-z, 0-9, a hyphen or a dot' ],
			'identifier held by refused'  => [ 'add_identifier', [ self::LEDGER, 'x_handle', 'TidewaterNews' ], 'x_handle:tidewaternews is held by organization:tidewater-news' ],
			'entity taken'                => [ 'create_entity', [ 'organization', 'Salem Ledger' ], 'organization:salem-ledger is taken' ],
			'uncited acceptance taken'    => [ 'create_entity', [ 'organization', 'Quiet Partner' ], 'organization:quiet-partner is taken' ],
			'cited rejection taken'       => [ 'create_entity', [ 'organization', 'Open Buyer' ], 'organization:open-buyer is taken' ],
			'accept a hidden rejection'   => [ 'accept_entity', [ self::SHELVED ], 'no entity: ' . self::SHELVED ],
			'merged-away entity taken'    => [ 'create_entity', [ 'organization', 'Chain 0' ], 'organization:chain-0 is taken' ],
			'collection taken'            => [ 'create_collection', [ 'col october' ], 'collection col-october is taken' ],
			'collection name unsluggable' => [ 'create_collection', [ '!?!' ], 'collection name !?! sanitizes to nothing' ],
			'item URL past its cap'       => [ 'collect', [ 'col:october', 'feed:sprawling-url' ], 'feed:sprawling-url url runs past 1024 bytes' ],
			'name past its cap'           => [ 'add_name', [ self::LEDGER, 'alias', $long ], 'value runs past 256 bytes' ],
			'item key past its cap'       => [ 'confirm_link', [ \str_repeat( 'k', 513 ), self::LEDGER ], 'item runs past 512 bytes' ],
			'entity key past its cap'     => [ 'accept_entity', [ \str_repeat( 'k', 321 ) ], 'key runs past 320 bytes' ],
			'confirm an unlinked item'    => [ 'confirm_link', [ 'github:ledger-launch', self::GAZETTE ], 'no link: github:ledger-launch organization:harbor-gazette' ],
			'reject an unlinked item'     => [ 'reject_link', [ 'github:ledger-launch', self::GAZETTE ], 'no link: github:ledger-launch organization:harbor-gazette' ],
			'tag with a non-topic'        => [ 'tag_topic', [ self::LEDGER, self::GAZETTE ], 'organization:harbor-gazette is not a topic' ],
			'merge across types'          => [ 'merge_entities', [ self::LEDGER, 'topic:ai' ], 'cannot merge organization:salem-ledger (organization) into topic:ai (topic)' ],
			'no such item'                => [ 'confirm_link', [ 'feed:never-recorded', self::LEDGER ], 'no item: feed:never-recorded' ],
			'no such collection'          => [ 'collect', [ 'col:missing', 'feed:gazette-award' ], 'no collection: col:missing' ],
			'collect a missing item'      => [ 'collect', [ 'col:october', 'feed:never-recorded' ], 'no item: feed:never-recorded' ],
			'collect one saved there'     => [ 'collect', [ 'col-october', 'linear:joint-grant' ], 'linear:joint-grant is already in Col October' ],
			'mark one not saved there'    => [ 'mark_used', [ 'col:october', 'linear:joint-grant', 'newsletter', '2026-10-19' ], 'linear:joint-grant is not saved in October wins' ],
			'mark one never saved'        => [ 'mark_used', [ 'col:october', 'feed:gazette-award', 'site', '2026-10-19' ], 'feed:gazette-award is not saved in October wins' ],
			'mark outside its set'        => [ 'mark_used', [ 'col-october', 'linear:joint-grant', 'podcast', '2026-10-19' ], 'mark must be one of: newsletter, linkedin, site' ],
			'mark in no folder'           => [ 'mark_used', [ 'col:missing', 'linear:joint-grant', 'linkedin', '2026-10-19' ], 'no collection: col:missing' ],
			'mark on a day that is not'   => [ 'mark_used', [ 'col-october', 'linear:joint-grant', 'site', '2026-02-30' ], 'not a Y-m-d date: 2026-02-30' ],
			'mark on a day misspelled'    => [ 'mark_used', [ 'col-october', 'linear:joint-grant', 'site', '19/10/2026' ], 'not a Y-m-d date: 19/10/2026' ],
			'move from no folder'         => [ 'move_item', [ 'col:missing', 'linear:joint-grant', 'col:october' ], 'no collection: col:missing' ],
			'move into no folder'         => [ 'move_item', [ 'col-october', 'linear:joint-grant', 'col:missing' ], 'no collection: col:missing' ],
			'move one not saved there'    => [ 'move_item', [ 'col:october', 'linear:joint-grant', 'col-october' ], 'linear:joint-grant is not saved in October wins' ],
			'move into its own folder'    => [ 'move_item', [ 'col-october', 'linear:joint-grant', 'col-october' ], 'linear:joint-grant is already in Col October' ],
			'end a role not held'         => [ 'end_role', [ self::LEDGER, 'client' ], 'organization:salem-ledger holds no open client role' ],
			'assert a role already held'  => [ 'assert_role', [ self::GAZETTE, 'client' ], 'organization:harbor-gazette already holds client' ],
			'a name kind outside its set' => [ 'add_name', [ self::LEDGER, 'nickname', 'Led' ], 'name_added: field kind is outside its set' ],
			'merge a blank theme'         => [ 'merge_theme', [ ' ', self::OFFBOARDING ], 'from is blank' ],
			'merge into a blank theme'    => [ 'merge_theme', [ self::PLATFORM, "\t" ], 'into is blank' ],
			'merge a theme past its cap'  => [ 'merge_theme', [ \str_repeat( 't', 81 ), self::OFFBOARDING ], 'from runs past 80 bytes' ],
			'merge a theme into itself'   => [ 'merge_theme', [ self::OFFBOARDING, self::OFFBOARDING ], 'cannot merge theme client offboarding into itself' ],
			'merge into a merged theme'   => [ 'merge_theme', [ self::PLATFORM, self::AUTOMATION ], 'offboarding automation merges into client offboarding; merge client offboarding from platform into client offboarding instead' ],
			'merge a theme past the hops' => [ 'merge_theme', [ 'theme chain 10', self::OFFBOARDING ], 'merging theme chain 10 into client offboarding would leave theme chain 0 on a chain that cycles or runs past 10 hops' ],
			'merge into a hidden theme'   => [ 'merge_theme', [ self::PLATFORM, self::SETTLEMENT ], 'no theme: board settlement talks' ],
			'merge from a hidden theme'   => [ 'merge_theme', [ self::SETTLEMENT, self::OFFBOARDING ], 'no theme: board settlement talks' ],
			'merge into an unused theme'  => [ 'merge_theme', [ self::PLATFORM, 'client exit' ], 'no theme: client exit' ],
			'merge from an unused theme'  => [ 'merge_theme', [ 'client exit', self::OFFBOARDING ], 'no theme: client exit' ],
			'merge a hidden chain theme'  => [ 'merge_theme', [ 'theme chain 4', self::OFFBOARDING ], 'no theme: theme chain 4' ],
			'merge into a cycling theme'  => [ 'merge_theme', [ 'theme loop a', 'theme loop b' ], 'theme loop b is on a merge chain that cycles or runs past 10 hops; merge theme loop a into a theme whose chain resolves instead' ],
		];
	}

	/**
	 * A refusal throws naming its cause and writes nothing.
	 *
	 * @param string       $verb    The verb.
	 * @param list<string> $args    Its tokens.
	 * @param string       $message The refusal.
	 */
	#[DataProvider( 'refusals' )]
	public function test_a_refusal_names_its_cause_and_writes_nothing( string $verb, array $args, string $message ): void {
		$this->seed_curation_world();

		try {
			$this->ci->dispatch( $verb, $args );
			$this->fail( "{$verb} was not refused" );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( $message, $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * A theme merge that would close a cycle is refused before anything is
	 * written: `offboarding automation` merges into `client offboarding`, so
	 * merging `client offboarding` back into it would leave both on a chain
	 * with no survivor.
	 */
	public function test_a_theme_merge_that_cycles_is_refused(): void {
		$this->seed_curation_world();

		try {
			$this->ci->dispatch( 'merge_theme', [ self::OFFBOARDING, self::AUTOMATION ] );
			$this->fail( 'a cycling theme merge was written' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'merging client offboarding into offboarding automation would leave client offboarding on a chain that cycles or runs past 10 hops', $e->getMessage() );
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * A caller without restricted access reads one refusal for a theme only
	 * a restricted item carries and for one no item carries, so the merge
	 * cannot tell it whether a restricted item carries a theme.
	 */
	public function test_a_reader_cannot_tell_a_restricted_theme_from_none(): void {
		$this->seed_curation_world();
		$refusal = function (): string {
			try {
				$this->ci->dispatch( 'merge_theme', [ self::PLATFORM, 'harbor dredging dispute' ] );
				$this->fail( 'a merge into a theme the reader cannot see was written' );
			} catch ( \InvalidArgumentException $e ) {
				return $e->getMessage();
			}
		};
		$none = $refusal();

		$this->seed(
			self::item( 'unthread:dredge-5', 'Dredging dispute', 'unthread', 'restricted', self::$oct_13 ),
			self::extraction( 'unthread:dredge-5', 'Dredging.', 'other', self::$oct_13, [], 5.0, 5.0, 'harbor dredging dispute' )
		);

		$this->assertSame( 'no theme: harbor dredging dispute', $none );
		$this->assertSame( $none, $refusal() );
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * A holder of the restricted tier sees a theme only restricted items
	 * carry, and is refused merging an unrestricted theme into it, which
	 * would show it to every reader; and it cannot see a theme that is not
	 * the theme of any item.
	 */
	public function test_a_holder_cannot_show_a_restricted_theme_to_every_reader(): void {
		$this->seed_curation_world();
		self::sign_in( 38, 'quay-analyst', 'manage_options', Intel_Tiers::CAPABILITY );

		foreach ( [ [ self::SETTLEMENT, 'merging client offboarding from platform into board settlement talks would show board settlement talks to every reader; it is not the theme of any item outside the restricted tier' ], [ 'client exit', 'no theme: client exit' ] ] as [ $into, $message ] ) {
			try {
				$this->ci->dispatch( 'merge_theme', [ self::PLATFORM, $into ] );
				$this->fail( "a merge into {$into} was written" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( $message, $e->getMessage() );
			}
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * A chain past the hop limit is refused naming its first broken theme
	 * only to a caller that may read it: a reader without the restricted
	 * tier reads a refusal naming no theme, and a holder reads the theme.
	 */
	public function test_a_chain_refusal_names_only_a_theme_the_caller_reads(): void {
		$this->seed_curation_world();
		$chain = [];
		for ( $n = 0; $n < 10; $n++ ) {
			$chain[] = [ Intel_Event::make( 'theme_merged', [ 'from' => "silt chain {$n}", 'into' => 'silt chain ' . ( $n + 1 ) ], 'user:41', 'fixture-9' ) ];
		}
		$this->seed(
			self::item( 'unthread:silt-1', 'Silt', 'unthread', 'restricted', self::$oct_13 ),
			self::extraction( 'unthread:silt-1', 'Silt.', 'other', self::$oct_13, [], 5.0, 5.0, 'silt chain 0' ),
			self::item( 'feed:silt-2', 'Silt', 'feed', 'public', self::$oct_13 ),
			self::extraction( 'feed:silt-2', 'Silt.', 'other', self::$oct_13, [], 5.0, 5.0, 'silt chain 10' ),
			...$chain
		);
		$refusal = function (): string {
			try {
				$this->ci->dispatch( 'merge_theme', [ 'silt chain 10', self::OFFBOARDING ] );
				$this->fail( 'a merge past the hop limit was written' );
			} catch ( \InvalidArgumentException $e ) {
				return $e->getMessage();
			}
		};

		$this->assertSame( 'merging silt chain 10 into client offboarding would leave a theme on a chain that cycles or runs past 10 hops', $refusal() );
		self::sign_in( 38, 'quay-analyst', 'manage_options', Intel_Tiers::CAPABILITY );
		$this->assertSame( 'merging silt chain 10 into client offboarding would leave silt chain 0 on a chain that cycles or runs past 10 hops', $refusal() );
		$this->assertSame( [], self::ontology_events() );
	}

	/**
	 * A theme that is a whole number stays a string throughout: a reader
	 * merges one it can see, and its chain resolves as any theme's does.
	 */
	public function test_a_numeric_theme_merges_as_a_string(): void {
		$this->seed_curation_world();
		$this->seed(
			self::item( 'feed:year-1', 'Year', 'feed', 'public', self::$oct_13 ),
			self::extraction( 'feed:year-1', 'Year.', 'other', self::$oct_13, [], 5.0, 5.0, '2026' ),
			[ Intel_Event::make( 'theme_merged', [ 'from' => '404', 'into' => '2026' ], 'user:41', 'fixture-9' ) ]
		);

		$this->ci->dispatch( 'merge_theme', [ '2026', self::OFFBOARDING ] );

		$this->assertSame( [ [ '2026', self::OFFBOARDING ] ], \array_map( static fn ( array $e ): array => [ $e['from'], $e['into'] ], self::ontology_events() ) );
	}

	/** A holder merges a theme only restricted items carry into one every reader sees. */
	public function test_a_holder_merges_a_restricted_theme_into_an_open_one(): void {
		$this->seed_curation_world();
		self::sign_in( 38, 'quay-analyst', 'manage_options', Intel_Tiers::CAPABILITY );

		$this->ci->dispatch( 'merge_theme', [ self::SETTLEMENT, self::OFFBOARDING ] );

		$this->assertSame( [ [ self::SETTLEMENT, self::OFFBOARDING ] ], \array_map( static fn ( array $e ): array => [ $e['from'], $e['into'] ], self::ontology_events() ) );
	}

	/**
	 * Only each item's newest theme counts as shown: a theme an unrestricted
	 * item carried in an older partition alone, and a restricted item still
	 * carries, is refused to a holder as a merge target that would show it.
	 */
	public function test_a_theme_an_older_partition_alone_shows_counts_as_hidden(): void {
		$this->seed_curation_world();
		self::sign_in( 38, 'quay-analyst', 'manage_options', Intel_Tiers::CAPABILITY );
		$this->seed(
			self::item( 'feed:drift-1', 'Drift ledger', 'feed', 'public', self::$oct_13 ),
			self::extraction( 'feed:drift-1', 'Drift.', 'other', self::$oct_13, [], 5.0, 5.0, 'drift ledger gap' ),
			self::item( 'unthread:drift-2', 'Drift ledger', 'unthread', 'restricted', self::$oct_13 ),
			self::extraction( 'unthread:drift-2', 'Drift.', 'other', self::$oct_13, [], 5.0, 5.0, 'drift ledger gap' )
		);
		$live  = Table_Node::file( 'intel-graph', 0 );
		$older = $this->make_temp_dir( 'intel-older-' ) . '/intel-graph.p0.sqlite';
		( new \PDO( 'sqlite:' . $live ) )->exec( 'VACUUM INTO ' . ( new \PDO( 'sqlite::memory:' ) )->quote( $older ) );
		$this->seed( self::extraction( 'feed:drift-1', 'Drift.', 'other', self::$oct_13, [], 5.0, 5.0, 'drift census' ) );
		Intel_Graph_Row_Schema::$read_rows = static fn ( \Closure $read ): mixed => $read( Sqlite_Arm::open_rows( [ 0 => $older, 1 => $live ], Intel_Graph_Row_Schema::version() ) );

		$this->expectExceptionMessage( 'merging client offboarding from platform into drift ledger gap would show drift ledger gap to every reader; it is not the theme of any item outside the restricted tier' );
		$this->ci->dispatch( 'merge_theme', [ self::PLATFORM, 'drift ledger gap' ] );
	}

	/** A key merged away answers as its survivor, so accepting it accepts the survivor. */
	public function test_accepting_a_merged_away_key_accepts_its_survivor(): void {
		$this->seed_curation_world();
		$this->seed(
			self::proposal( 'organization:tidewater-weekly', 'Tidewater Weekly', 'github:ledger-launch' ),
			self::merged( 'organization:tidewater-weekly', 'organization:tidewater-times' )
		);

		$this->ci->dispatch( 'accept_entity', [ 'organization:tidewater-weekly' ] );

		$events = self::ontology_events();
		$this->assertSame( [ 'organization:tidewater-times' ], \array_column( $events, 'entity_key' ) );
		$this->seed( $events );
		$this->assertSame( 'accepted', $this->ci->dispatch( 'entity', [ 'organization:tidewater-times' ] )['state'] );
	}

	/** A merge from a key merged away merges the survivor it answers as. */
	public function test_merging_from_a_merged_away_key_merges_its_survivor(): void {
		$this->seed_curation_world();

		$this->ci->dispatch( 'merge_entities', [ 'organization:gazette-weekly', self::LEDGER ] );

		$this->assertSame( [ [ self::GAZETTE, self::LEDGER ] ], \array_map( static fn ( array $e ): array => [ $e['entity_key'], $e['into_key'] ], self::ontology_events() ) );
	}

	/**
	 * A role opened and closed within one second is not opened again in it,
	 * since a third row from that second would overwrite the closed one.
	 */
	public function test_a_role_closed_this_second_is_not_reopened_in_it(): void {
		$this->seed_curation_world();
		Core::$now = (float) self::$oct_20 + 0.375;

		$this->ci->dispatch( 'assert_role', [ self::LEDGER, 'partner' ] );
		$this->seed( self::ontology_events() );
		$this->ci->dispatch( 'end_role', [ self::LEDGER, 'partner' ] );
		$this->seed( \array_slice( self::ontology_events(), 1 ) );

		try {
			$this->ci->dispatch( 'assert_role', [ self::LEDGER, 'partner' ] );
			$this->fail( 'a role reopened in the second it closed' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( 'organization:salem-ledger already has a partner row from this second; try again in a second', $e->getMessage() );
		}
		$this->assertCount( 2, self::ontology_events() );
	}

	/** An identifier the entity, or a key merged into it, already holds is added again. */
	public function test_an_identifier_its_own_entity_holds_is_added_again(): void {
		$this->seed_curation_world();

		$this->ci->dispatch( 'add_identifier', [ self::GAZETTE, 'domain', 'HarborGazette.example' ] );
		$this->ci->dispatch( 'add_identifier', [ 'organization:gazette-daily', 'domain', 'harborgazette.example' ] );

		$this->assertSame( [ 'harborgazette.example', 'harborgazette.example' ], \array_column( self::ontology_events(), 'value' ) );
	}

	/** An item outside the readable tiers is refused exactly as a missing one, by every command that reads one. */
	public function test_an_unreadable_item_is_refused_as_missing(): void {
		$this->seed_curation_world();
		$this->assertNotContains( 'restricted', Intel_Tiers::readable() );

		foreach ( [ 'confirm_link' => [ 'hubspot:deal-9', self::GAZETTE ], 'reject_link' => [ 'hubspot:deal-9', self::GAZETTE ], 'collect' => [ 'col:october', 'hubspot:deal-9' ] ] as $verb => $args ) {
			try {
				$this->ci->dispatch( $verb, $args );
				$this->fail( "{$verb} reads a restricted item" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( 'no item: hubspot:deal-9', $e->getMessage() );
			}
		}
		$this->assertSame( [], self::ontology_events() );
	}

	/** A collected title is cut at 512 bytes as the stream writes them, a character never split. */
	public function test_a_collected_title_is_cut_at_512_bytes(): void {
		$this->seed_curation_world();
		$this->seed(
			self::item( 'feed:long-ascii', \str_repeat( 'w', 600 ), 'feed', 'public', self::$oct_06 ),
			self::item( 'feed:long-accented', \str_repeat( 'é', 300 ), 'feed', 'public', self::$oct_06 )
		);

		$this->ci->dispatch( 'collect', [ 'col:october', 'feed:long-ascii' ] );
		$this->ci->dispatch( 'collect', [ 'col:october', 'feed:long-accented' ] );

		$titles = \array_column( self::ontology_events(), 'title' );
		$this->assertSame( \str_repeat( 'w', 512 ), $titles[0] );
		$this->assertSame( \str_repeat( 'é', 85 ), $titles[1], 'each é is six bytes escaped' );
	}

	/** A collected item lists in its collection, at its tier, after its item row expires. */
	public function test_a_collected_item_outlives_its_item_row(): void {
		$this->seed_curation_world();
		$this->ci->dispatch( 'collect', [ 'col:october', 'feed:gazette-award' ] );
		$this->seed( self::ontology_events() );

		$db = new \PDO( 'sqlite:' . \Newspack_Nodes\Table_Node::file( 'intel-graph', 0 ), null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
		$db->exec( "DELETE FROM items WHERE item_key = 'feed:gazette-award'" );

		$listed = Graph_Query::collections( [ 'key' => 'col:october' ] );
		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $listed['items'], 'item_key' ) );
		$this->assertSame( [ 'col:october' => 1, 'col-october' => 1 ], \array_column( Graph_Query::collections( [] )['collections'], 'items', 'collection_key' ) );
	}

	/** Each use mark carries the day it was used, a mark made again replacing its day, and reads beside its item in its folder. */
	public function test_an_item_carries_its_use_marks(): void {
		$this->seed_curation_world();

		$this->ci->dispatch( 'mark_used', [ 'col-october', 'linear:joint-grant', 'site', '2026-10-17' ] );
		$this->ci->dispatch( 'mark_used', [ 'col-october', 'linear:joint-grant', 'newsletter', '2026-10-19' ] );
		$this->ci->dispatch( 'mark_used', [ 'col-october', 'linear:joint-grant', 'newsletter', '2026-10-21' ] );
		$this->seed( self::ontology_events() );

		$items = Graph_Query::collections( [ 'key' => 'col-october' ] )['items'];
		$this->assertSame( [ 'linear:joint-grant' ], \array_column( $items, 'item_key' ) );
		$this->assertSame( [ [ 'mark' => 'newsletter', 'day' => '2026-10-21' ], [ 'mark' => 'site', 'day' => '2026-10-17' ] ], $items[0]['marks'] );
	}

	/** A move rewrites the saved item's folder: it leaves the first, keeping its saved copy and its marks. */
	public function test_an_item_moves_between_folders(): void {
		$this->seed_curation_world();
		$this->ci->dispatch( 'mark_used', [ 'col-october', 'linear:joint-grant', 'linkedin', '2026-10-18' ] );
		$this->seed( self::ontology_events() );
		Core::$now = (float) self::$oct_20 + 900;

		$this->ci->dispatch( 'move_item', [ 'col-october', 'linear:joint-grant', 'col:october' ] );
		$this->seed( \array_slice( self::ontology_events(), 1 ) );

		$this->assertSame( [], Graph_Query::collections( [ 'key' => 'col-october' ] )['items'] );
		$moved = Graph_Query::collections( [ 'key' => 'col:october' ] )['items'];
		$this->assertSame(
			[ [ 'linear:joint-grant', self::SAVED_TITLE, 'user:' . self::LOGIN, self::$oct_20 + 900, [ [ 'mark' => 'linkedin', 'day' => '2026-10-18' ] ] ] ],
			\array_map( static fn ( array $i ): array => [ $i['item_key'], $i['title'], $i['actor'], $i['saved_ts'], $i['marks'] ], $moved )
		);
		$this->assertSame( [ [ 'col:october', 'linear:joint-grant', 'internal' ] ], self::graph_rows( 'collected', 'item_key', 'collection_key, item_key, tier' ) );
		$this->assertSame( [ 'col:october' => 1, 'col-october' => 0 ], \array_column( Graph_Query::collections( [] )['collections'], 'items', 'collection_key' ) );
	}

	/** A folder lists the items not yet used first, then each run by save time, the latest first. */
	public function test_a_folder_lists_unused_items_first_then_by_save_time(): void {
		$this->seed_curation_world();
		$this->seed(
			[
				self::saved_at( 'col-october', 'feed:gazette-award', 'user:41', self::$oct_20 - 3600 ),
				self::saved_at( 'col-october', 'github:ledger-launch', 'user:41', self::$oct_20 - 7200 ),
				self::saved_at( 'col-october', 'feed:off-2', 'user:41', self::$oct_20 - 5400 ),
			]
		);
		$this->ci->dispatch( 'mark_used', [ 'col-october', 'linear:joint-grant', 'site', '2026-10-20' ] );
		$this->ci->dispatch( 'mark_used', [ 'col-october', 'github:ledger-launch', 'newsletter', '2026-10-14' ] );
		$this->seed( self::ontology_events() );

		$items = Graph_Query::collections( [ 'key' => 'col-october' ] )['items'];
		$this->assertSame(
			[ [ 'feed:gazette-award', self::$oct_20 - 3600 ], [ 'feed:off-2', self::$oct_20 - 5400 ], [ 'linear:joint-grant', self::$oct_20 ], [ 'github:ledger-launch', self::$oct_20 - 7200 ] ],
			\array_map( static fn ( array $i ): array => [ $i['item_key'], $i['saved_ts'] ], $items )
		);
		$this->assertSame( [ [], [] ], \array_column( \array_slice( $items, 0, 2 ), 'marks' ) );
	}

	/**
	 * A move into a folder already holding a copy at every tier it carries
	 * only empties the folder it leaves; the target's placement stays as it
	 * was.
	 */
	public function test_a_move_into_a_folder_holding_the_item_only_empties_the_one_it_leaves(): void {
		$this->seed_curation_world();
		$this->seed( [ self::placed_at( 'col:october', 'linear:joint-grant', 'internal', 'user:dock-editor', self::$oct_13 ) ] );

		$this->ci->dispatch( 'move_item', [ 'col-october', 'linear:joint-grant', 'col:october' ] );
		$this->seed( self::ontology_events() );

		$this->assertSame( [ [ 'internal' ] ], \array_column( self::ontology_events(), 'tiers' ) );
		$this->assertSame( [ [ 'col:october', 'internal', 'user:dock-editor', (float) self::$oct_13 ] ], \array_map( static fn ( array $row ): array => [ $row[0], $row[1], $row[2], (float) $row[3] ], self::graph_rows( 'collected', 'collection_key', 'collection_key, tier, actor, ts' ) ) );
		$this->assertSame( [], Graph_Query::collections( [ 'key' => 'col-october' ] )['items'] );
	}

	/**
	 * The recent folders are the caller's five latest saves or moves into a
	 * folder; every other folder follows by name, its recency left out.
	 */
	public function test_recent_folders_are_the_callers_five_latest(): void {
		$this->seed_curation_world();
		$shelves = [];
		foreach ( [ 'a', 'b', 'c', 'd', 'e', 'f', 'g' ] as $tag ) {
			$shelves[] = Intel_Event::make( 'collection_created', [ 'collection_key' => "shelf-{$tag}", 'name' => 'Shelf ' . \strtoupper( $tag ) ], 'user:41', 'fixture-9' );
		}
		$ago   = [ 'f' => 60, 'c' => 120, 'e' => 180, 'a' => 240, 'd' => 300, 'b' => 360 ];
		$saves = \array_map( static fn ( string $tag, int $seconds ): array => self::saved_at( "shelf-{$tag}", 'feed:off-2', 'user:' . self::LOGIN, self::$oct_20 - $seconds ), \array_keys( $ago ), $ago );
		$this->seed( $shelves, $saves );

		$listed = Graph_Query::collections( [] )['collections'];
		$this->assertSame( 5, Graph_Query::RECENT_FOLDERS );
		$this->assertSame(
			[
				[ 'shelf-f', self::$oct_20 - 60 ],
				[ 'shelf-c', self::$oct_20 - 120 ],
				[ 'shelf-e', self::$oct_20 - 180 ],
				[ 'shelf-a', self::$oct_20 - 240 ],
				[ 'shelf-d', self::$oct_20 - 300 ],
				[ 'col-october', null ],
				[ 'col:october', null ],
				[ 'shelf-b', null ],
				[ 'shelf-g', null ],
			],
			\array_map( static fn ( array $c ): array => [ $c['collection_key'], $c['recent_ts'] ], $listed )
		);
	}

	/**
	 * Folders list by the caller's own latest save or move into each, an
	 * agent's under the caller's login counting as the caller's, then by name.
	 */
	public function test_folders_list_the_callers_recent_first_then_by_name(): void {
		$this->seed_curation_world();
		$moved = Intel_Event::make( 'item_moved', [ 'collection_key' => 'zeta-picks', 'item_key' => 'feed:off-2', 'from' => 'archive', 'tiers' => [ 'public' ] ], 'agent:' . self::LOGIN . ':Desk Claude', 'fixture-9' );
		$moved['ts'] = (float) self::$oct_20 - 60;
		$this->seed(
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'archive', 'name' => 'Archive' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'zeta-picks', 'name' => 'Zeta picks' ], 'user:41', 'fixture-9' ),
				self::saved_at( 'archive', 'feed:off-2', 'user:' . self::LOGIN, self::$oct_20 - 600 ),
				$moved,
				self::saved_at( 'col:october', 'github:ledger-launch', 'user:' . self::LOGIN . 'y', self::$oct_20 + 60 ),
			]
		);

		$listed = Graph_Query::collections( [] )['collections'];
		$this->assertSame(
			[ [ 'zeta-picks', self::$oct_20 - 60 ], [ 'archive', self::$oct_20 - 600 ], [ 'col-october', null ], [ 'col:october', null ] ],
			\array_map( static fn ( array $c ): array => [ $c['collection_key'], $c['recent_ts'] ], $listed )
		);
	}

	/** fetch_now TICKs every source in each live worker of Intelligence's topology, whatever its name. */
	public function test_fetch_now_ticks_every_source(): void {
		$this->run_intelligence_as( 'kittiwake-intel', 'include newspack-intelligence-collect' );
		$base = Config::get_base_directory();
		self::live_worker( $base, 'kittiwake-intel.p6' );
		self::live_worker( $base, 'newspack-intelligence.p6' );
		$router = Core::node( Node_Names::ROUTER );
		$this->assertNotNull( $router );
		$this->ci->sink( $router );

		$answer = $this->ci->dispatch( 'fetch_now', [] );

		$this->assertSame( [ 'ok' => true, 'workers' => 1 ], $answer );
		$written = self::worker_input( $base, 'kittiwake-intel.p6' );
		$this->assertSame( [ 'github', 'feed', 'news', 'unthread', 'linear', 'slack', 'p2', 'p2-restricted' ], \array_column( $written, Message::TO ) );
		$this->assertSame( \array_fill( 0, 8, 'TICK' ), \array_column( $written, Message::VALUE ) );
		$this->assertSame( [], self::worker_input( $base, 'newspack-intelligence.p6' ), 'a topology outside the active set' );
		$this->assertSame( [], self::ontology_events() );
	}

	/** The sources fetch_now TICKs are the Source nodes Intelligence's topology declares, read from it. */
	public function test_fetch_now_ticks_the_sources_the_topology_declares(): void {
		$this->run_intelligence_as( 'tern-intel', 'make_node Linear_Source kestrel-linear internal', 'make_node Github_Source heron-github public' );

		$this->assertSame( [ [ 'kestrel-linear', 'TICK' ], [ 'heron-github', 'TICK' ] ], Intelligence_CI_Node::ticks() );
	}

	/**
	 * While the active topologies declare no event writer, fetch_now is refused
	 * naming why.
	 */
	public function test_fetch_now_with_no_intelligence_topology_is_refused(): void {
		self::live_worker( Config::get_base_directory(), 'newspack-intelligence.p3' );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No active topology declares an Intel_Event_Writer node' );
		$this->ci->dispatch( 'fetch_now', [] );
	}

	/** A caller below tune is refused every curation command; one below manage is refused fetch_now. */
	public function test_a_caller_below_the_declared_role_is_refused(): void {
		unset( $GLOBALS['_current_user_id'] );
		Capabilities::$session_scope = Capabilities::READ;
		foreach ( self::CURATION as $verb ) {
			try {
				$this->ci->dispatch( $verb, [] );
				$this->fail( "{$verb} answers a read caller" );
			} catch ( \RuntimeException $e ) {
				$this->assertSame( 'permission denied: tune capability required', $e->getMessage() );
			}
		}
		Capabilities::$session_scope = Capabilities::TUNE;
		$this->expectExceptionMessage( 'permission denied: manage capability required' );
		$this->ci->dispatch( 'fetch_now', [] );
	}

	/**
	 * Every curation event, each string arg at its cap in the characters
	 * that escape longest, a 60-character login and the longest label a
	 * session stores, packs into one partition line.
	 */
	public function test_every_curation_event_fits_one_atomic_write(): void {
		$wide   = static fn ( int $bytes ): string => \str_repeat( "\u{1F600}", \intdiv( $bytes, 12 ) ) . \str_repeat( 'q', $bytes % 12 );
		$key    = static fn ( string $tag ): string => "organization:{$tag}" . $wide( Graph_Curation::KEY_BYTES - \strlen( "organization:{$tag}" ) );
		$item   = 'feed:' . $wide( Graph_Curation::ITEM_KEY_BYTES - 5 );
		$saved  = 'linear:' . $wide( Graph_Curation::ITEM_KEY_BYTES - 7 );
		$name   = $wide( Graph_Curation::NAME_BYTES );
		$ascii  = \str_repeat( 'm', Graph_Curation::NAME_BYTES );
		$entity = $key( 'a' );
		$topic  = 'topic:t' . $wide( Graph_Curation::KEY_BYTES - 7 );
		$GLOBALS['_wp_test_user_logins'][37] = \str_repeat( 'l', 60 );
		Capabilities::$session_label         = Sessions::clean_label( \str_repeat( "\u{1F600}", 70 ) );
		$this->seed(
			[ Intel_Event::make( 'item_recorded', [ 'item_key' => $item, 'source' => 'feed', 'tier' => 'public', 'url' => 'https://news.example/' . $wide( Graph_Curation::URL_BYTES - 21 ), 'title' => \str_repeat( "\u{1F600}", 600 ), 'body' => '', 'item_ts' => self::$oct_06, 'retrieved_ts' => self::$oct_06 ], self::$machine, 'fixture-9' ) ],
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => $key( 'c' ), 'name' => 'Wide' ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => $key( 'e' ), 'name' => 'Wider' ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'collected', [ 'collection_key' => $key( 'c' ), 'item_key' => $saved, 'url' => 'https://news.example/' . $wide( Graph_Curation::URL_BYTES - 21 ), 'title' => Graph_Curation::cut( \str_repeat( "\u{1F600}", 600 ), Graph_Curation::TITLE_BYTES ), 'tier' => 'public' ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => $entity, 'role' => 'newspack_product', 'from_ts' => self::$jan_01 ], 'user:41', 'fixture-9' ) ],
			self::link( 'link_asserted', $item, $entity, 'subject', 'name', 1.0, self::$oct_06 ),
			self::entity( $topic, 'Wide' ),
			self::item( 'feed:wide-from', 'Wide from', 'feed', 'public', self::$oct_06 ),
			self::extraction( 'feed:wide-from', 'Wide.', 'other', self::$oct_06, [], 5.0, 5.0, $wide( Intel_Event::THEME_BYTES ) ),
			self::item( 'feed:wide-into', 'Wide into', 'feed', 'public', self::$oct_06 ),
			self::extraction( 'feed:wide-into', 'Wide.', 'other', self::$oct_06, [], 5.0, 5.0, $wide( Intel_Event::THEME_BYTES - 1 ) . 'r' ),
			...\array_map( static fn ( string $tag ): array => self::entity( $key( $tag ), 'Wide' ), [ 'a', 'b', 'd' ] )
		);
		$calls = [
			[ 'confirm_link', [ $item, $entity ] ],
			[ 'reject_link', [ $item, $entity ] ],
			[ 'accept_entity', [ $entity ] ],
			[ 'reject_entity', [ $entity ] ],
			[ 'merge_entities', [ $key( 'b' ), $entity ] ],
			[ 'add_name', [ $entity, 'canonical', $name ] ],
			[ 'add_identifier', [ $entity, 'linkedin_company', $name ] ],
			[ 'end_role', [ $entity, 'newspack_product' ] ],
			[ 'assert_role', [ $key( 'd' ), 'newspack_product' ] ],
			[ 'tag_topic', [ $entity, $topic ] ],
			[ 'create_entity', [ 'organization', $ascii ] ],
			[ 'create_collection', [ $ascii ] ],
			[ 'collect', [ $key( 'c' ), $item ] ],
			[ 'mark_used', [ $key( 'c' ), $saved, 'newsletter', '2026-10-19' ] ],
			[ 'move_item', [ $key( 'c' ), $saved, $key( 'e' ) ] ],
			[ 'merge_theme', [ $wide( Intel_Event::THEME_BYTES ), $wide( Intel_Event::THEME_BYTES - 1 ) . 'r' ] ],
		];
		foreach ( $calls as [ $verb, $args ] ) {
			$this->ci->dispatch( $verb, $args );
		}

		$events = self::ontology_events();
		$this->assertSame( \array_column( $calls, 0 ), \array_map( static fn ( array $e ): string => self::VERB_OF[ $e['event'] ], $events ) );
		foreach ( $events as $event ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::VALUE ] = $event;
			$this->assertLessThanOrEqual( Partition_Node::MAX_LINE_SIZE, Message::packed_size( $message ) + 1, $event['event'] );
		}
	}

	/**
	 * An item a person saved into a folder at a tier, at a moment.
	 *
	 * @param string $collection The folder's key.
	 * @param string $item       The item key.
	 * @param string $tier       The copy's tier.
	 * @param string $actor      Who saved it.
	 * @param int    $at         When.
	 * @return array<string,mixed>
	 */
	private static function placed_at( string $collection, string $item, string $tier, string $actor, int $at ): array {
		$event       = Intel_Event::make( 'collected', [ 'collection_key' => $collection, 'item_key' => $item, 'url' => self::url( $item ), 'title' => "Shelved {$item}", 'tier' => $tier ], $actor, 'fixture-9' );
		$event['ts'] = (float) $at;
		return $event;
	}

	/**
	 * An item a person saved into a folder at a moment.
	 *
	 * @param string $collection The folder's key.
	 * @param string $item       The item key.
	 * @param string $actor      Who saved it.
	 * @param int    $at         When.
	 * @return array<string,mixed>
	 */
	private static function saved_at( string $collection, string $item, string $actor, int $at ): array {
		$event       = Intel_Event::make( 'collected', [ 'collection_key' => $collection, 'item_key' => $item, 'url' => self::url( $item ), 'title' => "Saved {$item}", 'tier' => 'public' ], $actor, 'fixture-9' );
		$event['ts'] = (float) $at;
		return $event;
	}

	/**
	 * The curation world: the read fixture, two collections, the grant saved
	 * into one of them under a title of its own, a merge chain
	 * into the Gazette holding a partner role, a ten-hop chain ending at an
	 * entity, a proposal a public item cites and one only a restricted item
	 * cites, a rejection of each kind, an accepted entity that is not cited by
	 * any item, a rejected entity holding an X handle, and an item whose URL
	 * runs one byte past its cap, and a lens.
	 */
	private function seed_curation_world(): void {
		$this->seed_world();
		$chain = [];
		for ( $n = 0; $n < 10; $n++ ) {
			$chain[] = self::merged( "organization:chain-{$n}", 'organization:chain-' . ( $n + 1 ) );
		}
		$this->seed(
			self::entity( 'organization:gazette-daily', 'Gazette Daily' ),
			self::entity( 'organization:gazette-weekly', 'Gazette Weekly' ),
			self::merged( 'organization:gazette-weekly', 'organization:gazette-daily' ),
			self::merged( 'organization:gazette-daily', self::GAZETTE ),
			self::entity( 'organization:chain-10', 'Chain 10' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:gazette-daily', 'role' => 'partner', 'from_ts' => self::$oct_01 ], 'user:41', 'fixture-9' ) ],
			self::proposal( 'organization:tidewater-times', 'Tidewater Times', 'feed:gazette-award' ),
			self::proposal( self::HIDDEN, 'Hidden Buyer', 'hubspot:deal-9' ),
			self::proposal( self::SHELVED, 'Shelved Buyer', 'hubspot:deal-9' ),
			self::rejected( self::SHELVED ),
			self::proposal( 'organization:open-buyer', 'Open Buyer', 'feed:gazette-award' ),
			self::rejected( 'organization:open-buyer' ),
			self::entity( 'organization:quiet-partner', 'Quiet Partner' ),
			self::entity( 'organization:tidewater-news', 'Tidewater News', [ 'x_handle' => [ 'tidewaternews' ] ] ),
			self::rejected( 'organization:tidewater-news' ),
			[ Intel_Event::make( 'item_recorded', [ 'item_key' => 'feed:sprawling-url', 'source' => 'feed', 'tier' => 'public', 'url' => 'https://news.example/' . \str_repeat( 'u', 1004 ), 'title' => 'Sprawl', 'body' => '', 'item_ts' => self::$oct_06, 'retrieved_ts' => self::$oct_06 ], self::$machine, 'fixture-9' ) ],
			[
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:october', 'name' => 'October wins' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collection_created', [ 'collection_key' => 'col-october', 'name' => 'Col October' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'collected', [ 'collection_key' => 'col-october', 'item_key' => 'linear:joint-grant', 'url' => self::url( 'linear:joint-grant' ), 'title' => self::SAVED_TITLE, 'tier' => 'internal' ], 'user:41', 'fixture-9' ),
				Intel_Event::make( 'lens_saved', [ 'lens_key' => 'harbor-desk', 'name' => 'Harbor desk', 'description' => '', 'filters' => Graph_Query::unfiltered() ], 'user:41', 'fixture-9' ),
			],
			...$chain
		);
		self::write_restricted_item( 'hubspot:deal-9', 'Harbor Gazette deal at risk' );
		$this->seed_theme_world();
	}

	/**
	 * Three offboarding themes on items every reader reads, one merged into
	 * another; a theme only a restricted item names; a ten-hop chain of
	 * theme merges whose first theme an open item carries and whose fifth a
	 * restricted item alone carries; and two themes merged into each other,
	 * each carried by an open item.
	 */
	private function seed_theme_world(): void {
		$themed = static fn ( string $key, string $tier, string $theme ): array => [
			...self::item( $key, "Ticket {$key}", \explode( ':', $key )[0], $tier, self::$oct_13 ),
			...self::extraction( $key, 'Offboarding.', 'churned', self::$oct_13, [], 5.0, 5.0, $theme ),
		];
		$chain  = [];
		for ( $n = 0; $n < 10; $n++ ) {
			$chain[] = [ Intel_Event::make( 'theme_merged', [ 'from' => "theme chain {$n}", 'into' => 'theme chain ' . ( $n + 1 ) ], 'user:41', 'fixture-9' ) ];
		}
		$this->seed(
			$themed( 'linear:OFF-1', 'internal', self::OFFBOARDING ),
			$themed( 'feed:off-2', 'public', self::PLATFORM ),
			$themed( 'linear:OFF-3', 'internal', self::AUTOMATION ),
			$themed( 'unthread:board-4', 'restricted', self::SETTLEMENT ),
			$themed( 'feed:chain-5', 'public', 'theme chain 0' ),
			$themed( 'unthread:chain-6', 'restricted', 'theme chain 4' ),
			$themed( 'feed:loop-7', 'public', 'theme loop a' ),
			$themed( 'feed:loop-8', 'public', 'theme loop b' ),
			[ Intel_Event::make( 'theme_merged', [ 'from' => self::AUTOMATION, 'into' => self::OFFBOARDING ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'theme_merged', [ 'from' => 'theme loop a', 'into' => 'theme loop b' ], 'user:41', 'fixture-9' ) ],
			[ Intel_Event::make( 'theme_merged', [ 'from' => 'theme loop b', 'into' => 'theme loop a' ], 'user:41', 'fixture-9' ) ],
			...$chain
		);
	}
}

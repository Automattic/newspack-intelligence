<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Intel_Tiers;
use Newspack_Intelligence\Intelligence_CI_Node;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-intel-graph.php';
require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/signs-in-users.php';

/**
 * The restricted tier: a user holding `newspack_intelligence_restricted`
 * reads restricted items, their links, claims and counts, through every
 * read and curation verb, checked on each call; a user without it reads
 * public and internal items alone. An operator grants and revokes it per
 * user, each change logged naming the operator and the user.
 */
final class RestrictedTierTest extends TestCase {
	use Seeds_Intel_Graph;
	use Reads_Ontology_Log;
	use Signs_In_Users;

	private const GAZETTE = 'organization:harbor-gazette';

	private const DEAL = 'hubspot:renewal-4417';

	private const HOLDER = 6143;

	private const READER = 6144;

	/** A proposal only the restricted deal cites. */
	private const QUIET = 'organization:quiet-buyer';

	/** A topic only the restricted deal cites. */
	private const CHURN = 'topic:quiet-churn';

	private Intelligence_CI_Node $ci;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_intel_graph();
		self::add_user( self::HOLDER, 'harbor-analyst', 'manage_options', 'newspack_intelligence_restricted' );
		self::add_user( self::READER, 'harbor-reader', 'manage_options' );
		$this->ci = new Intelligence_CI_Node();
		$this->ci->name( 'intelligence' );
	}

	protected function tearDown(): void {
		self::forget_users();
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	/** A holder reads all three tiers; any other caller public and internal. */
	public function test_readable_adds_restricted_for_a_holder_alone(): void {
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ 'public', 'internal', 'restricted' ], Intel_Tiers::readable() );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'public', 'internal' ], Intel_Tiers::readable() );
	}

	/** The tier is asked on each call, so a grant reaches the very next read. */
	public function test_readable_is_asked_on_each_call(): void {
		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'public', 'internal' ], Intel_Tiers::readable() );

		Intel_Tiers::grant( 'harbor-reader', 'user:ops-lead' );

		$this->assertSame( [ 'public', 'internal', 'restricted' ], Intel_Tiers::readable() );
	}

	/** A holder's search finds the restricted item, by its body too; a reader's does not. */
	public function test_search_answers_a_restricted_item_to_a_holder_alone(): void {
		$this->seed_restricted_world();

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$all                         = $this->ci->dispatch( 'search', [] );
		$this->assertSame( [ self::DEAL, 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $all['items'], 'item_key' ) );
		$this->assertSame( 4, $all['total'] );
		$this->assertSame( [ self::DEAL ], \array_column( $this->ci->dispatch( 'search', [ '--q=renewal clause' ] )['items'], 'item_key' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$all                         = $this->ci->dispatch( 'search', [] );
		$this->assertSame( [ 'github:ledger-launch', 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $all['items'], 'item_key' ) );
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( [], $this->ci->dispatch( 'search', [ '--q=renewal clause' ] )['items'] );
	}

	/** An entity's counts and timeline include the restricted item for a holder alone. */
	public function test_entity_and_timeline_count_a_restricted_item_for_a_holder_alone(): void {
		$this->seed_restricted_world();

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ 'items' => 3, 'proposed_links' => 0 ], $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['counts'] );
		$this->assertSame( [ self::DEAL, 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'], 'item_key' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'items' => 2, 'proposed_links' => 0 ], $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['counts'] );
		$this->assertSame( [ 'feed:gazette-award', 'linear:joint-grant' ], \array_column( $this->ci->dispatch( 'timeline', [ self::GAZETTE ] )['items'], 'item_key' ) );
	}

	/** A claim a restricted item makes, and the trend it counts in, answer a holder alone. */
	public function test_claims_and_trends_rest_on_a_restricted_item_for_a_holder_alone(): void {
		$this->seed_restricted_world();

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ self::DEAL ], \array_column( $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'], 'item_key' ) );
		$this->assertContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind' ] )['rows'], 'value' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );
		$this->assertNotContains( 'churn_signal', \array_column( $this->ci->dispatch( 'trends', [ 'kind' ] )['rows'], 'value' ) );
	}

	/** A curation verb naming a restricted item refuses a reader as a missing item, and acts for a holder. */
	public function test_curation_of_a_restricted_item_answers_a_holder_alone(): void {
		$this->seed_restricted_world();

		$GLOBALS['_current_user_id'] = self::READER;
		foreach ( [ 'confirm_link' => [ self::DEAL, self::GAZETTE ], 'collect' => [ 'col:renewals', self::DEAL ] ] as $verb => $args ) {
			$this->assertSame( 'no item: ' . self::DEAL, self::refusal( fn () => $this->ci->dispatch( $verb, $args ) ), $verb );
		}
		$this->assertSame( [], self::ontology_events() );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->ci->dispatch( 'confirm_link', [ self::DEAL, self::GAZETTE ] );

		$this->assertSame( [ [ 'link_confirmed', self::DEAL, 'user:harbor-analyst' ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['item_key'], $e['actor'] ], self::ontology_events() ) );
	}

	/**
	 * Accepting a proposal only restricted items cite would show it to every
	 * reader, so a holder's accept is refused, naming why, and writes nothing.
	 */
	public function test_a_holder_cannot_accept_an_entity_only_restricted_items_cite(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->assertSame(
			self::QUIET . ' is cited by no item outside the restricted tier; accepting it would show it to every reader',
			self::refusal( fn () => $this->ci->dispatch( 'accept_entity', [ self::QUIET ] ) )
		);
		$this->assertSame( [], self::ontology_events() );
	}

	/** A proposal an internal item also cites is accepted, as is one any reader already sees. */
	public function test_a_holder_accepts_an_entity_an_unrestricted_item_cites(): void {
		$this->seed_restricted_world();
		$this->seed( self::link( 'link_proposed', 'linear:joint-grant', 'organization:tidal-buyer', 'mention', 'ner_fuzzy', 0.6, self::$oct_01 ) );
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->ci->dispatch( 'accept_entity', [ 'organization:tidal-buyer' ] );

		$this->assertSame( [ [ 'entity_accepted', 'organization:tidal-buyer' ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'] ], self::ontology_events() ) );
	}

	/**
	 * A merge joining an entity only restricted items cite with one every
	 * reader sees is refused either way: merged away, its names would show on
	 * the survivor; surviving, the other's citations would show it.
	 */
	public function test_a_merge_across_the_restricted_line_is_refused_either_way(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->assertSame(
			'merging ' . self::QUIET . ' into ' . self::GAZETTE . ' would show ' . self::QUIET . ' to every reader; no item outside the restricted tier cites it',
			self::refusal( fn () => $this->ci->dispatch( 'merge_entities', [ self::QUIET, self::GAZETTE ] ) )
		);
		$this->assertSame(
			'merging organization:salem-ledger into ' . self::QUIET . ' would show ' . self::QUIET . ' to every reader; no item outside the restricted tier cites it',
			self::refusal( fn () => $this->ci->dispatch( 'merge_entities', [ 'organization:salem-ledger', self::QUIET ] ) )
		);
		$this->assertSame( [], self::ontology_events() );
	}

	/** Two entities only restricted items cite merge, since neither shows to any other reader. */
	public function test_two_restricted_only_entities_merge(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->ci->dispatch( 'merge_entities', [ 'organization:quiet-buyer-llc', self::QUIET ] );

		$this->assertSame( [ [ 'entity_merged', 'organization:quiet-buyer-llc', self::QUIET ] ], \array_map( static fn ( array $e ): array => [ $e['event'], $e['entity_key'], $e['into_key'] ], self::ontology_events() ) );
	}

	/** A holder still rejects such a proposal, and creates a public entity over its key on purpose. */
	public function test_a_holder_rejects_or_creates_over_a_restricted_only_proposal(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->ci->dispatch( 'reject_entity', [ self::QUIET ] );
		$created = $this->ci->dispatch( 'create_entity', [ 'organization', 'Quiet Buyer' ] );

		$this->assertSame( self::QUIET, $created['entity_key'] );
		$this->assertSame( [ 'entity_rejected', 'entity_created' ], \array_column( self::ontology_events(), 'event' ) );
	}

	/** A topic only restricted items cite shows on a public entity's record to a holder alone. */
	public function test_an_entity_record_shows_a_restricted_only_topic_to_a_holder_alone(): void {
		$this->seed_restricted_world();
		$this->seed( [ Intel_Event::make( 'topic_tagged', [ 'entity_key' => self::GAZETTE, 'topic_key' => self::CHURN ], 'user:41', 'fixture-9' ) ] );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ 'topic:awards', self::CHURN ], \array_column( $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['topics'], 'topic_key' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'topic:awards' ], \array_column( $this->ci->dispatch( 'entity', [ self::GAZETTE ] )['topics'], 'topic_key' ) );
	}

	/**
	 * Tagging an entity every reader sees with a topic only restricted items
	 * cite would show the topic on its record, so it is refused, naming why.
	 */
	public function test_a_holder_cannot_tag_a_public_entity_with_a_restricted_only_topic(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->assertSame(
			'tagging ' . self::GAZETTE . ' with ' . self::CHURN . ' would show ' . self::CHURN . ' to every reader; no item outside the restricted tier cites it',
			self::refusal( fn () => $this->ci->dispatch( 'tag_topic', [ self::GAZETTE, self::CHURN ] ) )
		);
		$this->assertSame( [], self::ontology_events() );
	}

	/** A public topic tags a public entity, and a restricted-only one tags an entity only restricted items cite. */
	public function test_a_tag_that_shows_nothing_new_is_written(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;

		$this->ci->dispatch( 'tag_topic', [ self::GAZETTE, 'topic:ai' ] );
		$this->ci->dispatch( 'tag_topic', [ self::QUIET, self::CHURN ] );

		$this->assertSame( [ [ self::GAZETTE, 'topic:ai' ], [ self::QUIET, self::CHURN ] ], \array_map( static fn ( array $e ): array => [ $e['entity_key'], $e['topic_key'] ], self::ontology_events() ) );
	}

	/**
	 * A public item's claim whose object only restricted items cite answers
	 * a non-holder the object as the item spelled it, and no key; a holder
	 * the key. A public object answers its key to both.
	 */
	public function test_a_claims_object_answers_its_key_only_to_a_caller_who_sees_it(): void {
		$this->seed_restricted_world();
		$this->seed(
			self::claim( 'feed:gazette-award', 1, self::GAZETTE, 'partners_with', self::QUIET, 'the Quiet Buyer consortium', self::$oct_06 ),
			self::claim( 'feed:gazette-award', 2, self::GAZETTE, 'competes_with', 'organization:salem-ledger', 'the Ledger', self::$oct_06 )
		);
		$objects = fn (): array => \array_column( \array_filter( $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'], static fn ( array $c ): bool => 'feed:gazette-award' === $c['item_key'] ), 'object' );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertSame( [ self::QUIET, 'organization:salem-ledger' ], $objects() );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'the Quiet Buyer consortium', 'organization:salem-ledger' ], $objects() );
	}

	/**
	 * A claim whose object carries a key but no spelling, as claims written
	 * before the extractor kept one do, is left out for a caller who cannot
	 * see the object, since its key is all it could answer.
	 */
	public function test_a_claim_with_no_spelling_for_an_object_the_caller_cannot_see_is_left_out(): void {
		$this->seed_restricted_world();
		$this->seed( self::claim( 'feed:gazette-award', 1, self::GAZETTE, 'partners_with', self::QUIET, '', self::$oct_06 ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [], $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'] );

		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->assertContains( self::QUIET, \array_column( $this->ci->dispatch( 'claims', [ self::GAZETTE ] )['claims'], 'object' ) );
	}

	/** A saved restricted item lists, and counts, in its collection for a holder alone. */
	public function test_collections_list_a_saved_restricted_item_to_a_holder_alone(): void {
		$this->seed_restricted_world();
		$GLOBALS['_current_user_id'] = self::HOLDER;
		$this->ci->dispatch( 'collect', [ 'col:renewals', self::DEAL ] );
		$this->ci->dispatch( 'collect', [ 'col:renewals', 'feed:gazette-award' ] );
		$this->seed( self::ontology_events() );

		$this->assertSame( [ 'col:renewals' => 2 ], \array_column( $this->ci->dispatch( 'collections', [] )['collections'], 'items', 'collection_key' ) );
		$this->assertSame( [ 'feed:gazette-award', self::DEAL ], \array_column( $this->ci->dispatch( 'collections', [ 'col:renewals' ] )['items'], 'item_key' ) );

		$GLOBALS['_current_user_id'] = self::READER;
		$this->assertSame( [ 'col:renewals' => 1 ], \array_column( $this->ci->dispatch( 'collections', [] )['collections'], 'items', 'collection_key' ) );
		$this->assertSame( [ 'feed:gazette-award' ], \array_column( $this->ci->dispatch( 'collections', [ 'col:renewals' ] )['items'], 'item_key' ) );
	}

	/** A grant adds the capability to the user and logs the operator and the user. */
	public function test_grant_adds_the_capability_and_logs_it(): void {
		$stderr = $this->capture_stderr();

		Intel_Tiers::grant( 'harbor-reader', 'user:ops-lead' );

		$this->assertSame( [ 'harbor-analyst', 'harbor-reader' ], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access granted to harbor-reader by user:ops-lead' ], self::access_log( $stderr ) );
	}

	/** A revoke removes it and logs the operator and the user. */
	public function test_revoke_removes_the_capability_and_logs_it(): void {
		$stderr = $this->capture_stderr();

		Intel_Tiers::revoke( 'harbor-analyst', 'wp-cli uid 1047' );

		$this->assertSame( [], Intel_Tiers::holders() );
		$this->assertSame( [ 'restricted access revoked from harbor-analyst by wp-cli uid 1047' ], self::access_log( $stderr ) );
	}

	/**
	 * An unknown login, a grant to a holder and a revoke from a user who
	 * holds no grant are each refused, changing and logging nothing.
	 */
	public function test_a_change_that_changes_nothing_is_refused(): void {
		$stderr = $this->capture_stderr();

		$this->assertSame( 'no user: ghost-login', self::refusal( static fn () => Intel_Tiers::grant( 'ghost-login', 'user:ops-lead' ) ) );
		$this->assertSame( 'no user: ghost-login', self::refusal( static fn () => Intel_Tiers::revoke( 'ghost-login', 'user:ops-lead' ) ) );
		$this->assertSame( 'harbor-analyst already holds restricted access', self::refusal( static fn () => Intel_Tiers::grant( 'harbor-analyst', 'user:ops-lead' ) ) );
		$this->assertSame( 'harbor-reader holds no restricted access grant', self::refusal( static fn () => Intel_Tiers::revoke( 'harbor-reader', 'user:ops-lead' ) ) );

		$this->assertSame( [ 'harbor-analyst' ], Intel_Tiers::holders() );
		$this->assertSame( [], self::access_log( $stderr ) );
	}

	/** Strip removes every user's own grant on the current site, and leaves a role carrying it. */
	public function test_strip_removes_every_grant_and_leaves_roles(): void {
		self::add_user( 6145, 'harbor-closer', 'newspack_intelligence_restricted' );
		self::add_role_holder();

		Intel_Tiers::strip();

		$this->assertSame( [], Intel_Tiers::holders() );
		$this->assertSame( [ 'manage_options' => true ], $GLOBALS['_wp_test_site_caps'][1][ self::HOLDER ] );
		$this->assertSame( [ 'intel_auditor' => true ], $GLOBALS['_wp_test_site_caps'][1][6146] );
	}

	/**
	 * A role carrying the capability reads the tier, but holds no grant: it
	 * is not listed, and a revoke refuses it.
	 */
	public function test_a_role_carrying_the_capability_reads_but_is_not_a_holder(): void {
		self::add_role_holder();
		$GLOBALS['_current_user_id'] = 6146;

		$this->assertSame( [ 'public', 'internal', 'restricted' ], Intel_Tiers::readable() );
		$this->assertSame( [ 'harbor-analyst' ], Intel_Tiers::holders() );
		$this->assertSame( 'harbor-auditor holds no restricted access grant', self::refusal( static fn () => Intel_Tiers::revoke( 'harbor-auditor', 'user:ops-lead' ) ) );
	}

	/** Every holder listed can be revoked. */
	public function test_every_listed_holder_can_be_revoked(): void {
		self::add_user( 6145, 'harbor-closer', 'newspack_intelligence_restricted' );
		self::add_role_holder();

		foreach ( Intel_Tiers::holders() as $login ) {
			Intel_Tiers::revoke( $login, 'user:ops-lead' );
		}

		$this->assertSame( [], Intel_Tiers::holders() );
	}

	/** A change names the user's own login, not the string typed for it. */
	public function test_a_change_names_the_users_own_login(): void {
		$stderr = $this->capture_stderr();

		$this->assertSame( 'harbor-reader', Intel_Tiers::grant( "  harbor-reader	", 'user:ops-lead' ) );
		$this->assertSame( 'harbor-reader', Intel_Tiers::revoke( '<b>harbor-reader</b>', 'user:ops-lead' ) );

		$this->assertSame( [ 'restricted access granted to harbor-reader by user:ops-lead', 'restricted access revoked from harbor-reader by user:ops-lead' ], self::access_log( $stderr ) );
	}

	/** On a multisite, a user who is not a member of this site is refused, and is never made one. */
	public function test_a_user_outside_this_site_is_refused(): void {
		$GLOBALS['_wp_test_is_multisite'] = true;
		\switch_to_blog( 9 );
		self::add_user( 6147, 'harbor-visitor', 'read' );
		\restore_current_blog();
		$stderr = $this->capture_stderr();

		$this->assertSame( 'harbor-visitor is not a member of this site', self::refusal( static fn () => Intel_Tiers::grant( ' harbor-visitor', 'user:ops-lead' ) ) );
		$this->assertSame( 'harbor-visitor is not a member of this site', self::refusal( static fn () => Intel_Tiers::revoke( 'harbor-visitor', 'user:ops-lead' ) ) );

		$this->assertArrayNotHasKey( 6147, $GLOBALS['_wp_test_site_caps'][1] );
		$this->assertSame( [], self::access_log( $stderr ) );
	}

	/** User 6146, `harbor-auditor`, holding the capability only through the role `intel_auditor`. */
	private static function add_role_holder(): void {
		\add_role( 'intel_auditor', 'Intel Auditor', [ 'manage_options' => true, 'newspack_intelligence_restricted' => true ] );
		self::add_user( 6146, 'harbor-auditor', 'intel_auditor' );
	}

	/**
	 * The message of the refusal a call throws, or `no refusal`.
	 *
	 * @param \Closure $call The call.
	 */
	private static function refusal( \Closure $call ): string {
		try {
			$call();
		} catch ( \InvalidArgumentException $e ) {
			return $e->getMessage();
		}
		return 'no refusal';
	}

	/**
	 * The read world, plus a restricted renewal deal the Gazette is the
	 * subject of, extracted with a claim, three organizations and a topic
	 * only the deal cites, and a collection to save it in.
	 */
	private function seed_restricted_world(): void {
		$this->seed_world();
		$this->seed(
			self::item( self::DEAL, 'Harbor Gazette renewal under review', 'hubspot', 'restricted', self::$oct_13, 'The renewal clause expires next quarter.' ),
			self::extraction( self::DEAL, 'The Gazette may not renew.', 'churn_signal', self::$oct_13, [ 'topic:awards' ] ),
			self::link( 'link_asserted', self::DEAL, self::GAZETTE, 'subject', 'identifier', 1.0, self::$oct_13 ),
			self::claim( self::DEAL, 0, self::GAZETTE, 'uses_product', '', 'A rival CMS', self::$oct_13 ),
			self::proposal( self::QUIET, 'Quiet Buyer', self::DEAL ),
			self::proposal( 'organization:quiet-buyer-llc', 'Quiet Buyer LLC', self::DEAL ),
			self::proposal( 'organization:tidal-buyer', 'Tidal Buyer', self::DEAL ),
			self::proposal( self::CHURN, 'Quiet Churn', self::DEAL ),
			[ Intel_Event::make( 'collection_created', [ 'collection_key' => 'col:renewals', 'name' => 'Renewals' ], 'user:41', 'fixture-9' ) ]
		);
	}
}

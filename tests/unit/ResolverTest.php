<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Graph_Curation;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Resolver;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The golden set: known items that must link to known entities, and known
 * ones that must not, each resolved against fixture entities projected into
 * the graph. A name two entities share links to both, a name that is not
 * held by any entity becomes a candidate entity, and GitHub and Linear items
 * resolve like any other.
 */
final class ResolverTest extends TestCase {
	use Seeds_Entity_Index;

	private const MESA = 'organization:mesa-courier';

	private const GAZETTE = 'organization:harbor-gazette';

	private const PLAINSFILE = 'organization:atomic-3003';

	private const BAYSHORE = 'organization:atomic-3006';

	private const LANTERN = 'organization:atomic-3009';

	private const KIWILEDGER = 'organization:atomic-3010';

	private const TERN = 'organization:tern-weekly';

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
	}

	protected function tearDown(): void {
		Resolver::$similar_text = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	/** An item on an entity's own domain was published by it: the host links it as publisher. */
	public function test_a_domain_url_links_its_entity_as_publisher(): void {
		$this->assertSame( [ self::link( self::MESA, 'publisher', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://mesa-news.example/story/1' ] )['links'] );
	}

	public function test_a_www_or_subdomain_url_links_its_publisher(): void {
		$this->assertSame( [ self::link( self::MESA, 'publisher', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://www.mesa-news.example/x' ] )['links'] );
		$this->assertSame( [ self::link( self::MESA, 'publisher', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://blog.mesa-news.example/x' ] )['links'] );
	}

	/** A client's own story whose text never names the client is published by it, not about it. */
	public function test_an_item_on_its_own_domain_naming_nothing_links_publisher_only(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'publisher', 'identifier', 1.0 ) ],
			$this->golden( [ 'url' => 'https://www.harborgazette.example/2026/10/crater', 'title' => 'A crater study from the university', 'body' => 'Researchers mapped the rim.' ] )['links']
		);
	}

	/** A client's own story whose title names the client links it twice: publisher by host, subject by name. */
	public function test_an_item_on_its_own_domain_naming_its_publisher_links_both(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'publisher', 'identifier', 1.0 ), self::link( self::GAZETTE, 'subject', 'name', 1.0 ) ],
			$this->golden( [ 'url' => 'https://www.harborgazette.example/2026/10/award', 'title' => 'The Harbor Gazette wins an award', 'body' => 'First place. The post appeared first on Harbor Gazette.' ] )['links']
		);
	}

	/**
	 * A site naming itself in its own body is not news about it: a body-only
	 * mention of the item's publisher is dropped, as a feed footer spells
	 * one, and a mention of anyone else in the same body stays.
	 */
	public function test_a_body_naming_its_own_publisher_mentions_no_one_but_others(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'publisher', 'identifier', 1.0 ) ],
			$this->golden( [ 'url' => 'https://www.harborgazette.example/2026/10/tide', 'title' => 'Tide tables for Monday', 'body' => 'Low water at four. The post Tide tables for Monday appeared first on Harbor Gazette.' ] )['links']
		);
		$this->assertSame(
			[ self::link( self::GAZETTE, 'publisher', 'identifier', 1.0 ), self::link( self::MESA, 'mention', 'name', 1.0 ) ],
			$this->golden( [ 'url' => 'https://www.harborgazette.example/2026/10/staff', 'title' => 'Staff news', 'body' => 'HarbGaz hires an editor from the Mesa Courier.' ] )['links']
		);
	}

	/** The host's entity publishes a hosted item; the repo its path names is the subject. */
	public function test_a_hosting_sites_entity_publishes_and_the_path_names_the_subject(): void {
		$host     = 'organization:forge-host';
		$product  = 'product:kiln-plugin';
		$entities = [ ...self::entity( $host, 'Forge Host', [ 'domain' => [ 'github.com' ] ] ), ...self::entity( $product, 'Kiln Plugin', [ 'github_repo' => [ 'kiln/plugin' ] ] ) ];

		$this->assertSame(
			[ self::link( $host, 'publisher', 'identifier', 1.0 ), self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->resolve( [ 'url' => 'https://github.com/kiln/plugin/issues/7', 'title' => 'Kiln stalls' ], $entities )['links']
		);
	}

	/** A proposed entity's site publishes as proposed, so a guess never asserts. */
	public function test_a_proposed_entitys_site_publishes_as_proposed(): void {
		$this->assertSame(
			[ self::link( 'organization:tern-weekly', 'publisher', 'identifier', 1.0, 'proposed' ) ],
			$this->resolve( [ 'url' => 'https://ternweekly.example/a', 'title' => 'Ferry schedule' ], self::proposed( 'organization:tern-weekly', 'Tern Weekly', [ 'domain' => [ 'ternweekly.example' ] ] ) )['links']
		);
	}

	public function test_a_name_in_the_title_links_as_subject(): void {
		$this->assertSame( [ self::link( self::GAZETTE, 'subject', 'name', 1.0 ) ], $this->golden( [ 'title' => 'The Harbor Gazette wins an award', 'url' => 'https://elsewhere.example/x' ] )['links'] );
	}

	public function test_an_alias_only_in_the_body_links_as_mention(): void {
		$this->assertSame( [ self::link( self::GAZETTE, 'mention', 'alias', 1.0 ) ], $this->golden( [ 'body' => 'Reported first by HarbGaz staff.', 'url' => 'https://elsewhere.example/x' ] )['links'] );
	}

	/** A name in both the title and the body links once, as subject. */
	public function test_a_name_in_title_and_body_links_once_as_subject(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'name', 1.0 ) ],
			$this->golden( [ 'title' => 'Harbor Gazette award', 'body' => 'HarbGaz and Harbor Gazette staff', 'url' => 'https://elsewhere.example/x' ] )['links']
		);
	}

	/** The URL's entity is the publisher; another named in the body is a mention. */
	public function test_a_domain_and_a_competing_name_both_link(): void {
		$this->assertSame(
			[ self::link( self::MESA, 'publisher', 'identifier', 1.0 ), self::link( self::GAZETTE, 'mention', 'name', 1.0 ) ],
			$this->golden( [ 'url' => 'https://mesa-news.example/x', 'body' => 'The Harbor Gazette also covered this.' ] )['links']
		);
	}

	/** Two names in one item are two links, never a hold. */
	public function test_two_names_link_both(): void {
		$this->assertSame(
			[ self::link( self::MESA, 'subject', 'name', 1.0 ), self::link( self::GAZETTE, 'subject', 'name', 1.0 ) ],
			$this->golden( [ 'title' => 'Mesa Courier and The Harbor Gazette both reported', 'url' => 'https://elsewhere.example/x' ] )['links']
		);
	}

	/** An item matching nothing links nothing and proposes nothing. */
	public function test_no_signal_links_nothing(): void {
		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => false ], $this->golden( [ 'title' => 'A local bake sale', 'url' => 'https://random.example/x', 'body' => 'Nothing relevant here.' ] ) );
	}

	public function test_a_word_boundary_prevents_a_substring_match(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'MDCQUERQUEXYZ launch', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** The domain still names an entity that carries no name of its own. */
	public function test_a_domain_stem_links_an_entity_with_no_names(): void {
		$this->assertSame( [ self::link( self::PLAINSFILE, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'PlainsFile wins a national award', 'url' => 'https://elsewhere.example/x' ] )['links'] );
	}

	/** "Lanternese" opens with the stem and is capitalized; only the boundary rejects it. */
	public function test_a_domain_stem_requires_word_boundaries(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'Lanternese language study published', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** "thecitizen" welds the ordinary phrase "the Citizen", which is not a name. */
	public function test_a_domain_stem_spanning_words_requires_name_casing(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'Improvements to the Citizen Activation System shipped', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** "thecitizen" spells its article, so "The Citizen" is the stem's whole span. */
	public function test_a_domain_stem_reads_an_article_its_domain_spells(): void {
		$this->assertSame( [ self::link( 'organization:atomic-3005', 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'The Citizen Offboarding Checklist', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** "wren" does not spell the article, and "The" never lengthens a stem too short to read. */
	public function test_a_domain_stem_not_spelling_the_article_never_reads_it(): void {
		$item = [ 'title' => 'The Wren Offboarding Checklist', 'url' => 'https://random.example/x' ];

		$this->assertSame( [], $this->resolve( $item, self::entity( 'organization:atomic-3101', 'Atomic site 3101', [ 'domain' => [ 'wren.example' ] ] ) )['links'] );
		$this->assertSame(
			[ self::link( 'organization:atomic-3102', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve( $item, self::entity( 'organization:atomic-3102', 'Atomic site 3102', [ 'domain' => [ 'thewren.example' ] ] ) )['links']
		);
	}

	/** "the" is the one article a stem's span may lead with: "A" or "An" opening a title never welds onto one. */
	public function test_a_domain_stem_never_welds_on_a_or_an(): void {
		$this->assertSame( [], $this->resolve( [ 'title' => 'A Newsroom Guide to Elections', 'url' => 'https://random.example/x' ], self::entity( 'organization:atomic-3103', 'Atomic site 3103', [ 'domain' => [ 'anewsroom.example' ] ] ) )['links'] );
		$this->assertSame( [], $this->resolve( [ 'title' => 'An Ewsroom Guide to Elections', 'url' => 'https://random.example/x' ], self::entity( 'organization:atomic-3104', 'Atomic site 3104', [ 'domain' => [ 'anewsroom.example' ] ] ) )['links'] );
		$this->assertFalse( Resolver::names_site( 'A Newsroom', 'anewsroom.example' ) );
	}

	/** A site title may join its capitalized words with a lowercase de, in, of, and or the. */
	public function test_a_site_title_may_hold_lowercase_connecting_words(): void {
		$this->assertTrue( Resolver::names_site( 'Pueblo de Cobre', 'pueblodecobre.example' ) );
		$this->assertTrue( Resolver::names_site( 'Riders in TDW', 'ridersintdw.example' ) );
		$this->assertTrue( Resolver::names_site( 'Friends of the Marsh', 'friendsofthemarsh.example' ) );
		$this->assertTrue( Resolver::names_site( 'Salt and Ember', 'saltandember.example' ) );
		$this->assertTrue( Resolver::names_site( 'The Wren', 'thewren.example' ) );
	}

	/** A lowercase word outside the connecting set, or one opening or closing the span, is prose. */
	public function test_a_site_title_refuses_other_lowercase_words(): void {
		$this->assertFalse( Resolver::names_site( 'Riders on Tidewater', 'ridersontidewater.example' ) );
		$this->assertFalse( Resolver::names_site( 'the Wren Press', 'thewrenpress.example' ) );
		$this->assertFalse( Resolver::names_site( 'Pueblo de', 'pueblode.example' ) );
		$this->assertFalse( Resolver::names_site( 'News', 'kestrelbay.example' ) );
		$this->assertFalse( Resolver::names_site( 'Kestrel Bayou News', 'kestrelbay.example' ) );
		$this->assertFalse( Resolver::names_site( 'Lanternese Weekly', 'lantern.example' ) );
	}

	/**
	 * A name of several words past its "the" names it where its first word
	 * past the article is capitalized, the rest in any case: as written, in
	 * Title Case or in ALL CAPS, but never all in lowercase.
	 */
	public function test_a_name_led_by_the_article_needs_its_first_word_capitalized(): void {
		$center = self::entity( 'organization:center', 'The Center for Tidewater Integrity' );
		$trust  = self::entity( 'organization:trust', "The Kestrel's Trust" );
		$item   = static fn ( string $title ): array => [ 'title' => $title, 'url' => 'https://random.example/x' ];

		$this->assertSame( [ self::link( 'organization:center', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'The Center for Tidewater Integrity reported a grant' ), $center )['links'] );
		$this->assertSame( [ self::link( 'organization:center', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'A grant from Center for Tidewater Integrity' ), $center )['links'] );
		$this->assertSame( [ self::link( 'organization:center', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'Grant To The Center For Tidewater Integrity' ), $center )['links'] );
		$this->assertSame( [ self::link( 'organization:center', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'THE CENTER FOR TIDEWATER INTEGRITY REPORTED A GRANT' ), $center )['links'] );
		$this->assertSame( [], $this->resolve( $item( 'A grant from the center for tidewater integrity' ), $center )['links'] );
		$this->assertSame( [ self::link( 'organization:trust', 'subject', 'name', 1.0 ) ], $this->resolve( $item( "The Kestrel's Trust announced a fund" ), $trust )['links'] );
		$this->assertSame( [ self::link( 'organization:trust', 'subject', 'name', 1.0 ) ], $this->resolve( $item( "The Kestrel\u{2019}s Trust announced a fund" ), $trust )['links'] );
	}

	/** A name the model proposed in lowercase still names its entity cased as a name. */
	public function test_a_lowercase_name_led_by_the_article_matches_cased_text(): void {
		$this->assertSame(
			[ self::link( 'organization:trust', 'subject', 'name', 1.0, 'proposed' ) ],
			$this->resolve( [ 'title' => "The Kestrel's Trust announced a fund", 'url' => 'https://random.example/x' ], self::proposed( 'organization:trust', "the kestrel's trust" ) )['links']
		);
	}

	/**
	 * "The Echo" folds to "echo", so its one word names it only cased as
	 * written and right after "the" or "The": a title-case headline
	 * capitalizes the bare word anyway.
	 */
	public function test_a_one_word_name_led_by_the_article_needs_the_article(): void {
		$echo = self::entity( 'organization:the-echo', 'The Echo' );
		$item = static fn ( string $title, string $body = '' ): array => [ 'title' => $title, 'body' => $body, 'url' => 'https://random.example/x' ];

		foreach ( [ 'A refused wallet checkout shows the error and leaves no echo', 'Wallet Checkout Leaves No Echo', 'Echo Amounts Found' ] as $title ) {
			$this->assertSame( [], $this->resolve( $item( $title ), $echo )['links'], $title );
		}
		$this->assertSame( [], $this->resolve( $item( 'Refunds', 'A refund left the echo of a charge behind.' ), $echo )['links'] );
		$this->assertSame( [ self::link( 'organization:the-echo', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'The Echo wins a press award' ), $echo )['links'] );
		$this->assertSame( [ self::link( 'organization:the-echo', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'the Echo reported a fire' ), $echo )['links'] );
		$this->assertSame( [ self::link( 'organization:the-echo', 'mention', 'name', 1.0 ) ], $this->resolve( $item( 'Awards', 'Reported first by the Echo staff.' ), $echo )['links'] );
		$this->assertSame( [ self::link( 'organization:the-echo', 'subject', 'name', 1.0 ) ], $this->resolve( $item( 'THE ECHO WINS A PRESS AWARD' ), $echo )['links'] );
	}

	/** A body is raw markup, so the article before a one-word name is read as prose reads it. */
	public function test_markup_between_the_article_and_a_one_word_name_keeps_the_article(): void {
		$echo = self::entity( 'organization:the-echo', 'The Echo' );
		$item = static fn ( string $body ): array => [ 'title' => 'Awards', 'body' => $body, 'url' => 'https://random.example/x' ];

		$this->assertSame( [ self::link( 'organization:the-echo', 'mention', 'name', 1.0 ) ], $this->resolve( $item( 'Reported first by the <a href="https://news.example/">Echo</a> staff.' ), $echo )['links'] );
		$this->assertSame( [ self::link( 'organization:the-echo', 'mention', 'name', 1.0 ) ], $this->resolve( $item( 'Reported first by the&nbsp;Echo staff.' ), $echo )['links'] );
		$this->assertSame( [], $this->resolve( $item( 'Reported first by <a href="https://news.example/the">Echo</a> staff.' ), $echo )['links'] );
	}

	/** A name not led by an article is still found in any case. */
	public function test_a_name_without_an_article_matches_in_any_case(): void {
		$this->assertSame(
			[ self::link( self::MESA, 'mention', 'name', 1.0 ) ],
			$this->golden( [ 'title' => 'Awards', 'body' => 'Reported first by mesa courier staff.', 'url' => 'https://random.example/x' ] )['links']
		);
	}

	public function test_a_domain_stem_spanning_words_matches_a_real_name(): void {
		$this->assertSame( [ self::link( self::BAYSHORE, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'Bay Shore Report expands coverage', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** "bayshorereport" is spelled "Bay Shore Report" in prose. */
	public function test_a_domain_stem_matches_across_the_spacing_prose_uses(): void {
		$this->assertSame( [ self::link( self::BAYSHORE, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'Bay Shore Report expands its education desk', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** A name and a stem each link their own entity, the name at full confidence. */
	public function test_an_exact_name_and_a_domain_stem_both_link(): void {
		$this->assertSame(
			[ self::link( self::MESA, 'subject', 'name', 1.0 ), self::link( self::PLAINSFILE, 'subject', 'domain_stem', 0.9 ) ],
			$this->golden( [ 'title' => 'Mesa Courier partners with PlainsFile', 'url' => 'https://random.example/x' ] )['links']
		);
	}

	public function test_two_stems_link_both(): void {
		$this->assertSame(
			[ self::link( self::PLAINSFILE, 'subject', 'domain_stem', 0.9 ), self::link( self::BAYSHORE, 'subject', 'domain_stem', 0.9 ) ],
			$this->golden( [ 'title' => 'PlainsFile and Bay Shore Report co-publish', 'url' => 'https://random.example/x' ] )['links']
		);
	}

	/** "kxzt.example" reduces to "kxzt", too short to tell from ordinary text. */
	public function test_a_short_domain_stem_is_not_used(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'Reported by KXZT staff', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** A lone lowercase common word is prose, not a masthead. */
	public function test_a_domain_stem_requires_casing_on_a_single_word(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'the lantern building downtown', 'url' => 'https://random.example/x' ] )['links'] );
	}

	public function test_a_domain_stem_matches_a_capitalized_single_word(): void {
		$this->assertSame( [ self::link( self::LANTERN, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'Lantern names a new editor', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** A body's href, logo filename or email address spells a domain, never a mention. */
	public function test_a_domain_stem_ignores_the_body(): void {
		$bodies = [
			'See <a href="https://plainsfile.example/story-x">this piece</a>.',
			'<img src="/img/plainsfile-logo.png" alt="logo">',
			'Send tips to tips@plainsfile.example today.',
		];
		foreach ( $bodies as $body ) {
			$this->assertSame( [], $this->golden( [ 'title' => 'Media roundup', 'body' => $body, 'url' => 'https://random.example/x' ] )['links'], $body );
		}
	}

	/** "kiwiledger.co.nz" reduces to its registrable name, not "kiwiledgerco". */
	public function test_a_domain_stem_handles_multi_part_tlds(): void {
		$this->assertSame( [ self::link( self::KIWILEDGER, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => 'Kiwiledger published a scoop', 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** A subdomain names its group, not the generic first label. */
	public function test_a_domain_stem_uses_the_registrable_label_of_a_subdomain(): void {
		$group = 'organization:atomic-3011';
		$this->assertSame(
			[ self::link( $group, 'subject', 'domain_stem', 0.9 ) ],
			$this->golden( [ 'title' => 'Harborviewmediagroup names an editor', 'url' => 'https://random.example/x' ], self::entity( $group, 'Atomic site 3011', [ 'domain' => [ 'newsroom.harborviewmediagroup.example' ] ] ) )['links']
		);
	}

	/** U+0130 lowercases to two codepoints; the stem's offsets still line up. */
	public function test_a_domain_stem_survives_multi_codepoint_lowercasing(): void {
		$this->assertSame( [ self::link( self::BAYSHORE, 'subject', 'domain_stem', 0.9 ) ], $this->golden( [ 'title' => "\u{130} Bay Shore Report expands coverage", 'url' => 'https://random.example/x' ] )['links'] );
	}

	/** A folded codepoint in a name still finds the name it spells. */
	public function test_a_folded_codepoint_still_aligns(): void {
		$istanbul = 'organization:istanbul-gazette';
		$this->assertSame(
			[ self::link( $istanbul, 'subject', 'name', 1.0 ) ],
			$this->golden( [ 'title' => 'İstanbul Gazette wins a press award', 'url' => 'https://random.example/x' ], self::entity( $istanbul, 'İstanbul Gazette' ) )['links']
		);
	}

	public function test_a_domain_stem_reports_a_lower_confidence_than_an_identifier(): void {
		$exact = $this->golden( [ 'url' => 'https://mesa-news.example/story/1' ] )['links'][0]['confidence'];
		$stem  = $this->golden( [ 'title' => 'PlainsFile wins a national award', 'url' => 'https://elsewhere.example/x' ] )['links'][0]['confidence'];
		$this->assertSame( 1.0, $exact );
		$this->assertLessThan( $exact, $stem );
	}

	/** A rejected entity links by neither its domain nor its name. */
	public function test_a_rejected_entity_never_links(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'Faded Gazette returns', 'url' => 'https://fadedgazette.example/x' ] )['links'] );
	}

	/** GitHub and Linear items resolve like any other item. */
	public function test_github_and_linear_items_resolve_like_any_other(): void {
		foreach ( [ 'github', 'linear' ] as $source ) {
			$this->assertSame( [ self::link( self::MESA, 'publisher', 'identifier', 1.0 ) ], $this->golden( [ 'source' => $source, 'url' => 'https://mesa-news.example/x' ] )['links'], $source );
		}
	}

	public function test_a_repo_url_links_its_product(): void {
		$product = 'product:example-plugin';
		$this->assertSame(
			[ self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->golden( [ 'source' => 'github', 'url' => 'https://github.com/example/plugin/pull/4100', 'title' => 'Fix the checkout' ], self::entity( $product, 'Example Plugin', [ 'github_repo' => [ 'example/plugin' ] ] ) )['links']
		);
	}

	/** An org URL links the org; a repo URL links its org and its repo. */
	public function test_a_github_org_links_by_its_identifier(): void {
		$org     = 'organization:example';
		$product = 'product:example-plugin';
		$entities = [ ...self::entity( $org, 'Example', [ 'github_org' => [ 'Example' ] ] ), ...self::entity( $product, 'Example Plugin', [ 'github_repo' => [ 'example/plugin' ] ] ) ];

		$this->assertSame( [ self::link( $org, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://github.com/example' ], $entities )['links'] );
		$this->assertSame(
			[ self::link( $org, 'subject', 'identifier', 1.0 ), self::link( $product, 'subject', 'identifier', 1.0 ) ],
			self::unevidenced( $this->resolver()->resolve( self::item( [ 'url' => 'https://github.com/Example/plugin/issues/9' ] ), false ) )['links']
		);
	}

	public function test_a_linear_issue_links_its_team_product(): void {
		$product = 'product:kite-ledger';
		$this->assertSame(
			[ self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->golden( [ 'source' => 'linear', 'url' => 'https://linear.app/heron-example/issue/KITE-2731/rotate-the-backups', 'title' => 'Rotate the backups' ], self::entity( $product, 'Kite Ledger', [ 'linear_team' => [ 'KITE' ] ] ) )['links']
		);
	}

	public function test_profile_urls_link_by_their_identifiers(): void {
		$entities = self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'linkedin_company' => [ 'harbor-gazette-news' ], 'x_handle' => [ 'HarborGazette' ] ] );
		foreach ( [ 'https://www.linkedin.com/company/harbor-gazette-news/posts', 'https://x.com/HarborGazette/status/1', 'https://twitter.com/@harborgazette' ] as $url ) {
			$this->assertSame( [ self::link( self::GAZETTE, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => $url ], $entities )['links'], $url );
		}
	}

	/** A surface naming two entities, with nothing to tell them apart, proposes both. */
	public function test_an_ambiguous_name_proposes_both(): void {
		$this->assertSame(
			[ self::link( 'organization:bend-ledger', 'subject', 'name', 0.5, 'proposed' ), self::link( 'organization:salem-ledger', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve( [ 'title' => 'Ledger wins a state award', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links']
		);
	}

	/**
	 * A name one accepted entity and one machine proposal share asserts the
	 * accepted one at its name's strength and links the proposal not at all.
	 */
	public function test_an_accepted_entity_wins_a_name_over_a_proposal(): void {
		$this->assertSame(
			[ self::link( 'organization:osprey-ledger', 'subject', 'name', 1.0 ) ],
			$this->resolve(
				[ 'title' => 'Osprey Ledger wins a press award', 'url' => 'https://random.example/x' ],
				self::proposed( 'organization:osprey-ledger-7720', 'Osprey Ledger' ),
				self::entity( 'organization:osprey-ledger', 'Osprey Ledger' )
			)['links']
		);
	}

	/** The accepted entity takes the strength of the hit that found it: a stem's 0.9. */
	public function test_an_accepted_stem_wins_a_surface_over_a_proposal_at_its_own_strength(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-3012', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'Sentinel names a new editor', 'url' => 'https://random.example/x' ],
				self::proposed( 'organization:sentinel', 'Sentinel' ),
				self::entity( 'organization:atomic-3012', 'Atomic site 3012', [ 'domain' => [ 'sentinel.example' ] ] )
			)['links']
		);
	}

	/** Two accepted entities beside a proposal still tell nothing apart: each proposed. */
	public function test_two_accepted_entities_and_a_proposal_propose_all_three(): void {
		$this->assertSame(
			[
				self::link( 'organization:bend-ledger', 'subject', 'name', 0.5, 'proposed' ),
				self::link( 'organization:salem-ledger', 'subject', 'name', 0.5, 'proposed' ),
				self::link( 'organization:ledger-4410', 'subject', 'name', 0.5, 'proposed' ),
			],
			$this->resolve( [ 'title' => 'Ledger wins a state award', 'url' => 'https://random.example/x' ], ...[ ...self::ledgers(), self::proposed( 'organization:ledger-4410', 'Ledger' ) ] )['links']
		);
	}

	/** Name and locality together are sufficient: the one whose locality the text names. */
	public function test_a_locality_in_the_text_settles_an_ambiguous_name(): void {
		$this->assertSame(
			[ self::link( 'organization:salem-ledger', 'subject', 'name', 0.9 ) ],
			$this->resolve( [ 'title' => 'Ledger wins a state award', 'body' => 'The paper, based in Salem, took first place.', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links']
		);
	}

	/** Both localities named settle nothing. */
	public function test_both_localities_named_still_propose_both(): void {
		$this->assertSame(
			[ 'organization:bend-ledger', 'organization:salem-ledger' ],
			\array_column( $this->resolve( [ 'title' => 'Ledger wins in Bend and Salem', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links'], 'entity_key' )
		);
	}

	/** The body's stronger link keeps the subject role the title's weaker one gave. */
	public function test_an_entity_the_title_names_stays_the_subject(): void {
		$this->assertSame(
			[ self::link( 'organization:bend-ledger', 'subject', 'alias', 1.0 ), self::link( 'organization:salem-ledger', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve(
				[ 'title' => 'Ledger wins a state award', 'body' => 'High Desert Ledger staff celebrated.', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:bend-ledger', 'Ledger', [ 'alias' => [ 'High Desert Ledger' ] ] ),
				self::entity( 'organization:salem-ledger', 'Ledger' )
			)['links']
		);
	}

	/** A machine's guess never becomes an assertion: a proposed entity's links are proposed. */
	public function test_a_proposed_entity_links_as_proposed_at_the_confidence_earned(): void {
		$events = [
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::proposed( 'organization:salem-ledger', 'Salem Ledger', [ 'domain' => [ 'salemledger.example' ] ] ),
		];

		$this->assertSame(
			[ self::link( 'organization:salem-ledger', 'subject', 'name', 1.0, 'proposed' ), self::link( self::GAZETTE, 'subject', 'name', 1.0 ) ],
			$this->resolve( [ 'title' => 'Salem Ledger and Harbor Gazette co-publish', 'url' => 'https://random.example/x' ], ...$events )['links']
		);
		$this->assertSame(
			[ self::link( 'organization:salem-ledger', 'publisher', 'identifier', 1.0, 'proposed' ) ],
			self::unevidenced( $this->resolver()->resolve( self::item( [ 'url' => 'https://salemledger.example/a' ] ), false ) )['links']
		);
	}

	/** A proposed entity's fuzzy match is proposed even above the asserting band. */
	public function test_a_proposed_entity_is_never_asserted_by_ner(): void {
		$index = $this->index_of( self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) );

		$this->assertSame(
			[ self::link( 'organization:salem-ledger', 'subject', 'ner_fuzzy', 1.0, 'proposed' ) ],
			self::unevidenced( ( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Regional merger', 'url' => 'https://random.example/x' ] ), self::named( [ 'Salem Ledger' ] ) ) )['links']
		);
	}

	/** A stem's span is the longest, so the name inside it drops: one span, one entity. */
	public function test_a_name_inside_a_stem_span_drops(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-3014', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'Bay Shore Report cuts staff', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:atomic-3014', 'Atomic site 3014', [ 'domain' => [ 'bayshorereport.test' ] ] ),
				self::entity( 'organization:shore-report', 'Shore Report' )
			)['links']
		);
	}

	/** A locality is evidence, never a name: a longer locality span drops nothing. */
	public function test_a_name_inside_a_longer_locality_still_links(): void {
		$this->assertSame(
			[ self::link( 'organization:bend-bulletin', 'subject', 'name', 1.0 ) ],
			$this->resolve(
				[ 'title' => 'Bend Bulletin Square reopens', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:bend-bulletin', 'Bend Bulletin' ),
				self::entity( 'organization:mesa-courier', 'Mesa Courier', [ 'locality' => [ 'Bend Bulletin Square' ] ] )
			)['links']
		);
	}

	/** Two names overlapping at equal length are one ambiguous span: each proposed. */
	public function test_equal_length_overlapping_names_propose_both(): void {
		$this->assertSame(
			[ self::link( 'organization:bayport-times-media', 'subject', 'name', 0.5, 'proposed' ), self::link( 'organization:times-media-group', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve(
				[ 'title' => 'Bayport Times Media Group expands', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:bayport-times-media', 'Bayport Times Media' ),
				self::entity( 'organization:times-media-group', 'Times Media Group' )
			)['links']
		);
	}

	/** A lowercase word spelling a locality is prose, so it settles nothing. */
	public function test_a_lowercase_locality_settles_nothing(): void {
		$this->assertSame(
			[ self::link( 'organization:bend-ledger', 'subject', 'name', 0.5, 'proposed' ), self::link( 'organization:salem-ledger', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve( [ 'title' => 'Ledger readers bend the rules', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links']
		);
	}

	/** A name inside a longer name links only the longer one. */
	public function test_the_longest_name_wins(): void {
		$this->assertSame(
			[ self::link( 'organization:the-bayport-times', 'subject', 'name', 1.0 ) ],
			$this->resolve(
				[ 'title' => 'The Bayport Times wins a Kestrel', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:the-times', 'The Times' ),
				self::entity( 'organization:the-bayport-times', 'The Bayport Times' )
			)['links']
		);
	}

	/** A name and another entity's stem on one surface are one ambiguity: both proposed. */
	public function test_a_name_and_a_stem_on_one_surface_propose_both(): void {
		$this->assertSame(
			[ self::link( 'organization:sentinel', 'subject', 'name', 0.5, 'proposed' ), self::link( 'organization:atomic-3012', 'subject', 'domain_stem', 0.5, 'proposed' ) ],
			$this->resolve(
				[ 'title' => 'Sentinel names a new editor', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:sentinel', 'Sentinel' ),
				self::entity( 'organization:atomic-3012', 'Atomic site 3012', [ 'domain' => [ 'sentinel.example' ] ] )
			)['links']
		);
	}

	/** Two stems on one surface are settled by a locality the text names. */
	public function test_a_locality_settles_a_stem_ambiguity(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-3013', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'Sentinel names a new editor', 'body' => 'The Salem paper announced it Monday.', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:atomic-3012', 'Atomic site 3012', [ 'domain' => [ 'sentinel.example' ], 'locality' => [ 'Bend' ] ] ),
				self::entity( 'organization:atomic-3013', 'Atomic site 3013', [ 'domain' => [ 'sentinel.test' ], 'locality' => [ 'Salem' ] ] )
			)['links']
		);
	}

	/** A locality alone names no entity. */
	public function test_a_locality_alone_links_nothing(): void {
		$this->assertSame( [], $this->resolve( [ 'title' => 'Salem council meets', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links'] );
	}

	public function test_an_acronym_needs_its_exact_case(): void {
		$owl      = 'organization:owl-publishers';
		$entities = self::entity( $owl, 'Organized Writers League', [ 'acronym' => [ 'OWL' ] ] );

		$this->assertSame( [ self::link( $owl, 'subject', 'acronym', 0.9 ) ], $this->resolve( [ 'title' => 'OWL summit opens', 'url' => 'https://random.example/x' ], $entities )['links'] );
		$this->assertSame( [], $this->resolver()->resolve( self::item( [ 'title' => 'An owl escapes the zoo', 'url' => 'https://random.example/x' ] ), false )['links'] );
	}

	public function test_ner_exact_org_asserts(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 1.0 ) ],
			$this->golden( [ 'title' => 'Nonprofit newsrooms expand', 'url' => 'https://elsewhere.example/x' ], [], self::named( [ 'The Harbor Gazette' ] ) )['links']
		);
	}

	/** "Harbor Gazette" against "The Harbor Gazette": the leading article is dropped. */
	public function test_ner_close_variant_asserts(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 1.0 ) ],
			$this->golden( [ 'title' => 'Coverage roundup', 'url' => 'https://elsewhere.example/x' ], [], self::named( [ 'Harbor Gazette' ] ) )['links']
		);
	}

	/** An organization matching nothing becomes a candidate entity, not a drop. */
	public function test_ner_unrelated_org_becomes_a_proposal(): void {
		$this->assertSame(
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Acme Widgets Corporation' ] ], 'recognized' => true ],
			$this->golden( [ 'title' => 'Factory opens', 'url' => 'https://elsewhere.example/x' ], [], self::named( [ 'Acme Widgets Corporation' ] ) )
		);
	}

	public function test_ner_midband_proposes_a_link(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 0.8, 'proposed' ) ],
			$this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], self::named( [ 'Harbor Gazette Weekly' ] ), 0.99, 0.30 )['links']
		);
	}

	public function test_ner_bands_assert_propose_and_candidate(): void {
		$extractor = self::named( [ 'Salem Ledg', 'Harbor Gazette Foundation', 'Acme Widgets Corporation' ] );
		$index     = $this->index_of(
			self::entity( 'organization:salem-ledger', 'Salem Ledger' ),
			self::entity( self::GAZETTE, 'Harbor Gazette' ),
			self::entity( 'organization:cascade-courier', 'Cascade Courier' )
		);

		$this->assertSame(
			[
				'links'     => [
					self::link( 'organization:salem-ledger', 'subject', 'ner_fuzzy', 0.9091 ),
					self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 0.7179, 'proposed' ),
				],
				'proposals'  => [ [ 'type' => 'organization', 'name' => 'Acme Widgets Corporation' ] ],
				'recognized' => true,
			],
			self::unevidenced( ( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Regional publishers announce a merger', 'url' => 'https://random.example/x' ] ), $extractor ) )
		);
	}

	/** An exact norm scores 1.0 with no scan, and a norm whose length cannot reach propose_at is never compared. */
	public function test_the_fuzzy_step_compares_only_norms_that_can_reach_propose_at(): void {
		$compared               = [];
		Resolver::$similar_text = static function ( string $a, string $b ) use ( &$compared ): float {
			$compared[] = $b;
			\similar_text( $a, $b, $percent );
			return $percent;
		};
		$index = $this->index_of(
			self::entity( 'organization:quarry-ledger', 'Quarry Ledger' ),
			self::entity( 'organization:qu-press', 'QU' ),
			self::entity( 'organization:quarry-ledger-sunday', 'Quarry Ledger Weekly and Sunday Edition' )
		);
		$item = self::item( [ 'title' => 'Regional merger', 'url' => 'https://random.example/x' ] );

		$exact = ( new Resolver( $index ) )->resolve( $item, self::named( [ 'Quarry Ledger' ] ) );
		$this->assertSame( [ self::link( 'organization:quarry-ledger', 'subject', 'ner_fuzzy', 1.0 ) ], self::unevidenced( $exact )['links'] );
		$this->assertSame( [], $compared, 'an exact norm needs no scan' );

		$near = ( new Resolver( $index ) )->resolve( $item, self::named( [ 'Quarry Ledgr' ] ) );
		$this->assertSame( [ 'organization:quarry-ledger' ], \array_column( $near['links'], 'entity_key' ) );
		$this->assertSame( [ 'quarry ledger' ], $compared, 'neither a 2-byte nor a 39-byte norm can reach 0.60 against 12 bytes' );
	}

	/**
	 * NER that answered without an organization links nothing, and is
	 * recognized as having answered.
	 */
	public function test_ner_no_orgs_links_nothing(): void {
		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => true ], $this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], self::named( [] ) ) );
	}

	/** With no NER step, an item nothing else linked links and proposes nothing, and is not recognized. */
	public function test_without_a_ner_step_nothing_is_recognized(): void {
		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => false ], $this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], false ) );
	}

	/** A NER step owed where the first three steps found nothing answers none: the step is to be asked. */
	public function test_an_owed_ner_step_answers_none(): void {
		$this->assertNull( ( new Resolver( $this->index_of( self::golden_entities() ) ) )->resolve( self::item( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ] ), true ) );
	}

	/** A deterministic link, a publisher's included, spares the model call: an owed step answers, not recognized. */
	public function test_a_deterministic_link_skips_the_extractor(): void {
		$this->assertFalse( $this->golden( [ 'url' => 'https://mesa-news.example/x' ], [], true )['recognized'] );
	}

	public function test_a_rejected_name_is_never_proposed_again(): void {
		$index = $this->index_of(
			self::proposed( 'organization:acme-widgets-corporation', 'Acme Widgets Corporation' ),
			self::rejected( 'organization:acme-widgets-corporation' )
		);

		$this->assertSame(
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Portland Timber Union', 'evidence' => '' ] ], 'recognized' => true ],
			( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Factory opens', 'url' => 'https://random.example/x' ] ), self::named( [ 'Acme Widgets Corporation', 'Portland Timber Union' ] ) )
		);
	}

	/** A word the stoplist rejects, said in a body, links no accepted entity that carries it as its name. */
	public function test_a_rejected_name_in_a_body_links_nothing(): void {
		$this->assertSame( [], $this->resolve( [ 'body' => 'Volunteers swept the wharfside on Sunday.', 'url' => 'https://elsewhere.example/x' ], ...self::wharfside() )['links'] );
	}

	/** A word the stoplist rejects, said in a title, links no accepted entity by its name or its domain's stem. */
	public function test_a_rejected_name_in_a_title_links_nothing(): void {
		$this->assertSame( [], $this->resolve( [ 'title' => 'Wharfside honours its volunteers', 'url' => 'https://elsewhere.example/x' ], ...self::wharfside() )['links'] );
	}

	/** A domain's stem spelling a rejected name links nothing, though the entity carries no name of it. */
	public function test_a_domain_stem_spelling_a_rejected_name_links_nothing(): void {
		$this->assertSame( [], $this->resolve( [ 'title' => 'Wharfside honours its volunteers', 'url' => 'https://elsewhere.example/x' ], ...self::wharfside( [ 'domain' => [ 'wharfside.co.nz' ] ] ) )['links'] );
	}

	/** The client whose name the stoplist rejects is still its own site's publisher. */
	public function test_a_rejected_name_still_links_its_own_site_as_publisher(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-5121', 'publisher', 'identifier', 1.0 ) ],
			$this->resolve( [ 'title' => 'Wharfside honours its volunteers', 'body' => 'Volunteers swept the wharfside.', 'url' => 'https://www.wharfside.co.nz/2026/11/awards' ], ...self::wharfside() )['links']
		);
	}

	/** An alias the rejected entity does not carry links its entity as any alias does. */
	public function test_an_alias_a_rejected_entity_does_not_carry_still_links(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-5121', 'subject', 'alias', 1.0 ) ],
			$this->resolve( [ 'title' => 'Kaipara Wharf Press honours its volunteers', 'url' => 'https://elsewhere.example/x' ], ...self::wharfside() )['links']
		);
	}

	/** A rejected organization's name blocks only organizations: a product carrying it still links by title. */
	public function test_a_product_carrying_a_rejected_organizations_name_still_links_by_title(): void {
		$this->assertSame(
			[ self::link( 'product:kestrel-mail', 'subject', 'name', 1.0 ) ],
			$this->resolve( [ 'title' => 'Kestrel Mail goes dark for an hour', 'url' => 'https://elsewhere.example/x' ], ...self::kestrel_mail() )['links']
		);
	}

	/** The NER fallback never links an entity by a name a rejected entity of its own type carries, nor proposes it. */
	public function test_the_ner_fallback_never_reaches_a_name_its_type_rejects(): void {
		$this->last = new Resolver( $this->index_of( ...self::wharfside() ) );

		$this->assertSame(
			[ 'links' => [], 'proposals' => [], 'recognized' => true ],
			self::unevidenced( $this->last->resolve( self::item( [ 'title' => 'Prize season opens on the coast', 'url' => 'https://elsewhere.example/x' ] ), self::named( [ 'Wharfside' ] ) ) )
		);
	}

	/** The NER fallback still links a product by a name only a rejected organization also carries. */
	public function test_the_ner_fallback_still_reaches_another_type_carrying_a_rejected_name(): void {
		$this->last = new Resolver( $this->index_of( ...self::kestrel_mail() ) );

		$this->assertSame(
			[ self::link( 'product:kestrel-mail', 'subject', 'ner_fuzzy', 1.0 ) ],
			self::unevidenced( $this->last->resolve( self::item( [ 'title' => 'Mail outage', 'url' => 'https://elsewhere.example/x' ] ), self::named( [ 'Kestrel Mail' ] ) ) )['links']
		);
	}

	/** A stoplisted span still wins its overlap, so the shorter name inside it links nothing either. */
	public function test_a_stoplisted_span_still_claims_its_span(): void {
		$this->assertSame(
			[],
			$this->resolve(
				[ 'title' => 'Kestrel Mail goes dark', 'url' => 'https://elsewhere.example/x' ],
				self::entity( 'organization:kestrel-mail-service', 'Kestrel Mail' ),
				self::entity( 'organization:kestrel', 'Kestrel' ),
				self::proposed( 'organization:kestrel-mail', 'Kestrel Mail' ),
				self::rejected( 'organization:kestrel-mail' )
			)['links']
		);
	}

	/** A stem spelled across a space is judged by its squashed spelling, so "Wharf Side" is the rejected Wharfside. */
	public function test_a_stem_spelled_across_spacing_links_nothing(): void {
		$this->assertSame(
			[],
			$this->resolve(
				[ 'title' => 'Wharf Side Awards Open', 'url' => 'https://elsewhere.example/x' ],
				self::entity( 'organization:atomic-6012', 'Atomic site 6012', [ 'domain' => [ 'wharfside.example' ] ] ),
				self::entity( 'organization:wharfside', 'Wharfside' ),
				self::rejected( 'organization:wharfside' )
			)['links']
		);
	}

	/** A rejected name does not stoplist an acronym spelled the same, which matches only in its own case. */
	public function test_a_rejected_name_does_not_block_an_acronym(): void {
		$this->assertSame(
			[ self::link( 'organization:world-health', 'subject', 'acronym', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'WHO warns of measles', 'url' => 'https://elsewhere.example/x' ],
				self::entity( 'organization:world-health', 'World Health Organization', [ 'acronym' => [ 'WHO' ] ] ),
				self::entity( 'organization:who', 'Who' ),
				self::rejected( 'organization:who' )
			)['links']
		);
	}

	/** A rejected acronym does not stoplist a name spelled the same. */
	public function test_a_rejected_acronym_does_not_block_a_name(): void {
		$this->assertSame(
			[ self::link( 'organization:owl', 'subject', 'name', 1.0 ) ],
			$this->resolve(
				[ 'title' => 'Owl opens a bureau', 'url' => 'https://elsewhere.example/x' ],
				self::entity( 'organization:owl', 'Owl' ),
				self::proposed( 'organization:owl-publishers', 'Owl Publishers', [ 'acronym' => [ 'OWL' ] ] ),
				self::rejected( 'organization:owl-publishers' )
			)['links']
		);
	}

	/** A fuzzy tie is ambiguous before the stoplist drops a winner, so what remains is proposed, not asserted. */
	public function test_a_fuzzy_tie_with_a_stoplisted_winner_stays_proposed(): void {
		$this->last = new Resolver( $this->index_of( ...self::wharfside(), ...[ self::entity( 'product:wharfside-co', 'Wharfside Co' ) ] ) );

		$this->assertSame(
			[ self::link( 'product:wharfside-co', 'subject', 'ner_fuzzy', 0.8571, 'proposed' ) ],
			self::unevidenced( $this->last->resolve( self::item( [ 'title' => 'Prize season opens on the coast', 'url' => 'https://elsewhere.example/x' ] ), self::named( [ 'Wharfside' ] ) ) )['links']
		);
	}

	/** Equal spans whose co-winner is stoplisted stay ambiguous: the rest is proposed at 0.5. */
	public function test_an_equal_span_beside_a_stoplisted_hit_stays_ambiguous(): void {
		$this->assertSame(
			[ self::link( 'product:wharfside', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve( [ 'title' => 'Wharfside honours its volunteers', 'url' => 'https://elsewhere.example/x' ], ...self::wharfside(), ...[ self::entity( 'product:wharfside', 'Wharfside' ) ] )['links']
		);
	}

	/** Of one entity's equal-span hits, longest() keeps the one not stoplisted, in either order. */
	public function test_longest_prefers_an_entitys_hit_that_is_not_stoplisted(): void {
		$name    = [ 'entity_key' => 'organization:atomic-6012', 'kind' => 'canonical', 'surface' => 'News Room', 'at' => 0, 'stoplisted' => false ];
		$stem    = [ 'entity_key' => 'organization:atomic-6012', 'kind' => 'domain_stem', 'surface' => 'News Room', 'at' => 0, 'stoplisted' => true ];
		$longest = new \ReflectionMethod( Resolver::class, 'longest' );

		$this->assertSame( [ 'organization:atomic-6012' => $name ], $longest->invoke( null, [ $name, $stem ] ) );
		$this->assertSame( [ 'organization:atomic-6012' => $name ], $longest->invoke( null, [ $stem, $name ] ) );
	}

	/** A tool the extractor names is proposed as a product, an organization beside it as an organization. */
	public function test_a_tool_named_in_an_item_is_proposed_as_a_product(): void {
		$extractor = self::named( [ 'Plover Wharf Trust' ], [ 'Kestrel Mail' ] );

		$this->assertSame(
			[ [ 'organization', 'Plover Wharf Trust' ], [ 'product', 'Kestrel Mail' ] ],
			\array_map( static fn ( array $p ): array => [ $p['type'], $p['name'] ], $this->golden( [ 'title' => 'Wharf trust moves its mail', 'url' => 'https://random.example/x' ], [], $extractor )['proposals'] )
		);
	}

	/** A product the extractor names that an entity already carries links to it rather than being proposed. */
	public function test_a_named_product_an_entity_carries_links_to_it(): void {
		$resolved = $this->golden( [ 'title' => 'Mail outage', 'url' => 'https://random.example/x' ], self::entity( 'product:kestrel-mail', 'Kestrel Mail' ), self::named( [], [ 'Kestrel Mail' ] ) );

		$this->assertSame( [ 'product:kestrel-mail' ], \array_column( $resolved['links'], 'entity_key' ) );
		$this->assertSame( [], $resolved['proposals'] );
	}

	/** A proposed link found in the body quotes its sentence, markup gone; an asserted one quotes nothing. */
	public function test_a_proposed_body_name_quotes_its_sentence_and_an_asserted_one_none(): void {
		$links = $this->evidenced( [ 'url' => 'https://elsewhere.example/x', 'body' => '<p>Tides rose early.</p><p>Reporters at <strong>Tern Weekly</strong> won a prize &amp; a plaque.</p><p>The Harbor Gazette agreed.</p>' ], self::proposed( self::TERN, 'Tern Weekly' ) )['links'];

		$this->assertSame(
			[ [ self::TERN, 'proposed', 'Reporters at Tern Weekly won a prize & a plaque.' ], [ self::GAZETTE, 'asserted', null ] ],
			\array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['state'], \array_key_exists( 'evidence', $l ) ? $l['evidence'] : null ], $links )
		);
	}

	/** A name the body breaks across a line, a tab or a double space quotes the sentence it sits in. */
	public function test_a_name_split_by_whitespace_quotes_its_sentence(): void {
		$links = $this->evidenced( [ 'url' => 'https://elsewhere.example/x', 'body' => "<p>Tides rose early. Reporters at Tern\nWeekly won;\tTern  Weekly\tcheered.</p>" ], self::proposed( self::TERN, 'Tern Weekly' ) )['links'];

		$this->assertSame( [ [ self::TERN, 'Reporters at Tern Weekly won; Tern Weekly cheered.' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['evidence'] ], $links ) );
	}

	/** A proposed name in the title quotes the title; a host quotes nothing. */
	public function test_a_proposed_title_name_quotes_the_title_and_a_host_none(): void {
		$links = $this->evidenced( [ 'url' => 'https://mesa-news.example/x', 'title' => 'Tern Weekly wins an award' ], self::proposed( self::TERN, 'Tern Weekly' ) )['links'];

		$this->assertSame( [ [ self::MESA, null ], [ self::TERN, 'Tern Weekly wins an award' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], \array_key_exists( 'evidence', $l ) ? $l['evidence'] : null ], $links ) );
	}

	/** A candidate and a proposed lookalike quote the sentence naming them; a name the text never spells quotes nothing. */
	public function test_a_proposal_and_a_lookalike_carry_the_sentence_naming_them(): void {
		$resolved = $this->evidenced(
			[ 'url' => 'https://random.example/x', 'title' => 'Ferry news', 'body' => 'Plover Wharf Trust bought a ferry. Staff at harbor gazete covered the sale!' ],
			[],
			self::named( [ 'Plover Wharf Trust', 'Harbor Gazete', 'Gull Rota' ] ),
			0.99
		);

		$this->assertSame( [ [ self::GAZETTE, 'ner_fuzzy', 'proposed', 'Staff at harbor gazete covered the sale!' ] ], \array_map( static fn ( array $l ): array => [ $l['entity_key'], $l['method'], $l['state'], $l['evidence'] ], $resolved['links'] ) );
		$this->assertSame(
			[
				[ 'type' => 'organization', 'name' => 'Plover Wharf Trust', 'evidence' => 'Plover Wharf Trust bought a ferry.' ],
				[ 'type' => 'organization', 'name' => 'Gull Rota', 'evidence' => '' ],
			],
			$resolved['proposals']
		);
	}

	/** A sentence past the cap is cut on a whole character around its name, within the written bytes an event takes. */
	public function test_long_evidence_is_cut_on_a_character_boundary_keeping_the_name(): void {
		$body     = \str_repeat( 'Ünïcödé "quoted" \\ text, ', 120 ) . 'and Tern Weekly ' . \str_repeat( 'wrote éé "more" ', 120 ) . 'here.';
		$evidence = $this->evidenced( [ 'url' => 'https://elsewhere.example/x', 'body' => $body ], self::proposed( self::TERN, 'Tern Weekly' ) )['links'][0]['evidence'];

		$this->assertLessThanOrEqual( Intel_Event::EVIDENCE_BYTES, Graph_Curation::written_bytes( $evidence ) );
		$this->assertGreaterThan( Intel_Event::EVIDENCE_BYTES - 16, Graph_Curation::written_bytes( $evidence ), 'the cut fills the cap' );
		$this->assertTrue( \mb_check_encoding( $evidence, 'UTF-8' ) );
		$this->assertStringContainsString( 'Tern Weekly', $evidence );
		$this->assertStringStartsWith( '…', $evidence );
		$this->assertStringEndsWith( '…', $evidence );
	}

	public function test_no_more_than_three_proposals_per_item(): void {
		$extractor = self::named( [ 'Acme Widgets Corporation', 'acme widgets corporation', 'Portland Timber Union', 'Pine Valley Radio', 'River City Times', 'Coastal Ferry Lines' ] );

		$this->assertSame(
			[ 'Acme Widgets Corporation', 'Portland Timber Union', 'Pine Valley Radio' ],
			\array_column( $this->golden( [ 'title' => 'Factory opens', 'url' => 'https://random.example/x' ], [], $extractor )['proposals'], 'name' )
		);
		$this->assertSame( 3, Resolver::MAX_PROPOSALS );
	}

	/** With no extractor a deterministic miss links nothing and proposes nothing. */
	public function test_no_extractor_links_nothing(): void {
		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => false ], $this->golden( [ 'title' => 'Acme Widgets Corporation opens', 'url' => 'https://random.example/x' ] ) );
	}

	public function test_an_empty_index_links_nothing_and_still_proposes(): void {
		$this->assertSame(
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Harbor Gazette', 'evidence' => 'Harbor Gazette' ] ], 'recognized' => true ],
			( new Resolver( new Entity_Index() ) )->resolve( self::item( [ 'url' => 'https://harborgazette.example/x', 'title' => 'Harbor Gazette' ] ), self::named( [ 'Harbor Gazette' ] ) )
		);
	}

	/** The resolver built over the last index the test seeded. */
	private ?Resolver $last = null;

	/**
	 * Resolve an item against the golden fixtures plus any extra entities.
	 *
	 * @param array<string,string>      $over      The item's fields.
	 * @param list<array<string,mixed>> $extra     More entity events.
	 * @param array<string,list<string>>|bool $ner The names the NER step answered, true when it is owed, or false for none.
	 * @param float                     $assert_at The asserting band.
	 * @param float                     $propose_at The proposing band.
	 * @return array{links: list<array<string,mixed>>, proposals: list<array<string,string>>}
	 */
	private function golden( array $over, array $extra = [], array|bool $ner = false, float $assert_at = 0.85, float $propose_at = 0.60 ): array {
		return self::unevidenced( $this->evidenced( $over, $extra, $ner, $assert_at, $propose_at ) );
	}

	/**
	 * Resolve an item against the golden fixtures, every link and proposal
	 * carrying its evidence.
	 *
	 * @param array<string,string>       $over       The item's fields.
	 * @param list<array<string,mixed>>  $extra      More entity events.
	 * @param array<string,list<string>>|bool $ner The names the NER step answered, true when it is owed, or false for none.
	 * @param float                      $assert_at  The asserting band.
	 * @param float                      $propose_at The proposing band.
	 * @return array{links: list<array<string,mixed>>, proposals: list<array<string,string>>, recognized: bool}
	 */
	private function evidenced( array $over, array $extra = [], array|bool $ner = false, float $assert_at = 0.85, float $propose_at = 0.60 ): array {
		$this->last = new Resolver( $this->index_of( self::golden_entities(), $extra ), $assert_at, $propose_at );
		$resolution = $this->last->resolve( self::item( $over ), $ner );
		\assert( null !== $resolution );
		return $resolution;
	}

	/**
	 * A resolution with each link's and proposal's evidence left out: the
	 * golden set judges what links, and the evidence tests what it quotes.
	 *
	 * @param array{links: list<array<string,mixed>>, proposals: list<array<string,string>>, recognized: bool} $resolution What resolve() answered.
	 * @return array{links: list<array<string,mixed>>, proposals: list<array<string,string>>, recognized: bool}
	 */
	private static function unevidenced( array $resolution ): array {
		$drop = static fn ( array $rows ): array => \array_map( static fn ( array $row ): array => \array_diff_key( $row, [ 'evidence' => true ] ), $rows );
		return [ 'links' => $drop( $resolution['links'] ), 'proposals' => $drop( $resolution['proposals'] ) ] + $resolution;
	}

	/**
	 * Resolve an item against only the given entities.
	 *
	 * @param array<string,string>      $over     The item's fields.
	 * @param list<array<string,mixed>> ...$events Entity events.
	 * @return array{links: list<array<string,mixed>>, proposals: list<array<string,string>>}
	 */
	private function resolve( array $over, array ...$events ): array {
		$this->last = new Resolver( $this->index_of( ...$events ) );
		return self::unevidenced( $this->last->resolve( self::item( $over ), false ) );
	}

	/** The resolver the last golden() or resolve() built. */
	private function resolver(): Resolver {
		\assert( null !== $this->last );
		return $this->last;
	}

	/**
	 * The fixture roster: named entities, a rejected one, and entities known
	 * only by a domain, as an import leaves them.
	 *
	 * @return list<array<string,mixed>>
	 */
	private static function golden_entities(): array {
		$atomic = static fn ( int $id, string $domain ): array => self::entity( "organization:atomic-{$id}", "Atomic site {$id}", [ 'domain' => [ $domain ] ] );
		return [
			...self::entity( self::MESA, 'Mesa Courier', [ 'alias' => [ 'MDC', 'Mesa Daily Courier' ], 'domain' => [ 'mesa-news.example' ] ] ),
			...self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'domain' => [ 'harborgazette.example' ] ] ),
			...self::proposed( 'organization:faded-gazette', 'Faded Gazette', [ 'domain' => [ 'fadedgazette.example' ] ] ),
			...self::rejected( 'organization:faded-gazette' ),
			...$atomic( 3003, 'plainsfile.example' ),
			...$atomic( 3004, 'tidewater.co.nz' ),
			...$atomic( 3005, 'thecitizen.example' ),
			...$atomic( 3006, 'bayshorereport.example' ),
			...$atomic( 3007, 'kxzt.example' ),
			...$atomic( 3009, 'lantern.example' ),
			...$atomic( 3010, 'kiwiledger.co.nz' ),
		];
	}

	/**
	 * A client the roster named by its site, which took the site's title,
	 * Wharfside, as a canonical name, beside the stoplist's rejected
	 * Wharfside; or, given facts, a client holding only those.
	 *
	 * @param array<string,list<string>> $facts The client's names and identifiers in place of the default.
	 * @return list<list<array<string,mixed>>>
	 */
	private static function wharfside( array $facts = [ 'canonical' => [ 'Wharfside' ], 'alias' => [ 'Kaipara Wharf Press', 'Wharfside NZ' ], 'domain' => [ 'wharfside.co.nz' ] ] ): array {
		return [
			self::entity( 'organization:atomic-5121', 'Atomic site 5121', $facts ),
			self::entity( 'organization:wharfside', 'Wharfside' ),
			self::rejected( 'organization:wharfside' ),
		];
	}

	/**
	 * A product a curator created over a tool the model proposed as an
	 * organization, and that organization, rejected.
	 *
	 * @return list<list<array<string,mixed>>>
	 */
	private static function kestrel_mail(): array {
		return [
			self::entity( 'product:kestrel-mail', 'Kestrel Mail' ),
			self::proposed( 'organization:kestrel-mail', 'Kestrel Mail' ),
			self::rejected( 'organization:kestrel-mail' ),
		];
	}

	/**
	 * Two organizations named Ledger, told apart by their localities.
	 *
	 * @return list<list<array<string,mixed>>>
	 */
	private static function ledgers(): array {
		return [
			self::entity( 'organization:bend-ledger', 'Ledger', [ 'locality' => [ 'Bend' ] ] ),
			self::entity( 'organization:salem-ledger', 'Ledger', [ 'locality' => [ 'Salem' ] ] ),
		];
	}

	/**
	 * An item as `item_recorded` carries it.
	 *
	 * @param array<string,string> $over Fields to set.
	 * @return array<string,mixed>
	 */
	private static function item( array $over ): array {
		return \array_merge(
			[ 'item_key' => 'feed:golden-1', 'source' => 'feed', 'tier' => 'public', 'url' => '', 'title' => '', 'body' => '', 'item_ts' => 1792900000, 'retrieved_ts' => 1793000000 ],
			$over
		);
	}

	/**
	 * One link as resolve() answers it.
	 *
	 * @return array{entity_key:string, role:string, method:string, confidence:float, state:string}
	 */
	private static function link( string $entity_key, string $role, string $method, float $confidence, string $state = 'asserted' ): array {
		return [ 'entity_key' => $entity_key, 'role' => $role, 'method' => $method, 'confidence' => $confidence, 'state' => $state ];
	}
}

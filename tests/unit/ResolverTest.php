<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Resolver;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/seeds-entity-index.php';
require_once __DIR__ . '/../support/fake-entity-extractor.php';

/**
 * The golden set: known items that must link to known entities, and known
 * ones that must not, each resolved against fixture entities projected into
 * the graph. A name two entities share links to both, a name no entity
 * holds becomes a candidate entity, and GitHub and Linear items resolve like
 * any other.
 */
final class ResolverTest extends TestCase {
	use Seeds_Entity_Index;

	private const MESA = 'organization:mesa-courier';

	private const GAZETTE = 'organization:harbor-gazette';

	private const PLAINSFILE = 'organization:atomic-3003';

	private const BAYSHORE = 'organization:atomic-3006';

	private const LANTERN = 'organization:atomic-3009';

	private const KIWILEDGER = 'organization:atomic-3010';

	protected function setUp(): void {
		parent::setUp();
		$this->start_entity_graph();
	}

	protected function tearDown(): void {
		Resolver::$similar_text = null;
		Topology_Registry::reset();
		parent::tearDown();
	}

	public function test_a_domain_url_links_its_entity_as_subject(): void {
		$this->assertSame( [ self::link( self::MESA, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://mesa.news/story/1' ] )['links'] );
	}

	public function test_a_www_or_subdomain_url_links_its_entity(): void {
		$this->assertSame( [ self::link( self::MESA, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://www.mesa.news/x' ] )['links'] );
		$this->assertSame( [ self::link( self::MESA, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://blog.mesa.news/x' ] )['links'] );
	}

	public function test_a_client_domain_url_links_the_client_as_subject(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'identifier', 1.0 ) ],
			$this->golden( [ 'url' => 'https://www.harborgazette.org/2026/10/award', 'title' => 'An award', 'body' => 'First place.' ] )['links']
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

	/** The URL's entity is the subject; another named in the body is a mention. */
	public function test_a_domain_and_a_competing_name_both_link(): void {
		$this->assertSame(
			[ self::link( self::MESA, 'subject', 'identifier', 1.0 ), self::link( self::GAZETTE, 'mention', 'name', 1.0 ) ],
			$this->golden( [ 'url' => 'https://mesa.news/x', 'body' => 'The Harbor Gazette also covered this.' ] )['links']
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

	/** A title capitalizes its first word, so an article-led span cannot vouch for a name. */
	public function test_a_domain_stem_gives_up_on_article_led_names(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'The Citizen announced a new editor', 'url' => 'https://random.example/x' ] )['links'] );
	}

	public function test_a_domain_stem_ignores_a_sentence_initial_the(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'The Citizen Activation System shipped', 'url' => 'https://random.example/x' ] )['links'] );
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

	/** "kxzt.org" reduces to "kxzt", too short to tell from ordinary text. */
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
			'See <a href="https://plainsfile.com/story-x">this piece</a>.',
			'<img src="/img/plainsfile-logo.png" alt="logo">',
			'Send tips to tips@plainsfile.com today.',
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
			$this->golden( [ 'title' => 'Harborviewmediagroup names an editor', 'url' => 'https://random.example/x' ], self::entity( $group, 'Atomic site 3011', [ 'domain' => [ 'newsroom.harborviewmediagroup.com' ] ] ) )['links']
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
		$exact = $this->golden( [ 'url' => 'https://mesa.news/story/1' ] )['links'][0]['confidence'];
		$stem  = $this->golden( [ 'title' => 'PlainsFile wins a national award', 'url' => 'https://elsewhere.example/x' ] )['links'][0]['confidence'];
		$this->assertSame( 1.0, $exact );
		$this->assertLessThan( $exact, $stem );
	}

	/** A rejected entity links by neither its domain nor its name. */
	public function test_a_rejected_entity_never_links(): void {
		$this->assertSame( [], $this->golden( [ 'title' => 'Faded Gazette returns', 'url' => 'https://fadedgazette.com/x' ] )['links'] );
	}

	/** GitHub and Linear items resolve like any other item. */
	public function test_github_and_linear_items_resolve_like_any_other(): void {
		foreach ( [ 'github', 'linear' ] as $source ) {
			$this->assertSame( [ self::link( self::MESA, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'source' => $source, 'url' => 'https://mesa.news/x' ] )['links'], $source );
		}
	}

	public function test_a_repo_url_links_its_product(): void {
		$product = 'product:newspack-plugin';
		$this->assertSame(
			[ self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->golden( [ 'source' => 'github', 'url' => 'https://github.com/Automattic/newspack-plugin/pull/4100', 'title' => 'Fix the checkout' ], self::entity( $product, 'Newspack Plugin', [ 'github_repo' => [ 'Automattic/newspack-plugin' ] ] ) )['links']
		);
	}

	/** An org URL links the org; a repo URL links its org and its repo. */
	public function test_a_github_org_links_by_its_identifier(): void {
		$org     = 'organization:automattic';
		$product = 'product:newspack-plugin';
		$entities = [ ...self::entity( $org, 'Automattic', [ 'github_org' => [ 'Automattic' ] ] ), ...self::entity( $product, 'Newspack Plugin', [ 'github_repo' => [ 'Automattic/newspack-plugin' ] ] ) ];

		$this->assertSame( [ self::link( $org, 'subject', 'identifier', 1.0 ) ], $this->golden( [ 'url' => 'https://github.com/automattic' ], $entities )['links'] );
		$this->assertSame(
			[ self::link( $org, 'subject', 'identifier', 1.0 ), self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->resolver()->resolve( self::item( [ 'url' => 'https://github.com/Automattic/newspack-plugin/issues/9' ] ), null )['links']
		);
	}

	public function test_a_linear_issue_links_its_team_product(): void {
		$product = 'product:newspack';
		$this->assertSame(
			[ self::link( $product, 'subject', 'identifier', 1.0 ) ],
			$this->golden( [ 'source' => 'linear', 'url' => 'https://linear.app/a8c/issue/NEWS-2731/churn-dates-from-hubspot', 'title' => 'Churn dates' ], self::entity( $product, 'Newspack', [ 'linear_team' => [ 'NEWS' ] ] ) )['links']
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
			[ self::link( 'organization:salem-ledger', 'subject', 'identifier', 1.0, 'proposed' ) ],
			$this->resolver()->resolve( self::item( [ 'url' => 'https://salemledger.example/a' ] ), null )['links']
		);
	}

	/** A proposed entity's fuzzy match is proposed even above the asserting band. */
	public function test_a_proposed_entity_is_never_asserted_by_ner(): void {
		$index = $this->index_of( self::proposed( 'organization:salem-ledger', 'Salem Ledger' ) );

		$this->assertSame(
			[ self::link( 'organization:salem-ledger', 'subject', 'ner_fuzzy', 1.0, 'proposed' ) ],
			( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Regional merger', 'url' => 'https://random.example/x' ] ), new Fake_Entity_Extractor( [ 'Salem Ledger' ] ) )['links']
		);
	}

	/** A stem's span is the longest, so the name inside it drops: one span, one entity. */
	public function test_a_name_inside_a_stem_span_drops(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-3014', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'Bay Shore Report cuts staff', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:atomic-3014', 'Atomic site 3014', [ 'domain' => [ 'bayshorereport.com' ] ] ),
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
			[ self::link( 'organization:seattle-times-media', 'subject', 'name', 0.5, 'proposed' ), self::link( 'organization:times-media-group', 'subject', 'name', 0.5, 'proposed' ) ],
			$this->resolve(
				[ 'title' => 'Seattle Times Media Group expands', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:seattle-times-media', 'Seattle Times Media' ),
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
			[ self::link( 'organization:the-seattle-times', 'subject', 'name', 1.0 ) ],
			$this->resolve(
				[ 'title' => 'The Seattle Times wins a Pulitzer', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:the-times', 'The Times' ),
				self::entity( 'organization:the-seattle-times', 'The Seattle Times' )
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
				self::entity( 'organization:atomic-3012', 'Atomic site 3012', [ 'domain' => [ 'sentinel.com' ] ] )
			)['links']
		);
	}

	/** Two stems on one surface are settled by a locality the text names. */
	public function test_a_locality_settles_a_stem_ambiguity(): void {
		$this->assertSame(
			[ self::link( 'organization:atomic-3013', 'subject', 'domain_stem', 0.9 ) ],
			$this->resolve(
				[ 'title' => 'Sentinel names a new editor', 'body' => 'The Salem paper announced it Monday.', 'url' => 'https://random.example/x' ],
				self::entity( 'organization:atomic-3012', 'Atomic site 3012', [ 'domain' => [ 'sentinel.com' ], 'locality' => [ 'Bend' ] ] ),
				self::entity( 'organization:atomic-3013', 'Atomic site 3013', [ 'domain' => [ 'sentinel.net' ], 'locality' => [ 'Salem' ] ] )
			)['links']
		);
	}

	/** A locality alone names no entity. */
	public function test_a_locality_alone_links_nothing(): void {
		$this->assertSame( [], $this->resolve( [ 'title' => 'Salem council meets', 'url' => 'https://random.example/x' ], ...self::ledgers() )['links'] );
	}

	public function test_an_acronym_needs_its_exact_case(): void {
		$lion     = 'organization:lion-publishers';
		$entities = self::entity( $lion, 'Local Independent Online News Publishers', [ 'acronym' => [ 'LION' ] ] );

		$this->assertSame( [ self::link( $lion, 'subject', 'acronym', 0.9 ) ], $this->resolve( [ 'title' => 'LION summit opens', 'url' => 'https://random.example/x' ], $entities )['links'] );
		$this->assertSame( [], $this->resolver()->resolve( self::item( [ 'title' => 'A lion escapes the zoo', 'url' => 'https://random.example/x' ] ), null )['links'] );
	}

	public function test_ner_exact_org_asserts(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 1.0 ) ],
			$this->golden( [ 'title' => 'Nonprofit newsrooms expand', 'url' => 'https://elsewhere.example/x' ], [], new Fake_Entity_Extractor( [ 'The Harbor Gazette' ] ) )['links']
		);
	}

	/** "Harbor Gazette" against "The Harbor Gazette": the leading article is dropped. */
	public function test_ner_close_variant_asserts(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 1.0 ) ],
			$this->golden( [ 'title' => 'Coverage roundup', 'url' => 'https://elsewhere.example/x' ], [], new Fake_Entity_Extractor( [ 'Harbor Gazette' ] ) )['links']
		);
	}

	/** An organization matching nothing becomes a candidate entity, not a drop. */
	public function test_ner_unrelated_org_becomes_a_proposal(): void {
		$this->assertSame(
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Acme Widgets Corporation' ] ], 'recognized' => true ],
			$this->golden( [ 'title' => 'Factory opens', 'url' => 'https://elsewhere.example/x' ], [], new Fake_Entity_Extractor( [ 'Acme Widgets Corporation' ] ) )
		);
	}

	public function test_ner_midband_proposes_a_link(): void {
		$this->assertSame(
			[ self::link( self::GAZETTE, 'subject', 'ner_fuzzy', 0.8, 'proposed' ) ],
			$this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], new Fake_Entity_Extractor( [ 'Harbor Gazette Weekly' ] ), 0.99, 0.30 )['links']
		);
	}

	public function test_ner_bands_assert_propose_and_candidate(): void {
		$extractor = new Fake_Entity_Extractor( [ 'Salem Ledg', 'Harbor Gazette Foundation', 'Acme Widgets Corporation' ] );
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
			( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Regional publishers announce a merger', 'url' => 'https://random.example/x' ] ), $extractor )
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

		$exact = ( new Resolver( $index ) )->resolve( $item, new Fake_Entity_Extractor( [ 'Quarry Ledger' ] ) );
		$this->assertSame( [ self::link( 'organization:quarry-ledger', 'subject', 'ner_fuzzy', 1.0 ) ], $exact['links'] );
		$this->assertSame( [], $compared, 'an exact norm needs no scan' );

		$near = ( new Resolver( $index ) )->resolve( $item, new Fake_Entity_Extractor( [ 'Quarry Ledgr' ] ) );
		$this->assertSame( [ 'organization:quarry-ledger' ], \array_column( $near['links'], 'entity_key' ) );
		$this->assertSame( [ 'quarry ledger' ], $compared, 'neither a 2-byte nor a 39-byte norm can reach 0.60 against 12 bytes' );
	}

	/** NER that answered no organization links nothing, and is recognized as having answered. */
	public function test_ner_no_orgs_links_nothing(): void {
		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => true ], $this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], new Fake_Entity_Extractor( [] ) ) );
	}

	/** A NER call that failed links and proposes nothing, and is not recognized. */
	public function test_a_failed_ner_call_is_not_recognized(): void {
		$extractor = new Fake_Entity_Extractor( null );

		$this->assertSame( [ 'links' => [], 'proposals' => [], 'recognized' => false ], $this->golden( [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], [], $extractor ) );
		$this->assertSame( 1, $extractor->calls );
	}

	/** A deterministic link spares the model call, so NER is not recognized. */
	public function test_a_deterministic_link_skips_the_extractor(): void {
		$extractor = new Fake_Entity_Extractor( [ 'The Harbor Gazette' ] );
		$this->assertFalse( $this->golden( [ 'url' => 'https://mesa.news/x' ], [], $extractor )['recognized'] );
		$this->assertSame( 0, $extractor->calls );
	}

	public function test_a_rejected_name_is_never_proposed_again(): void {
		$index = $this->index_of(
			self::proposed( 'organization:acme-widgets-corporation', 'Acme Widgets Corporation' ),
			self::rejected( 'organization:acme-widgets-corporation' )
		);

		$this->assertSame(
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Portland Timber Union' ] ], 'recognized' => true ],
			( new Resolver( $index ) )->resolve( self::item( [ 'title' => 'Factory opens', 'url' => 'https://random.example/x' ] ), new Fake_Entity_Extractor( [ 'Acme Widgets Corporation', 'Portland Timber Union' ] ) )
		);
	}

	public function test_no_more_than_three_proposals_per_item(): void {
		$extractor = new Fake_Entity_Extractor( [ 'Acme Widgets Corporation', 'acme widgets corporation', 'Portland Timber Union', 'Pine Valley Radio', 'River City Times', 'Coastal Ferry Lines' ] );

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
			[ 'links' => [], 'proposals' => [ [ 'type' => 'organization', 'name' => 'Harbor Gazette' ] ], 'recognized' => true ],
			( new Resolver( new Entity_Index() ) )->resolve( self::item( [ 'url' => 'https://harborgazette.org/x', 'title' => 'Harbor Gazette' ] ), new Fake_Entity_Extractor( [ 'Harbor Gazette' ] ) )
		);
	}

	/** The resolver built over the last index the test seeded. */
	private ?Resolver $last = null;

	/**
	 * Resolve an item against the golden fixtures plus any extra entities.
	 *
	 * @param array<string,string>      $over      The item's fields.
	 * @param list<array<string,mixed>> $extra     More entity events.
	 * @param Fake_Entity_Extractor|null $extractor The NER seam.
	 * @param float                     $assert_at The asserting band.
	 * @param float                     $propose_at The proposing band.
	 * @return array{links: list<array<string,mixed>>, proposals: list<array<string,string>>}
	 */
	private function golden( array $over, array $extra = [], ?Fake_Entity_Extractor $extractor = null, float $assert_at = 0.85, float $propose_at = 0.60 ): array {
		$this->last = new Resolver( $this->index_of( self::golden_entities(), $extra ), $assert_at, $propose_at );
		return $this->last->resolve( self::item( $over ), $extractor );
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
		return $this->last->resolve( self::item( $over ), null );
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
			...self::entity( self::MESA, 'Mesa Courier', [ 'alias' => [ 'MDC', 'Mesa Daily Courier' ], 'domain' => [ 'mesa.news' ] ] ),
			...self::entity( self::GAZETTE, 'The Harbor Gazette', [ 'alias' => [ 'HarbGaz' ], 'domain' => [ 'harborgazette.org' ] ] ),
			...self::proposed( 'organization:faded-gazette', 'Faded Gazette', [ 'domain' => [ 'fadedgazette.com' ] ] ),
			...self::rejected( 'organization:faded-gazette' ),
			...$atomic( 3003, 'plainsfile.com' ),
			...$atomic( 3004, 'tidewater.co.nz' ),
			...$atomic( 3005, 'thecitizen.com' ),
			...$atomic( 3006, 'bayshorereport.org' ),
			...$atomic( 3007, 'kxzt.org' ),
			...$atomic( 3009, 'lantern.com' ),
			...$atomic( 3010, 'kiwiledger.co.nz' ),
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

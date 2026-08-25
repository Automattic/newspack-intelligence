<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use PHPUnit\Framework\TestCase;
use Newspack_Intelligence\Publisher_Matcher;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/fake-entity-extractor.php';

final class PublisherMatcherTest extends TestCase {

	private function repo(): Fake_Publisher_Repository {
		$repo             = new Fake_Publisher_Repository();
		$repo->store['1'] = [ 'atomic_site_id' => '1', 'domain_name' => 'abq.news', 'status' => 'active', 'publisher_name' => 'ABQ News', 'aliases' => 'ABQ|Albuquerque News' ];
		$repo->store['2'] = [ 'atomic_site_id' => '2', 'domain_name' => 'texastribune.org', 'status' => 'active', 'publisher_name' => 'The Texas Tribune', 'aliases' => 'TexTrib' ];
		$repo->store['9'] = [ 'atomic_site_id' => '9', 'domain_name' => 'gone.com', 'status' => 'churned', 'publisher_name' => 'Gone Gazette', 'aliases' => '' ];
		// As Client_Importer actually leaves a row: domain only, no enrichment.
		$repo->store['3'] = [ 'atomic_site_id' => '3', 'domain_name' => 'wyofile.com', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		// Subdomained + multi-part TLD: stem "newsroomco", which lurks inside "newsroom co...".
		$repo->store['4'] = [ 'atomic_site_id' => '4', 'domain_name' => 'newsroom.co.nz', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		// Real publisher whose stem also spells an ordinary phrase: "the Reader".
		$repo->store['5'] = [ 'atomic_site_id' => '5', 'domain_name' => 'thereader.com', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		$repo->store['6'] = [ 'atomic_site_id' => '6', 'domain_name' => 'fortworthreport.org', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		// Unenriched and short-stemmed: "kcur" is too generic to match on.
		$repo->store['7'] = [ 'atomic_site_id' => '7', 'domain_name' => 'kcur.org', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		// Single-word stem that is also an ordinary English word.
		$repo->store['8'] = [ 'atomic_site_id' => '8', 'domain_name' => 'sentinel.com', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		// Multi-part TLD: the registrable name is "newsroomnz", not "newsroom.co".
		$repo->store['10'] = [ 'atomic_site_id' => '10', 'domain_name' => 'newsroomnz.co.nz', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		return $repo;
	}

	/**
	 * @param array<string,string> $over
	 * @return array<string,string>
	 */
	private function item( array $over = [] ): array {
		return \array_merge(
			[ 'source' => 'feed', 'id' => 'i1', 'title' => '', 'url' => '', 'body' => '', 'timestamp' => '2026-07-14' ],
			$over
		);
	}

	/**
	 * @param array<string,string> $over
	 * @return array<string,mixed>
	 */
	private function match( array $over ): array {
		return ( new Publisher_Matcher( $this->repo(), 'csv-2026-07-14' ) )->match( $this->item( $over ) );
	}

	public function test_domain_exact_passes(): void {
		$d = $this->match( [ 'url' => 'https://abq.news/story/1' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '1', $d['atomic_site_id'] );
		$this->assertSame( 'domain', $d['matched_on'] );
		$this->assertSame( 'csv-2026-07-14', $d['config_version'] );
		$this->assertSame( 'gate', $d['stage'] );
		$this->assertSame( 'i1', $d['item_id'] );
	}

	public function test_domain_www_and_subdomain_pass(): void {
		$this->assertSame( '1', $this->match( [ 'url' => 'https://www.abq.news/x' ] )['atomic_site_id'] );
		$this->assertSame( '1', $this->match( [ 'url' => 'https://blog.abq.news/x' ] )['atomic_site_id'] );
	}

	public function test_name_match_passes(): void {
		$d = $this->match( [ 'title' => 'The Texas Tribune wins an award', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '2', $d['atomic_site_id'] );
		$this->assertSame( 'name', $d['matched_on'] );
	}

	public function test_alias_match_passes(): void {
		$d = $this->match( [ 'body' => 'Reported first by TexTrib staff.', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '2', $d['atomic_site_id'] );
		$this->assertSame( 'alias', $d['matched_on'] );
	}

	public function test_domain_beats_competing_name(): void {
		// URL is ABQ's domain; body names the Texas Tribune. Domain wins.
		$d = $this->match( [ 'url' => 'https://abq.news/x', 'body' => 'The Texas Tribune also covered this.' ] );
		$this->assertSame( '1', $d['atomic_site_id'] );
		$this->assertSame( 'domain', $d['matched_on'] );
	}

	public function test_ambiguous_multiple_names_holds(): void {
		$d = $this->match( [ 'title' => 'ABQ News and The Texas Tribune both reported', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_no_signal_holds(): void {
		$d = $this->match( [ 'title' => 'A local bake sale', 'url' => 'https://random.example/x', 'body' => 'Nothing relevant here.' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertSame( 'no deterministic signal', $d['reason'] );
	}

	public function test_word_boundary_prevents_substring_false_positive(): void {
		// "ABQ" must not match inside "ABQUERQUEXYZ".
		$d = $this->match( [ 'title' => 'ABQUERQUEXYZ launch', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_domain_stem_matches_publisher_with_no_enrichment(): void {
		// The importer writes no publisher_name/aliases, so name and alias signals are dead.
		// The domain itself still names the publisher: wyofile.com -> "WyoFile" in prose.
		$d = $this->match( [ 'title' => 'WyoFile wins a national award', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '3', $d['atomic_site_id'] );
		$this->assertSame( 'domain_stem', $d['matched_on'] );
	}

	public function test_domain_stem_requires_word_boundaries(): void {
		// "Sentinelese" opens with the stem "sentinel" and is capitalized, so it clears the
		// casing rule; only the boundary check can reject a hit that ends mid-word.
		$d = $this->match( [ 'title' => 'Sentinelese language study published', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_domain_stem_spanning_words_requires_name_casing(): void {
		// "thereader" is welded out of the ordinary phrase "the Reader". A publisher mention
		// reads as a name, so a span whose words are not all capitalized is not one.
		$d = $this->match( [ 'title' => 'Improvements to the Reader Activation System shipped', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_domain_stem_gives_up_on_article_led_names(): void {
		// A genuine mention of thereader.com is spelled exactly like the opening of
		// "The Reader Activation System ...", so the stem signal cannot separate them and
		// declines both. The publisher stays reachable through publisher_name / aliases.
		$d = $this->match( [ 'title' => 'The Reader announced a new editor', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_domain_stem_spanning_words_matches_a_real_name(): void {
		// Welding across words is still the point, when the span carries its own evidence.
		$d = $this->match( [ 'title' => 'Fort Worth Report expands coverage', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '6', $d['atomic_site_id'] );
	}

	public function test_domain_stem_matches_across_the_spacing_prose_uses(): void {
		// The headline capability: "fortworthreport" is spelled "Fort Worth Report" in prose.
		$d = $this->match( [ 'title' => 'Fort Worth Report expands its education desk', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '6', $d['atomic_site_id'] );
		$this->assertSame( 'domain_stem:Fort Worth Report', $d['reason'] );
	}

	public function test_exact_name_beats_domain_stem(): void {
		// Enrichment is the stronger signal, so it must resolve before the derived one.
		$d = $this->match( [ 'title' => 'ABQ News partners with WyoFile', 'url' => 'https://random.example/x' ] );
		$this->assertSame( '1', $d['atomic_site_id'] );
		$this->assertSame( 'name', $d['matched_on'] );
	}

	public function test_two_competing_stems_hold(): void {
		$d = $this->match( [ 'title' => 'WyoFile and Fort Worth Report co-publish', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_short_domain_stem_is_not_used(): void {
		// "kcur.org" reduces to "kcur" — too short to tell from ordinary text, so it is dropped.
		$d = $this->match( [ 'title' => 'Reported by KCUR staff', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_domain_stem_ignores_sentence_initial_the(): void {
		// "The Reader Activation System" opens a sentence, so every word is capitalized and a
		// casing rule alone cannot tell it from a name. The product phrase must not attribute.
		$d = $this->match( [ 'title' => 'The Reader Activation System shipped', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_domain_stem_requires_casing_on_single_word_spans(): void {
		// A lone lowercase common word is prose, not a publisher mention.
		$d = $this->match( [ 'title' => 'the sentinel building downtown', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_domain_stem_still_matches_a_capitalized_single_word(): void {
		$d = $this->match( [ 'title' => 'Sentinel names a new editor', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '8', $d['atomic_site_id'] );
	}

	public function test_domain_stem_ignores_the_body(): void {
		// Bodies are raw RSS description / Atom content: an href, a logo filename or an email
		// address containing a client domain must not attribute the item to that client.
		$bodies = [
			'See <a href="https://wyofile.com/story-x">this piece</a>.',
			'<img src="/img/wyofile-logo.png" alt="logo">',
			'Send tips to tips@wyofile.com today.',
		];
		foreach ( $bodies as $body ) {
			$d = $this->match( [ 'title' => 'Media roundup', 'body' => $body, 'url' => 'https://random.example/x' ] );
			$this->assertSame( 'hold', $d['decision'], $body );
			$this->assertNull( $d['atomic_site_id'], $body );
		}
	}

	public function test_domain_stem_handles_multi_part_tlds(): void {
		// "newsroomnz.co.nz" must reduce to the registrable name, not to "newsroomnzco".
		$d = $this->match( [ 'title' => 'Newsroomnz published a scoop', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '10', $d['atomic_site_id'] );
	}

	public function test_domain_stem_uses_the_registrable_label_of_a_subdomain(): void {
		// "newsroom.embarcaderomediagroup.com" names the group, not the generic first label.
		$repo             = $this->repo();
		$repo->store['11'] = [ 'atomic_site_id' => '11', 'domain_name' => 'newsroom.embarcaderomediagroup.com', 'status' => 'active', 'publisher_name' => '', 'aliases' => '' ];
		$m                = new Publisher_Matcher( $repo, 'v' );
		$d                = $m->match( $this->item( [ 'title' => 'Embarcaderomediagroup names an editor', 'url' => 'https://random.example/x' ] ) );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '11', $d['atomic_site_id'] );
	}

	public function test_domain_stem_survives_multi_codepoint_lowercasing(): void {
		// mb_strtolower() on U+0130 yields TWO codepoints. If the offset map is built per
		// character it desyncs from the squashed haystack, dropping a legitimate match.
		$d = $this->match( [ 'title' => "\u{130} Fort Worth Report expands coverage", 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '6', $d['atomic_site_id'] );
	}

	public function test_domain_stem_reports_a_lower_confidence_than_an_exact_match(): void {
		// A welded, guard-approximated substring is not the same evidence as a URL that IS
		// the publisher's domain. The decision log has to be able to tell them apart.
		$exact = $this->match( [ 'url' => 'https://abq.news/story/1' ] );
		$stem  = $this->match( [ 'title' => 'WyoFile wins a national award', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 1.0, $exact['confidence'] );
		$this->assertSame( 'pass', $stem['decision'] );
		$this->assertLessThan( $exact['confidence'], $stem['confidence'] );
	}

	public function test_churned_publisher_never_matches(): void {
		$d = $this->match( [ 'title' => 'Gone Gazette returns', 'url' => 'https://gone.com/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_github_and_linear_bypass(): void {
		foreach ( [ 'github', 'linear' ] as $src ) {
			$d = $this->match( [ 'source' => $src, 'url' => 'https://abq.news/x' ] );
			$this->assertSame( 'bypass', $d['decision'], $src );
			$this->assertNull( $d['atomic_site_id'] );
		}
	}

	/**
	 * @param array<string,string> $over
	 * @return array<string,mixed>
	 */
	private function matchWith( Fake_Entity_Extractor $ex, array $over, float $pass = 0.85, float $ignore = 0.60 ): array {
		return ( new Publisher_Matcher( $this->repo(), 'csv-2026-07-15', $ex, $pass, $ignore ) )->match( $this->item( $over ) );
	}

	public function test_ner_exact_org_passes(): void {
		$d = $this->matchWith( new Fake_Entity_Extractor( [ 'The Texas Tribune' ] ), [ 'title' => 'Nonprofit newsrooms expand', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '2', $d['atomic_site_id'] );
		$this->assertSame( 'ner', $d['matched_on'] );
		$this->assertSame( 1.0, $d['confidence'] );
	}

	public function test_ner_close_variant_passes(): void {
		// "Texas Tribune" vs stored "The Texas Tribune" — leading "the" dropped in normalization.
		$d = $this->matchWith( new Fake_Entity_Extractor( [ 'Texas Tribune' ] ), [ 'title' => 'Coverage roundup', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'pass', $d['decision'] );
		$this->assertSame( '2', $d['atomic_site_id'] );
	}

	public function test_ner_unrelated_org_ignores(): void {
		$d = $this->matchWith( new Fake_Entity_Extractor( [ 'Acme Widgets Corporation' ] ), [ 'title' => 'Factory opens', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'ignore', $d['decision'] );
		$this->assertNull( $d['atomic_site_id'] );
	}

	public function test_ner_midband_holds(): void {
		// Pin a narrow band so a partial overlap lands in [ignore,pass).
		$d = $this->matchWith( new Fake_Entity_Extractor( [ 'Texas Daily Chronicle' ] ), [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ], 0.99, 0.30 );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_ner_no_orgs_holds(): void {
		$d = $this->matchWith( new Fake_Entity_Extractor( [] ), [ 'title' => 'x', 'url' => 'https://elsewhere.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}

	public function test_deterministic_hit_does_not_call_extractor(): void {
		$ex = new Fake_Entity_Extractor( [ 'The Texas Tribune' ] );
		( new Publisher_Matcher( $this->repo(), 'v', $ex ) )->match( $this->item( [ 'url' => 'https://abq.news/x' ] ) );
		$this->assertSame( 0, $ex->calls );
	}

	public function test_no_extractor_falls_back_to_hold(): void {
		// Slice-1 regression: a deterministic miss with no extractor still holds.
		$d = $this->match( [ 'title' => 'A local bake sale', 'url' => 'https://random.example/x' ] );
		$this->assertSame( 'hold', $d['decision'] );
	}
}

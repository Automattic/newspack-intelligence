<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Publisher_Matcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/fake-publisher-repository.php';
require_once __DIR__ . '/../support/fake-entity-extractor.php';

final class PublisherMatcherTest extends TestCase {

	private function repo(): Fake_Publisher_Repository {
		$repo             = new Fake_Publisher_Repository();
		$repo->store['1'] = [ 'atomic_site_id' => '1', 'domain_name' => 'abq.news', 'status' => 'active', 'publisher_name' => 'ABQ News', 'aliases' => 'ABQ|Albuquerque News' ];
		$repo->store['2'] = [ 'atomic_site_id' => '2', 'domain_name' => 'texastribune.org', 'status' => 'active', 'publisher_name' => 'The Texas Tribune', 'aliases' => 'TexTrib' ];
		$repo->store['9'] = [ 'atomic_site_id' => '9', 'domain_name' => 'gone.com', 'status' => 'churned', 'publisher_name' => 'Gone Gazette', 'aliases' => '' ];
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

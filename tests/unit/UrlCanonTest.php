<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Url_Canon;
use Newspack_Nodes\Tests\TestCase;

/**
 * One host spelling: lowercase, its leading `www.` dropped, shared by the
 * canonical URL, a domain identifier and the resolver's host walk.
 */
final class UrlCanonTest extends TestCase {

	public function test_a_host_is_lowercased_without_its_www(): void {
		$this->assertSame(
			[ 'harbor-gazette.example', 'wwwharbor.example', 'www.quarry.example', 'mesa.example' ],
			[ Url_Canon::host( 'WWW.Harbor-Gazette.EXAMPLE' ), Url_Canon::host( 'WWWHarbor.example' ), Url_Canon::host( 'www.WWW.Quarry.example' ), Url_Canon::host( 'Mesa.Example' ) ]
		);
	}

	public function test_a_domain_identifier_and_a_canonical_url_spell_the_host_alike(): void {
		$this->assertSame( 'harbor-gazette.example', Entity_Index::identifier_value( 'domain', ' WWW.Harbor-Gazette.EXAMPLE ' ) );
		$this->assertSame( 'harbor-gazette.example/a', Url_Canon::of( 'https://WWW.Harbor-Gazette.EXAMPLE/a/' ) );
		$this->assertSame( 'www.harbgaznews', Entity_Index::identifier_value( 'x_handle', ' WWW.HarbGazNews ' ), 'only a domain loses its www.' );
	}
}

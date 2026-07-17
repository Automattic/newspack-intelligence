<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Publisher_CPT;
use Newspack_Intelligence\Publisher_Meta_Box;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../support/wp-post-stubs.php';

final class PublisherMetaBoxTest extends TestCase {

	protected function setUp(): void {
		\NPAINL_WP_Post_Store::reset();
	}

	public function test_enrichment_fields_returns_exactly_the_seven_enrichment_keys(): void {
		$fields = Publisher_Meta_Box::enrichment_fields();

		$this->assertSame(
			[
				Publisher_CPT::META_PUBLISHER_NAME,
				Publisher_CPT::META_LOCALITIES,
				Publisher_CPT::META_GITHUB_ORG,
				Publisher_CPT::META_LINKEDIN_ID,
				Publisher_CPT::META_X_HANDLE,
				Publisher_CPT::META_ALIASES,
				Publisher_CPT::META_BEAT_TAGS,
			],
			\array_keys( $fields )
		);

		$import_managed = [
			Publisher_CPT::META_ATOMIC_ID,
			Publisher_CPT::META_DOMAIN,
			Publisher_CPT::META_CREATED,
			Publisher_CPT::META_STATUS,
			Publisher_CPT::META_FIRST_SEEN,
			Publisher_CPT::META_LAST_SEEN,
			Publisher_CPT::META_CHURNED_AT,
		];
		foreach ( $import_managed as $key ) {
			$this->assertArrayNotHasKey( $key, $fields );
		}
	}

	public function test_persist_writes_each_provided_enrichment_field(): void {
		$post_id = 101;
		\NPAINL_WP_Post_Store::$meta[ $post_id ] = [];

		$raw = [
			Publisher_CPT::META_PUBLISHER_NAME => 'Acme Times',
			Publisher_CPT::META_LOCALITIES     => 'Boston|Cambridge',
			Publisher_CPT::META_GITHUB_ORG     => 'acme-times',
			Publisher_CPT::META_LINKEDIN_ID    => '12345',
			Publisher_CPT::META_X_HANDLE       => '@acmetimes',
			Publisher_CPT::META_ALIASES        => 'Acme|The Acme Times',
			Publisher_CPT::META_BEAT_TAGS      => 'local|politics',
		];

		Publisher_Meta_Box::persist( $post_id, $raw );

		foreach ( $raw as $key => $value ) {
			$this->assertSame( $value, \get_post_meta( $post_id, $key, true ) );
		}
	}

	public function test_persist_never_writes_import_managed_meta(): void {
		$post_id = 102;
		\NPAINL_WP_Post_Store::$meta[ $post_id ] = [];

		$raw = [
			Publisher_CPT::META_PUBLISHER_NAME => 'Acme Times',
			Publisher_CPT::META_STATUS         => 'churned',
			Publisher_CPT::META_DOMAIN         => 'evil.com',
		];

		Publisher_Meta_Box::persist( $post_id, $raw );

		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_STATUS, true ) );
		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_DOMAIN, true ) );
		$this->assertSame( 'Acme Times', \get_post_meta( $post_id, Publisher_CPT::META_PUBLISHER_NAME, true ) );
	}

	public function test_persist_sanitizes_stripping_tags(): void {
		$post_id = 103;
		\NPAINL_WP_Post_Store::$meta[ $post_id ] = [];

		$raw = [
			Publisher_CPT::META_PUBLISHER_NAME => '<script>alert(1)</script>Acme',
		];

		Publisher_Meta_Box::persist( $post_id, $raw );

		$stored = \get_post_meta( $post_id, Publisher_CPT::META_PUBLISHER_NAME, true );
		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertSame( 'alert(1)Acme', $stored );
	}
}

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
		$GLOBALS['_current_user_can'] = true;
		unset( $_POST );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['_current_user_can'], $_POST );
	}

	/** Seed a publisher post of the correct type and return its id. */
	private function seed_publisher(): int {
		$id                                 = 501;
		\NPAINL_WP_Post_Store::$posts[ $id ] = [ 'post_type' => Publisher_CPT::POST_TYPE, 'post_title' => 'x' ];
		\NPAINL_WP_Post_Store::$meta[ $id ]  = [];
		return $id;
	}

	public function test_save_persists_enrichment_fields_with_valid_nonce_and_caps(): void {
		$post_id                     = $this->seed_publisher();
		$GLOBALS['_current_user_can'] = true;
		$_POST                       = [
			Publisher_Meta_Box::NONCE_NAME     => \wp_create_nonce( Publisher_Meta_Box::NONCE_ACTION ),
			Publisher_CPT::META_GITHUB_ORG     => 'santa-fe-reporter',
			Publisher_CPT::META_LOCALITIES     => 'Santa Fe|Taos',
		];

		Publisher_Meta_Box::save( $post_id );

		$this->assertSame( 'santa-fe-reporter', \get_post_meta( $post_id, Publisher_CPT::META_GITHUB_ORG, true ) );
		$this->assertSame( 'Santa Fe|Taos', \get_post_meta( $post_id, Publisher_CPT::META_LOCALITIES, true ) );
	}

	public function test_save_bails_when_nonce_absent(): void {
		$post_id = $this->seed_publisher();
		$_POST   = [ Publisher_CPT::META_GITHUB_ORG => 'should-not-persist' ];

		Publisher_Meta_Box::save( $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_GITHUB_ORG, true ) );
	}

	public function test_save_bails_on_invalid_nonce(): void {
		$post_id = $this->seed_publisher();
		$_POST   = [
			Publisher_Meta_Box::NONCE_NAME => 'forged-nonce-value',
			Publisher_CPT::META_GITHUB_ORG => 'should-not-persist',
		];

		Publisher_Meta_Box::save( $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_GITHUB_ORG, true ) );
	}

	public function test_save_bails_when_capability_missing(): void {
		$post_id                     = $this->seed_publisher();
		$GLOBALS['_current_user_can'] = false;
		$_POST                       = [
			Publisher_Meta_Box::NONCE_NAME => \wp_create_nonce( Publisher_Meta_Box::NONCE_ACTION ),
			Publisher_CPT::META_GITHUB_ORG => 'should-not-persist',
		];

		Publisher_Meta_Box::save( $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_GITHUB_ORG, true ) );
	}

	public function test_save_bails_when_post_type_mismatches(): void {
		$post_id                             = 777;
		\NPAINL_WP_Post_Store::$posts[ $post_id ] = [ 'post_type' => 'page', 'post_title' => 'x' ];
		\NPAINL_WP_Post_Store::$meta[ $post_id ]  = [];
		$_POST                               = [
			Publisher_Meta_Box::NONCE_NAME => \wp_create_nonce( Publisher_Meta_Box::NONCE_ACTION ),
			Publisher_CPT::META_GITHUB_ORG => 'should-not-persist',
		];

		Publisher_Meta_Box::save( $post_id );

		$this->assertSame( '', \get_post_meta( $post_id, Publisher_CPT::META_GITHUB_ORG, true ) );
	}

	public function test_render_outputs_editable_and_readonly_fields(): void {
		$post_id                                                                = $this->seed_publisher();
		\NPAINL_WP_Post_Store::$meta[ $post_id ][ Publisher_CPT::META_GITHUB_ORG ] = 'roswell-daily';
		\NPAINL_WP_Post_Store::$meta[ $post_id ][ Publisher_CPT::META_DOMAIN ]     = 'roswell.example';

		\ob_start();
		Publisher_Meta_Box::render( new \WP_Post( $post_id ) );
		$html = (string) \ob_get_clean();

		$this->assertStringContainsString( 'name="' . Publisher_CPT::META_GITHUB_ORG . '"', $html );
		$this->assertStringContainsString( 'value="roswell-daily"', $html );
		$this->assertStringContainsString( 'Import-managed fields', $html );
		$this->assertStringContainsString( 'readonly="readonly"', $html );
		$this->assertStringContainsString( 'value="roswell.example"', $html );
	}

	public function test_readonly_fields_returns_exactly_the_seven_import_managed_keys(): void {
		$this->assertSame(
			[
				Publisher_CPT::META_ATOMIC_ID,
				Publisher_CPT::META_DOMAIN,
				Publisher_CPT::META_CREATED,
				Publisher_CPT::META_STATUS,
				Publisher_CPT::META_FIRST_SEEN,
				Publisher_CPT::META_LAST_SEEN,
				Publisher_CPT::META_CHURNED_AT,
			],
			\array_keys( Publisher_Meta_Box::readonly_fields() )
		);
	}

	public function test_register_adds_the_publisher_enrichment_meta_box(): void {
		Publisher_Meta_Box::register();

		$this->assertArrayHasKey( 'newspack-publisher-enrichment', \NPAINL_WP_Post_Store::$meta_boxes );
		$box = \NPAINL_WP_Post_Store::$meta_boxes['newspack-publisher-enrichment'];
		$this->assertSame( Publisher_CPT::POST_TYPE, $box['screen'] );
		$this->assertSame( [ Publisher_Meta_Box::class, 'render' ], $box['callback'] );
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

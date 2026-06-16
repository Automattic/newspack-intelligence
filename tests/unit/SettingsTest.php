<?php
/**
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Settings;
use Newspack_Nodes\Config_System\Field;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	public function test_declares_ai_and_secret_fields(): void {
		$keys = \array_map( static fn ( Field $f ): string => $f->key, Settings::fields() );
		foreach (
			[
				'ai_proxy_base_url',
				'ai_proxy_token',
				'ai_model',
				'ai_feature',
				'github_token',
				'linear_token',
				'feeds',
				'digest_schedule',
				'relevance_profile',
			] as $k
		) {
			$this->assertContains( $k, $keys, "missing field $k" );
		}
	}

	public function test_secret_fields_are_marked_secret(): void {
		$by_key = [];
		foreach ( Settings::fields() as $f ) {
			$by_key[ $f->key ] = $f;
		}
		foreach ( [ 'ai_proxy_token', 'github_token', 'linear_token' ] as $k ) {
			$this->assertTrue( ! empty( $by_key[ $k ]->register_args['secret'] ), "$k should be secret" );
		}
	}

	public function test_non_secret_fields_are_not_marked_secret(): void {
		$by_key = [];
		foreach ( Settings::fields() as $f ) {
			$by_key[ $f->key ] = $f;
		}
		foreach ( [ 'ai_proxy_base_url', 'ai_model', 'ai_feature', 'feeds' ] as $k ) {
			$this->assertTrue( empty( $by_key[ $k ]->register_args['secret'] ), "$k should not be secret" );
		}
	}

	public function test_defaults_for_ai_proxy_fields(): void {
		$by_key = [];
		foreach ( Settings::fields() as $f ) {
			$by_key[ $f->key ] = $f;
		}
		$this->assertSame(
			'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1',
			$by_key['ai_proxy_base_url']->register_args['default'] ?? null
		);
		$this->assertSame( 'gpt-oss-120b', $by_key['ai_model']->register_args['default'] ?? null );
		$this->assertSame( 'newspack-ai-newsletter', $by_key['ai_feature']->register_args['default'] ?? null );
	}

	public function test_get_returns_declared_default_when_option_unset(): void {
		delete_option( 'newspack_ai_newsletter_ai_model' );
		$this->assertSame( 'gpt-oss-120b', Settings::get( 'ai_model' ) );
	}

	public function test_get_returns_stored_option_over_default(): void {
		update_option( 'newspack_ai_newsletter_ai_model', 'llama3-70b' );
		$this->assertSame( 'llama3-70b', Settings::get( 'ai_model' ) );
		delete_option( 'newspack_ai_newsletter_ai_model' );
	}

	public function test_llm_client_is_null_without_token(): void {
		delete_option( 'newspack_ai_newsletter_ai_proxy_token' );
		$this->assertNull( Settings::llm_client() );
	}

	public function test_llm_client_built_when_token_set(): void {
		update_option( 'newspack_ai_newsletter_ai_proxy_token', 'SEKRET' );
		$this->assertInstanceOf( \Newspack_AI_Newsletter\LLM_Client::class, Settings::llm_client() );
		delete_option( 'newspack_ai_newsletter_ai_proxy_token' );
	}

	/** @return array<string,Field> fields keyed by key */
	private function by_key(): array {
		$out = [];
		foreach ( Settings::fields() as $f ) {
			$out[ $f->key ] = $f;
		}
		return $out;
	}

	public function test_every_settings_field_is_renderable_and_sanitized(): void {
		// A field without a render/sanitize callback is silently skipped by the
		// Schema (no UI, no save) — that was the "no settings UI" bug.
		foreach ( $this->by_key() as $key => $field ) {
			$this->assertTrue( \is_callable( $field->render ), "field $key has no render callback" );
			$this->assertTrue( \is_callable( $field->sanitize ), "field $key has no sanitize callback" );
		}
	}

	public function test_text_field_sanitize_uses_sanitize_text_field(): void {
		$sanitize = $this->by_key()['ai_model']->sanitize;
		$this->assertSame( 'gpt-oss-120b', $sanitize( "  gpt-oss-120b\n" ) );
		$this->assertSame( 'clean', $sanitize( "cle<script>an" ) );
	}

	public function test_array_strings_sanitize_splits_lines_dropping_blanks(): void {
		$sanitize = $this->by_key()['github_repos']->sanitize;
		$result   = $sanitize( "Automattic/wp-calypso\n\n  owner/repo  \n" );
		$this->assertSame( [ 'Automattic/wp-calypso', 'owner/repo' ], $result );
	}

	public function test_array_strings_sanitize_handles_an_already_array_value(): void {
		// options.php posts the textarea as a string, but be defensive.
		$sanitize = $this->by_key()['feeds']->sanitize;
		$this->assertSame( [ 'https://a.test/feed' ], $sanitize( [ 'https://a.test/feed', '' ] ) );
	}
}

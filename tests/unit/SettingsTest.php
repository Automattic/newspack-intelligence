<?php
/**
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter\Tests;

use Newspack_AI_Newsletter\Settings;
use Newspack_Nodes\Config_System\Field;
use Newspack_Nodes\Vault;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function tearDown(): void {
		delete_option( 'newspack_nodes_vault' );
		delete_option( 'newspack_ai_newsletter_github_token' );
		delete_option( 'newspack_ai_newsletter_ai_proxy_token' );
		Vault::get_instance()->reset_cache();
	}

	/** Seed one vault entry and point a *_token setting at its id. */
	private function seed_vault( string $entry_id, string $secret, string $setting_key ): void {
		update_option(
			'newspack_nodes_vault',
			[ $entry_id => [ 'id' => $entry_id, 'url' => 'https://x.test', 'auth_username' => 'u', 'auth_password' => $secret ] ]
		);
		update_option( 'newspack_ai_newsletter_' . $setting_key, $entry_id );
		Vault::get_instance()->reset_cache();
	}

	public function test_get_secret_resolves_vault_entry_password(): void {
		$this->seed_vault( 'gh-creds', 'TOKEN123', 'github_token' );
		$this->assertSame( 'TOKEN123', Settings::get_secret( 'github_token' ) );
	}

	public function test_get_secret_returns_empty_for_unset_setting(): void {
		delete_option( 'newspack_ai_newsletter_github_token' );
		$this->assertSame( '', Settings::get_secret( 'github_token' ) );
	}

	public function test_get_secret_returns_empty_for_unknown_vault_id(): void {
		update_option( 'newspack_ai_newsletter_github_token', 'does-not-exist' );
		Vault::get_instance()->reset_cache();
		$this->assertSame( '', Settings::get_secret( 'github_token' ) );
	}

	public function test_vault_select_render_lists_entries_with_current_selected(): void {
		$this->seed_vault( 'gh-creds', 'TOKEN123', 'github_token' );
		$fields = $this->by_key();
		$html   = $this->render_field( $fields['github_token'] );

		$this->assertStringContainsString( '<select name="newspack_ai_newsletter_github_token"', $html );
		$this->assertStringContainsString( '<option value="">', $html );
		$this->assertStringContainsString( '<option value="gh-creds" selected>gh-creds</option>', $html );
		// The raw secret never appears in the markup.
		$this->assertStringNotContainsString( 'TOKEN123', $html );
	}

	public function test_llm_client_resolves_token_from_vault(): void {
		$this->seed_vault( 'ai-creds', 'PROXY_SECRET', 'ai_proxy_token' );
		$this->assertInstanceOf( \Newspack_AI_Newsletter\LLM_Client::class, Settings::llm_client() );
	}
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

	public function test_get_array_trims_scalar_and_array_values(): void {
		update_option( 'newspack_ai_newsletter_feeds', " https://one.test/feed \n" );
		$this->assertSame( [ 'https://one.test/feed' ], Settings::get_array( 'feeds' ) );

		update_option(
			'newspack_ai_newsletter_feeds',
			[ ' https://two.test/feed ', '', [ 'not scalar' ], 42 ]
		);
		$this->assertSame( [ 'https://two.test/feed', '42' ], Settings::get_array( 'feeds' ) );

		update_option( 'newspack_ai_newsletter_feeds', (object) [ 'not' => 'a list' ] );
		$this->assertSame( [], Settings::get_array( 'feeds' ) );
		delete_option( 'newspack_ai_newsletter_feeds' );
	}

	public function test_llm_client_is_null_without_token(): void {
		delete_option( 'newspack_ai_newsletter_ai_proxy_token' );
		$this->assertNull( Settings::llm_client() );
	}

	/** @return array<string,Field> fields keyed by key */
	private function by_key(): array {
		$out = [];
		foreach ( Settings::fields() as $f ) {
			$out[ $f->key ] = $f;
		}
		return $out;
	}

	private function render_field( Field $field ): string {
		\ob_start();
		( $field->render )();
		return (string) \ob_get_clean();
	}

	public function test_every_settings_field_is_renderable_and_sanitized(): void {
		// A field without a render/sanitize callback is silently skipped by the
		// Schema (no UI, no save) — that was the "no settings UI" bug.
		foreach ( $this->by_key() as $key => $field ) {
			$this->assertTrue( \is_callable( $field->render ), "field $key has no render callback" );
			$this->assertTrue( \is_callable( $field->sanitize ), "field $key has no sanitize callback" );
		}
	}

	public function test_render_callbacks_emit_text_select_and_textarea_controls(): void {
		update_option( 'newspack_ai_newsletter_ai_model', 'model "quoted"' );
		update_option( 'newspack_ai_newsletter_feeds', [ 'https://a.test/feed', 'https://b.test/feed' ] );
		update_option( 'newspack_ai_newsletter_relevance_profile', "Line <one>\nLine two" );

		$fields = $this->by_key();

		$this->assertStringContainsString(
			'type="text" name="newspack_ai_newsletter_ai_model" value="model &quot;quoted&quot;"',
			$this->render_field( $fields['ai_model'] )
		);
		$this->assertStringContainsString(
			'<select name="newspack_ai_newsletter_github_token"',
			$this->render_field( $fields['github_token'] )
		);
		$this->assertStringContainsString(
			'<textarea name="newspack_ai_newsletter_feeds" rows="14" cols="60" class="code">https://a.test/feed' . "\n" . 'https://b.test/feed</textarea>',
			$this->render_field( $fields['feeds'] )
		);
		$this->assertStringContainsString(
			'<textarea name="newspack_ai_newsletter_relevance_profile" rows="5" cols="60">Line &lt;one&gt;' . "\n" . 'Line two</textarea>',
			$this->render_field( $fields['relevance_profile'] )
		);

		delete_option( 'newspack_ai_newsletter_ai_model' );
		delete_option( 'newspack_ai_newsletter_feeds' );
		delete_option( 'newspack_ai_newsletter_relevance_profile' );
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

	public function test_relevance_profile_sanitize_preserves_newlines(): void {
		// It's a multi-line textarea: paragraphs must survive (sanitize_textarea_field),
		// unlike the single-line text_sanitize which would collapse them.
		$sanitize = $this->by_key()['relevance_profile']->sanitize;
		$this->assertSame( "Line one.\nLine two.", $sanitize( "Line one.\nLine two." ) );
		$this->assertSame( 'no tags', $sanitize( 'no <b>tags</b>' ) );
	}
}

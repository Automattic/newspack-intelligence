<?php
/**
 * Settings: the plugin's declarative config — ONE Field per AI/connector setting.
 *
 * Mirrors newspack-event-logger-nodes' Settings_Schema pattern: each setting is a
 * single substrate Config_System\Field, the source from which the Schema derives
 * every consumer (overlay key-list, register_setting + add_settings_field loops,
 * reset surface, restart classification).
 *
 * The substrate Field has no native secret flag, so the three credential fields
 * are flagged via register_args['secret'] — the substrate's free-form per-field
 * metadata seam — which sub-projects #2/#3 read to render password inputs and keep
 * the value out of any dump. Labels are lazy `fn(): string` thunks so building the
 * schema (a frontend request does via overlay_keys()) never calls __() at load.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Config_System\Field;
use Newspack_Nodes\Config_System\Schema;

\defined( 'ABSPATH' ) || exit;

class Settings {
	private const PREFIX = 'newspack_ai_newsletter_';

	private const AI_SECTION         = 'newspack_ai_newsletter_ai_section';
	private const CONNECTORS_SECTION = 'newspack_ai_newsletter_connectors_section';
	private const DIGEST_SECTION     = 'newspack_ai_newsletter_digest_section';

	private const AI_PROXY_BASE_URL = 'https://public-api.wordpress.com/wpcom/v2/ai-api-proxy/v1';
	private const AI_MODEL          = 'gpt-oss-120b';
	private const AI_FEATURE        = 'newspack-ai-newsletter';

	/** Where the digest:log Node writes the rendered newsletter. MUST match topologies/newspack-ai-newsletter.tsl. */
	public const DIGEST_PATH = '/tmp/newspack-ai-newsletter/digest.md';

	/** @var Schema|null Memoized — pure structure (values resolve inside callbacks). */
	private static ?Schema $schema = null;

	/** Declared defaults, keyed by field key — the SAME values the Field declarations use. */
	private const DEFAULTS = [
		'ai_proxy_base_url' => self::AI_PROXY_BASE_URL,
		'ai_model'          => self::AI_MODEL,
		'ai_feature'        => self::AI_FEATURE,
	];

	/**
	 * The settings fields, in render order.
	 *
	 * @return array<int,Field>
	 */
	public static function fields(): array {
		return self::schema()->fields();
	}

	/** The plugin settings schema (memoized). */
	public static function schema(): Schema {
		if ( null !== self::$schema ) {
			return self::$schema;
		}

		self::$schema = new Schema(
			self::PREFIX,
			[
				// -- AI proxy ------------------------------------------------
				new Field(
					key: 'ai_proxy_base_url',
					type: 'text',
					label: static fn(): string => \__( 'AI Proxy Base URL', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					sanitize: self::text_sanitize(),
					render: self::text_render( 'ai_proxy_base_url' ),
					register_args: [ 'default' => self::AI_PROXY_BASE_URL ],
				),
				new Field(
					key: 'ai_proxy_token',
					type: 'text',
					label: static fn(): string => \__( 'AI Proxy Token (Vault entry)', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					sanitize: self::text_sanitize(),
					render: self::vault_select_render( 'ai_proxy_token' ),
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'ai_model',
					type: 'text',
					label: static fn(): string => \__( 'AI Model', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					sanitize: self::text_sanitize(),
					render: self::text_render( 'ai_model' ),
					register_args: [ 'default' => self::AI_MODEL ],
				),
				new Field(
					key: 'ai_feature',
					type: 'text',
					label: static fn(): string => \__( 'AI Feature', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					sanitize: self::text_sanitize(),
					render: self::text_render( 'ai_feature' ),
					register_args: [ 'default' => self::AI_FEATURE ],
				),

				// -- Connectors ----------------------------------------------
				new Field(
					key: 'github_repos',
					type: 'array_strings',
					label: static fn(): string => \__( 'GitHub Repos (one owner/name per line)', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					sanitize: self::list_sanitize(),
					render: self::list_render( 'github_repos' ),
					delete_on_blank: false,
				),
				new Field(
					key: 'github_token',
					type: 'text',
					label: static fn(): string => \__( 'GitHub Token (Vault entry)', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					sanitize: self::text_sanitize(),
					render: self::vault_select_render( 'github_token' ),
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'linear_token',
					type: 'text',
					label: static fn(): string => \__( 'Linear Token (Vault entry)', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					sanitize: self::text_sanitize(),
					render: self::vault_select_render( 'linear_token' ),
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'feeds',
					type: 'array_strings',
					label: static fn(): string => \__( 'Feeds (one RSS/Atom URL per line)', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					sanitize: self::list_sanitize(),
					render: self::list_render( 'feeds' ),
					delete_on_blank: false,
				),

				// -- Digest --------------------------------------------------
				new Field(
					key: 'digest_schedule',
					type: 'text',
					label: static fn(): string => \__( 'Digest Schedule', 'newspack-ai-newsletter' ),
					section: self::DIGEST_SECTION,
					sanitize: self::text_sanitize(),
					render: self::text_render( 'digest_schedule' ),
				),
				new Field(
					key: 'relevance_profile',
					type: 'text',
					label: static fn(): string => \__( 'Relevance Profile', 'newspack-ai-newsletter' ),
					section: self::DIGEST_SECTION,
					sanitize: self::textarea_sanitize(),
					render: self::string_textarea_render( 'relevance_profile' ),
					delete_on_blank: false,
				),
			],
			[
				self::AI_SECTION         => [
					'title'    => static fn(): string => \__( 'AI Proxy', 'newspack-ai-newsletter' ),
					'callback' => static function (): void {},
				],
				self::CONNECTORS_SECTION => [
					'title'    => static fn(): string => \__( 'Connectors', 'newspack-ai-newsletter' ),
					'callback' => static function (): void {},
				],
				self::DIGEST_SECTION     => [
					'title'    => static fn(): string => \__( 'Digest', 'newspack-ai-newsletter' ),
					'callback' => static function (): void {},
				],
			]
		);

		return self::$schema;
	}

	/** Sanitize a single text/secret value (trim + strip tags). */
	private static function text_sanitize(): \Closure {
		return static fn ( $value ): string => \sanitize_text_field( \is_scalar( $value ) ? (string) $value : '' );
	}

	/** Render a single-line text input bound to a setting. */
	private static function text_render( string $key ): \Closure {
		return static function () use ( $key ): void {
			\printf(
				'<input type="text" name="%s" value="%s" class="regular-text" autocomplete="off" />',
				\esc_attr( self::PREFIX . $key ),
				\esc_attr( self::get_string( $key ) )
			);
		};
	}

	/** Scalar config read coerced to string; non-scalar (e.g. the `feeds` array) becomes ''. */
	public static function get_string( string $key ): string {
		$value = self::get( $key );
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/** Runtime config read: stored option, else the declared default, else ''. */
	public static function get( string $key ): mixed {
		return \get_option( self::PREFIX . $key, self::DEFAULTS[ $key ] ?? '' );
	}

	/**
	 * Render a <select> of substrate Vault entry IDs bound to a credential setting.
	 *
	 * The stored value is a Vault entry ID — never the raw secret — which get_secret()
	 * resolves to the real `auth_password` at use-time. The dropdown lists every Vault
	 * entry (guarded on the substrate class being present) with the current selection
	 * marked; a leading "— None —" clears it. The secret itself is never rendered.
	 */
	private static function vault_select_render( string $key ): \Closure {
		return static function () use ( $key ): void {
			$current = self::get_string( $key );
			$entries = \class_exists( '\\Newspack_Nodes\\Vault' )
				? \Newspack_Nodes\Vault::get_instance()->get_all()
				: [];

			\printf( '<select name="%s" id="%1$s">', \esc_attr( self::PREFIX . $key ) );
			\printf( '<option value="">%s</option>', \esc_html( \__( '— None —', 'newspack-ai-newsletter' ) ) );
			foreach ( \array_keys( $entries ) as $id ) {
				$id = (string) $id;
				\printf(
					'<option value="%s"%s>%s</option>',
					\esc_attr( $id ),
					( $id === $current ) ? ' selected' : '',
					\esc_html( $id )
				);
			}
			echo '</select>';
		};
	}

	/** Sanitize an array_strings value: a textarea (or array) → trimmed, non-empty list. */
	private static function list_sanitize(): \Closure {
		return static function ( $value ): array {
			$lines = \is_array( $value )
				? $value
				: \preg_split( '/\r\n|\r|\n/', \is_scalar( $value ) ? (string) $value : '' );
			$out = [];
			foreach ( (array) $lines as $line ) {
				if ( ! \is_scalar( $line ) ) {
					continue;
				}
				$clean = \sanitize_text_field( (string) $line );
				if ( '' !== $clean ) {
					$out[] = $clean;
				}
			}
			return $out;
		};
	}

	/** Render an array_strings setting as a one-entry-per-line textarea (fixed width). */
	private static function list_render( string $key, int $rows = 14 ): \Closure {
		return static function () use ( $key, $rows ): void {
			\printf(
				'<textarea name="%s" rows="%s" cols="60" class="code">%s</textarea>',
				\esc_attr( self::PREFIX . $key ),
				\esc_attr( (string) $rows ),
				\esc_textarea( \implode( "\n", self::get_array( $key ) ) )
			);
		};
	}

	/**
	 * List config read (e.g. `feeds`, `github_repos`): the stored value as a list of
	 * non-empty strings. A scalar option becomes a single-element list; anything
	 * else becomes []. Entries are trimmed and blanks dropped.
	 *
	 * @return array<int,string>
	 */
	public static function get_array( string $key ): array {
		$value = self::get( $key );
		if ( \is_scalar( $value ) ) {
			$value = [ (string) $value ];
		}
		if ( ! \is_array( $value ) ) {
			return [];
		}
		$out = [];
		foreach ( $value as $entry ) {
			if ( ! \is_scalar( $entry ) ) {
				continue;
			}
			$trimmed = \trim( (string) $entry );
			if ( '' !== $trimmed ) {
				$out[] = $trimmed;
			}
		}
		return $out;
	}

	/** Sanitize a multi-line text value: strip tags but PRESERVE newlines. */
	private static function textarea_sanitize(): \Closure {
		return static fn ( $value ): string => \sanitize_textarea_field( \is_scalar( $value ) ? (string) $value : '' );
	}

	/** Render a multi-line text setting (a scalar string) as a fixed-width textarea. */
	private static function string_textarea_render( string $key, int $rows = 5 ): \Closure {
		return static function () use ( $key, $rows ): void {
			\printf(
				'<textarea name="%s" rows="%s" cols="60">%s</textarea>',
				\esc_attr( self::PREFIX . $key ),
				\esc_attr( (string) $rows ),
				\esc_textarea( self::get_string( $key ) )
			);
		};
	}

	/** Build the proxy client from config; null when no token (callers fall back to heuristics). */
	public static function llm_client(): ?LLM_Client {
		$token = self::get_secret( 'ai_proxy_token' );
		if ( '' === $token ) {
			return null;
		}
		return new Proxy_LLM_Client(
			self::get_string( 'ai_proxy_base_url' ),
			$token,
			self::get_string( 'ai_model' ),
			self::get_string( 'ai_feature' )
		);
	}

	/**
	 * Resolve a credential setting to its real secret via the substrate Vault.
	 *
	 * The setting stores a Vault entry ID (set in the admin <select>), not the raw
	 * secret. Returns the entry's `auth_password`, or '' when the setting is unset,
	 * the Vault class is absent, the ID is unknown, or the entry has no password.
	 */
	public static function get_secret( string $key ): string {
		$id = self::get_string( $key );
		if ( '' === $id || ! \class_exists( '\\Newspack_Nodes\\Vault' ) ) {
			return '';
		}
		$entry    = \Newspack_Nodes\Vault::get_instance()->get( $id );
		$password = ( null !== $entry ) ? ( $entry['auth_password'] ?? null ) : null;
		return ( \is_string( $password ) && '' !== $password ) ? $password : '';
	}
}

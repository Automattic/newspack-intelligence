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

	/** @var Schema|null Memoized — pure structure (values resolve inside callbacks). */
	private static ?Schema $schema = null;

	/** Declared defaults, keyed by field key — the SAME values the Field declarations use. */
	private const DEFAULTS = [
		'ai_proxy_base_url' => self::AI_PROXY_BASE_URL,
		'ai_model'          => self::AI_MODEL,
		'ai_feature'        => self::AI_FEATURE,
	];

	/** Runtime config read: stored option, else the declared default, else ''. */
	public static function get( string $key ): mixed {
		return \get_option( self::PREFIX . $key, self::DEFAULTS[ $key ] ?? '' );
	}

	/** Scalar config read coerced to string; non-scalar (e.g. the `feeds` array) becomes ''. */
	public static function get_string( string $key ): string {
		$value = self::get( $key );
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/** Build the proxy client from config; null when no token (callers fall back to heuristics). */
	public static function llm_client(): ?LLM_Client {
		$token = self::get_string( 'ai_proxy_token' );
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
					register_args: [ 'default' => self::AI_PROXY_BASE_URL ],
				),
				new Field(
					key: 'ai_proxy_token',
					type: 'text',
					label: static fn(): string => \__( 'AI Proxy Token', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'ai_model',
					type: 'text',
					label: static fn(): string => \__( 'AI Model', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					register_args: [ 'default' => self::AI_MODEL ],
				),
				new Field(
					key: 'ai_feature',
					type: 'text',
					label: static fn(): string => \__( 'AI Feature', 'newspack-ai-newsletter' ),
					section: self::AI_SECTION,
					register_args: [ 'default' => self::AI_FEATURE ],
				),

				// -- Connector secrets ---------------------------------------
				new Field(
					key: 'github_token',
					type: 'text',
					label: static fn(): string => \__( 'GitHub Token', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'linear_token',
					type: 'text',
					label: static fn(): string => \__( 'Linear Token', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					register_args: [ 'secret' => true, 'autoload' => false ],
				),
				new Field(
					key: 'feeds',
					type: 'array_strings',
					label: static fn(): string => \__( 'Feeds', 'newspack-ai-newsletter' ),
					section: self::CONNECTORS_SECTION,
					delete_on_blank: false,
				),

				// -- Digest --------------------------------------------------
				new Field(
					key: 'digest_schedule',
					type: 'text',
					label: static fn(): string => \__( 'Digest Schedule', 'newspack-ai-newsletter' ),
					section: self::DIGEST_SECTION,
				),
				new Field(
					key: 'relevance_profile',
					type: 'text',
					label: static fn(): string => \__( 'Relevance Profile', 'newspack-ai-newsletter' ),
					section: self::DIGEST_SECTION,
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
}

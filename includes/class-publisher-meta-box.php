<?php
/**
 * Publisher_Meta_Box: the human-owner enrichment editor for `newspack_publisher`.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

/**
 * Renders + saves the "Publisher details" meta box on the newspack_publisher
 * edit screen: editable enrichment fields (human/HubSpot-owned) plus a
 * read-only provenance section for the import-managed fields. All methods
 * are static so hooks can register them as string callables (see the
 * bootstrap comment in newspack-ai-newsletter.php re: lazy autoload).
 */
final class Publisher_Meta_Box {

	public const NONCE_ACTION = 'npainl_publisher_meta_box';
	public const NONCE_NAME   = 'npainl_publisher_meta_box_nonce';

	/**
	 * Single source of truth: editable enrichment meta keys => labels.
	 *
	 * @return array<string,string>
	 */
	public static function enrichment_fields(): array {
		return [
			Publisher_CPT::META_PUBLISHER_NAME => \__( 'Publisher name', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_LOCALITIES     => \__( 'Localities (pipe-separated)', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_GITHUB_ORG     => \__( 'GitHub org', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_LINKEDIN_ID    => \__( 'LinkedIn company ID', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_X_HANDLE       => \__( 'X handle', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_ALIASES        => \__( 'Aliases (pipe-separated)', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_BEAT_TAGS      => \__( 'Beat tags (pipe-separated)', 'newspack-ai-newsletter' ),
		];
	}

	/**
	 * Import-managed meta keys => labels, shown read-only for provenance.
	 *
	 * @return array<string,string>
	 */
	public static function readonly_fields(): array {
		return [
			Publisher_CPT::META_ATOMIC_ID  => \__( 'Atomic site ID', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_DOMAIN     => \__( 'Domain', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_CREATED    => \__( 'Created', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_STATUS     => \__( 'Status', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_FIRST_SEEN => \__( 'First seen', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_LAST_SEEN  => \__( 'Last seen', 'newspack-ai-newsletter' ),
			Publisher_CPT::META_CHURNED_AT => \__( 'Churned at', 'newspack-ai-newsletter' ),
		];
	}

	/** add_meta_boxes handler: register the "Publisher details" box. */
	public static function register(): void {
		\add_meta_box(
			'newspack-publisher-enrichment',
			\__( 'Publisher details', 'newspack-ai-newsletter' ),
			[ self::class, 'render' ],
			Publisher_CPT::POST_TYPE,
			'normal',
			'default'
		);
	}

	/** Render the meta box: editable enrichment fields + read-only provenance. */
	public static function render( \WP_Post $post ): void {
		\wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		foreach ( self::enrichment_fields() as $key => $label ) {
			$value = self::meta_string( $post->ID, $key );
			echo '<p>';
			echo '<label for="' . \esc_attr( $key ) . '">' . \esc_html( $label ) . '</label><br />';
			echo '<input type="text" class="widefat" id="' . \esc_attr( $key ) . '" name="' . \esc_attr( $key ) . '" value="' . \esc_attr( $value ) . '" />';
			echo '</p>';
		}

		echo '<h4>' . \esc_html__( 'Import-managed fields', 'newspack-ai-newsletter' ) . '</h4>';
		echo '<p class="description">' . \esc_html__( 'These fields are managed by the CSV import and must not be hand-edited; a re-import remains the sole source of truth for them.', 'newspack-ai-newsletter' ) . '</p>';
		echo '<dl>';
		foreach ( self::readonly_fields() as $key => $label ) {
			$value = self::meta_string( $post->ID, $key );
			echo '<dt>' . \esc_html( $label ) . '</dt>';
			echo '<dd><input type="text" class="widefat" value="' . \esc_attr( $value ) . '" readonly="readonly" disabled="disabled" /></dd>';
		}
		echo '</dl>';
	}

	/** Type-safe read of a single post meta value (get_post_meta's return is mixed). */
	private static function meta_string( int $post_id, string $key ): string {
		$value = \get_post_meta( $post_id, $key, true );
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * save_post handler: validate context, then persist only the enrichment
	 * fields present in $_POST. Never touches import-managed meta.
	 */
	public static function save( int $post_id ): void {
		if ( \defined( 'DOING_AUTOSAVE' ) && \DOING_AUTOSAVE ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized two lines below via sanitize_text_field( wp_unslash() ) before wp_verify_nonce().
		$nonce_raw = $_POST[ self::NONCE_NAME ] ?? null;
		if ( ! \is_string( $nonce_raw ) ) {
			return;
		}
		if ( ! \wp_verify_nonce( \sanitize_text_field( \wp_unslash( $nonce_raw ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( Publisher_CPT::POST_TYPE !== \get_post_type( $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per-field in persist() via sanitize_text_field( wp_unslash() ).
		$post_data = $_POST;
		// $_POST is documented as array<string,mixed> in practice (form field
		// names are always strings); phpstan's superglobal stub widens the key
		// type to mixed, so re-key defensively to satisfy persist()'s signature.
		/** @var array<string,mixed> $post_data */
		$post_data = \array_combine( \array_map( 'strval', \array_keys( $post_data ) ), \array_values( $post_data ) );
		self::persist( $post_id, $post_data );
	}

	/**
	 * Write only the enrichment fields present in $raw. CRITICAL INVARIANT:
	 * iterates ONLY enrichment_fields() — never writes an import-managed meta
	 * key. A re-import must remain the sole source of truth for
	 * domain/created/status/first_seen/last_seen/churned_at.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string,mixed>  $raw     Raw request data (e.g. $_POST).
	 */
	public static function persist( int $post_id, array $raw ): void {
		foreach ( self::enrichment_fields() as $key => $label ) {
			if ( ! isset( $raw[ $key ] ) || ! \is_string( $raw[ $key ] ) ) {
				continue;
			}
			\update_post_meta( $post_id, $key, \sanitize_text_field( \wp_unslash( $raw[ $key ] ) ) );
		}
	}
}

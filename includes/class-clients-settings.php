<?php
/**
 * Clients_Settings: CSV upload → publisher master import, on the Settings page.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

class Clients_Settings {

	public const ADMIN_POST_ACTION = 'newspack_ai_newsletter_import_clients';

	private Client_Importer $importer;

	public function __construct( ?Client_Importer $importer = null ) {
		$this->importer = $importer ?? new Client_Importer( new CPT_Publisher_Repository() );
	}

	/**
	 * Parse + import a CSV file at $path.
	 *
	 * @param string $path Path to the CSV file.
	 * @return array{created:int,updated:int,reactivated:int,churned:int,total_in_csv:int}
	 */
	public function import_path( string $path ): array {
		$rows = CSV_Parser::parse_file( $path );
		if ( null === $rows ) {
			return [
				'created'      => 0,
				'updated'      => 0,
				'reactivated'  => 0,
				'churned'      => 0,
				'total_in_csv' => 0,
			];
		}
		return $this->importer->import( $rows, \gmdate( 'Y-m-d' ) );
	}

	/** Render the upload form (called from the Settings page). */
	public function render_upload_section(): void {
		echo '<h2>' . \esc_html__( 'Import Newspack Clients', 'newspack-ai-newsletter' ) . '</h2>';
		echo '<form method="post" enctype="multipart/form-data" action="' . \esc_url( \admin_url( 'admin-post.php' ) ) . '">';
		\wp_nonce_field( self::ADMIN_POST_ACTION );
		echo '<input type="hidden" name="action" value="' . \esc_attr( self::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="file" name="clients_csv" accept=".csv" required /> ';
		\submit_button( \__( 'Import CSV', 'newspack-ai-newsletter' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** admin-post handler: validate nonce/caps + $_FILES, then import_path(). */
	public function handle_admin_post(): void {
		if ( ! \current_user_can( 'manage_options' ) || ! \check_admin_referer( self::ADMIN_POST_ACTION ) ) {
			\wp_die( \esc_html__( 'Not allowed.', 'newspack-ai-newsletter' ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line via sanitize_text_field( wp_unslash() ).
		$file = isset( $_FILES['clients_csv'] ) && \is_array( $_FILES['clients_csv'] ) ? $_FILES['clients_csv'] : [];
		$tmp  = isset( $file['tmp_name'] ) && \is_string( $file['tmp_name'] ) ? \sanitize_text_field( \wp_unslash( $file['tmp_name'] ) ) : '';
		$this->import_path( $tmp );
		$fallback = \admin_url( 'options-general.php?page=' . SETTINGS_MENU_SLUG );
		\wp_safe_redirect( \add_query_arg( 'clients_imported', '1', \wp_get_referer() ?: $fallback ) );
		exit;
	}

	/** admin_notices: a success notice after a completed CSV import. */
	public function render_import_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.
		$flag = isset( $_GET['clients_imported'] ) && \is_string( $_GET['clients_imported'] ) ? \sanitize_text_field( \wp_unslash( $_GET['clients_imported'] ) ) : '';
		if ( '1' !== $flag ) {
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . \esc_html__( 'Newspack clients imported.', 'newspack-ai-newsletter' ) . '</p></div>';
	}
}

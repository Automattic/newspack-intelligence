<?php
/**
 * Newspack Nodes uninstall cleanup.
 *
 * Runs ONLY on plugin delete (WordPress defines WP_UNINSTALL_PLUGIN), never on
 * deactivate. Removes every `newspack_ai_newsletter_` option this plugin created.
 *
 * @package Newspack_AI_Newsletter
 */

if ( ! \defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require __DIR__ . '/includes/uninstall-cleanup.php';

\Newspack_AI_Newsletter\uninstall_cleanup( 'newspack_ai_newsletter_' );

<?php
/**
 * Publisher_CPT: the `newspack_publisher` master-data post type + meta keys.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

final class Publisher_CPT {

	public const POST_TYPE = 'newspack_publisher';

	// Atomic-sourced + lifecycle meta (import-managed).
	public const META_ATOMIC_ID  = '_npainl_atomic_site_id';
	public const META_DOMAIN     = '_npainl_domain_name';
	public const META_CREATED    = '_npainl_created';
	public const META_STATUS     = '_npainl_status';
	public const META_FIRST_SEEN = '_npainl_first_seen';
	public const META_LAST_SEEN  = '_npainl_last_seen';
	public const META_CHURNED_AT = '_npainl_churned_at';

	// Enrichment meta (human/HubSpot-owned; NEVER written by the importer).
	public const META_PUBLISHER_NAME = '_npainl_publisher_name';
	public const META_LOCALITIES     = '_npainl_localities';
	public const META_GITHUB_ORG     = '_npainl_github_org';
	public const META_LINKEDIN_ID    = '_npainl_linkedin_company_id';
	public const META_X_HANDLE       = '_npainl_x_handle';
	public const META_ALIASES        = '_npainl_aliases';
	public const META_BEAT_TAGS      = '_npainl_beat_tags';

	public static function register(): void {
		\register_post_type(
			self::POST_TYPE,
			[
				'labels'       => [
					'name'          => \__( 'Publishers', 'newspack-intelligence' ),
					'singular_name' => \__( 'Publisher', 'newspack-intelligence' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-groups',
				'supports'     => [ 'title' ],
				'map_meta_cap' => false,
				'capabilities' => [
					'edit_post'           => 'manage_options',
					'read_post'           => 'manage_options',
					'delete_post'         => 'manage_options',
					'edit_posts'          => 'manage_options',
					'edit_others_posts'   => 'manage_options',
					'delete_posts'        => 'manage_options',
					'delete_others_posts' => 'manage_options',
					'publish_posts'       => 'manage_options',
					'read_private_posts'  => 'manage_options',
					'create_posts'        => 'manage_options',
				],
			]
		);
	}
}

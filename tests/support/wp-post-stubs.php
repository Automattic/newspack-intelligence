<?php
declare(strict_types=1);

// Global-namespace, in-memory WP post/meta stubs for the CPT layer. No
// `namespace` declaration on purpose: these must BE the global functions the
// production code calls via `\register_post_type()`, `\wp_insert_post()`, etc.
// Guarded so the suite can load this from more than one test file.

if ( ! class_exists( 'NPAINL_WP_Post_Store' ) ) {
	class NPAINL_WP_Post_Store {
		/** @var array<int,array{post_type:string,post_title:string}> */
		public static array $posts = [];
		/** @var array<int,array<string,string>> */
		public static array $meta = [];
		public static int $next_id = 100;
		/** @var array{type:string,args:array<string,mixed>}|null */
		public static ?array $last_cpt = null;
		public static int $update_calls = 0;
		public static function reset(): void {
			self::$posts        = [];
			self::$meta         = [];
			self::$next_id      = 100;
			self::$last_cpt     = null;
			self::$update_calls = 0;
		}
	}
}

if ( ! function_exists( 'register_post_type' ) ) {
	function register_post_type( string $type, array $args ) {
		NPAINL_WP_Post_Store::$last_cpt = [ 'type' => $type, 'args' => $args ];
		return (object) [ 'name' => $type ];
	}
}
if ( ! function_exists( 'wp_insert_post' ) ) {
	function wp_insert_post( array $args ) {
		$id                                 = NPAINL_WP_Post_Store::$next_id++;
		NPAINL_WP_Post_Store::$posts[ $id ] = [ 'post_type' => $args['post_type'], 'post_title' => $args['post_title'] ?? '' ];
		NPAINL_WP_Post_Store::$meta[ $id ]  = [];
		return $id;
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( int $post_id, string $key, $value ) {
		NPAINL_WP_Post_Store::$meta[ $post_id ][ $key ] = (string) $value;
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single = false ) {
		return NPAINL_WP_Post_Store::$meta[ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	function wp_update_post( array $args ) {
		$id = (int) $args['ID'];
		if ( array_key_exists( 'post_title', $args ) ) {
			NPAINL_WP_Post_Store::$posts[ $id ]['post_title'] = $args['post_title'];
		}
		++NPAINL_WP_Post_Store::$update_calls;
		return $id;
	}
}
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( array $args ) {
		$want = ( isset( $args['meta_key'], $args['meta_value'] ) )
			? [ (string) $args['meta_key'], (string) $args['meta_value'] ]
			: null;
		$ids = [];
		foreach ( NPAINL_WP_Post_Store::$posts as $id => $post ) {
			if ( $post['post_type'] !== $args['post_type'] ) {
				continue;
			}
			if ( null !== $want && ( NPAINL_WP_Post_Store::$meta[ $id ][ $want[0] ] ?? null ) !== $want[1] ) {
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
	}
}

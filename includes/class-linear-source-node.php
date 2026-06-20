<?php
/**
 * Linear_Source_Node: pulls recently-updated Linear issues via the Linear GraphQL
 * API and normalizes them into digest items.
 *
 * Extends Source_Node, so the base owns the TICK/TM_REQUEST trigger, dedup, and
 * snapshot; this class supplies only the two seams: config() (Settings read) and
 * fetch() (the blocking GraphQL call, behind the $http_post closure seam).
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

\defined( 'ABSPATH' ) || exit;

class Linear_Source_Node extends Source_Node {

	private const API_URL = 'https://api.linear.app/graphql';

	private const QUERY = '{ issues(first: 30, orderBy: updatedAt) { nodes { identifier title url description updatedAt } } }';

	/**
	 * wp_remote_post call seam. Null by default; the call site then invokes the real
	 * `wp_remote_post`. Tests reassign it (and reset to null in tearDown) to return
	 * canned Linear GraphQL JSON WITHOUT short-circuiting header/body assembly, the
	 * WP_Error / non-200 branches, or node normalization — so all of that runs as
	 * real, covered production code.
	 *
	 * Signature: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var (\Closure( string, array<string,mixed> ): (array<string,mixed>|\WP_Error))|null
	 */
	public static ?\Closure $http_post = null;

	/** @return array{token:string} */
	protected function config(): array {
		return [ 'token' => Settings::get_secret( 'linear_token' ) ];
	}

	/**
	 * Fetch recently-updated Linear issues, normalized to the item contract
	 * {source,id,title,url,body,timestamp}. No token → skip (no creds). A transport
	 * error or non-200 contributes nothing and never throws (fire-and-forget).
	 *
	 * @param array<string,mixed> $config {token: string}.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch( array $config ): array {
		$token = \is_string( $config['token'] ?? null ) ? $config['token'] : '';
		if ( '' === $token ) {
			return [];
		}

		$args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- connector fetches run in a background worker, not a web request.
			'timeout' => 15,
			'headers' => [
				'Authorization' => $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) \wp_json_encode( [ 'query' => self::QUERY ] ),
		];
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_post_wp_remote_post -- connector fetches run in a background worker, not a VIP web request; the closure seam covers tests.
		$response = null !== self::$http_post ? ( self::$http_post )( self::API_URL, $args ) : \wp_remote_post( self::API_URL, $args );
		if ( \is_wp_error( $response ) ) {
			$this->print_less_often( 'Linear fetch failed: ' . $response->get_error_message() );
			return [];
		}
		if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		return $this->normalize( \json_decode( \wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Walk data.issues.nodes[] and normalize each into a digest item. A node with no
	 * string identifier is skipped.
	 *
	 * @param mixed $decoded Decoded GraphQL response body.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize( mixed $decoded ): array {
		$data   = \is_array( $decoded ) ? ( $decoded['data'] ?? null ) : null;
		$issues = \is_array( $data ) ? ( $data['issues'] ?? null ) : null;
		$nodes  = \is_array( $issues ) ? ( $issues['nodes'] ?? null ) : null;
		if ( ! \is_array( $nodes ) ) {
			return [];
		}
		$out = [];
		foreach ( $nodes as $node ) {
			if ( ! \is_array( $node ) ) {
				continue;
			}
			$identifier = $node['identifier'] ?? null;
			if ( ! \is_string( $identifier ) || '' === $identifier ) {
				continue;
			}
			$out[] = $this->normalize_item( 'linear', $identifier, $node['title'] ?? '', $node['url'] ?? '', $node['description'] ?? '', $node['updatedAt'] ?? '' );
		}
		return $out;
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches recently-updated Linear issues on a TICK request (request_node linear TICK).',
			'Fetch + emit new Linear issues. Trigger with `request_node linear TICK`.'
		);
	}
}

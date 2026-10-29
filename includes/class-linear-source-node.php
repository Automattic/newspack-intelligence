<?php
/**
 * Linear_Source_Node: pulls the recently-updated issues of the Linear teams the
 * `add_team` verb names via the Linear GraphQL API and normalizes them into
 * recorded items.
 *
 * Extends Source_Node, so the base owns the TICK/TM_REQUEST trigger and dedup;
 * this class supplies only the two seams: config() (Settings read) and
 * fetch() (the blocking GraphQL call, behind the $http_post closure seam).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

class Linear_Source_Node extends Source_Node {
	use Vault_Secret;

	private const API_URL = 'https://api.linear.app/graphql';

	private const QUERY = 'query ($teams: [String!]) { issues(first: 30, orderBy: updatedAt, filter: { team: { key: { in: $teams } } }) { nodes { identifier title url description updatedAt } } }';

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

	/** @var list<string> Team keys the `add_team` verb registered and `remove_team` left, in call order. */
	protected array $teams = [];

	/**
	 * Fetch the configured teams' recently-updated Linear issues, normalized to
	 * the item_recorded fields. No team or no token → skip, with no call. The
	 * keys ride as the `$teams` GraphQL variable, never in the query text. A
	 * transport error or non-200 contributes nothing and never throws
	 * (fire-and-forget).
	 *
	 * @param array<string,mixed> $config {teams: string[], token: string}.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch( array $config ): array {
		$teams = \is_array( $config['teams'] ?? null ) ? \array_values( $config['teams'] ) : [];
		$token = \is_string( $config['token'] ?? null ) ? $config['token'] : '';
		if ( [] === $teams || '' === $token ) {
			return [];
		}

		$args = [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- connector fetches run in a background worker, not a web request.
			'timeout' => 15,
			'headers' => [
				'Authorization' => $token,
				'Content-Type'  => 'application/json',
			],
			'body'    => (string) \wp_json_encode(
				[
					'query'     => self::QUERY,
					'variables' => [ 'teams' => $teams ],
				]
			),
		];
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_post_wp_remote_post -- connector fetches run in a background worker, not a VIP web request; the closure seam covers tests.
		$response = null !== self::$http_post ? ( self::$http_post )( self::API_URL, $args ) : \wp_remote_post( self::API_URL, $args );
		if ( \is_wp_error( $response ) ) {
			$this->print_less_often( 'Linear fetch failed: ', $response->get_error_message() );
			return [];
		}
		if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		return $this->normalize( \json_decode( \wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Walk data.issues.nodes[] and normalize each into a recorded item. A node with no
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

	/** @return array{teams:list<string>,token:string} */
	protected function config(): array {
		return [
			'teams' => $this->teams,
			'token' => $this->vault_secret(),
		];
	}

	/** Emit the base config plus one `add_team` line per registered team. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_lists();
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches recently-updated Linear issues of the configured teams on a TICK request (request_node linear TICK).',
			'Fetch + emit new Linear issues. Trigger with `request_node linear TICK`.',
			[
				...self::list_verbs( 'team', 'teams', [ 'name' => 'team', 'type' => 'string', 'required' => true ], 'Register a Linear team to fetch on TICK by its key: <KEY>.' ),
				self::vault_id_verb( 'the Linear API token' ),
			]
		);
	}
}

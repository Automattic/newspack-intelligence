<?php
/**
 * Linear_Source_Node: pulls the recently-updated issues of the Linear teams the
 * `add_team` verb names via the Linear GraphQL API and normalizes them into
 * recorded items.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. A pass POSTs one GraphQL query of the newest page, the team
 * keys riding as its `$teams` variable, through the Curl sibling under the
 * source's vault group, and records the page's issues as it lands.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Linear_Source_Node extends Source_Node {

	private const API_URL = 'https://api.linear.app/graphql';

	private const QUERY = 'query ($teams: [String!]) { issues(first: 30, orderBy: updatedAt, filter: { team: { key: { in: $teams } } }) { nodes { identifier title url description updatedAt } } }';

	/** @var list<string> Team keys the `add_team` verb registered and `remove_team` left, in call order. */
	protected array $teams = [];

	/** Queue the one POST, unless no team is named. */
	protected function begin(): void {
		if ( [] === $this->teams ) {
			return;
		}
		$this->queue(
			self::API_URL,
			(string) \wp_json_encode(
				[
					'query'     => self::QUERY,
					'variables' => [ 'teams' => $this->teams ],
				]
			)
		);
	}

	/**
	 * Walk data.issues.nodes[] and normalize each into a recorded item. A node with no
	 * string identifier is skipped. An answer carrying `errors`, which Linear
	 * sends with a 200, logs its first error, and one whose nodes are not a
	 * list logs that and yields none.
	 *
	 * @param string $url  The GraphQL url.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$decoded = Core::arr( \json_decode( $body, true ) );
		$nodes   = Core::arr( Core::arr( $decoded['data'] ?? null )['issues'] ?? null )['nodes'] ?? null;
		$listed  = \is_array( $nodes ) && \array_is_list( $nodes );
		$error   = Core::arr( Core::arr( $decoded['errors'] ?? null )[0] ?? null )['message'] ?? null;
		if ( null !== $error || ! $listed ) {
			$this->print_less_often( 'Linear answered an error: ', Core::terminal_safe( null !== $error ? Core::as_string( $error ) : 'data.issues.nodes is not a list' ) );
		}
		if ( ! $listed ) {
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

	/** Emit the base config plus one `add_team` line per registered team. */
	public function dump_config(): string {
		return parent::dump_config() . $this->dump_lists();
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches recently-updated Linear issues of the configured teams without blocking, one GraphQL POST a pass, on a TICK request (request_node linear TICK).',
			'Start a pass over the teams; the page records its new issues as it lands. Trigger with `request_node linear TICK`.',
			[
				...self::list_verbs( 'team', 'teams', [ 'name' => 'team', 'type' => 'string', 'required' => true ], 'Register a Linear team to fetch on TICK by its key: <KEY>.' ),
			]
		);
	}
}

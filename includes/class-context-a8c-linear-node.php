<?php
/**
 * Context_A8C_Linear_Node: reads the newest issues of the Linear teams the
 * `add_team` verb names through ContextA8C's `linear` / `team-issues`, and
 * records each as Linear_Source_Node records the same issue.
 *
 * Extends Context_A8C_Source_Node, which owns the token, the JSON-RPC
 * envelope and the answer. A pass asks each team's newest page, LIMIT
 * issues, and pages no further, as the Linear source reads one page a pass.
 * The answer groups its issues by status; every group's issues record. Its
 * `description` is truncated, so an issue recorded here carries a shorter
 * body than the Linear source records.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Context_A8C_Linear_Node extends Context_A8C_Source_Node {

	/** Issues a team's page asks for, as many as the Linear source's page holds. */
	private const LIMIT = 30;

	/** @var list<string> Team keys the `add_team` verb registered and `remove_team` left, in call order. */
	protected array $teams = [];

	/** Ask each team's newest page. */
	protected function calls(): void {
		foreach ( $this->teams as $team ) {
			$this->call(
				'linear',
				'team-issues',
				[
					'team'  => $team,
					'limit' => self::LIMIT,
				]
			);
		}
	}

	/**
	 * Every status group's issues as items, through the Linear source's
	 * mapping; an issue with no string identifier is skipped.
	 *
	 * @param string                 $subtool The provider's tool, `team-issues`.
	 * @param array<array-key,mixed> $args    The call's arguments.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	protected function answered( string $subtool, array $args, array $answer ): array {
		$issues = [];
		foreach ( Core::arr( $answer['groups'] ?? null ) as $group ) {
			\array_push( $issues, ...\array_values( Core::arr( $group ) ) );
		}
		return self::items(
			$issues,
			function ( array $issue ): ?array {
				$identifier = $issue['identifier'] ?? null;
				if ( ! \is_string( $identifier ) || '' === $identifier ) {
					return null;
				}
				return $this->normalize_item( 'linear', $identifier, $issue['title'] ?? '', $issue['url'] ?? '', $issue['description'] ?? '', $issue['updated_at'] ?? '' );
			}
		);
	}

	public static function node_schema(): array {
		return self::context_schema(
			'Reads the newest issues of the configured Linear teams through ContextA8C, as the person signed in to it on the host, one call a team, without blocking, on a TICK request (request_node linear TICK). Local workstation only.',
			'Start a pass over the teams; each page records its new issues as it lands. Trigger with `request_node linear TICK`.',
			Linear_Source_Node::team_verbs()
		);
	}
}

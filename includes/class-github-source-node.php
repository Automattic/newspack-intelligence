<?php
/**
 * Github_Source_Node: pulls Releases, Merged PRs, and Issues from configured
 * GitHub repos and normalizes them into recorded items.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. The endpoint an answer's url names picks how its rows map to
 * items.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Github_Source_Node extends Source_Node {

	private const API_BASE = 'https://api.github.com/repos/';
	private const PER_PAGE = 30;

	/** Each endpoint a pass fetches per repo, by name, with its query. */
	private const ENDPOINTS = [
		'releases' => 'releases?per_page=' . self::PER_PAGE,
		'pulls'    => 'pulls?state=closed&sort=updated&direction=desc&per_page=' . self::PER_PAGE,
		'issues'   => 'issues?state=all&sort=updated&direction=desc&per_page=' . self::PER_PAGE,
	];

	/** @var list<string> Repos (owner/name) the `add_repo` verb registered and `remove_repo` left, in call order. */
	protected array $repos = [];

	/**
	 * One endpoint's rows as items, by the repo and the endpoint its url
	 * names; a body that is not a JSON list yields none, logged.
	 *
	 * @param string $url  The endpoint's url, as begin() queued it.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$endpoint = '#^' . \preg_quote( self::API_BASE, '#' ) . '([^/]+/[^/]+)/(' . \implode( '|', \array_keys( self::ENDPOINTS ) ) . ')\?#';
		$rows     = $this->json_answer( 'GitHub', $url, $body, static fn ( array $answer ): ?string => \array_is_list( $answer ) ? null : Core::as_string( $answer['message'] ?? null, 'the answer is not a list' ) );
		if ( null === $rows || 1 !== \preg_match( $endpoint, $url, $match ) ) {
			return [];
		}
		return match ( $match[2] ) {
			'releases' => $this->releases( $match[1], $rows ),
			'pulls'    => $this->merged_prs( $match[1], $rows ),
			default    => $this->issues( $match[1], $rows ),
		};
	}

	/**
	 * @param array<mixed> $rows The endpoint's rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function releases( string $repo, array $rows ): array {
		return self::items(
			$rows,
			function ( array $r ) use ( $repo ): ?array {
				$id = self::id_token( $r['id'] ?? null );
				if ( '' === $id ) {
					return null;
				}
				$title = $r['name'] ?? '';
				$title = ( \is_string( $title ) && '' !== $title ) ? $title : (string) ( \is_scalar( $r['tag_name'] ?? null ) ? $r['tag_name'] : '' );
				return $this->normalize_item( 'github', "$repo#release-$id", $title, $r['html_url'] ?? '', $r['body'] ?? '', $r['published_at'] ?? '' );
			}
		);
	}

	/**
	 * @param array<mixed> $rows The endpoint's rows.
	 * @return array<int,array<string,mixed>> Closed PRs that actually merged.
	 */
	private function merged_prs( string $repo, array $rows ): array {
		return self::items(
			$rows,
			function ( array $pr ) use ( $repo ): ?array {
				$number    = self::id_token( $pr['number'] ?? null );
				$merged_at = $pr['merged_at'] ?? null;
				// A closed PR with no merged_at was closed unmerged.
				if ( '' === $number || ! \is_string( $merged_at ) || '' === $merged_at ) {
					return null;
				}
				return $this->normalize_item( 'github', "$repo#pr-$number", $pr['title'] ?? '', $pr['html_url'] ?? '', $pr['body'] ?? '', $merged_at );
			}
		);
	}

	/**
	 * @param array<mixed> $rows The endpoint's rows.
	 * @return array<int,array<string,mixed>> Issues only — the issues endpoint also lists PRs (pull_request key); those are dropped.
	 */
	private function issues( string $repo, array $rows ): array {
		return self::items(
			$rows,
			function ( array $issue ) use ( $repo ): ?array {
				$number = self::id_token( $issue['number'] ?? null );
				if ( '' === $number || isset( $issue['pull_request'] ) ) {
					return null;
				}
				return $this->normalize_item( 'github', "$repo#issue-$number", $issue['title'] ?? '', $issue['html_url'] ?? '', $issue['body'] ?? '', $issue['updated_at'] ?? ( $issue['created_at'] ?? '' ) );
			}
		);
	}

	/** A GitHub id/number as a string token; '' when absent or non-scalar (skip it). */
	private static function id_token( mixed $id ): string {
		return ( \is_int( $id ) || \is_string( $id ) ) ? (string) $id : '';
	}

	/** Queue each repo's three endpoints. */
	protected function begin(): void {
		foreach ( $this->repos as $repo ) {
			foreach ( self::ENDPOINTS as $query ) {
				$this->queue( self::API_BASE . "{$repo}/{$query}" );
			}
		}
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches GitHub Releases, Merged PRs, and Issues for the configured repos without blocking, one request at a time, on a TICK request (request_node github TICK).',
			'Start a pass over the repos; each answer records its new items as it lands. Trigger with `request_node github TICK`.',
			self::checked_list( 'repo', 'repos', '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', 'a GitHub owner/name', 'Register a repo to fetch on TICK: <owner/name>, letters, digits, `_`, `.` and `-` on each side.' )
		);
	}
}

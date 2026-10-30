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

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Github_Source_Node extends Source_Node {

	private const API_BASE = 'https://api.github.com/repos/';
	private const PER_PAGE = 30;

	/** The one argument add_repo and remove_repo take. */
	private const REPO_ARG = [
		'name'     => 'repo',
		'type'     => 'string',
		'required' => true,
	];

	/** An owner/name pair, as add_repo takes it. */
	private const REPO = '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#';

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
	 * names; a body that is not a JSON list yields none.
	 *
	 * @param string $url  The endpoint's url, as begin() queued it.
	 * @param string $body The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $url, string $body ): array {
		$rows     = \json_decode( $body, true );
		$endpoint = '#^' . \preg_quote( self::API_BASE, '#' ) . '([^/]+/[^/]+)/(' . \implode( '|', \array_keys( self::ENDPOINTS ) ) . ')\?#';
		if ( ! \is_array( $rows ) || ! \array_is_list( $rows ) || 1 !== \preg_match( $endpoint, $url, $match ) ) {
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
		$out = [];
		foreach ( $rows as $r ) {
			if ( ! \is_array( $r ) ) {
				continue;
			}
			$id = self::id_token( $r['id'] ?? null );
			if ( '' === $id ) {
				continue;
			}
			$title = $r['name'] ?? '';
			$title = ( \is_string( $title ) && '' !== $title ) ? $title : (string) ( \is_scalar( $r['tag_name'] ?? null ) ? $r['tag_name'] : '' );
			$out[] = $this->normalize_item( 'github', "$repo#release-$id", $title, $r['html_url'] ?? '', $r['body'] ?? '', $r['published_at'] ?? '' );
		}
		return $out;
	}

	/**
	 * @param array<mixed> $rows The endpoint's rows.
	 * @return array<int,array<string,mixed>> Closed PRs that actually merged.
	 */
	private function merged_prs( string $repo, array $rows ): array {
		$out = [];
		foreach ( $rows as $pr ) {
			if ( ! \is_array( $pr ) ) {
				continue;
			}
			$number = self::id_token( $pr['number'] ?? null );
			if ( '' === $number ) {
				continue;
			}
			$merged_at = $pr['merged_at'] ?? null;
			if ( ! \is_string( $merged_at ) || '' === $merged_at ) {
				continue; // Closed but not merged.
			}
			$out[] = $this->normalize_item( 'github', "$repo#pr-$number", $pr['title'] ?? '', $pr['html_url'] ?? '', $pr['body'] ?? '', $merged_at );
		}
		return $out;
	}

	/**
	 * @param array<mixed> $rows The endpoint's rows.
	 * @return array<int,array<string,mixed>> Issues only — the issues endpoint also lists PRs (pull_request key); those are dropped.
	 */
	private function issues( string $repo, array $rows ): array {
		$out = [];
		foreach ( $rows as $issue ) {
			if ( ! \is_array( $issue ) || isset( $issue['pull_request'] ) ) {
				continue;
			}
			$number = self::id_token( $issue['number'] ?? null );
			if ( '' === $number ) {
				continue;
			}
			$ts    = $issue['updated_at'] ?? ( $issue['created_at'] ?? '' );
			$out[] = $this->normalize_item( 'github', "$repo#issue-$number", $issue['title'] ?? '', $issue['html_url'] ?? '', $issue['body'] ?? '', $ts );
		}
		return $out;
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

	/**
	 * The `add_repo` verb's entry point: register an owner/name repo once.
	 *
	 * @param string $repo The repo, `owner/name`.
	 * @return string The verb's result line.
	 * @throws \InvalidArgumentException Naming a value that is not one owner/name pair.
	 */
	public function add_repo( string $repo ): string {
		if ( 1 !== \preg_match( self::REPO, $repo ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \InvalidArgumentException( "add_repo: '{$repo}' is not a GitHub owner/name" );
		}
		if ( ! \in_array( $repo, $this->repos, true ) ) {
			$this->repos[] = $repo;
		}
		return "ok\n";
	}

	/**
	 * `add_repo` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param array<array-key,mixed>   $args        Bound args; `repo` is `owner/name`.
	 * @return string Result line.
	 */
	public static function cmd_add_repo( Command_Interpreter_Node $interpreter, array $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->add_repo( Core::as_string( $args['repo'] ) );
	}

	/** Emit the base config plus one `add_repo` line per registered repo. */
	public function dump_config(): string {
		return parent::dump_config() . \implode( '', \array_map( fn ( string $repo ): string => $this->config_line( 'add_repo', $repo ), $this->repos ) );
	}

	public static function node_schema(): array {
		return self::source_schema(
			'Fetches GitHub Releases, Merged PRs, and Issues for the configured repos without blocking, one request at a time, on a TICK request (request_node github TICK).',
			'Start a pass over the repos; each answer records its new items as it lands. Trigger with `request_node github TICK`.',
			[
				[
					'name'        => 'add_repo',
					'description' => 'Register a repo to fetch on TICK: <owner/name>, letters, digits, `_`, `.` and `-` on each side.',
					'args'        => [ self::REPO_ARG ],
					'multiple'    => true,
					'handler'     => static fn ( Command_Interpreter_Node $interpreter, array $args ): string => self::cmd_add_repo( $interpreter, $args ),
				],
				self::list_verbs( 'repo', 'repos', self::REPO_ARG, 'Register a repo to fetch on TICK: <owner/name>.' )[1],
			]
		);
	}
}

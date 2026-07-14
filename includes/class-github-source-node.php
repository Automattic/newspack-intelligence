<?php
/**
 * Github_Source_Node: pulls Releases, Merged PRs, and Issues from configured
 * GitHub repos and normalizes them into digest items.
 *
 * Extends Source_Node, so the base owns the TICK/TM_REQUEST trigger, dedup, and
 * snapshot; this class supplies only the two seams: config() (Settings read) and
 * fetch() (the blocking GitHub REST calls, behind the $http_get closure seam).
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

class Github_Source_Node extends Source_Node {
	use Vault_Secret;

	private const API_BASE   = 'https://api.github.com';
	private const PER_PAGE   = 10;
	private const USER_AGENT = 'newspack-ai-newsletter';

	/**
	 * libcurl/wp_remote_get call seam. Null by default; the call site then invokes
	 * the real `wp_remote_get`. Tests reassign it (and reset to null in tearDown) to
	 * return canned GitHub JSON WITHOUT short-circuiting header assembly, the
	 * WP_Error / non-200 branches, or the per-endpoint normalization — so all of
	 * that runs as real, covered production code.
	 *
	 * Signature: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var (\Closure( string, array<string,mixed> ): (array<string,mixed>|\WP_Error))|null
	 */
	public static ?\Closure $http_get = null;

	/** @var array<int,string> Repos (owner/name) registered via the `add_repo` verb, in call order. */
	protected array $repos = [];

	/** @var string Vault entry ID registered via the `set_vault_id` verb; resolved to the raw token at config() time. */
	protected string $vault_id = '';

	/**
	 * Fetch Releases + Merged PRs + Issues for every configured repo, normalized to
	 * the item contract {source,id,title,url,body,timestamp}. A failed repo/endpoint
	 * contributes nothing and never throws — one bad repo can't sink the batch.
	 *
	 * @param array<string,mixed> $config {repos: string[], token: string}.
	 * @return array<int,array<string,mixed>>
	 */
	public function fetch( array $config ): array {
		$repos = \is_array( $config['repos'] ?? null ) ? $config['repos'] : [];
		$token = \is_string( $config['token'] ?? null ) ? $config['token'] : '';
		$items = [];
		foreach ( $repos as $repo ) {
			if ( ! \is_string( $repo ) || '' === $repo ) {
				continue;
			}
			$items = \array_merge(
				$items,
				$this->releases( $repo, $token ),
				$this->merged_prs( $repo, $token ),
				$this->issues( $repo, $token ),
			);
		}
		return $items;
	}

	/** @return array<int,array<string,mixed>> */
	private function releases( string $repo, string $token ): array {
		$out = [];
		foreach ( $this->get_json( "/repos/$repo/releases?per_page=" . self::PER_PAGE, $token ) as $r ) {
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

	/** @return array<int,array<string,mixed>> Closed PRs that actually merged. */
	private function merged_prs( string $repo, string $token ): array {
		$out = [];
		foreach ( $this->get_json( "/repos/$repo/pulls?state=closed&sort=updated&direction=desc&per_page=" . self::PER_PAGE, $token ) as $pr ) {
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

	/** @return array<int,array<string,mixed>> Issues only — the issues endpoint also lists PRs (pull_request key); those are dropped. */
	private function issues( string $repo, string $token ): array {
		$out = [];
		foreach ( $this->get_json( "/repos/$repo/issues?state=all&sort=updated&direction=desc&per_page=" . self::PER_PAGE, $token ) as $issue ) {
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

	/**
	 * GET a GitHub REST path and decode a JSON array body. Returns [] on transport
	 * error, non-200, or a non-array body — the caller treats "no items" and "fetch
	 * failed" identically (fire-and-forget).
	 *
	 * @return array<mixed>
	 */
	private function get_json( string $path, string $token ): array {
		$url      = self::API_BASE . $path;
		$args     = $this->request_args( $token );
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- connector fetches run in a background worker, not a VIP web request; the closure seam covers tests.
		$response = null !== self::$http_get ? ( self::$http_get )( $url, $args ) : \wp_remote_get( $url, $args );
		if ( \is_wp_error( $response ) ) {
			$this->print_less_often( 'GitHub fetch failed: ', $response->get_error_message() );
			return [];
		}
		if ( 200 !== (int) \wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}
		$decoded = \json_decode( \wp_remote_retrieve_body( $response ), true );
		return Core::arr( $decoded );
	}

	/**
	 * Build wp_remote_get args. Bearer auth only when a token is set; GitHub
	 * requires a User-Agent.
	 *
	 * @return array{timeout:int,headers:array<string,string>}
	 */
	private function request_args( string $token ): array {
		$headers = [
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => self::USER_AGENT,
		];
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- connector fetches run in a background worker, not a web request.
			'timeout' => 15,
			'headers' => $headers,
		];
	}

	/** @return array{repos:array<int,string>,token:string} */
	protected function config(): array {
		return [
			'repos' => $this->repos,
			'token' => $this->resolve_vault_secret( $this->vault_id ),
		];
	}

	/**
	 * `add_repo` verb handler — appends one owner/name repo to the registered list.
	 *
	 * @param string $args The repo, `owner/name`.
	 * @return string Result line.
	 */
	public function add_repo( string $args ): string {
		$repo = \trim( $args );
		if ( '' === $repo ) {
			return 'error: add_repo requires <owner/name>';
		}
		$this->repos[] = $repo;
		return 'ok';
	}

	/**
	 * `add_repo` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param string                   $args        The repo, `owner/name`.
	 * @return string Result line.
	 */
	public static function cmd_add_repo( Command_Interpreter_Node $interpreter, string $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->add_repo( $args );
	}

	/**
	 * `set_vault_id` verb handler — last-write-wins.
	 *
	 * @param string $args The Vault entry ID.
	 * @return string Result line.
	 */
	public function set_vault_id( string $args ): string {
		$this->vault_id = \trim( $args );
		return 'ok';
	}

	/**
	 * `set_vault_id` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param string                   $args        The Vault entry ID.
	 * @return string Result line.
	 */
	public static function cmd_set_vault_id( Command_Interpreter_Node $interpreter, string $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_vault_id( $args );
	}

	/** Emit the base config plus round-trippable `cmd {name}:config add_repo …` / `set_vault_id …` lines. */
	public function dump_config(): string {
		$out = parent::dump_config();
		foreach ( $this->repos as $repo ) {
			$out .= "cmd {$this->name}:config add_repo {$repo}\n";
		}
		if ( '' !== $this->vault_id ) {
			$out .= "cmd {$this->name}:config set_vault_id {$this->vault_id}\n";
		}
		return $out;
	}

	public static function node_schema(): array {
		return \array_merge(
			self::source_schema(
				'Fetches GitHub Releases, Merged PRs, and Issues for the configured repos on a TICK request (request_node github TICK).',
				'Fetch + emit new GitHub items. Trigger with `request_node github TICK`.'
			),
			[
				'commands' => [
					[
						'name'        => 'add_repo',
						'description' => 'Register a repo to fetch on TICK: <owner/name>.',
						'args'        => [
							[ 'name' => 'repo', 'type' => 'string', 'required' => true ],
						],
						'handler'     => static fn ( Command_Interpreter_Node $interpreter, string $args ): string => self::cmd_add_repo( $interpreter, $args ),
						'multiple'    => true,
					],
					[
						'name'        => 'set_vault_id',
						'description' => 'Set the Vault entry ID to resolve the GitHub token from: <vault_id>.',
						'args'        => [
							[ 'name' => 'vault_id', 'type' => 'vault_id', 'required' => true ],
						],
						'handler'     => static fn ( Command_Interpreter_Node $interpreter, string $args ): string => self::cmd_set_vault_id( $interpreter, $args ),
					],
				],
			]
		);
	}
}

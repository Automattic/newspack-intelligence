<?php
/**
 * Context_A8C_Source_Node: abstract base for the local workstation's
 * sources, which read Slack, P2 and Linear through ContextA8C, Automattic's
 * MCP server, as the person signed in to it on the host.
 *
 * Extends Source_Node, which owns the trigger, the grid, the chain and the
 * record path. The relay's token lives in Context_A8C_Token, the process's
 * one hold on the dir this node's `make_node` line names, never in a node's
 * state: a pass reads it there, and a token due within EXPIRY_MARGIN seconds
 * of its expiry, or one a server refused, is refreshed first, by the one node
 * that claims the refresh, its form built as the request goes out; a node
 * finding another node's refresh in flight waits, and starts its pass on its
 * next tick once the refresh lands. The server refuses a call outside a
 * session, so every pass opens its own: `initialize`, keeping the
 * `Mcp-Session-Id` its response header names and the protocol revision it
 * answers, the `notifications/initialized` the protocol requires, then the
 * calls, and once nothing is left to send, a DELETE closing the session.
 * A request the server answers 401 ends the pass and marks the token stale,
 * and a call answered 400 or 404 ends the pass and drops the session; either
 * way the next pass starts afresh. Every call is one JSON-RPC `tools/call` of
 * `context-a8c-execute-tool`, queued under the subject
 * `<provider>/<subtool>?<args>`, all to one url, carrying the session and the
 * revision; each answer's first text content is the provider's JSON answer,
 * which a subclass maps through answered(). A subclass names its calls in
 * calls().
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;

\defined( 'ABSPATH' ) || exit;

abstract class Context_A8C_Source_Node extends Source_Node {

	/** The MCP server; the relay names its token file by this url's md5. */
	public const MCP_URL = 'https://public-api.wordpress.com/wpcom/v2/context-a8c-mcp/v1';

	/** The token endpoint the authorization server's metadata names. */
	public const TOKEN_URL = 'https://public-api.wordpress.com/oauth2-1/token';

	/** Seconds before its expiry a token is refreshed, so no pass outlives it. */
	public const EXPIRY_MARGIN = 600;

	/** The protocol revision `initialize` asks for. */
	public const PROTOCOL = '2025-06-18';

	/** The subject of the `initialize` request. */
	private const INITIALIZE = 'mcp/initialize';

	/** The subject of the `notifications/initialized` that follows it. */
	private const INITIALIZED = 'mcp/notifications/initialized';

	/** The subject of the DELETE closing a pass's session. */
	private const CLOSE = 'mcp/session/close';

	/** The response header naming the session, which every later request sends. */
	private const SESSION_HEADER = 'Mcp-Session-Id';

	/** The one tool every call runs. */
	private const TOOL = 'context-a8c-execute-tool';

	/** The answers an MCP call accepts; the server answers JSON. */
	private const ACCEPT = 'Accept: application/json, text/event-stream';

	/** The refresh's body type. */
	private const FORM = 'Content-Type: application/x-www-form-urlencoded';

	/** The dir holding the relay's `wordpress-remote-*` token dirs. */
	protected string $auth_dir = '';

	/** The proxy every request goes through; '' for none. */
	protected string $proxy = '';

	/** The session this pass's `initialize` opened; '' for none. */
	private string $session = '';

	/** The protocol revision the server answered `initialize` with; '' for none. */
	private string $revision = '';

	/** Whether this node's pass waits on another node's refresh. */
	private bool $waiting = false;

	/**
	 * Open a pass under the held token, or refresh it first when it is due:
	 * a token another refresh is landing waits, and a missing token, one the
	 * endpoint refused, one with no refresh token or a lock that will not
	 * hold is logged and the pass sends nothing.
	 */
	protected function begin(): void {
		$this->session  = '';
		$this->revision = '';
		$held           = Context_A8C_Token::held( $this->auth_dir );
		if ( null === $held ) {
			$this->print_less_often( 'no readable ContextA8C token: ', Context_A8C_Token::pattern( $this->auth_dir ) );
			return;
		}
		if ( ! Context_A8C_Token::due( $this->auth_dir, Core::$now, self::EXPIRY_MARGIN ) ) {
			$this->open();
			return;
		}
		match ( Context_A8C_Token::claim( $this->auth_dir ) ) {
			Context_A8C_Token::FRESH         => $this->open(),
			Context_A8C_Token::REFRESH       => $this->queue( self::TOKEN_URL, '', self::TOKEN_URL ),
			Context_A8C_Token::WAIT          => $this->waiting = true,
			Context_A8C_Token::REFUSED       => $this->refused( Context_A8C_Token::refused_status( $this->auth_dir ) ),
			Context_A8C_Token::UNREFRESHABLE => $this->print_less_often( 'the ContextA8C token has expired and holds no refresh token; sign in to ContextA8C on the host: ', $held['path'] ),
			default                          => $this->print_less_often( 'could not lock the ContextA8C token: ', $held['path'] ),
		};
	}

	/**
	 * What one answer means: a refresh's new token, then the pass opened;
	 * `initialize`'s answer, then the calls; the notification's and the
	 * close's empty ones; or a call's answer, which answered() maps, its
	 * subtool and its arguments read back off the subject.
	 *
	 * @param string $subject The token url, `initialize`, the notification, the close, or a call's `<provider>/<subtool>?<args>`.
	 * @param string $body    The JSON body.
	 * @return array<int,array<string,mixed>>
	 */
	protected function received( string $subject, string $body ): array {
		if ( self::TOKEN_URL === $subject ) {
			$this->refreshed( $body );
			return [];
		}
		if ( self::INITIALIZE === $subject ) {
			$this->initialized( $body );
			return [];
		}
		if ( self::CLOSE === $subject ) {
			$this->session  = '';
			$this->revision = '';
			return [];
		}
		if ( self::INITIALIZED === $subject ) {
			return [];
		}
		$answer = $this->tool_answer( $subject, $body );
		if ( null === $answer ) {
			return [];
		}
		[ $call, $query ] = \explode( '?', $subject, 2 ) + [ '', '' ];
		\parse_str( $query, $args );
		return $this->answered( \explode( '/', $call, 2 )[1] ?? '', $args, $answer );
	}

	/**
	 * Take the token endpoint's answer through the token store, then open
	 * the pass; an answer holding no access token, or a token that will not
	 * reach the file, is logged.
	 *
	 * @param string $body The JSON body.
	 */
	private function refreshed( string $body ): void {
		[ $landed, $written ] = Context_A8C_Token::landed( $this->auth_dir, $body, Core::$now );
		if ( ! $landed ) {
			$this->print_less_often( 'the token endpoint answered no access token: ', self::TOKEN_URL );
			return;
		}
		if ( ! $written ) {
			$this->print_less_often( 'could not write the refreshed ContextA8C token back: ', Context_A8C_Token::pattern( $this->auth_dir ) );
		}
		$this->open();
	}

	/** Queue the pass's `initialize`, which opens its session. */
	private function open(): void {
		$envelope = [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => [
				'protocolVersion' => self::PROTOCOL,
				'capabilities'    => new \stdClass(),
				'clientInfo'      => [
					'name'    => 'newspack-intelligence',
					'version' => \NEWSPACK_INTELLIGENCE_VERSION,
				],
			],
		];
		$this->queue( self::MCP_URL, (string) \wp_json_encode( $envelope ), self::INITIALIZE );
	}

	/**
	 * Take `initialize`'s answer: with the session its header named, keep the
	 * revision it answers, send the notification, then the calls; with none,
	 * or with no revision, log and send nothing.
	 *
	 * @param string $body The JSON-RPC body.
	 */
	private function initialized( string $body ): void {
		$answer = $this->json_answer( 'ContextA8C', self::INITIALIZE, $body, static fn ( array $answer ): ?string => isset( $answer['error'] ) ? Core::as_string( Core::arr( $answer['error'] )['message'] ?? null, 'a JSON-RPC error' ) : ( \is_string( Core::arr( $answer['result'] ?? null )['protocolVersion'] ?? null ) ? null : 'initialize answered no protocolVersion' ) );
		if ( null === $answer ) {
			return;
		}
		if ( '' === $this->session ) {
			$this->print_less_often( 'ContextA8C answered initialize with no Mcp-Session-Id: ', self::INITIALIZE );
			return;
		}
		$this->revision = Core::as_string( Core::arr( $answer['result'] ?? null )['protocolVersion'] ?? null );
		$notification   = [
			'jsonrpc' => '2.0',
			'method'  => 'notifications/initialized',
		];
		$this->queue( self::MCP_URL, (string) \wp_json_encode( $notification ), self::INITIALIZED );
		$this->calls();
	}

	/** Queue the pass's calls through call(). */
	abstract protected function calls(): void;

	/**
	 * A call's answer: the provider's JSON object from the first text
	 * content, or null, logged under the subject, for a JSON-RPC error, an
	 * error result or a text that is no JSON object.
	 *
	 * @param string $subject The call's subject.
	 * @param string $body    The JSON-RPC body.
	 * @return array<array-key,mixed>|null
	 */
	private function tool_answer( string $subject, string $body ): ?array {
		$envelope = $this->json_answer( 'ContextA8C', $subject, $body, static fn ( array $envelope ): ?string => self::fault( $envelope ) );
		return null === $envelope ? null : Core::arr( \json_decode( self::text( $envelope ), true ) );
	}

	/**
	 * What is wrong with a JSON-RPC answer, or null when its text content is
	 * a JSON object.
	 *
	 * @param array<array-key,mixed> $envelope The decoded answer.
	 */
	private static function fault( array $envelope ): ?string {
		if ( isset( $envelope['error'] ) ) {
			return Core::as_string( Core::arr( $envelope['error'] )['message'] ?? null, 'a JSON-RPC error' );
		}
		$text = self::text( $envelope );
		if ( true === ( Core::arr( $envelope['result'] ?? null )['isError'] ?? null ) ) {
			return $text;
		}
		$answer = \json_decode( $text, true );
		return \is_array( $answer ) && ! \array_is_list( $answer ) ? null : 'the text content holds no JSON object';
	}

	/**
	 * A result's first text content; '' for none.
	 *
	 * @param array<array-key,mixed> $envelope The decoded answer.
	 */
	private static function text( array $envelope ): string {
		foreach ( Core::arr( Core::arr( $envelope['result'] ?? null )['content'] ?? null ) as $content ) {
			$content = Core::arr( $content );
			if ( 'text' === ( $content['type'] ?? null ) ) {
				return Core::str( $content['text'] ?? null );
			}
		}
		return '';
	}

	/**
	 * What one call's answer means: the items to record, normalized, queuing
	 * through call() any call it names next.
	 *
	 * @param string                 $subtool The provider's tool the call ran.
	 * @param array<array-key,mixed> $args    Its arguments, as the subject spells them.
	 * @param array<array-key,mixed> $answer  The provider's answer.
	 * @return array<int,array<string,mixed>>
	 */
	abstract protected function answered( string $subtool, array $args, array $answer ): array;

	/**
	 * Act on a request the server refused: a refresh ends in the token
	 * store, logged when refused with 400 or 401; a close forgets the
	 * session; any other MCP request answered 401 ends the pass and marks the
	 * token stale, and one answered 400 or 404 under a session ends the pass
	 * and drops the session, each logged, so the next pass starts afresh.
	 *
	 * @param string $subject The request's subject.
	 * @param string $error   The TM_ERROR's text.
	 */
	protected function failed( string $subject, string $error ): void {
		$status = 1 === \preg_match( '/^HTTP (\d{3}) /', $error, $match ) ? (int) $match[1] : 0;
		if ( self::TOKEN_URL === $subject ) {
			Context_A8C_Token::failed( $this->auth_dir, $status );
			if ( 400 === $status || 401 === $status ) {
				$this->refused( $status );
			}
			return;
		}
		$refused = 401 === $status || ( '' !== $this->session && ( 400 === $status || 404 === $status ) );
		if ( self::CLOSE !== $subject && ! $refused ) {
			return;
		}
		$this->session  = '';
		$this->revision = '';
		if ( self::CLOSE === $subject ) {
			return;
		}
		$this->end_pass();
		if ( 401 === $status ) {
			Context_A8C_Token::stale( $this->auth_dir );
			$this->print_less_often( 'ContextA8C refused the token; refreshing it next pass: ', Core::terminal_safe( $error ) );
			return;
		}
		$this->print_less_often( 'ContextA8C refused the session; initializing again next pass: ', Core::terminal_safe( $error ) );
	}

	/** Log a refresh the endpoint refused, once a window whatever its status. */
	private function refused( int $status ): void {
		$this->print_less_often( 'ContextA8C refresh refused ', "(HTTP {$status}): sign in to ContextA8C again on the host" );
	}

	/** Resume, once, the pass another node's refresh held back. */
	protected function resumes(): bool {
		$resume        = $this->waiting;
		$this->waiting = false;
		return $resume;
	}

	/** Close the pass's session, once nothing is left to send, with a DELETE naming it. */
	protected function pass_ended(): void {
		if ( '' !== $this->session ) {
			$this->send_closing( self::MCP_URL, null, self::CLOSE );
		}
	}

	/** Release the refresh claim of a refresh that will never answer. */
	protected function abandoned( string $subject ): void {
		if ( self::TOKEN_URL === $subject ) {
			Context_A8C_Token::failed( $this->auth_dir, 0 );
		}
	}

	/**
	 * Keep the session `initialize`'s response header names.
	 *
	 * @param string                $subject The request's subject.
	 * @param array<string,string> $headers Each header the response sent.
	 */
	protected function headers_received( string $subject, array $headers ): void {
		if ( self::INITIALIZE === $subject ) {
			$this->session = $headers[ \strtolower( self::SESSION_HEADER ) ] ?? '';
		}
	}

	/**
	 * Queue one `tools/call` of the tool, under the subject naming it.
	 *
	 * @param string              $provider The provider: slack, wpcom or linear.
	 * @param string              $subtool  The provider's tool.
	 * @param array<string,mixed> $args     Its arguments.
	 */
	protected function call( string $provider, string $subtool, array $args ): void {
		$envelope = [
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'tools/call',
			'params'  => [
				'name'      => self::TOOL,
				'arguments' => [
					'provider'     => $provider,
					'subtool'      => $subtool,
					'subtool_args' => $args,
				],
			],
		];
		$this->queue( self::MCP_URL, (string) \wp_json_encode( $envelope ), "{$provider}/{$subtool}?" . \http_build_query( $args ) );
	}

	/**
	 * A refresh sends its form, built from the token store as it goes out,
	 * and its type; every MCP request sends the token as a bearer and accepts
	 * JSON or a stream, `initialize` reading the session header back and every
	 * later one sending the session and the revision, the close as a DELETE;
	 * each goes through the proxy when one is named.
	 *
	 * @param string $subject The request's subject.
	 * @return array<string,mixed>
	 */
	protected function request_options( string $subject ): array {
		$proxy = '' === $this->proxy ? [] : [ 'proxy' => $this->proxy ];
		if ( self::TOKEN_URL === $subject ) {
			return [
				'body'    => Context_A8C_Token::form( $this->auth_dir ),
				'headers' => [ self::FORM ],
			] + $proxy;
		}
		$headers = [ 'Authorization: Bearer ' . Context_A8C_Token::access( $this->auth_dir ), self::ACCEPT ];
		if ( self::INITIALIZE === $subject ) {
			return [
				'headers'          => $headers,
				'response_headers' => [ self::SESSION_HEADER ],
			] + $proxy;
		}
		if ( '' !== $this->session ) {
			$headers[] = self::SESSION_HEADER . ": {$this->session}";
		}
		if ( '' !== $this->revision ) {
			$headers[] = "MCP-Protocol-Version: {$this->revision}";
		}
		return [ 'headers' => $headers ] + ( self::CLOSE === $subject ? [ 'method' => 'DELETE' ] : [] ) + $proxy;
	}

	/**
	 * A ContextA8C source's node_schema: the Source shape, with no vault
	 * group, the token dir after the tier, and the proxy after it.
	 *
	 * @param list<array<string,mixed>> $commands The source's own verbs.
	 * @return array<string,mixed>
	 */
	protected static function context_schema( string $description, string $tick_description, array $commands ): array {
		$schema              = self::source_schema( $description, $tick_description, $commands, false );
		$schema['arguments'] = [
			...\array_values( Core::arr( $schema['arguments'] ?? null ) ),
			[ 'name' => 'auth_dir', 'type' => 'string', 'required' => true, 'description' => 'The dir holding the ContextA8C relay\'s wordpress-remote-* token dirs, the host\'s ~/.mcp-auth as the container mounts it.' ],
			[ 'name' => 'proxy', 'type' => 'string', 'default' => '', 'description' => 'Optional proxy every request goes through, <scheme>://<host>:<port>, as Curl takes it: the Automattic proxy\'s SOCKS tunnel, which the server requires.' ],
		];
		return $schema;
	}
}

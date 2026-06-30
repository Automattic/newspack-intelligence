<?php
/**
 * AI API Proxy LLM client (OpenAI chat/completions shape).
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

/**
 * Posts chat-completion requests to the Automattic AI API Proxy and returns the
 * assistant message content.
 */
final class Proxy_LLM_Client implements LLM_Client {
	/**
	 * `wp_remote_post` call seam. Null by default; when null the call site
	 * invokes the real `wp_remote_post`. Tests reassign this (and reset to null
	 * in tearDown) to capture the outbound request and supply a canned response
	 * WITHOUT short-circuiting the rest of `chat()` — so header/body assembly,
	 * the WP_Error / non-200 branches, and the `choices[0].message.content`
	 * parsing all run as real, covered production code.
	 *
	 * Signature: `function ( string $url, array $args ): array|\WP_Error`.
	 *
	 * @var (\Closure( string, array<string,mixed> ): (array<string,mixed>|\WP_Error))|null
	 */
	public static ?\Closure $http_post = null;

	/**
	 * @param string $base_url AI proxy base URL (e.g. https://proxy/v1).
	 * @param string $token    Bearer token.
	 * @param string $model    Model id.
	 * @param string $feature  X-WPCOM-AI-Feature value.
	 */
	public function __construct(
		private string $base_url,
		private string $token,
		private string $model,
		private string $feature
	) {}

	public function chat( array $messages, array $opts = [] ): string {
		$body = \wp_json_encode( \array_merge(
			[
				'model'    => $this->model,
				'messages' => $messages,
			],
			$opts
		) );

		$url      = \rtrim( $this->base_url, '/' ) . '/chat/completions';
		$args     = $this->request_args( (string) $body );
		$response = null !== self::$http_post
			? ( self::$http_post )( $url, $args )
			: \wp_remote_post( $url, $args );

		if ( \is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \RuntimeException( 'AI proxy request failed: ' . $response->get_error_message() );
		}
		$code = (int) \wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- plain-text message for log/CLI consumers; escape at the view, not the runtime.
			throw new \RuntimeException( "AI proxy returned HTTP $code" );
		}
		$decoded = \json_decode( \wp_remote_retrieve_body( $response ), true );
		$content = self::extract_content( $decoded );
		if ( ! \is_string( $content ) ) {
			throw new \RuntimeException( 'AI proxy response missing choices[0].message.content' );
		}
		return $content;
	}

	/**
	 * Build the `wp_remote_post` request args for a chat-completions call.
	 *
	 * @return array{timeout:int,headers:array<string,string>,body:string}
	 */
	private function request_args( string $body ): array {
		return [
			// phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout -- LLM completions are slow; the proxy needs a generous budget.
			'timeout' => 120,
			'headers' => [
				'Authorization'      => 'Bearer ' . $this->token,
				'Content-Type'       => 'application/json',
				'X-WPCOM-AI-Feature' => $this->feature,
			],
			'body'    => $body,
		];
	}

	/**
	 * Pull `choices[0].message.content` out of a decoded chat-completions body.
	 *
	 * @param mixed $decoded Decoded JSON response body.
	 */
	private static function extract_content( mixed $decoded ): ?string {
		$choices = \is_array( $decoded ) ? ( $decoded['choices'] ?? null ) : null;
		$choice  = \is_array( $choices ) ? ( $choices[0] ?? null ) : null;
		$message = \is_array( $choice ) ? ( $choice['message'] ?? null ) : null;
		$content = \is_array( $message ) ? ( $message['content'] ?? null ) : null;
		return \is_string( $content ) ? $content : null;
	}
}

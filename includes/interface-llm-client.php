<?php
/**
 * LLM client contract for the AI API Proxy.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

\defined( 'ABSPATH' ) || exit;

/**
 * A chat-completions client. Implementations target the Automattic AI API Proxy
 * (OpenAI `chat/completions` shape).
 */
interface LLM_Client {
	/**
	 * Send a chat-completion request and return the assistant message content.
	 *
	 * @param array<int,array{role:string,content:string}> $messages Chat messages.
	 * @param array<string,mixed>                          $opts     e.g. temperature, max_tokens.
	 */
	public function chat( array $messages, array $opts = [] ): string;
}

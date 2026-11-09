<?php
/**
 * Llm_Answering_Sink: a caller's sink that stands in for `intel:llm`,
 * answering each request it is sent TO its FROM at once, as LLM_Call_Node
 * answers, and capturing every other message.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Operator;
use Newspack_Intelligence\Prompts;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Tests\Capture_Sink_Node;

class Llm_Answering_Sink extends Capture_Sink_Node {

	/** The node a caller's requests are addressed to. */
	public const LLM = 'intel:llm';

	/** The Vault group an unlent answer names. */
	public const GROUP = 'ai-proxy-test-9';

	/**
	 * What every answer says: `content`, `error` or `unlent`; the group lends
	 * nothing until a test says otherwise.
	 *
	 * @var array<string,mixed>
	 */
	public array $outcome = [ 'unlent' => self::GROUP ];

	/**
	 * What an answer to a named prompt says instead, by prompt.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $outcomes = [];

	/** @var list<array<int,mixed>> Every request sent to `intel:llm`, in order. */
	public array $requests = [];

	/**
	 * Answer a request to `intel:llm`; capture anything else.
	 *
	 * @param array<int,mixed> $message The message.
	 */
	public function fill( array $message ): void {
		if ( self::LLM !== $message[ Message::TO ] ) {
			parent::fill( $message );
			return;
		}
		$this->requests[]          = $message;
		$answer                    = Message::new_message();
		$answer[ Message::TYPE ]   = Message::TM_STRUCT | Message::TM_RESPONSE;
		$answer[ Message::FROM ]   = self::LLM;
		$answer[ Message::KEY ]    = $message[ Message::KEY ];
		$answer[ Message::VALUE ]  = ( $this->outcomes[ $message[ Message::VALUE ]['prompt'] ] ?? $this->outcome ) + [ 'item' => $message[ Message::VALUE ]['item'], 'inputs' => $message[ Message::VALUE ]['inputs'], 'carry' => $message[ Message::VALUE ]['carry'] ];
		Core::node( Core::as_string( $message[ Message::FROM ] ) )?->fill( $answer );
	}

	/** @return list<array<int,array{role:string,content:string}>> Each request's messages, built as `intel:llm` builds them. */
	public function asked(): array {
		return \array_map( static fn ( array $m ): array => Prompts::messages( $m[ Message::VALUE ]['prompt'], $m[ Message::VALUE ]['item'], $m[ Message::VALUE ]['inputs'], new Operator( '', '' ) ), $this->requests );
	}
}

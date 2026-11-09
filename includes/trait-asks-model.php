<?php
/**
 * Asks_Model: the caller's half of the model-call protocol `LLM_Call_Node`
 * answers, shared by every node that asks it.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Event_Framework;
use Newspack_Nodes\Message;
use Newspack_Nodes\Worker_Should_Stop;

\defined( 'ABSPATH' ) || exit;

/**
 * A request goes to the model-call node the `make_node` line names as
 * `llm`, KEY the item's key, the item beside the carry; its answer comes
 * back TO this node as a TM_STRUCT|TM_RESPONSE, read here, and is finished
 * by the using node, which parks the item when finishing throws, so
 * nothing throws into the event loop but a cooperative stop (ADR-14). A
 * using node also uses `Emits_Struct`.
 */
trait Asks_Model {

	/** The model-call node every request is asked of. */
	protected string $llm = '';

	/**
	 * Whether a message is an answer from the model-call node.
	 *
	 * @param array<int,mixed> $message The message.
	 */
	private static function is_answer( array $message ): bool {
		return 0 !== ( Core::int( $message[ Message::TYPE ] ) & Message::TM_RESPONSE );
	}

	/**
	 * Ask the model-call node for one call: `$prompt` built over `$item` and
	 * `$inputs`, the answer bringing back the item, the inputs and `$carry`.
	 *
	 * @param string                 $prompt  One of `Prompts::PROMPTS`.
	 * @param array<string,mixed>    $item    The item.
	 * @param array<string,mixed>    $inputs  The prompt's other inputs.
	 * @param array<string,int|float> $options `max_tokens` and `temperature`, sent by a chat-completions wire alone.
	 * @param array<array-key,mixed> $carry   What the answer needs that the item and inputs do not hold.
	 * @param bool                   $backlog Whether it waits behind every new request.
	 */
	private function ask_model( string $prompt, array $item, array $inputs, array $options, array $carry, bool $backlog ): void {
		$request = [
			'prompt'  => $prompt,
			'item'    => $item,
			'inputs'  => $inputs,
			'options' => $options,
			'carry'   => $carry,
			'backlog' => $backlog,
		];
		$this->emit( $this->llm, $request, Core::as_string( $item['item_key'] ?? null ) );
	}

	/**
	 * An answer read: its VALUE, its item, its inputs and its carry; null,
	 * logged, for one naming no content, decision, error or unlent group, or
	 * carrying any of the three as anything but an array.
	 *
	 * @param array<int,mixed> $message The answer.
	 * @return array{0:array<array-key,mixed>,1:array<array-key,mixed>,2:array<array-key,mixed>,3:array<array-key,mixed>}|null
	 */
	private function answer_of( array $message ): ?array {
		$answer = Core::arr( $message[ Message::VALUE ] );
		$item   = $answer['item'] ?? null;
		$inputs = $answer['inputs'] ?? null;
		$carry  = $answer['carry'] ?? null;
		$says   = \is_string( $answer['content'] ?? $answer['error'] ?? $answer['unlent'] ?? null ) || \is_array( $answer['decision'] ?? null );
		if ( ! \is_array( $item ) || ! \is_array( $inputs ) || ! \is_array( $carry ) || ! $says ) {
			$this->unreadable( $message );
			return null;
		}
		return [ $answer, $item, $inputs, $carry ];
	}

	/**
	 * Log, rate-limited, an answer the node cannot read, which it drops.
	 *
	 * @param array<int,mixed> $message The answer.
	 */
	private function unreadable( array $message ): void {
		$this->print_less_often( 'dropping an answer it cannot read from ', Core::terminal_safe( Core::as_string( $message[ Message::FROM ] ) ) );
	}

	/**
	 * Finish an answer as one unit a cooperative stop may not split, since
	 * the model call is settled and nothing replays its writes; a stop due
	 * inside raises at the first check after it. One whose finish throws is
	 * logged, rate-limited, and its item parked, but for a cooperative stop.
	 *
	 * @param string   $item_key The item.
	 * @param \Closure $finish   Finishes the answer: `function (): void`.
	 * @param \Closure $park     Parks the item: `function (): void`.
	 * @throws Worker_Should_Stop When the worker is asked to stop.
	 */
	private function finish_answer( string $item_key, \Closure $finish, \Closure $park ): void {
		try {
			Event_Framework::instance()->uninterruptible( $finish );
		} catch ( Worker_Should_Stop $stop ) {
			throw $stop;
		} catch ( \Throwable $e ) {
			$this->print_less_often( 'an answer failed for ' . Core::terminal_safe( $item_key ) . ': ', Core::terminal_safe( $e->getMessage() ) );
			$park();
		}
	}
}

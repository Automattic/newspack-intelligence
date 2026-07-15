<?php
/**
 * Gate_Node: runs the intake Gate (Publisher_Matcher) on each ingested item and emits a
 * decision record. A Transform node modeled on Summarizer_Node — LLM_Config supplies the
 * optional NER extractor's client (no client => deterministic-only gate); a static
 * matcher-factory seam lets tests inject an in-memory matcher.
 *
 * @package Newspack_AI_Newsletter
 */

namespace Newspack_AI_Newsletter;

use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Node;
use Newspack_Nodes\Message;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

class Gate_Node extends Node {
	use Schema_Reflection;
	use LLM_Config;

	/**
	 * Matcher-factory seam (mirrors Summarizer_Node::$llm_factory). Null => build the
	 * production matcher from this node's config. Tests reassign, then reset in tearDown.
	 *
	 * @var (\Closure(): Publisher_Matcher)|null
	 */
	public static ?\Closure $matcher_factory = null;

	/** @var string Config/CSV version stamped onto every decision. */
	protected string $config_version = '';

	/** Production matcher, built once per node so its publisher-set memoization spans items. */
	private ?Publisher_Matcher $matcher = null;

	/** Tachikoma-parity: no-arg ctor wires the sibling :config interpreter. */
	public function __construct() {
		parent::__construct();
		$this->auto_wire_interpreter();
	}

	public function fill( array $message ): void {
		/** @var int $type */
		$type = $message[ Message::TYPE ];
		// Forward control signals (a source's DONE) unchanged, like Summarizer.
		if ( $type & Message::TM_INFO ) {
			parent::fill( $message );
			return;
		}
		if ( ! ( $type & Message::TM_STRUCT ) ) {
			return;
		}
		$item = $message[ Message::VALUE ];
		if ( ! \is_array( $item ) ) {
			return;
		}
		/** @var array<string,mixed> $item */
		$decision       = $this->matcher()->match( $item );
		$decision['ts'] = \gmdate( 'c' ); // Persist-time stamp; the pure matcher stays timestamp-free.
		$this->set_state( 'GATED:' . Core::as_string( $decision['decision'] ), Core::as_string( $decision['item_id'] ) );

		$out                   = Message::new_message();
		$out[ Message::TYPE ]  = Message::TM_STRUCT;
		$out[ Message::FROM ]  = $this->name;
		$out[ Message::VALUE ] = $decision;
		parent::fill( $out );
	}

	/**
	 * The matcher to use for an item. Tests inject via the factory (honored each call);
	 * production builds once and reuses, so the matcher's publisher-set memoization spans
	 * the whole collect rather than reloading the store per item.
	 */
	private function matcher(): Publisher_Matcher {
		if ( null !== self::$matcher_factory ) {
			return ( self::$matcher_factory )();
		}
		return $this->matcher ??= $this->make_matcher();
	}

	/** Production matcher: CPT store + optional LLM NER extractor (null when no client resolves). */
	private function make_matcher(): Publisher_Matcher {
		$client    = $this->make_llm_client();
		$extractor = $client instanceof LLM_Client ? new LLM_Entity_Extractor( $client ) : null;
		return new Publisher_Matcher( new CPT_Publisher_Repository(), $this->config_version, $extractor );
	}

	/** `set_config_version` verb handler — last-write-wins. */
	public function set_config_version( string $args ): string {
		$this->config_version = \trim( $args );
		return 'ok';
	}

	/**
	 * `set_config_version` verb dispatch — resolves the patron node and delegates.
	 *
	 * @param Command_Interpreter_Node $interpreter The sibling `:config` interpreter.
	 * @param string                   $args        The config/CSV version.
	 * @return string Result line.
	 */
	public static function cmd_set_config_version( Command_Interpreter_Node $interpreter, string $args ): string {
		/** @var self $patron */
		$patron = $interpreter->patron();
		return $patron->set_config_version( $args );
	}

	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Runs the intake Gate (Publisher_Matcher) on each item; emits a {decision,...} record. Source-agnostic.',
			'arguments'    => [],
			'commands'     => \array_merge(
				self::llm_config_commands(),
				[
					[
						'name'        => 'set_config_version',
						'description' => 'Stamp this config/CSV version onto every gate decision: <version>.',
						'args'        => [
							[ 'name' => 'version', 'type' => 'string', 'required' => true ],
						],
						'handler'     => static fn ( Command_Interpreter_Node $interpreter, string $args ): string => self::cmd_set_config_version( $interpreter, $args ),
					],
				]
			),
			'accepts_fill' => true,
			'has_target'   => true,
		];
	}
}

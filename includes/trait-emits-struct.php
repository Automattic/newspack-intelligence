<?php
/**
 * Emits_Struct: how a pipeline node sends a struct on, through its sink, and
 * the destinations it addresses by TO, which its `make_node` line names.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;

\defined( 'ABSPATH' ) || exit;

trait Emits_Struct {

	/**
	 * Send one TM_STRUCT from this node through the sink: to `$to`, or to
	 * the target when '', keyed by `$key`.
	 *
	 * @param string              $to    The destination, or '' for the target.
	 * @param array<string,mixed> $value The VALUE.
	 * @param string              $key   The KEY, '' for none.
	 */
	private function emit( string $to, array $value, string $key = '' ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = $this->name;
		$message[ Message::TO ]    = $to;
		$message[ Message::KEY ]   = $key;
		$message[ Message::VALUE ] = $value;
		parent::fill( $message );
	}

	/**
	 * The destinations this node addresses by TO: the leading arguments its
	 * schema types `node_name`.
	 *
	 * @param list<string> $args The `make_node` argument tokens.
	 * @return list<string>
	 */
	public static function declared_targets( array $args ): array {
		$named = 0;
		foreach ( Core::arr( static::node_schema()['arguments'] ?? null ) as $argument ) {
			if ( 'node_name' !== ( Core::arr( $argument )['type'] ?? null ) ) {
				break;
			}
			++$named;
		}
		return \array_slice( $args, 0, $named );
	}
}

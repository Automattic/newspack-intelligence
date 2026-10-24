<?php
/**
 * Intel_Event_Writer_Node: addresses each event by TO to the partition its
 * `make_node` line names for the stream its kind belongs to, and forwards it
 * through its sink, which in a graph reaches `_router`. It routes and nothing
 * more: make() validated the event when it was minted, and the projector
 * validates it again where it is read.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node;
use Newspack_Nodes\Schema_Reflection;

\defined( 'ABSPATH' ) || exit;

class Intel_Event_Writer_Node extends Node {
	use Schema_Reflection;
	use Emits_Struct;

	/** The partition an items-stream event is addressed to. */
	protected string $items_partition = '';

	/** The partition an ontology-stream event is addressed to. */
	protected string $ontology_partition = '';

	/**
	 * Route one TM_STRUCT event to its stream's partition. A message that is
	 * not an event, or names no kind, is dropped with a rate-limited line.
	 *
	 * @param array<int,mixed> $message Incoming Message.
	 */
	public function fill( array $message ): void {
		$event = $message[ Message::VALUE ];
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) || ! \is_array( $event ) ) {
			$this->print_less_often( 'dropping a message that is not an event from ', Core::as_string( $message[ Message::FROM ] ) );
			return;
		}
		$kind = Core::as_string( $event['event'] ?? null );
		if ( ! isset( Intel_Event::KINDS[ $kind ] ) ) {
			$this->print_less_often( 'dropping an event of no known kind: ', $kind );
			return;
		}
		$message[ Message::TO ] = Intel_Event::ITEMS === Intel_Event::stream_of( $kind ) ? $this->items_partition : $this->ontology_partition;
		parent::fill( $message );
	}

	/** @return array<string,mixed> */
	public static function node_schema(): array {
		return [
			'category'     => 'Transform',
			'description'  => 'Writes each intelligence event to the items or ontology partition by its kind.',
			'arguments'    => [
				[ 'name' => 'items_partition', 'type' => 'node_name', 'required' => true, 'description' => 'The partition items-stream events are written to: intel:items.' ],
				[ 'name' => 'ontology_partition', 'type' => 'node_name', 'required' => true, 'description' => 'The partition ontology-stream events are written to: intel:ontology.' ],
			],
			'commands'     => [],
			'accepts_fill' => true,
			'has_target'   => false,
		];
	}
}

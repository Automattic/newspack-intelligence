<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Projector_Node;
use Newspack_Nodes\Command_Interpreter_Node;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Node_Names;
use Newspack_Nodes\Router_Node;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;

/**
 * The projection fixture: an intel-graph Row_Table and the projector writing
 * it, both answering the Router as the project topology wires them, plus
 * delivery and a row read off partition 0's file.
 */
trait Projects_Intel_Graph {

	/**
	 * A Row_Table and its projector, on the Router and interpreter the graph
	 * already holds or new ones.
	 *
	 * @param string $table     The Row_Table's name.
	 * @param string $projector The projector's name.
	 */
	private function projector_graph( string $table = 'intel-graph', string $projector = 'projector' ): Projector_Node {
		$interpreter = Core::node( Node_Names::COMMAND_INTERPRETER );
		if ( ! $interpreter instanceof Command_Interpreter_Node ) {
			$router = new Router_Node();
			$router->name( Node_Names::ROUTER );
			$interpreter = new Command_Interpreter_Node();
			$interpreter->name( Node_Names::COMMAND_INTERPRETER );
			$interpreter->sink( $router );
		}
		$graph = new Row_Table_Node();
		$graph->name( $table );
		$graph->arguments( [ 'Intel_Graph' ] );
		$graph->sink( $interpreter );
		$node = new Projector_Node();
		$node->name( $projector );
		$node->arguments( [] );
		$node->set_graph_target( $table );
		$node->sink( $interpreter );
		return $node;
	}

	/**
	 * Deliver one VALUE as a Consumer does: a TM_STRUCT record.
	 *
	 * @param Projector_Node $projector The projector.
	 * @param mixed          $value     The event.
	 */
	private static function deliver( Projector_Node $projector, mixed $value ): void {
		$message                   = Message::new_message();
		$message[ Message::TYPE ]  = Message::TM_STRUCT;
		$message[ Message::FROM ]  = 'intel:items:consumer';
		$message[ Message::VALUE ] = $value;
		$projector->fill( $message );
	}

	/**
	 * Rows of one table in a graph's partition-0 file; none before it exists.
	 *
	 * @param string $table   The table.
	 * @param string $order   ORDER BY.
	 * @param string $columns The columns.
	 * @param string $graph   The Row_Table.
	 * @return list<list<mixed>>
	 */
	private static function graph_rows( string $table, string $order = '1', string $columns = '*', string $graph = 'intel-graph' ): array {
		$file = Table_Node::file( $graph, 0 );
		if ( ! \is_file( $file ) ) {
			return [];
		}
		return Sqlite_Arm::open_rows( [ 0 => $file ], Intel_Graph_Row_Schema::version() )->rows( "SELECT {$columns} FROM p0.{$table} ORDER BY {$order}", [] );
	}
}

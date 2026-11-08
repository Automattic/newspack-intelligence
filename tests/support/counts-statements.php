<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Table_Node;

/**
 * How many statements a call prepares against the projection, each read
 * through `Intel_Graph_Row_Schema::$read_rows` on a connection to partition
 * 0's file that counts them.
 */
trait Counts_Statements {

	/**
	 * The statements a call prepares.
	 *
	 * @param \Closure(): mixed $call The call.
	 */
	private function statements_of( \Closure $call ): int {
		$count                             = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$count ): mixed {
			$db = new Counting_PDO( 'sqlite::memory:', null, null, [ \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION ] );
			$db->exec( 'ATTACH DATABASE ' . $db->quote( Table_Node::file( 'intel-graph', 0 ) ) . ' AS p0' );
			try {
				return $read( new Row_Reader( $db, [ 'p0' ] ) );
			} finally {
				$count += $db->prepared;
			}
		};
		try {
			$call();
		} finally {
			Intel_Graph_Row_Schema::$read_rows = null;
		}
		return $count;
	}
}

/** A connection counting the statements it prepares, and keeping each. */
final class Counting_PDO extends \PDO {

	/** How many statements it has prepared. */
	public int $prepared = 0;

	/** @var list<string> Each statement it has prepared, in order. */
	public array $queries = [];

	/**
	 * Prepare a statement, counting it.
	 *
	 * @param string             $query   The statement.
	 * @param array<int,mixed>   $options Driver options.
	 */
	public function prepare( string $query, array $options = [] ): \PDOStatement|false {
		++$this->prepared;
		$this->queries[] = $query;
		return parent::prepare( $query, $options );
	}
}

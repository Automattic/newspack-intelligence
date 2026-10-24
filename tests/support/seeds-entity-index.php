<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Entity_Index;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Intelligence\Projector_Node;
use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Sqlite_Arm;
use Newspack_Nodes\Table_Node;
use Newspack_Nodes\Topology_Registry;

require_once __DIR__ . '/projects-intel-graph.php';

/**
 * The entity fixture: ontology events projected into an intel-graph file
 * and read back as the Entity_Index the resolver matches against, so every
 * golden case runs through the projection the index reads in production.
 */
trait Seeds_Entity_Index {
	use Projects_Intel_Graph;

	/** The projector the fixture entities are written through. */
	private ?Projector_Node $seeder = null;

	/** A runtime base, the plugin's namespace and a projector to seed with. */
	private function start_entity_graph(): void {
		$this->use_base_dir( $this->make_temp_dir( 'intel-entities-' ) );
		Core::$var['partition'] = '0';
		Topology_Registry::reset();
		Topology_Registry::register_builtin_dir( \dirname( __DIR__, 3 ) . '/newspack-nodes/topologies' );
		Topology_Registry::register_plugin( 'Newspack_Intelligence\\', \dirname( __DIR__, 2 ) . '/topologies' );
		$this->seeder = $this->projector_graph();
		( new \ReflectionProperty( Entity_Index::class, 'current' ) )->setValue( null, null );
	}

	/**
	 * Activate topologies, as the option layer holds them.
	 *
	 * @param string ...$names Topology names.
	 */
	private function activate( string ...$names ): void {
		\update_option( 'newspack_nodes_topologies', $names );
		Config::reset();
		Bootstrap::forget_node_tables();
	}

	/**
	 * Project the events, then read the index off partition 0's file.
	 *
	 * @param list<array<string,mixed>> ...$events Ontology events, in order.
	 */
	private function index_of( array ...$events ): Entity_Index {
		$this->seed( ...$events );
		return Entity_Index::load( Sqlite_Arm::open_rows( [ 0 => Table_Node::file( 'intel-graph', 0 ) ], Intel_Graph_Row_Schema::version() ) );
	}

	/**
	 * Project the events into the graph and write them.
	 *
	 * @param list<array<string,mixed>> ...$events Ontology events, in order.
	 */
	private function seed( array ...$events ): void {
		\assert( null !== $this->seeder );
		foreach ( \array_merge( ...$events ) as $event ) {
			self::deliver( $this->seeder, $event );
		}
		$this->seeder->save_state();
	}

	/**
	 * A person's entity: its creation, then each name or identifier, kind =>
	 * values. A kind from NAME_KINDS writes a name, any other an identifier.
	 *
	 * @param string                    $key   The entity key.
	 * @param string                    $name  Its canonical name.
	 * @param array<string,list<string>> $facts Its names and identifiers.
	 * @return list<array<string,mixed>>
	 */
	private static function entity( string $key, string $name, array $facts = [] ): array {
		return [
			Intel_Event::make( 'entity_created', [ 'entity_key' => $key, 'type' => \explode( ':', $key )[0], 'name' => $name ], 'user:41', 'fixture-7' ),
			...self::facts( $key, $facts ),
		];
	}

	/**
	 * A machine's proposed entity and its names.
	 *
	 * @param string                    $key   The entity key.
	 * @param string                    $name  Its proposed name.
	 * @param array<string,list<string>> $facts Its names and identifiers.
	 * @return list<array<string,mixed>>
	 */
	private static function proposed( string $key, string $name, array $facts = [] ): array {
		return [
			Intel_Event::make( 'entity_proposed', [ 'entity_key' => $key, 'type' => \explode( ':', $key )[0], 'name' => $name, 'from_item_key' => 'feed:seed-1' ], 'machine:resolve@0.0.1', 'fixture-7' ),
			...self::facts( $key, $facts ),
		];
	}

	/**
	 * The names and identifiers of an entity, kind => values.
	 *
	 * @param string                    $key   The entity key.
	 * @param array<string,list<string>> $facts Its names and identifiers.
	 * @return list<array<string,mixed>>
	 */
	private static function facts( string $key, array $facts ): array {
		$events = [];
		foreach ( $facts as $kind => $values ) {
			foreach ( $values as $value ) {
				$events[] = Intel_Event::make( \in_array( $kind, Intel_Event::NAME_KINDS, true ) ? 'name_added' : 'identifier_added', [ 'entity_key' => $key, 'kind' => $kind, 'value' => $value ], 'user:41', 'fixture-7' );
			}
		}
		return $events;
	}

	/**
	 * A person's rejection of an entity.
	 *
	 * @param string $key The entity key.
	 * @return list<array<string,mixed>>
	 */
	private static function rejected( string $key ): array {
		return [ Intel_Event::make( 'entity_rejected', [ 'entity_key' => $key ], 'user:41', 'fixture-7' ) ];
	}

	/**
	 * A person's merge of one entity into another.
	 *
	 * @param string $key  The entity merged away.
	 * @param string $into The entity it survives as.
	 * @return list<array<string,mixed>>
	 */
	private static function merged( string $key, string $into ): array {
		return [ Intel_Event::make( 'entity_merged', [ 'entity_key' => $key, 'into_key' => $into ], 'user:41', 'fixture-7' ) ];
	}
}

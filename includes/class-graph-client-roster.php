<?php
/**
 * Graph_Client_Roster: the client roster as the entity graph holds it. A
 * client is an organization carrying its atomic site id as an identifier and
 * an open `client` role; a churned one carries an open `former_client` role
 * instead. The roster reads the projection on its first call of an import,
 * through `Entity_Index::load()`, so every identifier answers the survivor
 * of any merge. A new client resolves first by its atomic id, else by its
 * domain, and only one found by neither creates `organization:atomic-<id>`,
 * so an import never moves an entity's domain onto a new one. A client
 * whose entity, or whose domain's holder, a person rejected or whose merge
 * chain does not resolve is left alone, and logged. Each change
 * is recorded as ontology events under the actor the roster was built with,
 * and `commit()` appends them in order as one write. A projection that will
 * not open throws, so an import never reconciles against a roster it could
 * not read.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;

\defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-type Open_Roles array<string,array<string,list<array{0:string,1:int}>>>
 */
final class Graph_Client_Roster implements Client_Roster {

	/** The roles a client holds, open while its `to_ts` is 0. */
	private const ROLES = [ 'client', 'former_client' ];

	/** The index this import resolves identifiers through; null until read. */
	private ?Entity_Index $index = null;

	/** @var array<string,string> Atomic id => the entity it answers. */
	private array $atomic = [];

	/** @var array<string,string> Atomic id => the refused entity holding it. */
	private array $refused = [];

	/** @var array<string,true> Each entity a row of this import's snapshot names. */
	private array $present = [];

	/** @var array<string,true> Each refused entity this import's snapshot names. */
	private array $left_alone = [];

	/** @var array<string,string> Each domain this import added => its entity. */
	private array $added = [];

	/**
	 * Entity => role => each open row, as its own key and from_ts, a merged
	 * entity's rows under its survivor.
	 *
	 * @var Open_Roles
	 */
	private array $open = [];

	/** @var list<array<string,mixed>> The events the import has minted. */
	private array $events = [];

	/**
	 * @param string $actor Who the import's events are recorded as.
	 */
	public function __construct( private string $actor ) {}

	/**
	 * The roster's record of an atomic id: active while its entity holds an
	 * open client role, refused when its entity is rejected or its merge chain
	 * does not resolve.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @return array{status:string}|null
	 */
	public function find_by_atomic_id( string $atomic_id ): ?array {
		$status = $this->statuses()[ self::id( $atomic_id ) ] ?? null;
		return null === $status ? null : [ 'status' => $status ];
	}

	/**
	 * Every atomic id an entity carries, active or churned, and every one a
	 * refused entity holds.
	 *
	 * @return array<string,string>
	 */
	public function statuses(): array {
		$this->load();
		$statuses = \array_map( fn ( string $key ): string => isset( $this->open[ $key ]['client'] ) ? 'active' : 'churned', $this->atomic );
		return \array_map( static fn (): string => 'refused', $this->refused ) + $statuses;
	}

	/**
	 * A client the roster lacks: the entity holding its domain, else a new
	 * organization keyed by the atomic id and named by its domain, or by
	 * the id when it has none. It gains the atomic id, the domain when no
	 * entity holds it, and a client role from the start of today. A domain
	 * a refused entity holds leaves the client alone.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row   The CSV row.
	 * @param string                                                         $today YYYY-MM-DD, UTC.
	 * @throws \InvalidArgumentException When the row names no atomic id.
	 */
	public function create( array $row, string $today ): void {
		$this->load();
		$id = self::id( $row['atomic_site_id'] );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'client import: a row names no atomic site id' );
		}
		$domain = Entity_Index::identifier_value( 'domain', $row['domain_name'] );
		if ( $this->refused_domain( $domain ) ) {
			return;
		}
		$key = '' === $domain ? null : $this->holder( $domain );
		if ( null === $key ) {
			$key = Intel_Event::entity_key( 'organization', "atomic-{$id}" );
			$this->mint( $id, 'entity_created', [ 'entity_key' => $key, 'type' => 'organization', 'name' => '' === $domain ? "Atomic site {$id}" : $domain ] );
		}
		$this->atomic[ $id ] = $key;
		$this->mint( $id, 'identifier_added', [ 'entity_key' => $key, 'kind' => 'atomic_site_id', 'value' => $id ] );
		$this->update_atomic_fields( $id, $row, $today );
		if ( ! isset( $this->open[ $key ]['client'] ) ) {
			$this->set_active( $id, $today );
		}
	}

	/**
	 * A former client is a client again from the start of today.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function set_active( string $atomic_id, string $today ): void {
		$this->load();
		$id  = self::id( $atomic_id );
		$key = $this->atomic[ $id ];
		$this->close( $id, $key, 'former_client', $today );
		$this->open( $id, $key, 'client', $today );
	}

	/**
	 * The client's entity is in the snapshot. A domain no entity holds is one
	 * more identifier of it; the entity keeps its name and every earlier
	 * domain. A refused entity, or a domain one holds, is left alone.
	 *
	 * @param string                                                         $atomic_id The atomic site id.
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row       The CSV row.
	 * @param string                                                         $today     YYYY-MM-DD, UTC.
	 */
	public function update_atomic_fields( string $atomic_id, array $row, string $today ): void {
		$this->load();
		$id = self::id( $atomic_id );
		if ( isset( $this->refused[ $id ] ) ) {
			$this->left_alone[ $this->refused[ $id ] ] = true;
			return;
		}
		$key                   = $this->atomic[ $id ];
		$this->present[ $key ] = true;
		$domain                = Entity_Index::identifier_value( 'domain', $row['domain_name'] );
		if ( '' === $domain || null !== $this->holder( $domain ) || $this->refused_domain( $domain ) ) {
			return;
		}
		$this->added[ $domain ] = $key;
		$this->mint( $id, 'identifier_added', [ 'entity_key' => $key, 'kind' => 'domain', 'value' => $domain ] );
	}

	/**
	 * Whether a refused entity holds a domain, which the import then leaves
	 * alone and logs.
	 *
	 * @param string $domain A normalised domain.
	 */
	private function refused_domain( string $domain ): bool {
		$holder = '' === $domain ? null : $this->index?->held_by_refused( 'domain', $domain );
		if ( null === $holder ) {
			return false;
		}
		$this->left_alone[ $holder ] = true;
		return true;
	}

	/**
	 * The entity holding a domain: one this import added it to, else the
	 * survivor of the entity the projection holds it on.
	 *
	 * @param string $domain A normalised domain.
	 */
	private function holder( string $domain ): ?string {
		return $this->added[ $domain ] ?? $this->index?->by_identifier( 'domain', $domain );
	}

	/**
	 * A client is a former client from the start of today, unless another
	 * of its atomic ids is in the snapshot or churned it already.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function mark_churned( string $atomic_id, string $today ): void {
		$this->load();
		$id  = self::id( $atomic_id );
		$key = $this->atomic[ $id ];
		if ( isset( $this->present[ $key ] ) || ! isset( $this->open[ $key ]['client'] ) ) {
			return;
		}
		$this->close( $id, $key, 'client', $today );
		$this->open( $id, $key, 'former_client', $today );
	}

	/**
	 * Open a role on an entity from the start of today.
	 *
	 * @param string $id    The atomic site id the change is for.
	 * @param string $key   The entity key.
	 * @param string $role  The role.
	 * @param string $today YYYY-MM-DD, UTC.
	 */
	private function open( string $id, string $key, string $role, string $today ): void {
		$from                        = Intel_Graph_Row_Schema::day( $today );
		$this->open[ $key ][ $role ] = [ [ $key, $from ] ];
		$this->mint( $id, 'role_asserted', [ 'entity_key' => $key, 'role' => $role, 'from_ts' => $from ] );
	}

	/**
	 * End each open row of a role at the start of today, on the key the row
	 * is held under; a role not open is left alone.
	 *
	 * @param string $id    The atomic site id the change is for.
	 * @param string $key   The entity key.
	 * @param string $role  The role.
	 * @param string $today YYYY-MM-DD, UTC.
	 */
	private function close( string $id, string $key, string $role, string $today ): void {
		foreach ( $this->open[ $key ][ $role ] ?? [] as [ $held, $from ] ) {
			$this->mint( $id, 'role_ended', [ 'entity_key' => $held, 'role' => $role, 'from_ts' => $from, 'to_ts' => Intel_Graph_Row_Schema::day( $today ) ] );
		}
		unset( $this->open[ $key ][ $role ] );
	}

	/**
	 * One event, under the roster's actor at the plugin version.
	 *
	 * @param string              $id     The atomic site id the event is for.
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @throws \InvalidArgumentException Naming the atomic id, when the event does not mint.
	 */
	private function mint( string $id, string $kind, array $fields ): void {
		try {
			$this->events[] = Intel_Event::make( $kind, $fields, $this->actor, \NEWSPACK_INTELLIGENCE_VERSION );
		} catch ( \InvalidArgumentException $e ) {
			throw new \InvalidArgumentException( \esc_html( "client import: atomic site {$id}: " . $e->getMessage() ), 0, $e );
		}
	}

	/**
	 * An atomic id as an identifier value.
	 *
	 * @param string $atomic_id The atomic site id.
	 */
	private static function id( string $atomic_id ): string {
		return Entity_Index::identifier_value( 'atomic_site_id', $atomic_id );
	}

	/**
	 * Read the projection, once an import.
	 *
	 * @throws \Newspack_Nodes\Table_Unavailable When no projection opens.
	 */
	private function load(): void {
		if ( null === $this->index ) {
			[ $this->index, $this->atomic, $this->refused, $this->open ] = self::read();
		}
	}

	/**
	 * The index, every atomic id by the entity it answers or by the refused
	 * entity holding it, and the open client and former_client rows of
	 * every entity carrying an atomic id or a domain, under its survivor.
	 *
	 * @return array{0:Entity_Index,1:array<string,string>,2:array<string,string>,3:Open_Roles}
	 * @throws \Newspack_Nodes\Table_Unavailable When no projection opens.
	 */
	private static function read(): array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ): array {
				$index     = Entity_Index::load( $r );
				$keys      = Graph_Query::inverse( $index->merged() );
				$atomic    = [];
				$survivors = [];
				foreach ( $index->identifiers( 'atomic_site_id' ) as $id => $entity ) {
					$atomic[ (string) $id ] = $entity;
				}
				foreach ( [ ...$atomic, ...$index->identifiers( 'domain' ) ] as $entity ) {
					$survivors[ $entity ] = $keys[ $entity ] ?? [ $entity ];
				}
				$refused = [];
				foreach ( $index->refused( 'atomic_site_id' ) as $id => $holder ) {
					$refused[ (string) $id ] = $holder;
				}
				return [ $index, $atomic, $refused, Graph_Query::open_roles( $r, $survivors, self::ROLES ) ];
			}
		);
	}

	/**
	 * Log the refused entities the snapshot named, append the import's
	 * events in order, then forget what was read, so the next import reads
	 * the roster again.
	 *
	 * @throws \Throwable What the append threw.
	 */
	public function commit(): void {
		try {
			if ( [] !== $this->left_alone ) {
				Core::stderr( 'client import: leaving alone the entities rejected or unresolvably merged: ' . \implode( ', ', \array_keys( $this->left_alone ) ) );
			}
			if ( [] !== $this->events ) {
				Ontology_Writer::append( $this->events );
			}
		} finally {
			$this->events     = [];
			$this->index      = null;
			$this->atomic     = [];
			$this->refused    = [];
			$this->present    = [];
			$this->left_alone = [];
			$this->added      = [];
			$this->open       = [];
		}
	}
}

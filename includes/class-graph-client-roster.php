<?php
/**
 * Graph_Client_Roster: the client roster as the entity graph holds it. A
 * client is an organization carrying its atomic site id as an identifier and
 * an open `client` role; a churned one carries an open `former_client` role
 * instead. The roster reads the projection on its first call of an import,
 * through `Entity_Index::load()`, so every identifier answers the survivor
 * of any merge. A new client resolves first by its atomic id, else by its
 * domain, and only one found by neither creates `organization:atomic-<id>`,
 * so an import never moves an entity's domain onto a new one. A client is
 * a client from the earliest day its snapshot rows were Created, or from
 * the end of its latest ended client or former_client row when that is
 * later, and an import moves an open client role that starts on any other
 * day. A client a curator ended, holding neither role open after a client
 * row that held time, is held: an import opens no role on it. A client
 * whose entity, or
 * whose domain's holder, a person rejected or whose merge chain does not
 * resolve is left alone, and logged. Each change
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

	/** A CSV `Created`: a day, then any time of it, which the role drops. */
	private const CREATED = '/^(\d{4}-\d{2}-\d{2})(?:[ T](?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?)?$/';

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

	/** @var array<string,array{0:int,1:string}> Each entity in the snapshot => the earliest day its rows were Created, and that row's atomic id. */
	private array $since = [];

	/** @var array<string,int> Each entity => the latest end of a closed client or former_client row holding time. */
	private array $returned = [];

	/** @var array<string,true> Each entity a curator ended: neither role open, and a client row closed holding time. */
	private array $held = [];

	/** @var list<array<string,mixed>> The events the import has minted. */
	private array $events = [];

	/**
	 * @param string $actor Who the import's events are recorded as.
	 */
	public function __construct( private string $actor ) {}

	/**
	 * The roster's record of an atomic id: active while its entity holds an
	 * open client role, held when a curator ended it, refused when its entity
	 * is rejected or its merge chain does not resolve.
	 *
	 * @param string $atomic_id The atomic site id.
	 * @return array{status:string}|null
	 */
	public function find_by_atomic_id( string $atomic_id ): ?array {
		$status = $this->statuses()[ self::id( $atomic_id ) ] ?? null;
		return null === $status ? null : [ 'status' => $status ];
	}

	/**
	 * Every atomic id an entity carries, active, churned or held, and every
	 * one a refused entity holds.
	 *
	 * @return array<string,string>
	 */
	public function statuses(): array {
		$this->load();
		$statuses = \array_map( fn ( string $key ): string => isset( $this->open[ $key ]['client'] ) ? 'active' : ( isset( $this->held[ $key ] ) ? 'held' : 'churned' ), $this->atomic );
		return \array_map( static fn (): string => 'refused', $this->refused ) + $statuses;
	}

	/**
	 * A client the roster lacks: the entity holding its domain, else a new
	 * organization keyed by the atomic id and named by its domain, or by the id
	 * when it has none. It gains the atomic id, the domain when it is not held
	 * by any entity, and settle() opens its client role from start() unless
	 * it is held. A domain a refused entity holds leaves the client alone.
	 *
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row   The CSV row.
	 * @param string                                                         $today YYYY-MM-DD, UTC.
	 * @throws \InvalidArgumentException When the row does not name an atomic
	 *                                   id.
	 */
	public function create( array $row, string $today ): void {
		$this->load();
		$id = self::id( $row['atomic_site_id'] );
		if ( '' === $id ) {
			throw new \InvalidArgumentException( 'client import: a row has no atomic site id' );
		}
		$domain = Entity_Index::identifier_value( 'domain', $row['domain_name'] );
		$key    = $this->holder( $domain );
		if ( $this->refused_domain( $domain, $key ) ) {
			return;
		}
		if ( null === $key ) {
			$key = Intel_Event::entity_key( 'organization', "atomic-{$id}" );
			$this->mint( $id, 'entity_created', [ 'entity_key' => $key, 'type' => 'organization', 'name' => '' === $domain ? "Atomic site {$id}" : $domain ] );
		}
		$this->atomic[ $id ] = $key;
		$this->mint( $id, 'identifier_added', [ 'entity_key' => $key, 'kind' => 'atomic_site_id', 'value' => $id ] );
		$this->update_atomic_fields( $id, $row, $today );
		if ( ! isset( $this->open[ $key ]['client'] ) && ! isset( $this->held[ $key ] ) ) {
			$this->set_active( $id, $today );
		}
	}

	/**
	 * A former client is a client again: its former_client row ends at the
	 * start of today, and settle() opens its client row from start().
	 *
	 * @param string $atomic_id The atomic site id.
	 * @param string $today     YYYY-MM-DD, UTC.
	 */
	public function set_active( string $atomic_id, string $today ): void {
		$this->load();
		$id  = self::id( $atomic_id );
		$key = $this->atomic[ $id ];
		$this->close( $id, $key, 'former_client', $today );
		// settle() mints the row once the client's start is known.
		$this->open[ $key ]['client'] ??= [];
	}

	/**
	 * The client's entity is in the snapshot, its site Created on the row's
	 * day. A domain that is not held by any entity is one more
	 * identifier of it; the entity
	 * keeps its name and every earlier domain. A refused entity, or a domain
	 * one holds, is left alone.
	 *
	 * @param string                                                         $atomic_id The atomic site id.
	 * @param array{atomic_site_id:string,domain_name:string,created:string} $row       The CSV row.
	 * @param string                                                         $today     YYYY-MM-DD, UTC.
	 * @throws \InvalidArgumentException Naming the atomic id, when its Created is not a day.
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
		$this->since[ $key ]   = \min( $this->since[ $key ] ?? [ \PHP_INT_MAX, $id ], [ self::created( $id, $row['created'] ), $id ] );
		$domain                = Entity_Index::identifier_value( 'domain', $row['domain_name'] );
		$holder                = $this->holder( $domain );
		if ( '' === $domain || null !== $holder ) {
			$this->refused_domain( $domain, $holder );
			return;
		}
		$this->added[ $domain ] = $key;
		$this->mint( $id, 'identifier_added', [ 'entity_key' => $key, 'kind' => 'domain', 'value' => $domain ] );
	}

	/**
	 * Whether a domain's holder is a refused entity, rejected or merged on a
	 * broken chain, which the import then leaves alone and logs: one the
	 * index answers by a key it holds no state for.
	 *
	 * @param string      $domain A normalised domain.
	 * @param string|null $holder Its holder, as holder() answers it.
	 */
	private function refused_domain( string $domain, ?string $holder ): bool {
		if ( null === $holder || isset( $this->added[ $domain ] ) || null !== $this->index?->state( $holder ) ) {
			return false;
		}
		$this->left_alone[ $holder ] = true;
		return true;
	}

	/**
	 * The entity holding a domain: one this import added it to, else the
	 * one the index answers, a refused entity's key among them; none for ''.
	 *
	 * @param string $domain A normalised domain.
	 */
	private function holder( string $domain ): ?string {
		return '' === $domain ? null : $this->added[ $domain ] ?? $this->index?->holder( 'domain', $domain );
	}

	/**
	 * The day a CSV row's site was Created, as a UTC day's start.
	 *
	 * @param string $id      The atomic site id, for the refusal.
	 * @param string $created The row's `Created`.
	 * @throws \InvalidArgumentException Naming the atomic id, when it is not a day.
	 */
	private static function created( string $id, string $created ): int {
		try {
			if ( 1 === \preg_match( self::CREATED, \trim( $created ), $day ) ) {
				return Intel_Graph_Row_Schema::day( $day[1] );
			}
		} catch ( \InvalidArgumentException ) {
			// A day the calendar lacks is refused below, as any other.
		}
		throw new \InvalidArgumentException( \esc_html( "client import: atomic site {$id}: Created is not a date: {$created}" ) );
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
		$from                                  = Intel_Graph_Row_Schema::day( $today );
		$this->open[ $key ]['former_client'] = [ [ $key, $from ] ];
		$this->mint( $id, 'role_asserted', [ 'entity_key' => $key, 'role' => 'former_client', 'from_ts' => $from ] );
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
		$to = Intel_Graph_Row_Schema::day( $today );
		foreach ( $this->open[ $key ][ $role ] ?? [] as [ $held, $from ] ) {
			$this->mint( $id, 'role_ended', [ 'entity_key' => $held, 'role' => $role, 'from_ts' => $from, 'to_ts' => $to ] );
			if ( $to > $from ) {
				$this->returned[ $key ] = \max( $this->returned[ $key ] ?? 0, $to );
			}
		}
		unset( $this->open[ $key ][ $role ] );
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
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection will not
	 *                                           open.
	 */
	private function load(): void {
		if ( null === $this->index ) {
			[ $this->index, $this->atomic, $this->refused, $this->open, $this->returned, $this->held ] = self::read();
		}
	}

	/**
	 * The index, every atomic id by the entity it answers or by the refused
	 * entity holding it, and, under its survivor, the open client and
	 * former_client rows of every entity carrying an atomic id or a domain,
	 * the latest end of one of those rows closed holding time, and the
	 * entities a curator ended.
	 *
	 * @return array{0:Entity_Index,1:array<string,string>,2:array<string,string>,3:Open_Roles,4:array<string,int>,5:array<string,true>}
	 * @throws \Newspack_Nodes\Table_Unavailable When the projection will not
	 *                                           open.
	 */
	private static function read(): array {
		return Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ): array {
				$index     = Entity_Index::load( $r );
				$atomic    = [];
				$survivors = [];
				foreach ( $index->identifiers( 'atomic_site_id' ) as $id => $entity ) {
					$atomic[ (string) $id ] = $entity;
				}
				foreach ( [ ...$atomic, ...$index->identifiers( 'domain' ) ] as $entity ) {
					$survivors[ $entity ] = $index->keys_of( $entity );
				}
				$refused = [];
				foreach ( $index->refused( 'atomic_site_id' ) as $id => $holder ) {
					$refused[ (string) $id ] = $holder;
				}
				return [ $index, $atomic, $refused, ...self::roles( $r, $survivors ) ];
			}
		);
	}

	/**
	 * Every client and former_client row of each survivor's keys, in one
	 * read: the open ones, the latest first, as `[ the key it is held under,
	 * from_ts ]`, the latest end of those closed holding time, which a
	 * client is a client from at the earliest, and each survivor holding
	 * neither role open whose client row closed holding time, which only a
	 * curator leaves, since a churn opens former_client. A row ended where
	 * it began holds none, so it is not counted in any of them.
	 *
	 * @param Row_Reader                 $r         The reader.
	 * @param array<string,list<string>> $survivors Each survivor => the keys its rows sit under.
	 * @return array{0:Open_Roles,1:array<string,int>,2:array<string,true>}
	 */
	private static function roles( Row_Reader $r, array $survivors ): array {
		$owner = [];
		foreach ( $survivors as $survivor => $keys ) {
			$owner += \array_fill_keys( $keys, $survivor );
		}
		$keys     = \array_map( 'strval', \array_keys( $owner ) );
		$open     = [];
		$returned = [];
		$ended    = [];
		if ( [] === $keys ) {
			return [ $open, $returned, $ended ];
		}
		$rows = $r->rows(
			'SELECT entity_key, role, from_ts, to_ts FROM ' . Intel_Graph_Row_Schema::all( $r, 'roles' ) . ' t WHERE role IN ( ' . Intel_Graph_Row_Schema::marks( self::ROLES )
			. ' ) AND entity_key IN ( ' . Intel_Graph_Row_Schema::marks( $keys ) . ' ) ORDER BY from_ts DESC, entity_key',
			[ ...self::ROLES, ...$keys ]
		);
		foreach ( $rows as [ $key, $role, $from, $to ] ) {
			$key      = Core::as_string( $key );
			$survivor = $owner[ $key ];
			[ $from, $to ] = [ Core::as_int( $from ), Core::as_int( $to ) ];
			if ( 0 === $to ) {
				$open[ $survivor ][ Core::as_string( $role ) ][] = [ $key, $from ];
			} elseif ( $to > $from ) {
				$returned[ $survivor ] = \max( $returned[ $survivor ] ?? 0, $to );
				if ( 'client' === $role ) {
					$ended[ $survivor ] = true;
				}
			}
		}
		return [ $open, $returned, \array_diff_key( $ended, $open ) ];
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
			$this->settle();
			if ( [] !== $this->events ) {
				Stream_Writer::append( $this->events );
			}
		} finally {
			$this->events     = [];
			$this->since      = [];
			$this->returned   = [];
			$this->held       = [];
			$this->index      = null;
			$this->atomic     = [];
			$this->refused    = [];
			$this->present    = [];
			$this->left_alone = [];
			$this->added      = [];
			$this->open       = [];
		}
	}

	/**
	 * Open each client in the snapshot from start(), once every row is
	 * reconciled, but a held one: an open client row starting on another day
	 * ends where it began, holding no time, and a row opens from start()
	 * unless one starts there already.
	 */
	private function settle(): void {
		foreach ( \array_diff_key( $this->since, $this->held ) as $key => [ , $id ] ) {
			$start = $this->start( $key );
			$rows  = $this->open[ $key ]['client'] ?? [];
			$stale = \array_filter( $rows, static fn ( array $row ): bool => $start !== $row[1] );
			foreach ( $stale as [ $held, $from ] ) {
				$this->mint( $id, 'role_ended', [ 'entity_key' => $held, 'role' => 'client', 'from_ts' => $from, 'to_ts' => $from ] );
			}
			if ( \count( $stale ) === \count( $rows ) ) {
				$this->mint( $id, 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => $start ] );
			}
		}
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
	 * When an entity is a client from: the earliest day its snapshot rows
	 * were Created, or the latest end of a client or former_client row that
	 * held time, when that is later, so an ended row is never reopened.
	 *
	 * @param string $key The entity key.
	 */
	private function start( string $key ): int {
		return \max( $this->since[ $key ][0], $this->returned[ $key ] ?? 0 );
	}
}

<?php
declare(strict_types=1);

namespace Newspack_Intelligence\Tests;

use Newspack_Intelligence\Client_Importer;
use Newspack_Intelligence\Graph_Client_Roster;
use Newspack_Intelligence\Intel_Event;
use Newspack_Intelligence\Intel_Graph_Row_Schema;
use Newspack_Nodes\Row_Table_Node;
use Newspack_Nodes\Table_Unavailable;
use Newspack_Nodes\Topology_Analyzer;
use Newspack_Nodes\Topology_Registry;
use Newspack_Nodes\Tests\TestCase;

require_once __DIR__ . '/../support/reads-ontology-log.php';
require_once __DIR__ . '/../support/seeds-entity-index.php';

/**
 * The client roster reads who is a client off the projection and records
 * each import's changes as ontology events.
 */
final class GraphClientRosterTest extends TestCase {
	use Reads_Ontology_Log;
	use Seeds_Entity_Index;

	/** 2025-08-20 00:00 UTC. */
	private const AUG_20 = 1755648000;

	/** 2026-03-14 00:00 UTC. */
	private const MAR_14 = 1773446400;

	/** 2026-04-02 00:00 UTC. */
	private const APR_02 = 1775088000;

	protected function setUp(): void {
		parent::setUp();
		Topology_Analyzer::reset_caches();
		$this->start_entity_graph();
		$this->activate( 'newspack-intelligence-project' );
	}

	protected function tearDown(): void {
		Intel_Graph_Row_Schema::$read_rows = null;
		Topology_Registry::reset();
		Topology_Analyzer::reset_caches();
		parent::tearDown();
	}

	public function test_creating_a_client_emits_entity_identifiers_and_role(): void {
		$result = self::importer()->import( self::rows( [ '990011', 'harborgazette.example' ] ), '2026-03-14' );

		$key = 'organization:atomic-990011';
		$this->assertSame(
			[
				[ 'entity_created', $key, 'organization', 'harborgazette.example' ],
				[ 'identifier_added', $key, 'atomic_site_id', '990011' ],
				[ 'identifier_added', $key, 'domain', 'harborgazette.example' ],
				[ 'role_asserted', $key, 'client', self::MAR_14 ],
			],
			self::brief( self::ontology_events() )
		);
		$this->assertSame( [ 'user:harbor-desk' ], \array_values( \array_unique( \array_column( self::ontology_events(), 'actor' ) ) ) );
		$this->assertSame( [ \NEWSPACK_INTELLIGENCE_VERSION ], \array_values( \array_unique( \array_column( self::ontology_events(), 'config_version' ) ) ) );
		$this->assertSame( 1, $result['created'] );
	}

	/** A client whose domain a curated entity holds becomes that entity: no new entity, its atomic id added, its role asserted. */
	public function test_a_curated_entity_holding_the_domain_becomes_the_client(): void {
		$this->seed( self::entity( 'organization:harbor-gazette', 'Harbor Gazette', [ 'domain' => [ 'harborgazette.example' ] ] ) );
		$before = \count( self::ontology_events() );

		$result = self::importer()->import( self::rows( [ '990011', 'HarborGazette.example' ] ), '2026-03-14' );

		$this->assertSame(
			[
				[ 'identifier_added', 'organization:harbor-gazette', 'atomic_site_id', '990011' ],
				[ 'role_asserted', 'organization:harbor-gazette', 'client', self::MAR_14 ],
			],
			\array_slice( self::brief( self::ontology_events() ), $before )
		);
		$this->assertSame( 1, $result['created'] );
	}

	/** A client merged into another entity churns on its survivor; the open role is closed on the row that holds it. */
	public function test_a_merged_clients_churn_lands_on_its_survivor(): void {
		$this->seed(
			self::client( '770022', 'tidewater.example', self::AUG_20 ),
			self::entity( 'organization:tidewater-times', 'Tidewater Times', [ 'domain' => [ 'tidewatertimes.example' ] ] ),
			self::merged( 'organization:atomic-770022', 'organization:tidewater-times' )
		);
		$before = \count( self::ontology_events() );

		$result = self::importer()->import( self::rows( [ '990011', 'harborgazette.example' ] ), '2026-03-14' );

		$this->assertSame(
			[
				[ 'role_ended', 'organization:atomic-770022', 'client', self::AUG_20, self::MAR_14 ],
				[ 'role_asserted', 'organization:tidewater-times', 'former_client', self::MAR_14 ],
			],
			\array_slice( self::brief( self::ontology_events() ), $before + 4 )
		);
		$this->assertSame( 1, $result['churned'] );
	}

	/** A publisher running several sites stays a client until every one of its atomic ids leaves the snapshot. */
	public function test_a_shared_survivor_churns_only_when_every_atomic_id_leaves(): void {
		$key = 'organization:harbor-media';
		$this->seed(
			self::entity( $key, 'Harbor Media', [ 'atomic_site_id' => [ '990011', '990012' ], 'domain' => [ 'harbormedia.example' ] ] ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => self::AUG_20 ], 'user:41', 'fixture-7' ) ]
		);
		$before = \count( self::ontology_events() );

		self::importer()->import( self::rows( [ '990011', 'harbormedia.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );

		self::importer()->import( self::rows( [ '330044', 'pinecrest.example' ] ), '2026-04-02' );

		$this->assertSame(
			[
				[ 'role_ended', $key, 'client', self::AUG_20, self::APR_02 ],
				[ 'role_asserted', $key, 'former_client', self::APR_02 ],
			],
			\array_slice( self::brief( self::ontology_events() ), $before + 4 )
		);
	}

	/** A client role held under a key merged into the client's entity is the client's own. */
	public function test_a_role_a_merged_key_holds_is_the_clients(): void {
		$this->seed(
			self::entity( 'organization:quayside-herald', 'Quayside Herald', [ 'atomic_site_id' => [ '990066' ], 'domain' => [ 'quaysideherald.example' ] ] ),
			self::entity( 'organization:quayside-old', 'Quayside Old' ),
			[ Intel_Event::make( 'role_asserted', [ 'entity_key' => 'organization:quayside-old', 'role' => 'client', 'from_ts' => self::AUG_20 ], 'user:41', 'fixture-7' ) ],
			self::merged( 'organization:quayside-old', 'organization:quayside-herald' )
		);
		$before = \count( self::ontology_events() );

		$result = self::importer()->import( self::rows( [ '990066', 'quaysideherald.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
		$this->assertSame( [ 0, 1, 0 ], [ $result['created'], $result['updated'], $result['reactivated'] ] );
	}

	/** A client whose entity a person rejected is left alone, counted as present, and logged once. */
	public function test_a_rejected_client_writes_nothing_and_still_counts(): void {
		$this->seed( self::client( '990013', 'saltmarsh.example', self::AUG_20 ), self::rejected( 'organization:atomic-990013' ) );
		$before   = \count( self::ontology_events() );
		$captured = $this->capture_stderr();

		$result = self::importer()->import( self::rows( [ '990013', 'saltmarsh-news.example' ], [ '990013', 'saltmarsh-news.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
		$this->assertSame( [ 0, 2, 0, 0 ], [ $result['created'], $result['updated'], $result['reactivated'], $result['churned'] ] );
		$this->assertCount( 1, self::stderr_lines( $captured, 'organization:atomic-990013' ) );
	}

	/** A client merged into a rejected survivor is left alone. */
	public function test_a_client_merged_into_a_rejected_survivor_is_left_alone(): void {
		$this->seed(
			self::client( '990077', 'brackwater.example', self::AUG_20 ),
			self::entity( 'organization:brackwater-group', 'Brackwater Group' ),
			self::rejected( 'organization:brackwater-group' ),
			self::merged( 'organization:atomic-990077', 'organization:brackwater-group' )
		);
		$before   = \count( self::ontology_events() );
		$captured = $this->capture_stderr();

		$result = self::importer()->import( self::rows( [ '990077', 'brackwater.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
		$this->assertSame( [ 0, 1 ], [ $result['created'], $result['updated'] ] );
		$this->assertCount( 1, self::stderr_lines( $captured, 'organization:atomic-990077' ) );
	}

	/** A new client whose domain a rejected entity holds is left alone, logged once with the others. */
	public function test_a_domain_a_rejected_entity_holds_is_left_alone(): void {
		$this->seed(
			self::entity( 'organization:fenwick-courier', 'Fenwick Courier', [ 'domain' => [ 'fenwickcourier.example' ] ] ),
			self::rejected( 'organization:fenwick-courier' ),
			self::client( '990013', 'saltmarsh.example', self::AUG_20 ),
			self::rejected( 'organization:atomic-990013' )
		);
		$before   = \count( self::ontology_events() );
		$captured = $this->capture_stderr();

		self::importer()->import( self::rows( [ '990088', 'fenwickcourier.example' ], [ '990013', 'saltmarsh.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
		$lines = self::stderr_lines( $captured, 'client import' );
		$this->assertCount( 1, $lines );
		$this->assertStringContainsString( 'organization:fenwick-courier', $lines[0] );
		$this->assertStringContainsString( 'organization:atomic-990013', $lines[0] );
	}

	/** A client on a merge cycle answers no survivor and is left alone. */
	public function test_a_client_on_a_merge_cycle_is_left_alone(): void {
		$this->seed(
			self::client( '990099', 'gullhaven.example', self::AUG_20 ),
			self::entity( 'organization:gullhaven-press', 'Gullhaven Press' ),
			self::merged( 'organization:atomic-990099', 'organization:gullhaven-press' ),
			self::merged( 'organization:gullhaven-press', 'organization:atomic-990099' )
		);
		$before = \count( self::ontology_events() );

		$result = self::importer()->import( self::rows( [ '990099', 'gullhaven.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
		$this->assertSame( [ 0, 1, 0 ], [ $result['created'], $result['updated'], $result['churned'] ] );
	}

	/** An event the roster cannot mint names the atomic id of its row. */
	public function test_an_event_that_will_not_mint_names_its_atomic_id(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'atomic site 990031' );
		self::importer()->import( self::rows( [ '990031', \str_repeat( 'longwharf', 600 ) . '.example' ] ), '2026-03-14' );
	}

	/** A row with no atomic id is refused rather than keyed `organization:atomic-`. */
	public function test_a_row_with_no_atomic_id_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'client import: a row names no atomic site id' );
		self::importer()->import( self::rows( [ '  ', 'blankpier.example' ] ), '2026-03-14' );
	}

	/** A client with no domain is named by its atomic id and carries no domain identifier. */
	public function test_an_empty_domain_names_the_entity_by_its_atomic_id(): void {
		self::importer()->import( self::rows( [ '440055', '' ] ), '2026-03-14' );

		$key = 'organization:atomic-440055';
		$this->assertSame(
			[
				[ 'entity_created', $key, 'organization', 'Atomic site 440055' ],
				[ 'identifier_added', $key, 'atomic_site_id', '440055' ],
				[ 'role_asserted', $key, 'client', self::MAR_14 ],
			],
			self::brief( self::ontology_events() )
		);
	}

	/** A client's new domain that another entity holds stays with that entity. */
	public function test_a_domain_another_entity_holds_is_never_moved(): void {
		$this->seed(
			self::client( '990011', 'harborgazette.example', self::AUG_20 ),
			self::entity( 'organization:lakeshore-ledger', 'Lakeshore Ledger', [ 'domain' => [ 'lakeshoreledger.example' ] ] )
		);
		$before = \count( self::ontology_events() );

		self::importer()->import( self::rows( [ '990011', 'lakeshoreledger.example' ] ), '2026-03-14' );

		$this->assertSame( [], \array_slice( self::ontology_events(), $before ) );
	}

	/** A churn closes the open client role and opens a former_client one; a return reverses it. */
	public function test_churn_and_return_are_role_events(): void {
		$this->seed( self::client( '770022', 'tidewater.example', self::AUG_20 ) );
		$key = 'organization:atomic-770022';

		$churn = self::importer()->import( self::rows( [ '990011', 'harborgazette.example' ] ), '2026-03-14' );

		$this->assertSame(
			[
				[ 'role_ended', $key, 'client', self::AUG_20, self::MAR_14 ],
				[ 'role_asserted', $key, 'former_client', self::MAR_14 ],
			],
			\array_slice( self::brief( self::ontology_events() ), 4 )
		);
		$this->assertSame( 1, $churn['churned'] );

		$this->seed( self::ontology_events() );
		$before = \count( self::ontology_events() );

		$return = self::importer()->import( self::rows( [ '770022', 'tidewater.example' ], [ '990011', 'harborgazette.example' ] ), '2026-04-02' );

		$this->assertSame(
			[
				[ 'role_ended', $key, 'former_client', self::MAR_14, self::APR_02 ],
				[ 'role_asserted', $key, 'client', self::APR_02 ],
			],
			\array_slice( self::brief( self::ontology_events() ), $before )
		);
		$this->assertSame( [ 1, 1, 0, 0 ], [ $return['reactivated'], $return['updated'], $return['created'], $return['churned'] ] );

		$this->seed( self::ontology_events() );
		$this->assertSame(
			[
				[ 'client', self::AUG_20, self::MAR_14 ],
				[ 'client', self::APR_02, 0 ],
				[ 'former_client', self::MAR_14, self::APR_02 ],
			],
			self::roles_of( $key )
		);
	}

	/** A new domain is one more identifier; the entity keeps its name, and a respelling of a held domain writes nothing. */
	public function test_a_domain_change_adds_an_identifier_and_renames_nothing(): void {
		$this->seed( self::client( '990011', 'harborgazette.example', self::AUG_20 ), self::client( '330044', 'pinecrest.example', self::AUG_20 ) );
		$before = \count( self::ontology_events() );

		$result = self::importer()->import( self::rows( [ '990011', 'harborgazette-news.example' ], [ '330044', 'WWW.Pinecrest.example' ] ), '2026-03-14' );

		$this->assertSame( [ [ 'identifier_added', 'organization:atomic-990011', 'domain', 'harborgazette-news.example' ] ], \array_slice( self::brief( self::ontology_events() ), $before ) );
		$this->assertSame( 2, $result['updated'] );

		$this->seed( self::ontology_events() );
		$this->assertSame( [ [ 'harborgazette.example', 'organization:atomic-990011' ] ], \array_values( \array_filter( self::graph_rows( 'entities', '1', 'name, entity_key' ), static fn ( array $row ): bool => 'organization:atomic-990011' === $row[1] ) ) );
	}

	/** One import reads the projection once, however many rows it reconciles. */
	public function test_the_roster_reads_state_once_per_import(): void {
		$this->seed( self::client( '770022', 'tidewater.example', self::AUG_20 ), self::client( '330044', 'pinecrest.example', self::AUG_20 ) );
		$reads                             = 0;
		Intel_Graph_Row_Schema::$read_rows = static function ( \Closure $read ) use ( &$reads ): mixed {
			++$reads;
			return Row_Table_Node::read( Intel_Graph_Row_Schema::class, $read );
		};
		$importer = self::importer();

		$importer->import( self::rows( [ '770022', 'tidewater-news.example' ], [ '990011', 'harborgazette.example' ], [ '550066', 'lakeshore.example' ] ), '2026-03-14' );
		$this->assertSame( 1, $reads );

		$importer->import( self::rows( [ '770022', 'tidewater-news.example' ] ), '2026-04-02' );
		$this->assertSame( 2, $reads );
	}

	/** An import against a roster it cannot read would re-create every client, so it throws before writing. */
	public function test_a_projection_that_will_not_open_fails_the_import_and_writes_nothing(): void {
		$this->activate();

		try {
			self::importer()->import( self::rows( [ '990011', 'harborgazette.example' ] ), '2026-03-14' );
			$this->fail( 'the import refuses' );
		} catch ( Table_Unavailable ) {
			$this->assertSame( [], self::ontology_events() );
		}
	}

	/**
	 * The projected roles of one entity, each its role and span.
	 *
	 * @param string $key The entity key.
	 * @return list<list<mixed>>
	 */
	private static function roles_of( string $key ): array {
		$rows = \array_filter( self::graph_rows( 'roles', 'role, from_ts', 'entity_key, role, from_ts, to_ts' ), static fn ( array $row ): bool => $key === $row[0] );
		return \array_values( \array_map( static fn ( array $row ): array => \array_slice( $row, 1 ), $rows ) );
	}

	/** An importer over a roster acting as a named person. */
	private static function importer(): Client_Importer {
		return new Client_Importer( new Graph_Client_Roster( 'user:harbor-desk' ) );
	}

	/**
	 * CSV rows, each an atomic id and a domain.
	 *
	 * @param array{0:string,1:string} ...$pairs The rows.
	 * @return list<array{atomic_site_id:string,domain_name:string,created:string}>
	 */
	private static function rows( array ...$pairs ): array {
		return \array_map( static fn ( array $p ): array => [ 'atomic_site_id' => $p[0], 'domain_name' => $p[1], 'created' => '2019-04-04' ], $pairs );
	}

	/**
	 * A client the projection already holds: its entity, both identifiers
	 * and an open client role.
	 *
	 * @param string $id     The atomic site id.
	 * @param string $domain Its domain.
	 * @param int    $from   When its client role opened.
	 * @return list<array<string,mixed>>
	 */
	private static function client( string $id, string $domain, int $from ): array {
		$key = "organization:atomic-{$id}";
		return [
			...self::entity( $key, $domain, [ 'atomic_site_id' => [ $id ], 'domain' => [ $domain ] ] ),
			Intel_Event::make( 'role_asserted', [ 'entity_key' => $key, 'role' => 'client', 'from_ts' => $from ], 'user:41', 'fixture-7' ),
		];
	}

	/**
	 * Each event as its kind and its own fields, in order.
	 *
	 * @param list<array<string,mixed>> $events Events.
	 * @return list<list<mixed>>
	 */
	private static function brief( array $events ): array {
		return \array_map( static fn ( array $e ): array => [ $e['event'], ...\array_values( \array_diff_key( $e, \array_flip( Intel_Event::ENVELOPE ) ) ) ], $events );
	}
}

<?php
/**
 * Ontology_Seed: the entities the graph starts from, a lens per audience
 * and the layout of each composed page, read from the seed file a
 * deployment supplies. A run writes only the keys the projection does not
 * settle, so an entity a curator has since accepted, rejected, merged or
 * renamed is never reset to what the seed says, while a machine's pending
 * proposal of a seed key is replaced by the seed's creation; a lens any
 * event has saved or removed is left alone; and a layout is written only to
 * a page that holds neither a saved layout nor a restore.
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Bootstrap;
use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Composition;
use Newspack_Nodes\Composition_Store;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Row_Reader;
use Newspack_Nodes\Table_Unavailable;

\defined( 'ABSPATH' ) || exit;

final class Ontology_Seed {

	/**
	 * The seed file's name, which a site keeps in its Intelligence data dir
	 * (`path()`). The plugin's fictional example, the shape to start from,
	 * sits in `examples/`.
	 */
	public const FILE = 'newspack-intelligence-ontology-seed.json';

	/** The fields an entity takes, `name` alone required. */
	private const ENTITY_FIELDS = [ 'name', 'names', 'identifiers', 'roles', 'state' ];

	/** Each state an entity may be declared in, by the event that sets it. */
	private const STATES = [ 'rejected' => 'entity_rejected' ];

	/** An entity's two fact lists, each by the event it writes. */
	private const FACT_LISTS = [
		'names'       => 'name_added',
		'identifiers' => 'identifier_added',
	];

	/** The fields a lens takes, each required. */
	private const LENS_FIELDS = [ 'name', 'description', 'filters' ];

	/**
	 * Entity key => its events as `load()` minted them: its creation, its
	 * names, its identifiers, then its roles, in the file's order.
	 *
	 * @var array<string,list<array<string,mixed>>>
	 */
	private array $entities = [];

	/**
	 * Lens key => its `lens_saved`, in the file's order.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $lenses = [];

	/**
	 * Context => the markup the seed lays it out with, in the file's order.
	 *
	 * @var array<string,string>
	 */
	private array $layouts = [];

	/**
	 * A seed read from its file.
	 *
	 * @param string $path The file, named by its basename in every refusal.
	 */
	private function __construct( private readonly string $path ) {}

	/**
	 * Write the seed events for the keys the projection does not settle, then
	 * each layout to a page that holds none (`Composition_Store::seed()`), the
	 * one seed `wp newspack-intelligence ontology seed` and the
	 * `seed_ontology` command run. It refuses while the projector has not read
	 * the ontology stream to its end, whoever wrote what it has not read, so
	 * a seed never reads a projection that lacks keys already written and
	 * writes them again.
	 *
	 * @return array{events:int,created:int,present:int} The events written, the keys they create and the keys left alone.
	 * @throws Seed_Refused While the projector stands behind the ontology stream.
	 * @throws Table_Unavailable When the projection cannot be read.
	 */
	public function seed(): array {
		if ( Projector_Node::behind( Stream_Writer::stream_dir( Intel_Event::ONTOLOGY ) ) ) {
			throw new Seed_Refused( 'Recent ontology changes are still being processed; try again in a minute.' );
		}
		$present = $this->present();
		$events  = $this->events( $present );
		Stream_Writer::append( $events );
		foreach ( $this->layouts as $context => $markup ) {
			Composition_Store::seed( $context, $markup );
		}
		return [
			'events'  => \count( $events ),
			'created' => \count( \array_filter( $events, static fn ( array $event ): bool => \in_array( $event['event'], [ 'entity_created', 'lens_saved' ], true ) ) ),
			'present' => \count( $present ),
		];
	}

	/**
	 * The seed's events, as `load()` minted them under the seed's own machine
	 * actor, for each entity and then each lens `$present` does not name.
	 *
	 * @param list<string> $present The keys to leave alone, as present() answers.
	 * @return list<array<string,mixed>>
	 */
	public function events( array $present ): array {
		$skip = \array_flip( $present );
		return [ ...\array_merge( ...\array_values( \array_diff_key( $this->entities, $skip ) ) ), ...\array_values( \array_diff_key( $this->lenses, $skip ) ) ];
	}

	/**
	 * The seed keys the projection settles — an entity in any state but a
	 * machine's pending `proposed`, a lens any `lens_saved` or
	 * `lens_removed` names — in seed order.
	 *
	 * @return list<string>
	 * @throws Table_Unavailable When the projection cannot be read.
	 */
	public function present(): array {
		$lenses = \array_keys( $this->lenses );
		$held   = Intel_Graph_Row_Schema::read(
			static function ( Row_Reader $r ) use ( $lenses ): array {
				$keys = [];
				foreach ( $r->aliases() as $alias ) {
					foreach ( $r->rows( "SELECT entity_key FROM {$alias}.entity_states WHERE state <> ?", [ 'proposed' ] ) as [ $key ] ) {
						$keys[ Core::as_string( $key ) ] = true;
					}
					foreach ( $r->rows( "SELECT subject FROM {$alias}.events WHERE subject IN ( " . Intel_Graph_Row_Schema::marks( $lenses ) . ' ) AND kind IN ( ?, ? )', [ ...$lenses, 'lens_saved', 'lens_removed' ] ) as [ $key ] ) {
						$keys[ Core::as_string( $key ) ] = true;
					}
				}
				return $keys;
			}
		);
		return \array_values( \array_filter( [ ...\array_keys( $this->entities ), ...$lenses ], static fn ( string $key ): bool => isset( $held[ $key ] ) ) );
	}

	/**
	 * The seed a file holds: the one given, else the site's at `path()`,
	 * its events minted as it is read, so whatever `Intel_Event` refuses — a
	 * type, kind or role outside its set, an event past one line — is
	 * refused here, naming the file and the field, as is a layout the
	 * composition layer refuses for its page (`Composition::validate_shape()`).
	 *
	 * @param string|null $path A seed file, or null for the site's seed file.
	 * @throws Seed_Refused When the file is not found, naming a given file by
	 *                      its name, else where the site's belongs, by its path
	 *                      only to a manager, and the example to start from; or
	 *                      when the file does not parse or names what the graph
	 *                      has no word for, naming the field.
	 */
	public static function load( ?string $path = null ): self {
		$at = $path ?? self::path();
		if ( ! \is_file( $at ) || ! \is_readable( $at ) ) {
			throw new Seed_Refused(
				\esc_html(
					null === $path
						? 'No ' . self::FILE . ': write a seed for this site ' . ( Capabilities::can( Capabilities::MANAGE ) ? "to {$at}" : 'as ' . self::FILE . ' in its Intelligence data dir' ) . ', starting from the example the plugin ships at examples/' . self::FILE . '.'
						: 'No seed file at ' . \basename( $at ) . '.'
				)
			);
		}
		$seed = new self( $at );
		// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- a local seed file, not a remote fetch.
		$text = (string) \file_get_contents( $at );
		$file = $seed->fields( '', $seed->decode( $text ), [ 'entities', 'lenses', 'layouts' ], [ 'entities', 'lenses' ] );
		foreach ( $seed->fields( 'entities', $file['entities'] ) as $type => $entities ) {
			foreach ( $seed->fields( "entities.{$type}", $entities ) as $slug => $entity ) {
				$seed->entity( "entities.{$type}.{$slug}", (string) $type, (string) $slug, $entity );
			}
		}
		foreach ( $seed->fields( 'lenses', $file['lenses'] ) as $key => $lens ) {
			$seed->lens( "lenses.{$key}", (string) $key, $lens );
		}
		foreach ( $seed->fields( 'layouts', $file['layouts'] ?? [], Intelligence_Blocks::pages() ) as $page => $markup ) {
			$seed->layout( "layouts.{$page}", (string) $page, $markup );
		}
		return $seed;
	}

	/**
	 * Where a site keeps its seed: its Intelligence data dir, a sibling of
	 * the substrate's runtime base, outside every topology dir.
	 *
	 * The dir passes the runtime base's own gate, created 0700 when absent,
	 * so a dir another user planted, or a symlink, is refused.
	 *
	 * @api Read by dndocker's deploy tools, which install a site's seed here.
	 * @throws \RuntimeException When the runtime base directory or the data dir is unusable, a symlink, or not privately ours.
	 */
	public static function path(): string {
		return Config::ensure_path( \dirname( Bootstrap::base_dir() ) . '/newspack-intelligence' ) . '/' . self::FILE;
	}

	/**
	 * The file's JSON.
	 *
	 * @param string $text The file's bytes.
	 * @throws Seed_Refused When they do not parse.
	 */
	private function decode( string $text ): mixed {
		try {
			return \json_decode( $text, true, 512, \JSON_THROW_ON_ERROR );
		} catch ( \JsonException $e ) {
			throw new Seed_Refused( \esc_html( \basename( $this->path ) . ": not JSON: {$e->getMessage()}" ) );
		}
	}

	/**
	 * One entity's events, minted in the order the file lists its facts: its
	 * name and every value bounded as `create_entity` and `add_name` bound
	 * them, its slug the one its key spells, and last the state it is
	 * declared in, so a rejected entity's names stop a proposal as a
	 * curator's rejection does.
	 *
	 * @param string $field Where it sits.
	 * @param string $type  Its type.
	 * @param string $slug  Its slug.
	 * @param mixed  $value The entity.
	 * @throws Seed_Refused Naming the field.
	 */
	private function entity( string $field, string $type, string $slug, mixed $value ): void {
		$entity = $this->fields( $field, $value, self::ENTITY_FIELDS, [ 'name' ] );
		$key    = $this->keyed( $field, "{$type}:{$slug}", static fn (): string => Intel_Event::entity_key( $type, $slug ) );
		$name   = $this->text( "{$field}.name", $entity['name'] );
		$events = [ $this->minted( "{$field}.name", static fn (): array => self::mint( 'entity_created', [ 'entity_key' => $key, 'type' => $type, 'name' => Graph_Curation::bounded( 'name', $name, Graph_Curation::NAME_BYTES ) ] ) ) ];
		foreach ( self::FACT_LISTS as $list => $event ) {
			foreach ( $this->fields( "{$field}.{$list}", $entity[ $list ] ?? [] ) as $kind => $values ) {
				foreach ( $this->strings( "{$field}.{$list}.{$kind}", $values ) as $text ) {
					$events[] = $this->minted( "{$field}.{$list}.{$kind}", static fn (): array => self::mint( $event, [ 'entity_key' => $key, 'kind' => $kind, 'value' => Graph_Curation::bounded( 'value', $text, Graph_Curation::NAME_BYTES ) ] ) );
				}
			}
		}
		foreach ( $this->strings( "{$field}.roles", $entity['roles'] ?? [] ) as $role ) {
			$events[] = $this->minted( "{$field}.roles", static fn (): array => self::mint( 'role_asserted', [ 'entity_key' => $key, 'role' => $role, 'from_ts' => 0 ] ) );
		}
		if ( \array_key_exists( 'state', $entity ) ) {
			$state = $this->text( "{$field}.state", $entity['state'] );
			if ( ! isset( self::STATES[ $state ] ) ) {
				$this->refuse( "{$field}.state", 'not one of: ' . \implode( ', ', \array_keys( self::STATES ) ) );
			}
			$events[] = $this->minted( "{$field}.state", static fn (): array => self::mint( self::STATES[ $state ], [ 'entity_key' => $key ] ) );
		}
		$this->entities[ $key ] = $events;
	}

	/**
	 * One lens's `lens_saved`, its filters checked by
	 * `Graph_Curation::lens_filters()` as `save_lens` checks them, its key
	 * the one `Intel_Event::lens_key()` spells.
	 *
	 * @param string $field Where it sits.
	 * @param string $key   Its key.
	 * @param mixed  $value The lens.
	 * @throws Seed_Refused Naming the field.
	 */
	private function lens( string $field, string $key, mixed $value ): void {
		$this->keyed( $field, $key, static fn (): string => Intel_Event::lens_key( $key ) );
		$lens    = $this->fields( $field, $value, self::LENS_FIELDS, self::LENS_FIELDS );
		$filters = $this->fields( "{$field}.filters", $lens['filters'], \array_keys( Graph_Query::unfiltered() ) );
		$args    = [ 'tier' => $this->strings( "{$field}.filters.tiers", $filters['tiers'] ?? [] ) ];
		foreach ( Graph_Query::LENS_LISTS as $list => [ $option ] ) {
			$args[ $option ] = $this->strings( "{$field}.filters.{$list}", $filters[ $list ] ?? [] );
		}
		foreach ( [ 'entity', 'by', 'bucket' ] as $one ) {
			$args[ $one ] = null === ( $filters[ $one ] ?? null ) ? null : $this->text( "{$field}.filters.{$one}", $filters[ $one ] );
		}
		$args['days'] = $filters['days'] ?? null;
		if ( null !== $args['days'] && ! \is_int( $args['days'] ) ) {
			$this->refuse( "{$field}.filters.days", 'not a whole number' );
		}
		$checked     = $this->minted( "{$field}.filters", static fn (): array => Graph_Curation::lens_filters( $args ) );
		$name        = $this->text( "{$field}.name", $lens['name'] );
		$description = $this->text( "{$field}.description", $lens['description'] );
		$bounded     = $this->minted( "{$field}.name", static fn (): string => Graph_Curation::bounded( 'name', $name, Graph_Curation::NAME_BYTES ) );
		$this->lenses[ $key ] = $this->minted( $field, static fn (): array => self::mint( 'lens_saved', [ 'lens_key' => $key, 'name' => $bounded, 'description' => $description, 'filters' => $checked ] ) );
	}

	/**
	 * One seed event, under the seed's own machine actor.
	 *
	 * @param string              $kind   The event kind.
	 * @param array<string,mixed> $fields Its fields.
	 * @return array<string,mixed>
	 */
	private static function mint( string $kind, array $fields ): array {
		return Intel_Event::machine_event( 'ontology-seed', $kind, $fields );
	}

	/**
	 * A list of strings.
	 *
	 * @param string $field Where it sits.
	 * @param mixed  $value The value.
	 * @return list<string>
	 * @throws Seed_Refused Naming the field.
	 */
	private function strings( string $field, mixed $value ): array {
		if ( ! \is_array( $value ) || ! \array_is_list( $value ) ) {
			$this->refuse( $field, 'not a list of strings' );
		}
		$strings = [];
		foreach ( $value as $item ) {
			if ( ! \is_string( $item ) ) {
				$this->refuse( $field, 'not a list of strings' );
			}
			$strings[] = $item;
		}
		return $strings;
	}

	/**
	 * A JSON object's fields, refused when it is not an object, holds a
	 * field outside `$takes`, or lacks one of `$required`.
	 *
	 * @param string            $field    Where it sits, '' for the file.
	 * @param mixed             $value    The value.
	 * @param list<string>|null $takes    The fields it takes, null for any.
	 * @param list<string>      $required The fields it must hold.
	 * @return array<array-key,mixed>
	 * @throws Seed_Refused Naming the field.
	 */
	private function fields( string $field, mixed $value, ?array $takes = null, array $required = [] ): array {
		if ( ! \is_array( $value ) || ( [] !== $value && \array_is_list( $value ) ) ) {
			$this->refuse( '' === $field ? 'seed' : $field, 'not an object' );
		}
		$at = static fn ( int|string $key ): string => '' === $field ? (string) $key : "{$field}.{$key}";
		foreach ( \array_keys( $value ) as $key ) {
			if ( null !== $takes && ! \in_array( $key, $takes, true ) ) {
				$this->refuse( $at( $key ), 'not a field it takes' );
			}
		}
		foreach ( $required as $key ) {
			if ( ! \array_key_exists( $key, $value ) ) {
				$this->refuse( $at( $key ), 'missing' );
			}
		}
		return $value;
	}

	/**
	 * A seed key, refused unless the key its slug makes spells it the same,
	 * so a key is never written in a spelling the file does not use.
	 *
	 * @param string             $field Where it sits.
	 * @param string             $key   The key as the file spells it.
	 * @param \Closure(): string $make  The key the slug makes.
	 * @throws Seed_Refused Naming the field.
	 */
	private function keyed( string $field, string $key, \Closure $make ): string {
		$made = $this->minted( $field, $make );
		if ( $made !== $key ) {
			$this->refuse( $field, "not a slug; the key it makes is {$made}" );
		}
		return $key;
	}

	/**
	 * What `$make` answers, its `\InvalidArgumentException` refused as the
	 * field's.
	 *
	 * @template T
	 * @param string      $field Where it sits.
	 * @param \Closure(): T $make  What mints or checks the value.
	 * @return T
	 * @throws Seed_Refused Naming the field.
	 */
	private function minted( string $field, \Closure $make ): mixed {
		try {
			return $make();
		} catch ( \InvalidArgumentException $e ) {
			$this->refuse( $field, $e->getMessage() );
		}
	}

	/**
	 * One page's layout, held to every rule the composition layer applies
	 * but the page's `check`, which reads lenses this seed may not yet have
	 * written.
	 *
	 * @param string $field Where it sits.
	 * @param string $page  The page.
	 * @param mixed  $value Its markup.
	 * @throws Seed_Refused Naming the field.
	 */
	private function layout( string $field, string $page, mixed $value ): void {
		$context = Intelligence_Blocks::NAMESPACE . "/{$page}";
		$markup  = $this->text( $field, $value );
		try {
			Composition::validate_shape( $context, $markup );
		} catch ( \RuntimeException $e ) {
			$this->refuse( $field, $e->getMessage() );
		}
		$this->layouts[ $context ] = $markup;
	}

	/**
	 * A string.
	 *
	 * @param string $field Where it sits.
	 * @param mixed  $value The value.
	 * @throws Seed_Refused Naming the field.
	 */
	private function text( string $field, mixed $value ): string {
		if ( ! \is_string( $value ) ) {
			$this->refuse( $field, 'not a string' );
		}
		return $value;
	}

	/**
	 * Refuse the file, naming it and the field.
	 *
	 * @param string $field Where the fault sits.
	 * @param string $why   What it is.
	 * @throws Seed_Refused Always.
	 */
	private function refuse( string $field, string $why ): never {
		throw new Seed_Refused( \esc_html( \basename( $this->path ) . ": {$field}: {$why}" ) );
	}
}

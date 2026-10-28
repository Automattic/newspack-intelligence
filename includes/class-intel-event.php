<?php
/**
 * Intel_Event: the event vocabulary. One table names every kind, its stream,
 * its fields and the closed sets they are drawn from; writers build events
 * through make() and the projector reads them back through from_message().
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Core;
use Newspack_Nodes\Message;
use Newspack_Nodes\Partition_Node;

\defined( 'ABSPATH' ) || exit;

final class Intel_Event {
	public const ITEMS    = 'items';
	public const ONTOLOGY = 'ontology';

	public const TYPES            = [ 'organization', 'person', 'place', 'product', 'topic', 'event' ];
	public const ROLES            = [ 'client', 'former_client', 'prospect', 'competitor', 'partner', 'funder', 'industry_body', 'newspack_product' ];
	public const ITEM_KINDS       = [ 'award', 'launch', 'release', 'funding', 'hire', 'departure', 'partnership', 'incident', 'feature_request', 'complaint', 'praise', 'churn_signal', 'research', 'other' ];
	public const PREDICATES       = [ 'works_for', 'part_of', 'funds', 'partners_with', 'competes_with', 'uses_product', 'won', 'launched', 'located_in' ];
	public const NAME_KINDS       = [ 'canonical', 'alias', 'acronym', 'locality' ];
	public const IDENTIFIER_KINDS = [ 'domain', 'github_org', 'github_repo', 'linkedin_company', 'x_handle', 'atomic_site_id', 'linear_team' ];
	public const TIERS            = [ 'public', 'internal', 'restricted' ];
	public const LINK_ROLES       = [ 'subject', 'mention', 'topic' ];
	public const METHODS          = [ 'identifier', 'name', 'alias', 'acronym', 'domain_stem', 'ner_fuzzy', 'human', 'extract' ];

	/** The fields every event carries beside its kind's own, in mint order. */
	public const ENVELOPE = [ 'event', 'event_id', 'ts', 'actor', 'config_version' ];

	/**
	 * kind => [ stream, its own fields, [ field => the closed set it is drawn
	 * from ] ]. Every item-scoped kind but the record carries its item's
	 * `retrieved_ts`, the canonical's for a supersession, which dates its rows.
	 */
	public const KINDS = [
		'item_recorded'       => [ self::ITEMS, [ 'item_key', 'source', 'tier', 'url', 'title', 'body', 'item_ts', 'retrieved_ts' ], [ 'tier' => self::TIERS ] ],
		'extraction_recorded' => [ self::ITEMS, [ 'item_key', 'summary', 'kind', 'relevance', 'importance', 'sentiment', 'topics', 'retrieved_ts' ], [ 'kind' => self::ITEM_KINDS ] ],
		'subjects_extracted'  => [ self::ITEMS, [ 'item_key', 'entity_keys', 'retrieved_ts' ], [] ],
		'link_asserted'       => [ self::ITEMS, [ 'item_key', 'entity_key', 'role', 'method', 'confidence', 'retrieved_ts' ], [ 'role' => self::LINK_ROLES, 'method' => self::METHODS ] ],
		'link_proposed'       => [ self::ITEMS, [ 'item_key', 'entity_key', 'role', 'method', 'confidence', 'retrieved_ts' ], [ 'role' => self::LINK_ROLES, 'method' => self::METHODS ] ],
		'claim_asserted'      => [ self::ITEMS, [ 'item_key', 'ord', 'subject_key', 'predicate', 'object_key', 'object_text', 'confidence', 'retrieved_ts' ], [ 'predicate' => self::PREDICATES ] ],
		'cluster_joined'      => [ self::ITEMS, [ 'item_key', 'cluster_key', 'retrieved_ts' ], [] ],
		'cluster_superseded'  => [ self::ITEMS, [ 'cluster_key', 'canonical_item_key', 'previous_item_key', 'rank', 'retrieved_ts' ], [] ],
		'entity_created'      => [ self::ONTOLOGY, [ 'entity_key', 'type', 'name' ], [ 'type' => self::TYPES ] ],
		'entity_proposed'     => [ self::ONTOLOGY, [ 'entity_key', 'type', 'name', 'from_item_key' ], [ 'type' => self::TYPES ] ],
		'entity_accepted'     => [ self::ONTOLOGY, [ 'entity_key' ], [] ],
		'entity_rejected'     => [ self::ONTOLOGY, [ 'entity_key' ], [] ],
		'entity_merged'       => [ self::ONTOLOGY, [ 'entity_key', 'into_key' ], [] ],
		'name_added'          => [ self::ONTOLOGY, [ 'entity_key', 'kind', 'value' ], [ 'kind' => self::NAME_KINDS ] ],
		'identifier_added'    => [ self::ONTOLOGY, [ 'entity_key', 'kind', 'value' ], [ 'kind' => self::IDENTIFIER_KINDS ] ],
		'role_asserted'       => [ self::ONTOLOGY, [ 'entity_key', 'role', 'from_ts' ], [ 'role' => self::ROLES ] ],
		'role_ended'          => [ self::ONTOLOGY, [ 'entity_key', 'role', 'from_ts', 'to_ts' ], [ 'role' => self::ROLES ] ],
		'topic_tagged'        => [ self::ONTOLOGY, [ 'entity_key', 'topic_key' ], [] ],
		'link_confirmed'      => [ self::ONTOLOGY, [ 'item_key', 'entity_key', 'item_url' ], [] ],
		'link_rejected'       => [ self::ONTOLOGY, [ 'item_key', 'entity_key', 'item_url' ], [] ],
		'collection_created'  => [ self::ONTOLOGY, [ 'collection_key', 'name' ], [] ],
		'collected'           => [ self::ONTOLOGY, [ 'collection_key', 'item_key', 'url', 'title', 'tier' ], [ 'tier' => self::TIERS ] ],
	];

	/** The actor classes an actor string may begin with. */
	private const ACTOR_CLASSES = [ 'user', 'agent', 'machine' ];

	/** @var array<string,list<string>> Each kind's envelope and fields, built on first validate(). */
	private static array $required = [];

	/**
	 * An event a pipeline stage mints: its actor the stage at the plugin
	 * version, and that version its config_version.
	 *
	 * @param string              $stage  The stage name.
	 * @param string              $kind   An event kind from KINDS.
	 * @param array<string,mixed> $fields The kind's fields.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException As make() does.
	 */
	public static function machine_event( string $stage, string $kind, array $fields ): array {
		return self::make( $kind, $fields, self::machine( $stage ), \NEWSPACK_INTELLIGENCE_VERSION );
	}

	/**
	 * A pipeline stage's actor: the stage at the plugin version.
	 *
	 * @param string $stage The stage name.
	 */
	public static function machine( string $stage ): string {
		return self::machine_prefix( $stage ) . \NEWSPACK_INTELLIGENCE_VERSION;
	}

	/**
	 * What every actor a pipeline stage mints begins with, at any version.
	 *
	 * @param string $stage The stage name.
	 */
	public static function machine_prefix( string $stage ): string {
		return "machine:{$stage}@";
	}

	/**
	 * Build a validated event VALUE, stamped on the tick clock, which the
	 * substrate pins at class load. An ontology event must pack into one
	 * partition line, because that stream takes no large write.
	 *
	 * @param string              $kind           An event kind from KINDS.
	 * @param array<string,mixed> $fields         The kind's fields.
	 * @param string              $actor          The actor string.
	 * @param string              $config_version The config version in force.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the event does not satisfy its kind, or an ontology event does not fit one line.
	 */
	public static function make( string $kind, array $fields, string $actor, string $config_version ): array {
		$event = [
			'event'          => $kind,
			'event_id'       => \bin2hex( \random_bytes( 16 ) ),
			'ts'             => Core::$now,
			'actor'          => $actor,
			'config_version' => $config_version,
		] + $fields;
		self::validate( $event );
		if ( self::ONTOLOGY === self::KINDS[ $kind ][0] ) {
			$message                   = Message::new_message();
			$message[ Message::TYPE ]  = Message::TM_STRUCT;
			$message[ Message::VALUE ] = $event;
			$size                      = Message::packed_size( $message ) + 1;
			if ( $size > Partition_Node::MAX_LINE_SIZE ) {
				throw new \InvalidArgumentException( \esc_html( "{$kind}: {$size} bytes packed, past the " . Partition_Node::MAX_LINE_SIZE . '-byte line the ontology stream takes' ) );
			}
		}
		return $event;
	}

	/**
	 * The event a message carries: a TM_STRUCT whose VALUE validates.
	 *
	 * @param array<int,mixed> $message A Message.
	 * @return array<array-key,mixed>
	 * @throws \InvalidArgumentException Naming why it carries none.
	 */
	public static function from_message( array $message ): array {
		if ( 0 === ( Core::int( $message[ Message::TYPE ] ) & Message::TM_STRUCT ) ) {
			throw new \InvalidArgumentException( 'not a TM_STRUCT' );
		}
		$event = $message[ Message::VALUE ];
		if ( ! \is_array( $event ) ) {
			throw new \InvalidArgumentException( 'no array VALUE' );
		}
		self::validate( $event );
		return $event;
	}

	/**
	 * Refuse an event that is not a known kind with its fields, sets and actor.
	 *
	 * @param array<array-key,mixed> $event An event VALUE, as read off the wire.
	 * @throws \InvalidArgumentException Naming the kind and field at fault.
	 */
	public static function validate( array $event ): void {
		$kind = $event['event'] ?? null;
		if ( ! \is_string( $kind ) || ! isset( self::KINDS[ $kind ] ) ) {
			throw new \InvalidArgumentException( 'unknown event kind: ' . ( \is_string( $kind ) ? $kind : \get_debug_type( $kind ) ) );
		}
		[ $stream, $fields, $sets ] = self::KINDS[ $kind ];
		foreach ( self::$required[ $kind ] ??= [ ...self::ENVELOPE, ...$fields ] as $field ) {
			if ( ! \array_key_exists( $field, $event ) ) {
				throw new \InvalidArgumentException( "{$kind}: missing field {$field}" );
			}
		}
		if ( ! \is_string( $event['event_id'] ) || 1 !== \preg_match( '/^[0-9a-f]{32}$/', $event['event_id'] ) ) {
			throw new \InvalidArgumentException( "{$kind}: event_id is not 32 lowercase hex" );
		}
		if ( ! \is_int( $event['ts'] ) && ! \is_float( $event['ts'] ) ) {
			throw new \InvalidArgumentException( "{$kind}: ts is not a number" );
		}
		if ( ! \is_string( $event['config_version'] ) ) {
			throw new \InvalidArgumentException( "{$kind}: config_version is not a string" );
		}
		if ( ! \is_string( $event['actor'] ) ) {
			throw new \InvalidArgumentException( "{$kind}: actor is not a string" );
		}
		$class = \explode( ':', $event['actor'], 2 )[0];
		if ( ! \in_array( $class, self::ACTOR_CLASSES, true ) ) {
			throw new \InvalidArgumentException( "{$kind}: unknown actor: {$event['actor']}" );
		}
		foreach ( $sets as $field => $set ) {
			if ( ! \in_array( $event[ $field ], $set, true ) ) {
				throw new \InvalidArgumentException( "{$kind}: field {$field} is outside its set" );
			}
		}
		if ( self::ITEMS === $stream && 'machine' !== $class ) {
			throw new \InvalidArgumentException( "{$kind}: only a machine writes the items stream" );
		}
	}

	/**
	 * An entity's key: its type and the slug of its name.
	 *
	 * @param string $type An entity type from TYPES.
	 * @param string $name The name the key is slugged from.
	 * @throws \InvalidArgumentException On a type outside TYPES, or a name that sanitizes to nothing.
	 */
	public static function entity_key( string $type, string $name ): string {
		if ( ! \in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( \esc_html( "unknown entity type: {$type}" ) );
		}
		return "{$type}:" . self::slug( "{$type} name", $name );
	}

	/**
	 * A collection's key: the slug of its name.
	 *
	 * @param string $name The collection's name.
	 * @throws \InvalidArgumentException On a name that sanitizes to nothing.
	 */
	public static function collection_key( string $name ): string {
		return self::slug( 'collection name', $name );
	}

	/**
	 * A name as a key slugs it, refused when it sanitizes to nothing.
	 *
	 * @param string $what What the name names, for the refusal.
	 * @param string $name The name.
	 * @throws \InvalidArgumentException When it sanitizes to nothing.
	 */
	private static function slug( string $what, string $name ): string {
		$slug = \sanitize_title( $name );
		if ( '' === $slug ) {
			throw new \InvalidArgumentException( \esc_html( "{$what} {$name} sanitizes to nothing" ) );
		}
		return $slug;
	}

	/**
	 * The second a role a person opens or closes is stamped with: the tick
	 * clock's, which the substrate pins at class load.
	 */
	public static function now(): int {
		return (int) Core::$now;
	}

	/**
	 * The actor a curation is recorded as: the agent the caller's session
	 * label names, `agent:<login>:<label>`, the label as the substrate bounds
	 * it; else the signed-in person, `user:<login>`.
	 *
	 * @throws \InvalidArgumentException When no person is signed in.
	 */
	public static function caller(): string {
		$login = Core::as_string( \wp_get_current_user()->user_login );
		if ( '' === $login ) {
			throw new \InvalidArgumentException( 'a user actor needs a login' );
		}
		$label = Capabilities::$session_label;
		return null === $label || '' === $label ? "user:{$login}" : "agent:{$login}:{$label}";
	}

	/**
	 * The item a record carries: exactly the `item_recorded` fields, in
	 * their order, or null when one is missing.
	 *
	 * @param array<array-key,mixed> $fields The fields carried.
	 * @return array<string,mixed>|null
	 */
	public static function item_of( array $fields ): ?array {
		$item = [];
		foreach ( self::KINDS['item_recorded'][1] as $field ) {
			if ( ! \array_key_exists( $field, $fields ) ) {
				return null;
			}
			$item[ $field ] = $fields[ $field ];
		}
		return $item;
	}

	/**
	 * The stream a kind is written to.
	 *
	 * @param string $kind An event kind.
	 * @throws \InvalidArgumentException On an unknown kind.
	 */
	public static function stream_of( string $kind ): string {
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			throw new \InvalidArgumentException( "unknown event kind: {$kind}" );
		}
		return self::KINDS[ $kind ][0];
	}
}

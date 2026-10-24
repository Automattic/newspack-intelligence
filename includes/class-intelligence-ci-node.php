<?php
/**
 * Intelligence_CI_Node: the `intelligence` service interpreter. Its read
 * verbs declare `read` and answer through `Graph_Query`, whose reads see
 * only the items of the tiers `Intel_Tiers::readable()` grants. Its curation
 * verbs declare `tune` and write each judgment as one ontology event through
 * `Graph_Curation`, which reads through the same graph. `fetch_now` declares
 * `manage`, because it mounts a live worker's input to TICK every source
 * (substrate ADR-23).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Config;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Intelligence_CI_Node extends Service_CI_Node {

	/** The source node names a fetch ticks; their count MUST equal the digest's `total` make_node arg in newspack-intelligence-digest.tsl. */
	private const SOURCE_NODES = [ 'github', 'linear', 'feed' ];

	/**
	 * A TICK request for every source node, as `Worker_Route::send()` takes
	 * them.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	public static function ticks(): array {
		return \array_map( static fn ( string $source ): array => [ $source, 'TICK' ], self::SOURCE_NODES );
	}

	/**
	 * A verb whose handler answers what `$answer` makes of its bound args.
	 *
	 * @param string                                                 $capability  The role it declares.
	 * @param string                                                 $name        Its name.
	 * @param string                                                 $description What it answers.
	 * @param list<array<string,mixed>>                              $args        Its args.
	 * @param \Closure(array<array-key,mixed>): array<string,mixed> $answer      The answer.
	 * @return array<string,mixed>
	 */
	private static function verb( string $capability, string $name, string $description, array $args, \Closure $answer ): array {
		return [
			'name'        => $name,
			'capability'  => $capability,
			'description' => $description,
			'args'        => $args,
			'handler'     => static fn ( self $ci, array $args ): array => $answer( $args ),
		];
	}

	/**
	 * Declarative schema: the eight read verbs, each a handler over
	 * `Graph_Query`; the thirteen curation verbs, each over
	 * `Graph_Curation` and seeing only the entities and items a read
	 * caller sees; and `fetch_now`.
	 *
	 * @api Used by the substrate to build the dispatch table and the catalog.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		$verb  = self::verb( ... );
		$arg   = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'required' => true, 'description' => $what ];
		$key   = static fn ( string $what ): array => $arg( 'key', $what );
		$limit = static fn ( int $default ): array => [ 'name' => 'limit', 'type' => 'int', 'default' => $default, 'description' => 'The most rows answered, at most ' . Graph_Query::MAX_LIMIT . '.' ];
		$text  = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'description' => $what ];
		$since = $text( 'since', 'The first UTC day, Y-m-d.' );
		$until = $text( 'until', 'The last UTC day, Y-m-d.' );
		$page  = [ 'name' => 'offset', 'type' => 'int', 'default' => 0, 'description' => 'Rows skipped before the page.' ];
		$item  = $arg( 'item', 'The item key.' );
		$link  = [ $item, $arg( 'entity', 'The entity key.' ) ];
		$about = $key( 'The entity key.' );
		$role  = [ $about, $arg( 'role', 'The role: ' . \implode( ', ', Intel_Event::ROLES ) . '.' ) ];
		$read  = Capabilities::READ;
		$tune  = Capabilities::TUNE;
		return \array_merge(
			parent::node_schema(),
			[
				'category'    => 'Service',
				'description' => 'Reads the intelligence graph — items, entities, claims, trends, the review queue and collections, each answer citing its items — and records each curation as an ontology event.',
				'commands'    => [
					$verb(
						$read,
						'search',
						'Items matching every filter given, newest first: { items: [ { item_key, title, url, source, tier, item_ts, summary, kind, relevance, importance, entities } ], total }.',
						[
							$text( 'q', 'Words every match holds in its title, body or summary.' ),
							$text( 'entity', 'An entity the item links.' ),
							$text( 'kind', 'The item kind its extraction gave.' ),
							$text( 'topic', 'A topic the item is tagged with.' ),
							$text( 'source', 'The source that recorded it.' ),
							$since,
							$until,
							$limit( Graph_Query::DEFAULT_LIMIT ),
							$page,
						],
						Graph_Query::search( ... )
					),
					$verb( $read, 'entity', 'One entity as its merge survivor: { entity_key, type, name, state, merged_from, names, identifiers, roles, topics, counts: { items, proposed_links } }.', [ $about ], Graph_Query::entity( ... ) ),
					$verb( $read, 'timeline', 'An entity\'s items, newest first: { items, total }, each item as search answers it.', [ $about, $since, $until, $limit( Graph_Query::DEFAULT_LIMIT ), $page ], Graph_Query::timeline( ... ) ),
					$verb( $read, 'related', 'The entities sharing the most items with one: { entities: [ { entity_key, name, type, shared_items, item_keys } ] }.', [ $about, $limit( Graph_Query::RELATED_LIMIT ) ], Graph_Query::related( ... ) ),
					$verb( $read, 'claims', 'The claims naming an entity, newest first: { claims: [ { subject, predicate, object, confidence, item_key, url } ] }.', [ $about, $text( 'predicate', 'Only claims of this predicate.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::claims( ... ) ),
					$verb(
						$read,
						'trends',
						'Items counted by kind or topic per bucket: { rows: [ { bucket, value, count, item_keys } ] }, five newest items cited a row.',
						[
							$arg( 'by', 'What is counted: kind or topic.' ),
							$text( 'role', 'Only items linking an entity holding this role at the time.' ),
							$text( 'entity', 'Only items linking this entity.' ),
							$since,
							$until,
							[ 'name' => 'bucket', 'type' => 'string', 'default' => 'week', 'description' => 'The span a row counts: day, week or month.' ],
						],
						Graph_Query::trends( ... )
					),
					$verb( $read, 'review', 'The review queue, each row with its citing items: { links } or { entities }.', [ $arg( 'what', 'The queue: links or entities.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::review( ... ) ),
					$verb( $read, 'collections', 'Every collection with its item count, or one with its saved items.', [ $text( 'key', 'One collection\'s key.' ) ], Graph_Query::collections( ... ) ),
					$verb( $tune, 'confirm_link', 'Confirm a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_confirmed', $args ) ),
					$verb( $tune, 'reject_link', 'Reject a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_rejected', $args ) ),
					$verb( $tune, 'accept_entity', 'Accept an entity: { ok, event_id }.', [ $about ], static fn ( array $args ): array => Graph_Curation::judge_entity( 'entity_accepted', $args ) ),
					$verb( $tune, 'reject_entity', 'Reject an entity: { ok, event_id }.', [ $about ], static fn ( array $args ): array => Graph_Curation::judge_entity( 'entity_rejected', $args ) ),
					$verb( $tune, 'merge_entities', 'Merge one entity into another, refused when the merge would cycle: { ok, event_id }.', [ $arg( 'from', 'The entity merged away.' ), $arg( 'into', 'The entity it survives as.' ) ], Graph_Curation::merge( ... ) ),
					$verb(
						$tune,
						'add_name',
						'Add a name to an entity: { ok, event_id }.',
						[ $about, $arg( 'kind', 'The name kind: ' . \implode( ', ', Intel_Event::NAME_KINDS ) . '.' ), $arg( 'value', 'The name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::add_name( ... )
					),
					$verb(
						$tune,
						'add_identifier',
						'Add an identifier to an entity, refused when another entity holds it: { ok, event_id }.',
						[ $about, $arg( 'kind', 'The identifier kind: ' . \implode( ', ', Intel_Event::IDENTIFIER_KINDS ) . '.' ), $arg( 'value', 'The identifier, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::add_identifier( ... )
					),
					$verb( $tune, 'assert_role', 'Open a role on an entity from now: { ok, event_id }.', $role, Graph_Curation::assert_role( ... ) ),
					$verb( $tune, 'end_role', 'Close the role an entity holds open, now: { ok, event_id }.', $role, Graph_Curation::end_role( ... ) ),
					$verb( $tune, 'tag_topic', 'Tag an entity with a topic: { ok, event_id }.', [ $about, $arg( 'topic', 'The topic\'s entity key.' ) ], Graph_Curation::tag_topic( ... ) ),
					$verb(
						$tune,
						'create_entity',
						'Create an entity keyed by its type and name, refused when the key is taken: { ok, event_id, entity_key }.',
						[ $arg( 'type', 'The entity type: ' . \implode( ', ', Intel_Event::TYPES ) . '.' ), $arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::create_entity( ... )
					),
					$verb( $tune, 'create_collection', 'Create a collection keyed by its name, refused when the key is taken: { ok, event_id, collection_key }.', [ $arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ], Graph_Curation::create_collection( ... ) ),
					$verb( $tune, 'collect', 'Save a readable item into a collection, its title cut at ' . Graph_Curation::TITLE_BYTES . ' bytes: { ok, event_id }.', [ $arg( 'collection', 'The collection key.' ), $item ], Graph_Curation::collect( ... ) ),
					[
						'name'        => 'fetch_now',
						'capability'  => Capabilities::MANAGE,
						'description' => 'TICK every source in each live worker: { ok, workers }.',
						'args'        => [],
						'handler'     => static fn ( self $ci ): array => [ 'ok' => true, 'workers' => Worker_Route::send( $ci, Config::get_base_directory(), self::ticks() ) ],
					],
				],
			]
		);
	}
}

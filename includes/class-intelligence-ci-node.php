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
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Service_CI_Node;

\defined( 'ABSPATH' ) || exit;

class Intelligence_CI_Node extends Service_CI_Node {

	/** The source node names `fetch_now` ticks. */
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
	 * Declarative schema: the ten read verbs, each a handler over
	 * `Graph_Query`; the five watch verbs, also at read, each over
	 * `Graph_Watches` and answering the caller's own watches alone; the
	 * fifteen curation verbs, each over `Graph_Curation` and seeing only the
	 * entities and items a read caller sees; and `fetch_now`.
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
		$any   = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'variadic' => true, 'description' => "{$what} Repeat it to match any." ];
		$lists = [];
		foreach ( Graph_Query::LENS_LISTS as [ $option, $values, $what ] ) {
			$lists[] = $any( $option, $what . ( null === $values ? '.' : ': ' . \implode( ', ', $values ) . '.' ) );
		}
		$lens  = $text( 'lens', 'A lens whose filters apply; each filter the call names itself replaces the lens\'s, and since or until replaces its days.' );
		$axis  = $text( 'by', 'What is counted: ' . \implode( ' or ', Graph_Query::trend_axes() ) . '; else the lens\'s, else ' . Graph_Query::DEFAULT_AXIS . '.' );
		$span  = $text( 'bucket', 'The span a row counts: ' . \implode( ', ', Graph_Query::buckets() ) . '; else the lens\'s, else ' . Graph_Query::DEFAULT_BUCKET . '.' );
		$since = $text( 'since', 'The first UTC day, Y-m-d.' );
		$until = $text( 'until', 'The last UTC day, Y-m-d.' );
		$page  = [ 'name' => 'offset', 'type' => 'int', 'default' => 0, 'description' => 'Rows skipped before the page.' ];
		$item  = $arg( 'item', 'The item key.' );
		$link  = [ $item, $arg( 'entity', 'The entity key.' ) ];
		$about = $key( 'The entity key.' );
		$role  = [ $about, $arg( 'role', 'The role: ' . \implode( ', ', Intel_Event::ROLES ) . '.' ) ];
		$box   = static fn ( string $name, bool $default, string $what ): array => [ 'name' => $name, 'type' => 'bool', 'default' => $default, 'description' => $what ];
		$read  = Capabilities::READ;
		$tune  = Capabilities::TUNE;
		return \array_merge(
			parent::node_schema(),
			[
				'category'    => 'Service',
				'description' => 'Reads the intelligence graph — items, entities, claims, trends, the review queue, collections and lenses, each answer citing its items — and records each curation as an ontology event.',
				'commands'    => [
					$verb(
						$read,
						'search',
						'Items matching every filter given, newest first: { items: [ { item_key, title, url, source, tier, item_ts, summary, kind, relevance, importance, entities } ], total }.',
						[
							$text( 'q', 'Words every match holds in its title, body or summary.' ),
							$text( 'entity', 'An entity the item links.' ),
							$lens,
							$since,
							$until,
							$limit( Graph_Query::DEFAULT_LIMIT ),
							$page,
							...$lists,
						],
						Graph_Query::search( ... )
					),
					$verb( $read, 'entity', 'One entity as its merge survivor: { entity_key, type, name, state, merged_from, names, identifiers, roles, topics, counts: { items, proposed_links } }.', [ $about ], Graph_Query::entity( ... ) ),
					$verb( $read, 'timeline', 'An entity\'s items, newest first: { items, total }, each item as search answers it.', [ $about, $since, $until, $limit( Graph_Query::DEFAULT_LIMIT ), $page ], Graph_Query::timeline( ... ) ),
					$verb( $read, 'related', 'The entities sharing the most items with one: { entities: [ { entity_key, name, type, shared_items, items: [ { item_key, url, title } ] } ] }, five newest shared items cited an entity.', [ $about, $limit( Graph_Query::RELATED_LIMIT ) ], Graph_Query::related( ... ) ),
					$verb( $read, 'claims', 'The claims naming an entity, newest first: { claims: [ { subject, predicate, object, confidence, item_key, url } ] }.', [ $about, $text( 'predicate', 'Only claims of this predicate.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::claims( ... ) ),
					$verb(
						$read,
						'trends',
						'Items counted by kind or topic per bucket: { rows: [ { bucket, value, count, items: [ { item_key, url, title } ] } ] }, five newest items cited a row.',
						[
							$axis,
							$text( 'entity', 'Only items linking this entity.' ),
							$lens,
							$since,
							$until,
							$span,
							...$lists,
						],
						Graph_Query::trends( ... )
					),
					$verb(
						$read,
						'signals',
						'The latest complete bucket\'s spikes, counted as trends counts: each value whose count reaches ' . Graph_Query::SIGNAL_MIN . ' and the mean plus ' . Graph_Query::SIGNAL_SIGMAS . ' standard deviations of the ' . Graph_Query::SIGNAL_BASELINE . ' buckets before it: { signals: [ { bucket, by, value, count, baseline, items: [ { item_key, url, title } ] } ] }, baseline the mean.',
						[ $lens, $text( 'entity', 'Only items linking this entity.' ) ],
						Graph_Query::signals( ... )
					),
					$verb( $read, 'review', 'The review queue, each row with its citing items: { links } or { entities }.', [ $arg( 'what', 'The queue: links or entities.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::review( ... ) ),
					$verb( $read, 'collections', 'Every collection with its item count, or one with its saved items.', [ $text( 'key', 'One collection\'s key.' ) ], Graph_Query::collections( ... ) ),
					$verb( $read, 'lenses', 'Every lens, by name: { lenses: [ { lens_key, name, description, filters: { kinds, topics, roles, sources, entity, days, tiers, by, bucket } } ] }.', [], Graph_Query::lenses( ... ) ),
					$verb( $read, 'watches', 'The caller\'s own watches, by name, each with its unseen matches: { watches: [ { watch_key, name, target: { kind, key }, digest, instant, inbox, unseen } ] }.', [], Graph_Watches::watches( ... ) ),
					$verb(
						$read,
						'save_watch',
						'Save one of the caller\'s own watches over exactly one lens, entity or collection, ticking at least one delivery; a key replaces one of the caller\'s watches: { ok, event_id, watch_key }.',
						[
							$arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ),
							$text( 'lens', 'A lens it follows.' ),
							$text( 'entity', 'An entity it follows.' ),
							$text( 'collection', 'A collection it follows.' ),
							$box( 'digest', false, 'Email a daily digest of its matches.' ),
							$box( 'instant', false, 'Email each new match.' ),
							$box( 'inbox', true, 'List its unseen matches on the Intelligence page.' ),
							$text( 'key', 'The key of the caller\'s own watch it replaces.' ),
						],
						Graph_Watches::save( ... )
					),
					$verb( $read, 'remove_watch', 'Remove one of the caller\'s own watches: { ok, event_id }.', [ $key( 'The watch key.' ) ], Graph_Watches::remove( ... ) ),
					$verb( $read, 'watch_matches', 'The unseen matches of one of the caller\'s own watches, latest matched first: { items, total }, each item as search answers it plus matched_ts.', [ $key( 'The watch key.' ), $limit( Graph_Query::DEFAULT_LIMIT ), $page ], Graph_Watches::matches( ... ) ),
					$verb( $read, 'mark_seen', 'Mark seen matches of one of the caller\'s own watches, every unseen one when no item is named: { ok, count }.', [ $key( 'The watch key.' ), [ 'name' => 'item', 'type' => 'string', 'variadic' => true, 'description' => 'A matched item key; repeat it to mark several.' ] ], Graph_Watches::mark_seen( ... ) ),
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
					$verb(
						$tune,
						'save_lens',
						'Save a lens, a named set of search and trends filters, replacing any under its key; refused past one ' . Partition_Node::MAX_LINE_SIZE . '-byte ontology line: { ok, event_id, lens_key }.',
						[
							$key( 'The lens key, slugged.' ),
							$arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ),
							[ 'name' => 'description', 'type' => 'string', 'default' => '', 'description' => 'What it is for.' ],
							$text( 'entity', 'An entity every item links.' ),
							[ 'name' => 'days', 'type' => 'int', 'description' => 'The last days it reads, today included, 1 to ' . Intel_Graph_Row_Schema::RETAIN_DAYS . '.' ],
							$text( 'by', 'What its trends count: ' . \implode( ' or ', Graph_Query::trend_axes() ) . '.' ),
							$text( 'bucket', 'The span its trend rows count: ' . \implode( ', ', Graph_Query::buckets() ) . '.' ),
							...$lists,
							$any( 'tier', 'A tier it reads, narrowing what its reader may read: ' . \implode( ', ', Intel_Event::TIERS ) . '.' ),
						],
						Graph_Curation::save_lens( ... )
					),
					$verb( $tune, 'remove_lens', 'Remove a lens: { ok, event_id }.', [ $key( 'The lens key.' ) ], Graph_Curation::remove_lens( ... ) ),
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

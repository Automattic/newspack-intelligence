<?php
/**
 * Intelligence_CI_Node: the `intelligence` service interpreter. Its read
 * commands declare `read` and answer through `Graph_Query`, whose reads see
 * only the items of the tiers `Intel_Tiers::readable()` grants. Its curation
 * commands declare `tune` and write each judgment as one ontology event through
 * `Graph_Curation`, which reads through the same graph; `seed_ontology`, at
 * `tune` too, writes the seed through `Ontology_Seed`. `fetch_now` and
 * `reresolve` declare `manage`, because each mounts a live worker's input,
 * one to TICK every source and one to send a re-resolution range (substrate
 * ADR-23).
 *
 * @package Newspack_Intelligence
 */

namespace Newspack_Intelligence;

use Newspack_Nodes\Capabilities;
use Newspack_Nodes\Config;
use Newspack_Nodes\Core;
use Newspack_Nodes\Partition_Node;
use Newspack_Nodes\Service_CI_Node;
use Newspack_Nodes\Topology_Analyzer;

\defined( 'ABSPATH' ) || exit;

class Intelligence_CI_Node extends Service_CI_Node {

	/**
	 * A TICK request for every Source node Intelligence's topology declares,
	 * in its order, as `Worker_Route::send()` takes them.
	 *
	 * @return list<array{0:string,1:string}>
	 */
	public static function ticks(): array {
		return \array_map( static fn ( array $node ): array => [ Core::as_string( $node['name'] ?? null ), 'TICK' ], self::sources() );
	}

	/**
	 * Every Source node Intelligence's topology declares, in its order, as
	 * `Topology_Analyzer::nodes_of_type()` exports each; none while
	 * collection is off.
	 *
	 * @api Read by Intelligence_Health.
	 * @return list<array<string,mixed>>
	 * @throws \RuntimeException When `Worker_Route::running()` cannot name one topology.
	 */
	public static function sources(): array {
		$topology = Worker_Route::running();
		return null === $topology ? [] : Topology_Analyzer::nodes_of_type( $topology, Source_Node::class );
	}

	/**
	 * A command whose handler answers what `$answer` makes of its bound args.
	 *
	 * @param string                                                    $capability  The role it declares.
	 * @param string                                                    $name        Its name.
	 * @param string                                                    $description What it answers.
	 * @param list<array<string,mixed>>                                 $args        Its args.
	 * @param \Closure(array<array-key,mixed>): array<array-key,mixed> $answer      The answer.
	 * @return array<string,mixed>
	 */
	private static function command( string $capability, string $name, string $description, array $args, \Closure $answer ): array {
		return [
			'name'        => $name,
			'capability'  => $capability,
			'description' => $description,
			'args'        => $args,
			'handler'     => static fn ( self $ci, array $args ): array => $answer( $args ),
		];
	}

	/**
	 * Declarative schema: the eleven read commands, each a handler over
	 * `Graph_Query`; the five watch commands, also at read, each over
	 * `Graph_Watches` and answering the caller's own watches alone;
	 * `briefing` and `health`, at read, over `Intelligence_Briefing` and
	 * `Intelligence_Health`; `ask` and `ask_result`, at read, over
	 * `Graph_Asks`; the fifteen curation
	 * commands, each over `Graph_Curation` and seeing only the
	 * entities and items a read caller sees; `seed_ontology`, at tune, over
	 * `Ontology_Seed::load()->seed()`; and `fetch_now` and `reresolve`, at manage,
	 * each sending to every live worker through `Worker_Route`.
	 *
	 * @api Used by the substrate to build the dispatch table and the catalog.
	 * @return array<string,mixed>
	 */
	public static function node_schema(): array {
		$command = self::command( ... );
		$arg     = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'required' => true, 'description' => $what ];
		$key     = static fn ( string $what ): array => $arg( 'key', $what );
		$limit   = static fn ( ?int $default, string $else = '' ): array => [ 'name' => 'limit', 'type' => 'int' ] + ( null === $default ? [] : [ 'default' => $default ] ) + [ 'description' => 'The most rows answered, at most ' . Graph_Query::MAX_LIMIT . "{$else}." ];
		$text    = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'description' => $what ];
		$any     = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'variadic' => true, 'description' => "{$what} Repeat it to match any." ];
		$lists   = [];
		foreach ( Graph_Query::LENS_LISTS as [ $option, $values, $what ] ) {
			$lists[] = $any( $option, $what . ( null === $values ? '.' : ': ' . \implode( ', ', $values ) . '.' ) );
		}
		$lens  = $text( 'lens', 'A lens whose filters apply; each filter the call names itself replaces the lens\'s, and since or until replaces its days.' );
		$axes  = \implode( ', ', Graph_Query::trend_axes() );
		$axis  = $text( 'by', "What is counted, one of {$axes}; else the lens's, else " . Graph_Query::DEFAULT_AXIS . '.' );
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
					$command(
						$read,
						'search',
						'Items matching every filter given, in the order sort names: { items: [ { item_key, title, url, source, tier, item_ts, summary, excerpt, kind, relevance, importance, entities } ], total, sort }, excerpt the opening of its body as plain text. With group=theme, through a lens or not, the same items as groups sharing a theme instead: { groups: [ { theme, kind, count, clients: [ { entity_key, name } ], newest_ts, items } ], total, available }, over the last ' . Graph_Query::GROUP_DAYS . ' days when neither the call nor its lens bounds the days, each story counted once as its canonical, an item with no theme a group of its own whose theme is \'\'; kind the most severe among every copy of its stories, by the kind\'s place in the kinds filter, most severe first, every kind alike when it names none; clients the open clients those copies are about; items its ' . Graph_Query::CITATIONS . ' newest stories, each as an item above, the most important first; groups ranked by kind, then clients, most first, then newest story, then theme; total, limit and offset counting groups.',
						[
							$text( 'q', 'Words every match holds in its title, body or summary.' ),
							$text( 'sort', 'The order, one of ' . \implode( ', ', Graph_Query::sorts() ) . ': importance the highest first, then the most relevant, then the newest; time the newest first. With none, time for words without a lens, else importance. Refused beside group.' ),
							$text( 'group', 'Answer groups instead of items, one of: ' . \implode( ', ', Graph_Query::groupings() ) . '.' ),
							$text( 'entity', 'An entity the item links.' ),
							$lens,
							$since,
							$until,
							$limit( null, '; else ' . Graph_Query::DEFAULT_LIMIT . ' items, or ' . Graph_Query::GROUP_LIMIT . ' groups' ),
							$page,
							...$lists,
						],
						Graph_Query::search( ... )
					),
					$command( $read, 'entity', 'One entity as its merge survivor: { entity_key, type, name, state, merged_from, names, identifiers, roles, topics, counts: { items, proposed_links }, published: { count }, status }, name the one it was created with, or, for one created under its own domain, its newest canonical name, counts.items the items about it, published.count those its own site published, and status, for a client alone, { client_since, kinds: { ' . \implode( ', ', Graph_Query::STATUS_KINDS ) . ' }, last_item_ts, churn_open } over the items about it, churn_open whether one dated after client_since is extracted churned, else null.', [ $about ], Graph_Query::entity( ... ) ),
					$command( $read, 'names', 'At most ' . Graph_Query::NAMES_LIMIT . ' entities holding a name or alias whose first word, or a later one, starts with prefix, as the resolver\'s index reads names, case and punctuation aside, each once as its merge survivor: { entities: [ { entity_key, name } ], available }, those whose whole name the prefix leads first, then by name; a rejected entity, a topic and an entity the caller may not see left out, and available false when the graph would not open.', [ $arg( 'prefix', 'What the name starts with, at least ' . Graph_Query::NAMES_MIN_PREFIX . ' letters or digits.' ) ], Graph_Query::names( ... ) ),
					$command( $read, 'timeline', 'The items about an entity, or with published those its own site published, newest first: { items, total }, each item as search answers it.', [ $about, $since, $until, $limit( Graph_Query::DEFAULT_LIMIT ), $page, $box( 'published', false, 'Page the items its own site published instead of those about it.' ) ], Graph_Query::timeline( ... ) ),
					$command( $read, 'related', 'The entities sharing the most items with one: { entities: [ { entity_key, name, type, shared_items, items: [ { item_key, url, title } ] } ] }, five newest shared items cited an entity.', [ $about, $limit( Graph_Query::RELATED_LIMIT ) ], Graph_Query::related( ... ) ),
					$command( $read, 'claims', 'The claims naming an entity, newest first, object_name null where the object is as the item spelled it: { claims: [ { subject, subject_name, predicate, object, object_name, confidence, item_key, url, title } ] }.', [ $about, $text( 'predicate', 'Only claims of this predicate.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::claims( ... ) ),
					$command(
						$read,
						'trends',
						"Items counted by {$axes} per bucket, the newest bucket first, a story counted once as its canonical: { rows: [ { bucket, value, count, items: [ { item_key, url, title } ] } ] }, five newest items cited a row; counting by theme, each row also names clients: [ { entity_key, name } ], the open clients its items are about, before its items, and an item with no theme counts under none.",
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
					$command(
						$read,
						'signals',
						'The latest complete bucket\'s signals, a story counted once as its canonical on both axes. On the about axis, spikes counted as trends counts, but other never signals on the about axis: each value whose count reaches ' . Graph_Query::SIGNAL_MIN . ', passes the mean and reaches the mean plus ' . Graph_Query::SIGNAL_SIGMAS . ' standard deviations of the ' . Graph_Query::SIGNAL_BASELINE . ' buckets before it, and only a value counted before those buckets, since a first appearance is not a change. For an entity named with no lens, also the publisher axis, what its own site published per week: a burst of one kind by the spike rule, a kind it never put out before included; and for a client, a drop to ' . Graph_Query::SIGNAL_DROP . ' of a baseline mean of at least ' . Graph_Query::SIGNAL_MIN . ', or silent on the first two empty weeks, neither in a week every client\'s output dropped: { signals: [ { axis, signal, bucket, by, value, count, baseline, items: [ { item_key, url, title } ] } ] }, signal spike, drop, silent or burst, by and value null for a drop or a silence, baseline the mean; and trend: [ { bucket, count } ], every story counted in each of the ' . Graph_Query::SIGNAL_TREND . ' latest complete buckets, the latest first, by week for an entity named with no lens, else by the lens\'s bucket over its filters, answered whether or not anything signals.',
						[ $lens, $text( 'entity', 'Only items linking this entity.' ) ],
						Graph_Query::signals( ... )
					),
					$command( $read, 'review', 'The review queue, each row with its citing items and the sentence each quotes as evidence: { links } or { entities }.', [ $arg( 'what', 'The queue: links or entities.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::review( ... ) ),
					$command( $read, 'theme_suggestions', 'Pairs of themes worth merging: of the readable stories of the last ' . Graph_Query::GROUP_DAYS . ' days, two themes whose stories share an entity they are about and whose names share a word that is not generic, the most alike names first, at most ' . Graph_Query::THEME_SUGGESTIONS . ': { suggestions: [ { themes: [ { theme, stories } ], words, shared } ] }, the theme with more stories first, words those both names hold and shared the entities both themes\' stories are about.', [], static fn (): array => Graph_Query::theme_suggestions() ),
					$command( $read, 'collections', 'Every collection with its item count, those the caller saved or moved an item into first, the latest first, then by name: { collections: [ { collection_key, name, created_ts, items, recent_ts } ] }; or one with its saved items, those not yet used first, then by save time, the latest first: { collection_key, name, created_ts, items: [ { item_key, url, title, actor, saved_ts, marks: [ { mark, day } ] } ] }.', [ $text( 'key', 'One collection\'s key.' ) ], Graph_Query::collections( ... ) ),
					$command( $read, 'lenses', 'Every lens, by name: { lenses: [ { lens_key, name, description, filters: { kinds, topics, roles, sources, entity, days, tiers, by, bucket } } ] }.', [], Graph_Query::lenses( ... ) ),
					$command( $read, 'watches', 'The caller\'s own watches, by name, each with its unseen matches: { watches: [ { watch_key, name, target: { kind, key }, digest, instant, inbox, unseen } ] }.', [], Graph_Watches::watches( ... ) ),
					$command(
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
					$command( $read, 'remove_watch', 'Remove one of the caller\'s own watches: { ok, event_id }.', [ $key( 'The watch key.' ) ], Graph_Watches::remove( ... ) ),
					$command( $read, 'watch_matches', 'The unseen matches of one of the caller\'s own watches, latest matched first: { items, total }, each item as search answers it plus matched_ts.', [ $key( 'The watch key.' ), $limit( Graph_Query::DEFAULT_LIMIT ), $page ], Graph_Watches::matches( ... ) ),
					$command( $read, 'mark_seen', 'Mark seen matches of one of the caller\'s own watches, or every unseen one when item is omitted: { ok, count }.', [ $key( 'The watch key.' ), [ 'name' => 'item', 'type' => 'string', 'variadic' => true, 'description' => 'A matched item key; repeat it to mark several.' ] ], Graph_Watches::mark_seen( ... ) ),
					$command(
						$read,
						'briefing',
						'What changed in the last ' . Intelligence_Briefing::DAYS . ' UTC days, one slice a call: changes { groups: [ { entity_key, name, role, items } ], total, summary }, the ' . Graph_Query::DEFAULT_LIMIT . ' most important items naming any entity as subject or mention, each as search answers it, in three kinds of group: under each open client it is about, role client; when it is about no open client but about an entity holding an open newspack_product role, in one group of that role; else in one group of role null, entity_key and name null for both; total counting every such item; summary { since, items, clients, kinds: [ { kind, items, clients } ] }, what of those items is dated at or after since: how many, the open clients they are about, and each of ' . \implode( ', ', Intelligence_Briefing::SUMMARY_KINDS ) . ' with any item, in that order; signals { clients: [ { entity_key, name, signals } ], lenses: [ { lens_key, name, signals } ] }, each client\'s signals on both axes and each lens\'s, as signals answers them, whose bucket closed in those days.',
						[
							$arg( 'what', 'The slice, one of: ' . \implode( ', ', Intelligence_Briefing::SLICES ) . '.' ),
							[ 'name' => 'since', 'type' => 'int', 'default' => 0, 'description' => 'For changes, the instant, UTC seconds, its summary counts from; the window\'s first instant at the latest. Refused for signals.' ],
						],
						Intelligence_Briefing::answer( ... )
					),
					$command( $read, 'ask', 'Ask a question the graph answers as a worker job, from at most ' . Ask_Node::MAX_ITEMS . ' items the caller may read, every sentence citing them: { ask_key }; read the answer with ask_result.', [ $arg( 'question', 'The question, at most ' . Graph_Asks::QUESTION_BYTES . ' bytes.' ) ], Graph_Asks::ask( ... ) ),
					$command( $read, 'ask_result', 'One of the caller\'s own asks: { ask_key, state, reason, found, dropped, sentences: [ { text, cites } ], items, searches, wait_s, available }. state is asked while it is owed its answer; answered; failed, with its reason, an ask still owed past wait_s, ' . Ask_Node::MAX_WAIT_S . ' seconds, among them, so poll it no longer than that; or withheld, with its reason, when the answer was written from an item of a tier the caller no longer reads, since every sentence may carry what that item said. found counts the items the answer was written from and dropped the sentences left out; each sentence cites items by their place in items from 1, each item as search answers it, and a sentence citing an item the caller cannot read now, as one expired since, is left out. searches are the searches the ask ran, each { words, lens, entity, kinds, roles, days } as its plan named them, and available is false when the graph would not open.', [ $key( 'The ask key.' ) ], Graph_Asks::result( ... ) ),
					$command( $read, 'health', 'Pipeline health, one slice a call: collection { state, topology }; sources { sources: [ { name, class, tier, state, reason, credential: { url, group }, last_ok_at, last_error, failing_since, failed, requests } ] }; backlog { reason, held, since, dropped: { first_day, last_day } }; extraction { mode, vault_group, last_ok_at, last_error, failing_since }. An unpublished fact reads as unknown or null.', [ $arg( 'what', 'The slice, one of: ' . \implode( ', ', Intelligence_Health::SLICES ) . '.' ) ], Intelligence_Health::answer( ... ) ),
					$command( $tune, 'confirm_link', 'Confirm a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_confirmed', $args ) ),
					$command( $tune, 'reject_link', 'Reject a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_rejected', $args ) ),
					$command( $tune, 'accept_entity', 'Accept an entity: { ok, event_id }.', [ $about ], static fn ( array $args ): array => Graph_Curation::judge_entity( 'entity_accepted', $args ) ),
					$command( $tune, 'reject_entity', 'Reject an entity: { ok, event_id }.', [ $about ], static fn ( array $args ): array => Graph_Curation::judge_entity( 'entity_rejected', $args ) ),
					$command( $tune, 'merge_entities', 'Merge one entity into another, refused when the merge would cycle: { ok, event_id }.', [ $arg( 'from', 'The entity merged away.' ), $arg( 'into', 'The entity it survives as.' ) ], Graph_Curation::merge( ... ) ),
					$command(
						$tune,
						'add_name',
						'Add a name to an entity: { ok, event_id }.',
						[ $about, $arg( 'kind', 'The name kind: ' . \implode( ', ', Intel_Event::NAME_KINDS ) . '.' ), $arg( 'value', 'The name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::add_name( ... )
					),
					$command(
						$tune,
						'add_identifier',
						'Add an identifier to an entity, refused when another entity holds it: { ok, event_id }.',
						[ $about, $arg( 'kind', 'The identifier kind: ' . \implode( ', ', Intel_Event::IDENTIFIER_KINDS ) . '.' ), $arg( 'value', 'The identifier, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::add_identifier( ... )
					),
					$command( $tune, 'assert_role', 'Open a role on an entity from now: { ok, event_id }.', $role, Graph_Curation::assert_role( ... ) ),
					$command( $tune, 'end_role', 'Close the role an entity holds open, now: { ok, event_id }.', $role, Graph_Curation::end_role( ... ) ),
					$command( $tune, 'tag_topic', 'Tag an entity with a topic: { ok, event_id }.', [ $about, $arg( 'topic', 'The topic\'s entity key.' ) ], Graph_Curation::tag_topic( ... ) ),
					$command(
						$tune,
						'create_entity',
						'Create an entity keyed by its type and name, refused when the key is taken: { ok, event_id, entity_key }.',
						[ $arg( 'type', 'The entity type: ' . \implode( ', ', Intel_Event::TYPES ) . '.' ), $arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ],
						Graph_Curation::create_entity( ... )
					),
					$command( $tune, 'create_collection', 'Create a collection keyed by its name, refused when the key is taken: { ok, event_id, collection_key }.', [ $arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ) ], Graph_Curation::create_collection( ... ) ),
					$command( $tune, 'collect', 'Save a readable item into a collection, its title cut at ' . Graph_Curation::TITLE_BYTES . ' bytes, refused when it is saved already: { ok, event_id }.', [ $arg( 'collection', 'The collection key.' ), $item ], Graph_Curation::collect( ... ) ),
					$command(
						$tune,
						'mark_used',
						'Mark an item saved in a collection used, on a day; marking it so again replaces the day: { ok, event_id }.',
						[ $arg( 'collection', 'The collection key.' ), $item, $arg( 'mark', 'Where it was used: ' . \implode( ', ', Intel_Event::MARKS ) . '.' ), $arg( 'day', 'The day it was used, Y-m-d.' ) ],
						Graph_Curation::mark_used( ... )
					),
					$command( $tune, 'move_item', 'Move an item saved in a collection into another: every copy of it the caller reads, each with its own tier and marks: { ok, event_id }.', [ $arg( 'collection', 'The collection it is in.' ), $item, $arg( 'into', 'The collection it moves into.' ) ], Graph_Curation::move_item( ... ) ),
					$command(
						$tune,
						'save_lens',
						'Save a lens, a named set of search and trends filters, replacing any under its key; refused past one ' . Partition_Node::MAX_LINE_SIZE . '-byte ontology line: { ok, event_id, lens_key }.',
						[
							$key( 'The lens key, slugged.' ),
							$arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ),
							[ 'name' => 'description', 'type' => 'string', 'default' => '', 'description' => 'What it is for.' ],
							$text( 'entity', 'An entity every item links.' ),
							[ 'name' => 'days', 'type' => 'int', 'description' => 'The last days it reads, today included, 1 to ' . Intel_Graph_Row_Schema::RETAIN_DAYS . '.' ],
							$text( 'by', "What its trends count, one of {$axes}." ),
							$text( 'bucket', 'The span its trend rows count: ' . \implode( ', ', Graph_Query::buckets() ) . '.' ),
							...$lists,
							$any( 'tier', 'A tier it reads, narrowing what its reader may read: ' . \implode( ', ', Intel_Event::TIERS ) . '.' ),
						],
						Graph_Curation::save_lens( ... )
					),
					$command( $tune, 'remove_lens', 'Remove a lens: { ok, event_id }.', [ $key( 'The lens key.' ) ], Graph_Curation::remove_lens( ... ) ),
					$command( $tune, 'merge_theme', 'Merge one theme into another, so every read of an item\'s theme reads the survivor; refused when the merge would cycle, when into itself merges away, or when it would show a theme only restricted items carry to every reader: { ok, event_id }.', [ $arg( 'from', 'The theme merged away, at most ' . Intel_Event::THEME_BYTES . ' bytes.' ), $arg( 'into', 'The theme it survives as.' ) ], Graph_Curation::merge_theme( ... ) ),
					$command( $tune, 'seed_ontology', 'Write the site\'s seed ontology for the keys the projection lacks, refused while the projector has not read every ontology change: { ok, events, created, present }.', [], static fn (): array => [ 'ok' => true ] + Ontology_Seed::load()->seed() ),
					[
						'name'        => 'fetch_now',
						'capability'  => Capabilities::MANAGE,
						'description' => 'TICK every source in each live worker: { ok, workers }.',
						'args'        => [],
						'handler'     => static fn ( self $ci ): array => [ 'ok' => true, 'workers' => Worker_Route::send( $ci, Config::get_base_directory(), self::ticks() ) ],
					],
					[
						'name'        => 'reresolve',
						'capability'  => Capabilities::MANAGE,
						'description' => 'Ask each live worker to re-resolve every stored item dated in a range of UTC days: { ok, workers }.',
						'args'        => [ $arg( 'since', 'The first UTC day, Y-m-d.' ), $arg( 'until', 'The last UTC day, Y-m-d.' ) ],
						'handler'     => static fn ( self $ci, array $args ): array => [ 'ok' => true, 'workers' => Reresolver_Node::ask( $ci, 'RERESOLVE', Core::as_string( $args['since'] ), Core::as_string( $args['until'] ), '' ) ],
					],
				],
			]
		);
	}
}

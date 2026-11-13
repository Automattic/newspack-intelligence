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
	 * `Graph_Asks`; `trace`, at read, over `Graph_Trace`; the curation commands, each at tune over
	 * `Graph_Curation` and seeing only the entities and items a read caller
	 * sees, `excluded` beside them, the one read listing the items a curator
	 * excluded; `seed_ontology`, at tune, over
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
		$blank = static fn ( string $name, string $what ): array => [ 'name' => $name, 'type' => 'string', 'default' => '', 'description' => $what ];
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
							$any( 'tier', 'A tier the item is recorded at, ' . \implode( ', ', Intel_Event::TIERS ) . ', read only within the caller\'s own and a lens\'s.' ),
						],
						Graph_Query::search( ... )
					),
					$command( $read, 'entity', 'One entity as its merge survivor: { entity_key, type, name, state, merged_from, names, identifiers, roles, topics, counts: { items, proposed_links }, published: { count }, status }, name the one it was created with, or, for one created under its own domain, its newest canonical name, counts.items the items about it, published.count those its own site published, and status, for a client alone, { client_since, kinds: { ' . \implode( ', ', Graph_Query::STATUS_KINDS ) . ' }, last_item_ts, churn_open, churn_items: [ { item_key, title, url, source, day } ], churn_note: { text, owner, next_step, day, by } } over the items naming it as a subject or a mention, each kind counting the items whose kind claims it, a churn kind\'s the client its kind subject names, last_item_ts the newest naming it as their subject, churn_items the ' . Graph_Query::CITATIONS . ' newest of them dated after client_since that are extracted churned and claim it, the newest first, churn_open whether there is one, and churn_note the client\'s churn-risk note, by the actor who set it, null for none; else null.', [ $about ], Graph_Query::entity( ... ) ),
					$command( $read, 'names', 'At most ' . Graph_Query::NAMES_LIMIT . ' entities holding a name or alias whose first word, or a later one, starts with prefix, as the resolver\'s index reads names, case and punctuation aside, each once as its merge survivor: { entities: [ { entity_key, name } ], available }, those whose whole name the prefix leads first, then by name; a rejected entity, a topic and an entity the caller may not see left out, and available false when the graph would not open.', [ $arg( 'prefix', 'What the name starts with, at least ' . Graph_Query::NAMES_MIN_PREFIX . ' letters or digits.' ) ], Graph_Query::names( ... ) ),
					$command( $read, 'timeline', 'The items about an entity, or with published those its own site published, newest first: { items, total }, each item as search answers it.', [ $about, $since, $until, $limit( Graph_Query::DEFAULT_LIMIT ), $page, $box( 'published', false, 'Page the items its own site published instead of those about it.' ) ], Graph_Query::timeline( ... ) ),
					$command( $read, 'related', 'The entities sharing the most items with one: { entities: [ { entity_key, name, type, shared_items, items: [ { item_key, url, title } ] } ] }, five newest shared items cited an entity.', [ $about, $limit( Graph_Query::RELATED_LIMIT ) ], Graph_Query::related( ... ) ),
					$command( $read, 'claims', 'The claims naming an entity, newest first, object_name null where the object is as the item spelled it: { claims: [ { subject, subject_name, predicate, object, object_name, confidence, item_key, url, title } ] }.', [ $about, $text( 'predicate', 'Only claims of this predicate.' ), $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::claims( ... ) ),
					$command(
						$read,
						'trends',
						"Items counted by {$axes} per bucket, the newest bucket first, a story counted once as its canonical: { rows: [ { bucket, value, count, items: [ { item_key, url, title } ] } ], by, bucket }, by and bucket the axis and the span counted, five newest items cited a row; counting by theme, each row also names clients: [ { entity_key, name } ], the open clients its items name as subject, before its items, and an item with no theme counts under none.",
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
						'The latest complete bucket\'s signals, a story counted once as its canonical on both axes. On the about axis, spikes counted as trends counts, for an entity named with no lens, only the items naming it as their subject, but other never signals on the about axis: each value whose count reaches ' . Graph_Query::SIGNAL_MIN . ', passes the mean and reaches the mean plus ' . Graph_Query::SIGNAL_SIGMAS . ' standard deviations of the ' . Graph_Query::SIGNAL_BASELINE . ' buckets before it, and only a value counted before those buckets, since a first appearance is not a change. For an entity named with no lens, also the publisher axis, what its own site published per week: a burst of one kind by the spike rule, a kind it never put out before included; and for a client, a drop to ' . Graph_Query::SIGNAL_DROP . ' of a baseline mean of at least ' . Graph_Query::SIGNAL_MIN . ', or silent on the first two empty weeks, neither in a week every client\'s output dropped: { signals: [ { axis, signal, bucket, by, value, count, usual: { mean, low, high }, trend: [ { bucket, count, in_window } ], items: [ { item_key, url, title } ] } ] }, signal spike, drop, silent or burst, by and value null for a drop or a silence, usual the range of its own series\' ' . Graph_Query::SIGNAL_BASELINE . ' buckets before the latest, mean their mean, low the mean less ' . Graph_Query::SIGNAL_SIGMAS . ' standard deviations and at least 0, high the mean plus them and at least ' . Graph_Query::SIGNAL_MIN . ', the range its rule reads: a spike or a burst counts at or above high, and for a drop or a silence low is ' . Graph_Query::SIGNAL_DROP . ' of the mean, which its count is at or below, and trend its own series over the buckets the answer\'s trend spans; trend: [ { bucket, count, in_window } ], every story counted in each of the ' . Graph_Query::SIGNAL_TREND . ' latest complete buckets, the latest first, by week for an entity named with no lens, else by the lens\'s bucket over its filters, answered whether or not anything signals, carrying no range because no rule judges a total, in_window false for a bucket holding none of the days read, so its 0 is unread rather than quiet; partial: { bucket, count }, the bucket holding now, apart from the trend; judged, whether any axis the answer carries signals for held the history its rule judges by, so a non-empty signals list is always judged and an empty one with judged false is too new to judge rather than unchanged; bucket, the span counted, day, week or month; and baseline_buckets, ' . Graph_Query::SIGNAL_BASELINE . ', how many buckets before the latest a change is measured against.',
						[ $lens, $text( 'entity', 'Only items linking this entity.' ) ],
						Graph_Query::signals( ... )
					),
					$command( $read, 'review', 'The review queue, one page at a time, each row with the sentence it quotes as evidence, and beside the page next, the cursor after its last row, which after reads back, null on the queue\'s last page. For links, each row is one item and one entity it proposes, the link that is not a topic tag before the one that is, and limit and total count items, not links: a page answers at most ' . Graph_Query::REVIEW_LINKS . ' of its proposed entities for each of its items, the first by entity key, each row\'s item_links counting every entity the item proposes, and total counts the items proposing one: { links, total, next }. For entities, each row is one proposed entity with its citing items, and limit and total count entities: { entities, total, next }. total counts the whole queue, whatever page after reads.', [ $arg( 'what', 'The queue: links or entities.' ), $limit( Graph_Query::DEFAULT_LIMIT ), $text( 'after', 'The next answer a page gave, an opaque cursor naming its last item for links, its last entity for entities; the page holds those after it in the queue\'s order, so judging one page never moves the next.' ) ], Graph_Query::review( ... ) ),
					$command( $read, 'theme_suggestions', 'Pairs of themes worth merging: of the readable stories of the last ' . Graph_Query::GROUP_DAYS . ' days, two themes whose stories share an entity they are about and whose names share a word that is not generic, the most alike names first, at most ' . Graph_Query::THEME_SUGGESTIONS . ': { suggestions: [ { themes: [ { theme, stories } ], words, shared } ], total }, the theme with more stories first, words those both names hold, shared the entities both themes\' stories are about, and total every pair found.', [], static fn (): array => Graph_Query::theme_suggestions() ),
					$command( $read, 'collections', 'Every collection with its item count, those the caller saved or moved an item into first, the latest first, then by name: { collections: [ { collection_key, name, created_ts, items, recent_ts } ] }; or one with its saved items, those not yet used first, then by save time, the latest first: { collection_key, name, created_ts, items: [ { item_key, url, title, actor, saved_ts, marks: [ { mark, day } ] } ] }.', [ $text( 'key', 'One collection\'s key.' ) ], Graph_Query::collections( ... ) ),
					$command( $read, 'lenses', 'Every lens, by name: { lenses: [ { lens_key, name, description, filters: { kinds, topics, roles, sources, entity, days, tiers, by, bucket }, week_items } ] }, week_items how many items the lens admits from the last ' . Graph_Query::NEW_DAYS . ' UTC days, today the last, whatever days it looks back over.', [], Graph_Query::lenses( ... ) ),
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
						'What changed in the last ' . Intelligence_Briefing::DAYS . ' UTC days, one slice a call: changes { order, items, groups: [ { role, key, name, items } ], categories: [ { lens_key, name, count } ], otherwise, total, summary }, the ' . Graph_Query::DEFAULT_LIMIT . ' most important items naming any entity as subject or mention, in order (importance), each as search answers it with categories, the lenses named that admit it, and otherwise, whether none does; each filed once by key: under the first open client it is about, a churn kind\'s the client its kind subject names, role client, key and name the client\'s; else under the first lens named admitting it, role category, key and name the lens\'s; else role otherwise, key and name null; categories counting the items of the window each lens admits, otherwise those none admits, as total counts every such item; summary { since, items, clients, kinds: [ { kind, items, clients } ] }, what of those items is dated at or after since: how many, the open clients they are about, and each of ' . \implode( ', ', Intelligence_Briefing::SUMMARY_KINDS ) . ' with any item, in that order; signals { clients: [ { entity_key, name, signals, churn_note } ], lenses: [ { lens_key, name, signals } ], judged }, each client the caller may see with its signals on both axes and each lens\'s, as signals answers them, whose bucket closed in those days, and judged, whether any client or lens held the history its rules judge by.',
						[
							$arg( 'what', 'The slice, one of: ' . \implode( ', ', Intelligence_Briefing::SLICES ) . '.' ),
							[ 'name' => 'since', 'type' => 'int', 'default' => 0, 'description' => 'For changes, the instant, UTC seconds, its summary counts from; the window\'s first instant at the latest. Refused for signals.' ],
							[ 'name' => 'lenses', 'type' => 'string', 'default' => '', 'description' => 'For changes, the category lenses it files the week under, keys joined by commas, at most ' . Intelligence_Briefing::CATEGORY_MAX . ', each saved. Refused for signals.' ],
						],
						Intelligence_Briefing::answer( ... )
					),
					$command( $read, 'ask', 'Ask a question the graph answers as a worker job, from at most ' . Ask_Node::MAX_ITEMS . ' items the caller may read, every sentence citing them: { ask_key }; read the answer with ask_result.', [ $arg( 'question', 'The question, at most ' . Graph_Asks::QUESTION_BYTES . ' bytes.' ) ], Graph_Asks::ask( ... ) ),
					$command( $read, 'ask_result', 'One of the caller\'s own asks: { ask_key, state, reason, found, dropped, hidden, sentences: [ { text, cites } ], items, searches, wait_s, available }. state is asked while it is owed its plan; planned once its plan is written, with the searches it names; read once those searches are read, with found the items they found; answered; failed, with its reason, an ask still owed past wait_s, ' . Ask_Node::MAX_WAIT_S . ' seconds, among them, so poll it no longer than that; or withheld, with its reason, when the answer was written from an item of a tier the caller no longer reads, since every sentence may carry what that item said. found counts the items the answer was written from and dropped the sentences left out because they cited nothing it was given; each sentence cites items by their place in items from 1, each item as search answers it, and a sentence citing an item the caller cannot read now, as one expired or excluded since, is left out and counted in hidden. searches are the searches the ask ran, each { words, lens, entity, kinds, roles, days } as its plan named them, and available is false when the graph would not open.', [ $key( 'The ask key.' ) ], Graph_Asks::result( ... ) ),
					$command( $read, 'trace', 'How one readable item was read, its own record in the order the pipeline wrote it: { item_key, title, url, steps }, each step { step, at, actor, … }: recorded { source, tier }; each linked { entity_key, name, role, method, confidence, state, judged: { state, actor, at } }, by when it was written, judged null unless a judgment set its state, an entity the caller may not see left out; extracted, gated or heuristic { kind, theme, rank_reason }, gated when the item was about its own publisher\'s journalism and so not sent to the model, heuristic when the model gave no answer; checked { question, kind, verdict }; clustered { canonical, copies }, copies the cluster\'s other items the caller may read. A step the item never took is left out, and an item of a tier the caller does not read, or one excluded, is refused as no item: <key>.', [ $item ], Graph_Trace::trace( ... ) ),
					$command( $read, 'copies', 'The other items of one readable item\'s cluster the caller may read, the copies a story merged: { item_key, copies }, each copy { item_key, url, title, source, day }, day its UTC Y-m-d, the newest first; none for an item in no cluster. A copy of a tier the caller does not read, or one excluded, is never listed, and an item the caller cannot read is refused as no item: <key>.', [ $item ], Graph_Trace::copies( ... ) ),
					$command( $read, 'health','Pipeline health, one slice a call: collection { state, topology }; sources { sources: [ { name, class, tier, state, reason, credential: { url, group }, last_ok_at, last_error, failing_since, failed, requests } ] }; backlog { reason, held, since, dropped: { first_day, last_day } }; extraction { mode, vault_group, last_ok_at, last_error, failing_since }. An unpublished fact reads as unknown or null.', [ $arg( 'what', 'The slice, one of: ' . \implode( ', ', Intelligence_Health::SLICES ) . '.' ) ], Intelligence_Health::answer( ... ) ),
					$command( $tune, 'confirm_link', 'Confirm a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_confirmed', $args ) ),
					$command( $tune, 'reject_link', 'Reject a readable item\'s link to an entity: { ok, event_id }.', $link, static fn ( array $args ): array => Graph_Curation::judge_link( 'link_rejected', $args ) ),
					$command(
						$tune,
						'add_link',
						'Link a readable item to an entity as your confirmed link, whether or not a machine linked them; it reads as linked once the resolver next resolves the item: { ok, event_id }.',
						[ ...$link, [ 'name' => 'role', 'type' => 'string', 'default' => 'subject', 'description' => 'The role: ' . \implode( ', ', Intel_Event::NAMING_ROLES ) . '.' ] ],
						Graph_Curation::add_link( ... )
					),
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
					$command(
						$tune,
						'create_collection',
						'Create a collection keyed by its name, refused when the key is taken, and save a readable item into it as collect does, the folder and the save written together or neither: { ok, event_id, collection_key }.',
						[ $arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ), $text( 'item', 'An item key to save into it.' ) ],
						Graph_Curation::create_collection( ... )
					),
					$command( $tune, 'collect', 'Save a readable item into a collection, its title cut at ' . Graph_Curation::TITLE_BYTES . ' bytes, refused when it is saved already: { ok, event_id }.', [ $arg( 'collection', 'The collection key.' ), $item ], Graph_Curation::collect( ... ) ),
					$command(
						$tune,
						'mark_used',
						'Mark an item saved in a collection used, on a day; marking it so again replaces the day: { ok, event_id }.',
						[ $arg( 'collection', 'The collection key.' ), $item, $arg( 'mark', 'Where it was used: ' . \implode( ', ', Intel_Event::MARKS ) . '.' ), $arg( 'day', 'The day it was used, Y-m-d.' ) ],
						Graph_Curation::mark_used( ... )
					),
					$command( $tune, 'move_item', 'Move an item saved in a collection into another: every copy of it the caller reads, each with its own tier and marks: { ok, event_id }.', [ $arg( 'collection', 'The collection it is in.' ), $item, $arg( 'into', 'The collection it moves into.' ) ], Graph_Curation::move_item( ... ) ),
					$command( $tune, 'uncollect', 'Take an item saved in a collection out of it: every copy of it there the caller reads, leaving it in every other collection: { ok, event_id }.', [ $arg( 'collection', 'The collection it is in.' ), $item ], Graph_Curation::uncollect( ... ) ),
					$command(
						$tune,
						'save_lens',
						'Save a lens, a named set of search and trends filters, replacing any under its key; refused past one ' . Partition_Node::MAX_LINE_SIZE . '-byte ontology line: { ok, event_id, lens_key }.',
						[
							$key( 'The lens key, slugged.' ),
							$arg( 'name', 'Its name, at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ),
							$blank( 'description', 'What it is for.' ),
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
					$command( $tune, 'keep_themes_apart', 'Keep two themes apart, so the theme suggestions never pair them again, nor the themes they merge into; refused for a theme kept apart from itself or one the caller cannot see: { ok, event_id }.', [ $arg( 'theme', 'One theme, at most ' . Intel_Event::THEME_BYTES . ' bytes.' ), $arg( 'other', 'The theme it is kept apart from.' ) ], Graph_Curation::keep_themes_apart( ... ) ),
					$command( $tune, 'rejoin_themes', 'Rejoin two themes kept apart, so the theme suggestions may pair them again; refused for a pair that is not kept apart, a theme rejoined with itself or one the caller cannot see: { ok, event_id }.', [ $arg( 'theme', 'One theme, at most ' . Intel_Event::THEME_BYTES . ' bytes.' ), $arg( 'other', 'The theme it was kept apart from.' ) ], Graph_Curation::rejoin_themes( ... ) ),
					$command( $tune, 'exclude_item', 'Exclude a readable item from every view — search, the briefing, signals, trends, watches and their matches, collections, an entity, an ask and this door — keeping its URL, title, tier, who excluded it, when and why; refused when it is excluded already: { ok, event_id }.', [ $item, $text( 'why', 'Why it is excluded, at most ' . Intel_Event::WHY_BYTES . ' bytes.' ) ], Graph_Curation::exclude_item( ... ) ),
					$command( $tune, 'include_item', 'Include an excluded item again, so every view reads it: { ok, event_id }.', [ $item ], Graph_Curation::include_item( ... ) ),
					$command(
						$tune,
						'note_churn',
						'Set a client\'s churn-risk note, with who owns it and the next step, replacing the note it carries; a note with no text clears it. Its profile\'s status and the briefing\'s signals answer it: { ok, event_id }.',
						[
							$arg( 'entity', 'The client\'s entity key.' ),
							$blank( 'text', 'What puts the client at risk, at most ' . Graph_Curation::NOTE_BYTES . ' bytes; none clears the note.' ),
							$blank( 'owner', 'Who owns the next step, a name of at most ' . Graph_Curation::NAME_BYTES . ' bytes.' ),
							$blank( 'next_step', 'What happens next, at most ' . Graph_Curation::NOTE_BYTES . ' bytes.' ),
							$blank( 'day', 'The day the next step is due, Y-m-d.' ),
						],
						Graph_Curation::note_churn( ... )
					),
					$command( $tune, 'excluded', 'The excluded items of the tiers the caller reads, the latest excluded first, the one read that lists them: { items: [ { item_key, title, url, actor, excluded_ts, why } ], available }, each as it was when excluded and why \'\' unless the curator gave one.', [ $limit( Graph_Query::DEFAULT_LIMIT ) ], Graph_Query::excluded( ... ) ),
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
						'handler'     => static fn ( self $ci, array $args ): array => [ 'ok' => true, 'workers' => Reresolver_Node::ask( $ci, 'RERESOLVE', Core::as_string( $args['since'] ), Core::as_string( $args['until'] ), '', [] ) ],
					],
				],
			]
		);
	}
}

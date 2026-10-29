# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **The restricted tier has holders.** A user granted the capability `newspack_intelligence_restricted` (`Intel_Tiers::CAPABILITY`), a WordPress user capability rather than a role, reads restricted items beside public and internal ones: `Intel_Tiers::readable()` asks `current_user_can()` on each call and caches nothing, so every read, every curation verb's item check, `collect` and `collections` answer restricted rows to a holder alone, and a non-holder still gets `no item: <key>` for one. The capability adds a tier and no verb: each verb's own role gate runs first. An MCP session reads the tier while the user who minted it holds the capability, since the door becomes that user for each call, so a revoke takes effect on the session's next call; the session's scope neither widens nor narrows it. A role carrying the capability also reads the tier, and on a multisite a super admin passes every `current_user_can()` check and so reads it on every site, both without a grant.
- **`wp newspack-intelligence restricted grant <login>`, `revoke <login>` and `list`** grant, revoke and list the capability on the current site. A holder is a user's own grant on the site, so `list` names every user `revoke` takes and no role, then says that a role and a super admin read the tier without a grant. A grant or a revoke names the user it changed by the user's own login, looked up as WordPress does; an unknown login, on a multisite a user who is not a member of the current site, a grant to a user already granted it and a revoke from a user holding no grant are each refused by name and change nothing, so a grant never makes anyone a member of a site. Every verb refuses to run as root.
- **A "Restricted access" section on Settings → Newspack Intelligence** lists each user's own grant with a revoke for it, grants by login, and says who reads the tier without a grant. Each is an admin-post, `newspack_intelligence_grant_restricted` or `newspack_intelligence_revoke_restricted`, refused to a caller below MANAGE or whose nonce for its own action fails; a refused change dies naming why.
- **Each grant and revoke writes one stderr line, which reaches the PHP error log**, naming the operator and the user by its own login: `restricted access granted to <login> by <operator>` or `restricted access revoked from <login> by <operator>`. The operator is `user:<login>` for the Settings page and for `wp … --user=<login>`, else `wp-cli uid <uid>`.
- **Uninstall removes every user's own grant of the capability**, on every site of a multisite, whether or not the substrate is active.
- **Curation cannot declassify.** `accept_entity` refuses an entity no item outside the restricted tier cites, by a link or as the item it was proposed from, with `<key> is cited by no item outside the restricted tier; accepting it would show it to every reader`, because an accepted entity shows to every reader. `merge_entities` refuses a merge joining such an entity with one every reader sees, in either direction, with `merging <from> into <into> would show <key> to every reader; no item outside the restricted tier cites it`: merged away, its names and key show on the survivor; surviving, it gains the other's citations. Two such entities still merge. `tag_topic` refuses such a topic on an entity every reader sees, with `tagging <entity> with <topic> would show <topic> to every reader; no item outside the restricted tier cites it`, and an entity's record lists a topic it is tagged with only when the caller may see that topic, so a tag written earlier shows a restricted-only topic to a holder alone. A claim answers its object's key only to a caller who may see that entity, and anyone else the object as the item spelled it, which the extractor now keeps in `object_text` on every claim, keyed or not; a claim written before that, whose object carries a key and no spelling, is left out for a caller who cannot see the object. A claim's subject is linked on the claim's own readable item, so it shows to every caller who sees the claim. All three refuse before writing, and all three decide through `Graph_Query::visible()` over the read's own graph narrowed to the unrestricted tiers, so an accepted or created entity is unaffected. A holder still rejects such an entity, or creates a public one over its key on purpose with `create_entity`.

### Changed
- **The Settings page's three admin-posts share one gate, one form head and one return.** The client-CSV upload and the two restricted-access handlers open on `admit_settings_post()`, build their forms on `open_settings_form()` and return through `return_to_settings()`, all three in the plugin file beside the Settings page. Each still refuses a caller below MANAGE or whose nonce for its own action fails with `Not allowed.`, and the upload still returns with `clients_imported=1`.

## [0.22.0] - 2026-10-29

### Changed
- **The Linear source fetches only the teams its topology names.** It asked for the workspace's 30 most recently updated issues with no team filter, and the token sees the whole Automattic workspace, so it recorded other teams' issues and none of Newspack's. `linear:config` takes `add_team <KEY>` and `remove_team <KEY>`, declared as `github:config` declares `add_repo` and `remove_repo`, which round-trip through `dump_config()`; an identical repeat is a no-op, and `remove_team` refuses a key never added. The request filters `issues` by `team.key` through a `$teams` GraphQL variable, keeping the page of 30, and with no team registered the source makes no call. The collect topology names Newspack's teams: NEWS, NPAI, NPPRO, NPPM, NPMIG, NPLAUNC, NPSUPP, NPPD and DSGNEWS.
- **An item recorded again is re-resolved, not resolved afresh.** A fresh worker forgets which items its sources recorded and records their recent items again; datapoke's `intel:items` holds 435 `item_recorded` events for 217 items. Each repeat ran the NER step, an LLM call, then the extractor, a second call that replaced the stored extraction, and the resolution it sent ignored the curators' verdicts, so a rejected link reached the extractor and a confirmed one was not sent asserted. When the projection already holds the item, the resolver now runs what an `item_reresolve` naming it runs: the verdicts in force, and the item forwarded for extraction only when no call extraction is projected or it asserts a link the last extraction was not given. It skips the NER step only for an item resolved before, one the projection holds an extraction of from any actor or a non-topic link of, because the projector writes the `items` row within 5 seconds whether or not anything resolved it. So a first recording the projector flushed before the resolver reached it, and an item never linked because its NER step named nobody or failed, still run NER and are forwarded with `extracted: false`. A first recording the projection does not hold yet takes the same path from the event's item, NER included, with the verdicts the projection already holds of it, since a replay can project the ontology stream ahead of the items stream; an item read while the projection will not open takes it with no verdicts and retracts nothing, logged. A crash in the 30 seconds between the projector flushing an item's extraction and `intel:items:resolve` committing its cursor replays the item as a settled repeat, so the deduper does not place it in a cluster; that is the accepted cost.

## [0.21.0] - 2026-10-29

### Added
- **`intel:later` parks records to reprocess later.** The streams topology declares a substrate Delay, `intel:later`, which keeps each record a day at most, sweeps every minute, and sends each due record to `intel:reresolve` as an `item_reresolve`, so the resolver reads it one a poll like any other re-resolution. `make_node Resolver` and `make_node Extractor` each take it as a second argument, declared as a destination beside the writer.
- **A failed extraction call is retried with backoff.** When the extractor's call throws a `RuntimeException` or answers a reply that does not parse, it still writes the heuristic stand-in, or keeps the earlier extraction, and also parks the item on `intel:later` for a re-resolution one attempt on, due 300 seconds doubled per attempt and capped at an hour, for at most 5 attempts. The re-resolution forwards the item for extraction only while it is still owed one. A re-resolution whose NER step did not run and answer now sends the extractor the item's stored NER links as they were stored in any partition, unless a curator rejected one, so a retry reaches an item only NER linked, and costs no NER call; the link events it writes are still only those it found. With no client configured nothing is retried.

### Changed
- **The resolver holds items while its entity index is empty.** An index holding no accepted or proposed entity resolved every item against nothing: after the 0.20.x upgrade discarded the projection, a respawn re-recorded recent items, and 43 good extractions were replaced by ones given the wrong subjects. The resolver now resolves nothing then: a recorded item parks on `intel:later` as a re-resolution owed its NER step, and a re-resolution parks again unchanged, each a minute on, under a line naming `wp newspack-intelligence ontology seed`, or `ontology reproject` after an upgrade. It also holds while the projector's reader of the ontology stream had not committed the stream's end just before the entity index was read: each load of the index takes that reading first, so a flush landing between the two reads cannot pass a stale index, and a curation the projector has not flushed yet holds an item a minute at most. The resolver's own `entity_proposed` events leave that cursor short until the projector's next checkpoint, up to 30 seconds, so items following a proposal may park a minute; nothing is lost and no LLM call is spent. A record parked again keeps its first `parked_at`, so a day bounds the wait, and every record parks keyed by its item, so the Delay's age-out line names it; `wp newspack-intelligence ontology reresolve --since=<day>` then re-resolves it, without its NER step, after `ontology seed` on a fresh install, as the hold line says. A replay owed NER runs it, and parks again while the projection does not hold the item yet.
- **A resolution retracts the links it no longer finds.** A new items-stream kind, `link_retracted` (`item_key`, `entity_key`, `role`, `retrieved_ts`), deletes the one `links` row it keys. The resolver writes one for each stored non-topic link of the item, by raw entity key and role, that a resolution does not write again, so a link whose role changed goes from its old role. It reads only this worker's own partition's file, the one its projector deletes in. It keeps a link whose entity a curator confirmed, and a NER link unless the NER step ran and answered: `Resolver::resolve()` now reports whether it did, and the NER extractor answers none, logged, for a failed call or a reply whose `orgs` is not a list, so a failure, or a NER step skipped because an earlier step found a link, is told from an answer naming nobody.
- **The substrate floor is 2.137.0,** the first release carrying the Delay node `intel:later` is built from.

### Fixed
- **A source refuses an item whose key runs past 512 bytes** as the stream writes it, JSON-escaped (`Graph_Curation::ITEM_KEY_BYTES`), with a rate-limited line naming the source, the key's length and the key cut to 64 bytes, terminal-safe, so no parked or re-resolution record naming it outgrows its 4,096-byte line. A process refuses such a key once.

## [0.20.1] - 2026-10-29

### Fixed
- **The view tabs follow the WAI-ARIA tabs pattern.** The page renders the substrate's shared `TabBar`, so each tab names the panel it controls, the panel is labelled by the selected tab, and the arrow keys, Home and End move and select. A release builds against substrate 2.135.0 or later, the first to carry `TabBar`.
- **Next leaves a page that shows no rows.** The pager offered Next when a page answered no rows while more matched, and Next asked for the same page again.

## [0.20.0] - 2026-10-28

### Added
- **`wp newspack-intelligence ontology reproject` rebuilds the graph from the event streams.** It holds the fleet with `wp nodes stop`, deletes the `intel-graph` Row_Table's files and the cursor of every Consumer the projector checkpoints with, read from each active topology's TSL, then releases the hold with `wp nodes start`, which runs even when a delete fails. A fleet that does not stop has nothing deleted under it, and its hold is released. The projector then replays both streams from their oldest segment. The streams and every other reader's cursor stay, so a rebuild signals nothing and re-resolves nothing. **Run it after upgrading to this release:** the graph schema's version rises to 2, so the substrate discards the old projection when it opens it, and the graph stays empty until it runs. Run it right after deploying. The reresolver's new reader (below) replays the ontology once on its first boot, and its sweeps may re-resolve items while the graph rebuilds; that spends no call beyond the one-time re-extraction below, because each new extraction lands after the item's old one in the stream and settles it. Then run `wp newspack-intelligence ontology reresolve --since=<day>` to reach the items no signal names.

### Changed
- **The reresolver reads the ontology through its own Consumer.** `intel:ontology:reresolve` feeds it and carries its checkpoint, and `intel:ontology:consumer` feeds the projector alone, so `intel:ontology:tee` is gone. Resetting the projector's cursors therefore leaves the reresolver where it was. On its first boot the new reader has no checkpoint, so it replays the ontology stream into the reresolver once.
- **A link is settled when the item's last extraction was given it.** A call's extraction now also writes `subjects_extracted`, naming the entities it was given as asserted, which the projector keeps per item in `extracted_subjects`, replacing the earlier set. A re-resolution forwards an item a call has extracted when, with the curators' verdicts in force, it asserts a link whose entity, compared by survivor, that extraction was not given; a link a curator rejected is not sent, so it never counts. So an item whose links were asserted while an older release ran, which never extracted it again, now is; a ✓ the projection had not yet recorded when the item was extracted is still owed its claims; and a merge settles through the survivor, costing no call. A curator's ✓ needs no flag of its own any more, so `item_reresolve` records no longer carry `judged`, and the reresolver queues a confirmed link's item like any other. Older releases recorded no such set, so after the reproject every item a call extracted reads as given nothing, and the next signal that reaches it, or a `reresolve`, extracts it once more: one LLM call per item, once.

### Fixed
- **One click on Review's ✓ or ✕ takes.** A judgment is appended to the ontology log at once, but the projector writes it into the queue the page re-reads only on its next five-second flush, so the re-read after a click still listed the proposal and its ✓ looked dead; each further click recorded the same judgment again. A judgment now takes its link or entity out of the queue the moment it is sent, and the row stays out until a read no longer lists it. A refused judgment puts its row back beside the refusal, matched by the arguments the refusal echoes, and a refusal naming no send, such as a Router's NOT_AVAILABLE, puts back every judged row of that queue. A queue no longer locks its other rows while one judgment waits, so the next proposal can be judged at once.
- **A failed re-extraction is tried again.** An extractor whose call fails keeps the item's earlier extraction, which was not given the link that sent it, so the item's next re-resolution forwards it again; before, the link already counted as settled and the item was never retried.
- **A heuristic stand-in never replaces a call's extraction.** The extractor records the stand-in when no call answers and the projection shows no call's extraction yet, which a lagging projection could show wrongly; the stand-in then deleted the call's claims and topics. The projector now writes a stand-in only into an item no extraction holds, with an `ignore`, so the stream's order decides and a call's extraction, claims and topics stand.

## [0.19.0] - 2026-10-27

### Changed
- **The Review queues are one compact control per proposal.** A proposed link was a row per item and entity, the item's title repeated for each entity, a `mention · name · 1` line beneath it and a Confirm slab beside a red Reject slab; a proposed entity carried an Accept slab, a red Reject slab and an open Merge field. A link row is now one item, its title once, holding a pill per entity proposed for it: the entity's name, which opens it, joined to a ✓ that confirms and a ✕ that rejects. The pill's tooltip says in words what the entity is to the item, how it was found and how sure the finding was ("Mentioned, found by a similar name (61%)"). The words for each role and method come from the page's localized vocabulary, keyed by `Intel_Event::LINK_ROLES` and `Intel_Event::METHODS`. A proposed entity is the same pill, accepting or rejecting it, beside its type and key and a Merge… button that opens the merge field. Each glyph is named for screen readers ("Confirm Acme Gazette", "Reject Valley Times"), and every pill takes the accent of the shared secondary button rather than the danger red.

- **An item is extracted again once an entity it names is accepted.** Extraction asserts a claim only about a subject the item links by an asserted link, so an item extracted while its entity was still a proposal kept no claim about it, and a re-resolution after the entity was accepted or seeded wrote asserted links but never extracted the item again. A re-resolution now forwards an item already extracted when it asserts a link to an entity whose link to the item is not settled — neither asserted already, compared by the entity's survivor so a merge costs nothing, nor rejected by a curator — so the extractor runs once more and writes that subject's claims. An item whose settled links are unchanged is still never extracted twice, so the LLM cost of a signal stays bounded by the items whose asserted entities grew. A curator's ✓ on a proposed link does the same: the confirmation sends its item back through the resolver, which extracts it again with the confirmed link asserted, so the extractor keeps that subject's claims, and a link a curator rejected is left out of what the extractor is sent. Each `item_reresolve` record now names whether a confirmation sent it (`judged`), and the resolver drops one that does not. An extraction now replaces the item's claims and topic links outright, through newspack-nodes 2.134.0's `delete` row op, so a re-extraction yielding fewer leaves none of the earlier behind; the substrate floor rises to 2.134.0 for it. A re-extraction whose call fails keeps the earlier extraction rather than replacing it with the heuristic stand-in, and a link a curator has judged counts as settled, so accepting its entity afterwards spends no second call.

### Fixed
- **The curation controls show for a curator.** `wp_localize_script()` sends a top-level `true` as the string `'1'`, and the page tested for `true`, so no one ever saw Review's judgments, Collections' create form, the Entity editors or Save to collection. The page now sends the flag as `'1'` or `''` and reads `'1'`, and the PHP suite's `wp_localize_script()` double casts as WordPress does, so the test holds the value the browser receives. newspack-nodes now refuses a boolean extra outright, so a substrate carrying that refusal needs this release beside it.

## [0.18.0] - 2026-10-25

### Added
- **`Intel_Event`, the entity graph's event vocabulary.** One table names every event kind, its stream (`items` or `ontology`), its fields and the closed sets they are drawn from; `make()` fills the envelope and refuses an ontology event that does not pack into one 4,096-byte partition line, `validate()` refuses an unknown kind, a missing field, a value outside its closed set, an actor that is not `user:`, `agent:` or `machine:`, or a person or agent writing the items stream, and `from_message()` reads the event a TM_STRUCT message carries.
- **Two event streams and the writer that fills them.** `intel:items` keeps two years of items (16 MiB segments, both retention rules at 63,072,000 seconds, a hard cap of 8,192 segments) and takes item bodies past PIPE_BUF from its one writer, the worker; `intel:ontology` keeps every segment. `Intel_Event_Writer` takes the two partitions as `make_node` arguments and addresses each event to the one its kind's stream names, dropping a message that names no kind with a rate-limited line.
- **Sources record `item_recorded` events.** Each source records at the tier its `make_node` line names (`make_node Feed_Source feed public`; github `public`, linear `internal`), which a load without one refuses and the `set_tier` verb replaces, and stamps the plugin version as each event's `config_version`; an item's body is cut at 65,536 bytes on a character boundary. An item `make()` refuses is logged, skipped and retried on the next TICK, and the TICK's reply counts it as `refused` beside `emitted`. The sources live in `newspack-intelligence-collect.tsl` and write to `intel:writer`. The feed source adds Nieman Lab, Knight Foundation, the Lenfest Institute, LION Publishers and Press Forward.
- **The `intel-graph` projection.** `Projector`, fed by `intel:items:consumer` and `intel:ontology:consumer` in `newspack-intelligence-project.tsl`, replays both streams into the `intel-graph` Row_Table (`Intel_Graph_Row_Schema`, version 1). Items, extractions, links, claims, clusters and canonicals keep 730 days by their day column; entities, their states and merges, names, identifiers, roles, entity topics, judgments, collections, collected items and the ontology `events` keep every row; `events` is indexed by subject and kind, and `judgments` by entity. Every write is an `ignore` or a whole-row `set`, so a replayed event changes nothing and the two streams project the same rows in either order; an item is written once, and a machine's proposal never overwrites a person's entity. Both Consumers drain in batches. Rows leave in one batch every 5 seconds, at 2,000 buffered ops or 1 MiB of string values, at each checkpoint and on a clean stop; a failed batch stops the worker and keeps the buffer, so the cursor replays it, and an event that will not read dead-letters while the cursor moves on. `extract` joins the link methods, for the topic links an extraction makes.
- **Resolution links each recorded item to the entities it is about.** `Resolver` carries the retired Gate's `Publisher_Matcher` over to every entity type and identifier kind, in cost order: identifiers in the item's URL (its host by suffix walk, a GitHub org or repo, a LinkedIn company, an X handle, a Linear issue's team) at 1.0; names and aliases as whole words in the title and body at 1.0, an acronym only in its own case at 0.9; a domain's stem in the title at 0.9, when it runs at least six characters, aligns to word boundaries, capitalizes every word and is not led by an article, and never in a body; and, only when those find nothing, the organizations the NER extractor names, fuzzy-matched against every name — asserted at 0.85, proposed at 0.60, and below that a candidate entity, at most three an item, never a name a rejected entity carries. Name and domain-stem hits whose spans overlap in one text settle together: the longest span wins, so "The Times" inside "The Seattle Times" links only the latter and a name inside a stem's span drops, while equal spans naming several entities assert the one whose locality the text also names as a capitalized word, at 0.9, and otherwise propose each at 0.5. A locality is evidence only and never drops a name. An entity found in the URL or the title is the item's subject, one found only in the body a mention, and two names in one item are two links rather than a hold. A link to an entity that is only proposed is itself proposed, at the confidence its method earned. `Entity_Index` reads the accepted and proposed entities from the projection, a merged entity's names and identifiers answering its survivor; an entity whose merge chain cycles or runs past ten hops is left out, logged once naming each chain, and every other entity still loads; `Intel_Event::entity_key()` keys a candidate by its type and slugged name. `Resolver` (`newspack-intelligence-resolve.tsl`), fed by its own `intel:items:resolve` Consumer, writes a `link_asserted` or `link_proposed` per link and an `entity_proposed` per candidate to `intel:writer` as `machine:resolve@<version>`, and forwards each resolution to `extractor`. It hands the resolver one item a poll (`set_line_mode true`), so a collect burst never runs every item's NER and extraction calls inside one `fill()`. It resolves against no entities, logged, while no `intel-graph` table exists; an `item_recorded` it cannot read dead-letters. The NER client is built from the node's `LLM_Config` state for each recorded item, so a runtime `set_vault_id` or `set_api_url` reaches the next item, and `Resolver::resolve( $item, $ner )` takes it per call. The NER step skips the fuzzy scan for a name whose norm is indexed exactly, and otherwise scores only the names whose length can reach the proposing band.
- **Extraction records what each linked item says.** `Extractor` (`newspack-intelligence-resolve.tsl`), fed each resolution in process, skips an item with no link and no candidate; for any other it makes one LLM call through `Prompts::extract()`, which carries the item as JSON inside an `<item>` fence the system message names as untrusted, and writes an `extraction_recorded` — a summary of at most 280 characters, a kind, topic keys, relevance and importance from 0 to 10, sentiment from -1 to 1 — plus a `claim_asserted` for each of at most five claims whose subject the item links by an asserted link, as `machine:extract@<version>`; a subject linked only by a proposed link asserts no claim. A claim's subject and object resolve through every entity whose name spells the whole of it: the subject is the one the item asserts, the object the only one or the only one the item links, else it stays text, so two entities sharing a name never resolve by load order. The claim takes its subject link's confidence. `Prompts::parse_extract()` reads the reply leniently, reading an unknown kind as `other` and dropping topics outside those offered and predicates outside the closed set. With no client, a thrown call or a reply that does not parse, the item records a heuristic extraction — the first sentence of its title and of its body, kind `other`, no topics, scores 0 — as `machine:extract-heuristic@<version>`, so it is marked extracted rather than retried. Its relevance profile names who Newspack serves and what counts.
- **Dedup clusters repeated items and records each cluster's canonical by event.** The Extractor forwards each extracted item, heuristic ones included at their 0 scores, to `deduper` (`newspack-intelligence-resolve.tsl`), which finds an earlier copy with the same canonical URL — scheme, `www.`, `utm_*`, `fbclid`, `gclid`, `mc_cid`, `mc_eid`, the fragment and a trailing slash dropped, query keys sorted — or else the same title norm of at least 24 characters, within 14 days of the earlier copy, timing an undated item by its retrieval. Meeting a singleton founds a cluster keyed by the earlier item: a `cluster_joined` for both, then a `cluster_superseded` naming the higher-ranked (relevance plus importance, a tie keeping the earlier) as canonical; a later copy joins, and supersedes the canonical only when it outranks it, and joins alone while the cluster's canonical is not yet projected. An item already placed is never placed again: its own lookup entry, else its own `clusters` row, is found before any copy, and re-delivered it only supersedes its own cluster's canonical once it outranks it. A canonical re-delivered at a higher rank supersedes itself to record it; at a lower one the cluster passes to its best-ranked member, a tie keeping the earlier item. Every decision is a new event as `machine:dedup@<version>`. Lookup reads a 5,000-entry LRU first and the `intel-graph` projection on a miss, URL before title, so two copies arriving in one tick still meet; a cluster the LRU no longer holds is read again from the projection. `intel:items:resolve` checkpoints the LRU with its cursor — positional entries under a 16-hex hash of each spelling, no cluster key where the item is its own cluster, about 425 KB when full — so a respawned deduper resumes what it had decided. With no `intel-graph` table a miss finds nothing, logged.
- **`wp newspack-intelligence ontology seed` writes the starting ontology the projection lacks**: thirty topics with their display names and aliases, the partners, funders and industry bodies the team follows (Nieman Lab, Knight Foundation, the Lenfest Institute for Journalism, the Institute for Nonprofit News, LION Publishers, Press Forward) with their domains and roles, and Newspack's products with their GitHub repositories and, for Newspack itself, the `NEWS` Linear team. Each entity is created once, and only for keys whose projected state is absent or a machine's pending proposal, so a seeded entity a curator has since accepted, rejected, merged or renamed is never reset while a machine's proposal of a seed key is replaced and a second run writes nothing; the command prints how many keys were new and how many present, and fails when the projection will not open rather than writing everything. Run as root, it refuses before it writes, as `wp newspack-intelligence ontology reresolve` does, because a root-owned segment or IPC file is one the workers cannot append to.
- **Stored items are re-resolved when the ontology grows.** `Reresolver` (`make_node Reresolver reresolver intel:reresolve`) takes the ontology stream through a new `intel:ontology:tee` in front of the projector, and checkpoints its queue with `intel:ontology:consumer`'s cursor, logging any entry the checkpoint cannot read. An `entity_created` or `name_added` queues the name, an `identifier_added` the URLs it spells (a domain, a GitHub org or repo, a LinkedIn company, an X handle on x.com or twitter.com, a Linear team's issues), an `entity_accepted` every name and identifier the projection holds for the entity, and a new locality the entity's own names; a topic queues nothing. Each Router tick appends `item_reresolve` records, each naming a stored item, to `intel:reresolve`, a Partition keeping a day of them, whose own Consumer `intel:reresolve:resolve` feeds the resolver one record a poll, so the tick never runs resolution or extraction itself and an item whose resolution throws dead-letters alone. A signal at least 30 seconds old goes first, appending its newest 500 hits at once, newest first, and counting the rest only when it reaches that cap, to log how many it skipped; only with none due does a requested range append its next 20. A name is one quoted FTS5 phrase over title and body; an identifier is a LIKE over the URL anchored to a whole host (ending at `/`, a `:` port, `?`, `#` or the end) or path segment, its wildcards escaped, so `ap.org` matches `ap.org:8443` but never `snap.org`, and the handle `abc` never matches `abcxyz`. A unit whose projection read fails for any reason but an absent table leaves the queue with one line naming it and the cause, so a respawn cannot crash-loop on it; a checkpoint holding no queue is dropped and logged. A queued unit checkpoints by its key, `<kind>|<value>`, with when it was queued and a range's cursor. A signal's record carries `fresh_after`, the tick its signal settled (its time plus 30 seconds), and a range's 0. The resolver reads each named item from the projection, drops one it no longer holds with a line, and runs the identifier, name and stem steps alone, because NER ran on first resolution; it writes the item's links, and forwards it to the Extractor only when the projection holds no extraction a call made, so an item is extracted by a call once, one that linked nothing before is extracted now, and one a proxy outage left with only a heuristic extraction is extracted again. An acronym — a `name_added` of kind `acronym`, or an accepted entity's acronym — is searched in its own case too, through a case-sensitive `GLOB` on the title or body beside the case-folding FTS phrase, so `LION` never sweeps every "lion".
- **`wp newspack-intelligence ontology reresolve --since=<Y-m-d> [--until=<Y-m-d>]` re-resolves every item dated in a range of UTC days**, an undated item by its retrieval, through to today when `--until` is omitted. It refuses a date that does not parse or a range that runs backwards before sending anything, sends `RERESOLVE <since> <until>` to each live worker's `reresolver` and reports how many it reached. The reresolver answers the range it queued and appends its items 20 a tick, in the order the projection recorded them; a request it cannot read is answered with the refusal and logged.
- **`Ontology_Writer::append()` writes ontology events from outside the worker** through a transient Partition built from the streams topology's own `intel:ontology` declaration. Its `keep_everything` line is honoured through the Partition's setter rather than replayed as a command, so a caller below MANAGE can append and the writer still never prunes the stream; any other config line on `intel:ontology` is refused by name. It flushes and releases its name whatever happens, and a failure reaches the caller.
- **The `intelligence` service CI answers the graph's read verbs.** Mounted on every request graph, it declares eight verbs, each gated at `read`, its args bound by the schema: `search` (items matching `q` over title, body and summary, an `entity`, a `topic`, a `kind`, a `source` and UTC days, newest first, with each item's summary, kind, scores and linked entities, and the total), `entity` (one entity as its merge survivor, with the keys merged into it, its names, identifiers, roles, topics, and counts of its items and proposed links), `timeline`, `related` (entities sharing the most items, each citing the five newest), `claims`, `trends` (items counted by kind or topic per day, week or month, filterable by an entity or a role held at the item's time, each row citing its five newest items), `review` (proposed links, or proposed entities with the items citing them) and `collections`. `Graph_Query` holds every read. A merged key reads as its survivor everywhere, links to it counting for the survivor, and a key whose merge chain cycles or runs past 10 hops is refused; `Entity_Index::survivors()` is the one survivor map the index load and every read share. `Intel_Graph_Row_Schema::range()` and `day()` parse UTC days for the reads, the `reresolve` command, the Reresolver and the client roster. A link counts by its effective state — a person's judgment over an agent's, else the machine's — and a rejected link counts nowhere. A read resolves the readable tiers once (`Intel_Tiers::readable()`: `public` and `internal`) into `ri`, the readable items every statement reads items through, so no item, link, claim, count or citation rests on a restricted item, and an entity short of `accepted` — proposed, or rejected — answers only while a readable item cites it (`Graph_Query::visible()`); `entity` and `timeline` refuse any other key as `no entity`; an entity's names, identifiers and roles carry no tier and are not filtered. A blank optional arg is a value read as given, so it matches nothing. `q` reaches FTS5 as one quoted phrase per word; no answer carries an item body; `limit` is capped at 200. A projection that will not open answers `available: false` beside empty rows.
- **The `intelligence` CI curates the graph, each judgment one ontology event.** Thirteen verbs, each gated at `tune` and answering `{ ok, event_id }`: `confirm_link` and `reject_link` (carrying the item's URL), `accept_entity`, `reject_entity`, `merge_entities`, `add_name`, `add_identifier`, `assert_role`, `end_role`, `tag_topic`, `create_entity` (keyed by type and slugged name, answering the key), `create_collection` (keyed by its slugged name, answering the key) and `collect`. A call through a labelled session, such as an MCP connection, records `agent:<login>:<label>`, the label as the session stored it; any other call records `user:<login>` (`Intel_Event::caller()`). Each verb reads the projection once and acts on the survivor each entity key answers as, so accepting, merging or tagging a key merged away acts on its survivor. Each refusal throws naming its cause and writes nothing: an entity key a read caller could not see answers `no entity`, exactly as a missing one, and an item outside the readable tiers answers `no item`; a link judgment where no link of any role joins the item to the entity (`no link`); a topic that is not of type `topic`; a merge into itself, across types (naming both), or one that would leave any key that resolved before on a chain that cycles or runs past 10 hops, naming the first; a role asserted in the same second a row of it already opened, which would overwrite that row; an identifier another entity holds, a rejected or broken-chain one included, naming it; an entity key that is taken — visible and no longer a machine's proposal — or a taken collection key, while a creation over a proposal, or over a key the caller cannot see, writes as one over a new key; a role any key resolving to the entity already holds open, or none open to end, which closes the row on the key that holds it; a blank key, name or value; a call with no signed-in user. Keys are capped at 320 bytes, item keys at 512, names and values at 256 and URLs at 1,024, measured as the stream writes them, and a collected title is cut at 512, so every curation event fits one atomic partition line. `fetch_now`, gated at `manage` because it mounts a worker's input, TICKs every source in each live worker. A `collected` event carries its item's tier, and `collections` reads saved items from it at that tier, so a saved item outlives its item's 730-day expiry.
- **An MCP door at `POST /wp-json/newspack-intelligence/v1/mcp` lets a person's agent read and curate the graph.** `Intelligence_Mcp_Door` declares one `intel_<verb>` tool per `intelligence` verb but `fetch_now`, read off the CI's schema, so each tool's role, args and their descriptions are the verb's own. A read session is offered the eight reads, a tune session the curation tools too, and every answer passes the same tier filter the verbs apply, fenced in `<site-data>`. `initialize` explains items, entities, links, claims and their states, listing the entity types and link roles `Intel_Event` declares, asks the agent to cite item URLs, and says an unlinked item was recorded but not summarized; every tool closes on the caveat that items are other people's text and that judgments recorded through the connection rank below a person's, and each read names the 730-day item horizon. Sessions, the per-session meter and the OAuth resource are the substrate door's.
- **The Intelligence admin page reads and curates the graph.** A top-level `Intelligence` menu (`?page=newspack-intelligence`), open to every caller holding `read`, switches in place among four views, each reading the `intelligence` verbs through its own one-shot commands and returning as it was left, its search filters, page and opened collection kept: **Search** (words, kind, topic, source and a UTC day range, a page at a time, each result with its linked entities as chips), **Entity** (the record with its names, identifiers, roles, topics and counts; its timeline, a page at a time; the entities sharing its items; the claims naming it), **Review** (proposed links and proposed entities, each with the items citing it) and **Collections** (every collection with its count, and one opened onto its saved items). A caller holding `tune` also gets the curation controls: add a name or identifier, assert or end a role, confirm or reject a link, accept, reject or merge an entity, create a collection, and save any result to a collection through one dialog. PHP passes the bundle that flag and the ontology's closed lists (`roles`, `nameKinds`, `identifierKinds`, `itemKinds`); the flag only hides controls, and the server's role gate refuses a write the caller may not make. Every cited item links its URL only when it is http or https, every string from the graph renders as text, and each view says whether it holds nothing or could not read the graph (`available: false`).
- **Uninstall removes the projection's files.** `delete_graph_tables()` deletes the SQLite files, WAL files included, of every Row_Table the plugin's own topologies declare with the `Intel_Graph` schema (`intel-graph`), on every partition, reading only the plugin's shipped topologies; it deletes nothing under the default base directory every unconfigured install shares. `uninstall.php` loads the plugin's autoloader to find the schema.

### Changed
- **The Intelligence page wears the event logger's page chrome.** It renders inside the substrate's shared `DashboardShell`: the skin paints the whole admin page, the page sits in a box positioned beside the admin menu instead of WordPress's padded column, and the shared `NEWSPACK::NODES` header names it `Intelligence · v<version> · <host>`, the version localized by the enqueue. The page's own heading, skin wrapper and debug overlay go. Each `Answer` card is the stock card the event logger's dashboards use, its title in the card's header band and its state inset in the card's body, so headings no longer sit flush against the edge. Requires newspack-nodes 2.130.0 at build time.
- **The client roster is imported as ontology events, not CPT rows.** `wp newspack-intelligence clients import <csv>` and the Settings-page upload reconcile against `Graph_Client_Roster`, which reads the projection once per import through `Entity_Index::load()`, so every identifier answers the survivor of any merge, and appends the import's changes through one `Ontology_Writer::append()`. A client the roster lacks resolves first by its atomic id, else by its domain, and the entity found gains the atomic id and `role_asserted client`; only a client found by neither creates `organization:atomic-<id>`, named by its normalised domain, or `Atomic site <id>` when it has none; a row naming no atomic id is refused, and an event that will not mint throws naming its row's atomic id. A domain is added only when no entity holds it, so an import never moves a curated or seeded entity's domain and renames nothing. A churn ends each open `client` row on the key that holds it and asserts `former_client` on the survivor, and a return reverses it, each role dated from the start, UTC, of the import's day. An entity carrying several atomic ids churns only once none of them is in the snapshot. A client whose atomic id, or whose new domain, is held by an entity a person rejected, merged into a rejected survivor, or left on a merge chain that cycles or runs past 10 hops is left alone: the import writes nothing for it, counts it as present, never churns it, and names every such entity in one log line; `Entity_Index::held_by_refused()` names the key holding such an identifier. The command and the upload build a roster per import, so a failed import's buffered events never reach the next. The command records as `machine:client-import@<version>`, the upload as `Intel_Event::caller()`. A projection that will not open fails the import with `Table_Unavailable` and writes nothing, and the command refuses to run as root before it reads the CSV, as the `ontology` verbs do. The counts, `CSV_Parser` and the empty-snapshot refusal are unchanged; the `newspack_publisher` store no longer receives imports. An open role held under any key merged into a client's entity counts as the client's. The importer churns each `active` id `Client_Roster::statuses()` holds that the snapshot lacks, and an entity with several such ids churns once.
- **A cooperative stop raised inside an LLM call stops the worker.** `Worker_Should_Stop` is a `RuntimeException`, so the Extractor and `LLM_Entity_Extractor` re-throw it before catching the call's failures, rather than recording a heuristic extraction or an empty NER result for the item it interrupted.
- **`LLM_Config::$llm_factory` is the one LLM-client seam.** Each node using the trait holds its own copy, `make_llm_client()` answers it first, and the per-node declarations are gone.
- **`Intel_Event::machine_event()` mints every machine stage's event**, stamping `machine:<stage>@<plugin version>` and that version as `config_version`; `Intel_Event::item_of()` reads an item's fields for the resolver and the extractor; `make()` stamps the tick clock, which the substrate pins at class load, and a role a person opens or closes is stamped with its whole second, `Intel_Event::now()`.
- **`intel:ontology:consumer` runs `set_multi_writer true`**, because the worker and request-side `Ontology_Writer` appends both write the ontology stream.
- **A topic is matched by extraction, never in text.** `Entity_Index` offers each live topic by its canonical name through `topics()` and indexes none of a topic's names, so the resolver never links an item to a topic because a word like "Events" appears in it.
- **One `Entity_Index` serves every reader in a process.** `Entity_Index::current()` reads the projection again once `TTL_S` (60 seconds) has passed, or when a re-resolution's `fresh_after` falls after its last read, so the resolver and the extractor never read two copies on two clocks and one signal's records cost one read; the log line for a missing `intel-graph` table reads `no intel-graph to read entities from`.
- **The substrate floor rises to 2.129.0**, because `newspack-intelligence-streams.tsl` turns on the ontology Partition's `keep_everything`, `Intel_Graph_Row_Schema` declares kept tables (`'keep' => true`), the curation verbs read `Capabilities::$session_label`, and the MCP door extends `Mcp_Door`, all four of which newspack-nodes 2.129.0 introduces; the release workflow builds against that tag.
- **The worker's lock goes stale after 600 seconds, not 60.** A TICK's sequential fetches and the LLM calls block inside one `fill()`, and `intel:items` has one writer under `void_warranty`, so a lock stolen mid-TICK could tear its records.
- **`Emits_Struct` is the one way a pipeline node sends a struct on**: the Resolver, the Extractor, the Deduper and every source mint through its `emit()`, and its `declared_targets()`, the leading `node_name` arguments a node's schema declares, names the destinations the Resolver, the Extractor, the Deduper, the Reresolver and the `Intel_Event_Writer` address by TO.
- **`Intel_Graph_Row_Schema::read()` is the one read of the projection.** Given a line, it logs a projection that will not open under it and answers null; given none, it throws. `Intel_Graph_Row_Schema::$read_rows` is its test seam, and `ITEM_TIME` and `item_time()` are the one rule, in SQL and in PHP, that times an item by its `item_ts`, or by its `retrieved_ts` when undated.
- **`Url_Canon::host()` is the one host spelling**, lowercase without its `www.`, which the canonical URL, a domain identifier and the resolver's host walk share; `Prompts::json_object()` is the one lenient read of a model's reply, its first `{…}` block, which the extractor's and the NER step's parses share.
- **`Worker_Route` is the one way a request reaches a worker's nodes from outside it.** The `fetch_now` verb and the `reresolve` command each hand it their requests, which it appends to every live worker's input IPC partition; it answers how many workers it reached, not how many locks it found, and refuses when it reached none with `No newspack-intelligence worker could be reached`.
- **Every item-scoped event carries its item's `retrieved_ts`, and its rows expire with the item.** `link_asserted`, `link_proposed`, `extraction_recorded`, `claim_asserted` and `cluster_joined` carry the item's retrieval and `cluster_superseded` its canonical's; the projector dates each row's `item_day` from it rather than from the event's own time, so a re-resolution written today about an item retrieved 700 days ago expires beside that item. The Deduper keeps each spelling's retrieval in its lookup to date a founder's join, which grows a full checkpoint to about 460 KB. Unreleased wire, so no event without the field is read.
- **A Deduper checkpoint it cannot read is dropped and logged** with its first 200 bytes.
- **The Settings page and its CSV upload answer the `MANAGE` role.** `newspack_intelligence_import_clients`, the page's render and its menu registration ask `Capabilities::can( MANAGE )` and register under `Capabilities::cap_for( MANAGE )`, so the substrate's `allowed_users` list and a scoped session's ceiling apply to the upload, where it asked `current_user_can( 'manage_options' )`.
- **The plugin describes itself as an entity graph**: the plugin header, `composer.json` and `package.json` read "Collects and curates intelligence as an entity graph".
- **`set_vault_id` is one declared setter, owned by `Vault_Secret`,** on `Github_Source`, `Linear_Source` and every `LLM_Config` node; the verb, its arguments and its dump line are unchanged.

### Fixed
- **Sources stamp `retrieved_ts` in whole seconds** (`Intel_Event::now()`), as `item_ts` is and every reader takes it. The fractional tick clock made the deduper drop every live item as unreadable, so nothing clustered.

### Removed
- **The digest pipeline, replaced by the entity graph.** `newspack-intelligence.tsl` composes the streams, collect, project and resolve topologies alone; `newspack-intelligence-ingest.tsl`, `-summary.tsl`, `-digest.tsl` and `-gate.tsl` are gone, and with them `ingest:partition`, `scored:partition`, `digest.md` and `gate-decisions.jsonl`. `Summarizer_Node` and `Scorer_Node` give way to `Extractor_Node`, which records each item's summary, kind, topics, scores and claims as an `extraction_recorded` event; `Digest_Builder_Node` and `Digest_Composer` to the `intelligence` reads, since nothing is compiled; `Gate_Node` and `Publisher_Matcher` to `Resolver` and `Resolver_Node`, which record links as events rather than appending decision lines. `Prompts::enrich()` and `Prompts::digest()` go with their stages; `Prompts::extract()` and `Prompts::extract_entities()` remain. Existing `ingest`, `scored`, `digest.md` and `gate-decisions` logs stay on disk until `wp nodes gc` sweeps them.
- **`Insights_CI` and the Publisher Insights menu**, replaced by `Intelligence_CI` and the Intelligence page. The `insights` CI's `counts`, `top`, `accumulated`, `generate` and `collect` verbs are gone; `intelligence` `fetch_now` ticks the sources as `collect` did.
- **The `newspack_publisher` post type**, its Publisher details meta box, `CPT_Publisher_Repository` and the `Publisher_Repository` interface, replaced by the client roster in the graph (`Graph_Client_Roster`). Existing `newspack_publisher` posts stay in the database, unregistered.
- **The Publisher Insights dashboard bundle.** `src/dashboard/` and `build/dashboard/` are gone, and nothing enqueues them. The draft-post action went with the bundle, and so did the three dependencies only it used, `@wordpress/api-fetch`, `@wordpress/blocks` and `@wordpress/block-library`. The substrate overlay registry names the Intelligence page in its place.

## [0.17.7] - 2026-10-23

### Changed
- **Built against newspack-nodes 2.128.0.** In the bundled debug overlay's Console tab, `/` focuses the REPL without also reaching a page's own `/` shortcut, and a `/` typed in a form control stays there; the Newspack skins draw charts in the re-stepped eight-color palette.

## [0.17.6] - 2026-10-21

### Changed
- **Built against newspack-nodes 2.121.1.** The dashboard's bundled runtime says what a dropped `/command` response line was: its HTTP status, its place in the body, whether the body was cut short, its size, the batch's verbs and its first 120 characters, credentials masked.

## [0.17.5] - 2026-10-20

### Changed
- **Built against newspack-nodes 2.119.0.** The bundled runtime sends every command a task makes in one POST, flushed on a MessageChannel task that a hidden tab does not throttle; its timers and Router tick pace by the shared grid; and its `fnv1a()` hashes a string's UTF-8 bytes, as PHP does.

## [0.17.4] - 2026-10-20

### Changed
- **Built against newspack-nodes 2.118.0.** The bundled runtime reads a raw block's format off its stamp, delivers a `read_block` reply whole, and renders a bare `TM_BATCH` as its text; the bundled debug overlay keeps its Timeline trace button out of the REPL and stores no trace or debug level.

## [0.17.3] - 2026-10-19

### Changed
- **Built against newspack-nodes 2.117.1.** The reserved-name table each bundle inlines no longer lists `_connect_timer`, the connect-queue timer that release deletes.

## [0.17.2] - 2026-10-19

### Changed
- **Plain English throughout.** Comments, docs and test names no longer use clipped forms such as "is no X" for "is not an X", and the loader's comment no longer names a substrate loader at `plugins_loaded:11` that does not exist.

## [0.17.1] - 2026-10-18

### Changed
- **Requires newspack-nodes 2.115.0,** whose style handle carries the `crt-color` skin the bundled skin list offers; below it the plugin goes dormant behind its admin notice.

## [0.17.0] - 2026-10-17

### Changed

- **The Insights verbs answer structs, encoded once.** `counts`, `top` and `accumulated` answer the arrays their shape handlers return, and `collect` and `generate` answer `{ collecting, workers }` and `{ regenerating, workers }` as arrays, where each JSON-encoded its reply into a string the wire encoded again. With no live worker the two verbs refuse, throwing `No live newspack-intelligence worker — start the workers first.`, which the reply carries as a `TM_ERROR` instead of a successful `{ error }`. The dashboard's views drop the `json` flag, and `AccumulatedPanel` shows the refusal's text and reads the ack struct on success.
- **Deploy with newspack-nodes 2.114.0.** The Insights verbs pass their shapes to `node_schema()` as handlers, so this release calls no `slice_verb()`, which 2.114.0 removes; an older intelligence on 2.114.0 fatals on its first schema read.

## [0.16.0] - 2026-10-14

### Added

- **`remove_repo`, `remove_url` and `remove_profile`,** each undoing one `add_repo`, `add_url` or `add_profile`: it takes the add's argument, drops that entry from what the node fetches or scores against and from its `dump_config()`, and is refused, naming the entry, when none matches (`remove_repo 'acme/ghost' names no repo this node declares`). The three pairs are declared through the substrate's `Schema_Reflection::list_verbs()`, which replaces their hand-written handlers and dump loops.

### Changed

- **Rebuilt against newspack-nodes 2.110.0:** the bundled stream hooks call the page link's `attachView()`, which an older substrate's link lacks, so deploy the two together.
- **An identical `add_repo`, `add_url` or `add_profile` repeat is a no-op,** where it added a duplicate, so one `remove_*` takes the value back and a `Vault_Group` rebuilds a child holding what its live children hold.
- **Requires newspack-nodes 2.110.0,** the release carrying `Schema_Reflection::list_verbs()`; below it the plugin stays dormant.
- **`add_repo`, `add_url` and `add_profile` answer `ok` with a trailing newline,** as every declarative verb does, and refuse a blank argument with `InvalidArgumentException`; `add_repo`'s refusal reads `usage: add_repo <repo>`.

## [0.15.5] - 2026-10-14

### Changed

- **Rebuilt against newspack-nodes 2.109.1:** the bundled topology console's shell warns on every unresolved config token rather than once per window.

## [0.15.4] - 2026-10-14

### Changed

- **Bundles newspack-nodes 2.109.0's console and stream:** stamps spelled with `:`, broker pairs with `=`, and Overview rates measured on the probe's own clock. The substrate floor stays at 2.107.0.


## [0.15.3] - 2026-10-13

### Changed

- **Bundles newspack-nodes 2.108.1's console,** which reads its topology list from its own payload, so a page opened in a background tab no longer loses `?topology=`. The substrate floor stays at 2.107.0.

## [0.15.2] - 2026-10-13

### Changed

- **Bundles newspack-nodes 2.108.0's console.** The bundled topology console moves between cards from the keyboard, opens a broker reader's cursor in the Log Viewer, and no longer paints a node a declared node publishes as drift. The substrate floor stays at 2.107.0.

## [0.15.1] - 2026-10-13

### Changed

- **Requires newspack-nodes 2.107.0.** The bundled Triage view removes a dead-letter record with `dl_remove`, which an older substrate refuses as an unknown verb, and the bundled topology console opens an owner's sidecars on the canvas; below 2.107.0 the plugin stays dormant.

## [0.15.0] - 2026-10-13

### Changed

- **Needs newspack-nodes 2.106.0, and bundles its console and stream.** The debug overlay's topology console opens an owner's sidecars as a grid of live cards, asking for them through `dump_metadata -o`, which an older substrate answers with an empty map; below 2.106.0 the plugin stays dormant. The dashboard reads the substrate's raw-block stream through `fetch()`.

## [0.14.6] - 2026-10-12

### Changed

- **The bundled debug overlay and topology canvas come from newspack-nodes 2.105.1:** a patroned node opens from a "…" on its owner's card instead of drawing on the canvas; a graph holding a loop draws left to right; a sink sharing its feeder sits beside it; a card fed by nothing that feeds only a hub sits in the column before the hub, no longer pushing it a column right; and a half step opens only in the block whose wires open it.

## [0.14.5] - 2026-10-10

### Changed

- **The bundled debug overlay and topology canvas come from newspack-nodes 2.104.1:** the overlay outlines as drift only the nodes its console made, and the canvas stacks the bands feeding one hub with no gap and leaves one empty row between groups.

## [0.14.4] - 2026-10-10

### Changed

- **The substrate floor rises to newspack-nodes 2.104.0,** whose page link routes by pairs; the bundled stream hooks call `addPairs()`.

## [0.14.3] - 2026-10-08

### Changed

- **Publisher Insights' commands pass `insights:shell`, no longer `shell:insights`,** so `connect insights:shell` watches this dashboard's traffic. Built against newspack-nodes' renamed group Taps.

## [0.14.2] - 2026-10-08

### Changed

- **The substrate floor rises to newspack-nodes 2.102.0,** whose `shell:<group>` Taps the dashboard is built against, and whose page global `Core` it shares with the substrate's bundles.
- **Publisher Insights' commands pass `shell:insights` rather than `_shell`.** The poll and the Collect and Regenerate buttons send under the `insights` group, so `connect shell:insights` watches this dashboard's traffic alone. Built against newspack-nodes' `egressPath( group, ci )`.

## [0.14.1] - 2026-10-07

### Changed

- **Rebuilt against newspack-nodes 2.100.0.** The debug overlay's Inspector shows a `Fetcher`'s `command` alone, with its arguments in their own field.

## [0.14.0] - 2026-10-07

### Changed

- **The four topologies write `{partition}`, and the substrate floor rises from 2.88.0 to 2.99.0.** The substrate resolves `{partition}` in the arguments a node's schema marks and refuses `<partition>`. A custom topology still writing `<partition>`, such as `<config:logs_dir>/ingest.p<partition>`, now fails to load; write `{partition}`. Below 2.99.0 the plugin stays dormant.

## [0.13.0] - 2026-10-06

### Changed

- **`scripts/pre-push` is newspack-nodes' shared hook**, vendored by `sync-shared-scripts.sh`. This plugin's `scripts/pre-push.local` holds only its container constants. `lint:shell` and lint-staged check it too.
- **`pre-push` checks this plugin's substrate floor.** The shared hook runs `scripts/check-substrate-floor.sh` on every push, after `lint-wp-pin`, so a `version_at_least()` floor below an API the plugin calls fails the push. The vendored `test-*.sh` copies are gone; those self-tests run in newspack-nodes alone.

### Fixed

- **A `DONE` still in flight at `RESET` completes its own cycle, not the next one.** `RESET` appends a `TM_INFO "RESET\n"` fence to `ingest:partition` and the digest clears when that fence arrives, behind every message the previous cycle left in the pipeline. The digest's first argument is the ingest Partition (`make_node Digest_Builder digest ingest:partition 3`), the scored-Partition nudge is gone, and `RESET` answers `{ fence }` instead of `{ cleared }`. The digest declares that Partition in its display targets, so the console draws the edge.
- **The digest composes once per cycle.** It composes on the `DONE` that brings the reported sources to `total`; a replayed or surplus `DONE` no longer recomposes and rewrites `digest.md`. `total` is a required argument, so a `make_node` without it fails loud, and a worker restoring its snapshot keeps the configured `total` rather than the snapshot's.
- **The Gate drops control signals.** It no longer forwards `DONE` or the `RESET` fence, so neither writes a line to `gate-decisions.jsonl`.
- **Dormant against an old substrate, the plugin registers no admin pages.** The Publisher Insights page, the Newspack Intelligence settings page, the dashboard enqueue and the `newspack_nodes/overlay_pages` declaration register behind the version handshake, where the settings page could otherwise fatal on `Clients_Settings`.
- **`pre-push` gates every pushed ref, and fails closed.** It gathers changed paths from every non-delete ref, diffs a branch without a merge base against the empty tree, fails when `git diff` fails, and classifies `.mjs` and `.ts` files as JS.

## [0.12.17] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.2,** so the debug overlay's canvas draws a node its owner builds with a plain outline, not as a node added at runtime.

## [0.12.16] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.1,** so the debug overlay's canvas seats a node's owned Table beside its owner, and lays an untouched graph out again when a poll brings a node or an edge it never covered.

## [0.12.15] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.96.0,** so the debug overlay draws a node's owned Table with its owner named, and offers no delete or rename on it.

## [0.12.14] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.95.1,** so the debug overlay's idle cards fade straight to their dim, a hover-out no longer replays a wire's draw animation, and the canvas fills its empty room before it widens.

## [0.12.13] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.95.0,** so the debug overlay's canvas dims a card whose counter held still since the last poll, and lays the graph out clear of where each wire is drawn.

## [0.12.12] - 2026-10-06

### Changed

- **Rebuilt against newspack-nodes 2.94.0,** so its charts select spans by drag, resize only from the corner button, and the debug overlay's canvas repaints only changed counters.

## [0.12.11] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.93.0,** so the dashboard refreshes the moment its tab is shown again, its search inputs carry a clear button, and its dialogs submit on Enter.

## [0.12.10] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.92.0,** so its time charts resize on shift+click or the corner expand button, and a plain click no longer resizes them.

## [0.12.9] - 2026-10-05

### Changed

- **Rebuilt against newspack-nodes 2.91.0,** so every bundled slice carries the substrate's Fetcher gate: a late reply to a superseded ask no longer reaches its view.

## [0.12.8] - 2026-10-03

### Changed

- **`Digest_Builder_Node` takes its `arguments()` from the substrate's `Schema_Reflection`,** which parses and stores the tokens exactly as its own override did, so the override is gone. The substrate floor rises from 2.77.0 to 2.88.0, the release carrying that method; below it the plugin stays dormant.

## [0.12.7] - 2026-10-03

### Changed

- **Rebuilt against newspack-nodes 2.87.0,** so the bundled debug overlay carries the Compose modal that mints its own messages and the lighter chart palette.

## [0.12.6] - 2026-10-03

### Changed

- **Rebuilt against newspack-nodes 2.86.0,** so the bundled substrate runtime carries its probe record layout, `END_DISK_BYTES` included.

## [0.12.5] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.85.1,** so the bundled charts toggle to double height on a click.

## [0.12.4] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.85.0,** so the bundled shared code and debug overlay carry its duration-axis ticks and probe-chart changes.

## [0.12.3] - 2026-10-02

### Changed

- **Rebuilt against newspack-nodes 2.84.2,** so the inlined debug overlay's REPL footer reads `CONNECTED`, as the station's does.

## [0.12.2] - 2026-09-30

### Changed

- **Every `:config` verb on this plugin's nodes demands MANAGE with newspack-nodes 2.79.1.** The substrate's `dispatch()` now refuses each verb below the role its schema declares, and the `LLM_Config` verbs (`set_api_url`, `set_vault_id`, `set_model`, `set_feature`, `add_profile`), `Gate`'s `set_config_version`, `Github_Source`'s `add_repo` and `Feed_Source`'s `add_url` declare none. A topology load runs them as before; a READ or TUNE session sent to a worker is refused. `insights generate` and `collect` no longer call `require_manage_options()` in their handlers, which the substrate removes: `dispatch()` holds them at MANAGE, as it does the three read slices.

## [0.12.1] - 2026-09-29

### Changed

- **Rebuilt against newspack-nodes 2.79.0,** so the inlined shell sends `request_struct <path> <json>`, a `TM_REQUEST|TM_STRUCT`, as the substrate's does.

## [0.12.0] - 2026-09-29

### Changed

- **Verb handlers read their arguments by name.** The substrate binds each verb's declared `args` before the handler runs, by position or as `--name=value`, so `add_url`, `add_repo`, `set_vault_id`, `set_config_version`, `set_api_url`, `set_model`, `set_feature` and `add_profile` read `$args['url']`, `$args['repo']`, `$args['vault_id']` and so on instead of `$args[0]`. `add_profile` declares `text` variadic, so an unquoted multi-word TSL line still joins into one profile.
- **The substrate floor rises to 2.77.0**, the release that binds verb arguments by their schema. Below it the plugin stays dormant.

## [0.11.0] - 2026-09-29

### Changed

- **Every TM_REQUEST gets an answer.** A source's `TICK` answers `{ verb: "TICK", data: { emitted } }` after its `DONE`, and the digest's `RESET` and `REGENERATE` answer `{ cleared }` and `{ composed }`, each TO the request's FROM through the substrate's `Schema_Reflection::answer_request()`. Any other verb fetches nothing and composes nothing: it answers on the TM_ERROR plane, where a source used to fetch on every TM_REQUEST whatever it carried.
- **The insights CI addresses its requests FROM its own name** rather than a hardcoded `insights`, so the answers land on the worker's output partition beside every other reply to IPC input.
- **The insights CI finds live workers through the substrate's lock-tree walk and mounts their input through `Bootstrap::register_worker_partition()`.** A lock dir no worker id spells, such as a zero-padded `newspack-intelligence.p03.lock.d`, is no longer counted, and the mounted input Partition no longer claims to be its log's sole writer.
- **The substrate floor rises to 2.71.0**, the release carrying `Schema_Reflection::answer_request()` and the static `Spawn_Coordinator::worker_lock_dirs( $base_dir )`, whose entries carry each worker's id. Below it the plugin stays dormant.

## [0.10.2] - 2026-09-28

### Added

- **`Newspack_Intelligence\register_cli_commands()`** registers `wp newspack-intelligence clients`, and `CliUsageOverviewTest` holds it to the usage overview through the substrate's `ListsEveryCliCommand`.

## [0.10.1] - 2026-09-19

### Fixed

- **Admin notices land above the Publisher Insights app, not inside it.** WordPress moves every notice after `.wp-header-end`, or failing that after the first `.wrap` heading, which on this page is inside the React tree; the page now prints the anchor ahead of its React root.

## [0.10.0] - 2026-09-19

### Changed

- **Publisher Insights widgets read their view node's `view` field through `useNodeField`,** following the substrate's move of structured output out of `setState()`.

## [0.9.15] - 2026-09-17

### Changed

- **Builds against substrate 2.60.11.** The substrate tag CI checks out moves up from `v2.60.7`, so the Publisher Insights bundle inlines the shared runtime and debug overlay as 2.60.11 ships them, `debug_ui` and the `_ui` relay included. The runtime floor stays 2.57.0.
- **The publisher store reads in pages of 500.** `all_atomic_ids()` and `all_with_enrichment()` each asked `get_posts()` for every row at once, which VIP forbids. They now walk one shared, id-ordered paged read, so a large publisher list still comes back whole.
- **The PHP lint runs the locked standard.** `lint:php` and `fix:php` called bare `phpcs` and `phpcbf`, which npm resolved to whatever global install sat on the PATH. That older install passed the two unbounded reads above that the locked standard refuses. Both scripts now call `./vendor/bin`.
- **A blank `add_url`, `add_repo` or `add_profile` refuses with a TM_ERROR.** Each answered an ordinary reply reading `error: … requires …`, which a reader testing the TM_ERROR bit took for success. They now throw, as the substrate's verbs do, and the reply is a TM_ERROR reading `usage: add_url <url>`, `usage: add_repo <owner/name>` or `usage: add_profile <text>`.

## [0.9.14] - 2026-09-15

### Changed

- **Builds against substrate 2.60.7.** The substrate tag this plugin's CI checks out moves up from `v2.57.0`, so the Publisher Insights bundle inlines the shared runtime, the shared hooks and the debug overlay as they stand rather than as they were at that tag. Nothing in this plugin changed, and the runtime floor stays 2.57.0 — the release whose `/auth` reply names the signing key `secret`, which the bundled auth client reads.

## [0.9.13] - 2026-09-12

### Changed

- **The substrate floor rises to 2.57.0**, the release whose `/auth` reply names the signing key `secret`. The bundled auth client reads that field, so below the floor the plugin stays dormant instead of minting sessions it cannot sign with.

## [0.9.12] - 2026-09-11

### Changed

- **The insights page registers on the substrate's `newspack_nodes/overlay_pages` filter.** The substrate renamed its DevTools hub the station and the overlay-page filter with it; no old name is aliased, so the loader's substrate floor rises to 2.56.0, the release carrying the new one.

- **The `@wordpress/*` family is declared exactly, and a push gate holds the lock to the declaration.** Every runtime package was declared with a caret at its `wp-7.0` version, and the committed lock had climbed to trunk (seven packages, `components` 32.6.0 and `block-library` 9.48.1 among them, against the tag), so `npm ci` built against an API ahead of the one WordPress 7.0 hands the browser. `@wordpress/icons` is bundled, so the shipped icons move from 11.8.0 to 11.7.1. The declarations drop the caret, the lock is rebuilt at the tag, and `scripts/lint-wp-pin.mjs`, run from `pre-push`, fails a push when a declaration carries a range or the lock resolves elsewhere.

## [0.9.11] - 2026-09-10

### Changed

- **Substrate pin moves to `v2.55.2`**, from `v2.53.0`. Nothing here calls the two APIs that range broke, `Partition_Node::locate_by()` without a key set and `useColumnPicker`'s `aliases` option, so the bundle picks up the shared runtime and hooks at their current versions and no code changes. The tests now seed the Vault through the substrate's `seed_vault()`, which seals the password the way `add()` does, because that substrate reads an unsealed password in the option as planted.

## [0.9.10] - 2026-09-08

### Changed

- **The three admin gates call `\Newspack_Nodes\Capabilities::can( MANAGE )` where they called `\Newspack_Nodes\Admin\Admin::current_user_allowed()`.** The substrate removed that method: `Capabilities::can()` now applies the `allowed_users` allowlist itself, so the wrapper was the same rule in two places. The gate is unchanged in effect — `manage_options` narrowed by that list — and the two class-presence guards ahead of the Publisher Insights menu and the Settings submenu name `Capabilities` for the same reason, since that is the class those functions call. **This release must ship with the substrate that removes the method**: an older Intelligence against the newer substrate fatals with `Call to undefined method` on every wp-admin page, because the enqueue gate runs on `admin_enqueue_scripts` and `version_at_least( '2.25.0' )` passes it through rather than going dormant.

## [0.9.9] - 2026-08-29

### Changed

- **Substrate pin moves to `v2.46.2`**, from `v2.43.3`. It registers the substrate's own `topologies/` dir at plugin load rather than inside the lazy runtime wiring — every topology here `include`s `topic-probe`, and on a request that wires neither tier (a frontend page view) the shutdown wake could not resolve it, losing the wake with `pending wake failed: unknown topology in include: topic-probe`.

## [0.9.8] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.3**, which stops a Router bounce crossing the wire. Unfixed, an unroutable error had the two ends POSTing `NOT_AVAILABLE` at each other about twenty times a second for as long as the tab stayed open. The runtime is INLINED into this bundle, so the fix only reaches a browser through a release here.

## [0.9.7] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.2**, so the debug overlay's REPL echoes each line with the prompt it was typed at rather than a fixed `/`. The overlay is INLINED into this plugin's bundle, not loaded from the substrate at runtime, so the fix only reaches a reader through a release here.

## [0.9.6] - 2026-08-27

### Fixed

- **The substrate floor read `0.54.0`, from when the substrate was 0.x.** It calls `Node::config_line()` (substrate 2.25.0), which every source node builds its `arguments` dump from, and `Worker_Base::ipc_partition_args()` (0.44.0), which the insights CI makes its Partition with. On any substrate between 0.54.0 and 2.25.0 the handshake passes and the plugin activates, then fatals on the missing method rather than lying dormant — which is the one thing the floor exists to prevent.

## [0.9.5] - 2026-08-27

### Fixed

- **Builds against substrate v2.43.1, up from v2.36.2.** The pin had sat eight releases behind, and a stale pin is invisible from the outside: local builds resolve the alias to the working tree while CI resolves it to the tag, so the shipped bundle carried shared code from weeks ago and every workflow run was green. This one moves what actually ships — a log line stamped in the reader's own zone rather than UTC, a failing command named by its own node, the inbound FROM stamp behind `stamp_message`'s guards, and everything else the substrate landed between the two tags.
- **The console-warning gate matches that prefix in any zone.** It stripped a hardcoded ` UTC ` before comparing a declared `expectConsoleWarn`, so every declaration stopped matching the moment the substrate moved. The zone token is constrained to the shapes `Intl` emits — `UTC`, `GMT±H[:MM]`, a 2–5 letter abbreviation — never a bare `\S+`, which would match any `<date> <time> <word> <word>: ` warning text and strip it: the gate swallowing the very lines it exists to report.

### Added

- **`NodeLogPrefixTest`** — subclasses the substrate's shared `NodeLogPrefixTestCase`, which reads this plugin's `make_node` lines for the names its node classes run under and fails on a `$this->` log call that hard-codes one. `Node::log_midfix()` already prints the name, so a message that repeats it doubles it. No node here was offending; the guard is for the next one.
- Intake Gate resolves a publisher from its domain even when enrichment is empty.
  The importer writes only `domain_name`, so the exact-name and alias signals were
  dead for every imported publisher and only a URL-domain hit could attribute an
  item. A key derived from the domain's registrable label (`wyofile.com` ->
  `wyofile`, `newsroom.example.co.nz` -> `example`) is now searched in the item
  **title**, ignoring the spacing prose uses, so "Fort Worth Report" resolves
  `fortworthreport.org`. Reported as `matched_on: domain_stem` with confidence
  0.9, below the 1.0 an exact domain or name earns, so the decision log can tell a
  heuristic hit from a certain one.

  Bodies are deliberately not searched: they are unstripped RSS `description` /
  Atom `content`, where an `href`, a logo filename or an email address spells a
  client's domain and would attribute any item merely linking to that client.

  Guards on a hit: it must align to word boundaries in the original text, every
  word must be capitalized, and a welded multi-word span must not open with an
  article — a title capitalizes its first word regardless, so "The Reader
  Activation System" is spelled exactly like a mention of `thereader.com`. Stems
  under 6 characters are dropped as too generic to tell from ordinary words.

## [0.9.4] - 2026-08-22

### Changed
- **Substrate pin moves 2.35.2 → 2.36.2, and nothing else changes.** Unlike 0.9.3, this release picks up no fix: the three substrate releases in between are a Vault admin screen (2.36.0), the shared log-pane header's scrollbar inset (2.36.1) and the Topology Console's draft dirty-state (2.36.2), and this dashboard inlines none of them — it carries the substrate runtime and the debug overlay, not the log pane or the console. Diffed to be sure: a bundle built against 2.36.2 differs from the published 0.9.3 asset in 493 bytes, all of them the `@newspack-nodes` banner and esbuild's mangled identifiers, at identical file length. The pin moves for hygiene — left at 2.35.2 it silently becomes the base for the NEXT release, which is how a stale pin ships old code past a green workflow.

## [0.9.3] - 2026-08-18

### Fixed
- **Picks up newspack-nodes 2.35.2.** The Publisher Insights dashboard inlines the substrate runtime, so two browser-side fixes only reach it through a rebuild. An unauthenticated send left `_http` locked, silently buffering every later direct `fill()` until the next Router tick POSTed the pile; and a send during a backbone rebuild threw on an unguarded `_http` lookup, unwinding out of `notifyTimer()` and killing the rest of that tick's pollers. The release.yml pin moves 2.34.0 → 2.35.2.

## [0.9.2] - 2026-08-17

### Fixed
- **The dashboard's three view classes are handed to `makeNode`, not named.** `SourceCountsView`, `TopTableView` and `AccumulatedView` were resolved by name through the interpreter's class map, which is a per-bundle static — a graph built through another bundle's interpreter cannot resolve them (substrate ADR-16). `nodes/register.js` exports the map it registers.

### Changed
- `generate` and `collect` ride the batched router tick. The substrate retired `useRequestNode`, whose node minted a POST of its own from the click; both actions now go through `useAwaitableCommand` — one node per verb, the reply still addressed back to it, and the command batched with whatever else the tick was already sending.

## [0.9.1] - 2026-08-14

### Fixed

- **Bundles substrate 2.26.1** (the pin was two releases behind, at 2.25.0), which stops a
  durable reader re-delivering the last record it read: the cursor was committed at each
  record's START rather than past it, so every resume replayed that record.

## [0.9.0] - 2026-08-12

### Fixed

- **The digest is written inside the runtime base.** `digest:log` and
  `Digest_Builder_Node` both hardcoded `/tmp/newspack-intelligence/digest.md`, an
  absolute path that ignored the configured base entirely — while the sibling
  gate topology already used `<config:logs_dir>`. The substrate's Log guard
  (which previously validated nothing for a `Log` node) now refuses a path
  outside the base, and it was right to: the topology now writes
  `<config:logs_dir>/digest.md`, and `DIGEST_PATH` becomes `digest_path()`,
  derived from the same `logs_dir` token so the two cannot drift apart. The
  digest moves from `/tmp/newspack-intelligence/digest.md` to
  `{logs_dir}/digest.md`.

### Fixed

- **`dump_config` quotes every name it emits.** Each `command_node <name>:config`
  line was built by interpolating the node name raw, so a node whose name held a
  space emitted a line with one token too many and replayed as a different graph.
  The lines now go through the substrate's `Node::config_line()`, which serializes
  the whole token list (substrate v-next). Dump output uses the canonical
  `command_node` verb; `cmd` remains a valid input alias.

## [0.8.23] - 2026-08-11

### Changed

- **Substrate pin moves to newspack-nodes v2.24.0.** The browser Shell now has
  one entry point, `fill( message )`, and the debug overlay this plugin embeds
  mounts a `_stdout` node for builtin output. Nothing here called the retired
  `ShellNode.sendCommand()` / `parse()`+`dispatch()` pair, so no source change
  was needed — but the shared code this plugin inlines is only rebuilt against
  the new substrate by a release, which is what this one is for.


## [0.8.22] - 2026-08-11

### Fixed

- **Rebuilt against substrate v2.23.0, whose SSE client fixes the resume.**
  The dashboard's stream inlines `SseInNode`, which used to depend on the
  browser's `Last-Event-ID` header — a header a freshly-constructed
  `EventSource` never sends, so every reconnect tail-seeked past whatever
  arrived while the tab was hidden. The client now owns its reconnect and
  carries its own `positions`.

## [0.8.21] - 2026-08-10

### Changed

- **The `commandClient` seam is gone.** `useInsightsGraph` and
  `PublisherInsights` no longer take it. Injecting a transport replaced the
  whole subsystem, so a hook test never ran HttpOut, pack/unpack, the router
  or the interpreter; the suites seam at `fetch` via the substrate's
  `installFakeCommandWire` instead. Requires the substrate release that
  removes the seam.

## [0.8.20] - 2026-08-10

### Added

- **`lint:types` and the dead-code phpstan layer**, matching the substrate and
  event-logger-nodes. The type-check found a real defect: `pasteHandler` widens
  to `string` for `mode:'INLINE'`, so `serialize()` was handed a type it cannot
  take. The dead-code detector needs the substrate really loaded rather than
  merely scanned, so `.phpstan/load-substrate.php` mirrors the ELN bootstrap.

### Changed

- **Dependencies updated within range** — phpstan 2.2.8, vipwpcs 3.1.0, phpunit
  10.5.64, esbuild, knip, babel presets.
- **The `@wordpress/*` packages now follow the `wp-7.0` dist tag** rather than
  npm `latest`: they are build externals mapped to `window.wp.*`, so core
  supplies the code at runtime. `react`/`react-dom` are pinned to the major
  WP 7.0 bundles.
- Plugin header now carries the same fields, in the same order, as its siblings.

## [0.8.19] - 2026-08-09

### Changed

- Shared tooling synced from newspack-nodes. `collapse_generics` rejected prose
  whose angle brackets happen to balance (`a<b, see c>d`) and let an unbalanced
  `<` earlier in a line hide a real generic from the gate; it validates each
  candidate span and backtracks now. `--fix` also gates instead of always
  exiting 0, and `lint-docs.sh` gains a rule keeping a documented substrate
  version floor in step with the loader that enforces it.

## [0.8.18] - 2026-08-09

### Changed

- Substrate pin moves to newspack-nodes v2.16.1 → v2.17.1.

- One spelling for a generic type in docblocks: `array<string,mixed>`, no space
  after the comma. Both parse identically; only that one highlights as a single
  type in the editor. `lint-comments.php` carries the rule now, so it holds.

## [0.8.17] - 2026-08-09

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.3 → v2.16.1**, five releases at
  once, so the bundled runtime and debug overlay stop being that far behind.
  What it carries: a log stream that resumes at EOF closes on the first tick
  instead of holding a worker for the whole idle window; the browser's SSE node
  is named `<link>:sse-in` so `trace` can reach it; a `set_state` transition is
  traced like the PHP twin's; the heartbeat's expected `slot_released` no
  longer counts as an error; a CONNECTED state no longer publishes the lease
  owner into the transcript; and "reset stats" clears the overlay's message
  list rather than leaving it on screen.

## [0.8.16] - 2026-08-07

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.3**, which stops an on-demand
  worker respawning itself at exit. A worker that both writes and tails a
  partition marked a wake for itself every run, then woke on its own writes
  once it had released its lock — so it cycled on an exact `on_demand_idle`
  period instead of scaling to zero.

## [0.8.15] - 2026-08-07

### Fixed

- **Substrate pin moves to newspack-nodes v2.14.2**, which stops an on-demand
  worker being pinned awake by a Consumer tailing a log that has never been
  written. Any topology here that mounts a Consumer against a log a given
  install never produces was scaling to zero only until the first message woke
  it, then staying up indefinitely.

## [0.8.14] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.1**, a performance release on
  paths the digest pipeline sits on: `Router_Node::fill()` (~1427ns → ~1020ns
  per routed message) and `Message::packed()` (~754ns → ~593ns, on every
  partition write). Figures measured in the arm64 dev container, whose PHP 8.4
  build lacks the vectorized JSON escape scan present on x86 — read them as
  ratios, not absolutes.

## [0.8.13] - 2026-08-07

### Changed

- **Substrate pin moves to newspack-nodes v2.14.0**, which adds the
  `wp nodes stop` / `wp nodes start` deploy hold. Digest runs are long-lived
  LLM calls, so this fleet is among the most exposed to a plugin update landing
  mid-job: swapping `includes/` under a live worker makes its autoloader fail
  on this plugin's own classes, and the in-flight record is quarantined as
  poison. Take the fleet down around the deploy:
  `wp nodes stop && ./deploy.sh && wp nodes start`.

## [0.8.12] - 2026-08-07

## [0.8.11] - 2026-08-07

### Fixed

- **Re-pinned to newspack-nodes 2.13.1** for the shared log-browser header
  alignment. `LogRowList` is inlined at build time, so 0.8.10 shipped the
  pre-fix copy.

## [0.8.10] - 2026-08-07

### Changed

- **Pinned resident with `var on_demand_idle = 0`.** newspack-nodes 2.13.0 lets
  a topology scale to zero when every reporter goes idle, and an operator can
  turn that on fleet-wide from config. This pipeline opts out explicitly rather
  than inheriting that: it holds LLM work in flight that no Consumer's EOF
  describes, so an idle window would be measuring the wrong thing.

### Changed

- **Blank-line runs are collapsed on commit.** `scripts/fix-blank-lines.php`
  joins the shared tooling and runs in `lint-staged` after the comment gate. It
  is token-aware: heredoc and string bodies keep their blank lines.

## [0.8.9] - 2026-08-06

### Changed

- **The shared comment gate is now `scripts/lint-comments.{php,mjs}`** (was
  `lint-comment-length`), because the PHP half no longer checks only length: at
  class-body level the only comment allowed is a docblock immediately preceding
  its declaration. Section headers, `//` notes where a docblock belongs, and
  docblocks whose method was deleted are all rejected. Comments inside a
  class-level initializer annotate their entry and stay exempt. Existing
  violations in this plugin are cleaned up here; no behavior changes.

## [0.8.8] - 2026-08-05

### Changed

- **Re-pinned to newspack-nodes v2.10.0.** No change of its own — the substrate
  turned every command-interpreter refusal into a TM_ERROR reply, and the shared
  runtime is inlined into this plugin's bundles at build time, so the old pin
  would keep shipping an interpreter that answers a refusal with a plain string.

## [0.8.7] - 2026-08-04

### Fixed

- **The insights poll declares its own cadence.** `useInsightsGraph` forwarded
  `opts.intervalMs` straight through, so a caller that omitted it — which
  `PublisherInsights` does unless its own prop is supplied — fell through to
  `useBatchedPoll`'s silent default of every router tick, i.e. 1Hz for a digest
  that changes on the order of minutes. It now defaults to 30s.

## [0.8.6] - 2026-08-04

### Changed

- **Substrate pinned to newspack-nodes v2.8.0**, up from v2.4.6 — four minor
  versions of shared code the bundle had been building against a stale tag.
- The newspaper-order method gate (`reorder-node-methods --check`) runs in
  lint-staged, matching the substrate.

### Fixed

- Followed the substrate's `Fetcher` field rename: the configured command name
  is `verb`, because the old spelling shadowed the inherited `Node#command()`
  minter and made `.command` a string on that one class.

## [0.8.5] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.6.** Dashboards stop polling while
  their tab is hidden — the router tick every poller hitchhikes is now gated on
  page visibility — and the SSE slot poke drops from every 5s to every 15s
  against the server's 60s lease TTL.

## [0.8.4] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.5.** A node whose schema declares
  `hidden` is now hidden on the topology console canvas, not just in the palette.

## [0.8.3] - 2026-08-03

### Changed

- **Substrate pinned to newspack-nodes v2.4.4.** Brings the Topic partition-dir
  declaration fix: a Topic that re-partitions beyond its topology's worker count
  now declares every directory it writes, so `Log_Cleaner` stops treating them as
  orphans and worker status shows them all.

## [0.8.2] - 2026-08-02

### Changed

- **Substrate pinned to newspack-nodes v2.4.3.** Brings the per-site SSE slot
  namespace — the pool keyed on `gethostname()`, which on Atomic is the shared
  pool host, so co-located sites collapsed onto a single 10-slot budget and the
  surplus was refused with a permanent HTTP 429. Also brings the staggered
  Remote_Link connects, the phased session request, and the topology console's
  auto-layout and stored-coordinate fixes.

## [0.8.1] - 2026-08-02

### Fixed

- **Rebuilt against substrate v2.4.2**, which keeps a `Request` node alive while
  any hook still holds it. `useRequestNode` is inlined into this bundle, so
  0.8.0 shipped the copy where one hook's unmount removed a node another still
  held. No source change here; the pin is the fix.

## [0.8.0] - 2026-08-02

### Changed

- **`CommandClient` is gone from the substrate**, folded into `HttpOut`. The
  `_http.client` seam is unchanged and duck-typed, so the dashboard's fake still
  works; production no longer constructs one.

- **`generate` and `collect` each mint from their own `Request` node.** They used
  to stamp an op-id into `message[ID]` and have `accumulated:view` settle the
  match — routing that had already happened, since a command is minted FROM the
  node that wants the answer and the server replies `TO = FROM`. Two nodes, two
  replies, nothing to tell apart. `accumulated:view` is a slice publisher again.

## [0.7.14] - 2026-08-02

### Added

- **`npm run lint:deadcode:js` — the JS half of the dead-code audit.** Mirrors
  the substrate's `knip.json`: `__tests__` out of `project`, knip's jest plugin
  off so a module reachable only from its own test reads as dead, and the
  `@newspack-nodes/*` aliases resolved through the sibling checkout. Opt-in, not
  in the push gate.

- **`.prettierrc.js`.** Every sibling plugin loads `@wordpress/prettier-config`
  through this file; this one had the dependency installed and nothing wiring it
  up, so `npm run format` ran on wp-prettier's defaults and fought eslint. Found
  by knip reporting the dependency as unused.

### Removed

- **`@xyflow/react` and `d3-flame-graph`.** Declared but imported nowhere in
  this plugin or the substrate. Found by knip.

### Changed

- **The build takes ONE substrate override, `NEWSPACK_NODES_SRC`.** It replaces
  four independent ones — `NEWSPACK_NODES_RUNTIME`, `_DEBUG_OVERLAY`, `_SHARED`,
  `_BUILD_KIT` — which all named paths inside the same directory. Every alias and
  the build-kit path now derive from that one base via the substrate's
  `build-kit/alias-map.js`, so a new alias needs no workflow change.

  That enumeration is what made `ERR_MODULE_NOT_FOUND` releases possible: omit
  any single variable and the build fell back to a nonexistent sibling path.
  Setting a retired name now fails immediately and names it, rather than being
  silently ignored — a stale override that does nothing is how a release builds
  against the wrong checkout and still goes green. `release.yml` updated to match.

  Build output is byte-identical, verified by rebuilding with only `build.mjs`
  varying.

## [0.7.13] - 2026-07-31

### Security

- **Dev-dependency advisories cleared where a fix exists.** `js-yaml` to 4.3.1
  and `brace-expansion` to 1.1.18 / 2.1.4, closing GHSA-52cp-r559-cp3m and
  GHSA-3jxr-9vmj-r5cp. All are development scope — `build-release.sh` excludes
  `node_modules` and PHP installs `--no-dev`, so none of this ships.

  **GHSA-mh99-v99m-4gvg (brace-expansion) is deliberately left open.** It covers
  every version `<= 5.0.7`, so the fix is 5.0.8 — and 5.0.8 changed the CommonJS
  export from a bare function to `{ EXPANSION_MAX, EXPANSION_MAX_LENGTH, expand }`,
  which every `minimatch` below 10 calls as a function. An override to 5.0.8 or
  5.0.9 breaks eslint with `TypeError: expand is not a function`. `npm audit fix
  --force` clears it only by taking eslint 10 AND downgrading jest 30 to 25 and
  babel-jest 30 to 23. The residual risk is a DoS reachable only by feeding a
  hostile glob to our own build tooling, which a hostile PR could not do without
  already being able to run code in CI.

### Fixed

- **`Source_Node`'s docblock promised a durability guarantee that does not exist.**
  It claimed the emitted-id set round-trips through `save_state`/`restore_state`;
  neither method is defined on that class, and `git log -S` shows neither ever was.
  The set is in-memory and bounded, so a respawned worker re-emits whatever its
  next fetch still returns. `Digest_Builder_Node` dedups on the same id, so the
  digest stays correct — but the summarize and score stages sit upstream of that
  dedup and pay for the repeat. A TICK-driven source has no Consumer offsetlog to
  co-commit a snapshot into, which is why `add_snapshot_node` is unavailable here.

## [0.7.12] - 2026-07-31

### Changed

- **`lint-docs.sh` is a shared pre-push gate.** The doc-drift lint was
  substrate-only; it now ships to every plugin via `sync-shared-scripts.sh` and
  runs from each `pre-push`. It caught three `make_node` examples in
  event-logger-nodes documenting a retention arg list the shipped topology never
  passes.


## [0.7.11] - 2026-07-31

### Changed

- **The vendored `reorder-node-methods` tooling now passes the comment-length
  gate.** Function-level prose moved into docblocks, inline prose condensed to
  one line; four algorithm notes that genuinely need the length carry
  `@longform`. No behavior change — the tool's own test still passes 38/38.


## [0.7.10] - 2026-07-31

### Added

- **Vendored copies of the substrate's shared tooling** (`scripts/bump-version.sh`
  + `scripts/lib/`, `reorder-node-methods`, the coverage and comment-length
  gates, `pre-commit`, `commit-msg`), so a standalone clone works without a
  sibling checkout. `scripts/sync-shared-scripts.sh` refreshes them from
  `../newspack-nodes/scripts/` on each `pre-commit` when that sibling exists —
  edit shared scripts THERE, not here.
- **`scripts/commit-msg`** — the conventional-commit gate, now a tracked hook.
  It skips cleanly where commitlint isn't installed.

### Changed

- **Git hooks come from `core.hooksPath`, not `.git/hooks`.** `composer install`
  now points git at `scripts/`, so the hooks are version controlled and reviewed
  with the code they gate. A clone that has never run `composer install` has no
  hooks at all.
- `scripts/bump-version.sh` replaces dndocker's `tools/bump-intelligence-version.sh`.
  Behavior is unchanged; the shared flow lives in `scripts/lib/bump-version.sh`
  and the wrapper is only the per-plugin knobs.

### Removed

- `brainmaestro/composer-git-hooks` — `core.hooksPath` does the job with no
  dependency, and cghooks-installed `.git/hooks` files are now dead files git
  ignores.


## [0.7.9] - 2026-07-31

### Fixed

- **A dev build and a CI build now emit the same bytes.** Shared substrate
  source importing a bare dependency (`d3`, `@noble/hashes`) resolved it from
  the substrate's own tree first. CI checks the substrate out without
  `node_modules`, so resolution fell through to this plugin's copy; a developer
  checkout HAS `node_modules`, so esbuild bundled a second copy under a
  different absolute path. Every
  dependency this plugin owns is now pinned to its own copy. Verified by
  building against a substrate checkout carrying `node_modules` and diffing the
  result against the published release: byte-identical.


## [0.7.8] - 2026-07-30

### Fixed

- **Bundles newspack-nodes v2.2.10.** The release workflow still pinned the
  v2.2.9 substrate, so v0.7.7 shipped the pre-rewrite graph stylesheet — the
  very styles that release set out to load.

## [0.7.7] - 2026-07-30

### Changed

- **The embedded Publisher Insights graph/debug overlay declares its stylesheet
  dependencies.** The dashboard asset now loads `wp-components` and
  `newspack-nodes-graph` for that substrate surface.

## [0.7.6] - 2026-07-29

### Changed
- **Bundles newspack-nodes v2.2.9.** Dashboard first polls now survive
  navigation-time authentication and visibility races instead of remaining
  stalled until a later reload or focus transition.
- Updated the release workflow to checkout and Node setup Actions v7.

## [0.7.5] - 2026-07-28

### Changed
- **Release builds against newspack-nodes 2.2.5.** The inlined runtime carries
  the canonical seven-check Site Health/doctor report, APCu-capable command
  sessions, and base-dir-independent diagnostics while preserving full
  attached-console access to normal workers.

## [0.7.4] - 2026-07-28

### Changed
- **Release builds against newspack-nodes 2.2.4.** The inlined runtime now uses
  ownership-fenced SSE slot leases and reports the endpoint's disconnect reason,
  preventing a stale connection from releasing a successor's slot while making
  future unexplained stream endings distinguishable from lease loss.

## [0.7.3] - 2026-07-27

### Changed
- **Release builds against newspack-nodes 2.2.3.** The pin sat at 2.0.0, which
  predates the `secure` ratchet the topologies below now declare, and the shell
  and command-session work in 2.1–2.2 that the inlined runtime carries.

### Security
- **Every topology declares `secure`.** The ratchet (newspack-nodes 2.1.0) drops
  a class of verb per level; level 1 removes `make_node`, so a wire-arrived
  command can no longer add nodes to a running graph. It sits on the LAST line
  of each file because it disables the very verb the rest of the file uses —
  declared any earlier it would refuse the graph it exists to protect. Each
  sub-topology carries its own, since `include` strips the declaration from an
  included file and the four are separately activatable.

## [0.7.2] - 2026-07-26

### Changed
- **Release builds against newspack-nodes 2.0.0**, and declares
  `@noble/hashes`. The workflow pinned an older substrate tag, so the archive
  compiled against a runtime predating the command-session exports; and the
  substrate runtime this plugin inlines imports `@noble/hashes` for its
  synchronous HMAC, which resolved locally only by walking up into the
  substrate's own `node_modules`. CI checks the substrate out without its
  dependencies, so it has to be declared here.

### Fixed
- **`useInsightsGraph` mints through `Node.command()`.** The hand-built builder
  called `markLocal()`, which marks LOCAL but declines to sign with no session.
  Minting through the accumulated view gates on the session instead.
- **`useInsightsGraph` actions hold for the session.** A click during the
  `/auth` round trip minted UNSIGNED and was refused; the action now fires on
  `ensureSession()`, guarded against a teardown mid-round-trip. Requires the
  matching newspack-nodes change.

### Changed
- **`useInsightsGraph`'s command mint completes.** It built commands without
  marking them, so every first poll shipped unsigned and was refused. The poller
  retries, so it self-healed once the session landed — noisy, not broken.
- **Test harness authenticates, polyfills `TextEncoder`, and transforms
  `@noble/hashes`.** The substrate's emitters hold until a command session
  exists, and its signer is ESM-only and needs `TextEncoder`, which jsdom lacks.
  This plugin inlines that runtime and needed all three. Requires the matching
  newspack-nodes change.

## [0.7.1] - 2026-07-24

### Changed
- Dropped `ReflectionProperty`/`ReflectionMethod::setAccessible()` calls from
  the test suite — deprecated in PHP 8.5, a no-op since PHP 8.1.

## [0.7.0] - 2026-07-24

### Added
- Substrate version handshake at boot: on a substrate older than 0.54.0 the
  plugin goes dormant with an admin notice (via the substrate's
  `Bootstrap::version_at_least()`) instead of fataling on a missing API.
  A substrate predating the handshake API (nodes < 0.54.0) also parks the
  plugin — the stack deploys as a unit, so a missing API means too old.

### Changed
- The release workflow pins the newspack-nodes checkout to the `v0.54.0` tag
  instead of tracking `main` — bump the pin when adopting a newer substrate.

## [0.6.0] - 2026-07-24

### Changed
- Track the substrate's retention rename: `newspack-intelligence-ingest.tsl` and
  `newspack-intelligence-summary.tsl` swap the durable Partition tails from
  `<config:max_segments>` / `<config:max_lifetime>` to `<config:num_segments>` /
  `<config:lifetime>` (count target and age rule under their new names). Test
  config renames `max_segments`→`num_segments` and `max_lifetime`→`lifetime`.

## [0.5.3] - 2026-07-23

**Pairs with newspack-nodes ≥ 0.51.0** (the `add_snapshot_node` verb and the
name-keyed snapshot frame).

### Changed
- `newspack-intelligence-digest.tsl` migrates `set_snapshot_node` (deleted
  upstream) to `add_snapshot_node`; `Insights_CI_Node` reads the digest state
  from the frame's name-keyed `cache` map (`cache.digest`), including the
  `read_latest_snapshot_cache( …, 'digest' )` node argument.

## [0.5.2] - 2026-07-23

### Fixed
- **`add_profile` takes the whole line.** The verb dispatch was a stale port
  from the token-array migration reading only `$args[0]`, so an unquoted
  multi-word profile stored just its first word (`Don't produce tables.` →
  `Do`). All positional tokens now join with spaces, like `echo`.
- **`dump_config` round-trips.** LLM-config verb args emit through
  `Node::serialize_args()` (multi-word profiles come back single-quoted as
  one token), and an explicitly-set value dumps even when it equals the
  trait default — a pinned `set_model gpt-oss-120b` survives a future
  default bump instead of silently vanishing from the dump.
- **Topology profile lines are properly quoted** (double quotes, so
  apostrophes survive the tokenizer and `<…>` still interpolates).

## [0.5.1] - 2026-07-21

### Fixed

- **`Proxy_LLM_Client::chat()` no longer silently POSTs an empty body to the AI proxy.** Ingested GitHub/Linear/feed content isn't guaranteed clean UTF-8; when `wp_json_encode()` on the chat-completions body failed, the bare `(string)` cast around it silently coerced the encode failure to `''`, sending an empty request body instead of a diagnosable error. `chat()` now throws a `RuntimeException` naming `json_last_error_msg()` before sending anything (mirrors the substrate `newspack-nodes` fix in `Message::packed()` for the same swallow-into-empty-string shape).

## [0.5.0] - 2026-07-17

### Added

- Add `Publisher_Repository::all_with_enrichment()`, exposing each publisher's matchable fields (domain, publisher name, aliases, status) for the intake Gate.
- Add `Publisher_Matcher`, the intake Gate's deterministic hard-match layer: resolves a normalized item to a publisher by URL domain then exact name/alias (whole-word), emitting a `pass`/`hold`/`bypass` decision record. GitHub/Linear items bypass the Gate; deterministic misses `hold` (recall-biased, pending the LLM NER layer).
- Add `Prompts::extract_entities()` and `LLM_Entity_Extractor` (behind an `Entity_Extractor` seam) — the intake Gate's cheap-NER step: extract an item's subject organizations/people/locations as JSON, with a lenient parse that degrades to an empty result on any model/transport failure.
- `Publisher_Matcher`: on a deterministic miss, optionally run NER + fuzzy string-similarity match against the publisher store, banded by confidence into `pass` (≥0.85), `hold` (0.60–0.85 or ambiguous), or `ignore` (<0.60). The decision record now carries a `confidence` field. With no extractor injected, behavior is unchanged (miss ⇒ `hold`).
- Add `Gate_Node`, a Transform node wrapping `Publisher_Matcher`: runs the Gate on each item and emits a decision record (stamped with a persist-time `ts`). Uses the `LLM_Config` verbs for the optional NER extractor (no token ⇒ deterministic-only) plus a `set_config_version` verb; builds its matcher once so the publisher-set memoization spans a collect.
- Wire the intake Gate into the pipeline as an **observer** stage (`newspack-intelligence-gate`): a second consumer (`gate:consumer`) tails the same `ingest` partition with its own offsets, feeding `gate → gate:tojson → gate:log`, which appends every decision as one JSON line to `gate-decisions.jsonl` (the append-only decision-log backbone). Purely additive — the summary/digest stages are unchanged; moving the Gate inline to filter the digest is deferred.

### Changed

- **Renamed the plugin to Newspack Intelligence.** Slug `newspack-ai-newsletter` → `newspack-intelligence`; PHP namespace `Newspack_AI_Newsletter` → `Newspack_Intelligence`; constants (`NEWSPACK_INTELLIGENCE_*`), text domain, admin-menu slugs, WP option keys (`newspack_intelligence_*`), WP-CLI root (`wp newspack-intelligence clients`), AI-proxy feature (`X-WPCOM-AI-Feature: newspack-intelligence`), and the digest path (`/tmp/newspack-intelligence/digest.md`) all move. The GitHub repo and plugin directory are renamed to match. Clean break — no migration of old options/paths (local-only environment). The pipeline topologies were already `newspack-intelligence`; this finishes the rebrand of the plugin identity.

## [0.4.1] - 2026-07-16

### Fixed

- **A malformed clients CSV can no longer churn every publisher.** `CSV_Parser::parse()` now returns `null` (instead of `[]`) when the first non-blank line isn't the expected "Atomic…" header, or when zero valid data rows result; `Client_Importer::import()` refuses to reconcile an empty snapshot. Previously a readable-but-malformed file produced an empty snapshot whose reconciliation marked EVERY existing client churned.
- **The Publisher Insights dashboard emits the token-array command form.** `useInsightsGraph` action verbs (`generate` / `collect`) now send `arguments: []` instead of the retired joined-string `''`.

### Changed

- **Split the pipeline topology into `newspack-intelligence` and deleted the `newspack-ai-newsletter` monolith.** The one-file `newspack-ai-newsletter.tsl` is replaced by `newspack-intelligence.tsl`, which `include`s three composable stages (`newspack-intelligence-ingest`, `-summary`, `-digest`) that build the identical node graph. The worker topology identifier — and therefore the worker-id/lock-dir prefix `Insights_CI` globs — is now `newspack-intelligence` (was `newspack-ai-newsletter`). The plugin slug, plugin file, namespace, text domain, admin-menu slugs, AI-proxy feature name, and the `/tmp/newspack-ai-newsletter/` digest path are unchanged; only the topology name moved.

### Removed

- Removed the dead `Insights_CI_Node::read_snapshot_items()` — the parallel snapshot-read path retired when Regenerate moved to the worker; production reads through `read_snapshot()`.
- **Removed the never-loaded `newspack-ai-newsletter-config.php` and its scaffold-era guards.** The plugin has no app `Config` class, so nothing loaded the bundled file; it runs on the `newspack-nodes` substrate config + option overlay (its retention values were identical to the substrate defaults, and the digest path is the hardcoded `Digest_Builder_Node::DIGEST_PATH`). Also dropped the redundant `is_dir( build/dashboard )` enqueue guard (the substrate's `Admin::enqueue_react_page()` already no-ops when `index.js` is absent), the build.mjs "entry may not exist yet" filter (the dashboard now always ships), and its stale `phpcs.xml.dist` scan entry. Behavior-neutral.

## [0.4.0] - 2026-07-16

### Changed

- **Migrated to the newspack-nodes token-array command contract.** TM_COMMAND `arguments` and node-constructor `arguments` are now a token array (`list<string>` argv) rather than a joined string, matching the substrate change. The source-node config verbs (`add_url`, `add_repo`, `set_vault_id`, `set_api_url`, `set_model`, `set_feature`, `add_profile`), `Digest_Builder_Node::arguments()`, the `Insights_CI` verbs, and the Publisher Insights dashboard read and produce token arrays; `Insights_CI` spreads the substrate IPC-partition argument tokens into `make_node`.

## [0.3.2] - 2026-07-15

### Fixed

- **The publisher CPT no longer rewrites every `manage_options` check into a bare `delete_post` check.** Its fully explicit admin-only capability map assigned `manage_options` to all three singular post capabilities while also enabling `map_meta_cap`; WordPress consequently registered the global reverse mapping `manage_options => delete_post`, so ordinary admin-page permission checks emitted the “specific post” notice. Disabling post-type meta-cap registration keeps every publisher operation gated directly by `manage_options` without poisoning unrelated capability checks.

## [0.3.1] - 2026-07-15


### Fixed

- **The publisher CPT/meta-box hooks no longer fatal the site when the substrate is briefly inactive.** `init`/`add_meta_boxes`/`save_post` were wired to `Publisher_CPT`/`Publisher_Meta_Box` class-string callables at plugin-file scope, but those classes only autoload after the substrate-gated bootstrap requires the Composer autoloader. Whenever `newspack-nodes` was momentarily deactivated (e.g. a nodes redeploy), the gate short-circuited, the autoloader never loaded, and the still-registered `init` callback raised `class "…\Publisher_CPT" not found` — a site-wide critical error. The three registrations now sit inside the gated bootstrap closure (like the Clients upload hooks), so they only wire when the plugin's own classes are loadable.

## [0.3.0] - 2026-07-15

### Added

- Add CSV_Parser for the Newspack clients list.
- Add Publisher_Repository contract and Client_Importer reconciliation.
- Register the newspack_publisher master-data CPT.
- Add CPT-backed Publisher_Repository implementation.
- Add `wp newspack-ai-newsletter clients import` WP-CLI command.
- Add Settings-page CSV upload for the publisher master store.
- Add `CSV_Parser::parse_file()`, the single owner of the CSV file-read (readability guard + `file_get_contents` + parse), used by both the CLI command and the Settings handler.
- Render an `admin_notices` success notice ("Newspack clients imported.") after a completed Settings-page CSV import.
- Add `Publisher_Meta_Box`, the "Publisher details" admin meta box on `newspack_publisher`: editable enrichment fields (publisher name, localities, GitHub org, LinkedIn company ID, X handle, aliases, beat tags) plus a read-only provenance section for the import-managed fields (atomic site ID, domain, created, status, first/last seen, churned at).

### Changed

- De-duplicated the CSV file-read between the WP-CLI command and the Settings handler by routing both through `CSV_Parser::parse_file()`; `Client_Importer` remains pure (no file I/O).
- Documented the `Publisher_Repository` interface contract (each method now states its effect, e.g. `set_active()` clears `churned_at`, `create()` seeds `first_seen`/`last_seen`/`churned_at`).

### Fixed

- Register the publisher-CSV `admin_post` handler and `admin_notices` inside the `plugins_loaded` bootstrap closure (after the composer autoloader is required) instead of at plugin-file scope. Referencing `Clients_Settings::ADMIN_POST_ACTION` at file-load time fataled with `Class "Newspack_AI_Newsletter\Clients_Settings" not found` on activation, before the autoloader was set up.
- `Client_Importer::import()` no longer double-counts a reactivated publisher in both `updated` and `reactivated`; the counts are now disjoint (a churned row that returns is counted only as `reactivated`).
- The Settings-page CSV import's redirect fallback now points at the plugin's own Settings page (`options-general.php?page=newspack-ai-newsletter-settings`) instead of the generic admin dashboard.
- Restrict the `newspack_publisher` CPT to `manage_options`: an explicit `capabilities` map now gates list/edit/delete/create, so roles with only `edit_posts` (Editors/Authors) can no longer view or modify publisher records via `capability_type => 'post'` defaults.
- `CPT_Publisher_Repository::update_atomic_fields()` now syncs `post_title` to the new domain when a publisher's domain changes on re-import (previously only the `_npainl_domain_name` meta updated, leaving the admin list showing the stale domain); the title write is skipped when the domain is unchanged.
- **The PHPUnit bootstrap no longer reflects the removed Nodes config allowlist.** Operator-selected config files are validated directly by the substrate, so the obsolete test-only widening seam now stays out of the consumer harness and the suite boots against the current Nodes API.
- **The PHPUnit `add_action`/`add_filter` doubles accept lazy class-string callables, matching WordPress.** The stubs type-hinted `callable`, which rejects a `[ 'Class', 'method' ]` array for a class Composer autoloads after plugin-file scope — exactly the publisher CPT/meta-box registrations at plugin-file scope. That fataled the whole test bootstrap (`TypeError: add_action(): Argument #2 must be of type callable`). The doubles now mirror WordPress's untyped `$callback` and resolve it lazily at `do_action` time.

## [0.2.13] - 2026-07-14

### Fixed

- **`ingest:consumer` and `scored:consumer` now declare a dead-letter dir.** Without it the substrate disables the DLQ, so a poison item was logged and dropped rather than quarantined.

- **The insights IPC partition inherited a retention config that stopped it pruning.** It built its Partition from `IPC_SEGMENT_SIZE` + a bare segment count, leaving `min_lifetime` to fall back to `<config:min_lifetime>` (an hour) — which protects every freshly-written segment from the count rule, so the scratch dir grew without bound. It now uses the substrate's `Worker_Base::ipc_partition_args()`, which declares all four retention axes, so the geometry can't drift from the substrate's again.

## [0.2.12] - 2026-07-13

### Added

- **`NodeSchemaArgumentDescriptionsTest` gate** — fails if any node's `node_schema` constructor argument lacks a `description` (the tooltip the topology console shows). `Digest_Builder`'s args already carry descriptions; `Scorer`/`Summarizer` take none. Guards future args.

### Changed

- **Topology + config migrated to the substrate's four-knob retention split** (`newspack-nodes` `min_segments` / `max_segments` / `min_lifetime` / `max_lifetime`, replacing `num_segments` / `max_lifespan`). The `ingest`/`scored` `Partition` make_node lines now pass `<config:segment_size> <config:min_segments> <config:max_segments> <config:min_lifetime> <config:max_lifetime>`, and `digest:log`'s `Log` line inserts `min_segments=2` (`… digest.md 1 2 7`). Behavior-preserving mapping: old retained-count → `max_segments` (`min_segments` at the hard floor 2), old min-age → `min_lifetime`, `max_lifetime` 0. Without this the old count landed in `max_segments`'s slot, disabling count-pruning (unbounded disk growth). `newspack-ai-newsletter-config.php` (and the test config) split their keys to match. No stored-option migration is needed — these keys are config-file-only.
- **Publisher Insights newsletter-card buttons use stock WordPress admin `.button` classes** instead of the bespoke `eai-insights__btn` re-theme. Collect / Regenerate digest are `button button-primary`; Copy markdown / Create draft post are `button`. The `&__btn` (+ `&__btn--secondary`) SCSS appearance block and the now-orphaned `$cobalt-hover` token are deleted; only the `&__actions` layout rules remain.

### Fixed

- **`print_less_often()` call sites split throttle-key from varying payload (consumes the substrate's variadic port).** The four fire-and-forget connector/compose warnings that concatenated a per-call error message into the throttled string — `GitHub fetch failed`, `Linear fetch failed`, `Feed fetch failed`, and `AI digest compose failed` — now pass the stable category prefix as the throttle key with the `WP_Error` / exception message as a trailing `...$extra` payload arg. Previously the varying error text minted a fresh key every call, so a flapping source never throttled (it logged worst exactly when failing most); now one line per category per window. Emitted text is byte-for-byte unchanged.

## [0.2.11] - 2026-07-10

**Requires newspack-nodes ≥ 0.34.0** (the release carrying the `Core` coercion-helper family).

### Changed

- **Coercions fold onto the substrate's new `\Newspack_Nodes\Core` helper family** — `Insights_CI_Node`'s `to_float`/`int_of` onto the strict `num_float()`/`num_int()` (an untrusted JSON score that isn't numeric contributes exactly 0), and the `is_string`/`is_array`/`is_scalar` read idioms in the source nodes, summarizer, digest composer, and prompts onto `str()`/`arr()`/`as_string()` — same semantics, defined once in the substrate this plugin already depends on.

## [0.2.10] - 2026-07-09

### Changed

- **The `DONE` completion sentinel now carries a trailing newline (`"DONE\n"`), so it flushes through a `Log` → `Tail` pipeline immediately.** Written to a `Log` (a line-buffered `Partition`) and read back over `Tail`, a `'DONE'` value with no terminator stalls as an incomplete final line — it only flushes once a later newline-bearing write arrives, so completion could hang indefinitely. Source nodes now emit `TM_INFO "DONE\n"` and `Digest_Builder_Node` matches it in lockstep.

## [0.2.9] - 2026-07-07

### Changed

- **`fill()` takes the message by value** (`array $message`, was `array &$message`), propagating the newspack-nodes substrate contract: each Node subclass owns the message it is given and forwards a value to its sink. Requires newspack-nodes ≥ 0.29.0.

## [0.2.8] - 2026-07-07

### Security

- **Direct-access guard on the first-party PHP files that lacked it.** Added `\defined( 'ABSPATH' ) || exit;` so no plugin PHP file runs on a direct web hit. (`uninstall.php` keeps its stricter `WP_UNINSTALL_PLUGIN` guard.)

### Changed

- **BREAKING: configuration moved from the Settings page into the topology.** The **Settings → AI Newsletter** page is removed; the five pipeline nodes now self-configure via topology `:config` verbs (set in the nodes console or the `.tsl`) instead of the global option store. GitHub/Linear/Summarizer/Digest take `set_vault_id` (a Vault-entry dropdown in the console); GitHub takes `add_repo`, Feed takes `add_url`, Summarizer/Digest take `add_profile` (+ optional `set_api_url`/`set_model`/`set_feature`, defaulting to the AI proxy / `gpt-oss-120b` / `newspack-ai-newsletter`). **Re-enter your connector repos/feeds, vault ids, and digest profile as node verbs** — existing `newspack_ai_newsletter_*` options are no longer read (they remain inert in the DB and are cleared on uninstall). The digest path constant moved from `Settings::DIGEST_PATH` to `Digest_Builder_Node::DIGEST_PATH`.

## [0.2.7] - 2026-07-02

### Added

- **Uninstall cleanup.** Deleting the plugin now removes every `newspack_ai_newsletter_` option row it created (settings + runtime state) and their transient variants, via a prefix-based `uninstall.php`. It runs only on delete (`WP_UNINSTALL_PLUGIN`), never on deactivate, so a deactivate/reactivate keeps all settings; previously these options were orphaned in the database on uninstall. Prefix-based so it stays complete as options come and go and catches `autoload=off` rows a hardcoded list would miss.

## [0.2.6] - 2026-06-30

### Fixed

- Stopped `esc_html()` over-escaping of `Proxy_LLM_Client` error messages. The thrown text is plain text for its log/CLI consumers (and React escapes on render), so runtime escaping only mangled quotes/markup in the logs. Escaping belongs to the view, not the runtime.

## [0.2.5] - 2026-06-29

### Fixed

- Restored the two-column Publisher Insights dashboard layout. The "de-god the dashboard" refactor (splitting the monolith into the `SourceCounts` / `TopTable` / `AccumulatedPanel` widgets) replaced the `eai-insights__layout` grid wrapper with a flat `eai-insights__grid` div that has no CSS rule, so all three cards stacked in a single column. The orchestrator now restores the original grid: the accumulated digest and source counts stack in the left column, the tall per-source Top-items table takes the right. No styling changed — the existing `__layout`/`__side` rules were already in `insights.scss`.

## [0.2.4] - 2026-06-29

### Changed

- **Rebuilt against newspack-nodes 0.24.1**, refreshing the inlined `@newspack-nodes/runtime` + `debug-overlay`: the bundled debug-overlay/console no-node stats header now reads wire-accurate IoTelemetry for browser graphs and no longer spikes its rate sparklines to the cumulative total on a fresh load / shift-reload / worker-switch; `dump_config` takes an optional regex-glob name filter; and the `HttpOut` bytesRead / `RemoteLink` write-byte tallies are corrected.

## [0.2.3] - 2026-06-28

### Changed

- Rebuilt against newspack-nodes 0.22.1: `_http`/`_heartbeat` are now permanent backbone fixtures of `mountExospine` (survive Reset Graph), plus the overlay shell-special/local command dispatch, the accumulating reset-chip `reinitNames`, and the JS class-catalog `arguments` fixes. No ai-newsletter code changes; the dashboards inline the updated substrate JS.

## [0.2.2] - 2026-06-27

### Changed

- Updated the three dashboard view nodes' import path for the shared `SliceViewNode` base to its renamed kebab file (`@newspack-nodes/shared/nodes/slice-view-node`). No behavior change. Rebuilds against newspack-nodes 0.22.0's shared modules.

## [0.2.1] - 2026-06-27

### Changed

- De-godded the Publisher Insights dashboard. The server `Insights_CI_Node` `insights` god verb is replaced by three slice verbs (`counts`/`top`/`accumulated`) built via `Service_CI_Node::slice_verb()` over one memoized scored-snapshot read; the browser graph is now `useBatchedPoll` + three `SliceViewNode` view nodes (`source-counts:view`/`top-table:view`/`accumulated:view`) fed by per-slice Fetchers (one batched POST per tick), and `PublisherInsights` is split into per-slice widgets (`SourceCounts`/`TopTable`/`AccumulatedPanel`) each reading its own view. `generate`/`collect` worker-routing verbs, the rendered digest, collection progress, and per-source top-10 are preserved; the debug overlay stays mounted.

### Removed

- The god `insights:view` view node (`nodes/insightsView.js`) and the single-view-model `useInsightsGraph` path.

### Fixed

- Publisher Insights debug overlay: the page now declares itself on the substrate's `newspack_nodes/devtools_overlay_pages` registry, so ELN's "Request" overlay tab loads here (previously the overlay showed only Overview + Console). Harmless no-op when newspack-nodes / the event logger aren't active.

## [0.2.0] - 2026-06-23

### Added

- Publisher Insights dashboard: the substrate debug overlay now mounts on the page (debug-gated, storage key `newspack-nodes:debug:publisher-insights`), so the `insights:view` browser node graph is inspectable like every other dashboard.

### Changed

- **Credential settings now reference a substrate Vault entry instead of storing a raw secret.** The three credential fields (`ai_proxy_token`, `github_token`, `linear_token`) store a `\Newspack_Nodes\Vault` entry ID, chosen from a `<select>` of vault entries, and the real secret is resolved at use-time via the new `Settings::get_secret()` (reads the entry's `auth_password`). Consumers (`Github_Source_Node`, `Linear_Source_Node`, `Settings::llm_client()`) now resolve through `get_secret()`. The Vault holds the encrypted secret; the plugin's own options no longer do. Falls back gracefully (empty secret, None-only dropdown) when newspack-nodes' Vault class is unavailable.

### Fixed

- Publisher Insights dashboard: the page title now uses the standard WordPress admin heading size (23px / 400) instead of an oversized 32px heading, so it matches the rest of wp-admin.

## [0.1.0] - 2026-06-17

### Changed

- **Regenerate digest delegates to the worker instead of composing in the request graph.** Insights_CI's `generate` verb no longer reads the snapshot and composes itself; it routes a single `TM_REQUEST REGENERATE` to the worker's `digest` node over the input IPC partition (`{base}/ipc/newspack-ai-newsletter.p0/input` — the same request-graph→worker transport `collect` uses) and returns an ack. The worker's `Digest_Builder` (which handles `REGENERATE` on `TM_REQUEST`) composes from its live in-memory items and writes `digest:log`; the dashboard's poll surfaces the new draft. Removes the parallel request-graph compose path (`generate_json` / `read_snapshot_items`), so there's one digest writer. The dashboard's "Regenerate digest" button now shows a "Regenerating…" ack (or a no-worker error) and lets the poll bring in the result, mirroring Collect.

- **The collect pipeline runs through a durable `ingest` partition so the worker keeps heartbeating during a collect.** Sources append fetched items to a new `ingest` partition; an `ingest:consumer` (in `line_mode`) paces them one read-block per drain into Summarizer → Scorer → `scored`. Previously a `TICK` ran the fetch plus every item's blocking LLM enrich in one synchronous pass, freezing the worker's heartbeat (it went `[stale]` mid-collect); the buffer + paced consumer spread that work across drain cycles. Requires newspack-nodes with the Consumer `line_mode` verb.

- **Collect is reachable everywhere and clearly gated.** The Collect button now renders in the empty state too (you need it most when nothing's scored yet), shows `Collected X/3` (0 immediately on click), and is enabled only at a clean boundary — empty (`0`) or complete (`done >= total`) — so it can't double-fire mid-collection; an optimistic in-flight lock self-releases when the poll reflects the new cycle or after a timeout (so a no-op collection can't latch the button). A success ack ("Collecting from N worker(s)…") or a no-worker error now shows in both the empty and populated states.

- **The dashboard's top items are per-source (top 10 each), not one global list.** `Insights_CI::top_by_source` groups the scored items into a per-source top-10; the dashboard renders a ranked table per source (github / linear / feed) instead of one list a single high-scoring source dominated. The model's `top` is now keyed by source (`{ source: [{title, score}] }`).

- **The dashboard is a two-column layout.** The left column holds the KPI stat cards, "By source", and the "Newsletter" actions; the right column holds "Top items by source" as a single stacked column of per-source tables. Widens to use the wp-admin content area. The digest action button is now **Regenerate digest** (the durable digest composes on its own; this recomposes on demand), and the empty preview reads simply "No digest yet." The draft preview is taller (720px) so more of the markdown is visible without scrolling.

- **The digest covers every accumulated item, not just the top 10.** `Digest_Composer` previously sent only the top-10-by-score items into the LLM prompt, so the draft summarized 10 of (e.g.) 30 collected items while the no-LLM fallback already listed them all. It now ranks the whole set by score and sends all of it, with a larger output-token budget to fit the bigger briefing. One cycle's items are bounded (the builder resets per cycle).

- **The AI Newsletter settings page moved to the WordPress "Settings" menu.** It was a submenu under the Publisher Insights dashboard; it now registers under `options-general.php` as "Settings → AI Newsletter". Publisher Insights stays its own top-level dashboard.

- **The Summarizer drops each item's `body` after summarizing it.** The `body` (release notes / PR descriptions / feed content) feeds the summary, but nothing past the Summarizer reads it (the Scorer uses `relevance_score`/`source`/`timestamp`; the digest and dashboard use `summary`/`title`/`score`/`url`). Stripping it there shrinks every downstream message plus the durable `scored` log and the digest snapshot — which previously carried a full body per accumulated item.

### Added

- **Collect button + live collection progress.** The dashboard's Newsletter section gains a **Collect** button that drives a full collection cycle: it sends a `collect` command to `Insights_CI`, which (since the sources live in the worker, not the request graph) writes a RESET then a TICK to each source into the worker's input IPC partition — the same transport `wp nodes cli` uses. Each `Source_Node`, after its fetch, emits a `TM_INFO DONE` (always — even if the fetch throws); Summarizer and Scorer now forward `TM_INFO` so the DONE flows down to the `Digest_Builder`, which counts **distinct sources reported** into its snapshot as `done`/`total`. The dashboard shows "Collected X/total" and gates the buttons accordingly: **Regenerate digest** only enables once every source has reported (`done >= total`), and **Copy markdown** / **Create draft post** only once a digest exists. `collect` replies in JSON (`{collecting,workers}` or `{error}`) so the dashboard surfaces failures instead of guessing.

### Fixed

- **The digest is balanced per source — no source gets crowded out.** `Digest_Composer` previously sent every accumulated item ranked by raw score, so a high-volume source (github) dominated the briefing while linear/feed barely appeared (or didn't). It now selects the top 10 PER SOURCE, so every source is represented in the digest regardless of volume.
- **The Collect button stays disabled for the whole collection cycle.** The optimistic lock released on the first poll tick (or a short timeout), so at `done=0` the button became clickable again mid-collection. The lock now holds until THIS cycle actually completes (a `complete` reading after it's been observed in progress), with a long safety timeout, so you can't re-fire Collect while it's still running.
- **"Create draft post" now uses the block editor's OWN markdown-paste engine.** The hand-rolled `markdownToBlocks` converter is gone, replaced by `markdownToBlockMarkup` which runs the editor's `registerCoreBlocks` + `pasteHandler` (empty HTML + plainText markdown → blocks) and serializes the result — the exact path the editor takes when you paste markdown into it. So a created draft matches "Copy markdown → paste" byte-for-byte, including GFM tables → `core/table` (the custom converter mangled them). Needs the `@wordpress/blocks` + `@wordpress/block-library` runtime scripts (now enqueued via the build kit's externals).
- **The dashboard's status notes no longer linger forever.** The "Collecting from N worker(s)…" and "Regenerating… the draft updates on the next poll." acks had no reliable clear path (the collect lock's timeout released the lock but left the note; the regenerate note only cleared on the next regenerate), so they stuck on screen. Both transient acks now auto-dismiss after a short delay.
- **The digest composes automatically once every source reports DONE — no manual FLUSH.** `Digest_Builder` takes the scored Partition node to nudge plus the source `total` (`make_node Digest_Builder digest scored:partition 3`, where `3` MUST equal `count(Insights_CI::SOURCE_NODES)`) and composes + emits the markdown draft to `digest:tee` → `digest:log` the moment `count(distinct sources reported) === total`. The dashboard reads that durable `digest:log` on every poll, so a generated digest survives a page reload. There is no `request_node digest FLUSH` trigger or manual-flush path — runtime triggers are `TICK` (sources), `RESET` (clear state), and `REGENERATE` (recompose on demand). The `Digest_Builder` still **dedupes accumulated items by id** (cleared on `RESET`, which the dashboard Collect sends before TICKing the sources, and which nudges the scored Partition so the consumer persists the emptied snapshot; a dirty restored snapshot is deduped too), so the same item can't appear twice in a digest.

### Changed

- **The Scorer is now source-agnostic: it blends the LLM `relevance_score` with a recency bonus only.** The old per-source weight table keyed on `releases`/`community` — never the live `github`/`linear`/`feed` sources — so that term silently contributed zero for every real item; it's dropped. The no-LLM fallback (`score()`) stays a flat base plus title-keyword bumps. The relevance judgment lives upstream in the Summarizer's LLM `enrich` call; the Scorer just ranks.

- **The main topology now runs the real connectors, not the stub.** `topologies/newspack-ai-newsletter.tsl` replaces the single `Stub_Source` head with the three live connectors (`github`, `linear`, `feed`) fanned into the summarizer. They emit nothing until configured (`github_repos`/`github_token`, `linear_token`, `feeds`); trigger a source with `request_node <source> TICK` and the digest composes automatically once all sources report DONE (or `request_node digest REGENERATE` to recompose on demand). The canned `Stub_Source_Node` demo source has been removed now that the real connectors are wired.
- **Runtime triggers are now fire-and-forget `TM_REQUEST`s, not `TM_COMMAND` verbs.**
  The source's `tick` and the digest's triggers were dispatched as `TM_COMMAND` verbs
  through a sibling `{node}:config` interpreter (`Schema_Reflection` +
  `auto_wire_interpreter`). Per the Tachikoma convention — `TM_COMMAND` is for
  startup/administration, runtime triggers are `TM_REQUEST` — the source nodes and
  `Digest_Builder` now handle a `TM_REQUEST` directly in `fill()` (TICK / RESET /
  REGENERATE), emit downstream fire-and-forget, and declare the trigger under
  `node_schema()['requests']` so the console renders a request button. Trigger from
  the REPL with `request_node source TICK` / `request_node digest REGENERATE`.

### Added

- **Publisher Insights React dashboard.** `src/dashboard/` is now a real control panel (built to `build/dashboard`, enqueued on the Publisher Insights admin page). It mounts a node graph (`useInsightsGraph`) that polls the `Insights_CI` `insights` verb over the substrate `_http` boundary — page-visibility-gated, interval poll, reply pivot `FROM=insights:view` — and renders the scored model (`{ sources, top, accumulated }`) as KPI stats, proportion bars, and a ranked table. The Newsletter section shows the **real LLM-rendered digest** (`Insights_CI` now also serves the latest `digest:log` content as `model.digest`): **Generate digest** recomposes a fresh one on demand via a new manage_options-gated `generate` verb (awaited request/reply over the graph), **Copy markdown** copies that digest, and **Create draft post** converts it to native Gutenberg blocks (via the editor's paste engine) and POSTs a WordPress draft via `@wordpress/api-fetch`. The shared compose core lives in `Digest_Composer` so the worker auto-compose and the dashboard recompose can't drift. Ported from the `newspack-nodes/examples/example-ai-newsletter` teaching dashboard; the JS test harness gains `@testing-library/react` + `@testing-library/jest-dom`.
- **Nodes publish `set_state` lifecycle observability.** Traced nodes (`debug_state > 0`) now stream their progress to the REPL: the Summarizer emits `SUMMARIZED` (item title) on the LLM-enrich path and `FAILED` (title) when an LLM call errors; the Scorer emits `SCORED` (title); the Digest_Builder emits `RECEIVED` (title) per accumulated item and `COMPOSED` (item count) when it composes the draft. Trace a node to watch items flow.
- **Settings UI — a classic settings page for the AI proxy + connector credentials.** Every `Settings` field now carries a `render` + `sanitize` callback (text/password inputs; one-entry-per-line textareas for the `github_repos`/`feeds` lists), so the substrate `Schema` actually wires them via the Settings API — previously the fields were declared without callbacks and silently skipped, so there was no UI. A new **Publisher Insights → Settings** submenu (`manage_options`-gated) renders the form (`settings_fields` + `do_settings_sections` + save). Secrets render as password inputs; list values sanitize to trimmed, non-empty arrays; `relevance_profile` is a multi-line textarea (newlines preserved via `sanitize_textarea_field`). The list/textarea fields are fixed-width (not full-bleed) and the repo/feed lists show 14 rows. (The React dashboard handles insights *display* separately.)
- **Connector substrate — `Source_Node` base.** A new abstract `Source_Node extends Node implements Source` owns the uniform connector behavior so each connector supplies only `fetch( $config )` (the blocking HTTP call) and `config()` (its Settings read). On a fire-and-forget `TICK` (`TM_REQUEST`) it fetches, dedups by item `id` against an in-process bounded set (`MAX_SEEN = 2000`, oldest evicted), and emits each new item as `TM_STRUCT`. It also provides `normalize_item()` (the shared `{source,id,title,url,body,timestamp}` coercion) and `source_schema()` (the shared Source node_schema) so connectors don't restate either. (Dedup is per-worker-lifetime; durable cross-respawn dedup is a follow-up — it belongs at the ingest layer keyed by id, not in the head source.)
- **Connector — GitHub source.** `Github_Source_Node` fetches Releases, Merged PRs (closed with a `merged_at`), and Issues (the issues endpoint's PR entries are dropped) for every repo in the `github_repos` setting, normalized to the item contract with stable ids (`github:owner/repo#release-11` / `#pr-5` / `#issue-7`). Bearer auth + `User-Agent` when `github_token` is set. A failed repo/endpoint contributes nothing and never throws. Trigger `request_node github TICK`; the `wp_remote_get` call sits behind `Github_Source_Node::$http_get`.
- **Connector — Linear source.** `Linear_Source_Node` POSTs a GraphQL query for recently-updated issues (raw-token `Authorization`, no `Bearer`) and normalizes `data.issues.nodes[]` to the item contract (`linear:ABC-123`). No token → no call; transport error / non-200 / GraphQL-error body → nothing, never throws. Trigger `request_node linear TICK`; behind `Linear_Source_Node::$http_post`.
- **Settings — `github_repos` list + `Settings::get_array()`.** A new `github_repos` connector field (list of `owner/name`) and a list-config reader that returns the stored value as a trimmed, non-empty list of strings (shared by the `feeds` + `github_repos` connectors).
- **Connector — RSS/Atom Feed source.** `Feed_Source_Node` (a `Source_Node`) fetches
  every URL in the `feeds` setting and normalizes both RSS 2.0 (`channel/item`) and
  Atom (`entry`) into the digest item contract `{source,id,title,url,body,timestamp}`.
  The id prefers the RSS `guid` / Atom `id` and falls back to the link, so it stays
  stable across ticks. Atom link selection prefers `rel="alternate"` (the canonical
  URL) over a leading `rel="self"`/`edit`; RSS dating falls back to Dublin Core
  `<dc:date>` when `<pubDate>` is absent. The body is parsed with `LIBXML_NONET`
  (untrusted third-party XML). A feed that transport-errors, returns non-200, or won't
  parse contributes nothing and never throws. Trigger with `request_node feed TICK`;
  the `wp_remote_get` call sits behind the `Feed_Source_Node::$http_get` closure seam.

- **AI core — the Summarizer now calls the LLM.** Each item makes one AI API Proxy
  enrich call (`Prompts::enrich`) returning a one-line `summary`, a 0–10
  `relevance_score` (against the configured relevance profile), and a `reason`. When
  no `ai_proxy_token` is set or the proxy errors/returns unparseable JSON, it falls
  back to the heuristic summary (no score) and never throws. Driven by `Settings::get`
  / `Settings::llm_client` and the new `Prompts` builders.

- **AI core — the Scorer ranks by LLM relevance + recency + source.** When an item
  carries the Summarizer's `relevance_score`, the final score is
  `relevance × weight + recency_bonus(timestamp) + source_weight` (7-day half-life
  exponential recency decay); when it doesn't (Summarizer fell back), the existing
  keyword/source heuristic is used. No LLM call — purely deterministic.

- **AI core — the Digest builder composes an LLM briefing.** Once every source
  reports DONE, the scored items are sent in one AI API Proxy call (`Prompts::digest`) to compose a
  "what mattered" markdown briefing (intro + grouped sections + per-item blurbs).
  No token / proxy error / empty result → falls back to the ranked bullet list. The
  offsetlog snapshot contract (`save_state`/`restore_state`) is unchanged.

- **Foundation of the `newspack-ai-newsletter` sibling plugin** — a team-intelligence
  digest built on the newspack-nodes substrate. This initial drop is the runnable
  pipeline skeleton + the shared seams; live sources, real LLM wiring, the dashboard,
  and publishing land in follow-on sub-projects.
  - Scaffold: build/lint/test tooling (composer + npm + jest + phpunit + phpcs +
    phpstan), the `@newspack-nodes/*` build aliases, release + pre-push wiring —
    mirroring `newspack-event-logger-nodes`.
  - Pipeline spine (ported from the `example-ai-newsletter` walkthrough, contracts
    preserved byte-for-byte): `Summarizer_Node`, `Scorer_Node`, `Digest_Builder_Node`,
    `Insights_CI_Node` (its `{sources,top,accumulated}` JSON model unchanged), plus a
    canned `Stub_Source_Node` so the graph runs end-to-end before real connectors exist.
  - Bootstrap + `topologies/newspack-ai-newsletter.tsl` (the real ingest→summarize→
    score→scored-partition→digest→log graph, stub source wired) + an Insights admin page
    (dashboard enqueue guarded until the React build ships).
  - `LLM_Client` interface + `Proxy_LLM_Client` targeting the Automattic AI API Proxy
    (OpenAI `chat/completions`; default model `gpt-oss-120b`), with a closure-HTTP test seam.
  - `Source` connector interface + `Settings` schema declaring the AI + connector config,
    flagging the credential fields (`ai_proxy_token`, `github_token`, `linear_token`) secret
    via the substrate's `register_args` extension seam.
  - See `dndocker/docs/superpowers/specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md`.

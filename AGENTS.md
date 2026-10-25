# AGENTS.md — Newspack Intelligence

Collects and curates intelligence as an entity graph, built on the
`newspack-nodes` substrate (sibling plugin; `Requires Plugins: newspack-nodes`).
It records GitHub, Linear and feed items as events, links each to the entities
it concerns, extracts a summary, topics and claims with an LLM, and answers
questions about them with citations — on the Intelligence admin page, and to
agents through its MCP door. Nothing is compiled: a person or an agent asks,
and the graph answers.

> The design record lives in dndocker (see [References](#references)); where it
> and the code disagree, the code is the authority.

## Workflow discipline (mandatory)

- **TDD always.** Every code-writing turn (main Claude AND subagents) invokes
  `superpowers:test-driven-development` BEFORE writing code: no production code
  without a failing test first — watch it fail, watch it pass.
- **`/code-review` before every commit** (main Claude only; subagents never commit).
- Conventional commits; update `CHANGELOG.md` `[Unreleased]` on every behavior change.
- Never hand-edit version headers — use `./scripts/bump-version.sh`, which also
  rewrites the substrate pin in `.github/workflows/release.yml` and refuses to
  bump when that substrate version carries no local tag.
- Shared React lives in `newspack-nodes/src/shared` only, consumed via the
  `@newspack-nodes/shared` build alias — never a per-plugin `src/shared/` copy.

**Release the substrate first.** CI builds this bundle against the pinned
`ref:` in `release.yml`, while a local build resolves the same aliases to the
working tree. When the two disagree the build still succeeds and ships older
shared code, so a green Release workflow proves nothing about which substrate
got bundled. Verify by downloading the published zip and diffing its `build/`
against a local one.

## Build / test

```bash
composer install && npm install
npm run build
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npm run lint:types                        # tsc over the JS via tsconfig.check.json
npm run lint:shell                        # shellcheck over pre-push, pre-push.local, scripts/*.sh
npm run lint:deadcode:js                  # knip; GATED in pre-commit (caveat below)
npm run test:js                           # jest (local); test:js:coverage feeds the gate
cd tests && ../vendor/bin/phpunit --enforce-time-limit   # PHP; needs the substrate active
```

`lint:js` is three gates under one name: eslint, `scripts/lint-comments.mjs` (the
80-column inline-comment rule, honoring `@longform`) and `scripts/lint-contract.mjs`,
which fails the build on the substrate ADR violations a correctness review passes
because none of them is a bug — a minted correlation id, a pending-reply registry
or a hook naming a node class by string. `lint:php` pairs phpcs with the same
comment rule. lint-staged adds `scripts/reorder-node-methods.{js,php} --check`,
which orders every top-level class in a staged file: a `Node` subclass takes the
newspaper order, and every other class takes the generic one — the constructor
first, then a topological walk of the call graph. The `includes/**/*.php` and
`src/**/*.{js,jsx}` entries also pass `--all-classes`, which neither twin
recognizes — both drop an unknown `--` token — so those two entries do exactly
what the plain `--check` entries do.

Inside dndocker, PHPUnit runs in the container from the `/services` mount, never
from `/usr/src`, and the static tools run on the host:

```bash
docker exec eve-pyrobase1-1 /services/pyrobase/setup/newspack-intelligence.sh
docker exec -u bend eve-pyrobase1-1 bash -c \
  'cd /services/pyrobase/sources/newspack-intelligence/tests && ../vendor/bin/phpunit --enforce-time-limit'
```

`--enforce-time-limit` is what makes the budget real: `phpunit.xml` sets no
`defaultTimeLimit`, so every test gets PHPUnit's one second, and
`failOnRisky="true"` beside `failOnWarning="true"` turns a breach into a failure
rather than an `OK, but there were issues!` the push ignores. No class buys more
with `#[Medium]` or `#[Large]`, because a test must not wait in real time — it
drives a seam, as this plugin's `Proxy_LLM_Client::$http_post` and the
substrate's `Core::$clock` and `Event_Framework::$sleep` are there for.

After adding or renaming a Node class, regenerate the classmap (`make_node` and
the console palette read it): `composer build:autoloaders` (= `composer
install --optimize-autoloader`) or `composer dump-autoload -o`.

**The substrate floor is a handshake, and a floor set too LOW is the dangerous
error.** The loader calls `Bootstrap::version_at_least( '2.129.0' )` and stays
dormant below it. Against a substrate between a too-low floor and the real one
the handshake passes, the plugin activates, and it then fatals on the missing
method — the one outcome the gate exists to prevent. `CHANGELOG.md` records why
each floor was raised.
`scripts/check-substrate-floor.sh` derives the floor the plugin's PHP calls need
from PHPStan's declaring-class resolution and compares it to the declared one. It
reads PHP calls and not bundles, so when only a bundled dashboard reaches a
substrate API, the maintainer raises the floor by hand. `scripts/lint-docs.sh` fails the push when the
number in this file and the number in the loader disagree. Change both together.

**`@wordpress/*` is pinned to the `wp-7.0` dist tag — never bump it to close an
advisory.** Each runtime package is declared exactly, no caret, and `scripts/lint-wp-pin.mjs` fails the push when a declaration carries a range or the lock resolves it elsewhere. A caret lets every lock climb to trunk while the declaration still reads as the tag, and `npm ci` then builds against the climb. Transitive `@wordpress/*` packages still float within the ranges the family's own manifests declare; the build externalizes those too. Every declared runtime version IS its `wp-7.0` tag: `components 32.2.1`, `element
6.40.1`, `i18n 6.13.1`, `icons 11.7.1` — the newest tag npm publishes for the family,
one minor behind the WordPress 7.1 both containers run. The substrate build-kit externalises `element`, `api-fetch`,
`components`, `blocks`, `block-library`, `i18n` and `data` to the `wp.*` globals,
so npm's copy of those never ships. `@wordpress/icons` is deliberately absent from
that map: it publishes no runtime global, so an import of it bundles the icons it
uses. For an externalised package a bump delivers no new code at all — it moves
the API you compile, lint and type-check against AHEAD of the one the browser is
handed. A component changed or removed between majors then builds clean, passes
eslint and jest, and breaks at runtime, with nothing mechanical to catch it. Check
`npm view @wordpress/<pkg> dist-tags --json` against `wp core version` before
proposing any bump, and move the whole family together only when the WordPress
target itself moves. A Dependabot advisory reachable only PAST the pin gets
dismissed, not bumped. npm `overrides` cannot scope around it, because npm
matches an override by package NAME anywhere in the tree: a top-level override and
one nested under `@wordpress/components` alike downgrade the `@wordpress/*`
packages that legitimately require the newer transitive dependency.

`lint:deadcode:js` (knip) runs in pre-commit on staged JS. knip's jest plugin is
off and tests are excluded as consumers, so any export or module reachable only
from its own test reads as dead — the same rule phpstan-deadcode applies on the
PHP side; mark such an export `@testonly` in the docblock. Most findings are
public API or test seams, not dead code; verify every call path first. knip also
cannot parse JSX in a `.js` file, which drops that file's `import()` expressions:
any `lazy( () => import( './X' ) )` target must be listed as `entry` in
`knip.json` or it reads as an unused file.

Deploy — build the zip first (`npm run release:archive`), then install
`release/newspack-intelligence.zip` however the target site installs plugins.
Every installer, `setup/newspack-intelligence.sh` included, installs the zip that
is already there rather than building one, so a skipped build ships stale code —
and PHPUnit cannot catch it, because the suite runs from the source mount.

### Git hooks

Hooks are the tracked files in `scripts/` (`pre-commit`, `commit-msg`, `pre-push`),
reached via `core.hooksPath`, which `composer install` sets:

```bash
git config core.hooksPath scripts    # what composer's post-install-cmd runs
```

A clone that never ran `composer install` has no hooks. `pre-commit` first runs
`scripts/sync-shared-scripts.sh`, refreshing this plugin's copy of the shared
tooling from `../newspack-nodes/scripts/` when that sibling is checked out — edit
shared scripts THERE, not here. `pre-push` is one of them; this plugin adds no
gate of its own, so its `scripts/pre-push.local`, which the shared hook sources
and the sync never touches, holds only the container constants the hook requires.

`pre-push` runs the JS suite with coverage, its per-file 90% gate,
`scripts/lint-docs.sh`, `scripts/lint-wp-pin.mjs` and
`scripts/check-substrate-floor.sh` on every push, including a docs-only one:
jest can break
from a sibling shared-runtime change this push never touched, and lint-docs is the
grep gate keeping this file in step with the runtime. Everything else is scoped to
the file types in the push range — PHP adds `lint:php`, the container deploy, the
PHPUnit coverage suite and the per-class 90% gate; JS and SCSS add their linter
and a build. The two coverage gates hold every JS file and every PHP class at 90%.

## Architecture

Everything the graph knows is an event on one of two streams. The sources
record items; the resolver, the extractor and the deduper record what they
make of each; the seed, the client import, people and agents record the
ontology and every judgment. The projector replays both streams into one
SQLite Row_Table, which every read queries and the streams can rebuild.

**Streams.** `intel:items` holds `item_recorded` and the other per-item events
for two years (16 MiB segments, both retention rules at 63,072,000 seconds,
a hard cap of 8,192 segments), and only the worker writes it, so it
takes bodies past PIPE_BUF under `void_warranty`. `intel:ontology` holds the
entity events and keeps every segment (`keep_everything`, substrate ADR-37).
`intel:reresolve` keeps a day of `item_reresolve` records, each naming one
stored item.
`Intel_Event_Writer_Node` (`intel:writer intel:items intel:ontology`) addresses
each event by TO to the partition its `make_node` line names for the stream
`Intel_Event::stream_of()` gives its kind, and drops a message naming no kind
with a rate-limited line; it validates nothing, because `Intel_Event::make()`
validated the event at mint and the projector validates it where it reads.
`make()` refuses an ontology event that does not pack into one 4,096-byte
line, because that stream takes no large write.

**Projection.** `projector` replays both streams into the `intel-graph`
Row_Table (`Intel_Graph_Row_Schema`), fed by `intel:items:consumer` and
`intel:ontology:consumer`, which drain in batches and each checkpoint it.
`projections()` declares, per kind, the row ops it writes and where each column
comes from — an event field, a literal, a computed closure, or each element of
a fan-out field — and every value is typed by its column's declared type. Every
op is an `ignore` or a whole-row `set` on a keyed table, and each table is
written by one stream alone, so a replay writes what it wrote before and the
streams project the same rows in either order. The projector never reads
before it writes: an entity is three rows — identity in `entities`, state in
`entity_states`, merge in `entity_merges` — and an item's extraction sits in
`extractions` beside its `items` row, which is written once. A link, claim,
judgment or topic may name an entity no `entities` row holds yet; readers join
to it. A machine's `entity_proposed` writes with `ignore` and a person's
`entity_created` with `set`, so a machine never overwrites a person.
Item-scoped tables keep 730 days by an indexed day column, the item's
retrieval `floor( retrieved_ts / 86400 )`, which every item-scoped event
carries — a supersession its canonical's — so an item and every row about it
expire together however late a re-resolution writes them; the ontology and
curation tables declare `keep`. Ops buffer and leave as
one `ROWS` batch every 5 seconds, at 2,000 ops or 1 MiB of string values, at
each checkpoint and on a clean stop; a failed batch raises a plain stop and
keeps the buffer, so the cursor replays it. A message that carries no valid
event throws out of `fill()`, so its Consumer dead-letters it and reads on.
`Url_Canon::of()` and `Entity_Index::norm()` compute the canonical URL, the
title norm and each name's norm the readers match on.

**Resolution.** `resolver`, fed `item_recorded` events by its own
`intel:items:resolve` Consumer, which skips every other kind on the stream
and, like `intel:reresolve:resolve`, hands it one record a poll
(`set_line_mode true`), links each item to the entities it is about. `Resolver` holds the order:
identifiers in the item's URL (host by suffix walk, GitHub org and repo,
LinkedIn company, X handle, a Linear issue's team) at 1.0; names and aliases
as whole words in title and body at 1.0, an acronym only in its own case at
0.9; a domain's stem in the title at 0.9, when it runs at least
`MIN_STEM_LENGTH` (6) characters, aligns to word boundaries, capitalizes
every word and is not led by an article, and never in a body, which is raw
markup where an href spells a domain; and only when those find nothing, the NER extractor's organizations fuzzy-matched
against every name — asserted at 0.85, proposed at 0.60, below that a
candidate entity (at most `MAX_PROPOSALS`, 3, and never a name a rejected
entity carries). A name whose norm the index holds exactly scores 1.0 with no
scan; otherwise only names whose byte length can reach the proposing band
are scored, because similar_text's ratio never passes 2·min/(sum). Name and stem hits whose spans overlap in one text settle
together: the longest span wins and a shorter hit drops, while equal spans
naming several entities assert the one whose locality the text also names,
written capitalized, at 0.9, else propose each at 0.5. A locality never
drops a name. An entity
found in the URL or title is the subject, one found only in the body a
mention, and each entity keeps its strongest link. `Entity_Index` reads
accepted and proposed entities off the projection through
`Intel_Graph_Row_Schema::read()`: names by norm, identifiers by `kind:value`, a
merged entity's names and identifiers answering its survivor; an entity
whose merge chain cycles or runs past 10 hops is left out, logged once, and
the rest loads. A link to a proposed entity is `link_proposed` at whatever
confidence its method earned, so a machine's guess never asserts.
`Resolver_Node` writes a
`link_asserted` or `link_proposed` per link and an `entity_proposed` per
candidate, keyed `Intel_Event::entity_key()`, to the writer its `make_node`
line names, by TO and declared as an extra target, then forwards `{ item,
links, proposals }` to its target, `extractor`. An `item_reresolve` record,
fed by `intel:reresolve:resolve`, names a stored item, which it reads from
the projection with whether the projection holds an extraction a call
made — a `machine:extract-heuristic@…` stand-in does not count, matched
through `Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE )`.
An item the projection no longer holds is dropped with a line, and a
projection that will not open throws, so the record dead-letters. A
re-resolution runs the first three steps alone, since NER ran on first
resolution, writes its links, and forwards the item only when no such
extraction is projected, so an item is extracted by a call at most once and
an item a proxy outage left heuristic is extracted again. `Resolver::resolve(
$item, $ner )` takes the NER step per call: the node builds it from its
`LLM_Config` state for each recorded item, so a runtime `set_vault_id` or
`set_api_url` reaches the next one, and passes none on a re-resolution; the
`Resolver` itself is rebuilt only when the index is. Every link event carries the item's
`retrieved_ts`. The resolver and the extractor share the process's one
`Entity_Index::current()`, read again every `Entity_Index::TTL_S` (60
seconds) and when an `item_reresolve` record's `fresh_after` falls after its
last read, so one signal's records cost one read; with no `intel-graph`
table it answers no entities and logs once. Every projection read goes
through `Intel_Graph_Row_Schema::read()`, which given a line logs a
projection that will not open and answers null, and given none throws. An `item_recorded` it cannot read
throws, so the Consumer dead-letters it. A topic is never matched in text:
the index offers each live topic by its canonical name through `topics()`
and indexes none of its names.

**Extraction.** `extractor` skips a resolution with no link and no
candidate; for any other it makes one `chat()` call through
`Prompts::extract()`, which carries the item as JSON (tags hex-escaped)
inside an `<item>` fence the system message names as untrusted and offers
the index's topic names. `Prompts::parse_extract()` reads the first `{…}`
block through `Prompts::json_object()`, which the NER step shares, cuts the summary to 280 characters, reads an unknown kind as `other`,
clamps the scores and keeps at most five claims with a predicate from the
closed set. It writes an `extraction_recorded` naming topic keys, and a
`claim_asserted` per claim whose subject the item links by an asserted
link, taking that link's confidence; a subject linked only by
`link_proposed` asserts no claim. Subject and object resolve through every
entity whose name spells the whole of them in `Entity_Index::names_in()`:
the subject is the one of those the item asserts, and the object is the
only one, or the only one the item links, else it stays `object_text`, so
two entities sharing a name never resolve by load order. No client, a thrown
`RuntimeException` or a reply that does not parse records a heuristic
extraction instead — the first sentence of the title and of the body, kind
`other`, no topics, scores 0 — as `machine:extract-heuristic@<version>`, so
the item is never retried in a loop; a later re-resolution extracts it
again. Either way it then
forwards an `item_extracted` struct `{ item, relevance, importance }` to its
target, `deduper`; it is a struct between two nodes, not an event kind.

**Dedup.** `deduper` finds an earlier copy of each extracted item: the same
`Url_Canon::of()` URL, or else the same `Entity_Index::norm()` title when that
runs at least `MIN_TITLE_NORM` (24) characters, within `WINDOW_DAYS` (14) of
the earlier copy. An item's time is its `item_ts`, or its `retrieved_ts` when
undated: `Intel_Graph_Row_Schema::ITEM_TIME` in SQL, `item_time()` in PHP. A newcomer meeting a singleton founds a cluster keyed by the earlier
item's `item_key`: a `cluster_joined` for each, then a `cluster_superseded`
naming the higher-ranked as canonical, a tie keeping the earlier. A later
newcomer writes `cluster_joined`, and `cluster_superseded` only when it
outranks the canonical; while the cluster's canonical is unknown, as when
its members are projected and its canonical is not yet, it only joins. An
item already placed is never placed again: its own LRU entry, else its own
`clusters` row, is looked for before any copy, and re-delivered it only
supersedes its own cluster's canonical once it outranks it. A canonical
re-delivered at a higher rank supersedes itself to record it; at a lower one
the cluster passes to its best-ranked projected member, a tie keeping the
earlier item. Rank is
relevance plus importance. Supersession is by event, as `machine:dedup@<version>`; nothing
edits an earlier row. Lookup reads a bounded `LRU_Cache` (`LRU_SIZE` 5,000
entries) first — each spelling, keyed by a 16-hex md5 of it, naming its item,
time, cluster and retrieval, and each `cluster:<key>` naming the canonical, its rank and
whether the cluster was founded, both as positional lists — and the
projection on a miss: the extracted copy at the same URL, else the nearest
in time by title. A cluster the LRU no longer holds is read again from
`canonicals`, and a cluster held again replaces its one entry. The resolve
Consumer checkpoints the LRU with its cursor (`add_snapshot_node deduper`),
so a respawned deduper resumes exactly what it had decided, and a miss
reaches only items older than the LRU's horizon; a full LRU checkpoints
about 460 KB, most of it the item keys it names; an entry whose item
stands in its own cluster stores no cluster key. With no `intel-graph` table a miss finds nothing, logged.

**Re-resolution.** `reresolver`, which the composed graph builds, takes the
ontology stream through `intel:ontology:tee`, the Tee between
`intel:ontology:consumer` and the projector, and queues what an event can
link anew: an `entity_created` or `name_added` its name, an
`identifier_added` the URL spellings its kind has (`IDENTIFIER_URLS`), an
`entity_accepted` every name and identifier the projection holds for the
entity, and a new locality the entity's own names, because a locality settles
an ambiguous name and links nothing alone. A topic queues nothing, and a
repeated signal is queued once. Each Router tick appends `item_reresolve`
records, each naming a stored item, to `intel:reresolve`, a Partition
keeping a day of records under PIPE_BUF. Its own Consumer,
`intel:reresolve:resolve`, feeds `resolver` one record a poll, so the tick
never runs resolution and extraction itself, and an item whose resolution
throws dead-letters alone (ADR-12). A tick takes the oldest signal at least
`SWEEP_INTERVAL_S` (30) old, past the projector's 5-second flush; only with
no signal due does a requested range send its next `RERESOLVE_PAGE` (20).
Each record carries `fresh_after`, its signal's time plus
`SWEEP_INTERVAL_S`, or 0 for a range. A signal searches one
quoted FTS5 phrase per name over `items_fts`
(`Intel_Graph_Row_Schema::fts_phrase()`, its `"` doubled) and a LIKE per
URL pattern, anchored to a whole host (after `://` or `.`, before `/`, a `:`
port, `?`, `#` or the end) or a whole path segment (before `/`, `?`, `#` or
the end), with `%`, `_` and `\` escaped. FTS folds case, so an acronym —
a `name_added` of kind `acronym`, or an accepted entity's acronym — also
needs a case-sensitive `GLOB` on the title or body (`*`, `?` and `[`
bracketed), as the resolver matches an acronym only in its own case. It appends its newest `MAX_ITEMS_PER_SIGNAL`
(500) hits at once, newest first, and only on reaching that cap counts the
rest, to log how many it skipped. `wp
newspack-intelligence ontology reresolve --since=<Y-m-d> [--until=<Y-m-d>]`
validates the days, then sends `RERESOLVE <since> <until>` through
`Worker_Route` to each live worker's `reresolver`, reporting how many it
reached; the reresolver answers the range it queued, or the refusal, and
sends every item dated in those UTC days, an undated one by its retrieval,
partition by partition in row-id order. The queue checkpoints with
`intel:ontology:consumer` (`add_snapshot_node reresolver`), each unit by its
key, `<kind>|<value>`, with when it was queued and a range's cursor, and an
entry the checkpoint cannot read, or a checkpoint holding no queue, is
dropped and logged; a signal leaves the queue once swept, a range once a
page comes back short, and either waits, logged, while the projection will
not open. Any other failure of a unit's projection
read drops that unit with a rate-limited line naming it and the cause, so a
respawn cannot restore the same head and fail on it again; a cooperative stop
propagates first. `Intel_Graph_Row_Schema::$read_rows` is the read's test
seam. Both `ontology` verbs and `clients import` refuse to run as root
before they write, through the `Refuses_Root` trait, as `wp nodes` verbs do,
because a root-owned segment or IPC file is one the workers cannot append
to.

**Reads.** `Intelligence_CI` (`intelligence`), mounted on every request
graph, answers eight verbs, each declaring `read` and its
args: `search`, `entity`, `timeline`, `related`, `claims`, `trends`, `review`
and `collections`. Each handler hands its bound args to the `Graph_Query`
read of the same name, through `Intel_Graph_Row_Schema::read()` over every
partition's file. A read opens on `Graph_Query::graph()`, which resolves
the tiers once from `Intel_Tiers::readable()` — `public` and `internal` —
and binds the prelude every statement opens with: `mg`, the survivor map,
and `ri`, the items of those tiers. Every statement reads items through
`ri`, never `items` with a filter of its own, so no item, link, claim,
count or citation rests on an item of another tier, and an entity short of
`accepted` — proposed, or rejected — answers `entity` only while a readable
item cites it, by a link or as the item it was proposed from.
`Graph_Query::visible()` is that rule, and `seen()` refuses what it hides
as `no entity: <key>`; `entity`, `timeline` and every curation verb resolve
their entity keys through it. Names, identifiers and roles carry no
tier and are not filtered. A key a read names, and every key a row names,
answers as its survivor in `Entity_Index::survivors()`, the one survivor
map the index load also reads, built with its inverse once a read in
`graph()`; a key whose chain cycles or runs past
`Entity_Index::MAX_MERGE_HOPS` is refused, and its rows count nowhere. Links read through the `live` CTE as
one per item and survivor, a topic link apart from the rest, subject over
mention; a link's state is the highest-ranked judgment's (`JUDGE_RANK`: a
person over an agent), else the machine's, and a `rejected` link counts
nowhere. Each read hands `live()` the links it rests on — an entity's keys,
a page's items, the items a claim or a proposal names — and windows only
those; `trends` with no `entity` reads every link in range. Summaries, kinds and scores come from `extractions`,
names from a join on `entities`. `q` reaches FTS5 as one `fts_phrase()` per
word over title, body and summary, and a word holding no letter or digit
matches as an empty phrase, which FTS5 ignores; every other value binds,
and a blank optional arg is a value read as given (substrate ADR-25), so a
blank `q` or `source` matches nothing. Days parse through
`Intel_Graph_Row_Schema::range()`. No answer carries a body; `limit` is
capped at `Graph_Query::MAX_LIMIT` (200); trend rows, related entities and
proposed entities cite their five newest items, and items and claims their
URLs. A projection that will not open answers `available: false` beside
empty rows, logged; one no partition has written answers empty rows.
A collection's saved items read from `collected` alone, at the tier each
was saved at, so a saved item outlives its expiring `items` row; `collected`
keeps its own tier filter, and `collections` opens no graph.

**Curation.** `Intelligence_CI` also answers thirteen curation verbs, each
declaring `tune`, each writing one ontology event through
`Ontology_Writer::append()` and answering `{ ok, event_id }`; the two
creations add the key they minted. `Graph_Curation` holds them:
`confirm_link` and `reject_link` (carrying the item's URL), `accept_entity`,
`reject_entity`, `merge_entities`, `add_name`, `add_identifier`,
`assert_role`, `end_role`, `tag_topic`, `create_entity` (keyed
`Intel_Event::entity_key()`), `create_collection` (keyed
`Intel_Event::collection_key()`, the same slug) and `collect` (the item's
URL, its title and its tier). The actor is `Intel_Event::caller()`:
`agent:<login>:<label>` when `Capabilities::$session_label` is non-empty,
as for an MCP connection or an issued session, the label as
`Sessions::clean_label()` bounded it, else `user:<login>`; a call with no
signed-in user is refused before it writes. Every refusal throws before
anything is written, and no handler checks a role (substrate ADR-26).
Each verb reads the projection once. Every entity key it names — both
sides of a merge, a link's entity, a tagged topic — must be one
`Graph_Query::seen()` lets the caller see, any other key is refused as
`no entity: <key>`, and the verb acts on the survivor it answers as, so a
key merged away accepts, merges or tags its survivor; a curation verb
answers no more than a read does. An item is read through `ri`, and one
outside the readable tiers is refused as `no item: <key>`, exactly as a
missing one. A judged link must exist: `confirm_link` and `reject_link`
refuse `no link: <item> <entity>` unless a `links` row, of any role, joins
the item to one of the entity's keys. `tag_topic` refuses a topic whose
survivor is not of type `topic` (its `entities` type, else its key's). A
merge into itself or across types — the two survivors' types, read the same
way — is refused, and so is any merge after which `Entity_Index::walk()` — over the
projection's merges, read by `Entity_Index::merges()`, plus the new one —
leaves a key that resolved before on a chain that cycles or runs past
`MAX_MERGE_HOPS`, naming the first such key. `add_identifier` stores the
value as `Entity_Index::identifier_value()` spells it, reads the holder by
the `identifiers` primary key, and refuses one another entity holds: the
holder's survivor, accepted or proposed, or the holding key itself when
its survivor is rejected or its chain does not resolve. `create_entity` refuses
a key `Graph_Query::taken()` finds taken — visible and no longer a
machine's `proposed` — and over any other key, a proposal or one the caller
cannot see, writes `entity_created` as it would for a new key; `create_collection` refuses a taken
collection key. A role counts as held when any key resolving to the
entity's survivor holds it open, as `Graph_Query::open_roles()` reads it:
`assert_role` refuses it then, and when any of those keys holds a row of
the role from this very second, because a role row is keyed by its
`from_ts` and a new one would overwrite it (`try again in a second`);
otherwise it opens it on the survivor from `Intel_Event::now()`, the tick
clock's second, and `end_role` closes the latest open row now, on
the key that holds it, and refuses when there is none. Every string is
bounded first: a key, a name or a value that trims to nothing is refused,
and so is one past its cap in bytes as the stream writes it, JSON-escaped —
keys at `Graph_Curation::KEY_BYTES` (320), item keys at `ITEM_KEY_BYTES`
(512), names and values at `NAME_BYTES` (256) and URLs at `URL_BYTES`
(1,024) — while a collected title is cut at `TITLE_BYTES` (512) on a
character boundary, so every event fits one 4,096-byte line whatever
characters fill it. `fetch_now` TICKs every source in each live worker
through `Worker_Route` and declares `manage`, because it mounts a worker's
input (substrate ADR-23). `Worker_Route` appends each request to every live
worker's input IPC partition — durable and synchronous, with no live-worker
dependency on read — carrying the CI's own name as FROM, which the worker's
input reader stamps under `_repl`, so each source's answer lands on the
worker's output partition, where an attached `wp nodes cli` reads it.

**MCP door.** `Intelligence_Mcp_Door`, wired by the loader, is a declaration
on the substrate's `Mcp_Door` (substrate ADR-38), serving
`POST /wp-json/newspack-intelligence/v1/mcp`. Its tools are read off
`Intelligence_CI_Node`'s schema, one `intel_<verb>` per verb but
`fetch_now`, which it leaves out by name, each offering every declared arg
under the verb's own description. The door owns the session check, the
scope ceiling, the meter, the OAuth resource and the `<site-data>` fence,
and offers a session only the tools its role covers, so a read session is
offered the reads alone and every answer passes the reads' own tiers. Each
read's summary names the 730-day item horizon,
`Intel_Graph_Row_Schema::RETAIN_DAYS`, filled as `{horizon}`. The glossary
lists the entity types and link roles from `Intel_Event::TYPES` and
`LINK_ROLES`.

**Seeding.** `wp newspack-intelligence ontology seed` writes
`Ontology_Seed::events()` — thirty topics, the partners, funders and
industry bodies, and Newspack's products, one entity per table row — for
the keys `Ontology_Seed::present()` does not find settled in the
projection's `entity_states` — any state but a machine's pending
`proposed` — so an entity a curator has accepted, rejected, merged or
renamed is never reset, while a machine's proposal of a seed key is
replaced by the seed's creation; it prints how many keys were new and how many present, and a
projection that will not open fails it with `Table_Unavailable` rather than
writing everything. It writes through `Ontology_Writer::append()`, the
request-side writer: a transient Partition named `intel:ontology:writer`,
built from the `intel:ontology` declaration in
`newspack-intelligence-streams.tsl` read through `Topology_Analyzer` — its
directory and its arguments — then filled, flushed and removed in a
`finally`, so a failure propagates and a second append finds the name free.
The declared `keep_everything` is honoured through
`Partition_Node::set_keep_everything()`, never replayed as a command,
because a command's role gate judges the caller and would refuse a curator
below MANAGE; any other `intel:ontology:config` line is refused by name. A
writer missing `keep_everything` would prune the kept-forever stream on
rotation, which is why nothing copies the declaration. With the worker and
these appends both writing it, `intel:ontology:consumer` runs
`set_multi_writer true`, so it seals a segment only after the grace a
shared log needs. Every machine stage mints through
`Intel_Event::machine_event()`, which stamps `machine:<stage>@<plugin
version>` and that version as the `config_version`; `make()` stamps the
tick clock, which the substrate pins at class load.

**Collect.** The connector **Source nodes** (`github`, `linear`, `feed`) fetch on
a `TICK` request inside the background worker and emit one `item_recorded` event
per new item to `intel:writer`. Each records at the tier its `make_node` line
names (`make_node Feed_Source feed public`), which a load without one refuses
and `set_tier` replaces at runtime. Blocking HTTP runs in the
worker rather than a per-fetch job. `Source_Node` dedups by `item_key` against a
bounded in-memory set (`MAX_SEEN` 2000, oldest evicted first) that does not
survive a respawn, so a fresh worker records an item again.

`Github_Source_Node` hits three REST endpoints per repo on each TICK — releases,
closed pull requests and issues — keeping a closed PR only when it carries a
`merged_at`, and dropping any issue carrying a `pull_request` key, because the
issues endpoint lists PRs too. Each endpoint takes the 10 most recent results and
pages no further, so an update that falls past that window between ticks is
missed outright. A transport error, a non-200 or an undecodable body makes that
one endpoint contribute nothing and never throws, so one bad repo cannot sink the
batch; a rate-limited `print_less_often()` line is the only record, which makes a
repo going quiet across many ticks the rare place here that fails quietly rather
than loud.

**Seams and the LLM contract.** LLM calls go through the Automattic
AI API Proxy via `LLM_Client` / `Proxy_LLM_Client`, whose `$http_post` closure is
the test seam. More follow the same pattern, each a static `?\Closure`
replacing ONE call so the branches around it still run as production code:
`Github_Source_Node::$http_get`, `Linear_Source_Node::$http_post` and
`Feed_Source_Node::$http_get` for the connector fetches;
`LLM_Config::$llm_factory`, which every node using the trait holds its own
copy of (`Resolver_Node::$llm_factory` and `Extractor_Node::$llm_factory`)
and `make_llm_client()` answers first; and
`Intel_Graph_Row_Schema::$read_rows` and `Resolver::$similar_text` for the
projection read and the fuzzy step's scoring.
Both callers of `LLM_Client::chat()` — `Extractor_Node::fill()` and
`LLM_Entity_Extractor`, the resolver's NER step — re-throw
`Worker_Should_Stop` first, because a cooperative stop is a
`\RuntimeException` too and must stop the worker rather than read as a
failed call (substrate ADR-14), then catch `\RuntimeException` and nothing
wider. `Proxy_LLM_Client` throws only that, so a second implementation
raising any other `Throwable` propagates out of `fill()` and breaks the Node
contract that `fill()` never throws. Bearer tokens are
substrate Vault entries: the node config holds only the entry id, set by the
`set_vault_id` setter `Vault_Secret` declares and resolved at use time by its
`vault_secret()`.

### The item contract

`Source_Node::normalize_item()` mints every item as the `item_recorded` fields
`{item_key, source, tier, url, title, body, item_ts, retrieved_ts}`: `item_key`
namespaced `"{source}:{raw id}"`, the key dedup reads; `body` cut at
`MAX_BODY_BYTES` (65,536) on a UTF-8 character boundary; `item_ts` 0 when the
connector's date string will not parse; `retrieved_ts` the worker's tick
clock in whole seconds, `Intel_Event::now()`, as every reader takes it.
`tick()` wraps each in `Intel_Event::machine_event()` under the actor
`machine:source:{name}@{plugin version}`, with the plugin version as its
`config_version`.

### Topologies

| File | What it wires |
|------|---------------|
| `newspack-intelligence.tsl` | Includes every file below and wires `reresolver`, which spans the project and streams files; `on_demand_idle 0` keeps the worker resident, which the TICK-driven sources need, and `stale_timeout 600` keeps a peer from stealing its lock while a TICK's fetches or an LLM call block one `fill()` |
| `newspack-intelligence-streams.tsl` | `intel:items`, `intel:ontology`, `intel:reresolve` and `intel:writer` |
| `newspack-intelligence-collect.tsl` | The three sources → `intel:writer`; includes the streams |
| `newspack-intelligence-project.tsl` | `intel:items:consumer` → `projector`, and `intel:ontology:consumer` → `intel:ontology:tee` → `projector`, writing the `intel-graph` Row_Table |
| `newspack-intelligence-resolve.tsl` | `intel:items:resolve` and `intel:reresolve:resolve` → `resolver` → `extractor` → `deduper`, each writing its events to `intel:writer`, the items Consumer checkpointing the deduper; includes the streams |

### Nodes

`Consumer`, `Partition`, `Tee` and `Row_Table` come from the substrate. This plugin adds:

| Type | Class | Runtime triggers and verbs |
|------|-------|----------------------------|
| `Github_Source` | `Github_Source_Node` | `<tier>`; `TICK`; `set_tier`, `add_repo`, `remove_repo`, `set_vault_id` |
| `Linear_Source` | `Linear_Source_Node` | `<tier>`; `TICK`; `set_tier`, `set_vault_id` |
| `Feed_Source` | `Feed_Source_Node` | `<tier>`; `TICK`; `set_tier`, `add_url`, `remove_url` |
| `Intel_Event_Writer` | `Intel_Event_Writer_Node` | `<items_partition> <ontology_partition>`; no verbs |
| `Projector` | `Projector_Node` | `set_graph_target` |
| `Resolver` | `Resolver_Node` | `<writer>`; the `LLM_Config` verbs |
| `Extractor` | `Extractor_Node` | `<writer>`; the `LLM_Config` verbs |
| `Deduper` | `Deduper_Node` | `<writer>`; no verbs |
| `Reresolver` | `Reresolver_Node` | `<stream>`; `RERESOLVE <since> <until>` |
| `Intelligence_CI` | `Intelligence_CI_Node` | `search`, `entity`, `timeline`, `related`, `claims`, `trends`, `review`, `collections`, each at `read`; `confirm_link`, `reject_link`, `accept_entity`, `reject_entity`, `merge_entities`, `add_name`, `add_identifier`, `assert_role`, `end_role`, `tag_topic`, `create_entity`, `create_collection`, `collect`, each at `tune`; `fetch_now` at `manage` |

The `LLM_Config` verbs are `set_api_url`, `set_vault_id`, `set_model`,
`set_feature`, `add_profile` and `remove_profile`; every node carrying the trait
also re-emits its state through `dump_config()`. An identical `add_*` repeat is
a no-op, and each `remove_*` takes its `add_*`'s argument, drops that entry and
is refused when none is. Every `Intelligence_CI` verb declares its role, and
no handler checks a role of its own (substrate ADR-26).

### Client roster

`Client_Importer` reconciles a client CSV against a `Client_Roster`. An
import is a reconciliation against a full snapshot, reported as four
disjoint counts. A CSV id absent from the roster is `created`; a present one
has its atomic fields updated and counts as `reactivated` if it was churned
and `updated` otherwise; an `active` id the roster's `statuses()` holds
that the CSV lacks is `churned`. The importer then calls the roster's
`commit()` once. `CSV_Parser` reads the
columns as `Atomic site ID, Created, Domain name` and returns null — never
`[]` — for a file whose first non-blank line is not that header or that
yields no valid rows, because an empty snapshot would churn every client.
`Client_Importer` refuses an empty row list for the same reason, and
commits nothing. `total_in_csv` counts DISTINCT `atomic_site_id` values
rather than rows, so a snapshot repeating an id reports fewer than its line
count while still processing the repeat — the second appearance finds the
client the first one created and lands in `updated`.

`Graph_Client_Roster` keeps the roster in the entity graph. A client is an
organization carrying its `atomic_site_id` as an identifier and an open
`client` role; a churned one carries an open `former_client` role instead,
and reads as `churned` whenever no `client` role is open. Its first call of
an import reads the projection through `Intel_Graph_Row_Schema::read()`
given no line, so a projection that will not open throws `Table_Unavailable`
rather than reconciling against an empty roster and re-creating every
client. That one read is `Entity_Index::load()`, whose
`identifiers( <kind> )` and `refused( <kind> )` give the atomic and domain
identifiers it holds and leaves out, plus `Graph_Query::open_roles()` over
every key of each survivor holding one, through `merged()`; so every
identifier answers the survivor of any merge, and an open role held under
any key merged into a client's entity counts as the client's. A client the roster lacks resolves first by its atomic id,
else by its domain, to the entity `by_identifier()` answers, which gains
the atomic id and a `client` role; only a client found by neither creates
`organization:atomic-<id>`, named by its normalised domain, or
`Atomic site <id>` when it has none; a row naming no atomic id is refused. A domain is added as an `identifier_added` only when no
entity holds it, so an import never moves a curated or seeded entity's
domain, and it renames nothing. One entity may carry several atomic ids,
one per site a publisher runs, and churns once, only when none of them is
in the snapshot. A churn writes `role_ended client` on the key each open row is
held under, from that row's `from_ts`, and `role_asserted former_client` on
the survivor; a return is the reverse. `Entity_Index::load()` leaves out an
identifier whose survivor is rejected or whose merge chain cycles or runs
past 10 hops, and `held_by_refused()` names the key holding it. A client
whose atomic id such an entity holds — rejected, merged into a rejected
survivor, or on a broken chain — is left alone: no event, counted
`updated`, never churned. So is a new client whose domain such an entity
holds, and a domain one holds is never added to a client. Every entity left
alone is named in one stderr line per import. An event that will not mint
throws naming its row's atomic id.
Each role event dates from the start, UTC, of the import's day. Identifier
values go through `Entity_Index::identifier_value()`. `commit()` appends
the import's events in order through one `Ontology_Writer::append()`, then
forgets what it read, so the next import reads the roster again. The roster
takes its actor at construction: `Intel_Event::machine( 'client-import' )`
from `wp newspack-intelligence clients import <csv>`, and
`Intel_Event::caller()`, the signed-in user, from the CSV upload on the
Settings page. Both build their importer and roster per import, so a
failed import's read and buffered events never reach the next. The command
refuses to run as root before it reads the CSV, as the `ontology` verbs do;
the upload runs as the web user.

### Dashboard

`src/intelligence/` builds one bundle, `build/intelligence/index.js`, mounted on
the Intelligence admin page. Every view reads the `intelligence` verbs through
its own one-shot commands; nothing polls.

| Module | Export | What it does |
|--------|--------|--------------|
| `index.js` | — | Mounts `IntelligencePage` on `#newspack-intelligence-app`, handing it `canTune` and `vocabulary` from `NewspackNodesData` |
| `IntelligencePage.js` | default | Switches Search, Entity, Review and Collections in place; holds the submitted search, the open entity and collection, and the item being saved, so a view returns as it was left; renders inside the shared `DashboardShell`, which supplies the skin, the page surface, the `NEWSPACK::NODES` header subtitled `Intelligence` and the debug overlay |
| `hooks/useIntelCommand.js` | `useIntelRead`, `useIntelWrite` | A read asked whenever its arguments change, showing only the answer to the question it asks now, or a refusal that echoes no arguments; a write sent once per `run()`, running `refresh` once it lands and handing back `useCommandOnce`'s own `pending`, refusal and answered arguments |
| `views/Search.js` | default | `search` with words, kind, topic, source and a day range, a page at a time |
| `views/Entity.js` | default | `entity`, `timeline` a page at a time, `related` and `claims`; once the record answers, for a curator, `add_name`, `add_identifier`, `assert_role` and `end_role` |
| `views/Review.js` | default | `review links` and `review entities`; for a curator, `confirm_link`, `reject_link`, `accept_entity`, `reject_entity` and `merge_entities` |
| `views/Collections.js` | default | `collections`, listed and opened; for a curator, `create_collection` |
| `components/SaveToCollection.js` | default | The one dialog any result saves through, sending `collect` |
| `components/Answer.js`, `Refusal.js` | `Answer`, `AnswerBody`, `Refusal` | One read in exactly one state — owed, refused, unavailable (one sentence for every read), empty by its `rows` key, or its rows — as a stock `Card` section named by an optional title, which heads its `CardHeader`, with the state inset in its `CardBody`, or bare inside a dialog; the one alert every refusal, read or write, renders in |
| `components/Pager.js` | default | How many of the matches a page shows, and Previous and Next over a stack of page starts, for Search and the Entity timeline |
| `components/ItemRow.js`, `Cite.js`, `format.js` | — | An item row; a citation linking only an http or https URL, and `EntityChip`, the one chip opening an entity; the UTC day and `metaLine()`, which joins a row's meta parts and keeps a 0 |
| `styles/intelligence.scss` | — | Geometry only, emitted as `build/intelligence/index.css` |

Each read is a `useCommandOnce` read with `retry`, under the `intelligence`
group, so `connect intelligence:shell` watches every send; its scope names
what the slice shows (`entity:record`, `review:links`, `collections:one`), so
two reads of one verb never share a Fetcher. A write is one `useCommandOnce`
per verb, held by its view rather than by a row, sending every arg named
(`--key=…`, through `formatCommandArgs`) so a curator's value beginning
`--name=` binds as a value, and a write that lands
re-reads the slice it changed. No send names a subject: an item key spells a
whole URL, past the length a reply address carries, so pending is per verb
and a queue's rows hold while any judgment of it waits, a merge included.
The view switcher wears the shared tab roles (`nodes-tab-host__tabbar`,
`nodes-tab-host__tab`). `canTune`, which `enqueue_intelligence_assets()` sets
from `Capabilities::can( TUNE )` on the page's own hook suffix alone,
only hides the curation controls: the server's role gate refuses a write the
caller may not make. The vocabulary the selects offer is `Intel_Event`'s own
lists, localized beside it. Every string from the graph renders as text, an
item URL becomes a link only when it is http or https, and `Answer` gives an
empty answer and an `available: false` one different words. The page
renders inside the substrate's shared `DashboardShell`, whose `ThemedRoot`
carries `newspack-nodes-theme`, `newspack-nodes-skin-root` and
`newspack-nodes-ui` and paints the admin gutters in the skin, and whose header
stamps the `version` the enqueue localizes; the enqueue names `newspack-nodes-graph`, which pulls
the ui and theme sheets in, so cards, banners, empty states and buttons take
the shared roles. The build resolves `@newspack-nodes/runtime`,
`@newspack-nodes/shared/*` and `@newspack-nodes/debug-overlay` through the
substrate's `alias-map.cjs`, from `NEWSPACK_NODES_SRC` in CI and from the
sibling checkout locally.

## Layout

| Path | What |
|------|------|
| `newspack-intelligence.php` | Bootstrap: substrate handshake, topology registration, admin menus, WP-CLI, the `Intelligence_CI` mount, the MCP door's `wire()` |
| `includes/` (streams) | `Intel_Event`, `Intel_Event_Writer_Node`, `Projector_Node`, `Intel_Graph_Row_Schema`, `Url_Canon`, `Ontology_Seed`, `Ontology_Writer`, the `Emits_Struct` trait |
| `includes/` (collect) | `Source_Node` and its three connectors, the `Source` interface |
| `includes/` (resolve) | `Entity_Index`, `Resolver`, `Resolver_Node`, `Extractor_Node`, `Deduper_Node`, `Reresolver_Node`, `LLM_Entity_Extractor` and the `Entity_Extractor` interface |
| `includes/` (clients) | `Client_Importer`, the `Client_Roster` interface, `Graph_Client_Roster`, `CSV_Parser`, `Clients_Settings` |
| `includes/` (LLM) | `Prompts`, the `LLM_Client` interface, `Proxy_LLM_Client`, the `LLM_Config` and `Vault_Secret` traits |
| `includes/` (service) | `Intelligence_CI_Node`, `Intelligence_Mcp_Door`, `Graph_Query`, `Graph_Curation`, `Intel_Tiers`, `Worker_Route`, `uninstall-cleanup.php` |
| `includes/cli/` | `Clients_CLI_Command` — `wp newspack-intelligence clients import <csv>`; `Ontology_CLI_Command` — `wp newspack-intelligence ontology seed` and `ontology reresolve`; the `Refuses_Root` trait both use |
| `topologies/` | The five `.tsl` node-graph topologies |
| `src/intelligence/` | The Intelligence page's React bundle |
| `tests/` | PHPUnit (`unit/` + `bootstrap.php` + `support/` + `run-coverage.sh`; `integration/` exists but is empty) |
| `scripts/` | Git hooks, the shared tooling copies, `bump-version.sh`, `build.mjs`, the lint and coverage gates |

## WordPress surface

Nothing here is registered until the substrate handshake passes, and the plugin
declares no hook or filter of its own for others to extend.

- **Admin pages.** `Intelligence`, a top-level menu at position 58.6 with the
  slug `newspack-intelligence`, gated on
  `Newspack_Nodes\Capabilities::can( Capabilities::READ )` and registered under
  the capability `READ` maps to. Its bundle is enqueued on
  `admin_enqueue_scripts` for its own hook suffix,
  `toplevel_page_newspack-intelligence`, and no other — WordPress refuses the
  page to a caller without the menu's capability before that hook runs —
  through `Admin::enqueue_react_page()`, which no-ops when
  `build/intelligence/index.js` is absent. `Newspack Intelligence` under
  Settings, which hosts the client-CSV upload, is gated, rendered and
  registered the same way on `Capabilities::MANAGE`, which applies the
  `allowed_users` list.
- **Filter.** `newspack_nodes/overlay_pages` — declares the Intelligence
  page to the substrate overlay-page registry.
- **Action.** `newspack_nodes/request_graph_ready` — mounts `Intelligence_CI`
  into each request graph, idempotently.
- **Admin-post.** `newspack_intelligence_import_clients` handles the CSV
  upload, refusing a caller `Capabilities::can( MANAGE )` refuses or a failed
  nonce, and redirects with `clients_imported=1`, which an `admin_notices`
  callback turns into the success notice. `handle_admin_post()` discards
  `import_path()`'s counts, so that redirect is unconditional: an unreadable
  temp file, a header line other than `Atomic site ID, Created, Domain name`,
  or a file yielding no valid rows still reports success while importing
  nothing.
- **REST.** `POST /wp-json/newspack-intelligence/v1/mcp`, the MCP door, which
  the substrate's `Mcp_Door` serves and declares as an OAuth resource on
  `newspack_nodes/oauth_resources`.
- **Uninstall.** Plugin delete removes every `newspack_intelligence_` option row
  and its transient variants, on every site of a multisite, and
  `delete_graph_tables()` removes the SQLite files, WAL files included, of
  every Row_Table the plugin's own topologies declare with the `Intel_Graph`
  schema, on every partition — under a base directory this install
  configured, never the default one every unconfigured install shares. The
  substrate's uninstall removes the streams with the rest of its runtime
  tree. Deactivation removes nothing.

## References

- Substrate: [`newspack-nodes`](../newspack-nodes) (+ its `AGENTS.md`)
- Design record, in `dndocker/docs/superpowers/`; the code is the authority where
  the two disagree:
  - `specs/2026-10-24-intelligence-entity-graph-design.md` — the entity graph:
    the two streams, the projection, resolution, curation, the MCP door and
    the later phases, executed by
    `plans/2026-10-24-intelligence-entity-graph-phase1.md`, with the substrate
    halves in `plans/2026-10-24-nodes-partition-keep-everything.md` (the
    ontology stream's retention) and `plans/2026-10-24-nodes-mcp-door.md`
    (the door)
  - `specs/2026-07-16-newspack-intelligence-rebrand-design.md` — the rename
  - `specs/2026-07-29-intelligence-actions-v7-design.md` — the release workflow
  - `specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md` and
    `specs/2026-06-15-newspack-ai-newsletter-ai-core-design.md` — the digest
    pipeline the entity graph replaced, executed by
    `plans/2026-06-15-newspack-ai-newsletter-foundation.md` and
    `plans/2026-06-15-newspack-ai-newsletter-ai-core.md`
  - `specs/2026-07-06-ai-newsletter-topology-config-migration-design.md` — why
    node config lives in the topology verbs

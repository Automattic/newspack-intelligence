# AGENTS.md — Newspack Intelligence

An AI-driven team intelligence digest built on the `newspack-nodes` substrate
(sibling plugin; `Requires Plugins: newspack-nodes`). It ingests GitHub, Linear
and feed items, enriches and scores each one with an LLM, accumulates them into a
durable digest, and publishes that digest as markdown and a WordPress draft post.

> The whole path runs end-to-end: three live connectors, the LLM enrich / score /
> compose stages, the intake Gate that attributes an item to a Newspack client,
> and the two-column Publisher Insights dashboard. The design record lives in
> dndocker (see [References](#references)); where it and the code disagree, the
> code is the authority.

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
error.** The loader calls `Bootstrap::version_at_least( '2.115.0' )` and stays
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
advisory.** Each runtime package is declared exactly, no caret, and `scripts/lint-wp-pin.mjs` fails the push when a declaration carries a range or the lock resolves it elsewhere. A caret lets every lock climb to trunk while the declaration still reads as the tag, and `npm ci` then builds against the climb. Transitive `@wordpress/*` packages still float within the ranges the family's own manifests declare; the build externalizes those too. Every declared runtime version IS its `wp-7.0` tag: `api-fetch
7.40.1`, `block-library 9.40.2`, `blocks 15.13.1`, `components 32.2.1`, `element
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

`markdownToBlockMarkup.js` meets that JS floor without exercising its own
conversion. `ensureCoreBlocks()` requires `@wordpress/block-library` lazily, and
jest resolves that package's transitive `@wordpress/block-editor` to untranspiled
`src/`, which babel-jest refuses to execute. The suite therefore covers the
empty-input short-circuit and, through `jest.doMock`, the register-once call
sequence; the markdown-to-blocks conversion itself is verified only by using the
admin page in a browser, where WordPress enqueues the real `wp-block-library`.

## Architecture

The graph is being rebuilt on two event streams. The sources already write to
them; the four older stages below stay composed by `newspack-intelligence.tsl`
until the entity graph replaces them, and receive nothing from the sources.

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
0.9; a domain's stem in the title behind the gate's guards at 0.9; and only
when those find nothing, the NER extractor's organizations fuzzy-matched
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
quoted FTS5 phrase per name over `items_fts` (its `"` doubled) and a LIKE per
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
seam. Both `ontology` verbs refuse to run as root before they write, as
`wp nodes` verbs do, because a root-owned segment or IPC file is one the
workers cannot append to.

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
tick clock, or the wall clock in a cold WP-CLI process.

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

**Summary.** `ingest:consumer` paces items through `Summarizer` → `Scorer` into
the durable `scored` Partition. The Summarizer asks the LLM for `{summary,
relevance_score, reason}` and falls back to a deterministic template when no
client resolves; the Scorer blends that relevance score with a recency bonus
(7-day half-life, 2.0 points maximum) and falls back to a title-keyword heuristic.

**Digest.** `scored:consumer` feeds `Digest_Builder` → `Tee` → `Log`
(`digest:log`, writing `digest.md`). The builder accumulates items and composes a
draft on the `DONE` that completes the cycle — counting DISTINCT source names, so
a re-tick or a replay neither advances `done` nor recomposes — or on an explicit
`REGENERATE`. `Digest_Composer` takes the top 10 per source, so no
busy source crowds the others out, and asks the LLM for a markdown briefing under
a 32000-token budget; a thrown `RuntimeException`, an empty reply and a
whitespace-only reply all render that same selection as a ranked bullet list
rather than throwing. The cap is `Digest_Composer::PER_SOURCE`, unrelated to the
dashboard's `Insights_CI_Node::TOP_N`: the two hold the same number today, and
changing one leaves the other where it was. `RESET` clears nothing itself: it
appends a `TM_INFO "RESET\n"` fence to `ingest:partition`, which reaches the
digest through the summary stage behind every `DONE` already in flight, so a
late `DONE` completes its own cycle rather than the next one. The digest clears
its items, dedup set and `done` when the fence arrives, and `scored:consumer`
co-commits that emptied snapshot with the cursor past it.

**Gate (observer).** `gate:consumer` tails the SAME `ingest` Partition with its
OWN offsets, so gating neither moves the summarizer's cursor nor changes the
digest. `Gate_Node` runs `Publisher_Matcher` over each item and appends one JSON
line to `gate:log` — an append-only, replayable decision log, and the data source
for threshold tuning. Each line is `{stage, item_id, decision, atomic_site_id,
matched_on, confidence, reason, config_version}` plus the persist-time `ts` the
node stamps, and `decision` is one of `pass`, `hold`, `ignore` and `bypass`.
Moving the gate inline, to filter what reaches the digest, is a deliberate later
step.

Matching resolves in cheapness order against ACTIVE publishers only: URL domain
(confidence 1.0), then a whole-word publisher name or alias across title and body
(1.0), then a domain-stem hit in the TITLE alone (0.9), then optional cheap-LLM
NER through `LLM_Entity_Extractor`. Two candidates at any step yield `hold` rather
than a guess; NER bands its best similarity, passing at 0.85 or above, ignoring
below 0.60 and holding between. A `hold` reading `ner: no entities` does not
separate a genuine empty result from a failed call — `LLM_Entity_Extractor`
catches every `RuntimeException` and degrades to an empty triple — so a spike in
that reason can mean the proxy is down rather than that the items name no
subject. Bodies feed the name search but never the stem search — they are
unstripped RSS `description` and Atom `content`, where an `href` or a logo
filename spells a client's domain — and a stem must run at least 6 characters,
align to word boundaries, capitalize every word and not open with an article,
because a title capitalizes its first word regardless. GitHub and Linear
items `bypass` the gate entirely, being attributed structurally upstream. With no
resolvable vault token the gate runs deterministic-only.

`Insights_CI` serves the dashboard slices and routes Collect and Regenerate to
the worker through `Worker_Route`, which appends each request to every live
worker's input IPC partition — durable and synchronous, with no live-worker
dependency on read. Each request carries the CI's own name as FROM,
which the worker's input reader stamps under `_repl`, so the answer every
source's `TICK` and the digest's `RESET` and `REGENERATE` sends back lands on
the worker's output partition, where an attached `wp nodes cli` reads it. The browser creates the WordPress draft from the
digest markdown via `@wordpress/api-fetch`. LLM calls go through the Automattic
AI API Proxy via `LLM_Client` / `Proxy_LLM_Client`, whose `$http_post` closure is
the test seam. More follow the same pattern, each a static `?\Closure`
replacing ONE call so the branches around it still run as production code:
`Github_Source_Node::$http_get`, `Linear_Source_Node::$http_post` and
`Feed_Source_Node::$http_get` for the connector fetches;
`LLM_Config::$llm_factory`, which every node using the trait holds its own
copy of (`Summarizer_Node::$llm_factory`, `Extractor_Node::$llm_factory`
and the rest) and `make_llm_client()` answers first; and
`Gate_Node::$matcher_factory` and `Insights_CI_Node::$read_items`; and
`Intel_Graph_Row_Schema::$read_rows` and `Resolver::$similar_text` for the
projection read and the fuzzy step's scoring.
Every caller of `LLM_Client::chat()` — `Summarizer_Node::fill()`,
`Extractor_Node::fill()`, `Digest_Composer` and `LLM_Entity_Extractor` —
re-throws `Worker_Should_Stop` first, because a cooperative stop is a
`\RuntimeException` too and must stop the worker rather than read as a
failed call (substrate ADR-14), then catches `\RuntimeException` and nothing
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
connector's date string will not parse; `retrieved_ts` the worker's `Core::$now`.
`tick()` wraps each in `Intel_Event::machine_event()` under the actor
`machine:source:{name}@{plugin version}`, with the plugin version as its
`config_version`.

The older stages read the item shape `{source, id, title, url, body, timestamp}`
off `ingest:partition`. Each adds to that array and forwards the whole of it: the
Summarizer adds `summary`, plus `relevance_score` (clamped to 0–10) and `reason`
when the LLM reply parses, then DROPS `body` to keep the scored log and its
snapshot small; the Scorer adds `score`. The Summarizer and the Scorer forward
TM_INFO unchanged, which is how a `DONE` reaches the digest with its sender's
name still in FROM. The Gate is a terminal observer and drops it, so neither a
DONE nor the RESET fence reaches `gate-decisions.jsonl`.

Each stage also stamps a state label, which `trace <node>` inside `wp nodes cli`
prints: the Summarizer `SUMMARIZED` or `FAILED` with the title, the Scorer
`SCORED`, the Gate `GATED:{decision}` with the item id, and the builder
`RECEIVED` per item and `COMPOSED` with the composed count. `FAILED` covers three
cases rather than one — no LLM client resolves, `chat()` throws, or the reply
fails `parse_enrich()` — so it does not on its own mean the proxy call failed.

### Topologies

| File | What it wires |
|------|---------------|
| `newspack-intelligence.tsl` | Includes every file below and wires `reresolver`, which spans the project and streams files; `on_demand_idle 0` keeps the worker resident, which the TICK-driven sources need, and `stale_timeout 600` keeps a peer from stealing its lock while a TICK's fetches or an LLM call block one `fill()` |
| `newspack-intelligence-streams.tsl` | `intel:items`, `intel:ontology`, `intel:reresolve` and `intel:writer` |
| `newspack-intelligence-collect.tsl` | The three sources → `intel:writer`; includes the streams |
| `newspack-intelligence-project.tsl` | `intel:items:consumer` → `projector`, and `intel:ontology:consumer` → `intel:ontology:tee` → `projector`, writing the `intel-graph` Row_Table |
| `newspack-intelligence-resolve.tsl` | `intel:items:resolve` and `intel:reresolve:resolve` → `resolver` → `extractor` → `deduper`, each writing its events to `intel:writer`, the items Consumer checkpointing the deduper; includes the streams |
| `newspack-intelligence-ingest.tsl` | `ingest:partition` alone |
| `newspack-intelligence-summary.tsl` | `ingest:consumer` → `summarizer` → `scorer` → `scored:partition` |
| `newspack-intelligence-digest.tsl` | `scored:consumer` → `digest` → `digest:tee` → `digest:log` |
| `newspack-intelligence-gate.tsl` | `gate:consumer` → `gate` → `gate:tojson` → `gate:log` |

### Nodes

`Consumer`, `Partition`, `Tee`, `Log` and `Struct_To_JSON` come from the
substrate. This plugin adds:

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
| `Summarizer` | `Summarizer_Node` | the `LLM_Config` verbs |
| `Scorer` | `Scorer_Node` | none |
| `Digest_Builder` | `Digest_Builder_Node` | `RESET`, `REGENERATE`; the `LLM_Config` verbs |
| `Gate` | `Gate_Node` | the `LLM_Config` verbs, `set_config_version` |
| `Insights_CI` | `Insights_CI_Node` | `counts`, `top`, `accumulated`, `generate`, `collect` |

The `LLM_Config` verbs are `set_api_url`, `set_vault_id`, `set_model`,
`set_feature`, `add_profile` and `remove_profile`; every node carrying the trait
also re-emits its state through `dump_config()`. An identical `add_*` repeat is
a no-op, and each `remove_*` takes its `add_*`'s argument, drops that entry and
is refused when none is. `Digest_Builder` takes two positional arguments,
the ingest Partition `RESET` fences and the `total` source count, both required.
That total MUST equal the number of names in `Insights_CI_Node::SOURCE_NODES`:
above it a collect never completes, and below it the digest composes before
every source has reported. A snapshot restore never overrides it.

All five `Insights_CI` verbs require `manage_options`, the three read slices
included: no schema entry declares a `capability`, and the substrate's
`dispatch()` refuses an undeclared verb at the strictest role rather than the
loosest. No handler checks a role of its own.

### Publisher master store

The gate matches against a `newspack_publisher` CPT whose rows split in two.
`Client_Importer` owns the ATOMIC fields — `atomic_site_id`, `domain_name`,
`created`, plus the `status` / `first_seen` / `last_seen` / `churned_at`
provenance — and never touches the ENRICHMENT fields a human edits in the
Publisher details meta box: publisher name, localities, GitHub org, LinkedIn
company id, X handle, aliases and beat tags. Localities, aliases and beat tags are
pipe-separated.

An import is a reconciliation against a full snapshot, reported as four disjoint
counts. A CSV id absent from the store is `created`; a present one has its atomic
fields updated and counts as `reactivated` if it was churned and `updated`
otherwise; a stored id absent from the CSV is `churned`. `CSV_Parser` reads the
columns as `Atomic site ID, Created, Domain name` and returns null — never `[]` —
for a file whose first non-blank line is not that header or that yields no valid
rows, because an empty snapshot would churn every client. `Client_Importer`
refuses an empty row list for the same reason.

Two things keep those counts from being an audit. `total_in_csv` counts DISTINCT
`atomic_site_id` values rather than rows, so a snapshot repeating an id reports
fewer than its line count while still processing the repeat — the second
appearance finds the row the first one created and lands in `updated`. And
`CPT_Publisher_Repository::create()` returns without a word when
`wp_insert_post()` fails, while `Client_Importer` counts that row `created`
regardless, so the reported creation can overshoot with nothing logged. Read the
four counts as a report, never as a reconciliation that sums to `total_in_csv`.

The repository holds neither a memo nor an object cache. `post_id()` resolves an
atomic id through an uncached `meta_value` exact-match query on every call, and
`find_by_atomic_id()`, `update_atomic_fields()`, `set_active()` and
`mark_churned()` each call it independently, so one existing CSV row costs two or
three lookups, on top of the unbounded `all_atomic_ids()` scan the churn pass
runs once. The source marks the spot `TODO(Gate): reverse index + object cache
under load` — a known limit to fix there, not one to route around.

Reach it from `wp newspack-intelligence clients import <csv>` or from the CSV
upload on the Settings page. Both paths run the identical parse-and-reconcile.

### Dashboard

`src/dashboard/` builds one bundle, `build/dashboard/index.js`, mounted on the
Publisher Insights admin page.

| Module | Export | What it does |
|--------|--------|--------------|
| `index.js` | — | Mounts `PublisherInsightsPage` on `#newspack-intelligence-insights` |
| `PublisherInsightsPage.js` | default | The page: the dashboard plus the substrate debug overlay |
| `PublisherInsights.js` | default | The orchestrator: runs the graph hook, lays the three widgets in two columns |
| `hooks/useInsightsGraph.js` | `useInsightsGraph`, `SERVER` | Timer → Tee → three slice Fetchers; ONE batched POST per 30s tick |
| `nodes/register.js` | `views` | Declares `source-counts:view`, `top-table:view` and `accumulated:view` through `registerSliceViews` |
| `widgets/SourceCounts.js` | `SourceCounts` | The "By source" card |
| `widgets/TopTable.js` | `TopTable` | The "Top items by source" card |
| `widgets/AccumulatedPanel.js` | `AccumulatedPanel` | The digest card, and the Collect / Regenerate / Create-draft actions |
| `markdownToBlockMarkup.js` | `markdownToBlockMarkup` | Converts digest markdown to block markup through the editor's own paste engine |
| `styles/insights.scss` | — | The page styling, imported by `PublisherInsights.js` and emitted as `build/dashboard/index.css` |

Each widget reads ONLY its own view node's `view` field through `useNodeField`;
there is no god view node and no god `insights` command. The three slices arrive
as `{sources: {source: count}}`, `{top: {source: [{title, score}]}}` and
`{accumulated, done, total, digest}` — the same shapes `nodes/register.js`
declares as each view's `empty`. `Insights_CI_Node::top_by_source()` sorts each
source's list by score descending and caps it at ten before the wire, so
`TopTable` trusts that order for its rank column and neither sorts nor caps.
Collect and Regenerate are `useCommandOnce` one-shots owned by
`AccumulatedPanel`, riding the same batched tick as the poll, because the lock,
the note and the latch each reply sets live there. The view classes are handed
to `makeNode` rather than named, because the interpreter's class map is a
per-bundle static (substrate ADR-16). The build resolves
`@newspack-nodes/runtime`, `@newspack-nodes/shared/*` and
`@newspack-nodes/debug-overlay` through the substrate's `alias-map.cjs`, from
`NEWSPACK_NODES_SRC` in CI and from the sibling checkout locally.

## Layout

| Path | What |
|------|------|
| `newspack-intelligence.php` | Bootstrap: substrate handshake, topology registration, admin menus, WP-CLI, CPT hooks, `Insights_CI` mount |
| `includes/` (pipeline) | `Intel_Event`, `Intel_Event_Writer_Node`, `Projector_Node`, `Intel_Graph_Row_Schema`, `Url_Canon`, `Entity_Index`, `Resolver`, `Resolver_Node`, `Extractor_Node`, `Deduper_Node`, `Reresolver_Node`, the `Emits_Struct` trait, `Ontology_Seed`, `Ontology_Writer`, `Source_Node` and its three connectors, `Summarizer_Node`, `Scorer_Node`, `Digest_Builder_Node`, `Digest_Composer` |
| `includes/` (gate) | `Gate_Node`, `Publisher_Matcher`, `LLM_Entity_Extractor` and the `Entity_Extractor` interface |
| `includes/` (publishers) | `Publisher_CPT`, `Publisher_Meta_Box`, `CPT_Publisher_Repository` and the `Publisher_Repository` interface, `Client_Importer`, `CSV_Parser`, `Clients_Settings` |
| `includes/` (LLM) | `Prompts`, the `LLM_Client` interface, `Proxy_LLM_Client`, the `LLM_Config` and `Vault_Secret` traits |
| `includes/` (service) | `Insights_CI_Node`, `Worker_Route`, the `Source` interface, `uninstall-cleanup.php` |
| `includes/cli/` | `Clients_CLI_Command` — `wp newspack-intelligence clients import <csv>`; `Ontology_CLI_Command` — `wp newspack-intelligence ontology seed` and `ontology reresolve` |
| `topologies/` | The nine `.tsl` node-graph topologies |
| `src/dashboard/` | The Publisher Insights React panel |
| `tests/` | PHPUnit (`unit/` + `bootstrap.php` + `support/` + `run-coverage.sh`; `integration/` exists but is empty) |
| `scripts/` | Git hooks, the shared tooling copies, `bump-version.sh`, `build.mjs`, the lint and coverage gates |

## WordPress surface

Nothing here is registered until the substrate handshake passes, and the plugin
declares no hook or filter of its own for others to extend.

- **Admin pages.** `Publisher Insights`, a top-level menu at position 58.7, and
  `Newspack Intelligence` under Settings, which hosts the client-CSV upload.
  Both are gated on the substrate's
  `Newspack_Nodes\Capabilities::can( Capabilities::MANAGE )`, which is
  `manage_options` narrowed by the `allowed_users` list. The dashboard bundle is
  enqueued on `admin_enqueue_scripts` through `Admin::enqueue_react_page()`,
  which no-ops when `build/dashboard/index.js` is absent.
- **Filter.** `newspack_nodes/overlay_pages` — declares the Insights
  page to the substrate overlay-page registry.
- **Action.** `newspack_nodes/request_graph_ready` — mounts `Insights_CI` into
  each request graph, idempotently.
- **Post type.** `newspack_publisher`, the publisher master store: private, with
  its own `Publishers` admin menu and every capability mapped to
  `manage_options`. Its meta keys are the `Publisher_CPT::META_*` constants — the
  CSV-owned atomic fields, and the enrichment fields the meta box edits on
  `add_meta_boxes` and `save_post`.
- **Admin-post.** `newspack_intelligence_import_clients`, nonce- and
  capability-checked, handles the CSV upload and redirects with
  `clients_imported=1`, which an `admin_notices` callback turns into the success
  notice. `handle_admin_post()` discards `import_path()`'s counts, so that
  redirect is unconditional: an unreadable temp file, a header line other than
  `Atomic site ID, Created, Domain name`, or a file yielding no valid rows still
  reports success while importing nothing.
- **REST.** None registered. The dashboard's "Create draft post" action POSTs
  core `/wp/v2/posts` from the browser.
- **Uninstall.** Plugin delete removes every `newspack_intelligence_` option row
  and its transient variants, on every site of a multisite. Deactivation removes
  nothing.

## References

- Substrate: [`newspack-nodes`](../newspack-nodes) (+ its `AGENTS.md`)
- Teaching walkthrough: `newspack-nodes/examples/example-ai-newsletter`
- Design record, in `dndocker/docs/superpowers/`; the code is the authority where
  the two disagree:
  - `specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md` — the master
    floorplan, executed by `plans/2026-06-15-newspack-ai-newsletter-foundation.md`
  - `specs/2026-06-15-newspack-ai-newsletter-ai-core-design.md` — the LLM stages,
    executed by `plans/2026-06-15-newspack-ai-newsletter-ai-core.md`
  - `specs/2026-07-06-ai-newsletter-topology-config-migration-design.md` — why
    there is no settings page: config lives in the topology verbs
  - `specs/2026-07-16-newspack-intelligence-rebrand-design.md` — the rename
  - `specs/2026-07-29-intelligence-actions-v7-design.md` — the release workflow

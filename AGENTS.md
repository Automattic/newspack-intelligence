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
error.** The loader calls `Bootstrap::version_at_least( '2.138.0' )` and stays
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

Everything the graph knows is an event on one of three streams. The sources
record items; the resolver, the extractor and the deduper record what they
make of each; the seed, the client import, people and agents record the
ontology and every judgment, each reader's watches among them; the watcher
records what each watch matched and how far it has delivered, and each
reader the matches they have seen. The projector replays the three streams
into one SQLite Row_Table, which every read queries and the streams can
rebuild.

**Streams.** `intel:items` holds `item_recorded` and the other per-item events
for two years (16 MiB segments, both retention rules at 63,072,000 seconds,
a hard cap of 8,192 segments), and only the worker writes it, so it
takes bodies past PIPE_BUF under `void_warranty`. `intel:ontology` holds the
entity events and keeps every segment (`keep_everything`, substrate ADR-37).
`intel:reresolve` keeps a day of `item_reresolve` records, each naming one
stored item. `intel:watches` holds each watch's `watch_matched`,
`watch_seen` and `watch_delivered` records, one line each, for two years
(4 MiB segments, both retention rules at 63,072,000 seconds, a hard cap of
8,192 segments), as long as the items they name, so a replay rebuilds every
match row a retained item still has; the watcher and request-side appends
both write it. `intel:later`, a substrate Delay
(`make_node Delay intel:later <dir> <offsets_dir> <deadletter_dir> 60 86400`),
parks the `item_reresolve` records the resolver and the extractor want to
reprocess later, each due at its `not_before`; every minute it sends each
one due to `intel:reresolve`, its target, and drops one parked longer than a
day, a record parked again keeping its first `parked_at`. Each record parks
with KEY its `item_key`, so the Delay's age-out line names the item. Each
of the five carries restricted items whole or names them, so each declares `command_node
<name>:config set_read_capability newspack_intelligence_restricted`
(substrate ADR-39): each writes a `.read-capability` marker naming it into
its own dir on the write that opens it, every reader of it copies the
marker into its cursor and dead-letter dirs as it next writes them, and
the substrate's raw-log verbs, its catalog and an SSE subscription refuse
a marked dir to a caller without the capability. The TSL spells the
capability out; a test writes through each stream, checkpoints and
quarantines through each reader, and holds every marker to
`Intel_Tiers::CAPABILITY`.
`Intel_Event_Writer_Node` (`intel:writer intel:items intel:ontology
intel:watches`) addresses
each event by TO to the partition its `make_node` line names for the stream
`Intel_Event::stream_of()` gives its kind, and drops a message naming no kind
with a rate-limited line; it validates nothing, because `Intel_Event::make()`
validated the event at mint and the projector validates it where it reads.
`make()` refuses an event of any stream but the items stream that does not
pack into one 4,096-byte line, because only the items stream takes a large
write.

**Projection.** `projector` replays the three streams into the `intel-graph`
Row_Table (`Intel_Graph_Row_Schema`), fed by `intel:items:consumer`,
`intel:ontology:consumer` and `intel:watches:consumer`, which drain in
batches and each checkpoint it.
`projections()` declares, per kind, the row ops it writes and where each column
comes from — an event field, a literal, a computed closure, or each element of
a fan-out field — and every value is typed by its column's declared type. Every
op is an `ignore` or a whole-row `set` on a keyed table, but for an
extraction, which first deletes the item's claims and topic links (the
substrate's `delete` op, 2.134.0) so one yielding fewer leaves none of the
earlier behind, and for a `link_retracted`, which deletes the one `links`
row its `item_key`, `entity_key` and `role` key in the file of the
partition that projects it, and for a `lens_removed`, which deletes the
one `lenses` row its `lens_key` keys; a `lens_saved` sets that row whole,
its `filters` as JSON; and for a `watch_removed`, which deletes the one
`watches` row its `watch_key` keys, a `watch_saved` setting that row whole,
its three boxes as integers; and for an `item_purged`, which deletes the
rows the items stream has written about its item in each table that stream
writes (see Purging items). A heuristic stand-in is projected by
`STAND_IN` instead, one `ignore` of its `extractions` row, so it fills an
item no extraction holds and never replaces a call's extraction, claims or
topics, whatever the projection held when the extractor chose it. A
`subjects_extracted` replaces
the item's `extracted_subjects` rows, the entities its last call extraction
was given as asserted, which the resolver settles links by. Each table is
written by one stream alone, so a replay writes
what it wrote before and the streams project the same rows in any order. The projector never reads
before it writes: an entity is three rows — identity in `entities`, state in
`entity_states`, merge in `entity_merges` — and an item's extraction sits in
`extractions` beside its `items` row, which is written once. A link, claim,
judgment or topic may name an entity no `entities` row holds yet; readers join
to it. A machine's `entity_proposed` writes with `ignore` and a person's
`entity_created` with `set`, so a machine never overwrites a person.
Item-scoped tables keep 730 days by an indexed day column, the item's
retrieval `floor( retrieved_ts / 86400 )`, which every item-scoped event
carries — a supersession its canonical's — so an item and every row about it
expire together however late a re-resolution writes them; `watch_matches`
and `watch_seen`, keyed by watch and item, are dated the same way, so a
match expires with its item. The ontology and curation tables, `watches`
and `watch_deliveries` declare `keep`. Ops buffer and leave as
one `ROWS` batch every 5 seconds, at 2,000 ops or 1 MiB of string values, at
each checkpoint and on a clean stop; a failed batch raises a plain stop and
keeps the buffer, so the cursor replays it. A message that carries no valid
event throws out of `fill()`, so its Consumer dead-letters it and reads on.
`Url_Canon::of()` and `Entity_Index::norm()` compute the canonical URL, the
title norm and each name's norm the readers match on.

**Rebuilding.** `Intel_Graph_Row_Schema::version()`, 2, versions the
projection's shape; the substrate discards a file at another version when
the Row_Table opens it, and the projector's Consumers resume where they
stopped, so the graph stays empty until it is rebuilt. A schema change bumps
the version, and the upgrade runs `wp newspack-intelligence ontology
reproject`. It holds the fleet with `wp nodes stop`, deletes the graph's
files and the cursor of every Consumer whose config adds the projector as a
snapshot node, read from each active topology's TSL for each partition, and
releases the hold with `wp nodes start`, in a `finally`, so the projector
replays every stream from its oldest segment. It reads `nodes stop`'s exit
code rather than letting it exit, which would skip that `finally`; a fleet
that does not stop has nothing deleted under it and its hold released. The
streams and every other reader's cursor stay, the reresolver's included,
since it reads the ontology through its own Consumer, so a rebuild signals
nothing and re-resolves nothing; `wp newspack-intelligence ontology
reresolve` does that when wanted. `wp nodes gc --force` would not do here,
because a deactivated topology orphans its event logs with its tables. A
table added at the same version needs neither: the writer declares every
table with `CREATE TABLE IF NOT EXISTS` when its Row_Table opens the file,
so a worker restarted after the deploy creates it, and a read before that
restart refuses `no such table`.

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
`Resolver_Node` (`make_node Resolver resolver intel:writer intel:later`)
writes a `link_asserted` or `link_proposed` per link, a `link_retracted` per
stored link it no longer writes, and an `entity_proposed` per candidate,
keyed `Intel_Event::entity_key()`, to the writer its `make_node` line names,
by TO and declared as an extra target, then forwards `{ item, links,
proposals, extracted, attempt }` to its target, `extractor`. It resolves
nothing while its index holds no live entity
(`Entity_Index::has_live_entities()`), or while a projector's reader of the
ontology stream `Stream_Writer::stream_dir()` names had not committed the
stream's end (`Projector_Node::behind()`, over
`Consumer_Node::lag_from_disk()`) just before the index was
read (`Entity_Index::behind_ontology()`): `Entity_Index::current()` takes
that reading first at every load, every `Entity_Index::TTL_S`, whichever
reader triggers it, so a flush and commit landing between the two reads can
make the index only fresher than the reading says. The projector flushes at
every checkpoint, so a cursor at the end means every ontology event is
projected, and a false hold only parks a record a minute. The resolver's
own `entity_proposed` events leave that cursor short until the projector's
next checkpoint, up to 30 seconds, so items following a proposal may park a
minute; nothing is lost and no LLM call is spent.
`Projector_Node::readers()` answers each
projector reader's source and offsets dirs from the active topologies' TSL,
the one walk `ontology reproject` deletes the cursors by. A held
`item_recorded` parks on `intel:later`, its second argument, also declared,
as `{ event: item_reresolve, item_key, fresh_after: 0, ner: true }`, and a
held `item_reresolve` parks again unchanged, its `parked_at` too, each due
`HOLD_S` (60) seconds on with KEY its `item_key`, so the Delay's age-out
line names it, under a rate-limited line naming the cause, the fix (`wp
newspack-intelligence ontology seed`, or `ontology reproject` after an
upgrade) and the recovery once a day ages a record out: `ontology
reresolve --since=<day>` re-resolves it without its NER step, and on a
fresh install `ontology seed` runs first. `Resolver_Node::reresolve()` is
the one constructor of that record, which the hold, the extractor's retry
and the reresolver all mint and the resolver reads back strictly. An
`item_reresolve` carrying `ner: true` runs the NER step, and parks again
while the projection does not hold its item yet rather than dropping it;
`ner`, `attempt` and `parked_at` are optional, and a record carrying one
that does not read is dropped with the rest. `Resolver::resolve()` answers
`recognized` beside the links, whether its NER step ran and answered:
`LLM_Entity_Extractor::extract()` answers none, logged rate-limited, for a
failed call or a reply whose `orgs` is not a list (none, an error object, a
scalar), so a failure is told from an answer naming nobody.
A resolution retracts each stored non-topic `links` row of the item in this
worker's own partition's file, the only one its projector deletes in, by
its raw entity key and role, that it does not write again, so a link whose
role changed is retracted in its old one; a row whose survivor a curator
confirmed is kept, and so is a `ner_fuzzy` row unless the NER step ran and
answered, since nothing else looked for it again. Every resolution reads
those rows with the rest of `settled()`; an `item_recorded` reads them
through `Intel_Graph_Row_Schema::read()` given a line, so a projection that
will not open resolves the item from the event, NER included, with no
judgments, and retracts nothing, logged. An `item_recorded` for an item the
projection already holds is a re-resolution: it runs exactly what an
`item_reresolve` naming the item does, with an `attempt` of 0, and runs
the NER step unless the item was
resolved before — the projection holds an extraction of it from any
actor, or a non-topic link in any partition. Holding the `items` row alone
is not that: the projector writes it on its next flush, every 5 seconds,
whether or not any resolution ran, while `intel:items:resolve` hands the
resolver one line a poll, each possibly blocking on LLM calls, so under a
burst most first recordings arrive with their row projected. Such a
first recording, and an item whose NER step named nobody or failed, so
that nothing linked or extracted it, runs NER and is forwarded with
`extracted: false`; a fresh worker's repeat of a resolved item runs none,
and a repeat a call extracted with its links settled spends no call and
keeps the stored extraction. One the projection does not hold yet takes
the same path from the event's item, NER included, forwarded with
`extracted: false` and the judgments the projection already holds of it,
because a replay can project the ontology stream, verdicts included, ahead
of the items stream. The repeat path has one accepted cost: a crash after the projector
flushed an item's extraction but before `intel:items:resolve` committed
its cursor, up to 30 seconds, replays the `item_recorded` as a settled
repeat, which is not forwarded, so the deduper never places the item in a
cluster. A missing cluster placement is the price of the NER and
extraction calls the repeat path saves on every respawn. An
`item_reresolve` record, fed by `intel:reresolve:resolve`, names a stored
item, which it reads from
the projection with whether the projection holds an extraction a call
made — a `machine:extract-heuristic@…` stand-in does not count, matched
through `Intel_Event::machine_prefix( Extractor_Node::HEURISTIC_STAGE )` —
its stored links, the judgments of them and the entities its last call
extraction was given, each entity as its survivor in the resolver's own
index, across every partition, a key whose merge chain is broken counting
nowhere. A link is settled when the last call extraction was given its
survivor as asserted, so a merge settles through the survivor and a
confirmation the projection had not yet recorded when the item was
extracted is still owed its claims; a link a curator rejected never reaches
the comparison, because the extractor is not sent it;
its verdict is its highest-ranked judgment's (`Graph_Query::judge_rank()`, the
latest among equals). A record missing its item or its
freshness is dropped with a line, as is one naming an item the projection no
longer holds, and a projection that will not open throws, so the record
dead-letters. A re-resolution runs the first three steps alone, since NER
ran on first resolution, unless it carries `ner: true`, writes its links,
and forwards the item when no such extraction is projected, or when, with
the curators' verdicts in force, it asserts a link that is not settled. So an
item a proxy outage left heuristic is
extracted again, as is one naming an entity accepted or created after its
extraction, which asserted no claim about a subject it only proposed, and
one a curator confirmed a link of since; an item whose settled links are
unchanged is never extracted twice. What it forwards carries the
curators' verdicts: a confirmed link asserted, from its stored row when the
re-resolution did not find it again, and a rejected one left out, so the
extractor's claims follow them; and, from a re-resolution whose NER step
did not run and answer, each stored `ner_fuzzy` link it did not find, read
across every partition by survivor, in its stored state, unless a curator
rejected it, asserted when
one confirmed it, so the settled check sees it and an extraction retry
reaches an item only NER linked. The link events it writes stay the
resolver's own; and whether a call extracted the item before (`extracted`),
so an extractor whose call fails keeps that extraction rather than replacing
it with the heuristic stand-in. That extraction was not given the link, so
the item's next re-resolution tries again. `Resolver::resolve(
$item, $ner )` takes the NER step per call: the node builds it from its
`LLM_Config` state for each item owed NER, so a runtime `set_vault_id` or
`set_api_url` reaches the next one, and passes none on any other
re-resolution; the `Resolver` itself is rebuilt only when the index is.
Every link event carries the item's `retrieved_ts`. The resolver and the
extractor share the process's one
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
closed set. It writes an `extraction_recorded` naming topic keys, then a
`subjects_extracted` naming the entities it was given as asserted, and a
`claim_asserted` per claim whose subject the item links by an asserted
link, taking that link's confidence; a subject linked only by
`link_proposed` asserts no claim. Subject and object resolve through every
entity whose name spells the whole of them in `Entity_Index::names_in()`:
the subject is the one of those the item asserts, and the object is the
only one, or the only one the item links, else it carries no key, so
two entities sharing a name never resolve by load order. Every claim keeps
the object as the item spelled it in `object_text`, keyed or not, which a
read answers to a caller who cannot see the object's entity. No client, a thrown
`RuntimeException` or a reply that does not parse records a heuristic
extraction instead — the first sentence of the title and of the body, kind
`other`, no topics, scores 0 — as `machine:extract-heuristic@<version>`, so
the item is never retried in a loop; a later re-resolution extracts it
again. A call made that fails — a thrown `RuntimeException` or a reply that
does not parse, never a cooperative stop and never a missing client — also
parks `{ event: item_reresolve, item_key, fresh_after: 0, attempt: attempt +
1 }` on `intel:later`, the extractor's second argument, with KEY its
`item_key`, due
`RETRY_BASE_S * 2 ** attempt` (300 seconds doubled) capped at `RETRY_MAX_S`
(3,600), while `attempt` is below `MAX_ATTEMPTS` (5), so every retry lands
inside the day the queue keeps; at the cap a line says it gave up. The
replay is a re-resolution, which forwards the item for extraction only
while it is still owed one, and the resolution it reads carries that
`attempt`, which a resolution lacking it is dropped for. Either way it then
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

**Re-resolution.** `reresolver`, which the composed graph builds, reads the
ontology stream through its own Consumer, `intel:ontology:reresolve`, apart
from the projector's, and queues what an event can
link anew: an `entity_created` or `name_added` its name, an
`identifier_added` the URL spellings its kind has (`IDENTIFIER_URLS`), an
`entity_accepted` every name and identifier the projection holds for the
entity, and a new locality the entity's own names, because a locality settles
an ambiguous name and links nothing alone; a `link_confirmed` queues its one
item without a search. A topic queues nothing, and a
repeated signal is queued once. Each Router tick appends `item_reresolve`
records, each naming a stored item, to
`intel:reresolve`, a Partition
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
`intel:ontology:reresolve` (`add_snapshot_node reresolver`), each unit by its
key, `<kind>|<value>`, with when it was queued and a range's cursor, and an
entry the checkpoint cannot read, or a checkpoint holding no queue, is
dropped and logged; a signal leaves the queue once swept, a range once a
page comes back short, and either waits, logged, while the projection will
not open. Any other failure of a unit's projection
read drops that unit with a rate-limited line naming it and the cause, so a
respawn cannot restore the same head and fail on it again; a cooperative stop
propagates first. `Intel_Graph_Row_Schema::$read_rows` is the read's test
seam. Every `wp newspack-intelligence` verb refuses to run as root
before it reads or writes, through the `Refuses_Root` trait, as `wp nodes`
verbs do, because a root-owned segment or IPC file is one the workers
cannot append to.

**Reads.** `Intelligence_CI` (`intelligence`), mounted on every request
graph, answers ten read verbs, each declaring `read` and its
args: `search`, `entity`, `timeline`, `related`, `claims`, `trends`,
`signals`, `review`, `collections` and `lenses`, and the five watch verbs
below. Each handler hands its bound args to the `Graph_Query`
read of the same name, through `Intel_Graph_Row_Schema::read()` over every
partition's file. A read opens on `Graph_Query::graph()`, which resolves
the tiers once from `Intel_Tiers::readable()` — `public` and `internal`,
and `restricted` for a caller holding the restricted tier (below) — and
binds the prelude every statement opens with: `mg`, the survivor map,
and `ri`, the items of those tiers. Every statement reads items through
`ri`, never `items` with a filter of its own, so no item, link, claim,
count or citation rests on an item of another tier, and an entity short of
`accepted` — proposed, or rejected — answers `entity` only while a readable
item cites it, by a link or as the item it was proposed from.
`Graph_Query::visible()` is that rule, and `seen()` refuses what it hides
as `no entity: <key>`; `entity`, `timeline` and every curation verb resolve
their entity keys through it. Names, identifiers and roles carry no
tier and are not filtered: they are the entity's own, under the keys
merged into it. Every other entity a read names passes the same rule: an
entity's record lists a topic it is tagged with only when `visible()` lets
the caller see the topic; a claim answers its object's key only when
`visible()` lets the caller see the object, else the object as the item
spelled it (`object_text`), and leaves out a claim holding neither, as one
written before the extractor kept the spelling may; a claim's subject is
linked by a live link on the claim's own readable item, so the caller
always sees it; and every other read names an entity only through `live`
or `cites`, so only one a readable item cites. A key a read names, and every key a row names,
answers as its survivor in `Entity_Index::survivors()`, the one survivor
map the index load also reads, built with its inverse once a read in
`graph()`; a key whose chain cycles or runs past
`Entity_Index::MAX_MERGE_HOPS` is refused, and its rows count nowhere. Links read through the `live` CTE as
one per item and survivor, a topic link apart from the rest, subject over
mention; a link's state is the highest-ranked judgment's (`JUDGE_RANK`: a
person over an agent), else the machine's, and a `rejected` link counts
nowhere. Each read hands `live()` the links it rests on — an entity's keys,
a page's items, the items a claim or a proposal names — and windows only
those; `search` and `trends` naming a role, or `trends` counting topics,
with no entity or topic window the links of the readable items inside
their days. Summaries, kinds and scores come from `extractions`,
names from a join on `entities`. `q` reaches FTS5 as one `fts_phrase()` per
word over title, body and summary, and a word holding no letter or digit
matches as an empty phrase, which FTS5 ignores; every other value binds,
and a blank optional arg is a value read as given (substrate ADR-25), so a
blank `q` or `source` matches nothing. Days parse through
`Intel_Graph_Row_Schema::range()`. No answer carries a body; `limit` is
capped at `Graph_Query::MAX_LIMIT` (200); trend rows, related entities and
proposed entities cite their five newest items, the first two as `items: [
{ item_key, url, title } ]`, and items and claims their URLs. A projection that will not open answers `available: false` beside
empty rows, logged; one no partition has written answers empty rows.
A collection's saved items read from `collected` alone, at the tier each
was saved at, so a saved item outlives its expiring `items` row; `collected`
keeps its own tier filter, and `collections` opens no graph.
`search` and `trends` filter alike — `entity`, and the lists
`Graph_Query::LENS_LISTS` declares once, `kind`, `topic`, `role` and
`source`, each repeatable and matching any of its values, `role` an entity
the item links holding it at the item's time — and `search` adds `q`.
`LENS_LISTS` names each list's field, its arg, its vocabulary (null when
free) and its description, and the call's filters, a lens's, `save_lens`,
the schema's args and the page's `lensLists` vocabulary all iterate it. `trends` counts by `by` per `bucket`, the call's, else its lens's,
else `kind` (`Graph_Query::DEFAULT_AXIS`) per `week` (`DEFAULT_BUCKET`);
neither arg declares a default, since a bound default would mask the
lens's. `lenses` answers `{ lenses: [ { lens_key, name, description,
filters } ], available }` by name, `filters` always the whole
`Graph_Query::unfiltered()` shape — `kinds`, `topics`, `roles`,
`sources`, `entity`, `days`, `tiers`, `by` and `bucket` — its entity and
topics only those the caller may see now (`visible()`), so one a judgment
has since left to restricted items alone is left out for a non-holder.
Given `lens`, slugged as `save_lens` slugs it, `search` and `trends` read
through it (`Graph_Query::through()`):
each list the call names, and `entity`, replaces the lens's; `since` or
`until`, or both, replace the lens's `days`, the last that many UTC days
ending today, whole, the side the call leaves open staying open; and the
graph opens with `ri` over the caller's readable tiers intersected with the
lens's `tiers`, so a lens narrows a holder and never widens a reader. A
lens's entity or topic the caller cannot see stays in the filter, where it
matches no item the caller may read, so it narrows that caller to nothing
rather than dropping out and widening. A lens no one saved is refused as
`no lens: <key>`.

**Signals.** `signals [--lens=] [--entity=]` reads the rows `trends`
answers for the same lens and entity, so the lens's filters, axis, bucket
and tiers apply alike, and answers `{ signals: [ { bucket, by, value,
count, baseline, items } ], available }`: per value counted, the latest
complete bucket's count when it is at least `Graph_Query::SIGNAL_MIN` (3)
and at least the mean plus `SIGNAL_SIGMAS` (2) population standard
deviations of the `SIGNAL_BASELINE` (8) buckets before it, compared in whole
numbers so the threshold itself counts. `baseline` is that mean, and
`items` cite the bucket's five newest items. It counts those
`SIGNAL_BASELINE + 2` buckets alone, not the whole retention. A history
holding no counted item before the oldest baseline bucket signals nothing,
because its zeros are not a baseline: an item in that bucket answers it,
else one `LIMIT 1` look further back within the lens's own days.
`Graph_Query::spikes()` is the same read on a read already open, answering
none unless the latest complete bucket closed after a given instant; the
digest reads it.

**Watches.** A watch is one reader's standing question over exactly one
lens, entity or collection. `Graph_Watches` holds its five verbs, each at
`read`, each answering or changing the caller's own watches alone:
`watches` lists them by name, `{ watches: [ { watch_key, name, target: {
kind, key }, digest, instant, inbox, unseen } ], available }`, `unseen`
counting the unseen matches the caller may read now, 0 for a watch whose
`inbox` is off; `save_watch <name>
[--lens=] [--entity=] [--collection=] [--digest=] [--instant=] [--inbox=]
[--key=]` writes `watch_saved` under a key it mints, sixteen hex
characters, or under the key of one of the caller's own watches, which it
replaces, and answers `{ ok, event_id, watch_key }`; `remove_watch <key>`
writes `watch_removed`; `watch_matches <key> [--limit=] [--offset=]`
answers the watch's unseen matches the caller may read now, the latest
matched first, each as `search` answers it plus `matched_ts`, through
`Graph_Query::listed()`, and `{ items: [], total: 0 }` for a watch whose
`inbox` is off, whose matches the watcher still records for its email; and `mark_seen <key> [--item=…]` writes one
`watch_seen` per named match, or per unseen match when none is named, a
match already seen not again, and answers `{ ok, count }`. The three boxes
are bools, `digest` and `instant` off and `inbox` on by default.
`save_watch` refuses a blank name, not exactly one target (`a watch follows
exactly one of lens, entity or collection`), no box ticked (`a watch
delivers by digest, instant email or inbox; tick one`), a lens no one saved
(`no lens: <key>`, slugged as `save_lens` slugs it), a collection no one
made (`no collection: <key>`), an entity the caller cannot see (`no
entity: <key>`, through `Graph_Query::seen()`, whose survivor it stores),
and a new watch once the caller keeps `Graph_Watches::MAX_PER_OWNER` (25)
(`you have 25 watches, and one reader may keep 25 at most; remove one
first`); an edit under `--key` is never refused by the cap, and another
reader's watches do not count. The count reads the caller's own rows from
the projection, so a save not yet flushed counts five seconds late and a
burst can pass the cap by the saves inside one flush window, which is
accepted: the cap bounds what one reader's watches cost each pass, and
holding it exactly would need a second count of the ontology stream's
unprojected tail. A
key that is not one of the caller's own watches is refused as `no watch:
<key>` whether another reader saved it or no one did, so no verb tells the
two apart, and `mark_seen` refuses an item the watch never matched, or one
of a tier the caller does not hold now, as `no match: <item>`. Each verb
reads the projection once, writes as `Intel_Event::caller()` with the
caller's user id as `owner`, and refuses before anything is written. A
watch's definition rides the ontology stream, which keeps every segment,
because a watch must outlive the watches stream's two years; its matches,
seen marks and deliveries ride the watches stream.

**Watcher.** `watcher` (`make_node Watcher watcher intel:writer`) rides the
Router's tick every `Watcher_Node::WATCH_INTERVAL_S` (300) seconds. A pass
waits, logged, while a projector's reader of the watches stream has not
committed its end (`Projector_Node::behind()`), so it never reads a mark or
a match it appended itself as missing; while it waits it drops to the
Router's own tick and asks again on the next one, taking its interval back
once a pass runs, so a burst of `mark_seen` writes delays it seconds, not
an interval. It takes no such hold on the ontology stream, whose many
writers would starve it, so a watch saved or removed in the last few
seconds runs as it stood. It reads every watch and its marks from the
projection (`Graph_Watches::standing()`), takes the user signed in before
the pass once, then for each owner drops that user's cached record
(`clean_user_cache()`), signs in with `wp_set_current_user()` inside a
`try`, and in its `finally` signs back in as that prior user, so a
`set_current_user` listener that throws leaves no one signed in as the
owner, every read answers the tiers the owner holds now, and a revoked
grant stops at the next pass. Any failure of one owner, the sign-in among
them, is logged and the next owner runs; an owner who no longer exists, or
no longer holds `read`, is skipped and logged. An owner's whole pass reads
the projection once, as that owner: each watch's arrivals, the matches its
instant email is owed, and, with the digest due, every digest watch's owed
matches and signals, read first and acted on after. Arrivals are
`Graph_Query::arrivals()`: the target's items the owner may read now that
the watch has not matched, entering the target at or after `LATE_S`
(86,400 seconds, the day `intel:later` parks a retry at most) before the
watch's high-water mark, or before its saving on a first pass, the oldest
first, at most `PASS_LIMIT` (200) a pass, so a backlog drains over passes.
An item enters a lens or an entity at its retrieval, read through the lens
or the entity exactly as `search` reads it, and a collection at its
saving, read through the readable items. Because an item can join its
target well after its retrieval — an extraction retried from
`intel:later`, a resolver's 60-second hold, a curator's `confirm_link`, a
re-resolution — the watch reads a day behind its mark, and its own match
rows keep an item it has already matched from matching again; an item
that joins more than a day behind the mark is not read. The mark is the
newest entry it has matched, written as a `watch_delivered` of mode `scan`,
a `keep` row, whenever it moves, so no retention moves it back and nothing
old floods in when a match row expires. Each item becomes one
`watch_matched { watch_key, owner, item_key, at, retrieved_ts, quiet }`
addressed to `intel:writer`, `quiet` when it entered the target before the
watch was saved. A watch's first pass, one with no `scan` mark written
since it was saved, so that every save, new or replacing, starts it
afresh, marks the watch at its saving at least, even when it finds
nothing, so the first item after the saving is news and a backlog that
joins later — a re-resolution linking history, a grant, a merge, a widened
lens — floods nothing older than a day before it. A watch with `instant`
ticked is then sent its owed matches (`Graph_Watches::owed()`): each not
quiet, recorded after both its last `instant` mark and its saving, of an
item the owner may read now, with this pass's news; a sent email writes
the `instant` mark at the pass's clock. Once a day, on the first pass at or
after `DIGEST_HOUR_UTC` (13) whose owner's last `digest` mark falls before
that hour, each owner with a digest watch is sent one email of every digest
watch's matches owed since that mark and the watch's saving, this pass's
among them, and of each lens's or entity's signals since that mark
(`Graph_Query::spikes()`); an empty digest sends nothing, and either writes
the owner's `digest` mark. Every mark is a watches-stream event projected
into `watch_deliveries`, so a respawned watcher reads the day as done. A
send that fails, or an owner with no valid address, is logged and marks
nothing, so the next pass sends the same recorded matches again and never
matches them again. Each log line keys its rate limit on its subject, the
owner's login and the watch key, so every failing owner and watch is
logged, not only the first; a cooperative stop propagates first (substrate
ADR-14). Every mark compares the worker's own clock, `Core::$now`, against
stamps the watcher wrote, so a wall-clock step backwards can leave a match
recorded across it unemailed.

Email goes through the one `wp_mail()` call `Watcher_Node::$wp_mail`
replaces, as plain text (`Content-Type: text/plain; charset=UTF-8`, and no
other header), to the owner's own address alone, the subject led by the
site's name. It carries each match's title as one line, every run of
whitespace or control, format or invalid characters one space; its URL only
when it is http or https; its source and its UTC day, at most
`Graph_Query::DEFAULT_LIMIT` (50) a watch with a count of the rest; each
signal with the items it cites; and a link to the Intelligence page naming
the watch, `admin.php?page=newspack-intelligence&watch=<key>`. It never
carries an item's body.

**Restricted tier.** `Intel_Tiers::CAPABILITY`,
`newspack_intelligence_restricted`, is a WordPress user capability, granted
per user and per site, and the one spelling every reader uses. `readable()`
adds `restricted` when `current_user_can()` answers for it, asked on each
call and cached nowhere, so a grant or a revoke reaches the very next read.
It adds a tier and no verb: a verb's own role gate runs first, and holding
the capability without `read` reads nothing. A role carrying the
capability, and on a multisite a super admin, who passes every
`current_user_can()` check, also reads the tier without a grant. To the
operator surfaces a holder is one thing, a user's own grant on the current
site (`WP_User::$caps`): `holders()` lists those alone, so every listed
login is one `revoke()` takes, and `strip()` removes every one, leaving a
role's. `grant()` and `revoke()` look the login up as WordPress does, through
`sanitize_user()`, and refuse an unknown one (`no user: <login>`), on a
multisite a user who is not a member of the current site, so a grant never
makes one, a grant to a user already granted it and a revoke from one
holding no grant. Each change returns the user's own login, and writes one
`Core::stderr()` line, which reaches the PHP error log, naming that login
and the operator: `user:<login>` for the Settings page and for `--user`,
else `wp-cli uid <uid>`. A refusal carries plain text, which each surface
escapes as it renders it. `wp newspack-intelligence restricted grant
<login>`, `revoke <login>` and `list` (`Restricted_CLI_Command`) and the
Settings page's "Restricted access" section (`Restricted_Settings`) are the
two operator surfaces, both at MANAGE, and each says that a role and a
super admin read the tier without a grant. The raw-log surface is gated on
the same capability, so a non-holder's `raw-logs` reads and SSE
subscriptions of the five intel streams, and of every cursor and
dead-letter dir derived from them, are refused (see Streams). Uninstall reaches `strip()` with
the substrate possibly inactive, so it and everything it calls use no
substrate class.

**Curation.** `Intelligence_CI` also answers fifteen curation verbs, each
declaring `tune`, each writing one ontology event through
`Stream_Writer::append()` and answering `{ ok, event_id }`; the two
creations add the key they minted. `Graph_Curation` holds them:
`confirm_link` and `reject_link` (carrying the item's URL), `accept_entity`,
`reject_entity`, `merge_entities`, `add_name`, `add_identifier`,
`assert_role`, `end_role`, `tag_topic`, `create_entity` (keyed
`Intel_Event::entity_key()`), `create_collection` (keyed
`Intel_Event::collection_key()`, the same slug), `collect` (the item's
URL, its title and its tier), `save_lens` (keyed `Intel_Event::lens_key()`,
the given key slugged, answering it) and `remove_lens`. The actor is `Intel_Event::caller()`:
`agent:<login>:<label>` when `Capabilities::$session_label` is non-empty,
as for an MCP connection or an issued session, the label as
`Sessions::clean_label()` bounded it, else `user:<login>`; a call with no
signed-in user is refused before it writes. Every refusal throws before
anything is written, and no handler checks a role (substrate ADR-26).
Each verb reads the projection once, `save_lens` only when it names an
entity or a topic. Every entity key it names — both
sides of a merge, a link's entity, a tagged topic — must be one
`Graph_Query::seen()` lets the caller see, any other key is refused as
`no entity: <key>`, and the verb acts on the survivor it answers as, so a
key merged away accepts, merges or tags its survivor; a curation verb
answers no more than a read does. An item is read through `ri`, and one
outside the readable tiers is refused as `no item: <key>`, exactly as a
missing one. Curation cannot declassify: `accept_entity` refuses an entity
a caller without restricted access cannot see (`<key> is cited by no item
outside the restricted tier; accepting it would show it to every reader`),
`merge_entities` refuses a merge where exactly one side is such an
entity (`merging <from> into <into> would show <key> to every reader; no
item outside the restricted tier cites it`), since a merged-away entity's
names and key show on its survivor and a survivor gains the other's
citations, and `tag_topic` refuses such a topic on an entity every reader
sees (`tagging <entity> with <topic> would show <topic> to every reader;
no item outside the restricted tier cites it`). All three ask `Graph_Query::visible()` over
`Graph_Query::unrestricted()`, the read's own graph with `ri` bound to
`Intel_Tiers::UNRESTRICTED`, so an accepted or created entity passes, and
so does one an unrestricted item cites by a link or as the item it was
proposed from. Two entities only restricted items cite merge, and a holder
still rejects one, or creates a public entity over its key on purpose with
`create_entity`. A judged link must exist: `confirm_link` and `reject_link`
refuse `no link: <item> <entity>` unless a `links` row, of any role, joins
the item to one of the entity's keys. `tag_topic` refuses a topic whose
survivor is not of type `topic` (its `entities` type, else its key's). A
merge into itself or across types — the two survivors' types, read the same
way — is refused, and so is any merge after which `Entity_Index::walk()` — over the
projection's merges, read by `Entity_Index::merges()`, plus the new one —
leaves a key that resolved before on a chain that cycles or runs past
`MAX_MERGE_HOPS`, naming the first such key. `add_identifier` stores the
value as `Entity_Index::identifier_value()` spells it, refuses a `domain`
that is not a plain hostname, naming why (`domain:<value> is not a plain
hostname: <why>`, from `Entity_Index::hostname_fault()`: two or more labels
of a-z, 0-9 and hyphens, none starting or ending with one, each at most 63
characters, the last alphabetic, the whole at most 253, so no IP literal,
port, path, userinfo or trailing dot), because a client's domain becomes a
URL the worker fetches, reads the holder by
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
characters fill it. A lens's lists are the exception: `save_lens` writes
`lens_saved` replacing any lens under its key, every filter in
`Graph_Query::unfiltered()`'s shape, each value of a `LENS_LISTS` list
checked against its vocabulary and each free one bounded at `KEY_BYTES`,
each tier checked against `Intel_Event::TIERS`, `by` and `bucket` against the trend sets
(`Graph_Query::check_trend()`), `days` from 1 to `RETAIN_DAYS`, each
value bounded, and its entity and topics stored as the survivors `seen()`
answers, a topic refused when its survivor is not of type `topic`, as
`tag_topic` refuses it (`<key> is not a topic`), and either refused, since every reader lists every lens, when a caller
without restricted access could not see one (`a lens naming <key> would
show it to every reader; no item outside the restricted tier cites it`);
no cap holds the lists, so `Intel_Event::make()` refuses an event past one
line, before anything is written. `remove_lens` slugs its key as
`save_lens` does and refuses `no lens: <key>`.
`fetch_now` TICKs every source in each live worker
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
offered the reads alone and every answer passes the reads' own tiers. The
door becomes the session's minting user for each call, so a session reads
the restricted tier while that user holds `Intel_Tiers::CAPABILITY`, and a
revoke takes effect on its next call; its scope neither widens nor narrows
the tier. Each
read's summary names the 730-day item horizon,
`Intel_Graph_Row_Schema::RETAIN_DAYS`, filled as `{horizon}`. The glossary
lists the entity types and link roles from `Intel_Event::TYPES` and
`LINK_ROLES`. `intel_lenses`, `intel_save_lens`, `intel_remove_lens` and
the `lens` and list args of `intel_search` and `intel_trends` come off the
schema with no door code; a variadic arg is a JSON array. So do
`intel_signals` and the five watch tools, which a read session is offered;
the door becomes the session's minting user, so a watch tool answers and
changes that user's own watches, and a watch saved through it emails that
user.

**Seeding.** `wp newspack-intelligence ontology seed` writes
`Ontology_Seed::events()` — thirty topics, the partners, funders and
industry bodies, Newspack's products, one entity per table row, and three
lenses, `leadership`, `support` and `developers` — for the keys
`Ontology_Seed::present()` does not find settled in the projection: an
entity in `entity_states` in any state but a machine's pending
`proposed`, a seed lens any `lens_saved` or `lens_removed` in `events`
names, read by subject and kind, the index `events` keeps.
So an entity a curator has accepted, rejected, merged or renamed is never
reset, a machine's proposal of a seed key is replaced by the seed's
creation, and a lens a curator saved over or removed is left alone; it
prints how many keys were new and how many present, and a
projection that will not open fails it with `Table_Unavailable` rather than
writing everything. It writes through `Stream_Writer::append()`, the
request-side writer of the ontology and watches streams, which routes each
event by `Intel_Event::stream_of()` and refuses an items-stream event, which
only the worker writes, before anything is written: per stream, a transient
Partition named `<partition>:writer`, built from that stream's declaration
in `newspack-intelligence-streams.tsl` read through `Topology_Analyzer` —
its directory and its arguments — then filled, flushed and removed in a
`finally`, so a failure propagates and a second append finds the name free.
The declared `keep_everything` and `set_read_capability` are bound
against the Partition's own declaration of each verb, by position or by
name (`Command_Args::bind()`), and honoured through the Partition's own
setters, never replayed as commands, because a command's role gate judges
the caller and would refuse a curator below MANAGE; any other
`<partition>:config` line is refused by name. A writer missing
`keep_everything` would prune the kept-forever stream on rotation, and one
missing the read gate could open the shared directory without its
`.read-capability` marker, which is why nothing copies the declaration;
this writer leaves the marker when it flushes. With the worker and
these appends both writing each of the two, `intel:ontology:consumer` and
`intel:watches:consumer` run `set_multi_writer true`, so each seals a
segment only after the grace a shared log needs. Every machine stage mints through
`Intel_Event::machine_event()`, which stamps `machine:<stage>@<plugin
version>` and that version as the `config_version`; `make()` stamps the
tick clock, which the substrate pins at class load.

**Collect.** The connector **Source nodes** (`github`, `linear`, `feed`) fetch
inside the background worker and emit one `item_recorded` event per new item to
`intel:writer`. Each records at the tier its `make_node` line names
(`make_node Feed_Source feed public`), which a load without one refuses and
`set_tier` replaces at runtime. A source fetches on a `TICK` request, which
`fetch_now` sends, and on the wall-clock grid once `set_interval <seconds>`
sets its interval: it rides the Router's tick and fetches when
`intdiv( now, interval )` passes the value it had at the process's previous
tick, so a 900-second source fetches at :00, :15, :30 and :45 whatever its
worker's age. A process's first tick only marks the clock, so a respawned
worker waits for the next boundary rather than fetching at spawn, and a
boundary that passes while no worker runs is skipped. With no interval set a
source holds no timer and only `TICK` fetches. The collect topology sets
GitHub and Linear to 900 seconds and the feeds to 1,800.

Each source supplies one seam, `tick()`, which a `TICK` request and the grid
both call; `Source_Node` declares it abstract. GitHub's and Linear's hand
`record()` what their public `fetch( $config )` finds for their `config()`;
the feed's starts a pass. Every fetch records through that one path,
`Source_Node::record()`: the blocking sources call it with a whole fetch, the
feed with each body as it lands.
It drops the keys a bounded in-memory set already holds (`MAX_SEEN` 2000,
oldest evicted first), then reads, in one read through
`Intel_Graph_Row_Schema::read()`, which of the rest the projection's `items`
table holds in any partition, and remembers those without recording them, so a
respawned worker records only items the graph lacks. A projection that will
not open records everything, under a rate-limited line naming the source, so
a broken projection costs repeats, not items. An item recorded twice, from
two workers or past a projection still catching up, the resolver reads as a
re-resolution, which runs no NER step and is forwarded for extraction only
while the item is still owed one; re-resolution proper has its own path, the
reresolver.

GitHub and Linear fetch blocking HTTP inside one `fill()`, a few dozen calls
a pass. The feed never blocks: `Feed_Source_Node` owns a `{name}:curl`
sibling, the substrate's `Curl_Node`, which follows no redirect
(`follow_redirects( false )`) and answers back through its target. A pass
sends each feed URL to it as a TM_BYTESTREAM keyed by the URL, keeps at most
`Curl_Node::MAX_IN_FLIGHT` (16) in flight and sends the next as each answers;
each body is parsed as RSS 2.0 or Atom when it lands and its items recorded
then. Every URL a feed sends, configured or a client's, first passes
`wp_http_validate_url()`, which resolves the host and refuses a private,
loopback or reserved address and a port other than 80, 443 and 8080; one it
refuses is logged rate-limited, naming it, and records nothing. A 3xx comes
back as a TM_RESPONSE carrying the absolute Location, which the feed follows
itself, at the head of the queue and checked the same way, as the same feed
— configured, or the same client with its domain — at most
`Curl_Node::MAX_REDIRECTS` (5) hops; a hop past that is logged and records
nothing. A TM_ERROR — a transport failure or a status of 400 or more — is
logged rate-limited and records nothing, an
answer for no feed in flight is dropped, and the next feed goes out whatever
the record did, so a pass always ends. A feed Curl throws on leaves flight,
and the next pass replaces whatever the throw left queued, so a throw costs
one pass, never the source. A due fetch, by `TICK` or on the grid, while
feeds are still in flight starts nothing. A scheduled fetch that throws is
logged naming the source and tried again at the next boundary, so it never
stops the worker; a cooperative stop propagates (substrate ADR-14), and a
throw on the `TICK` path propagates out of `fill()`, which no catch answers
as a TM_ERROR. So the feed's `TICK` answers
`{ fetching }`, the count of feeds the pass it starts fetches, 0 when one is
still in flight, where the blocking sources answer `{ emitted, refused }`.
Curl sends `User-Agent: newspack-nodes/<version>` (`Curl_Node::USER_AGENT`)
on every fetch, which some feeds require — Nieman Lab and Pugpig answer 403
without one — so the substrate floor rises to the release carrying it.

`set_client_feeds true`, which the collect topology sets, adds to each pass
`https://<domain>/feed/` for each `domain` identifier of every entity holding
an open `client` role, as its survivor, read once a pass through
`Entity_Index::load()` and `Graph_Query::open_roles()`; a churned client drops
out on its next pass, and a projection that will not open leaves the pass the
configured feeds alone, logged. A stored domain that is not a plain hostname
(`Entity_Index::hostname_fault()`), such as one a client CSV import
wrote, since the importer only normalises a domain, is never fetched and is named in a rate-limited line. A URL
both configured and a client's is fetched once, as configured. A client's
items stay in its lane: each is keyed `feed:<domain>:<guid>`, so no other
feed can take its key first, and records only when its link's host,
normalised as `Entity_Index::identifier_value()` spells a domain, is the
client's domain or a subdomain of it, and its `item_ts` falls from
`Feed_Source_Node::CLIENT_FEED_DAYS` (7) days before the worker's clock to
one day after it, the day ahead allowing for the publisher's own zone or
clock; an undated one never records. A configured feed keys `feed:<guid>`
and records every item, so an item two configured feeds list records once.

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
`Github_Source_Node::$http_get` and `Linear_Source_Node::$http_post` for the
blocking connector fetches, while the feed's fetches go through the
substrate's own curl seams, `Event_Framework::$curl_dispatch` and
`Curl_Node::$curl_result`, and `Feed_Source_Node::$validate_url` replaces
the one `wp_http_validate_url()` call each feed URL and Location passes, so
a test refuses a URL without DNS;
`LLM_Config::$llm_factory`, which every node using the trait holds its own
copy of (`Resolver_Node::$llm_factory` and `Extractor_Node::$llm_factory`)
and `make_llm_client()` answers first; and
`Intel_Graph_Row_Schema::$read_rows` and `Resolver::$similar_text` for the
projection read and the fuzzy step's scoring; and `Watcher_Node::$wp_mail`
for the one `wp_mail()` call every watch email goes through.
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
namespaced `"{source}:{raw id}"`, the key dedup reads, a client feed's raw id
led by its domain (`feed:<domain>:<guid>`); `body` cut at
`MAX_BODY_BYTES` (65,536) on a UTF-8 character boundary; `item_ts` 0 when the
connector's date string will not parse; `retrieved_ts` the worker's tick
clock in whole seconds, `Intel_Event::now()`, as every reader takes it.
`record()` refuses an item whose `item_key` runs past
`Graph_Curation::ITEM_KEY_BYTES` (512) as the stream writes it, JSON-escaped
(`Graph_Curation::written_bytes()`), with a rate-limited line naming the
source, the key's length and the key cut to 64 bytes through
`Core::terminal_safe()`, so no record naming it outgrows one line; it counts
as refused and is remembered, so a process refuses it once. It wraps each
in `Intel_Event::machine_event()` under the actor `machine:source:{name}@{plugin
version}`, with the plugin version as its `config_version`.

### Purging items

`item_purged { item_key, reason }`, an items-stream kind, deletes with the
substrate's `delete` op the rows the items stream has written about its
item by the time the projector reads it, in the tables that stream writes:
`items`, which `items_fts` follows through its triggers, `extractions`,
which `extractions_fts` follows, `extracted_subjects`, `links`, `claims`,
`clusters`, and the `canonicals` row of any cluster whose canonical it is.
Nothing elects that cluster another canonical; its kept members keep their
`clusters` rows. Rows another stream wrote about the item — `judgments`,
`collected`, `watch_matches`, `watch_seen` — stay, and no read answers
them, because every read joins `ri`; a collection still lists its saved
copy. An entity a machine proposed from the item (`entity_proposed`, on
the ontology stream) stays in the entity index and goes on matching other
items. A replay applies the record, then the purge, and ends purged, while
a later `item_recorded` of the same key, a source recording it again,
records it again.

An item the resolver has not reached when its purge is projected is
resolved afterwards, from the `item_recorded` its own Consumer still
holds, and the links, extraction and claims that writes outlive the
purge: no read answers them, since the `items` row is gone, and no rerun
selects them, since the selection reads that row. So purge only once
the resolver has caught up — `wp nodes status` lists the reader
`newspack-intelligence.intel-items-resolve.p<N>` with nothing behind.
A worker running a release older than the one carrying `item_purged`
drops every purge its writer receives as an unknown kind, so restart the
workers (`wp nodes restart all`) after deploying that release and before
purging.

`wp newspack-intelligence items purge --source=<name> --keep=<globs>
[--reason=<text>] [--yes]` (`Items_CLI_Command`) mints them. It selects,
across every partition, each stored item of the source whose key matches
none of the comma-separated keep globs, each matched by `fnmatch()`
against the whole key in its own case. `--keep` is required, so no command
purges a whole source, and WP-CLI keeps only the last of a repeated flag,
so the command counts `--keep` in `$_SERVER['argv']` and refuses it given
twice. Without `--yes` it writes nothing and prints the count per key
prefix, up to the first `-` and its control characters rendered by
`Core::terminal_safe()`, the total and the command that purges them. With
`--yes` it appends one `item_purged` per item, as `machine:purge@<version>`,
its `reason` the one given or the selection, at most `REASON_BYTES` (512)
as the stream writes it (`Graph_Curation::written_bytes()`). The items
stream takes no write from outside the worker, which writes it alone under
`void_warranty`, so the events go through `Worker_Route::append()` to
`intel:writer` in every live worker, `BATCH` (500) at a time: 500 events
whose key and reason each fill their cap with the most escaped characters
pack under one IPC segment, and every batch, the first included, is
appended only once `Worker_Route::read_through()` finds every live
worker's committed input cursor at its end, so the unread input never
outruns the IPC partition's count-pruned retention. That cursor commits
about every 30 seconds. Input left unread for `READ_WAIT_S` (600) stops
the command, naming how many it sent; running it again sends the rest,
because it selects only what the projection still holds. Its success line
names the dry run to rerun once the projectors have read the purges, which
answers `Nothing to purge` when none is left. `Items_CLI_Command::$sleep`
is the wait's test seam. The Linear items recorded from other teams before
0.22.0 go with:

```bash
wp newspack-intelligence items purge --source=linear \
  --keep='linear:NEWS-*,linear:NPAI-*,linear:NPPRO-*,linear:NPPM-*,linear:NPMIG-*,linear:NPLAUNC-*,linear:NPSUPP-*,linear:NPPD-*,linear:DSGNEWS-*'
```

Read the counts, then run the command it prints, which adds `--yes`.

### Topologies

| File | What it wires |
|------|---------------|
| `newspack-intelligence.tsl` | Includes every file below and wires `reresolver`, which spans the project and streams files, and `watcher`, which reads the projection and writes to `intel:writer`; `on_demand_idle 0` keeps the worker resident, which the scheduled sources need, and `stale_timeout 600` keeps a peer from stealing its lock while a GitHub or Linear fetch or an LLM call blocks one `fill()` |
| `newspack-intelligence-streams.tsl` | `intel:items`, `intel:ontology`, `intel:reresolve`, `intel:watches`, `intel:writer`, and `intel:later`, the Delay whose due records go to `intel:reresolve` |
| `newspack-intelligence-collect.tsl` | The three sources → `intel:writer`, GitHub and Linear on a 900-second grid and the feeds on 1,800 with client feeds on; includes the streams |
| `newspack-intelligence-project.tsl` | `intel:items:consumer`, `intel:ontology:consumer` and `intel:watches:consumer`, each → `projector`, writing the `intel-graph` Row_Table; no other node rides their cursors |
| `newspack-intelligence-resolve.tsl` | `intel:items:resolve` and `intel:reresolve:resolve` → `resolver` → `extractor` → `deduper`, each writing its events to `intel:writer`, the resolver and the extractor parking on `intel:later`, the items Consumer checkpointing the deduper; includes the streams |

### Nodes

`Consumer`, `Partition`, `Row_Table` and `Delay` come from the substrate. This
plugin adds:

| Type | Class | Runtime triggers and verbs |
|------|-------|----------------------------|
| `Github_Source` | `Github_Source_Node` | `<tier>`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_repo`, `remove_repo`, `set_vault_id` |
| `Linear_Source` | `Linear_Source_Node` | `<tier>`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_team`, `remove_team`, `set_vault_id` |
| `Feed_Source` | `Feed_Source_Node` | `<tier>`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_url`, `remove_url`, `set_client_feeds`; owns `{name}:curl` |
| `Intel_Event_Writer` | `Intel_Event_Writer_Node` | `<items_partition> <ontology_partition> <watches_partition>`; no verbs |
| `Projector` | `Projector_Node` | `set_graph_target` |
| `Resolver` | `Resolver_Node` | `<writer> <later>`; the `LLM_Config` verbs |
| `Extractor` | `Extractor_Node` | `<writer> <later>`; the `LLM_Config` verbs |
| `Deduper` | `Deduper_Node` | `<writer>`; no verbs |
| `Reresolver` | `Reresolver_Node` | `<stream>`; `RERESOLVE <since> <until>` |
| `Watcher` | `Watcher_Node` | `<writer>`; the Router's tick, every `WATCH_INTERVAL_S`; no verbs |
| `Intelligence_CI` | `Intelligence_CI_Node` | `search`, `entity`, `timeline`, `related`, `claims`, `trends`, `signals`, `review`, `collections`, `lenses`, `watches`, `save_watch`, `remove_watch`, `watch_matches`, `mark_seen`, each at `read`; `confirm_link`, `reject_link`, `accept_entity`, `reject_entity`, `merge_entities`, `add_name`, `add_identifier`, `assert_role`, `end_role`, `tag_topic`, `create_entity`, `create_collection`, `collect`, `save_lens`, `remove_lens`, each at `tune`; `fetch_now` at `manage` |

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
the import's events in order through one `Stream_Writer::append()`, then
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
| `IntelligencePage.js` | default | Switches Search, Entity, Lenses, Review, Collections and Watches in place; holds the submitted search, the open entity, lens, collection and watch, the watch form's draft and the item being saved, so a view returns as it was left, and a lens's "Watch this" opens the watch form on that lens; a URL carrying `watch=<key>`, as the watcher's email links it, opens the page on Watches with that watch open, or on the list when the reader's watches do not hold it; renders inside the shared `DashboardShell`, which supplies the skin, the page surface, the `NEWSPACK::NODES` header subtitled `Intelligence` and the debug overlay |
| `hooks/useIntelCommand.js` | `useIntelRead`, `useIntelWrite`, `commandArgs` | `commandArgs()` names every value given, a list once per member, leaving out a blank value or an empty list; a read asked whenever its arguments change, showing only the answer to the question it asks now, or a refusal that echoes no arguments; a write sent once per `run()`, which answers the tokens it sent, running `refresh` once it lands, handing a refused send's echoed tokens to `onRefused`, and handing back `useCommandOnce`'s own refusal and answered arguments |
| `views/Search.js` | default, `newSearch` | `search` with words, the lens's list filters (`vocabulary.lensLists`), an entity and a day range, a page at a time; a lens picked from `lenses` shows every filter it searches with read-only and sends only `--lens`, the words and the page, and "Edit as my own search" copies its filters into those fields — its days as `since`, the first of the last that many UTC days, its tiers dropped, which the view says — and drops the lens |
| `views/Lens.js` | default, `CLOSED_LENS` | `lenses`, listed and opened onto `signals --lens` above `search --lens` a page at a time beside `trends --lens`; trend rows and signals citing their items, each signal's count shown against its baseline; for every reader, "Watch this", which opens the watch form on the lens; for a curator, `save_lens` for a new lens or the one opened — kinds, topics, roles and sources comma-separated, tiers as checkboxes over `vocabulary.tiers`, the trend axis and bucket as selects over `trendAxes` and `buckets` led by "Not set" — and `remove_lens`, each held through `useHeld` until a read of `lenses` reflects it |
| `views/Entity.js` | default | `entity`, `timeline` a page at a time, `related` and `claims`; once the record answers, for a curator, `add_name`, `add_identifier`, `assert_role` and `end_role` |
| `views/Review.js` | default | `review links`, one row per item with a pill per proposed entity, and `review entities`, a pill per entity; for a curator, each pill's ✓ and ✕ send `confirm_link` and `reject_link`, or `accept_entity` and `reject_entity`, and an entity's Merge… opens `merge_entities`; a judgment hides its pill or row through `useHeld` as it is sent, until a read no longer lists it, and a refusal puts it back |
| `views/Collections.js` | default | `collections`, listed and opened; for a curator, `create_collection`, its collection shown saving through `useHeld` until a read lists it by name |
| `views/Watches.js` | default, `CLOSED_WATCH`, `newWatch` | `watches`, the caller's own, each with its target and unseen count, or "Inbox off" for a watch without its inbox, opened onto `watch_matches --key` a page at a time, or onto the words that its inbox is off, each match with a "Mark seen" (`mark_seen --key --item`) and the page with "Mark these seen", naming each match it shows, a marked match hidden through `useHeld` until a read no longer lists it and a landed mark returning to the first page; for every reader, not gated on `canTune`, the form saving a new watch or the one opened through `save_watch` — its name, the kind it follows and exactly one target (a lens picked from `lenses`, an entity key, or a collection picked from `collections`), and the three deliveries as checkboxes, each sent as `true` or `false` — and `remove_watch`, each held until a read of `watches` lists the watch with the name, target kind and deliveries the save sent, an edit by its own key, the target's key uncompared because the server stores an entity as its survivor |
| `hooks/useHeld.js` | `useHeld` | A read's rows with each write shown from the moment it is sent: a held key's row taken out until no read lists it, or a held row put in its place until a read lists one `matches` says reflects it; a refusal undoes the hold whose send it echoes, and a bare refusal every hold |
| `components/SaveToCollection.js` | default | The one dialog any result saves through, sending `collect` |
| `components/Answer.js`, `Refusal.js` | `Answer`, `AnswerBody`, `Refusal` | One read in exactly one state — owed, refused, unavailable (one sentence for every read), empty by its `rows` key, or its rows — as a stock `Card` section named by an optional title, which heads its `CardHeader`, with the state inset in its `CardBody`, or bare inside a dialog; the one alert every refusal, read or write, renders in |
| `components/Pager.js` | default | How many of the matches a page shows, and Previous and Next over a stack of page starts, for Search, the Entity timeline, a lens's items and a watch's matches |
| `components/ListField.js`, `lensDraft.js` | `ListField`; `lensDraft`, `newLens`, `draftLens`, `draftArgs`, `listOf`, `daysSince` | A comma-separated field naming the values a closed list allows; a lens read into a form's text and back into the lens and the options `save_lens` and `search` take, its list filters read from `vocabulary.lensLists` and its tiers kept beside them, the one place that knows the lens's shape; and the first UTC day of a lens's rolling window |
| `components/ItemRow.js`, `Cite.js`, `format.js` | — | An item row, headed by any control the view passes as its children; a citation linking only an http or https URL, and `EntityChip`, the one chip opening an entity; the UTC day and `metaLine()`, which joins a row's meta parts and keeps a 0 |
| `styles/intelligence.scss` | — | Geometry only, emitted as `build/intelligence/index.css` |

Each read is a `useCommandOnce` read with `retry`, under the `intelligence`
group, so `connect intelligence:shell` watches every send; its scope names
what the slice shows (`entity:record`, `review:links`, `lens:items`), so
two reads of one verb never share a Fetcher. A write is one `useCommandOnce`
per verb, held by its view rather than by a row, sending every arg named
(`--key=…`, through `formatCommandArgs`) so a curator's value beginning
`--name=` binds as a value, and a write that lands
re-reads the slice it changed. No send names a subject: an item key spells a
whole URL, past the length a reply address carries, so a refusal is matched
to its send by the arguments it echoes. Review, Lenses, Collections and
Watches show each write as it is sent through `useHeld`, because the projector records it
only on its next flush, so a re-read answers the old rows until then.
The view switcher is the substrate's shared `TabBar`, over one `tabpanel`
labelled by the selected tab. `canTune`, which `enqueue_intelligence_assets()` sets
from `Capabilities::can( TUNE )` on the page's own hook suffix alone, as
the string `'1'` or `''` because the substrate refuses a boolean extra,
only hides the curation controls: the server's role gate refuses a write the
caller may not make. The vocabulary the selects offer is `Intel_Event`'s own
lists, `tiers` among them, the trend axes and buckets as `trendAxes`
and `buckets` (`Graph_Query::trend_axes()` and `buckets()`), and
`lensLists`, one `{ field, option, values }` per `Graph_Query::LENS_LISTS`
entry, `values` null when free, localized beside it. Every string from the graph renders as text, an
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
| `newspack-intelligence.php` | Bootstrap: substrate handshake, topology registration, admin menus and the Settings page's admin-post helpers, WP-CLI, the `Intelligence_CI` mount, the MCP door's `wire()` |
| `includes/` (streams) | `Intel_Event`, `Intel_Event_Writer_Node`, `Projector_Node`, `Intel_Graph_Row_Schema`, `Url_Canon`, `Ontology_Seed`, `Stream_Writer`, the `Emits_Struct` trait |
| `includes/` (collect) | `Source_Node` and its three connectors |
| `includes/` (resolve) | `Entity_Index`, `Resolver`, `Resolver_Node`, `Extractor_Node`, `Deduper_Node`, `Reresolver_Node`, `LLM_Entity_Extractor` and the `Entity_Extractor` interface |
| `includes/` (clients) | `Client_Importer`, the `Client_Roster` interface, `Graph_Client_Roster`, `CSV_Parser`, `Clients_Settings` |
| `includes/` (LLM) | `Prompts`, the `LLM_Client` interface, `Proxy_LLM_Client`, the `LLM_Config` and `Vault_Secret` traits |
| `includes/` (service) | `Intelligence_CI_Node`, `Intelligence_Mcp_Door`, `Graph_Query`, `Graph_Curation`, `Graph_Watches`, `Intel_Tiers`, `Restricted_Settings`, `Worker_Route`, `uninstall-cleanup.php` |
| `includes/` (watches) | `Watcher_Node` |
| `includes/cli/` | `Clients_CLI_Command` — `wp newspack-intelligence clients import <csv>`; `Items_CLI_Command` — `wp newspack-intelligence items purge`; `Ontology_CLI_Command` — `wp newspack-intelligence ontology seed`, `ontology reresolve` and `ontology reproject`; `Restricted_CLI_Command` — `wp newspack-intelligence restricted grant <login>`, `restricted revoke <login>` and `restricted list`; the `Refuses_Root` trait they share |
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
  Settings, which hosts the client-CSV upload and the "Restricted access"
  section, is gated, rendered and
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
  nothing. `newspack_intelligence_grant_restricted` and
  `newspack_intelligence_revoke_restricted` grant and revoke restricted
  access to the posted login, then return to the page; a refused change
  dies naming why. All three open on `admit_settings_post()`, which refuses
  a caller below MANAGE or whose nonce for its own action fails, build
  their forms on `open_settings_form()` and return through
  `return_to_settings()`, the three helpers the plugin file declares beside
  the Settings page they serve.
- **Capability.** `newspack_intelligence_restricted`, a user capability
  granted per user, adds the restricted tier to every read (see Restricted
  tier). Nothing in this plugin grants it but an operator.
- **REST.** `POST /wp-json/newspack-intelligence/v1/mcp`, the MCP door, which
  the substrate's `Mcp_Door` serves and declares as an OAuth resource on
  `newspack_nodes/oauth_resources`.
- **Email.** The watcher sends a watch's owner, at their own address, an
  email of new matches when the watch asks for one and one digest a day
  from 13:00 UTC, through `wp_mail()`; nothing else in the plugin sends
  mail.
- **Uninstall.** Plugin delete removes every `newspack_intelligence_` option row
  and its transient variants and strips `newspack_intelligence_restricted`
  from every user's own grant, on every site of a multisite, with or
  without the substrate active, and
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
  - `specs/2026-10-29-intelligence-signals-watches-design.md` — signals,
    watches, the watcher and its email
  - `specs/2026-07-16-newspack-intelligence-rebrand-design.md` — the rename
  - `specs/2026-07-29-intelligence-actions-v7-design.md` — the release workflow
  - `specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md` and
    `specs/2026-06-15-newspack-ai-newsletter-ai-core-design.md` — the digest
    pipeline the entity graph replaced, executed by
    `plans/2026-06-15-newspack-ai-newsletter-foundation.md` and
    `plans/2026-06-15-newspack-ai-newsletter-ai-core.md`
  - `specs/2026-07-06-ai-newsletter-topology-config-migration-design.md` — why
    node config lives in the topology verbs

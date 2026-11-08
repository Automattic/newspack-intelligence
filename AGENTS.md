# AGENTS.md — Newspack Intelligence

Collects and curates intelligence as an entity graph, built on the
`newspack-nodes` substrate (sibling plugin; `Requires Plugins: newspack-nodes`).
It records GitHub, Linear, Slack, P2, Unthread and feed items as events,
links each to the entities it concerns, extracts a summary, topics and claims with an LLM,
and answers questions about them with citations — on the Intelligence admin
page, and to agents through its MCP door. Nothing is compiled: a person or an
agent asks, and the graph answers.

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
because each of them works — a minted correlation id, a pending-reply registry
or a hook naming a node class by string. `lint:php` pairs phpcs, the ABSPATH guard
sniff among its rules, with the same comment rule. lint-staged adds `scripts/reorder-node-methods.{js,php} --check`,
which orders every top-level class in a staged file: a `Node` subclass takes the
newspaper order, and every other class takes the generic one — the constructor
first, then a topological walk of the call graph. The `includes/**/*.php` and
`src/**/*.{js,jsx}` entries also pass `--all-classes`, which neither twin
recognizes — both drop an unknown `--` token — so those two entries do exactly
what the plain `--check` entries do.

PHPUnit runs from `tests/`, and the static tools run on the host. The test
bootstrap loads WordPress's own `WP_HTML_Tag_Processor` and `WP_Http`, which
the feed reads a home page with, from the WordPress install `WP_ABSPATH`
names, and refuses to start without it; `scripts/pre-push.local` sets it for
the push:

```bash
cd tests && WP_ABSPATH=<wordpress root> ../vendor/bin/phpunit --enforce-time-limit
```

`phpunit.xml` sets `enforceTimeLimit="true"` and `defaultTimeLimit="10"`, so a
test aborts at ten seconds with or without `--enforce-time-limit`, and
`failOnRisky="true"` beside `failOnWarning="true"` makes a breach exit 1 beneath
PHPUnit's own `OK, but there were issues!` line, which the push reads as a
failure. A class never buys more
with `#[Medium]` or `#[Large]`, because a test must not wait in real time — it
drives a seam, as the substrate's curl seams, `Core::$clock` and
`Event_Framework::$sleep` are there for.

After adding or renaming a Node class, regenerate the classmap (`make_node` and
the console palette read it): `composer build:autoloaders` (= `composer
install --optimize-autoloader`) or `composer dump-autoload -o`.

**The substrate floor is a handshake, and a floor set too LOW is the dangerous
error.** The loader calls `Bootstrap::version_at_least( '2.148.0' )` and stays
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
shared scripts THERE, not here. `pre-commit` then runs the private-data gate
over the staged diff, and `commit-msg` over the message, because
`scripts/pre-push.local` sets `PRIVATE_DATA_GATE=1`. `pre-push` is a shared
script too; this plugin adds no
gate of its own, so its `scripts/pre-push.local`, which the shared hook sources
and the sync never touches, holds the container constants the hook requires and
`PRIVATE_DATA_GATE=1`, which turns the private-data gate on.

`pre-push` runs the JS suite with coverage, its per-file 90% gate, the
private-data gate `scripts/lint-private-data.py` over every pushed commit,
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
`Intel_Event::stream_of()` gives its kind, and drops a message that does not name a
kind with a rate-limited line; it validates nothing, because `Intel_Event::make()`
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
extraction, which first deletes the item's claims, topic links and theme (the
substrate's `delete` op, 2.134.0) so one yielding fewer leaves none of the
earlier behind, and for a `link_retracted`, which deletes the one `links`
row its `item_key`, `entity_key` and `role` key in the file of the
partition that projects it, and for a `lens_removed`, which deletes the
one `lenses` row its `lens_key` keys; a `lens_saved` sets that row whole,
its `filters` as JSON; and for a `watch_removed`, which deletes the one
`watches` row its `watch_key` keys, a `watch_saved` setting that row whole,
its three boxes as integers; and for an `item_purged`, which deletes the
rows the items stream has written about its item in each table that stream
writes (see Purging items). A stand-in, heuristic or gated
(`Extractor_Node::stands_in()`), is projected by
`STAND_IN` instead, one `ignore` of its `extractions` row, so it fills the
extraction of an item that has none and never replaces a call's extraction, claims or
topics, whatever the projection held when the extractor chose it. A
`subjects_extracted` replaces
the item's `extracted_subjects` rows, the entities its last call extraction
was given as asserted, which the resolver settles links by. An extraction
naming a `theme` writes the item's one `themes` row, `( item_key, theme,
item_day )` keyed by `item_key`, a fan-out whose list is computed from the
event; one naming none, the stand-in among them, writes no row, so a
re-extraction naming none leaves the item without a theme. A
`theme_merged { from, into }`, an ontology event so it outlives the items
it touches, sets the `theme_merges` row keyed by `from`, a curation table
kept whole, and rewrites no item's row: every read of an item's theme reads
it through `Graph_Query::theme_survivors()`, which reads the newest
partition's `theme_merges` rows in one statement (`theme_merges()`), walks
them with `Entity_Index::walk()`, the walker `merge_theme` judges a merge
by and the entity map is built with, and binds the survivors as JSON in
the clause `tm ( theme, survivor )`, so a theme merged into another reads
as its survivor; a theme whose chain cycles or runs past
`Entity_Index::MAX_MERGE_HOPS` reads as itself, its chain logged once. An
`item_recorded` or an `item_grown` also writes, with `ignore`, one
`boilerplate` row `( source, line_hash, item_key, item_day )` per distinct
body line `Entity_Index::line_hashes()` counts: the body split at each line
break, `<br>` and closing block tag, each line read as `plain()` reads it
and lowercased, one shorter than `Entity_Index::MIN_LINE_CHARS` (12)
characters left out, hashed to 16 hex digits of its md5, so a line counts
once per item and a title never counts. A body holding more than
`Entity_Index::MAX_LINES` (200) such lines counts its first 100 and its
last 100, where a template opens and a footer closes, so one item writes
at most 200 rows. Each table is
written by one stream alone, so a replay writes
what it wrote before and the streams project the same rows in any order. The projector never reads
before it writes: an entity is three rows — identity in `entities`, state in
`entity_states`, merge in `entity_merges` — and an item's extraction sits in
`extractions` beside its `items` row, which an `item_recorded` writes once,
with `ignore`, so a repeat of the record changes nothing; only an
`item_grown` replaces it (see The item contract). A link, claim,
judgment or topic may name an entity that has no `entities` row yet; readers join
to it. A machine's `entity_proposed` writes with `ignore` and a person's
`entity_created` with `set`, so a machine never overwrites a person.
Item-scoped tables keep 730 days by an indexed day column, the item's
retrieval `floor( retrieved_ts / 86400 )`, which every item-scoped event
carries — a supersession its canonical's — so an item and every row about it
expire together however late a re-resolution writes them; `watch_matches`
and `watch_seen`, keyed by watch and item, are dated the same way, so a
match expires with its item. The ontology and curation tables, `theme_merges`, `watches`
and `watch_deliveries` declare `keep`. Ops buffer and leave as
one `ROWS` batch every 5 seconds, at 2,000 ops or 1 MiB of string values, at
each checkpoint and on a clean stop; a failed batch raises a plain stop and
keeps the buffer, so the cursor replays it. A message that carries no valid
event throws out of `fill()`, so its Consumer dead-letters it and reads on.
`Url_Canon::of()` and `Entity_Index::norm()` compute the canonical URL, the
title norm and each name's norm the readers match on.

**Rebuilding.** `Intel_Graph_Row_Schema::version()`, 3, versions the
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
so a worker restarted after the deploy creates it. Until then a file
lacks the table, and `Intel_Graph_Row_Schema::all()` leaves out every
attached partition whose file does not hold it, read from that file's
`sqlite_master` once per connection, so a read meets an empty table
rather than `no such table`.

**Resolution.** `resolver`, fed `item_recorded` events by its own
`intel:items:resolve` Consumer, which skips every other kind on the stream
and, like `intel:reresolve:resolve`, hands it one record a poll
(`set_line_mode true`), links each item to the entities it is about. `Resolver` holds the order:
identifiers in the item's URL (host by suffix walk, GitHub org and repo,
LinkedIn company, X handle, a Linear issue's team) at 1.0, the entity whose
`domain` the host is, by `Entity_Index::by_host()`'s suffix walk, linked as
the item's `publisher`; names and aliases
as whole words in title and body at 1.0, an acronym only in its own case at
0.9, and a name led by `the`, which `norm()` drops, only where its first
word past the article is capitalized, the rest in any case, and a single
such word only right after `the` or `The` as `Entity_Index::plain()` reads
the text, so markup or an entity between them never hides the article
(`Entity_Index::names_in()`): neither "leaves no echo" nor "Leaves No Echo"
names The Echo, while "the Echo", "THE ECHO", "Harbor Gazette" and "Grant
To The Harbor Gazette" name theirs; a domain's stem in the title at 0.9,
when it runs at least `MIN_STEM_LENGTH` (6) characters, aligns to word
boundaries and reads as a name (`Resolver::reads_as_name()`), each word
capitalized but a lowercase `de`, `in`, `of`, `and` or `the` between two
that are, and a span of several words not led by "A" or "An", so
`thecitizen.example`, whose label spells the article, reads "The Citizen"
while "A Newsroom" never reads as `anewsroom.example`, and never in a body, which is raw
markup where an href spells a domain; and only when those find nothing, the NER extractor's organizations and products fuzzy-matched
against the names sharing a distinctive word with them — asserted at 0.85, proposed at 0.60, below that a
candidate entity of the type it was named as (at most `MAX_PROPOSALS`, 3,
and never a name a rejected entity carries). A distinctive word is one
outside `Resolver::GENERIC_NAME_WORDS`, the words that say what kind of
publication or organization a name is rather than which one, so "Harbor
Journal" never pairs with "Kestrel Journal", and a name holding none cannot
match. An exact name needs no such word and scores 1.0. A name shaped like
code is refused before any of that, logged rate-limited `NER name left out,
shaped like code: <name>`, in one place, `Resolver::recognized()`, because
only the resolver sees the index: one holding a `_`, a backtick, `::` or
`()`, one lower-camelCase word (`apiFetch`), or a bare domain or file name
(`DESIGN_SYSTEM.md`). A word led by a capital passes whatever capitals it
holds, so ProPublica and McClatchy are proposed, and a curator rejects an
identifier spelled the same way (`CategoryAutocomplete`). An entity past
proposal that holds exactly a refused name keeps it, so a lower-camelCase
brand links its accepted entity. `Prompts::extract_entities()`
asks for `orgs` and `products` apart, a tool, platform, vendor product or
service being a product, never an organization, and
`Prompts::parse_entities()` reads a missing `products` as none and leaves out,
logged, any name past `Graph_Curation::NAME_BYTES` (256), so a proposal
fits one line. The rejected entities are the stoplist: a deployment's seed
rejects its own teams, processes and generic words (see Seeding), and a
curator adds a name by rejecting the proposal that carries it. The
stoplist has two scopes. A proposal is blocked by a rejected name whatever
type the model gives it. A link is blocked by type: a name a rejected
entity of type T carries never links an entity of type T by a name, alias,
acronym, domain-stem or NER fuzzy match, even an accepted one, while an
entity of another type still links by it. `Entity_Index` keys each
rejected name by its entity's type, `is_rejected_name()` answering for any
type and `stoplisted()` for one entity's, a rejected entity's localities
and acronyms never among them. `names_in()` marks each hit that
`stoplisted()` holds, except an acronym's hit, which matches only in its own
case, and
`Resolver::stemmed()` each stem hit whose squashed stem or surface's norm
it holds, so "News Room" is the rejected Newsroom; a marked hit still
claims its span, so a shorter name inside it drops, and then links
nothing, while equal spans beside it are each proposed at 0.5. The fuzzy
step scores no name `name_norms()` leaves out for its type, judges a tie
before it drops any winner `stoplisted()` holds for the name the extractor
gave, and proposes what a tie leaves. So a
client organization whose site title is a generic word the seed rejects as
an organization is not linked to every item that uses the word. An
identifier is not a name and still links, so the client's own site links it
as `publisher`, and an alias the rejected entity does not carry links it as
any alias does. An organization proposed before tools were asked
for as products is corrected by hand: `create_entity` the
`product:<slug>`, then `reject_entity` the mistyped organization, whose
name then proposes nothing again while the product's links it. A name whose norm the index holds exactly scores 1.0 with no
scan; otherwise only names whose byte length can reach the proposing band
are scored, because similar_text's ratio never passes 2·min/(sum). Name and stem hits whose spans overlap in one text settle
together: the longest span wins and a shorter hit drops, while equal spans
naming several entities assert the one whose locality the text also names,
written capitalized, at 0.9; else, when exactly one of them is accepted or
created and the rest are machine proposals, assert that one at the strength
its hit earns and link the proposals not at all; else propose each at 0.5.
A locality never drops a name. The host's entity published the item and is linked
`publisher`, never `subject`, apart from any link the text earns it, so a
client's own story is about the client only when its text names it, and a
publisher spares the NER step as any link does. An entity the URL's path or
the title names is the subject, one found only in the body a mention, and
each entity keeps its strongest such link, but the item's own publisher
found only in its body is not mentioned: a site naming itself there, as a
feed's "appeared first on" footer does, is not news about it.
`Entity_Index` reads accepted and proposed entities off the projection through
`Intel_Graph_Row_Schema::read()`: names by norm, identifiers by `kind:value`, a
merged entity's names and identifiers answering its survivor; an entity
whose merge chain cycles or runs past 10 hops is left out, logged once, and
the rest loads. A link to a proposed entity is `link_proposed` at whatever
confidence its method earned, so a machine's guess never asserts.
`Resolver_Node` (`make_node Resolver resolver intel:writer intel:later intel:llm`)
writes a `link_asserted` or `link_proposed` per link, a `link_retracted` per
stored link it no longer writes, and an `entity_proposed` per candidate,
keyed `Intel_Event::entity_key()`, to the writer its `make_node` line names,
by TO and declared as an extra target, then forwards `{ item, links,
proposals, extracted, attempt, extract, publisher }` to its target, `extractor`. It resolves
nothing while a projector's reader of the ontology stream
`Stream_Writer::stream_dir()` names had not committed the stream's end
(`Projector_Node::behind()`, over `Consumer_Node::lag_from_disk()`) just
before the index was read, or while its index holds no live entity, the two
in that order (`Entity_Index::hold_cause()`), so a seed not yet projected
holds as `behind_ontology` rather than as an empty ontology:
`Entity_Index::current()` takes
that reading first at every load, every `Entity_Index::TTL_S`, whichever
reader triggers it, so a flush and commit landing between the two reads can
make the index only fresher than the reading says. The projector flushes at
every checkpoint, so a cursor at the end means every ontology event is
projected, and a false hold only parks a record a minute. The resolver's
own `entity_proposed` events leave that cursor short until the projector's
next checkpoint, up to 30 seconds, so items following a proposal may park a
minute; nothing is lost. Parking before the NER step was asked spends no
call, but a hold met when a NER answer comes back parks the record `{ ner:
true }`, its answer unused, so that item costs one call more.
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
`ner`, `extract`, `attempt` and `parked_at` are optional, and a record
carrying one that does not read is dropped with the rest. An `item_reresolve`
carrying `extract: true`, which a `REEXTRACT` range and the extractor's retry
of one mint, is forwarded whatever the item holds, so the extractor calls the
model for an item a call already extracted with its links settled. `Resolver::resolve()` answers
`recognized` beside the links, whether its NER step ran and answered:
`Prompts::parse_entities()` answers none, logged rate-limited, for a reply
whose `orgs` is not a list (none, an error object, a scalar), and a failed
call, logged `NER call failed: <error>`, or a group lending no credential
answers none too, so a failure is told from an answer naming nobody.
A resolution retracts each stored non-topic `links` row of the item in this
worker's own partition's file, the only one its projector deletes in, by
its raw entity key and role, that it does not write again, so a link whose
role changed is retracted in its old one; an about-link whose survivor a
curator confirmed is kept unless the resolution finds its entity again by the
same method in another about-role, which replaces it, so a host link a
curator confirmed as `subject` stays beside the `publisher` link the host now
earns, and so is a `ner_fuzzy` row unless the NER step ran and answered,
since nothing else looked for it again. A judgment does not name a
role, so it governs an item's about-links alone and never a `publisher` link, and
the extractor is sent one link per entity, an about-link over a publisher
one, and, apart, as `publisher`, the entity the item's `publisher` link
names, '' for none, so a publisher the text also names is not lost. Every resolution reads
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
whether or not any resolution ran, so a first recording may arrive with
its row projected or before it. Such a
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
made — a `machine:extract-heuristic@…` or `machine:extract-gated@…`
stand-in does not count, matched through `Intel_Event::machine_prefix()`
of each of `Extractor_Node::STAND_IN_STAGES` —
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
and forwards the item when the projection holds no such extraction, or when, with
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
$item, $ner )` takes the NER step per call as none (`false`), owed
(`true`) or answered (the names `Prompts::parse_entities()` read): an owed
step the first three steps leave to run answers null and writes nothing,
so the node asks `intel:llm`, its third argument, also declared, naming
the `extract_entities` prompt, 300 tokens at temperature 0, KEY the
`item_key`, the item beside the record it was filled with, which rides as
the carry without the item's fields, as backlog when the record carries
`extract: true`. The answer, a TM_STRUCT|TM_RESPONSE `{ content | error |
unlent, item, inputs, carry }`, runs the record, the carry with the item's
fields laid back on it, through its whole path again,
hold and projection read included, with the names in hand, or with none
for an error or an unlent group; an answer it cannot read is dropped with
a rate-limited line. An answer whose resolution throws is logged
rate-limited, `an answer failed for <item>: <why>`, and parks the record on
`intel:later` as the hold parks it (`park()`): an `item_recorded` as `{
event: item_reresolve, item_key, fresh_after: 0, ner: true }`, an
`item_reresolve` as it was read, its `parked_at`, `fresh_after` and
`extract` kept, so the Delay ages it out a day after it first parked, and
its `attempt` counted one on; from `Extractor_Node::MAX_ATTEMPTS` (5) it
parks nothing, logged `giving up resolving <item> after <n> attempts`, so
a poisoned item stops asking the model. Nothing throws into the event
loop but a cooperative stop. Any other re-resolution hands none; the
`Resolver` itself is rebuilt only when the index is.
Every link event carries the item's `retrieved_ts`. A `link_proposed` and
an `entity_proposed` carry `evidence`: the first of the title's and the
body's sentences, each read by `Entity_Index::sentences()`, that spells the
name the link or the candidate was found by, the name read by
`Entity_Index::plain()` too, so one a line or a tab breaks still matches,
case aside, cut on whole characters to `Intel_Event::EVIDENCE_BYTES` (512)
as the stream writes it, from 60 characters before the name, an ellipsis
marking each cut; '' for a link an identifier found, or a name that is not
spelled in any sentence. `Resolver::linked()` alone decides that a proposed link carries it
and an asserted one does not. Each `Intel_Event::KINDS` row ends with its
optional string fields and the bytes each takes, `evidence` on those two
kinds; one written before proposals carried it has none, `validate()`
refuses one that is present and not a string within the cap, and the
projector writes a missing one, and every asserted link's, as ''. An
`extraction_recorded` carries `theme` the same way, at
`Intel_Event::THEME_BYTES` (80), and `validate()` also refuses one that is
blank, so an extraction naming no theme leaves the field out. The
extractor is sent each link without it. The resolver and the
extractor share the process's one
`Entity_Index::current()`, read again every `Entity_Index::TTL_S` (60
seconds) and when an `item_reresolve` record's `fresh_after` falls after its
last read, so one signal's records cost one read; with no `intel-graph`
table it returns no entities and logs once. Boilerplate is read with the
item: `Resolver_Node::stored()`'s one read also answers which of the
item's body lines at least `Resolver::BOILERPLATE_MIN_ITEMS` (8) items of
its source carry, the item counted once whether or not it is projected
yet, in one statement whatever the body's line count: per line hash, one
arm per partition holding the table (`Intel_Graph_Row_Schema::holding()`,
since SQLite pushes no correlated term into a compound), each a search of
the key over `( source, line_hash )` stopping at 7 other items, so a footer
every item carries costs what a line eight items carry does.
`Resolver::resolve( $item, $ner, $boilerplate )` reads the body without
those lines (`Entity_Index::without_lines()`), so a name, alias or acronym
only a checklist template, a tool's footer or a ticket heading spells
links nothing; the title, which names the item's own subject, and the URL
are read whole, so an unfilled template still links what its URL
identifies. The NER request carries the lines' hashes as its `boilerplate`
input, at most 200, which `Prompts::messages()` refuses a request without,
and `Prompts::extract_entities()` leaves them out of the body it sends; the extractor is sent the whole body. The table counts only items
recorded after it was declared, so `ontology reproject` counts the rest.
Every projection read goes
through `Intel_Graph_Row_Schema::read()`, which given a line logs a
projection that will not open and answers null, and given none throws. An `item_recorded` it cannot read
throws, so the Consumer dead-letters it. A topic is never matched in text:
the index offers each live topic by its canonical name through `topics()`
and indexes none of its names.

The resolver publishes its hold through `Publishes_Status`, under its name
and worker partition, for `Resolver_Node::STATUS_TTL_S` (30 days), from
`publish_hold()`, which `fill()` calls with each record's
`hold_reason()`: `reason` (`no_live_entity`, `behind_ontology`, or '' when
nothing holds) and `since`, the first hold of the standing reason, read
back by a recycled worker and restarted at now when the reason changes,
null once nothing holds. Once the clear is published, a passing record
reads and writes nothing until the next hold. How many records wait, and
the days of any it dropped, the `intel:later` Delay publishes itself (see
Health).

**Model calls.** `LLM_Call_Node` (`make_node LLM_Call intel:llm ai-proxy`)
makes every model call without blocking the worker. A caller sends it a
TM_STRUCT whose KEY names its subject and whose VALUE is `{ prompt, item,
inputs, options, carry, backlog }`, `prompt` one of `Prompts::PROMPTS`,
which names each prompt's kind: the chat prompts `extract` and
`extract_entities`, and the decision prompts `extract_review` and
`extract_gate`. `options`
holds nothing but an integer `max_tokens` and a numeric `temperature`, and
nothing at all for a decision, and the carry what the caller
needs back less the item and the inputs, which ride beside it once. One
it cannot read is refused TO its FROM, `{ error: "<name>: a request it
cannot read: it carries no <what>", item?, inputs?, carry? }`, with
whatever of the three reads, so the caller's own finish runs and parks the
item; only one naming no FROM is logged and dropped. Both callers send
and read through `Asks_Model`, which runs each finish inside
`Event_Framework::uninterruptible()`, since the call is settled and nothing
replays its writes, so a stop falling due inside raises at the first check
after it, and which also catches what finishing an answer
throws, rethrowing a stop, logs it and has the caller park the item. With its `set_api_url`, `set_model` or `set_feature`
unset it answers a chat request an error naming them, and with
`set_api_url`, `set_decision_model` or `set_feature` unset a decision
`<name>: set_api_url, set_decision_model and set_feature before a decision`; where its Vault group,
its one argument, holds no entry on the api url's origin (`Curl_Node::lends_credential()`)
it answers `{ unlent: <group>, item, inputs, carry }` at once and calls
nothing. Otherwise it adds the request to the `{name}:frontier` sqlite
Table it owns, through the substrate's `Frontier`, as the member
`<0|1>/<arrival>/<from>/<key>`, `<arrival>` the microsecond it arrived,
zero-padded to 16 digits and one past the last when the clock has not
moved, and `from` and `key` `rawurlencode()`d, with the value `[ from,
key, prompt, item, inputs, options, carry ]`, so the state an answer needs
lives in the member and the item's body is stored once. Every request is
its own member, so two for one item each get their own answer. On the
Router's tick it publishes `{ queue, at }` through `Publishes_Status`,
under its name and worker partition: `queue` is `{ new, backlog,
in_flight }`, `new` and `backlog` the members its Table counts waiting
under `0/` and `1/` (`Frontier::count()`, one `SCARD` each) and
`in_flight` the calls this process has sent and not settled, or null when
a count is not answered, never a zero; `at` is the second it was written.
A request, a take or a settle since the last publish landed makes the
queue due once `QUEUE_PUBLISH_S` (5) seconds have passed, an unmoving one
once `STATUS_HEARTBEAT_S` (300) have, and a clock stepped back counts as
either having passed; a new process owes one, so a successor publishes
what it inherits. A change stays owed until a counted snapshot lands, so
a null count or a write the cache did not take (`publish_status()`
answers false) is tried again at the next tick past the interval, and a
throw is logged, `publishing the queue failed; trying again at the next
tick`, and tried at the next tick, a stop propagating. The snapshot lives
three heartbeats; the `health extraction` slice reads it (see Health). The POST
body is built when the request is sent: a chat prompt's is `{ model,
messages, ...options }`, the messages `Prompts::messages()` builds from the
prompt, the item and the inputs; a decision's is `{ model, state,
questions }`, the decision model's, the state and questions
`Prompts::decision()` builds. A decision prompt asks the questions
`Prompts::questions()` reads off its inputs; one whose inputs call for
none is refused as `<prompt> asks no question` and never sent. The
questions are `about_publisher`, a `noul`, when the inputs name a `publisher`, asking
whether the article is about the publisher itself or the news industry;
`fits_kind`, a `noul`, when `Prompts::KIND_CHECKS` holds their `kind`,
asking that kind's question, which says what a reply gives the kind and it
does not fit, such as a ticket tracking a launch, a publication asking its
readers to donate, a setting changed for one site or a release candidate;
and `theme_choice`, a `choice`, when they offer `themes`, asking which
names the event or initiative the item is part of, its `criteria` each
offered theme keyed `t1` to `tN`, told as `<theme>: <example>`, then
`none`, "none of these: this item is part of a different event or
initiative", `Prompts::chosen_theme()` reading a key back as its theme.
An `extract_gate` names the `publisher` alone in its inputs, so it asks
`about_publisher` alone; an `extract_review` asks whichever its inputs
call for.
A decision's state leads with `Source: <label>`, `Prompts::SOURCE_LABELS` saying what
the item is (a ticket in a work tracker, an internal blog post or chat
message, a published article), any other source its own name, since a ticket
scored as news without it, then `Publisher: <name>` when one is given, `Title: <title>`,
`Text: ` the body's plain text (`Entity_Index::plain()`) cut to
`Prompts::DECISION_TEXT_CHARS` (2,000) characters, since an article's lede
and first paragraphs say whom it is about, and `Summary: ` the reply's
summary when one is given. Both bodies are JSON-encoded, so a name, a
title, a theme or an example carrying `</item>` is data. A stored request that will not send, its
prompt unknown, as a row of an older shape names none, its body
unencodable or Curl throwing on it, is settled and answered `{ error }`,
`<name>: a stored request could not be sent: <why>`, and the next one
goes out; a take the Table cannot answer is logged, `a send failed; trying
again at the next tick`, and never thrown into the event loop. The Table
hands its lowest members first, so a request with `backlog` false, `0`,
goes ahead of every backlog request, `1`, queued before it, and within
each the one that arrived first goes first: an item recorded now is never
queued behind a re-extraction. Its `{name}:curl` sibling, built under
the group with its body cap raised to 1 MiB (`Curl_Node::set_body_cap()`),
POSTs a chat prompt to `<api_url>/chat/completions` and a decision to
`<api_url>/systemone`, the same origin and credential, each with `X-WPCOM-AI-Feature:
<feature>` and the group's credential, `Authorization: Bearer <token>`,
and a `timeout` of `Curl_Node::MAX_REQUEST_TIMEOUT` (120) seconds, at most
`set_concurrency` at once (1 until set, at most `Curl_Node::MAX_IN_FLIGHT`),
refilling the window once half of it, or all of it, is free, on a request,
an answer and the Router's tick. Each answer is settled in the frontier and
sent TO the request's FROM, keyed as asked, a TM_STRUCT|TM_RESPONSE whose
VALUE is `{ content, item, inputs, carry }`, the first choice's
`message.content`, or `{ error, item, inputs, carry }`: Curl's TM_ERROR
text, such as `HTTP 502 <url>` or
`curl error 28 (Timeout was reached) <url>`, or `AI proxy answer names no
choices[0].message.content`. A decision answers `{ decision: { <question>:
<answer> }, item, inputs, carry }`, each question asked read from the
body's `answers`: a noul's `noul` from 0 to 1, and a choice's `{ choice,
confidence }`, its `choice` one of its criteria and its `confidence` from
0 to 1. Otherwise it answers `{ error, … }`: Curl's text, `AI proxy
decision names no noul from 0 to 1 for <question>`, or `AI proxy decision
names no offered choice with a confidence from 0 to 1 for <question>`, for
the first answer that is missing, out of range or not of its type. Chat calls and decisions share the one frontier, window and
backlog order. A caller that throws on its answer is logged
rate-limited, `answering <key> failed: <why>`, never thrown into the event
loop; a cooperative stop propagates, and starts no new POST. Only an
answer under the FROM the node minted, which its Curl copies back, settles
a request: any other message keyed as a member, as a forged TM_ERROR, is
logged and settles nothing. A request in flight when the worker stops
stays in the Table's `inflight` set, and the next process's first take
sends it again; a clean stop's `shutdown_sweep()` removes the answered
ones, so none is sent twice. The queue is durable and not capped by count;
`ontology reextract --limit` bounds a backlog. Every value carries one item
body, up to 64 KiB, for as long as it waits.

**Extraction.** `extractor` skips a resolution with no link and no
candidate, unless it carries `extract: true`, which is never skipped; for any other it asks `intel:llm`, its third argument, also
declared, for one call, 800 tokens at temperature 0, KEY the `item_key`,
naming the `extract` prompt with the item, and as its inputs the topics it
offers, by name and key, the profile, the audience and the publisher,
carrying the resolution less its item, as backlog when the resolution carries
`extract: true`; the answer hands the item and the inputs back, and
`Prompts::messages()` offers the names of the topic map. The publisher is
the name `Graph_Query::name_of()` shows for the survivor of the
resolution's `publisher`, as the shared index reads it, when that survivor
holds an open `client` role (`Graph_Query::open_roles()` over the keys
`Entity_Index::keys_of()` answers, the inverse of its merges, built once
per index), read when it asks; '' when the item has no publisher entity, the publisher's survivor is not an open
client, a former one among them, or the projection will not open, logged.
A resolution whose `publisher` is not a string is dropped as unreadable. It offers the
themes of the items most like this one as the projection holds them when
it builds the messages, as `intel:llm` sends the request, so a request
queued before a similar item was themed offers that theme, and never any
its inputs carry. Similar items are the `Prompts::THEME_NEIGHBOURS` (12)
themed items whose title best matches the item's title words, by `bm25`
over `items_fts` under the `{title} :` column filter, since a title states
the problem and a body drags in common words, a tie by key: the words are the title's runs of
letters and digits, lowercased, each once, holding a letter, at least two
characters long and not a stop word, the first sixteen in title order, each
one quoted phrase (`Intel_Graph_Row_Schema::fts_phrase()`) joined by `OR`,
so `bm25` rather than word length weighs how rare each is. bm25 ranks
within one partition's index, so the rank assumes the single partition
every topology runs. A neighbour is another item, retrieved within
`THEME_DAYS` (30) days either side of this item's own retrieval, the last
day included, so a re-extraction of an old item reads its neighbours of that time, of a tier
in `Intel_Tiers::UNRESTRICTED`, so a theme never travels from a restricted
item into one a lower tier reads. Its theme is the newest partition's, by
`partition_ordinal`, as `Graph_Query::stories()` reads one, and a neighbour
whose theme names a kind or one of `Prompts::GENERIC_THEMES` is passed over.
Their distinct themes are offered in the order of each one's best match,
each with that match's extraction summary as its example, the newest
partition's, else its title when the summary is empty, cut to
`Source_Node::TITLE_CHARS` (120) on a character boundary, since a title
such as a bare domain says nothing. After them the offer adds the
`Prompts::THEME_POPULAR` (12) themes the window uses most, each one the
neighbours have not already offered: of the other items of a tier in
`Intel_Tiers::UNRESTRICTED` whose `themes` row, the newest partition's, is
retrieved within the same days, restricted items counting nowhere, not even
toward which themes are candidates, the themes counted by distinct stories, a
story its cluster's canonical, each read from the newest partition, when the
canonical's tier is unrestricted, else the item, the most stories first,
then the newest story by its retrieval day, then by theme, each with its
newest story's summary as its example, else that story's title. Every
offered theme, a neighbour's or a popular one, reads as its survivor
through `Graph_Query::theme_survivors()`, and one that names a kind or one
of `GENERIC_THEMES` once read is passed over. The popular count is exact
for the `THEME_CANDIDATES` themes that hold the most of the window's
unrestricted items' `themes` rows and reads no other, so it costs the same on any window. The read is one statement: the `MATCH`
arms per partition, materialized once, each keeping the
`Prompts::THEME_CANDIDATES` (36, three times `THEME_NEIGHBOURS`) best
matches holding a theme it may offer, any partition's by an `EXISTS` over
`all( 'themes' )`, so what follows costs the same on a title of common
words as on a rare one, and the themes and summaries of
those keys alone, each read through its table's key, the themes within the same days; the popular themes read
the window's `themes` rows through their day index and every item, cluster
and canonical through its key, in the same statement, after the one that
reads the theme merges. Nothing is cached, a title holding no such word reads nothing, an
item naming no integer `retrieved_ts` is refused, an item no themed item
resembles is offered none, and none is offered, logged once, while the
`intel-graph` tables cannot be read. `Prompts::extract()` builds the
prompt, which asks for a summary of two or three sentences, what happened,
who it affects and why it matters, read from the item alone and saying only
what a thin item says, and carries the item as JSON (tags hex-escaped)
inside an `<item>` fence the system message names as untrusted and offers
the index's topic names; any themes offered follow the item as a JSON list
of `{ theme, example }` in a `<themes>` fence, tags hex-escaped, which the
system message names as untrusted as the item, and never in the system
message itself, the examples with them. An item a client published names
its publisher as `publisher` inside the item's fence, never in the system
message, and the kind clause then says the publisher field names a Newspack
client and reads an article a publication runs about anyone but itself as
`other`, keeping its kind when it is about the publication itself (its
awards, fundraising, launches, staff or finances) or about the news
industry (journalism, publishing, funding for newsrooms, or the platforms
and tools newsrooms use); an item no open client published carries
neither, its prompt unchanged. It defines
every kind in one line each from `Intel_Event::KIND_DEFINITIONS`, which
lists each of `ITEM_KINDS` in its order, and where a kind is often
confused, says what it is not: `bug` is a defect in Newspack's shipped
code as Newspack files or fixes it, never a CRM record, one site's content
or configuration, a launch snag list or internal tooling, and is told
apart from `complaint`, a client's dissatisfaction; `feature_request` is a
client or user asking for a capability Newspack's product lacks, never a
setup, styling, migration, onboarding, offboarding or configuration task
for one site; `incident` leaves out a storage-quota warning, a legal
notice and a delayed project; `launch` is a site, product or publication
going live or being prepared to go live, a client's or Newspack's, never
an internal tool, process or deploy going live; `research` is a study,
survey, report or analysis of the news industry, its audiences or its
business, as published, never Newspack's or Automattic's own engineering,
audit, performance or planning work;
`release` is a software release or version shipped for general use, and
a pre-release, release candidate, alpha, beta or nightly build is `other`;
`departure` is a person
leaving an organization or a role, never a client leaving Newspack, which is
`churned`; `churned` is a client that
has left Newspack or is leaving, offboarding underway or done, the decision
already made; `churn_signal` is a hint that a client may leave, such as
dissatisfaction with price or service, going quiet or weighing a
competitor, and leaves out a client already offboarding, a client adopting
more Newspack and routine account and storage work; and
`other` takes routine service and onboarding work, internal planning,
internal tooling, audits and engineering reports, team updates and kudos, and a publication's own journalism about anyone
else; and it
asks for a `theme`, two to six words naming the event or initiative the
item is part of, at the level a reader tracks it across clients, with no
client names, URLs or numbers, such as "community site launch" or "site
blocked from sending email", never a step within one such as "redirect
rules for launch" or "launch checklist", never a category of work such as
"ui fixes" and never a kind, two items sharing a theme only when they are
part of the same event or initiative; only when there are themes to offer
does it add the clause reusing a fenced theme only when its example, a
summary, is part of the same event or initiative as this item, and writing
a new one otherwise. The system message frames the reading for the
audience `set_audience` names, and without an audience while `set_audience` has set none, and scores
against the `add_profile` lines; the shipped topologies set neither, so a
deployment supplies both (see Topologies). `add_importance_floor <kind>
<min> [<relevance>]` sets a kind's least importance, 0 to
`Extractor_Node::MAX_FLOOR` (9), and the relevance an extraction needs
before the floor lifts it, 0 to `Prompts::MAX_SCORE` (10), the scale
`Prompts::parse_extract()` clamps to, and 0 when omitted; a second for the
same kind replaces the first, and it refuses a kind outside
`Intel_Event::ITEM_KINDS`, a min outside its span and a relevance outside
its own, each naming the value, and `remove_importance_floor <kind>` drops
it, refused when the kind has none; `dump_config()` replays one add line per
floor, its relevance only when it is not 0. After the call, and on the heuristic
stand-in, whose kind is `other` and whose relevance is 0, alike, the
importance written and forwarded is the greater of the score and its kind's
floor when the relevance is at least the floor's, else the score, so the
stand-in takes only a floor that names no relevance; the shipped
topologies set none. `Prompts::parse_extract()` reads the first `{…}`
block through `Prompts::json_object()`, which the NER step shares, cuts the summary to `Prompts::SUMMARY_CHARS` (600) characters, reads an unknown kind as `other`,
reads the theme as inert words — lowercase, each run of anything but
letters, hyphens and apostrophes one space, so no digit, quote or
punctuation survives, its ends trimmed of spaces, hyphens and apostrophes,
cut on a character boundary to `Intel_Event::THEME_BYTES` (80) as the
stream writes it — and leaves it out when that leaves nothing, or when it
names an item kind, its underscore a space, or one of
`Prompts::GENERIC_THEMES` (`update`, `news`, `issue`, `bug report`,
`general`, `misc`, `request`, `announcement`), which say nothing, `other`
being a kind already,
clamps the scores and keeps at most five claims with a predicate from the
closed set. It writes an `extraction_recorded` naming topic keys, and its
`theme` when the answer names one, then a
`subjects_extracted` naming the entities it was given as asserted, and a
`claim_asserted` per claim whose subject the item links by an asserted
link, taking that link's confidence; a subject linked only by
`link_proposed` does not assert a claim. Subject and object resolve through every
entity whose name spells the whole of them in `Entity_Index::names_in()`,
read in any case, since the model writes them and not the item, a
stoplisted hit aside unless the item asserts its entity by an
identifier, so a client's own site naming it still asserts its claims:
the subject is the one of those the item asserts, and the object is the
only one, or the only one the item links, else it carries no key, so
two entities sharing a name never resolve by load order. Every claim keeps
the object as the item spelled it in `object_text`, keyed or not, which a
read answers to a caller who cannot see the object's entity. The answer
finishes the extraction: its topics resolve through the names the request
offered, and its claims through the index current when it lands. An
unlent group, a failed call or a reply that does not parse records a heuristic
extraction instead — the first of `Entity_Index::sentences()` of the
title and of the body, markup stripped and entities decoded, kind
`other`, no topics, scores 0 — as `machine:extract-heuristic@<version>`, so
the item is never retried in a loop; a later re-resolution extracts it
again. A call made that fails — an error or a reply that does not parse,
never a cooperative stop and never an unlent group — and an answer whose
extraction throws, logged `an answer failed for <item>: <why>`, also
parks, once per failure, after the write and never again if the write then
throws, `{ event: item_reresolve, item_key, fresh_after: 0, attempt: attempt +
1 }`, with `extract: true` when the resolution it read carried it, on
`intel:later`, the extractor's second argument, with KEY its
`item_key`, due
`RETRY_BASE_S * 2 ** attempt` (300 seconds doubled) capped at `RETRY_MAX_S`
(3,600), while `attempt` is below `MAX_ATTEMPTS` (5), so every retry lands
inside the day the queue keeps; at the cap a line says it gave up. The
replay is a re-resolution, which forwards the item for extraction only
while it is still owed one, and the resolution it reads carries that
`attempt` and `extract`, which a resolution lacking either is dropped for.

A client's own feed item is gated before its chat call: an item whose key
reads as a client's lane, `feed:<domain>:<guid>`
(`Feed_Source_Node::lane_of()`), whose publisher names an open client,
whose tier is in `Intel_Tiers::UNRESTRICTED` (`Intel_Tiers::unrestricted()`,
the test the review applies too) and whose resolution says no call
extracted it (`extracted` false). A lane item a call extracted before is
extracted again and reviewed, its review asking `about_publisher`, so a
stand-in never stands in for an extraction it holds. The extractor first asks
`intel:llm` for one `extract_gate`, its inputs the publisher alone, no
options, its carry the resolution and `gate: true`, as backlog when the
resolution is, so the decision states the source, the publisher, the title
and the text and asks `about_publisher` alone, with no summary yet. A noul
below `Extractor_Node::ABOUT_PUBLISHER_MIN` (0.5) spends no chat call: it
writes the heuristic stand-in under `machine:extract-gated@<version>`
(`Extractor_Node::GATED_STAGE`), kind `other`, no topics, scores 0 and no
`subjects_extracted`, which, like the heuristic stand-in, the projector
never lets replace a call's extraction and the resolver does not count as
one, so a re-resolution, `ontology reextract` and `extract: true` reach the
item again. A noul at the bar or above asks the `extract` call as any item
does, its carry naming the `about_publisher` noul, so its review asks that
question no more. A gate that fails, its group lending nothing, or one
whose `about_publisher` does not read is logged `extract gate failed: <why>
for <item>`, rate-limited by the failure, and the item is extracted as any
other is, its review asking `about_publisher` itself; it parks no retry. A
restricted item is never gated, so it never reaches the decision model, and
a configured feed's item, `feed:<guid>`, which may carry any publication's
article, is never gated.

An answer that parses is owed a review by the decision model when its
item is not restricted, since a restricted item's data stays with the
chat model alone, and when any of three questions applies.
`about_publisher` applies when the item's `inputs.publisher` names an
open client, its kind is not `other`, and its gate has not already
answered the question. `fits_kind` applies when `Prompts::KIND_CHECKS`
holds its kind, because the extraction model types a launch checklist
`launch`, a reader appeal `funding`, a one-site task `feature_request` and
a pre-release `release` whatever its definitions say. `theme_choice`
applies when the projection offers it themes, read again as the answer
lands through `Prompts::offered_themes()`, as an `extract` prompt offers
them. Such an answer writes nothing yet: the
extractor asks `intel:llm` for one `extract_review`, every question that
applies in one request, its inputs the topics, the publisher, '' when
that question does not apply, the themes, the reply's summary and its kind, no
options, its carry the resolution and the extraction's reply, as backlog
when the extraction was. Its answer finishes the extraction once, from
that reply, read again by `Prompts::parse_extract()`: a noul below its
bar, `Extractor_Node::ABOUT_PUBLISHER_MIN` (0.5) or its kind's `min` in
`Prompts::KIND_CHECKS`,
writes the kind as `other`,
before the importance floor applies, so the floor of the kind the model
gave does not lift it, and at or above every bar asked the kind stands; an offered
theme chosen with a `confidence` of `Extractor_Node::THEME_CHOICE_MIN`
(0.6) or more is the theme written, and a weaker choice, or `none`, keeps
the reply's own. A review that fails, an unlent group, or an answer to a
question it asked that does not read keeps the kind and the theme, logged
`extract review failed: <why> for <item>`, rate-limited by the failure, so
an outage across many items logs once, and parks no retry;
the extraction is still written, and a review answer whose finish throws
parks a retry as any answer does. A failure to start a review, the themes
read or the request throwing, is the same failure: the reply already paid
for is recorded, and only a cooperative stop propagates. A restricted item, a reply that does not
parse, or one owed no question asks no review. Either way it then
forwards an `item_extracted` struct `{ item, relevance, importance }` to its
target, `deduper`; it is a struct between two nodes, not an event kind.

The extractor publishes how it extracts through `Publishes_Status`, under
its name and worker partition, for 86,400 seconds, with every key of
`Extractor_Node::BLANK_STATUS`: `mode` (`call` or `heuristic`),
`vault_group`, `last_ok_at`, `last_error` and `failing_since`. An unlent
answer publishes `heuristic` with the `vault_group` it names, clearing the
rest; a call whose reply parses publishes `call` with `last_ok_at`, clearing
the failure; a call that fails or whose reply does not parse publishes
`call`, keeps `last_ok_at`, and publishes its text through the trait's
`status_text()`, terminal-safe and cut to 500 bytes, with
`status_since( 'failing_since' )`: the value already published, else now.
The mode follows the answer, so a held `heuristic` gives way to `call` once
the group lends a credential. A gate that fails, and a review that fails
or will not start, publish the same way, so a `systemone` outage reads as
`last_error` beside a fresh `last_ok_at`: while one stands a reply that
parses moves `last_ok_at` and leaves `last_error` and `failing_since`, and
only a gate or a review that answers clears them. The extractor holds that
standing in the process, so a recycled worker's next parsed reply clears it
until a decision fails again. A gated stand-in publishes nothing of its own.

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
`SWEEP_INTERVAL_S` (30) old, past the projector's 5-second flush; a requested
range sends its next `RERESOLVE_PAGE` (20) only when the tick finds no queued signal due.
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
newspack-intelligence ontology reresolve --since=<Y-m-d> [--until=<Y-m-d>]
[--source=<name>]` and the `reresolve` verb both call `Reresolver_Node::ask()`
with their graph, `RERESOLVE` and the source, '' for every one, which the
verb always passes; `ask()` validates the days and refuses a source of more
than one word before it sends anything, then sends `RERESOLVE <since>
<until> [<source>]` through `Worker_Route` to each live worker's
`reresolver`, answering how many it reached; with none live it throws, which the command lets fail and the
verb answers as a refusal. `wp newspack-intelligence ontology reextract
--since=<Y-m-d> [--until=<Y-m-d>] [--source=<name>] [--limit=<n>] [--dry-run]`
re-extracts the same range through the same path, sharing `reresolve`'s one
helper for the root refusal, the days, the source, the refusal of a bad
range, `ask()` and the success line, which names the source when given.
Both read `--source` trimmed, as `items purge` does, and refuse one given
empty rather than widening it to every source, and one with no stored item
recorded under it, in its own case, listing those sources sorted
(`Items_CLI_Command::stored_sources()`, which reads every partition's
`items` as `items purge` selects them). A node's name is not always its
items' source: `news` records under `feed` and `p2-restricted` under `p2`.
Every live worker re-extracts the whole range, so it counts its extraction calls as
the items of the source, or of every one, the range dates
(`Reresolver_Node::count_dated()`) times the
live workers (`Worker_Route::live_workers()`), refuses extraction calls above
`--limit`, `Ontology_CLI_Command::REEXTRACT_LIMIT` (200) when omitted and
refused when not a positive whole number, naming the items and the workers
and that a failed call may retry up to `Extractor_Node::MAX_ATTEMPTS` (5)
times, sends nothing for a range that contains no item, and otherwise asks with
`REEXTRACT`, which queues the range as a `reextract` unit whose records
carry `extract: true`; `--dry-run` sends nothing and prints those calls,
that each can add one decision-model call, its review, which neither the
count nor `--limit` includes, and
the command that runs them, with `--source` when given and `--limit` only
past the default.
`Reresolver_Node::RANGES` maps each range request to its queue kind, and
both the declared requests and `ask()`'s refusal of any other read it. A
call that fails keeps the item's earlier extraction, and its retry stays
owed one. The reresolver publishes a range it queues naming no source as
`last_range { since, until }` through `Publishes_Status`, for a year, so it
outlives the Delay's dropped days it covers, writing each one it is asked
(no heartbeat); a range naming a source covers only some of its days' items
and publishes nothing. It answers the range it queued, `{ since, until }`
with `source` beside them when one is named, or the refusal, and sends
every item dated in those UTC days, an undated one by its retrieval, of the
named source alone when there is one (`i.source = ?`), partition by
partition in row-id order. The queue checkpoints with
`intel:ontology:reresolve` (`add_snapshot_node reresolver`), each unit by its
key, `<kind>|<value>`, a range's value `<from> <to>` or `<from> <to>
<source>`, with when it was queued and a range's cursor, and an
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
graph, answers eighteen read verbs, each declaring `read` and its
args: `search`, `entity`, `timeline`, `related`, `claims`, `trends`,
`signals`, `review`, `collections` and `lenses`, `theme_suggestions`, the
five watch verbs, `briefing` and `health`, below. Each of the first ten hands its bound args to the
`Graph_Query` read of the same name, through `Intel_Graph_Row_Schema::read()`
over every partition's file. A read opens on `Graph_Query::graph()`, which resolves
the tiers once from `Intel_Tiers::readable()` — `public` and `internal`,
and `restricted` for a caller holding the restricted tier (below) — and
binds the prelude every statement opens with: `mg`, the survivor map,
and `ri`, the items of those tiers. Every statement reads items through
`ri`, never `items` with a filter of its own, so an item, link, claim,
count or citation never rests on an item of another tier, and an entity short of
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
mention over publisher among the links that were not rejected by any judgment, over the roles
saying an item is about its entity,
`Intel_Event::ABOUT_ROLES`, unless a read names others: `visible()` and a
claim's subject rest on any link, and an entity's publishing reads its
`publisher` links alone, at the machine's state, since a judgment governs
an about-link alone, so `search`, a lens, `timeline`, `related`,
`trends`, the about signals, `review`, an entity's `counts` and each
listed item's entities leave `publisher` out, and an entity's publisher
signals read it alone. Each listed item's entity answers `{ entity_key,
name, state, role, client }`, `role` its live link's and `client` whether
it is an open client (`Graph_Query::open_clients()`, the `oc` CTE a group's
clients read too), an open client first, then a subject, then by key. `entity` answers `published: { count }`,
the readable items its own site published, and `timeline --published`
pages them. For an entity holding an open `client` role, `entity` also
answers `status: { client_since, kinds, last_item_ts, churn_open }`
(`Graph_Query::status()`): the earliest start of its open `client` rows,
read off the role rows the record already holds, `kinds` counting the
readable items about it extracted as each of `Graph_Query::STATUS_KINDS`
(`churned`, `churn_signal`, `feature_request` and `complaint`, in that order), the time of the newest readable
item about it, or null, and `churn_open`, whether a readable item about it
dated after `client_since` is extracted `churned` (`Graph_Query::CHURNED`),
one statement reading all four. Its window is the whole retention: every readable
item counts, as the timeline pages them, and what its own site published
does not count in any of them. Any other entity answers `status: null`. The
status carries no signal: the Entity view reads the latest from its own
`signals` read. A link's state is the highest-ranked judgment's state
(`JUDGE_RANK`: a person over an agent), else the machine's state, and a
`rejected` link counts nowhere. `review links` also leaves out a proposed
link whose entity's survivor is rejected, read as
`Entity_Index::winning_states()` reads a state, from the highest partition
holding one, because a `ner_fuzzy` row outlives a re-resolution that did
not run NER and nothing is left to judge of it; each listed item's
entities leave out such an entity by the same read, so no item shows a stored
link to it. Each read hands `live()` the links it rests
on — an entity's keys, a page's items, the items a claim or a proposal
names — and windows only those; `search` and `trends` naming a role, or
`trends` counting topics, without an entity or topic window the links of the
readable items inside their days. Summaries, kinds and scores come from
`extractions`.
Every read names an entity through `Graph_Query::name_of()`: the newest
`canonical` name added to it, the highest partition's first, else the name
it was created with; so a client the roster named by its domain shows its
site's title once the feed adds it, a machine's proposal shows the name a
curator gave it, and an entity with no canonical name keeps its created
one. Only a curator, a seed's `names` and the feed write one, and the feed
only while `name_of()` still shows one of the entity's own domains, so a
name someone chose is never replaced by a feed's title. `q` reaches FTS5 as one `fts_phrase()` per
word over title, body and summary, and a word holding no letter or digit
matches as an empty phrase, which FTS5 ignores; every other value binds,
and a blank optional arg is a value read as given (substrate ADR-25), so a
blank `q` or `source` matches nothing. Days parse through
`Intel_Graph_Row_Schema::range()`. An answer never carries a body, but for an item's `excerpt`, its opening as
plain text: `ri` reads `Graph_Query::EXCERPT_READ_CHARS` (4,096) characters of
it as `head`, so a list moves no whole body, and `Graph_Query::excerpt()`
cuts a `<script>` or `<style>` left open, since stripping keeps what an
unclosed one holds, strips the markup, decodes the entities, collapses the
whitespace and cuts it on a word boundary to `EXCERPT_CHARS` (800), an ellipsis marking a cut. Every
read answering through `ITEM_COLUMNS` carries it, `search`, a theme group, a
timeline and watch matches, under the tiers `ri` admits; `limit` is
capped at `Graph_Query::MAX_LIMIT` (200); trend rows, related entities and
proposed entities cite their five newest items, the first two as `items: [
{ item_key, url, title } ]`, and items their URLs. A claim answers `{
subject, subject_name, predicate, object, object_name, confidence,
item_key, url, title }`: its subject's name, else its key; beside an
object's key its name, else the key; null beside the item's spelling, so
`object_name` is null for free text alone; and its item's URL and title. A projection that will not open answers `available: false` beside
empty rows, logged; one that has not been written by any partition answers empty
rows.
A collection's saved items read from `collected` alone, at the tier each
was saved at, so a saved item outlives its expiring `items` row; `collected`
keeps its own tier filter, and `collections` opens no graph.
`search` and `trends` filter alike — `entity`, and the lists
`Graph_Query::LENS_LISTS` declares once, `kind`, `topic`, `role` and
`source`, each repeatable and matching any of its values, `role` an entity
the item links holding it at the item's time — and `search` adds `q`.
`LENS_LISTS` names each list's field, its arg, its vocabulary (null when
free) and its description, and the call's filters, a lens's, `save_lens`,
the schema's args and the page's `lensLists` vocabulary all iterate it.
`search` reads in the order its `sort` names (`Graph_Query::sorts()`):
`importance`, the highest importance first, then the most relevant, then
the newest, an unscored item last, or `time`, the newest first; any other is
refused. `Graph_Query::MOST_IMPORTANT` is that order, which the briefing
reads by too.
With none named it reads by `time` when it has words and no lens, else by
`importance`, so a lens and a bare search rank by importance, and it answers
the order it read in as `sort`. The arg declares no default, since a bound
default would mask that choice; the MCP door and the page read it off the
schema. Given `group`, one of `Graph_Query::groupings()`, `theme` alone,
`search` answers the same items as `{ groups, total, available }`
(`Graph_Query::grouped()`), a lens or none, and refuses a `sort` beside it,
since groups rank themselves; the Lens view sends `group=theme`, and
Search sends none. Where neither the call nor its lens bounds the days, a
grouped read keeps the last `Graph_Query::GROUP_DAYS` (30), so it never
reads links over the whole retention. Each item counts as its story, as
`trends` counts it, and a group is the stories whose copies share a theme;
a story none of whose copies names a theme is a group of its own, its
`theme` ''. A group answers `{ theme, kind, count, clients: [ {
entity_key, name } ], newest_ts, items }`, each read over every copy of
its stories, a copy naming no theme among them: `kind` the most severe of
those copies' kinds, a
kind's severity its place in the effective `kinds` list, a lens's
replaced by the call's, the most severe first, every kind alike when the
list is empty, the newest copy's kind among equals; `count` its stories;
`clients` the open clients, as survivors, that those copies are about by
a subject or a mention, by name, a copy about more than
`Graph_Query::MAX_ITEM_CLIENTS` (3) of them naming none, though it still
counts, as the briefing files such an item once, so an item naming dozens
of clients neither lists them nor ranks its group by them; `newest_ts` its newest story's time; and
`items` its `Graph_Query::CITATIONS` (5) newest stories, each as `search`
answers an item, the most important first, in `MOST_IMPORTANT`'s order.
Groups rank by severity, then by the highest importance of any copy, then
by clients, the most first, then by newest story, then by theme. `limit` and `offset` count groups, `limit`
`Graph_Query::GROUP_LIMIT` (20) when the call names none and items'
`DEFAULT_LIMIT` (50) without `group`, and `total` counts every group. One
statement reads the page of groups with their stories, and one more those
stories' entities, however many groups the page holds. `trends` counts by `by` per `bucket`, the call's, else its lens's,
else `kind` (`Graph_Query::DEFAULT_AXIS`) per `week` (`DEFAULT_BUCKET`);
neither arg declares a default, since a bound default would mask the
lens's values. The axes are `kind`, `topic` and `theme`, the theme an
extraction names (`themes`), each item's read from the newest partition
holding a row for it and read as its survivor (`theme_survivors()`), so a
group, a trend row and a signal on the theme axis count a merged theme's
stories under the theme it merged into, as a cluster is, so a stale row in an older file
makes no second value; counting by theme, each row is `{ bucket,
value, count, clients, items }`, `clients` named as a group names them,
and an item with no theme counts under none, and is not history to a
signal. Its rows list the newest bucket first, then the highest count. A
story counts once (`Graph_Query::stories()`): an item the deduper placed in
a cluster counts as the cluster's canonical, the cluster read from the
newest partition holding the item's `clusters` row and the canonical from
the newest holding that cluster's `canonicals` row, when the caller may
read that canonical, else as itself, so neither a restricted canonical nor
an older partition's one stands for a copy. An item two partitions hold
counts once. Every read dates a story by the earliest of its copies the
scope admits, across every partition's rows, so a canonical before or after
the days asked never moves the story out of them; it is cited by the
canonical's key, URL and title, and its value is the admitted copy's kind,
topic or theme, so copies extracted as two kinds count once under each. `lenses` answers `{ lenses: [ { lens_key, name, description,
filters } ], available }` by name, `filters` always the whole
`Graph_Query::unfiltered()` shape — `kinds`, `topics`, `roles`,
`sources`, `entity`, `days`, `tiers`, `by` and `bucket` — its entity and
topics only those the caller may see now (`visible()`), so one a judgment
has since left to restricted items alone is left out for a non-holder.
Given `lens`, slugged as `save_lens` slugs it, `search` and `trends` read
through it (`Graph_Query::through()`):
each list the call names, and `entity`, replaces the lens's value; `since` or
`until`, or both, replace the lens's `days`, the last that many UTC days
ending today, whole, the side the call leaves open staying open; and the
graph opens with `ri` over the caller's readable tiers intersected with the
lens's `tiers`, so a lens narrows a holder and never widens a reader. A
lens's entity or topic the caller cannot see stays in the filter, where it
matches no item the caller may read, so it narrows that caller to nothing
rather than dropping out and widening. A lens that was never saved is refused as
`no lens: <key>`.

`theme_suggestions` (`Graph_Query::theme_suggestions()`) answers `{
suggestions: [ { themes: [ { theme, stories }, { theme, stories } ], words,
shared } ], available }`: of the readable items whose `themes` row, the
newest partition's, was retrieved in the last `Graph_Query::GROUP_DAYS`
(30) days, each theme read as its survivor, the pairs of themes whose items
share an entity they are about by a `subject` or `mention` link, as a
survivor, unless the highest-ranked judgment of that link rejects it, and
whose names share a word `Prompts::theme_words()` keeps, the words
`title_terms()` reads less every word of an item kind or of
`GENERIC_THEMES`. `stories` counts each theme's distinct stories, as the
offer counts them, the theme with more first, then by name; `words` are
the words both names hold, sorted; and `shared` counts the entities both
themes' items are about. Pairs rank by how alike the names are,
`Graph_Query::alike()`: the weight of the words they share over the weight
of every word either holds, a word weighing `log( 1 + themes / holding )`,
so a rare word such as "offboarding" outweighs a common one such as
"local"; then by `shared`, then by the two themes' stories, then by name;
`Graph_Query::THEME_SUGGESTIONS` (10) are answered. A word more than
`Graph_Query::SUGGESTION_WORD_MAX_THEMES` (20) themes hold pairs none of
them, though it still counts in the weights and in `words`; each pair is
scored once, from the first word in string order that pairs it, and the
best are kept as they are scored, so the work grows with the words rather
than with the square of the themes. One statement reads the theme merges,
one every theme's stories and entities, and the pairs are made in PHP.

**Health.** `health <what>` (`Intelligence_Health`), at `read`, answers
one slice of pipeline health a call, so the strip asks each through its
own Fetcher, and refuses any other `what` (`what must be one of:
collection, sources, backlog, extraction`); `Intelligence_Health::SLICES`
lists them once, for the dispatch, the refusal and the arg's description,
each answered by the method of its name. What durable state answers is
derived in the request; the rest is a snapshot a worker published through
`Publishes_Status`, read at partition 0, which the topology runs alone,
and a snapshot that is not in any cache reads as unknown or null, never as healthy.
`collection` asks `Worker_Route::running()` first: with a topology running
Intelligence it answers `{ state, topology }`, the `CLI::worker_states()`
word `wp nodes status` reports for that topology's `.p0` against
`Bootstrap::get_topologies()`, whatever the topology dirs hold. Only when
`running()` answers null, or throws because an active `.tsl` will not read
(the one throw it chains a cause on), does it ask
`Intelligence_Health::missing_lists()`: while
`newspack-intelligence-lists` does not resolve in any topology dir it answers `{ state: unconfigured, file }`,
`file` the path it belongs at in the user topology dir; else `{ state:
inactive, topologies }`, every composition `Worker_Route::candidates()`
offers to activate, sorted, or, after a throw, the throw. `candidates()`
leaves out a composition declaring a `Source_Node` whose class's
`runs_here()`, given its line bound by `Worker_Route::line()`, answers
false: a ContextA8C source answers false unless its `auth_dir` is a dir
the worker's user can read and search, so a host without the relay's token
dir is not offered the workstation. A source line that will not bind
refuses the slice as `<topology>: <Type> <name>: <why>`, and the `sources`
slice as `<Type> <name>: <why>`. `running()` and the `sources` slice
filter nothing, so a live workstation whose token dir has gone still
reports, its sources idle.
`sources` answers `{ sources: [ { name, class, tier, state, … } ] }`, one
row per `Source_Node` Intelligence's topology declares, in its order
(`Intelligence_CI_Node::sources()`, which `fetch_now`'s ticks map), `class`
its `make_node` type, leaving out a source whose tier
`Intel_Tiers::readable()` does not grant the caller. Each line binds
against its class's own `node_schema()` arguments (`Command_Args::bind()`),
so `tier` and `vault_group` are read by name, a class declaring no group,
as a ContextA8C one, reading as ''. A source's `tier` is the one its
topology row carries, which `Source_Node::graph_facts()` answers to
`Topology_Analyzer`: the last `set_tier` its config is sent, bound by
that verb's own args, else its line's. A source whose class's `credential_url()` is not empty and
whose group lends nothing on that url's origin
(`Curl_Node::lends_credential()`, asked once per group and url a call) is
`{ state: idle, credential: { url, group } }` whatever its snapshot says;
any other answers its snapshot as
`Source_Node::BLANK_STATUS` names it, or `{ state: unknown }`. A caller
below `manage` reads each source row and the `extraction` row without
`credential`, `vault_group` and `last_error`, and the `unconfigured` row
without `file` (`Intelligence_Health::OPERATOR_FIELDS`), so an absolute
path never reaches it, and it reads their state, times,
counts and an idle ContextA8C source's `reason`, fixed text naming at most
an HTTP status, and a source idle for want of a credential still reads `idle`;
that is field redaction through `Capabilities::can( MANAGE )`, and the verb
stays at `read` (substrate ADR-26). `backlog`
answers `{ reason, held, since, dropped }`: `reason` is
`Entity_Index::hold_cause_now()`, which answers what the resolver's
`hold_cause()` answers of a freshly read index without loading one, the
projector's reading then one `LIMIT 1` look for a live entity through
the same `live()` query `load()` indexes by, which judges each key by the
state the highest partition holding one writes
(`Intel_Graph_Row_Schema::all()`, asked for it, tags each row with its
file's `partition_ordinal`), so it names the cause with the cache flushed; `held` is the `depth` the later queue's Delay publishes,
every entry it holds, the extractor's retries among them, null with no
snapshot; `since` is the resolver's snapshot's when its `reason` is that
same cause, else null; `dropped` is the Delay's `{ first_day, last_day }`,
null with none or once the reresolver's `last_range` covers it, `since` on
or before its first day and `until` on or after its last. The Delay read is
the one the resolver's `make_node` line names as `later`.
`extraction` answers the extractor's snapshot, else `{ mode: unknown,
last_mode }`, `last_mode` `heuristic` for an actor naming a stand-in stage,
heuristic or gated, or `call`, from the actor of the
newest `extractions` row, by row id, which says how that extraction was
made and never why, else `{ mode: unknown }`. Beside any of the three,
`queue` is the `LLM_Call` node's published `queue` (see Model calls),
every NER and extraction call alike: the calls waiting to be sent as new
work and as backlog, and those sent and not yet answered; null with no
snapshot, with counts the Table did not answer, or with a snapshot written
more than two of its heartbeats ago, as a stopped worker leaves, never
zero, and read whole below manage. Each snapshot is read under the name the
topology `Worker_Route::running()` answers gives its `Resolver`,
`Extractor`, `Reresolver` and `LLM_Call`
(`Topology_Analyzer::nodes_of_type()`), and a class it does not declare
reads as a missing snapshot.

**Signals.** `signals [--lens=] [--entity=]` answers `{ signals: [ {
axis, signal, bucket, by, value, count, baseline, items } ], available }`.
On the `about` axis, each a `spike`, it reads the rows `trends` answers
for the same lens and entity, so the lens's filters, axis, bucket and
tiers apply alike: per value counted, the latest
complete bucket's count when it is at least `Graph_Query::SIGNAL_MIN` (3),
above the mean of the `SIGNAL_BASELINE` (8) buckets before it, so a flat
history never signals, and at least that mean plus `SIGNAL_SIGMAS` (2)
population standard deviations of them, compared in whole numbers so the
threshold itself counts. Counting by kind, the about axis also spikes a
value only when that same value was counted before the oldest baseline
bucket, read across the whole retention whatever days a lens keeps,
because a first appearance within the window is not a change: a client
feed reaching back two weeks would otherwise spike every kind it first
brings, and one stray item in a baseline would re-arm the rule. Counting
by any other axis, a topic or a theme among them, a value needs no
history of its own, so a new topic or a theme's first surge spikes, once
a themed story precedes the window, since an unthemed one is not history
there; a signal on the theme axis names no clients. `baseline` is that mean, and
`items` cite the bucket's five newest items. A story counts once, as its
canonical, as `trends` counts it, on both axes, dated by its earliest
admitted copy, so a story whose copy precedes the window is history and
leaves the window, even when its canonical falls inside it. The about axis
counting by kind joins no extraction of kind `other`, the kind that says
nothing (`Graph_Query::MUTE_KIND`), whatever the lens or entity, so
`other` neither spikes nor counts as history there; the `publisher` axis
keeps it. It counts those `SIGNAL_BASELINE + 2` buckets alone. Counting
by kind on the about axis, it reads which values the stories before them
counted, in the same statement when the lens keeps every day and in one
more when it keeps fewer. On any other axis, and on the publisher axis, a
history holding no counted story before the oldest baseline bucket
signals nothing, because its zeros are not a baseline: a story in that
bucket answers it, else one dated earlier within the lens's own days,
which the same statement reads. An
entity named with no lens answers the `publisher` axis after them, read
from its `publisher` links per week: a
`burst` for each item kind its own output spikes by the count rule above
and the short history, no history of the kind itself required, so a
steady publisher bursts nothing and a kind it never put out before
bursts; and, for an
entity holding an open `client` role now (`Graph_Query::open_roles()`, as
the feed source reads it), a `drop` when the latest complete week falls
(`fell()`), holding at most `SIGNAL_DROP` (0.5) of a baseline mean of at
least `SIGNAL_MIN`, or `silent` in its place when that week and the one
before it are empty and the week before them was not, so a silence is told
once and then neither signal follows. Neither fires in a week when the
`publisher`-linked items of every client, an entity linked holding
`client` at the item's time, fell by the same rule, because that is
collection failing rather than the client. A drop or a silence counts
every item, `by` and `value` null, against the same eight weeks before the
latest, and cites the week's items. A lens's signals leave out the
publisher axis, its own entity's included.
`Graph_Query::spikes()` is the same read on a read already open, answering
none unless the latest complete bucket closed after a given instant; the
digest reads it, so an entity watch's digest carries both axes, each line
`Signal (<axis>, <signal>): …`. An entity named with no lens is read by
`Graph_Query::signals_of()`, which reads any number of entities in the
same statements: each statement's `live` reads the items linking any of
their keys, and it counts what is about them per entity, week and kind,
what they published likewise for bursts, and per entity and week for a
drop or a silence, with one `open_roles()`; whether every client's output
fell is read once, and only when a client fell. So `signals --entity`,
the watcher's digest and the briefing read an entity by one path, and
twenty clients cost the statements two do. A lens and the entities share
one pipeline, `spiking()` over a scope, which `filtered()` builds from a
lens's filters under the one subject `''` and `linking()` from the
entities under each survivor; whether a counted story precedes the window
is read in the statement that counts the window, for every subject. A
window's bucket starts are one recursive statement, so a week lens costs
two statements.

**Watches.** A watch is one reader's standing question over exactly one
lens, entity or collection. `Graph_Watches` holds its five verbs, each at
`read`, each answering or changing the caller's own watches alone:
`watches` lists them by name, `{ watches: [ { watch_key, name, target: {
kind, key }, digest, instant, inbox, unseen } ], available }`, `unseen`
counting the unseen matches the caller may read now whose items still sit
in the target (`Graph_Query::in_target()`), 0 for a watch whose `inbox` is
off; `save_watch <name>
[--lens=] [--entity=] [--collection=] [--digest=] [--instant=] [--inbox=]
[--key=]` writes `watch_saved` under a key it mints, sixteen hex
characters, or under the key of one of the caller's own watches, which it
replaces, and answers `{ ok, event_id, watch_key }`; `remove_watch <key>`
writes `watch_removed`; `watch_matches <key> [--limit=] [--offset=]`
answers the watch's unseen matches the caller may read now whose items
still sit in the target, the latest matched first, each as `search` answers it plus `matched_ts`, through
`Graph_Query::listed()`, and `{ items: [], total: 0 }` for a watch whose
`inbox` is off, whose matches the watcher still records for its email; and `mark_seen <key> [--item=…]` writes one
`watch_seen` per named match, or per unseen match when the call names none, a
match already seen not again, and answers `{ ok, count }`. The three boxes
are bools, `digest` and `instant` off and `inbox` on by default.
`save_watch` refuses a blank name, not exactly one target (`a watch follows
exactly one of lens, entity or collection`), no box ticked (`a watch
delivers by digest, instant email or inbox; tick one`), a lens that was never saved
(`no lens: <key>`, slugged as `save_lens` slugs it), a collection that was
never made (`no collection: <key>`), an entity the caller cannot see (`no
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
<key>` whether another reader saved it or it was never saved, so the verbs never tell
the two apart, and `mark_seen` refuses an item the watch never matched, or one
of a tier the caller does not hold now, as `no match: <item>`. Each verb
reads the projection once, writes as `Intel_Event::caller()` with the
caller's user id as `owner`, and refuses before anything is written. A
watch's definition rides the ontology stream, which keeps every segment,
because a watch must outlive the watches stream's two years; its matches,
seen marks and deliveries ride the watches stream.

**Briefing.** `briefing <what>` (`Intelligence_Briefing`), at `read`,
answers one slice of the last `Intelligence_Briefing::DAYS` (7) UTC days a
call, today the last (`Graph_Query::last_days()`), so the page asks each
through its own Fetcher, and refuses any other `what` (`what must be one
of: changes, signals`); `SLICES` lists them once, as `health`'s do, each
answered by the method of its name, and each reads through a read that
already answers it. `changes` answers `{ groups: [ { entity_key, name,
role, items } ], total, available }`: `Graph_Query::most_important()`, the
window's `Graph_Query::DEFAULT_LIMIT` (50) readable items holding a live
`subject` or `mention` link to any entity (`Intel_Event::NAMING_ROLES`),
so an item only tagged with a topic or only `publisher`-linked never
appears, by importance, then relevance, then the newest, the unscored last,
each as `search` answers it, and `total` counting every such item; each
item sits once, under the first entity holding an open `client` role,
as its survivor, that it is about, in a group of role `client` carrying
the client's key and name, unless it is about more than
`Graph_Query::MAX_ITEM_CLIENTS` (3) of them, when it is filed
once as an item about no client is, its entities still naming every
client. One that is not about an open client, or is about that many, but
is about an entity holding an open `newspack_product` role sits in one
group of that role, and any other, such as one about a former client
alone, in one group of role null; both carry a null `entity_key` and `name`. One statement reads
every survivor holding an open `client` or `newspack_product` role
(`Graph_Query::CLIENT`, `Graph_Query::NEWSPACK_PRODUCT`) with its role, and
names only the clients. Groups follow the order of their most important
item, so the cap ranks the whole window before it groups. `signals`
answers `{ clients: [ { entity_key, name, signals } ],
lenses: [ { lens_key, name, signals } ], available }`: every open client,
by name, in one `signals_of()`, so both axes are read without a statement per client,
and every lens, by name, through `spikes()` with its lens, each whose
latest complete bucket closed at or after the window's first instant, so a
week bucket always shows its latest week and a month bucket only in the
week its month closed; a subject without a signal is left out. The
reader's watches are the `watches` verb's, which the Briefing tab reads
itself.

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
or the entity exactly as `search` reads it, so an item an entity only
published enters neither, and a collection at its saving, read through the
readable items. Because an item can join its
target well after its retrieval — an extraction retried from
`intel:later`, a resolver's 60-second hold, a curator's `confirm_link`, a
re-resolution — the watch reads a day behind its mark, and its own match
rows keep an item it has already matched from matching again; an item
that joins more than a day behind the mark is not read. The mark is the
newest entry it has matched, written as a `watch_delivered` of mode `scan`,
a `keep` row, whenever it moves, so retention never moves it back and nothing
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
It adds a tier, never a verb: a verb's own role gate runs first, and holding
the capability without `read` reads nothing. A role carrying the
capability, and on a multisite a super admin, who passes every
`current_user_can()` check, also reads the tier without a grant. To the
operator surfaces a holder is one thing, a user's own grant on the current
site (`WP_User::$caps`): `holders()` lists those alone, so every listed
login is one `revoke()` takes, and `strip()` removes every one, leaving a
role's grant. `grant()` and `revoke()` look the login up as WordPress does, through
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

**Curation.** `Intelligence_CI` also answers sixteen curation verbs, each
declaring `tune`, each writing one ontology event through
`Stream_Writer::append()` and answering `{ ok, event_id }`; the two
creations add the key they minted. `Graph_Curation` holds them:
`confirm_link` and `reject_link` (carrying the item's URL), `accept_entity`,
`reject_entity`, `merge_entities`, `add_name`, `add_identifier`,
`assert_role`, `end_role`, `tag_topic`, `create_entity` (keyed
`Intel_Event::entity_key()`), `create_collection` (keyed
`Intel_Event::collection_key()`, the same slug), `collect` (the item's
URL, its title and its tier), `save_lens` (keyed `Intel_Event::lens_key()`,
the given key slugged, answering it), `remove_lens` and `merge_theme`. The actor is `Intel_Event::caller()`:
`agent:<login>:<label>` when `Capabilities::$session_label` is non-empty,
as for an MCP connection or an issued session, the label as
`Sessions::clean_label()` bounded it, else `user:<login>`; a call with no
signed-in user is refused before it writes. Every refusal throws before
anything is written, and the handlers never check a role (substrate
ADR-26).
Each verb reads the projection once, `save_lens` only when it names an
entity or a topic. Every entity key it names — both
sides of a merge, a link's entity, a tagged topic — must be one
`Graph_Query::seen()` lets the caller see, any other key is refused as
`no entity: <key>`, and the verb acts on the survivor it answers as, so a
key merged away accepts, merges or tags its survivor; a curation verb
answers no more than a read does. An item is read through `ri`, and one
outside the readable tiers is refused as `no item: <key>`, exactly as a
missing one. Curation cannot declassify: `accept_entity` refuses an entity
a caller without restricted access cannot see (`<key> is not cited by any
item outside the restricted tier; accepting it would show it to every
reader`), `merge_entities` refuses a merge where exactly one side is such an
entity (`merging <from> into <into> would show <key> to every reader; it
is not cited by any item outside the restricted tier`), since a merged-away entity's
names and key show on its survivor and a survivor gains the other's
citations, and `tag_topic` refuses such a topic on an entity every reader
sees (`tagging <entity> with <topic> would show <topic> to every reader;
it is not cited by any item outside the restricted tier`). All three ask `Graph_Query::visible()` over
`Graph_Query::unrestricted()`, the read's own graph with `ri` bound to
`Intel_Tiers::UNRESTRICTED`, so an accepted or created entity passes, and
so does one an unrestricted item cites by a link or as the item it was
proposed from. Two entities only restricted items cite merge, and a holder
still rejects one, or creates a public entity over its key on purpose with
`create_entity`. A judged link must exist: `confirm_link` and `reject_link`
refuse `no link: <item> <entity>` unless a `links` row of a role in
`Intel_Event::ABOUT_ROLES` joins the item to one of the entity's keys, so a
`publisher` link alone is not judged. `tag_topic` refuses a topic whose
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
characters, the last alphabetic, the whole at most 253, so an IP literal,
port, path, userinfo or trailing dot never passes), because a client's domain becomes a
URL the worker fetches, reads the holder through `Entity_Index::holder()`
over the index it loads, and refuses one another entity holds: the
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
(`Graph_Query::check_trend()`), `by` one of `kind`, `topic` and `theme`, `days` from 1 to `RETAIN_DAYS`, each
value bounded, and its entity and topics stored as the survivors `seen()`
answers, a topic refused when its survivor is not of type `topic`, as
`tag_topic` refuses it (`<key> is not a topic`), and either refused, since every reader lists every lens, when a caller
without restricted access could not see one (`a lens naming <key> would
show it to every reader; it is not cited by any item outside the restricted tier`);
the lists carry no cap, so `Intel_Event::make()` refuses an event past one
line, before anything is written. `remove_lens` slugs its key as
`save_lens` does and refuses `no lens: <key>`.
`merge_theme <from> <into>` writes `theme_merged`, so every read of an
item's theme reads `into` where it read `from` (see Projection). It bounds
each theme at `Intel_Event::THEME_BYTES` (80) and reads the projection once:
its `theme_merges` rows (`Graph_Query::theme_merges()`) and the theme of
each item in the caller's `ri`, with its tier, both the newest partition's.
It refuses a merge into itself (`cannot merge theme <from> into itself`);
one naming a theme that is not the theme of any item the caller may read,
neither as written nor as its survivor (`no theme: <theme>`), as
`merge_entities` refuses an entity the caller cannot see, so a caller
without restricted access reads one refusal for a theme only restricted
items carry and for one that is not the theme of any item; one after which `Entity_Index::walk()`,
over those rows plus the new one, leaves a theme that resolved before on a
chain that cycles or runs past `MAX_MERGE_HOPS`, naming the first such
theme the caller can see as the refusal above sees it (`merging <from>
into <into> would leave <theme> on a chain that cycles or runs past 10
hops`), or `a theme` where the caller can see none of them, since the
merges carry no tier; one into a theme that itself merges away, naming its survivor
(`<into> merges into <survivor>; merge <from> into <survivor> instead`),
or whose own chain cycles or runs past the cap (`<into> is on a merge chain
that cycles or runs past 10 hops; merge <from> into a theme whose chain
resolves instead`); and, since curation cannot declassify, one by a
holder of the restricted tier that would make `into` the theme of an
unrestricted item for the first time, an item an older partition alone
gives it not counting (`merging <from> into <into> would show <into> to
every reader; it is not the theme of any item outside the restricted
tier`). A theme merged away merges again, its row replaced.
`fetch_now` TICKs every source in each live worker — each node of a
`Source_Node` subclass Intelligence's topology declares, read through
`Topology_Analyzer::nodes_of_type()` — through `Worker_Route` and
declares `manage`, because it mounts a worker's input (substrate ADR-23).
`Worker_Route` appends each request to every live worker's input IPC
partition — durable and synchronous, with no live-worker
dependency on read — carrying the CI's own name as FROM, which the worker's
input reader stamps under `_repl`, so each source's answer lands on the
worker's output partition, where an attached `wp nodes cli` reads it.
Two operator fixes share their implementation with a WP-CLI verb:
`seed_ontology`, at `tune`, runs `Ontology_Seed::load()->seed()` as `ontology
seed` does and answers `{ ok, events, created, present }`; `reresolve
<since> <until>`, at `manage`, both days required, runs
`Reresolver_Node::ask()` as `ontology reresolve` does and answers `{ ok,
workers }`.

**MCP door.** `Intelligence_Mcp_Door`, wired by the loader, is a declaration
on the substrate's `Mcp_Door` (substrate ADR-38), serving
`POST /wp-json/newspack-intelligence/v1/mcp`. Its tools are read off
`Intelligence_CI_Node`'s schema, one `intel_<verb>` per verb but
`fetch_now`, `health`, `seed_ontology` and `reresolve`, which it leaves
out by name (`NOT_TOOLS`), each offering every declared arg
under the verb's own description. Its glossary says what a `publisher`
link means and which reads leave it out. The door owns the session check, the
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
`intel_signals`, `intel_briefing`, `intel_theme_suggestions` and the five
watch tools, which a read session is offered, and `intel_merge_theme`, which
a session whose role covers `tune` is offered;
the door becomes the session's minting user, so a watch tool answers and
changes that user's own watches, and a watch saved through it emails that
user.

**Seeding.** The seed is data a site supplies:
`newspack-intelligence-ontology-seed.json`, which it keeps in its
Intelligence data dir, a sibling of the runtime base, at
`Ontology_Seed::path()`, `<dirname(base_dir)>/newspack-intelligence/`
(`/tmp/newspack-intelligence/` where the base is `/tmp/newspack-nodes`);
the topology dirs are not searched, and the deploy tools install a site's seed
at the path the plugin answers. The plugin ships a fictional example,
`examples/newspack-intelligence-ontology-seed.json` (a few `.example`
organizations, a generic name it rejects, four topics and one lens), as
the shape to start from. With no seed at `path()`, `load()` refuses with
`Seed_Refused` naming where the file belongs and the example as
`examples/newspack-intelligence-ontology-seed.json`, relative to the
plugin, which the command shows through `WP_CLI::error()` and the health
strip's "Seed the ontology" fix beside its button. The path is named only
to a caller `Capabilities::can( MANAGE )` admits, since `seed_ontology`
answers `tune`; anyone else, WP-CLI without a manager's `--user` among
them, reads "in its Intelligence data dir". The file holds `entities`, type =>
slug => `{ name, names, identifiers, roles, state }`, `names` and
`identifiers` each kind => values, `state` `rejected` or absent, and
`lenses`, key => `{ name, description, filters }`, the filters laid over
`Graph_Query::unfiltered()`. `load()` mints every event as it reads,
so `Intel_Event::make()` judges each type, kind and role and the one-line
cap; it bounds each name and value as `create_entity` and `add_name` do,
checks each lens's filters through `Graph_Curation::lens_filters()`, the
half of `save_lens`'s checks that does not read the projection, and refuses a slug
or lens key its own key function would spell otherwise. Any refusal is a
`Seed_Refused` naming the file, by its name alone so a `tune` caller of
`seed_ontology` reads no absolute path, and the field (`<file name>:
entities.organization.<slug>.roles: role_asserted: field role is outside
its set`), before anything is written, as is a file that does not parse
or holds a field neither shape takes. `wp newspack-intelligence ontology
seed [<file>]` reads the file given, else the one at
`Ontology_Seed::path()`, and the `seed_ontology` verb the one at `path()`;
both run `load()->seed()`, which
writes `events()` — per entity its creation, its names, its identifiers,
its roles from 0 and an `entity_rejected` when its `state` says so, then
each lens, in the file's order — for the keys
`present()` does not find settled in the projection: an
entity in `entity_states` in any state but a machine's pending
`proposed`, a seed lens any `lens_saved` or `lens_removed` in `events`
names, read by subject and kind, the index `events` keeps.
A rejected seed entity's names never reach a proposal and never link an
entity of its type by a match, as a curator's rejection keeps them (see
Resolution), so the
deployment's stoplist is its
seed's rejected entities and not a list of its own.
So an entity a curator has accepted, rejected, merged or renamed is never
reset, a machine's proposal of a seed key is replaced by the seed's
creation, and a lens a curator saved over or removed is left alone; it
answers how many events it wrote, how many keys were new and how many
present, and a projection that will not open fails it with
`Table_Unavailable` rather than writing everything. While a projector's
reader of the ontology stream has not committed its end
(`Projector_Node::behind()`, the reading `Entity_Index::current()` takes),
whoever wrote what it has not read, it throws `Seed_Refused` before reading
the projection, `Recent ontology changes are still being processed; try
again in a minute.`, so a seed never writes keys already written that the
projection has not recorded; the command shows that refusal, and only it,
through `WP_CLI::error()`. It writes through `Stream_Writer::append()`, the
request-side writer of the ontology and watches streams, which routes each
event by `Intel_Event::stream_of()` and refuses an items-stream event, which
only the worker writes, before anything is written: per stream, a transient
Partition named `<partition>:writer`, built from that stream's declaration
in `newspack-intelligence-streams.tsl.php` read through `Topology_Analyzer` —
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

**Evaluation.** `wp newspack-intelligence eval <file> [--check=<kind>]
[--dry-run]` (`Eval_CLI_Command`) measures the review's checks against
labelled items, so a change to a question or a bar in `Prompts::KIND_CHECKS`
is scored before it ships. The file is JSONL, one label a line: `{
item_key, kind, fits_kind }`, `kind` a kind `KIND_CHECKS` holds and
`fits_kind` whether the item truly fits it, and optionally `about_publisher`
with `publisher`, the client's name, which come together. A line naming any
other field, a kind without a check, a value of the wrong type, or one of
the publisher pair alone is refused, naming the line, before anything is
read or sent; so is a label whose item the projection does not hold, all
such labels named at once. Each label makes one call, asking the questions
`Prompts::questions()` builds from its inputs: the label's `kind`, its
`publisher` when it names one, and the stored extraction's summary, ''
for an item never extracted, so `fits_kind` and `about_publisher` share
the call, and `--check=<kind>`, a kind check or `about_publisher`, asks
that question alone of the labels holding it. A restricted item is never
sent, and an item any partition holds at a tier outside
`Intel_Tiers::UNRESTRICTED` is restricted, whichever partition's row reads
last: its labels are skipped, and the command says how many. `--dry-run`
prints the calls it would make and makes none. Otherwise it reads the
first `LLM_Call` node `Worker_Route::topology()` declares, whose topology
row (`LLM_Call_Node::graph_facts()`) names the `api_url`, `decision_model`
and `feature` its `set_*` lines leave, kept as each setter keeps it, and
binds its `vault_group` through `Worker_Route::line()`; one unset, or a
group lending no credential on the api url, is refused before any call.
Each request is built by `LLM_Call_Node::curl_request()` and each answer
read by `LLM_Call_Node::outcome()`, as the node builds and reads them, and
the calls go one at a time through a Curl under that group, named
`intel:eval:curl`, inside the event loop, on the request graph
`Bootstrap::mount_request_graph()` builds. A check keeps the kind when its
noul is at its bar or above, as the extractor judges it, and scores as
`<check>: <n> labels, precision <p>, recall <r> at the bar <bar>`, kind
checks in `KIND_CHECKS` order then `about_publisher`, each ratio to two
places and `n/a` over nothing; each miss follows as `<item_key> <check>
answered <noul> wanted <keep|drop>`, the noul to three places, so a
miss within a hundredth of its bar reads as a miss. A call that fails is named with its
line, left out of the scores, and fails the run. The plugin ships a
fictional example, `examples/newspack-intelligence-eval.jsonl`; a site
keeps its own golden set beside its seed, never in the plugin.

**Collect.** The connector **Source nodes** (`github`, `linear`, `slack`,
`p2`, `p2-restricted`, `unthread`, `feed`, `news`) fetch inside the background worker
without blocking it and emit one `item_recorded` event per new item to
`intel:writer`. Each records at the
tier its `make_node` line names (`make_node Feed_Source feed public`),
which a load without one refuses and `set_tier` replaces at runtime. A source
fetches on a `TICK` request, which `fetch_now` sends, and on the wall-clock
grid once `set_interval <seconds>` sets its interval: it rides the Router's
tick and fetches when `intdiv( now, interval )` passes the value it had at
the process's previous tick, so a 900-second source fetches at :00, :15, :30
and :45 whatever its worker's age. A process's first tick only marks the
clock, so a respawned worker waits for the next boundary rather than
fetching at spawn, and a boundary that passes while the source has no
running worker is skipped; only a pass its Table still holds resumes at once (below). With no interval set a source holds no timer and only `TICK`
fetches. The collect topology sets GitHub, Linear, Slack and Unthread to
900 seconds, both P2 nodes and `feed` to 1,800, and `news` to 3,600.

`Source_Node` owns the chain every source fetches on, and each source
supplies two seams: `begin()`, which queues the pass's opening requests
through `queue( $url, $body, $subject, $note )` — a GET, or with a body a
POST of it as JSON, carrying a note `note()` reads back as its answer is
taken — and `received( $url, $body )`, which turns one answer into
normalized items and may queue the next requests, the next page's url
built by `add_query_arg()`. Three helpers serve `received()`:
`json_answer()` decodes a JSON body and, for a body that is not a JSON object
or list or one its source's closure reads an error off, logs
`<api> answered an error for <url>: <error>` and answers null; `items()`
maps each row to an item through its source's closure, leaving out a row
the closure answers null for; and `checked_list()` declares a validated
add verb, below. Two more serve a title and a body: `first_line()` cuts a
text's first line to `TITLE_CHARS` (120), and `mrkdwn()` decodes Slack's
three escapes in one pass. `dump_config()` replays every declared setter, list and
checked list. Three hooks tune it: `max_in_flight()`, `max_requests()`
and `follows_redirects()`. A pass starts on a `TICK` or a grid boundary
unless requests are in flight, in which case it starts nothing; it drops
whatever is still queued, calls `begin()`, and answers `{ fetching }`,
the number of requests it opens on, 0 while one is in flight. A request
is keyed by its subject, the url it was queued under: it goes to the
source's `{name}:curl` sibling, the substrate's `Curl_Node`, as a
TM_STRUCT whose KEY is that subject and whose VALUE is `{ url, method }`
for its current hop, `body` added for a POST, and `public_only` as the
source's `public_only()` answers: true, so a source reaches public
addresses alone, a redirect's every hop included, but for a ContextA8C
source, whose requests may go through a proxy Curl refuses beside the flag;
Curl answers back through its target with a copy of that message, KEY
untouched.
`max_in_flight()`, clamped to `Curl_Node::MAX_IN_FLIGHT` (16), bounds the
requests in flight: 1 for the API sources, so theirs go strictly one
after another, and the feed's `concurrency`, 16 unless `set_concurrency`
lowers it, for the feeds. A host takes one request at a time,
so a request whose host is busy waits in the queue while others go
past it; searches a deployment gives the `news` node on one host go out
one after another. `max_requests()` bounds a pass:
`Source_Node::MAX_REQUESTS_PER_PASS` (60) for an API source, past which
the next request is logged and the queue dropped, and no cap for the
feeds. A body goes to `received()`; a redirect Curl answers
because `follows_redirects()` is false re-queues the same subject at the
Location, one hop on, at most `Curl_Node::MAX_REDIRECTS` (5) hops, a hop
past that logged, unless the request carries header lines of its own or a
method other than GET, which the re-queue would carry past Curl's own
redirect guard, so it is logged and records nothing; and a TM_ERROR — a transport failure or a status of 400
or more — is logged rate-limited under its url and ends that request's
branch alone. An answer that throws is logged under its subject, and the
drain runs on; a cooperative stop propagates (substrate ADR-14). An answer
that does not match a request in flight is dropped, and the next request goes out whatever
the record did, so a pass always ends once nothing is queued or in flight.
A request Curl throws on leaves flight, so a throw costs that request,
never the source. A scheduled pass that throws is logged naming the source and
tried again at the next boundary, so it never stops the worker, and a throw
on the `TICK` path propagates out of `fill()` without being caught and
answered as a TM_ERROR.

Each source publishes how its last pass went through the substrate's
`Publishes_Status`, under its name and worker partition, for 7,200 seconds,
twice `news`'s interval; a reader asks
`Source_Node::read_status( <name>, <partition> )`. An answer counts toward
the pass as failed when it is a TM_ERROR, when `received()` throws, when a
redirect is not followed, or when the source calls
`fails( $prefix, $detail )` for it, which logs the failure rate-limited
under its prefix and publishes the same text, its detail terminal-safe, as
`json_answer()` does for an error its body carries, the feed source for a
body that is not an RSS or Atom feed, ContextA8C for a refresh that does
not name an access token or an `initialize` that does not name a session,
and Slack for an `auth.test` that does not name an https url; each error names its url. Any other answer counts as
answered unless its subject is in the class's `PROTOCOL_SUBJECTS`, the
requests that open or close a pass rather than fetch its data, empty on the
base: ContextA8C's refresh, `initialize`, notification and close, and
Slack's `auth.test`, which count only when they fail. A redirect re-queued
is a hop of the same request and counts not at all. Once nothing is queued
or in flight the pass ends: `pass_ended()` runs, and then, unless a request
it sent with `send_closing()` is still to answer, in which case that answer
ends the pass, the source publishes
`{ state: ok, last_ok_at, failed, requests }` when any counted answer did
not fail, else
`{ state: failing, last_error, failing_since, failed, requests }` with the
last failure's text, cut to 500 bytes on a character boundary; a pass that
did not count an answer, asking for no data and failing nothing, is quiet, not a
fault, and publishes ok with both counts 0, as does a pass whose `begin()`
queues nothing, which ends at once, unless `begin()` called `fails()`,
which publishes failing with that text, or the source `waits()` on another
node, which publishes nothing. A scheduled pass that throws
publishes failing with the throw's message, terminal-safe. `failing_since`
keeps the value already published while the state stays failing, read back
from the cache by a recycled worker's first publish. Every publish writes
all seven keys — `state`, `last_ok_at`, `last_error`, `failing_since`,
`failed`, `requests` and `reason` — those its state does not carry null,
because a publish merges over the snapshot read back. A source that cannot fetch calls
`idle( $reason )`, publishing `{ state: idle, reason }`, which its pass's
end leaves standing; only the ContextA8C sources do, below.

A pass outlives its worker. Each source owns a sqlite Table,
`{name}:frontier`, which its schema declares under `owns` and the analyzer
reads, and keeps every request in it through the substrate's `Frontier`,
by subject, until it is answered. A send takes `TAKE_ROOMS` (4) times
`max_in_flight()` from the Table once half the room, or all of it, is
free, so requests waiting on a busy host leave room for the rest; the
Table hands them lowest subject first, so a pass sends in about that
order. A request's url, body, hop count and note ride in the member, so it
resumes at the hop it reached with what its answer needs. A process's first
send returns `inflight` to `pending`, and every Router tick with nothing in
flight sends what the Table holds, so the next worker's first tick resumes
a pass a recycle or a crash cut, a request in flight then sent again; its
counts start afresh. A clean stop's `shutdown_sweep()` removes the
answered requests the Table still lists, so the next worker sends none of
them again. A pass starting on a `TICK` or a boundary drops what the Table
still holds, and a replay naming another vault group sends again
what the rebuilt Curl dropped. The ContextA8C sources own no Table: their
`Frontier` keeps the requests in the process, because a pass's MCP session
does not outlive it.
Curl sends `User-Agent: newspack-nodes/<version>` (`Curl_Node::USER_AGENT`)
on every fetch, which some feeds require, answering 403 without one.

Credentials ride with the Curl sibling, never with the source. The API
sources take a Vault group as their second `make_node` argument (`make_node
Linear_Source linear internal linear`), and their Curl sibling runs under
it: a request to the origin of the group's one entry carries the header
line `Vault::credential_header()` builds from that entry's `auth_header`
and `auth_scheme`. The `github` entry is `https://api.github.com` with
`Authorization` and `Bearer`; the `linear` entry is `https://api.linear.app`
with `Authorization` and no scheme; the `slack` entry is
`https://slack.com` and the `p2` entry, which both P2 nodes name,
`https://public-api.wordpress.com`, each with `Authorization` and `Bearer`;
the `unthread` entry is `https://api.unthread.io` with `X-Api-Key` and no
scheme.
Linear, Slack, P2 and Unthread answer nothing without a credential, so each
names the url it fetches through the static `credential_url()`, a fact of
the class a reader asks with no instance, and a pass asks
`Curl_Node::lends_credential()` of its group first: with no group, or no
entry of the group on that
url's origin, the pass sends nothing, answers `{ fetching: 0 }`, logs
nothing and publishes nothing, sitting idle until the entry exists. Every
pass asks again, so the pass after the entry is added fetches, with no
restart. GitHub, whose public API answers without a token, keeps the default
`''` and fetches with no credential when its group lends none; the
ContextA8C nodes take their token from the relay's file, not the Vault. The
feeds send no credential and declare no group.

Every answer records through one path, `Source_Node::record()`.
It drops the keys a bounded in-memory set already holds (`MAX_SEEN` 2000,
oldest evicted first), then reads, in one read through
`Intel_Graph_Row_Schema::read()`, which of the rest the projection's `items`
table holds in any partition, and remembers those without recording them, so a
respawned worker records only items the graph lacks. A source whose
`raw_id()` names a key's raw id, a feed's client lanes alone, also judges
each item as a copy: two items are one when they come from the same source,
their canonical URL (`Url_Canon::of()`) names a host, and their raw ids,
the guid past `feed:` and the lane's domain, are equal. A configured feed's
item names none and is judged by its key alone, because a configured feed
is anyone's and its copy of a client's post must never stand in for the
client's own, whichever records first. The same read finds
the items of that source the `items` table holds at those canonical URLs,
the seen set remembers each item once, by its copy or else its key, and a
copy held or recorded earlier in the process is remembered and not
recorded, so a post sister feeds list under one guid records once, under
the key that took it first, while a podcast's episodes, sharing their
show's one link under their own guids, each record. An item whose URL is
empty or names no host is judged by its key alone, and so is every item of
every other source, because a P2 comment's URL differs from its post's
only by a fragment, which the canonical URL drops. A projection that will
not open records everything, under a rate-limited line naming the source, so
a broken projection costs repeats, not items. An item recorded twice, from
two workers or past a projection still catching up, the resolver reads as a
re-resolution, which runs no NER step and is forwarded for extraction only
while the item is still owed one; re-resolution proper has its own path, the
reresolver. A source writes any other event it mints through the same door
as an item, `Source_Node::write()`, as the stage `source:<name>`; one
`Intel_Event::make()` refuses is logged naming its subject and not sent.

A feed pass queues each configured feed under its own url, then each
client's, its note naming the client's domain and found feed, and bounds
every request's connection at `Feed_Source_Node::CONNECT_TIMEOUT` (10)
seconds within Curl's 30, through Curl's `connect_timeout`. The feed's Curl
follows no redirect, so the base re-queues each Location under the feed it
redirects, note and all, and a configured feed redirected onto a client's
`/feed/` keeps its own lane. A body that is not an RSS or Atom feed fails,
logged once under its own url with libxml's first error or the root
element found. Each body is parsed as RSS 2.0 or Atom when it lands and
its items recorded then, an RSS item's body its `content:encoded`, else its
`description`. An RSS item carrying a `<source>`, as a Google
News copy does, whose title ends with ` - ` and that element's text,
records its title without that exact suffix, so the deduper's title norm
matches the publisher's own copy; any other title is kept as written. The `news` node is a second
`Feed_Source`, hourly, for the searches a deployment adds in place of the
feeds an organization does not publish.

`set_client_feeds true`, which the collect topology sets, adds to each pass
`https://<domain>/feed/` for each `domain` identifier of every entity holding
an open `client` role, as its survivor, read once a pass through
`Entity_Index::load()` and `Graph_Query::open_roles()`; a churned client drops
out on its next pass, and a projection that will not open leaves the pass the
configured feeds alone, logged. A stored domain that is not a plain hostname
(`Entity_Index::hostname_fault()`), such as one a client CSV import
wrote, since the importer only normalises a domain, is never fetched and is named in a rate-limited line. A URL
both configured and a client's is fetched once, as configured. A client's
items stay in its lane: each is keyed `feed:<domain>:<guid>`, so another
feed can never take its key first, and records only when its link's host,
normalised as `Entity_Index::identifier_value()` spells a domain, is the
client's domain, the host of the feed its request notes its home page
advertised, so spelled, or a subdomain of either, and its `item_ts` falls
from
`Feed_Source_Node::CLIENT_FEED_DAYS` (7) days before the worker's clock to
one day after it, the day ahead allowing for the publisher's own zone or
clock; an undated one never records. A configured feed keys `feed:<guid>`
and records every item, so an item two configured feeds list records once;
a guid that a plain hostname and `:` lead, as a client's lane is spelled,
is keyed `feed::<guid>`, so no configured feed takes a client's key or
reads as its copy.

A client's feed also names the client. The pass's read of the open
clients notes each whose entity `Graph_Query::name_of()` still shows by one
of its own domains, as the roster import names a client; when such a
client's feed lands in its lane, its RSS `<channel><title>` or Atom
`<feed><title>`, whitespace collapsed, is written once through
`Source_Node::write()` as a `name_added` of kind `canonical`; when the part
before its first ` | `, ` - `, ` – `, ` — ` or `: ` names the site, that
part alone is written, a tagline being no part of the name. A title that
is blank, past `Graph_Curation::NAME_BYTES`, or the client's domain again,
spelled as `Entity_Index::identifier_value()` spells one, names no one; so
does one that does not name the site (`Resolver::names_site()`), which is
the resolver's own `domain_stem` match: the domain's registrable label, of
at least `MIN_STEM_LENGTH` (6) characters, spelled by the title's letters
and digits, lowercased, on word boundaries, every word of it capitalized
but a lowercase `de`, `in`, `of`, `and` or `the` between two that are
(`Resolver::reads_as_name()`); or, for naming alone, through its words'
initials: read from the first word, each word spelling itself or its first
letter, the label spelled exactly, every word up to the last one used
taking part and only trailing words left over, those words read as a
name. The resolver's `domain_stem` link never takes initials, because an
item title is any prose, whose leading initials spell a short label by
chance, while a site's own feed title vouches for its domain. So "Harbor
Gazette" names harborgazette.example, "The Wren" thewren.example, "Pueblo
de Cobre" pueblodecobre.example, "Plover Tern Courier"
ptcourier.example and "Gull Ridge Sand Lake Post" gullridgeslp.example,
and "News", "Home", "Kestrel Bayou News" for kestrelbay.example,
"Lanternese Weekly" for lantern.example and "Morning Kestrel Times" for
ktimes.example, whose initials skip a leading word, name nothing. Once
is once: the feed remembers each entity it named for the life of its
process, and the next process reads the name off the projection, which
then shows the client by it. The name joins the resolver's names like any
canonical name, and the reresolver re-reads the items naming it.

A client's feed that answers a 4xx, a body that is not a feed, or a redirect
off its lane that does not list an item linking into it is lost, and the client's
home page, `https://<domain>/`, is fetched in the same pass, its redirects
followed anywhere. The first `<link rel="alternate">` of type
`application/rss+xml` or `application/atom+xml` it carries, read with
WordPress's `WP_HTML_Tag_Processor` and resolved by
`WP_Http::make_absolute_url()` against the url the page landed on, is
fetched under the client's feed and remembered as the domain's feed, when
`Curl_Node::origin_of()` reads it as http or https free of userinfo and
`Entity_Index::hostname_fault()` finds its host a plain hostname; one
refused is logged once under the page and fails discovery. A remembered
feed that is lost, a page advertising none, only the conventional feed or
one refused, a home page answering a 4xx, or a host that does not resolve
(curl error 6) backs the domain off and forgets its found feed: it sits out
the next 1, 2, 4 … passes, doubling with each failure in a row up to
`Feed_Source_Node::MAX_SKIP_PASSES` (48), kept as `retry_at`, the start of
the pass it runs in again on the grid of the feed's interval, and a client
feed that answers resets it. A timeout, a 5xx and any other curl error back
nothing off. Each domain's found feed, failures and `retry_at` live in one
row of the feed's Table, `domains`, kept a week and holding the pass's
clients alone: read at a pass's first use, and written, when changed, at
the Router's next tick, as the pass ends and on a clean stop, until the
Table takes it. A read the Table does not
answer runs the pass with no backoff and writes nothing, so it never
overwrites the row.

`Github_Source_Node` GETs three REST endpoints per repo each pass — releases,
closed pull requests and issues — and reads which one an answer is from its
url, through one pattern built from `ENDPOINTS`, keeping a closed PR only when it carries a `merged_at`, and dropping any
issue carrying a `pull_request` key, because the issues endpoint lists PRs
too. `add_repo` refuses anything but one `owner/name` pair of letters,
digits, `_`, `.` and `-`, naming the value: it and the other sources' checked
adds are declared by `Source_Node::checked_list()`, `list_verbs()`'s pair
with an add that refuses a value its pattern does not match, which
`dump_config()` replays one add line per entry. Each endpoint takes the 30
most recent results and pages no further, so an update that falls past that
window between passes is missed outright. A TM_ERROR makes that one
endpoint contribute nothing, logged rate-limited, and the pass goes on to
the next; a body that is not a JSON list contributes nothing, logged with
its `message` or as not a list. `Linear_Source_Node` POSTs one
GraphQL query a pass, the newest 30 issues of its teams, the team keys
riding as the `$teams` variable, never in the query text; a pass with no
team sends nothing. An answer carrying `errors`, which Linear sends with a
200, logs its first error message, rate-limited, and records nothing, as
one whose `data.issues.nodes` is not a list does.

`Slack_Source_Node` asks `auth.test` while it holds no workspace url,
which it keeps for the worker's life when it is https and names a host,
and otherwise logs; once it answers, or straight away with the url held,
each channel's `conversations.history` over `WINDOW_SECONDS` (two days),
200 a page, every page noting the workspace, so a page another worker
resumes links its messages the same; a pass whose `auth.test` named none,
or failed in transport, records them with no url. An answer's
url names its endpoint, channel, window and thread. A page naming
`response_metadata.next_cursor` queues the next by `cursor`, and from a
history page each message with replies whose `latest_reply` is newer than
two intervals, or the window while the interval is unset, queues that
thread's `conversations.replies`, paged alike, so a pass reads only the
threads that moved since the pass before last; the
parent a replies page repeats, `record()` drops as the history page's.
Each message records as `slack:<channel>:<ts>`, its text's `&amp;`,
`&lt;` and `&gt;` decoded in one pass by `Source_Node::mrkdwn()` and its
`<@U…>` and `<#C…>` mentions kept as Slack wrote them, titled by its first
line, cut to `Source_Node::TITLE_CHARS` (120) by `Source_Node::first_line()`,
its poster leading its text, its url
`<workspace>archives/<channel>/p<ts without the dot>`; `channel_join`,
`channel_leave`, `channel_topic` and `channel_purpose` notices record
nothing. The poster is the message's `user_profile.real_name`, else
`bot_profile.name`, else `username`, else `user`: the source makes no
`users.info` request, which would hold each message until its poster's name arrived.
Slack answers a refusal with a 200 and `ok` false, which logs its `error`,
rate-limited, and records nothing. `add_channel` refuses anything but
`^[CG][A-Z0-9]{8,}\z`. A reply under a parent older than the window is not
seen.

`P2_Source_Node` asks each site's posts and comments through the
WordPress.com REST API since `WINDOW_SECONDS` (seven days) ago, 100 a
page. A posts page's `meta.next_page` is sent back as `page_handle`;
comments carry no handle, so a full page of them queues the next `page`.
A post records as `p2:<site_ID>:<ID>`, titled by its title, and a comment
as `p2:<site_ID>:<post ID>:c<ID>`, the site's id read from the comments
answer, which carries it beside the comments rather than on each. A comment
is titled `<author name>: <first line>`, the first line cut by
`Source_Node::first_line()`, so two comments on a post never share a title the
deduper would fold them by, and its body opens with `Re: <post title>` on a
line of its own. Titles and content
are stripped to text with entities decoded, the author's name leading the
text. An answer carrying `error`
logs its `message`, rate-limited, and records nothing, and an answer
that does not name a `site_ID` logs that, rate-limited under its site, and records
none of what it lacks. `add_site` refuses anything but
`^[a-z0-9-]+\.wordpress\.com\z`. `p2` reads at the internal
tier and `p2-restricted`, for the sites a deployment holds back, at the
restricted one.

`Unthread_Source_Node` POSTs `conversations/list` with `{ order:
[updatedAt], descending: true, limit: 100 }`, the newest update first. The
published reference documents a `where: [{ field, operator, value }]` clause
with `>` among its operators but does not name a field it accepts, so the source
reads its edge client-side until a live call shows whether `updatedAt`
filters. Each conversation whose `updatedAt` falls within two intervals, or
`WINDOW_SECONDS` (two days) while the interval is unset, queues
`conversations/<id>/messages/list` with `{ limit: 100 }`, as Slack reads
only the threads that moved, so a pass re-reads only the conversations
that moved since the pass before last; an id that is not a uuid is logged
and skipped, and each messages page notes the conversation's
`#<friendlyId>` and context line, which every later page carries on. A page whose `cursors.hasNext` is true queues
the next with `cursor: cursors.next` added to its body. A conversations page
does so only while its last conversation with an `updatedAt` that parses is
inside the edge, skipping one whose `updatedAt` does not parse; its
conversations' messages sort ahead of it in the Table. Each message records as
`unthread:<conversation id>:<ts>`, one item per message rather than per
conversation, because the projection dedup records only keys the graph
lacks and a conversation recorded once would never gain its later
messages. Its text's `&amp;`, `&lt;` and `&gt;` are decoded once by
`Source_Node::mrkdwn()`, as Slack's are. It is titled `#<friendlyId> <first
line>`, the first line cut by `Source_Node::first_line()`, or
`#<friendlyId>` when its text is empty, so the deduper folds neither a
conversation's messages nor one macro reply sent in two tickets. Its body
opens with the context line `[<status>] <customer>: <subject>`, the
customer's name else `Unthread`, the subject the conversation's title, else
its initial message's first line, else `#<friendlyId>`; then `user.name`,
else `userId`, and the text. Its `item_ts` is its Slack-style `ts`, as
Slack's is, and its url is empty, because the API does not supply a permalink. A
message missing its `ts` or `text` is logged under its page's url and
skipped. An answer with no `data` list logs Unthread's `message`,
rate-limited, and records nothing. `unthread` reads at the restricted
tier, because support threads quote customers by name. The node
was written against Unthread's published API reference and has not yet
called the API live.

`Context_A8C_Source_Node` serves the local workstation, which holds no
Vault credential but is signed in to ContextA8C, Automattic's MCP server at
`Context_A8C_Source_Node::MCP_URL`. Its `make_node` line takes the tier,
the dir holding the relay's `wordpress-remote-*` token dirs, and an
optional proxy every request goes through. `newspack-intelligence-context-a8c`
declares one for each token source, and a workstation activates
`newspack-intelligence-workstation`, which includes it in place of the
token half, in place of `newspack-intelligence` (see Topologies). The token lives in `Context_A8C_Token`, the process's one hold
on each token dir, and not in any node's state: it reads the file obtained last,
by `obtained_at`, which is in milliseconds, when it holds none. A token
within `EXPIRY_MARGIN` (600) seconds of its expiry, or one a server answered
401, is refreshed before the pass opens, by the one node that claims the
refresh: the claim re-reads the newest file under an exclusive `flock` on a
`.lock` file beside it, takes a token the relay refreshed meanwhile rather
than refreshing again, and refuses to spend a refresh token the endpoint
refused. The claiming node sends a form POST to `TOKEN_URL` of
`grant_type`, `refresh_token`, the client file's `client_id` and
`resource`, built as the request goes out through `request_options()`, so
a queued request never holds it; another node finding the refresh in flight
waits, and its next tick starts its pass once the refresh lands, through
`Source_Node::resumes()`, under the guard a scheduled pass runs in. A
refresh that will never answer, thrown on as it went out or dropped from
the queue, reaches `abandoned()`, which releases the claim. A refusal stands
until the file holds a token obtained after the refused one, by
`obtained_at`, so an older file's spent refresh token is never sent again;
without one, a token obtained at another moment than the one held clears the
stale mark. The answer
is merged over the held token and written back under the same lock through
a rename beside the file, so the relay and the node keep one valid pair. A
refresh refused with 400 or 401 logs `ContextA8C refresh refused (HTTP
<code>): sign in to ContextA8C again on the host`, and the next pass reads
the file again, waiting for the relay to sign in. A pass with no readable
token, or an expired one with no refresh token, logs and sends nothing.
Each of the three publishes idle, its reason opening `not signed in to
ContextA8C` and ending with the fix, to sign in to ContextA8C on the host.
A token lock that will not hold publishes failing, `could not lock the
ContextA8C token: <path>`, and a pass waiting on another node's refresh
publishes nothing, so the snapshot before it stands until the resumed pass.
Every call is one JSON-RPC `tools/call` of `context-a8c-execute-tool` to
the one url, queued under the subject `<provider>/<subtool>?<args>`, so
`queue()` takes a subject apart from the url and `request_options()` adds
the proxy, `Authorization: Bearer` and `Accept` as each goes out. The server
refuses a call outside a session, so every pass opens its own:
`initialize`, asking Curl for its `Mcp-Session-Id` response header, which
`headers_received()` keeps with the protocol revision the answer names, then
the `notifications/initialized` the protocol requires, then the calls, each
carrying `Mcp-Session-Id` and `MCP-Protocol-Version`, and once nothing is
left to send `pass_ended()` closes the session with a DELETE naming it,
through `send_closing()`, outside the request cap, so a capped pass closes
too. A
request answered 401 reaches `failed()`, which ends the pass through
`end_pass()` and marks the token stale, and one answered 400 or 404 under a
session ends the pass and drops the session, each logged, so the next pass
starts afresh; an `initialize` that does not name a session is logged and the pass
calls nothing. A JSON-RPC error, an `isError` result or a first text
content that is not a JSON object is logged under the subject; otherwise
`answered()` maps the provider's answer through the mapping the source it
stands in for exposes, so the item records under that source's key.
`Context_A8C_Slack_Node` reads `messages` over Slack's window, 100 a page,
older than `next_latest` while `has_more`, a message's workspace read off
its own `permalink`, and the `thread` of each message Slack's rule says
moved, paged by `next_cursor`. `Context_A8C_P2_Node` reads `posts-text`
over P2's window with each post's comments, every page to `max_pages`; a
comment records with no url, and only comments on the window's posts
arrive. `Context_A8C_Linear_Node` reads each team's newest 30 issues from
`team-issues`, every status group's, whose `description` arrives cut to 300
characters ending `...`. An issue so cut that moved within two intervals, or
the window with none set, waits while the node asks `issue { id }`, which
answers the same fields flat with the whole description, and records from
that, so its first extraction reads all of it; an issue that did not move, or
past the pass's room, records as cut. Once the pass's team pages are in, the
walk asks `issue` for stored `linear` items of the last 30 days still cut
short, as many as the room left under
`Source_Node::MAX_REQUESTS_PER_PASS` allows, drawn at random, because a worker
lives 595 seconds and a pass comes every 900, so any order would let rows that
never grow, a deleted issue or a genuine 300 characters, take the room every
pass. The backlog drains over passes and stops with no verb, and the page's
items record before it starts, so a walk that fails, logged, costs them
nothing. What `issue` answers of a stored item grows it (see The item
contract); a walk's answer for a row no longer stored writes nothing. The cut
item rides as its `issue` request's note, through `call()` and `queue()`, so
a request that fails, answers no description or is dropped, which `abandoned()`
hears with its note, records as cut, logged, and a stop loses nothing the next
page does not list again. `Context_A8C_P2_Node` carries the window its first page asked
forward to every later page of the pass.

**Seams and the model-call contract.** Each seam is a static `?\Closure`
replacing ONE call so the branches around it still run as production code.
Every fetch, each source's and every model call `intel:llm` makes, goes
through the substrate's own curl seams, `Event_Framework::$curl_dispatch`
and `Curl_Node::$curl_result`, which `tests/support/drives-curl.php`
drives: it completes a transfer by its url, or by its dispatch order
(`complete_nth()`) where every request shares one url, as model calls do.
`Intel_Graph_Row_Schema::$read_rows` and `Resolver::$similar_text` serve the
projection read and the fuzzy step's scoring, and `Watcher_Node::$wp_mail`
the one `wp_mail()` call every watch email goes through. A caller's tests
stand `tests/support/llm-answering-sink.php` in for `intel:llm`, a sink
answering each request TO its FROM at once. Both callers of `intel:llm`,
the extractor and the resolver's NER step, catch every `Throwable` their
answer's handling raises, re-throwing `Worker_Should_Stop` first (substrate
ADR-14), and park the item's retry, because an answer lands from Curl's
completion, where nothing above catches, and a throw there would end the
worker. `Source_Node` and `LLM_Call_Node` share their Curl and Frontier
half through `Owns_Curl_Frontier`: the constructor, the owned Table, Curl
built under the vault group with a `configure_curl()` hook, the rebuild on
a new group, `send_curl()`, which records a request in flight and hands
Curl its TM_STRUCT keyed by its subject, `abandon()`, which lets one go,
`settled()`, which takes an answer by its KEY when its FROM is the one the
node minted, and the clean stop's sweep. Each keeps its own contract for a
send Curl throws on, by design: `Source_Node` abandons it and rethrows,
since its pass logs and publishes the failure, and `LLM_Call_Node`
abandons it and answers its caller an error, since a caller is waiting. The AI API Proxy's bearer token never rides in a message: it is
the `ai-proxy` Vault group's entry on `https://public-api.wordpress.com`,
with `auth_header` `Authorization` and `auth_scheme` `Bearer`, which
`intel:llm`'s Curl sibling lends each call.

### The item contract

An item's row is written once, so a longer body reaches it only through
`item_grown`, an items-stream kind carrying the `item_recorded` fields, which
projects as `set`: `Sqlite_Arm` keeps the row's id and the `AFTER UPDATE`
trigger keeps `items_fts` in step. Its one minter, `Context_A8C_Linear_Node`,
writes the stored row's own fields, its first retrieval among them, with the
longer body, and only when that is longer in bytes than the stored body and
than any it has written in this process, so a body cannot shrink: `set` has
no such guard of its own, so that check is the guard. The resolver acts on
`item_recorded` alone, so a grown item spends no NER and no extraction until
`ontology reextract` asks. A plain repeat of `item_recorded` stays `ignore`.

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
`Core::terminal_safe()`, so a record naming it never outgrows one line; it counts
as refused and is remembered, so a process refuses it once. It wraps each
in `Intel_Event::machine_event()` under the actor `machine:source:{name}@{plugin
version}`, with the plugin version as its `config_version`.

### Purging items

`item_purged { item_key, reason }`, an items-stream kind, deletes with the
substrate's `delete` op the rows the items stream has written about its
item by the time the projector reads it, in the tables that stream writes:
`items`, which `items_fts` follows through its triggers, `extractions`,
which `extractions_fts` follows, `extracted_subjects`, `themes`, `links`,
`claims`, `clusters`, `boilerplate`, and the `canonicals` row of any
cluster whose canonical it is.
Nothing elects that cluster another canonical; its kept members keep their
`clusters` rows. Rows another stream wrote about the item — `judgments`,
`collected`, `watch_matches`, `watch_seen` — stay, and every read leaves
them out, because every read joins `ri`; a collection still lists its saved
copy. An entity a machine proposed from the item (`entity_proposed`, on
the ontology stream) stays in the entity index and goes on matching other
items. A replay applies the record, then the purge, and ends purged, while
a later `item_recorded` of the same key, a source recording it again,
records it again.

An item the resolver has not reached when its purge is projected is
resolved afterwards, from the `item_recorded` its own Consumer still
holds, and the links, extraction and claims that writes outlive the
purge: every read leaves them out, since the `items` row is gone, and a
rerun never selects them, since the selection reads that row. So purge only once
the resolver has caught up — `wp nodes status` lists the reader
`<topology>.intel-items-resolve.p<N>` with nothing behind, `<topology>`
being the one it lists for Intelligence.
An `item_grown` whose read of the row came just before the purge was
projected restores that row; a rerun of `items purge` removes it.
A worker running a release older than the one carrying `item_purged`
drops every purge its writer receives as an unknown kind, so restart the
workers (`wp nodes restart all`) after deploying that release and before
purging.

`wp newspack-intelligence items purge --source=<name> --keep=<globs>
[--reason=<text>] [--dry-run]` (`Items_CLI_Command`) mints them. It selects,
across every partition, each stored item of the source whose key matches
none of the comma-separated keep globs, each matched by `fnmatch()`
against the whole key in its own case. `--keep` is required, so a command never
purges a whole source, and WP-CLI keeps only the last of a repeated flag,
so the command counts `--keep` in `$_SERVER['argv']` and refuses it given
twice. It purges when run, with no prompt: it appends one `item_purged`
per item, as `machine:purge@<version>`, its `reason` the one given or
the selection, at most `REASON_BYTES` (512) as the stream writes it
(`Graph_Curation::written_bytes()`). The items
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
names the dry run, the command with `--dry-run`, to run once the
projectors have read the purges, which answers `Nothing to purge` when
nothing is left. With `--dry-run` it writes nothing and prints the count per
key prefix, up to the first `-` and its control characters rendered by
`Core::terminal_safe()`, the total and the command that purges them, the
same command without `--dry-run`, `--reason` carried when given.
`Items_CLI_Command::$sleep` is the wait's test seam. The Linear items
recorded from teams a deployment no longer lists go with one `--keep` glob
per team it does, `<KEY>` standing for each team's key:

```bash
wp newspack-intelligence items purge --source=linear \
  --keep='linear:<KEY>-*,linear:<KEY>-*' \
  --dry-run
```

Read the counts, then run the command it prints, the same command without
`--dry-run`, which purges them.

### Topologies

| File | What it wires |
|------|---------------|
| `newspack-intelligence.tsl.php` | Its frontmatter, then `-core`, `-collect` and `-deployment` last: `on_demand_idle 0` keeps the worker resident, which the scheduled sources need, and `stale_timeout 600` stays until a measured run shows a lower value is safe, because a pass still holds work across ticks. Frontmatter is read from the activated file alone, so a part cannot carry it — the composition a deployment activates, though nothing finds Intelligence by this name. `Worker_Route::running()` takes Intelligence's topology to be the one active topology declaring an `Intel_Event_Writer` node (every composition includes `-streams`, through `-core`), so a deployment may compose its own under another name, holding its frontmatter, `-core`, its own collect and `-deployment` last, and activate that instead; routing, `fetch_now`, `Intelligence_CI_Node::sources()` and every `health` slice read that one. Two active topologies declaring the writer are refused naming both, and none read as collection off, which routing refuses (`Worker_Route::topology()`) and `health` answers `inactive`; with none declaring it while an active `.tsl` will not read, both are refused naming that topology and why, while an active name that does not resolve to any `.tsl` (`Topology_Missing`) declares nothing and is passed over. Uninstall skips a composition while it will not read only because the lists file does not resolve, because its parts declare its tables, and fails on any other shipped topology that will not read |
| `newspack-intelligence-workstation.tsl.php` | The composition a workstation activates in place of `newspack-intelligence`: the same frontmatter and `-core`, then `-collect-public`, `-context-a8c` in place of the token half and `-collect-unthread`, then `-deployment`, then `cmd feed:config set_concurrency 6`, because a workstation's Docker resolver fails lookups at sixteen. The `cmd` applies after the include, so it lands after the site's lists |
| `newspack-intelligence-context-a8c.tsl.php` | `linear`, `slack`, `p2` and `p2-restricted` as ContextA8C nodes, each under the name, tier and interval of the token source it stands in for, so the site's lists reach it and its items record under that source's keys, reading the relay's token dir at `/mcp-auth` through the proxy at `host.docker.internal:8080`; includes the streams |
| `newspack-intelligence-core.tsl.php` | Everything but the collect: `-streams`, `-project` and `-resolve`, then `reresolver`, which spans the project and streams files, fed by its own `intel:ontology:reresolve` Consumer, and `watcher`, which reads the projection and writes to `intel:writer`; every composition includes it first |
| `newspack-intelligence-streams.tsl.php` | `intel:items`, `intel:ontology`, `intel:reresolve`, `intel:watches`, `intel:writer`, and `intel:later`, the Delay whose due records go to `intel:reresolve` |
| `newspack-intelligence-collect.tsl.php` | The eight sources → `intel:writer`, as two halves it includes: `-collect-public`, GitHub on a 900-second grid, `feed` on 1,800 with client feeds on and `news`, a second feed node for searches, on 3,600; and `-collect-token`, Linear, Slack and Unthread on 900, `p2` and `p2-restricted` on 1,800, each under its Vault group, the two P2 nodes sharing `p2`, Unthread through `-collect-unthread`; includes the streams |
| `newspack-intelligence-collect-unthread.tsl.php` | `unthread` at the restricted tier on 900, under its Vault group, → `intel:writer`, an include of its own so a collect reading the rest of the token half another way can keep it; includes the streams |
| `newspack-intelligence-deployment.tsl.php` | `include newspack-intelligence-lists` alone, which every composition includes last; it declares no `secure`, because the Shell skips one inside an include. The file it includes, `newspack-intelligence-lists`, is not shipped: what a site reads and cares about, which it keeps in the user topology dir, `<base_dir>/topologies/newspack-intelligence-lists.tsl`, empty when it has no settings of its own. The shipped topologies name no repo, feed, search, team, channel, site, profile line or audience, so with an empty file GitHub, Linear, Slack, both P2 nodes and `news` fetch nothing, `feed` reads only the open clients' feeds, Unthread, which takes no list, reads as it would anyway, and the extractor frames its prompt without an audience, scores without a profile and sets no importance floor. The lists' `cmd <node>:config add_…`, `add_profile`, `add_importance_floor` and `set_audience` lines reach every node the files before the deployment file declare, because every composition includes that last. Without a lists file in any topology dir, none of the compositions reads: the worker cannot load, `wp nodes doctor` fails worker liveness naming the include and opens with a warning naming the path and that an empty file will do (`Intelligence_Health::warn_missing_lists()`, on `before_invoke:nodes doctor`), and the health strip's `collection` row reads `no lists file` naming the path. The user dir resolves after every stock dir, so the plugin ships no lists file, which would win, and a user-dir `newspack-intelligence-deployment.tsl.php` is shadowed by this one |
| `newspack-intelligence-project.tsl.php` | `intel:items:consumer`, `intel:ontology:consumer` and `intel:watches:consumer`, each → `projector`, writing the `intel-graph` Row_Table; their cursors serve no other node |
| `newspack-intelligence-resolve.tsl.php` | `intel:items:resolve` and `intel:reresolve:resolve` feed `resolver`, which feeds `extractor`, which feeds `deduper`, each of those three writing its events to `intel:writer`, the resolver and the extractor parking on `intel:later` and asking `intel:llm`, an `LLM_Call` under the `ai-proxy` Vault group at concurrency 4, its decision model `decision-lux-9b`, for every model call, the items Consumer checkpointing the deduper; includes the streams |

### Nodes

`Consumer`, `Partition`, `Row_Table` and `Delay` come from the substrate. This
plugin adds:

| Type | Class | Runtime triggers and verbs |
|------|-------|----------------------------|
| `Github_Source` | `Github_Source_Node` | `<tier> [vault_group]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_repo`, `remove_repo`; owns `{name}:curl` and `{name}:frontier` |
| `Linear_Source` | `Linear_Source_Node` | `<tier> [vault_group]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_team`, `remove_team`; owns `{name}:curl` and `{name}:frontier` |
| `Slack_Source` | `Slack_Source_Node` | `<tier> [vault_group]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_channel`, `remove_channel`; owns `{name}:curl` and `{name}:frontier` |
| `P2_Source` | `P2_Source_Node` | `<tier> [vault_group]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_site`, `remove_site`; owns `{name}:curl` and `{name}:frontier` |
| `Unthread_Source` | `Unthread_Source_Node` | `<tier> [vault_group]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`; owns `{name}:curl` and `{name}:frontier` |
| `Context_A8C_Slack`, `Context_A8C_P2`, `Context_A8C_Linear` | `Context_A8C_*_Node` over `Context_A8C_Source_Node` | `<tier> <auth_dir> [proxy]`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, and the list verbs of the source each stands in for; owns `{name}:curl` |
| `Feed_Source` | `Feed_Source_Node` | `<tier>`; `TICK`, and the Router's tick once `set_interval` is set; `set_tier`, `set_interval`, `add_url`, `remove_url`, `set_client_feeds`, `set_concurrency`; owns `{name}:curl` and `{name}:frontier` |
| `Intel_Event_Writer` | `Intel_Event_Writer_Node` | `<items_partition> <ontology_partition> <watches_partition>`; no verbs |
| `Projector` | `Projector_Node` | `set_graph_target` |
| `LLM_Call` | `LLM_Call_Node` | `<vault_group>`; a caller's request, and the Router's tick, which sends what its Table holds and publishes `{ queue: { new, backlog, in_flight }, at }`, at most every 5 seconds once the queue moves and every 300 while it does not; `set_api_url`, `set_model`, `set_decision_model`, `set_feature`, `set_concurrency`; owns `{name}:curl` and `{name}:frontier` |
| `Resolver` | `Resolver_Node` | `<writer> <later> <llm>`; no verbs; publishes its hold (`reason`, `since`) through `Publishes_Status` |
| `Extractor` | `Extractor_Node` | `<writer> <later> <llm>`; `set_audience`, `add_profile`, `remove_profile`, `add_importance_floor` and `remove_importance_floor` |
| `Deduper` | `Deduper_Node` | `<writer>`; no verbs |
| `Reresolver` | `Reresolver_Node` | `<stream>`; `RERESOLVE <since> <until> [<source>]`, `REEXTRACT <since> <until> [<source>]`; publishes `last_range` through `Publishes_Status` |
| `Watcher` | `Watcher_Node` | `<writer>`; the Router's tick, every `WATCH_INTERVAL_S`; no verbs |
| `Intelligence_CI` | `Intelligence_CI_Node` | `search`, `entity`, `timeline`, `related`, `claims`, `trends`, `signals`, `review`, `theme_suggestions`, `collections`, `lenses`, `watches`, `save_watch`, `remove_watch`, `watch_matches`, `mark_seen`, `briefing`, `health`, each at `read`; `confirm_link`, `reject_link`, `accept_entity`, `reject_entity`, `merge_entities`, `add_name`, `add_identifier`, `assert_role`, `end_role`, `tag_topic`, `create_entity`, `create_collection`, `collect`, `save_lens`, `remove_lens`, `merge_theme`, `seed_ontology`, each at `tune`; `fetch_now`, `reresolve`, each at `manage` |

`LLM_Call_Node`'s four verbs are declared setters, replayed by its
`dump_config()`; `set_api_url` refuses anything but an absolute http(s)
url, naming it, and drops a trailing slash, and `set_concurrency` refuses a
count outside 1 to `Curl_Node::MAX_IN_FLIGHT`. `Extractor_Node` declares
the prompt verbs, `set_audience`, `add_profile` and `remove_profile`, and
the floor verbs, `add_importance_floor` and `remove_importance_floor`,
which the resolver, building no extraction prompt, does not declare; its
`dump_config()` replays every setter and list, then one
`add_importance_floor` line per floor. An identical `add_*` repeat is
a no-op, and each `remove_*` takes its `add_*`'s argument, drops that entry and
is refused when that entry is absent. Every `Intelligence_CI` verb declares its role, and
the handlers never check a role of their own (substrate ADR-26).

### Client roster

`Client_Importer` reconciles a client CSV against a `Client_Roster`. An
import is a reconciliation against a full snapshot, reported as five
disjoint counts. A CSV id absent from the roster is `created`; a present one
the roster answers `held`, a client a curator ended, is left alone and
counts as `held`; any other present one
has its atomic fields updated and counts as `reactivated` if it was churned
and `updated` otherwise; an `active` id the roster's `statuses()` holds
that the CSV lacks is `churned`. The command's success line reports the
fifth as `<n> held by curation`. The importer then calls the roster's
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
and reads as `churned` whenever it holds no open `client` role and is not held (below). Its first call of
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
`Atomic site <id>` when it has none; a row that does not name an atomic id is refused. A domain is added as an `identifier_added` only when it is not
held by any entity, so an import never moves a curated or seeded entity's
domain, and it renames nothing. One entity may carry several atomic ids,
one per site a publisher runs, and churns once, only when every one of them is
missing from the snapshot. A churn writes `role_ended client` on the key each open row is
held under, from that row's `from_ts`, and `role_asserted former_client` on
the survivor; a return is the reverse. `Entity_Index::load()` leaves out an
identifier whose survivor is rejected or whose merge chain cycles or runs
past 10 hops, and `Entity_Index::holder()` answers the key holding it, as
it answers a live holder's survivor, the one rule the roster and
`add_identifier` read a holder by; a holder the index holds no state for
is a refused one. A client
whose atomic id such an entity holds — rejected, merged into a rejected
survivor, or on a broken chain — is left alone: no event, counted
`updated`, never churned. So is a new client whose domain such an entity
holds, and a domain one holds is never added to a client. Every entity left
alone is named in one stderr line per import. An event that will not mint
throws naming its row's atomic id.
A client is a client from the start, UTC, of the earliest day its rows in
the snapshot were `Created` (`Y-m-d`, then optionally a time of day, which
is dropped), or from the latest end of a `client` or `former_client` row
that held time, its return or a curator's `end_role`, when that is later,
so an ended row is never reopened; a churn and a return's `former_client` end
date from the start of the import's day. A client holding neither role open
whose `client` row closed holding time is a client a curator ended, since a churn
always opens `former_client`: the roster answers its atomic ids `held`, and
no import opens a role on it, not even through a new atomic id whose domain
it holds, which gains the id alone. Such a new atomic id still counts as
`created`, though it opens no role. A `Created` that is not a day, or
whose time is not a time of day, refuses the import, naming the row's
atomic id, before anything is written. One read of the `roles` table gives
both the open rows and those ends. Once every row is reconciled,
`commit()` settles each client in the snapshot: an open `client` row
starting on another day ends at its own start, holding no time, and the
client's one `role_asserted` is minted from its start unless an open row
already starts there, so a re-import corrects a client the import day
dated. Identifier
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
| `index.js` | — | Mounts `IntelligencePage` on `#newspack-intelligence-app`, handing it `canTune`, `canManage` and `vocabulary` from `NewspackNodesData` |
| `IntelligencePage.js` | default | Opens on Briefing, its first tab, and switches Briefing, Search, Entity, Lenses, Review, Collections and Watches in place; holds the submitted search, the open entity, lens, collection and watch, the watch form's draft and the item being saved, so a view returns as it was left, and a lens's "Watch this" opens the watch form on that lens; labels the Entity tab "Entity: <name>" with what the open entity's heading shows, which `Entity` hands back through `onNamed` paired with its key, so a name answered for an entity opened before never labels the next, else, before the record answers or once it is refused, "Entity: <key>"; the Briefing opens an entity, a lens or the lens list, a watch, the watch form or Search; a URL carrying `watch=<key>`, as the watcher's email links it, opens the page on Watches with that watch open, or on the list when the reader's watches do not hold it; mounts `HealthStrip` above the tab bar, on every view; renders inside the shared `DashboardShell`, which supplies the skin, the page surface, the `NEWSPACK::NODES` header subtitled `Intelligence` and the debug overlay |
| `hooks/useIntelCommand.js` | `useIntelRead`, `useIntelWrite`, `commandArgs` | `commandArgs()` names every value given, a list once per member, leaving out a blank value or an empty list; a read asked whenever its arguments change, showing only the answer to the question it asks now, or a refusal that echoes no arguments; a write sent once per `run()` to the `intelligence` CI or the `ci` it names, which answers the tokens it sent, running `refresh` once it lands, handing a refused send's echoed tokens to `onRefused`, and handing back `useCommandOnce`'s own refusal, answered arguments and `pending` |
| `components/HealthStrip.js` | default | The four `health` slices, read on mount, after a fix lands and on Refresh, laid out as one `.newspack-nodes-banner` of rows; every row's pill, tone, cause and fix come from one table of states (`STATES`), and a state it does not hold reads as a neutral unknown row; each row renders from state, times and counts, so a reader below manage, whose answer omits `credential`, `vault_group`, `last_error` and `file`, reads it whole; while collection is off every other row is listed neutral, never a fault, and its Activate fix offers one button per composition the slice's `topologies` names, which leaves out any whose sources cannot collect on this host, labelled with the name and sharing one `activate` write, the row naming `wp nodes activate` as `<code>` when it names none; `unconfigured` is a fault naming the path the lists file belongs at and that an empty file will do, or, for a reader whose answer carries no `file`, that an administrator can create it and an empty one will do; the projector catching up on the ontology is neutral until its hold has stood five minutes (`BEHIND_FAULT_S`), then a fault; with nothing held the backlog row counts the later queue's entries as items waiting to resolve or retry; while the extraction slice's `queue` holds a call, a neutral "Model calls" row, pill `busy`, counts them, grouped in the page's own language, a class or a part holding none left out ("5,735 calls waiting (5,735 backlog) · 4 in flight", "1 call waiting (1 new)", "2 in flight"), for every reader, and the line follows the backlog row's cause with that row's counts, dropping the cause while the backlog row reads idle (nothing held, `held` 0), so it never says "Nothing waiting." beside queued calls; every time it shows renders in the browser's own zone through the substrate's `formatLocalDateTime()`, while the dropped days stay the UTC days the server names, read as "Items parked from <first_day> to <last_day> were dropped before they resolved.", true of an entry aged out and of one the full queue refused; the line names each source that has not reported yet ("Collecting from 5 of 6 sources; feed has not reported yet."), the names joined in the page's own language (`document.documentElement.lang`, else the browser's), and says the health is not fully known for any other unknown row, a source in a state it does not recognise included; a fix mounts its write only for a reader holding its role (`canTune` for `seed_ontology`, `canManage` for the rest), and anyone else reads "An administrator can …" |
| `views/Briefing.js` | default | The two `briefing` slices and `watches`, each its own read and card: "Last seven days", each group in the answer's order and headed by its `role`: a `client` group under its `EntityChip`, the `newspack_product` group under "Newspack" and the group of role null under "Industry", each item an `ItemRow`, and, when the window held more than it shows, how many of how many; "Signals", each client's under its chip and each lens's under the button opening it, through `TrendTable`; "Your watches", each a `WatchRow` opening it; a card answering nothing says so and offers the one control that acts on it: Search everything, Open lenses or New watch |
| `views/Search.js` | default, `newSearch` | `search` with words, the lens's list filters (`vocabulary.lensLists`), an entity and a day range, a page at a time, in the order the answer's `sort` names, which the reader switches, sending `--sort` from the first page; a lens picked from `lenses` shows every filter it searches with read-only and sends only `--lens`, the words, the order and the page, and "Edit as my own search" copies its filters into those fields — its days as `since`, the first of the last that many UTC days, its tiers dropped, which the view says — and drops the lens |
| `views/Lens.js` | default, `CLOSED_LENS` | `lenses`, listed and opened onto `signals --lens`, then `trends --lens` as one `TrendChart`, then `search --lens --group=theme`'s theme groups through `GroupPage`, a page of groups at a time in the order the server ranks them; signals citing their items, each signal's count shown against its baseline; for every reader, "Watch this", which opens the watch form on the lens; for a curator, `save_lens` for a new lens or the one opened — kinds, topics, roles and sources comma-separated, tiers as checkboxes over `vocabulary.tiers`, the trend axis and bucket as selects over `trendAxes` and `buckets` led by "Not set" — and `remove_lens`, each held through `useHeld` until a read of `lenses` reflects it |
| `views/Entity.js` | default | `entity`, headed, for a record answering a `status`, by the client's status line — client since, the first row of its own `signals` read once answered or "No signal", its churn reports, churn signals, feature requests and complaints, and the day of its last item or "No item about it yet" — then `signals --entity` under its header, both axes, each row's value its axis and kind and the value it counts, its count against its baseline, then `timeline` of the items about it a page at a time, then, when the record counts any, a collapsed "Published by <name> (N)" section reading `timeline --published` a page at a time only once opened, `related` and `claims`, each claim read as its subject's `EntityChip`, the predicate and its object, a chip when `object_name` is set and the item's spelling as text when it is null, each chip showing the key where the name is null, and cited by its item's title linking its URL; after the timeline, a "Details" section lists the keys merged into it, its names, identifiers, topics and every role row but a closed one that held no time (`to_ts` not 0 and equal to `from_ts`), an import's same-day correction, so an open row a seed asserted from 0 stays; hands `onNamed` the entity's key with what its heading shows, its name else its key, whenever the record answers, and with null once it is refused; once the record answers, for a curator, `EditDrawer` under its counts and, when its `status` answers `churn_open`, "This client has a churn report." and an "End client?" button in its status line, which sends `end_role` (`client`) and, only once that lands, `assert_role` (`former_client`), the button `aria-disabled` and sending nothing until the pair is answered or refused, a refused end asserting nothing, each refusal shown as every edit's is |
| `views/Review.js` | default | `review links`, one row per item with a pill per proposed entity and the sentence each link quotes, and `review entities`, a pill per entity, each citing item with the sentence it quotes; for a curator, each pill's ✓ and ✕ send `confirm_link` and `reject_link`, or `accept_entity` and `reject_entity`, and an entity's Merge… opens `merge_entities`, its field focused; a judgment hides its pill or row through `useHeld` as it is sent, until a read no longer lists it, and a refusal puts it back. Each queue's list, named for its queue and described by a visually hidden sentence of its keys, takes focus and the review keys while focus is in it and not in a field, as the substrate's `isTypingTarget()` reads it: `j` and `k` move between the link pills or the entity rows (`data-review-row`, `tabindex="-1"`, so the shared `[tabindex]:focus-visible` ring shows), and `y`, `n` and `m` press the focused row's control whose `aria-keyshortcuts` names the key (✓, ✕, Merge…), a judgment moving focus to the row then in its place, else the last; then "Themes", `theme_suggestions`' pairs, each naming both themes, their stories, the entities they share and the words their names share, and for a curator "Merge into" each theme, which sends `merge_theme` from the other and hides the pair through `useHeld` as `merge_entities` does |
| `views/Collections.js` | default | `collections`, listed and opened; for a curator, `create_collection`, its collection shown saving through `useHeld` until a read lists it by name |
| `views/Watches.js` | default, `CLOSED_WATCH`, `newWatch`, `WatchRow` | `watches`, the caller's own, each a `WatchRow`, the one row the Briefing lists too: the button opening it, its target, and its unseen count, or "Inbox off" for a watch without its inbox, or the words that a save of it is awaiting a read; opened onto `watch_matches --key` a page at a time, or onto the words that its inbox is off, each match with a "Mark seen" (`mark_seen --key --item`) and the page with "Mark these seen", naming each match it shows, a marked match hidden through `useHeld` until a read no longer lists it and a landed mark returning to the first page; for every reader, not gated on `canTune`, the form saving a new watch or the one opened through `save_watch` — its name, the kind it follows and exactly one target (a lens picked from `lenses`, an entity key, or a collection picked from `collections`), and the three deliveries as checkboxes, each sent as `true` or `false` — and `remove_watch`, each held until a read of `watches` lists the watch with the name, target kind and deliveries the save sent, an edit by its own key, the target's key uncompared because the server stores an entity as its survivor |
| `components/TrendTable.js` | default, `TrendChart`, `againstBaseline`, `signalValue` | The table of signals a lens, an entity and the Briefing render, each row's bucket, a date that does not wrap, value, count and cited items; `TrendChart`, a lens's trend rows as one compact shared `AreaTimeChart`, a series per value over the 8 newest buckets, each a UTC day, at most 5 values, those counting the most over them first, titled by the span it covers, every row also a visually hidden table of bucket, value and count, and nothing for no rows; a signal's count against its baseline, and its axis and kind with the value it counts |
| `components/EditDrawer.js` | default | An entity's curation behind an "Edit" toggle carrying `aria-expanded` and `aria-controls`, closed until opened onto a region "Edit <name>": each role it holds open with "End <role>" (`end_role`), then the forms `add_name`, `add_identifier` and `assert_role`, each through the write the Entity view hands it |
| `hooks/useHeld.js` | `useHeld` | A read's rows with each write shown from the moment it is sent: a held key's row taken out until the reads stop listing it, or a held row put in its place until a read lists one `matches` says reflects it; a refusal undoes the hold whose send it echoes, and a bare refusal every hold |
| `components/SaveToCollection.js` | default | The one dialog any result saves through, sending `collect` |
| `components/Answer.js`, `Refusal.js` | `Answer`, `AnswerBody`, `Refusal` | One read in exactly one state — owed, refused, unavailable (one sentence for every read), empty when every list its `rows` names is, its words and any control the view gives as `empty`, or its rows — as a stock `Card` section named by an optional title, which heads its `CardHeader`, with the state inset in its `CardBody`, or bare inside a dialog; the one alert every refusal, read or write, renders in |
| `components/Pager.js` | default | How many of the matches a page shows, as items unless `forms` names the plural forms of another noun, and Previous and Next over a stack of page starts, for Search, the Entity timeline and its published items, a lens's groups and a watch's matches |
| `components/GroupPage.js` | default | A page of a lens's theme groups in the answer's order under the fixed line "Ranked by severity, importance, clients affected, then recency": a group counting one item as that item's `ItemRow`, any other a row headed by its theme as an `h4`, its count, kind and newest UTC day, an `EntityChip` per client, and a "Show items" button carrying `aria-expanded` and naming the theme, which discloses its items as `ItemRow`s; then its `Pager`, counting groups through `forms`; each row keyed by its theme and first item's key, since a story can stand in two groups |
| `components/ListField.js`, `lensDraft.js` | `ListField`; `lensDraft`, `newLens`, `draftLens`, `draftArgs`, `listOf`, `daysSince` | A comma-separated field naming the values a closed list allows; a lens read into a form's text and back into the lens and the options `save_lens` and `search` take, its list filters read from `vocabulary.lensLists` and its tiers kept beside them, the one place that knows the lens's shape; and the first UTC day of a lens's rolling window |
| `components/ItemRow.js`, `Cite.js`, `format.js` | `ItemPage` | An item row, headed by any control the view passes as its children, its excerpt behind a "Show more" toggle unless its summary or title holds it, its entities as chips, leaving out a `proposed` one while another of its name is not proposed and keeping only the first of several proposals of one name, and any whose `role` is neither `subject` nor `mention`: an open client and a subject each chip, in the answer's order, and its mentions sit behind a "+N more" toggle carrying `aria-expanded`, which shows them after the rest and reads "Show fewer"; and `ItemPage`, a page of rows above its `Pager`, which Search and the Entity timeline and published section render, and which, given `onSort`, says whether the page is ranked by importance or newest first and offers the other; a citation linking only an http or https URL, and `EntityChip`, the one chip opening an entity; the UTC day (`utcDay()`), `metaLine()`, which joins a row's meta parts and keeps a 0, and `roleText()`, a role row as open since its first day or held between two |
| `styles/intelligence.scss` | — | Geometry only, emitted as `build/intelligence/index.css` |

Each read is a `useCommandOnce` read with `retry`, under the `intelligence`
group, so `connect intelligence:shell` watches every send; its scope names
what the slice shows (`entity:record`, `review:links`, `lens:items`), so
two reads of one verb never share a Fetcher. A write is one `useCommandOnce`
per verb, held by its view rather than by a row, sending every arg named
(`--key=…`, through `formatCommandArgs`) so a curator's value beginning
`--name=` binds as a value, and a write that lands
re-reads the slice it changed. A send never names a subject: an item key spells a
whole URL, past the length a reply address carries, so a refusal is matched
to its send by the arguments it echoes. Review, Lenses, Collections and
Watches show each write as it is sent through `useHeld`, because the projector records it
only on its next flush, so a re-read answers the old rows until then.
The view switcher is the substrate's shared `TabBar`, over one `tabpanel`
labelled by the selected tab. `canTune`, which `enqueue_intelligence_assets()` sets
from `Capabilities::can( TUNE )` on the page's own hook suffix alone, as
the string `'1'` or `''` because the substrate refuses a boolean extra,
only hides the curation controls: the server's role gate refuses a write the
caller may not make. `canManage` is localized beside it the same way, from
`Capabilities::can( MANAGE )`, so the page knows whether its reader is an
operator, which the health strip reads to offer the operator fixes. The
vocabulary the selects offer is `Intel_Event`'s own lists, `tiers` among them, the trend axes and buckets as `trendAxes`
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
| `newspack-intelligence.php` | Bootstrap: substrate handshake, topology registration, admin menus and the Settings page's admin-post helpers, WP-CLI and its `before_invoke:nodes doctor` warning, the `Intelligence_CI` mount, the MCP door's `wire()` |
| `includes/` (streams) | `Intel_Event`, `Intel_Event_Writer_Node`, `Projector_Node`, `Intel_Graph_Row_Schema`, `Url_Canon`, `Ontology_Seed` and its `Seed_Refused`, `Stream_Writer`, the `Emits_Struct` trait |
| `includes/` (collect) | `Source_Node` and its six connectors; `Context_A8C_Source_Node`, its three local connectors and `Context_A8C_Token`, the process's hold on the relay's token |
| `includes/` (resolve) | `Entity_Index`, `Resolver`, `Resolver_Node`, `Extractor_Node`, `Deduper_Node`, `Reresolver_Node` |
| `includes/` (clients) | `Client_Importer`, the `Client_Roster` interface, `Graph_Client_Roster`, `CSV_Parser`, `Clients_Settings` |
| `includes/` (LLM) | `LLM_Call_Node`, every model call; the `Asks_Model` trait, its callers' half; `Prompts`, the requests' messages and the parse of their replies; the `Owns_Curl_Frontier` trait, the Curl and Frontier half it shares with `Source_Node` |
| `includes/` (service) | `Intelligence_CI_Node`, `Intelligence_Briefing`, `Intelligence_Health`, `Intelligence_Mcp_Door`, `Graph_Query`, `Graph_Curation`, `Graph_Watches`, `Intel_Tiers`, `Restricted_Settings`, `Worker_Route`, `uninstall-cleanup.php` |
| `includes/` (watches) | `Watcher_Node` |
| `includes/cli/` | `Clients_CLI_Command` — `wp newspack-intelligence clients import <csv>`; `Items_CLI_Command` — `wp newspack-intelligence items purge`; `Ontology_CLI_Command` — `wp newspack-intelligence ontology seed [<file>]`, `ontology reresolve`, `ontology reextract` and `ontology reproject`; `Restricted_CLI_Command` — `wp newspack-intelligence restricted grant <login>`, `restricted revoke <login>` and `restricted list`; `Eval_CLI_Command` — `wp newspack-intelligence eval <file>`; the `Refuses_Root` trait they share |
| `topologies/` | The `.tsl` node-graph topologies |
| `examples/` | The fictional ontology seed and eval labels, the shapes a deployment starts its own from |
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
  `delete_tables()` removes the SQLite files, WAL files included, of
  every Row_Table the plugin's own topologies declare with the `Intel_Graph`
  schema and of every `{name}:frontier` Table their sources own, on every
  partition — under a base directory this install
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

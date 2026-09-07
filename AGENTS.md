# AGENTS.md — Newspack Intelligence

An AI-driven team intelligence digest built on the `newspack-nodes` substrate
(sibling plugin; `Requires Plugins: newspack-nodes`). It ingests GitHub, Linear
and feed items, enriches and scores each one with an LLM, accumulates them into a
durable digest, and publishes that digest as markdown and a WordPress draft post.

> The whole path runs end-to-end: three live connectors, the LLM enrich / score /
> compose stages, the intake Gate that attributes an item to a Newspack client,
> and the two-column Publisher Insights dashboard. The design record lives in
> dndocker under the pre-rebrand `newspack-ai-newsletter` filenames (see
> [References](#references)); those documents are historical, and the code is the
> authority.

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
npm run lint:shell                        # shellcheck over pre-push and scripts/*.sh
npm run lint:deadcode:js                  # knip; GATED in pre-commit (caveat below)
npm run test:js                           # jest (local); test:js:coverage feeds the gate
cd tests && ../vendor/bin/phpunit         # PHP; needs the substrate active
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
  'cd /services/pyrobase/sources/newspack-intelligence/tests && ../vendor/bin/phpunit'
```

After adding or renaming a Node class, regenerate the classmap (`make_node` and
the console palette read it): `composer build:autoloaders` (= `composer
install --optimize-autoloader`) or `composer dump-autoload -o`.

**The substrate floor is a handshake, and a floor set too LOW is the dangerous
error.** The loader calls `Bootstrap::version_at_least( '2.25.0' )` and stays
dormant below it. 2.25.0 is `Node::config_line()`, which every source node builds
its `arguments` dump from; `Worker_Base::ipc_partition_args()`, which the insights
CI makes its Partition with, landed in 0.44.0. Against a substrate between a
too-low floor and the real one the handshake passes, the plugin activates, and it
then fatals on the missing method — the one outcome the gate exists to prevent.
`scripts/check-substrate-floor.sh` derives the true floor from PHPStan's
declaring-class resolution and compares it to the declared one, and
`scripts/lint-docs.sh` fails the push when the number in this file and the number
in the loader disagree. Change both together.

**`@wordpress/*` is pinned to the `wp-7.0` dist tag — never bump it to close an
advisory.** Every declared runtime version IS its `wp-7.0` tag: `api-fetch
7.40.1`, `block-library 9.40.2`, `blocks 15.13.1`, `components 32.2.1`, `element
6.40.1`, `i18n 6.13.1`, `icons 11.7.1` — the family matching the WordPress 7.0.4
both containers run. The substrate build-kit externalises `element`, `api-fetch`,
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
dismissed, not bumped: 2026-08-17, uuid GHSA-w5hq-g745-h8pq needed `components >=
33.1.0`, one major past `wp-7.0`, for a package absent from `build/` entirely —
dismissed `not_used`. npm `overrides` cannot rescue that, because npm matches an
override by package NAME anywhere in the tree: both `{uuid: …}` and the nested
`{"@wordpress/components": {uuid: …}}` downgraded the fourteen `@wordpress/*`
packages that legitimately require `uuid@^14`.

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
shared scripts THERE, not here.

`pre-push` runs the JS suite with coverage, its per-file 90% gate and
`scripts/lint-docs.sh` on every push, including a docs-only one: jest can break
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

Four stages, each its own topology file, composed by `newspack-intelligence.tsl`.

**Ingest.** The connector **Source nodes** (`github`, `linear`, `feed`) fetch on a
`TICK` request inside the background worker and append normalized items to the
durable `ingest` Partition. Blocking HTTP runs in the worker rather than a
per-fetch job; the ingest buffer and the paced consumers downstream keep the
worker heartbeating through a collect. `Source_Node` dedups by item `id` against
a bounded in-memory set (`MAX_SEEN` 2000, oldest evicted first) that does not
survive a respawn — `Digest_Builder_Node` dedups on the same id, so the digest
stays correct while the summarize and score stages pay for the repeat.

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
draft when every source has reported `DONE` — counting DISTINCT source names, so
a re-tick, a replay or a stale cross-cycle signal cannot overshoot `total` — or
on an explicit `REGENERATE`. `Digest_Composer` takes the top 10 per source, so no
busy source crowds the others out, and asks the LLM for a markdown briefing under
a 32000-token budget; a thrown `RuntimeException`, an empty reply and a
whitespace-only reply all render that same selection as a ranked bullet list
rather than throwing. The cap is `Digest_Composer::PER_SOURCE`, unrelated to the
dashboard's `Insights_CI_Node::TOP_N`: the two hold the same number today, and
changing one leaves the other where it was. `RESET` empties the accumulator and
nudges the scored Partition so the consumer's next checkpoint co-commits the
emptied snapshot; without the nudge a worker restart reloads the stale item list.

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
the worker over its input IPC partition — durable and synchronous, with no
live-worker dependency on read. The browser creates the WordPress draft from the
digest markdown via `@wordpress/api-fetch`. LLM calls go through the Automattic
AI API Proxy via `LLM_Client` / `Proxy_LLM_Client`, whose `$http_post` closure is
the test seam. Seven more follow the same pattern, each a static `?\Closure`
replacing ONE call so the branches around it still run as production code:
`Github_Source_Node::$http_get`, `Linear_Source_Node::$http_post` and
`Feed_Source_Node::$http_get` for the connector fetches, and
`Summarizer_Node::$llm_factory`, `Digest_Builder_Node::$llm_factory`,
`Gate_Node::$matcher_factory` and `Insights_CI_Node::$read_items` for the rest.
Every caller of `LLM_Client::chat()` — `Summarizer_Node::fill()`,
`Digest_Composer` and `LLM_Entity_Extractor` — catches `\RuntimeException` and
nothing wider, and `Proxy_LLM_Client` throws only that, so a second
implementation raising any other `Throwable` propagates out of `fill()` and
breaks the Node contract that `fill()` never throws. Bearer tokens are
substrate Vault entries: the node config holds only the entry id, resolved at
use-time by `Vault_Secret`.

### The item contract

`Source_Node::normalize_item()` mints every item as `{source, id, title, url,
body, timestamp}`, `id` namespaced `"{source}:{raw id}"` — the key every later
dedup reads, and `timestamp` 0 when the connector's date string will not parse.
Each stage adds to that array and forwards the whole of it: the Summarizer adds
`summary`, plus `relevance_score` (clamped to 0–10) and `reason` when the LLM
reply parses, then DROPS `body` to keep the scored log and its snapshot small;
the Scorer adds `score`.

A source ends each TICK by emitting one `TM_INFO "DONE\n"` from a `finally`, so a
throwing fetch still reports progress. The Summarizer, the Scorer and the Gate
forward TM_INFO unchanged, which is how a DONE reaches the digest with the
source's own name still in FROM.

Each stage also stamps a state label, which `trace <node>` inside `wp nodes cli`
prints: the Summarizer `SUMMARIZED` or `FAILED` with the title, the Scorer
`SCORED`, the Gate `GATED:{decision}` with the item id, and the builder
`RECEIVED` per item and `COMPOSED` with the composed count. `FAILED` covers three
cases rather than one — no LLM client resolves, `chat()` throws, or the reply
fails `parse_enrich()` — so it does not on its own mean the proxy call failed.

### Topologies

| File | What it wires |
|------|---------------|
| `newspack-intelligence.tsl` | Includes the four stages below; `on_demand_idle 0` keeps the worker resident, which the TICK-driven sources need |
| `newspack-intelligence-ingest.tsl` | The three sources → `ingest:partition` |
| `newspack-intelligence-summary.tsl` | `ingest:consumer` → `summarizer` → `scorer` → `scored:partition` |
| `newspack-intelligence-digest.tsl` | `scored:consumer` → `digest` → `digest:tee` → `digest:log` |
| `newspack-intelligence-gate.tsl` | `gate:consumer` → `gate` → `gate:tojson` → `gate:log` |

### Nodes

`Consumer`, `Partition`, `Tee`, `Log` and `Struct_To_JSON` come from the
substrate. This plugin adds:

| Type | Class | Runtime triggers and verbs |
|------|-------|----------------------------|
| `Github_Source` | `Github_Source_Node` | `TICK`; `add_repo`, `set_vault_id` |
| `Linear_Source` | `Linear_Source_Node` | `TICK`; `set_vault_id` |
| `Feed_Source` | `Feed_Source_Node` | `TICK`; `add_url` |
| `Summarizer` | `Summarizer_Node` | the `LLM_Config` verbs |
| `Scorer` | `Scorer_Node` | none |
| `Digest_Builder` | `Digest_Builder_Node` | `RESET`, `REGENERATE`; the `LLM_Config` verbs |
| `Gate` | `Gate_Node` | the `LLM_Config` verbs, `set_config_version` |
| `Insights_CI` | `Insights_CI_Node` | `counts`, `top`, `accumulated`, `generate`, `collect` |

The `LLM_Config` verbs are `set_api_url`, `set_vault_id`, `set_model`,
`set_feature` and `add_profile`; every node carrying the trait also re-emits its
state through `dump_config()`. `Digest_Builder` takes two positional arguments,
the scored Partition to nudge on `RESET` and the `total` source count — that
total MUST equal the number of names in `Insights_CI_Node::SOURCE_NODES`, or a
collect never completes. All five `Insights_CI` verbs require `manage_options`,
the three read slices included: no schema entry declares a `capability`, and
`Service_CI_Node` gates an undeclared verb at the strictest role rather than the
loosest. The `require_manage_options()` calls inside the `generate` and `collect`
handlers restate that gate rather than establish it.

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

Each widget reads ONLY its own view node through `useNodeState`; there is no god
view node and no god `insights` command. The three slices arrive as `{sources:
{source: count}}`, `{top: {source: [{title, score}]}}` and `{accumulated, done,
total, digest}` — the same shapes `nodes/register.js` declares as each view's
`empty`. `Insights_CI_Node::top_by_source()` sorts each source's list by score
descending and caps it at ten before the wire, so `TopTable` trusts that order
for its rank column and neither sorts nor caps. Collect and Regenerate are
`useCommandOnce` one-shots owned by `AccumulatedPanel`, riding the same batched
tick as the poll, because the lock, the note and the latch each reply sets live
there. The view classes are handed to `makeNode` rather than named, because the
interpreter's class map is a per-bundle static (substrate ADR-16). The build
resolves `@newspack-nodes/runtime`, `@newspack-nodes/shared/*` and
`@newspack-nodes/debug-overlay` through the substrate's `alias-map.cjs`, from
`NEWSPACK_NODES_SRC` in CI and from the sibling checkout locally.

## Layout

| Path | What |
|------|------|
| `newspack-intelligence.php` | Bootstrap: substrate handshake, topology registration, admin menus, WP-CLI, CPT hooks, `Insights_CI` mount |
| `includes/` (pipeline) | `Source_Node` and its three connectors, `Summarizer_Node`, `Scorer_Node`, `Digest_Builder_Node`, `Digest_Composer` |
| `includes/` (gate) | `Gate_Node`, `Publisher_Matcher`, `LLM_Entity_Extractor` and the `Entity_Extractor` interface |
| `includes/` (publishers) | `Publisher_CPT`, `Publisher_Meta_Box`, `CPT_Publisher_Repository` and the `Publisher_Repository` interface, `Client_Importer`, `CSV_Parser`, `Clients_Settings` |
| `includes/` (LLM) | `Prompts`, the `LLM_Client` interface, `Proxy_LLM_Client`, the `LLM_Config` and `Vault_Secret` traits |
| `includes/` (service) | `Insights_CI_Node`, the `Source` interface, `uninstall-cleanup.php` |
| `includes/cli/` | `Clients_CLI_Command` — `wp newspack-intelligence clients import <csv>` |
| `topologies/` | The five `.tsl` node-graph topologies |
| `src/dashboard/` | The Publisher Insights React panel |
| `tests/` | PHPUnit (`unit/` + `bootstrap.php` + `support/` + `run-coverage.sh`; `integration/` exists but is empty) |
| `scripts/` | Git hooks, the shared tooling copies, `bump-version.sh`, `build.mjs`, the lint and coverage gates |

## WordPress surface

Nothing here is registered until the substrate handshake passes, and the plugin
declares no hook or filter of its own for others to extend.

- **Admin pages.** `Publisher Insights`, a top-level menu at position 58.7, and
  `Newspack Intelligence` under Settings, which hosts the client-CSV upload.
  Both are gated on `manage_options` AND the substrate's
  `Newspack_Nodes\Admin\Admin::current_user_allowed()`. The dashboard bundle is
  enqueued on `admin_enqueue_scripts` through `Admin::enqueue_react_page()`,
  which no-ops when `build/dashboard/index.js` is absent.
- **Filter.** `newspack_nodes/devtools_overlay_pages` — declares the Insights
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
- Design record, in `dndocker/docs/superpowers/` — historical, and named for the
  plugin's pre-rebrand slug where it predates the rename:
  - `specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md` — the master
    floorplan, executed by `plans/2026-06-15-newspack-ai-newsletter-foundation.md`
  - `specs/2026-06-15-newspack-ai-newsletter-ai-core-design.md` — the LLM stages,
    executed by `plans/2026-06-15-newspack-ai-newsletter-ai-core.md`
  - `specs/2026-07-06-ai-newsletter-topology-config-migration-design.md` — why
    there is no settings page: config lives in the topology verbs
  - `specs/2026-07-16-newspack-intelligence-rebrand-design.md` — the rename
  - `specs/2026-07-29-intelligence-actions-v7-design.md` — the release workflow

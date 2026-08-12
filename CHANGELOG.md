# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

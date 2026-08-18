# AGENTS.md — Newspack Intelligence

An AI-driven team intelligence digest built on the `newspack-nodes` substrate
(sibling plugin; `Requires Plugins: newspack-nodes`). Ingests GitHub/Linear/feed
items → LLM summarize+score → durable digest → markdown + WordPress draft post.

> **Working pipeline + dashboard (v0.2.5).** That whole path runs end-to-end with
> three live connectors, the LLM enrich/score/compose stages, and the two-column
> Publisher Insights dashboard. Authoritative design — the floorplan spec
> `dndocker/docs/superpowers/specs/2026-06-15-newspack-intelligence-floorplan-design.md`,
> executed by `dndocker/docs/superpowers/plans/2026-06-15-newspack-intelligence-foundation.md`.

## Workflow discipline (mandatory)

- **TDD always.** Every code-writing turn (main Claude AND subagents) invokes
  `superpowers:test-driven-development` BEFORE writing code: no production code
  without a failing test first — watch it fail, watch it pass.
- **`/code-review` before every commit** (main Claude only; subagents never commit).
- Conventional commits; update `CHANGELOG.md` `[Unreleased]` on every behavior change.
- Never hand-edit version headers — use `./scripts/bump-version.sh`.
- Shared React lives in `newspack-nodes/src/shared` only, consumed via the
  `@newspack-nodes/shared` build alias — never a per-plugin `src/shared/` copy.

## Build / test

```bash
composer install && npm install
npm run build
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npm run lint:deadcode:js                  # knip; GATED in pre-commit (caveat below)
npx jest                                  # JS (local)
cd tests && ../vendor/bin/phpunit         # PHP; needs the substrate active
```

After adding or renaming a Node class, regenerate the classmap (`make_node` and
the console palette read it): `composer build:autoloaders` (= `composer
install --optimize-autoloader`) or `composer dump-autoload -o`.

**`@wordpress/*` is pinned to the `wp-7.0` dist tag — never bump it to close an
advisory.** Every declared runtime version IS its `wp-7.0` tag (`components
32.2.1`, `element 6.40.1`, `i18n 6.13.1`, `api-fetch 7.40.1`, `icons 11.7.1`).
`scripts/build.mjs` externalises them to the `wp.*` globals, so npm's copy never
ships — raising it delivers no new code, it moves the API you compile, lint and
type-check against AHEAD of the one the browser is handed. A component changed or
removed between majors then builds clean, passes eslint and jest, and breaks at
runtime, with nothing mechanical to catch it. Check `npm view @wordpress/<pkg>
dist-tags --json` against `wp core version` before proposing any bump, and move
the whole family together only when the WordPress target itself moves. A
Dependabot advisory reachable only PAST the pin gets dismissed, not bumped:
2026-08-17, uuid GHSA-w5hq-g745-h8pq needed `components >= 33.1.0`, one major
past `wp-7.0`, for a package absent from `build/` entirely — dismissed
`not_used`. npm `overrides` cannot rescue that, because npm matches an override
by package NAME anywhere in the tree: both `{uuid: …}` and the nested
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
`release/newspack-intelligence.zip` however the target site installs plugins. An
installer that only copies the prebuilt zip will happily ship stale code if the
build is skipped.

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

## Architecture (see the spec for detail)

Connector **Source nodes** (`github`/`linear`/`feed`) fetch on a `TICK` request
inside the background worker and append normalized items to a durable `ingest`
Partition; an `ingest:consumer` paces them through `Summarizer` → `Scorer` → the
durable `scored` Partition; a `scored:consumer` feeds `Digest_Builder` → `Tee` →
`Log` (`digest:log`). Blocking HTTP runs in the worker, not a per-fetch job; the
ingest buffer and paced consumers keep the worker heartbeating through a collect.
`Insights_CI` serves the dashboard slices and routes Collect/Regenerate to the
worker; the browser creates the WordPress draft from the digest markdown
(`@wordpress/api-fetch`). LLM calls go through the AI API Proxy via `LLM_Client`
(closure-HTTP test seam).

## Layout

| Path | What |
|------|------|
| `newspack-intelligence.php` | Bootstrap: topology registration, Insights + Settings admin pages, Insights_CI mount |
| `includes/` | Nodes (`Summarizer`, `Scorer`, `Digest_Builder`, `Insights_CI`, sources), `Digest_Composer`, `Prompts`, `LLM_Client` interface + `Proxy_LLM_Client`, `Source`, `Settings` |
| `topologies/` | `.tsl` node-graph topologies |
| `src/dashboard/` | Publisher Insights React panel — orchestrator + per-slice widgets/view nodes (consumes `@newspack-nodes/*` via build alias) |
| `tests/` | PHPUnit (`unit/` + `bootstrap.php`; `integration/` exists but is empty) |

## References

- Substrate: [`newspack-nodes`](../newspack-nodes) (+ its `AGENTS.md`)
- Teaching walkthrough: `newspack-nodes/examples/example-ai-newsletter`

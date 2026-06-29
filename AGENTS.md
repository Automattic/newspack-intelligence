# AGENTS.md — Newspack AI Newsletter

An AI-driven team intelligence digest built on the `newspack-nodes` substrate
(sibling plugin; `Requires Plugins: newspack-nodes`). Ingests GitHub/Linear/feed
items → LLM summarize+score → durable digest → markdown + WordPress draft post.

> **Working pipeline + dashboard (v0.2.5).** The full ingest → summarize → score →
> digest → WordPress-draft path runs end-to-end with the three live connectors, the
> LLM enrich/score/compose stages, and the two-column Publisher Insights dashboard.
> The authoritative design is the floorplan spec:
> `dndocker/docs/superpowers/specs/2026-06-15-newspack-ai-newsletter-floorplan-design.md`,
> executed by `dndocker/docs/superpowers/plans/2026-06-15-newspack-ai-newsletter-foundation.md`.

## Workflow discipline (mandatory)

- **TDD always.** No production code without a failing test first — watch it fail,
  watch it pass. Every code-writing turn (main Claude AND subagents) invokes
  `superpowers:test-driven-development` BEFORE writing code.
- **`/code-review` before every commit** (main Claude only; subagents never commit).
- Conventional commits; update `CHANGELOG.md` `[Unreleased]` on every behavior change.
- Never hand-edit version headers — use `dndocker/tools/bump-ai-newsletter-version.sh`.
- Shared React lives in `newspack-nodes/src/shared` only, consumed via the
  `@newspack-nodes/shared` build alias — never a per-plugin `src/shared/` copy.

## Build / test

```bash
composer install && npm install
npm run build
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npx jest                                  # JS (local)
docker exec -u bend eve-pyrobase1-1 bash -c \
  'cd /services/pyrobase/sources/newspack-ai-newsletter/tests && ../vendor/bin/phpunit'   # PHP (container, from /services)
```

Deploy (build the zip first — the setup script installs the release zip, it does
not build): `npm run release:archive` then
`docker exec eve-pyrobase1-1 /services/pyrobase/setup/newspack-ai-newsletter.sh`.

## Architecture (see the spec for detail)

Connector **Source nodes** (`github`/`linear`/`feed`) fetch on a `TICK` request
inside the background worker and append normalized items to a durable `ingest`
Partition; an `ingest:consumer` paces them through `Summarizer` → `Scorer` → the
durable `scored` Partition; a `scored:consumer` feeds `Digest_Builder` → `Tee` →
`Log` (`digest:log`). The blocking HTTP runs in the worker (not a per-fetch job);
the ingest buffer + paced consumers keep the worker heartbeating during a collect.
`Insights_CI` serves the dashboard slices and routes Collect/Regenerate to the
worker; the WordPress draft is created browser-side from the digest markdown
(`@wordpress/api-fetch`). LLM calls go through the AI API Proxy via `LLM_Client`
(closure-HTTP test seam).

## Layout

| Path | What |
|------|------|
| `newspack-ai-newsletter.php` | Bootstrap: topology registration, Insights + Settings admin pages, Insights_CI mount |
| `includes/` | Nodes (`Summarizer`, `Scorer`, `Digest_Builder`, `Insights_CI`, sources), `Digest_Composer`, `Prompts`, `LLM_Client` interface + `Proxy_LLM_Client`, `Source`, `Settings` |
| `topologies/` | `.tsl` node-graph topologies |
| `src/dashboard/` | Publisher Insights React panel — orchestrator + per-slice widgets/view nodes (consumes `@newspack-nodes/*` via build alias) |
| `tests/` | PHPUnit (`unit/` + `bootstrap.php`; `integration/` exists but is empty/pending) |

## References

- Substrate: [`newspack-nodes`](../newspack-nodes) (+ its `AGENTS.md`)
- Teaching walkthrough: `newspack-nodes/examples/example-ai-newsletter`

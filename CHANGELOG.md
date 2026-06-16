# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Runtime triggers are now fire-and-forget `TM_REQUEST`s, not `TM_COMMAND` verbs.**
  The source's `tick` and the digest's `flush` were dispatched as `TM_COMMAND` verbs
  through a sibling `{node}:config` interpreter (`Schema_Reflection` +
  `auto_wire_interpreter`). Per the Tachikoma convention — `TM_COMMAND` is for
  startup/administration, runtime triggers are `TM_REQUEST` — `Stub_Source` and
  `Digest_Builder` now handle a `TM_REQUEST` directly in `fill()` (TICK / FLUSH),
  emit downstream fire-and-forget, and declare the trigger under
  `node_schema()['requests']` so the console renders a request button. Trigger from
  the REPL with `request_node source TICK` / `request_node digest FLUSH`.

### Added

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

- **AI core — the Digest builder composes an LLM briefing.** On `flush`, the top-10
  scored items are sent in one AI API Proxy call (`Prompts::digest`) to compose a
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

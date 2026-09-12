# Newspack Intelligence

An AI-driven **team intelligence digest** built on the
[newspack-nodes](../newspack-nodes) substrate. It ingests items from real sources
(GitHub, Linear, RSS/feeds), enriches them with an LLM (summarize + score),
accumulates them into a durable digest, and publishes that digest as markdown and
a WordPress draft post, from an admin control panel.

> **Status:** the whole path runs end-to-end — the three connectors, the LLM
> summarize, score and compose stages, the intake Gate, and the Publisher
> Insights dashboard. The teaching walkthrough lives in
> `newspack-nodes/examples/example-ai-newsletter`.

**Requires** WordPress 6.5, PHP 8.2, and the `newspack-nodes` substrate at 2.57.0
or newer. Below that floor the plugin stays dormant.

## How it works

Connector **Source nodes** (GitHub, Linear, feed) fetch inside a background
worker and append normalized items to the durable `ingest` partition. A
**Summarizer** and a **Scorer** pace through that partition into the durable
`scored` partition. A **Digest_Builder** accumulates the scored items and
composes the markdown digest once every source has reported in, writing it
through a `Log`. A **Gate** tails the same `ingest` partition with its own
offsets, appending one JSON decision line per item to attribute it to a Newspack
client; it observes the pipeline without changing what the digest sees.

An `Insights_CI` service serves the dashboard and routes Collect and Regenerate
to the worker. The browser turns the digest markdown into block markup through
the block editor's own paste engine and creates the draft over the REST API.

AI calls go through the Automattic **AI API Proxy** (OpenAI-compatible),
defaulting to the `gpt-oss-120b` model. The bearer token is a substrate Vault
entry: the node config holds only its id, resolved at use time.

## Develop

```bash
composer install && npm install
npm run build          # esbuild the dashboard
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npx jest               # JS unit tests (local)
cd tests && ../vendor/bin/phpunit   # PHP; needs newspack-nodes active
```

## Docs

- Working guide: [`AGENTS.md`](AGENTS.md) — the topologies, the node catalogue,
  the dashboard modules, the WordPress surface and the release order
- Substrate: [`newspack-nodes`](https://github.com/Automattic/newspack-nodes)

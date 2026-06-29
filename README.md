# Newspack AI Newsletter

An AI-driven **team intelligence digest** built on the
[newspack-nodes](../newspack-nodes) substrate. It ingests items from real sources
(GitHub, Linear, RSS/feeds), enriches them with an LLM (summarize + score),
accumulates them into a durable digest, and publishes that digest as markdown +
a WordPress draft post, from an admin control panel.

> **Status:** the ingest → summarize → score → digest → WordPress-draft pipeline
> and the Publisher Insights dashboard are working (v0.2.5). The teaching
> walkthrough lives in `newspack-nodes/examples/example-ai-newsletter`.

## How it works

Connector **Source nodes** (GitHub/Linear/feed) fetch inside a background worker
and append normalized items to a durable `ingest` partition; a **Summarizer →
Scorer** stage (LLM) paces through it into a durable `scored` partition; a
**Digest_Builder** accumulates and, once every source reports in, composes the
markdown digest → a `Log`. An `Insights_CI` service serves the admin dashboard;
the WordPress draft is created from there via the block editor's markdown engine.

AI calls go through the Automattic **AI API Proxy** (OpenAI-compatible), defaulting
to a free internally-hosted model (`gpt-oss-120b`). The bearer token is stored as a
substrate Vault entry (the option holds only its id) and resolved at use-time.

## Develop

```bash
composer install && npm install
npm run build          # esbuild the dashboard
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npx jest               # JS unit tests (local)
# PHP tests run in the container, as bend, from /services:
#   docker exec -u bend eve-pyrobase1-1 bash -c 'cd /services/pyrobase/sources/newspack-ai-newsletter/tests && ../vendor/bin/phpunit'
```

## Docs

- Substrate: [`newspack-nodes`](https://github.com/Automattic/newspack-nodes)

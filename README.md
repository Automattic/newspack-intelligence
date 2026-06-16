# Newspack AI Newsletter

An AI-driven **team intelligence digest** built on the
[newspack-nodes](../newspack-nodes) substrate. It ingests items from real sources
(GitHub, Linear, RSS/feeds), enriches them with an LLM (summarize + score),
accumulates them into a durable digest, and publishes that digest as markdown +
a WordPress draft post — on a schedule, with an admin control panel.

> **Status:** under construction. This plugin is being built sub-project by
> sub-project per the floorplan. The teaching walkthrough lives in
> `newspack-nodes/examples/example-ai-newsletter`.

## How it works

Fetches run as **jobs** (blocking HTTP is isolated there); normalized items flow
into an ingest log; a **Summarizer → Scorer** stage (LLM, in a background worker)
writes to a durable `scored` partition; a **Digest_Builder** accumulates and, on
the digest cadence, flushes markdown → a `Log` + a `publish:wp-draft` job. An
`Insights_CI` service serves the admin dashboard.

AI calls go through the Automattic **AI API Proxy** (OpenAI-compatible), defaulting
to a free internally-hosted model (`gpt-oss-120b`). The bearer token is a secret
config field — never committed.

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

- Substrate: [`newspack-nodes`](../newspack-nodes)

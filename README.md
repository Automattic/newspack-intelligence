# Newspack Intelligence

Collects and curates intelligence as an **entity graph**, built on the
[newspack-nodes](../newspack-nodes) substrate. It records items from GitHub,
Linear and feeds, links each to the organizations, people and topics it
concerns, and answers questions about them with citations, on the Intelligence
admin page and to agents over MCP.

**Requires** WordPress 6.5, PHP 8.2, and the `newspack-nodes` substrate at 2.138.0
or newer. Below that floor the plugin stays dormant.

## How it works

Connector **Source nodes** (GitHub, Linear, feed) fetch inside a background
worker and record each new item as an `item_recorded` event on the `intel:items`
stream. Entities, names, identifiers, roles and every person's judgment are
events on the `intel:ontology` stream, which keeps every segment. A
**Projector** replays both streams into the `intel-graph` SQLite Row_Table.

A **Resolver** links each recorded item to the entities it names, by
identifier, by name and, failing both, by an LLM's named-entity extraction; an
**Extractor** asks the LLM for a summary, a kind, topics, scores and claims; a
**Deduper** clusters copies of one story. When the ontology changes, a
**Reresolver** queues the stored items the change could link anew.

`Intelligence_CI` answers the graph's read verbs (search, entity, timeline,
related, claims, trends, review, collections, lenses) and its curation verbs, which the
Intelligence page sends; the same verbs reach agents through the plugin's MCP
door. Nothing is compiled: a person or an agent asks, and the graph answers.
A client CSV, imported on the Settings page or with
`wp newspack-intelligence clients import`, keeps Newspack's clients in the
graph. Restricted items answer only a user granted the
`newspack_intelligence_restricted` capability, on the Settings page or with
`wp newspack-intelligence restricted grant <login>`.

AI calls go through the Automattic **AI API Proxy** (OpenAI-compatible),
defaulting to the `gpt-oss-120b` model. The bearer token is a substrate Vault
entry: the node config holds only its id, resolved at use time.

## Develop

```bash
composer install && npm install
npm run build          # esbuild the Intelligence page
npm run lint:js && npm run lint:php && npm run lint:phpstan && npm run lint:scss
npx jest               # JS unit tests (local)
cd tests && ../vendor/bin/phpunit --enforce-time-limit   # PHP; needs newspack-nodes active
```

## Docs

- Working guide: [`AGENTS.md`](AGENTS.md) — the streams, the node catalogue,
  the topologies, the Intelligence page, the WordPress surface and the release
  order
- Substrate: [`newspack-nodes`](https://github.com/Automattic/newspack-nodes)

<h1 align="center">askmydocs-connector-jira</h1>

<p align="center">
  <strong>Jira Cloud connector for AskMyDocs — OAuth 2.0 3LO sync with ADF→markdown rendering, JQL-driven incremental sync, full structured-field frontmatter (status, priority, labels, components, sprint, assignee, fix-versions, browse URL).</strong><br/>
  Drop-in Laravel package. <code>composer require</code> it from any AskMyDocs install and the Jira connector appears in the admin UI on the next request.
</p>

<p align="center">
  <a href="https://github.com/padosoft/askmydocs-connector-jira/actions/workflows/tests.yml"><img alt="CI status" src="https://img.shields.io/github/actions/workflow/status/padosoft/askmydocs-connector-jira/tests.yml?branch=main&label=tests"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-jira"><img alt="Packagist version" src="https://img.shields.io/packagist/v/padosoft/askmydocs-connector-jira.svg?label=packagist"></a>
  <a href="https://packagist.org/packages/padosoft/askmydocs-connector-jira"><img alt="Total downloads" src="https://img.shields.io/packagist/dt/padosoft/askmydocs-connector-jira.svg?label=downloads"></a>
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue.svg"></a>
  <img alt="PHP version" src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4">
  <img alt="Laravel version" src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20">
</p>

---

## Table of contents

1. [Why this package](#why-this-package)
2. [Features](#features)
3. [AI vibe-coding pack included](#-ai-vibe-coding-pack-included)
4. [Architecture at a glance](#architecture-at-a-glance)
5. [Installation](#installation)
6. [Credential setup (junior-proof, step by step)](#credential-setup-junior-proof-step-by-step)
7. [Activation inside AskMyDocs](#activation-inside-askmydocs)
8. [What gets ingested](#what-gets-ingested)
9. [Sync semantics](#sync-semantics)
10. [Testing](#testing)
11. [Live testsuite](#live-testsuite)
12. [Troubleshooting](#troubleshooting)
13. [License](#license)

---

## Why this package

[AskMyDocs](https://github.com/lopadova/AskMyDocs) is an enterprise-grade RAG + canonical knowledge compilation system. Out of the box it ingests markdown from disk, the chat UI, an HTTP API, and a Git-driven workflow — but for engineering teams the most-asked questions live in Jira tickets: "what status is BUG-1234", "who's assigned the schema-migration sprint", "what did we decide on the architecture-rejection issue last quarter".

This package is the smallest possible surface for shipping that integration:

- A `JiraConnector` that implements `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`.
- A `JiraAdfToMarkdown` converter that flattens Jira's Atlassian Document Format (ADF) — used across description bodies, comments, custom-field text — into clean GitHub-flavoured markdown. Handles paragraphs, headings, lists, code blocks, panels (info/warning/note/error/success), tables (GFM-flavoured), mentions (`@displayName`), inline cards, internal `mediaSingle` references via stable `[adf-media: <id>]` placeholders.
- A `JqlBuilder` for safe, fluent JQL composition with backslash + single-quote escape rules (R19 input-escape-complete).
- A `JiraPaginator` with offset-based pagination (Jira's classic `/search` contract).
- A composer.json that auto-registers via `extra.askmydocs.connectors`. Zero edits to your host app's config required.

> **`composer require padosoft/askmydocs-connector-jira`. Done.**

## Features

- 🔌 **Zero-config installation** — composer-extra discovery auto-registers the connector at boot.
- 🔐 **Atlassian OAuth 2.0 3LO** — single-use state-token CSRF protection (600 s TTL), `accessible-resources` lookup to resolve the per-tenant `cloud_id`, refresh-token rotation built-in.
- 🔁 **True revocation on disconnect** — unlike Confluence (no revoke API), Atlassian DOES expose a revoke endpoint for OAuth 2.0 3LO tokens. The connector revokes BOTH the access token AND the refresh token (a refresh-only token can still mint a new access token upstream, so revoking the access token alone is insufficient).
- ♻️ **Incremental sync** — workspace-wide JQL `updated >= "YYYY-MM-DD HH:mm" ORDER BY updated DESC`; daily syncs cost one round-trip on quiet projects.
- 🧠 **ADF→markdown** — proper rendering of every node type Jira ships (paragraph, heading, list, code, blockquote, rule, panel, mention, inline card, table, hardBreak, media). Unknown node types surface as `[adf-node: <type>]` placeholders so operators can audit gaps rather than silently dropping content (R14 surface-failures-loudly).
- 📊 **Rich structured-field frontmatter** — status, priority, issue-type, assignee, reporter, labels, components, fix-versions, active sprint (sniffed from `customfield_*`), browse URL, created/updated timestamps surface to the host's reranker via `SourceAwareMetadataBuilder`.
- 🎯 **Status-aware ranking** — terminal Jira statuses (Done / Closed / Resolved / Cancelled / Won't Do) automatically flip the reranker's `status_active=false` signal so retrieval down-weights stale tickets.
- 🚦 **Failure-loud exception taxonomy** — 401 / 403 → `ConnectorAuthException`, 5xx / 429 → `ConnectorApiException`, pagination overflow → `ConnectorPaginationLimitException` with `maxPages` field.
- 🏢 **Per-tenant isolated** — every credential read and ingestion dispatch is scoped to the active `TenantContext`.
- 🧪 **Test-friendly** — pure-PHP unit tests for the ADF converter + JQL builder, `Http::fake()` feature tests for the connector, opt-in live test against a real Atlassian sandbox cloud when `CONNECTOR_JIRA_LIVE=1`.

## 🚀 AI vibe-coding pack included

This package was built with a vibe-coding pack of Claude Code skills and rules (`.claude/` directory in the parent AskMyDocs repo) that codify the architectural invariants — the IoC contract that keeps this package standalone-agnostic, the Atlassian REST API quirks (`accessible-resources` scope-driven `cloud_id` resolution, JQL date format `"YYYY-MM-DD HH:mm"` NOT ISO-8601, offset pagination semantics, the `customfield_*` sprint sniffing), the failure-loud exception taxonomy, the ADF node-type contract with explicit `[adf-node: <type>]` audit-trail for unknown types.

The `JqlBuilder` specifically codifies R19 (input-escape-complete) — it doubles backslashes BEFORE escaping single quotes so a literal `\\'` survives the round-trip without producing a half-escaped artefact.

If you're using Claude Code to fork or extend this package, point the agent at the parent repo's `.claude/` pack and it stays inside the invariants automatically. No tribal-knowledge drift.

## Architecture at a glance

```
                ┌──────────────────────────────┐
Composer        │ padosoft/askmydocs-          │
require ───────▶│ connector-jira               │
                │ (this package)               │
                └────────────┬─────────────────┘
                             │
                             │ auto-registered via composer
                             │ extra.askmydocs.connectors
                             ▼
                ┌──────────────────────────────┐
                │ padosoft/askmydocs-connector-│
                │ base v1.1.1+                 │
                │ ConnectorRegistry            │
                └────────────┬─────────────────┘
                             │
                             │ resolves JiraConnector
                             ▼
                ┌──────────────────────────────┐
                │ JiraConnector::syncFull      │
                │  • /accessible-resources     │
                │  • GET /project/search       │
                │  • GET /search (JQL)         │
                │  • JiraAdfToMarkdown         │
                │  • SourceAwareMetadata       │
                └────────────┬─────────────────┘
                             │
                             │ ConnectorIngestionContract
                             │ (IoC bridge — host implements)
                             ▼
                ┌──────────────────────────────┐
                │ Host app (AskMyDocs):        │
                │  • Storage::put → KB disk    │
                │  • IngestDocumentJob         │
                │  • kb_canonical_audit row    │
                │  • PII redactor at boundary  │
                └──────────────────────────────┘
```

The IoC bridge is the key design decision: this package never imports `App\Jobs\IngestDocumentJob`, `App\Models\KnowledgeDocument`, or any other host class. It dispatches every host-side concern through `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract`. The host binds its own implementation in a service provider; this package stays standalone-agnostic so it can run inside AskMyDocs Community Edition, AskMyDocs Pro, or any third-party Laravel app that wants Jira-backed RAG.

## Installation

```bash
composer require padosoft/askmydocs-connector-jira
```

The package follows Laravel's auto-discovery convention so no manual provider registration is required. After install, run:

```bash
php artisan vendor:publish --tag=connector-jira-config   # optional — for env-var overrides
php artisan vendor:publish --tag=connector-jira-assets   # optional — copies jira.svg to public/connectors
```

The `connector-base` migrations ship in the parent package (`padosoft/askmydocs-connector-base`) and auto-load via its service provider; no extra `migrate` step is needed.

## Credential setup (junior-proof, step by step)

Jira Cloud uses Atlassian's OAuth 2.0 3LO flow — the same flow as the Confluence sister connector. If you've already wired the Confluence connector, you can reuse the same Atlassian Developer Console app and just add the Jira scopes.

### 1. Sign in to the Atlassian Developer Console

1. Open <https://developer.atlassian.com/console/myapps/> in your browser.
2. Sign in with the Atlassian account that owns (or has admin access to) the Jira site you want to integrate.

### 2. Create a new OAuth 2.0 (3LO) integration (or reuse one)

1. Click **"Create"** in the top-right.
2. Pick **"OAuth 2.0 integration"** from the dropdown.
3. Name it `AskMyDocs` (or any label that makes sense).
4. Click **"Create"** to land on the new app's overview page.

(If you already created this app for the Confluence connector, click on it instead of creating a new one — Atlassian supports multiple product scopes per OAuth app.)

### 3. Add the Jira API permissions

1. From the app's left navigation, click **"Permissions"**.
2. Find the **"Jira API"** row and click **"Add"**.
3. After adding, click **"Configure"** on the same row.
4. Tick the following scopes (and ONLY these — the connector is strictly read-only):
    - `read:jira-work` — read issues, projects, comments, attachments metadata
    - `read:jira-user` — read user info (used for the health probe)
    - `offline_access` — issue refresh tokens (required so sync keeps running past the initial access-token TTL)
5. Click **"Save"**.

### 4. Configure the OAuth 2.0 (3LO) callback URL

1. From the app's left navigation, click **"Authorization"**.
2. Click **"Configure"** on the **"OAuth 2.0 (3LO)"** row.
3. Set **Callback URL** to your host app's callback endpoint, for example:
   ```
   https://your-app.example.com/api/admin/connectors/jira/oauth/callback
   ```
4. Click **"Save changes"**.

Atlassian requires HTTPS in production; for local development behind `http://localhost` use a tunnel (Cloudflare Tunnel, ngrok, Tailscale Funnel).

### 5. Capture the credentials

1. From the app's left navigation, click **"Settings"**.
2. Scroll to **"Authentication details"**:
   - **Client ID** → `CONNECTOR_JIRA_CLIENT_ID`
   - **Secret** → `CONNECTOR_JIRA_CLIENT_SECRET`

### 6. Write credentials to `.env`

In your AskMyDocs host app's `.env`:

```dotenv
CONNECTOR_JIRA_CLIENT_ID=<your-client-id>
CONNECTOR_JIRA_CLIENT_SECRET=<your-client-secret>
CONNECTOR_JIRA_REDIRECT_URI=https://your-app.example.com/api/admin/connectors/jira/oauth/callback
# Optional — only override if you proxy Atlassian's API:
# CONNECTOR_JIRA_API_BASE_TEMPLATE=https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3
```

### 7. Verify (curl)

After completing the OAuth flow once (step 8), grab the access token from the database via `php artisan tinker` and run:

```bash
curl -s https://api.atlassian.com/oauth/token/accessible-resources \
  -H "Authorization: Bearer <access-token>"
```

You should see a JSON array of accessible Atlassian sites with a `scopes` entry that contains `read:jira-work`.

### 8. Common errors

- `redirect_uri_mismatch` — The exact redirect URI in `.env` must match the one registered in the Developer Console (case-sensitive, trailing slashes matter).
- `invalid_scope` — Your Developer Console app doesn't have one of the required scopes enabled. Re-check step 3.
- `User has not granted access to any Atlassian site` — The OAuth grant succeeded but the user has no Jira access. Add the user as a Jira user in <https://admin.atlassian.com>.

## Activation inside AskMyDocs

After `composer require` + the env vars above:

1. Run the host app's admin UI.
2. Navigate to **Settings → Connectors**.
3. The **Jira** card appears with an **Install** button.
4. Click **Install** → browser redirects to `auth.atlassian.com` → operator authorises → returns to the admin UI → status flips to `active`.
5. The first full sync fires within the cadence window (default 15 minutes). To trigger immediately, click **Sync now**.

## What gets ingested

For every Jira issue the integration can see:

- **Markdown body** — issue title prepended as `# [PROJ-1234] Summary`, then the description (ADF → markdown), then a `## Comments` appendix (each comment heading `### <author> — <timestamp>`).
- **Frontmatter / metadata** captured under `metadata.converter_hints.jira`:
  - `project_key`, `project_name`
  - `issue_key`, `issue_id`, `issue_type`
  - `status`, `priority`
  - `assignee`, `reporter` (email when exposed, displayName as fallback under GDPR-strict mode)
  - `labels`, `components`, `fix_versions`
  - `sprint` (active sprint sniffed from `customfield_*`)
  - `created`, `updated`
  - `cloud_id`, `source_url` (resolved `https://<site>.atlassian.net/browse/<KEY>`)
- **`_derived` reranker signals** under `metadata.converter_hints._derived`:
  - `search_tags` (labels + components + priority + issue-type unioned)
  - `status_active` (false for Done/Closed/Resolved/Cancelled/"Won't Do")
  - `recency_bucket`, `owner`

The synthetic MIME `application/vnd.jira.issue+json` routes the document to the host's Jira-aware chunker when one is installed.

## Sync semantics

- **Full sync** — `GET /rest/api/3/project/search` to enumerate projects, then per-project JQL `project = "{KEY}" ORDER BY updated DESC` against `GET /rest/api/3/search` with `expand=renderedFields` and `fields=*all,-attachment`. Offset pagination via `startAt` + `maxResults=50`. Safety cap at 100 pages per project; when the cap fires a `ConnectorPaginationLimitException` surfaces in the per-project error counter.
- **Incremental sync** — workspace-wide JQL `updated >= "YYYY-MM-DD HH:mm" ORDER BY updated DESC`. Jira's JQL date grammar is Jira-specific (NOT ISO-8601); `JqlBuilder::updatedSince()` formats Carbon instances to the wire shape.
- **Deletion reconciliation** — Jira's REST API does NOT surface hard-deletes through search endpoints (they vanish silently). The host's periodic full sync detects orphaned `knowledge_documents` rows whose `metadata.jira_issue_key` no longer round-trips and soft-deletes them. Real-time deletion via webhooks is out of scope for v1.0.
- **Disconnect** — calls `auth.atlassian.com/oauth/token/revoke` for BOTH the access token AND the refresh token (best-effort, with `token_type_hint`), then clears local credentials regardless of the revoke response. Failed remote revoke does NOT block local cleanup.

## Testing

```bash
composer install
vendor/bin/phpunit
```

The suite has three flavours:

| Suite     | What it covers                                                                                  | Network |
|-----------|-------------------------------------------------------------------------------------------------|---------|
| Unit      | `JiraAdfToMarkdown` + `JqlBuilder` — pure PHP, 20+ ADF node-type cases + JQL escape rules.      | None    |
| Feature   | `JiraConnector` against `Http::fake()` and the spy ingestion contract.                          | None    |
| Live      | Opt-in — actually hits the configured Atlassian cloud. Skipped unless `CONNECTOR_JIRA_LIVE=1`.  | Real    |

CI runs Default (Unit + Feature) against PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

## Live testsuite

The live suite is **opt-in** so CI never pays for real API calls. To run it:

```bash
export CONNECTOR_JIRA_LIVE=1
export CONNECTOR_JIRA_TOKEN=<an-active-oauth-access-token>
export CONNECTOR_JIRA_CLOUD_ID=<the-cloud-id-from-accessible-resources>
vendor/bin/phpunit --testsuite=Live
```

This calls `/rest/api/3/myself` on the real Atlassian cloud once to validate credentials.

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `401 invalid_token` during sync | Refresh token expired (Atlassian rotates them aggressively when the user revokes consent), or operator manually revoked the connection from id.atlassian.com | Re-install from the admin UI |
| `403 quota_exceeded` | Hit the per-tenant Atlassian API rate-limit (5000 requests / 5 min) | Wait or split the workspace across multiple installations |
| `Jira accessible-resources returned no resources` | OAuth grant succeeded but the user has no Jira access to any Atlassian site | Add the user as a Jira-user member in <https://admin.atlassian.com> |
| `Jira cloud_id missing` | Race condition during the OAuth flow — the `accessible-resources` call returned `[]` so `cloud_id` was never stored | Re-install from the admin UI; the new flow will retry the lookup |
| Sprint field missing from ingested metadata | Sprint custom field id varies per workspace; the connector sniffs `customfield_*` values for the Sprint object shape but some workspaces use exotic custom-field configurations | Open an issue with the sample shape; we'll widen the sniffer |
| Issues with no description ingest as `_No description._` | This is by design — empty issues still produce a useful chunk via the structured fields (status, priority, assignee) which surface in retrieval via the source-aware metadata builder | n/a |
| Browse URL points at `api.atlassian.com` instead of `<workspace>.atlassian.net` | The issue's `project.self` field was missing or malformed — the connector falls back to the API path which Atlassian redirects to the canonical URL when followed in a browser | Re-trigger full sync; the next pass should resolve from the fresh `project.self` |

## License

Apache-2.0 — see [LICENSE](LICENSE).

Built and maintained by [Padosoft](https://padosoft.com/). Part of the AskMyDocs connector ecosystem.

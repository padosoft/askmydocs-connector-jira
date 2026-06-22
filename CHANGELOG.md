# Changelog

All notable changes to `padosoft/askmydocs-connector-jira` are documented here.

## [1.1.0] — 2026-06-22 — Multi-account project binding

### Changed

- Adopt `BaseConnector::resolveProjectKey()` from `padosoft/askmydocs-connector-base` v1.3.0 for project-key resolution during full and incremental sync. The connector now resolves the ingestion project key from the installation's explicit `project_key` binding, falling back to the host's `kb.ingest.default_project` config (itself defaulting to the literal `default`). This replaces the previous synthetic `connector-jira` fallback and enables multi-account installations that bind to distinct KB projects.
- Requires `padosoft/askmydocs-connector-base` `^1.3`.

## v1.0.0 — 2026-05-12 — Initial extraction

Inaugural release. Extracted from AskMyDocs v4.5 (`feature/v4.6` cycle, W4) as a standalone composer package.

### Added

- `JiraConnector` implementing `Padosoft\AskMyDocsConnectorBase\ConnectorInterface`:
  - Atlassian OAuth 2.0 3LO with state-token CSRF protection (600 s TTL).
  - `accessible-resources` lookup picks the first Jira-capable site (scopes include `read:jira-*`); `cloud_id` persisted in `extra_json`.
  - Full sync via `/rest/api/3/project/search` + per-project JQL `project = "{key}" ORDER BY updated DESC` against `/rest/api/3/search` with `expand=renderedFields` and `fields=*all,-attachment`.
  - Incremental sync via JQL `updated >= "YYYY-MM-DD HH:mm" ORDER BY updated DESC` (Jira-specific date grammar, NOT ISO-8601).
  - Health probe against `/rest/api/3/myself`.
  - Disconnect revokes BOTH access AND refresh tokens via `auth.atlassian.com/oauth/token/revoke` then clears local credentials regardless of remote-revoke response.
- `Jira\JiraAdfToMarkdown` — Atlassian Document Format (ADF) → markdown converter. Handles paragraph, heading (1-6, clamped), bulletList / orderedList, codeBlock, blockquote, rule, panel (info/note/warning/success/error), mention, inlineCard, hardBreak, mediaSingle / media, table (GFM-flavoured), with marks (strong, em, code, strike, link). Unknown node types surface as `[adf-node: <type>]` placeholders so operators can audit gaps (R14 surface-failures-loudly).
- `Jira\JqlBuilder` — fluent, immutable JQL builder with R19 input-escape-complete rules (backslashes doubled BEFORE single-quote escaping). `JqlBuilder::for($projectKey)` / `JqlBuilder::any()` / `->updatedSince(Carbon)` / `->orderBy()` / `->build()`.
- `Jira\JiraPaginator` — offset-based pagination walker (Jira's classic `startAt` + `maxResults`); `MODE_OFFSET` for both `/project/search` (`values` key) and `/search` (`issues` key); eager + lazy traversal; explicit `ConnectorPaginationLimitException` on `maxPages` overflow.
- `JiraServiceProvider` — registers the config block under `connectors.providers.jira`, publishes the config + brand-icon as opt-in tags.
- Auto-registration via `extra.askmydocs.connectors` composer-extra discovery.
- 41 tests / 86 assertions; CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13.

### Architecture

- IoC bridge via `Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract` — this package never imports host classes; host applications bind their own implementation.
- Per-tenant isolation enforced via `TenantContext` injected by the base package.
- PII redaction at the ingest boundary via `$this->maybeRedactContent()` (no-op when the host doesn't wire it).
- Source-aware metadata builder surfaces `project_key`, `issue_key`, `status`, `priority`, `assignee`, `reporter`, `labels`, `components`, `fix_versions`, `sprint` (sniffed from `customfield_*`), `created`/`updated`, `source_url` (resolved `<site>.atlassian.net/browse/<KEY>`) to the host reranker.
- Status-aware reranker signal: terminal Jira statuses (Done / Closed / Resolved / Cancelled / "Won't Do") flip `_derived.status_active=false` so retrieval down-weights closed tickets.

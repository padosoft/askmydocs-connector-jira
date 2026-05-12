<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Jira connector configuration
|--------------------------------------------------------------------------
|
| Provider settings for `padosoft/askmydocs-connector-jira`.
|
| The base package merges this block under
| `config('connectors.providers.jira')`, so concrete connector code
| reads its config via the standard
| `config('connectors.providers.jira.<key>')` path.
|
| All knobs accept env-var overrides — set them in your host app's
| `.env` (see the package README §Credential setup).
|
*/

return [
    'client_id' => env('CONNECTOR_JIRA_CLIENT_ID'),
    'client_secret' => env('CONNECTOR_JIRA_CLIENT_SECRET'),
    'redirect_uri' => env(
        'CONNECTOR_JIRA_REDIRECT_URI',
        env('APP_URL', 'http://localhost').'/api/admin/connectors/jira/oauth/callback'
    ),
    'oauth_authorize_url' => env(
        'CONNECTOR_JIRA_OAUTH_AUTHORIZE_URL',
        'https://auth.atlassian.com/authorize'
    ),
    'oauth_token_url' => env(
        'CONNECTOR_JIRA_OAUTH_TOKEN_URL',
        'https://auth.atlassian.com/oauth/token'
    ),
    'oauth_revoke_url' => env(
        'CONNECTOR_JIRA_OAUTH_REVOKE_URL',
        'https://auth.atlassian.com/oauth/token/revoke'
    ),
    'accessible_resources_url' => env(
        'CONNECTOR_JIRA_ACCESSIBLE_RESOURCES_URL',
        'https://api.atlassian.com/oauth/token/accessible-resources'
    ),
    'api_base_template' => env(
        'CONNECTOR_JIRA_API_BASE_TEMPLATE',
        'https://api.atlassian.com/ex/jira/{cloud_id}/rest/api/3'
    ),
];

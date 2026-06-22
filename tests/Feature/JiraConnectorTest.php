<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorJira\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Padosoft\AskMyDocsConnectorBase\Contracts\ConnectorIngestionContract;
use Padosoft\AskMyDocsConnectorBase\Exceptions\ConnectorAuthException;
use Padosoft\AskMyDocsConnectorBase\HealthStatus;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorCredential;
use Padosoft\AskMyDocsConnectorBase\Models\ConnectorInstallation;
use Padosoft\AskMyDocsConnectorJira\JiraConnector;
use Padosoft\AskMyDocsConnectorJira\Tests\Support\SpyIngestionContract;
use Padosoft\AskMyDocsConnectorJira\Tests\TestCase;

/**
 * Feature tests for {@see JiraConnector}.
 *
 * Every Atlassian API interaction is stubbed via `Http::fake()`; host
 * pipeline dispatches go through a spy implementation of
 * {@see ConnectorIngestionContract}.
 */
final class JiraConnectorTest extends TestCase
{
    private SpyIngestionContract $spy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spy = new SpyIngestionContract;
        $this->app->instance(ConnectorIngestionContract::class, $this->spy);
        Storage::fake('local');

        config()->set('connectors.providers.jira.client_id', 'cid');
        config()->set('connectors.providers.jira.client_secret', 'csec');
        config()->set('connectors.providers.jira.redirect_uri', 'http://localhost/cb');
    }

    private function connector(): JiraConnector
    {
        return $this->app->make(JiraConnector::class);
    }

    private function makeInstallation(string $tenantId = 'default'): ConnectorInstallation
    {
        return ConnectorInstallation::create([
            'tenant_id' => $tenantId,
            'connector_name' => 'jira',
            'status' => ConnectorInstallation::STATUS_PENDING,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function seedActiveCredential(
        int $installationId,
        string $access = 'AT-jira',
        ?string $refresh = 'RT-jira',
        array $extra = ['cloud_id' => 'cloud-jira-1'],
        string $tenantId = 'default',
    ): void {
        ConnectorCredential::create([
            'tenant_id' => $tenantId,
            'connector_installation_id' => $installationId,
            'encrypted_access_token' => Crypt::encryptString($access),
            'encrypted_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
            'expires_at' => Carbon::now()->addHour(),
            'extra_json' => $extra === [] ? null : $extra,
        ]);
    }

    private function initiateAndExtractState(int $installationId): string
    {
        Cache::flush();
        $url = $this->connector()->initiateOAuth($installationId);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['state'] ?? '');
    }

    public function test_key_and_display_name(): void
    {
        $this->assertSame('jira', $this->connector()->key());
        $this->assertSame('Jira', $this->connector()->displayName());
    }

    public function test_oauth_scopes_include_jira_work_and_offline_access(): void
    {
        $scopes = $this->connector()->oauthScopes();
        $this->assertContains('read:jira-work', $scopes);
        $this->assertContains('read:jira-user', $scopes);
        $this->assertContains('offline_access', $scopes);
    }

    public function test_initiate_oauth_returns_atlassian_auth_url_with_state(): void
    {
        $installation = $this->makeInstallation();

        $url = $this->connector()->initiateOAuth($installation->id);

        $this->assertStringStartsWith('https://auth.atlassian.com/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('cid', $query['client_id']);
        $this->assertSame('api.atlassian.com', $query['audience']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_oauth_callback_exchanges_code_and_resolves_cloud_id(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT-real',
                'refresh_token' => 'RT-real',
                'expires_in' => 3600,
            ], 200),
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                [
                    'id' => 'cloud-jira-real',
                    'scopes' => ['read:jira-work', 'read:jira-user'],
                ],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('AT-real', Crypt::decryptString($row->encrypted_access_token));
        $this->assertSame('cloud-jira-real', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_picks_first_jira_capable_resource(): void
    {
        $installation = $this->makeInstallation();
        $state = $this->initiateAndExtractState($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token' => Http::response([
                'access_token' => 'AT', 'expires_in' => 3600,
            ], 200),
            // First is Confluence-only, second is Jira.
            'api.atlassian.com/oauth/token/accessible-resources' => Http::response([
                ['id' => 'cloud-conf', 'scopes' => ['read:confluence-content.all']],
                ['id' => 'cloud-jira', 'scopes' => ['read:jira-work']],
            ], 200),
        ]);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => $state]);
        $this->connector()->handleOAuthCallback($installation->id, $req);

        $row = ConnectorCredential::query()->first();
        $this->assertSame('cloud-jira', $row->extra_json['cloud_id'] ?? null);
    }

    public function test_oauth_callback_rejects_invalid_state(): void
    {
        $installation = $this->makeInstallation();
        $this->initiateAndExtractState($installation->id);

        $req = Request::create('/cb', 'GET', ['code' => 'c', 'state' => 'WRONG']);
        $this->expectException(ConnectorAuthException::class);
        $this->connector()->handleOAuthCallback($installation->id, $req);
    }

    public function test_full_sync_walks_projects_and_issues_and_dispatches_ingestion(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            // Project list (offset pagination, `values` key).
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/project/search*' => Http::response([
                'values' => [
                    ['key' => 'ENG', 'name' => 'Engineering', 'self' => 'https://acme.atlassian.net/rest/api/3/project/10001'],
                ],
                'isLast' => true,
                'total' => 1,
                'startAt' => 0,
                'maxResults' => 50,
            ], 200),
            // Issue search.
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/search*' => Http::response([
                'issues' => [
                    [
                        'key' => 'ENG-1',
                        'id' => '10001',
                        'fields' => [
                            'summary' => 'Implement connector',
                            'project' => ['key' => 'ENG', 'name' => 'Engineering'],
                            'issuetype' => ['name' => 'Story'],
                            'status' => ['name' => 'In Progress'],
                            'priority' => ['name' => 'Medium'],
                            'labels' => ['backend', 'rag'],
                            'updated' => '2026-05-12T10:00:00.000+0000',
                            'description' => [
                                'type' => 'doc',
                                'content' => [
                                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]],
                                ],
                            ],
                        ],
                    ],
                ],
                'isLast' => true,
                'total' => 1,
                'startAt' => 0,
                'maxResults' => 50,
            ], 200),
        ]);

        $result = $this->connector()->syncFull($installation->id);

        $this->assertSame(1, $result->documentsAdded);
        $this->assertCount(1, $this->spy->dispatches);

        $dispatch = $this->spy->dispatches[0];
        $this->assertSame('[ENG-1] Implement connector', $dispatch['title']);
        $this->assertSame('default', $dispatch['projectKey']);
        $this->assertStringContainsString('/jira/eng/eng-1.md', $dispatch['relativePath']);

        $metadata = $dispatch['metadata'];
        $this->assertSame('jira', $metadata['connector']);
        $this->assertSame('ENG-1', $metadata['jira_issue_key']);
        $this->assertSame('ENG', $metadata['jira_project_key']);
        $this->assertSame('cloud-jira-1', $metadata['jira_cloud_id']);
    }

    public function test_full_sync_emits_no_description_stub_when_body_empty(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/project/search*' => Http::response([
                'values' => [['key' => 'X', 'self' => 'https://acme.atlassian.net/rest/api/3/project/10000']],
                'isLast' => true, 'total' => 1, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/search*' => Http::response([
                'issues' => [
                    [
                        'key' => 'X-1',
                        'fields' => [
                            'summary' => 'Bare ticket',
                            'project' => ['key' => 'X'],
                            'status' => ['name' => 'Open'],
                            // No description, no comments.
                        ],
                    ],
                ],
                'isLast' => true, 'total' => 1, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $this->assertCount(1, $this->spy->dispatches);
        $body = Storage::disk('local')->get($this->spy->dispatches[0]['relativePath']);
        $this->assertIsString($body);
        $this->assertStringContainsString('_No description._', (string) $body);
    }

    public function test_incremental_sync_falls_back_to_full_when_since_null(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/project/search*' => Http::response([
                'values' => [],
                'isLast' => true, 'total' => 0, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
        ]);

        $result = $this->connector()->syncIncremental($installation->id, null);
        $this->assertSame(0, $result->documentsAdded);
    }

    public function test_incremental_sync_uses_jql_updated_since(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/search*' => Http::response([
                'issues' => [],
                'isLast' => true, 'total' => 0, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
        ]);

        $since = Carbon::parse('2026-05-01T00:00:00Z');
        $this->connector()->syncIncremental($installation->id, $since);

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());

            // JQL date format is Jira-specific (NOT ISO-8601).
            return str_contains($url, 'updated >= "2026-05-01 00:00"');
        });
    }

    public function test_health_reports_healthy_with_valid_token(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/myself*' => Http::response([
                'accountId' => 'a-1',
            ], 200),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_HEALTHY, $status->state);
    }

    public function test_health_reports_errored_without_credentials(): void
    {
        $installation = $this->makeInstallation();
        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_health_reports_errored_on_401(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/myself*' => Http::response([], 401),
        ]);

        $status = $this->connector()->health($installation->id);
        $this->assertSame(HealthStatus::STATE_ERRORED, $status->state);
    }

    public function test_disconnect_revokes_both_tokens_then_clears_credentials(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token/revoke' => Http::response([], 200),
        ]);

        $this->connector()->disconnect($installation->id);

        $this->assertSame(0, ConnectorCredential::query()
            ->where('connector_installation_id', $installation->id)
            ->count());

        // Both tokens revoked.
        Http::assertSentCount(2);

        $audit = collect($this->spy->audits)->firstWhere('eventType', 'disconnected');
        $this->assertNotNull($audit);
    }

    public function test_disconnect_continues_local_cleanup_even_if_revoke_fails(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);

        Http::fake([
            'auth.atlassian.com/oauth/token/revoke' => Http::response(['error' => 'down'], 500),
        ]);

        $this->connector()->disconnect($installation->id);

        // Local credentials still removed.
        $this->assertSame(0, ConnectorCredential::query()->count());
    }

    public function test_pii_redaction_runs_through_spy_at_ingest_boundary(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id);
        $this->spy->redactionPrefix = "[REDACTED]\n\n";

        Http::fake([
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/project/search*' => Http::response([
                'values' => [['key' => 'X', 'self' => 'https://acme.atlassian.net/rest/api/3/project/1']],
                'isLast' => true, 'total' => 1, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
            'api.atlassian.com/ex/jira/cloud-jira-1/rest/api/3/search*' => Http::response([
                'issues' => [[
                    'key' => 'X-99',
                    'fields' => [
                        'summary' => 'Has PII',
                        'project' => ['key' => 'X'],
                        'description' => [
                            'type' => 'doc',
                            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'sensitive']]]],
                        ],
                    ],
                ]],
                'isLast' => true, 'total' => 1, 'startAt' => 0, 'maxResults' => 50,
            ], 200),
        ]);

        $this->connector()->syncFull($installation->id);

        $writtenPath = $this->spy->dispatches[0]['relativePath'];
        $body = Storage::disk('local')->get($writtenPath);
        $this->assertStringContainsString('[REDACTED]', (string) $body);
    }

    public function test_full_sync_fails_loudly_without_cloud_id(): void
    {
        $installation = $this->makeInstallation();
        $this->seedActiveCredential($installation->id, extra: []);

        $this->expectException(ConnectorAuthException::class);
        $this->connector()->syncFull($installation->id);
    }
}

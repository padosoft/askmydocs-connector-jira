<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorJira;

use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Jira connector package.
 *
 * Merges the Jira provider block into the host's `connectors.php`
 * config tree (under `providers.jira`). Publishes both the config
 * fragment + the brand asset for hosts that want to customise either.
 *
 * Auto-registration into the connector registry happens at the base
 * package level via composer's `extra.askmydocs.connectors` discovery
 * — the entry is in this package's composer.json.
 */
class JiraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jira.php', 'connectors.providers.jira');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/jira.php' => config_path('connectors-jira.php'),
            ], 'connector-jira-config');

            $this->publishes([
                __DIR__.'/../public/icons' => public_path('connectors'),
            ], 'connector-jira-assets');
        }
    }
}

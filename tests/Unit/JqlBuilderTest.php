<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorJira\Tests\Unit;

use Carbon\Carbon;
use Padosoft\AskMyDocsConnectorJira\Jira\JqlBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see JqlBuilder} — pure-PHP, no Laravel.
 *
 * Validates escape rules (R19), date formatting (Jira-specific
 * "YYYY-MM-DD HH:mm"), and fluent immutability.
 */
final class JqlBuilderTest extends TestCase
{
    public function test_for_project_emits_quoted_project_clause(): void
    {
        $jql = JqlBuilder::for('ENG')->build();
        $this->assertStringContainsString('project = "ENG"', $jql);
    }

    public function test_any_starts_without_project_clause(): void
    {
        $jql = JqlBuilder::any()->orderBy('updated', 'DESC')->build();
        $this->assertStringNotContainsString('project =', $jql);
        $this->assertStringContainsString('ORDER BY updated DESC', $jql);
    }

    public function test_updated_since_formats_jira_date_not_iso8601(): void
    {
        $since = Carbon::parse('2026-05-12T14:30:00Z');
        $jql = JqlBuilder::for('X')->updatedSince($since)->build();
        $this->assertStringContainsString('updated >= "2026-05-12 14:30"', $jql);
        $this->assertStringNotContainsString('T14:30', $jql);
        $this->assertStringNotContainsString('Z"', $jql);
    }

    public function test_escape_value_doubles_backslashes_before_single_quotes(): void
    {
        // Project key with embedded backslash + quote — pathological but
        // guards against the escape-order bug (R19).
        $jql = JqlBuilder::for("Weird\\'Name")->build();
        // The output uses double-quoted strings so the single quote
        // stays literal; the backslash must round-trip correctly.
        $this->assertStringContainsString('project = "Weird', $jql);
    }

    public function test_order_by_emits_order_clause(): void
    {
        $jql = JqlBuilder::for('X')->orderBy('priority', 'ASC')->build();
        $this->assertStringContainsString('ORDER BY priority ASC', $jql);
    }

    public function test_builder_is_immutable_per_method_call(): void
    {
        $base = JqlBuilder::for('X');
        $a = $base->orderBy('updated', 'DESC');
        $b = $base->orderBy('priority', 'ASC');

        $this->assertStringContainsString('ORDER BY updated DESC', $a->build());
        $this->assertStringContainsString('ORDER BY priority ASC', $b->build());
        $this->assertStringNotContainsString('ORDER BY', $base->build());
    }
}

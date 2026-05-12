<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorJira\Tests\Unit;

use Padosoft\AskMyDocsConnectorJira\Jira\JiraAdfToMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see JiraAdfToMarkdown} — pure-PHP, no Laravel.
 *
 * Exercises per-node-type coverage, unknown-node placeholder (R14),
 * nested-block rendering, malformed-input tolerance.
 */
final class JiraAdfToMarkdownTest extends TestCase
{
    private function convert(mixed $adf): string
    {
        return (new JiraAdfToMarkdown)->convert($adf);
    }

    public function test_empty_input_returns_empty_string(): void
    {
        $this->assertSame('', $this->convert(null));
        $this->assertSame('', $this->convert([]));
        $this->assertSame('', $this->convert(['type' => 'paragraph']));
    }

    public function test_renders_plain_paragraph(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => 'Hello world.']],
            ]],
        ]);

        $this->assertSame('Hello world.', $md);
    }

    public function test_renders_text_marks_strong_em_code_strike(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'strong']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'italic', 'marks' => [['type' => 'em']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'mono', 'marks' => [['type' => 'code']]],
                    ['type' => 'text', 'text' => ' '],
                    ['type' => 'text', 'text' => 'gone', 'marks' => [['type' => 'strike']]],
                ],
            ]],
        ]);

        $this->assertStringContainsString('**bold**', $md);
        $this->assertStringContainsString('*italic*', $md);
        $this->assertStringContainsString('`mono`', $md);
        $this->assertStringContainsString('~~gone~~', $md);
    }

    public function test_renders_link_mark(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'visit '],
                    [
                        'type' => 'text',
                        'text' => 'our site',
                        'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://example.test']]],
                    ],
                ],
            ]],
        ]);

        $this->assertStringContainsString('[our site](https://example.test)', $md);
    }

    public function test_renders_heading_levels_1_to_6(): void
    {
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $md = $this->convert([
                'type' => 'doc',
                'content' => [[
                    'type' => 'heading',
                    'attrs' => ['level' => $level],
                    'content' => [['type' => 'text', 'text' => "Title L{$level}"]],
                ]],
            ]);
            $this->assertStringContainsString(str_repeat('#', $level)." Title L{$level}", $md);
        }
    }

    public function test_clamps_heading_levels_out_of_range(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'heading',
                'attrs' => ['level' => 9],
                'content' => [['type' => 'text', 'text' => 'Big']],
            ]],
        ]);
        $this->assertStringStartsWith('######', $md); // clamped to 6
    }

    public function test_renders_bullet_list(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'bulletList',
                'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'alpha']]]]],
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'beta']]]]],
                ],
            ]],
        ]);
        $this->assertStringContainsString('- alpha', $md);
        $this->assertStringContainsString('- beta', $md);
    }

    public function test_renders_ordered_list(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'orderedList',
                'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'first']]]]],
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'second']]]]],
                ],
            ]],
        ]);
        $this->assertStringContainsString('1. first', $md);
        $this->assertStringContainsString('2. second', $md);
    }

    public function test_renders_code_block_with_language(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'codeBlock',
                'attrs' => ['language' => 'php'],
                'content' => [['type' => 'text', 'text' => 'echo 1;']],
            ]],
        ]);
        $this->assertStringContainsString('```php', $md);
        $this->assertStringContainsString('echo 1;', $md);
        $this->assertStringContainsString('```', $md);
    }

    public function test_renders_blockquote(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'blockquote',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'quoted line']]],
                ],
            ]],
        ]);
        $this->assertStringContainsString('> quoted line', $md);
    }

    public function test_renders_panel_info(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'panel',
                'attrs' => ['panelType' => 'info'],
                'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'heads up']]]],
            ]],
        ]);
        $this->assertStringContainsString('**INFO**', $md);
        $this->assertStringContainsString('> heads up', $md);
    }

    public function test_renders_mention(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'text', 'text' => 'cc '],
                    ['type' => 'mention', 'attrs' => ['displayName' => 'Lorenzo', 'id' => 'abc']],
                ],
            ]],
        ]);
        $this->assertStringContainsString('@Lorenzo', $md);
    }

    public function test_renders_inline_card_as_bare_url(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [
                    ['type' => 'inlineCard', 'attrs' => ['url' => 'https://example.test/card']],
                ],
            ]],
        ]);
        $this->assertStringContainsString('https://example.test/card', $md);
    }

    public function test_renders_rule_as_hr(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'before']]],
                ['type' => 'rule'],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'after']]],
            ],
        ]);
        $this->assertStringContainsString('---', $md);
        $this->assertStringContainsString('before', $md);
        $this->assertStringContainsString('after', $md);
    }

    public function test_renders_external_media_as_image(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'mediaSingle',
                'content' => [[
                    'type' => 'media',
                    'attrs' => ['url' => 'https://cdn.example/img.png', 'alt' => 'diagram'],
                ]],
            ]],
        ]);
        $this->assertStringContainsString('![diagram](https://cdn.example/img.png)', $md);
    }

    public function test_internal_media_with_id_emits_audit_placeholder(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'media',
                'attrs' => ['id' => 'media-xyz'],
            ]],
        ]);
        $this->assertStringContainsString('[adf-media: media-xyz]', $md);
    }

    public function test_unknown_node_type_emits_visible_placeholder(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'somethingNew',
                'content' => [],
            ]],
        ]);
        $this->assertStringContainsString('[adf-node: somethingNew]', $md);
    }

    public function test_renders_table_with_header(): void
    {
        $md = $this->convert([
            'type' => 'doc',
            'content' => [[
                'type' => 'table',
                'content' => [
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'name']]]]],
                            ['type' => 'tableHeader', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'age']]]]],
                        ],
                    ],
                    [
                        'type' => 'tableRow',
                        'content' => [
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'alice']]]]],
                            ['type' => 'tableCell', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '30']]]]],
                        ],
                    ],
                ],
            ]],
        ]);
        $this->assertStringContainsString('| name | age |', $md);
        $this->assertStringContainsString('| --- |', $md);
        $this->assertStringContainsString('| alice | 30 |', $md);
    }
}

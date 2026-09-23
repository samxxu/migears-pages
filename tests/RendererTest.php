<?php

declare(strict_types=1);

namespace MiGears\Pages\Tests;

use MiGears\Pages\Compiler;
use MiGears\Pages\Renderer;
use MiGears\Template\Template;
use PHPUnit\Framework\TestCase;

class RendererTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/migears_pages_test_' . getmypid();
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->cacheDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->cacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->cacheDir);
    }

    public function testTemplateMarkersInPageTextRenderLiterally(): void
    {
        // Pages own {{ }}; a "##" in page text is escaped for the template layer, so it
        // reaches the browser as written instead of being evaluated as an expression.
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'body' => [
                ['type' => 'text', 'text' => '## Note ##'],
                ['type' => 'text', 'text' => '### $user["name"] ###'],
                ['type' => 'text', 'text' => 'Hello, {{ user.name }}'],
            ],
        ], ['user' => ['name' => '<b>Alice</b>']]);

        self::assertStringContainsString('## Note ##', $html);
        self::assertStringContainsString('### $user["name"] ###', $html);
        self::assertStringContainsString('Hello, &lt;b&gt;Alice&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Alice</b>', $html);
    }

    public function testRendersBodyPage(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'body' => [
                ['type' => 'heading', 'level' => 2, 'text' => 'User list'],
                ['type' => 'text', 'text' => 'Hello, {{ user.name }}'],
            ],
        ], ['user' => ['name' => 'Alice']]);

        self::assertSame("<h2>User list</h2>\nHello, Alice", $html);
    }

    public function testRendersLayoutWithSections(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'title' => 'User admin',
            'layout' => 'layout/main',
            'sections' => [
                'content' => [
                    ['type' => 'table', 'items' => 'users', 'as' => 'user', 'columns' => [
                        ['label' => 'ID', 'pop' => '{{ user.id }}'],
                        ['label' => 'Name', 'pop' => '{{ user.name }}'],
                    ]],
                ],
            ],
        ], ['users' => [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]]);

        self::assertStringContainsString('<title>User admin', $html);
        self::assertStringContainsString('</title>', $html);
        self::assertStringContainsString('<td>Alice</td>', $html);
        self::assertStringContainsString('<td>Bob</td>', $html);
    }

    public function testEscapesInterpolatedValues(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'body' => [['type' => 'text', 'text' => '{{ payload }}']],
        ], ['payload' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testCreatesCacheDirAutomatically(): void
    {
        $cacheDir = $this->cacheDir . '/nested/cache';
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $cacheDir
        );

        $renderer->render(['body' => [['type' => 'text', 'text' => 'hi']]]);

        self::assertDirectoryExists($cacheDir);
        self::assertCount(1, glob($cacheDir . '/*.tpl.php') ?: []);
    }

    public function testRerendersWhenDeclarationChanges(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $first = $renderer->render(['body' => [['type' => 'text', 'text' => 'v1']]]);
        $second = $renderer->render(['body' => [['type' => 'text', 'text' => 'v2']]]);

        self::assertSame('v1', $first);
        self::assertSame('v2', $second);
        self::assertCount(2, glob($this->cacheDir . '/*.tpl.php') ?: []);
    }

    public function testClearCacheRemovesDerivedPagesAndRefillsThem(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $renderer->render(['body' => [['type' => 'text', 'text' => 'v1']]]);
        $renderer->render(['body' => [['type' => 'text', 'text' => 'v2']]]);

        self::assertSame(2, $renderer->clearCache());
        self::assertCount(0, glob($this->cacheDir . '/page_*.tpl.php') ?: []);
        self::assertSame(0, $renderer->clearCache(), 'clearing twice is a no-op');
        self::assertDirectoryExists($this->cacheDir, 'clearing removes derived pages, not the directory');

        self::assertSame('v1', $renderer->render(['body' => [['type' => 'text', 'text' => 'v1']]]));
        self::assertCount(1, glob($this->cacheDir . '/page_*.tpl.php') ?: []);
    }

    public function testClearCacheKeepsForeignFiles(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $renderer->render(['body' => [['type' => 'text', 'text' => 'hi']]]);
        mkdir($this->cacheDir . '/layout', 0755, true);
        file_put_contents($this->cacheDir . '/card.php', '<?= "card" ?>');
        file_put_contents($this->cacheDir . '/layout/main.php', '<?= $content ?>');
        file_put_contents($this->cacheDir . '/page_handwritten.php', '<?= 1 ?>');

        self::assertSame(1, $renderer->clearCache());
        self::assertFileExists($this->cacheDir . '/card.php');
        self::assertFileExists($this->cacheDir . '/layout/main.php');
        self::assertFileExists($this->cacheDir . '/page_handwritten.php', 'only exact page_*.tpl.php matches are removed');
    }

    public function testRendersComponentThroughTemplatePaths(): void
    {
        $tpl = new Template(__DIR__ . '/fixtures/views');
        $tpl->addPath(__DIR__ . '/../vendor/migears/template/tests/fixtures/components');
        $renderer = new Renderer($tpl, new Compiler(), $this->cacheDir);

        $html = $renderer->render([
            'body' => [['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}', 'body' => 'About']]],
        ], ['user' => ['name' => 'Alice']]);

        self::assertStringContainsString('Alice', $html);
    }
}

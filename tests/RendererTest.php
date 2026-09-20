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

    public function testRendersBodyPage(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'body' => [
                ['type' => 'heading', 'level' => 2, 'text' => '用户列表'],
                ['type' => 'text', 'text' => '你好，{{ user.name }}'],
            ],
        ], ['user' => ['name' => 'Alice']]);

        self::assertSame("<h2>用户列表</h2>\n你好，Alice", $html);
    }

    public function testRendersLayoutWithSections(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'title' => '用户管理',
            'layout' => 'layout/main',
            'sections' => [
                'content' => [
                    ['type' => 'table', 'items' => 'users', 'as' => 'user', 'columns' => [
                        ['label' => 'ID', 'bind' => 'id'],
                        ['label' => '姓名', 'bind' => 'name'],
                    ]],
                ],
            ],
        ], ['users' => [
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob'],
        ]]);

        self::assertStringContainsString('<title>用户管理', $html);
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

    public function testRendersComponentThroughTemplatePaths(): void
    {
        $tpl = new Template(__DIR__ . '/fixtures/views');
        $tpl->addPath(__DIR__ . '/../vendor/migears/template/tests/fixtures/components');
        $renderer = new Renderer($tpl, new Compiler(), $this->cacheDir);

        $html = $renderer->render([
            'body' => [['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}', 'body' => '简介']]],
        ], ['user' => ['name' => 'Alice']]);

        self::assertStringContainsString('Alice', $html);
    }
}

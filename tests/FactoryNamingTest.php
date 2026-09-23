<?php

declare(strict_types=1);

namespace MiGears\Pages\Tests;

use FilesystemIterator;
use MiGears\Pages\FieldNode;
use MiGears\Pages\Html;
use MiGears\Pages\InputNode;
use MiGears\Pages\Node;
use MiGears\Pages\PlainNode;
use MiGears\Pages\TagNode;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * The casing convention of §10: factories are all-caps, member methods are not, and
 * THEN / ELSE are the only uppercase members.
 *
 * PHP resolves method names case-insensitively, so `h5::TEXTAREA()` and `h5::textarea()`
 * are the same method and a lowercase call site cannot be rejected at runtime — even a
 * deliberately added __callStatic never sees it. The convention is therefore the one
 * thing a reader leans on, and a test is the only thing that can hold it in place: this
 * file checks the declarations, then reads the package's own docs, spec, examples and
 * tests looking for a lowercase spelling.
 */
final class FactoryNamingTest extends TestCase
{
    /** Every factory of Html, and nothing else. */
    private const FACTORIES = [
        'TEXT', 'HEADING', 'LINK', 'IF', 'EACH', 'FORM', 'INPUT',
        'TEXTAREA', 'SELECT', 'TABLE', 'COL', 'COMPONENT', 'EL',
    ];

    /** The only member methods allowed to be uppercase, because they name statements. */
    private const UPPER_METHODS = ['ELSE', 'THEN'];

    public function testHtmlDeclaresExactlyTheCapsFactories(): void
    {
        $declared = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(Html::class))->getMethods(ReflectionMethod::IS_STATIC)
        );
        sort($declared);
        $expected = self::FACTORIES;
        sort($expected);

        $this->assertSame($expected, $declared, 'Html 的静态工厂应当正好是这 13 个，不多不少');
    }

    public function testEveryFactoryIsDeclaredInCaps(): void
    {
        $reflection = new ReflectionClass(Html::class);

        foreach (self::FACTORIES as $name) {
            // getMethod() ignores case, so the check has to read the declared spelling
            // back out: a lowercase declaration would surface here.
            $this->assertSame(
                $name,
                $reflection->getMethod($name)->getName(),
                "工厂必须以全大写声明: {$name}"
            );
        }
    }

    public function testThenAndElseAreTheOnlyUppercaseMemberMethods(): void
    {
        $found = [];
        $classes = [Node::class, PlainNode::class, TagNode::class, FieldNode::class, InputNode::class];

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                $name = $method->getName();
                if (preg_match('/[A-Z]/', $name) === 1 && strtoupper($name) === $name) {
                    $found[$name] = true;
                }
            }
        }

        $found = array_keys($found);
        sort($found);

        $this->assertSame(self::UPPER_METHODS, $found, '只有 THEN / ELSE 可以大写，其余成员方法一律小写');
    }

    public function testSamplesWrittenWithTheFactoryStillWork(): void
    {
        // The docs promise that the lowercase spelling keeps running — it is the same
        // method. Asserted so that the convention is known to be cosmetic, never a
        // behaviour the package depends on.
        $this->assertSame(
            Html::textarea('bio')->label('简介')->toArray(),
            Html::TEXTAREA('bio')->label('简介')->toArray()
        );
        $this->assertSame(
            Html::if('users')->then([Html::text('x')])->toArray(),
            Html::IF('users')->THEN([Html::TEXT('x')])->toArray()
        );
    }

    public function testDocsSpecExamplesAndTestsSpellTheFactoriesInCaps(): void
    {
        $pattern = '/h5::([A-Za-z_][A-Za-z0-9_]*)/';
        $offenders = [];

        foreach ($this->sources() as $file => $content) {
            if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) !== false) {
                foreach ($matches as $match) {
                    if (! in_array($match[1], self::FACTORIES, true)) {
                        $offenders[] = "{$file}: {$match[0]}";
                    }
                }
            }

            if (preg_match_all('/->(then|else)\(/', $content, $matches) !== false) {
                foreach ($matches[1] as $name) {
                    $offenders[] = "{$file}: ->{$name}(";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "示例与文档里的工厂调用必须全大写，THEN / ELSE 是唯一的大写成员方法：\n" . implode("\n", $offenders)
        );
    }

    /**
     * Every hand-written file that a reader may copy from, keyed by its path relative to
     * the package root. This file is skipped: it holds the patterns it searches for.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root = dirname(__DIR__);
        $paths = [$root . '/README.md', $root . '/spec.md'];

        foreach (['docs', 'src', 'tests', 'examples', 'bin'] as $dir) {
            $base = $root . '/' . $dir;
            if (! is_dir($base)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'md'], true)) {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $sources = [];
        foreach ($paths as $path) {
            if (! is_file($path) || realpath($path) === __FILE__) {
                continue;
            }
            $sources[str_replace($root . '/', '', $path)] = (string) file_get_contents($path);
        }

        return $sources;
    }
}

<?php

declare(strict_types=1);

namespace MiGears\Pages\Tests;

use MiGears\Pages\Compiler;
use MiGears\Pages\Exception\CompileException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The array form of the DSL is the canonical one: every format frontend feeds
 * this compiler, so what is asserted here is the behaviour all of them share.
 */
final class CompilerTest extends TestCase
{
    private Compiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new Compiler();
    }

    public function testTextLeavesLiteralsAloneAndInterpolatesPaths(): void
    {
        $this->assertSame('Hi', $this->compile(['body' => [['type' => 'text', 'text' => 'Hi']]]));
        $this->assertSame(
            "Hi, ## \$user['name'] ?? '' ##",
            $this->compile(['body' => [['type' => 'text', 'text' => 'Hi, {{ user.name }}']]])
        );
    }

    public function testHeadingLevelDefaultsToOneAndIsValidated(): void
    {
        $this->assertSame('<h1>Title</h1>', $this->compile(['body' => [['type' => 'heading', 'text' => 'Title']]]));
        $this->assertSame('<h2>Title</h2>', $this->compile(['body' => [['type' => 'heading', 'level' => 2, 'text' => 'Title']]]));
        $this->expectError(
            ['body' => [['type' => 'heading', 'level' => 7, 'text' => 'Title']]],
            'heading level must be an integer from 1 to 6'
        );
    }

    public function testLinkInterpolatesHrefAndText(): void
    {
        $this->assertSame(
            '<a href="/x/## $user[\'id\'] ?? \'\' ##">Go</a>',
            $this->compile(['body' => [['type' => 'link', 'href' => '/x/{{ user.id }}', 'text' => 'Go']]])
        );
        $this->assertSame(
            '<a href="/x" target="_blank">Go</a>',
            $this->compile(['body' => [['type' => 'link', 'href' => '/x', 'text' => 'Go', 'target' => '_blank']]])
        );
    }

    public function testIfEmitsNativePhpCondition(): void
    {
        $this->assertSame(
            "<?php if (\$a['b'] ?? null): ?>\nA\n<?php endif ?>",
            $this->compile(['body' => [['type' => 'if', 'when' => 'a.b', 'then' => [['type' => 'text', 'text' => 'A']]]]])
        );
        $this->assertSame(
            "<?php if (!(\$a ?? null)): ?>\nA\n<?php endif ?>",
            $this->compile(['body' => [['type' => 'if', 'when' => '!a', 'then' => [['type' => 'text', 'text' => 'A']]]]])
        );
    }

    public function testIfElseBranches(): void
    {
        $this->assertSame(
            "<?php if (\$a ?? null): ?>\nA\n<?php else: ?>\nB\n<?php endif ?>",
            $this->compile(['body' => [[
                'type' => 'if',
                'when' => 'a',
                'then' => [['type' => 'text', 'text' => 'A']],
                'else' => [['type' => 'text', 'text' => 'B']],
            ]]])
        );
    }

    public function testEachBindsValueAndIndex(): void
    {
        $this->assertSame(
            "<?php foreach (\$users ?? [] as \$i => \$u): ?>\n## \$u['name'] ?? '' ##\n<?php endforeach ?>",
            $this->compile(['body' => [[
                'type' => 'each',
                'items' => 'users',
                'as' => 'u',
                'index' => 'i',
                'body' => [['type' => 'text', 'text' => '{{ u.name }}']],
            ]]])
        );
    }

    public function testEachDefaultsValueVariableToItem(): void
    {
        $this->assertSame(
            "<?php foreach (\$users ?? [] as \$item): ?>\n## \$item['name'] ?? '' ##\n<?php endforeach ?>",
            $this->compile(['body' => [[
                'type' => 'each',
                'items' => 'users',
                'body' => [['type' => 'text', 'text' => '{{ item.name }}']],
            ]]])
        );
    }

    public function testNestedEachReferencingOuterVariable(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'each',
            'items' => 'groups',
            'as' => 'g',
            'body' => [[
                'type' => 'each',
                'items' => 'g.members',
                'as' => 'm',
                'body' => [['type' => 'text', 'text' => '{{ m.name }}']],
            ]],
        ]]]);

        $this->assertStringContainsString('foreach ($groups ?? [] as $g)', $out);
        $this->assertStringContainsString("foreach (\$g['members'] ?? [] as \$m)", $out);
    }

    public function testElForwardsFrameworkDirectivesVerbatim(): void
    {
        $this->assertSame(
            "<div class=\"panel\" @click=\"open = ! open\" data-n=\"3\">\nhi\n</div>",
            $this->compile(['body' => [[
                'type' => 'el',
                'tag' => 'div',
                'class' => 'panel',
                '@click' => 'open = ! open',
                'data-n' => 3,
                'body' => [['type' => 'text', 'text' => 'hi']],
            ]]])
        );
    }

    public function testElInterpolatesAttributeValuesAndEscapesLiterals(): void
    {
        $this->assertSame(
            "<li data-id=\"## \$user['id'] ?? '' ##\" class=\"a &amp; b\"></li>",
            $this->compile(['body' => [[
                'type' => 'el',
                'tag' => 'li',
                'data-id' => '{{ user.id }}',
                'class' => 'a & b',
                'body' => [],
            ]]])
        );
    }

    public function testValuelessAttributeUsesNull(): void
    {
        $this->assertSame(
            '<div x-cloak=""></div>',
            $this->compile(['body' => [['type' => 'el', 'tag' => 'div', 'x-cloak' => null, 'body' => []]]])
        );
    }

    public function testUnknownAttributeIsRejectedNotDropped(): void
    {
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'shwo' => 'x', 'body' => []]]],
            'unknown attribute "shwo"'
        );
    }

    public function testNonScalarAttributeValueIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'data-x' => ['nested' => 1], 'body' => []]]],
            'must have a scalar value'
        );
    }

    public function testAttributeOnNodeWithoutTagIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'if', 'when' => 'a', 'class' => 'x', 'then' => [['type' => 'text', 'text' => 'A']]]]],
            'node type: if emits no tag'
        );
    }

    public function testComponentWithoutAndWithData(): void
    {
        $this->assertSame(
            "<?= \$this->component('badge') ?>",
            $this->compile(['body' => [['type' => 'component', 'name' => 'badge']]])
        );
        $this->assertSame(
            "<?= \$this->component('card', [\n    'title' => (\$user['name'] ?? ''),\n]) ?>",
            $this->compile(['body' => [['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}']]]])
        );
    }

    public function testTableColumnsPopAndContent(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'table',
            'items' => 'users',
            'columns' => [
                ['label' => 'ID', 'pop' => '{{ row.id }}'],
                ['label' => 'Actions', 'content' => [['type' => 'link', 'href' => '/u/{{ row.id }}', 'text' => 'Edit']]],
            ],
        ]]]);

        $this->assertStringContainsString('<th>ID</th><th>Actions</th>', $out);
        $this->assertStringContainsString("<td>## \$row['id'] ?? '' ##</td>", $out);
        $this->assertStringContainsString('<a href="/u/## $row[\'id\'] ?? \'\' ##">Edit</a>', $out);
        $this->assertStringContainsString('foreach ($users ?? [] as $row)', $out);
    }

    public function testTableEmptyBranchRendersColspan(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'table',
            'items' => 'users',
            'empty' => 'No data',
            'columns' => [['label' => 'ID', 'pop' => '{{ row.id }}']],
        ]]]);

        $this->assertStringContainsString('if (($users ?? []) === [])', $out);
        $this->assertStringContainsString('<td colspan="1">No data</td>', $out);
    }

    public function testColumnNeedsExactlyOneOfPopOrContent(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A']]]]],
            'a column needs either pop or content'
        );
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'pop' => '{{ row.a }}', 'content' => []]]]]],
            'a column cannot specify both pop and content'
        );
    }

    public function testPopReferenceMustNameTheRowVariableInBraces(): void
    {
        // A bare path would silently mean "relative to the row" — and 'user.name'
        // would mean row['user']['name'] instead of the page-level user.
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'pop' => 'a']]]]],
            'write it as {{ row.a }}'
        );
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'pop' => '{{ user.name }}']]]]],
            'must reference the row variable "row"'
        );
    }

    public function testBindTakesABrowserSideNameOnly(): void
    {
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'bind' => '{{ user.name }}', 'body' => []]]],
            'bind is a browser-side variable name and does not support {{ }} interpolation'
        );
    }

    public function testBindIsEmittedForEveryTagAndField(): void
    {
        $out = $this->compile(['body' => [
            ['type' => 'el', 'tag' => 'span', 'bind' => 'user.email', 'body' => []],
            ['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 'email', 'input' => 'text', 'label' => 'Email', 'bind' => 'form.email'],
            ]],
        ]]);

        self::assertStringContainsString('<span bind="user.email">', $out);
        self::assertStringContainsString('name="email" id="email" bind="form.email"', $out);
    }

    public function testExplicitIdOverridesTheFieldNameInsteadOfDuplicatingIt(): void
    {
        // id defaults to name (which keeps HTML hooks and the DTO key aligned), but an
        // explicit id has to override it — not be emitted a second time.
        $out = $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
            ['name' => 'email', 'input' => 'text', 'label' => 'Email', 'id' => 'userEmail'],
        ]]]]);

        self::assertStringContainsString('<label for="userEmail">', $out);
        self::assertStringContainsString('name="email" id="userEmail"', $out);
        self::assertSame(1, substr_count($out, 'id="userEmail"'));
    }

    public function testColumnContentMustBeNodeTree(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'content' => 'bare text']]]]],
            'content: must be a node tree array'
        );
    }

    public function testColumnContentRejectsBareNodeMap(): void
    {
        // A single node written without the list wrapper is an array, so an
        // is_array() guard lets it through and the failure surfaces later as
        // "content[type]: node must be an array" — a message that names the wrong fault.
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [
                ['label' => 'A', 'content' => ['type' => 'text', 'text' => 'x']],
            ]]]],
            'content: must be a node tree array (a list)'
        );
    }

    public function testFormRendersLabelPerInputType(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'form',
            'action' => '/save',
            'fields' => [
                ['name' => 'n', 'label' => 'Name', 'input' => 'text', 'value' => 'user.name', 'required' => true],
                ['name' => 'p', 'label' => 'Password', 'input' => 'password'],
                ['name' => 'b', 'label' => 'Bio', 'input' => 'textarea'],
                ['name' => 's', 'label' => 'Role', 'input' => 'select', 'options' => ['a' => 'Admin']],
                ['name' => 'c', 'label' => 'Enabled', 'input' => 'checkbox', 'checked' => 'user.active'],
                ['name' => 't', 'label' => 'TOKEN', 'input' => 'hidden', 'value' => 'form.csrf'],
                ['name' => 'go', 'label' => 'Save', 'input' => 'submit'],
            ],
        ]]]);

        $this->assertStringContainsString('<form action="/save" method="post">', $out);
        $this->assertStringContainsString('value="## $user[\'name\'] ?? \'\' ##" required>', $out);
        $this->assertStringContainsString('<textarea name="b" id="b" rows="4">', $out);
        $this->assertStringContainsString('<option value="a">Admin</option>', $out);
        $this->assertStringContainsString("<?= (\$user['active'] ?? null) ? ' checked' : '' ?>", $out);
        // hidden has no label; submit carries its label as the button text
        $this->assertStringNotContainsString('<label for="t">', $out);
        $this->assertStringContainsString('<input type="submit" value="Save">', $out);
    }

    public function testFormMethodDefaultsToPostAndRejectsBadValues(): void
    {
        $out = $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
            ['name' => 'a', 'label' => 'A'],
        ]]]]);
        $this->assertStringContainsString('method="post"', $out);

        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'method' => 'put', 'fields' => [['name' => 'a', 'label' => 'A']]]]],
            'method must be "get" or "post"'
        );
    }

    public function testFormMethodRejectsNonStringValuesWithoutLeakingWarning(): void
    {
        // Casting first would raise "Array to string conversion" — a PHP warning
        // escaping into output — and then report a type fault as an enum fault.
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'method' => ['post'], 'fields' => [['name' => 'a', 'label' => 'A']]]]]);
            $this->fail('should have failed to compile');
        } catch (CompileException $e) {
            $this->assertStringContainsString('method must be the string "get" or "post", got array', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'the compiler must not leak PHP warnings');
    }

    public function testFormMethodRejectsNonStringScalars(): void
    {
        foreach ([true, 5] as $bad) {
            $this->expectError(
                ['body' => [['type' => 'form', 'action' => '/s', 'method' => $bad, 'fields' => [['name' => 'a', 'label' => 'A']]]]],
                'method must be the string "get" or "post", got ' . gettype($bad)
            );
        }
    }

    public function testFieldRequiredMustBeBooleanAndOptionTextMustBeString(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'required' => 'true']]]]],
            'required must be a boolean, got string'
        );
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 's', 'label' => 'S', 'input' => 'select', 'options' => ['a' => ['x']]],
            ]]]],
            'option "a" text must be a string, got array'
        );
    }

    public function testSelectRejectsValueBindingAndNonSelectRejectsOptions(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 's', 'label' => 'S', 'input' => 'select', 'value' => 'a', 'options' => ['x' => 'X']],
            ]]]],
            'select fields do not support value binding'
        );
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 's', 'label' => 'S', 'options' => ['x' => 'X']],
            ]]]],
            'options is only for select fields'
        );
    }

    public function testPageRootFieldsAreTypeChecked(): void
    {
        $this->expectError(['body' => 'not an array'], 'body: must be a node tree array, got string');
        $this->expectError(['body' => ['type' => 'text', 'text' => 'x']], 'body: must be a node tree array (a list)');
        $this->expectError(['layout' => ['a'], 'sections' => []], 'page: layout must be a string, got array');
        $this->expectError(
            ['title' => ['a'], 'layout' => 'layout/main', 'sections' => []],
            'page: title must be a string, got array'
        );
        $this->expectError(
            ['layout' => 'layout/main', 'sections' => 'not a map'],
            'page: sections must be a map of section name to node tree, got string'
        );
        $this->expectError(
            ['layout' => 'layout/main', 'sections' => ['content' => 'not a tree']],
            'sections.content: must be a node tree array, got string'
        );
        $this->expectError(
            ['layout' => 'layout/main', 'sections' => ['content' => ['type' => 'text', 'text' => 'x']]],
            'sections.content: must be a node tree array (a list)'
        );
    }

    public function testLayoutPageCollectsTitleAndSections(): void
    {
        $out = $this->compile([
            'layout' => 'layout/main',
            'title' => 'User management',
            'sections' => ['content' => [['type' => 'text', 'text' => 'Body']]],
        ]);

        $this->assertStringContainsString("\$this->extends('layout/main')", $out);
        $this->assertStringContainsString("\$this->start('title')", $out);
        $this->assertStringContainsString('User management', $out);
        $this->assertStringContainsString("\$this->start('content')", $out);
        $this->assertStringContainsString('Body', $out);
    }

    public function testStandalonePageIgnoresTitleAndWarns(): void
    {
        $warnings = [];
        $compiler = new Compiler(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $out = $compiler->compile(['title' => 'Ignored', 'body' => [['type' => 'text', 'text' => 'Content']]]);

        $this->assertSame('Content', $out);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('title', $warnings[0]);
    }

    public function testLayoutAndBodyAreMutuallyExclusive(): void
    {
        $this->expectError(
            ['layout' => 'layout/main', 'body' => [['type' => 'text', 'text' => 'A']]],
            'layout and body cannot be set together'
        );
    }

    public function testInterpolationEdgeCases(): void
    {
        $this->assertSame(
            'before ## $a ?? \'\' ## after',
            $this->compile(['body' => [['type' => 'text', 'text' => 'before {{ a }} after']]])
        );
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{{ a }}}']]],
            'cannot run three braces'
        );
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{ a }} }}']]],
            'unbalanced interpolation markers'
        );
    }

    public function testLiteralFieldsRejectInterpolation(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'empty' => '{{ a }}', 'columns' => [['label' => 'A', 'pop' => '{{ row.a }}']]]]],
            'does not support {{ }} interpolation'
        );
    }

    public function testUnknownNodeTypeIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'sectoin', 'text' => 'x']]],
            'unknown node type "sectoin"'
        );
    }

    public function testNodeMustBeArrayAndCarryType(): void
    {
        $this->expectError(['body' => ['bare text']], 'node must be an array');
        $this->expectError(['body' => [['text' => 'x']]], 'node is missing its type field');
    }

    public function testStructuralChildrenMustBeNodeLists(): void
    {
        $this->expectError(['body' => [['type' => 'if', 'when' => 'a']]], 'if is missing then (a node tree array)');
        $this->expectError(
            ['body' => [['type' => 'if', 'when' => 'a', 'then' => ['type' => 'text', 'text' => 'x']]]],
            'then: must be a node tree array (a list)'
        );
        $this->expectError(
            ['body' => [['type' => 'if', 'when' => 'a', 'then' => [], 'else' => 'x']]],
            'else: must be a node tree array, got string'
        );
        $this->expectError(['body' => [['type' => 'each', 'items' => 'u']]], 'each is missing body (a node tree array)');
        $this->expectError(
            ['body' => [['type' => 'each', 'items' => 'u', 'body' => ['type' => 'text', 'text' => 'x']]]],
            'body: must be a node tree array (a list)'
        );
    }

    public function testFieldAndColumnListsMustBeLists(): void
    {
        $this->expectError(['body' => [['type' => 'form', 'action' => '/s']]], 'form is missing fields (an array of fields)');
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => ['name' => 'a']]]],
            'fields: must be an array of fields (a list)'
        );
        $this->expectError(['body' => [['type' => 'table', 'items' => 'u']]], 'table is missing columns (an array of columns)');
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => ['label' => 'A']]]],
            'columns: must be an array of columns (a list)'
        );
    }

    public function testElRequiresTagAndBody(): void
    {
        $this->expectError(['body' => [['type' => 'el']]], 'missing string field "tag"');
        // an <el> may wrap nothing: it exists to carry attributes
        $this->assertSame('<div></div>', $this->compile(['body' => [['type' => 'el', 'tag' => 'div']]]));
        $this->expectError(['body' => [['type' => 'el', 'tag' => 'div', 'body' => 'x']]], 'body: must be a node tree array, got string');
        $this->expectError(['body' => [['type' => 'el', 'tag' => 'div', 'body' => ['type' => 'text', 'text' => 'x']]]], 'body: must be a node tree array (a list)');
        // Present but null is a mistake, not "no children" — an emptied key in a
        // mapping source lands here.
        $this->expectError(['body' => [['type' => 'el', 'tag' => 'div', 'body' => null]]], 'body: must be a node tree array, got NULL');
        $this->expectError(['body' => [['type' => 'el', 'tag' => '1div', 'body' => []]]], 'invalid tag');
        // uppercase is normalised, not rejected
        $this->assertSame('<div></div>', $this->compile(['body' => [['type' => 'el', 'tag' => 'DIV', 'body' => []]]]));
    }

    public function testPathMustBePlainVariablePath(): void
    {
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{ a[b] }}']]],
            'invalid path'
        );
    }

    public function testSourceEntryPointsRejectTextOnTheBaseCompiler(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('only accepts array page declarations');
        $this->compiler->compileSource('<page/>');
    }

    public function testCompileFileRejectsMissingFile(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('page file not found');
        $this->compiler->compileFile('/nonexistent/page.xml');
    }

    /**
     * Every malformed input must fail as a readable CompileException: never as a
     * PHP warning escaping into output, and never as a TypeError from an array
     * parameter. One row per guard, so an unguarded cast shows up here.
     *
     * @param array<string, mixed> $page
     */
    #[DataProvider('malformedPages')]
    public function testMalformedInputFailsReadablyWithoutPhpWarnings(array $page): void
    {
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        });

        try {
            $this->compile($page);
            $this->fail('should have failed to compile');
        } catch (CompileException) {
            // expected: a readable compile error
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $warnings, 'the compiler must not leak PHP warnings');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function malformedPages(): array
    {
        $fields = [['name' => 'a', 'label' => 'A']];

        return [
            'layout not a string' => [['layout' => ['a'], 'sections' => []]],
            'title not a string' => [['title' => ['a'], 'layout' => 'layout/main', 'sections' => []]],
            'sections not a map' => [['layout' => 'layout/main', 'sections' => 'x']],
            'sections value empty' => [['layout' => 'layout/main', 'sections' => ['c' => null]]],
            'body not an array' => [['body' => 'x']],
            'body a single node map' => [['body' => ['type' => 'text', 'text' => 'x']]],
            'then not an array' => [['body' => [['type' => 'if', 'when' => 'a', 'then' => 'x']]]],
            'else a map' => [['body' => [['type' => 'if', 'when' => 'a', 'then' => [], 'else' => ['type' => 'text', 'text' => 'x']]]]],
            'each body a map' => [['body' => [['type' => 'each', 'items' => 'u', 'body' => ['type' => 'text', 'text' => 'x']]]]],
            'el body not an array' => [['body' => [['type' => 'el', 'tag' => 'div', 'body' => 'x']]]],
            'el body null' => [['body' => [['type' => 'el', 'tag' => 'div', 'body' => null]]]],
            'fields a map' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => ['name' => 'a']]]]],
            'method an array' => [['body' => [['type' => 'form', 'action' => '/s', 'method' => ['post'], 'fields' => $fields]]]],
            'required a string' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'required' => 'true']]]]]],
            'option text an array' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 's', 'label' => 'S', 'input' => 'select', 'options' => ['a' => ['x']]]]]]]],
            'columns a map' => [['body' => [['type' => 'table', 'items' => 'u', 'columns' => ['label' => 'A']]]]],
            'column content a map' => [['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'content' => ['type' => 'text', 'text' => 'x']]]]]]],
            'sections a list' => [['layout' => 'layout/main', 'sections' => [['type' => 'text', 'text' => 'x']]]],
            'component data key interpolates' => [['body' => [['type' => 'component', 'name' => 'card', 'data' => ['{{ a }}' => 'x']]]]],
            'component data a list' => [['body' => [['type' => 'component', 'name' => 'card', 'data' => ['x', 'y']]]]],
            'placeholder on select' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 's', 'label' => 'S', 'input' => 'select', 'placeholder' => 'p', 'options' => ['a' => 'A']]]]]]],
            'checked on text' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'text', 'checked' => 'a.b']]]]]],
            'rows on text' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'text', 'rows' => 4]]]]]],
            'value on submit' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'submit', 'value' => 'a.b']]]]]],
            'required on hidden' => [['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'hidden', 'required' => true]]]]]],
        ];
    }

    public function testComponentDataKeysAreLiteralFields(): void
    {
        $this->expectError(
            ['body' => [['type' => 'component', 'name' => 'card', 'data' => ['{{ user.id }}' => 'x']]]],
            '"data key" is a literal field and does not support {{ }} interpolation'
        );

        // A literal key still compiles, and the value beside it still interpolates.
        $this->assertStringContainsString(
            "'title' => (\$user['name'] ?? ''),",
            $this->compile(['body' => [['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}']]]])
        );
    }

    public function testFieldFieldsAreScopedToTheirInputType(): void
    {
        $form = static fn (array $field): array => [
            'body' => [['type' => 'form', 'action' => '/s', 'fields' => [$field]]],
        ];
        $base = ['name' => 'a', 'label' => 'A'];

        $this->expectError(
            $form(['input' => 'select', 'placeholder' => 'p', 'options' => ['x' => 'X']] + $base),
            '"placeholder" is only for the text / password / email / number fields; the input here is "select"'
        );
        $this->expectError(
            $form(['input' => 'text', 'checked' => 'user.ok'] + $base),
            '"checked" is only for the checkbox fields; the input here is "text"'
        );
        $this->expectError(
            $form(['input' => 'text', 'rows' => 4] + $base),
            '"rows" is only for the textarea fields; the input here is "text"'
        );
        $this->expectError(
            $form(['input' => 'submit', 'value' => 'user.label'] + $base),
            'submit fields do not support value binding'
        );
        $this->expectError(
            $form(['input' => 'hidden', 'required' => true] + $base),
            'required is only for the text / password / email / number / textarea / select / checkbox fields'
        );
    }

    public function testRequiredIsEmittedForEveryInputTypeThatSupportsIt(): void
    {
        $out = $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
            ['name' => 's', 'label' => 'S', 'input' => 'select', 'required' => true, 'options' => ['a' => 'A']],
            ['name' => 'b', 'label' => 'B', 'input' => 'textarea', 'required' => true],
            ['name' => 'c', 'label' => 'C', 'input' => 'checkbox', 'required' => true],
        ]]]]);

        $this->assertStringContainsString('<select name="s" id="s" required>', $out);
        $this->assertStringContainsString('<textarea name="b" id="b" rows="4" required>', $out);
        $this->assertStringContainsString('<input type="checkbox" name="c" id="c" required>', $out);
    }

    public function testMapsMustNotBeWrittenAsLists(): void
    {
        $this->expectError(
            ['layout' => 'layout/main', 'sections' => [['type' => 'text', 'text' => 'x']]],
            'page: sections must be a map of section name to node tree (a key-value map), but got a list'
        );
        $this->expectError(
            ['body' => [['type' => 'component', 'name' => 'card', 'data' => ['x', 'y']]]],
            'component data must be a map of key => string (a key-value map), but got a list'
        );

        // An empty array is an empty mapping too, so it stays legal.
        $this->assertStringContainsString(
            "extends('layout/main')",
            $this->compile(['layout' => 'layout/main', 'sections' => []])
        );
    }

    public function testRequiredPathsAreReportedWhenMissing(): void
    {
        $this->expectError(['body' => [['type' => 'if', 'then' => []]]], 'missing string field "when"');
        $this->expectError(['body' => [['type' => 'each', 'body' => []]]], 'missing string field "items"');
        $this->expectError(['body' => [['type' => 'table', 'columns' => []]]], 'missing string field "items"');
    }

    public function testHyphenFormOfColonDirectiveIsRejected(): void
    {
        // Alpine spells these with a colon; the bare 'x-' prefix would forward the
        // hyphen form and the directive would silently do nothing.
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'x-on-click' => 'open = !open']]],
            'write "x-on:click" or "@click"'
        );
    }

    public function testNestedStructureTypeMustMatchItsPosition(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'type' => 'column']]]]],
            'type must be "field"'
        );
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'bind' => 'id', 'type' => 'field']]]]],
            'type must be "column"'
        );
    }

    public function testTemplateMarkersInPageTextAreEscaped(): void
    {
        // Page text, attribute values and component values are escaped for the template
        // layer, so a literal "##" survives compilation as text instead of being read
        // back as a template expression (which would bypass path validation entirely).
        $compiler = new Compiler();

        $cases = [
            'text' => ['body' => [['type' => 'text', 'text' => '## note ##']]],
            'attribute value' => ['body' => [['type' => 'el', 'tag' => 'div', 'class' => 'a-## b', 'body' => []]]],
            'component value' => ['body' => [['type' => 'component', 'name' => 'card', 'data' => ['title' => '## x ##']]]],
        ];

        foreach ($cases as $name => $page) {
            self::assertStringContainsString('\##', $compiler->compile($page), "[{$name}] was not escaped for the template layer");
        }

        // A single hash needs no escape and stays untouched.
        self::assertStringContainsString('# heading', $compiler->compile(['body' => [['type' => 'text', 'text' => '# heading']]]));
    }

    public function testLiteralFieldsRejectTemplateMarkers(): void
    {
        // Literal fields are emitted verbatim, so there is nothing to escape: reject.
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/x', 'fields' => [['name' => 'a', 'input' => 'text', 'label' => '## Name ##']]]]],
            'is a literal and may not contain "##"'
        );
    }

    public function testEmptyComponentDataCompilesToTheSameCallAsNoDataAtAll(): void
    {
        // An empty map has no entries to pass, so both spellings mean the same
        // thing. Emitting the array form for the empty case would produce
        // `component('c', [ , ])` — invalid PHP that still counted as a successful
        // compile and only failed once the page was rendered.
        $noArgument = "<?= \$this->component('c') ?>";

        self::assertSame($noArgument, $this->compile(['body' => [['type' => 'component', 'name' => 'c']]]));
        self::assertSame($noArgument, $this->compile(['body' => [['type' => 'component', 'name' => 'c', 'data' => []]]]));
        self::assertSame(
            "<?= \$this->component('c', [\n    'title' => 'T',\n]) ?>",
            $this->compile(['body' => [['type' => 'component', 'name' => 'c', 'data' => ['title' => 'T']]]])
        );
    }

    public function testCompileFileAndCompileToFileRoundTripThroughAFrontend(): void
    {
        // The base compiler has no source syntax of its own, so reading a file only
        // means something for a frontend — the stub below stands in for one.
        $dir = self::makeTempDir();

        try {
            $source = $dir . '/users.page.stub';
            file_put_contents($source, 'Hello');

            $frontend = new StubFrontend();
            self::assertSame('Hello', $frontend->compileFile($source));

            $target = $frontend->compileToFile($source);
            self::assertSame($dir . '/users.tpl.php', $target);
            self::assertSame('Hello', file_get_contents($target));
        } finally {
            self::removeDir($dir);
        }
    }

    public function testCompileToFileCreatesTheOutputDirectory(): void
    {
        $dir = self::makeTempDir();

        try {
            $source = $dir . '/users.page.stub';
            file_put_contents($source, 'Hello');

            $target = (new StubFrontend())->compileToFile($source, $dir . '/deep/nested');

            self::assertSame($dir . '/deep/nested/users.tpl.php', $target);
            self::assertFileExists($target);
            self::assertFileDoesNotExist($dir . '/users.tpl.php');
        } finally {
            self::removeDir($dir);
        }
    }

    public function testComponentDataValuesMustBeStrings(): void
    {
        // Values are interpolated into a PHP array literal, so a nested structure
        // has no representation there and is named instead of being cast.
        $this->expectError(
            ['body' => [['type' => 'component', 'name' => 'c', 'data' => ['title' => ['nested' => 'x']]]]],
            'must be a string (values support {{ path }} interpolation)'
        );
    }

    public function testValueWithUnbalancedInterpolationMarkersIsRejected(): void
    {
        $field = ['name' => 'a', 'label' => 'A', 'value' => '{{ x'];

        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [$field]]]],
            'has unbalanced interpolation markers'
        );
    }

    public function testCompileFileReportsAFileItCannotRead(): void
    {
        $dir = self::makeTempDir();
        $source = $dir . '/unreadable.page.stub';
        file_put_contents($source, 'Hello');
        chmod($source, 0o000);

        try {
            if (is_readable($source)) {
                self::markTestSkipped('file permissions are not enforced on this filesystem');
            }

            $this->expectException(CompileException::class);
            $this->expectExceptionMessage('cannot read page file');

            (new StubFrontend())->compileFile($source);
        } finally {
            chmod($source, 0o644);
            self::removeDir($dir);
        }
    }

    public function testCompileToFileReportsAnOutputDirectoryItCannotCreate(): void
    {
        $dir = self::makeTempDir();

        try {
            $source = $dir . '/a.page.stub';
            file_put_contents($source, 'Hello');
            file_put_contents($dir . '/blocker', 'not a directory');

            $this->expectException(CompileException::class);
            $this->expectExceptionMessage('cannot create output directory');

            // mkdir('blocker/nested') cannot succeed while 'blocker' is a file.
            (new StubFrontend())->compileToFile($source, $dir . '/blocker/nested');
        } finally {
            self::removeDir($dir);
        }
    }

    public function testCompileToFileReportsAFileItCannotWrite(): void
    {
        $dir = self::makeTempDir();

        try {
            $source = $dir . '/a.page.stub';
            file_put_contents($source, 'Hello');
            // A directory where the template should land: the write cannot succeed,
            // and reporting the target anyway would be a success that never happened.
            mkdir($dir . '/a.tpl.php');

            $this->expectException(CompileException::class);
            $this->expectExceptionMessage('cannot write page file');

            (new StubFrontend())->compileToFile($source);
        } finally {
            self::removeDir($dir);
        }
    }

    /* ---------------------------------------------------------------- *
     * Guard tests: one case per validation rule. Each of these was a
     * branch the suite never reached, so a rule could stop firing
     * without a test going red.
     * ---------------------------------------------------------------- */

    public function testPageRejectsAnUnknownField(): void
    {
        $this->expectError(['body' => [], 'titel' => 'T'], 'unknown field "titel"');
    }

    public function testPageRequiresSectionsWhenLayoutIsSet(): void
    {
        $this->expectError(['layout' => 'layout/main'], 'layout requires sections');
    }

    public function testPageRejectsSectionsWithoutLayout(): void
    {
        $this->expectError(['sections' => ['content' => []]], 'sections cannot be used without layout');
    }

    public function testPageRequiresEitherBodyOrLayout(): void
    {
        $this->expectError([], 'no page content');
    }

    public function testHeadingRejectsANonIntegerLevel(): void
    {
        // The out-of-range level is covered; this is the other half of the same
        // guard, and the one a mapping frontend hands over when a value was left
        // quoted.
        $this->expectError(
            ['body' => [['type' => 'heading', 'level' => '2', 'text' => 'T']]],
            'heading level must be an integer from 1 to 6'
        );
    }

    public function testEachRejectsVariableNamesThatAreNotIdentifiers(): void
    {
        $this->expectError(
            ['body' => [['type' => 'each', 'items' => 'users', 'as' => '1x', 'body' => []]]],
            'each as must be a valid variable name'
        );
        $this->expectError(
            ['body' => [['type' => 'each', 'items' => 'users', 'as' => 'u', 'index' => 'i-j', 'body' => []]]],
            'each index must be a valid variable name'
        );
    }

    public function testTableRejectsARowVariableThatIsNotAnIdentifier(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'as' => 'row list', 'columns' => [['label' => 'A', 'pop' => '{{ row.a }}']]]]],
            'table as must be a valid variable name'
        );
    }

    public function testFormRejectsAFieldThatIsNotANode(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => ['name']]]],
            'field must be an array'
        );
    }

    public function testFieldRejectsAnUnknownInputType(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'nope']]]]],
            'invalid input type "nope"'
        );
    }

    public function testSelectRequiresAnOptionsMap(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'select']]]]],
            'select field is missing an options map'
        );
    }

    public function testTextareaRejectsANonPositiveRowCount(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [['name' => 'a', 'label' => 'A', 'input' => 'textarea', 'rows' => 0]]]]],
            'textarea rows must be a positive integer'
        );
    }

    public function testCheckboxCarriesAnExplicitValue(): void
    {
        // The other half of the checkbox branch: value is a data path, so the box
        // submits the bound value rather than the browser's default "on".
        $out = $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
            ['name' => 'agree', 'label' => 'Agree', 'input' => 'checkbox', 'value' => 'agree.value'],
        ]]]]);

        $this->assertStringContainsString('value="## $agree[\'value\'] ?? \'\' ##"', $out);
    }

    public function testColumnMustBeANodeNotAScalar(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => ['A']]]],
            'column must be an array'
        );
    }

    public function testColumnRequiresALabel(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['pop' => '{{ row.a }}']]]]],
            'missing string field "label"'
        );
    }

    public function testBindMustHoldAJavaScriptName(): void
    {
        // The {{ }} refusal is covered above; these are the two other shapes a
        // wrong value arrives in, and both used to reach the tag unchecked.
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'bind' => 'user email', 'body' => []]]],
            'bind must hold a JS variable name or path'
        );
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'bind' => true, 'body' => []]]],
            'bind must hold a JS variable name or path'
        );
    }

    public function testBooleanAttributeValueIsNormalised(): void
    {
        // A mapping frontend parses true/false into booleans, so the attribute
        // that reaches the tag would otherwise be cast to "" or "1".
        $out = $this->compile(['body' => [
            ['type' => 'el', 'tag' => 'div', 'data-on' => true, 'body' => []],
            ['type' => 'el', 'tag' => 'div', 'data-on' => false, 'body' => []],
        ]]);

        $this->assertStringContainsString('data-on="true"', $out);
        $this->assertStringContainsString('data-on="false"', $out);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function compile(array $page): string
    {
        return $this->compiler->compile($page);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function expectError(array $page, string $needle): void
    {
        try {
            $this->compile($page);
            $this->fail('should have failed to compile: ' . $needle);
        } catch (CompileException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    private static function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/migears-pages-' . bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $dir;
    }

    private static function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }

        rmdir($dir);
    }
}

/**
 * A stand-in frontend. The base compiler has no source syntax of its own, so
 * compileFile() / compileToFile() are only meaningful for a subclass — this one
 * reads the file's trimmed text as the page's only node.
 */
final class StubFrontend extends Compiler
{
    protected function parse(string $source): array
    {
        return ['body' => [['type' => 'text', 'text' => trim($source)]]];
    }
}
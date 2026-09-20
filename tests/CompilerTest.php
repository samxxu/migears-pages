<?php

declare(strict_types=1);

namespace MiGears\Pages\Tests;

use MiGears\Pages\Compiler;
use MiGears\Pages\Exception\CompileException;
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
        $this->assertSame('你好', $this->compile(['body' => [['type' => 'text', 'text' => '你好']]]));
        $this->assertSame(
            "你好，## \$user['name'] ?? '' ##",
            $this->compile(['body' => [['type' => 'text', 'text' => '你好，{{ user.name }}']]])
        );
    }

    public function testHeadingLevelDefaultsToOneAndIsValidated(): void
    {
        $this->assertSame('<h1>标题</h1>', $this->compile(['body' => [['type' => 'heading', 'text' => '标题']]]));
        $this->assertSame('<h2>标题</h2>', $this->compile(['body' => [['type' => 'heading', 'level' => 2, 'text' => '标题']]]));
        $this->expectError(
            ['body' => [['type' => 'heading', 'level' => 7, 'text' => '标题']]],
            'level 必须是 1-6 的整数'
        );
    }

    public function testLinkInterpolatesHrefAndText(): void
    {
        $this->assertSame(
            '<a href="/x/## $user[\'id\'] ?? \'\' ##">去</a>',
            $this->compile(['body' => [['type' => 'link', 'href' => '/x/{{ user.id }}', 'text' => '去']]])
        );
        $this->assertSame(
            '<a href="/x" target="_blank">去</a>',
            $this->compile(['body' => [['type' => 'link', 'href' => '/x', 'text' => '去', 'target' => '_blank']]])
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
            '未知属性 "shwo"'
        );
    }

    public function testNonScalarAttributeValueIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'el', 'tag' => 'div', 'data-x' => ['nested' => 1], 'body' => []]]],
            '值必须是标量'
        );
    }

    public function testAttributeOnNodeWithoutTagIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'if', 'when' => 'a', 'class' => 'x', 'then' => [['type' => 'text', 'text' => 'A']]]]],
            '节点 type: if 不输出标签'
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

    public function testTableColumnsBindAndContent(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'table',
            'items' => 'users',
            'columns' => [
                ['label' => 'ID', 'bind' => 'id'],
                ['label' => '操作', 'content' => [['type' => 'link', 'href' => '/u/{{ row.id }}', 'text' => '改']]],
            ],
        ]]]);

        $this->assertStringContainsString('<th>ID</th><th>操作</th>', $out);
        $this->assertStringContainsString("<td>## \$row['id'] ?? '' ##</td>", $out);
        $this->assertStringContainsString('<a href="/u/## $row[\'id\'] ?? \'\' ##">改</a>', $out);
        $this->assertStringContainsString('foreach ($users ?? [] as $row)', $out);
    }

    public function testTableEmptyBranchRendersColspan(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'table',
            'items' => 'users',
            'empty' => '暂无数据',
            'columns' => [['label' => 'ID', 'bind' => 'id']],
        ]]]);

        $this->assertStringContainsString('if (($users ?? []) === [])', $out);
        $this->assertStringContainsString('<td colspan="1">暂无数据</td>', $out);
    }

    public function testColumnNeedsExactlyOneOfBindOrContent(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A']]]]],
            '列缺少 bind 或 content'
        );
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'bind' => 'a', 'content' => []]]]]],
            '列同时指定 bind 与 content'
        );
    }

    public function testColumnContentMustBeNodeTree(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'columns' => [['label' => 'A', 'content' => '裸文本']]]]],
            'content: 必须是节点树数组'
        );
    }

    public function testFormRendersLabelPerInputType(): void
    {
        $out = $this->compile(['body' => [[
            'type' => 'form',
            'action' => '/save',
            'fields' => [
                ['name' => 'n', 'label' => '姓名', 'input' => 'text', 'value' => 'user.name', 'required' => true],
                ['name' => 'p', 'label' => '密码', 'input' => 'password'],
                ['name' => 'b', 'label' => '简介', 'input' => 'textarea'],
                ['name' => 's', 'label' => '角色', 'input' => 'select', 'options' => ['a' => '管理员']],
                ['name' => 'c', 'label' => '启用', 'input' => 'checkbox', 'checked' => 'user.active'],
                ['name' => 't', 'label' => 'TOKEN', 'input' => 'hidden', 'value' => 'form.csrf'],
                ['name' => 'go', 'label' => '保存', 'input' => 'submit'],
            ],
        ]]]);

        $this->assertStringContainsString('<form action="/save" method="post">', $out);
        $this->assertStringContainsString('value="## $user[\'name\'] ?? \'\' ##" required>', $out);
        $this->assertStringContainsString('<textarea name="b" id="b" rows="4">', $out);
        $this->assertStringContainsString('<option value="a">管理员</option>', $out);
        $this->assertStringContainsString("<?= (\$user['active'] ?? null) ? ' checked' : '' ?>", $out);
        // hidden has no label; submit carries its label as the button text
        $this->assertStringNotContainsString('<label for="t">', $out);
        $this->assertStringContainsString('<input type="submit" value="保存">', $out);
    }

    public function testFormMethodDefaultsToPostAndRejectsBadValues(): void
    {
        $out = $this->compile(['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
            ['name' => 'a', 'label' => 'A'],
        ]]]]);
        $this->assertStringContainsString('method="post"', $out);

        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'method' => 'put', 'fields' => [['name' => 'a', 'label' => 'A']]]]],
            'method 必须是 "get" 或 "post"'
        );
    }

    public function testSelectRejectsValueBindingAndNonSelectRejectsOptions(): void
    {
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 's', 'label' => 'S', 'input' => 'select', 'value' => 'a', 'options' => ['x' => 'X']],
            ]]]],
            'select 字段不支持 value 绑定'
        );
        $this->expectError(
            ['body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                ['name' => 's', 'label' => 'S', 'options' => ['x' => 'X']],
            ]]]],
            'options 仅用于 select 字段'
        );
    }

    public function testLayoutPageCollectsTitleAndSections(): void
    {
        $out = $this->compile([
            'layout' => 'layout/main',
            'title' => '用户管理',
            'sections' => ['content' => [['type' => 'text', 'text' => '主体']]],
        ]);

        $this->assertStringContainsString("\$this->extends('layout/main')", $out);
        $this->assertStringContainsString("\$this->start('title')", $out);
        $this->assertStringContainsString('用户管理', $out);
        $this->assertStringContainsString("\$this->start('content')", $out);
        $this->assertStringContainsString('主体', $out);
    }

    public function testStandalonePageIgnoresTitleAndWarns(): void
    {
        $warnings = [];
        $compiler = new Compiler(function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $out = $compiler->compile(['title' => '忽略', 'body' => [['type' => 'text', 'text' => '正文']]]);

        $this->assertSame('正文', $out);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('title', $warnings[0]);
    }

    public function testLayoutAndBodyAreMutuallyExclusive(): void
    {
        $this->expectError(
            ['layout' => 'layout/main', 'body' => [['type' => 'text', 'text' => 'A']]],
            '同时指定 layout 与 body 冲突'
        );
    }

    public function testInterpolationEdgeCases(): void
    {
        $this->assertSame(
            '前 ## $a ?? \'\' ## 后',
            $this->compile(['body' => [['type' => 'text', 'text' => '前 {{ a }} 后']]])
        );
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{{ a }}}']]],
            '插值符号不能连续三个花括号'
        );
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{ a }} }}']]],
            '插值符号未配对'
        );
    }

    public function testLiteralFieldsRejectInterpolation(): void
    {
        $this->expectError(
            ['body' => [['type' => 'table', 'items' => 'u', 'empty' => '{{ a }}', 'columns' => [['label' => 'A', 'bind' => 'a']]]]],
            '不支持 {{ }} 插值'
        );
    }

    public function testUnknownNodeTypeIsRejected(): void
    {
        $this->expectError(
            ['body' => [['type' => 'sectoin', 'text' => 'x']]],
            '未知节点类型 "sectoin"'
        );
    }

    public function testNodeMustBeArrayAndCarryType(): void
    {
        $this->expectError(['body' => ['裸文本']], '节点必须是对象');
        $this->expectError(['body' => [['text' => 'x']]], '节点缺少 type 字段');
    }

    public function testElRequiresTagAndBody(): void
    {
        $this->expectError(['body' => [['type' => 'el']]], '缺少 string 字段 "tag"');
        // an <el> may wrap nothing: it exists to carry attributes
        $this->assertSame('<div></div>', $this->compile(['body' => [['type' => 'el', 'tag' => 'div']]]));
        $this->expectError(['body' => [['type' => 'el', 'tag' => 'div', 'body' => 'x']]], 'body 必须是节点树数组');
        $this->expectError(['body' => [['type' => 'el', 'tag' => '1div', 'body' => []]]], '非法的 tag');
        // uppercase is normalised, not rejected
        $this->assertSame('<div></div>', $this->compile(['body' => [['type' => 'el', 'tag' => 'DIV', 'body' => []]]]));
    }

    public function testPathMustBePlainVariablePath(): void
    {
        $this->expectError(
            ['body' => [['type' => 'text', 'text' => '{{ a[b] }}']]],
            '非法路径'
        );
    }

    public function testSourceEntryPointsRejectTextOnTheBaseCompiler(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('只接受数组页面定义');
        $this->compiler->compileSource('<page/>');
    }

    public function testCompileFileRejectsMissingFile(): void
    {
        $this->expectException(CompileException::class);
        $this->expectExceptionMessage('页面文件不存在');
        $this->compiler->compileFile('/nonexistent/page.xml');
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
            $this->fail('应当编译失败: ' . $needle);
        } catch (CompileException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}

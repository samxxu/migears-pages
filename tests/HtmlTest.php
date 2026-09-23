<?php

declare(strict_types=1);

namespace MiGears\Pages\Tests;

use MiGears\Pages\Compiler;
use MiGears\Pages\Exception\CompileException;
use MiGears\Pages\Html as h5;
use MiGears\Pages\Node;
use MiGears\Pages\Renderer;
use MiGears\Template\Template;
use PHPUnit\Framework\TestCase;

/**
 * The user-level syntax is sugar over the array model. Every case below asserts both
 * halves of that claim: the node normalizes to the documented array, and a page written
 * with the factory compiles to byte-identical output as the same page written as arrays.
 */
final class HtmlTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/migears_pages_html_' . getmypid();
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->cacheDir)) {
            return;
        }
        foreach (glob($this->cacheDir . '/*') ?: [] as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        rmdir($this->cacheDir);
    }

    public function testEveryFactoryNormalizesToTheDocumentedArray(): void
    {
        $cases = [
            'text' => [h5::TEXT('你好，{{ user.name }}'), ['type' => 'text', 'text' => '你好，{{ user.name }}']],
            'heading' => [h5::HEADING(2)->text('用户列表'), ['type' => 'heading', 'level' => 2, 'text' => '用户列表']],
            'heading default level' => [h5::HEADING()->text('标题'), ['type' => 'heading', 'level' => 1, 'text' => '标题']],
            'heading with attributes' => [
                h5::HEADING(2)->text('用户列表')->id('usersTitle')->class('page-title'),
                ['type' => 'heading', 'level' => 2, 'text' => '用户列表', 'id' => 'usersTitle', 'class' => 'page-title'],
            ],
            'link' => [
                h5::LINK('/users/1')->text('详情')->target('_blank'),
                ['type' => 'link', 'href' => '/users/1', 'text' => '详情', 'target' => '_blank'],
            ],
            'if' => [
                h5::IF('user.loggedIn')->THEN([h5::TEXT('欢迎回来')]),
                ['type' => 'if', 'when' => 'user.loggedIn', 'then' => [['type' => 'text', 'text' => '欢迎回来']]],
            ],
            'if else' => [
                h5::IF('!user.hidden')->THEN([h5::TEXT('可见')])->ELSE([h5::TEXT('已隐藏')]),
                ['type' => 'if', 'when' => '!user.hidden', 'then' => [['type' => 'text', 'text' => '可见']], 'else' => [['type' => 'text', 'text' => '已隐藏']]],
            ],
            'each' => [
                h5::EACH('users')->body([h5::TEXT('{{ item.name }}')])->as('user')->index('i'),
                ['type' => 'each', 'items' => 'users', 'body' => [['type' => 'text', 'text' => '{{ item.name }}']], 'as' => 'user', 'index' => 'i'],
            ],
            'form' => [
                h5::FORM('/users/save')->fields([h5::INPUT('name')->label('姓名')])->method('post'),
                ['type' => 'form', 'action' => '/users/save', 'fields' => [['type' => 'field', 'name' => 'name', 'input' => 'text', 'label' => '姓名']], 'method' => 'post'],
            ],
            'input' => [
                h5::INPUT('name')->label('姓名')->value('user.name')->required(),
                ['type' => 'field', 'name' => 'name', 'input' => 'text', 'label' => '姓名', 'value' => 'user.name', 'required' => true],
            ],
            'input with type' => [
                h5::INPUT('email')->label('邮箱')->type('email')->placeholder('name@example.com'),
                ['type' => 'field', 'name' => 'email', 'input' => 'email', 'label' => '邮箱', 'placeholder' => 'name@example.com'],
            ],
            'input checkbox' => [
                h5::INPUT('active')->label('启用')->type('checkbox')->checked('user.active'),
                ['type' => 'field', 'name' => 'active', 'input' => 'checkbox', 'label' => '启用', 'checked' => 'user.active'],
            ],
            'textarea' => [
                h5::TEXTAREA('bio')->label('简介')->rows(5),
                ['type' => 'field', 'name' => 'bio', 'input' => 'textarea', 'label' => '简介', 'rows' => 5],
            ],
            'select' => [
                h5::SELECT('role')->label('角色')->options(['admin' => '管理员']),
                ['type' => 'field', 'name' => 'role', 'input' => 'select', 'label' => '角色', 'options' => ['admin' => '管理员']],
            ],
            'table' => [
                h5::TABLE('users')->columns([
                    h5::COL('ID')->pop('{{ row.id }}'),
                    h5::COL('操作')->content([h5::LINK('/u/{{ row.id }}')->text('编辑')]),
                ])->empty('暂无数据'),
                ['type' => 'table', 'items' => 'users', 'columns' => [
                    ['type' => 'column', 'label' => 'ID', 'pop' => '{{ row.id }}'],
                    ['type' => 'column', 'label' => '操作', 'content' => [['type' => 'link', 'href' => '/u/{{ row.id }}', 'text' => '编辑']]],
                ], 'empty' => '暂无数据'],
            ],
            'component' => [
                h5::COMPONENT('card')->data(['title' => '{{ user.name }}']),
                ['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}']],
            ],
            'el' => [
                h5::EL('div')->class('card')->body([h5::TEXT('正文')]),
                ['type' => 'el', 'tag' => 'div', 'class' => 'card', 'body' => [['type' => 'text', 'text' => '正文']]],
            ],
            'el with directives' => [
                h5::EL('button')->attr('x-data', '{ open: false }')->on('click', 'open = !open')->body([h5::TEXT('切换')]),
                ['type' => 'el', 'tag' => 'button', 'x-data' => '{ open: false }', '@click' => 'open = !open', 'body' => [['type' => 'text', 'text' => '切换']]],
            ],
            'bind (browser side)' => [
                h5::EL('span')->bind('user.email')->body([]),
                ['type' => 'el', 'tag' => 'span', 'bind' => 'user.email', 'body' => []],
            ],
            'pop and bind together' => [
                h5::INPUT('email')->label('邮箱')->popAndBind('{{ user.email }}'),
                ['type' => 'field', 'name' => 'email', 'input' => 'text', 'label' => '邮箱', 'value' => '{{ user.email }}', 'bind' => 'user.email'],
            ],
            'popAndBind with a framework spelling' => [
                h5::INPUT('email')->label('邮箱')->popAndBind('{{ user.email }}', 'x-model'),
                ['type' => 'field', 'name' => 'email', 'input' => 'text', 'label' => '邮箱', 'value' => '{{ user.email }}', 'x-model' => 'user.email'],
            ],
        ];

        $compiler = new Compiler();

        foreach ($cases as $name => [$node, $expected]) {
            self::assertSame($expected, $node->toArray(), "[{$name}] 归一后的数组与文档不符");
            self::assertSame(
                $compiler->compile($this->pageFor($expected)),
                $compiler->compile($this->pageFor($node)),
                "[{$name}] 经工厂编译的产物与手写数组不一致"
            );
        }
    }

    /**
     * Wrap a node in the container it belongs to: fields live in forms, columns in
     * tables, everything else directly in the body.
     *
     * @param array<string, mixed>|Node $node
     *
     * @return array<string, mixed>
     */
    private function pageFor(array|Node $node): array
    {
        $type = $node instanceof Node ? $node->toArray()['type'] : $node['type'];

        return match ($type) {
            'field' => ['body' => [['type' => 'form', 'action' => '/x', 'fields' => [$node]]]],
            'column' => ['body' => [['type' => 'table', 'items' => 'rows', 'columns' => [$node]]]],
            default => ['body' => [$node]],
        };
    }

    public function testNestedNodesNormalizeThroughEveryLevel(): void
    {
        $compiler = new Compiler();

        $page = [
            'layout' => 'layout/main',
            'sections' => [
                'content' => [
                    h5::EACH('users')->body([
                        h5::EL('li')->class('item')->body([h5::TEXT('{{ user.name }}')]),
                    ]),
                ],
            ],
        ];

        $sameAsArrays = [
            'layout' => 'layout/main',
            'sections' => [
                'content' => [
                    ['type' => 'each', 'items' => 'users', 'body' => [
                        ['type' => 'el', 'tag' => 'li', 'class' => 'item', 'body' => [['type' => 'text', 'text' => '{{ user.name }}']]],
                    ]],
                ],
            ],
        ];

        self::assertSame($compiler->compile($sameAsArrays), $compiler->compile($page));
    }

    public function testRendererAcceptsFactoryNodes(): void
    {
        $renderer = new Renderer(
            new Template(__DIR__ . '/fixtures/views'),
            new Compiler(),
            $this->cacheDir
        );

        $html = $renderer->render([
            'body' => [
                h5::HEADING(2)->text('用户列表'),
                h5::TEXT('你好，{{ user.name }}'),
            ],
        ], ['user' => ['name' => 'Alice']]);

        self::assertSame("<h2>用户列表</h2>\n你好，Alice", $html);
    }

    public function testValidationStillComesFromTheCompiler(): void
    {
        $compiler = new Compiler();

        // The factory checks nothing about the node vocabulary: an unknown attribute and
        // an out-of-range level both fail in the compiler, with the node path attached.
        foreach ([
            'unknown attribute' => [h5::HEADING(2)->text('x')->attr('levl', 2), '未知属性 "levl"'],
            'level out of range' => [h5::HEADING(7)->text('x'), 'level'],
            'missing required field' => [h5::HEADING(2), 'text'],
        ] as $name => [$node, $needle]) {
            try {
                $compiler->compile(['body' => [$node]]);
                self::fail("[{$name}] 应抛出 CompileException");
            } catch (CompileException $e) {
                self::assertStringContainsString($needle, $e->getMessage(), "[{$name}] 错误信息不符");
                self::assertStringContainsString('body[0]', $e->getMessage(), "[{$name}] 错误信息应带节点路径");
            }
        }
    }

    public function testPopAndBindShortcutWritesBothHalves(): void
    {
        // pop is the server half, bind the browser half; the shortcut is for the usual
        // case where both name the same data.
        $html = (new Renderer(new Template(__DIR__ . '/fixtures/views'), new Compiler(), $this->cacheDir))
            ->render([
                'body' => [['type' => 'form', 'action' => '/s', 'fields' => [
                    h5::INPUT('email')->label('邮箱')->popAndBind('{{ user.email }}'),
                ]]],
            ], ['user' => ['email' => 'a@b.c']]);

        self::assertStringContainsString('value="a@b.c"', $html);
        self::assertStringContainsString('bind="user.email"', $html);
    }

    public function testSettingTheSameFieldTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('字段重复设置: text');

        h5::HEADING(2)->text('a')->text('b');
    }

    public function testSettingTheSameAttributeTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('字段重复设置: class');

        h5::EL('div')->class('a')->class('b');
    }

    public function testSettingTheControlTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('控件重复设置: 已经是 email');

        h5::INPUT('email')->type('email')->type('text');
    }

    public function testTextareaAndSelectHaveNoTypeMethod(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('type');

        /** @phpstan-ignore-next-line — the missing method is the point */
        h5::TEXTAREA('bio')->type('email');
    }

    public function testNodeWithoutATagHasNoAttributeMethods(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('class');

        /** @phpstan-ignore-next-line — the missing method is the point */
        h5::TEXT('x')->class('a');
    }
}

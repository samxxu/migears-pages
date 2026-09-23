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
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

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
            'text' => [h5::TEXT('Hello, {{ user.name }}'), ['type' => 'text', 'text' => 'Hello, {{ user.name }}']],
            'heading' => [h5::HEADING(2)->text('User list'), ['type' => 'heading', 'level' => 2, 'text' => 'User list']],
            'heading default level' => [h5::HEADING()->text('Title'), ['type' => 'heading', 'level' => 1, 'text' => 'Title']],
            'heading with attributes' => [
                h5::HEADING(2)->text('User list')->id('usersTitle')->class('page-title'),
                ['type' => 'heading', 'level' => 2, 'text' => 'User list', 'id' => 'usersTitle', 'class' => 'page-title'],
            ],
            'link' => [
                h5::LINK('/users/1')->text('Details')->target('_blank'),
                ['type' => 'link', 'href' => '/users/1', 'text' => 'Details', 'target' => '_blank'],
            ],
            'if' => [
                h5::IF('user.loggedIn')->THEN([h5::TEXT('Welcome back')]),
                ['type' => 'if', 'when' => 'user.loggedIn', 'then' => [['type' => 'text', 'text' => 'Welcome back']]],
            ],
            'if else' => [
                h5::IF('!user.hidden')->THEN([h5::TEXT('Visible')])->ELSE([h5::TEXT('Hidden')]),
                ['type' => 'if', 'when' => '!user.hidden', 'then' => [['type' => 'text', 'text' => 'Visible']], 'else' => [['type' => 'text', 'text' => 'Hidden']]],
            ],
            'each' => [
                h5::EACH('users')->BODY([h5::TEXT('{{ item.name }}')])->AS('user')->INDEX('i'),
                ['type' => 'each', 'items' => 'users', 'body' => [['type' => 'text', 'text' => '{{ item.name }}']], 'as' => 'user', 'index' => 'i'],
            ],
            'each loop header in the constructor' => [
                h5::EACH('users', as: 'user', index: 'i')->BODY([h5::TEXT('{{ i }}. {{ user.name }}')]),
                ['type' => 'each', 'items' => 'users', 'as' => 'user', 'index' => 'i', 'body' => [['type' => 'text', 'text' => '{{ i }}. {{ user.name }}']]],
            ],
            'form' => [
                h5::FORM('/users/save')->fields([h5::INPUT('name')->label('Name')])->method('post'),
                ['type' => 'form', 'action' => '/users/save', 'fields' => [['type' => 'field', 'name' => 'name', 'input' => 'text', 'label' => 'Name']], 'method' => 'post'],
            ],
            'input' => [
                h5::INPUT('name')->label('Name')->value('user.name')->required(),
                ['type' => 'field', 'name' => 'name', 'input' => 'text', 'label' => 'Name', 'value' => 'user.name', 'required' => true],
            ],
            'input with type' => [
                h5::INPUT('email')->label('Email')->type('email')->placeholder('name@example.com'),
                ['type' => 'field', 'name' => 'email', 'input' => 'email', 'label' => 'Email', 'placeholder' => 'name@example.com'],
            ],
            'input checkbox' => [
                h5::INPUT('active')->label('Enabled')->type('checkbox')->checked('user.active'),
                ['type' => 'field', 'name' => 'active', 'input' => 'checkbox', 'label' => 'Enabled', 'checked' => 'user.active'],
            ],
            'textarea' => [
                h5::TEXTAREA('bio')->label('Bio')->rows(5),
                ['type' => 'field', 'name' => 'bio', 'input' => 'textarea', 'label' => 'Bio', 'rows' => 5],
            ],
            'select' => [
                h5::SELECT('role')->label('Role')->options(['admin' => 'Admin']),
                ['type' => 'field', 'name' => 'role', 'input' => 'select', 'label' => 'Role', 'options' => ['admin' => 'Admin']],
            ],
            'table' => [
                h5::TABLE('users')->columns([
                    h5::COL('ID')->pop('{{ row.id }}'),
                    h5::COL('Actions')->content([h5::LINK('/u/{{ row.id }}')->text('Edit')]),
                ])->empty('No data'),
                ['type' => 'table', 'items' => 'users', 'columns' => [
                    ['type' => 'column', 'label' => 'ID', 'pop' => '{{ row.id }}'],
                    ['type' => 'column', 'label' => 'Actions', 'content' => [['type' => 'link', 'href' => '/u/{{ row.id }}', 'text' => 'Edit']]],
                ], 'empty' => 'No data'],
            ],
            'table row variable in the constructor' => [
                h5::TABLE('users', as: 'user')->columns([h5::COL('Name')->pop('{{ user.name }}')]),
                ['type' => 'table', 'items' => 'users', 'as' => 'user', 'columns' => [
                    ['type' => 'column', 'label' => 'Name', 'pop' => '{{ user.name }}'],
                ]],
            ],
            'component' => [
                h5::COMPONENT('card')->data(['title' => '{{ user.name }}']),
                ['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}']],
            ],
            'el' => [
                h5::EL('div')->class('card')->BODY([h5::TEXT('Content')]),
                ['type' => 'el', 'tag' => 'div', 'class' => 'card', 'body' => [['type' => 'text', 'text' => 'Content']]],
            ],
            'el with directives' => [
                h5::EL('button')->attr('x-data', '{ open: false }')->on('click', 'open = !open')->BODY([h5::TEXT('Toggle')]),
                ['type' => 'el', 'tag' => 'button', 'x-data' => '{ open: false }', '@click' => 'open = !open', 'body' => [['type' => 'text', 'text' => 'Toggle']]],
            ],
            'bind (browser side)' => [
                h5::EL('span')->bind('user.email')->BODY([]),
                ['type' => 'el', 'tag' => 'span', 'bind' => 'user.email', 'body' => []],
            ],
            'pop and bind together' => [
                h5::INPUT('email')->label('Email')->popAndBind('{{ user.email }}'),
                ['type' => 'field', 'name' => 'email', 'input' => 'text', 'label' => 'Email', 'value' => '{{ user.email }}', 'bind' => 'user.email'],
            ],
            'popAndBind with a framework spelling' => [
                h5::INPUT('email')->label('Email')->popAndBind('{{ user.email }}', 'x-model'),
                ['type' => 'field', 'name' => 'email', 'input' => 'text', 'label' => 'Email', 'value' => '{{ user.email }}', 'x-model' => 'user.email'],
            ],
        ];

        $compiler = new Compiler();

        foreach ($cases as $name => [$node, $expected]) {
            self::assertSame($expected, $node->toArray(), "[{$name}] normalized array differs from the documented shape");
            self::assertSame(
                $compiler->compile($this->pageFor($expected)),
                $compiler->compile($this->pageFor($node)),
                "[{$name}] factory output differs from hand-written array output"
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
                    h5::EACH('users')->BODY([
                        h5::EL('li')->class('item')->BODY([h5::TEXT('{{ user.name }}')]),
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
                h5::HEADING(2)->text('User list'),
                h5::TEXT('Hello, {{ user.name }}'),
            ],
        ], ['user' => ['name' => 'Alice']]);

        self::assertSame("<h2>User list</h2>\nHello, Alice", $html);
    }

    public function testValidationStillComesFromTheCompiler(): void
    {
        $compiler = new Compiler();

        // The factory checks nothing about the node vocabulary: an unknown attribute and
        // an out-of-range level both fail in the compiler, with the node path attached.
        foreach ([
            'unknown attribute' => [h5::HEADING(2)->text('x')->attr('levl', 2), 'unknown attribute "levl"'],
            'level out of range' => [h5::HEADING(7)->text('x'), 'level'],
            'missing required field' => [h5::HEADING(2), 'text'],
        ] as $name => [$node, $needle]) {
            try {
                $compiler->compile(['body' => [$node]]);
                self::fail("[{$name}] expected CompileException to be thrown");
            } catch (CompileException $e) {
                self::assertStringContainsString($needle, $e->getMessage(), "[{$name}] error message mismatch");
                self::assertStringContainsString('body[0]', $e->getMessage(), "[{$name}] error message should include the node path");
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
                    h5::INPUT('email')->label('Email')->popAndBind('{{ user.email }}'),
                ]]],
            ], ['user' => ['email' => 'a@b.c']]);

        self::assertStringContainsString('value="a@b.c"', $html);
        self::assertStringContainsString('bind="user.email"', $html);
    }

    public function testSettingTheSameFieldTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field already set: text');

        h5::HEADING(2)->text('a')->text('b');
    }

    public function testSettingTheSameAttributeTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field already set: class');

        h5::EL('div')->class('a')->class('b');
    }

    public function testSettingTheControlTwiceThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('control already set: email');

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

    public function testOmittingTheLoopHeaderWritesNoField(): void
    {
        // An omitted header argument means the compiler's own default, not a value the
        // factory copies in — which is exactly what keeps the method spelling usable.
        self::assertSame(['type' => 'each', 'items' => 'users'], h5::EACH('users')->toArray());
        self::assertSame(
            ['type' => 'each', 'items' => 'users', 'as' => 'user'],
            h5::EACH('users', as: 'user')->toArray()
        );
        self::assertSame(['type' => 'table', 'items' => 'users'], h5::TABLE('users')->toArray());
    }

    public function testTheLoopHeaderMayBeAnArgumentOrAMethodButNotBoth(): void
    {
        self::assertSame(
            h5::EACH('users', as: 'user', index: 'i')->toArray(),
            h5::EACH('users')->AS('user')->INDEX('i')->toArray()
        );
        self::assertSame(
            h5::TABLE('users', as: 'user')->toArray(),
            h5::TABLE('users')->AS('user')->toArray()
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('field already set: as');

        h5::EACH('users', as: 'user')->AS('member');
    }

    public function testIterationFactoriesTakeTheLoopHeaderOthersTakeOneArgument(): void
    {
        $loopHeader = ['EACH' => ['items', 'as', 'index'], 'TABLE' => ['items', 'as']];

        foreach ((new ReflectionClass(h5::class))->getMethods(ReflectionMethod::IS_STATIC) as $method) {
            $parameters = $method->getParameters();
            $names = array_map(static fn (ReflectionParameter $p): string => $p->getName(), $parameters);

            if (! isset($loopHeader[$method->getName()])) {
                self::assertCount(1, $names, $method->getName() . ' accepts exactly one argument');

                continue;
            }

            self::assertSame($loopHeader[$method->getName()], $names);
            // required first, header after it: nothing to count, and no named argument
            // can ever end up in front of a positional one
            self::assertFalse($parameters[0]->isOptional(), $method->getName() . ' first argument must be required');
            foreach (array_slice($parameters, 1) as $optional) {
                self::assertTrue($optional->isOptional(), $method->getName() . ' loop-header argument must be optional');
            }
        }
    }
}

# migears/pages

![Version](https://img.shields.io/badge/version-2.0.0-blue)

Declarative page definitions as plain PHP arrays, compiled to miGears Template files (`.tpl.php`). The array form is the canonical DSL: `migears/xml-pages` and `migears/yaml-pages` are thin syntax frontends that parse their own format into exactly this array model, and the whole node vocabulary, validation and interpolation live here — once, shared.

## Features

- PHP 8.1+, PSR-4 autoloading, namespace `MiGears\Pages`
- Node model: `text`, `heading`, `link`, `if`, `each`, `form`, `table`, `el`, `component` — the full page vocabulary in one place
- `{{ path }}` interpolation with auto-escaping — XSS protection inherited from the template engine
- Compile-time validation of structure, fields, paths and keys — nothing is silently dropped
- **Attribute passthrough** for front-end frameworks: `"@click"`, `x-on:click`, `v-bind:href`, `wire:click`, `hx-get`, `data-*`, `class`/`id`/`style` are forwarded to the emitted tag
- `Renderer` facade: one call from array DSL to HTML
- Format frontends (`migears/xml-pages`, `migears/yaml-pages`) inherit the compiler and only implement `parse()` plus a couple of spelling hooks
- Deliberately out of scope: business logic, event handling, state management, routing — those belong to the front-end framework you pair it with

## How It Works

The page declaration is a PHP array — the single source of truth. Everything else is derived:

1. `Compiler::compile($page)` turns the array into `.tpl.php` sugar syntax (`## $expr ##`). The intermediate output stays readable, so each DSL keyword maps visibly to template syntax.
2. `migears/template`'s `TemplateCompiler` turns that sugar into a pure PHP template (mtime-cached, recompiled only when the template changes). Rendering is plain PHP: the template runs and its variables are output to the browser as HTML. The declaration layer never enters runtime.

The generated `.tpl.php` file is a derived artifact — re-running the compiler overwrites it. Edit the declaration, never the output.

The XML and YAML packages are frontends over this same compiler: they parse their source into the array model (`Compiler::parse()`), then everything downstream — node compilation, validation, interpolation, attribute forwarding — is inherited. Different surface syntax, one compiler, one node model.

## Installation

```bash
composer require migears/pages
```

Requires PHP 8.1+ and `migears/template` ^2.0. No extensions, no third-party packages.

## Quick Start

```php
use MiGears\Pages\Compiler;
use MiGears\Template\Template;

$compiler = new Compiler();
$tpl = $compiler->compile([
    'title' => '用户管理',
    'layout' => 'layout/main',
    'sections' => [
        'content' => [
            ['type' => 'heading', 'level' => 2, 'text' => '用户列表'],
            ['type' => 'table', 'items' => 'users', 'as' => 'user', 'empty' => '暂无数据', 'columns' => [
                ['label' => 'ID', 'bind' => 'id'],
                ['label' => '姓名', 'bind' => 'name'],
            ]],
        ],
    ],
]);

file_put_contents('views/pages/users.tpl.php', $tpl);
```

Render it like any other template:

```php
$view = new Template(__DIR__ . '/views');
echo $view->render('pages/users', [
    'users' => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
]);
```

Or skip the file entirely with the `Renderer` facade:

```php
use MiGears\Pages\Renderer;

$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render($page, ['users' => [...]]);
```

## Array DSL Reference

The page is a PHP array. The root has the fields `title` / `layout` / `body` / `sections`; `layout` + `sections` and `body` are mutually exclusive. Every node in `body` / `sections` is an array with a `type` key. Nested structures (`field`, `column`) are typed by their position — they need no `type`, and a written one must match.

| Node | Fields |
|------|--------|
| `text` | `text` required |
| `heading` | `text` required, `level` 1–6 (default 1) |
| `link` | `href`, `text` required; `target` optional |
| `if` | `when` required (optional `!` prefix for negation), `then` required node tree, `else` optional |
| `each` | `items` required, `as` (default `item`), `index`, `body` required |
| `form` | `action` required, `method` (default `post`), `fields` required |
| `table` | `items` required, `as` (default `row`), `columns` required, `empty` optional |
| `el` | `tag` required (lowercase), `body` optional, any forwarded attribute |
| `component` | `name` required, `data` optional (values support `{{ path }}`) |

`field` inputs: `text` (default), `password`, `email`, `number`, `textarea`, `select`, `checkbox`, `hidden`, `submit`. `select` fields take an `options` mapping and reject `value`; `checkbox` takes `checked` (bound path); `textarea` takes `rows` (default 4). `column` needs `label` and exactly one of `bind` (path relative to the row variable) or `content` (node tree in row scope).

`{{ path }}` interpolates a dot path into an auto-escaped output (`{{ user.name }}` → `## $user['name'] ?? '' ##`). Only `a.b.c` paths are allowed — no function calls, no arithmetic. Literal fields — `layout`, section names, `form.method`, `field.name`, `field.label`, `option` value and text, `table.empty`, `column.label`, `component.name` — are emitted as-is; `{{ }}` there is a compile error.

Keys on a node fall into three groups: DSL fields (consumed by the node), forwarded attributes (emitted on the tag: `"@event"`, names containing a colon like `x-on:click`, prefixes `x-` / `v-` / `hx-` / `data-`, and `class` / `id` / `style`), and everything else — a compile error, treated as a typo and never dropped silently.

The full node grammar, interpolation rules and error catalogue are specified in `spec.md`.

## Front-end Packages

`migears/xml-pages` and `migears/yaml-pages` provide the same DSL in their own syntax. Use them when a text format is easier to author or to have AI generate:

- `migears/yaml-pages` — `.page.yaml` declarations, parsed with PECL `ext-yaml`
- `migears/xml-pages` — `.page.xml` declarations, parsed with SimpleXML

Both compile through this package, so behaviour is identical: same node vocabulary, same validation, same output. Their CLI binaries (`bin/yaml-pages`, `bin/xml-pages`) compile files and directories; this package has no CLI of its own because the array DSL has no source-file form — call `compile()` directly.

## Errors

Compile errors throw `MiGears\Pages\Exception\CompileException` with a node path, e.g.:

```
sections.content[2].columns[2]: 列同时指定 bind 与 content
```

A frontend overrides `newException()` so its own failures still arrive as its own exception class, and one `catch` keeps working for everything it throws.

## Testing

```bash
composer test
```

Unit tests assert exact compiled output; the renderer tests run the compiled page through the full miGears Template pipeline.

## License

MIT

---

# migears/pages

![Version](https://img.shields.io/badge/version-2.0.0-blue)

用纯 PHP 数组书写的声明式页面定义，编译为 miGears 模板文件（`.tpl.php`）。数组形态是 DSL 的唯一事实标准：`migears/xml-pages` 与 `migears/yaml-pages` 只是语法前端——它们把自己的格式解析成同一个数组模型，而整套节点词表、校验与插值逻辑都在这一个包里，只实现一份，共同使用。

## 特性

- PHP 8.1+，PSR-4 自动加载，命名空间 `MiGears\Pages`
- 节点模型：`text`、`heading`、`link`、`if`、`each`、`form`、`table`、`el`、`component` —— 全部页面词汇集中在一处
- `{{ path }}` 插值自动转义 —— XSS 防护由模板引擎承担
- 编译期校验结构、字段、路径与键，**不静默丢弃任何东西**
- **属性透传**：`"@click"`、`x-on:click`、`v-bind:href`、`wire:click`、`hx-get`、`data-*`、`class`/`id`/`style` 输出到生成的标签
- `Renderer` 门面：从数组 DSL 一步渲染出 HTML
- 格式前端（`migears/xml-pages`、`migears/yaml-pages`）继承编译器，只实现 `parse()` 与少量拼写钩子
- 明确不做：业务逻辑、事件处理、状态管理、路由 —— 这些交给你搭配的前端框架

## 工作原理

页面声明是一个 PHP 数组 —— 唯一事实标准，其余都是派生物：

1. `Compiler::compile($page)` 把数组翻译为 `.tpl.php` 糖语法（`## $expr ##`）。中间产物保持可读，每个 DSL 词汇对应什么模板语法一目了然。
2. `migears/template` 的 `TemplateCompiler` 把糖编译成纯 PHP 模板（mtime 缓存，仅模板变更后重编一次）。渲染由 PHP 执行：模板运行时把变量以 HTML 形式输出给浏览器，声明层不进入运行期。

生成的 `.tpl.php` 是派生文件——重新编译即覆盖。修改声明，不要改产物。

XML 与 YAML 两个包都是这个编译器的前端：它们把各自的源解析成数组模型（`Compiler::parse()`），之后的全部流程——节点编译、校验、插值、属性透传——都是继承来的。表面语法不同，编译器与节点模型只有一个。

## 安装

```bash
composer require migears/pages
```

要求 PHP 8.1+ 与 `migears/template` ^2.0。无扩展、无第三方包。

## 快速开始

```php
use MiGears\Pages\Compiler;
use MiGears\Template\Template;

$compiler = new Compiler();
$tpl = $compiler->compile([
    'title' => '用户管理',
    'layout' => 'layout/main',
    'sections' => [
        'content' => [
            ['type' => 'heading', 'level' => 2, 'text' => '用户列表'],
            ['type' => 'table', 'items' => 'users', 'as' => 'user', 'empty' => '暂无数据', 'columns' => [
                ['label' => 'ID', 'bind' => 'id'],
                ['label' => '姓名', 'bind' => 'name'],
            ]],
        ],
    ],
]);

file_put_contents('views/pages/users.tpl.php', $tpl);
```

与普通模板一样渲染：

```php
$view = new Template(__DIR__ . '/views');
echo $view->render('pages/users', [
    'users' => [
        ['id' => 1, 'name' => 'Alice'],
        ['id' => 2, 'name' => 'Bob'],
    ],
]);
```

或用 `Renderer` 门面跳过落盘：

```php
use MiGears\Pages\Renderer;

$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render($page, ['users' => [...]]);
```

## 数组 DSL 参考

页面是一个 PHP 数组。根字段为 `title` / `layout` / `body` / `sections`；`layout` + `sections` 与 `body` 互斥。`body` / `sections` 中的每个节点都是带 `type` 键的数组。内嵌结构（`field`、`column`）的类型由位置决定——不必写 `type`；若写出，值必须匹配。

| 节点 | 字段 |
|------|------|
| `text` | `text` 必填 |
| `heading` | `text` 必填，`level` 取值 1–6（默认 1） |
| `link` | `href`、`text` 必填；`target` 可选 |
| `if` | `when` 必填（支持 `!` 前缀取反）、`then` 必填节点树、`else` 可选 |
| `each` | `items` 必填、`as`（默认 `item`）、`index`、`body` 必填 |
| `form` | `action` 必填、`method`（默认 `post`）、`fields` 必填 |
| `table` | `items` 必填、`as`（默认 `row`）、`columns` 必填、`empty` 可选 |
| `el` | `tag` 必填（小写）、`body` 可选、任意透传属性 |
| `component` | `name` 必填、`data` 可选（值支持 `{{ path }}`） |

`field` 的 input：`text`（默认）、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。`select` 字段带 `options` 映射且不接受 `value`；`checkbox` 带 `checked`（绑定路径）；`textarea` 带 `rows`（默认 4）。`column` 需要 `label`，且 `bind`（相对行变量的路径）与 `content`（行变量作用域内的节点树）二选一。

`{{ path }}` 把点路径插值为自动转义输出（`{{ user.name }}` → `## $user['name'] ?? '' ##`）。只支持 `a.b.c` 路径——函数调用、算术一律不允许。字面量字段——`layout`、section 名、`form.method`、`field.name`、`field.label`、`option` 的 value 与显示文本、`table.empty`、`column.label`、`component.name`——原样输出；在其中写 `{{ }}` 属编译错误。

节点上的键分三类：DSL 字段（节点自己消费）、透传属性（输出到标签：`"@event"`、带冒号的名字如 `x-on:click`、前缀 `x-` / `v-` / `hx-` / `data-`、`class` / `id` / `style`）、其余一律编译错误——视为拼写错误，绝不静默丢弃。

完整节点文法、插值规则与错误清单见 `spec.md`。

## 前端包

`migears/xml-pages` 与 `migears/yaml-pages` 以各自的语法提供同一个 DSL。当文本格式更容易书写或更容易让 AI 生成时使用它们：

- `migears/yaml-pages` —— `.page.yaml` 声明，用 PECL `ext-yaml` 解析
- `migears/xml-pages` —— `.page.xml` 声明，用 SimpleXML 解析

两者都经由本包编译，行为完全一致：同一套节点词汇、同一套校验、同一份产物。它们的 CLI 二进制（`bin/yaml-pages`、`bin/xml-pages`）负责编译文件与目录；本包没有 CLI——数组 DSL 没有源文件形态，直接调用 `compile()` 即可。

## 错误处理

编译错误抛出 `MiGears\Pages\Exception\CompileException`，信息带节点路径，例如：

```
sections.content[2].columns[2]: 列同时指定 bind 与 content
```

前端包可覆写 `newException()`，使共享层产生的失败仍以其自身的异常类抛出，一个 `catch` 覆盖全部错误。

## 测试

```bash
composer test
```

单元测试断言编译产物；渲染测试把编译产物经 migears/template 完整渲染验证。

## License

MIT

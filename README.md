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

The cache directory is content-addressed and only ever added to: a changed declaration writes a new `page_<md5>.tpl.php` and leaves the old one behind. Call `$renderer->clearCache()` on deploy to drop those derived pages — it returns how many it removed, leaves foreign files alone, and pages are simply recompiled on the next render. Deleting the directory wholesale is always safe too.

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

Structural children (`body`, `sections.<name>`, `then`, `else`, `each.body`, `el.body`, `column.content`) and `fields` / `columns` are **lists**; `sections`, `options` and `component.data` are **maps**. Writing a single node without its list wrapper is an array too, so it is rejected where it happens rather than failing later with a message about the wrong thing.

The full node grammar, interpolation rules and error catalogue are specified in `spec.md`.

## Front-end Packages

`migears/xml-pages` and `migears/yaml-pages` provide the same DSL in their own syntax. Use them when a text format is easier to author or to have AI generate:

- `migears/yaml-pages` — `.page.yaml` declarations, parsed with PECL `ext-yaml`
- `migears/xml-pages` — `.page.xml` declarations, parsed with SimpleXML

Both compile through this package, so behaviour is identical: same node vocabulary, same validation, same output. Their CLI binaries (`bin/yaml-pages`, `bin/xml-pages`) compile files and directories; this package has no CLI of its own because the array DSL has no source-file form — call `compile()` directly.

## Custom Components

This package ships no components of its own (`migears/xml-pages` and `migears/yaml-pages` bundle four built-ins; here you bring your own). A `component` node is handed straight to `migears/template`, so writing a custom component means writing an ordinary template file and making its name reachable from a registered path. There is no registry, no configuration, and no compile-time check that the file exists — `Renderer` registers only its own cache directory.

Write the component as a plain template file (`views/components/my-card.php`):

```php
<div class="my-card">
    <h3><?= $this->e($title ?? '') ?></h3>
    <div><?= $this->raw((string) ($body ?? '')) ?></div>
</div>
```

A `.tpl.php` component is equally valid — the engine compiles the `## ##` sugar to PHP on first render:

```php
<!-- views/components/my-card.tpl.php -->
<div class="my-card">
    <h3>## $title ?? '' ##</h3>
    <div>### $body ?? '' ###</div>
</div>
```

Four differences worth knowing before you pick the sugar:

- `## $expr ##` compiles to `$this->e($expr)` and `### $expr ###` to `$this->raw($expr)`: raw is one extra `#`, not a different function.
- `## $this->raw($expr) ##` does **not** produce raw output — the sugar wraps the expression in `$this->e()` regardless, so it silently escapes. That is why the built-in components reach for `<?= $this->raw(...) ?>` in plain PHP.
- Every `.tpl.php` component leaves a compiled artifact in the template cache directory (writable; system temp when unset) and wins over a `.php` file of the same name. A `.php` component produces no artifact at all.
- Plain PHP output is never auto-escaped: `<?= $title ?>` prints raw. So the sugar's escaped-by-default is the safer of the two once real PHP control flow enters the file.

The sugar earns its keep in markup-heavy components — interpolation inside an attribute reads well, e.g. `class="badge-## $type ?? 'info' ##"`. When the escaping decision is the whole point of the component, or the component already needs `if` / `foreach`, plain PHP keeps it visible.

Make its directory findable, then reference it by name:

```php
$tpl = new Template(__DIR__ . '/views');
$tpl->addPath(__DIR__ . '/views/components');

$renderer = new Renderer($tpl, new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render([
    'body' => [['type' => 'component', 'name' => 'my-card', 'data' => [
        'title' => '{{ user.name }}',
        'body' => 'body 由组件决定是否转义',
    ]]],
], ['user' => ['name' => 'Alice']]);
```

How `Template::findTemplate()` resolves the name:

| Rule | Behaviour |
|------|-----------|
| Name → file | `<path>/<name>.tpl.php` first, then `<path>/<name>.php`. The `.tpl.php` pass runs over every path before `.php` does, so sugar wins over plain PHP regardless of order |
| Path order | `addPath()` unshifts, so the directory added **last** is searched first — a same-named file there overrides an earlier one (theme override) |
| Sub-directories | The name is a path relative to a registered directory: `admin/table` resolves `<path>/admin/table.php` |
| Missing file | Not detected at compile time: rendering throws `RuntimeException: Component not found: <name>` |
| Components in components | A component template may call `$this->component()` itself |

Two limits worth designing around:

- **Isolated scope.** A component is evaluated with its `data` map only — the page's other variables are not passed down, so everything it needs has to be handed over explicitly.
- **String values only.** `data` values are validated at compile time and must be strings, and a `component` node emits no tag of its own, so it cannot carry `class` / `id` / `x-*`: wrap it in `el` when the wrapper needs attributes. Values arrive **unescaped**, so the component template chooses between `$this->e()` and `$this->raw()`.

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

缓存目录按内容寻址、只增不减：声明一变就写入新的 `page_<md5>.tpl.php`，旧文件留在原地。部署时调用 `$renderer->clearCache()` 即可清掉这些派生页面——它返回删除数量、保留外来文件，页面在下次渲染时重新编译；直接整体删除缓存目录也始终安全。

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

结构性字段（`body`、`sections.<名>`、`then`、`else`、`each.body`、`el.body`、`column.content`）与 `fields` / `columns` 是**列表**；`sections`、`options`、`component.data` 是**映射**。单个节点漏掉列表包裹时仍是数组，因此会在发生处被拦下，而不是留到更深处报一个指错对象的错误。

完整节点文法、插值规则与错误清单见 `spec.md`。

## 前端包

`migears/xml-pages` 与 `migears/yaml-pages` 以各自的语法提供同一个 DSL。当文本格式更容易书写或更容易让 AI 生成时使用它们：

- `migears/yaml-pages` —— `.page.yaml` 声明，用 PECL `ext-yaml` 解析
- `migears/xml-pages` —— `.page.xml` 声明，用 SimpleXML 解析

两者都经由本包编译，行为完全一致：同一套节点词汇、同一套校验、同一份产物。它们的 CLI 二进制（`bin/yaml-pages`、`bin/xml-pages`）负责编译文件与目录；本包没有 CLI——数组 DSL 没有源文件形态，直接调用 `compile()` 即可。

## 自定义组件

本包不自带任何组件（`migears/xml-pages` 与 `migears/yaml-pages` 各随包分发四个内置组件；本包要自己写）。`component` 节点直接交给 `migears/template` 处理，所以「写自定义组件」就是写一个普通模板文件、再让这个名字能被某个已注册路径找到。没有注册表、没有配置，编译期也不会检查文件是否存在——`Renderer` 只注册自己的缓存目录。

组件写成普通模板文件（`views/components/my-card.php`）：

```php
<div class="my-card">
    <h3><?= $this->e($title ?? '') ?></h3>
    <div><?= $this->raw((string) ($body ?? '')) ?></div>
</div>
```

`.tpl.php` 组件同样可用——引擎会在首次渲染时把 `## ##` 糖编译成 PHP：

```php
<!-- views/components/my-card.tpl.php -->
<div class="my-card">
    <h3>## $title ?? '' ##</h3>
    <div>### $body ?? '' ###</div>
</div>
```

选择糖之前值得知道四条差异：

- `## $expr ##` 编译为 `$this->e($expr)`，`### $expr ###` 编译为 `$this->raw($expr)`——raw 是多一个 `#`，不是换一个函数。
- `## $this->raw($expr) ##` **不会**原样输出：糖无论如何都会把表达式包进 `$this->e()`，于是静默转义。内置组件因此在原生 PHP 里用 `<?= $this->raw(...) ?>`。
- 每个 `.tpl.php` 组件都会在模板缓存目录留一份编译产物（目录需可写，未配置时是系统临时目录），且同名时优先于 `.php`；`.php` 组件不产生任何产物。
- 原生 PHP 的输出永不自动转义：`<?= $title ?>` 是原样输出。所以一旦文件里出现真正的 PHP 控制流，糖的「默认转义」反而是两者中更安全的那个。

糖的价值在标记密集的组件里体现得最明显——属性内插尤其好读，例如 `class="badge-## $type ?? 'info' ##"`。而当转义决策本身就是组件的重点，或组件已经需要 `if` / `foreach` 时，原生 PHP 能把它一直摆在明面上。

让它所在目录可被找到，然后在页面里按名引用：

```php
$tpl = new Template(__DIR__ . '/views');
$tpl->addPath(__DIR__ . '/views/components');

$renderer = new Renderer($tpl, new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render([
    'body' => [['type' => 'component', 'name' => 'my-card', 'data' => [
        'title' => '{{ user.name }}',
        'body' => 'body 由组件决定是否转义',
    ]]],
], ['user' => ['name' => 'Alice']]);
```

`Template::findTemplate()` 的解析规则：

| 规则 | 行为 |
|------|------|
| 名字 → 文件 | 先 `<path>/<name>.tpl.php`，再 `<path>/<name>.php`。`.tpl.php` 那一轮会遍历完全部路径才轮到 `.php`，所以无论路径顺序如何，糖语法文件都优先于原生 PHP |
| 路径顺序 | `addPath()` 是 unshift，**后加**的目录先被搜索——同名文件放进去即覆盖先前的（主题覆盖） |
| 子目录 | 名字是相对某个已注册目录的路径：`admin/table` 命中 `<path>/admin/table.php` |
| 文件缺失 | 编译期不检测，渲染时才抛 `RuntimeException: Component not found: <name>` |
| 组件套组件 | 组件模板里可以继续调用 `$this->component()` |

设计组件前值得知道的两个限制：

- **作用域隔离。** 组件只用它的 `data` 求值，页面的其它变量不会透传下来，需要什么就得显式传进去。
- **只能传字符串。** `data` 的值在编译期校验，必须是字符串；且 `component` 节点自身不输出标签，挂不上 `class` / `id` / `x-*`，需要外层属性时用 `el` 包裹。值以**未转义**形式送达，转义与否由组件模板在 `$this->e()` 与 `$this->raw()` 之间决定。

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

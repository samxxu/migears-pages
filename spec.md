# migears/pages 模块规格说明

版本：2.0.0（草案，待评审）
日期：2026-09-21

## 1. 定位

pages 是 miGears 框架的声明式页面编译层：以 **PHP 数组**为 DSL 的唯一事实标准，把页面声明编译为 migears/template 的模板文件（`.tpl.php` 语法）。它不是核心组件，不承担运行期职责，只做编译期的"声明 → 模板"翻译。

它与 `migears/xml-pages`、`migears/yaml-pages` 是**编译层与语法前端**的关系：两个前端只解析自己的格式，产出同一个数组模型（本文档 §4 的 IR），此后的一切——节点编译、校验、插值、属性透传——都在本包完成。三个包共享同一份节点词表与编译产物。

## 2. 边界

### 2.1 范围内

- IR 契约：数组页面定义的确切形状（§4、§6）
- 节点编译：`text` / `heading` / `link` / `if` / `each` / `form` / `table` / `el` / `component` 及内嵌结构 `field` / `column`
- 数据绑定：`{{ path }}` 插值的两种上下文（HTML 文本/属性、组件 PHP 字面量）
- 编译期校验：结构、字段、路径、键、字面量、花括号配对——**不静默丢弃**
- 属性透传：前端框架指令白名单与定向纠错
- 前端集成点：`parse()` 与拼写钩子（§8）
- `Renderer` 门面：数组 DSL 一步渲染 HTML（§9）

### 2.2 范围外（明确不做）

- 业务逻辑、事件处理、状态管理、路由定义——一律不进页面声明；由前端框架承担
- 运行期解析——编译是唯一入口，运行期只依赖生成的模板
- 自身的源文本语法与 CLI——数组没有"源文件"形态，直接调用 `compile()`；CLI 由各前端包提供
- composer 第三方依赖——零依赖（仅 `migears/template`）

## 3. 核心原则

### 3.1 数组是唯一事实标准

页面声明是一个 PHP 数组。一切修改都回到数组完成；生成的 `.tpl.php` 是**派生文件**，可随时被重新编译覆盖，不应被手工修改。工作流固定为：改声明 → 编译 → 渲染。

### 3.2 刻意两次编译

第一次编译（本包）：数组声明 → `.tpl.php` 糖语法（`## $expr ##`）。产物保持可读，每个 DSL 词汇对应什么模板语法一目了然。

第二次编译：migears/template 的 `TemplateCompiler` 把 `.tpl.php` 编译成纯 PHP 模板（mtime 缓存）。渲染由 PHP 执行，声明层不进入运行期。

两次编译各有意义，不合并、不省略。

### 3.3 极轻量

实现规模保持在千行量级（编译器约 900 行，Renderer 约 50 行）。任何让实现显著膨胀的特性都拒绝。

### 3.4 编译即校验

编译期对结构、字段、路径、键做完整校验，**不静默丢弃**：未知键、拼错的指令名一律报错，而不是被悄悄忽略。错误信息必须带节点路径，可定位。

### 3.5 单一实现

前端之间不复制编译逻辑。格式特有的差异（属性拼写、属性存储位置、错误措辞、异常类）收敛为 §8 的钩子方法；新增格式前端只需实现 `parse()` 与钩子，节点词表自动获得。

## 4. IR 契约：页面声明

页面声明是一个 PHP 数组（`array<string, mixed>`）。根映射即一个页面，无需 `type` 键。

### 4.1 顶层字段

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `title` | string | 否 | 页面标题，写入 `title` section |
| `layout` | string | 否 | 继承的布局模板名（如 `layout/admin`） |
| `body` | array | 视情况 | 无 `layout` 时的页面主体节点树 |
| `sections` | array | 视情况 | 有 `layout` 时，section 名 → 节点树数组 |

规则：`layout` 存在时 `sections` 必填、`body` 禁用；`layout` 不存在时 `body` 必填、`sections` 禁用。违反即编译错误。根上出现 `title` / `layout` / `body` / `sections` 以外的键即编译错误。

`title` 存在时自动生成一个 `title` section（仅在有 `layout` 时生效；无 layout 时忽略并告警）。

### 4.2 节点

`body` / `sections` 的值均为**节点树数组**：`list<array{type: string, ...}>`。每个节点必须有字符串 `type` 键。共有 9 种节点 + 2 种内嵌结构（`field`、`column` 不写 `type`，位置决定类型；若写出则必须匹配）。

| 节点 | 用途 | 是否输出标签 |
|------|------|--------------|
| `text` | 文本，支持插值 | 否 |
| `heading` | 标题 | 是 |
| `link` | 链接 | 是 |
| `if` | 条件显示 | 否 |
| `each` | 循环列表 | 否 |
| `form` | 表单容器 | 是 |
| `table` | 表格容器 | 是 |
| `el` | 通用元素容器 | 是 |
| `component` | 引用内置或自定义组件 | 否 |

不输出标签的节点（`text` / `if` / `each` / `component`）不接受透传属性，需要 `el` 包裹。

## 5. IR 契约：字段规则

### 5.1 三类键

节点上的键分三类处理：

1. **DSL 字段**——该节点类型自己消费的字段（如 `heading.level`、`link.href`、`form.action`），含结构性子键（`then`、`body`、`fields`、`columns`、`data`、`options`、`content`）。
2. **透传属性**——输出到该节点生成的标签。白名单：
   - `@event`——Alpine / Vue 的事件简写
   - 任何含冒号的指令名：`x-on:click`、`x-bind:href`、`v-on:click`、`wire:click`、`on:click`、`:href`
   - 前缀：`x-`、`v-`、`hx-`、`data-`
   - 常用 HTML 钩子：`class`、`id`、`style`
3. **其余一律编译错误**——未知键视为拼写错误，绝不静默丢弃。

**定向拦截**：`x-on-*` / `x-bind-*` / `x-transition-*` 在 Alpine 中不存在（Alpine 一律用冒号）。由于 `x-` 前缀本会放行，这类拼写会被静默透传、编译成功而指令失效——因此单独拦截并给出建议（提示改写 `x-on:click` 或 `@click`）。

**重复属性检测**：同一节点输出两个同名属性（如两次透传 `class`）即编译错误。

### 5.2 透传值的归一与转义

透传值先按 HTML 属性可承载的形态归一，再转义（`ENT_COMPAT`，保留单引号可读性），**最后**做 `{{ }}` 插值——顺序不能反，否则 `## ##` 糖语法里的引号会被转义破坏。

| 值类型 | 归一结果 |
|--------|----------|
| string | 原样 |
| null | `''`（`x-cloak:` 这类无值属性的数组写法） |
| bool | `true` / `false` |
| int / float | 十进制字符串 |
| 其他（数组/对象） | 编译错误 |

### 5.3 字面量字段

以下字段是**字面量**，原样输出，在其中写 `{{ }}` 属编译错误：

`layout`、section 名、`form.method`、`field.name`、`field.label`、`option` 的 value 与显示文本、`table.empty`、`column.label`、`component.name`、`component.data` 的键。

### 5.4 内嵌结构

`field` 与 `column` 是内嵌结构：类型由位置决定。若写出 `type`，值必须与位置一致（`field` / `column`），否则编译错误。

## 6. IR 契约：节点文法

### 6.1 text

```php
['type' => 'text', 'text' => '你好，{{ user.name }}']
```

`text` 必填，原样输出（字面部分由作者控制，可含 HTML）。插值自动转义。编译为裸文本（无标签）。

### 6.2 heading

```php
['type' => 'heading', 'level' => 2, 'text' => '用户管理']
```

`text` 必填；`level` 取值 1–6，默认 1，越界即编译错误。编译为 `<hN>...</hN>`。

### 6.3 link

```php
['type' => 'link', 'href' => '/users/{{ user.id }}/edit', 'text' => '编辑']
```

`href`、`text` 必填，均支持插值。`target` 可选（支持插值）。

### 6.4 if

```php
['type' => 'if', 'when' => 'user.loggedIn', 'then' => [...], 'else' => [...]]
```

`when` 必填，路径可带 `!` 前缀取反（`'!user.hidden'`）；`then` 必填节点树；`else` 可选节点树。编译为：

```php
<?php if ($user['loggedIn'] ?? null): ?>
  ...then...
<?php else: ?>
  ...else...
<?php endif ?>
```

### 6.5 each

```php
['type' => 'each', 'items' => 'users', 'as' => 'user', 'index' => 'i', 'body' => [...]]
```

`items` 必填路径（`!` 取反只属于 `if.when`，此处写 `!` 按非法路径报错）；`as` 默认 `item`；`index` 可选变量名；`body` 必填节点树。编译为：

```php
<?php foreach ($users as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

`as` / `index` 必须是合法 PHP 变量名，否则编译错误。嵌套 each 允许，内层 `as` 同名时按 PHP 语义自然遮蔽。

### 6.6 form + field

```php
['type' => 'form', 'action' => '/users/save', 'method' => 'post', 'fields' => [...]]
```

`action` 必填（支持插值），`method` 默认 `post`，只允许 `get` / `post`，`fields` 必填数组。

**field** 字段：

| 字段 | 类型 | 必填 | 说明 |
|------|------|------|------|
| `name` | string 字面量 | 是 | 字段名（`name` / `id` 属性） |
| `label` | string 字面量 | 是 | 标签文本；`submit` 类型时为按钮文字 |
| `input` | enum | 否 | 见下，默认 `text` |
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"` |
| `required` | bool | 否 | 默认 false，输出 `required` 属性 |
| `placeholder` | string | 否 | 仅 text/password/email/number，支持插值 |
| `options` | array | 仅 select | `['admin' => '管理员']` 映射，value 与文本均为字面量 |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性 |
| `rows` | int | 仅 textarea | 默认 4，须为正整数 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`，均编译错误。

编译产物（节选）：

```php
<form action="/users/save" method="post">
  <label for="name">姓名</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  ...
</form>
```

### 6.7 table + column

```php
['type' => 'table', 'items' => 'users', 'as' => 'user', 'empty' => '暂无数据', 'columns' => [...]]
```

`items` 必填路径；`as` 默认 `row`；`empty` 可选（字面量）；`columns` 必填数组。

**column**：`label` 必填（字面量）；`bind`（相对行变量的路径，如 `'id'` → `row.id`）与 `content`（节点树，行变量作用域）**二选一必填**，同时提供即编译错误。

编译为：

```php
<table>
<thead><tr><th>ID</th><th>姓名</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="2">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users as $user): ?>
<tr>
<td>## $user['id'] ?? '' ##</td>
<td>## $user['name'] ?? '' ##</td>
</tr>
<?php endforeach ?>
<?php endif ?>
</tbody>
</table>
```

### 6.8 component

```php
['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}', 'body' => '简介']]
```

`name` 必填（字面量，即模板名）；`data` 可选映射，键为字面量，值支持 `{{ path }}` 插值（PHP 上下文拼接编译，不预转义），值必须为字符串。编译为：

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => '简介',
]) ?>
```

插值值以未转义形式到达组件，转义由组件模板决定（文本用 `$this->e()`，信任的 HTML 用 `$this->raw()`）——编译期预转义会叠成双重转义。

### 6.9 el

```php
['type' => 'el', 'tag' => 'div', 'x-data' => '{ open: false }', 'body' => [...]]
```

`tag` 必填（小写 HTML 标签名，`/^[a-z][a-z0-9-]*$/`）；`body` 为子节点树，可省略（视为空）。接受任意透传属性。编译为：

```php
<div x-data="{ open: false }">
<h3>## $user['name'] ?? '' ##</h3>
正文
</div>
```

`body` 为空时输出自闭合形态 `<div></div>`（保留属性）。

## 7. IR 契约：数据绑定

### 7.1 路径表达式

路径是数据绑定的唯一载体，文法严格：

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

首段即变量名，后续段为数组键访问。编译后的访问统一带 `?? ''`（文本/属性上下文）或 `?? null`（条件/循环上下文）兜底。

### 7.2 插值 `{{ path }}` 的两种上下文

| 上下文 | 编译方式 | 示例 |
|--------|----------|------|
| HTML 文本 / 属性（text、heading、link、属性值等） | 原样保留 `## expr ##` 糖，模板引擎渲染时转义 | `href="/users/## $user['id'] ?? '' ##"` |
| PHP 数组字面量（component 的 `data`） | 字符串拼接 `'...' . ($expr) . '...'`，**不预转义** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

PHP 上下文绝不能输出 `## ##` 糖——它会被 TemplateCompiler 二次替换进 PHP 字符串字面量，造成语法错误。

### 7.3 非法表达式

任何 `{{ ... }}` 内不符合路径文法的内容（函数调用、算术、字符串字面量、嵌套插值）都是编译错误。插值符号最多两个花括号：出现 `{{{` 或 `}}}` 即编译错误（三个花括号会骗过配对计数，把错乱花括号留在产物里）。

### 7.4 数据形态约束

路径编译为数组访问（`$user['name']`）。页面数据约定为**数组形态**，由控制器在边界处归一化（Domain 实体转为数组）。这是文档化约束，不在本模块内做对象兼容。

## 8. 前端集成点（钩子）

前端包继承 `MiGears\Pages\Compiler`，只实现解析与拼写差异：

| 钩子 | 签名 | 语义 |
|------|------|------|
| `parse` | `parse(string $source): array` | 源语法 → IR（抽象层基类抛错：数组页面请直接 `compile()`） |
| `mapAttributeName` | `(string $name, string $path): string` | 前端拼写 → 输出名。基类接受 `@event` 原样；XML 覆写为 `__click` → `@click` |
| `attributeCandidates` | `(array $n, list<string> $dslFields): list<array{name, value, explicit}>` | 属性候选枚举。映射形态源（数组/YAML）默认从节点自身读取；XML 覆写为从 `<attr>` 子元素读取，`explicit` 标记显式属性（跳过名称映射、原样输出） |
| `normalizeAttrValue` | `(mixed $value, string $name, string $path): string` | 标量归一（§5.2）；字符串源天然全为 string，无需覆写 |
| `newException` | `(string $message): CompileException` | 异常类；前端覆写返回自己的异常，一个 `catch` 覆盖全部错误 |
| `nodeRef` / `containerRef` / `explicitAttrRef` | `(string): string` | 错误信息中节点/容器/显式属性的措辞 |
| `COLON_ONLY_DIRECTIVES` | `array<string, list<string>>` | 连字符定向拦截表（默认 Alpine 三个指令） |

基类还提供公共入口：`compile(array $page)`、`compileSource(string $source)`（走 `parse()`）、`compileFile(string $path)`、`compileToFile(string $sourcePath, ?string $outputDir)`（`xxx.page.*` → `xxx.tpl.php`）。

## 9. Renderer 门面

`MiGears\Pages\Renderer` 提供从数组 DSL 一步到 HTML 的入口：

```php
$renderer = new Renderer($template, $compiler, $cacheDir);
echo $renderer->render($page, $data);
```

- 内部完成：`compile()` → 写入 `$cacheDir`（内容寻址：`page_<md5(source)>.tpl.php`，声明不变不重写）→ `$template->render()`。
- 构造时把 `$cacheDir` 注册进 `$template` 的搜索路径，布局与组件仍走用户已配置的模板路径。
- 两次渲染同一声明时命中缓存文件，只做一次磁盘写入。

## 10. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），信息带节点路径，格式：

```
sections.content[2].columns[2]: 列同时指定 bind 与 content
```

错误分类：

| 类别 | 检测 | 示例 |
|------|------|------|
| 根类型错误 | 根不是数组/结构不符 | page: 未知字段 "foo" |
| 结构错误 | 顶层规则违反 | 同时指定 body 与 sections |
| 未知节点 | type 不在词表 | 未知节点类型 "foo" |
| 字段缺失/非法 | 必填缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| 路径错误 | 插值/路径文法不匹配 | 非法路径 "user name" |
| 上下文错误 | bind/content 互斥等 | column 同时含 bind 与 content |
| method 类型错误 | `form.method` 不是字符串（校验先于任何强转，不泄漏 PHP 警告） | method 必须是字符串 "get" 或 "post"，收到 array |
| column.content 形态错误 | `content` 是单个节点映射而非节点列表 | columns[0].content: 必须是节点树数组（列表），当前是单个节点映射 |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 内嵌结构类型错误 | field/column 的 type 与位置不符 | type 必须是 "field" |
| 未知键 | 既非 DSL 字段，也不在透传白名单 | 未知属性 "levl" |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*` | 请写 "x-on:click" 或 "@click" |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| 属性无挂载点 | 透传属性出现在不输出标签的节点上 | 节点 "text" 不输出标签，请用 type: el 包裹内容 |
| 属性值类型错误 | 透传属性值不是标量 | 属性 "x" 的值必须是标量，收到 array |
| 重复属性 | 同名透传属性出现两次 | 属性 "class" 重复定义 |

失败即中止（fail-fast）：首个错误抛出，`CompileException` 携带从根到节点的路径。

## 11. 模块结构

```
migears-pages/
├── composer.json            name: migears/pages; require: php >=8.1, migears/template ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、数组 DSL 参考、前端包、错误处理、测试说明
├── LICENSE
├── src/
│   ├── Compiler.php         编译器（核心，约 900 行）
│   ├── Renderer.php         一步渲染门面（约 50 行）
│   └── Exception/
│       └── CompileException.php
└── tests/
    ├── CompilerTest.php     节点编译、校验、插值、透传断言
    ├── RendererTest.php     经 migears/template 完整渲染验证
    └── fixtures/
        └── views/           渲染测试用布局
```

composer 依赖说明：运行期执行的是生成的模板，依赖 migears/template，故设为 `require`。解析扩展（ext-yaml、SimpleXML）由前端包各自声明，本包不感知。

## 12. 测试计划（TDD）

单元测试以数组页面定义驱动，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行 |
| 结构 | heading 各级、越界 level 报错；link href/text 插值 |
| 条件 | if then / then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input / method 非字符串（array、bool、int）报类型错误且不泄漏 PHP 警告 |
| 表格 | bind 列 / content 列 / empty / as 默认与自定义 / bind+content 同存报错 / columns 缺失报错 / content 非数组与单个节点映射均报可读错误 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 / data 值非字符串报错 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 内嵌结构 | `type: field` / `type: column` 写对可通过，写成另一种即报错 |
| 字面量 | `field.label`、`table.empty`、`option` 等字面量字段写 `{{ }}` 报错 |
| 透传 | Alpine / Vue / htmx / Livewire 指令与 `class`/`id`/`style` 透传；`@click` 原样；值转义；值内插值；标量归一（整数/布尔/空值）；重复属性报错 |
| 透传误用 | 未知键报错；无标签节点承载属性报错；页面根未知字段报错 |
| el | 带 body / 空 body / 缺 tag 报错 / 非法 tag 报错 |
| 定向拦截 | `x-on-click` 报错并提示 `x-on:click` 或 `@click` |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 放行 |
| 抽象层 | 基类 `compileSource()` 抛错提示使用前端包 |
| 渲染 | Renderer：body 页 / layout+sections / 自动转义 / 缓存目录自动创建 / 声明变更重渲染 / 组件经模板路径解析 |

## 13. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- 表达式语言扩展（算术、函数、三元）
- 运行期解析 / 热更新
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）
- 数组 DSL 的 CLI（无源文件形态；CLI 属于各前端包）

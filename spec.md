# migears/pages Module Specification

Version: 2.0.0 (draft, pending review)
Date: 2026-09-21

## 1. Positioning

`pages` is the declarative page compilation layer of the miGears framework: it treats the **PHP array** as the single source of truth for the DSL and compiles a page declaration into a `migears/template` template file (`.tpl.php` syntax). It is not a core component and carries no runtime responsibility; it only does the compile-time "declaration → template" translation.

Together with `migears/xml-pages` and `migears/yaml-pages`, it forms a **compiler + syntax-frontend** relationship: the two frontends only parse their own format and produce the same array model (the IR in §4); everything after that — node compilation, validation, interpolation, attribute passthrough — happens in this package. All three packages share one node vocabulary and one compiled output.

## 2. Boundaries

### 2.1 In scope

- IR contract: the exact shape of an array page definition (§4, §6)
- Node compilation: `text` / `heading` / `link` / `if` / `each` / `form` / `table` / `el` / `component` and the embedded structures `field` / `column`
- Data binding: `{{ path }}` interpolation in two contexts (HTML text/attribute, component PHP literal)
- Compile-time validation: structure, fields, paths, keys, literals, brace pairing — never silently dropped
- Attribute passthrough: front-end framework directive whitelist and targeted corrections
- Front-end integration points: `parse()` and spelling hooks (§8)
- `Renderer` facade: render an array DSL page straight to HTML (§9)
- `Html` factory: user-level syntax that normalizes to the same array IR the frontends produce (§10)

### 2.2 Out of scope (explicitly not done)

- Business logic, event handling, state management, route definition — never in a page declaration; left to the front-end framework
- Runtime parsing — compilation is the only entry point; at runtime only the generated template is used
- Its own source-text syntax and CLI — an array has no "source file" form; call `compile()` directly; the CLI belongs to the frontend packages
- Composer third-party dependencies — zero dependencies (only `migears/template`)

## 3. Core principles

### 3.1 The array is the single source of truth

A page declaration is a PHP array. Every change goes back to the array; the generated `.tpl.php` is a **derived file** — it can be recompiled and overwritten at any time and should not be hand-edited. The workflow is fixed: change declaration → compile → render.

### 3.2 Two deliberate compilations

First compilation (this package): array declaration → `.tpl.php` sugar syntax (`## $expr ##`). The output stays readable — you can see at a glance which template syntax each DSL word maps to.

Second compilation: `migears/template`'s `TemplateCompiler` compiles the `.tpl.php` into plain PHP templates (mtime-cached). Rendering is done by PHP; the declaration layer never enters runtime.

Both compilations have a purpose and are neither merged nor skipped.

### 3.3 Extremely lightweight

The implementation stays in the thousand-line range (compiler ≈ 1050 lines, Renderer ≈ 80 lines). Any feature that would significantly bloat it is rejected.

### 3.4 Compilation is validation

At compile time, structure, fields, paths, and keys are fully validated, with **nothing silently dropped**: unknown keys and misspelled directive names always raise an error rather than being quietly ignored. Error messages must carry the node path so they can be located.

### 3.5 Single implementation

Frontends do not duplicate compilation logic. Format-specific differences (attribute spelling, attribute storage location, error wording, exception class) converge into the §8 hook methods; a new format frontend only needs to implement `parse()` plus the hooks and automatically inherits the whole node vocabulary.

## 4. IR contract: page declaration

A page declaration is a PHP array (`array<string, mixed>`). The root mapping is a page and needs no `type` key.

### 4.1 Top-level fields

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `title` | string | no | page title, written into the `title` section |
| `layout` | string | no | the layout template to extend (e.g. `layout/admin`) |
| `body` | array | conditional | the page body node tree when there is no `layout` |
| `sections` | array | conditional | with a `layout`, section name → node tree array |

Rule: when `layout` is present, `sections` is required and `body` is forbidden; when `layout` is absent, `body` is required and `sections` is forbidden. Violating this is a compile error. Any key on the root other than `title` / `layout` / `body` / `sections` is a compile error.

When `title` is present, a `title` section is generated automatically (only effective with a `layout`; without one it is ignored with a warning).

### 4.2 Nodes

The values of `body` / `sections` are all **node tree arrays**: `list<array{type: string, ...}>`. Every node must have a string `type` key. There are 9 node types + 2 embedded structures (`field`, `column` do not carry `type`; their position decides their type; if written it must match).

| Node | Purpose | Outputs a tag |
|------|---------|---------------|
| `text` | text, supports interpolation | no |
| `heading` | heading | yes |
| `link` | link | yes |
| `if` | conditional display | no |
| `each` | loop over a list | no |
| `form` | form container | yes |
| `table` | table container | yes |
| `el` | generic element container | yes |
| `component` | reference a bundled or custom component | no |

Nodes that do not output a tag (`text` / `if` / `each` / `component`) cannot take passthrough attributes; wrap them in `el`.

## 5. IR contract: field rules

### 5.1 Three kinds of keys

Keys on a node are handled in three categories:

1. **DSL fields** — fields the node type itself consumes (e.g. `heading.level`, `link.href`, `form.action`), including the structural sub-keys (`then`, `body`, `fields`, `columns`, `data`, `options`, `content`).
2. **Passthrough attributes** — output onto the tag the node generates. Whitelist:
   - `@event` — the Alpine / Vue event shorthand
   - any directive name containing a colon: `x-on:click`, `x-bind:href`, `v-on:click`, `wire:click`, `on:click`, `:href`
   - prefixes: `x-`, `v-`, `hx-`, `data-`
   - common HTML hooks: `class`, `id`, `style`, plus `bind` (a framework binding attribute whose value is a browser-side variable name)
3. **Anything else is a compile error** — unknown keys are treated as typos, never silently dropped.

**Targeted interception**: `x-on-*` / `x-bind-*` / `x-transition-*` do not exist in Alpine (Alpine always uses a colon). Because the `x-` prefix normally passes through, such typos would pass silently, compile, and leave a dead directive — so they are intercepted individually with a suggestion (write `x-on:click` or `@click` instead).

**Duplicate attribute detection**: emitting two same-named attributes from one node (e.g. `class` passed twice) is a compile error.

### 5.2 Normalization and escaping of passthrough values

Passthrough values are first normalized into a shape an HTML attribute can carry, then escaped (`ENT_COMPAT`, keeping single quotes readable), and only **then** interpolated with `{{ }}` — the order cannot be reversed, or the quotes inside the `## ##` sugar would be mangled by escaping.

| Value type | Normalization result |
|------------|----------------------|
| string | as-is |
| null | `''` (the array spelling of valueless attributes like `x-cloak:`) |
| bool | `true` / `false` |
| int / float | decimal string |
| other (array/object) | compile error |

### 5.3 Literal fields

The following fields are **literals**: they are output as-is, and writing `{{ }}` in them is a compile error:

`layout`, section names, `form.method`, `field.name`, `field.label`, `option` values and display text, `table.empty`, `column.label`, `component.name`, and the keys of `component.data`.

### 5.4 Embedded structures

`field` and `column` are embedded structures: their type is decided by position. If `type` is written, its value must match the position (`field` / `column`) or it is a compile error.

### 5.5 Collection shapes and type guards

Values appearing in structural positions come in two shapes; going out of bounds is a compile error:

| Position | Shape | Elements |
|----------|-------|----------|
| `body`, `sections.<name>`, `if.then`, `if.else`, `each.body`, `el.body`, `column.content` | list | node objects |
| `form.fields`, `table.columns` | list | field / column objects |
| `sections`, `field.options`, `component.data` | mapping | section name → node tree; option value → text; data name → string |

Writing a list as a mapping (a single node without `[ ]` wrapping, or a bare mapping after `fields:`) is the most common shape mistake. Such values are still arrays, so a mere `is_array()` check lets them through until a deeper, misdirected error surfaces (`content[type]: node must be an object`) — misreporting "missing list wrapping" as "node is not an object". So every list entry point is guarded by `requireList()`, and both a non-array and a mapping are named at the point they occur, stating what type was received.

Writing a mapping as a list (`sections` followed directly by a node, `component.data` followed directly by several values) similarly has no key to lean on; it used to fall through to a deeper error like "section name 0" / "data key 0". Such entry points are guarded by `requireMap()`: an empty array is an empty mapping (`[]` is both an empty list and an empty mapping, holding nothing that could be misread). `field.options` is a deliberate exception — when option values are plain numbers (e.g. `value="0"`), PHP keys themselves are `0..n-1` and indistinguishable from a list, so there only "must be an array" is checked.

The accompanying scalar type guards work the same way: `layout`, `title` must be strings, `sections` must be a mapping, `field.required` must be a boolean, `option` text must be a string. These values used to be handled by `(string)` casts or `=== true` comparisons, which either leaked PHP warnings (`Array to string conversion`) or silently ignored mismatches — both forbidden by this module.

**Overall contract**: every compile-time error must be a path-carrying `CompileException`; no PHP warning may leak into the output, and no raw `TypeError` may be thrown for a type mismatch.

## 6. IR contract: node grammar

### 6.1 text

```php
['type' => 'text', 'text' => 'Hello, {{ user.name }}']
```

`text` is required and output as-is (the literal part is controlled by the author and may contain HTML). Interpolation is auto-escaped. Compiles to bare text (no tag).

### 6.2 heading

```php
['type' => 'heading', 'level' => 2, 'text' => 'User management']
```

`text` is required; `level` accepts 1–6 and defaults to 1; out of range is a compile error. Compiles to `<hN>...</hN>`.

### 6.3 link

```php
['type' => 'link', 'href' => '/users/{{ user.id }}/edit', 'text' => 'Edit']
```

`href` and `text` are required and both support interpolation. `target` is optional (supports interpolation) and not validated — HTML allows named targets beyond `_blank`, and an enum whitelist would kill legitimate uses.

### 6.4 if

```php
['type' => 'if', 'when' => 'user.loggedIn', 'then' => [...], 'else' => [...]]
```

`when` is required; the path may carry a `!` prefix to negate (`'!user.hidden'`); `then` is a required node tree; `else` is an optional node tree. Compiles to:

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

`items` is a required path (`!` negation belongs only to `if.when`; writing `!` here is reported as an invalid path); `as` defaults to `item`; `index` is an optional variable name; `body` is a required node tree. Compiles to:

```php
<?php foreach ($users ?? [] as $i => $user): ?>
  ...body...
<?php endforeach ?>
```

`as` / `index` must be legal PHP variable names or it is a compile error. Nested `each` is allowed; when the inner `as` shares a name, PHP semantics naturally shadow it.

### 6.6 form + field

```php
['type' => 'form', 'action' => '/users/save', 'method' => 'post', 'fields' => [...]]
```

`action` is required (supports interpolation), `method` defaults to `post` and only allows `get` / `post`, `fields` is a required array.

**field** fields:

| Field | Type | Required | Meaning |
|-------|------|----------|---------|
| `name` | string literal | yes | field name (the `name` attribute); also the default for the `id` and the `label`'s `for` |
| `id` | string literal | no | defaults to `name`; when given explicitly it overrides it and also drives the `label`'s `for` (output once, no longer defaulted) |
| `bind` | JS name/path | no | the framework binding attribute, outputs `bind="user.email"`; the value is a browser-side name — writing `{{ }}` is a compile error |
| `label` | string literal | yes | the label text; for `submit` types the button text |
| `input` | enum | no | see below, defaults to `text` |
| `value` | path | no | the bound value, compiled to `value="## $path ?? '' ##"`; may be written as `{{ user.name }}` (recommended, the data is more visible); not supported on `submit` (buttons use `label`) |
| `required` | bool | no | defaults to false; outputs `required` on inputs that support it; `true` on `hidden` / `submit` is a compile error |
| `placeholder` | string | no | only text/password/email/number, supports interpolation; on other inputs it is a compile error |
| `options` | array | select only | `['admin' => 'Admin']` mapping; both value and text are literals |
| `checked` | path | checkbox only | outputs the `checked` attribute when truthy; on other inputs it is a compile error |
| `rows` | int | textarea only | defaults to 4, must be a positive integer; on other inputs it is a compile error |

`input` enum: `text`, `password`, `email`, `number`, `textarea`, `select`, `checkbox`, `hidden`, `submit`. An invalid enum is a compile error. A missing `options` on `select`, `options` used on an unsupported input, or `value` used on `select` are all compile errors.

A field's **usage scope** is equally a hard constraint — out of range is a compile error, and these fields used to be silently dropped, contradicting this module's "never silently drop" contract: `placeholder` only text/password/email/number, `checked` only checkbox, `rows` only textarea, `value` not on submit, `required` only text/password/email/number/textarea/select/checkbox. When `required` is true it is also output on select / textarea / checkbox.

Compiled output (excerpt):

```php
<form action="/users/save" method="post">
  <label for="name">Name</label>
  <input type="text" name="name" id="name" value="## $user['name'] ?? '' ##" required>
  ...
</form>
```

### 6.7 table + column

```php
['type' => 'table', 'items' => 'users', 'as' => 'user', 'empty' => 'No data', 'columns' => [...]]
```

`items` is a required path; `as` defaults to `row`; `empty` is optional (literal); `columns` is a required array.

**column**: `label` is required (literal); exactly one of `pop` (a data reference PHP renders into the cell, written `'{{ row.id }}'`) and `content` (a node tree, row-variable scope) is required — providing both is a compile error. The `pop` value must carry `{{ }}` and its first segment must equal the table's `as` variable (default `row`) — a bare path without braces, or a reference to a different variable, is a compile error.

> Vocabulary note: this module **only uses `pop` for "PHP renders the data into the page"**, never `bind`; `bind` refers to the front-end framework's binding attribute (browser side). The combined form and `popAndBind` are in §10.

Compiled output:

```php
<table>
<thead><tr><th>ID</th><th>Name</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="2">No data</td></tr>
<?php else: ?>
<?php foreach ($users ?? [] as $user): ?>
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
['type' => 'component', 'name' => 'card', 'data' => ['title' => '{{ user.name }}', 'body' => 'About']]
```

`name` is required (literal, the template name); `data` is an optional mapping whose keys are literals and values support `{{ path }}` interpolation (compiled as PHP-context concatenation, not pre-escaped); values must be strings. An empty mapping carries no entries, so it compiles to the same no-argument call as omitting `data` altogether. Compiles to:

```php
<?= $this->component('card', [
    'title' => ($user['name'] ?? ''),
    'body' => 'About',
]) ?>
```

Interpolated values reach the component unescaped; escaping is the component template's decision (text with `$this->e()`, trusted HTML with `$this->raw()`) — pre-escaping at compile time would stack into double escaping.

### 6.9 el

```php
['type' => 'el', 'tag' => 'div', 'x-data' => '{ open: false }', 'body' => [...]]
```

`tag` is required (lowercase HTML tag name, `/^[a-z][a-z0-9-]*$/`); `body` is a child node tree, optional (omitting it means "empty"). Omitting differs from "wrote a non-list": `body` as `null` (a left-empty key in a mapping source), a string, or a single node mapping is a compile error, not treated as an empty body. It accepts arbitrary passthrough attributes. Compiles to:

```php
<div x-data="{ open: false }">
<h3>## $user['name'] ?? '' ##</h3>
Content
</div>
```

An empty `body` outputs the self-closing form `<div></div>` (attributes preserved).

## 7. IR contract: data binding

### 7.1 Path expressions

A path is the only vehicle for data binding, with a strict grammar:

```
path   := segment ( "." segment )*
segment := [A-Za-z_][A-Za-z0-9_]*
```

The first segment is the variable name; later segments are array-key accesses. Compiled access carries a fallback per context: `?? ''` in text/attribute contexts, `?? null` in conditional contexts, `?? []` in loop contexts (`each.items` / `table.items`, letting empty data render as empty rather than error).

### 7.2 The two contexts of `{{ path }}` interpolation

| Context | How it compiles | Example |
|---------|-----------------|---------|
| HTML text / attribute (text, heading, link, attribute values, ...) | keeps the `## expr ##` sugar, escaped at template render | `href="/users/## $user['id'] ?? '' ##"` |
| PHP array literal (`component`'s `data`) | string concatenation `'...' . ($expr) . '...'`, **not pre-escaped** | `'title' => 'Edit ' . ($user['name'] ?? '')` |

The PHP context must never emit `## ##` sugar — `TemplateCompiler` would replace it inside a PHP string literal and cause a syntax error.

### 7.3 Invalid paths and interpolation symbols

Anything inside `{{ ... }}` that does not match the path grammar (function calls, arithmetic, string literals, nested interpolation) is a compile error. Interpolation uses at most two braces: the presence of `{{{` or `}}}` is a compile error (three braces defeat the pairing count and leave mangled braces in the output).

### 7.4 Data shape constraint

Paths compile to array access (`$user['name']`). Page data is agreed to be **array-shaped**, normalized by the controller at the boundary (Domain entities become arrays). This is a documented constraint; this module does no object compatibility.

## 8. Front-end integration points (hooks)

Frontend packages extend `MiGears\Pages\Compiler` and only implement parsing and spelling differences:

| Hook | Signature | Semantics |
|------|-----------|-----------|
| `parse` | `parse(string $source): array` | source syntax → IR (the abstract base throws: array pages call `compile()` directly) |
| `mapAttributeName` | `(string $name, string $path): string` | frontend spelling → output name. The base accepts `@event` as-is; XML overrides `__click` → `@click` |
| `attributeCandidates` | `(array $n, list<string> $dslFields): list<array{name, value, explicit}>` | attribute candidate enumeration. Mapping-shaped sources (array/YAML) read from the node itself by default; XML overrides to read from `<attr>` children, where `explicit` marks explicit attributes (skip name mapping, output as-is) |
| `normalizeAttrValue` | `(mixed $value, string $name, string $path): string` | scalar normalization (§5.2); string sources are naturally all strings, no override needed |
| `newException` | `(string $message): CompileException` | the exception class; a frontend overrides it to return its own, and one `catch` covers every error |
| `nodeRef` / `containerRef` / `explicitAttrRef` | `(string): string` | the wording for node/container/explicit-attribute in error messages |
| `COLON_ONLY_DIRECTIVES` | `array<string, list<string>>` | the hyphen-form interception table (defaults to Alpine's three directives) |

The base class also provides the public entry points: `compile(array $page)`, `compileSource(string $source)` (goes through `parse()`), `compileFile(string $path)`, `compileToFile(string $sourcePath, ?string $outputDir)` (`xxx.page.*` → `xxx.tpl.php`). The file helpers name their own failures — a missing or unreadable source, an output directory that cannot be created, a target that cannot be written — instead of letting a PHP warning through and then reporting a file that was never written.

## 9. Renderer facade

`MiGears\Pages\Renderer` provides the one-step entry from an array DSL to HTML:

```php
$renderer = new Renderer($template, $compiler, $cacheDir);
echo $renderer->render($page, $data);
```

- Internally it does: `compile()` → write to `$cacheDir` (content-addressed: `page_<md5(source)>.tpl.php`, not rewritten while the declaration is unchanged) → `$template->render()`.
- On construction it registers `$cacheDir` into `$template`'s search paths; layouts and components still resolve through the template paths the user already configured. Because it is an unshift, the derived directory comes **before** the user's template directories: normal names (`page_<md5>`) do not collide, but the direction is "derivatives win".
- Rendering the same declaration twice hits the cache file and does one disk write.
- The cache **only ever grows**: a changed declaration writes a new file, and old ones are not auto-pruned. `clearCache()` deletes the `page_*.tpl.php` this class wrote and returns the deleted count (repeated calls return 0); the cache directory itself stays, and pages are recompiled on the next `render()`. Clearing matches that name exactly, so hand-written templates, foreign files, and the template compiler's own outputs sharing the directory are unaffected. The template compiler's own outputs are managed by `Template` and are outside this API — to reset both at once, deleting the whole cache directory remains safe.

## 10. User syntax: the Html factory

`MiGears\Pages\Html` is the syntax for page authors: one factory per node, named after the HTML it outputs, with the remaining fields filled by same-named member methods.

**Factory names are all caps; member methods are lowercase.** Uppercase is a node, lowercase is a field, so `h5::INPUT('email')->label('邮箱')` reads as "a label plus an attribute". `->THEN()` and `->ELSE()` are the only two uppercase methods because they name statements rather than attributes. Two points to note: PHP method names are case-insensitive, so the lowercase spelling of a factory and the all-caps one point to the same method and this layer cannot block the lowercase spelling; and all-caps method names deviate from the PSR-1 / PSR-12 camelCase requirement — a deliberate exception of this layer. The convention is guarded by `tests/FactoryNamingTest.php` — it inspects the methods declared by `Html` and scans the package's own README, spec, `docs/`, examples, and source for factory-call spelling. When a single tag name is mentioned it follows HTML habit and is lowercase (`textarea`, `select`); only factory-call sites are all-caps; the normalized `type` also stays in the lowercase vocabulary (`h5::IF` → `type: if`), matching what XML and YAML parse into.

```php
use MiGears\Pages\Html as h5;

h5::HEADING(2)->text('User list')->id('usersTitle')->class('page-title')
h5::TABLE('users')->columns([h5::COL('Name')->pop('{{ row.name }}')])->empty('No data')
h5::FORM('/users/save')->fields([h5::INPUT('email')->label('Email')->type('email')])
```

**It is just sugar; its landing shape is still an array.** A factory returns a `Node` object, and `Compiler::compile()` recursively normalizes the whole tree into the §4 array IR at the entry; after that it shares the same compilation path as a hand-written array, XML, and YAML — the same node vocabulary, the same validation, the same error wording. Frontend `parse()` still produces arrays; this layer has no effect on them.

**Factories only name fields; they do not validate.** Unknown attributes, out-of-range `level`, misplaced `placeholder`, and missing required fields are all thrown by the compiler at compile time as path-carrying `CompileException`; this layer neither duplicates validation rules nor changes the error wording.

A factory takes the value that "no longer holds once you leave this node"; the iteration nodes `EACH` and `TABLE` additionally accept an optional loop header; every other factory takes exactly one argument:

| Factory | Argument | Normalized node |
|---------|----------|-----------------|
| `h5::TEXT` | `text` | `type: text` |
| `h5::HEADING` | `level` (default 1) | `type: heading` |
| `h5::LINK` | `href` | `type: link` |
| `h5::IF` | `when` | `type: if` |
| `h5::EACH` | `items`, `as`, `index` (last two optional) | `type: each` |
| `h5::FORM` | `action` | `type: form` |
| `h5::INPUT` | `name` | `type: field` + `input: text` |
| `h5::TEXTAREA` | `name` | `type: field` + `input: textarea` |
| `h5::SELECT` | `name` | `type: field` + `input: select` |
| `h5::TABLE` | `items`, `as` (optional) | `type: table` |
| `h5::COL` | `label` | `type: column` |
| `h5::COMPONENT` | `name` | `type: component` |
| `h5::EL` | `tag` | `type: el` |

**Loop header as optional arguments.** The signatures are `EACH(string $items, ?string $as = null, ?string $index = null)` and `TABLE(string $items, ?string $as = null)`: the required value comes first, the header arguments after and omissible — so either named args (`EACH('users', as: 'user', index: 'i')`, PHP 8 named arguments, making the parameter names public API) or just `EACH('users')` work. When omitted, the fields are not written and the compiler supplies the §6 defaults (`as` is `item` / `row`; `index` introduces no index variable — **no default `$i` binding**, or nested loops would fight over the same name, the inner shadowing the outer silently); when given, the field is written, and calling `->AS()` / `->INDEX()` afterwards throws `\LogicException` for repeated setting. `Node::AS()` / `Node::INDEX()` stay, equivalent to the named arguments. "Every other factory takes one argument" is guarded by `HtmlTest::testIterationFactoriesTakeTheLoopHeaderOthersTakeOneArgument()` via reflection, so this exception cannot silently spread.

Member methods fall into three groups:

- **Base-class methods** (field names are exactly the §5 / §6 field names): `text`, `target`, `THEN`, `ELSE`, `BODY`, `AS`, `INDEX`, `fields`, `method`, `columns`, `empty`, `data`, `label`, `value`, `required`, `placeholder`, `options`, `checked`, `rows`, `pop`, `content`. Using the wrong node (e.g. `label()` on `HEADING`) is not blocked here; the compiler's unknown-key check names it.
- **Attribute methods**: only on nodes that output a tag (`HEADING`, `LINK`, `FORM`, `TABLE`, `EL`, and form controls) — `class`, `id`, `style`, `attr(name, value)`, `on(event, expression)` (outputs `@event`), `bind(name)` (outputs `bind="name"`; the value is a browser-side variable name). Nodes that output no tag have none of these; writing them is a PHP-level `undefined method`.
- **Control methods**: only `h5::INPUT` has `type(control)` (`type` is the `<input>`-only attribute). `h5::TEXTAREA` and `h5::SELECT` fix their control once, in the factory. All three have `popAndBind(reference, attribute = 'bind')`: the `pop` + `bind` shortcut that writes the field's `value` and that attribute (default `bind`; may be `x-model` / `v-model`) to the same data reference, for the common case where front-end and back-end share a variable name; when the two sides differ, write `value()` and `bind()` separately.
- **Server-side vs browser-side split**: `pop` / `value` are server-side (compiled to `## $var['key'] ?? '' ##`, evaluated at render), `bind` only emits an attribute and hands the name to the browser (the value must be a JS variable name/path; writing `{{ }}` is a compile error).

**Repeated setting throws `\LogicException` immediately**, carrying on the "never silently overwrite" stance: writing the same field twice (`->text('a')->text('b')`), the same attribute twice (`->class('a')->class('b')`), or `h5::INPUT(...)->type('a')->type('b')` all fail outright. `Node::toArray()` retrieves the array shape for inspection before compiling.

## 11. Error handling

All errors throw `CompileException` (extends `\RuntimeException`), with a node path in the message:

```
sections.content[2].columns[2]: column specifies both pop and content
```

Error classes:

| Category | Detection | Example |
|----------|-----------|---------|
| Root type error | root is not an array / wrong shape | page: unknown field "foo" |
| Structural error | a top-level rule is violated | specifying both layout and body |
| Unknown node | `type` not in the vocabulary | unknown node type "foo" |
| Missing/invalid field | required missing, enum out of range, wrong type | if missing when; level is 7 |
| Path error | interpolation/path grammar mismatch | invalid path "user name" |
| Context error | pop/content mutually exclusive, etc. | column has both pop and content; pop does not reference the row variable |
| Root field type error | `layout` / `title` not a string, `sections` not a mapping | page: layout must be a string, got array |
| method type error | `form.method` not a string (validated before any cast, no PHP warning leaks) | method must be the string "get" or "post", got array |
| List shape error | node tree / `fields` / `columns` written as a keyed mapping | body[0].then: must be a node tree array (a list), currently a keyed mapping; wrap it in [ ] |
| Field value type error | `field.required` not a boolean, `option` text not a string | required must be a boolean, got string |
| Literal error | `{{ }}` written in a literal field | "empty" is a literal field and does not support {{ }} interpolation |
| Template-layer marker | `##` in a literal field (`label` / `name` / `tag` / `empty` / option, etc.) — these fields are written verbatim with no place to escape | body[0].fields[0]: "label" is a literal; "##" (template-layer syntax) is not allowed |
| Mapping shape error | `sections` / `component.data` written as a list | page: sections must be a mapping of section names to node trees, currently a list |
| Field usage-scope error | `placeholder` / `checked` / `rows` / `value` / `required` on unsupported inputs | "placeholder" is only used for text / password / email / number fields, current input is "select" |
| Embedded structure type error | a field/column `type` mismatches its position | type must be "field" |
| Unknown key | neither a DSL field nor in the passthrough whitelist | unknown attribute "levl" |
| Hyphen directive name | `x-on-*` / `x-bind-*` / `x-transition-*` | write "x-on:click" or "@click" |
| Brace mismatch | interpolation contains `{{{` or `}}}` | interpolation cannot use three consecutive braces |
| Attribute with no mount point | a passthrough attribute on a node that outputs no tag | node "text" emits no tag; wrap the content in type: el |
| Attribute value type error | a passthrough value is not a scalar | the value of attribute "x" must be a scalar, got array |
| Duplicate attribute | the same passthrough attribute appears twice | attribute "class" defined more than once |

It fails fast: the first error throws, and the `CompileException` carries the path from root to node.

## 12. Module structure

```
migears-pages/
├── composer.json            name: migears/pages; require: php ^8.1, migears/template ^2.0
├── README.md                bilingual (Chinese/English), architecture, installation, quick start, user syntax (h5 factory), array DSL reference, custom components, frontend packages, error handling, testing notes
├── LICENSE
├── src/
│   ├── Compiler.php         the compiler (core)
│   ├── Renderer.php         one-step rendering facade (~80 lines)
│   ├── Html.php             user syntax: node factories (§10)
│   ├── Node.php             node base class: field methods, array normalization, repeated-setting guard
│   ├── PlainNode.php        nodes that output no tag
│   ├── TagNode.php          nodes that output a tag (attribute methods: class / id / style / attr / on / bind)
│   ├── FieldNode.php        form controls: positions where the server can fill a value (popAndBind)
│   ├── InputNode.php        `<input>` fields (the `type` method)
│   └── Exception/
│       └── CompileException.php
└── tests/
    ├── CompilerTest.php     node compilation, validation, interpolation, passthrough assertions
    ├── RendererTest.php     full rendering through migears/template
    ├── HtmlTest.php         Html factory: normalization byte-identical to a hand-written array
    ├── FactoryNamingTest.php  naming convention guard: factory names all caps, member methods lowercase (§10)
    └── fixtures/
        └── views/           layouts used by rendering tests
```

Composer dependency note: what runs at runtime is the generated template, which depends on `migears/template`, so it is a `require`. Parsing extensions (ext-yaml, SimpleXML) are declared by each frontend package; this package is not aware of them.

## 13. Test plan (TDD)

Unit tests are driven by array page definitions and assert that the compiled output is byte-identical to (or contains) the expected `.tpl.php`.

| Group | Cases |
|-------|-------|
| Text | text: plain / single interpolation / multiple interpolations / multi-line |
| Structure | heading at each level, out-of-range level error; link href/text interpolation |
| Conditional | if then / then+else / `!` negation / missing when error |
| Loop | each basic / index / nested / missing items error / loop header as named args or methods (equivalent) / omitted header doesn't write fields / both sites set throws repeated-setting / signature reflection guard |
| Form | each input enum / select options / checkbox checked / submit / invalid enum / select missing options / options on unsupported input / method not a string (array, bool, int) type error without leaking PHP warnings / required not boolean / option text not string / placeholder, checked, rows, value, required out of scope errors / required output on select, textarea, checkbox |
| Table | pop columns (`{{ row.x }}`) / content columns / empty / default and custom as / row-variable validation (bare path, other variable errors) / pop+content together error / missing columns error / content and columns non-array or written as mapping each give a readable error |
| bind | any tag and field can output `bind="js.name"`; values containing `{{ }}` or not a JS name error; `popAndBind` writes both value and bind (including the `x-model` spelling) |
| Field id | `id` defaults to `name` (the label's `for` same value); an explicit `id` overrides it and is output once |
| Factory naming | `Html`'s 13 static factories declared all-caps (`TEXT` / `HEADING` / `LINK` / `IF` / `EACH` / `FORM` / `INPUT` / `TEXTAREA` / `SELECT` / `TABLE` / `COL` / `COMPONENT` / `EL`) with no extra static methods; `THEN` / `ELSE` the only two uppercase member methods; all factory-call spellings in README, `docs/`, `spec.md`, examples, source, and tests are all-caps (anti-doc-drift, see §10) |
| Page root | body not an array or written as a single node mapping, layout / title not a string, sections not a mapping, sections value not a list (including null) |
| Collection shape | then / else / body / content / sections values / fields / columns written as a keyed mapping give a readable error, not falling through to `content[type]: node must be an object`; `sections`, `component.data` written as a list give a readable error |
| Warning leakage | data-driven assertions over all malformed inputs: only a `CompileException` (no `TypeError`) and zero PHP warnings |
| Layout | layout+sections / standalone body / both together error / both missing error / title section |
| Component | no data / data interpolation (PHP-context concatenation) / data literal / non-string data value error / `{{ }}` in a data key error / data written as a list error |
| Binding | path-grammar boundaries (invalid characters, empty segment, `!` only in when) |
| Embedded structure | `type: field` / `type: column` correct ones pass, writing the other one errors |
| Literal | literal fields like `field.label`, `table.empty`, `option`, and the keys of `component.data` with `{{ }}` error |
| Template-layer marker | `##` in text, attribute values, and component values is escaped as template-layer syntax (output contains `\##`) and renders verbatim without being taken as an expression (Renderer end-to-end asserts `## Note ##` and `### $user["name"] ###`); `##` in a literal field errors with a path; a single `#` needs no escaping |
| Passthrough | Alpine / Vue / htmx / Livewire directives and `class`/`id`/`style` passthrough; `@click` as-is; value escaping; interpolation inside values; scalar normalization (integer/boolean/null); duplicate attribute error |
| Passthrough misuse | unknown key error; attribute on a tag-less node error; unknown root field error |
| el | with body / empty body / missing tag error / invalid tag error / body as null error |
| Targeted interception | `x-on-click` errors and suggests `x-on:click` or `@click` |
| Interpolation symbol | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` error; adjacent `{{ a }}{{ b }}` allowed |
| Abstract layer | base `compileSource()` throws, pointing to the frontend packages |
| Render | Renderer: body page / layout+sections / auto-escaping / automatic cache-dir creation / rerender on declaration change / component resolution through template paths / `clearCache()` removes derived pages and keeps foreign files |
| Html factory | the 13 factories normalize per the §10 table; each case asserts factory compilation output == the same-content hand-written array byte-for-byte; field / column normalize inside their own containers; nesting (each → el → text) normalizes recursively; Renderer accepts factory nodes directly; unknown attributes, out-of-range level, missing required still throw path-carrying `CompileException` from the compiler |
| Html repeated setting | same field twice, same attribute twice, `input`'s `type` twice all throw `LogicException`; `textarea` / `select` have no `type()`, and tag-less nodes have no attribute methods (PHP-level `undefined method`) |

## 14. Explicitly not done (future candidates)

- Event handling, state management, routing — never
- Expression-language extensions (arithmetic, functions, ternary)
- Runtime parsing / hot reload
- Covering HTML form controls beyond `input` (file upload, date picker, etc.)
- An array-DSL CLI (no source-file form; the CLI belongs to each frontend package)

---

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
- `Html` 工厂：用户级语法，归一后产出与前端解析结果相同的数组 IR（§10）

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

实现规模保持在千行量级（编译器约 1050 行，Renderer 约 80 行）。任何让实现显著膨胀的特性都拒绝。

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
   - 常用 HTML 钩子：`class`、`id`、`style`，以及 `bind`（前端框架的绑定属性，值是浏览器端变量名）
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

### 5.5 集合形态与类型守卫

出现在结构位置的值分两种形态，越界即编译错误：

| 位置 | 形态 | 元素 |
|------|------|------|
| `body`、`sections.<名>`、`if.then`、`if.else`、`each.body`、`el.body`、`column.content` | 列表 | 节点对象 |
| `form.fields`、`table.columns` | 列表 | 字段 / 列对象 |
| `sections`、`field.options`、`component.data` | 映射 | section 名 → 节点树；选项值 → 文本；数据名 → 字符串 |

列表被写成映射（单个节点不加 `[ ]` 包裹、`fields:` 直接跟映射）是最常见的形态错误。这类值本身仍是数组，只查 `is_array()` 会放行，直到更深处才以一个指错对象的报错暴露（`content[type]: 节点必须是对象`）——把「缺列表包裹」误报成「节点不是对象」。因此所有列表入口统一由 `requireList()` 守卫，非数组与映射两种失误都在发生处点名，并说明收到什么类型。

映射被写成列表（`sections` 直接跟一个节点、`component.data` 直接跟若干值）同样没有键名可依，此前会一路走到更深处报出「section 名 0」「data 键 0」。这类入口统一由 `requireMap()` 守卫：空数组视为空映射（`[]` 既是空列表也是空映射，其中没有可被误读的条目）。`field.options` 是刻意的例外——选项值为纯数字时（如 `value="0"`）PHP 键本身就是 `0..n-1`，与列表形态无法区分，故该处只校验必须是数组。

配套的标量类型守卫同理：`layout`、`title` 必须是字符串，`sections` 必须是映射，`field.required` 必须是布尔，`option` 文本必须是字符串。这些值此前靠 `(string)` 强转或 `=== true` 比较处理，遇到不符的值要么泄漏 PHP 警告（`Array to string conversion`），要么被静默忽略——两者都是本模块明令禁止的。

**总契约**：编译期任何错误都必须是带节点路径的 `CompileException`；不得有 PHP 警告泄漏到输出，也不得因类型不符抛出原始 `TypeError`。

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

`href`、`text` 必填，均支持插值。`target` 可选（支持插值），取值不校验——HTML 允许 `_blank` 之外的命名目标，枚举白名单会误杀合法用法。

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
<?php foreach ($users ?? [] as $i => $user): ?>
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
| `name` | string 字面量 | 是 | 字段名（`name` 属性）；同时是 `id` 与 `label` 的 `for` 的默认值 |
| `id` | string 字面量 | 否 | 默认等于 `name`；显式给出时覆盖它，并同时驱动 `label` 的 `for`（只输出一次，不再默认输出） |
| `bind` | JS 变量名/路径 | 否 | 前端框架的绑定属性，输出 `bind="user.email"`；值是浏览器端名字，写 `{{ }}` 即编译错误 |
| `label` | string 字面量 | 是 | 标签文本；`submit` 类型时为按钮文字 |
| `input` | enum | 否 | 见下，默认 `text` |
| `value` | path | 否 | 绑定值，编译为 `value="## $path ?? '' ##"`；可写成 `{{ user.name }}`（推荐，数据更显眼）；不支持 `submit`（按钮文字用 `label`） |
| `required` | bool | 否 | 默认 false；在支持该属性的 input 上输出 `required`，`hidden` / `submit` 上写 `true` 属编译错误 |
| `placeholder` | string | 否 | 仅 text/password/email/number，支持插值；其他 input 上属编译错误 |
| `options` | array | 仅 select | `['admin' => '管理员']` 映射，value 与文本均为字面量 |
| `checked` | path | 仅 checkbox | 真值时输出 `checked` 属性；其他 input 上属编译错误 |
| `rows` | int | 仅 textarea | 默认 4，须为正整数；其他 input 上属编译错误 |

`input` 枚举：`text`、`password`、`email`、`number`、`textarea`、`select`、`checkbox`、`hidden`、`submit`。非法枚举即编译错误。`select` 缺 `options`、`options` 用在不支持的 input 上、`select` 上使用 `value`，均编译错误。

字段的**使用范围**同样是硬约束，越界即编译错误——这些字段此前会被静默丢弃，与本模块「不静默丢弃」的契约相悖：`placeholder` 仅 text/password/email/number、`checked` 仅 checkbox、`rows` 仅 textarea、`value` 不支持 submit、`required` 仅 text/password/email/number/textarea/select/checkbox。`required` 为真时在 select / textarea / checkbox 上同样输出 `required` 属性。

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

**column**：`label` 必填（字面量）；`pop`（服务端要渲染进单元格的数据引用，写成 `'{{ row.id }}'`）与 `content`（节点树，行变量作用域）**二选一必填**，同时提供即编译错误。`pop` 的值必须带 `{{ }}` 且首段等于该表格的 `as` 变量（默认 `row`）——不带花括号的裸路径、或引用了别的变量，都编译错误。

> 词汇约定：本模块**只用 `pop` 指代「PHP 把数据渲染进页面」**，不用 `bind` 指代自己的概念；`bind` 专指前端框架的绑定属性（浏览器端）。两者合用时写法与 `popAndBind` 见 §10。

编译为：

```php
<table>
<thead><tr><th>ID</th><th>姓名</th></tr></thead>
<tbody>
<?php if (($users ?? []) === []): ?>
  <tr><td colspan="2">暂无数据</td></tr>
<?php else: ?>
<?php foreach ($users ?? [] as $user): ?>
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

`name` 必填（字面量，即模板名）；`data` 可选映射，键为字面量，值支持 `{{ path }}` 插值（PHP 上下文拼接编译，不预转义），值必须为字符串。空映射没有任何条目，因此与完全省略 `data` 编译为同一个不带参的调用。编译为：

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

`tag` 必填（小写 HTML 标签名，`/^[a-z][a-z0-9-]*$/`）；`body` 为子节点树，可省略（省略即视为空）。省略与「写了个非列表值」不同：`body` 写成 `null`（映射源里留空的键）、字符串或单个节点映射都是编译错误，不会被当成空 body。接受任意透传属性。编译为：

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

首段即变量名，后续段为数组键访问。编译后的访问按上下文带兜底：文本/属性上下文 `?? ''`、条件上下文 `?? null`、循环上下文 `?? []`（`each.items` / `table.items`，让空数据自然渲染为空而不是报错）。

### 7.2 插值 `{{ path }}` 的两种上下文

| 上下文 | 编译方式 | 示例 |
|--------|----------|------|
| HTML 文本 / 属性（text、heading、link、属性值等） | 原样保留 `## expr ##` 糖，模板引擎渲染时转义 | `href="/users/## $user['id'] ?? '' ##"` |
| PHP 数组字面量（component 的 `data`） | 字符串拼接 `'...' . ($expr) . '...'`，**不预转义** | `'title' => '编辑 ' . ($user['name'] ?? '')` |

PHP 上下文绝不能输出 `## ##` 糖——它会被 TemplateCompiler 二次替换进 PHP 字符串字面量，造成语法错误。

### 7.3 非法路径与插值符号

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

基类还提供公共入口：`compile(array $page)`、`compileSource(string $source)`（走 `parse()`）、`compileFile(string $path)`、`compileToFile(string $sourcePath, ?string $outputDir)`（`xxx.page.*` → `xxx.tpl.php`）。文件相关入口自行命名失败原因——源文件不存在或不可读、输出目录无法创建、目标文件无法写入——而不是放行 PHP 原生警告、再报告一个根本没写出来的文件。

## 9. Renderer 门面

`MiGears\Pages\Renderer` 提供从数组 DSL 一步到 HTML 的入口：

```php
$renderer = new Renderer($template, $compiler, $cacheDir);
echo $renderer->render($page, $data);
```

- 内部完成：`compile()` → 写入 `$cacheDir`（内容寻址：`page_<md5(source)>.tpl.php`，声明不变不重写）→ `$template->render()`。
- 构造时把 `$cacheDir` 注册进 `$template` 的搜索路径，布局与组件仍走用户已配置的模板路径。因为是 unshift，派生目录排在用户模板目录**之前**：正常命名（`page_<md5>`）不会碰撞，但方向上是「派生物优先」。
- 两次渲染同一声明时命中缓存文件，只做一次磁盘写入。
- 缓存**只增不减**：声明一变就写新文件，旧文件不自动清理。`clearCache()` 删除本类写出的 `page_*.tpl.php` 并返回删除数量（重复调用返回 0），缓存目录本身保留，页面在下次 `render()` 时重新编译回填；清理只按该命名精确匹配，因此共享该目录的手写模板、外来文件与模板编译器产物都不受影响。模板编译器自身的产物归 `Template` 管理，不在本 API 范围内——要一次性重置两处，整体删除缓存目录仍然安全。

## 10. 用户级语法：Html 工厂

`MiGears\Pages\Html` 是给页面作者用的语法：一个节点一个工厂，工厂名与它输出的 HTML 对齐，其余字段用同名成员方法补齐。

**工厂名全大写，成员方法小写。** 大写是节点、小写是字段，`h5::INPUT('email')->label('邮箱')` 因此读起来就是「标签加属性」。`->THEN()`、`->ELSE()` 是仅有的两个大写成员方法，因为它们命名的是语句而不是属性。两点必须记明：一是 PHP 方法名不区分大小写，工厂名的小写拼写与全大写指向同一个方法，本层无法拦截小写写法；二是全大写方法名偏离 PSR-1 / PSR-12 的 camelCase 要求，属于本层有意的例外。约定由 `tests/FactoryNamingTest.php` 守住——它检查 `Html` 声明的方法名，并扫描本包自己的 README、规格、`docs/`、示例与源码里工厂调用的拼写。单个提到标签名时按 HTML 习惯写小写（`textarea`、`select`），只有工厂调用处的名字全大写；归一后的 `type` 也仍是小写词表（`h5::IF` → `type: if`），与 XML、YAML 解析出的模型一致。

```php
use MiGears\Pages\Html as h5;

h5::HEADING(2)->text('用户列表')->id('usersTitle')->class('page-title')
h5::TABLE('users')->columns([h5::COL('姓名')->pop('{{ row.name }}')])->empty('暂无数据')
h5::FORM('/users/save')->fields([h5::INPUT('email')->label('邮箱')->type('email')])
```

**它只是语法糖，落地形态仍是数组。** 工厂返回 `Node` 对象，`Compiler::compile()` 在入口把整棵树递归归一成 §4 的数组 IR；此后与手写数组、XML、YAML 走同一条编译路径——同一套节点词表、同一套校验、同一套错误文案。前端包 `parse()` 产出的仍是数组，本层对它们没有任何影响。

**工厂只命名字段，不校验。** 未知属性、越界 `level`、错放的 `placeholder`、缺失的必填字段，全部由编译器在编译期抛带路径的 `CompileException`；本层不复制校验规则，也不更改错误措辞。

工厂收「离开它这个节点就不成立」的那个值；迭代节点 `EACH` 与 `TABLE` 额外收可选的循环头，其余工厂一律只收一个参数：

| 工厂 | 参数 | 归一后的节点 |
|------|------|--------------|
| `h5::TEXT` | `text` | `type: text` |
| `h5::HEADING` | `level`（默认 1） | `type: heading` |
| `h5::LINK` | `href` | `type: link` |
| `h5::IF` | `when` | `type: if` |
| `h5::EACH` | `items`、`as`、`index`（后两个可选） | `type: each` |
| `h5::FORM` | `action` | `type: form` |
| `h5::INPUT` | `name` | `type: field` + `input: text` |
| `h5::TEXTAREA` | `name` | `type: field` + `input: textarea` |
| `h5::SELECT` | `name` | `type: field` + `input: select` |
| `h5::TABLE` | `items`、`as`（可选） | `type: table` |
| `h5::COL` | `label` | `type: column` |
| `h5::COMPONENT` | `name` | `type: component` |
| `h5::EL` | `tag` | `type: el` |

**循环头作为可选参数。** 签名是 `EACH(string $items, ?string $as = null, ?string $index = null)` 与 `TABLE(string $items, ?string $as = null)`：必填项在前，头参数在后且可省略，所以既可以用命名参数（`EACH('users', as: 'user', index: 'i')`，PHP 8 的名字参数，参数名因此是公开 API），也可以只写 `EACH('users')`。省略时不写入对应字段，由编译器补 §6 的默认值（`as` 为 `item` / `row`，`index` 则是不引入下标变量——**不默认绑 `$i`**，否则嵌套循环会互抢同名变量、内层静默遮蔽外层）；显式给出即写入该字段，之后再调 `->AS()` / `->INDEX()` 会按重复设置抛 `\LogicException`。`Node::AS()` / `Node::INDEX()` 保留，与命名参数等价。"其余工厂只收一个参数"由 `HtmlTest::testIterationFactoriesTakeTheLoopHeaderOthersTakeOneArgument()` 用反射守住，避免这个例外悄悄扩散。

成员方法按归属分三组：

- **基类方法**（字段名即 §5 / §6 的字段名）：`text`、`target`、`THEN`、`ELSE`、`BODY`、`AS`、`INDEX`、`fields`、`method`、`columns`、`empty`、`data`、`label`、`value`、`required`、`placeholder`、`options`、`checked`、`rows`、`pop`、`content`。用错节点（如 `HEADING` 上调 `label()`）不在此层拦截，由编译器的未知键检查点名。
- **属性方法**：只出现在输出标签的节点（`HEADING`、`LINK`、`FORM`、`TABLE`、`EL`，以及表单控件）上——`class`、`id`、`style`、`attr(name, value)`、`on(event, expression)`（输出 `@event`）、`bind(name)`（输出 `bind="name"`，值是浏览器端变量名）。不输出标签的节点没有这些方法，写出来是 PHP 层的 `undefined method`。
- **控件方法**：只有 `h5::INPUT` 有 `type(control)`（`type` 是 `<input>` 独有的属性）。`h5::TEXTAREA` 与 `h5::SELECT` 的控件由工厂一次定下。三者都有 `popAndBind(reference, attribute = 'bind')`：`pop` 与 `bind` 合用的 shortcut，把字段的 `value` 与该属性（默认 `bind`，可传 `x-model` / `v-model`）写成同一个数据引用，用于前后端变量同名的常见情形；两侧不一致时分开写 `value()` 与 `bind()`。
- **服务端与浏览器端的分工**：`pop` / `value` 走服务端（编译成 `## $var['key'] ?? '' ##`，渲染时求值），`bind` 只产出属性、名字交给浏览器（值必须是 JS 变量名/路径，写 `{{ }}` 即编译错误）。

**重复设置立即抛 `\LogicException`**，沿用「不静默覆盖」的立场：同一字段写两次（`->text('a')->text('b')`）、同一属性写两次（`->class('a')->class('b')`）、`h5::INPUT(...)->type('a')->type('b')` 都直接失败。`Node::toArray()` 可取回数组形态，便于在编译前检查。

## 11. 错误处理

所有错误抛 `CompileException`（继承 `\RuntimeException`），信息带节点路径，格式：

```
sections.content[2].columns[2]: 列同时指定 pop 与 content
```

错误分类：

| 类别 | 检测 | 示例 |
|------|------|------|
| 根类型错误 | 根不是数组/结构不符 | page: 未知字段 "foo" |
| 结构错误 | 顶层规则违反 | 同时指定 layout 与 body |
| 未知节点 | type 不在词表 | 未知节点类型 "foo" |
| 字段缺失/非法 | 必填缺失、枚举越界、类型不符 | if 缺 when；level 为 7 |
| 路径错误 | 插值/路径文法不匹配 | 非法路径 "user name" |
| 上下文错误 | pop/content 互斥等 | column 同时含 pop 与 content；pop 未引用行变量 |
| 根字段类型错误 | `layout` / `title` 不是字符串，`sections` 不是映射 | page: layout 必须是字符串，收到 array |
| method 类型错误 | `form.method` 不是字符串（校验先于任何强转，不泄漏 PHP 警告） | method 必须是字符串 "get" 或 "post"，收到 array |
| 列表形态错误 | 节点树 / `fields` / `columns` 写成键值映射 | body[0].then: 必须是节点树数组（列表），当前是键值映射；请用 [ ] 包成列表 |
| 字段值类型错误 | `field.required` 不是布尔，`option` 文本不是字符串 | required 必须是布尔值，收到 string |
| 字面量错误 | 字面量字段写了 `{{ }}` | "empty" 是字面量字段，不支持 {{ }} 插值 |
| 模板层标记 | 字面量字段（`label` / `name` / `tag` / `empty` / option 等）里出现 `##`——这些字段原样写入产物，没有可转义的位置 | body[0].fields[0]: "label" 是字面量，不允许出现 "##"（模板层语法） |
| 映射形态错误 | `sections` / `component.data` 写成列表 | page: sections 必须是 section 名到节点树的映射（键值映射），当前是列表 |
| 字段使用范围错误 | `placeholder` / `checked` / `rows` / `value` / `required` 用在不支持的 input 上 | "placeholder" 仅用于 text / password / email / number 字段，当前 input 是 "select" |
| 内嵌结构类型错误 | field/column 的 type 与位置不符 | type 必须是 "field" |
| 未知键 | 既非 DSL 字段，也不在透传白名单 | 未知属性 "levl" |
| 连字符指令名 | `x-on-*` / `x-bind-*` / `x-transition-*` | 请写 "x-on:click" 或 "@click" |
| 花括号错乱 | 插值出现 `{{{` 或 `}}}` | 插值符号不能连续三个花括号 |
| 属性无挂载点 | 透传属性出现在不输出标签的节点上 | 节点 "text" 不输出标签，请用 type: el 包裹内容 |
| 属性值类型错误 | 透传属性值不是标量 | 属性 "x" 的值必须是标量，收到 array |
| 重复属性 | 同名透传属性出现两次 | 属性 "class" 重复定义 |

失败即中止（fail-fast）：首个错误抛出，`CompileException` 携带从根到节点的路径。

## 12. 模块结构

```
migears-pages/
├── composer.json            name: migears/pages; require: php ^8.1, migears/template ^2.0
├── README.md                双语（中英）、架构、安装、快速开始、用户语法（h5 工厂）、数组 DSL 参考、自定义组件、前端包、错误处理、测试说明
├── LICENSE
├── src/
│   ├── Compiler.php         编译器（核心）
│   ├── Renderer.php         一步渲染门面（约 80 行）
│   ├── Html.php             用户级语法：节点工厂（§10）
│   ├── Node.php             节点基类：字段方法、数组归一、重复设置守卫
│   ├── PlainNode.php        不输出标签的节点
│   ├── TagNode.php          输出标签的节点（属性方法：class / id / style / attr / on / bind）
│   ├── FieldNode.php        表单控件：可被服务端填值的位置（popAndBind）
│   ├── InputNode.php        `<input>` 字段（`type` 方法）
│   └── Exception/
│       └── CompileException.php
└── tests/
    ├── CompilerTest.php     节点编译、校验、插值、透传断言
    ├── RendererTest.php     经 migears/template 完整渲染验证
    ├── HtmlTest.php         Html 工厂：归一结果与手写数组逐字节一致
    ├── FactoryNamingTest.php 命名约定守卫：工厂名全大写、成员方法小写（§10）
    └── fixtures/
        └── views/           渲染测试用布局
```

composer 依赖说明：运行期执行的是生成的模板，依赖 migears/template，故设为 `require`。解析扩展（ext-yaml、SimpleXML）由前端包各自声明，本包不感知。

## 13. 测试计划（TDD）

单元测试以数组页面定义驱动，断言编译产物与期望 `.tpl.php` 完全一致（或含指定片段）。

| 分组 | 用例 |
|------|------|
| 文本 | text 纯文本 / 单插值 / 多插值 / 多行 |
| 结构 | heading 各级、越界 level 报错；link href/text 插值 |
| 条件 | if then / then+else / `!` 取反 / when 缺失报错 |
| 循环 | each 基础 / index / 嵌套 / items 缺失报错 / 循环头写成命名参数或方法（等价）/ 省略头参数不写字段 / 同一字段两处都写抛重复设置 / 签名反射守卫 |
| 表单 | 各 input 枚举 / select options / checkbox checked / submit / 非法枚举 / select 缺 options / options 用在不支持的 input / method 非字符串（array、bool、int）报类型错误且不泄漏 PHP 警告 / required 非布尔 / option 文本非字符串 / placeholder、checked、rows、value、required 越界报错 / required 在 select、textarea、checkbox 上输出 |
| 表格 | pop 列（`{{ row.x }}`）/ content 列 / empty / as 默认与自定义 / 行变量校验（裸路径、别的变量报错）/ pop+content 同存报错 / columns 缺失报错 / content 与 columns 非数组、写成映射均报可读错误 |
| bind | 任意标签与字段可输出 `bind="js.name"`；值含 `{{ }}` 或不是 JS 名字时报错；`popAndBind` 同时写出 value 与 bind（含 `x-model` 拼写） |
| 字段 id | `id` 默认等于 `name`（label 的 `for` 同值）；显式 `id` 覆盖它且只输出一次 |
| 工厂命名 | `Html` 的 13 个静态工厂声明为全大写（`TEXT` / `HEADING` / `LINK` / `IF` / `EACH` / `FORM` / `INPUT` / `TEXTAREA` / `SELECT` / `TABLE` / `COL` / `COMPONENT` / `EL`），且没有多出别的静态方法；`THEN` / `ELSE` 是仅有的两个大写成员方法；README、`docs/`、`spec.md`、示例、源码与测试里出现的工厂调用拼写全部大写（防文档漂移，见 §10） |
| 页面根 | body 非数组或写成单个节点映射、layout / title 非字符串、sections 非映射、sections 值非列表（含 null） |
| 集合形态 | then / else / body / content / sections 值 / fields / columns 写成键值映射时报可读错误，不落到 `content[type]: 节点必须是对象`；`sections`、`component.data` 写成列表时报可读错误 |
| 警告泄漏 | 数据驱动断言全部畸形输入：只抛 CompileException（不是 TypeError），且零 PHP 警告 |
| 布局 | layout+sections / body 独立 / 两者同存报错 / 双缺失报错 / title section |
| 组件 | 无 data / data 插值（PHP 上下文拼接）/ data 字面量 / data 值非字符串报错 / data 键写 `{{ }}` 报错 / data 写成列表报错 |
| 绑定 | 路径文法边界（非法字符、空段、`!` 只允许 when） |
| 内嵌结构 | `type: field` / `type: column` 写对可通过，写成另一种即报错 |
| 字面量 | `field.label`、`table.empty`、`option`、`component.data` 的键等字面量字段写 `{{ }}` 报错 |
| 模板层标记 | 文本、属性值、组件值里出现 `##` 时按模板层语法转义（产物含 `\##`），渲染后原样输出且不被当作表达式（Renderer 端到端断言 `## 说明 ##` 与 `### $user["name"] ###`）；字面量字段里出现 `##` 报错并带路径；单个 `#` 不需转义 |
| 透传 | Alpine / Vue / htmx / Livewire 指令与 `class`/`id`/`style` 透传；`@click` 原样；值转义；值内插值；标量归一（整数/布尔/空值）；重复属性报错 |
| 透传误用 | 未知键报错；无标签节点承载属性报错；页面根未知字段报错 |
| el | 带 body / 空 body / 缺 tag 报错 / 非法 tag 报错 / body 写成 null 报错 |
| 定向拦截 | `x-on-click` 报错并提示 `x-on:click` 或 `@click` |
| 插值符号 | `{{{ a }}}` / `{{ a }}}` / `{{{ a }}` 报错；相邻的 `{{ a }}{{ b }}` 放行 |
| 抽象层 | 基类 `compileSource()` 抛错提示使用前端包 |
| 渲染 | Renderer：body 页 / layout+sections / 自动转义 / 缓存目录自动创建 / 声明变更重渲染 / 组件经模板路径解析 / `clearCache()` 清理派生页面并保留外来文件 |
| Html 工厂 | 13 个工厂的归一结果与 §10 表格一致；每例断言「工厂编译产物 == 同内容手写数组的产物」逐字节相同；field / column 在各自容器内归一；嵌套（each → el → text）递归归一；Renderer 直接接受工厂节点；未知属性、越界 level、缺必填仍由编译器抛带路径的 `CompileException` |
| Html 重复设置 | 同字段两次、同属性两次、`input` 的 `type` 两次均抛 `LogicException`；`textarea` / `select` 无 `type()`、不输出标签的节点无属性方法（PHP 层 `undefined method`） |

## 14. 明确不做（后续候选）

- 事件处理、状态管理、路由——永不进入
- 表达式语言扩展（算术、函数、三元）
- 运行期解析 / 热更新
- 覆盖 `input` 之外的 HTML 表单控件（文件上传、日期选择等）
- 数组 DSL 的 CLI（无源文件形态；CLI 属于各前端包）
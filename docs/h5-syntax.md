# miGears/pages User Syntax Guide (h5 factory)

> This file is the complete user-level syntax reference for `miGears/pages`, backed by the implementation `MiGears\Pages\Html` (contract in §10 of `spec.md`).
> The factory alias is chosen by the caller: `use MiGears\Pages\Html as h5;`. This document uses `h5` throughout; writing `h` works just as well.

## 1. Position of the builder in the overall system

`migears/pages` takes a PHP array as input, and the shape of that array is exactly the IR contract—the "page declaration, field rules, node grammar, data binding" defined in §4 through §7 of `spec.md`. The fluent builder does not change this contract: every factory method returns a node object, chained methods write fields, and the compiler normalizes these objects into arrays at the single `compile()` entry point. Everything after that is identical to writing the array by hand.

```php
h5::HEADING(2)->text('User list')->id('usersTitle')
// normalizes to
['type' => 'heading', 'level' => 2, 'text' => 'User list', 'id' => 'usersTitle']
```

```php
h5::TEXTAREA('bio')->label('About')->rows(5)
// normalizes to
['type' => 'field', 'input' => 'textarea', 'name' => 'bio', 'label' => 'About', 'rows' => 5]
```

The three control factories (`INPUT`, `TEXTAREA`, `SELECT`) produce the IR `field` structure, and `COL` produces the `column` structure; after normalization they are byte-for-byte identical to hand-written arrays. Note that casing exists only at the call site: factory names are uppercase, while the normalized `type` remains a lowercase vocabulary word (`h5::IF(...)` lands on `['type' => 'if', ...]`), matching the model that the XML and YAML frontends produce.

Normalization happens at the single `compile()` entry point and recognizes only instances of `MiGears\Pages\Node`; the XML and YAML frontend packages still emit only arrays, so their pathing and validation are untouched by a single line. All three entry points therefore share the same validation and the same error messages; the builder only does shape conversion and does not re-validate—a mistyped path, an out-of-range `level`, or a misplaced `placeholder` is still named by the compiler at compile time.

## 2. Installation and minimal example

```bash
composer require migears/pages
```

```php
use MiGears\Pages\Compiler;
use MiGears\Pages\Html as h5;
use MiGears\Pages\Renderer;
use MiGears\Template\Template;

// Compile directly to a template file
$compiler = new Compiler();
$source = $compiler->compile([
    'body' => [
        h5::HEADING(2)->text('User list'),
        h5::TEXT('{{ total }} total'),
    ],
]);
$compiler->compileToFile(...);   // or write it out yourself

// Or render in one step
$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render(['body' => [h5::TEXT('Hello, {{ user.name }}')]], ['user' => ['name' => '张三']]);
```

Node objects can be embedded in arrays or nested in one another; the compiler normalizes recursively:

```php
h5::EACH('users')->BODY([
    h5::EL('li')->class('item')->BODY([h5::TEXT('{{ user.name }}')]),
])
```

The alias is decided by the caller; `h5` is just this document's choice—after `use MiGears\Pages\Html as h;`, `h::TEXTAREA('bio')` works just as well.

## 3. Five rules

**Factory names are all caps; member methods are lowercase.** Nodes are called `h5::TEXTAREA`, `h5::IF`, and fields are called `->label()`, `->pop()`: uppercase is a node, lowercase is a field, so `h5::INPUT('email')->label('Email')` reads at a glance as "label plus attribute", matching HTML's own feel. The two control-flow branches follow the node and are written `->THEN()`, `->ELSE()`, because they name statements rather than attributes. Note that PHP method names are case-insensitive, so spelling a factory name in lowercase still runs and the language cannot block it—this convention is therefore enforced by `tests/FactoryNamingTest.php`, which checks the methods declared on `Html` and scans this package's own docs, spec, samples, and source. When mentioning a tag name alone, follow HTML convention and write it lowercase (`textarea`, `select`); only the name at the factory call site is uppercase.

**Factory names align with HTML elements.** `INPUT`, `TEXTAREA`, `SELECT`, `TABLE`, `COL`, `FORM`, `EL` are tag names; `HEADING` maps to `<h1>`–`<h6>`, and `LINK` maps to `<a>`. The four that emit no tag are named by control-flow/component semantics: `TEXT`, `IF`, `EACH`, `COMPONENT`. There is no generic `field` factory for form controls; use whichever factory matches the control you are writing.

**The constructor takes the value that "does not hold if this node is removed."** The heading's level, the element's tag name, the loop's data source, the form's submit URL, the link's target address, the component's name, the control's field name, the column's title—one argument each. Blocks (`->BODY()`, `->THEN()`, `->fields()`) and HTML attributes are methods, and the signature never requires counting arguments. The only exceptions are the iteration nodes `EACH` and `TABLE`: they additionally take an optional **loop header** (`EACH` takes `as` / `index`, `TABLE` takes `as`) so that the names in the `foreach ($users as $i => $user)` header sit together; see 4.5 and 4.7.

**Everything else is a member method, named after its HTML counterpart where possible.** If it is an HTML attribute, use the attribute name (`->type()`, `->href()`, `->target()`, `->method()`, `->value()`, `->placeholder()`, `->checked()`, `->rows()`, `->required()`, `->class()`, `->id()`, `->style()`); if it is an HTML element, use the element name; if there is no corresponding tag, use a name that fits the component's semantics (`->label()`, `->options()`, `->BODY()`, `->AS()`, `->INDEX()`, `->columns()`, `->empty()`, `->pop()`, `->bind()`, `->popAndBind()`, `->content()`, `->data()`). Only `->THEN()`, `->ELSE()` are named after statements rather than attributes.

**Attribute-style methods grow only on nodes that emit a tag.** `HEADING`, `LINK`, `FORM`, `TABLE`, `EL` have `->class()` / `->id()` / `->style()` / `->attr()` / `->on()` / `->bind()`; `TEXT`, `IF`, `EACH`, `COMPONENT` have none of these—they are PHP-level `undefined method` errors, not something discovered only at compile time. If a hand-written array hangs attributes on these nodes, the compiler still reports `node "text" emits no tag; wrap the content with type: el`.

| Factory | Constructor | Member methods |
|------|----------|----------|
| `h5::TEXT` | `(string $text)` | none (emits no tag) |
| `h5::HEADING` | `(int $level)` | `->text()`, attribute group |
| `h5::LINK` | `(string $href)` | `->text()`, `->target()`, attribute group |
| `h5::IF` | `(string $when)` | `->THEN()`, `->ELSE()` |
| `h5::EACH` | `(string $items, ?string $as, ?string $index)` | `->BODY()`, `->AS()`, `->INDEX()` |
| `h5::FORM` | `(string $action)` | `->fields()`, `->method()`, attribute group |
| `h5::INPUT` | `(string $name)` | `->type()`, `->label()`, `->value()`, `->required()`, `->placeholder()` |
| `h5::TEXTAREA` | `(string $name)` | `->label()`, `->value()`, `->required()`, `->rows()` |
| `h5::SELECT` | `(string $name)` | `->label()`, `->options()`, `->required()` |
| `h5::TABLE` | `(string $items, ?string $as)` | `->columns()`, `->AS()`, `->empty()`, attribute group |
| `h5::COL` | `(string $label)` | `->pop()`, `->content()` |
| `h5::COMPONENT` | `(string $name)` | `->data()` |
| `h5::EL` | `(string $tag)` | `->BODY()`, attribute group |

`h5::INPUT('name')` defaults to a text input; `->type()` takes the `<input>` `type` value: `email`, `password`, `number`, `checkbox`, `hidden`, `submit`. `type` is exclusive to `<input>`, so it appears only on the `input` family—`textarea` and `select` have no `->type()` to write.

`level` is 1 by default, consistent with the IR: `h5::HEADING()->text('Title')` is an h1, and `h5::HEADING(2)->text('Title')` is an h2. Valid values are 1–6; out of range is a compiler error.

## 4. Node by node

### 4.1 text

```php
h5::TEXT('Hello, {{ user.name }}')
```

Bare text that emits no tag. The literal part is preserved as-is (HTML is allowed), and interpolations are escaped automatically. It has no member methods—when you need attributes like `class`, wrap it with `el`.

### 4.2 heading

```php
h5::HEADING(2)->text('User list')                      // <h2>User list</h2>
h5::HEADING(2)->text('User list')->id('usersTitle')->class('page-title')
```

```php
// normalizes to
['type' => 'heading', 'level' => 2, 'text' => 'User list', 'id' => 'usersTitle', 'class' => 'page-title']
```

`level` takes values 1–6; out of range is a compile error. `->text()` is required—forgetting it reports `heading: missing string field "text"` at compile time. Chained writing never silently turns a required field into an optional one.

### 4.3 link

```php
h5::LINK('/users/{{ user.id }}/edit')->text('Edit')
h5::LINK('/users/1')->text('Details')->target('_blank')->class('btn')
```

`href` and `->text()` both support interpolation. `target` is not validated—HTML allows named targets other than `_blank`.

### 4.4 if

```php
h5::IF('user.loggedIn')->THEN([
    h5::TEXT('Welcome back, {{ user.name }}'),
])
```

```php
h5::IF('!user.hidden')->THEN([
    h5::TEXT('Visible'),
])->ELSE([
    h5::TEXT('Hidden'),
])
```

`when` is a path and may carry a `!` prefix to negate; `!` belongs only to `when`, and writing it on `each.items` is an invalid path. Omitting `->ELSE()` means no branch is generated; omitting `->THEN()` is a compile error.

### 4.5 each

Write the loop header once; hand the loop body to `->BODY()`:

```php
h5::EACH('users', as: 'user', index: 'i')->BODY([
    h5::TEXT('{{ i }}. {{ user.name }}'),
])
```

Both header arguments may be omitted; when omitted, the field is not written and the compiler fills in its own default:

```php
h5::EACH('users')->BODY([
    h5::EL('li')->BODY([h5::TEXT('{{ item.name }}')]),
])
```

```php
h5::EACH('users', as: 'user')->BODY([
    h5::TEXT('{{ user.name }}'),
])
```

`items` compiles to `foreach ($users ?? [])`, so undefined data naturally renders empty rather than erroring. `as` is the row variable; when omitted the compiler uses the default `item`. `index` is the index variable; when omitted it introduces **no** index variable (not a default `i`—that would make nested loops fight over the same name, with the inner one silently shadowing the outer). Both must be valid PHP variable names.

The loop header can also be written as `->AS()` / `->INDEX()`, equivalent to the named arguments:

```php
h5::EACH('users')->AS('user')->INDEX('i')->BODY([...])
```

Omitted header arguments are not written into the node, so the two forms are interchangeable; writing both sites for the same field throws `LogicException: field set twice: as` under the "never silently overwrite" stance. Named arguments must carry a colon and may only appear after positional arguments: `h5::EACH('users', index: 'i')` is valid (`as` uses its default), while `h5::EACH('users', index: 'i', 'user')` is a syntax error.

`EACH` is an iteration node whose constructor signature takes two extra optional parameters—see the exception note in §3.

### 4.6 form and form controls

```php
h5::FORM('/users/save')->fields([
    h5::INPUT('name')->label('Name')->value('user.name')->required(),
    h5::INPUT('email')->label('Email')->type('email')->placeholder('name@example.com'),
    h5::SELECT('role')->label('Role')->options(['admin' => 'Admin', 'editor' => 'Edit']),
    h5::TEXTAREA('bio')->label('About')->rows(5),
    h5::INPUT('active')->label('Enabled')->type('checkbox')->checked('user.active'),
    h5::INPUT('id')->label('')->type('hidden')->value('user.id'),
    h5::INPUT('save')->label('Save')->type('submit'),
])->method('post')
```

Use whichever factory matches the control you are writing: `h5::INPUT('email')` reads at a glance as a text-style input, and `h5::TEXTAREA('bio')` and `h5::SELECT('role')` are literally tag names. All three are spellings of the same `input` field in the IR:

```php
h5::INPUT('email')->label('Email')->type('email')   // ['type' => 'field', 'input' => 'email', ...]
h5::TEXTAREA('bio')->label('About')                 // ['type' => 'field', 'input' => 'textarea', ...]
h5::SELECT('role')->label('Role')                  // ['type' => 'field', 'input' => 'select', ...]
```

The control is set once, by the factory, and there is no second path that changes it: `textarea` and `select` have no `->type()` (PHP-level `call to undefined method`), and `h5::INPUT('x')->type('email')->type('text')` throws `LogicException: control set twice: already email, cannot be set to text`.

`->required()` with no argument is `true`, matching HTML's boolean-attribute spelling; just don't write it when it isn't needed. `->checked()` and `->value()` take paths (`'user.active'`, `'user.name'`), not literal values.

Controls also have an attribute method `->bind('user.email')`, which produces `bind="user.email"`—the name is handed to the browser-side framework, so the value is a JS variable name/path, and writing `{{ }}` is a compile error. When the frontend and backend variables have the same name, use the shortcut to write both sides at once:

```php
h5::INPUT('email')->label('Email')->type('email')->popAndBind('{{ user.email }}')
// normalizes to ['type' => 'field', 'name' => 'email', 'input' => 'email', 'label' => 'Email',
//          'value' => '{{ user.email }}', 'bind' => 'user.email']
// renders to <input type="email" name="email" id="email" value="## $user['email'] ?? '' ##" bind="user.email">
```

The second argument of `popAndBind()` can be a different binding spelling: `->popAndBind('{{ user.role }}', 'x-model')` produces `x-model="user.role"`. Don't use it when the two sides differ; write `->value()` and `->bind()` separately for clarity. The division of labor between the three names is covered in §5.

Control and field applicability is a hard constraint—crossing it is a compile error, never silently dropped:

| Control | Available member methods |
|------|----------------|
| `h5::INPUT('name')`, `->type('password' / 'email' / 'number')` | `->value()`, `->required()`, `->placeholder()` |
| `h5::TEXTAREA('bio')` | `->value()`, `->required()`, `->rows()` |
| `h5::SELECT('role')` | `->options()` required (compile error if missing); `->required()` optional; writing `->value()` is a compile error |
| `h5::INPUT('active')->type('checkbox')` | `->required()`, `->checked()` |
| `h5::INPUT('id')->type('hidden')` | `->value()`; writing `->required()` is a compile error |
| `h5::INPUT('save')->type('submit')` | `->label()` is the button text; writing `->value()`, `->required()` is a compile error |

`name`, the `label`/`options` key values, `column.label`, and `table.empty` are all literal fields; writing `{{ }}` in them is a compile error.

### 4.7 table and column

```php
h5::TABLE('users')->columns([
    h5::COL('ID')->pop('{{ row.id }}'),
    h5::COL('Name')->pop('{{ row.name }}'),
    h5::COL('Actions')->content([
        h5::LINK('/users/{{ row.id }}/edit')->text('Edit'),
    ]),
])->empty('No data')
```

```php
h5::TABLE('users', as: 'user')->columns([
    h5::COL('Name')->pop('{{ user.name }}'),
])
```

`TABLE` and `EACH` are both iteration nodes, and the row variable can likewise be given once in the constructor (`->AS('user')` is equivalent). `col` shares its name with `<col>` and reads as a table column. `as` defaults to `row`; `->empty()` is the text of the empty-data row (a literal); omitting it generates no empty-state row. Each column's `->pop()` (a data reference the server renders into the cell, written `'{{ user.name }}'`) and `->content()` (a node tree, within the row variable's scope) are mutually exclusive and one of the two is required; writing both is a compile error. The value of `pop` must carry `{{ }}`, and its first segment must equal that table's `as` variable—writing the bare path `'row.name'` or referencing another variable is a compile error.

### 4.8 component

```php
h5::COMPONENT('card')->data([
    'title' => '{{ user.name }}',
    'body' => 'About',
])
```

`name` is a literal (the template name); the keys of `data` are literals, the values must be strings, and the values support interpolation. Components emit no tag, so they have no attribute-style methods. See §6.

### 4.9 el

```php
h5::EL('div')->class('card')->BODY([h5::TEXT('Content')])
```

```php
h5::EL('div')->attr('x-data', '{ open: false }')->class('card')->BODY([
    h5::HEADING(3)->text('Card'),
])
```

```php
h5::EL('button')->on('click', 'open = !open')->BODY([h5::TEXT('Toggle')])
```

`tag` must be a valid lowercase HTML tag name. Omitting `->BODY()` means an empty body, outputting `<div></div>` (attributes preserved); but writing the body as `null`, a string, or a single-node mapping is a compile error and is not treated as an empty body.

Attribute-style methods:

| Method | Output | Description |
|------|------|------|
| `->class('card card--wide')` | `class="card card--wide"` | The value is the HTML `class` attribute verbatim; separate multiple classes with spaces. Don't write the dot from CSS selectors (`.card` would add a stray dot to the class value and break the selector match) |
| `->id('usersTitle')` | `id="usersTitle"` | |
| `->style('margin-top: 8px')` | `style="margin-top: 8px"` | |
| `->attr('data-role', 'admin')` | `data-role="admin"` | Entry point for any attribute on the whitelist |
| `->on('click', 'open = !open')` | `@click="open = !open"` | Event shorthand; use `->attr()` for other spellings like `x-on:click` or `v-on:click` |
| `->bind('user.email')` | `bind="user.email"` | Frontend framework binding attribute; the value is a browser-side variable name/path, and writing `{{ }}` is a compile error (see §5) |

Attribute values support interpolation: `->class('item--{{ user.role }}')`. The pass-through attribute whitelist is: `@event`, any directive name containing a colon (`x-on:click`, `v-on:click`, `wire:click`, `:href`), the `x-` / `v-` / `hx-` / `data-` prefixes, and `class` / `id` / `style` / `bind`; every other name is a compile error—an unknown name is treated as a typo. Hyphenated spellings like `x-on-click` are intercepted separately and prompted to be rewritten as `x-on:click` or `@click`, because although the `x-` prefix would otherwise pass, the directive would be inert in Alpine.

Setting the same attribute twice throws an exception (`->class('a')->class('b')`), following the same compile-time "never silently discard" stance—for multiple classes, write them all at once as `'a b'`. The same holds outside of chaining: `->attr('class', 'a')` followed by `->class('b')` is also a duplicate.

## 5. Data binding, escaping, and errors

**`pop`, `bind`, and `popAndBind` sit on opposite sides.** When this module wants to say "PHP renders data into the page," it always uses `pop` (short for populate); it takes a data reference, compiles into `## $user['name'] ?? '' ##`, and evaluates at render time—`->value()`, `h5::COL()->pop()` are all on this side. `bind` is the browser-side half: it only produces an attribute `bind="user.email"`, handing the name to the frontend framework to resolve; this module does not evaluate it, so the value must be a JS variable name/path, and writing `{{ }}` is a compile error. `popAndBind('{{ user.email }}')` is a shortcut for the two, to be used only when frontend and backend variables have the same name; when they differ, write them separately.

The different spellings of the same "data reference" on the two sides are also deliberate: `pop` takes `{{ user.name }}` (page-layer interpolation, validated and escaped), while `bind` takes `user.name` (browser-side name, output verbatim).

The path grammar is only "variable name plus array keys": anything outside the `user.name`, `users.0.email` shape (function calls, arithmetic, string literals) is a compile error. After compilation it carries a fallback by context: `?? ''` in text and attribute contexts, `?? null` in conditional contexts, `?? []` in loop contexts.

Interpolation has two contexts. In HTML text and attributes, write `{{ user.name }}`, compiled into the template sugar `## $user['name'] ?? '' ##`, escaped by the template engine at render time; the values of `component.data` compile into PHP string concatenation without pre-escaping, leaving the escaping responsibility to the component template. Interpolation markers are at most two braces—`{{{` and `}}}` are both compile errors.

**Page-layer and template-layer markers are not shared.** The page layer writes `{{ path }}`; the template layer (component templates, compiled output) writes `## expr ##`. Page-compiled output is scanned again by `migears/template`, so a literal `##` in a page (Markdown headings, code snippets, etc.) is escaped per the template-layer syntax at compile time and output verbatim at render time, never evaluated as an expression; a single `#` needs no escaping. Literal fields (`label`, `name`, `tag`, `empty`, `option`, etc.) are written into the output verbatim, and a `##` in them is a compile error.

Page data is expected to be array-shaped (`$user['name']`); objects are normalized by the caller at the boundary.

All errors are `CompileException`s with the node path, and the first error is thrown:

```
sections.content[2].columns[2]: column specifies both pop and content
body[0].fields[0]: bind value must be a JS variable name or path (e.g. user.email), got "{{ user.email }}"
body[0].fields[0]: "placeholder" only applies to text / password / email / number fields; current input is "select"
body[1].then: must be a node-tree array (list); got a key-value mapping; wrap it in [ ] as a list
```

No PHP warnings leak, and no raw `TypeError` is thrown for type mismatches.

## 6. Referencing components

The component name is the template name, resolved along the registered search paths: it looks in `<path>/<name>.tpl.php` first, then `<path>/<name>.php`; directories added later via `addPath` come first, so overriding a built-in component is a matter of dropping a same-named file into your own directory.

```php
$template = new Template(__DIR__ . '/views');
$template->addPath(__DIR__ . '/views/components');
```

`data` values can only be strings; they reach the component unescaped, and escaping is decided by the component template—use `$this->e()` for text and `$this->raw()` for trusted HTML. Component template scope is isolated; page variables do not pass through automatically—pass through `data` whatever is needed.

The built-in `card`, `button`, `alert`, and `badge` ship with the two frontend packages (`migears/xml-pages`, `migears/yaml-pages`); using this package alone there are no built-in components—write your own, or copy the frontend package's `components/` directory into your project and register it. Component templates can be plain PHP or `.tpl.php` (in the sugar syntax `## ##` escapes and `### ###` outputs verbatim).

## 7. Two complete pages

### 7.1 List page with layout

```php
$page = [
    'title' => 'User management',
    'layout' => 'layout/admin',
    'sections' => [
        'content' => [
            h5::HEADING(2)->text('User list'),
            h5::IF('users')->THEN([
                h5::TABLE('users')->columns([
                    h5::COL('ID')->pop('{{ row.id }}'),
                    h5::COL('Name')->pop('{{ row.name }}'),
                    h5::COL('Actions')->content([
                        h5::LINK('/users/{{ row.id }}/edit')->text('Edit'),
                    ]),
                ])->empty('No data'),
            ])->ELSE([
                h5::TEXT('No users yet'),
            ]),
        ],
    ],
];
```

With a `layout` you must use `sections` and cannot use `body`; without a `layout` you must use `body` and cannot use `sections`. When `title` is present and a layout is used, a `title` section is generated automatically.

### 7.2 Edit form page

```php
$page = [
    'layout' => 'layout/admin',
    'sections' => [
        'content' => [
            h5::HEADING(2)->text('Edit user'),
            h5::FORM('/users/{{ user.id }}/save')->fields([
                h5::INPUT('name')->label('Name')->value('user.name')->required(),
                h5::INPUT('email')->label('Email')->type('email')->value('user.email')->required(),
                h5::SELECT('role')->label('Role')->options(['admin' => 'Admin', 'editor' => 'Edit']),
                h5::INPUT('save')->label('Save')->type('submit'),
            ])->method('post'),
        ],
    ],
];
```

### 7.3 One-step rendering and caching

```php
$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');

echo $renderer->render($page, ['user' => $user]);

$renderer->clearCache();   // removes the derived pages this renderer wrote; returns the number removed
```

Derived pages are addressed by their content (`page_<md5>.tpl.php`), and are not rewritten as long as the declaration is unchanged; the cache only ever grows, so clean it at deploy time with `clearCache()`, or delete the cache directory entirely.

## 8. Design notes

**A call site reads like a piece of HTML.** `h5::HEADING(2)->text('User list')->id('usersTitle')->class('page-title')` and `<h2 id="usersTitle" class="page-title">User list</h2>` are two spellings of the same thing. The factory name gives the tag; member methods fill in attributes by their HTML names; `input`, `textarea`, `select`, `table`, `col` are themselves tag names, while the `<input>` `type` values are still written on `->type()`, so things that are not elements, like `email`, `checkbox`, `hidden`, `submit`, are never mistaken for elements.

**The constructor has a single argument; iteration nodes add two optional ones.** That argument is the "does not hold if this node is removed" value; fields, no matter how many, go through the method chain, so there is no such question as "what is the ninth positional argument." Nothing in `h5::INPUT('email')->label('Email')->type('email')->required()` has to be memorized as a positional argument. `EACH` and `TABLE` are exceptions, because the `foreach ($users as $i => $user)` header is inherently a single whole, and splitting it into two chained calls would only be harder to read; both arguments come after the required one and are given either as named arguments (`as: 'user'`, `index: 'i'`) or not at all.

**Same-shaped arguments are told apart by method name.** `IF`'s `->THEN()` and `->ELSE()`, `EL`'s `->BODY()` and attribute methods, `EACH`'s `->BODY()` and `->AS()`—each carries its own name, so you never have to look back at the signature.

**The method set itself is the documentation.** Nodes that emit no tag have no attribute methods, so `h5::TEXT('x')->class('a')` is an `undefined method` at the PHP level; `h5::TEXTAREA('bio')->type('email')` likewise errors at the PHP level because control methods only grow on `h5::INPUT`. Following the IDE's autocomplete list lets you read off every writable field of a node.

**Validation still lives in exactly one place.** The factory does not check the node vocabulary: unknown attributes, an out-of-range `level`, a misplaced `placeholder`, and missing required fields are all thrown as path-carrying `CompileException`s by the compiler, so the four entry points—array, XML, YAML, and h5—produce identical error wording. The cost is an extra layer at the `compile()` entry that recursively normalizes `Node` into arrays, making method names and parameter names part of the public API (renaming is a breaking change) and adding a few classes over a plain function set; also, the all-caps method names deviate from the camelCase requirement of PSR-1 / PSR-12, a deliberate exception at this layer. These trade-offs are recorded in §10 of `spec.md`.

---
# miGears/pages 用户语法说明（h5 工厂）

> 本文件是 `miGears/pages` 用户级语法的完整说明，对应实现 `MiGears\Pages\Html`（契约见 `spec.md` 第 10 节）。
> 工厂别名由调用方选择：`use MiGears\Pages\Html as h5;`，本文统一用 `h5`；写成 `h` 同样成立。

## 一、构造器在整套体系里的位置

`migears/pages` 的输入是一份 PHP 数组，这份数组的形状就是它的 IR 契约——`spec.md` 第 4 到第 7 节定义的「页面声明、字段规则、节点文法、数据绑定」。流式构造器不修改这份契约：每个工厂方法返回一个节点对象，链式方法写入字段，编译器在 `compile()` 入口把这些对象归一成数组，之后的一切与手写数组完全相同。

```php
h5::HEADING(2)->text('用户列表')->id('usersTitle')
// 归一为
['type' => 'heading', 'level' => 2, 'text' => '用户列表', 'id' => 'usersTitle']
```

```php
h5::TEXTAREA('bio')->label('简介')->rows(5)
// 归一为
['type' => 'field', 'input' => 'textarea', 'name' => 'bio', 'label' => '简介', 'rows' => 5]
```

三个控件工厂（`INPUT`、`TEXTAREA`、`SELECT`）产出 IR 的 `field` 结构，`COL` 产出 `column` 结构；归一化之后与手写数组逐字节相同。注意大小写只存在于调用点这一层：工厂名大写，归一后的 `type` 仍是小写词表（`h5::IF(...)` 落到 `['type' => 'if', ...]`），与 XML、YAML 两个前端解析出的模型保持一致。

归一化发生在 `compile()` 这一个入口，且只认 `MiGears\Pages\Node` 的实例；XML 与 YAML 两个前端包仍然只产出数组，它们的路径与校验一行都不受影响。因此三个入口共用同一套校验与同一套错误文案，构造器只做形状转换，不重复校验——写错的路径、越界的 `level`、放错位置的 `placeholder`，仍然由编译器在编译期点名。

## 二、安装与最小示例

```bash
composer require migears/pages
```

```php
use MiGears\Pages\Compiler;
use MiGears\Pages\Html as h5;
use MiGears\Pages\Renderer;
use MiGears\Template\Template;

// 直接编译成模板文件
$compiler = new Compiler();
$source = $compiler->compile([
    'body' => [
        h5::HEADING(2)->text('用户列表'),
        h5::TEXT('共 {{ total }} 人'),
    ],
]);
$compiler->compileToFile(...);   // 或自行写盘

// 或者一步渲染
$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');
echo $renderer->render(['body' => [h5::TEXT('你好，{{ user.name }}')]], ['user' => ['name' => '张三']]);
```

节点对象可以嵌在数组里、也可以互相嵌套，编译器递归归一：

```php
h5::EACH('users')->BODY([
    h5::EL('li')->class('item')->BODY([h5::TEXT('{{ user.name }}')]),
])
```

别名由调用方决定，`h5` 只是本文的选择——写成 `use MiGears\Pages\Html as h;` 后，`h::TEXTAREA('bio')` 同样成立。

## 三、五条规则

**工厂名全大写，成员方法小写。** 节点叫 `h5::TEXTAREA`、`h5::IF`，字段叫 `->label()`、`->pop()`：大写是节点，小写是字段，`h5::INPUT('email')->label('邮箱')` 一眼就是「标签加属性」，与 HTML 自己的观感一致。两个控制流分支跟着节点走，写成 `->THEN()`、`->ELSE()`，因为它们命名的是语句而不是属性。需要注意 PHP 的方法名不区分大小写，把工厂名写成小写拼写照样能跑，语言层面拦不住，所以这条约定由 `tests/FactoryNamingTest.php` 守着——它检查 `Html` 声明的方法名，并扫本包自己的文档、规格、示例与源码。单个提到标签名时按 HTML 习惯写小写（`textarea`、`select`），只有工厂调用处的名字全大写。

**工厂名与 HTML 元素对齐。** `INPUT`、`TEXTAREA`、`SELECT`、`TABLE`、`COL`、`FORM`、`EL` 就是标签名；`HEADING` 对应 `<h1>`–`<h6>`，`LINK` 对应 `<a>`。不输出标签的四个用控制流与组件的语义命名：`TEXT`、`IF`、`EACH`、`COMPONENT`。表单控件不再有笼统的 `field` 工厂，写哪种控件就用哪个工厂。

**构造函数收「离开它这个节点就不成立」的那个值。** 标题的级别、元素的标签名、循环的数据源、表单的提交地址、链接的目标地址、组件的名字、控件的字段名、列的标题——各一个参数。块（`->BODY()`、`->THEN()`、`->fields()`）与 HTML 属性都是方法，签名永远不需要数参数。唯一的例外是迭代类节点 `EACH` 与 `TABLE`：它们额外收可选的**循环头**（`EACH` 收 `as` / `index`，`TABLE` 收 `as`），把 `foreach ($users as $i => $user)` 头部那几个名字放在一处，详见 4.5 与 4.7。

**其余一切都是成员方法，方法名尽量与 HTML 同名。** 是 HTML 属性的就用属性名（`->type()`、`->href()`、`->target()`、`->method()`、`->value()`、`->placeholder()`、`->checked()`、`->rows()`、`->required()`、`->class()`、`->id()`、`->style()`），是 HTML 元素的就用元素名，没有对应标签的用一个贴合组件语义的名字（`->label()`、`->options()`、`->BODY()`、`->AS()`、`->INDEX()`、`->columns()`、`->empty()`、`->pop()`、`->bind()`、`->popAndBind()`、`->content()`、`->data()`）；只有 `->THEN()`、`->ELSE()` 按语句而不是属性命名。

**属性类方法只长在输出标签的节点上。** `HEADING`、`LINK`、`FORM`、`TABLE`、`EL` 有 `->class()` / `->id()` / `->style()` / `->attr()` / `->on()` / `->bind()`；`TEXT`、`IF`、`EACH`、`COMPONENT` 没有这些方法——它们是 PHP 层的 `undefined method`，而不是等到编译期才被发现。手写数组若给这些节点挂属性，仍由编译器报「节点 "text" 不输出标签，请用 type: el 包裹内容」。

| 工厂 | 构造函数 | 成员方法 |
|------|----------|----------|
| `h5::TEXT` | `(string $text)` | 无（不输出标签） |
| `h5::HEADING` | `(int $level)` | `->text()`，属性组 |
| `h5::LINK` | `(string $href)` | `->text()`、`->target()`，属性组 |
| `h5::IF` | `(string $when)` | `->THEN()`、`->ELSE()` |
| `h5::EACH` | `(string $items, ?string $as, ?string $index)` | `->BODY()`、`->AS()`、`->INDEX()` |
| `h5::FORM` | `(string $action)` | `->fields()`、`->method()`，属性组 |
| `h5::INPUT` | `(string $name)` | `->type()`、`->label()`、`->value()`、`->required()`、`->placeholder()` |
| `h5::TEXTAREA` | `(string $name)` | `->label()`、`->value()`、`->required()`、`->rows()` |
| `h5::SELECT` | `(string $name)` | `->label()`、`->options()`、`->required()` |
| `h5::TABLE` | `(string $items, ?string $as)` | `->columns()`、`->AS()`、`->empty()`，属性组 |
| `h5::COL` | `(string $label)` | `->pop()`、`->content()` |
| `h5::COMPONENT` | `(string $name)` | `->data()` |
| `h5::EL` | `(string $tag)` | `->BODY()`，属性组 |

`h5::INPUT('name')` 默认是文本输入框；`->type()` 取 `<input>` 的 `type` 值：`email`、`password`、`number`、`checkbox`、`hidden`、`submit`。`type` 是 `<input>` 独有的属性，所以它只出现在 `input` 家族上——`textarea` 与 `select` 没有 `->type()` 可写。

`level` 默认 1，与 IR 一致：`h5::HEADING()->text('标题')` 即 h1，`h5::HEADING(2)->text('标题')` 是 h2。取值 1–6，越界由编译器报错。

## 四、逐个节点

### 4.1 text

```php
h5::TEXT('你好，{{ user.name }}')
```

裸文本，不输出标签。字面部分原样保留（可以写 HTML），插值自动转义。没有成员方法——需要 `class` 之类的属性时用 `el` 包裹。

### 4.2 heading

```php
h5::HEADING(2)->text('用户列表')                      // <h2>用户列表</h2>
h5::HEADING(2)->text('用户列表')->id('usersTitle')->class('page-title')
```

```php
// 归一为
['type' => 'heading', 'level' => 2, 'text' => '用户列表', 'id' => 'usersTitle', 'class' => 'page-title']
```

`level` 取值 1–6，越界即编译错误。`->text()` 是必填的，忘了会在编译期报 `heading: 缺少 string 字段 "text"`——链式写法不会让必填项悄悄变成可选。

### 4.3 link

```php
h5::LINK('/users/{{ user.id }}/edit')->text('编辑')
h5::LINK('/users/1')->text('详情')->target('_blank')->class('btn')
```

`href` 与 `->text()` 都支持插值。`target` 不做取值校验——HTML 允许 `_blank` 之外的命名目标。

### 4.4 if

```php
h5::IF('user.loggedIn')->THEN([
    h5::TEXT('欢迎回来，{{ user.name }}'),
])
```

```php
h5::IF('!user.hidden')->THEN([
    h5::TEXT('可见内容'),
])->ELSE([
    h5::TEXT('已隐藏'),
])
```

`when` 是路径，可带 `!` 前缀取反；`!` 只属于 `when`，写在 `each.items` 上是非法路径。不写 `->ELSE()` 即不生成分支，不写 `->THEN()` 则是编译错误。

### 4.5 each

循环头一次写完，循环体交给 `->BODY()`：

```php
h5::EACH('users', as: 'user', index: 'i')->BODY([
    h5::TEXT('{{ i }}. {{ user.name }}'),
])
```

两个头参数都可省略，省略时不写该字段、由编译器补自己的默认值：

```php
h5::EACH('users')->BODY([
    h5::EL('li')->BODY([h5::TEXT('{{ item.name }}')]),
])
```

```php
h5::EACH('users', as: 'user')->BODY([
    h5::TEXT('{{ user.name }}'),
])
```

`items` 编译为 `foreach ($users ?? [])`，未定义的数据自然渲染为空而不是报错。`as` 是行变量，省略时编译器用默认的 `item`；`index` 是下标变量，省略就**不引入**下标变量（不是默认绑一个 `i`——那样嵌套循环会互抢同一个名字，内层静默遮蔽外层）。两者都必须是合法 PHP 变量名。

循环头也可以写成 `->AS()` / `->INDEX()`，与命名参数等价：

```php
h5::EACH('users')->AS('user')->INDEX('i')->BODY([...])
```

省略的头参数不会被写进节点，所以两种写法可以互换；同一个字段两处都写会按「不静默覆盖」抛 `LogicException: 字段重复设置: as`。命名参数必须带冒号，且只能写在位置参数之后：`h5::EACH('users', index: 'i')` 合法（`as` 用默认值），`h5::EACH('users', index: 'i', 'user')` 是语法错误。

`EACH` 是迭代节点，它的构造函数签名多两个可选参数，这一点见第三节的例外说明。

### 4.6 form 与表单控件

```php
h5::FORM('/users/save')->fields([
    h5::INPUT('name')->label('姓名')->value('user.name')->required(),
    h5::INPUT('email')->label('邮箱')->type('email')->placeholder('name@example.com'),
    h5::SELECT('role')->label('角色')->options(['admin' => '管理员', 'editor' => '编辑']),
    h5::TEXTAREA('bio')->label('简介')->rows(5),
    h5::INPUT('active')->label('启用')->type('checkbox')->checked('user.active'),
    h5::INPUT('id')->label('')->type('hidden')->value('user.id'),
    h5::INPUT('save')->label('保存')->type('submit'),
])->method('post')
```

写哪种控件就用哪个工厂：`h5::INPUT('email')` 一眼是文本类输入框，`h5::TEXTAREA('bio')` 与 `h5::SELECT('role')` 直接就是标签名。三者都是对 IR 里同一个 `input` 字段的写法：

```php
h5::INPUT('email')->label('邮箱')->type('email')   // ['type' => 'field', 'input' => 'email', ...]
h5::TEXTAREA('bio')->label('简介')                 // ['type' => 'field', 'input' => 'textarea', ...]
h5::SELECT('role')->label('角色')                  // ['type' => 'field', 'input' => 'select', ...]
```

控件由工厂一次定下，之后没有第二条路径改它：`textarea` 与 `select` 上没有 `->type()`（PHP 层 `call to undefined method`），而 `h5::INPUT('x')->type('email')->type('text')` 抛 `LogicException: 控件重复设置: 已经是 email，不能再设为 text`。

`->required()` 不带参数即为 true，对应 HTML 里布尔属性的写法；不需要时直接不写。`->checked()` 与 `->value()` 收的是路径（`'user.active'`、`'user.name'`），不是字面值。

控件还有一个属性方法 `->bind('user.email')`，产出 `bind="user.email"`——名字交给浏览器端框架，所以值是 JS 变量名/路径，写 `{{ }}` 即编译错误。前后端变量同名时用 shortcut 一次写好两侧：

```php
h5::INPUT('email')->label('邮箱')->type('email')->popAndBind('{{ user.email }}')
// 归一为 ['type' => 'field', 'name' => 'email', 'input' => 'email', 'label' => '邮箱',
//          'value' => '{{ user.email }}', 'bind' => 'user.email']
// 渲染为 <input type="email" name="email" id="email" value="## $user['email'] ?? '' ##" bind="user.email">
```

`popAndBind()` 的第二个参数可以换成别的绑定拼写：`->popAndBind('{{ user.role }}', 'x-model')` 产出 `x-model="user.role"`。两侧不一致时不要用它，分开写 `->value()` 与 `->bind()` 更清楚。三个名字的分工见第五节。

控件与字段的适用范围是硬约束，越界即编译错误，不会被静默丢弃：

| 控件 | 可用的成员方法 |
|------|----------------|
| `h5::INPUT('name')`、`->type('password' / 'email' / 'number')` | `->value()`、`->required()`、`->placeholder()` |
| `h5::TEXTAREA('bio')` | `->value()`、`->required()`、`->rows()` |
| `h5::SELECT('role')` | `->options()` 必填（缺即编译错误）；`->required()` 可选；`->value()` 写上即编译错误 |
| `h5::INPUT('active')->type('checkbox')` | `->required()`、`->checked()` |
| `h5::INPUT('id')->type('hidden')` | `->value()`；`->required()` 写上即编译错误 |
| `h5::INPUT('save')->type('submit')` | `->label()` 即按钮文字；`->value()`、`->required()` 写上即编译错误 |

`name`、`label`、`options` 的键值、`column.label`、`table.empty` 都是字面量字段，写 `{{ }}` 即编译错误。

### 4.7 table 与 column

```php
h5::TABLE('users')->columns([
    h5::COL('ID')->pop('{{ row.id }}'),
    h5::COL('姓名')->pop('{{ row.name }}'),
    h5::COL('操作')->content([
        h5::LINK('/users/{{ row.id }}/edit')->text('编辑'),
    ]),
])->empty('暂无数据')
```

```php
h5::TABLE('users', as: 'user')->columns([
    h5::COL('姓名')->pop('{{ user.name }}'),
])
```

`TABLE` 与 `EACH` 同属迭代节点，行变量同样可以在构造函数里一次给出（`->AS('user')` 等价）。`col` 与 `<col>` 同名，读起来就是表格列。`as` 默认 `row`；`->empty()` 是空数据时那一行的文字（字面量），不写则不生成空态行。每列的 `->pop()`（服务端要渲染进单元格的数据引用，写成 `'{{ user.name }}'`）与 `->content()`（节点树，行变量作用域内）二选一必填，同时写即编译错误。`pop` 的值必须带 `{{ }}`，且首段要等于该表格的 `as` 变量——写裸路径 `'row.name'` 或引用别的变量，都是编译错误。

### 4.8 component

```php
h5::COMPONENT('card')->data([
    'title' => '{{ user.name }}',
    'body' => '简介',
])
```

`name` 是字面量（模板名）；`data` 的键是字面量、值必须是字符串，值支持插值。组件不输出标签，所以没有属性类方法。见第六节。

### 4.9 el

```php
h5::EL('div')->class('card')->BODY([h5::TEXT('正文')])
```

```php
h5::EL('div')->attr('x-data', '{ open: false }')->class('card')->BODY([
    h5::HEADING(3)->text('卡片'),
])
```

```php
h5::EL('button')->on('click', 'open = !open')->BODY([h5::TEXT('切换')])
```

`tag` 必须是合法的小写 HTML 标签名。不写 `->BODY()` 即空 body，输出 `<div></div>`（保留属性）；但把 body 写成 `null`、字符串或单个节点映射都是编译错误，不会被当成空 body。

属性类方法：

| 方法 | 输出 | 说明 |
|------|------|------|
| `->class('card card--wide')` | `class="card card--wide"` | 值就是 HTML 的 class 属性原文，多个类用空格分隔；不要写 CSS 选择器里的点（`.card` 会让 class 值多出一个点，选择器就匹配不上了） |
| `->id('usersTitle')` | `id="usersTitle"` | |
| `->style('margin-top: 8px')` | `style="margin-top: 8px"` | |
| `->attr('data-role', 'admin')` | `data-role="admin"` | 任意白名单属性的入口 |
| `->on('click', 'open = !open')` | `@click="open = !open"` | 事件简写；需要 `x-on:click` 或 `v-on:click` 这类别的拼写时用 `->attr()` |
| `->bind('user.email')` | `bind="user.email"` | 前端框架的绑定属性；值是浏览器端变量名/路径，写 `{{ }}` 即编译错误（见第五节） |

属性值支持插值：`->class('item--{{ user.role }}')`。透传属性的白名单是：`@event`、任何含冒号的指令名（`x-on:click`、`v-on:click`、`wire:click`、`:href`）、`x-` / `v-` / `hx-` / `data-` 前缀、以及 `class` / `id` / `style` / `bind`；其余名字一律编译错误——未知名字视为拼写错误。`x-on-click` 这类连字符写法会被单独拦截并提示改写为 `x-on:click` 或 `@click`，因为 `x-` 前缀本会放行、指令却在 Alpine 里失效。

同一个属性设置两次会抛异常（`->class('a')->class('b')`），沿用编译期「不静默丢弃」的同一套立场——要多个类就一次写全 `'a b'`。链式之外的属性同理：`->attr('class', 'a')` 之后再 `->class('b')` 也是重复。

## 五、数据绑定、转义与错误

**`pop`、`bind`、`popAndBind` 分属两侧。** 本模块要说「PHP 把数据渲染进页面」时一律用 `pop`（populate 的缩写），它收一个数据引用，编译成 `## $user['name'] ?? '' ##`，渲染时求值——`->value()`、`h5::COL()->pop()` 都是这一侧。`bind` 则是浏览器端那一半：只产出一个属性 `bind="user.email"`，名字交给前端框架去解析，本模块不求值，所以值必须是 JS 变量名/路径，写 `{{ }}` 即编译错误。`popAndBind('{{ user.email }}')` 是两者的 shortcut，只在前后端变量同名时用；不一致就分开写。

同一个「数据引用」在两侧的不同拼写也是刻意的：`pop` 收 `{{ user.name }}`（页面层插值，走校验与转义），`bind` 收 `user.name`（浏览器端名字，原样输出）。

路径的文法只有「变量名加数组键」：`user.name`、`users.0.email` 这种形式之外的内容（函数调用、算术、字符串字面量）都是编译错误。编译后按上下文带兜底：文本与属性上下文 `?? ''`，条件上下文 `?? null`，循环上下文 `?? []`。

插值有两种上下文。HTML 文本与属性里写 `{{ user.name }}`，编译为模板糖 `## $user['name'] ?? '' ##`，由模板引擎在渲染时转义；`component.data` 的值编译为 PHP 字符串拼接，不预转义，转义权交给组件模板。插值符号最多两个花括号，`{{{` 与 `}}}` 都是编译错误。

**页面层与模板层的标记不共用。** 页面层写 `{{ path }}`，模板层（组件模板、编译产物）写 `## expr ##`。页面编译产物会被 `migears/template` 再扫一遍，所以页面里的字面 `##`（Markdown 标题、代码片段等）会在编译时按模板层语法转义，渲染后原样输出，不会被当作表达式求值；单个 `#` 不需要转义。字面量字段（`label`、`name`、`tag`、`empty`、`option` 等）是原样写入产物的，出现 `##` 即编译报错。

页面数据约定为数组形态（`$user['name']`），对象由调用方在边界处归一化。

错误全部是带节点路径的 `CompileException`，首个错误即抛出：

```
sections.content[2].columns[2]: 列同时指定 pop 与 content
body[0].fields[0]: bind 的值必须是 JS 变量名或路径（如 user.email），收到 "{{ user.email }}"
body[0].fields[0]: "placeholder" 仅用于 text / password / email / number 字段，当前 input 是 "select"
body[1].then: 必须是节点树数组（列表），当前是键值映射；请用 [ ] 包成列表
```

不会出现 PHP 警告泄漏，也不会因为类型不符抛出原始 `TypeError`。

## 六、引用组件

组件名即模板名，按注册的搜索路径解析：先找 `<path>/<name>.tpl.php`，再找 `<path>/<name>.php`；后 `addPath` 的目录排在前面，因此覆盖内置组件只需把同名文件放进你自己的目录。

```php
$template = new Template(__DIR__ . '/views');
$template->addPath(__DIR__ . '/views/components');
```

`data` 的值只能是字符串，到达组件时未转义，转义由组件模板决定——文本用 `$this->e()`，可信 HTML 用 `$this->raw()`。组件模板的作用域是隔离的，页面变量不会自动透传，需要什么就通过 `data` 传什么。

内置的 `card`、`button`、`alert`、`badge` 随两个前端包分发（`migears/xml-pages`、`migears/yaml-pages`），单独使用本包时没有内置组件——可以自己写，或把前端包的 `components/` 目录拷进项目并注册。组件模板可以是原生 PHP，也可以是 `.tpl.php`（糖语法里 `## ##` 转义、`### ###` 原样输出）。

## 七、两个完整页面

### 7.1 带布局的列表页

```php
$page = [
    'title' => '用户管理',
    'layout' => 'layout/admin',
    'sections' => [
        'content' => [
            h5::HEADING(2)->text('用户列表'),
            h5::IF('users')->THEN([
                h5::TABLE('users')->columns([
                    h5::COL('ID')->pop('{{ row.id }}'),
                    h5::COL('姓名')->pop('{{ row.name }}'),
                    h5::COL('操作')->content([
                        h5::LINK('/users/{{ row.id }}/edit')->text('编辑'),
                    ]),
                ])->empty('暂无数据'),
            ])->ELSE([
                h5::TEXT('还没有用户'),
            ]),
        ],
    ],
];
```

有 `layout` 时必须用 `sections`、不能用 `body`；没有 `layout` 时必须用 `body`、不能用 `sections`。`title` 存在且用了布局时，会自动生成一个 `title` section。

### 7.2 编辑表单页

```php
$page = [
    'layout' => 'layout/admin',
    'sections' => [
        'content' => [
            h5::HEADING(2)->text('编辑用户'),
            h5::FORM('/users/{{ user.id }}/save')->fields([
                h5::INPUT('name')->label('姓名')->value('user.name')->required(),
                h5::INPUT('email')->label('邮箱')->type('email')->value('user.email')->required(),
                h5::SELECT('role')->label('角色')->options(['admin' => '管理员', 'editor' => '编辑']),
                h5::INPUT('save')->label('保存')->type('submit'),
            ])->method('post'),
        ],
    ],
];
```

### 7.3 一步渲染与缓存

```php
$renderer = new Renderer(new Template(__DIR__ . '/views'), new Compiler(), __DIR__ . '/cache/pages');

echo $renderer->render($page, ['user' => $user]);

$renderer->clearCache();   // 清掉本渲染器写出的派生页面，返回删除数量
```

派生页面按内容寻址（`page_<md5>.tpl.php`），声明不变就不重写；缓存只增不减，部署时用 `clearCache()` 清理，或整体删除缓存目录。

## 八、设计说明

**调用点读起来像一段 HTML。** `h5::HEADING(2)->text('用户列表')->id('usersTitle')->class('page-title')` 与 `<h2 id="usersTitle" class="page-title">用户列表</h2>` 是同一件事的两种拼写。工厂名给出标签，成员方法按 HTML 属性名补齐；`input`、`textarea`、`select`、`table`、`col` 本身就是标签名，而 `<input>` 的 type 值仍写在 `->type()` 上，所以 `email`、`checkbox`、`hidden`、`submit` 这些不是元素的东西不会被误当成元素。

**构造函数只有一个参数，迭代节点多两个可选的。** 那个参数是「离开它这个节点就不成立」的值；字段再多也走方法链，因此不存在「第九个位置参数是什么」这类问题。`h5::INPUT('email')->label('邮箱')->type('email')->required()` 里没有一个位置参数需要记忆。`EACH` 与 `TABLE` 是例外，因为 `foreach ($users as $i => $user)` 的头部本来就是一个整体，把它拆成两次链式调用反而更难读；那两个参数都排在必填项之后，调用时要么用命名参数（`as: 'user'`、`index: 'i'`），要么一个不写。

**同形参数靠方法名分辨。** `IF` 的 `->THEN()` 与 `->ELSE()`、`EL` 的 `->BODY()` 与属性方法、`EACH` 的 `->BODY()` 与 `->AS()`——每一项都自带名字，不必回查签名。

**方法集合本身是文档。** 不输出标签的节点没有属性方法，所以 `h5::TEXT('x')->class('a')` 在 PHP 层就是 `undefined method`；`h5::TEXTAREA('bio')->type('email')` 同样在 PHP 层报错，因为控件方法只长在 `h5::INPUT` 上。顺着 IDE 的补全列表就能把一个节点的全部可写字段看一遍。

**校验仍然只有一处。** 工厂不检查节点词表：未知属性、越界的 `level`、错放的 `placeholder`、缺失的必填字段，全部由编译器抛带路径的 `CompileException`——数组、XML、YAML 与 h5 四个入口的错误措辞因此完全一致。代价是 `compile()` 入口多了一层把 `Node` 递归归一为数组的处理，方法名与参数名成为公开 API（重命名即破坏性变更），实现上也比纯函数集合多出几个类；另外全大写方法名偏离了 PSR-1 / PSR-12 的 camelCase 要求，属于本层有意的例外。这些取舍记录在 `spec.md` 第 10 节。
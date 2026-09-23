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
h5::EACH('users')->body([
    h5::EL('li')->class('item')->body([h5::TEXT('{{ user.name }}')]),
])
```

别名由调用方决定，`h5` 只是本文的选择——写成 `use MiGears\Pages\Html as h;` 后，`h::TEXTAREA('bio')` 同样成立。

## 三、五条规则

**工厂名全大写，成员方法小写。** 节点叫 `h5::TEXTAREA`、`h5::IF`，字段叫 `->label()`、`->pop()`：大写是节点，小写是字段，`h5::INPUT('email')->label('邮箱')` 一眼就是「标签加属性」，与 HTML 自己的观感一致。两个控制流分支跟着节点走，写成 `->THEN()`、`->ELSE()`，因为它们命名的是语句而不是属性。需要注意 PHP 的方法名不区分大小写，把工厂名写成小写拼写照样能跑，语言层面拦不住，所以这条约定由 `tests/FactoryNamingTest.php` 守着——它检查 `Html` 声明的方法名，并扫本包自己的文档、规格、示例与源码。单个提到标签名时按 HTML 习惯写小写（`textarea`、`select`），只有工厂调用处的名字全大写。

**工厂名与 HTML 元素对齐。** `INPUT`、`TEXTAREA`、`SELECT`、`TABLE`、`COL`、`FORM`、`EL` 就是标签名；`HEADING` 对应 `<h1>`–`<h6>`，`LINK` 对应 `<a>`。不输出标签的四个用控制流与组件的语义命名：`TEXT`、`IF`、`EACH`、`COMPONENT`。表单控件不再有笼统的 `field` 工厂，写哪种控件就用哪个工厂。

**构造函数收「离开它这个节点就不成立」的那个值。** 标题的级别、元素的标签名、循环的数据源、表单的提交地址、链接的目标地址、组件的名字、控件的字段名、列的标题——各一个参数。块（`->body()`、`->THEN()`、`->fields()`）与 HTML 属性都是方法，签名永远不需要数参数。唯一的例外是迭代类节点 `EACH` 与 `TABLE`：它们额外收可选的**循环头**（`EACH` 收 `as` / `index`，`TABLE` 收 `as`），把 `foreach ($users as $i => $user)` 头部那几个名字放在一处，详见 4.5 与 4.7。

**其余一切都是成员方法，方法名尽量与 HTML 同名。** 是 HTML 属性的就用属性名（`->type()`、`->href()`、`->target()`、`->method()`、`->value()`、`->placeholder()`、`->checked()`、`->rows()`、`->required()`、`->class()`、`->id()`、`->style()`），是 HTML 元素的就用元素名，没有对应标签的用一个贴合组件语义的名字（`->label()`、`->options()`、`->body()`、`->as()`、`->index()`、`->columns()`、`->empty()`、`->pop()`、`->bind()`、`->popAndBind()`、`->content()`、`->data()`）；只有 `->THEN()`、`->ELSE()` 按语句而不是属性命名。

**属性类方法只长在输出标签的节点上。** `HEADING`、`LINK`、`FORM`、`TABLE`、`EL` 有 `->class()` / `->id()` / `->style()` / `->attr()` / `->on()` / `->bind()`；`TEXT`、`IF`、`EACH`、`COMPONENT` 没有这些方法——它们是 PHP 层的 `undefined method`，而不是等到编译期才被发现。手写数组若给这些节点挂属性，仍由编译器报「节点 "text" 不输出标签，请用 type: el 包裹内容」。

| 工厂 | 构造函数 | 成员方法 |
|------|----------|----------|
| `h5::TEXT` | `(string $text)` | 无（不输出标签） |
| `h5::HEADING` | `(int $level)` | `->text()`，属性组 |
| `h5::LINK` | `(string $href)` | `->text()`、`->target()`，属性组 |
| `h5::IF` | `(string $when)` | `->THEN()`、`->ELSE()` |
| `h5::EACH` | `(string $items, ?string $as, ?string $index)` | `->body()`、`->as()`、`->index()` |
| `h5::FORM` | `(string $action)` | `->fields()`、`->method()`，属性组 |
| `h5::INPUT` | `(string $name)` | `->type()`、`->label()`、`->value()`、`->required()`、`->placeholder()` |
| `h5::TEXTAREA` | `(string $name)` | `->label()`、`->value()`、`->required()`、`->rows()` |
| `h5::SELECT` | `(string $name)` | `->label()`、`->options()`、`->required()` |
| `h5::TABLE` | `(string $items, ?string $as)` | `->columns()`、`->as()`、`->empty()`，属性组 |
| `h5::COL` | `(string $label)` | `->pop()`、`->content()` |
| `h5::COMPONENT` | `(string $name)` | `->data()` |
| `h5::EL` | `(string $tag)` | `->body()`，属性组 |

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

循环头一次写完，循环体交给 `->body()`：

```php
h5::EACH('users', as: 'user', index: 'i')->body([
    h5::TEXT('{{ i }}. {{ user.name }}'),
])
```

两个头参数都可省略，省略时不写该字段、由编译器补自己的默认值：

```php
h5::EACH('users')->body([
    h5::EL('li')->body([h5::TEXT('{{ item.name }}')]),
])
```

```php
h5::EACH('users', as: 'user')->body([
    h5::TEXT('{{ user.name }}'),
])
```

`items` 编译为 `foreach ($users ?? [])`，未定义的数据自然渲染为空而不是报错。`as` 是行变量，省略时编译器用默认的 `item`；`index` 是下标变量，省略就**不引入**下标变量（不是默认绑一个 `i`——那样嵌套循环会互抢同一个名字，内层静默遮蔽外层）。两者都必须是合法 PHP 变量名。

循环头也可以写成 `->as()` / `->index()`，与命名参数等价：

```php
h5::EACH('users')->as('user')->index('i')->body([...])
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

`TABLE` 与 `EACH` 同属迭代节点，行变量同样可以在构造函数里一次给出（`->as('user')` 等价）。`col` 与 `<col>` 同名，读起来就是表格列。`as` 默认 `row`；`->empty()` 是空数据时那一行的文字（字面量），不写则不生成空态行。每列的 `->pop()`（服务端要渲染进单元格的数据引用，写成 `'{{ user.name }}'`）与 `->content()`（节点树，行变量作用域内）二选一必填，同时写即编译错误。`pop` 的值必须带 `{{ }}`，且首段要等于该表格的 `as` 变量——写裸路径 `'row.name'` 或引用别的变量，都是编译错误。

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
h5::EL('div')->class('card')->body([h5::TEXT('正文')])
```

```php
h5::EL('div')->attr('x-data', '{ open: false }')->class('card')->body([
    h5::HEADING(3)->text('卡片'),
])
```

```php
h5::EL('button')->on('click', 'open = !open')->body([h5::TEXT('切换')])
```

`tag` 必须是合法的小写 HTML 标签名。不写 `->body()` 即空 body，输出 `<div></div>`（保留属性）；但把 body 写成 `null`、字符串或单个节点映射都是编译错误，不会被当成空 body。

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

**同形参数靠方法名分辨。** `IF` 的 `->THEN()` 与 `->ELSE()`、`EL` 的 `->body()` 与属性方法、`EACH` 的 `->body()` 与 `->as()`——每一项都自带名字，不必回查签名。

**方法集合本身是文档。** 不输出标签的节点没有属性方法，所以 `h5::TEXT('x')->class('a')` 在 PHP 层就是 `undefined method`；`h5::TEXTAREA('bio')->type('email')` 同样在 PHP 层报错，因为控件方法只长在 `h5::INPUT` 上。顺着 IDE 的补全列表就能把一个节点的全部可写字段看一遍。

**校验仍然只有一处。** 工厂不检查节点词表：未知属性、越界的 `level`、错放的 `placeholder`、缺失的必填字段，全部由编译器抛带路径的 `CompileException`——数组、XML、YAML 与 h5 四个入口的错误措辞因此完全一致。代价是 `compile()` 入口多了一层把 `Node` 递归归一为数组的处理，方法名与参数名成为公开 API（重命名即破坏性变更），实现上也比纯函数集合多出几个类；另外全大写方法名偏离了 PSR-1 / PSR-12 的 camelCase 要求，属于本层有意的例外。这些取舍记录在 `spec.md` 第 10 节。

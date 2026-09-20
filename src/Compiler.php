<?php

declare(strict_types=1);

namespace MiGears\Pages;

use MiGears\Pages\Exception\CompileException;

/**
 * Compiles a page declaration into miGears Template (.tpl.php) source.
 *
 * A page declaration is a PHP array — the canonical form of the DSL. The
 * format frontends (migears/xml-pages, migears/yaml-pages) extend this class
 * and implement parse() to produce the same array from their own syntax, so
 * there is exactly one compiler and one node model behind every frontend.
 *
 * Two-stage pipeline: array -> .tpl.php (this class), then TemplateCompiler
 * turns the ## ## sugar into pure PHP at render time. The .tpl.php output is a
 * derived artifact — the page declaration is the single source of truth.
 *
 * Node model: every node is ['type' => ...] plus its own fields. Structural
 * children (then / else / body / fields / columns / options / content / data)
 * are nested node trees. Attributes that are not DSL fields are forwarded to
 * the emitted tag when they match the front-end framework whitelist.
 *
 * What differs between frontends is only surface syntax, plus how an attribute
 * name reaching the compiler should be spelled on output — see the hooks below.
 * Everything else, from interpolation to validation, is shared.
 */
class Compiler
{
    private const PATH_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';

    private const INTERPOLATION = '/\{\{\s*([^{}]+?)\s*\}\}/';

    private const INPUT_TYPES = ['text', 'password', 'email', 'number', 'textarea', 'select', 'checkbox', 'hidden', 'submit'];

    /**
     * Attributes the DSL does not define are forwarded verbatim only when they
     * belong to a known front-end convention or a common HTML hook. Everything
     * else is treated as a typo: silently dropping an Alpine directive the
     * author did write is the worst possible outcome, because the page still
     * compiles and the directive simply vanishes.
     */
    private const PASSTHROUGH_PREFIXES = ['x-', 'v-', 'hx-', 'data-'];

    private const PASSTHROUGH_EXACT = ['class', 'id', 'style'];

    private const TAG_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /**
     * Alpine spells these directives with a colon. The hyphen form is not an
     * Alpine attribute at all, so a bare 'x-' prefix rule would forward it
     * happily and the directive would then do nothing — the silent failure we
     * refuse. prefix => shorthands to suggest instead.
     *
     * @var array<string, list<string>>
     */
    protected const COLON_ONLY_DIRECTIVES = [
        'x-on-' => ['x-on:', '@'],
        'x-bind-' => ['x-bind:', ':'],
        'x-transition-' => ['x-transition:'],
    ];

    /** @var callable|null */
    private $warn;

    public function __construct(?callable $warn = null)
    {
        $this->warn = $warn;
    }

    /**
     * Compile a page declaration — the array form of the DSL — to template source.
     *
     * @param array<string, mixed> $page
     */
    public function compile(array $page): string
    {
        return $this->compilePage($page);
    }

    /**
     * Compile a page written in a frontend's own syntax. The base class has no
     * source syntax of its own: array pages go through compile() instead.
     */
    public function compileSource(string $source): string
    {
        return $this->compilePage($this->parse($source));
    }

    /**
     * Turn this frontend's source syntax into the array node model.
     *
     * @return array<string, mixed>
     */
    protected function parse(string $source): array
    {
        throw $this->newException(
            '此编译器只接受数组页面定义；源文本语法请使用 migears/xml-pages 或 migears/yaml-pages，'
            . '数组页面请直接调用 compile()'
        );
    }

    public function compileFile(string $path): string
    {
        if (! is_file($path)) {
            throw $this->newException("页面文件不存在: {$path}");
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw $this->newException("无法读取页面文件: {$path}");
        }

        return $this->compileSource($source);
    }

    public function compileToFile(string $sourcePath, ?string $outputDir = null): string
    {
        $compiled = $this->compileFile($sourcePath);
        $dir = $outputDir ?? dirname($sourcePath);
        $base = preg_replace('/\.page\.[a-z0-9]+$/i', '', basename($sourcePath)) ?? basename($sourcePath);
        $target = rtrim($dir, '/\\') . '/' . $base . '.tpl.php';

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw $this->newException("无法创建输出目录: {$dir}");
        }

        file_put_contents($target, $compiled, LOCK_EX);

        return $target;
    }

    /* ---------------------------------------------------------------- *
     * Attribute forwarding
     * ---------------------------------------------------------------- */

    private function isForwardable(string $name): bool
    {
        // Namespace-style framework directives (x-on:click, v-bind:href,
        // wire:click, hx-on:click) and the '@event' shorthand pass through
        // unchanged. Reaching this point already means the frontend could
        // spell the name, so no rewriting is needed here.
        if (str_starts_with($name, '@') || str_contains($name, ':')) {
            return true;
        }
        if (in_array($name, self::PASSTHROUGH_EXACT, true)) {
            return true;
        }
        foreach (self::PASSTHROUGH_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the name an attribute is emitted under, rejecting anything that is
     * neither a DSL field nor a whitelisted framework directive.
     *
     * A frontend overrides this to translate its own spelling: XML maps
     * '__click' onto '@click' because '@' cannot be an XML attribute name. The
     * base accepts '@event' as written.
     */
    protected function mapAttributeName(string $name, string $path): string
    {
        foreach (static::COLON_ONLY_DIRECTIVES as $prefix => $forms) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }
            $rest = substr($name, strlen($prefix));
            $hint = implode('" 或 "', array_map(static fn (string $f): string => $f . $rest, $forms));
            $this->error("{$path}: 未知属性 \"{$name}\"；Alpine 的事件/绑定指令用冒号形式，请写 \"{$hint}\"");
        }

        if (! $this->isForwardable($name)) {
            $this->error("{$path}: 未知属性 \"{$name}\"；透传支持 '@event' 简写、"
                . '带冒号的指令名（x-on:click / wire:click / :href 等）、'
                . implode(' / ', self::PASSTHROUGH_PREFIXES) . ' 前缀与 '
                . implode(' / ', self::PASSTHROUGH_EXACT)
                . '；请检查拼写');
        }

        return $name;
    }

    /**
     * Attribute name/value pairs this node may forward, in source order.
     *
     * Default implementation reads them from the node itself, which is how a
     * mapping-shaped source (arrays, YAML) works: anything that is not a DSL
     * field is a candidate. A frontend whose parser stores attributes apart
     * from the fields (XML) overrides this.
     *
     * @param list<string> $dslFields field names this node type consumes itself
     * @return list<array{name: string, value: mixed, explicit: bool}>
     *         explicit marks an attribute attached outside the whitelist, which
     *         therefore skips name mapping and is emitted exactly as written
     */
    protected function attributeCandidates(array $n, array $dslFields): array
    {
        $candidates = [];
        foreach ($n as $name => $value) {
            $name = (string) $name;
            if ($name === 'type' || in_array($name, $dslFields, true)) {
                continue;
            }
            $candidates[] = ['name' => $name, 'value' => $value, 'explicit' => false];
        }

        return $candidates;
    }

    /**
     * Normalise an attribute value into what an HTML attribute can hold. A
     * mapping-shaped source parses bare scalars into native types, so
     * `data-count: 3` arrives as an int; string sources hand over strings.
     */
    protected function normalizeAttrValue(mixed $value, string $name, string $path): string
    {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';          // `x-cloak:` — the mapping spelling of a valueless attribute
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $this->error("{$path}: 属性 \"{$name}\" 的值必须是标量，收到 " . gettype($value));
    }

    /**
     * How an error message names a node type in this frontend's spelling.
     */
    protected function nodeRef(string $type): string
    {
        return "type: {$type}";
    }

    /**
     * How an error message names a container node, in this frontend's spelling.
     */
    protected function containerRef(string $type): string
    {
        return "type: {$type}";
    }

    /**
     * How an error message names an explicitly attached attribute.
     */
    protected function explicitAttrRef(string $name): string
    {
        return "属性 \"{$name}\"";
    }

    /**
     * Render the attributes a node forwards to the tag it emits, and reject
     * everything else. An unrecognised attribute is a typo, not an instruction
     * to drop it: forwarding it blindly would hide the mistake, while dropping
     * it silently yields a page that compiles and quietly lost a directive.
     *
     * @param list<string> $dslFields field names this node type consumes itself
     */
    private function forwardedAttrs(array $n, array $dslFields, string $path, bool $emitsTag): string
    {
        $emitted = [];
        $out = '';

        foreach ($this->attributeCandidates($n, $dslFields) as $candidate) {
            $name = $candidate['name'];

            if ($candidate['explicit'] && in_array($name, $dslFields, true)) {
                $this->error("{$path}: " . $this->explicitAttrRef($name)
                    . " 与节点字段 \"{$name}\" 同名，请直接使用该字段");
            }

            $target = $candidate['explicit'] ? $name : $this->mapAttributeName($name, $path);

            if (isset($emitted[$target])) {
                $this->error($candidate['explicit']
                    ? "{$path}: " . $this->explicitAttrRef($target) . ' 与已有的同名属性重复'
                    : "{$path}: 属性 \"{$target}\" 重复定义");
            }
            $emitted[$target] = true;
            $out .= $this->renderAttr(
                $target,
                $this->normalizeAttrValue($candidate['value'], $name, $path),
                $n,
                $path,
                $emitsTag
            );
        }

        return $out;
    }

    private function renderAttr(string $name, string $value, array $n, string $path, bool $emitsTag): string
    {
        if (! $emitsTag) {
            $this->error("{$path}: 节点 " . $this->nodeRef((string) $n['type'])
                . " 不输出标签，无法承载属性 \"{$name}\"；请改用 "
                . $this->containerRef('el') . ' 包裹内容');
        }

        // Escape the literal part first, then interpolate: the ## ## sugar must
        // reach the template engine unescaped, or its quotes would be mangled.
        // ENT_COMPAT (not ENT_QUOTES) keeps single quotes readable — every
        // attribute here is double-quoted, and Alpine expressions are full of
        // single quotes that would otherwise turn into &#039; noise.
        $value = $this->interpolate(htmlspecialchars($value, ENT_COMPAT), $path);

        return ' ' . $name . '="' . $value . '"';
    }

    /* ---------------------------------------------------------------- *
     * Compilation: array node model -> .tpl.php
     * ---------------------------------------------------------------- */

    private function compilePage(array $page): string
    {
        $hasLayout = array_key_exists('layout', $page);
        $hasBody = array_key_exists('body', $page);
        $hasSections = array_key_exists('sections', $page);

        foreach ($page as $key => $_) {
            if (! in_array($key, ['title', 'layout', 'body', 'sections'], true)) {
                $this->error('page: 未知字段 "' . $key . '"（可用: title / layout / body / sections）');
            }
        }

        if ($hasLayout && $hasBody) {
            $this->error('page: 同时指定 layout 与 body 冲突，有 layout 时请使用 sections');
        }
        if ($hasLayout && ! $hasSections) {
            $this->error('page: 指定 layout 时必须同时提供 sections');
        }
        if ($hasSections && ! $hasLayout) {
            $this->error('page: 未指定 layout 时不能使用 sections，请改用 body');
        }
        if (! $hasLayout && ! $hasBody) {
            $this->error('page: 缺少页面内容，请提供 body（无 layout 时）或 layout+sections');
        }

        if ($hasLayout) {
            return $this->compileLayout($page);
        }

        if (array_key_exists('title', $page) && $this->warn !== null) {
            ($this->warn)('page: title 仅在指定 layout 时生效，当前页面无 layout，title 已忽略');
        }

        return $this->compileNodes($page['body'], 'body');
    }

    private function compileLayout(array $page): string
    {
        $layout = $this->literal((string) $page['layout'], 'page', 'layout');
        $out = "<?php \$this->extends('" . $this->str($layout) . "') ?>\n";

        $sections = $page['sections'];
        $ordered = [];
        if (array_key_exists('title', $page) && ! array_key_exists('title', $sections)) {
            $ordered[] = ['name' => 'title', 'nodes' => [['type' => 'text', 'text' => (string) $page['title']]]];
        }
        foreach ($sections as $name => $nodes) {
            if (! is_array($nodes)) {
                $this->error('sections.' . $name . ': section 的值必须是节点树数组');
            }
            $ordered[] = ['name' => (string) $name, 'nodes' => $nodes];
        }

        foreach ($ordered as $section) {
            $name = $this->literal($section['name'], 'sections', 'section 名');
            $out .= "\n<?php \$this->start('" . $this->str($name) . "') ?>\n";
            $out .= $this->compileNodes($section['nodes'], 'sections.' . $name);
            $out .= "\n<?php \$this->end() ?>";
        }

        return $out . "\n";
    }

    private function compileNodes(array $nodes, string $path): string
    {
        $parts = [];
        foreach ($nodes as $i => $node) {
            $parts[] = $this->compileNode($node, $path . '[' . $i . ']');
        }

        return implode("\n", $parts);
    }

    private function compileNode(mixed $node, string $path): string
    {
        if (! is_array($node)) {
            $this->error("{$path}: 节点必须是对象");
        }
        if (! isset($node['type']) || ! is_string($node['type'])) {
            $this->error("{$path}: 节点缺少 type 字段");
        }

        return match ($node['type']) {
            'text' => $this->compileText($node, $path),
            'heading' => $this->compileHeading($node, $path),
            'link' => $this->compileLink($node, $path),
            'if' => $this->compileIf($node, $path),
            'each' => $this->compileEach($node, $path),
            'form' => $this->compileForm($node, $path),
            'table' => $this->compileTable($node, $path),
            'el' => $this->compileEl($node, $path),
            'component' => $this->compileComponent($node, $path),
            default => $this->error("{$path}: 未知节点类型 \"{$node['type']}\""),
        };
    }

    private function compileText(array $n, string $path): string
    {
        $text = $this->requireString($n, 'text', $path);
        $this->forwardedAttrs($n, ['text'], $path, false);   // text emits bare text: validate only

        return $this->interpolate($text, $path);
    }

    private function compileHeading(array $n, string $path): string
    {
        $level = $n['level'] ?? 1;
        if (! is_int($level) || $level < 1 || $level > 6) {
            $this->error("{$path}: heading 的 level 必须是 1-6 的整数，收到 " . var_export($level, true));
        }

        $attrs = $this->forwardedAttrs($n, ['level', 'text'], $path, true);
        $text = $this->interpolate($this->requireString($n, 'text', $path), $path);

        return "<h{$level}{$attrs}>{$text}</h{$level}>";
    }

    private function compileLink(array $n, string $path): string
    {
        $href = $this->interpolate($this->requireString($n, 'href', $path), $path);
        $text = $this->interpolate($this->requireString($n, 'text', $path), $path);

        $out = '<a href="' . $href . '"';
        if (array_key_exists('target', $n)) {
            $out .= ' target="' . $this->interpolate($this->requireString($n, 'target', $path), $path) . '"';
        }
        $out .= $this->forwardedAttrs($n, ['href', 'text', 'target'], $path, true);

        return $out . '>' . $text . '</a>';
    }

    private function compileIf(array $n, string $path): string
    {
        $when = $this->requireString($n, 'when', $path);
        $this->forwardedAttrs($n, ['when', 'then', 'else'], $path, false);   // if emits no tag
        $then = $n['then'] ?? null;
        if (! is_array($then)) {
            $this->error("{$path}: if 缺少 then（节点树数组）");
        }

        $cond = $this->compileCondition($when, $path);

        $out = "<?php if ({$cond}): ?>\n" . $this->compileNodes($then, $path . '.then');
        if (array_key_exists('else', $n)) {
            $else = $n['else'];
            if (! is_array($else)) {
                $this->error("{$path}: if 的 else 必须是节点树数组");
            }
            $out .= "\n<?php else: ?>\n" . $this->compileNodes($else, $path . '.else');
        }

        return $out . "\n<?php endif ?>";
    }

    private function compileEach(array $n, string $path): string
    {
        $items = $this->compilePath($this->requireString($n, 'items', $path), $path);
        $this->forwardedAttrs($n, ['items', 'as', 'index', 'body'], $path, false);   // each emits no tag
        $as = $n['as'] ?? 'item';
        if (! is_string($as) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            $this->error("{$path}: each 的 as 必须是合法变量名");
        }

        $body = $n['body'] ?? null;
        if (! is_array($body)) {
            $this->error("{$path}: each 缺少 body（节点树数组）");
        }

        $loop = "foreach ({$items} ?? [] as ";
        if (array_key_exists('index', $n)) {
            $index = $n['index'];
            if (! is_string($index) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
                $this->error("{$path}: each 的 index 必须是合法变量名");
            }
            $loop .= '$' . $index . ' => ';
        }

        return "<?php {$loop}\$" . $as . "): ?>\n" . $this->compileNodes($body, $path . '.body') . "\n<?php endforeach ?>";
    }

    private function compileForm(array $n, string $path): string
    {
        $action = $this->interpolate($this->requireString($n, 'action', $path), $path);
        $method = $this->literal((string) ($n['method'] ?? 'post'), $path, 'method');
        if ($method !== 'get' && $method !== 'post') {
            $this->error("{$path}: method 必须是 \"get\" 或 \"post\"");
        }
        $fields = $n['fields'] ?? null;
        if (! is_array($fields)) {
            $this->error("{$path}: form 缺少 fields（字段数组）");
        }

        $attr = $this->forwardedAttrs($n, ['action', 'method', 'fields'], $path, true);
        $out = '<form action="' . $action . '" method="' . $method . '"' . $attr . '>';
        $lines = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                $this->error("{$path}.fields[{$i}]: 字段必须是对象");
            }
            $lines[] = $this->compileField($field, $path . '.fields[' . $i . ']');
        }
        $out .= "\n" . implode("\n", $lines) . "\n</form>";

        return $out;
    }

    private function compileField(array $n, string $path): string
    {
        $this->requireStructuralType($n, 'field', $path);
        $name = $this->literal($this->requireString($n, 'name', $path), $path, 'name');
        $label = $this->literal($this->requireString($n, 'label', $path), $path, 'label');
        $extra = $this->forwardedAttrs(
            $n,
            ['name', 'label', 'input', 'value', 'required', 'placeholder', 'checked', 'rows', 'options'],
            $path,
            true
        );
        $input = $n['input'] ?? 'text';
        if (! is_string($input) || ! in_array($input, self::INPUT_TYPES, true)) {
            $this->error("{$path}: 非法的 input 类型 \"" . (is_string($input) ? $input : gettype($input)) . '"');
        }
        if ($input === 'select' && array_key_exists('value', $n)) {
            $this->error("{$path}: select 字段不支持 value 绑定（选中态绑定不在当前范围）");
        }
        if ($input !== 'select' && array_key_exists('options', $n)) {
            $this->error("{$path}: options 仅用于 select 字段");
        }

        if ($input === 'submit') {
            return '  <input type="submit" value="' . $label . '"' . $extra . '>';
        }

        $out = '';
        if ($input !== 'hidden') {
            $out .= '  <label for="' . $name . '">' . $label . "</label>\n";
        }

        $value = $this->bindValue(array_key_exists('value', $n) ? $this->requireString($n, 'value', $path) : null, $path);

        if (in_array($input, ['text', 'password', 'email', 'number'], true)) {
            $out .= '  <input type="' . $input . '" name="' . $name . '" id="' . $name . '"';
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }
            if (array_key_exists('placeholder', $n)) {
                $out .= ' placeholder="' . $this->interpolate($this->requireString($n, 'placeholder', $path), $path) . '"';
            }
            if (($n['required'] ?? false) === true) {
                $out .= ' required';
            }
            $out .= $extra . '>';

            return $out;
        }

        if ($input === 'hidden') {
            $out .= '  <input type="hidden" name="' . $name . '"';
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }

            return $out . $extra . '>';
        }

        if ($input === 'textarea') {
            $rows = $n['rows'] ?? 4;
            if (! is_int($rows) || $rows < 1) {
                $this->error("{$path}: textarea 的 rows 必须是正整数");
            }
            $content = $value !== '' ? $value : '';

            return $out . '  <textarea name="' . $name . '" id="' . $name . '" rows="' . $rows . '"' . $extra . '>' . $content . '</textarea>';
        }

        if ($input === 'select') {
            $options = $n['options'] ?? null;
            if (! is_array($options)) {
                $this->error("{$path}: select 字段缺少 options 映射");
            }
            $out .= '  <select name="' . $name . '" id="' . $name . '"' . $extra . '>';
            foreach ($options as $optValue => $optLabel) {
                $optValue = $this->literal((string) $optValue, $path, 'option value');
                $optLabel = $this->literal((string) $optLabel, $path, 'option 文本');
                $out .= "\n    <option value=\"" . $optValue . '">' . $optLabel . '</option>';
            }

            return $out . "\n  </select>";
        }

        // checkbox
        $out .= '  <input type="checkbox" name="' . $name . '" id="' . $name . '"';
        if ($value !== '') {
            $out .= ' value="' . $value . '"';
        }
        if (array_key_exists('checked', $n)) {
            $checked = $this->compilePath($this->requireString($n, 'checked', $path), $path);
            $out .= "<?= ({$checked} ?? null) ? ' checked' : '' ?>";
        }

        return $out . $extra . '>';
    }

    private function compileTable(array $n, string $path): string
    {
        $items = $this->compilePath($this->requireString($n, 'items', $path), $path);
        $as = $n['as'] ?? 'row';
        if (! is_string($as) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $as)) {
            $this->error("{$path}: table 的 as 必须是合法变量名");
        }
        $columns = $n['columns'] ?? null;
        if (! is_array($columns)) {
            $this->error("{$path}: table 缺少 columns（列数组）");
        }
        $empty = array_key_exists('empty', $n) ? $this->literal($this->requireString($n, 'empty', $path), $path, 'empty') : null;
        $attr = $this->forwardedAttrs($n, ['items', 'as', 'empty', 'columns'], $path, true);

        $head = '<thead><tr>';
        $rows = [];
        foreach ($columns as $i => $column) {
            $columnPath = $path . '.columns[' . $i . ']';
            if (! is_array($column)) {
                $this->error("{$columnPath}: 列必须是对象");
            }
            $this->requireStructuralType($column, 'column', $columnPath);
            $label = $this->literal($this->requireString($column, 'label', $columnPath), $columnPath, 'label');
            $columnAttr = $this->forwardedAttrs($column, ['label', 'bind', 'content'], $columnPath, true);
            $head .= '<th>' . $label . '</th>';

            $hasBind = array_key_exists('bind', $column);
            $hasContent = array_key_exists('content', $column);
            if ($hasBind && $hasContent) {
                $this->error($columnPath . ': 列同时指定 bind 与 content');
            }
            if (! $hasBind && ! $hasContent) {
                $this->error($columnPath . ': 列缺少 bind 或 content');
            }

            if ($hasBind) {
                $bind = $this->requireString($column, 'bind', $columnPath);
                $rows[] = '<td' . $columnAttr . '>' . $this->bindValue($as . '.' . $bind, $columnPath) . '</td>';
            } else {
                if (! is_array($column['content'])) {
                    $this->error($columnPath . '.content: 必须是节点树数组');
                }
                $rows[] = '<td' . $columnAttr . '>' . $this->compileNodes($column['content'], $columnPath . '.content') . '</td>';
            }
        }
        $head .= '</tr></thead>';

        $colspan = count($columns);
        $out = "<table{$attr}>\n{$head}\n<tbody>\n";
        if ($empty !== null) {
            $out .= "<?php if (({$items} ?? []) === []): ?>\n<tr><td colspan=\"{$colspan}\">{$empty}</td></tr>\n<?php else: ?>\n";
        }
        $out .= "<?php foreach ({$items} ?? [] as \${$as}): ?>\n<tr>\n" . implode("\n", $rows) . "\n</tr>\n<?php endforeach ?>";
        if ($empty !== null) {
            $out .= "\n<?php endif ?>";
        }

        return $out . "\n</tbody>\n</table>";
    }

    /**
     * Generic element node. text / if / each emit no tag of their own, so this
     * is the only way to hang attributes on a wrapper — which is where front-end
     * state containers belong (Alpine's x-data, Vue's v-scope).
     */
    private function compileEl(array $n, string $path): string
    {
        $tag = strtolower($this->literal($this->requireString($n, 'tag', $path), $path, 'tag'));
        if (! preg_match(self::TAG_PATTERN, $tag)) {
            $this->error("{$path}: 非法的 tag \"{$tag}\"，需为小写 HTML 标签名");
        }

        $attrs = $this->forwardedAttrs($n, ['tag', 'body'], $path, true);

        $body = $n['body'] ?? [];
        if (! is_array($body)) {
            $this->error("{$path}: el 的 body 必须是节点树数组");
        }
        $inner = $this->compileNodes($body, $path . '.body');

        return $inner === ''
            ? "<{$tag}{$attrs}></{$tag}>"
            : "<{$tag}{$attrs}>\n{$inner}\n</{$tag}>";
    }

    private function compileComponent(array $n, string $path): string
    {
        $name = $this->literal($this->requireString($n, 'name', $path), $path, 'name');
        $this->forwardedAttrs($n, ['name', 'data'], $path, false);   // component emits its own markup

        if (! array_key_exists('data', $n)) {
            return "<?= \$this->component('" . $this->str($name) . "') ?>";
        }

        $data = $n['data'];
        if (! is_array($data)) {
            $this->error("{$path}: component 的 data 必须是对象");
        }

        $lines = [];
        foreach ($data as $key => $value) {
            if (! is_string($value)) {
                $this->error("{$path}: component data 的 \"{$key}\" 必须是字符串（值支持 {{ 路径 }} 插值）");
            }
            $lines[] = "    '" . $this->str((string) $key) . "' => " . $this->interpolatePhp($value, $path . '.data.' . $key);
        }

        return "<?= \$this->component('" . $this->str($name) . "', [\n" . implode(",\n", $lines) . ",\n]) ?>";
    }

    /**
     * HTML-context interpolation: {{ path }} -> ## $var['key'] ?? '' ## sugar.
     * The template engine escapes these at render time.
     */
    private function interpolate(string $text, string $path): string
    {
        $this->assertInterpolationBalanced($text, $path);

        return preg_replace_callback(
            self::INTERPOLATION,
            function (array $m) use ($path): string {
                $php = $this->compilePath(trim($m[1]), $path);

                return '## ' . $php . " ?? '' ##";
            },
            $text
        );
    }

    /**
     * PHP-context interpolation for component data arrays: builds a PHP
     * expression string, e.g. 'edit ' . ($user['name'] ?? '') .
     * Never emits ## ## sugar here — it would corrupt the PHP literal.
     *
     * Values stay unescaped: the component template owns that decision
     * ($this->e() for text, $this->raw() for markup), so escaping here would
     * double-encode every interpolated value that contains HTML.
     */
    private function interpolatePhp(string $text, string $path): string
    {
        $this->assertInterpolationBalanced($text, $path);

        $parts = preg_split(self::INTERPOLATION, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $exprs = [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if ($part !== '') {
                    $exprs[] = "'" . addcslashes($part, "\\'") . "'";
                }
            } else {
                $php = $this->compilePath(trim($part), $path);
                $exprs[] = '(' . $php . " ?? '')";
            }
        }

        return $exprs === [] ? "''" : implode(' . ', $exprs);
    }

    private function compileCondition(string $expr, string $path): string
    {
        $negated = str_starts_with($expr, '!');
        if ($negated) {
            $expr = substr($expr, 1);
        }
        $php = $this->compilePath($expr, $path);

        return $negated ? "!({$php} ?? null)" : "{$php} ?? null";
    }

    /** Compile a dot path into PHP array access: user.name -> $user['name']. */
    private function compilePath(string $path, string $where): string
    {
        if (! preg_match(self::PATH_PATTERN, $path)) {
            $this->error("{$where}: 非法路径 \"{$path}\"，仅支持 a.b.c 形式的变量路径");
        }

        $segments = explode('.', $path);
        $php = '$' . array_shift($segments);
        foreach ($segments as $segment) {
            $php .= "['" . $this->str($segment) . "']";
        }

        return $php;
    }

    /** Compile a bound path into escaped attribute sugar: ## $user['name'] ?? '' ##. */
    private function bindValue(?string $path, string $where): string
    {
        if ($path === null) {
            return '';
        }

        return '## ' . $this->compilePath($path, $where) . " ?? '' ##";
    }

    private function assertInterpolationBalanced(string $text, string $path): void
    {
        // A third brace defeats the counting below: '{{{ a }}}' contains one
        // '{{' and one '}}', so it passes as balanced, and the regex then
        // matches only the inner '{{ a }}' — leaving stray braces wrapped around
        // the compiled sugar in the output.
        if (str_contains($text, '{{{') || str_contains($text, '}}}')) {
            $this->error("{$path}: 插值符号不能连续三个花括号（{{{ 或 }}}），请写 {{ path }}");
        }

        $open = substr_count($text, '{{');
        if ($open === 0) {
            return;
        }
        if ($open !== substr_count($text, '}}')) {
            $this->error("{$path}: 插值符号未配对（{{ 与 }} 数量不一致）");
        }
    }

    private function requireString(array $n, string $key, string $path): string
    {
        if (! isset($n[$key]) || ! is_string($n[$key])) {
            $this->error("{$path}: 缺少 string 字段 \"{$key}\"");
        }

        return $n[$key];
    }

    /**
     * Nested structures (field / column) are typed by their position — in this
     * variant that position is the element name — so a written type must match
     * it instead of being silently ignored. Keeps the node model identical to
     * the YAML variant.
     */
    private function requireStructuralType(array $n, string $expected, string $path): void
    {
        if (! array_key_exists('type', $n)) {
            return;
        }
        if ($n['type'] !== $expected) {
            $this->error("{$path}: type 必须是 \"{$expected}\"（{$expected} 是内嵌结构，位置已决定类型）");
        }
    }

    /**
     * Literal fields are compiled as-is — interpolation has no meaning there,
     * so {{ }} is a compile error rather than a silent no-op.
     */
    private function literal(string $value, string $path, string $field): string
    {
        if (str_contains($value, '{{') || str_contains($value, '}}')) {
            $this->error("{$path}: \"{$field}\" 是字面量字段，不支持 {{ }} 插值");
        }

        return $value;
    }

    /** Escape a value for a PHP single-quoted string. */
    private function str(string $value): string
    {
        return str_replace("'", "\\'", $value);
    }

    /**
     * Raise a compile error. A frontend overrides newException() so that the
     * failures it produces through the shared layer still arrive as its own
     * exception class, and one catch keeps working for everything it throws.
     */
    protected function error(string $message): never
    {
        throw $this->newException($message);
    }

    protected function newException(string $message): CompileException
    {
        return new CompileException($message);
    }
}

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
     * Fields that only mean something for particular input types. Written
     * anywhere else they used to compile and then leave no trace in the output
     * — the silent drop this module refuses everywhere else, and the reason each
     * misuse is named where it happens instead.
     *
     * @var array<string, list<string>>
     */
    private const FIELD_SCOPES = [
        'placeholder' => ['text', 'password', 'email', 'number'],
        'checked' => ['checkbox'],
        'rows' => ['textarea'],
    ];

    /**
     * Input types whose `required` has an HTML meaning. Every other type would
     * drop the attribute on the floor, so asking for it there is a mistake.
     *
     * @var list<string>
     */
    private const REQUIRED_INPUTS = ['text', 'password', 'email', 'number', 'textarea', 'select', 'checkbox'];

    /**
     * Attributes the DSL does not define are forwarded verbatim only when they
     * belong to a known front-end convention or a common HTML hook. Everything
     * else is treated as a typo: silently dropping an Alpine directive the
     * author did write is the worst possible outcome, because the page still
     * compiles and the directive simply vanishes.
     */
    private const PASSTHROUGH_PREFIXES = ['x-', 'v-', 'hx-', 'data-'];

    private const PASSTHROUGH_EXACT = ['class', 'id', 'style', 'bind'];

    private const TAG_PATTERN = '/^[a-z][a-z0-9-]*$/';

    /**
     * A bind value is a browser-side name, not a server-side path: it names the
     * JavaScript variable (or path) the framework binds to. Validated so a
     * server path or an interpolation cannot be written there by mistake.
     */
    private const BIND_PATTERN = '/^[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*|\[\d+\])*$/';

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
     * Node objects built with the Html factory are accepted anywhere a node is expected
     * and normalized into the array model first, so every entry point hands the compiler
     * the same thing: arrays.
     *
     * @param array<string, mixed> $page
     */
    public function compile(array $page): string
    {
        return $this->compilePage($this->normalize($page));
    }

    /**
     * Replace Node objects with the arrays they stand for, recursively.
     *
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private function normalize(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = match (true) {
                $item instanceof Node => $this->normalize($item->toArray()),
                is_array($item) => $this->normalize($item),
                default => $item,
            };
        }

        return $out;
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
            'this compiler only accepts array page declarations; for source syntax use '
            . 'migears/xml-pages or migears/yaml-pages, and call compile() for array pages'
        );
    }

    public function compileFile(string $path): string
    {
        if (! is_file($path)) {
            throw $this->newException("page file not found: {$path}");
        }

        $source = file_get_contents($path);
        if ($source === false) {
            throw $this->newException("cannot read page file: {$path}");
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
            throw $this->newException("cannot create output directory: {$dir}");
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
            $hint = implode('" or "', array_map(static fn (string $f): string => $f . $rest, $forms));
            $this->error("{$path}: unknown attribute \"{$name}\"; Alpine event/binding directives use a colon, write \"{$hint}\"");
        }

        if (! $this->isForwardable($name)) {
            $this->error("{$path}: unknown attribute \"{$name}\"; the passthrough accepts the '@event' shorthand, "
                . 'directive names with a colon (x-on:click / wire:click / :href, etc.), the '
                . implode(' / ', self::PASSTHROUGH_PREFIXES) . ' prefixes and '
                . implode(' / ', self::PASSTHROUGH_EXACT)
                . '; check the spelling');
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

        $this->error("{$path}: attribute \"{$name}\" must have a scalar value, got " . gettype($value));
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
        return "attribute \"{$name}\"";
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
                    . " collides with the node field \"{$name}\"; use that field directly");
            }

            $target = $candidate['explicit'] ? $name : $this->mapAttributeName($name, $path);

            if ($target === 'bind') {
                $this->assertBindName($candidate['value'], $path);
            }

            if (isset($emitted[$target])) {
                $this->error($candidate['explicit']
                    ? "{$path}: " . $this->explicitAttrRef($target) . ' duplicates an existing attribute of the same name'
                    : "{$path}: attribute \"{$target}\" defined twice");
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

    /** A bind value names a JavaScript variable/path; anything else is a mistake. */
    private function assertBindName(mixed $value, string $path): void
    {
        if (is_string($value) && (str_contains($value, '{{') || str_contains($value, '}}'))) {
            $this->error("{$path}: bind is a browser-side variable name and does not support {{ }} interpolation; use value / pop for server-side rendering");
        }
        if (! is_string($value) || ! preg_match(self::BIND_PATTERN, $value)) {
            $this->error("{$path}: bind must hold a JS variable name or path (e.g. user.email), got "
                . (is_string($value) ? "\"{$value}\"" : gettype($value)));
        }
    }

    private function renderAttr(string $name, string $value, array $n, string $path, bool $emitsTag): string
    {
        if (! $emitsTag) {
            $this->error("{$path}: node " . $this->nodeRef((string) $n['type'])
                . " emits no tag and cannot carry attribute \"{$name}\"; wrap the content in "
                . $this->containerRef('el'));
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
                $this->error('page: unknown field "' . $key . '" (allowed: title / layout / body / sections)');
            }
        }

        if ($hasLayout && $hasBody) {
            $this->error('page: layout and body cannot be set together; use sections when layout is set');
        }
        if ($hasLayout && ! $hasSections) {
            $this->error('page: layout requires sections to be provided as well');
        }
        if ($hasSections && ! $hasLayout) {
            $this->error('page: sections cannot be used without layout; use body instead');
        }
        if (! $hasLayout && ! $hasBody) {
            $this->error('page: no page content; provide body (without layout) or layout + sections');
        }

        if ($hasLayout) {
            return $this->compileLayout($page);
        }

        if (array_key_exists('title', $page) && $this->warn !== null) {
            ($this->warn)('page: title only applies when layout is set; this page has no layout, so title is ignored');
        }

        $body = $this->requireList($page['body'], 'body', 'a node tree array');

        return $this->compileNodes($body, 'body');
    }

    private function compileLayout(array $page): string
    {
        $layout = $page['layout'];
        if (! is_string($layout)) {
            $this->error('page: layout must be a string, got ' . gettype($layout));
        }
        $layout = $this->literal($layout, 'page', 'layout');
        $out = "<?php \$this->extends('" . $this->str($layout) . "') ?>\n";

        $sections = $this->requireMap(
            $page['sections'],
            'page',
            'sections must be a map of section name to node tree'
        );

        $ordered = [];
        if (array_key_exists('title', $page) && ! array_key_exists('title', $sections)) {
            $title = $page['title'];
            if (! is_string($title)) {
                $this->error('page: title must be a string, got ' . gettype($title));
            }
            $ordered[] = ['name' => 'title', 'nodes' => [['type' => 'text', 'text' => $title]]];
        }
        foreach ($sections as $name => $nodes) {
            $ordered[] = [
                'name' => (string) $name,
                'nodes' => $this->requireList($nodes, 'sections.' . $name, 'a node tree array'),
            ];
        }

        foreach ($ordered as $section) {
            $name = $this->literal($section['name'], 'sections', 'section name');
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
            $this->error("{$path}: node must be an array");
        }
        if (! isset($node['type']) || ! is_string($node['type'])) {
            $this->error("{$path}: node is missing its type field");
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
            default => $this->error("{$path}: unknown node type \"{$node['type']}\""),
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
            $this->error("{$path}: heading level must be an integer from 1 to 6, got " . var_export($level, true));
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
        if (! array_key_exists('then', $n)) {
            $this->error("{$path}: if is missing then (a node tree array)");
        }
        $then = $this->requireList($n['then'], $path . '.then', 'a node tree array');

        $cond = $this->compileCondition($when, $path);

        $out = "<?php if ({$cond}): ?>\n" . $this->compileNodes($then, $path . '.then');
        if (array_key_exists('else', $n)) {
            $else = $this->requireList($n['else'], $path . '.else', 'a node tree array');
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
            $this->error("{$path}: each as must be a valid variable name");
        }

        if (! array_key_exists('body', $n)) {
            $this->error("{$path}: each is missing body (a node tree array)");
        }
        $body = $this->requireList($n['body'], $path . '.body', 'a node tree array');

        $loop = "foreach ({$items} ?? [] as ";
        if (array_key_exists('index', $n)) {
            $index = $n['index'];
            if (! is_string($index) || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $index)) {
                $this->error("{$path}: each index must be a valid variable name");
            }
            $loop .= '$' . $index . ' => ';
        }

        return "<?php {$loop}\$" . $as . "): ?>\n" . $this->compileNodes($body, $path . '.body') . "\n<?php endforeach ?>";
    }

    private function compileForm(array $n, string $path): string
    {
        $action = $this->interpolate($this->requireString($n, 'action', $path), $path);
        // Check the type before touching the value: casting first would emit a
        // PHP warning ("Array to string conversion") and then report a type
        // fault with the enum message, which names the wrong problem.
        $method = $n['method'] ?? 'post';
        if (! is_string($method)) {
            $this->error("{$path}: method must be the string \"get\" or \"post\", got " . gettype($method));
        }
        $method = $this->literal($method, $path, 'method');
        if ($method !== 'get' && $method !== 'post') {
            $this->error("{$path}: method must be \"get\" or \"post\"");
        }
        if (! array_key_exists('fields', $n)) {
            $this->error("{$path}: form is missing fields (an array of fields)");
        }
        $fields = $this->requireList($n['fields'], $path . '.fields', 'an array of fields');

        $attr = $this->forwardedAttrs($n, ['action', 'method', 'fields'], $path, true);
        $out = '<form action="' . $action . '" method="' . $method . '"' . $attr . '>';
        $lines = [];
        foreach ($fields as $i => $field) {
            if (! is_array($field)) {
                $this->error("{$path}.fields[{$i}]: field must be an array");
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
        // The label's `for` and the control's `id` are the same identity — and it
        // defaults to the field name, which is what keeps HTML hooks and the DTO
        // key aligned. An explicit id overrides it instead of being emitted a
        // second time.
        $id = array_key_exists('id', $n)
            ? $this->literal($this->requireString($n, 'id', $path), $path, 'id')
            : $name;
        $extra = $this->forwardedAttrs(
            $n,
            ['name', 'label', 'input', 'value', 'required', 'placeholder', 'checked', 'rows', 'options', 'id'],
            $path,
            true
        );
        $input = $n['input'] ?? 'text';
        if (! is_string($input) || ! in_array($input, self::INPUT_TYPES, true)) {
            $this->error("{$path}: invalid input type \"" . (is_string($input) ? $input : gettype($input)) . '"');
        }
        if ($input === 'select' && array_key_exists('value', $n)) {
            $this->error("{$path}: select fields do not support value binding (selected-state binding is out of scope)");
        }
        if ($input !== 'select' && array_key_exists('options', $n)) {
            $this->error("{$path}: options is only for select fields");
        }
        // The `=== true` test below would silently ignore any other type, which
        // is the silent drop this compiler refuses everywhere else.
        if (array_key_exists('required', $n) && ! is_bool($n['required'])) {
            $this->error("{$path}: required must be a boolean, got " . gettype($n['required']));
        }
        $this->assertFieldScope($n, $input, $path);

        // A submit button takes its text from label; a bound value there would
        // be read, ignored and lost.
        if ($input === 'submit' && array_key_exists('value', $n)) {
            $this->error("{$path}: submit fields do not support value binding; use label for the button text");
        }

        $required = ($n['required'] ?? false) === true;
        if ($required && ! in_array($input, self::REQUIRED_INPUTS, true)) {
            $this->error("{$path}: required is only for the " . implode(' / ', self::REQUIRED_INPUTS)
                . " fields; the input here is \"{$input}\"");
        }

        if ($input === 'submit') {
            return '  <input type="submit" value="' . $label . '"'
                . (array_key_exists('id', $n) ? ' id="' . $id . '"' : '')
                . $extra . '>';
        }

        $out = '';
        if ($input !== 'hidden') {
            $out .= '  <label for="' . $id . '">' . $label . "</label>\n";
        }

        $value = $this->resolveValue(array_key_exists('value', $n) ? $this->requireString($n, 'value', $path) : null, $path);

        if (in_array($input, ['text', 'password', 'email', 'number'], true)) {
            $out .= '  <input type="' . $input . '" name="' . $name . '" id="' . $id . '"';
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }
            if (array_key_exists('placeholder', $n)) {
                $out .= ' placeholder="' . $this->interpolate($this->requireString($n, 'placeholder', $path), $path) . '"';
            }
            if ($required) {
                $out .= ' required';
            }
            $out .= $extra . '>';

            return $out;
        }

        if ($input === 'hidden') {
            $out .= '  <input type="hidden" name="' . $name . '"'
                . (array_key_exists('id', $n) ? ' id="' . $id . '"' : '');
            if ($value !== '') {
                $out .= ' value="' . $value . '"';
            }

            return $out . $extra . '>';
        }

        if ($input === 'textarea') {
            $rows = $n['rows'] ?? 4;
            if (! is_int($rows) || $rows < 1) {
                $this->error("{$path}: textarea rows must be a positive integer");
            }
            $content = $value !== '' ? $value : '';

            return $out . '  <textarea name="' . $name . '" id="' . $id . '" rows="' . $rows . '"'
                . ($required ? ' required' : '') . $extra . '>' . $content . '</textarea>';
        }

        if ($input === 'select') {
            $options = $n['options'] ?? null;
            if (! is_array($options)) {
                $this->error("{$path}: select field is missing an options map");
            }
            $out .= '  <select name="' . $name . '" id="' . $id . '"'
                . ($required ? ' required' : '') . $extra . '>';
            foreach ($options as $optValue => $optLabel) {
                $optValue = $this->literal((string) $optValue, $path, 'option value');
                // Option text is a literal field, so it is a string or nothing —
                // casting an array here would leak "Array to string conversion".
                if (! is_string($optLabel)) {
                    $this->error("{$path}: option \"{$optValue}\" text must be a string, got " . gettype($optLabel));
                }
                $optLabel = $this->literal($optLabel, $path, 'option text');
                $out .= "\n    <option value=\"" . $optValue . '">' . $optLabel . '</option>';
            }

            return $out . "\n  </select>";
        }

        // checkbox
        $out .= '  <input type="checkbox" name="' . $name . '" id="' . $id . '"';
        if ($required) {
            $out .= ' required';
        }
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
            $this->error("{$path}: table as must be a valid variable name");
        }
        if (! array_key_exists('columns', $n)) {
            $this->error("{$path}: table is missing columns (an array of columns)");
        }
        $columns = $this->requireList($n['columns'], $path . '.columns', 'an array of columns');
        $empty = array_key_exists('empty', $n) ? $this->literal($this->requireString($n, 'empty', $path), $path, 'empty') : null;
        $attr = $this->forwardedAttrs($n, ['items', 'as', 'empty', 'columns'], $path, true);

        $head = '<thead><tr>';
        $rows = [];
        foreach ($columns as $i => $column) {
            $columnPath = $path . '.columns[' . $i . ']';
            if (! is_array($column)) {
                $this->error("{$columnPath}: column must be an array");
            }
            $this->requireStructuralType($column, 'column', $columnPath);
            $label = $this->literal($this->requireString($column, 'label', $columnPath), $columnPath, 'label');
            $head .= '<th>' . $label . '</th>';
            $columnAttr = $this->forwardedAttrs($column, ['label', 'pop', 'content'], $columnPath, true);

            $hasPop = array_key_exists('pop', $column);
            $hasContent = array_key_exists('content', $column);

            if ($hasPop && $hasContent) {
                $this->error($columnPath . ': a column cannot specify both pop and content');
            }

            if (! $hasPop && ! $hasContent) {
                $this->error($columnPath . ': a column needs either pop or content');
            }

            if ($hasPop) {
                $reference = $this->requireString($column, 'pop', $columnPath);
                $rows[] = '<td' . $columnAttr . '>'
                    . $this->resolveValue($this->rowReference($reference, $as, $columnPath), $columnPath) . '</td>';
            } else {
                $content = $this->requireList($column['content'], $columnPath . '.content', 'a node tree array');
                $rows[] = '<td' . $columnAttr . '>' . $this->compileNodes($content, $columnPath . '.content') . '</td>';
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
            $this->error("{$path}: invalid tag \"{$tag}\"; tag names must be lowercase HTML");
        }

        $attrs = $this->forwardedAttrs($n, ['tag', 'body'], $path, true);

        // An absent body means "no children"; a body that is present but is not
        // a node list — `null` from an emptied mapping key, a string, a bare
        // node map — is a mistake and is named as one instead of being read as
        // "empty".
        $body = array_key_exists('body', $n)
            ? $this->requireList($n['body'], $path . '.body', 'a node tree array')
            : [];
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

        $data = $this->requireMap($n['data'], $path, 'component data must be a map of key => string');

        $lines = [];
        foreach ($data as $key => $value) {
            // The key names a variable inside the component, so it is a literal
            // field: `{{ }}` there is not interpolated but emitted verbatim as
            // part of the PHP array key.
            $key = $this->literal((string) $key, $path, 'data key');
            if (! is_string($value)) {
                $this->error("{$path}: component data \"{$key}\" must be a string (values support {{ path }} interpolation)");
            }
            $lines[] = "    '" . $this->str($key) . "' => " . $this->interpolatePhp($value, $path . '.data.' . $key);
        }

        return "<?= \$this->component('" . $this->str($name) . "', [\n" . implode(",\n", $lines) . ",\n]) ?>";
    }

    /**
     * HTML-context interpolation: {{ path }} -> ## $var['key'] ?? '' ## sugar.
     * The template engine escapes these at render time.
     */
    private function interpolate(string $text, string $path): string
    {
        $text = $this->escapeTemplateMarker($text);
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
                    // Escape after the PHP-string escaping: the marker pass in
                    // migears/template removes exactly the backslashes added here.
                    $exprs[] = "'" . $this->escapeTemplateMarker(addcslashes($part, "\\'")) . "'";
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
            $this->error("{$where}: invalid path \"{$path}\"; only a.b.c variable paths are supported");
        }

        $segments = explode('.', $path);
        $php = '$' . array_shift($segments);
        foreach ($segments as $segment) {
            $php .= "['" . $this->str($segment) . "']";
        }

        return $php;
    }

    /** Compile a data reference into escaped attribute sugar: ## $user['name'] ?? '' ##. */
    private function resolveValue(?string $reference, string $where): string
    {
        if ($reference === null) {
            return '';
        }

        return '## ' . $this->compilePath($this->stripBraces($reference, $where, 'value'), $where) . " ?? '' ##";
    }

    /**
     * A pop reference names the row-scoped data to render, written as {{ row.name }}.
     *
     * The braces mark it as data — the same marker pages use for interpolation, so
     * "data is rendered here" reads the same in both places — and the leading
     * variable must be the table's own row variable. Without it, 'name' would
     * silently mean row['name'], and 'user.name' would silently mean
     * row['user']['name'] instead of the page-level user.
     */
    private function rowReference(string $reference, string $row, string $where): string
    {
        $trimmed = trim($reference);
        if (! preg_match('/^\{\{\s*(.+?)\s*\}\}$/', $trimmed, $m)) {
            $this->error("{$where}.pop: write it as {{ {$row}." . (trim($trimmed) === '' ? 'field' : trim($trimmed))
                . " }}; data references all use {{ }} markers");
        }

        $path = trim($m[1]);
        if (explode('.', $path)[0] !== $row) {
            $this->error("{$where}.pop: must reference the row variable \"{$row}\", got \"{$reference}\"");
        }

        return $path;
    }

    /**
     * Accept a data reference with or without the {{ }} marker; the marker is the
     * recommended spelling (it makes data visible in the source), the bare path is
     * kept for the fields that predate it.
     */
    private function stripBraces(string $reference, string $where, string $field): string
    {
        $trimmed = trim($reference);
        if (preg_match('/^\{\{\s*(.+?)\s*\}\}$/', $trimmed, $m)) {
            return trim($m[1]);
        }
        if (str_contains($trimmed, '{{') || str_contains($trimmed, '}}')) {
            $this->error("{$where}: {$field} has unbalanced interpolation markers; write {{ path }}");
        }

        return $trimmed;
    }

    private function assertInterpolationBalanced(string $text, string $path): void
    {
        // A third brace defeats the counting below: '{{{ a }}}' contains one
        // '{{' and one '}}', so it passes as balanced, and the regex then
        // matches only the inner '{{ a }}' — leaving stray braces wrapped around
        // the compiled sugar in the output.
        if (str_contains($text, '{{{') || str_contains($text, '}}}')) {
            $this->error("{$path}: interpolation markers cannot run three braces ({{{ or }}}); write {{ path }}");
        }

        $open = substr_count($text, '{{');
        if ($open === 0) {
            return;
        }
        if ($open !== substr_count($text, '}}')) {
            $this->error("{$path}: unbalanced interpolation markers (mismatched {{ and }} counts)");
        }
    }

    /**
     * Guard a collection that has to be a list: node trees (then / else / body /
     * sections.<name> / content) and the field and column lists.
     *
     * is_array() alone is not enough. A bare node map is an array too, so it
     * passes and only fails deeper — as "content[type]: node must be an array" —
     * blaming a node that was never the problem instead of the missing list
     * wrapper.
     * Both failures are named here, where the mistake actually is.
     *
     * @return list<mixed>
     */
    private function requireList(mixed $value, string $where, string $expected): array
    {
        if (! is_array($value)) {
            $this->error("{$where}: must be {$expected}, got " . gettype($value));
        }
        if (! array_is_list($value)) {
            $this->error("{$where}: must be {$expected} (a list), but got a key-value map; wrap it in [ ] to make a list");
        }

        return $value;
    }

    /**
     * Guard a collection that has to be a map: `sections` and `component.data`.
     * A positional list reaching one of these has no key to name its entries
     * with, so the misuse is named here instead of surfacing later as a section
     * or a data key called "0".
     *
     * An empty array passes: `[]` is an empty mapping as much as an empty list,
     * and there are no entries whose meaning could be misread.
     *
     * $expected carries the whole predicate so the message keeps naming the
     * field, e.g. "page: sections must be a map of section name to node tree".
     *
     * @return array<array-key, mixed>
     */
    private function requireMap(mixed $value, string $where, string $expected): array
    {
        if (! is_array($value)) {
            $this->error("{$where}: {$expected}, got " . gettype($value));
        }
        if ($value !== [] && array_is_list($value)) {
            $this->error("{$where}: {$expected} (a key-value map), but got a list");
        }

        return $value;
    }

    private function requireString(array $n, string $key, string $path): string
    {
        if (! isset($n[$key]) || ! is_string($n[$key])) {
            $this->error("{$path}: missing string field \"{$key}\"");
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
            $this->error("{$path}: type must be \"{$expected}\" ({$expected} is a nested structure; its position decides the type)");
        }
    }

    /**
     * Reject a field that has no meaning for the node's input type:
     * `placeholder` on a select, `checked` on a text box, `rows` on a single
     * line. Each of these used to compile and then leave no trace in the
     * output, so the author's intent disappeared without a word — naming the
     * misuse where it happens is the only honest answer.
     */
    private function assertFieldScope(array $n, string $input, string $path): void
    {
        foreach (self::FIELD_SCOPES as $field => $inputs) {
            if (! array_key_exists($field, $n) || in_array($input, $inputs, true)) {
                continue;
            }
            $this->error("{$path}: \"{$field}\" is only for the " . implode(' / ', $inputs)
                . " fields; the input here is \"{$input}\"");
        }
    }

    /**
     * Literal fields are compiled as-is — interpolation has no meaning there,
     * so {{ }} is a compile error rather than a silent no-op.
     */
    private function literal(string $value, string $path, string $field): string
    {
        if (str_contains($value, '{{') || str_contains($value, '}}')) {
            $this->error("{$path}: \"{$field}\" is a literal field and does not support {{ }} interpolation");
        }

        $this->assertNoTemplateMarker($value, $path, "\"{$field}\"");

        return $value;
    }

    /**
     * The page source is compiled to .tpl.php sugar, and that output is scanned again
     * by migears/template — a text-level pass with no notion of PHP context. Literal
     * fields are emitted verbatim (attributes, tags, names), so a "##" there would be
     * read back as template interpolation. They are literals, not text: reject instead
     * of guessing. Text, attribute values and component values go through the
     * interpolation helpers, which escape the marker instead (see escapeTemplateMarker).
     */
    private function assertNoTemplateMarker(string $text, string $path, string $where): void
    {
        if (str_contains($text, '##')) {
            $this->error("{$path}: {$where} is a literal and may not contain \"##\" (template-level syntax)");
        }
    }

    /**
     * Prefix the template layer's escape to every run of two or more hashes, so a page
     * can carry literal "##" text: {{ }} stays the only interpolation marker in pages,
     * and the compiled output renders the hashes as written.
     */
    private function escapeTemplateMarker(string $text): string
    {
        return preg_replace('/#{2,}/', '\\\\$0', $text) ?? $text;
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
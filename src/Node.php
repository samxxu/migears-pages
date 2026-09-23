<?php

declare(strict_types=1);

namespace MiGears\Pages;

/**
 * A page node written with the user-level Html factory.
 *
 * The object only records what was written — it validates nothing. Compiler::compile()
 * normalizes the tree into plain arrays at its entry point, so pages written with the
 * factory, hand-written arrays, and the XML / YAML frontends all reach the compiler
 * with the same node model: one vocabulary, one set of checks, one error catalogue.
 *
 * The rules the factory follows:
 *
 * - a factory takes the value without which the node would not be that kind of node
 *   (level, tag, items, action, name, label); the two iteration factories also take
 *   their loop header as optional arguments (`as`, `index` on EACH, `as` on TABLE),
 *   since that is the one place a `foreach` header keeps its names together — every
 *   other factory takes exactly one argument;
 * - every other field is a member method named after its HTML counterpart (`type`,
 *   `href`, `target`, `method`, `value`, `placeholder`, `checked`, `rows`, `required`,
 *   `class`, `id`, `style`);
 * - fields with no HTML counterpart keep a name that matches the component (`body`,
 *   `as`, `index`, `columns`, `empty`, `pop`, `content`, `data`);
 * - the two control-flow branches are spelled like the nodes they hang off (`THEN`,
 *   `ELSE`) instead of like attributes, because they name statements, not attributes;
 * - `pop` is the server-side half (PHP renders data into the page) while `bind` is the
 *   browser-side half (the framework's binding attribute); `popAndBind` is the two
 *   combined for the common case where both sides use the same name.
 *
 * Setting the same field twice throws instead of overwriting: the compiler never drops
 * a written value silently, and neither does the layer in front of it.
 */
abstract class Node
{
    /** @var array<string, mixed> */
    private array $node;

    /**
     * @param array<string, mixed> $node
     */
    protected function __construct(array $node)
    {
        $this->node = $node;
    }

    /**
     * The array form of this node, as the compiler expects it: node trees nested inside
     * a field (`then`, `body`, `fields`, `columns`, `content`) are expanded too.
     *
     * @return array<string, mixed>
     */
    final public function toArray(): array
    {
        return self::expand($this->node);
    }

    /**
     * @param array<string, mixed> $value
     *
     * @return array<string, mixed>
     */
    private static function expand(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = match (true) {
                $item instanceof Node => $item->toArray(),
                is_array($item) => self::expand($item),
                default => $item,
            };
        }

        return $out;
    }

    final public function text(string $text): static
    {
        return $this->fill('text', $text);
    }

    final public function target(string $target): static
    {
        return $this->fill('target', $target);
    }

    /** Uppercase because it names the statement, not an attribute. */
    final public function THEN(array $nodes): static
    {
        return $this->fill('then', $nodes);
    }

    /** Uppercase because it names the statement, not an attribute. */
    final public function ELSE(array $nodes): static
    {
        return $this->fill('else', $nodes);
    }

    final public function body(array $nodes): static
    {
        return $this->fill('body', $nodes);
    }

    final public function as(string $variable): static
    {
        return $this->fill('as', $variable);
    }

    final public function index(string $variable): static
    {
        return $this->fill('index', $variable);
    }

    final public function fields(array $fields): static
    {
        return $this->fill('fields', $fields);
    }

    final public function method(string $method): static
    {
        return $this->fill('method', $method);
    }

    final public function columns(array $columns): static
    {
        return $this->fill('columns', $columns);
    }

    final public function empty(string $text): static
    {
        return $this->fill('empty', $text);
    }

    final public function data(array $data): static
    {
        return $this->fill('data', $data);
    }

    final public function label(string $label): static
    {
        return $this->fill('label', $label);
    }

    final public function value(string $path): static
    {
        return $this->fill('value', $path);
    }

    final public function required(bool $required = true): static
    {
        return $this->fill('required', $required);
    }

    final public function placeholder(string $placeholder): static
    {
        return $this->fill('placeholder', $placeholder);
    }

    final public function options(array $options): static
    {
        return $this->fill('options', $options);
    }

    final public function checked(string $path): static
    {
        return $this->fill('checked', $path);
    }

    final public function rows(int $rows = 4): static
    {
        return $this->fill('rows', $rows);
    }

    /**
     * Server-side half: PHP renders the data this column (or field) points at.
     * Write the reference as {{ row.name }} — the braces are what make data visible
     * in the source, and for a column the leading variable is checked against the
     * table's own row variable.
     */
    final public function pop(string $reference): static
    {
        return $this->fill('pop', $reference);
    }

    final public function content(array $nodes): static
    {
        return $this->fill('content', $nodes);
    }

    final protected function fill(string $field, mixed $value): static
    {
        if (array_key_exists($field, $this->node)) {
            throw new \LogicException('字段重复设置: ' . $field);
        }

        $this->node[$field] = $value;

        return $this;
    }

    final protected function current(string $field): mixed
    {
        return $this->node[$field] ?? null;
    }

    /**
     * Overwrite a value seeded by a constructor. Only for the one case where a factory
     * sets a default the author is allowed to change (`input`).
     */
    final protected function reseed(string $field, mixed $value): static
    {
        $this->node[$field] = $value;

        return $this;
    }
}

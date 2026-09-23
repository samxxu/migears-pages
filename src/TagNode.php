<?php

declare(strict_types=1);

namespace MiGears\Pages;

/**
 * A node that emits a tag, and therefore accepts forwarded attributes.
 *
 * The methods are the HTML attributes that make sense on any element; anything else
 * goes through attr() and is still checked against the compiler's whitelist, so a typo
 * keeps failing at compile time with the node path attached.
 *
 * Form controls extend this (FieldNode): they emit a tag too, and add the value half.
 */
class TagNode extends Node
{
    /**
     * @param array<string, mixed> $node
     *
     * @internal built by the Html factory
     */
    public function __construct(array $node)
    {
        parent::__construct($node);
    }

    public function class(string $class): static
    {
        return $this->attr('class', $class);
    }

    public function id(string $id): static
    {
        return $this->attr('id', $id);
    }

    public function style(string $style): static
    {
        return $this->attr('style', $style);
    }

    /**
     * Event shorthand: on('click', '...') is emitted as @click="...". Use attr() when
     * you need another spelling (x-on:click, v-on:click).
     */
    public function on(string $event, string $expression): static
    {
        return $this->attr('@' . $event, $expression);
    }

    /**
     * Browser-side half: the framework's binding attribute, naming the JavaScript
     * variable it binds to. The value is a JS name/path (user.email), never a {{ }}
     * interpolation — that is the server-side half (pop).
     */
    public function bind(string $name): static
    {
        return $this->fill('bind', $name);
    }

    public function attr(string $name, string|int|float|bool|null $value): static
    {
        return $this->fill($name, $value);
    }
}

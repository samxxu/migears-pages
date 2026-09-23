<?php

declare(strict_types=1);

namespace MiGears\Pages;

/**
 * A node that emits no tag of its own: text, if, each, component, and the two
 * structural types (field, column).
 *
 * No attribute methods here on purpose — `h5::text('x')->class('a')` is an undefined
 * method in PHP, not a compile error discovered later.
 */
final class PlainNode extends Node
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
}

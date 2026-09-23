<?php

declare(strict_types=1);

namespace MiGears\Pages;

/**
 * An <input> field. `type` is an attribute of <input> alone, so type() lives here and
 * not on the nodes built by h5::textarea() / h5::select() — those have no such method.
 *
 * The type defaults to text and may be set once.
 */
final class InputNode extends FieldNode
{
    private bool $controlSet = false;

    /**
     * @internal built by the Html factory
     */
    public function __construct(string $name)
    {
        parent::__construct(['type' => 'field', 'name' => $name, 'input' => 'text']);
    }

    public function type(string $control): static
    {
        if ($this->controlSet) {
            throw new \LogicException('控件重复设置: 已经是 ' . $this->current('input') . '，不能再设为 ' . $control);
        }

        $this->controlSet = true;

        return $this->reseed('input', $control);
    }
}

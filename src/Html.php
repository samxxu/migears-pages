<?php

declare(strict_types=1);

namespace MiGears\Pages;

use MiGears\Pages\Node;

/**
 * The user-level page syntax: one factory per node, named after the HTML it emits.
 *
 * ```php
 * use MiGears\Pages\Html as h5;
 *
 * $page = ['body' => [
 *     h5::heading(2)->text('用户列表'),
 *     h5::table('users')->columns([
 *         h5::col('姓名')->pop('{{ row.name }}'),
 *     ])->empty('暂无数据'),
 * ]];
 * ```
 *
 * What the factory returns is sugar: Compiler::compile() normalizes the nodes into the
 * same array model the XML and YAML frontends produce, so the syntax you write here and
 * the syntax those frontends parse compile to identical output through identical checks.
 * Nothing about the node vocabulary is duplicated at this layer — the factory only names
 * the fields, and the compiler remains the single place that validates them.
 *
 * `input`, `textarea`, `select`, `table`, `col`, `form` and `el` are tag names; `heading`
 * covers <h1>–<h6> and `link` is <a>. The four nodes that emit no tag keep the semantics
 * of the model: `text`, `if`, `each`, `component`.
 */
final class Html
{
    public static function text(string $text): PlainNode
    {
        return new PlainNode(['type' => 'text', 'text' => $text]);
    }

    /**
     * @param int $level 1–6; the compiler rejects anything outside that range
     */
    public static function heading(int $level = 1): TagNode
    {
        return new TagNode(['type' => 'heading', 'level' => $level]);
    }

    public static function link(string $href): TagNode
    {
        return new TagNode(['type' => 'link', 'href' => $href]);
    }

    public static function if(string $when): PlainNode
    {
        return new PlainNode(['type' => 'if', 'when' => $when]);
    }

    public static function each(string $items): PlainNode
    {
        return new PlainNode(['type' => 'each', 'items' => $items]);
    }

    public static function form(string $action): TagNode
    {
        return new TagNode(['type' => 'form', 'action' => $action]);
    }

    /**
     * A single-line <input>; text unless type() says otherwise.
     */
    public static function input(string $name): InputNode
    {
        return new InputNode($name);
    }

    public static function textarea(string $name): FieldNode
    {
        return new FieldNode(['type' => 'field', 'name' => $name, 'input' => 'textarea']);
    }

    public static function select(string $name): FieldNode
    {
        return new FieldNode(['type' => 'field', 'name' => $name, 'input' => 'select']);
    }

    public static function table(string $items): TagNode
    {
        return new TagNode(['type' => 'table', 'items' => $items]);
    }

    public static function col(string $label): PlainNode
    {
        return new PlainNode(['type' => 'column', 'label' => $label]);
    }

    public static function component(string $name): PlainNode
    {
        return new PlainNode(['type' => 'component', 'name' => $name]);
    }

    public static function el(string $tag): TagNode
    {
        return new TagNode(['type' => 'el', 'tag' => $tag]);
    }
}

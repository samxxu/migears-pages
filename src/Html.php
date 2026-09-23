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
 *     h5::HEADING(2)->text('用户列表'),
 *     h5::TABLE('users')->columns([
 *         h5::COL('姓名')->pop('{{ row.name }}'),
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
 * Factory names are all-caps and streaming methods are lowercase, so a node never looks
 * like a field: `h5::INPUT('email')->label('邮箱')` reads as a tag with attributes, the
 * same split HTML itself uses. The two control-flow branches follow the node spelling
 * (`->THEN()`, `->ELSE()`), because they name statements rather than attributes. Note
 * that PHP method names are case-insensitive, so the lowercase spelling keeps working;
 * `tests/FactoryNamingTest.php` is what actually holds the convention in place.
 *
 * `INPUT`, `TEXTAREA`, `SELECT`, `TABLE`, `COL`, `FORM` and `EL` are tag names; `HEADING`
 * covers <h1>–<h6> and `LINK` is <a>. The four nodes that emit no tag keep the semantics
 * of the model: `TEXT`, `IF`, `EACH`, `COMPONENT`.
 */
final class Html
{
    public static function TEXT(string $text): PlainNode
    {
        return new PlainNode(['type' => 'text', 'text' => $text]);
    }

    /**
     * @param int $level 1–6; the compiler rejects anything outside that range
     */
    public static function HEADING(int $level = 1): TagNode
    {
        return new TagNode(['type' => 'heading', 'level' => $level]);
    }

    public static function LINK(string $href): TagNode
    {
        return new TagNode(['type' => 'link', 'href' => $href]);
    }

    public static function IF(string $when): PlainNode
    {
        return new PlainNode(['type' => 'if', 'when' => $when]);
    }

    public static function EACH(string $items): PlainNode
    {
        return new PlainNode(['type' => 'each', 'items' => $items]);
    }

    public static function FORM(string $action): TagNode
    {
        return new TagNode(['type' => 'form', 'action' => $action]);
    }

    /**
     * A single-line <input>; text unless type() says otherwise.
     */
    public static function INPUT(string $name): InputNode
    {
        return new InputNode($name);
    }

    public static function TEXTAREA(string $name): FieldNode
    {
        return new FieldNode(['type' => 'field', 'name' => $name, 'input' => 'textarea']);
    }

    public static function SELECT(string $name): FieldNode
    {
        return new FieldNode(['type' => 'field', 'name' => $name, 'input' => 'select']);
    }

    public static function TABLE(string $items): TagNode
    {
        return new TagNode(['type' => 'table', 'items' => $items]);
    }

    public static function COL(string $label): PlainNode
    {
        return new PlainNode(['type' => 'column', 'label' => $label]);
    }

    public static function COMPONENT(string $name): PlainNode
    {
        return new PlainNode(['type' => 'component', 'name' => $name]);
    }

    public static function EL(string $tag): TagNode
    {
        return new TagNode(['type' => 'el', 'tag' => $tag]);
    }
}

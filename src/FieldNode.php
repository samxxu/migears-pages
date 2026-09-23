<?php

declare(strict_types=1);

namespace MiGears\Pages;

/**
 * A form control: it emits a tag (so it carries attributes) and it has a value the
 * server can fill.
 *
 * That combination is what `popAndBind()` needs — a place to render data into plus a
 * framework binding to point at the same name.
 */
class FieldNode extends TagNode
{
    /**
     * The shortcut: render the value on the server and bind it on the client, both
     * naming the same data — the usual case when the back-end field and the front-end
     * variable share a name.
     *
     * ```php
     * h5::input('email')->popAndBind('{{ user.email }}');
     * // value="## $user['email'] ?? '' ##" bind="user.email"
     * ```
     *
     * When the two sides diverge, write them apart: `->value('{{ user.email }}')`
     * plus `->bind('form.email')`. The attribute name defaults to this framework's
     * `bind`; pass a second argument for another spelling (`x-model`, `v-model`).
     */
    final public function popAndBind(string $reference, string $attribute = 'bind'): static
    {
        $this->fill('value', $reference);

        return $this->fill($attribute, $this->bindName($reference));
    }

    /** The browser-side name is the reference without its {{ }} marker. */
    private function bindName(string $reference): string
    {
        return preg_match('/^\{\{\s*(.+?)\s*\}\}$/', trim($reference), $m) ? trim($m[1]) : trim($reference);
    }
}

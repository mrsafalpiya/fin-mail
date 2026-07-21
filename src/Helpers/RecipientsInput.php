<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

use Filament\Forms\Components\TagsInput;

/**
 * Builds the recipient TagsInput fields (to / cc / bcc) used by the compose
 * flows so that address parsing stays consistent across every surface.
 *
 * Two ways to split input into individual address tags:
 *
 *  - Typing a comma commits the current entry as a tag (Filament's built-in
 *    {@see TagsInput::splitKeys()} keydown behaviour).
 *  - Pasting a block of addresses separated by commas, semicolons, tabs, or
 *    newlines breaks it into one tag per address.
 *
 * The paste splitting is handled by our own listener rather than `splitKeys`
 * because the field renders as a single-line `<input>`: browsers strip line
 * breaks out of pasted text before it reaches the model, so Filament's
 * paste handler never sees the newlines. Reading the raw clipboard via
 * `event.clipboardData` preserves them, letting us split a column of addresses
 * copied from a spreadsheet — or any comma/newline-separated list — into
 * separate, individually validated entries.
 */
final class RecipientsInput
{
    /**
     * Delimiters that break a pasted block into individual address tags:
     * newlines, carriage returns, commas, semicolons, and tabs. Spaces are
     * intentionally excluded — addresses never contain them and splitting on
     * them would only mangle rare "Name <addr>" pastes.
     */
    private const PASTE_SPLIT_PATTERN = '/[\\r\\n,;\\t]+/';

    public static function make(string $name): TagsInput
    {
        return TagsInput::make($name)
            ->splitKeys([','])
            ->extraInputAttributes(['x-on:paste' => self::pasteHandler()], merge: true)
            ->nestedRecursiveRules(['email']);
    }

    /**
     * Alpine handler, evaluated in the tagsInputFormComponent scope, that reads
     * the raw clipboard, splits it, and appends each unique address to `state`.
     * When the paste holds a single entry it defers to Filament's own handler.
     */
    private static function pasteHandler(): string
    {
        return implode(' ', [
            "const parts = (\$event.clipboardData?.getData('text') ?? '')",
            '    .split('.self::PASTE_SPLIT_PATTERN.')',
            '    .map((entry) => entry.trim())',
            '    .filter((entry) => entry.length);',
            'if (parts.length <= 1) return;',
            '$event.preventDefault();',
            'parts.forEach((email) => { if (! state.includes(email)) state.push(email); });',
            "newTag = '';",
        ]);
    }
}

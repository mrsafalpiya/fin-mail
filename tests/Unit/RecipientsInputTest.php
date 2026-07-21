<?php

declare(strict_types=1);

use FinityLabs\FinMail\Helpers\RecipientsInput;

it('commits a tag when a comma is typed', function () {
    expect(RecipientsInput::make('to')->getSplitKeys())->toContain(',');
});

it('installs a paste handler that splits the raw clipboard on commas and newlines', function () {
    $paste = RecipientsInput::make('to')->getExtraInputAttributes()['x-on:paste'] ?? '';

    // Reads the raw clipboard (newlines survive there but not in the sanitised
    // single-line input value) and splits on newlines, commas, semicolons, tabs.
    expect($paste)
        ->toContain('clipboardData')
        ->toContain('\\r\\n,;\\t');
});

it('keeps the recipient field name it was given', function () {
    expect(RecipientsInput::make('bcc')->getName())->toBe('bcc');
});

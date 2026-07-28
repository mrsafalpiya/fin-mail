<?php

declare(strict_types=1);

use FinityLabs\FinMail\Helpers\RecipientCsvParser;
use FinityLabs\FinMail\Helpers\RecipientCsvResult;
use FinityLabs\FinMail\Helpers\TokenRowModels;

$tokens = ['user.name', 'invoice.total'];

it('parses one recipient per row with its token values', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name,invoice.total\nalice@example.com,Alice,\$120.00\nbob@example.com,Bob,\$40.00\n",
        $tokens,
    );

    expect($result->hasError())->toBeFalse()
        ->and($result->rows)->toBe([
            ['email' => 'alice@example.com', 'tokens' => ['user.name' => 'Alice', 'invoice.total' => '$120.00']],
            ['email' => 'bob@example.com', 'tokens' => ['user.name' => 'Bob', 'invoice.total' => '$40.00']],
        ])
        ->and($result->emails())->toBe(['alice@example.com', 'bob@example.com'])
        ->and($result->mappedTokens)->toBe($tokens);
});

it('finds the email column wherever it sits in the header', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "user.name,EMAIL,invoice.total\nAlice,alice@example.com,\$120.00\n",
        $tokens,
    );

    expect($result->rows)->toBe([
        ['email' => 'alice@example.com', 'tokens' => ['user.name' => 'Alice', 'invoice.total' => '$120.00']],
    ]);
});

it('rejects a file with no email column', function () use ($tokens) {
    $result = RecipientCsvParser::parse("address,user.name\nalice@example.com,Alice\n", $tokens);

    expect($result->hasError())->toBeTrue()
        ->and($result->error['key'])->toBe('error_missing_email_column')
        ->and($result->rows)->toBe([]);
});

it('rejects an empty file', function () use ($tokens) {
    expect(RecipientCsvParser::parse("  \n", $tokens)->error['key'])->toBe('error_empty');
});

it('reports columns that match no declared token instead of failing', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name,notes\nalice@example.com,Alice,called twice\n",
        $tokens,
    );

    expect($result->ignoredColumns)->toBe(['notes'])
        ->and($result->mappedTokens)->toBe(['user.name'])
        ->and($result->rows[0]['tokens'])->toBe(['user.name' => 'Alice']);
});

it('accepts headers pasted with their token braces', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,{{ user.name }}\nalice@example.com,Alice\n",
        $tokens,
    );

    expect($result->ignoredColumns)->toBe([])
        ->and($result->rows[0]['tokens'])->toBe(['user.name' => 'Alice']);
});

it('skips rows with a blank or invalid address and reports their line numbers', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name\nalice@example.com,Alice\nnot-an-email,Broken\n,Nameless\nbob@example.com,Bob\n",
        $tokens,
    );

    expect($result->emails())->toBe(['alice@example.com', 'bob@example.com'])
        ->and($result->invalidRows)->toBe([3, 4]);
});

it('keeps the first occurrence of a repeated address and reports the rest', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name\nalice@example.com,Alice\nbob@example.com,Bob\nALICE@example.com,Alicia\n",
        $tokens,
    );

    expect($result->emails())->toBe(['alice@example.com', 'bob@example.com'])
        ->and($result->rows[0]['tokens'])->toBe(['user.name' => 'Alice'])
        ->and($result->duplicateRows)->toBe([['row' => 4, 'original' => 2]]);
});

it('omits blank cells from the token map and counts them', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name,invoice.total\nalice@example.com,Alice,\nbob@example.com,,\n",
        $tokens,
    );

    expect($result->rows[0]['tokens'])->toBe(['user.name' => 'Alice'])
        ->and($result->rows[1]['tokens'])->toBe([])
        ->and($result->missingCounts)->toBe(['user.name' => 1, 'invoice.total' => 2]);
});

it('detects semicolon and tab delimited files', function (string $delimiter) use ($tokens) {
    $result = RecipientCsvParser::parse(
        implode($delimiter, ['email', 'user.name'])."\n".implode($delimiter, ['alice@example.com', 'Alice'])."\n",
        $tokens,
    );

    expect($result->rows)->toBe([
        ['email' => 'alice@example.com', 'tokens' => ['user.name' => 'Alice']],
    ]);
})->with([';', "\t"]);

it('handles a UTF-8 BOM, CRLF line endings, and quoted values containing the delimiter', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "\xEF\xBB\xBFemail,user.name\r\nalice@example.com,\"Alice, of Example Ltd\"\r\n",
        $tokens,
    );

    expect($result->rows)->toBe([
        ['email' => 'alice@example.com', 'tokens' => ['user.name' => 'Alice, of Example Ltd']],
    ]);
});

it('ignores entirely blank lines', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name\nalice@example.com,Alice\n\n\nbob@example.com,Bob\n",
        $tokens,
    );

    expect($result->emails())->toBe(['alice@example.com', 'bob@example.com'])
        ->and($result->invalidRows)->toBe([]);
});

it('rejects a file that exceeds the row cap', function () use ($tokens) {
    $contents = "email,user.name\n";

    foreach (range(1, 4) as $index) {
        $contents .= "user{$index}@example.com,User {$index}\n";
    }

    $result = RecipientCsvParser::parse($contents, $tokens, maxRows: 3);

    expect($result->hasError())->toBeTrue()
        ->and($result->error)->toBe(['key' => 'error_too_many_rows', 'params' => ['max' => 3]]);
});

it('accepts a file sitting exactly on the row cap', function () use ($tokens) {
    $result = RecipientCsvParser::parse(
        "email,user.name\na@example.com,A\nb@example.com,B\n",
        $tokens,
        maxRows: 2,
    );

    expect($result->hasError())->toBeFalse()
        ->and($result->count())->toBe(2);
});

it('survives round-tripping through form state', function () use ($tokens) {
    $result = RecipientCsvParser::parse("email,user.name\nalice@example.com,Alice\n", $tokens);

    expect(RecipientCsvResult::fromArray($result->toArray()))->toEqual($result);
});

describe('token models', function () {
    it('un-dots tokens into the nested shape the replacer resolves', function () {
        expect(TokenRowModels::build(['user.name' => 'Alice', 'order.customer.city' => 'Oslo', 'code' => 'X1']))
            ->toBe([
                'user' => ['name' => 'Alice'],
                'order' => ['customer' => ['city' => 'Oslo']],
                'code' => 'X1',
            ]);
    });

    it('escapes values when they are bound for the HTML body', function () {
        expect(TokenRowModels::build(['user.name' => 'Smith & Sons <b>'], escape: true))
            ->toBe(['user' => ['name' => 'Smith &amp; Sons &lt;b&gt;']]);
    });
});

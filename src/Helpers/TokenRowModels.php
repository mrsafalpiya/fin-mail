<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

use Illuminate\Support\Arr;

/**
 * Turns one CSV row's flat token values into the nested `models` array that
 * {@see TokenReplacer} resolves against.
 *
 * A dotted token is un-dotted so it resolves exactly as a model attribute
 * would — `user.name` becomes `['user' => ['name' => …]]`, and
 * `order.customer.name` nests three deep. A token with no dot stays top level,
 * which is the form `{{ url }}`-style tokens already use.
 *
 * Values destined for the HTML body are escaped: a CSV is often an export from
 * somewhere else, and a value like `Smith & Sons` would otherwise emit invalid
 * markup while `<b>` would inject it. The subject and preheader take the raw
 * values — the subject is a plain-text mail header, and Blade escapes the
 * preheader when it renders.
 */
final class TokenRowModels
{
    /**
     * @param  array<string, string>  $tokenValues  Token name => value, as parsed from one CSV row.
     * @param  bool  $escape  Escape values for insertion into HTML.
     *
     * @return array<string, mixed>
     */
    public static function build(array $tokenValues, bool $escape = false): array
    {
        if ($escape) {
            $tokenValues = array_map(
                static fn (string $value): string => e($value),
                $tokenValues,
            );
        }

        return Arr::undot($tokenValues);
    }
}

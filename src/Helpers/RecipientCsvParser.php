<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

/**
 * Parses an uploaded recipient CSV into one row per recipient, carrying that
 * recipient's token values.
 *
 * Contract:
 *  - A header row is mandatory, and one of its columns must be named `email`
 *    (case-insensitive). Its absence rejects the file.
 *  - Every other header is normalised (trimmed, `{{ }}` stripped if the token
 *    was pasted with its braces) and matched against the template's declared
 *    tokens. Headers that match nothing are ignored and reported, so a
 *    spreadsheet export carrying extra columns still imports.
 *  - Rows with a blank or invalid address are skipped; a repeated address keeps
 *    its first occurrence. Both are reported by line number.
 *  - Blank cells are omitted from the row's token map rather than stored as an
 *    empty string, so the body's `{{ token | 'fallback' }}` still gets its
 *    chance before the value is blanked out at render time.
 */
final class RecipientCsvParser
{
    /** Delimiters tried against the header row; the one yielding the most columns wins. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * @param  string  $contents  Raw file contents.
     * @param  list<string>  $tokens  Tokens the template declares (already filtered of `config.*`).
     * @param  int|null  $maxRows  Row cap; defaults to the configured limit.
     */
    public static function parse(string $contents, array $tokens, ?int $maxRows = null): RecipientCsvResult
    {
        $maxRows ??= (int) config('fin-mail.csv.max_rows', 500);

        $contents = self::stripBom($contents);

        if (trim($contents) === '') {
            return RecipientCsvResult::failed('error_empty');
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return RecipientCsvResult::failed('error_unreadable');
        }

        try {
            fwrite($handle, $contents);
            rewind($handle);

            $delimiter = self::detectDelimiter($contents);

            $header = self::readRow($handle, $delimiter);

            if ($header === null) {
                return RecipientCsvResult::failed('error_empty');
            }

            $headers = array_map(self::normaliseHeader(...), $header);

            $emailIndex = self::findEmailColumn($headers);

            if ($emailIndex === null) {
                return RecipientCsvResult::failed('error_missing_email_column');
            }

            [$tokenColumns, $ignoredColumns] = self::mapTokenColumns($headers, $tokens, $emailIndex);

            return self::readRows($handle, $delimiter, $emailIndex, $tokenColumns, $ignoredColumns, $maxRows);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Read the data rows, skipping the ones that cannot become a recipient.
     *
     * @param  resource  $handle
     * @param  array<int, string>  $tokenColumns  Column index => token name.
     * @param  list<string>  $ignoredColumns
     */
    private static function readRows(
        $handle,
        string $delimiter,
        int $emailIndex,
        array $tokenColumns,
        array $ignoredColumns,
        int $maxRows,
    ): RecipientCsvResult {
        $rows = [];
        $invalidRows = [];
        $duplicateRows = [];
        $missingCounts = array_fill_keys(array_values($tokenColumns), 0);

        /** @var array<string, int> $seen Lower-cased address => the line that claimed it */
        $seen = [];

        // Line 1 is the header, so the first data row is line 2.
        $line = 1;

        while (($row = self::readRow($handle, $delimiter)) !== null) {
            $line++;

            if (self::isBlankRow($row)) {
                continue;
            }

            if (count($rows) >= $maxRows) {
                return RecipientCsvResult::failed('error_too_many_rows', ['max' => $maxRows]);
            }

            $email = trim((string) ($row[$emailIndex] ?? ''));

            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidRows[] = $line;

                continue;
            }

            $key = mb_strtolower($email);

            if (isset($seen[$key])) {
                $duplicateRows[] = ['row' => $line, 'original' => $seen[$key]];

                continue;
            }

            $seen[$key] = $line;

            $tokenValues = [];

            foreach ($tokenColumns as $index => $token) {
                $value = trim((string) ($row[$index] ?? ''));

                if ($value === '') {
                    $missingCounts[$token]++;

                    continue;
                }

                $tokenValues[$token] = $value;
            }

            $rows[] = ['email' => $email, 'tokens' => $tokenValues];
        }

        return new RecipientCsvResult(
            rows: $rows,
            mappedTokens: array_values($tokenColumns),
            ignoredColumns: $ignoredColumns,
            invalidRows: $invalidRows,
            duplicateRows: $duplicateRows,
            missingCounts: array_filter($missingCounts),
        );
    }

    /**
     * Match every non-email header against the declared tokens.
     *
     * @param  list<string>  $headers
     * @param  list<string>  $tokens
     *
     * @return array{0: array<int, string>, 1: list<string>} Column index => token, and the unmatched headers.
     */
    private static function mapTokenColumns(array $headers, array $tokens, int $emailIndex): array
    {
        $tokenColumns = [];
        $ignoredColumns = [];

        foreach ($headers as $index => $header) {
            if ($index === $emailIndex) {
                continue;
            }

            if ($header === '') {
                continue;
            }

            // A token already claimed by an earlier column wins; a second column
            // for the same token is treated as unused rather than overwriting it.
            if (in_array($header, $tokens, true) && ! in_array($header, $tokenColumns, true)) {
                $tokenColumns[$index] = $header;

                continue;
            }

            $ignoredColumns[] = $header;
        }

        return [$tokenColumns, array_values(array_unique($ignoredColumns))];
    }

    /**
     * @param  list<string>  $headers
     */
    private static function findEmailColumn(array $headers): ?int
    {
        foreach ($headers as $index => $header) {
            if (mb_strtolower($header) === 'email') {
                return $index;
            }
        }

        return null;
    }

    /**
     * Trim a header and unwrap it if the token was pasted with its braces.
     */
    private static function normaliseHeader(?string $header): string
    {
        $header = trim(self::stripBom((string) $header));

        $open = (string) config('fin-mail.tokens.open', '{{');
        $close = (string) config('fin-mail.tokens.close', '}}');

        if (str_starts_with($header, $open) && str_ends_with($header, $close)) {
            $header = trim(mb_substr(
                $header,
                mb_strlen($open),
                mb_strlen($header) - mb_strlen($open) - mb_strlen($close),
            ));
        }

        return $header;
    }

    /**
     * Pick the delimiter that splits the header row into the most columns.
     * Ties fall back to the earliest candidate, i.e. a comma.
     */
    private static function detectDelimiter(string $contents): string
    {
        $headerLine = strtok($contents, "\r\n");

        if ($headerLine === false) {
            return ',';
        }

        $best = ',';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            $count = count(str_getcsv($headerLine, $delimiter, '"', ''));

            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * @param  resource  $handle
     *
     * @return list<string|null>|null Null at end of file.
     */
    private static function readRow($handle, string $delimiter): ?array
    {
        // An explicit empty escape keeps parsing RFC 4180-compliant (and avoids
        // the deprecated default escape character).
        $row = fgetcsv($handle, 0, $delimiter, '"', '');

        return $row === false ? null : $row;
    }

    /**
     * @param  list<string|null>  $row
     */
    private static function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function stripBom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}

<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

/**
 * The outcome of parsing a recipient CSV.
 *
 * Everything here is structured rather than pre-translated: the parser reports
 * *what* happened (which lines were skipped, which columns went unused) and the
 * compose form turns that into the wording the user reads. Keeps the parser
 * testable without asserting on translation strings.
 *
 * A hard failure (no header, no email column, too many rows) sets {@see $error}
 * and leaves every other property empty — nothing is imported.
 */
final class RecipientCsvResult
{
    /**
     * @param  list<array{email: string, tokens: array<string, string>}>  $rows  Accepted recipients, in file order.
     * @param  list<string>  $mappedTokens  Declared tokens that a column supplied.
     * @param  list<string>  $ignoredColumns  Headers that matched no declared token.
     * @param  list<int>  $invalidRows  1-based file line numbers skipped for a blank/invalid address.
     * @param  list<array{row: int, original: int}>  $duplicateRows  Repeat addresses, with the line that won.
     * @param  array<string, int>  $missingCounts  Token => number of accepted rows with no value for it.
     * @param  array{key: string, params: array<string, mixed>}|null  $error  Hard failure, as a lang key + params.
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly array $mappedTokens = [],
        public readonly array $ignoredColumns = [],
        public readonly array $invalidRows = [],
        public readonly array $duplicateRows = [],
        public readonly array $missingCounts = [],
        public readonly ?array $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function failed(string $key, array $params = []): self
    {
        return new self(error: ['key' => $key, 'params' => $params]);
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /**
     * The recipient addresses, in file order.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        return array_map(static fn (array $row): string => $row['email'], $this->rows);
    }

    public function hasWarnings(): bool
    {
        return $this->ignoredColumns !== []
            || $this->invalidRows !== []
            || $this->duplicateRows !== []
            || array_sum($this->missingCounts) > 0;
    }

    /**
     * Flatten to plain arrays for Livewire form state.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'mapped_tokens' => $this->mappedTokens,
            'ignored_columns' => $this->ignoredColumns,
            'invalid_rows' => $this->invalidRows,
            'duplicate_rows' => $this->duplicateRows,
            'missing_counts' => $this->missingCounts,
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $state
     */
    public static function fromArray(?array $state): self
    {
        if (! $state) {
            return new self;
        }

        return new self(
            rows: $state['rows'] ?? [],
            mappedTokens: $state['mapped_tokens'] ?? [],
            ignoredColumns: $state['ignored_columns'] ?? [],
            invalidRows: $state['invalid_rows'] ?? [],
            duplicateRows: $state['duplicate_rows'] ?? [],
            missingCounts: $state['missing_counts'] ?? [],
            error: $state['error'] ?? null,
        );
    }
}

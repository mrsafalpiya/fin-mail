<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Helpers;

/**
 * Splits a "To" list into the groups that each become one email.
 *
 * Individual mode with more than one recipient yields one group per recipient;
 * every other case delivers a single email addressed to all. Shared by the
 * compose page (immediate send) and the scheduled-send command so both surfaces
 * expand a batch identically.
 */
final class RecipientGrouper
{
    /**
     * Expand compose data into the payload overrides for each email to send.
     *
     * A CSV batch always yields one email per row, carrying that row's token
     * values; without a CSV this falls back to the send-mode grouping. Both the
     * compose page and the scheduled-send command go through here so a batch
     * expands identically whether it goes out now or in three days.
     *
     * @param  array<string, mixed>  $data  Compose payload.
     * @param  'individual'|'combined'|null  $sendMode  Ignored for a CSV batch.
     *
     * @return list<array{to: list<string>, token_values?: array<string, string>}>
     */
    public static function sendGroups(array $data, ?string $sendMode): array
    {
        if (! empty($data['csv_rows'])) {
            return array_map(
                static fn (array $row): array => [
                    'to' => [$row['email']],
                    'token_values' => $row['tokens'] ?? [],
                ],
                array_values($data['csv_rows']),
            );
        }

        $recipients = array_values(array_filter($data['to'] ?? []));

        return array_map(
            static fn (array $group): array => ['to' => $group],
            self::groups($recipients, $sendMode),
        );
    }

    /**
     * @param  list<string>  $recipients
     * @param  'individual'|'combined'|null  $sendMode
     *
     * @return list<list<string>>
     */
    public static function groups(array $recipients, ?string $sendMode): array
    {
        if ($sendMode === 'individual' && count($recipients) > 1) {
            return array_map(static fn (string $recipient): array => [$recipient], $recipients);
        }

        return [$recipients];
    }
}

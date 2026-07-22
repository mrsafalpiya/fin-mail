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

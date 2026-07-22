<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Enums;

use BackedEnum;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

/**
 * Lifecycle of a scheduled email: it starts Pending, then becomes Sent once the
 * cron dispatches it, Cancelled if an admin stops it first, or Failed if the
 * send could not be handed off.
 */
enum ScheduledEmailStatus: int implements HasColor, HasIcon, HasLabel
{
    case Pending = 1;
    case Sent = 2;
    case Cancelled = 3;
    case Failed = 4;

    public function getLabel(): string
    {
        return (string) __('fin-mail::fin-mail.enums.scheduled_email_status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Sent => 'success',
            self::Cancelled => 'gray',
            self::Failed => 'danger',
        };
    }

    public function getIcon(): BackedEnum
    {
        return match ($this) {
            self::Pending => Heroicon::OutlinedClock,
            self::Sent => Heroicon::OutlinedCheckCircle,
            self::Cancelled => Heroicon::OutlinedNoSymbol,
            self::Failed => Heroicon::OutlinedXCircle,
        };
    }
}

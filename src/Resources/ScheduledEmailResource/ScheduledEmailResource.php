<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\ScheduledEmailResource;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use FinityLabs\FinMail\FinMailPlugin;
use FinityLabs\FinMail\Models\ScheduledEmail;
use FinityLabs\FinMail\Resources\ScheduledEmailResource\Tables\ScheduledEmailsTable;
use UnitEnum;

class ScheduledEmailResource extends Resource
{
    protected static ?string $model = ScheduledEmail::class;

    protected static ?string $slug = 'scheduled-emails';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function getNavigationSort(): ?int
    {
        /** @var FinMailPlugin $plugin */
        $plugin = filament('fin-mail');

        return $plugin->getScheduledEmailNavigationSort();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        /** @var FinMailPlugin $plugin */
        $plugin = filament('fin-mail');

        return $plugin->getScheduledEmailNavigationGroup();
    }

    public static function getModelLabel(): string
    {
        return __('fin-mail::fin-mail.models.scheduled_email');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fin-mail::fin-mail.models.scheduled_emails');
    }

    public static function getNavigationLabel(): string
    {
        return __('fin-mail::fin-mail.navigation.scheduled-emails');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ScheduledEmailsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScheduledEmails::route('/'),
        ];
    }
}

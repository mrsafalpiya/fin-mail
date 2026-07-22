<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\ScheduledEmailResource\Tables;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use FinityLabs\FinMail\Models\ScheduledEmail;

class ScheduledEmailsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('subject')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.subject'))
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                TextColumn::make('recipients_display')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.to'))
                    ->limit(40)
                    ->searchable(
                        query: fn ($query, string $search) => $query->whereJsonContains('to', $search)
                    ),

                TextColumn::make('template.name')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.template'))
                    ->badge()
                    ->color('gray')
                    ->placeholder(__('fin-mail::fin-mail.scheduled.columns.template_placeholder')),

                TextColumn::make('status')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.status'))
                    ->badge(),

                TextColumn::make('scheduled_at')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.scheduled_at'))
                    ->dateTime(app('fin-mail')->dateTimeFormat())
                    ->sortable(),

                TextColumn::make('sender.name')
                    ->label(__('fin-mail::fin-mail.scheduled.columns.scheduled_by'))
                    ->placeholder(__('fin-mail::fin-mail.scheduled.columns.scheduled_by_placeholder')),
            ])
            ->defaultSort('scheduled_at', 'asc')
            ->deferFilters()
            ->recordAction(null)
            ->filters([
                SelectFilter::make('status')
                    ->options(ScheduledEmailStatus::class),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label(__('fin-mail::fin-mail.scheduled.actions.cancel'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('fin-mail::fin-mail.scheduled.actions.cancel_heading'))
                    ->modalDescription(__('fin-mail::fin-mail.scheduled.actions.cancel_description'))
                    ->visible(fn (ScheduledEmail $record): bool => $record->isCancellable())
                    ->action(function (ScheduledEmail $record): void {
                        // Guard against a race with the cron claiming the row.
                        if (! $record->isCancellable()) {
                            Notification::make()
                                ->title(__('fin-mail::fin-mail.scheduled.notifications.cancel_failed'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $record->markAsCancelled();

                        Notification::make()
                            ->title(__('fin-mail::fin-mail.scheduled.notifications.cancelled'))
                            ->success()
                            ->send();
                    }),
            ])
            ->poll('30s');
    }
}

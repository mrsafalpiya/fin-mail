<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\ScheduledEmailResource\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinMail\Editors\Blocks\ButtonBlock;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Models\ScheduledEmail;
use FinityLabs\FinMail\Resources\Concerns\ComposesEmail;
use FinityLabs\FinMail\Resources\ScheduledEmailResource\ScheduledEmailResource;
use Illuminate\Support\Carbon;

/**
 * Compose screen for an email that has been scheduled but has not gone out yet.
 *
 * Loaded from: /admin/scheduled-emails/{record}/edit
 *
 * The form is the composer's, filled from the stored payload rather than from
 * the template — what was scheduled is what you edit, even if the template has
 * changed since. Saving rewrites the same row; it never creates a second one.
 *
 * @property Schema $form
 */
class EditScheduledEmail extends Page
{
    use ComposesEmail;
    use InteractsWithForms;

    protected static string $resource = ScheduledEmailResource::class;

    protected string $view = 'fin-mail::pages.compose-email';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public ScheduledEmail $record;

    /**
     * Resolved from the record rather than persisted: Livewire restores public
     * properties between requests, and a model is not one of the things worth
     * round-tripping when the schedule already points at it.
     */
    protected EmailTemplate $emailTemplate;

    public function mount(ScheduledEmail $record): void
    {
        $this->record = $record;

        // A schedule that has fired, failed or been cancelled is a record of
        // something that already happened, and the table offers no way in. Guard
        // anyway: the row can fire between the list rendering and this request.
        if (! $record->isCancellable() || $record->template === null) {
            Notification::make()
                ->title(__('fin-mail::fin-mail.scheduled.notifications.not_editable'))
                ->warning()
                ->send();

            $this->redirect(ScheduledEmailResource::getUrl('index'));

            return;
        }

        ButtonBlock::setPreviewTheme($this->template()->theme?->resolvedColors());

        $payload = $record->payload;

        $this->form->fill([
            'from' => $payload['from'] ?? $record->from_address,
            // In CSV mode the recipients live in the parsed rows below, not in
            // To / Cc / Bcc — those fields are not on the form at all.
            ...$this->csvMode() ? [] : [
                'to' => $payload['to'] ?? $record->to,
                'cc' => $payload['cc'] ?? [],
                'bcc' => $payload['bcc'] ?? [],
            ],
            'locale' => $payload['locale'] ?? app()->getLocale(),
            'subject' => $payload['subject'] ?? $record->subject,
            'preheader' => $payload['preheader'] ?? '',
            'body' => $payload['body'] ?? '',
            'attachments' => $payload['attachments'] ?? [],
        ]);

        if ($this->csvMode()) {
            // Not a form component — the upload's afterStateUpdated writes it,
            // and everything downstream reads it straight off the state array.
            $this->data['recipient_csv_data'] = $this->csvStateFromRows($payload['csv_rows'] ?? []);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $this->composeForm($schema);
    }

    public function template(): EmailTemplate
    {
        if (! isset($this->emailTemplate)) {
            // The table hides Edit once a schedule's template is gone, so this
            // only fires if it was deleted while the page was open.
            $this->emailTemplate = $this->record->template ?? abort(404);
        }

        return $this->emailTemplate;
    }

    /**
     * The recipients came from a file that was parsed and thrown away, so
     * uploading another one is how you replace them — not a requirement for
     * saving a change to anything else.
     */
    protected function requiresCsvUpload(): bool
    {
        return false;
    }

    /**
     * Rewrite the schedule, unless the sender has already claimed it.
     *
     * @param  array<string, mixed>  $actionData  Data from the schedule modal (scheduled_at, send_mode).
     */
    public function updateSchedule(array $actionData): void
    {
        $data = $this->composePayload($this->form->getState());

        if ($data === null) {
            return;
        }

        $applied = $this->record->updateIfPending([
            ...$this->scheduleAttributes($data, $actionData),
            // sent_by stays with whoever armed the send; the editor is recorded
            // beside it rather than over it.
            'metadata' => array_merge($this->record->metadata ?? [], [
                'last_edited_by' => auth()->id(),
                'last_edited_at' => now()->toIso8601String(),
            ]),
        ]);

        if (! $applied) {
            Notification::make()
                ->title(__('fin-mail::fin-mail.scheduled.notifications.edit_failed'))
                ->body(__('fin-mail::fin-mail.scheduled.notifications.edit_failed_body'))
                ->warning()
                ->send();

            $this->redirect(ScheduledEmailResource::getUrl('index'));

            return;
        }

        Notification::make()
            ->title(__('fin-mail::fin-mail.scheduled.notifications.updated'))
            ->body(__('fin-mail::fin-mail.scheduled.notifications.updated_body', [
                'time' => Carbon::parse($actionData['scheduled_at'])->format(app('fin-mail')->dateTimeFormat() ?? 'Y-m-d H:i'),
            ]))
            ->success()
            ->send();

        $this->redirect(ScheduledEmailResource::getUrl('index'));
    }

    public function getTitle(): string
    {
        return __('fin-mail::fin-mail.scheduled.edit.title_with_name', [
            'name' => $this->template()->name,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('update')
                ->label(__('fin-mail::fin-mail.scheduled.edit.actions.update'))
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading(__('fin-mail::fin-mail.scheduled.edit.heading'))
                ->modalDescription(fn (): string => $this->hasMultipleRecipients()
                    ? __('fin-mail::fin-mail.scheduled.edit.description_multiple')
                    : __('fin-mail::fin-mail.scheduled.edit.description'))
                ->modalSubmitActionLabel(__('fin-mail::fin-mail.scheduled.edit.actions.update'))
                ->schema(fn (): array => $this->getScheduleSchema())
                ->fillForm(fn (): array => [
                    'send_mode' => $this->record->send_mode ?? 'individual',
                    'scheduled_at' => $this->record->scheduled_at,
                ])
                ->action(function (array $data): void {
                    $this->updateSchedule($data);
                }),

            $this->getPreviewAction(),

            Action::make('back')
                ->label(__('fin-mail::fin-mail.scheduled.edit.actions.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(ScheduledEmailResource::getUrl('index'))
                ->color('gray'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\EmailTemplateResource\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinMail\Actions\EmailSender;
use FinityLabs\FinMail\Editors\Blocks\ButtonBlock;
use FinityLabs\FinMail\Enums\ScheduledEmailStatus;
use FinityLabs\FinMail\Helpers\RecipientGrouper;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Models\ScheduledEmail;
use FinityLabs\FinMail\Resources\Concerns\ComposesEmail;
use FinityLabs\FinMail\Resources\EmailTemplateResource\EmailTemplateResource;
use FinityLabs\FinMail\Resources\ScheduledEmailResource\ScheduledEmailResource;
use FinityLabs\FinMail\Settings\GeneralSettings;
use Illuminate\Support\Carbon;

/**
 * Full-page compose screen.
 *
 * Loaded from: /admin/email-templates/{record}/compose
 *
 * @property Schema $form
 */
class ComposeEmail extends Page
{
    use ComposesEmail;
    use InteractsWithForms;

    protected static string $resource = EmailTemplateResource::class;

    protected string $view = 'fin-mail::pages.compose-email';

    protected static ?string $title = null;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaperAirplane;

    public EmailTemplate $record;

    public function mount(EmailTemplate $record): void
    {
        $this->record = $record;

        $locale = app()->getLocale();
        $rendered = $record->render([], $locale, renderBlocks: false);

        ButtonBlock::setPreviewTheme($record->theme?->resolvedColors());

        $this->form->fill([
            'template_key' => $record->key,
            'from' => $record->from['address'] ?? app(GeneralSettings::class)->default_from_address,
            // A tokenised template takes its recipients from the CSV instead, so
            // there is no To / Cc / Bcc field to seed.
            ...$this->csvMode() ? [] : [
                'to' => array_filter([auth()->user()?->email]),
                'cc' => [],
                'bcc' => [],
            ],
            'locale' => $locale,
            'subject' => $rendered['subject'],
            'preheader' => $rendered['preheader'],
            'body' => $rendered['body'],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $this->composeForm($schema);
    }

    public function template(): EmailTemplate
    {
        return $this->record;
    }

    /**
     * @param  'individual'|'combined'|null  $sendMode  How to deliver when there are multiple "To" recipients.
     */
    public function send(?string $sendMode = null): void
    {
        $data = $this->composePayload($this->form->getState());

        if ($data === null) {
            return;
        }

        $groups = RecipientGrouper::sendGroups($data, $sendMode);

        $sentCount = 0;

        foreach ($groups as $group) {
            $sender = new EmailSender(
                data: array_merge($data, $group),
                record: null,
                templateKey: $this->record->key,
                notify: count($groups) === 1,
            );

            if ($sender->send()) {
                $sentCount++;
            }
        }

        if (count($groups) > 1 && $sentCount > 0) {
            Notification::make()
                ->title(__('fin-mail::fin-mail.compose.notifications.individual_sent'))
                ->body(__('fin-mail::fin-mail.compose.notifications.individual_sent_body', ['count' => $sentCount]))
                ->success()
                ->send();
        }

        if ($sentCount === count($groups)) {
            $this->redirect(static::getResource()::getUrl('index'));
        }
    }

    /**
     * Split recipients into the "To" groups that each become one email.
     *
     * Individual mode with more than one recipient yields one group per
     * recipient; every other case delivers a single email addressed to all.
     *
     * @param  list<string>  $recipients
     * @param  'individual'|'combined'|null  $sendMode
     *
     * @return list<list<string>>
     */
    protected function resolveRecipientGroups(array $recipients, ?string $sendMode): array
    {
        return RecipientGrouper::groups($recipients, $sendMode);
    }

    /**
     * Persist the current compose form as a Pending scheduled email that the
     * fin-mail:send-scheduled command delivers once its time arrives.
     *
     * @param  array<string, mixed>  $actionData  Data from the schedule modal (scheduled_at, send_mode).
     */
    public function schedule(array $actionData): void
    {
        $data = $this->composePayload($this->form->getState());

        if ($data === null) {
            return;
        }

        ScheduledEmail::create([
            ...$this->scheduleAttributes($data, $actionData),
            'email_template_id' => $this->record->id,
            'status' => ScheduledEmailStatus::Pending,
            'sent_by' => auth()->id(),
        ]);

        Notification::make()
            ->title(__('fin-mail::fin-mail.compose.notifications.scheduled'))
            ->body(__('fin-mail::fin-mail.compose.notifications.scheduled_body', [
                'time' => Carbon::parse($actionData['scheduled_at'])->format(app('fin-mail')->dateTimeFormat() ?? 'Y-m-d H:i'),
            ]))
            ->success()
            ->send();

        $this->redirect(ScheduledEmailResource::getUrl('index'));
    }

    public function getTitle(): string
    {
        return __('fin-mail::fin-mail.compose.title_with_name', ['name' => $this->record->name]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('send')
                ->label(__('fin-mail::fin-mail.compose.actions.send'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->requiresConfirmation()
                ->modalHeading(__('fin-mail::fin-mail.compose.confirm.heading'))
                ->modalDescription(fn (): string => $this->hasMultipleRecipients()
                    ? __('fin-mail::fin-mail.compose.confirm.description_multiple')
                    : __('fin-mail::fin-mail.compose.confirm.description'))
                ->modalSubmitActionLabel(__('fin-mail::fin-mail.compose.actions.send'))
                ->schema(fn (): array => $this->getSendModeSchema())
                ->action(function (array $data): void {
                    $this->send($data['send_mode'] ?? null);
                }),

            Action::make('schedule')
                ->label(__('fin-mail::fin-mail.compose.actions.schedule'))
                ->icon(Heroicon::OutlinedClock)
                ->color('gray')
                ->modalHeading(__('fin-mail::fin-mail.compose.schedule.heading'))
                ->modalDescription(fn (): string => $this->hasMultipleRecipients()
                    ? __('fin-mail::fin-mail.compose.schedule.description_multiple')
                    : __('fin-mail::fin-mail.compose.schedule.description'))
                ->modalSubmitActionLabel(__('fin-mail::fin-mail.compose.actions.schedule'))
                ->schema(fn (): array => $this->getScheduleSchema())
                ->action(function (array $data): void {
                    $this->schedule($data);
                }),

            $this->getPreviewAction(),

            Action::make('back')
                ->label(__('fin-mail::fin-mail.template.actions.back_to_templates'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->url(static::getResource()::getUrl('index'))
                ->color('gray'),
        ];
    }
}

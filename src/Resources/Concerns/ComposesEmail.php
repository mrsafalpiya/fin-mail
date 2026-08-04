<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinMail\Helpers\RecipientCsvResult;
use FinityLabs\FinMail\Helpers\TipTapConverter;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Resources\EmailTemplateResource\Schemas\ComposeEmailForm;
use FinityLabs\FinMail\Settings\GeneralSettings;

/**
 * The compose screen's shared behaviour: its form, its preview, and the shape of
 * a scheduled send.
 *
 * Two pages compose an email — the template's Compose screen, which sends or
 * schedules, and the Scheduled Emails edit screen, which rewrites a schedule
 * that has not fired yet. They differ only in what they are bound to and what
 * their header actions do, so everything that decides how a compose *behaves*
 * lives here and both pages read it from the same place.
 *
 * The using page supplies the template through {@see template()}; it cannot be a
 * shared property because each page's `$record` is a different model.
 */
trait ComposesEmail
{
    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /**
     * The template this compose is based on.
     */
    abstract public function template(): EmailTemplate;

    /**
     * The compose form.
     *
     * Named apart from the page's own form() because InteractsWithForms
     * declares that too, and two traits cannot both supply it.
     */
    public function composeForm(Schema $schema): Schema
    {
        return ComposeEmailForm::configure(
            $schema,
            $this->template(),
            requireCsvUpload: $this->requiresCsvUpload(),
        );
    }

    /**
     * Whether this template needs a per-recipient token value for every send,
     * which is what swaps the recipient fields for a CSV upload.
     */
    public function csvMode(): bool
    {
        return $this->template()->csvTokens() !== [];
    }

    /**
     * Whether a CSV file must be supplied before this compose can go anywhere.
     *
     * True while composing: there is nothing to send until a file is uploaded.
     * The edit screen relaxes it, because the recipients it already holds came
     * from a file that was parsed and thrown away.
     */
    protected function requiresCsvUpload(): bool
    {
        return true;
    }

    /**
     * Turn validated form state into the payload that EmailSender replays.
     *
     * In CSV mode the uploaded file itself is dropped — only the parsed rows
     * travel onward, which is what makes the payload safe to persist for a
     * scheduled send. Returns null when there is nothing to send, having
     * already told the user why.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>|null
     */
    protected function composePayload(array $data): ?array
    {
        $csvState = $this->data['recipient_csv_data'] ?? null;

        unset($data['recipient_csv']);

        if (! $this->csvMode()) {
            return $data;
        }

        $result = RecipientCsvResult::fromArray($csvState);

        if ($result->isEmpty()) {
            Notification::make()
                ->title(__('fin-mail::fin-mail.compose.csv.errors.required'))
                ->danger()
                ->send();

            return null;
        }

        $data['csv_rows'] = $result->rows;
        $data['to'] = $result->emails();

        return $data;
    }

    /**
     * Rebuild the parsed-CSV form state from rows stored in a schedule payload.
     *
     * Only the accepted rows are persisted, which is all a send needs. The
     * original parse warnings described a file that no longer exists, so they
     * are not resurrected — the stored rows are the recipient list now.
     *
     * @param  list<array{email: string, tokens?: array<string, string>}>  $rows
     *
     * @return array<string, mixed>
     */
    protected function csvStateFromRows(array $rows): array
    {
        $supplied = [];

        foreach ($rows as $row) {
            foreach (array_keys($row['tokens'] ?? []) as $token) {
                $supplied[$token] = true;
            }
        }

        return (new RecipientCsvResult(
            rows: $rows,
            mappedTokens: array_values(array_filter(
                $this->template()->csvTokens(),
                fn (string $token): bool => isset($supplied[$token]),
            )),
        ))->toArray();
    }

    /**
     * The columns describing a scheduled send.
     *
     * Shared by the page that creates the schedule and the page that rewrites
     * it, so a row looks the same however it was last touched.
     *
     * @param  array<string, mixed>  $data  The composed payload.
     * @param  array<string, mixed>  $actionData  Data from the schedule modal (scheduled_at, send_mode).
     *
     * @return array<string, mixed>
     */
    protected function scheduleAttributes(array $data, array $actionData): array
    {
        $recipients = array_values(array_filter($data['to'] ?? []));

        // A CSV batch is always one email per row, whatever the modal offered.
        $sendMode = $this->csvMode()
            ? 'individual'
            : ($actionData['send_mode'] ?? null);

        return [
            'from_address' => $data['from'] ?? app(GeneralSettings::class)->default_from_address,
            'to' => $recipients,
            'subject' => $data['subject'],
            'payload' => array_merge($data, ['template_key' => $this->template()->key]),
            // One recipient is one email however it was addressed, so the mode
            // is meaningless — and must not linger from an earlier, longer list.
            'send_mode' => count($recipients) > 1 ? $sendMode : null,
            'scheduled_at' => $actionData['scheduled_at'],
        ];
    }

    protected function getPreviewHtml(): string
    {
        $body = $this->data['body'] ?? '';

        if (is_array($body)) {
            return TipTapConverter::toHtml($body);
        }

        return $body;
    }

    protected function hasMultipleRecipients(): bool
    {
        if ($this->csvMode()) {
            return RecipientCsvResult::fromArray($this->data['recipient_csv_data'] ?? null)->count() > 1;
        }

        return count(array_filter($this->data['to'] ?? [])) > 1;
    }

    /**
     * The delivery-mode chooser shown in the send modal, only when there is
     * more than one "To" recipient. A single recipient needs no choice.
     *
     * CSV mode offers none either: each row carries its own token values, so a
     * combined email addressed to everyone could only be right for one of them.
     *
     * @return list<Radio>
     */
    protected function getSendModeSchema(): array
    {
        if ($this->csvMode() || ! $this->hasMultipleRecipients()) {
            return [];
        }

        return [
            Radio::make('send_mode')
                ->label(__('fin-mail::fin-mail.compose.confirm.send_mode_label'))
                ->options([
                    'individual' => __('fin-mail::fin-mail.compose.confirm.send_mode_individual'),
                    'combined' => __('fin-mail::fin-mail.compose.confirm.send_mode_combined'),
                ])
                ->descriptions([
                    'individual' => __('fin-mail::fin-mail.compose.confirm.send_mode_individual_help'),
                    'combined' => __('fin-mail::fin-mail.compose.confirm.send_mode_combined_help'),
                ])
                ->default('individual')
                ->required(),
        ];
    }

    /**
     * The schedule modal's fields: how to deliver, and when.
     *
     * @return list<Component>
     */
    protected function getScheduleSchema(): array
    {
        return [
            ...$this->getSendModeSchema(),
            DateTimePicker::make('scheduled_at')
                ->label(__('fin-mail::fin-mail.compose.schedule.scheduled_at'))
                ->seconds(false)
                ->native(false)
                // Frontend only: allow today or any future date, with any
                // time from the start of the day (no time is disabled).
                ->minDate(now()->startOfDay())
                ->required()
                // Backend: the real guard — the moment must be in the future.
                // Validated server-side on submit, so an out-of-range time
                // shows an inline error instead of resetting the field.
                ->rules(['after:now'])
                ->validationMessages([
                    'after' => __('fin-mail::fin-mail.compose.schedule.future_error'),
                ])
                ->helperText(__('fin-mail::fin-mail.compose.schedule.timezone_hint', [
                    'timezone' => config('app.timezone'),
                ])),
        ];
    }

    protected function getPreviewAction(): Action
    {
        return Action::make('preview')
            ->label(__('fin-mail::fin-mail.compose.actions.preview'))
            ->icon(Heroicon::OutlinedEye)
            ->modal()
            ->modalHeading(__('fin-mail::fin-mail.template.actions.preview'))
            ->modalContent(fn () => view('fin-mail::components.email-preview', [
                'subject' => $this->data['subject'] ?? '',
                'preheader' => $this->data['preheader'] ?? '',
                'html' => $this->getPreviewHtml(),
                'theme' => $this->template()->theme?->resolvedColors(),
            ]))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitAction(false)
            ->color('gray');
    }
}

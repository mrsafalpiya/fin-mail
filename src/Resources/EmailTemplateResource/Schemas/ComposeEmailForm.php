<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\EmailTemplateResource\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use FinityLabs\FinMail\Contracts\EditorContract;
use FinityLabs\FinMail\Helpers\RecipientCsvParser;
use FinityLabs\FinMail\Helpers\RecipientCsvResult;
use FinityLabs\FinMail\Helpers\RecipientsInput;
use FinityLabs\FinMail\Models\EmailTemplate;
use FinityLabs\FinMail\Settings\AttachmentSettings;
use FinityLabs\FinMail\Settings\GeneralSettings;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ComposeEmailForm
{
    /** How many line numbers a warning lists before collapsing into "and N more". */
    private const MAX_LISTED_ROWS = 10;

    /**
     * @param  bool  $requireCsvUpload  Whether a recipient CSV must be uploaded before this compose can go anywhere. False when the compose already carries recipients parsed from an earlier upload.
     */
    public static function configure(Schema $schema, EmailTemplate $record, bool $requireCsvUpload = true): Schema
    {
        $editor = app(EditorContract::class);

        $mailSettings = app(GeneralSettings::class);

        $senders = collect($mailSettings->additional_senders)
            ->prepend(['address' => $mailSettings->default_from_address, 'name' => $mailSettings->default_from_name])
            ->filter()
            ->mapWithKeys(fn (array $s): array => [$s['address'] => "{$s['name']} <{$s['address']}>"])
            ->all();

        return $schema
            ->columns(3)
            ->components([
                Group::make()
                    ->columns(1)
                    ->columnSpan(['lg' => 2])
                    ->schema([
                        Section::make(__('fin-mail::fin-mail.compose.sections.recipients'))
                            ->icon(Heroicon::OutlinedUserGroup)
                            ->schema([
                                Select::make('from')
                                    ->label(__('fin-mail::fin-mail.compose.fields.from'))
                                    ->options($senders)
                                    ->native(false)
                                    ->required(),

                                ...self::recipientComponents($record, $requireCsvUpload),
                            ])
                            ->columns(2)
                            ->collapsible(),
                        Section::make(__('fin-mail::fin-mail.compose.sections.content'))
                            ->icon(Heroicon::OutlinedDocumentText)
                            ->schema([
                                Select::make('locale')
                                    ->label(__('fin-mail::fin-mail.compose.fields.locale'))
                                    ->options(fn (): array => collect($mailSettings->languages)->pluck('display', 'code')->all())
                                    ->default(fn (): string => app()->getLocale())
                                    ->native(false)
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Set $set) use ($record): void {
                                        if (! $state) {
                                            return;
                                        }

                                        $rendered = $record->render([], $state, renderBlocks: false);
                                        $set('subject', $rendered['subject']);
                                        $set('preheader', $rendered['preheader']);
                                        $set('body', $rendered['body']);
                                    }),

                                TextInput::make('subject')
                                    ->label(__('fin-mail::fin-mail.compose.fields.subject'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                TextInput::make('preheader')
                                    ->label(__('fin-mail::fin-mail.compose.fields.preheader'))
                                    ->maxLength(255)
                                    ->helperText(__('fin-mail::fin-mail.compose.fields.preheader_helper'))
                                    ->columnSpanFull(),

                                self::applyMergeTags(
                                    $editor->make('body')
                                        ->label(__('fin-mail::fin-mail.compose.fields.body'))
                                        ->required(),
                                    $record,
                                ),
                            ]),
                    ]),

                Group::make()
                    ->columnSpan(['lg' => 1])
                    ->schema([
                        Section::make(__('fin-mail::fin-mail.compose.sections.attachments'))
                            ->icon(Heroicon::OutlinedPaperClip)
                            ->schema([
                                FileUpload::make('attachments')
                                    ->label(__('fin-mail::fin-mail.compose.fields.attach_files'))
                                    ->multiple()
                                    ->disk(config('fin-mail.attachments_disk', 'local'))
                                    ->directory('email-attachments')
                                    ->maxSize(app(AttachmentSettings::class)->max_size_mb * 1024)
                                    ->columnSpanFull(),
                            ]),

                        Section::make(__('fin-mail::fin-mail.compose.sections.tokens'))
                            ->icon(Heroicon::OutlinedCodeBracket)
                            ->schema([
                                TextEntry::make('tokens_info')
                                    ->bulleted()
                                    ->state(function () use ($record): array {
                                        $tokens = $record->token_schema ?? [];
                                        if (empty($tokens)) {
                                            return [__('fin-mail::fin-mail.compose.fields.no_tokens')];
                                        }

                                        return collect($tokens)
                                            ->map(fn (array $t): string => "{{ {$t['token']} }} — {$t['description']}".($t['example'] ?? false ? " (e.g., {$t['example']})" : ''))
                                            ->all();
                                    }),
                            ]),
                    ]),
            ])->statePath('data');
    }

    /**
     * The recipient controls, which depend on whether the template needs
     * per-recipient token values.
     *
     * Without declared tokens the composer keeps its To / Cc / Bcc fields. With
     * them, one email is sent per CSV row carrying that row's values, so a typed
     * recipient list has no way to supply them — the whole trio is replaced by
     * the upload rather than left half-usable beside it.
     *
     * @return array<int, Component>
     */
    protected static function recipientComponents(EmailTemplate $record, bool $requireCsvUpload = true): array
    {
        $csvTokens = $record->csvTokens();

        if ($csvTokens === []) {
            return [
                RecipientsInput::make('to')
                    ->label(__('fin-mail::fin-mail.compose.fields.to'))
                    ->placeholder(__('fin-mail::fin-mail.compose.fields.to_placeholder'))
                    ->required(),

                RecipientsInput::make('cc')
                    ->label(__('fin-mail::fin-mail.compose.fields.cc'))
                    ->placeholder(__('fin-mail::fin-mail.compose.fields.cc_placeholder')),

                RecipientsInput::make('bcc')
                    ->label(__('fin-mail::fin-mail.compose.fields.bcc'))
                    ->placeholder(__('fin-mail::fin-mail.compose.fields.bcc_placeholder')),
            ];
        }

        return [
            FileUpload::make('recipient_csv')
                ->label(__('fin-mail::fin-mail.compose.csv.field'))
                ->helperText(
                    __('fin-mail::fin-mail.compose.csv.helper').' '.
                    __('fin-mail::fin-mail.compose.csv.headers', [
                        'headers' => implode(',', self::csvHeaderRow($csvTokens)),
                    ]).
                    // Editing a schedule starts with the recipients its original
                    // file produced, so say what uploading another one does.
                    ($requireCsvUpload ? '' : ' '.__('fin-mail::fin-mail.compose.csv.replace_helper'))
                )
                ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                // Parsed on upload and never written to disk: a file of customer
                // addresses has no reason to linger on the attachments disk, and
                // the parsed rows are all that send and schedule need.
                ->storeFiles(false)
                ->maxSize((int) config('fin-mail.csv.max_size_kb', 2048))
                ->required($requireCsvUpload)
                ->live()
                ->afterStateUpdated(function (mixed $state, Set $set) use ($csvTokens): void {
                    $file = is_array($state) ? Arr::first($state) : $state;

                    $set('recipient_csv_data', $file instanceof TemporaryUploadedFile
                        ? RecipientCsvParser::parse($file->get(), $csvTokens)->toArray()
                        : null);
                })
                ->columnSpanFull(),

            TextEntry::make('recipient_csv_error')
                ->hiddenLabel()
                ->color('danger')
                ->state(fn (Get $get): string => self::errorMessage(self::result($get)) ?? '')
                ->visible(fn (Get $get): bool => self::result($get)->hasError())
                ->columnSpanFull(),

            TextEntry::make('recipient_csv_summary')
                ->hiddenLabel()
                ->color(fn (Get $get): string => self::result($get)->isEmpty() ? 'danger' : 'success')
                ->state(fn (Get $get): array => self::summaryLines(self::result($get)))
                ->bulleted()
                ->visible(fn (Get $get): bool => ! self::result($get)->hasError() && filled($get('recipient_csv_data')))
                ->columnSpanFull(),

            TextEntry::make('recipient_csv_warnings')
                ->hiddenLabel()
                ->color('warning')
                ->state(fn (Get $get): array => self::warningLines(self::result($get)))
                ->bulleted()
                ->visible(fn (Get $get): bool => self::result($get)->hasWarnings())
                ->columnSpanFull(),

            Actions::make([
                Action::make('view_recipients')
                    ->label(__('fin-mail::fin-mail.compose.csv.actions.view_recipients'))
                    ->icon(Heroicon::OutlinedUsers)
                    ->link()
                    ->modalHeading(__('fin-mail::fin-mail.compose.csv.preview.heading'))
                    ->modalContent(fn ($livewire) => view('fin-mail::components.recipient-csv-preview', [
                        'result' => self::resultOf($livewire),
                    ]))
                    ->modalSubmitAction(false)
                    ->visible(fn ($livewire): bool => ! self::resultOf($livewire)->isEmpty()),

                Action::make('download_csv_template')
                    ->label(__('fin-mail::fin-mail.compose.csv.actions.download_template'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->link()
                    ->color('gray')
                    ->action(fn () => response()->streamDownload(
                        function () use ($record, $csvTokens): void {
                            self::writeCsvTemplate($record, $csvTokens);
                        },
                        Str::slug($record->key).'-recipients.csv',
                        ['Content-Type' => 'text/csv'],
                    )),
            ])->columnSpanFull(),
        ];
    }

    /**
     * The CSV's required header row: the address column, then one column per
     * declared token.
     *
     * @param  list<string>  $csvTokens
     *
     * @return list<string>
     */
    public static function csvHeaderRow(array $csvTokens): array
    {
        return ['email', ...$csvTokens];
    }

    /**
     * Emit the downloadable starter file — the header row plus one example row
     * built from each token's documented example.
     *
     * @param  list<string>  $csvTokens
     */
    protected static function writeCsvTemplate(EmailTemplate $record, array $csvTokens): void
    {
        $examples = collect($record->token_schema ?? [])
            ->mapWithKeys(fn (array $token): array => [
                trim((string) ($token['token'] ?? '')) => (string) ($token['example'] ?? ''),
            ])
            ->all();

        $handle = fopen('php://output', 'w');

        fputcsv($handle, self::csvHeaderRow($csvTokens), ',', '"', '');
        fputcsv($handle, [
            'alice@example.com',
            ...array_map(fn (string $token): string => $examples[$token] ?? '', $csvTokens),
        ], ',', '"', '');

        fclose($handle);
    }

    protected static function result(Get $get): RecipientCsvResult
    {
        return RecipientCsvResult::fromArray($get('recipient_csv_data'));
    }

    /**
     * Read the parsed result straight off the Livewire component, for closures
     * (action modals) that are evaluated outside a schema component's scope.
     */
    protected static function resultOf(mixed $livewire): RecipientCsvResult
    {
        return RecipientCsvResult::fromArray($livewire->data['recipient_csv_data'] ?? null);
    }

    protected static function errorMessage(RecipientCsvResult $result): ?string
    {
        if (! $result->error) {
            return null;
        }

        return __('fin-mail::fin-mail.compose.csv.errors.'.$result->error['key'], $result->error['params']);
    }

    /**
     * @return list<string>
     */
    protected static function summaryLines(RecipientCsvResult $result): array
    {
        if ($result->isEmpty()) {
            return [__('fin-mail::fin-mail.compose.csv.no_recipients')];
        }

        $lines = [__('fin-mail::fin-mail.compose.csv.ready', ['count' => $result->count()])];

        if ($result->mappedTokens !== []) {
            $lines[] = __('fin-mail::fin-mail.compose.csv.mapped', [
                'tokens' => implode(', ', $result->mappedTokens),
            ]);
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    protected static function warningLines(RecipientCsvResult $result): array
    {
        $lines = [];

        if ($result->ignoredColumns !== []) {
            $lines[] = __('fin-mail::fin-mail.compose.csv.warnings.ignored_columns', [
                'columns' => implode(', ', $result->ignoredColumns),
            ]);
        }

        if ($result->invalidRows !== []) {
            $lines[] = __('fin-mail::fin-mail.compose.csv.warnings.invalid_rows', [
                'rows' => self::listRows($result->invalidRows),
            ]);
        }

        if ($result->duplicateRows !== []) {
            $lines[] = __('fin-mail::fin-mail.compose.csv.warnings.duplicate_rows', [
                'rows' => self::listRows(array_column($result->duplicateRows, 'row')),
            ]);
        }

        foreach ($result->missingCounts as $token => $count) {
            $lines[] = __('fin-mail::fin-mail.compose.csv.warnings.missing_values', [
                'count' => $count,
                'token' => $token,
            ]);
        }

        return $lines;
    }

    /**
     * @param  list<int>  $rows
     */
    protected static function listRows(array $rows): string
    {
        $listed = array_slice($rows, 0, self::MAX_LISTED_ROWS);
        $remaining = count($rows) - count($listed);

        $text = implode(', ', $listed);

        if ($remaining > 0) {
            $text .= ' '.__('fin-mail::fin-mail.compose.csv.warnings.more', ['count' => $remaining]);
        }

        return $text;
    }

    protected static function applyMergeTags(mixed $component, EmailTemplate $record): mixed
    {
        if ($component instanceof RichEditor) {
            $component->mergeTags(
                collect($record->token_schema ?? [])
                    ->pluck('token')
                    ->filter()
                    ->values()
                    ->all(),
            );
        }

        return $component;
    }
}

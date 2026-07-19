<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Editors\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use FinityLabs\FinMail\Helpers\UtmComposer;

/**
 * Drop-in replacement for Filament's built-in RichEditor link action that adds
 * per-link UTM controls (a toggle plus utm_content / utm_term).
 *
 * The per-link state is embedded into the stored href via internal markers
 * ({@see UtmComposer::embedLinkMarkers()}) so no custom TipTap JS extension or
 * link-mark attributes are required. On re-open, the markers are parsed back
 * out so the modal shows a clean base URL with the fields repopulated. The
 * template-level defaults and final parameters are appended at render time.
 */
final class UtmLinkAction
{
    public static function make(): Action
    {
        return Action::make('link')
            ->label(__('filament-forms::components.rich_editor.actions.link.label'))
            ->modalHeading(__('filament-forms::components.rich_editor.actions.link.modal.heading'))
            ->modalWidth(Width::Large)
            ->fillForm(fn (array $arguments): array => self::fillFromArguments($arguments))
            ->schema([
                TextInput::make('url')
                    ->label(__('filament-forms::components.rich_editor.actions.link.modal.form.url.label'))
                    ->inputMode('url'),
                Checkbox::make('shouldOpenInNewTab')
                    ->label(__('filament-forms::components.rich_editor.actions.link.modal.form.should_open_in_new_tab.label')),
                ...(UtmComposer::enabled() ? [
                    Toggle::make('shouldUseUtm')
                        ->label(__('fin-mail::fin-mail.template.blocks.use_utm'))
                        ->helperText(__('fin-mail::fin-mail.template.blocks.use_utm_helper'))
                        ->live(),
                    TextInput::make('utmContent')
                        ->label(__('fin-mail::fin-mail.template.blocks.utm_content'))
                        ->helperText(__('fin-mail::fin-mail.template.blocks.utm_content_helper'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('shouldUseUtm')),
                    TextInput::make('utmTerm')
                        ->label(__('fin-mail::fin-mail.template.blocks.utm_term'))
                        ->helperText(__('fin-mail::fin-mail.template.blocks.utm_term_helper'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => (bool) $get('shouldUseUtm')),
                ] : []),
            ])
            ->action(function (array $arguments, array $data, RichEditor $component): void {
                $isSingleCharacterSelection = ($arguments['editorSelection']['head'] ?? null) === ($arguments['editorSelection']['anchor'] ?? null);

                if (blank($data['url'] ?? null)) {
                    $component->runCommands(
                        [
                            ...($isSingleCharacterSelection ? [EditorCommand::make(
                                'extendMarkRange',
                                arguments: ['link'],
                            )] : []),
                            EditorCommand::make('unsetLink'),
                        ],
                        editorSelection: $arguments['editorSelection'],
                    );

                    return;
                }

                $href = self::buildHref($data);

                $component->runCommands(
                    [
                        ...($isSingleCharacterSelection ? [EditorCommand::make(
                            'extendMarkRange',
                            arguments: ['link'],
                        )] : []),
                        EditorCommand::make(
                            'setLink',
                            arguments: [[
                                'href' => $href,
                                'target' => $data['shouldOpenInNewTab'] ? '_blank' : null,
                            ]],
                        ),
                    ],
                    editorSelection: $arguments['editorSelection'],
                );
            });
    }

    /**
     * Populate the modal from the current link, parsing any embedded UTM markers
     * back into a clean URL and the individual fields.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private static function fillFromArguments(array $arguments): array
    {
        $url = is_string($arguments['url'] ?? null) ? $arguments['url'] : '';

        [$base, $hasUtm, $content, $term] = UtmComposer::extractLinkMarkers($url);

        return [
            'url' => $base,
            'shouldOpenInNewTab' => $arguments['shouldOpenInNewTab'] ?? false,
            'shouldUseUtm' => $hasUtm,
            'utmContent' => $content,
            'utmTerm' => $term,
        ];
    }

    /**
     * Build the href to store, embedding UTM markers when the toggle is on.
     *
     * @param  array<string, mixed>  $data
     */
    private static function buildHref(array $data): string
    {
        $url = (string) $data['url'];

        if (! UtmComposer::enabled() || empty($data['shouldUseUtm'])) {
            return $url;
        }

        $content = filled($data['utmContent'] ?? null) ? (string) $data['utmContent'] : null;
        $term = filled($data['utmTerm'] ?? null) ? (string) $data['utmTerm'] : null;

        return UtmComposer::embedLinkMarkers($url, $content, $term);
    }
}

<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Editors;

use Filament\Schemas\Components\Component;
use FinityLabs\FinMail\Contracts\EditorContract;
use FinityLabs\FinMail\FinMailPlugin;

class DefaultEditor implements EditorContract
{
    public function make(string $fieldName): Component
    {
        return FinMailRichEditor::make($fieldName)
            ->toolbarButtons([
                'bold', 'italic', 'underline', 'strike',
                'h2', 'h3',
                'bulletList', 'orderedList',
                'link', 'blockquote',
                'codeBlock', 'customBlocks', 'mergeTags',
                'redo', 'undo',
            ])
            ->customBlocks(FinMailPlugin::getCustomBlockClasses())
            ->columnSpanFull();
    }

    public function name(): string
    {
        return 'default';
    }
}

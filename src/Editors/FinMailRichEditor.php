<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Editors;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use FinityLabs\FinMail\Editors\Actions\UtmLinkAction;

/**
 * RichEditor subclass that swaps Filament's built-in link action for one that
 * supports per-link UTM tracking ({@see UtmLinkAction}).
 */
final class FinMailRichEditor extends RichEditor
{
    /**
     * @return array<Action>
     */
    public function getDefaultActions(): array
    {
        return array_map(
            fn (Action $action): Action => $action->getName() === 'link'
                ? UtmLinkAction::make()
                : $action,
            parent::getDefaultActions(),
        );
    }
}

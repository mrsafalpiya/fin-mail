<?php

declare(strict_types=1);

namespace FinityLabs\FinMail\Resources\ScheduledEmailResource\Pages;

use Filament\Resources\Pages\ListRecords;
use FinityLabs\FinMail\Resources\ScheduledEmailResource\ScheduledEmailResource;

class ListScheduledEmails extends ListRecords
{
    protected static string $resource = ScheduledEmailResource::class;
}

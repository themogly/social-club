<?php

namespace App\Filament\Resources\MessageThreads\Pages;

use App\Filament\Resources\MessageThreads\MessageThreadResource;
use Filament\Resources\Pages\ListRecords;

/** Threads are originated by members from the PWA — there is no "create" here. */
class ListMessageThreads extends ListRecords
{
    protected static string $resource = MessageThreadResource::class;
}

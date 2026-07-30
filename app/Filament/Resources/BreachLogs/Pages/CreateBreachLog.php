<?php

namespace App\Filament\Resources\BreachLogs\Pages;

use App\Filament\Resources\BreachLogs\BreachLogResource;
use App\Support\ActiveScope;
use Filament\Resources\Pages\CreateRecord;

class CreateBreachLog extends CreateRecord
{
    protected static string $resource = BreachLogResource::class;

    /** Stamp the active organisation (this model carries no auto-fill scope trait). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organisation_id'] = app(ActiveScope::class)->organisationId();

        return $data;
    }
}

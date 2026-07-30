<?php

namespace App\Filament\Resources\DataRequests\Pages;

use App\Filament\Resources\DataRequests\DataRequestResource;
use App\Support\ActiveScope;
use Filament\Resources\Pages\CreateRecord;

class CreateDataRequest extends CreateRecord
{
    protected static string $resource = DataRequestResource::class;

    /** Stamp the active organisation (this model carries no auto-fill scope trait). */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['organisation_id'] = app(ActiveScope::class)->organisationId();

        return $data;
    }
}

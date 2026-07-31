<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Support\Settings;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    /** @var array<string, bool> the per-location toggles, stashed until the record exists */
    private array $toggleState = [];

    /**
     * Strip the virtual toggle fields off the model payload (they aren't columns) and stash them
     * to persist as location-scoped Setting rows once the location has an id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        foreach (LocationForm::SETTING_TOGGLES as $key) {
            $this->toggleState[$key] = (bool) ($data[$key] ?? Settings::DEFAULTS[$key]);
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        foreach (LocationForm::SETTING_TOGGLES as $key) {
            Settings::set($key, $this->toggleState[$key] ?? false, SettingType::BOOL, (string) $this->record->getKey());
        }
    }
}

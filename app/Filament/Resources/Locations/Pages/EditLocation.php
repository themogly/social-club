<?php

namespace App\Filament\Resources\Locations\Pages;

use App\Enums\SettingType;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Support\Settings;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Load the per-location toggles from their location-scoped Setting rows (prompt 59). They are
     * virtual form fields, never model columns — read for THIS sede, not the active one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (LocationForm::SETTING_TOGGLES as $key) {
            $data[$key] = (bool) Settings::get($key, Settings::DEFAULTS[$key], (string) $this->record->getKey());
        }

        foreach (LocationForm::SETTING_INTEGERS as $key) {
            $data[$key] = (int) Settings::get($key, Settings::DEFAULTS[$key], (string) $this->record->getKey());
        }

        return $data;
    }

    /**
     * Persist the toggles as location-scoped Setting rows and strip them from the model payload
     * (they are not columns on `locations`).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach (LocationForm::SETTING_TOGGLES as $key) {
            Settings::set($key, (bool) ($data[$key] ?? false), SettingType::BOOL, (string) $this->record->getKey());
            unset($data[$key]);
        }

        foreach (LocationForm::SETTING_INTEGERS as $key) {
            Settings::set($key, (int) ($data[$key] ?? Settings::DEFAULTS[$key]), SettingType::INT, (string) $this->record->getKey());
            unset($data[$key]);
        }

        return $data;
    }
}

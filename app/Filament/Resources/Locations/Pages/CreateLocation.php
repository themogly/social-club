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

    /** @var array<string, int> the per-location integer settings, stashed until the record exists */
    private array $integerState = [];

    /** @var array<string, list<int|float>> the per-location numeric-list settings, stashed until the record exists */
    private array $arrayState = [];

    /** @var array<string, string> the per-location string settings, stashed until the record exists */
    private array $stringState = [];

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

        // Owner-only (prompt 259): a non-owner creating a sede gets the default (OFF), whatever was posted.
        foreach (LocationForm::OWNER_TOGGLES as $key) {
            $this->toggleState[$key] = LocationForm::actorIsOwner() ? (bool) ($data[$key] ?? false) : (bool) Settings::DEFAULTS[$key];
            unset($data[$key]);
        }

        foreach (LocationForm::SETTING_INTEGERS as $key) {
            $this->integerState[$key] = (int) ($data[$key] ?? Settings::DEFAULTS[$key]);
            unset($data[$key]);
        }

        foreach (LocationForm::SETTING_ARRAYS as $key) {
            $this->arrayState[$key] = LocationForm::normalizeNumberList((array) ($data[$key] ?? Settings::DEFAULTS[$key]));
            unset($data[$key]);
        }

        foreach (LocationForm::SETTING_STRINGS as $key) {
            $this->stringState[$key] = (string) ($data[$key] ?? Settings::DEFAULTS[$key]);
            unset($data[$key]);
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        foreach ([...LocationForm::SETTING_TOGGLES, ...LocationForm::OWNER_TOGGLES] as $key) {
            Settings::set($key, $this->toggleState[$key] ?? false, SettingType::BOOL, (string) $this->record->getKey());
        }

        foreach (LocationForm::SETTING_INTEGERS as $key) {
            Settings::set($key, $this->integerState[$key] ?? (int) Settings::DEFAULTS[$key], SettingType::INT, (string) $this->record->getKey());
        }

        foreach (LocationForm::SETTING_ARRAYS as $key) {
            Settings::set($key, $this->arrayState[$key] ?? Settings::DEFAULTS[$key], SettingType::JSON, (string) $this->record->getKey());
        }

        foreach (LocationForm::SETTING_STRINGS as $key) {
            Settings::set($key, $this->stringState[$key] ?? (string) Settings::DEFAULTS[$key], SettingType::STRING, (string) $this->record->getKey());
        }
    }
}

<?php

namespace App\Filament\Resources\MembershipTiers\Pages;

use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use App\Models\MembershipTier;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMembershipTier extends EditRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Seed the virtual euro field from the stored integer cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var MembershipTier $tier */
        $tier = $this->getRecord();
        $data['default_fee_eur'] = $tier->default_fee_cents->cents / 100;
        $data['daily_limit_g'] = $tier->daily_limit_cg !== null ? $tier->daily_limit_cg / 100 : null;
        $data['monthly_limit_g'] = $tier->monthly_limit_cg !== null ? $tier->monthly_limit_cg / 100 : null;

        // The raw cast keys must NOT reach the form: default_fee_cents is a Money value
        // object, which Livewire cannot hold as component state ("Property type not
        // supported"). The virtual euro/gram fields above carry these values instead.
        unset($data['default_fee_cents'], $data['daily_limit_cg'], $data['monthly_limit_cg']);

        return $data;
    }

    /**
     * Convert the edited euros back to integer cents in default_fee_cents.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['default_fee_cents'] = (int) round_half_up(((float) ($data['default_fee_eur'] ?? 0)) * 100);
        $data['daily_limit_cg'] = filled($data['daily_limit_g'] ?? null) ? (int) round_half_up(((float) $data['daily_limit_g']) * 100) : null;
        $data['monthly_limit_cg'] = filled($data['monthly_limit_g'] ?? null) ? (int) round_half_up(((float) $data['monthly_limit_g']) * 100) : null;
        unset($data['default_fee_eur'], $data['daily_limit_g'], $data['monthly_limit_g']);

        return $data;
    }
}

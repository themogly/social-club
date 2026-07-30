<?php

namespace App\Filament\Resources\MembershipTiers\Pages;

use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use App\Models\MembershipTier;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditMembershipTier extends EditRecord
{
    protected static string $resource = MembershipTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            ForceDeleteAction::make(),
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
        $data['default_fee_cents'] = (int) round(((float) ($data['default_fee_eur'] ?? 0)) * 100);
        $data['daily_limit_cg'] = filled($data['daily_limit_g'] ?? null) ? (int) round_half_up(((float) $data['daily_limit_g']) * 100) : null;
        $data['monthly_limit_cg'] = filled($data['monthly_limit_g'] ?? null) ? (int) round_half_up(((float) $data['monthly_limit_g']) * 100) : null;
        unset($data['default_fee_eur'], $data['daily_limit_g'], $data['monthly_limit_g']);

        return $data;
    }
}

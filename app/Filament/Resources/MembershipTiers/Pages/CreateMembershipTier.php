<?php

namespace App\Filament\Resources\MembershipTiers\Pages;

use App\Filament\Resources\MembershipTiers\MembershipTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMembershipTier extends CreateRecord
{
    protected static string $resource = MembershipTierResource::class;

    /**
     * Money lives as integer cents in default_fee_cents; the form edits euros.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['default_fee_cents'] = (int) round(((float) ($data['default_fee_eur'] ?? 0)) * 100);
        $data['daily_limit_cg'] = filled($data['daily_limit_g'] ?? null) ? (int) round_half_up(((float) $data['daily_limit_g']) * 100) : null;
        $data['monthly_limit_cg'] = filled($data['monthly_limit_g'] ?? null) ? (int) round_half_up(((float) $data['monthly_limit_g']) * 100) : null;
        unset($data['default_fee_eur'], $data['daily_limit_g'], $data['monthly_limit_g']);

        return $data;
    }
}
